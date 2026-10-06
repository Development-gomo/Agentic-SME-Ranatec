/**
 * Ranatec MCP server — Model Context Protocol endpoint for ranatec.com.
 *
 * Transport: Streamable HTTP, stateless (a fresh McpServer + transport per request).
 * Data source: the Ranatec Agent API (WordPress plugin) at RANATEC_API_BASE.
 *
 *   POST /mcp          MCP JSON-RPC endpoint (rate-limited)
 *   GET  /mcp/health   liveness + upstream API check   (also /health)
 *   GET  /mcp/tools    tool list for discovery          (also /tools)
 */
import { createRequire } from "node:module";
import express, { type Request, type Response } from "express";
import rateLimit from "express-rate-limit";
import { z } from "zod";
import { McpServer } from "@modelcontextprotocol/sdk/server/mcp.js";
import { StreamableHTTPServerTransport } from "@modelcontextprotocol/sdk/server/streamableHttp.js";

const NAME = "ranatec-mcp";
// Single source of truth: package.json (stamped from the repo VERSION file by tools/release.sh).
const VERSION: string = (createRequire(import.meta.url)("../package.json") as { version: string }).version;
const API_BASE = (process.env.RANATEC_API_BASE ?? "https://ranatec.com/agent/v1").replace(/\/$/, "");
const PORT = parseInt(process.env.PORT ?? "3000", 10);
const API_TIMEOUT = parseInt(process.env.API_TIMEOUT_MS ?? "10000", 10);
const CACHE_TTL = parseInt(process.env.CACHE_TTL_MS ?? "300000", 10);
const RATE_LIMIT = parseInt(process.env.RATE_LIMIT_PER_MIN ?? "100", 10);

// ----------------------------------------------------------------------------- types (subset of the API)

type LocaleUrls = { "en-US": string; "en-GB": string; "en-CA": string };
type Spec = { parameter: string; value: string | string[] | null };
interface Product {
  id: string; name: string; model_number: string | null; summary: string | null; categories: string[]; domain: string | null;
  listing: "catalogue" | "additional"; custom: boolean; description: string[]; applications: string[]; features: string[];
  specifications: Spec[]; electrical_interfaces: string[]; control_and_ordering: string[]; optional_accessories: string[];
  datasheets: string[]; url: string; urls: LocaleUrls; primary_listing?: string | null; [k: string]: unknown;
}
interface NewsItem {
  id: string; title: string; type: string; date_published: string; summary: string | null; body: string[];
  related_product_ids: string[]; url: string; urls: LocaleUrls; [k: string]: unknown;
}
interface Faq { q: string; a: string; source: string }

// ----------------------------------------------------------------------------- HTTP helpers

async function fetchWithTimeout(url: string, options: RequestInit = {}, timeoutMs = API_TIMEOUT): Promise<globalThis.Response> {
  const controller = new AbortController();
  const id = setTimeout(() => controller.abort(), timeoutMs);
  try {
    return await fetch(url, { ...options, signal: controller.signal, headers: { Accept: "application/json", "User-Agent": `${NAME}/${VERSION}`, ...(options.headers ?? {}) } });
  } finally {
    clearTimeout(id);
  }
}

const cache = new Map<string, { at: number; data: unknown }>();

async function api<T>(path: string): Promise<T> {
  const url = `${API_BASE}/${path}`;
  const hit = cache.get(url);
  if (hit && Date.now() - hit.at < CACHE_TTL) return hit.data as T;
  const res = await fetchWithTimeout(url);
  const body = await res.text();
  let data: unknown;
  try {
    data = JSON.parse(body);
  } catch {
    throw new Error(`Ranatec API returned non-JSON (${res.status}) for ${url}`);
  }
  if (!res.ok) {
    const msg = (data as { message?: string })?.message ?? res.statusText;
    throw new ApiError(res.status, msg, data);
  }
  cache.set(url, { at: Date.now(), data });
  return data as T;
}

class ApiError extends Error {
  constructor(public status: number, message: string, public body: unknown) {
    super(message);
  }
}

const products = async () => (await api<{ products: Product[] }>("products.json")).products;
const news = async () => (await api<{ news: NewsItem[] }>("news.json")).news;
const faq = async () => (await api<{ faq: Faq[] }>("faq.json")).faq;

