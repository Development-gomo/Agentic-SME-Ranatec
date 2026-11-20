#!/usr/bin/env python3
"""Bring the package's catalogue data in line with what the site publishes NOW, before a rebuild.

The WordPress plugin's daily sync keeps /agent/v1/{products,news,categories}.json up to date: password-protected,
unpublished and deleted posts and products drop out there. The package's own ranatec-api/data/*.json is only as new
as the last crawl, so rebuilding llms.txt, llms-full.txt and the /agent/ page from it (tools/build_static.py) would
put withdrawn content back, and so would uploading a plugin built from it. Run this first:

    python3 tools/pull_live_data.py            # download the synced data into ranatec-api/data/ (then build/release)
    python3 tools/pull_live_data.py --check    # change nothing; exit 1 if local data still holds withdrawn items

It also drops references to products that are no longer public from news (related_product_ids) and solutions
(product_ids). Nothing is written unless every download is complete and valid. --base sets another API (staging).
"""
import argparse
import json
import os
import sys
import time
import urllib.error
import urllib.request

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DATA = os.path.join(ROOT, 'ranatec-api', 'data')
SYNCED = ['products', 'news', 'categories']   # the collections the plugin's sync rewrites


def fetch(base, name):
    url = f'{base}/{name}.json?nocache={int(time.time())}'   # bypass page caches (LiteSpeed)
    req = urllib.request.Request(url, headers={'User-Agent': 'ranatec-package-build/1.0', 'Accept': 'application/json'})
    for attempt in range(4):   # the site firewall can refuse a quick burst of requests (403/429) for a moment
        try:
            with urllib.request.urlopen(req, timeout=30) as r:
                d = json.loads(r.read().decode('utf-8'))
            break
        except urllib.error.HTTPError as e:
            if e.code not in (403, 429, 500, 502, 503, 504) or attempt == 3:
                raise
            time.sleep(2 * (attempt + 1))
    if not isinstance(d, dict) or not isinstance(d.get(name), list) or not isinstance(d.get('meta'), dict):
        raise RuntimeError(f'{url}: not a {name} collection')
    if d['meta'].get('count') != len(d[name]) or d['meta'].get('filters_applied'):
        raise RuntimeError(f'{url}: incomplete or filtered response (count {d["meta"].get("count")} vs {len(d[name])} items)')
    return d


def load(name):
    with open(os.path.join(DATA, name + '.json'), encoding='utf-8') as f:
        return json.load(f)


def save(name, d):
    path = os.path.join(DATA, name + '.json')
    tmp = path + '.tmp'
    with open(tmp, 'w', encoding='utf-8') as f:
        f.write(json.dumps(d, indent=2, ensure_ascii=False) + '\n')
    os.replace(tmp, path)


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument('--base', default='https://ranatec.com/agent/v1', help='API base URL (default: production)')
    ap.add_argument('--check', action='store_true', help='change nothing; exit 1 if local data holds items the site no longer publishes')
    a = ap.parse_args()
    base = a.base.rstrip('/')

    try:
        live = {n: fetch(base, n) for n in SYNCED}
    except Exception as e:  # network, HTTP, JSON or shape problem: write nothing
        print(f'ERROR: could not read the live data from {base}: {e}', file=sys.stderr)
        return 2

    withdrawn, added = {}, {}
    for n in ['products', 'news']:
        here = {x['id'] for x in load(n)[n]}
        there = {x['id'] for x in live[n][n]}
        withdrawn[n], added[n] = sorted(here - there), sorted(there - here)
    for n in ['products', 'news']:
        print(f'{n}: {len(live[n][n])} on the site · {len(added[n])} new since the package data · {len(withdrawn[n])} no longer public')
        for i in withdrawn[n]:
            print(f'  - no longer public: {i}')

    if a.check:
        if withdrawn['products'] or withdrawn['news']:
            print('FAIL: the package data still contains items the site no longer publishes. Run: python3 tools/pull_live_data.py', file=sys.stderr)
            return 1
        print('OK: the package data holds nothing the site has withdrawn.')
        return 0

    public = {p['id'] for p in live['products']['products']}
    pruned = 0
    for item in live['news']['news']:
        keep = [i for i in item.get('related_product_ids', []) if i in public]
        pruned += len(item.get('related_product_ids', [])) - len(keep)
        item['related_product_ids'] = keep
    sol = load('solutions')
    for s in sol['solutions']:
        if 'product_ids' in s:
            keep = [i for i in s['product_ids'] if i in public]
            pruned += len(s['product_ids']) - len(keep)
            s['product_ids'] = keep
    for n in SYNCED:
        save(n, live[n])
    save('solutions', sol)
    print(f'Wrote {", ".join(n + ".json" for n in SYNCED)} from {base}; removed {pruned} reference(s) to products that are no longer public.')
    print('Next: python3 tools/build_static.py (or bash tools/release.sh, which rebuilds and tests everything).')
    return 0


if __name__ == '__main__':
    sys.exit(main())
