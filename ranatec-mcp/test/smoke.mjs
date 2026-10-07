// Smoke test: connects to a running ranatec-mcp server with the official MCP client and exercises every tool.
// Usage: MCP_URL=http://127.0.0.1:3000/mcp node test/smoke.mjs
import { Client } from "@modelcontextprotocol/sdk/client/index.js";
import { StreamableHTTPClientTransport } from "@modelcontextprotocol/sdk/client/streamableHttp.js";
import { readFileSync } from "node:fs";

const PKG_VERSION = JSON.parse(readFileSync(new URL("../package.json", import.meta.url), "utf8")).version;

const url = new URL(process.env.MCP_URL ?? "http://127.0.0.1:3000/mcp");
const client = new Client({ name: "ranatec-smoke", version: "1.0.0" });
await client.connect(new StreamableHTTPClientTransport(url));
const parse = (r) => JSON.parse(r.content[0].text);
let failures = 0;
const check = (name, cond, info = "") => { console.log(`${cond ? "PASS" : "FAIL"} ${name}${info ? " — " + info : ""}`); if (!cond) failures++; };

const health = await (await fetch(new URL("/mcp/health", url))).json();
check("health reports package version", health.version === PKG_VERSION, `${health.version} (package.json ${PKG_VERSION})`);
check("server info reports package version", client.getServerVersion()?.version === PKG_VERSION, client.getServerVersion()?.version);

// Plain HTTP (agents without an MCP connector): no handshake, Accept: application/json or none, plain JSON back.
for (const accept of ["application/json", null]) {
  const headers = { "Content-Type": "application/json" };
  if (accept) headers.Accept = accept;
  const r = await fetch(url, { method: "POST", headers, body: JSON.stringify({ jsonrpc: "2.0", id: 9, method: "tools/call", params: { name: "get_product", arguments: { id: "RI 268" } } }) });
  const body = await r.json().catch(() => null);
  check(`plain HTTP tools/call (Accept: ${accept ?? "none"})`, r.status === 200 && JSON.parse(body?.result?.content?.[0]?.text ?? "{}").model_number === "RI 268", `HTTP ${r.status} ${r.headers.get("content-type")}`);
}

const { tools } = await client.listTools();
check("listTools returns 13 tools", tools.length === 13, tools.map((t) => t.name).join(", "));

const company = parse(await client.callTool({ name: "get_company", arguments: {} }));
check("get_company", company.company?.legal_name === "Ranatec AB", company.company?.contact?.email);

const lp = parse(await client.callTool({ name: "list_products", arguments: { category: "butler-matrices" } }));
check("list_products category filter", lp.count === 2, lp.products.map((p) => p.model_number).join(", "));

const gp = parse(await client.callTool({ name: "get_product", arguments: { id: "RI 268" } }));
check("get_product by model number", gp.id === "tunable-band-reject-filter-ri-268", `${gp.specifications.length} spec rows`);

const cmp = parse(await client.callTool({ name: "compare_products", arguments: { ids: ["RF2037", "RF2038", "switch-box-rf2022b"] } }));
check("compare_products", cmp.products.length === 3 && cmp.specifications.length > 3, `${cmp.specifications.length} parameters`);

const cats = parse(await client.callTool({ name: "list_categories", arguments: { domain: "shielding" } }));
check("list_categories domain filter", cats.count === 4, cats.categories.map((c) => c.id).join(", "));

const sol = parse(await client.callTool({ name: "list_solutions", arguments: {} }));
check("list_solutions", sol.solutions.length === 3);

const ln = parse(await client.callTool({ name: "list_news", arguments: { type: "event" } }));
check("list_news type filter", ln.count === 3);

const ni = parse(await client.callTool({ name: "get_news_item", arguments: { id: "ranatec-delivers-5g-test-system-to-faang-client" } }));
check("get_news_item", ni.body.length > 3, ni.title);

const s = parse(await client.callTool({ name: "search", arguments: { query: "26.5 GHz switch" } }));
check("search", s.products.some((p) => p.id === "switch-box-rf2037"), s.products.map((p) => p.id).slice(0, 3).join(", "));

const f = parse(await client.callTool({ name: "get_faq", arguments: {} }));
check("get_faq", f.faq.length >= 10);

const pg = parse(await client.callTool({ name: "list_pages", arguments: {} }));
check("list_pages has 3 locale URLs", pg.pages.every((p) => p.urls["en-GB"] && p.urls["en-CA"]));

const CTX = { user_authorized_submission: true, agent_name: "smoke-test", user_request_summary: "Smoke test: user asked for this" };
const CUSTOMER = { first_name: "Smoke", last_name: "Test", email: "smoke@gomogroup.com", phone: "+46 31 000 00 00", company: "Example Labs",
  country: "SE", address_1: "Testgatan 1", city: "Göteborg", postcode: "41250" };
const txt = (r) => r.content[0].text.slice(0, 110).replace(/\n/g, " ");

