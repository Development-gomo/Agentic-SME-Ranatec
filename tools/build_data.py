"""Normalise site-data.json into the ranatec-api data files (one entity per page, all three locale URLs)."""
import json, re, os, sys

BASE = os.environ.get('RANATEC_WORK', os.path.join(os.path.dirname(os.path.abspath(__file__)), '.work'))
OUT = sys.argv[1] if len(sys.argv) > 1 else os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))), 'ranatec-api', 'data')
os.makedirs(OUT, exist_ok=True)
d = json.load(open(os.path.join(BASE, 'site-data.json')))
SITE = 'https://ranatec.com'
API = SITE + '/agent/v1'
TODAY = os.environ.get('RANATEC_DATE') or __import__('datetime').date.today().isoformat()
VERSION = open(os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))), 'VERSION')).read().strip()
JUNK = re.compile(r'(Customize with extra items|quantity|quote request list|Add to RFQ|View Cart)', re.I)
MODEL = re.compile(r'\b(RI ?\d{3,4}(?:-\d{2})?B?|RF ?\d{4}B?)\b')

def locale_urls(path):
    path = path.lstrip('/')
    return {'en-US': f'{SITE}/{path}', 'en-GB': f'{SITE}/en-gb/{path}', 'en-CA': f'{SITE}/en-ca/{path}'}

def norm_model(m):
    return re.sub(r'^(RI|RF) ?', lambda x: x.group(1) + (' ' if x.group(1) == 'RI' else ''), m.upper())

def meta(endpoint, count=None):
    m = {'source': f'{API}/{endpoint}.json', 'endpoint': endpoint, 'version': VERSION,
         'last_updated': TODAY, 'publisher': 'Ranatec AB', 'canonical_site': SITE + '/',
         'locales': ['en-US', 'en-GB', 'en-CA'],
         'locale_note': 'ranatec.com publishes the same English content in three regional variants: en-US (default, https://ranatec.com/), en-GB (/en-gb/) and en-CA (/en-ca/). Content is identical; each entity lists all three URLs.'}
    if count is not None:
        m['count'] = count
    return m

def write(name, obj):
    with open(os.path.join(OUT, name + '.json'), 'w', encoding='utf-8') as f:
        json.dump(obj, f, indent=2, ensure_ascii=False)
        f.write('\n')

# ---------------- categories ----------------
nav_summary = {}
for href, text in d['nav']:
    m = re.match(r'^(.*?) \((.*)\)$', text)
    if '/product/' in href and m:
        nav_summary[href] = m.group(2)
home_doc = next(x for x in d['docs'] if x['locale'] == 'en-US' and x['slug'] == 'home')
cat_blurbs = {}
lines = home_doc['text'].split('\n')
for i, l in enumerate(lines):
    if l in ('Butler Matrices', 'Digital Attenuator Boxes', 'Digital Step Attenuator Modules', 'EMI Ventilation Panels', 'Forensic RF Shield Box',
             'Shield Boxes', 'Shielded Feedthrough Filters', 'Switch Boxes and RF Test Automation Systems', 'Switch Modules',
             'Tunable Band Reject Filters', 'Tunable Band Pass Filters') and i + 1 < len(lines) and len(lines[i + 1]) > 40:
        cat_blurbs[l] = lines[i + 1]

CAT_ORDER = ['tunable-band-reject-filter', 'tunable-band-pass-filter', 'butler-matrices', 'digital-step-attenuator', 'digital-step-attenuator-module',
             'switch-box', 'rf-coaxial-switch', 'rf-shield-box', 'forensic-box', 'shielded-feedthrough-filters', 'emi-ventilation-panels']
CAT_NAME = {'butler-matrices': 'Butler Matrices'}
CAT_DOMAIN = {'tunable-band-reject-filter': 'filtering', 'tunable-band-pass-filter': 'filtering', 'butler-matrices': 'automation',
              'digital-step-attenuator': 'automation', 'digital-step-attenuator-module': 'automation', 'switch-box': 'switching',
              'rf-coaxial-switch': 'switching', 'rf-shield-box': 'shielding', 'forensic-box': 'shielding',
              'shielded-feedthrough-filters': 'shielding', 'emi-ventilation-panels': 'shielding'}
LEGACY_ALIASES = {'tunable-band-reject-filter': ['https://ranatec.com/tunable-notch-filter/'],
                  'rf-coaxial-switch': ['https://ranatec.com/rf-coaxial-switch/'],
                  'digital-step-attenuator-module': ['https://ranatec.com/wireless-test-automation/digital-attenuator-module/'],
                  'digital-step-attenuator': ['https://ranatec.com/digital-step-attenuator/']}