function ok(data: unknown) {
  return { content: [{ type: "text" as const, text: JSON.stringify(data, null, 2) }] };
}
function fail(message: string, extra?: unknown) {
  return { isError: true, content: [{ type: "text" as const, text: JSON.stringify({ error: message, ...(extra ? { details: extra } : {}) }, null, 2) }] };
}
async function guard(fn: () => Promise<ReturnType<typeof ok>>) {
  try {
    return await fn();
  } catch (err) {
    if (err instanceof ApiError) return fail(err.message, err.body);
    const e = err as Error;
    return fail(e.name === "AbortError" ? `Ranatec API timed out after ${API_TIMEOUT} ms` : e.message);
  }
}

const modelKey = (s: string | null | undefined) => (s ?? "").toLowerCase().replace(/[^a-z0-9]/g, "");
const summarise = (p: Product) => ({ id: p.id, name: p.name, model_number: p.model_number, summary: p.summary, categories: p.categories, domain: p.domain, listing: p.listing, url: p.url, urls: p.urls });
const newsSummary = (n: NewsItem) => ({ id: n.id, title: n.title, type: n.type, date_published: n.date_published, summary: n.summary, related_product_ids: n.related_product_ids, url: n.url, urls: n.urls });

function findProduct(list: Product[], ref: string): Product | undefined {
  const byId = list.find((p) => p.id === ref);
  if (byId) return byId;
  const k = modelKey(ref);
  return list.find((p) => p.listing === "catalogue" && modelKey(p.model_number) === k)
    ?? list.find((p) => modelKey(p.model_number) === k)
    ?? list.find((p) => p.listing === "catalogue" && modelKey(p.name).includes(k) && k.length >= 4);
}

// ----------------------------------------------------------------------------- tools

export const TOOLS = [
  { name: "get_company", phase: 1, method: "GET company.json", description: "Ranatec AB company profile: description, HQ address, contact (info@ranatec.com, +46 31 706 16 60), founding year, Qamcom Group ownership, ISO 9001:2015, product domains, industries, distribution partners, press contacts, careers and the three regional locales (en-US, en-GB, en-CA)." },
  { name: "list_products", phase: 1, method: "GET products.json", description: "List Ranatec RF test & measurement products (summary fields). Filter by category id, domain (filtering|shielding|switching|automation), listing (catalogue|additional), model number or free-text query. Prices are never published — products are sold by request for quote." },
  { name: "get_product", phase: 1, method: "GET products/{id}.json", description: "Full details for one product — description, applications, features, specification table, interfaces, ordering notes, accessories, datasheet PDFs and en-US/en-GB/en-CA URLs. Accepts the product id (slug) or a model number such as 'RI 268', 'RF2037' or 'RI 3101'." },
  { name: "compare_products", phase: 1, method: "GET products.json", description: "Compare 2–6 products side by side: summary plus every specification parameter aligned in one table. Accepts ids or model numbers." },
  { name: "list_categories", phase: 1, method: "GET categories.json", description: "Product categories (tunable band reject/pass filters, Butler matrices, attenuators, switch boxes and modules, shield boxes, forensic box, feedthrough filters, EMI ventilation panels, accessories) with summaries and product ids." },
  { name: "list_solutions", phase: 1, method: "GET solutions.json", description: "Ranatec's product domains and solution areas: wireless test automation, RF shielded enclosures, customised RF test equipment." },
  { name: "list_news", phase: 1, method: "GET news.json", description: "News, product launches, technical articles, customer orders and events (newest first). Filter by type, year, related product id or text." },
  { name: "get_news_item", phase: 1, method: "GET news/{id}.json", description: "Full text of one news item or technical article by id." },
  { name: "search", phase: 1, method: "GET products.json, news.json, faq.json", description: "Full-text search across products (including specifications), news/articles and FAQ. Use for questions like 'which product covers 26.5 GHz?' or 'USB 3.2 feedthrough'." },
  { name: "get_faq", phase: 1, method: "GET faq.json", description: "Frequently asked questions about Ranatec and RF test equipment, with answers and source URLs." },
  { name: "list_pages", phase: 1, method: "GET pages.json", description: "ranatec.com pages (home, about, contact, solutions, news, shop, RFQ, …) with en-US, en-GB and en-CA URLs." },
  { name: "submit_inquiry", phase: 2, method: "POST contact.json", description: "Send a quote request or enquiry to Ranatec AB on behalf of the user. Before calling, show the user exactly what will be sent (their name, email, company, products/quantities, message) and get explicit confirmation. You MUST set agent_context.user_authorized_submission to true to confirm that consent was given. quote_request needs at least one product id." },
] as const;

