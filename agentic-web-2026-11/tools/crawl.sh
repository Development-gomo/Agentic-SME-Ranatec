#!/usr/bin/env bash
# Step 1 of the refresh pipeline: discover and download every ranatec.com URL (all three locales).
#   FIRECRAWL_API_KEY=fc-... tools/crawl.sh
# Output: $RANATEC_WORK (default tools/.work): urls.json, url-map.txt, raw/*.xml, html/*.html, firecrawl-map.json, firecrawl-scrape.json
set -euo pipefail
cd "$(dirname "$0")"
WORK="${RANATEC_WORK:-$PWD/.work}"
mkdir -p "$WORK/raw" "$WORK/html"
UA="Mozilla/5.0 (compatible; GOMO-AgenticWeb/1.0; +https://ranatec.com/agent/)"

# 1. Sitemaps (Yoast)
for s in post page product product_cat; do curl -sS "https://ranatec.com/$s-sitemap.xml" -o "$WORK/raw/$s-sitemap.xml"; done

# 2. Firecrawl map (discovers URLs not in sitemaps, e.g. careers subdomain, legacy redirects)
if [[ -n "${FIRECRAWL_API_KEY:-}" ]]; then
  curl -sS -X POST https://api.firecrawl.dev/v1/map -H "Authorization: Bearer $FIRECRAWL_API_KEY" -H "Content-Type: application/json" \
    -d '{"url":"https://ranatec.com","limit":5000}' -o "$WORK/firecrawl-map.json"
fi

# 3. URL list: every sitemap URL in all locales, minus transactional pages, plus known legacy URLs
python3 - "$WORK" <<'PY'
import json, re, sys, hashlib, os
w = sys.argv[1]
urls = []
for s in ['page', 'post', 'product', 'product_cat']:
    urls += [u for u in re.findall(r'<loc>([^<]+)', open(f'{w}/raw/{s}-sitemap.xml').read()) if 'wp-content' not in u]
urls = [u for u in urls if not re.search(r'/(cart|checkout|basket|shopping-cart|my-account)/', u)]
urls += ['https://ranatec.com/digital-step-attenuator/', 'https://ranatec.com/rf-coaxial-switch/', 'https://ranatec.com/tunable-notch-filter/',
         'https://ranatec.com/wireless-test-automation/digital-attenuator-module/', 'https://ranatec.com/product-category/fading-simulator/']
urls = list(dict.fromkeys(urls))
json.dump(urls, open(f'{w}/urls.json', 'w'), indent=0)
with open(f'{w}/url-map.txt', 'w') as f:
    for u in urls:
        f.write(f'{u} {hashlib.md5(u.encode()).hexdigest()}\n')
print(len(urls), 'urls')
PY

# 4. Raw HTML (used by extract.py) — 4 parallel requests
export WORK UA
xargs -P4 -n2 sh -c 'curl -sSL --max-time 40 -A "$UA" "$0" -o "$WORK/html/$1.html"' < "$WORK/url-map.txt" || true

# 5. Firecrawl batch scrape (markdown, used to cross-verify specifications)
if [[ -n "${FIRECRAWL_API_KEY:-}" ]]; then
  python3 -c "import json;print(json.dumps({'urls':json.load(open('$WORK/urls.json'))+['https://career.ranatec.com/jobs'],'formats':['markdown'],'onlyMainContent':False}))" > "$WORK/batch.json"
  ID=$(curl -sS -X POST https://api.firecrawl.dev/v1/batch/scrape -H "Authorization: Bearer $FIRECRAWL_API_KEY" -H "Content-Type: application/json" -d @"$WORK/batch.json" | python3 -c "import sys,json;print(json.load(sys.stdin)['id'])")
  echo "Firecrawl batch $ID started — poll with: curl -H \"Authorization: Bearer \$FIRECRAWL_API_KEY\" https://api.firecrawl.dev/v1/batch/scrape/$ID"
fi
echo "Next: python3 tools/extract.py && python3 tools/build_data.py && python3 tools/build_static.py"