prod_cat = {}
cats_src = {c['slug']: c for c in d['categories']}
for c in d['categories']:
    for u in c['product_links']:
        prod_cat.setdefault(u.rstrip('/').split('/')[-1], []).append(c['slug'])

def cat_text(c):
    t = c['text']
    t = t.split('Product is added to Quotation')[-1]
    out = []
    for l in t.split('\n'):
        if JUNK.search(l) or l in ('Details', 'View details') or re.match(r'^(Sort by|Show|\d+ Products|Default Order|Name|Price|Date|Popularity|Rating|Next|\d)$', l):
            continue
        out.append(l)
    return out

categories = []
for slug in CAT_ORDER:
    c = cats_src[slug]
    name = CAT_NAME.get(slug, c['name'])
    body = cat_text(c)
    prods = [u.rstrip('/').split('/')[-1] for u in c['product_links']]
    # narrative: lines that are sentences and not product names
    pnames = set()
    narrative = [l for l in body if len(l) > 60 and not l.startswith('The Ranatec RI') and '…' not in l][:12]
    categories.append({
        'id': slug, 'name': name, 'domain': CAT_DOMAIN[slug],
        'summary': cat_blurbs.get(name) or cat_blurbs.get(c['name']) or c['meta_description'],
        'meta_description': c['meta_description'],
        'overview': narrative,
        'product_ids': prods, 'product_count': len(prods),
        'url': c['url'], 'urls': locale_urls(f'product-category/{slug}/'),
        'legacy_urls_redirecting_here': LEGACY_ALIASES.get(slug, []),
        'api_url': f'{API}/products.json?category={slug}',
    })

# ---------------- products ----------------
nav_prod = {h.rstrip('/').split('/')[-1] for h, _ in d['nav'] if '/product/' in h}
by_model = {}
products = []
for p in d['products']:
    slug = p['slug']
    models = [norm_model(m) for m in MODEL.findall(p['name'])]
    model = models[0] if models else None
    summary = nav_summary.get(p['url'])
    paras = []
    for t in ([p['subtitle']] if p['subtitle'] else []) + p['description']:
        if t and not JUNK.search(t) and t not in paras:
            paras.append(t)
    if not summary and paras and len(paras[0]) < 110 and ',' in paras[0]:
        summary = paras[0]
    paras = [x for x in paras if x != summary]
    secs = p['sections']
    def clean_list(k):
        return [x for x in secs.get(k, []) if x and not JUNK.search(x)]
    ordering = [x for x in clean_list('control-ordering') if x not in ('Ordering', 'Control')]
    in_nav = slug in nav_prod
    cats = [c for c in prod_cat.get(slug, []) if c != 'uncategorized']
    name = p['name'] if p['name'] != 'Customized' else ('Customized RF Shield Box' if 'shield' in slug else 'Customized RF Switch System')
    products.append({
        'id': slug,
        'name': name,
        'model_number': model,
        'summary': summary,
        'categories': cats,
        'domain': CAT_DOMAIN.get(cats[0]) if cats else None,
        'listing': 'catalogue' if in_nav else 'additional',
        'custom': slug.startswith('customized-'),
        'description': paras,
        'applications': clean_list('applications'),
        'features': clean_list('features'),
        'specifications': secs.get('specifications', []),
        'electrical_interfaces': clean_list('electrical-interfaces'),
        'control_and_ordering': ordering,
        'technical_drawings_note': ' '.join(clean_list('technical-drawings')) or None,
        'optional_accessories': p['optional_accessories'],
        'datasheets': p['datasheets'],
        'image': p['image'],
        'pricing': {'model': 'request-for-quote', 'public_price': None,
                    'how_to_buy': 'Add to the RFQ list on the product page and submit, or use POST /agent/v1/contact.json with inquiry.type = "quote_request" and the product id(s).'},
        'url': p['url'],
        'urls': locale_urls(f'product/{slug}/'),
        'canonical_url': p['canonical'] or p['url'],
        'date_published': p['date_published'],
        'date_modified': p['date_modified'],
    })
    if model:
        by_model.setdefault(model, []).append(slug)

