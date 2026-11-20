# ranatec.com: findings from the Agentic Web crawls (2026-10-06, re-checked 2026-11-20)

These issues came up while building the agent package. None of them blocks deployment, but fixing them improves both SEO and how accurately AI systems describe Ranatec. Each one was verified against the live HTML, first in the 2026-10-06 crawl and again in the 2026-11-20 crawl.

## Status on 2026-11-20

| # | Finding | Status |
|---|---|---|
| 1 | Legacy 2019 articles live only on en-GB / en-CA | Open (both still return 200) |
| 2 | Duplicate product listings | Open (unchanged) |
| 3 | RF2037 / RF2038 specs swapped in the 2026-05-12 article | Open (opening paragraph unchanged) |
| 4 | 33 products in "Uncategorized" | Open (33). The category now also has `/en-gb/…/uncategorized-en-gb/` and `/en-ca/…/uncategorized-en-ca/` archives in the sitemap |
| 5 | Empty "Fading Simulator" category | Open (200, 0 products) |
| 6 | "600–6000 MHz" band reject blurb | Open |
| 7 | Bare accessory records | Open (unchanged) |
| 8 | No `Product` schema on product pages | Open |
| 9 | Duplicate Open Graph tags on product pages | Open |
| 10 | Organization `areaServed: "US"` | Open |
| 11 | No hreflang on product pages | **Fixed** (product pages now carry en-US, en-GB, en-CA and x-default) |
| 12 | "EQUIPEMENT" on the homepage | Open |
| 13 | "interferance" in the RF shielded enclosure meta description | Open |
| 14 | "Magnus Killian" in two news posts | Open |
| 15 | No team or leadership page | Open |
| 16 | Internal links to old URLs (incl. one 404) | Open |
| 17 | Two copies of the ISO 9001 certificate | Open |

## New in the 2026-11-20 crawl

