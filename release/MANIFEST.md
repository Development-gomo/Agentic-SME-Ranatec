# Ranatec Agentic Web package — release 1.0.1

Built from the ranatec.com crawl of 2026-10-06.

| File | What it is | How to use |
|---|---|---|
| `ranatec-api-1.0.1.zip` | WordPress plugin: agent page, llms.txt, llms-full.txt, ai.txt, API catalogs, OpenAPI, REST API, contact/RFQ endpoint, daily sync | WordPress → Plugins → Add New → Upload → Activate → Settings → Permalinks → Save |
| `ranatec-agentic-web-package-1.0.1.zip` | Complete package: plugin, MCP server source, web-root copies, robots.txt additions, build tools, tests, docs | Hand-over / archive; see README.md and docs/DEPLOYMENT.md inside |

## Changes in 1.0.1 (QA fixes)
- **mbstring no longer required.** `mb_strlen()` / `mb_substr()` calls in the contact handler and the sync are replaced by `Ranatec_Agent_API::str_len()` / `str_sub()`, which use mbstring when present and a UTF-8 PCRE fallback otherwise. Verified: the 1.0.0 code fatals under `php -n` (no mbstring); 1.0.1 accepts the same submission with non-ASCII names intact.
- **One-command verification:** `bash tests/run-all.sh` (36 checks), which runs `npm ci` for the MCP server when `node_modules` is missing. New `npm run typecheck` script. docs/DEPLOYMENT.md has a new "Verify before deploying" step.

## SHA-256
```
d3f8a0d2c318cfe43b785819193a37dddde166637fffc840c2aa81dd6124a1cf  ranatec-agentic-web-package-1.0.1.zip
cfba7aa9b2a585b13916a84865c2d8978e9204cde8a7a0c90cc7c5031c996613  ranatec-api-1.0.1.zip
```

## Final check
- `npm ci && npm run build && npm test` from a clean install: passed (14/14 MCP checks)
- `bash tests/run-all.sh`: 36 passed, 0 failed on 31 consecutive runs (incl. contact endpoint with mbstring disabled). One earlier run reported a single failure that could not be reproduced and printed no detail; the script now prints diagnostics on failure and checks that both test servers started.
- Sitemap: 262/262 URLs covered (9 cart/checkout/account pages skipped on purpose)
- Scraped: 258 site pages + 2 careers pages + 98 regional product pages, 0 pending
- 425 specification rows, 0 mismatches against Firecrawl
- OpenAPI 3.0.3 valid; API responses validated against the OpenAPI schemas, 0 errors
- 654 ranatec.com URLs referenced by the package: 0 broken
- No secrets in the repository