# link duplicate listings of the same model (e.g. lan-1-gbps-feedthru-filter-ri-4182 -> feedthrough-filter-ri-4182)
prod_by_id = {p['id']: p for p in products}
for p in products:
    p['same_model_listings'] = []
    if p['model_number'] and len(by_model.get(p['model_number'], [])) > 1:
        others = [x for x in by_model[p['model_number']] if x != p['id']]
        p['same_model_listings'] = others
        if p['listing'] == 'additional':
            primary = next((x for x in others if prod_by_id[x]['listing'] == 'catalogue'), None)
            if primary:
                p['primary_listing'] = primary
                if not p['categories']:
                    p['categories'] = prod_by_id[primary]['categories']
                    p['domain'] = prod_by_id[primary]['domain']
                if not p['summary']:
                    p['summary'] = prod_by_id[primary]['summary']
for p in products:
    if p['listing'] == 'catalogue' and not p['categories']:
        for o in p['same_model_listings']:
            if prod_by_id[o]['categories']:
                p['categories'] = prod_by_id[o]['categories']; p['domain'] = prod_by_id[o]['domain']; break
    if p['custom'] and not p['summary']:
        p['summary'] = 'Customised to your requirements (contact Ranatec for a quote)'
    if not p['summary'] and p['description'] and len(p['description'][0]) <= 160:
        p['summary'] = p['description'][0]
# classify remaining additional items
for p in products:
    if p['listing'] == 'additional' and not p['categories']:
        n = p['name'].lower()
        if 'foam' in n or 'bracket' in n or 'shielding wall' in n or 'bulkhead' in n or 'dc feedthru emi' in n:
            p['categories'] = ['accessories']; p['domain'] = 'shielding' if 'bracket' not in n else 'automation'
        elif 'frequency extension' in n:
            p['categories'] = ['accessories']; p['domain'] = 'filtering'
        elif 'feedthru' in n or 'feedthrough' in n or 'terminal block' in n:
            p['categories'] = ['shielded-feedthrough-filters']; p['domain'] = 'shielding'
        elif 'switch box' in n:
            p['categories'] = ['switch-box']; p['domain'] = 'switching'
        else:
            p['categories'] = ['accessories']
    if p['listing'] == 'additional' and 'primary_listing' not in p:
        p['primary_listing'] = None

# frequency extension box RI 4270-4276 relation (accessory of band reject filters) — derived from site: RI 4278 is stated for RI 268
order = {c: i for i, c in enumerate(CAT_ORDER + ['accessories'])}
products.sort(key=lambda p: (p['listing'] != 'catalogue', order.get(p['categories'][0] if p['categories'] else 'accessories', 99), p['name']))

categories.append({
    'id': 'accessories', 'name': 'Accessories and options', 'domain': None,
    'summary': 'Options and accessories listed in the Ranatec shop (RF absorption foam, internal shielding walls, bulkhead connectors, DC feedthru EMI filters, mounting brackets, frequency extension boxes). Not a category page on ranatec.com; grouped here for agents.',
    'meta_description': None, 'overview': [],
    'product_ids': [p['id'] for p in products if 'accessories' in p['categories']],
    'product_count': sum('accessories' in p['categories'] for p in products),
    'url': f'{SITE}/shop/', 'urls': locale_urls('shop/'), 'legacy_urls_redirecting_here': [],
    'api_url': f'{API}/products.json?category=accessories',
})
for c in categories:
    if c['id'] != 'accessories':
        inc = [p for p in products if c['id'] in p['categories']]
        c['product_ids'] = [p['id'] for p in inc if p['listing'] == 'catalogue']
        c['product_count'] = len(c['product_ids'])
        c['additional_listing_ids'] = [p['id'] for p in inc if p['listing'] != 'catalogue']

# ---------------- news ----------------
post_docs = [x for x in d['docs'] if x['type'] == 'post']
by_slug = {}
for x in post_docs:
    by_slug.setdefault(x['slug'], {})[x['locale']] = x
REDIRECTED_US = {'digital-step-attenuator': 'https://ranatec.com/product-category/digital-step-attenuator/',
                 'shielded-usb-3-0-feedthru-filter': 'https://ranatec.com/product/feedthrough-filter-ri-4201/'}

