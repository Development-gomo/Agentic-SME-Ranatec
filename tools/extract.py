"""Extract structured site data from downloaded ranatec.com HTML into site-data.json."""
import json, re, sys, os, html as H
from bs4 import BeautifulSoup

BASE = os.environ.get('RANATEC_WORK', os.path.join(os.path.dirname(os.path.abspath(__file__)), '.work'))
umap = dict(l.split() for l in open(os.path.join(BASE, 'url-map.txt')))

def soup(u):
    p = os.path.join(BASE, 'html', umap[u] + '.html')
    if not os.path.exists(p) or os.path.getsize(p) == 0:
        return None
    return BeautifulSoup(open(p, encoding='utf-8'), 'html.parser')

def meta(s, key):
    m = s.find('meta', attrs={'property': key}) or s.find('meta', attrs={'name': key})
    return m['content'].strip() if m and m.get('content') else None

def clean(t):
    return re.sub(r'\s+', ' ', H.unescape(t or '')).strip()

def ldgraph(s):
    out = []
    for sc in s.find_all('script', type='application/ld+json'):
        try:
            d = json.loads(sc.string or '')
        except Exception:
            continue
        out += d.get('@graph', [d]) if isinstance(d, dict) else d
    return out

def hreflangs(s):
    return {l['hreflang']: l['href'] for l in s.find_all('link', hreflang=True)}

def canonical(s):
    l = s.find('link', rel='canonical')
    return l['href'] if l else None

def main_text(s):
    m = s.find('main') or s.body
    m = BeautifulSoup(str(m), 'html.parser')
    for t in m(['script', 'style', 'noscript', 'svg', 'form']):
        t.decompose()
    for sel in ['.related', '.upsells', '.fusion-sharing-box', '.ywraq-add-to-quote', '.yith-ywraq-add-to-quote']:
        for t in m.select(sel):
            t.decompose()
    lines = [clean(x) for x in m.get_text('\n').split('\n')]
    return '\n'.join(x for x in lines if x)

urls = json.load(open(os.path.join(BASE, 'urls.json')))
def locale_of(u):
    m = re.match(r'https://ranatec\.com/(en-gb|en-ca)/', u)
    return {'en-gb': 'en-GB', 'en-ca': 'en-CA'}[m.group(1)] if m else 'en-US'

# ---------- products ----------
products = []
for u in urls:
    if '/product/' not in u or locale_of(u) != 'en-US':
        continue
    s = soup(u)
    if not s:
        continue
    body_cls = s.body.get('class', []) if s.body else []
    cats = [c[len('product_cat-'):] for c in body_cls if c.startswith('product_cat-')]
    h1 = s.find('h1')
    name = clean(h1.get_text()) if h1 else clean(meta(s, 'og:title'))
    sub = None
    info = s.select_one('.product-info')
    desc = []
    if info:
        st = info.select_one('.product-subtitle')
        if st:
            ps = [clean(p.get_text(' ')) for p in st.find_all('p') if clean(p.get_text())]
            sub = ps[0] if ps else clean(st.get_text(' '))
            desc += ps[1:]
        for p in info.find_all(['p', 'li']):
            if p.find_parent(class_='product-subtitle') or p.find_parent('form'):
                continue
            t = clean(p.get_text(' '))
            if t and t not in desc and t != sub and 'quantity' not in t and 'quote request list' not in t:
                desc.append(t)
    secs = {}
    for sec in s.select('.custom-product-sections .product-section'):
        cls = [c for c in sec.get('class', []) if c != 'product-section']
        key = cls[0] if cls else 'other'
        title = sec.select_one('.product-section-title')
        if key == 'specifications':
            rows = []
            for tr in sec.select('tr'):
                tds = tr.find_all(['td', 'th'])
                if len(tds) >= 2:
                    vals = [clean(x) for x in tds[1].get_text('\n').split('\n') if clean(x)]
                    rows.append({'parameter': clean(tds[0].get_text()), 'value': vals[0] if len(vals) == 1 else vals})
                elif len(tds) == 1 and clean(tds[0].get_text()):
                    rows.append({'parameter': clean(tds[0].get_text()), 'value': None})
            secs['specifications'] = rows
        else:
            if title:
                title.decompose()
            items = [clean(li.get_text(' ')) for li in sec.select('li')]
            if not items:
                items = [clean(x) for x in sec.get_text('\n').split('\n') if clean(x)]
            secs[key] = [i for i in items if i]
    pdfs = sorted({a['href'] for a in s.find_all('a', href=True) if a['href'].lower().endswith('.pdf')})
    graph = ldgraph(s)
    wp = next((g for g in graph if g.get('@type') == 'WebPage'), {})
    accessories = []
    for it in s.select('.product-item.sub_prod'):
        t = it.select_one('.product-title')
        if t:
            for n in t.select('.subproduct-note'):
                n.decompose()
            accessories.append(clean(t.get_text(' ')))
    products.append({
        'url': u,
        'slug': u.rstrip('/').split('/')[-1],
        'canonical': canonical(s),
        'name': name,
        'subtitle': sub,
        'categories': cats,
        'meta_description': meta(s, 'description'),
        'og_description': (s.find_all('meta', attrs={'property': 'og:description'}) or [None])[-1]['content'] if s.find_all('meta', attrs={'property': 'og:description'}) else None,
        'description': desc,
        'sections': secs,
        'datasheets': pdfs,
        'image': meta(s, 'og:image'),
        'date_published': wp.get('datePublished'),
        'date_modified': wp.get('dateModified') or meta(s, 'article:modified_time'),
        'optional_accessories': sorted(set(accessories)),
        'hreflang': hreflangs(s),
        'noindex': bool(s.find('meta', attrs={'name': 'robots', 'content': re.compile('noindex')})),
    })