const desc = (n: (typeof TOOLS)[number]["name"]) => TOOLS.find((t) => t.name === n)!.description;
const READ_ONLY = { readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false };

function buildServer(): McpServer {
  const server = new McpServer({ name: NAME, version: VERSION }, {
    instructions: "Ranatec AB (Gothenburg, Sweden) manufactures RF test & measurement equipment. Prices are never published: for pricing, lead times or availability, offer to submit a quote request (submit_inquiry, only with explicit user consent) or refer to info@ranatec.com / +46 31 706 16 60. Quote specifications from get_product or the datasheet links; do not guess. The website exists in three regional English versions (en-US, en-GB, en-CA) with identical content — give users the URL for their region when known.",
  });

  server.registerTool("get_company", { title: "Ranatec company profile", description: desc("get_company"), inputSchema: {}, annotations: READ_ONLY },
    async () => guard(async () => ok(await api("company.json"))));

  server.registerTool("list_products", {
    title: "List products", description: desc("list_products"), annotations: READ_ONLY,
    inputSchema: {
      category: z.string().optional().describe("Category id, e.g. tunable-band-reject-filter, switch-box, shielded-feedthrough-filters, accessories"),
      domain: z.enum(["filtering", "shielding", "switching", "automation"]).optional(),
      listing: z.enum(["catalogue", "additional"]).optional().describe("catalogue = main product menu items (default view for recommendations)"),
      model: z.string().optional().describe("Model number, e.g. 'RI 268' or 'RF2037'"),
      query: z.string().optional().describe("Free-text search over all product fields"),
    },
  }, async ({ category, domain, listing, model, query }) => guard(async () => {
    const q = query?.toLowerCase();
    const list = (await products()).filter((p) =>
      (!category || p.categories.includes(category)) && (!domain || p.domain === domain) && (!listing || p.listing === listing)
      && (!model || modelKey(p.model_number) === modelKey(model)) && (!q || JSON.stringify(p).toLowerCase().includes(q)));
    return ok({ count: list.length, pricing: "request-for-quote (no public prices)", products: list.map(summarise) });
  }));

  server.registerTool("get_product", {
    title: "Get product details", description: desc("get_product"), annotations: READ_ONLY,
    inputSchema: { id: z.string().min(2).describe("Product id (slug) or model number, e.g. 'tunable-band-reject-filter-ri-268' or 'RI 268'") },
  }, async ({ id }) => guard(async () => {
    const p = findProduct(await products(), id);
    if (!p) return fail(`No product matches '${id}'. Use list_products or search to find ids.`);
    const extra = p.primary_listing ? { note: `This is an additional shop listing; the catalogue item is '${p.primary_listing}'.` } : {};
    return ok({ ...p, ...extra });
  }));

  server.registerTool("compare_products", {
    title: "Compare products", description: desc("compare_products"), annotations: READ_ONLY,
    inputSchema: { ids: z.array(z.string()).min(2).max(6).describe("Product ids or model numbers") },
  }, async ({ ids }) => guard(async () => {
    const list = await products();
    const found = ids.map((i) => ({ ref: i, p: findProduct(list, i) }));
    const missing = found.filter((f) => !f.p).map((f) => f.ref);
    if (missing.length) return fail(`Unknown product(s): ${missing.join(", ")}`);
    const ps = found.map((f) => f.p!);
    const params: string[] = [];
    ps.forEach((p) => p.specifications.forEach((s) => { if (!params.includes(s.parameter)) params.push(s.parameter); }));
    const val = (p: Product, k: string) => {
      const v = p.specifications.find((s) => s.parameter === k)?.value;
      return v == null ? null : Array.isArray(v) ? v.join(", ") : v;
    };
    return ok({
      products: ps.map((p) => ({ id: p.id, name: p.name, model_number: p.model_number, summary: p.summary, url: p.url, datasheets: p.datasheets })),
      specifications: params.map((k) => ({ parameter: k, values: Object.fromEntries(ps.map((p) => [p.model_number ?? p.id, val(p, k)])) })),
      features: Object.fromEntries(ps.map((p) => [p.model_number ?? p.id, p.features])),
      note: "Parameter names are as published per product; null = not listed on that product page (check the datasheet).",
    });
  }));

  server.registerTool("list_categories", { title: "List categories", description: desc("list_categories"), inputSchema: { domain: z.enum(["filtering", "shielding", "switching", "automation"]).optional() }, annotations: READ_ONLY },
    async ({ domain }) => guard(async () => {
      const d = await api<{ categories: Array<Record<string, unknown>> }>("categories.json");
      const cats = d.categories.filter((c) => !domain || c.domain === domain).map(({ overview: _o, meta_description: _m, ...rest }) => rest);
      return ok({ count: cats.length, categories: cats });
    }));

  server.registerTool("list_solutions", { title: "List solutions", description: desc("list_solutions"), inputSchema: {}, annotations: READ_ONLY },
    async () => guard(async () => ok(await api("solutions.json"))));

  server.registerTool("list_news", {
    title: "List news", description: desc("list_news"), annotations: READ_ONLY,
    inputSchema: {
      type: z.enum(["product-launch", "technical-article", "company-news", "event", "distribution-partner"]).optional(),
      year: z.string().regex(/^\d{4}$/).optional(),
      product: z.string().optional().describe("Only items related to this product id"),
      query: z.string().optional(),
      limit: z.number().int().min(1).max(100).optional().describe("Default 20"),
    },
  }, async ({ type, year, product, query, limit }) => guard(async () => {
    const q = query?.toLowerCase();
    const list = (await news()).filter((n) => (!type || n.type === type) && (!year || n.date_published?.startsWith(year))
      && (!product || n.related_product_ids.includes(product)) && (!q || JSON.stringify(n).toLowerCase().includes(q)));
    return ok({ count: list.length, news: list.slice(0, limit ?? 20).map(newsSummary) });
  }));

  server.registerTool("get_news_item", { title: "Get news item", description: desc("get_news_item"), inputSchema: { id: z.string().min(2) }, annotations: READ_ONLY },
    async ({ id }) => guard(async () => {
      const n = (await news()).find((x) => x.id === id);
      return n ? ok(n) : fail(`No news item '${id}'. Use list_news to find ids.`);
    }));

  server.registerTool("search", {
    title: "Search", description: desc("search"), annotations: READ_ONLY,
    inputSchema: { query: z.string().min(2), limit: z.number().int().min(1).max(50).optional().describe("Per section, default 10") },
  }, async ({ query, limit }) => guard(async () => {
    const terms = query.toLowerCase().split(/\s+/).filter(Boolean);
    const score = (text: string, title: string) => terms.reduce((s, t) => s + (text.includes(t) ? 1 : 0) + (title.includes(t) ? 2 : 0), 0);
    const n = limit ?? 10;
    const [ps, ns, fs] = await Promise.all([products(), news(), faq()]);
    const rank = <T,>(xs: T[], f: (x: T) => number) => xs.map((x) => ({ x, s: f(x) })).filter((r) => r.s >= terms.length).sort((a, b) => b.s - a.s).slice(0, n).map((r) => r.x);
    return ok({
      query,
      products: rank(ps, (p) => score(JSON.stringify(p).toLowerCase(), `${p.name} ${p.model_number ?? ""} ${p.summary ?? ""}`.toLowerCase()) + (p.listing === "catalogue" ? 0.5 : 0)).map(summarise),
      news: rank(ns, (x) => score(JSON.stringify(x).toLowerCase(), x.title.toLowerCase())).map(newsSummary),
      faq: rank(fs, (f) => score(`${f.q} ${f.a}`.toLowerCase(), f.q.toLowerCase())),
    });
  }));

  server.registerTool("get_faq", { title: "FAQ", description: desc("get_faq"), inputSchema: {}, annotations: READ_ONLY },
    async () => guard(async () => ok(await api("faq.json"))));

  server.registerTool("list_pages", { title: "List pages", description: desc("list_pages"), inputSchema: {}, annotations: READ_ONLY },
    async () => guard(async () => ok(await api("pages.json"))));

  server.registerTool("submit_inquiry", {
    title: "Submit quote request / enquiry to Ranatec",
    description: desc("submit_inquiry"),
    annotations: { readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: true },
    inputSchema: {
      agent_context: z.object({
        user_authorized_submission: z.literal(true).describe("Must be true — the user explicitly approved sending this enquiry and their contact details to Ranatec AB"),
        agent_name: z.string().max(120).optional(),
        user_request_summary: z.string().max(500).optional(),
      }),
      person: z.object({ name: z.string().min(1).max(120), email: z.string().email(), phone: z.string().max(40).optional(), job_title: z.string().max(120).optional() }),
      company: z.object({ name: z.string().min(1).max(160), country: z.string().max(80).optional(), website: z.string().url().optional() }),
      inquiry: z.object({
        type: z.enum(["quote_request", "technical_question", "custom_solution", "distributor_inquiry", "general"]),
        message: z.string().min(10).max(5000),
        products: z.array(z.object({ id: z.string().describe("Product id from list_products/get_product"), quantity: z.number().int().min(1).max(10000).default(1) })).max(50).optional(),
        application: z.string().max(300).optional(),
        timeline: z.string().max(120).optional(),
        preferred_locale: z.enum(["en-US", "en-GB", "en-CA"]).optional(),
      }),
    },
  }, async (args) => guard(async () => {
    if (args.inquiry.type === "quote_request" && !args.inquiry.products?.length) {
      return fail("quote_request requires at least one product (use custom_solution for bespoke requirements).");
    }
    // Resolve model numbers to ids so agents can pass "RI 268".
    if (args.inquiry.products?.length) {
      const list = await products();
      for (const line of args.inquiry.products) {
        const p = findProduct(list, line.id);
        if (!p) return fail(`Unknown product '${line.id}'. Use list_products or search to find ids.`);
        line.id = p.id;
      }
    }
    const res = await fetchWithTimeout(`${API_BASE}/contact.json`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ ...args, agent_context: { agent_name: "ranatec-mcp client", ...args.agent_context } }),
    });
    const body = await res.json().catch(() => ({ error: `HTTP ${res.status}` }));
    return res.ok ? ok(body) : fail(`Submission rejected (HTTP ${res.status})`, body);
  }));

  return server;
}