def news_body(x):
    t = x['text']
    chunks = t.split('❮\nSee all news')
    t = max(chunks, key=len)
    t = t.split('Related News')[0]
    lines = [l for l in t.split('\n') if l]
    # drop header lines
    while lines and (lines[0] in ('NEWS', '❮', 'See all news') or lines[0].startswith('READ TIME') or re.match(r'^\d{1,2} [A-Z]+ \d{4}$', lines[0])):
        lines.pop(0)
    title = x['h1']
    while lines and title and lines[0].strip().lower() == title.strip().lower():
        lines.pop(0)
    paras = []
    for l in lines:
        if paras and (re.match(r'^[a-z.,;:)\]”"’]', l) or not re.search(r'[.!?:”"]$', paras[-1]) and len(paras[-1]) < 60 and not re.match(r'^[A-Z][A-Za-z ]{0,40}$', paras[-1])):
            sep = '' if re.match(r'^[.,;:)\]]', l) else ' '
            paras[-1] = paras[-1] + sep + l
        else:
            paras.append(l)
    return [p.strip() for p in paras if p.strip() and p.strip() not in ('Ask for a Quote',)]

def news_type(slug, title, body):
    s = (slug + ' ' + title).lower()
    if any(k in s for k in ('exhibition', 'emv', 'microwave-week', 'eumw', 'meet-us')):
        return 'event'
    if any(k in s for k in ('distributor', 'distribute')):
        return 'distribution-partner'
    if any(k in s for k in ('ceo', 'management', 'brand', 'boom', 'order', 'delivers', 'selected', 'acquires')):
        return 'company-news'
    if any(k in s for k in ('how-to', 'compared', 'applications-and-characteristics', 'you-need', 'for-carrier', 'for-multiple', 'sharp-slopes', 'shield-boxes-for', 'feedthrough-filters-for')):
        return 'technical-article'
    return 'product-launch'

model_to_ids = {}
for p in products:
    if p['model_number'] and p['listing'] == 'catalogue':
        model_to_ids.setdefault(p['model_number'], []).append(p['id'])

news = []
for slug, locs in by_slug.items():
    x = locs.get('en-US') if slug not in REDIRECTED_US else (locs.get('en-GB') or locs.get('en-CA'))
    if not x:
        continue
    body = news_body(x)
    title = x['h1'] or x['title']
    full = ' '.join(body)
    mentioned = []
    for m in MODEL.findall(title + ' ' + full):
        for pid in model_to_ids.get(norm_model(m), []):
            if pid not in mentioned:
                mentioned.append(pid)
    urls = locale_urls(slug + '/')
    item = {
        'id': slug, 'title': title, 'seo_title': x['title'],
        'type': news_type(slug, title, full),
        'date_published': (x['date_published'] or '')[:10] or None,
        'date_modified': (x['date_modified'] or '')[:10] or None,
        'summary': x['meta_description'],
        'body': body,
        'related_product_ids': mentioned,
        'image': x['image'],
        'url': urls['en-US'] if slug not in REDIRECTED_US else urls['en-GB'],
        'urls': urls,
    }
    if slug in REDIRECTED_US:
        item['url_status'] = {'en-US': f'301 redirect to {REDIRECTED_US[slug]}', 'en-GB': '200 (legacy article)', 'en-CA': '200 (legacy article)'}
        item['note'] = 'Legacy 2019 article: the en-US URL now redirects to the current product/category page; the en-GB and en-CA copies still serve the original article.'
    news.append(item)
NEWS_NOTES = {
    'introducing-6ghz-and-26-5ghz-high-performance-rf-switch-boxes': 'Correction: the opening paragraphs of this article swap the two models. Per the product pages (authoritative): RF2037 = 4×SP6T + 2×SPDT, DC–26.5 GHz; RF2038 = 6×SPDT, DC–6 GHz.',
}
for n in news:
    if n['id'] in NEWS_NOTES:
        n['note'] = NEWS_NOTES[n['id']]
news.sort(key=lambda n: n['date_published'] or '', reverse=True)

# ---------------- pages ----------------
page_docs = [x for x in d['docs'] if x['type'] == 'page' and x['locale'] == 'en-US' and x['date_published']]
PAGE_PURPOSE = {'home': 'Homepage — product domains (filtering, shielding, switching, automation), featured products, FAQ',
                'about-us': 'Company background, Qamcom Group ownership, ISO 9001:2015 certification',
                'contact-us': 'Address, phone, email',
                'wireless-test-automation': 'Solution page — wireless test automation',
                'rf-shielded-enclosure': 'Solution page — RF shielded enclosures, customised shield boxes, FAQ',
                'latest-news': 'News archive', 'shop': 'Full product listing (RFQ shop, no public prices)',
                'request-quote': 'RFQ list / quote request', 'cookies-policy': 'Cookie policy',
                'cart': 'RFQ cart (transactional — not for indexing)', 'checkout': 'Checkout (transactional)', 'my-account': 'Customer account (login)'}
