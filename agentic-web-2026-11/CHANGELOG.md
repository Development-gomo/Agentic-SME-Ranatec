# Changelog — Ranatec Agentic Web package

## 1.3.7 — 2026-11-20 (`agentic-web-2026-11`)

New: **WebMCP**, the Lighthouse "Agentic Browsing" audit **WebMCP schemas are valid**. Same crawl and data as 1.3.6.

- **Why the audit showed "Not applicable":** read in Lighthouse 13.6's source. It does so when the page registers no WebMCP tools, or when the browser running Lighthouse has no WebMCP. ranatec.com registered none.
- **5 read-only WebMCP tools on every page** (`search_ranatec`, `get_product`, `list_products`, `compare_products`, `get_company_info`) and on `/agent/`. They are registered with `document.modelContext.registerTool()` from the new `public/webmcp.js`, which `tools/build_static.py` generates so that the category and domain lists come from the data. The tools read the same-origin `/agent/v1` API, so they work on staging too. Every tool is marked read-only, and nothing can be submitted through them: quotes and enquiries stay with the MCP server, which carries the consent rules. Errors such as an unknown product come back as a readable message. The website forms are deliberately not annotated for WebMCP, so agents can't fill in the reCAPTCHA-protected forms.
- **Tools → Ranatec Agent API:** a switch (on by default), and an optional Chrome origin-trial token, printed as `<meta http-equiv="origin-trial">`. Only a well-formed token is printed.
- **Verified:**
  - **Lighthouse 13.6** in Chrome 153, on a real WordPress install: **WebMCP schemas are valid** passes, **WebMCP tools registered** lists all 5 tools with their full input schemas, and **ARD manifest is valid** still passes.
  - **Real-browser tests** call every tool and check the answers.
- 17 new tests (166 in total).

Deploy:
1. Upload `release/ranatec-api-1.3.7.zip` in WordPress and choose **Replace current**.
2. Replace `public_html/.well-known/ard.json` with `web-root/.well-known/ard.json`.
3. Run LiteSpeed **Purge All**.
4. Check in Lighthouse as in step 24a of `docs/DEPLOYMENT-ODERLAND.md`.
5. The MCP server code is unchanged. Only its version number changes, in `Development-gomo/agentic-mcp-sme-ranatec`.

## 1.3.6 — 2026-11-20 (`agentic-web-2026-11`)

Fixes from a third external review (Codex) of 1.3.5. Both points held up when checked.

- **High — the documented rebuild could republish withdrawn content.**
  - **Stale data:** `tools/build_static.py` builds `llms.txt`, `llms-full.txt` and the `/agent/` page from the package's own `ranatec-api/data/*.json`, which is only as new as the last crawl. So "rebuild to remove a withdrawn item" put it straight back.
  - **Plugin upgrades** (found while checking): uploading a plugin replaces the synced data with the release copies, so withdrawn items returned to the data API until the next daily sync.
  - **Crash** (reproduced): the rebuild failed with a `KeyError` when the catalogue was empty and a solution still referenced a removed product.
- **Fixed by:**
  - **New `tools/pull_live_data.py`:** downloads the products, news and categories the site publishes now. It writes nothing unless every download is complete and valid, and drops references to products that are no longer public from news and solutions. With `--check` it changes nothing and fails if the package data still holds anything the site has withdrawn.
  - **`tools/release.sh`:** runs that check first and refuses to build. An offline build needs an explicit `SKIP_LIVE_CHECK=1`.
  - **`tools/build_static.py`:** leaves out, with a warning, every reference to a product that isn't in the catalogue, so it no longer crashes and never names a withdrawn product.
  - **The plugin:** syncs once right after an upgrade. On an admin request it runs at once; on any other request WP-Cron runs it immediately. Tested on a real WordPress install.
  - **This release's data** was pulled from the live site. Only the product order, one full-size image URL and two news dates differ, and nothing had been withdrawn.
- **Medium — a sync slower than 10 minutes could overlap with a newer run.** A running sync now renews its lock as it works: after reading the products, every 25 items, and right before every file is replaced. A run that has stalled past the 10-minute limit and lost its lock to a newer run stops before writing anything, and leaves the newer run's lock alone. So two runs never write at the same time.
- 13 new tests (149 in total), including a stand-in site with a withdrawn product and post.

