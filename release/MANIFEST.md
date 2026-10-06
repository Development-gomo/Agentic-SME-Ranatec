# Ranatec Agentic Web package — release 1.0.0

Built from the ranatec.com crawl of 2026-10-06. Final check passed on 2026-10-06 (see "Final check" below).

| File | What it is | How to use |
|---|---|---|
| `ranatec-api-1.0.0.zip` | WordPress plugin: agent page, llms.txt, llms-full.txt, ai.txt, API catalogs, OpenAPI, REST API, contact/RFQ endpoint, daily sync | WordPress → Plugins → Add New → Upload → Activate → Settings → Permalinks → Save |
| `ranatec-agentic-web-package-1.0.0.zip` | Complete package: plugin, MCP server source, web-root copies, robots.txt additions, build tools, tests, docs | Hand-over / archive; see README.md and docs/DEPLOYMENT.md inside |

## SHA-256
```
b2f46b5362d8eac33b8988daa104b3ff8350c84f0e121cb7418553606045015b  ranatec-agentic-web-package-1.0.0.zip
3df1614791371aa1f767b61683e824d62d6f5dfef9493ac717313dcddabd2a05  ranatec-api-1.0.0.zip
```

## Final check
- Sitemap: 262/262 URLs covered (9 cart/checkout/account pages skipped on purpose); sitemap unchanged since the crawl
- Scraped: 258 site pages + 2 careers pages + 98 regional product pages, 0 pending, 0 failed
- Data reproducible from the crawl (byte-identical); static artifacts regenerate identically
- 425 specification rows, 0 mismatches against Firecrawl
- PHP lint OK; all JSON valid; OpenAPI 3.0.3 valid; 14 API responses validated against the OpenAPI schemas, 0 errors
- 19 plugin endpoints return 200 with correct content types; contact without consent → 403
- MCP server: 14/14 end-to-end checks passed (12 tools)
- 654 ranatec.com URLs referenced by the package: 648 × 200, 6 intentional redirects, 0 broken
- No secrets in the repository