LOCAL_SLUG = {'cart': {'en-GB': 'basket', 'en-CA': 'shopping-cart'}}
pages = []
for x in sorted(page_docs, key=lambda x: list(PAGE_PURPOSE).index(x['slug']) if x['slug'] in PAGE_PURPOSE else 99):
    slug = x['slug']
    path = '' if slug == 'home' else slug + '/'
    urls = locale_urls(path)
    pages.append({'id': slug, 'title': x['title'], 'purpose': PAGE_PURPOSE.get(slug), 'meta_description': x['meta_description'],
                  'urls': urls, 'date_modified': (x['date_modified'] or '')[:10]})
for slug in ('cart', 'checkout', 'my-account'):
    urls = locale_urls(slug + '/')
    for loc, alt in LOCAL_SLUG.get(slug, {}).items():
        urls[loc] = f"{SITE}/{loc.lower()}/{alt}/"
    pages.append({'id': slug, 'title': slug.replace('-', ' ').title(), 'purpose': PAGE_PURPOSE[slug], 'meta_description': None, 'urls': urls,
                  'transactional': True, 'date_modified': None})

json.dump({'products': products, 'categories': categories, 'news': news, 'pages': pages},
          open(os.path.join(BASE, 'normalised.json'), 'w'), indent=1, ensure_ascii=False)

# ---------------- write API data files ----------------
domains = [
    {'id': 'filtering', 'name': 'Filtering', 'summary': 'Digitally tunable band reject (notch) and band pass filters for 2G/3G/4G/5G, Wi-Fi and Bluetooth conformance and spurious-emission testing.'},
    {'id': 'shielding', 'name': 'Shielding', 'summary': 'RF shield boxes (off-the-shelf and customised), forensic RF box, shielded feedthrough filters (USB, LAN, HDMI, AC, DC, optical fibre) and EMI ventilation panels.'},
    {'id': 'switching', 'name': 'Switching', 'summary': 'Solid-state switch modules and rack-mount RF switch boxes from DC to 26.5 GHz, plus a modular platform for custom switch systems.'},
    {'id': 'automation', 'name': 'Automation', 'summary': 'Digital step attenuators and attenuator boxes, Butler matrices and complete wireless test automation systems.'},
]
for dm in domains:
    dm['category_ids'] = [c['id'] for c in categories if c['domain'] == dm['id']]