Deploy:
1. Upload `release/ranatec-api-1.3.6.zip` in WordPress and choose **Replace current**. The plugin syncs by itself.
2. Replace `public_html/.well-known/ard.json` with `web-root/.well-known/ard.json`.
3. Run LiteSpeed **Purge All**.
4. The MCP server code is unchanged. Only its version number changes, so update `package.json` and `package-lock.json` in `Development-gomo/agentic-mcp-sme-ranatec`.

## 1.3.5 — 2026-11-20 (`agentic-web-2026-11`)

Fixes from a second external review (Codex) of 1.3.4. All three points held up when checked. Same crawl and content.

- **Medium — two syncs could run at once.** Codex found that two runs could take over the same expired lock together. The problem was wider: WordPress's `add_option()` runs `INSERT … ON DUPLICATE KEY UPDATE`, so even a free lock could be "taken" by two runs. The lock now uses atomic database statements:
  - `INSERT IGNORE` takes it for exactly one run;
  - an expired lock is taken over by an `UPDATE` that matches only the value just read, so exactly one run wins;
  - a run releases only its own lock, so a run that outlived the 10-minute limit can't free a newer run's lock.

  Tested in the suite and on a real WordPress install. A 1.3.4-style lock left over during the upgrade is handled too.
- **Medium — the smoke test could submit to production.** `SMOKE_MODE=staging` and `stub` now refuse to start (exit 2, `REFUSED`, nothing sent) when the MCP server under test uses the live `ranatec.com` API. The US-state check is now always a dry run, so the staging test creates exactly the two quote orders the guide promises, whatever the site's checkout requires.
- **Medium — content made private could stay public.**
  - **All items protected:** when every post or every product is password-protected, the sync now writes an empty list. Before, it treated that as "nothing published" and kept serving the previous JSON. Nothing published at all is still treated as a site problem, and the last good file is kept.
  - **Static files:** `llms.txt`, `llms-full.txt` and the `/agent/` page are built offline and can't be rewritten by the sync. WordPress now shows administrators a warning naming every item that is no longer public but still appears in those files, and which files. The warning clears when the item is public again, or when a rebuilt plugin no longer contains it. The guide explains the rebuild.
- 18 new tests (136 in total).

Deploy:
1. Upload `release/ranatec-api-1.3.5.zip` in WordPress and choose **Replace current**.
2. Replace `public_html/.well-known/ard.json` with `web-root/.well-known/ard.json`.
3. Run LiteSpeed **Purge All**.
4. The MCP server code is unchanged. Only its version number changes, so the ARD manifest and the running server report the same version: update `package.json` and `package-lock.json` in `Development-gomo/agentic-mcp-sme-ranatec`.

## 1.3.4 — 2026-11-20 (`agentic-web-2026-11`)

Fixes from an external review (Codex) of 1.3.3. Every point was checked before changing anything; all four held up. Same crawl and content.

