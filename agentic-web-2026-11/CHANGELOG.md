# Changelog — Ranatec Agentic Web package

## 1.3.0 — 2026-11-20 (`agentic-web-2026-11`)

A complete, deployable package rebuilt from a new crawl of https://ranatec.com/ on **2026-11-20**, compared with the previous package **1.2.4** (crawl of 2026-10-06). The previous package folder was not modified.

### How the comparison was made

- **Crawl:** Yoast sitemaps in all three locales (en-US `/`, en-GB `/en-gb/`, en-CA `/en-ca/`) → 445 URLs, plus a Firecrawl site map (269 links) to find pages outside the sitemaps. Every URL was downloaded (445 pages, 0 empty). A Firecrawl batch scrape of all 445 URLs plus the careers site was used to cross-check product specifications.
- **Status check:** all 456 URLs in the previous package or the new crawl were requested without following redirects.
- **Content check:** the new crawl was run through the same extraction and data pipeline, and every entity (company, 82 products, 12 categories, 44 news items, 12 pages, 3 solutions, 12 FAQ) was compared field by field with 1.2.4. Every difference was verified against the live HTML.
- Contact details, the main menu and the Organization structured data (JSON-LD) were checked separately on the live pages.

### Pages added

**None.** The sitemaps list the same pages, products, categories and news items as 1.2.4, in all three locales.

Found but not added on purpose:

- `https://ranatec.com/product-category/uncategorized/`, `…/en-gb/product-category/uncategorized-en-gb/`, `…/en-ca/product-category/uncategorized-en-ca/`: WooCommerce's default "Uncategorized" archive (see `docs/FINDINGS.md` #4).
- `https://career.ranatec.com/people/`, `/cookie-policy/`, `/privacy-policy/`: careers-site (Teamtailor) pages outside WordPress. The careers site and its one open position are already in `company.json`.
- `https://staging.ranatec.com/forensic-box/`: Ranatec's staging site, publicly reachable (see `docs/FINDINGS.md` #20).

### Pages updated

Content is identical in all three locales, so each change applies to all three URLs.

| Page | URLs (en-US · en-GB · en-CA) | What changed on the site | What changed in the package |
|---|---|---|---|
| Homepage | https://ranatec.com/ · https://ranatec.com/en-gb/ · https://ranatec.com/en-ca/ | SEO title "RF Test & Measurement Equipment Manufacturer" → "RF Test & Measurement Equipment". Page edited 2026-10-07 (AI-agent notice added). | Title in `pages.json`, `agent-page.html` (page index), `llms.txt` (Key Pages); `date_modified` 2026-10-01 → 2026-10-07 |
| Contact Us | https://ranatec.com/contact-us/ · https://ranatec.com/en-gb/contact-us/ · https://ranatec.com/en-ca/contact-us/ | Page edited 2026-10-07 (AI-agent notice added). Address, phone and email unchanged. | `date_modified` 2026-07-16 → 2026-10-07 in `pages.json` |
| RF Shielded Enclosure | https://ranatec.com/rf-shielded-enclosure/ · https://ranatec.com/en-gb/rf-shielded-enclosure/ · https://ranatec.com/en-ca/rf-shielded-enclosure/ | Page edited 2026-10-12. No change to title, meta description or solution text. | `date_modified` 2026-09-18 → 2026-10-12 in `pages.json` |
| Solid State Switch RI 2582 | https://ranatec.com/product/solid-state-switch-ri-2582/ · https://ranatec.com/en-gb/product/solid-state-switch-ri-2582/ · https://ranatec.com/en-ca/product/solid-state-switch-ri-2582/ | Product edited 2026-10-07: the feature bullet "Insertion loss < 4.8 dB up to 3 GHz • Isolation > 70 dB" is now two bullets. Specifications unchanged. | `features` and `date_modified` (2026-09-11 → 2026-10-07) in `products.json`; feature list in `agent-page.html` and `llms-full.txt` |
| News: New management at Ranatec | https://ranatec.com/new-management-at-ranatec/ · https://ranatec.com/en-gb/new-management-at-ranatec/ · https://ranatec.com/en-ca/new-management-at-ranatec/ | Re-saved 2026-11-17. Text unchanged. | `date_modified` 2019-10-09 → 2026-11-17 in `news.json` |