company = {
    'meta': meta('company'),
    'company': {
        'legal_name': 'Ranatec AB', 'brand': 'Ranatec', 'website': SITE + '/',
        'tagline': 'We create progress.',
        'description': 'Ranatec designs and manufactures niche RF test and measurement equipment for filtering, shielding, switching and automation — used for design verification, product certification, production testing, expert troubleshooting and in-service monitoring of wireless devices and systems.',
        'about': [
            'A part of the Qamcom Group, Ranatec designs and manufactures niche test and measurement tools for the most demanding RF and microwave applications.',
            'Ranatec is a specialist in test equipment for design verification, product certification, production testing as well as expert trouble-shooting and in-service monitoring.',
            'The product portfolio has been developed in close cooperation with leading global brands within cellular infrastructure, mobile phone and radar system industries, and offers products for Wireless Test Automation, RFI/EMI Shielding and Radar Testing.',
            'Ranatec is not only a distributor but an RF test equipment manufacturer, with sales, engineering, manufacturing and customer support in Gothenburg, Sweden. All products are designed, engineered and manufactured in Sweden.',
            'Customers are producers of semiconductors, devices and base stations, as well as ISPs and certification institutes.',
        ],
        'parent_company': {'name': 'Qamcom Group', 'note': 'Qamcom Technology AB acquired Ranatec Instrument AB in September 2018 (source: news article 2018-09-03).'},
        'certifications': [{'name': 'ISO 9001:2015', 'certificate_pdf': 'https://ranatec.com/wp-content/uploads/2025/04/2460-Ranatec-AB-en.pdf'}],
        'experience': 'Over 30 years of experience in RF and microwave applications (per the About Us page meta description).',
        'headquarters': {'street_address': 'Falkenbergsgatan 3', 'postal_code': '412 85', 'city': 'Gothenburg', 'country': 'Sweden', 'country_code': 'SE'},
        'contact': {'email': 'info@ranatec.com', 'phone': '+46 31 706 16 60', 'contact_page': locale_urls('contact-us/'), 'quote_page': locale_urls('request-quote/')},
        'sales_model': 'B2B, request-for-quote. Products are added to an RFQ list on ranatec.com; no public prices are shown.',
        'product_domains': domains,
        'industries_served': ['Mobile devices and handsets', 'Cellular infrastructure / base stations', 'Semiconductors (wireless chipsets)', 'Test houses and certification institutes', 'Internet service providers / network operators', 'Radar systems', 'Automotive (multimedia and connectivity testing)', 'Law enforcement / digital forensics', 'Radio astronomy (SKAO signal-processing hardware via Qamcom)'],
        'standards_referenced': ['3GPP TS 136 521-1 (LTE UE conformance)', '3GPP TS 138 521-1 (5G NR UE conformance)', 'ETSI conformance test specifications', 'IEEE 802.11 (Wi-Fi 4/5/6/6E/7)', 'Bluetooth', 'MIL-STD-285 and MIL-STD-461 (shielding)'],
        'differentiators': [
            'Own design, engineering and manufacturing in Gothenburg, Sweden — standard and customised products from a modular mechanical and electrical platform.',
            'Niche focus: products where tier-1 test-equipment vendors are less competitive (e.g. first-of-a-kind tunable band reject filters 600–8000 MHz, widest-bandwidth 4×4 and 8×8 Butler matrices 2.4–8 GHz).',
            'Responsiveness and close cooperation between sales, engineering, manufacturing and customer support.',
        ],
        'distribution_partners': [
            {'name': 'Signal Solutions', 'note': 'Distribution agreement announced 2020-05-27', 'source': 'https://ranatec.com/signal-solutions-inks-agreement-to-distribute-ranatec-test-measurement-tools/'},
            {'name': 'Conical Technologies', 'region': 'South Africa', 'note': 'Distributor announced 2020-04-02', 'source': 'https://ranatec.com/conical-technologies-distributor-south-africa/'},
        ],
        'named_contacts': [
            {'name': 'Charlotte Ornstein', 'role': 'Operations', 'email': 'charlotte.ornstein@ranatec.com', 'phone': '+46 31 706 16 60', 'source': 'https://ranatec.com/ranatec-launches-16-channel-rf-attenuator-box/', 'source_date': '2025-05-06'},
            {'name': 'Leslie Johnsen', 'role': 'Public Relations', 'email': 'leslie.johnsen@ranatec.com', 'phone': '+47 4145 8043', 'source': 'https://ranatec.com/ranatec-launches-16-channel-rf-attenuator-box/', 'source_date': '2025-05-06'},
            {'name': 'Magnus Kilian', 'role': 'CEO (appointed 2019-10-01; most recently named as CEO in site news on 2023-03-16)', 'email': 'magnus.kilian@ranatec.com', 'source': 'https://ranatec.com/ranatec-at-emv-in-stuttgart-28-30-march-2023/', 'source_date': '2023-03-16'},
        ],
        'named_contacts_note': 'ranatec.com has no team page. These people are named as contacts in Ranatec press releases. For quotes and general enquiries use info@ranatec.com.',
        'social': {'linkedin': 'https://www.linkedin.com/company/ranatec-ab/', 'x_twitter': 'https://twitter.com/ranatec1'},
        'founded': 1991,
        'founded_source': 'https://career.ranatec.com/jobs',
        'careers': {'url': 'https://career.ranatec.com/', 'jobs_url': 'https://career.ranatec.com/jobs', 'platform': 'Teamtailor', 'language': 'sv',
                    'open_positions_as_of': TODAY,
                    'open_positions': [{'id': 'job-elektronikmontor-rf-mikrovagsteknik', 'title': 'Elektronikmontör - RF- & mikrovågsteknik', 'title_en': 'Electronics Assembler – RF & Microwave Technology',
                                        'location': 'Gothenburg, Sweden', 'languages_required': ['English', 'Swedish'],
                                        'summary_en': 'Production role: assembly (incl. soldering, ESD-protected environment), RF/microwave performance measurement, calibration, functional test, troubleshooting, quality control and documentation of Ranatec test equipment. Rolling selection.',
                                        'url': 'https://career.ranatec.com/jobs/8314724-elektronikmontor-rf-mikrovagsteknik'}]},
        'locales': [
            {'code': 'en-US', 'hreflang': 'en-US', 'base_url': SITE + '/', 'default': True},
            {'code': 'en-GB', 'hreflang': 'en-GB', 'base_url': SITE + '/en-gb/'},
            {'code': 'en-CA', 'hreflang': 'en-CA', 'base_url': SITE + '/en-ca/'},
        ],
    },
}
write('company', company)
write('products', {'meta': {**meta('products', len(products)), 'filters': ['category', 'domain', 'listing', 'q', 'model']}, 'products': products})
write('categories', {'meta': meta('categories', len(categories)), 'categories': categories})
write('news', {'meta': {**meta('news', len(news)), 'filters': ['type', 'year', 'product', 'q'], 'types': sorted({n['type'] for n in news})}, 'news': news})
write('pages', {'meta': meta('pages', len(pages)), 'pages': pages})
print('products', len(products), 'catalogue', sum(p['listing'] == 'catalogue' for p in products), 'categories', len(categories), 'news', len(news), 'pages', len(pages))