# ---------- navigation catalogue (authoritative active product list) ----------
home = soup('https://ranatec.com/')
nav = []
for a in home.find_all('a', href=True):
    if '/product/' in a['href'] or '/product-category/' in a['href']:
        nav.append((a['href'], clean(a.get_text(' '))))
nav_dedup = list(dict.fromkeys(nav))

# ---------- categories ----------
categories = []
for u in urls:
    if '/product-category/' not in u:
        continue
    s = soup(u)
    if not s:
        continue
    h1 = s.find('h1')
    categories.append({
        'url': u,
        'slug': u.rstrip('/').split('/')[-1],
        'name': clean(h1.get_text()) if h1 else clean(meta(s, 'og:title')),
        'meta_description': meta(s, 'description'),
        'text': main_text(s)[:6000],
        'canonical': canonical(s),
        'product_links': sorted({a['href'] for a in s.select('main a[href*="/product/"]')}),
    })

# ---------- posts & pages ----------
post_urls = set(re.findall(r'<loc>([^<]+)', open(os.path.join(BASE, 'raw/post-sitemap.xml')).read()))
docs = []
for u in urls:
    if '/product' in u:
        continue
    s = soup(u)
    if not s:
        continue
    graph = ldgraph(s)
    art = next((g for g in graph if g.get('@type') in ('Article', 'BlogPosting', 'NewsArticle')), {})
    wp = next((g for g in graph if g.get('@type') == 'WebPage'), {})
    h1 = s.find('h1')
    title = clean(meta(s, 'og:title') or (s.title.string if s.title else ''))
    title = re.sub(r'\s*[-|]\s*Ranatec\s*$', '', title)
    docs.append({
        'url': u,
        'locale': locale_of(u),
        'type': 'post' if u in post_urls else 'page',
        'slug': re.sub(r'^https://ranatec\.com/(en-gb/|en-ca/)?', '', u).strip('/') or 'home',
        'title': title,
        'h1': clean(h1.get_text()) if h1 else None,
        'meta_description': meta(s, 'description'),
        'date_published': art.get('datePublished') or meta(s, 'article:published_time') or wp.get('datePublished'),
        'date_modified': art.get('dateModified') or meta(s, 'article:modified_time') or wp.get('dateModified'),
        'author': (art.get('author') or {}).get('name') if isinstance(art.get('author'), dict) else None,
        'section': art.get('articleSection'),
        'image': meta(s, 'og:image'),
        'canonical': canonical(s),
        'hreflang': hreflangs(s),
        'text': main_text(s),
        'pdfs': sorted({a['href'] for a in s.find_all('a', href=True) if a['href'].lower().endswith('.pdf')}),
        'external_links': sorted({a['href'] for a in (s.find('main') or s).find_all('a', href=True) if a['href'].startswith('http') and 'ranatec.com' not in a['href']}),
    })

json.dump({'products': products, 'categories': categories, 'docs': docs, 'nav': nav_dedup},
          open(os.path.join(BASE, 'site-data.json'), 'w'), indent=1, ensure_ascii=False)
print('products', len(products), 'categories', len(categories), 'docs', len(docs), 'nav', len(nav_dedup))