- **High — a malformed `company.website` crashed WordPress (HTTP 500).** An array value reached WordPress's URL sanitizer, which fails on PHP 8. Reproduced on a real WordPress install with 1.3.3. It is now rejected with a 400 validation error before anything is stored or emailed.
- **High — `"dry_run": "true"` (a string) sent a real enquiry.** Only the JSON boolean `true` counted as a dry run, so a string, `1` or `"yes"` became a real submission once the other fields passed. Reproduced on real WordPress with 1.3.3: a real enquiry email was sent. Any `dry_run` that is not a JSON boolean is now rejected with a 400 on both the contact and quote endpoints, and nothing is stored or sent.
- **Medium — the daily sync could publish password-protected posts and products.** It read the full text of every published post, including password-protected ones. Those posts and products are now left out. Tested on real WordPress: the protected post and its text are not in `news.json`.
- **Medium — overlapping syncs could corrupt a data file.** "Sync now" and the daily cron wrote to the same temporary file names. Runs are now serialized with a lock (a second run is skipped with "Sync not started: another sync is already running"; a lock left by a crashed run expires after 10 minutes), and every write uses its own temporary file.
- **Medium — the UTC test reported PASS without testing anything.** `grep` read the pattern `->date('c')` as an option, failed, and the failure counted as a pass. The new test runs the sync against WooCommerce's real `WC_DateTime` class (vendored in `tests/vendor/woocommerce/`), in both the time-zone and UTC-offset modes, and checks the UTC output. It fails if the old formatting is put back (checked).
- **Medium — the deployment guides ran real submissions against production.** The documented `npm test` sent one contact enquiry and two quote orders, and expected the test stub's order number 4242. The smoke test is now **read-only by default**: it reads data and sends both submission tools as dry runs, so nothing is stored, ordered or emailed. Real submissions need `SMOKE_MODE=staging`, run against a staging site with the real plugins and captured email. `docs/DEPLOYMENT-ODERLAND.md` describes that staging check (Advanced CF7 DB lead, WooCommerce/YITH quote orders, emails).
- **Medium — the MCP server did not check the `Origin` header,** which the MCP Streamable HTTP specification requires against DNS rebinding. Requests from browser origins other than ranatec.com now get HTTP 403. MCP clients and server-side agents, which send no `Origin` header, are unaffected. Configure the allowed list with `MCP_ALLOWED_ORIGINS`.
- **Render deployment corrected.**
  - `render.yaml` named the service `ranatec-mcp`, not the advertised `agentic-mcp-sme-ranatec`. It now uses the advertised name and sets `NODE_ENV=production` and `MCP_ALLOWED_ORIGINS`.
  - `render.yaml` is now in the complete-package zip.
  - The guide no longer says to apply the Blueprint from `Agentic-SME-Ranatec`, whose root `render.yaml` builds the previous package's server. The live service builds from `Development-gomo/agentic-mcp-sme-ranatec`, and the guide explains how to update it.
- **Hand-uploaded files in update and rollback steps.** A physical `public_html/.well-known/ard.json` overrides the plugin, and the WordPress ARD switch cannot remove it. The update and rollback steps now cover it, and a post-deployment step checks that `ard.json`, `index.json` and the MCP server report the intended version.
- Guide correction: ranatec.com runs PHP 8.4, not 8.2.
- Smoke test against production: whether a US quote needs a state depends on the site's checkout settings, which the plugin follows. ranatec.com's checkout makes state and postcode optional for every country, so the "state required for US" check now runs only against the test stub. Elsewhere it accepts either a state error or a valid result. Found by running the read-only smoke test against the live API before deploying the MCP server. The plugin is unchanged.
- 17 new tests (118 in total). The test runner also no longer fails at random: under `pipefail`, `php … | grep -q` could kill PHP with SIGPIPE when grep stopped reading early.

Deploy:
1. Upload `release/ranatec-api-1.3.4.zip` in WordPress and choose **Replace current**.
2. Replace `public_html/.well-known/ard.json` with `web-root/.well-known/ard.json`.
3. Run LiteSpeed **Purge All**.
4. Update the MCP server by copying `ranatec-mcp/src`, `package.json` and `package-lock.json` to `Development-gomo/agentic-mcp-sme-ranatec`. Render deploys automatically. Then set `NODE_ENV=production` in the service's environment.

## 1.3.3 — 2026-11-20 (`agentic-web-2026-11`)

Review release on top of 1.3.2; same crawl and content.

- **New — warning about outdated files uploaded to the web root by hand.** A file placed in `public_html` (for example `/.well-known/ard.json`, `/llms.txt`, `/openapi.json`) is served by the web server instead of the plugin, so it silently goes stale when the plugin is updated. The plugin now compares each such copy with its own version and lists them on **Tools → Ranatec Agent API** (matches ✓ / older than the plugin). Administrators also get an admin notice while any copy is outdated. Today's manual `/.well-known/ard.json` on ranatec.com matches 1.3.2. Note: once 1.3.3 is installed, that copy differs from the plugin's new ard.json only by the version stamp, so the notice will ask you to replace it with `web-root/.well-known/ard.json` from this package (or delete it and let the plugin serve it). 3 new tests.
- **Correction — the daily sync has not yet run under 1.3.2.** The live product dates are correct only because uploading the plugin replaced the data files with the release copies. Run **Tools → Ranatec Agent API → Sync now** once so the fixed date formatting is applied by the sync itself.
- Also verified in this review, no change needed: the plugin runs without warnings on PHP 8.4 (the live server runs 8.4.28); sync writes are atomic (temporary file + rename), so agents never read a half-written file; every public file on ranatec.com is identical to 1.3.2.