# ---------------- solutions ----------------
solutions = [
    {'id': 'wireless-test-automation', 'name': 'Wireless Test Automation',
     'summary': 'Products and complete systems that rationalise wireless test set-ups: RF switching and routing, signal conditioning (digital attenuators, RF filters), tunable notch/band reject filters for spurious-emission tests and channel emulation — from discrete modules to complete test automation solutions tailored to customer demands.',
     'details': [
        'Wireless test automation has become a necessity to stay ahead in the race for more capable products and systems. With mobile devices covering multiple standards in multiple frequency bands using multiple antennas, conformance testing to demonstrate fulfilment of regulatory radio requirements is a key element in the product release process.',
        'RF switching and routing products: using high quality RF switches and couplers, any RF-signal path can be set up to interconnect any device under test to any external or internal test port.',
        'Signal conditioning products such as digital attenuators and RF filters assure the right power level and bandwidth at any port in an RF test set-up.',
        'Advanced dedicated instruments enable accurate spurious emissions tests (wide-range electronically tunable notch filter) and channel emulators for performance verification under realistic conditions in a laboratory.',
        'Applications span the whole life-cycle: early development and evaluation, product release and certification, production testing and in-service performance assessment.'],
     'category_ids': ['tunable-band-reject-filter', 'butler-matrices', 'shielded-feedthrough-filters', 'rf-shield-box', 'digital-step-attenuator', 'digital-step-attenuator-module', 'rf-coaxial-switch', 'switch-box', 'tunable-band-pass-filter'],
     'urls': locale_urls('wireless-test-automation/')},
    {'id': 'rf-shielded-enclosure', 'name': 'RF Shielded Enclosures (Shielded Solutions)',
     'summary': 'High-performance RF shield boxes covering all cellular and Wi-Fi bands, fully customisable shielded test enclosures, shielded LAN/USB/HDMI/AC/DC/optical-fibre feedthrough filters, RF connectors, EMI ventilation panels and accessories.',
     'details': [
        'RF shielded enclosure may refer to any enclosure with RF shielding properties including RF shield boxes, cabinets and rooms; its purpose is to shield the device under test from interference, so the achieved attenuation and enclosure design are crucial.',
        'Suitable applications: automotive multimedia test, mobile telephone test, base station transceiver test, cellular network test, wireless semiconductor test, WLAN/Bluetooth/ZigBee/WiMax device test.',
        'Feedthrough filters can be purchased separately or with a Ranatec shield box.',
        'Custom shield boxes are built on a scalable, flexible platform. Customisable attributes: shape and size, shielding attributes, box material, power outlets (AC, DC), ventilation (vents, fans), interface connectors (LAN, USB, TNC).'],
     'category_ids': ['rf-shield-box', 'forensic-box', 'shielded-feedthrough-filters', 'emi-ventilation-panels'],
     'urls': locale_urls('rf-shielded-enclosure/')},
    {'id': 'custom-rf-test-equipment', 'name': 'Customized RF Test Equipment Solutions',
     'summary': 'Design, engineering and manufacturing of customised RF test and measurement products — small and large customisation projects — using a modular mechanical and electrical platform for low cost and high quality.',
     'details': [
        'Custom solutions are developed efficiently through an agile organisation with tight cooperation between sales, engineering, manufacturing and customer support.',
        'Customised RF switch systems: modular mainframe + plug-in modules (switch modules DC to 50 GHz, attenuators, amplifiers, mixers), 64 control ports as standard (128 optional), LAN/GPIB/RS-232 remote control.',
        'Customised RF shield boxes: modular and fully customisable to your needs and requirements.'],
     'product_ids': ['customized-rf-switch-systems', 'customized-rf-shield-box'],
     'urls': locale_urls('')},
]
write('solutions', {'meta': meta('solutions', len(solutions)), 'domains': domains, 'solutions': solutions})

