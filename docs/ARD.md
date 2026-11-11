# Agentic Resource Discovery (ARD)

The package publishes an **ARD manifest** at `/.well-known/ard.json`. It follows
[ARD v0.91](https://agenticresourcediscovery.org/spec/) ([spec repository](https://github.com/ards-project/ard-spec)),
the current published version.

ARD is a single, domain-anchored list of a website's *agentic resources* (MCP servers, APIs, A2A agents, skills),
in a format that AI agents, registries and Lighthouse can read. It doesn't replace any existing Agentic Web
feature; it points at them:

| Existing feature | Its role | How ARD relates to it |
|---|---|---|
| MCP server (`ranatec-mcp`) | Tools that agents call | One ARD entry of type `application/mcp-server-card+json`, with an inline MCP Server Card (SEP-2127) giving its endpoint |
| OpenAPI (`/openapi.json`) and REST API (`/agent/v1`) | Structured data and the quote/enquiry actions | One ARD entry of type `application/vnd.oai.openapi+json` pointing at `/openapi.json` |
| `/.well-known/api-catalog` (RFC 9727) and `/api-catalog.json` (APIs.json) | API catalogs | Unchanged. ARD uses the same URLs, so the catalogs never disagree |
| `/agent/`, `llms.txt`, `llms-full.txt`, `ai.txt` | Content for LLMs | Unchanged. They aren't callable resources, so they aren't ARD entries |

Nothing is advertised that doesn't exist. A2A agents and Agent Skills only become entries when they are configured,
and none are configured for ranatec.com.

## How it works

```
ranatec-api/data/*.json ─┐
ranatec-api.php constants ├─► tools/build_static.py ──► tools/ard.py (shared core) ──► ranatec-api/public/ard.json
ranatec-mcp (name, ver.)  │        (existing generator)    build_manifest()            web-root/.well-known/ard.json
openapi.json ─────────────┘                                validate_manifest()
ard.config.json (optional) ──────────────────────────────►
                                     WordPress plugin: GET /.well-known/ard.json · <link rel="ard"> · robots.txt Agentmap · index.json
```

* **`tools/ard.py`** is the shared core. It is pure Python standard library, with no framework or website code. It
  builds the entries, enforces the spec (URN identifiers, media types, exactly one of `url`/`data`, 2–5
  `representativeQueries`), and refuses to publish credentials, signed URLs or internal/localhost addresses.
* **`tools/build_static.py`** gives the core the package's existing metadata, so nothing is configured twice:
  * site URL: `RANATEC_API_SITE`;
  * MCP endpoint: `RANATEC_MCP_URL`;
  * MCP name: `ranatec-mcp/src/index.ts`;
  * MCP version: `ranatec-mcp/package.json`;
  * MCP tools: the same list as the API catalog;
  * OpenAPI title, version and operations: `openapi.json`;
  * company name and description: `company.json`;
  * `representativeQueries`: the site's own FAQ questions.
* **The WordPress plugin** (`includes/class-ard.php`) publishes the generated file:

| Where | What |
|---|---|
| `GET /.well-known/ard.json` | `200`, `application/json; charset=utf-8`, `Access-Control-Allow-Origin: *`, `Cache-Control: public, max-age=3600`, ETag |
| Every WordPress page `<head>` | `<link rel="ard" type="application/json" href="https://<this site>/.well-known/ard.json">` |
| The static `/agent/` page | The same `<link rel="ard">`, plus a visible **ard.json** link in its *LLM Discovery* list (both removed at serve time while ARD is switched off) |
| WordPress' virtual robots.txt | `Agentmap: https://<this site>/.well-known/ard.json` (only if no Agentmap line exists) |
| `/agent/v1/index.json` | `discovery.ard_manifest` |
| `web-root/robots-addition.txt` | `Agentmap:` and `Allow:` lines for sites with a physical robots.txt |

  The link and `Agentmap` use the site's own **home URL**, so staging advertises staging's copy and production
  advertises production's. The plugin also flushes its rewrite rules once per plugin version, so
  `/.well-known/ard.json` works right after an upgrade without a manual "Save Permalinks".

## Enabled by default

No setup is needed. On every build (`bash tools/release.sh` or `python3 tools/build_static.py`), the manifest is
generated whenever the package has at least one real resource to publish. A plugin install or update then
serves it at once.

If there is nothing to publish (no MCP server, no OpenAPI), no file is written and the URL returns `404`. The
package never publishes an empty placeholder manifest.

## Registering resources and custom entries

Resources the package knows about (MCP server, OpenAPI API) are discovered automatically. To add or adjust
anything, copy `ard.config.example.json` to **`ard.config.json`** in the repository root and rebuild. All keys
are optional:

| Key | Purpose |
|---|---|
| `enabled` | `false` = no manifest and no references at all (see below) |
| `publisher` | FQDN for `urn:air:<publisher>:…` identifiers. Default: site host without `www.`. Set it to keep identifiers identical across environments. |
| `autoDiscover` | `{"mcp": false}` or `{"openapi": false}` drops an auto-discovered resource |
| `overrides` | Replace generated terms, keyed by kind (`mcp`, `openapi`, `a2a`, `skills`, `custom`) or by identifier; `null` removes a term |
| `a2a` | A2A agents: `[{"name", "cardUrl", "description", "skills", "queries"}]` |
| `skills` | Agent Skills: `[{"name", "url", "format": "md\|json\|zip\|gzip", "description", "queries"}]` |
| `mcp`, `openapi` | Additional MCP servers / APIs, with the same fields the package passes |
| `entries` | Any other resource as a raw ARD entry. `identifier` is derived from `namespace` + `displayName` when omitted, and a relative `url` is resolved against the site. |

Example: register a published A2A agent and override the MCP queries.

```json
{
  "a2a": [{ "name": "sales-assistant", "cardUrl": "/.well-known/agent-card.json",
            "description": "Answers product questions and prepares quotes.",
            "queries": ["which shield box fits a laptop", "prepare a quote for RI 181"] }],
  "overrides": { "mcp": { "representativeQueries": ["compare RI 268 and RI 270", "request a quote for RI 181"] } }
}
```

The build fails, and publishes nothing, if an entry is invalid, has a duplicate identifier, or contains a
credential-like member or a private address.

## Disabling ARD

| How | Effect |
|---|---|
| **WordPress → Tools → Ranatec Agent API → untick "Publish the ARD manifest" → Save** (runtime) | `/.well-known/ard.json` returns `404`. These all disappear: `<link rel="ard">` (pages and `/agent/`), the virtual-robots `Agentmap:` line, and `index.json → ard_manifest`. Purge LiteSpeed/CDN caches afterwards. |
| **`ard.config.json` → `"enabled": false`, then rebuild** (build time) | No `ard.json` is generated. Stale copies are removed, and the `/agent/` link and the `robots-addition.txt` lines are not written. |

If you pasted the `Agentmap:` line into a **physical** robots.txt, remove it by hand.

## Other website architectures

The core has no WordPress dependency. For a project without this plugin, for example **Next.js** or a
**headless WordPress + Next.js** frontend, describe the resources in an `ard.config.json` that includes `site`,
then build into the framework's static folder:

```json
{
  "site": { "url": "https://www.example.com", "name": "Example Ltd", "description": "…" },
  "mcp": [{ "name": "example-mcp", "title": "Example MCP server", "endpoint": "https://mcp.example.com/mcp",
            "version": "1.0.0", "description": "Product data and quote tools.", "tools": [{"name": "search"}],
            "queries": ["find a product", "request a quote"] }],
  "openapi": [{ "url": "/openapi.json", "title": "Example API", "operations": ["listProducts"],
                "queries": ["list products", "get product details"] }]
}
```

```bash
python3 tools/ard.py build --config ard.config.json --out public/.well-known/ard.json
python3 tools/ard.py build --config ard.config.json --site-url https://staging.example.com --out public/.well-known/ard.json   # per environment
```

Next.js serves `public/.well-known/ard.json` as `application/json`. Then:
* Put `<link rel="ard" type="application/json" href="/.well-known/ard.json" />` in the `<head>` of
  `app/layout.tsx` (App Router) or `pages/_document.tsx` (Pages Router).
* Add `Agentmap: https://www.example.com/.well-known/ard.json` to `public/robots.txt`.
* Keep `/.well-known/` excluded from middleware redirects, locale prefixes and auth.

Run the `build` command in the project's build step (e.g. `prebuild`), so the manifest always matches the
deployed resources.

## Validating the manifest

```bash
python3 tools/ard.py validate ranatec-api/public/ard.json                                  # local file
python3 tools/ard.py validate https://ranatec.com/.well-known/ard.json --check-urls        # live: also fetches every advertised url
python3 tools/vendor/ard-spec/conformance/bin/conformance-test publisher ranatec.com       # official tool, consumer resolution (§5.1)
python3 -m unittest tests/test_ard.py                                                      # ARD test suite (also part of bash tests/run-all.sh)
```

`validate` runs, in order:
1. the official ARD JSON Schema (`ArdManifest`/`ArdEntry`);
2. the official conformance tool (vendored unmodified in `tools/vendor/`, at the same spec commit that
   Lighthouse 13.6's `ard-schema` audit is ported from);
3. the MCP Server Card JSON Schema for inline cards.

Expected output:
`CONFORMANCE STATUS: PASS | 0 critical specification errors and 0 warnings and 1 informational messages` and
`VALID: 2 entries, 0 errors, 0 warnings`. The informational message notes that the OpenAPI media type is an
extension type. ARD permits it, and Lighthouse does not count it.

Strict schema validation needs the Python `jsonschema` package (`pip install jsonschema`). Without it, the other
checks still run and a note says the schema step was skipped.

### Lighthouse

The Lighthouse `ard-schema` audit ("ARD manifest is valid") does the following:

1. It looks for the manifest in this order: the robots.txt `Agentmap:` line, `<link rel="ard">`, an HTTP `Link`
   header with `rel="ard"`, then `/.well-known/ard.json`, then the legacy `ai-catalog` names.
2. It fetches the first one that answers `200`.
3. It runs the conformance rules on that manifest.

Scoring:
* **1.0** with no errors and no warnings.
* **0.9** if there are warnings, e.g. missing `representativeQueries`, or an advertised location that failed.
* **0** for errors, or if the manifest could not be loaded.

Run it against a deployed site:

```bash
npx lighthouse https://ranatec.com/ --only-audits=ard-schema --output=json --quiet --chrome-flags="--headless" \
  | python3 -c "import json,sys; a=json.load(sys.stdin)['audits']['ard-schema']; print(a['score'], a['title'], a.get('explanation',''))"
```

## Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| Lighthouse: **"ARD manifest is invalid — ARD manifest could not be loaded for schema validation."** | No `200` from any location Lighthouse tried: a 404/5xx, a timeout, or a blocked request. On ranatec.com before this release, `/.well-known/ard.json` was a 404 HTML page. | Deploy this release. Then check `curl -sI https://<site>/.well-known/ard.json` shows `200` and `application/json`. |
| `404` after updating the plugin | Rewrite rules not refreshed (e.g. a cached object store), or ARD switched off | Tools → Ranatec Agent API → **Flush rewrite rules**, and check the ARD box. |
| `404` HTML from the web server, not WordPress | The server answers `/.well-known/` itself (some cPanel setups), so the request never reaches WordPress | Upload `web-root/.well-known/ard.json` to `public_html/.well-known/ard.json`. Static files take precedence, so re-upload after every rebuild. |
| `403` / challenge page | WAF, ModSecurity, Cloudflare Bot Fight or a security plugin blocking `/.well-known/*` or JSON for bots | Allow-list `/.well-known/ard.json` (and `/openapi.json`) for all user agents, including `Chrome-Lighthouse`. |
| Old content after switching ARD off or rebuilding | LiteSpeed Cache / CDN | LiteSpeed → Toolbox → **Purge All**, and purge the CDN. |
| `301` to `/.well-known/ard.json/` or a language prefix | Trailing-slash or WPML redirect | The plugin disables canonical redirects for its routes. Remove any custom redirect rule for `/.well-known/`. |
| Score 0.9 with "Advertised ARD manifest location could not be loaded" | A robots.txt `Agentmap:` line or a `<link rel="ard">` points at a URL that fails, e.g. production pasted into staging's robots.txt | Fix or remove the stale reference. Lighthouse tries advertised locations first. |
| Score 0.9 with a `representativeQueries` warning | A custom entry has fewer than 2 or more than 5 queries | Give each entry 2–5 queries. |

## Example manifest (generated by this package for ranatec.com)

<!-- ARD-EXAMPLE:START -->
```json
{
  "host": {
    "displayName": "Ranatec AB",
    "identifier": "https://ranatec.com",
    "documentationUrl": "https://ranatec.com/agent/",
    "description": "Ranatec designs and manufactures niche RF test and measurement equipment for filtering, shielding, switching and automation — used for design verification, product certification, production testing, expert troubleshooting and in-service monitoring of wireless devices and systems."
  },
  "entries": [
    {
      "identifier": "urn:air:ranatec.com:mcp:ranatec-mcp",
      "displayName": "Ranatec MCP server",
      "type": "application/mcp-server-card+json",
      "description": "Model Context Protocol server for Ranatec AB (RF test and measurement equipment): product specifications, comparisons, categories, news, FAQ and company data, plus consent-gated tools to request a product quote (request_quote) or send a contact enquiry (submit_inquiry).",
      "capabilities": [
        "get_company",
        "list_products",
        "get_product",
        "compare_products",
        "list_categories",
        "list_solutions",
        "list_news",
        "get_news_item",
        "search",
        "get_faq",
        "list_pages",
        "request_quote",
        "submit_inquiry"
      ],
      "representativeQueries": [
        "What does Ranatec make?",
        "What is RF equipment?",
        "Where can an RF shielded enclosure be used?",
        "What is an RF shielded test enclosure?",
        "What are the advantages of RF test equipment from Ranatec?"
      ],
      "tags": [
        "mcp",
        "rf-test-equipment",
        "product-catalog",
        "request-for-quote"
      ],
      "version": "1.2.2",
      "updatedAt": "2026-10-06T00:00:00Z",
      "metadata": {
        "transport": "streamable-http",
        "endpoint": "https://agentic-mcp-sme-ranatec.onrender.com/mcp"
      },
      "data": {
        "$schema": "https://static.modelcontextprotocol.io/schemas/v1/server-card.schema.json",
        "name": "com.ranatec/ranatec-mcp",
        "version": "1.2.2",
        "description": "Ranatec products, company, news and FAQ, plus consent-gated quote and enquiry tools.",
        "title": "Ranatec MCP server",
        "websiteUrl": "https://ranatec.com/agent/",
        "remotes": [
          {
            "type": "streamable-http",
            "url": "https://agentic-mcp-sme-ranatec.onrender.com/mcp"
          }
        ]
      }
    },
    {
      "identifier": "urn:air:ranatec.com:api:ranatec-agent-api",
      "displayName": "Ranatec Agent API",
      "type": "application/vnd.oai.openapi+json",
      "url": "https://ranatec.com/openapi.json",
      "description": "Read-only JSON API describing Ranatec AB (Gothenburg, Sweden) — RF test & measurement equipment for filtering, shielding, switching and automation — plus two consent-gated POST endpoints: product quote requests and contact enquiries. Content mirrors ranatec.com in three regional English locales (en-US default, en-GB, en-CA); every entity lists all three URLs. Ranatec publishes no prices: all products are sold B2B via request for quote.",
      "capabilities": [
        "getIndex",
        "getSchema",
        "getCompany",
        "listProducts",
        "getProduct",
        "listCategories",
        "getCategory",
        "listSolutions",
        "listNews",
        "getNewsItem",
        "listPages",
        "getFaq",
        "requestQuote",
        "submitInquiry"
      ],
      "representativeQueries": [
        "What does Ranatec make?",
        "What is RF equipment?",
        "Where can an RF shielded enclosure be used?",
        "What is an RF shielded test enclosure?",
        "What are the advantages of RF test equipment from Ranatec?"
      ],
      "tags": [
        "rest",
        "openapi",
        "rf-test-equipment",
        "product-catalog"
      ],
      "version": "1.2.2",
      "updatedAt": "2026-10-06T00:00:00Z",
      "metadata": {
        "baseUrl": "https://ranatec.com/agent/v1",
        "openapiVersion": "3.0.3"
      }
    }
  ]
}
```
<!-- ARD-EXAMPLE:END -->