Deploy: WordPress → upload `release/ranatec-api-1.3.3.zip` → Replace current → replace `public_html/.well-known/ard.json` with `web-root/.well-known/ard.json` → **Sync now** → LiteSpeed Purge All. The MCP server code is unchanged since 1.3.2 (only its version number changes), so Render does not need an update for this release.

## 1.3.2 — 2026-11-20 (`agentic-web-2026-11`)

Bug-fix release on top of 1.3.1 (deployed on ranatec.com 2026-11-20); same crawl and content.

- **Medium — the daily sync wrote product dates 2 hours wrong.** `class-sync.php` formatted WooCommerce dates with `WC_DateTime::date('c')`, which prints the site's local time (Europe/Stockholm) labelled `+00:00`. After the sync ran on ranatec.com, every product's `date_modified` read e.g. `2026-09-11T07:34:54+00:00` instead of the real `05:34:54+00:00` (confirmed against WooCommerce's source and the pages' own structured data). The sync now writes real UTC; the next daily run (or **Tools → Ranatec Agent API → Sync now**) corrects the live data. A test fails if the old pattern returns. Present since 1.0.x.
- **Medium — the MCP server leaked stack traces.** A malformed or oversized request got Express's HTML error page, which includes the stack trace and server file paths whenever `NODE_ENV` is not `production` (`render.yaml` did not set it). The server now always answers with a JSON-RPC error (`-32700` parse error, `-32600` request too large or invalid) and a JSON 404 for unknown paths; `render.yaml` also sets `NODE_ENV=production`. 3 new smoke-test checks.
- Also verified in this review, no change needed: the daily sync otherwise reproduces the release data (only product order within categories and full-size image URLs differ); plugin files block direct access; admin actions check permission and nonce; the agent page escapes all text, has no script/iframe/event-handler injection points and parses with 0 HTML5 errors; MCP unknown tools/methods and wrong HTTP methods return proper JSON-RPC errors.

Deploy: WordPress → upload `release/ranatec-api-1.3.2.zip` → Replace current → **Tools → Ranatec Agent API → Sync now** → LiteSpeed Purge All. Render: copy `ranatec-mcp/src/index.ts`, `package.json`, `package-lock.json` and set `NODE_ENV=production` in the service's environment (or apply `render.yaml`).

## 1.3.1 — 2026-11-20 (`agentic-web-2026-11`)

Bug-fix release on top of 1.3.0; same crawl and content. Released under a new version number because the first 1.3.0 build was already deployed on ranatec.com and later 1.3.0 builds could not be told apart from it: the new number also makes the plugin refresh its URL rules on upgrade by itself (needed for the `/agent/v1` fix), so no manual "Flush rewrite rules" is required.

- **High — malformed submissions crashed WordPress (HTTP 500).** See *Fixed after the first 1.3.0 build* below.
- **Medium — `/agent/v1` (API base URL) returned 404**; now 301 to `/agent/v1/index.json`.
- **Medium — MCP `list_news` rejected `year: 2026` as a number**; numbers and strings are accepted.
- **Low — llms.txt bare URL** in the MCP server line, now a Markdown link.

Deploy: WordPress → upload `release/ranatec-api-1.3.1.zip` → Replace current → LiteSpeed Purge All. Render: copy `ranatec-mcp/src/index.ts`, `package.json` and `package-lock.json`.

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