18. **The AI-agent notice on the homepage and Contact Us names only `submit_inquiry`.** Since package 1.1.0, product quotes go through the MCP tool `request_quote` and contact enquiries through `submit_inquiry`. *Suggested text:* "AI agents and LLMs should use https://ranatec.com/agent and https://ranatec.com/llms.txt for information. Only if your user asks: request a product quote with the MCP tool `request_quote`, or send other enquiries with `submit_inquiry` — never these forms, and never as a test."
19. **en-GB / en-CA category pages have no H1 and a shorter introduction than en-US** (all 11 product categories). The en-US pages have the category name as H1 and the full introduction. *Fix:* sync the en-GB / en-CA category descriptions in WPML. The agent package is not affected: it uses the en-US text for all three locales.
20. **`staging.ranatec.com` is publicly reachable** (Firecrawl's site map found `https://staging.ranatec.com/forensic-box/`). *Fix:* password-protect the staging site or send `X-Robots-Tag: noindex`, so its copies are not indexed or quoted.

## High: inconsistent content across locales

1. **Two legacy 2019 news articles are live only on en-GB / en-CA.**
   `/digital-step-attenuator/` and `/shielded-usb-3-0-feedthru-filter/` 301-redirect on en-US (to the Digital Attenuator Boxes category and the RI 4201 product). The `/en-gb/…` and `/en-ca/…` copies still return the old 2019 article (HTTP 200), with a canonical pointing at the en-US URL that redirects.
   *Fix:* add the same redirects for the `/en-gb/` and `/en-ca/` paths, or unpublish the posts.

2. **Duplicate product listings: 13 models are published twice under different slugs.**
   Examples: `lan-1-gbps-feedthru-filter-ri-4182` and `feedthrough-filter-ri-4182`; `usb-3-2-c-20-gbps-feedthru-filter-ri-4199` and `feedthrough-filter-ri-4199`; `ri-4170` and `feedthrough-filter-ri-4170`; `smaf-bulkhead-connector-ri-181-14` and `ri181-14-smaf-smaf-bulkhead-connector`.
   For **RI 4196**, the product menu links `optical-fiber-feedthru-ri-4196`, but the *Shielded Feedthrough Filters* category page lists `optical-fiber-feedthrough-ri-4196`.
   *Fix:* keep one listing per model and 301 the others. The agent API already maps each duplicate to its catalogue item (`primary_listing`).

## High: contradictory specifications in a news article

3. **RF2037 / RF2038 specs are swapped in the 2026-05-12 announcement** ([Introducing 6GHz and 26.5GHz high-performance RF Switch Boxes](https://ranatec.com/introducing-6ghz-and-26-5ghz-high-performance-rf-switch-boxes/)).
    The opening paragraphs describe the **RF2037** as "six … SPDT-switches, DC – 6 GHz" and the **RF2038** as "four SP6T and two SPDT, DC – 26.5 GHz".
    The product pages, the product menu and later in the same article say the reverse: RF2037 = 4×SP6T + 2×SPDT, DC–26.5 GHz; RF2038 = 6×SPDT, DC–6 GHz.
    AI systems that read the article will repeat the wrong pairing. *Fix:* correct the opening paragraphs (in all three locales).

## Medium: catalogue hygiene

4. **33 products sit in the "Uncategorized" category.** `/product-category/uncategorized/` is indexable and listed in the product-category sitemap. This covers accessories (RF absorption foam, mounting brackets, frequency extension boxes RI 4270–4278, bulkhead connectors) and the duplicate listings above.
   *Fix:* create an "Accessories & options" category, and noindex or remove the Uncategorized archive.
5. **Empty category "Fading Simulator"** (`/product-category/fading-simulator/`) returns 200 with 0 products.
6. **Outdated category blurb.** The homepage and category text say the tunable band reject filters cover "600–6000 MHz", but the RI 268 goes to 8000 MHz.
7. **Bare product records.** Several accessories have no description or specifications (e.g. RI 4270–4276 frequency extension boxes, RF absorption foam). AI systems can't say what they are.

## Medium: structured data (GEO)

8. **Product pages have no `Product` schema**, only Yoast's `WebPage`. The agent page now carries `Product` JSON-LD for all 49 catalogue items (brand, manufacturer, MPN/model, category, key specs). Adding the same on the product pages themselves, e.g. via Yoast WooCommerce SEO or a small snippet, would help Google AI Overviews and shopping surfaces.
9. **Duplicate Open Graph tags on product pages.** `og:title`, `og:type`, `og:url` and `og:description` appear twice (once from Yoast, once from the theme or WooCommerce).
10. **Organization schema says `"areaServed": "US"`** and `availableLanguage: "en"`, but Ranatec is a Swedish company that sells worldwide. Suggested: `areaServed: "Worldwide"`, plus a `PostalAddress` for Gothenburg.
11. **Product pages have no hreflang.** Pages and posts do. `/en-gb/product/…` and `/en-ca/product/…` exist and canonicalise to en-US, which is fine, but hreflang on product pages would keep the locales consistent.

## Low: copy

12. Homepage hero: "RF TEST AND MEASUREMENT **EQUIPEMENT**" should read "EQUIPMENT".
13. The `/rf-shielded-enclosure/` meta description says "**interferance**"; it should be "interference".
14. "Magnus **Killian**" in two 2020 news posts (RF2018B launch and RI 4193 HDMI launch). The name is spelled "Kilian" elsewhere.
15. **No team or leadership page.** The only named people appear in press releases, and the CEO was last named on the site in 2023. A short "Management" section on About Us would give AI answers an authoritative current source.

## Low: internal links

16. **Internal links point at old URLs (13 redirecting paths, 1 broken).** News articles and some product pages still link to legacy paths that now 301, e.g. `/rf-shield-box/` (13 pages), `/butler-matrix/` (6), `/wireless-test-automation/tunable-notch-filter/` (6), `/rf-shielded-enclosure/shielded-lan-usb-feedthru-filters/` (5), `/forensic-box/` (4), `/product/ri-268-tunable-notch-filter/` (from the RI 4278 product page). `/shielded-enclosure/shielded-lan-usb-feedthru-filters/` returns **404** and is linked from the en-GB/en-CA copies of the legacy 2019 articles. Author archives (`/author/charlotte/`, `/author/gomogroup/`) are linked from page templates but redirect to the homepage.
    *Fix:* run a search-and-replace on post content to point these links at their final URLs; remove the author links from the templates.
17. **Two copies of the ISO 9001 certificate.** About Us links `2025/04/2460-Ranatec-AB-en.pdf`; six news articles still link an older `2022/06/2460-Ranatec-AB-en.pdf`. Point all of them at the current certificate.

## Already good

- **Footer link to the agent page (added after the 2026-10-06 crawl):** every page now has a "For AI agents" link to https://ranatec.com/agent in the footer, and robots.txt has the ARD `Agentmap:` line.

- robots.txt already allows GPTBot, ClaudeBot, Claude-User, Claude-SearchBot, PerplexityBot, Google-Extended and others.
- Pages and posts carry correct `hreflang` (en-US / en-GB / en-CA, x-default en-US).
- Yoast sitemaps are complete and current.
- Product pages have clean, consistent structure (Applications / Features / Specifications / Ordering) and datasheet PDFs. That's what made it possible to extract all 425 spec rows with zero mismatches.