// ----------------------------------------------------------------------------- HTTP app

const app = express();
app.disable("x-powered-by");
app.set("trust proxy", process.env.TRUST_PROXY ?? "loopback");

const toolList = () => ({ server: NAME, version: VERSION, endpoint: "/mcp", transport: "streamable-http (stateless)", api_base: API_BASE, count: TOOLS.length, tools: TOOLS });
app.get(["/health", "/mcp/health"], async (_req, res) => {
  let upstream = "unknown";
  try {
    const r = await fetchWithTimeout(`${API_BASE}/index.json`, {}, 5000);
    upstream = r.ok ? "ok" : `http_${r.status}`;
  } catch {
    upstream = "unreachable";
  }
  res.status(upstream === "ok" ? 200 : 503).json({ status: upstream === "ok" ? "ok" : "degraded", name: NAME, version: VERSION, api_base: API_BASE, upstream });
});
app.get(["/tools", "/mcp/tools"], (_req, res) => { res.json(toolList()); });

const limiter = rateLimit({ windowMs: 60 * 1000, limit: RATE_LIMIT, standardHeaders: true, legacyHeaders: false });
app.use("/mcp", limiter);
app.use("/mcp", express.json({ limit: "100kb" }));

app.post("/mcp", async (req: Request, res: Response) => {
  const transport = new StreamableHTTPServerTransport({ sessionIdGenerator: undefined });
  const server = buildServer();
  res.on("close", () => {
    transport.close().catch(() => {});
    server.close().catch(() => {});
  });
  try {
    await server.connect(transport);
    await transport.handleRequest(req, res, req.body);
  } catch (err) {
    console.error("MCP handler error:", err);
    if (!res.headersSent) res.status(500).json({ jsonrpc: "2.0", error: { code: -32603, message: "Internal MCP server error" }, id: null });
  }
});

// Stateless server: no server-initiated SSE stream and no sessions to delete.
const methodNotAllowed = (_req: Request, res: Response) => {
  res.status(405).set("Allow", "POST").json({ jsonrpc: "2.0", error: { code: -32000, message: "Method not allowed. This MCP server is stateless: use POST /mcp. Tool list: GET /mcp/tools" }, id: null });
};
app.get("/mcp", methodNotAllowed);
app.delete("/mcp", methodNotAllowed);

app.listen(PORT, () => {
  console.log(`${NAME} ${VERSION} listening on :${PORT} (API ${API_BASE})`);
});