- `bash tests/run-all.sh`: 97 passed, 0 failed (incl. the `/agent/v1` redirect checks and the malformed-input checks) (plugin, quote and contact flows, ARD manifest, HTTP endpoints, version consistency, MCP end-to-end).
- Link check of every URL in the package's assets (719): see `README.md`. One external URL is not resolvable: the MCP Server Card schema `https://static.modelcontextprotocol.io/schemas/v1/server-card.schema.json` (404 upstream). The SEP-2127 schema requires exactly this `$schema` value, so the inline card in `ard.json` keeps it.
- Second bug review: the plugin under PHP `error_reporting=-1` for 46 normal and malformed requests (now 0 warnings); the OpenAPI spec through `openapi-spec-validator` (valid) and 15 real API responses against the spec's response schemas (0 violations); 250 REST and 131 MCP product lookups by id and model number; MCP search, compare and list tools; specification values and dates.
- Bug and anomaly review of the build: data cross-references (IDs, URLs, product/category/news links, counts), agent page (191 unique ids, 68 internal anchors, all JSON-LD valid), text artifacts — no further issues.
- Firecrawl batch scrape: 446/446 URLs (all three locales + careers) returned HTTP 200. All 425 specification rows (601 values, 47 products) in `products.json` were found in Firecrawl's independent copy of the product pages: **0 mismatches**.

### Fixed after the first 1.3.0 build (the build deployed to ranatec.com on 2026-11-20)

The first 1.3.0 build went live on ranatec.com on 2026-11-20 at 05:31 GMT. These fixes were made afterwards and ship as **1.3.1**:

| Fix | Severity | Detail |
|---|---|---|
| **Malformed submissions crashed WordPress (HTTP 500)** | High | `POST /agent/v1/contact.json` / `quote.json` with a list or object where text is expected (e.g. `"email": ["a@b.c"]`, a product `id` as a list) reached `sanitize_email()` / `sanitize_title()`, which throw a fatal error on PHP 8: "There has been a critical error on this website", and WordPress may email the admin about it each time. Reproduced on a real WordPress install with the first 1.3.0 build (and present since 1.0.x). Now every such value gets the normal 400 validation error; array query parameters (`?model[]=…`) are ignored. Covered by 4 new tests that fail on any PHP warning |
| **API base URL returned 404** | Medium | `/agent/v1` and `/agent/v1/` (advertised as `baseURL` / `servers` / `baseUrl`) now 301 to `/agent/v1/index.json` |
| **MCP `list_news` rejected `year: 2026`** | Medium | The tool only accepted the year as a string (`"2026"`); agents usually send a number, which failed with a validation error. Both are accepted now. New smoke-test check |
| **llms.txt bare URL** | Low | The MCP server line is now a Markdown link |

Deploy them as 1.3.1 (see the 1.3.1 entry above).

### Known limitations (not changed)

- **Product structured data on `/agent/` has no `offers`, `review` or `aggregateRating`.** Google requires one of them for Product rich results, so Search Console's "Product snippets" report will likely list the 49 Product items as invalid. Ranatec publishes no prices, so a valid `Offer` cannot be added. AI agents and the ARD/OpenAPI layers are unaffected. If the Search Console warnings matter, the alternative is to describe the catalogue items as `ProductModel` or to drop the Product markup from the agent page.
- **`/agent/v1/products/ri-4170.json`** returns the duplicate shop listing `ri-4170` (its slug equals the model number) instead of the catalogue product `feedthrough-filter-ri-4170`; the response's `primary_listing` points to the catalogue product. The MCP tool `get_product` resolves "RI 4170" correctly. Goes away when the duplicate listing is removed on the site (`docs/FINDINGS.md` #2).
- **The MCP Server Card schema URL** required by SEP-2127 is not published upstream yet (404); see *Verification*.

### Deploying 1.3.0

1. WordPress: **Plugins → Add New → Upload Plugin** → `release/ranatec-api-1.3.0.zip` → **Replace current with uploaded**, then **LiteSpeed Cache → Toolbox → Purge All**.
2. If physical copies are in `public_html`, re-upload them from `web-root/`.
3. MCP server (Render): no code changes. Optionally copy `ranatec-mcp/package.json` and `package-lock.json` to the Render repository, so the server reports 1.3.0 like the ARD manifest.
4. robots.txt: no change.

## 1.2.4 and earlier

See the git history of the previous package (`claude/ranatec-agentic-web-package-qx9tjw`).