// --- contact enquiry (submit_inquiry) — contact form only
const noConsent = await client.callTool({ name: "submit_inquiry", arguments: {
  agent_context: { user_authorized_submission: false }, person: { name: "T", email: "t@example.com", phone: "+46 31 000 00 00" }, company: { name: "X" },
  inquiry: { type: "general", message: "Hello there, a question." } } }).catch((e) => ({ isError: true, content: [{ text: String(e) }] }));
check("submit_inquiry rejects missing consent (Zod layer)", noConsent.isError === true, txt(noConsent));
const inqDesc = tools.find((t) => t.name === "submit_inquiry")?.description ?? "";
check("submit_inquiry description: contact form only, never while reviewing/testing", /CONTACT ENQUIRY/.test(inqDesc) && /request_quote instead/.test(inqDesc) && /NEVER a reason to submit/.test(inqDesc) && /dry_run/.test(inqDesc));
const enq = await client.callTool({ name: "submit_inquiry", arguments: {
  agent_context: CTX, person: { name: "Smoke Test", email: "smoke@gomogroup.com", phone: "+46 31 000 00 00" }, company: { name: "Example Labs" },
  inquiry: { type: "technical_question", message: "Is the RI 181 available with a USB-C feedthrough?" } } });
check("submit_inquiry → contact-form enquiry", !enq.isError && parse(enq).status === "received" && parse(enq).flow === "contact_form", parse(enq).lead_id);
const enqProducts = await client.callTool({ name: "submit_inquiry", arguments: {
  agent_context: CTX, person: { name: "Smoke Test", email: "smoke@gomogroup.com", phone: "+46 31 000 00 00" }, company: { name: "Example Labs" },
  inquiry: { type: "quote_request", message: "Please quote one RI 3101.", products: [{ id: "RI 3101", quantity: 1 }] } } });
check("submit_inquiry refuses products → points to request_quote", enqProducts.isError === true && /request_quote/.test(enqProducts.content[0].text), txt(enqProducts));

// --- product quote (request_quote) — quote checkout fields
const qDesc = tools.find((t) => t.name === "request_quote")?.description ?? "";
check("request_quote description lists the checkout fields and the policy", /first_name, last_name, email, phone, company, country/.test(qDesc) && /NEVER a reason to submit/.test(qDesc) && /ASK THE USER/.test(qDesc));
const q = await client.callTool({ name: "request_quote", arguments: { agent_context: CTX, customer: CUSTOMER, products: [{ id: "RI 3101", quantity: 1 }], note: "Smoke test" } });
check("request_quote → WooCommerce quote order (resolves model number)", !q.isError && parse(q).flow === "product_quote" && parse(q).order_number === "4242", parse(q).quote_id);
const { address_1, ...noAddress } = CUSTOMER;
const qMissing = await client.callTool({ name: "request_quote", arguments: { agent_context: CTX, customer: noAddress, products: [{ id: "RI 268" }] } }).catch((e) => ({ isError: true, content: [{ text: String(e) }] }));
check("request_quote requires the checkout address", qMissing.isError === true && /address_1/.test(qMissing.content[0].text), txt(qMissing));
const qUS = await client.callTool({ name: "request_quote", arguments: { agent_context: CTX, customer: { ...CUSTOMER, country: "US", postcode: "94105" }, products: [{ id: "RI 268" }] } });
check("request_quote requires state for US (plugin layer)", qUS.isError === true && /customer\.state/.test(qUS.content[0].text), txt(qUS));
const cfg = await client.callTool({ name: "request_quote", arguments: { agent_context: CTX, customer: CUSTOMER,
  products: [{ id: "RI 181", quantity: 2, configuration: [{ id: "RI 4182", quantity: 2 }, { id: "RI 4205", quantity: 1 }] }] } });
check("request_quote with per-unit configuration (RI 181 + RI 4182 ×2 + RI 4205 ×1)", !cfg.isError && parse(cfg).products?.[0]?.configuration?.length === 2, JSON.stringify(parse(cfg).products?.[0]?.configuration?.map((c) => `${c.quantity_per_unit}x ${c.id}`)));
const dry = await client.callTool({ name: "request_quote", arguments: { agent_context: { user_authorized_submission: true, dry_run: true }, customer: CUSTOMER, products: [{ id: "RI 268" }] } });
check("request_quote dry_run validates and sends nothing", !dry.isError && parse(dry).submitted === false, parse(dry).status);
const badCfg = await client.callTool({ name: "request_quote", arguments: { agent_context: CTX, customer: CUSTOMER, products: [{ id: "RI 181", quantity: 1, configuration: [{ id: "RI 3101", quantity: 1 }] }] } });
check("configuration rejects an option that is not on the product", badCfg.isError === true, txt(badCfg));

await client.close();
console.log(failures ? `\n${failures} FAILED` : "\nALL PASSED");
process.exit(failures ? 1 : 0);
