# Changelog — Ranatec Agentic Web package

## 1.3.0 — 2026-11-20 (`agentic-web-2026-11`)

A complete, deployable package rebuilt from a new crawl of https://ranatec.com/ on **2026-11-20**, compared with the previous package **1.2.4** (crawl of 2026-10-06). The previous package folder was not modified.

### How the comparison was made

- **Crawl:** Yoast sitemaps in all three locales (en-US `/`, en-GB `/en-gb/`, en-CA `/en-ca/`) → 445 URLs, plus a Firecrawl site map (269 links) to find pages outside the sitemaps. Every URL was downloaded (445 pages, 0 empty). A Firecrawl batch scrape of all 445 URLs plus the careers site was used to cross-check product specifications.
- **Status check:** all 456 URLs in the previous package or the new crawl were requested without following redirects.
- **Content check:** the new crawl was run through the same extraction and data pipeline, and every entity (company, 82 products, 12 categories, 44 news items, 12 pages, 3 solutions, 12 FAQ) was compared field by field with 1.2.4. Every difference was verified against the live HTML.
- Contact details, the main menu, the footer, robots.txt and the Organization structured data (JSON-LD) were checked separately on the live pages.
- **Fourth review:** every public WordPress content type and taxonomy via the REST API (Avada FAQ and portfolio are empty; the 3 `author-card` items are July 2026 article bylines already in `news.json`; no media uploaded or changed since 2026-10-06; tags and product tags hold no content), the en-GB / en-CA REST listings (44 posts, 82 products, 12 pages each; only the edits already listed here), the WooCommerce Store API (all 82 products visible, quote-only), the site's FAQPage structured data against `faq.json` (same facts, see `docs/FINDINGS.md` #23), the sitemaps again and the careers site again (no changes).
- **Third review:** the WordPress REST API's list of every published page, post, product and product category (including pages excluded from the sitemaps) and their exact edit times; the full text of every en-GB / en-CA page, news item and category compared with en-US (dates normalised); the stored checkout configuration; the `Last-Modified` date of all 46 datasheet PDFs (all 200, none replaced since 2026-10-06); and a fresh fetch of the sitemaps (no edits since the crawl).
- **Second review:** every URL's Yoast sitemap `lastmod` was compared with the previous crawl date (71 URLs edited since), the full page text of every en-GB / en-CA product page was compared with en-US, and every internal link on all 445 pages was checked for pages outside the sitemaps.

### Pages added

**None.** The sitemaps list the same pages, products, categories and news items as 1.2.4, in all three locales.

Found but not added on purpose:

- `https://ranatec.com/product-category/uncategorized/`, `…/en-gb/product-category/uncategorized-en-gb/`, `…/en-ca/product-category/uncategorized-en-ca/`: WooCommerce's default "Uncategorized" archive (see `docs/FINDINGS.md` #4).
- `https://career.ranatec.com/people/`, `/cookie-policy/`, `/privacy-policy/`: careers-site (Teamtailor) pages outside WordPress. The careers site and its one open position are already in `company.json`.
- `https://staging.ranatec.com/forensic-box/`: Ranatec's staging site, publicly reachable (see `docs/FINDINGS.md` #20).
- `https://ranatec.com/home-cro/` and `https://ranatec.com/contact-us-cro/`: published 2024 test variants of Home and Contact Us (`noindex, nofollow`, not in the sitemaps, last edited 2024-05-15). They existed before 1.2.4 and duplicate pages already in the package (see `docs/FINDINGS.md` #21). Found through the WordPress REST API, which lists 14 published en-US pages against the 12 in the sitemaps.

### Pages updated

Content is identical in all three locales, so each change applies to all three URLs.

| Page | URLs (en-US · en-GB · en-CA) | What changed on the site | What changed in the package |
|---|---|---|---|
| Homepage | https://ranatec.com/ · https://ranatec.com/en-gb/ · https://ranatec.com/en-ca/ | SEO title "RF Test & Measurement Equipment Manufacturer" → "RF Test & Measurement Equipment". Page edited 2026-10-07 (AI-agent notice added). | Title in `pages.json`, `agent-page.html` (page index), `llms.txt` (Key Pages); `date_modified` 2026-10-01 → 2026-10-07 |
| Contact Us | https://ranatec.com/contact-us/ · https://ranatec.com/en-gb/contact-us/ · https://ranatec.com/en-ca/contact-us/ | Page edited 2026-10-07 (AI-agent notice added). Address, phone and email unchanged. | `date_modified` 2026-07-16 → 2026-10-07 in `pages.json` |
| RF Shielded Enclosure | https://ranatec.com/rf-shielded-enclosure/ · https://ranatec.com/en-gb/rf-shielded-enclosure/ · https://ranatec.com/en-ca/rf-shielded-enclosure/ | Page edited 2026-10-12. No change to title, meta description or solution text. | `date_modified` 2026-09-18 → 2026-10-12 in `pages.json` |
| Solid State Switch RI 2582 | https://ranatec.com/product/solid-state-switch-ri-2582/ · https://ranatec.com/en-gb/product/solid-state-switch-ri-2582/ · https://ranatec.com/en-ca/product/solid-state-switch-ri-2582/ | Product edited 2026-10-07: the feature bullet "Insertion loss < 4.8 dB up to 3 GHz • Isolation > 70 dB" is now two bullets. Specifications unchanged. | `features` and `date_modified` (2026-09-11 → 2026-10-07) in `products.json`; feature list in `agent-page.html` and `llms-full.txt` |
| News: New management at Ranatec | https://ranatec.com/new-management-at-ranatec/ · https://ranatec.com/en-gb/new-management-at-ranatec/ · https://ranatec.com/en-ca/new-management-at-ranatec/ | Re-saved 2026-11-17. Text unchanged. | `date_modified` 2019-10-09 → 2026-11-17 in `news.json` |

All other pages are unchanged in every field the package uses: 9 pages, 81 products, 12 categories, 3 solutions and 43 news items, in each locale. Some of them were re-saved without content changes (see the next section).

### Pages re-saved with no content change

These URLs have a newer date in the Yoast sitemaps, but their visible text, specifications, category assignments, breadcrumbs and structured data are identical to 1.2.4 and to the en-US version. Verified by comparing the full page text of all 164 en-GB / en-CA product pages with en-US, and the product lists of the categories, the shop and the "Uncategorized" archives in all three locales. The package data is unchanged for them.

**2026-11-18, en-GB and en-CA only** (a WPML re-save on the same day the en-GB / en-CA "Uncategorized" archives appeared; en-US was not touched, except `/shop/`):

- 24 products, en-GB and en-CA URLs (`/en-gb/product/<slug>/`, `/en-ca/product/<slug>/`):
  - Digital Attenuator Box RF2035 (`digital-attenuator-box-rf2035`)
  - Digital Attenuator Box RF2036 (`digital-attenuator-box-rf2036`)
  - Feedthrough Filter RI 4171 (`feedthrough-filter-ri-4171`)
  - Feedthrough Filter RI 4182 (`feedthrough-filter-ri-4182`)
  - Feedthrough Filter RI 4184 (`feedthrough-filter-ri-4184`)
  - Feedthrough Filter RI 4185 (`feedthrough-filter-ri-4185`)
  - Feedthrough Filter RI 4188 (`feedthrough-filter-ri-4188`)
  - Feedthrough Filter RI 4192 (`feedthrough-filter-ri-4192`)
  - Feedthrough Filter RI 4193 (`feedthrough-filter-ri-4193`)
  - Feedthrough Filter RI 4194 (`feedthrough-filter-ri-4194`)
  - Feedthrough Filter RI 4197 (`feedthrough-filter-ri-4197`)
  - Feedthrough Filter RI 4199 (`feedthrough-filter-ri-4199`)
  - Feedthrough Filter RI 4201 (`feedthrough-filter-ri-4201`)
  - Feedthrough Filter RI 4205 (`feedthrough-filter-ri-4205`)
  - Feedthrough Filter RI 4206 (`feedthrough-filter-ri-4206`)
  - Filtered Terminal Block RI 4198 (`filtered-terminal-block-ri-4198`)
  - Frequency Extension Box RI 4273 (`frequency-extension-box-ri-4273`)
  - Frequency Extension Box RI 4274 (`frequency-extension-box-ri-4274`)
  - Frequency Extension Box RI 4276 (`frequency-extension-box-ri-4276`)
  - Frequency Extension Box RI 4278 (`frequency-extension-box-ri-4278`)
  - Optical Fiber Feedthrough RI 4196 (`optical-fiber-feedthrough-ri-4196`)
  - RI 181-11 Internal shielding wall (`ri-181-11-internal-shielding-wall`)
  - RI 4205 110/230 VAC Feedthru Filter (`ri-4205-110-230-vac-feedthru-filter`)
  - Switch Box (`switch-box-2`)
- Categories: `https://ranatec.com/en-ca/product-category/digital-step-attenuator/`, `https://ranatec.com/en-ca/product-category/shielded-feedthrough-filters/`, `https://ranatec.com/en-gb/product-category/digital-step-attenuator/`, `https://ranatec.com/en-gb/product-category/shielded-feedthrough-filters/`
- Shop: `https://ranatec.com/en-ca/shop/`, `https://ranatec.com/en-gb/shop/`, `https://ranatec.com/shop/`

**2026-10-07, en-US:** `https://ranatec.com/product-category/rf-coaxial-switch/` (Switch Modules), saved together with the RI 2582 edit. Category content unchanged.

**2026-11-17, all three locales:** the WooCommerce transactional pages (all `noindex`, not crawled for content):
- Cart: `https://ranatec.com/cart/` · `https://ranatec.com/en-gb/basket/` · `https://ranatec.com/en-ca/shopping-cart/`
- Checkout: `https://ranatec.com/checkout/` · `https://ranatec.com/en-gb/checkout/` · `https://ranatec.com/en-ca/checkout/`
- My Account: `https://ranatec.com/my-account/` · `https://ranatec.com/en-gb/my-account/` · `https://ranatec.com/en-ca/my-account/`

  Checked through a guest cart (no order created): the checkout still requires email, country, first and last name, company, address, city, state, postcode and phone; apartment/suite and "Additional Note" are optional; the only payment method is Request a Quote. These are the fields the MCP tool `request_quote` requires, so agent quotes are unaffected.

### Site-wide changes (not tied to a single page)

- **Footer, all pages, all three locales:** new **"For AI agents"** link to `https://ranatec.com/agent`, between the contact details and the address. It is now linked from all 445 crawled pages. The other footer links (phone, email, address, X, LinkedIn, Cookie policy, Career) are unchanged.
- **Homepage (all three locales) and Contact Us:** the new AI-agent notice links `https://ranatec.com/agent` and `https://ranatec.com/llms.txt` (see *Pages updated*).
- **Locale wording (not changes to the package, which uses the en-US text for all locales):** the en-CA homepage hero button reads "Get a quote" where en-US and en-GB read "Contact us" (same target, the contact form; `docs/FINDINGS.md` #22). Two en-GB category pages use British spelling ("one-handed" on Shield Boxes, "customisable" on Tunable Band Pass Filters), and the en-GB / en-CA Butler Matrices category lists its two products in the opposite order.
- **robots.txt:** now ends with `Agentmap: https://ranatec.com/.well-known/ard.json` (added 2026-11-11 for the ARD manifest), after an "AI-readable resources" comment block and the `Sitemap:` line.

### Pages removed or redirecting

**None.** Every page in 1.2.4 still returns HTTP 200. Unchanged since 1.2.4:

- `/checkout/` (and the en-GB / en-CA checkout pages) redirect to the cart while the cart is empty, which is normal WooCommerce behaviour.
- The legacy URLs `/digital-step-attenuator/`, `/rf-coaxial-switch/`, `/tunable-notch-filter/`, `/wireless-test-automation/digital-attenuator-module/` and `/shielded-usb-3-0-feedthru-filter/` still 301 to their category or product. The package lists them as `legacy_urls_redirecting_here` and keeps links to them in old news articles.

### Navigation, services, products, contact details, structured data

- **Navigation:** main menu unchanged (the product menu lists 49 catalogue products in 11 categories; top level: Products, News, About Us, Contact Us, RFQ). **Footer: new "For AI agents" link to /agent** (see *Site-wide changes*).
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
| `ranatec-api/public/llms.txt`, `llms-full.txt` | New homepage title (llms.txt), RI 2582 features (llms-full.txt), dates. **Fix:** the MCP server line in llms.txt's agent section is now a Markdown link (it was the only bare URL in a list) |
| `ai.txt`, `api-catalog.json`, `well-known-api-catalog.json`, `openapi.json`, `ard.json` | Version and date only |
| `ranatec-api/ranatec-api.php` | **Fix:** the API base URL `https://ranatec.com/agent/v1` (and `/agent/v1/`), advertised as `baseURL` / `servers` / `baseUrl` in the API catalogs, OpenAPI and ARD manifest, returned a WordPress 404. It now 301-redirects to `/agent/v1/index.json`. Active right after the plugin update (the plugin refreshes its URL rules once per version) |
| `ranatec-mcp/` (MCP server) | Version 1.3.0 only; no code changes |
| `web-root/` | Regenerated copies of the files above |
| `docs/FINDINGS.md` | Re-checked all 17 findings (1 fixed, 16 still open) and added 6 new ones (#18–#23) |
| `tools/extract.py` | The site now lists category pages in all three locales and no longer tags product pages with their categories. Categories are read from the en-US category pages only, and WooCommerce's "Uncategorized" archive is skipped |
| `tools/release.sh` | Crawl date read from the data; crawl coverage from `tools/crawl-coverage.txt`; `CHANGELOG.md` included in the package zip |
| `README.md` | Notes the 2026-11-20 refresh; link-check figures replaced with the 1.3.0 results (719 URLs) |

### Verification

- `bash tests/run-all.sh`: 93 passed, 0 failed (incl. the two new `/agent/v1` redirect checks) (plugin, quote and contact flows, ARD manifest, HTTP endpoints, version consistency, MCP end-to-end).
- Link check of every URL in the package's assets (719): see `README.md`. One external URL is not resolvable: the MCP Server Card schema `https://static.modelcontextprotocol.io/schemas/v1/server-card.schema.json` (404 upstream). The SEP-2127 schema requires exactly this `$schema` value, so the inline card in `ard.json` keeps it.
- Bug and anomaly review of the build: data cross-references (IDs, URLs, product/category/news links, counts), agent page (191 unique ids, 68 internal anchors, all JSON-LD valid), text artifacts — no further issues.
- Firecrawl batch scrape: 446/446 URLs (all three locales + careers) returned HTTP 200. All 425 specification rows (601 values, 47 products) in `products.json` were found in Firecrawl's independent copy of the product pages: **0 mismatches**.

### Deploying 1.3.0

1. WordPress: **Plugins → Add New → Upload Plugin** → `release/ranatec-api-1.3.0.zip` → **Replace current with uploaded**, then **LiteSpeed Cache → Toolbox → Purge All**.
2. If physical copies are in `public_html`, re-upload them from `web-root/`.
3. MCP server (Render): no code changes. Optionally copy `ranatec-mcp/package.json` and `package-lock.json` to the Render repository, so the server reports 1.3.0 like the ARD manifest.
4. robots.txt: no change.

## 1.2.4 and earlier

See the git history of the previous package (`claude/ranatec-agentic-web-package-qx9tjw`).