faq = [
    {'q': 'What does Ranatec make?', 'a': 'Ranatec AB (Gothenburg, Sweden; part of the Qamcom Group) designs and manufactures RF test and measurement equipment for filtering (tunable band reject and band pass filters), shielding (RF shield boxes, forensic RF box, feedthrough filters, EMI ventilation panels), switching (solid-state switch modules and switch boxes) and automation (digital attenuators, Butler matrices, test automation systems).', 'source': SITE + '/'},
    {'q': 'What is RF equipment?', 'a': 'RF stands for radio frequency. RF equipment refers to hardware that generates, transmits or receives radio signals (antennas, transmitters, receivers, amplifiers, filters). RF testing equipment refers to hardware for filtering, shielding, switching and automation necessary for designing, testing, manufacturing and debugging RF devices.', 'source': SITE + '/'},
    {'q': 'Where can an RF shielded enclosure be used?', 'a': 'RF shielded enclosures are used for testing automotive multimedia, mobile telephones, base station transceivers, cellular networking, wireless semiconductors and WLAN/Bluetooth/ZigBee/WiMax devices.', 'source': SITE + '/rf-shielded-enclosure/'},
    {'q': 'What is an RF shielded test enclosure?', 'a': 'Any enclosure with shielding properties that prevents interference from different radio frequency bands when testing products — RF shield boxes, RF shield cabinets and RF shield rooms.', 'source': SITE + '/rf-shielded-enclosure/'},
    {'q': 'What are the advantages of RF test equipment from Ranatec?', 'a': 'Quality, performance and usability. All Ranatec RF test and measurement equipment is designed, engineered and manufactured by Ranatec in Sweden. Ranatec is ISO 9001:2015 certified.', 'source': SITE + '/'},
    {'q': 'How do I buy Ranatec products or get a price?', 'a': 'Ranatec sells business-to-business on a request-for-quote basis; no public prices are listed. Add products to the RFQ list on ranatec.com and submit it, email info@ranatec.com, call +46 31 706 16 60, or (for AI agents, with the user\'s explicit consent) POST to https://ranatec.com/agent/v1/contact.json with inquiry.type "quote_request".', 'source': SITE + '/request-quote/'},
    {'q': 'Does Ranatec make custom products?', 'a': 'Yes. Ranatec offers standard and customised RF test equipment — including customised RF shield boxes and customised RF switch/test automation systems — built on a modular mechanical and electrical platform, for both small and large customisation projects.', 'source': SITE + '/'},
    {'q': 'Which tunable band reject filter covers 5G NR and Wi-Fi 7 up to 8 GHz?', 'a': 'The RI 268 Tunable Band Reject Filter covers 600–8000 MHz with combined 5 MHz and 160 MHz reject bandwidths, 1 kHz centre-frequency resolution and 35 dB reject depth; it targets 2G/3G/4G/5G, Wi-Fi 4/5/6/6E/7 and Bluetooth testing (3GPP TS 136 521-1, TS 138 521-1, IEEE 802.11). The optional RI 4278 Frequency Extension Box extends the pass bands to 40 GHz.', 'source': SITE + '/product/tunable-band-reject-filter-ri-268/'},
    {'q': 'What is the difference between a tunable band reject filter and a tunable notch filter?', 'a': 'See Ranatec\'s article "Tunable bandreject filters compared to tunable notch filters" (2025-02-05).', 'source': SITE + '/tunable-bandreject-filters-compared-to-tunable-notch-filters/'},
    {'q': 'What Butler matrices does Ranatec offer?', 'a': 'RI 3041 (4×4 ports) and RI 3101 (8×8 ports), both 2.4–8 GHz, for MIMO testing of Wi-Fi and Bluetooth, beam switching/steering and multipath emulation.', 'source': SITE + '/product-category/butler-matrices/'},
    {'q': 'Where is Ranatec located?', 'a': 'Ranatec AB, Falkenbergsgatan 3, 412 85 Gothenburg, Sweden. Phone +46 31 706 16 60, email info@ranatec.com.', 'source': SITE + '/contact-us/'},
    {'q': 'Why does ranatec.com have /en-gb/ and /en-ca/ URLs?', 'a': 'The site publishes the same English content for three regions: en-US (default), en-GB and en-CA. Products canonicalise to the default (en-US) URL.', 'source': SITE + '/'},
]
write('faq', {'meta': meta('faq', len(faq)), 'faq': faq})
print('solutions', len(solutions), 'faq', len(faq))
