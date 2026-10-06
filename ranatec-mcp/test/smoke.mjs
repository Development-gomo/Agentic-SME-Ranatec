// Smoke test: connects to a running ranatec-mcp server with the official MCP client and exercises every tool.
// Usage: MCP_URL=http://127.0.0.1:3000/mcp node test/smoke.mjs
import { Client } from "@modelcontextprotocol/sdk/client/index.js";
import { StreamableHTTPClientTransport } from "@modelcontextprotocol/sdk/client/streamableHttp.js";

const url = new URL(process.env.MCP_URL ?? "http://127.0.0.1:3000/mcp");
const client = new Client({ name: "ranatec-smoke", version: "1.0.0" });
await client.connect(new StreamableHTTPClientTransport(url));
const parse = (r) => JSON.parse(r.content[0].text);
let failures = 0;
const check = (name, cond, info = "") => { console.log(`${cond ? "PASS" : "FAIL"} ${name}${info ? " — " + info : ""}`); if (!cond) failures++; };

const { tools } = await client.listTools();
check("listTools returns 12 tools", tools.length === 12, tools.map((t) => t.name).join(", "));

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

const noConsent = await client.callTool({ name: "submit_inquiry", arguments: {
  agent_context: { user_authorized_submission: false }, person: { name: "T", email: "t@example.com" }, company: { name: "X" },
  inquiry: { type: "general", message: "Hello there, a question." } } }).catch((e) => ({ isError: true, content: [{ text: String(e) }] }));
check("submit_inquiry rejects missing consent (Zod layer)", noConsent.isError === true, noConsent.content[0].text.slice(0, 90));

const sub = await client.callTool({ name: "submit_inquiry", arguments: {
  agent_context: { user_authorized_submission: true, agent_name: "smoke-test" },
  person: { name: "Smoke Test", email: "smoke@example.com" }, company: { name: "Example Labs", country: "Sweden" },
  inquiry: { type: "quote_request", message: "Please quote one RI 3101 Butler matrix.", products: [{ id: "RI 3101", quantity: 1 }] } } });
const subBody = parse(sub);
check("submit_inquiry with consent (resolves model number)", !sub.isError && subBody.status === "received", subBody.lead_id);

await client.close();
console.log(failures ? `\n${failures} FAILED` : "\nALL PASSED");
process.exit(failures ? 1 : 0);
