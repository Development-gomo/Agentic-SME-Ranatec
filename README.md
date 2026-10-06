# Ranatec — Agentic Web package

An AI-agent-ready layer for **[ranatec.com](https://ranatec.com/)** (WordPress + WooCommerce, Avada theme, Yoast SEO), built by GO MO Group from a full crawl on **2026-10-06**. It lets LLMs, AI search engines and tool-calling agents read Ranatec's complete product catalogue, company facts and news, and send consent-gated quote requests.

**Coverage:** all three regional English versions of the site: **en-US** (default, `/`), **en-GB** (`/en-gb/`) and **en-CA** (`/en-ca/`). The crawl showed their content is identical, so each entity is modelled once and carries all three URLs.

| Crawled | Count |
|---|---|
| Pages | 12 × 3 locales |
| News / articles | 44 × 3 locales (incl. 2 legacy articles still live only on en-GB / en-CA) |
| Product listings | 82 = 49 catalogue products + 20 accessories/options + 13 duplicate shop listings |
| Product categories | 11 + an "accessories" grouping |
| Specification rows | 425 (all cross-verified against an independent Firecrawl scrape: 0 mismatches) |
| Careers | career.ranatec.com (Teamtailor), 1 open position |
| Referenced URLs link-checked | 655 (all resolve; the 6 redirects are documented on purpose) |

## Deliverables

| Deliverable | File | Live URL (after deployment) |
|---|---|---|
| Machine-readable agent page | `ranatec-api/public/agent-page.html` | `https://ranatec.com/agent/` |
| llms.txt (llmstxt.org) | `ranatec-api/public/llms.txt` | `https://ranatec.com/llms.txt` |
| llms-full.txt (all specs + article text) | `ranatec-api/public/llms-full.txt` | `https://ranatec.com/llms-full.txt` |
| AI permissions | `ranatec-api/public/ai.txt` | `https://ranatec.com/ai.txt` |
| API catalog (APIs.json 0.16) | `ranatec-api/public/api-catalog.json` | `https://ranatec.com/api-catalog.json` |
| API catalog (RFC 9727 linkset) | `ranatec-api/public/well-known-api-catalog.json` | `https://ranatec.com/.well-known/api-catalog` |
| WordPress plugin (REST API, sync, admin) | `ranatec-api/` | `https://ranatec.com/agent/v1/index.json` |
| OpenAPI 3.0.3 spec (13 operations, 36 schemas) | `ranatec-api/openapi.json` | `https://ranatec.com/openapi.json` |
| MCP server (12 tools) | `ranatec-mcp/` | `https://ranatec.com/mcp` |
| robots.txt additions | `web-root/robots-addition.txt` | merge into `https://ranatec.com/robots.txt` |
| Site findings for the Ranatec team | `docs/FINDINGS.md` | |
| Deployment checklist (Oderland, step by step) | `docs/DEPLOYMENT-ODERLAND.md` | |
| Deployment reference (generic: nginx, Docker) | `docs/DEPLOYMENT.md` | |

`web-root/` contains copies of the static files in case you'd rather upload them to the web root than let the plugin serve them.

## How it fits together

```
                ┌──────────── ranatec.com (WordPress) ─────────────┐
 LLM crawlers ─►│ /agent/  /llms.txt  /llms-full.txt  /ai.txt      │  served by the ranatec-api plugin
                │ /api-catalog.json  /.well-known/api-catalog      │  (or by physical files in the web root)
 REST agents ──►│ /openapi.json   /agent/v1/*.json   POST contact  │──► wp_mail → info@ranatec.com
                └──────────────────────────▲────────────────────────┘
 MCP clients ──► /mcp  (nginx → Node ranatec-mcp) ── fetches ─┘
```

* **`ranatec-api/`**: WordPress plugin. It uses rewrite rules + `template_redirect`, not `/wp-json/`, and serves the JSON data files with `readfile()`. It supports filters (`?category=`, `?domain=`, `?q=`, `?type=`, `?year=`, `?product=`, `?fields=summary`) and lookup by id or model number (`/agent/v1/products/ri-268.json`). A daily WP-Cron sync keeps `news.json` / `products.json` in step with WordPress, and **Tools → Ranatec Agent API** has *Sync now*, *Flush rewrite rules* and the contact recipient.
* **Contact / RFQ**: `POST /agent/v1/contact.json`, with types `quote_request | technical_question | custom_solution | distributor_inquiry | general`. Product ids are validated against the catalogue, and requests are limited to 5 per IP per hour. Consent is enforced in **three layers**: the MCP tool description, the MCP Zod schema (`z.literal(true)`) and the PHP check (403).
* **`ranatec-mcp/`**: Node/TypeScript, `@modelcontextprotocol/sdk` 1.32, stateless Streamable HTTP, 100 req/min rate limit, 10 s upstream timeout and a 5-minute cache. Tools: `get_company`, `list_products`, `get_product`, `compare_products`, `list_categories`, `list_solutions`, `list_news`, `get_news_item`, `search`, `get_faq`, `list_pages`, `submit_inquiry`.
* **`tools/`**: the reproducible build pipeline (`crawl.sh` → `extract.py` → `build_data.py` → `build_static.py`). Every artifact is generated from `ranatec-api/data/*.json`, so the URLs stay consistent across all files.

## Refreshing content

| What changed on ranatec.com | Do this |
|---|---|
| New/edited news post or product | Nothing. The daily sync (or *Sync now*) updates the API. Then run `python3 tools/build_static.py` and redeploy `public/` so the agent page and llms files match. |
| New product specs/categories, larger changes | Full refresh: `FIRECRAWL_API_KEY=… tools/crawl.sh && python3 tools/extract.py && python3 tools/build_data.py && python3 tools/build_static.py` |

## Versioning and releases

The repo-root `VERSION` file is the single source of truth. `bash tools/release.sh` stamps it into the plugin header and `RANATEC_API_VERSION`, the MCP `package.json`/`package-lock.json` and every data file's `meta.version`. It then regenerates the OpenAPI spec, agent page and catalogs and runs `tools/check_versions.py` plus the full test suite. If everything passes, it writes `release/ranatec-api-<v>.zip`, `release/MANIFEST.md` and `release/ranatec-agentic-web-package-<v>.zip`. The package zip contains the plugin zip and manifest under `release/`. The MCP server reads its version from `package.json` at runtime, so `/mcp/health` always reports the released version.

To cut a new release: edit `VERSION`, run `bash tools/release.sh`, commit.

## Tests

One command runs everything (PHP lint, JSON, the contact endpoint with and without `mbstring`, HTTP endpoints and the MCP end-to-end test). It runs `npm ci` itself if `node_modules` is missing:

```bash
bash tests/run-all.sh      # 37 checks
```

Individual steps:

```bash
# Plugin (no WordPress needed: tests/wp-stubs.php stubs the WP functions used)
php tests/wp-stub-harness.php products '' GET 'category=butler-matrices&fields=summary' </dev/null
php -S 127.0.0.1:8088 tests/php-router.php        # full HTTP behaviour incl. content types

# MCP end-to-end against the local plugin
cd ranatec-mcp && npm install && npm run build
RANATEC_API_BASE=http://127.0.0.1:8088/agent/v1 PORT=3077 npm start &
MCP_URL=http://127.0.0.1:3077/mcp npm test        # 14 checks
```