All other pages are unchanged in every field the package uses: 9 pages, 81 products, 12 categories, 3 solutions and 43 news items, in each locale.

### Pages removed or redirecting

**None.** Every page in 1.2.4 still returns HTTP 200. Unchanged since 1.2.4:

- `/checkout/` (and the en-GB / en-CA checkout pages) redirect to the cart while the cart is empty, which is normal WooCommerce behaviour.
- The legacy URLs `/digital-step-attenuator/`, `/rf-coaxial-switch/`, `/tunable-notch-filter/`, `/wireless-test-automation/digital-attenuator-module/` and `/shielded-usb-3-0-feedthru-filter/` still 301 to their category or product. The package lists them as `legacy_urls_redirecting_here` and keeps links to them in old news articles.

### Navigation, services, products, contact details, structured data

- **Navigation:** unchanged. The product menu lists 49 catalogue products in 11 categories; top level: Products, News, About Us, Contact Us, RFQ.
- **Services / solutions:** unchanged (3: wireless test automation, RF shielded enclosures, customised RF test equipment).
- **Products:** unchanged (82 listings = 49 catalogue products + accessories and duplicate listings). Only RI 2582's feature list changed (see above).
- **Contact details:** unchanged. Falkenbergsgatan 3, 412 85 Gothenburg, Sweden · +46 31 706 16 60 · info@ranatec.com.
- **Careers:** unchanged (1 open position, Elektronikmontör – RF- & mikrovågsteknik).
- **Structured data:** unchanged. Yoast Organization JSON-LD (name, logo, LinkedIn and X, telephone) and the JSON-LD types used (Organization, WebSite, WebPage, BreadcrumbList, Article, Person, FAQPage, CollectionPage, ImageObject). New on the site: product pages now carry hreflang (`docs/FINDINGS.md` #11, now fixed).

### Package assets

| Asset | Change |
|---|---|
| `ranatec-api/data/*.json` | Rebuilt from the new crawl; `meta.version` 1.3.0, `meta.last_updated` 2026-11-20. Content changes as listed under *Pages updated* |
| `ranatec-api/public/agent-page.html` (`/agent/`) | New homepage title, RI 2582 features, last-crawled date 2026-11-20 |
| `ranatec-api/public/llms.txt`, `llms-full.txt` | New homepage title (llms.txt), RI 2582 features (llms-full.txt), dates |
| `ai.txt`, `api-catalog.json`, `well-known-api-catalog.json`, `openapi.json`, `ard.json` | Version and date only |
| `ranatec-api/` plugin PHP, `ranatec-mcp/` (MCP server) | Version 1.3.0 only; no code changes |
| `web-root/` | Regenerated copies of the files above |
| `docs/FINDINGS.md` | Re-checked all 17 findings (1 fixed, 16 still open) and added 3 new ones |
| `tools/extract.py` | The site now lists category pages in all three locales and no longer tags product pages with their categories. Categories are read from the en-US category pages only, and WooCommerce's "Uncategorized" archive is skipped |
| `tools/release.sh` | Crawl date read from the data; crawl coverage from `tools/crawl-coverage.txt`; `CHANGELOG.md` included in the package zip |
| `README.md` | Notes the 2026-11-20 refresh |

### Verification

- `bash tests/run-all.sh`: 91 passed, 0 failed (plugin, quote and contact flows, ARD manifest, HTTP endpoints, version consistency, MCP end-to-end).
- Firecrawl batch scrape: 446/446 URLs (all three locales + careers) returned HTTP 200. All 425 specification rows (601 values, 47 products) in `products.json` were found in Firecrawl's independent copy of the product pages: **0 mismatches**.

### Deploying 1.3.0

1. WordPress: **Plugins → Add New → Upload Plugin** → `release/ranatec-api-1.3.0.zip` → **Replace current with uploaded**, then **LiteSpeed Cache → Toolbox → Purge All**.
2. If physical copies are in `public_html`, re-upload them from `web-root/`.
3. MCP server (Render): no code changes. Optionally copy `ranatec-mcp/package.json` and `package-lock.json` to the Render repository, so the server reports 1.3.0 like the ARD manifest.
4. robots.txt: no change.

## 1.2.4 and earlier

See the git history of the previous package (`claude/ranatec-agentic-web-package-qx9tjw`).
