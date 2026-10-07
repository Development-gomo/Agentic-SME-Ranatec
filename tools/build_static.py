#!/usr/bin/env python3
"""Generate every static artifact of the Ranatec Agentic Web package from ranatec-api/data/*.json.

Outputs
  ranatec-api/openapi.json                  OpenAPI 3.0.3 (true JSON)
  ranatec-api/public/agent-page.html        machine-readable agent page  (served at /agent/)
  ranatec-api/public/llms.txt               llmstxt.org summary          (served at /llms.txt)
  ranatec-api/public/llms-full.txt          full catalogue + articles    (served at /llms-full.txt)
  ranatec-api/public/ai.txt                 AI permissions               (served at /ai.txt)
  ranatec-api/public/api-catalog.json       APIs.json 0.16               (served at /api-catalog.json)
  ranatec-api/public/well-known-api-catalog.json  RFC 9727 linkset     (served at /.well-known/api-catalog)
  web-root/*                                copies for upload to the web root (optional — the plugin serves them too)
  web-root/robots-addition.txt              lines to merge into the live robots.txt

Usage: python3 tools/build_static.py
"""
import json, os, re, shutil, html
from datetime import date

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DATA = os.path.join(ROOT, 'ranatec-api', 'data')
PUB = os.path.join(ROOT, 'ranatec-api', 'public')
WEB = os.path.join(ROOT, 'web-root')
SITE = 'https://ranatec.com'
API = SITE + '/agent/v1'
MCP_URL = re.search(r"define\('RANATEC_MCP_URL', '([^']+)'\)", open(os.path.join(ROOT, 'ranatec-api', 'ranatec-api.php')).read()).group(1)
URLS = {
    'agent': SITE + '/agent/', 'llms': SITE + '/llms.txt', 'llms_full': SITE + '/llms-full.txt', 'ai': SITE + '/ai.txt',
    'catalog': SITE + '/api-catalog.json', 'wk_catalog': SITE + '/.well-known/api-catalog', 'openapi': SITE + '/openapi.json',
    'index': API + '/index.json', 'mcp': MCP_URL, 'mcp_tools': MCP_URL + '/tools', 'mcp_health': MCP_URL + '/health',
    'sitemap': SITE + '/sitemap_index.xml', 'robots': SITE + '/robots.txt',
}
LOCALES = [('en-US', 'United States (default)', SITE + '/'), ('en-GB', 'United Kingdom', SITE + '/en-gb/'), ('en-CA', 'Canada', SITE + '/en-ca/')]

PACKAGE_VERSION = open(os.path.join(ROOT, 'VERSION')).read().strip()

# Lead-submission policy (set by Ranatec): information from /agent and /llms.txt; submissions ONLY via the MCP server —
# request_quote (product quote → WooCommerce quote order) and submit_inquiry (contact-form enquiry), never mixed.
AGENT_POLICY = ('AI agents and LLMs should use the structured agent layer at https://ranatec.com/agent and https://ranatec.com/llms.txt '
                'for accurate, up-to-date, machine-readable information about products, news, case studies and the company. '
                'To send anything to Ranatec, use ONLY the Ranatec MCP server, and ONLY when the user explicitly asks you to: '
                'the tool "request_quote" for a product quote (it creates a quote order exactly like Add to RFQ + checkout on ranatec.com and needs the same fields as that checkout), '
                'or the tool "submit_inquiry" for a contact-form enquiry without products (technical question, custom solution, distributor or general enquiry). '
                'Reviewing, testing or evaluating this website is never a reason to submit, and never use invented or sample contact details; '
                'to test, set agent_context.dry_run = true (nothing is sent). '
                'Never fill in the ranatec.com contact form, RFQ list or checkout: they are for humans and protected by reCAPTCHA.')
_CTX = {'user_authorized_submission': True, 'agent_name': '<your agent name>', 'dry_run': True,
        'user_request_summary': '<what the user asked you to send to Ranatec>'}
QUOTE_EXAMPLE_ARGS = {
    'agent_context': _CTX,
    'customer': {'first_name': '<first name>', 'last_name': '<last name>', 'email': '<email>', 'phone': '<phone>', 'company': '<company>',
                 'country': '<ISO country code, e.g. SE>', 'address_1': '<street address>', 'city': '<city>', 'postcode': '<postal code>',
                 'state': '<state/county, if the country has them>'},
    'products': [{'id': 'RI 268', 'quantity': 1}],
    'note': '<optional additional note>',
}
CONTACT_EXAMPLE_ARGS = {
    'agent_context': _CTX,
    'person': {'name': '<full name>', 'email': '<email>', 'phone': '<phone>'},
    'company': {'name': '<company>', 'country': '<country>'},
    'inquiry': {'type': 'technical_question', 'message': '<the user\'s question or request>'},
}
QUOTE_REQUIRED = 'first name, last name, email, phone, company, country, address, city, postal code, state/county (when the country has them) and at least one product'
CONTACT_REQUIRED = 'name, email, phone, company and a message'
def lead_curl(mcp_url, tool='request_quote'):
    args = QUOTE_EXAMPLE_ARGS if tool == 'request_quote' else CONTACT_EXAMPLE_ARGS
    body = json.dumps({'jsonrpc': '2.0', 'id': 1, 'method': 'tools/call', 'params': {'name': tool, 'arguments': args}}, ensure_ascii=False)
    return f"curl -X POST {mcp_url} -H 'Content-Type: application/json' -d '{body}'"

def load(n):
    return json.load(open(os.path.join(DATA, n + '.json'), encoding='utf-8'))

company = load('company')['company']
products = load('products')['products']
categories = load('categories')['categories']
sol = load('solutions')
solutions, domains = sol['solutions'], sol['domains']
news = load('news')['news']
pages = load('pages')['pages']
faq = load('faq')['faq']
UPDATED = load('company')['meta']['last_updated']
P = {p['id']: p for p in products}
CAT = {c['id']: c for c in categories}
catalogue = [p for p in products if p['listing'] == 'catalogue']
os.makedirs(PUB, exist_ok=True)
os.makedirs(WEB, exist_ok=True)

def e(s):
    return html.escape(str(s if s is not None else ''), quote=True)

def spec_value(v):
    if v is None:
        return ''
    return ', '.join(v) if isinstance(v, list) else v

def write(path, text):
    with open(path, 'w', encoding='utf-8') as f:
        f.write(text)

def dump(path, obj):
    write(path, json.dumps(obj, indent=2, ensure_ascii=False) + '\n')

CUSTOMER_RESULTS = ['ranatec-delivers-5g-test-system-to-faang-client', 'ranatec-secures-100-keur-order-for-ri-266-tunable-band-reject-filters-for-mobile-device-conformance-testing',
                    'ranatec-selected-by-qamcom-to-build-advanced-signal-processing-hardware-for-major-skao-initiative-worlds-largest-radio-telescopes',
                    'ranatec-sees-boom-in-5g-equipment-orders-from-asia-in-1st-half-of-2020']
N = {n['id']: n for n in news}
events = [n for n in news if n['type'] == 'event']
people = company['named_contacts']

def person_id(name):
    return 'person-' + re.sub(r'[^a-z0-9]+', '-', name.lower()).strip('-')

# ====================================================================== OpenAPI
def build_openapi():
    ref = lambda n: {'$ref': f'#/components/schemas/{n}'}
    def ok(schema, desc='OK'):
        return {'200': {'description': desc, 'content': {'application/json': {'schema': schema}}}}
    err = {'description': 'Error', 'content': {'application/json': {'schema': ref('Error')}}}
    qp = lambda name, desc, enum=None, schema_type='string': {'name': name, 'in': 'query', 'required': False, 'description': desc, 'schema': {'type': schema_type, **({'enum': enum} if enum else {})}}
    cat_ids = [c['id'] for c in categories]
    spec = {
        'openapi': '3.0.3',
        'info': {
            'title': 'Ranatec Agent API',
            'version': PACKAGE_VERSION,
            'description': 'Read-only JSON API describing Ranatec AB (Gothenburg, Sweden) — RF test & measurement equipment for filtering, shielding, switching and automation — plus one consent-gated POST endpoint for quote requests and enquiries. Content mirrors ranatec.com in three regional English locales (en-US default, en-GB, en-CA); every entity lists all three URLs. Ranatec publishes no prices: all products are sold B2B via request for quote.',
            'contact': {'name': 'Ranatec AB', 'email': 'info@ranatec.com', 'url': SITE + '/contact-us/'},
            'x-agent-page': URLS['agent'], 'x-llms-txt': URLS['llms'], 'x-mcp-server': URLS['mcp'],
        },
        'externalDocs': {'description': 'Machine-readable agent page', 'url': URLS['agent']},
        'servers': [{'url': API, 'description': 'Production'}],
        'tags': [{'name': 'discovery'}, {'name': 'company'}, {'name': 'products'}, {'name': 'content'}, {'name': 'actions'}],
        'paths': {
            '/index.json': {'get': {'tags': ['discovery'], 'operationId': 'getIndex', 'summary': 'API index, counts and discovery links', 'responses': ok(ref('IndexResponse'))}},
            '/schema.json': {'get': {'tags': ['discovery'], 'operationId': 'getSchema', 'summary': 'Field reference and conventions', 'responses': ok(ref('SchemaResponse'))}},
            '/company.json': {'get': {'tags': ['company'], 'operationId': 'getCompany', 'summary': 'Company profile, contact, certifications, locales, partners, careers', 'responses': ok(ref('CompanyResponse'))}},
            '/products.json': {'get': {'tags': ['products'], 'operationId': 'listProducts', 'summary': 'List products (full specifications unless fields=summary)',
                'parameters': [qp('category', 'Category id', cat_ids), qp('domain', 'Product domain', ['filtering', 'shielding', 'switching', 'automation']),
                               qp('listing', '"catalogue" = in the ranatec.com product menu; "additional" = extra shop listing / accessory', ['catalogue', 'additional']),
                               qp('model', 'Model number, e.g. "RI 268", "ri-268", "RF2037"'), qp('q', 'Case-insensitive full-text search'),
                               qp('fields', 'Return summary fields only', ['summary'])],
                'responses': {**ok(ref('ProductsResponse')), '503': err}}},
            '/products/{id}.json': {'get': {'tags': ['products'], 'operationId': 'getProduct', 'summary': 'One product by id (slug) or model number',
                'parameters': [{'name': 'id', 'in': 'path', 'required': True, 'description': 'Product id (e.g. tunable-band-reject-filter-ri-268) or model number (e.g. ri-268, rf2037)', 'schema': {'type': 'string'}}],
                'responses': {**ok(ref('SingleProductResponse')), '404': err}}},
            '/categories.json': {'get': {'tags': ['products'], 'operationId': 'listCategories', 'summary': 'Product categories',
                'parameters': [qp('domain', 'Product domain', ['filtering', 'shielding', 'switching', 'automation'])], 'responses': ok(ref('CategoriesResponse'))}},
            '/categories/{id}.json': {'get': {'tags': ['products'], 'operationId': 'getCategory', 'summary': 'One category with its products (summary fields)',
                'parameters': [{'name': 'id', 'in': 'path', 'required': True, 'schema': {'type': 'string', 'enum': cat_ids}}],
                'responses': {**ok(ref('SingleCategoryResponse')), '404': err}}},
            '/solutions.json': {'get': {'tags': ['company'], 'operationId': 'listSolutions', 'summary': 'Product domains and solution areas', 'responses': ok(ref('SolutionsResponse'))}},
            '/news.json': {'get': {'tags': ['content'], 'operationId': 'listNews', 'summary': 'News, product launches, technical articles, events (newest first)',
                'parameters': [qp('type', 'News type', ['product-launch', 'technical-article', 'company-news', 'event', 'distribution-partner']),
                               qp('year', 'Publication year, e.g. 2026'), qp('product', 'Only items mentioning this product id'), qp('q', 'Full-text search'),
                               qp('limit', 'Max items (1–100)', None, 'integer'), qp('fields', 'Return summary fields only', ['summary'])],
                'responses': ok(ref('NewsResponse'))}},
            '/news/{id}.json': {'get': {'tags': ['content'], 'operationId': 'getNewsItem', 'summary': 'One news item by slug',
                'parameters': [{'name': 'id', 'in': 'path', 'required': True, 'schema': {'type': 'string'}}], 'responses': {**ok(ref('SingleNewsResponse')), '404': err}}},
            '/pages.json': {'get': {'tags': ['content'], 'operationId': 'listPages', 'summary': 'Site pages with en-US / en-GB / en-CA URLs', 'responses': ok(ref('PagesResponse'))}},
            '/faq.json': {'get': {'tags': ['content'], 'operationId': 'getFaq', 'summary': 'Frequently asked questions', 'responses': ok(ref('FaqResponse'))}},
            '/quote.json': {'post': {'tags': ['actions'], 'operationId': 'requestQuote',
                'summary': 'Product quote request — backend for the MCP tool request_quote (AI agents: use the MCP tool, not this path)',
                'description': 'AI agents must request product quotes ONLY through the MCP server tool request_quote at ' + URLS['mcp'] + ' (JSON-RPC tools/call over HTTP POST, or an MCP connector); when the site owner configures an MCP server key, direct calls are rejected with 403 use_mcp_request_quote. Creates a WooCommerce quote order exactly like Add to RFQ + checkout on ranatec.com (never a contact-form lead) and needs the same fields as that checkout: ' + QUOTE_REQUIRED + '. Optional: address_2, note (the checkout "Additional Note"). ONLY call this after the user explicitly asked for the quote and confirmed what will be sent; agent_context.user_authorized_submission must be true and user_request_summary set. agent_context.dry_run = true validates without sending. Rate limit: 5 submissions per IP per hour.',
                'requestBody': {'required': True, 'content': {'application/json': {'schema': ref('QuoteRequest'), 'example': {
                    'agent_context': {'user_authorized_submission': True, 'agent_name': 'ExampleAssistant', 'user_request_summary': 'User asked for a quote for two RI 268 filters with the RI 4278 extension box', 'dry_run': True},
                    'customer': {'first_name': 'Alex', 'last_name': 'Example', 'email': 'alex@example.com', 'phone': '+49 89 000000', 'company': 'Example Labs GmbH',
                                 'country': 'DE', 'address_1': 'Beispielstraße 1', 'city': 'München', 'postcode': '80331', 'state': 'BY'},
                    'products': [{'id': 'tunable-band-reject-filter-ri-268', 'quantity': 2}, {'id': 'frequency-extension-box-ri-4278', 'quantity': 1}],
                    'note': 'Delivery Q1 2027.'}}}},
                'responses': {'200': {'description': 'Quote order created (or, with dry_run, validated only)', 'content': {'application/json': {'schema': ref('QuoteResponse')}}},
                              '400': {'description': 'Invalid JSON or validation failed', 'content': {'application/json': {'schema': ref('ContactErrorResponse')}}},
                              '403': {'description': 'user_authorized_submission is not true, or the MCP-only lock is on', 'content': {'application/json': {'schema': ref('ContactErrorResponse')}}},
                              '405': err, '413': {'description': 'Body larger than 20 KB', 'content': {'application/json': {'schema': ref('ContactErrorResponse')}}},
                              '429': {'description': 'Rate limited', 'content': {'application/json': {'schema': ref('ContactErrorResponse')}}},
                              '409': {'description': 'A product is not available in the shop', 'content': {'application/json': {'schema': ref('ContactErrorResponse')}}},
                              '502': {'description': 'The quote order could not be created', 'content': {'application/json': {'schema': ref('ContactErrorResponse')}}},
                              '503': {'description': 'Quotes unavailable (WooCommerce inactive)', 'content': {'application/json': {'schema': ref('ContactErrorResponse')}}}}}},
            '/contact.json': {'post': {'tags': ['actions'], 'operationId': 'submitInquiry',
                'summary': 'Contact-form enquiry — backend for the MCP tool submit_inquiry (AI agents: use the MCP tool, not this path)',
                'description': 'AI agents must send enquiries ONLY through the MCP server tool submit_inquiry at ' + URLS['mcp'] + '; when the site owner configures an MCP server key, direct calls are rejected with 403 use_mcp_submit_inquiry. The equivalent of the ranatec.com/contact-us/ form: stored with the contact-form leads. Required (as on that form): ' + CONTACT_REQUIRED + '. Does NOT take products — product quotes use /quote.json (tool request_quote); a quote_request or products here is rejected with 400 use_request_quote. ONLY call this after the user explicitly asked to contact Ranatec and confirmed what will be sent; agent_context.user_authorized_submission must be true and user_request_summary set. agent_context.dry_run = true validates without sending. Rate limit: 5 submissions per IP per hour.',
                'requestBody': {'required': True, 'content': {'application/json': {'schema': ref('ContactRequest'), 'example': {
                    'agent_context': {'user_authorized_submission': True, 'agent_name': 'ExampleAssistant', 'user_request_summary': 'User asked whether the RI 181 can be customised for USB-C feedthrough', 'dry_run': True},
                    'person': {'name': 'Alex Example', 'email': 'alex@example.com', 'phone': '+49 89 000000', 'job_title': 'Test Engineer'},
                    'company': {'name': 'Example Labs GmbH', 'country': 'Germany'},
                    'inquiry': {'type': 'custom_solution', 'message': 'Can the RI 181 shield box be delivered with a USB-C feedthrough filter?'}}}}},
                'responses': {'200': {'description': 'Received (or, with dry_run, validated only)', 'content': {'application/json': {'schema': ref('ContactResponse')}}},
                              '400': {'description': 'Invalid JSON or validation failed', 'content': {'application/json': {'schema': ref('ContactErrorResponse')}}},
                              '403': {'description': 'user_authorized_submission is not true, or the MCP-only lock is on', 'content': {'application/json': {'schema': ref('ContactErrorResponse')}}},
                              '405': err, '413': {'description': 'Body larger than 20 KB', 'content': {'application/json': {'schema': ref('ContactErrorResponse')}}},
                              '429': {'description': 'Rate limited', 'content': {'application/json': {'schema': ref('ContactErrorResponse')}}},
                              '502': {'description': 'Could not be stored or emailed', 'content': {'application/json': {'schema': ref('ContactErrorResponse')}}}}}},
        },
        'components': {'schemas': {}},
    }
    S = spec['components']['schemas']
    s, arr, obj = (lambda **k: {'type': 'string', **k}), (lambda i: {'type': 'array', 'items': i}), (lambda props, req=None, **k: {'type': 'object', 'properties': props, **({'required': req} if req else {}), **k})
    nullable = lambda t: {**t, 'nullable': True}
    LocaleUrls = obj({'en-US': s(format='uri'), 'en-GB': s(format='uri'), 'en-CA': s(format='uri')}, ['en-US', 'en-GB', 'en-CA'])
    S['LocaleUrls'] = LocaleUrls
    S['Meta'] = obj({'source': s(format='uri'), 'endpoint': s(), 'version': s(), 'last_updated': s(format='date'), 'publisher': s(), 'canonical_site': s(format='uri'),
                     'locales': arr(s()), 'locale_note': s(), 'count': {'type': 'integer'}, 'filters': arr(s()), 'filters_applied': {'type': 'object'}, 'types': arr(s())}, ['source', 'endpoint', 'version'])
    S['Error'] = obj({'error': s(), 'message': s(), 'documentation': s(format='uri')}, ['error', 'message'])
    S['Specification'] = obj({'parameter': s(), 'value': nullable({'oneOf': [s(), arr(s())]})}, ['parameter'])
    S['Pricing'] = obj({'model': s(enum=['request-for-quote']), 'public_price': nullable(s()), 'how_to_buy': s()})
    S['Product'] = obj({
        'id': s(), 'name': s(), 'model_number': nullable(s()), 'summary': nullable(s()), 'categories': arr(s()), 'domain': nullable(s(description='filtering | shielding | switching | automation; null for some accessories')),
        'listing': s(enum=['catalogue', 'additional']), 'custom': {'type': 'boolean'},
        'configurator': nullable(obj({'how_it_works': s(), 'quantity_basis': s(), 'options': arr(obj({'id': s(), 'name': s(), 'model_number': nullable(s()), 'summary': nullable(s())})), 'request_quote_usage': s()})), 'description': arr(s()), 'applications': arr(s()), 'features': arr(s()),
        'specifications': arr(ref('Specification')), 'electrical_interfaces': arr(s()), 'control_and_ordering': arr(s()), 'technical_drawings_note': nullable(s()),
        'optional_accessories': arr(s()), 'datasheets': arr(s(format='uri')), 'image': nullable(s(format='uri')), 'pricing': ref('Pricing'),
        'url': s(format='uri'), 'urls': ref('LocaleUrls'), 'canonical_url': s(format='uri'), 'same_model_listings': arr(s()), 'primary_listing': nullable(s()),
        'date_published': nullable(s()), 'date_modified': nullable(s())}, ['id', 'name', 'categories', 'listing', 'url', 'urls'])
    S['ProductSummary'] = obj({k: S['Product']['properties'][k] for k in ['id', 'name', 'model_number', 'summary', 'categories', 'domain', 'listing', 'url', 'urls']})
    S['Category'] = obj({'id': s(), 'name': s(), 'domain': nullable(s()), 'summary': nullable(s()), 'meta_description': nullable(s()), 'overview': arr(s()),
                         'product_ids': arr(s()), 'additional_listing_ids': arr(s()), 'product_count': {'type': 'integer'}, 'url': s(format='uri'), 'urls': ref('LocaleUrls'),
                         'legacy_urls_redirecting_here': arr(s(format='uri')), 'api_url': s(format='uri')}, ['id', 'name', 'product_ids'])
    S['Domain'] = obj({'id': s(), 'name': s(), 'summary': s(), 'category_ids': arr(s())})
    S['Solution'] = obj({'id': s(), 'name': s(), 'summary': s(), 'details': arr(s()), 'category_ids': arr(s()), 'product_ids': arr(s()), 'urls': ref('LocaleUrls')})
    S['NewsItem'] = obj({'id': s(), 'title': s(), 'seo_title': s(), 'type': s(enum=['product-launch', 'technical-article', 'company-news', 'event', 'distribution-partner']),
                         'date_published': s(format='date'), 'date_modified': nullable(s(format='date')), 'summary': nullable(s()), 'body': arr(s()), 'related_product_ids': arr(s()),
                         'image': nullable(s(format='uri')), 'url': s(format='uri'), 'urls': ref('LocaleUrls'), 'url_status': {'type': 'object', 'additionalProperties': s()}, 'note': s()},
                        ['id', 'title', 'type', 'url', 'urls'])
    S['Page'] = obj({'id': s(), 'title': s(), 'purpose': nullable(s()), 'meta_description': nullable(s()), 'urls': ref('LocaleUrls'), 'transactional': {'type': 'boolean'}, 'date_modified': nullable(s())})
    S['FaqEntry'] = obj({'q': s(), 'a': s(), 'source': s(format='uri')}, ['q', 'a'])
    S['Address'] = obj({'street_address': s(), 'postal_code': s(), 'city': s(), 'country': s(), 'country_code': s()})
    S['NamedContact'] = obj({'name': s(), 'role': s(), 'email': s(format='email'), 'phone': s(), 'source': s(format='uri'), 'source_date': s(format='date')})
    S['Company'] = obj({'legal_name': s(), 'brand': s(), 'website': s(format='uri'), 'tagline': s(), 'description': s(), 'about': arr(s()), 'founded': {'type': 'integer'},
                        'parent_company': {'type': 'object'}, 'certifications': arr({'type': 'object'}), 'headquarters': ref('Address'),
                        'contact': obj({'email': s(format='email'), 'phone': s(), 'contact_page': ref('LocaleUrls'), 'quote_page': ref('LocaleUrls')}),
                        'sales_model': s(), 'product_domains': arr(ref('Domain')), 'industries_served': arr(s()), 'standards_referenced': arr(s()), 'differentiators': arr(s()),
                        'distribution_partners': arr({'type': 'object'}), 'named_contacts': arr(ref('NamedContact')), 'social': {'type': 'object'}, 'careers': {'type': 'object'},
                        'locales': arr(obj({'code': s(), 'hreflang': s(), 'base_url': s(format='uri'), 'default': {'type': 'boolean'}}))})
    S['IndexResponse'] = obj({'meta': ref('Meta'), 'name': s(), 'description': s(), 'locales': {'type': 'object'}, 'counts': {'type': 'object'}, 'endpoints': arr({'type': 'object'}), 'discovery': {'type': 'object'}})
    S['SchemaResponse'] = obj({'meta': ref('Meta'), 'openapi': s(format='uri'), 'conventions': {'type': 'object'}, 'entities': {'type': 'object'}})
    S['CompanyResponse'] = obj({'meta': ref('Meta'), 'company': ref('Company')}, ['meta', 'company'])
    S['ProductsResponse'] = obj({'meta': ref('Meta'), 'products': arr({'anyOf': [ref('Product'), ref('ProductSummary')]})}, ['meta', 'products'])
    S['SingleProductResponse'] = obj({'meta': ref('Meta'), 'item': ref('Product')}, ['meta', 'item'])
    S['CategoriesResponse'] = obj({'meta': ref('Meta'), 'categories': arr(ref('Category'))}, ['meta', 'categories'])
    S['SingleCategoryResponse'] = obj({'meta': ref('Meta'), 'item': ref('Category'), 'products': arr(ref('ProductSummary'))}, ['meta', 'item'])
    S['SolutionsResponse'] = obj({'meta': ref('Meta'), 'domains': arr(ref('Domain')), 'solutions': arr(ref('Solution'))}, ['meta', 'solutions'])
    S['NewsResponse'] = obj({'meta': ref('Meta'), 'news': arr(ref('NewsItem'))}, ['meta', 'news'])
    S['SingleNewsResponse'] = obj({'meta': ref('Meta'), 'item': ref('NewsItem')}, ['meta', 'item'])
    S['PagesResponse'] = obj({'meta': ref('Meta'), 'pages': arr(ref('Page'))}, ['meta', 'pages'])
    S['FaqResponse'] = obj({'meta': ref('Meta'), 'faq': arr(ref('FaqEntry'))}, ['meta', 'faq'])
    S['AgentContext'] = obj({'user_authorized_submission': {'type': 'boolean', 'enum': [True], 'description': 'Must be true: the user explicitly approved sending this request and their contact details to Ranatec.'},
                             'agent_name': s(maxLength=120), 'user_request_summary': s(maxLength=500, description='Required unless dry_run: what the user asked you to send (min 10 characters)'),
                             'dry_run': {'type': 'boolean', 'description': 'true = validate only, nothing is stored or sent'}}, ['user_authorized_submission'])
    S['ContactPerson'] = obj({'name': s(maxLength=120), 'email': s(format='email'), 'phone': s(maxLength=40), 'job_title': s(maxLength=120)}, ['name', 'email', 'phone'])
    S['ContactCompany'] = obj({'name': s(maxLength=160), 'country': s(maxLength=80), 'website': s(format='uri')}, ['name'])
    S['ProductLine'] = obj({'id': s(description='Product id from /products.json'), 'quantity': {'type': 'integer', 'minimum': 1, 'maximum': 10000, 'default': 1},
                            'configuration': arr(obj({'id': s(description='Option id from the product configurator'), 'quantity': {'type': 'integer', 'minimum': 0, 'maximum': 100, 'description': 'Per unit of the main product'}}, ['id']))}, ['id'])
    S['ContactInquiry'] = obj({'type': s(enum=['technical_question', 'custom_solution', 'distributor_inquiry', 'general'], default='general'), 'message': s(minLength=10, maxLength=5000),
                               'application': s(maxLength=300), 'timeline': s(maxLength=120), 'preferred_locale': s(enum=['en-US', 'en-GB', 'en-CA'])}, ['message'])
    S['ContactRequest'] = obj({'agent_context': ref('AgentContext'), 'person': ref('ContactPerson'), 'company': ref('ContactCompany'), 'inquiry': ref('ContactInquiry')}, ['agent_context', 'person', 'company', 'inquiry'])
    S['ContactResponse'] = obj({'status': s(enum=['received', 'valid']), 'flow': s(enum=['contact_form']), 'lead_id': s(example='ranatec-lead-2026-A3F7B2C1'), 'type': s(), 'stored_in': nullable(s()), 'entry_id': nullable({'type': 'integer'}),
                                'submitted': {'type': 'boolean'}, 'dry_run': {'type': 'boolean'}, 'notification_email': s(), 'message': s(), 'next_step': s()}, ['status'])
    S['QuoteCustomer'] = obj({'first_name': s(maxLength=80), 'last_name': s(maxLength=80), 'email': s(format='email'), 'phone': s(maxLength=40), 'company': s(maxLength=160),
                              'country': s(description='ISO 3166-1 alpha-2 code (e.g. SE, DE, US, GB) or country name'), 'address_1': s(maxLength=200), 'address_2': s(maxLength=200),
                              'city': s(maxLength=100), 'state': s(maxLength=100, description='State/County code or name — required when the country has states (e.g. US, CA)'),
                              'postcode': s(maxLength=20, description='Required unless the country has no postal codes')},
                             ['first_name', 'last_name', 'email', 'phone', 'company', 'country', 'address_1', 'city'])
    S['QuoteRequest'] = obj({'agent_context': ref('AgentContext'), 'customer': ref('QuoteCustomer'), 'products': {'type': 'array', 'minItems': 1, 'maxItems': 50, 'items': ref('ProductLine')},
                             'note': s(maxLength=2000, description='The checkout "Additional Note" (optional)'), 'preferred_locale': s(enum=['en-US', 'en-GB', 'en-CA'])}, ['agent_context', 'customer', 'products'])
    S['QuoteResponse'] = obj({'status': s(enum=['received', 'valid']), 'flow': s(enum=['product_quote']), 'quote_id': s(example='ranatec-rfq-2026-A3F7B2C1'), 'order_id': {'type': 'integer'}, 'order_number': s(),
                              'products': arr(obj({'id': s(), 'name': s(), 'quantity': {'type': 'integer'}, 'url': s(format='uri')})), 'stored_in': s(),
                              'submitted': {'type': 'boolean'}, 'dry_run': {'type': 'boolean'}, 'notification_email': s(), 'message': s(), 'next_step': s()}, ['status'])
    S['ContactErrorResponse'] = obj({'status': s(enum=['error']), 'error': s(), 'message': s(), 'fields': {'type': 'object', 'additionalProperties': s()}}, ['status', 'error', 'message'])
    dump(os.path.join(ROOT, 'ranatec-api', 'openapi.json'), spec)
    return spec

# ====================================================================== agent page
def build_agent_page():
    H = []
    a = H.append
    def link(u, t=None):
        return f'<a href="{e(u)}">{e(t or u)}</a>'
    def locale_links(urls, status=None):
        out = []
        for l in ('en-US', 'en-GB', 'en-CA'):
            note = f' <span class="dim">({e(status[l])})</span>' if status and l in status and not status[l].startswith('200') else ''
            out.append(f'<a href="{e(urls[l])}" hreflang="{l}">{l}</a>{note}')
        return ' · '.join(out)

    # ---------- JSON-LD ----------
    org_id = SITE + '/#organization'
    graph = [{
        '@type': 'Organization', '@id': org_id, 'name': 'Ranatec AB', 'alternateName': 'Ranatec', 'url': SITE + '/',
        'logo': SITE + '/wp-content/uploads/2021/02/logo_ranatec_black_gradient_promise.svg', 'description': company['description'], 'foundingDate': str(company['founded']),
        'slogan': company['tagline'], 'email': 'info@ranatec.com', 'telephone': '+46 31 706 16 60',
        'address': {'@type': 'PostalAddress', 'streetAddress': 'Falkenbergsgatan 3', 'postalCode': '412 85', 'addressLocality': 'Gothenburg', 'addressCountry': 'SE'},
        'parentOrganization': {'@type': 'Organization', 'name': 'Qamcom Group'},
        'hasCredential': {'@type': 'EducationalOccupationalCredential', 'name': 'ISO 9001:2015', 'url': company['certifications'][0]['certificate_pdf']},
        'contactPoint': [{'@type': 'ContactPoint', 'contactType': 'sales', 'email': 'info@ranatec.com', 'telephone': '+46 31 706 16 60', 'availableLanguage': ['en', 'sv'], 'areaServed': 'Worldwide', 'url': SITE + '/request-quote/'}],
        'sameAs': [company['social']['linkedin'], company['social']['x_twitter']],
        'knowsAbout': ['RF test and measurement', 'tunable band reject filters', 'tunable band pass filters', 'Butler matrix', 'digital step attenuators', 'RF switch boxes', 'RF shield boxes', 'feedthrough filters', 'EMI shielding', 'wireless test automation', '5G NR conformance testing', 'Wi-Fi testing'],
    }, {
        '@type': 'WebSite', '@id': SITE + '/#website', 'url': SITE + '/', 'name': 'Ranatec', 'publisher': {'@id': org_id}, 'inLanguage': ['en-US', 'en-GB', 'en-CA'],
    }, {
        '@type': 'WebPage', '@id': URLS['agent'], 'url': URLS['agent'], 'name': 'Ranatec — machine-readable agent page', 'isPartOf': {'@id': SITE + '/#website'}, 'about': {'@id': org_id},
        'inLanguage': 'en', 'dateModified': UPDATED,
        'hasPart': [{'@type': 'WebPageElement', '@id': URLS['agent'] + '#' + sid, 'name': nm} for sid, nm in SECTIONS],
    }, {
        '@type': 'FAQPage', '@id': URLS['agent'] + '#faq', 'mainEntity': [{'@type': 'Question', 'name': f['q'], 'acceptedAnswer': {'@type': 'Answer', 'text': f['a']}} for f in faq],
    }]
    for pr in people:
        graph.append({'@type': 'Person', '@id': URLS['agent'] + '#' + person_id(pr['name']), 'name': pr['name'], 'jobTitle': pr['role'].split(' (')[0], 'email': pr['email'], 'worksFor': {'@id': org_id}})
    for p in catalogue:
        node = {'@type': 'Product', '@id': p['url'] + '#product', 'name': p['name'], 'url': p['url'], 'brand': {'@type': 'Brand', 'name': 'Ranatec'}, 'manufacturer': {'@id': org_id},
                'category': ', '.join(CAT[c]['name'] for c in p['categories'] if c in CAT), 'description': p['summary'] or (p['description'][0] if p['description'] else p['name'])}
        if p['model_number']:
            node['model'] = p['model_number']; node['mpn'] = p['model_number']
        if p['image']:
            node['image'] = p['image']
        props = [s for s in p['specifications'] if s['value'] and isinstance(s['value'], str)][:8]
        if props:
            node['additionalProperty'] = [{'@type': 'PropertyValue', 'name': s['parameter'], 'value': s['value']} for s in props]
        graph.append(node)
    graph.append({'@type': 'ItemList', '@id': URLS['agent'] + '#news', 'name': 'Ranatec news and articles', 'numberOfItems': len(news),
                  'itemListElement': [{'@type': 'ListItem', 'position': i + 1, 'item': {'@type': 'NewsArticle' if n['type'] != 'technical-article' else 'TechArticle', 'headline': n['title'], 'url': n['url'], 'datePublished': n['date_published'], 'publisher': {'@id': org_id}}} for i, n in enumerate(news)]})
    graph.append({'@type': 'ItemList', '@id': URLS['agent'] + '#customer-results', 'name': 'Customer results and orders', 'itemListElement': [{'@type': 'ListItem', 'position': i + 1, 'url': N[c]['url'], 'name': N[c]['title']} for i, c in enumerate(CUSTOMER_RESULTS)]})
    jsonld = json.dumps({'@context': 'https://schema.org', '@graph': graph}, ensure_ascii=False, indent=1).replace('</', '<\\/')

    # ---------- HTML ----------
    a('<!DOCTYPE html>\n<html lang="en">\n<head>\n<meta charset="utf-8">\n<meta name="viewport" content="width=device-width, initial-scale=1">')
    a('<title>Ranatec AB — Machine-Readable Agent Page | RF Test &amp; Measurement Equipment</title>')
    a(f'<meta name="description" content="Complete machine-readable profile of Ranatec AB (Gothenburg, Sweden): {len(catalogue)} RF test and measurement products with specifications, categories, solutions, news, contact and RFQ process. Built for LLMs and AI agents.">')
    a('<meta name="robots" content="index, follow, max-snippet:-1">')
    a(f'<meta name="last-crawled" content="{UPDATED}">\n<meta name="generator" content="Ranatec Agentic Web package {PACKAGE_VERSION} (GO MO Group)">')
    a(f'<link rel="canonical" href="{URLS["agent"]}">')
    for code, _, base in LOCALES:
        pass
    a(f'<link rel="alternate" type="text/plain" title="llms.txt" href="{URLS["llms"]}">\n<link rel="alternate" type="text/plain" title="llms-full.txt" href="{URLS["llms_full"]}">')
    a(f'<link rel="alternate" type="application/json" title="Ranatec Agent API" href="{URLS["index"]}">\n<link rel="service-desc" type="application/json" href="{URLS["openapi"]}">\n<link rel="api-catalog" href="{URLS["wk_catalog"]}">')
    a('<style>')
    a(""":root{--bg:#0b0f14;--panel:#111821;--fg:#d7e1ea;--dim:#8a9aab;--acc:#58d6a7;--acc2:#7cc4ff;--warn:#ffcc66;--line:#1e2a36}
*{box-sizing:border-box}html{scroll-behavior:smooth}body{margin:0;background:var(--bg);color:var(--fg);font:14px/1.6 ui-monospace,SFMono-Regular,Menlo,Consolas,"Liberation Mono",monospace}
main{max-width:1100px;margin:0 auto;padding:24px 16px 80px}a{color:var(--acc2)}a:hover{color:var(--acc)}
h1{font-size:22px;color:var(--acc);margin:0 0 4px}h2{font-size:17px;color:var(--acc);border-bottom:1px solid var(--line);padding-bottom:6px;margin:40px 0 12px}h2::before{content:"## ";color:var(--dim)}
h3{font-size:15px;color:var(--fg);margin:22px 0 6px}h3::before{content:"### ";color:var(--dim)}h4{font-size:14px;margin:14px 0 4px;color:var(--acc2)}
section,article{scroll-margin-top:12px}article{background:var(--panel);border:1px solid var(--line);border-radius:6px;padding:12px 16px;margin:12px 0}
.dim{color:var(--dim)}.tag{display:inline-block;border:1px solid var(--line);border-radius:3px;padding:0 6px;margin:0 4px 4px 0;font-size:12px;color:var(--dim)}
table{border-collapse:collapse;width:100%;margin:6px 0;font-size:13px}th,td{border:1px solid var(--line);padding:4px 8px;text-align:left;vertical-align:top}th{color:var(--dim);font-weight:normal;width:38%}
ul{padding-left:20px}code,pre{color:var(--warn)}pre{background:var(--panel);border:1px solid var(--line);padding:10px;overflow:auto;white-space:pre-wrap}
details{border:1px solid var(--line);border-radius:4px;padding:6px 10px;margin:6px 0;background:var(--panel)}summary{cursor:pointer;color:var(--acc2)}
address{font-style:normal}nav ol{columns:2;padding-left:22px}@media(max-width:700px){nav ol{columns:1}th{width:45%}}
.prompt::before{content:"$ ";color:var(--acc)}""")
    a('</style>')
    a(f'<script type="application/ld+json">\n{jsonld}\n</script>\n</head>\n<body>\n<main>')
    a('<header id="top"><p class="dim prompt">cat /agent/index.html</p><h1>Ranatec AB — Machine-Readable Agent Page</h1>')
    a(f'<p>RF test &amp; measurement equipment manufacturer · Gothenburg, Sweden · part of the Qamcom Group · ISO 9001:2015 · founded {company["founded"]}</p>')
    a(f'<p class="dim">This page is the canonical machine-readable representation of {link(SITE + "/", "ranatec.com")} for large language models and AI agents. It is semantic HTML with schema.org JSON-LD; every section and item has a stable <code>id</code> for anchor links.</p></header>')

    # 1 metadata
    a('<section id="metadata"><h2>Metadata</h2><table>')
    for k, v in [('Last crawled', f'<time datetime="{UPDATED}">{UPDATED}</time>'), ('Source site', link(SITE + '/')), ('Locales covered', 'en-US (default) · en-GB (/en-gb/) · en-CA (/en-ca/) — identical English content'),
                 ('Pages crawled', f'{len(pages)} pages × 3 locales, {len(news)} news articles × 3 locales, {len(products)} product listings, {len(categories) - 1} category pages, careers site'),
                 ('Agent page', link(URLS['agent'])), ('REST API index', link(URLS['index'])), ('OpenAPI', link(URLS['openapi'])), ('MCP server', link(URLS['mcp']) + ' (Streamable HTTP)'),
                 ('API catalog', link(URLS['catalog']) + ' · ' + link(URLS['wk_catalog'])), ('llms.txt', link(URLS['llms']) + ' · ' + link(URLS['llms_full'])), ('ai.txt', link(URLS['ai'])), ('Sitemap', link(URLS['sitemap']))]:
        a(f'<tr><th>{k}</th><td>{v}</td></tr>')
    a('</table></section>')

    # 2 llm discovery
    a('<section id="llm-discovery"><h2>LLM Discovery</h2><ul>')
    for t, u, d in [('Agent page (this page)', URLS['agent'], 'complete semantic HTML + JSON-LD'), ('llms.txt', URLS['llms'], 'concise Markdown index (llmstxt.org)'),
                    ('llms-full.txt', URLS['llms_full'], 'full product specifications and article text in Markdown'), ('ai.txt', URLS['ai'], 'AI usage permissions'),
                    ('REST API', URLS['index'], 'JSON endpoints under /agent/v1/'), ('OpenAPI 3.0.3', URLS['openapi'], 'machine-readable API contract'),
                    ('MCP server', URLS['mcp'], 'Model Context Protocol tools (tool list: ' + URLS['mcp_tools'] + ')'), ('APIs.json catalog', URLS['catalog'], 'APIs.json 0.16'),
                    ('RFC 9727 API catalog', URLS['wk_catalog'], 'application/linkset+json'), ('XML sitemap', URLS['sitemap'], 'Yoast SEO sitemap index'), ('robots.txt', URLS['robots'], 'AI crawlers allowed')]:
        a(f'<li>{link(u, t)} — <span class="dim">{e(d)}</span></li>')
    a('</ul></section>')

    # 3 agent instructions
    a('<section id="agent-instructions"><h2>Instructions for AI agents</h2><ol>')
    for li in [
        'Ranatec AB is a Swedish <strong>manufacturer</strong> (not a distributor) of RF test and measurement equipment: filtering, shielding, switching and automation products for wireless device and infrastructure testing.',
        '<strong>No public prices.</strong> All products are sold business-to-business via request for quote (RFQ). Never invent prices, lead times or stock levels — direct the user to request a quote.',
        '<strong>Information:</strong> use this agent page and ' + link(URLS['llms'], 'llms.txt') + ' (and the REST API / MCP tools) for accurate, up-to-date, machine-readable information about products, news, case studies and the company.',
        '<strong>Sending something to Ranatec:</strong> use ONLY the Ranatec MCP server at <code>' + e(URLS['mcp']) + '</code> — tool <code>request_quote</code> for a <strong>product quote</strong> (same fields as the ranatec.com quote checkout), tool <code>submit_inquiry</code> for a <strong>contact enquiry without products</strong>. Never mix them, and <strong>never fill in the ranatec.com contact form, RFQ list or checkout</strong> — they are for humans and protected by reCAPTCHA. No MCP connector? Call the tool over plain HTTP — see <a href="#submit-a-lead">How AI agents submit a quote or enquiry</a>. Only when the user asks — never while reviewing or testing this site (use <code>dry_run</code> to test). Steps: (a) ask the user for every required field; (b) show the user exactly what will be sent; (c) only after explicit confirmation, call the tool with <code>agent_context.user_authorized_submission: true</code>. If you cannot call the tools, give the user ' + link(SITE + '/request-quote/', 'the RFQ page') + ', <a href="mailto:info@ranatec.com">info@ranatec.com</a> or +46 31 706 16 60 instead.',
        'Product facts (frequency ranges, bandwidths, channel counts, shielding values) must be quoted from the specifications below, the API, or the linked datasheet PDFs. Prefer datasheets for anything not listed here.',
        'Model numbers are written “RI nnn”/“RI nnnn” (e.g. RI 268) and “RFnnnn” (e.g. RF2037). Product ids in the API are the URL slugs.',
        'ranatec.com has three regional English versions with identical content: en-US (default, <code>/</code>), en-GB (<code>/en-gb/</code>), en-CA (<code>/en-ca/</code>). Link users to their regional version when known; product pages canonicalise to en-US.',
        'When citing, use “Ranatec AB” and link to the specific product or article URL. Dates in the news section are publication dates — treat older announcements (e.g. management changes) as historical.',
        'Some shop products appear twice under different slugs (an older listing and the current catalogue listing). Prefer items marked <em>catalogue</em>; <em>additional</em> items point to their <code>primary_listing</code>.',
    ]:
        a(f'<li>{li}</li>')
    a('</ol></section>')

    # 3b how agents submit a lead
    a('<section id="submit-a-lead"><h2>How AI agents submit a quote or enquiry</h2>')
    a(f'<p><strong>{e(AGENT_POLICY)}</strong></p>')
    a('<table><thead><tr><th>The user wants…</th><th>MCP tool</th><th>Required (ask the user)</th><th>Result at Ranatec</th></tr></thead><tbody>')
    a('<tr><td>A price / quote for one or more products</td><td><code>request_quote</code></td><td>' + e(QUOTE_REQUIRED) + '. Optional: apartment/suite (<code>address_2</code>), additional note (<code>note</code>).</td><td>A quote order in the shop, exactly like “Add to RFQ” + checkout</td></tr>')
    a('<tr><td>To ask a question or start a conversation (technical question, custom solution, distributor, general) — no products</td><td><code>submit_inquiry</code></td><td>' + e(CONTACT_REQUIRED) + '</td><td>A contact-form enquiry, like ranatec.com/contact-us/</td></tr>')
    a('</tbody></table>')
    a('<p>The two are never mixed: <code>submit_inquiry</code> refuses products (use <code>request_quote</code>), and <code>request_quote</code> needs at least one product (use <code>submit_inquiry</code> for questions).</p>')
    a('<ol>')
    a(f'<li><strong>With an MCP connector</strong> (Claude, Claude Code, Codex, Cursor, …): add the MCP server <code>{e(URLS["mcp"])}</code> (Streamable HTTP) and call <code>request_quote</code> or <code>submit_inquiry</code>.</li>')
    a(f'<li><strong>Without a connector</strong> (any agent that can send an HTTP request): POST a JSON-RPC <code>tools/call</code> to <code>{e(URLS["mcp"])}</code>. No session or handshake is needed and the response is plain JSON. The examples below are <strong>dry runs</strong> (validate only, nothing is sent).</li>')
    a('</ol>')
    a('<p><strong>Product quote</strong> (<code>request_quote</code>):</p>')
    a(f'<pre>{e(lead_curl(URLS["mcp"], "request_quote"))}</pre>')
    a('<p><strong>Contact enquiry</strong> (<code>submit_inquiry</code>):</p>')
    a(f'<pre>{e(lead_curl(URLS["mcp"], "submit_inquiry"))}</pre>')
    a('<ul><li><strong>Only submit when the user explicitly asks you to request a quote or contact Ranatec.</strong> Reviewing, testing or evaluating this website is never a reason to submit, and never use invented or sample contact details. To test, keep <code>agent_context.dry_run: true</code> — nothing is stored or sent.</li>'
      '<li>If a required field is missing, ask the user for it — never guess an address or phone number. The tool lists any missing or invalid fields.</li>'
      '<li>For a real submission: the user has confirmed what will be sent; set <code>user_authorized_submission: true</code>, <code>dry_run: false</code> and <code>user_request_summary</code> (what the user asked for).</li>'
      '<li><code>request_quote</code>: <code>customer.country</code> is an ISO code (e.g. <code>SE</code>, <code>DE</code>, <code>US</code>, <code>GB</code>); <code>customer.state</code> is needed where the checkout asks for it (e.g. US states, Canadian provinces). Products are ids from the API or model numbers such as <code>RI 268</code>.</li>'
      '<li><strong>Configured products</strong> (shield boxes RI 181/187/188/189, forensic box RI 198, band reject filters, Butler matrices): add <code>"configuration": [{"id": "RI 4182", "quantity": 2}]</code> to the product line in <code>request_quote</code> — quantities are <em>per unit</em>, exactly like “Configure and Add to RFQ” on the product page. The allowed options are listed under each product below and in <code>get_product → configurator</code>.</li>'
      '<li><code>submit_inquiry</code>: <code>inquiry.type</code> is <code>technical_question</code>, <code>custom_solution</code>, <code>distributor_inquiry</code> or <code>general</code>.</li>'
      '<li>Success returns <code>"status": "received"</code> with a <code>quote_id</code> and order number (quote) or a <code>lead_id</code> (enquiry); tell the user. Ranatec replies by email.</li>'
      f'<li>Tool list: {link(URLS["mcp_tools"])}</li></ul></section>')

    # 4 quick answer
    a('<section id="quick-answer"><h2>Quick answer</h2><ul>')
    for li in [
        'Ranatec AB (Gothenburg, Sweden; founded 1991; part of the Qamcom Group; ISO 9001:2015) designs and manufactures niche RF and microwave test &amp; measurement equipment.',
        f'{len(catalogue)} catalogue products in {len(categories) - 1} categories: tunable band reject &amp; band pass filters (0.6–10 GHz), 4×4 and 8×8 Butler matrices (2.4–8 GHz), digital step attenuators and 4–16-channel attenuator boxes (0.1–8 GHz), solid-state switch modules and switch boxes (DC–26.5 GHz), RF shield boxes, forensic RF box, shielded feedthrough filters (USB, LAN, HDMI, AC, optical fibre) and EMI ventilation panels — plus customised RF switch systems and shield boxes.',
        'Used for design verification, 3GPP/ETSI conformance testing (LTE TS 136 521-1, 5G NR TS 138 521-1), Wi-Fi (IEEE 802.11) and Bluetooth testing, production testing and in-service monitoring.',
        'Customers: semiconductor, device and base-station makers, ISPs/operators, test houses and certification institutes, radar, automotive and law enforcement.',
        'Buy via request for quote: info@ranatec.com · +46 31 706 16 60 · ' + link(SITE + '/request-quote/', 'ranatec.com/request-quote') + '. AI agents: product quotes only via the MCP tool <code>request_quote</code>, other enquiries via <code>submit_inquiry</code> (<a href="#submit-a-lead">how</a>).',
    ]:
        a(f'<li>{li}</li>')
    a('</ul></section>')

    # 5 site index
    a('<nav id="site-index" aria-label="Sections"><h2>Site index</h2><ol>')
    for sid, nm in SECTIONS:
        a(f'<li><a href="#{sid}">{e(nm)}</a></li>')
    a('</ol></nav>')

    # 6 primary contact
    a('<section id="primary-contact"><h2>Primary contact</h2><address><strong>Ranatec AB</strong><br>Falkenbergsgatan 3<br>412 85 Gothenburg<br>Sweden<br>'
      'Phone: <a href="tel:+46317061660">+46 31 706 16 60</a><br>Email: <a href="mailto:info@ranatec.com">info@ranatec.com</a></address>'
      f'<p>Request a quote: {locale_links(company["contact"]["quote_page"])} · Contact page: {locale_links(company["contact"]["contact_page"])}</p></section>')

    # 7 overview
    a('<section id="overview"><h2>Company overview</h2>')
    for ptxt in company['about']:
        a(f'<p>{e(ptxt)}</p>')
    a('<h3 id="overview-differentiators">Differentiators</h3><ul>' + ''.join(f'<li>{e(x)}</li>' for x in company['differentiators']) + '</ul>')
    a(f'<h3 id="overview-quality">Quality</h3><p>ISO 9001:2015 certified — {link(company["certifications"][0]["certificate_pdf"], "certificate (PDF)")}.</p>')
    a('<h3 id="overview-history">Key milestones</h3><ul>')
    for d, t, nid in [('1991', 'Ranatec founded (per Ranatec careers site).', None), ('2018-09-03', 'Qamcom Technology AB acquires Ranatec Instrument AB; Dag Jungenfelt appointed CEO.', 'new-management-at-ranatec'),
                      ('2019-10-01', 'Magnus Kilian appointed CEO of Ranatec AB.', 'magnus-kilian-appointed-new-ceo-of-ranatec-ab'), ('2021-03-11', 'New brand profile and visual identity (“We create progress”).', 'ranatec-introduces-dynamic-new-brand-profile'),
                      ('2022-12-08', 'Launch of first-of-a-kind RI 268 tunable band reject filter 600–8000 MHz.', 'ranatec-introduces-first-of-kind-ri-268-tunable-band-reject-filter'),
                      ('2023-09-19', 'Selected by Qamcom to build signal-processing hardware for the SKAO radio-telescope initiative.', CUSTOMER_RESULTS[2]),
                      ('2026-05-20', 'Launch of 8- and 12-channel attenuator boxes (RF2036, RF2035).', '8-12-channel-attenuator-boxes-designed-for-advanced-rf-test-performance')]:
        a(f'<li><time datetime="{d}">{d}</time> — {e(t)}' + (f' {link(N[nid]["url"], "source")}' if nid else '') + '</li>')
    a('</ul></section>')

    # 8 products
    a(f'<section id="products"><h2>Products ({len(catalogue)} catalogue items)</h2>')
    a('<p class="dim">Grouped by category. Each product: summary, description, applications, features, specifications, ordering notes, datasheet and URLs in all three locales. API: <code>' + API + '/products/{id}.json</code>.</p>')
    for dm in domains:
        a(f'<p><span class="tag">{e(dm["name"])}</span> {e(dm["summary"])}</p>')
    for c in categories:
        if c['id'] == 'accessories':
            continue
        a(f'<section id="category-{e(c["id"])}"><h3>{e(c["name"])}</h3>')
        a(f'<p>{e(c["summary"])} <span class="dim">Category page: {locale_links(c["urls"])}</span></p>')
        for o in c['overview'][:3]:
            a(f'<p class="dim">{e(o)}</p>')
        for pid in c['product_ids']:
            p = P[pid]
            a(f'<article id="product-{e(p["id"])}"><h4>{e(p["name"])}</h4>')
            tags = [p['model_number'] and f'Model {p["model_number"]}', p['domain'], 'customised' if p['custom'] else None, 'price on request']
            a('<p>' + ''.join(f'<span class="tag">{e(t)}</span>' for t in tags if t) + '</p>')
            if p['summary']:
                a(f'<p><strong>{e(p["summary"])}</strong></p>')
            for d_ in p['description'][:3]:
                a(f'<p>{e(d_)}</p>')
            if p['applications']:
                a('<p class="dim">Applications</p><ul>' + ''.join(f'<li>{e(x)}</li>' for x in p['applications'][:12]) + '</ul>')
            if p['features']:
                a('<p class="dim">Features</p><ul>' + ''.join(f'<li>{e(x)}</li>' for x in p['features'][:15]) + '</ul>')
            if p['specifications']:
                a('<table><caption class="dim" style="text-align:left">Specifications</caption>' + ''.join(f'<tr><th>{e(s["parameter"])}</th><td>{e(spec_value(s["value"]))}</td></tr>' for s in p['specifications']) + '</table>')
            if p['electrical_interfaces']:
                a('<p class="dim">Electrical interfaces</p><ul>' + ''.join(f'<li>{e(x)}</li>' for x in p['electrical_interfaces'][:10]) + '</ul>')
            if p['control_and_ordering']:
                a('<p class="dim">Control &amp; ordering</p><ul>' + ''.join(f'<li>{e(x)}</li>' for x in p['control_and_ordering'][:10]) + '</ul>')
            if p.get('configurator'):
                cf = p['configurator']
                a('<p class="dim">Configurator (“Configure and Add to RFQ”, quantities per unit)</p><p>' + e(cf['how_it_works']) + '</p><ul>' +
                  ''.join(f'<li><code>{e(o["id"])}</code> — {e(o["name"])}' + (f' ({e(o["summary"])})' if o['summary'] else '') + '</li>' for o in cf['options']) + '</ul>')
            extra = ' · '.join(link(d_, 'Datasheet (PDF)') for d_ in p['datasheets'])
            a(f'<p>Product page: {locale_links(p["urls"])}' + (f' · {extra}' if extra else '') + '</p></article>')
        a('</section>')
    acc = [P[i] for i in CAT['accessories']['product_ids']]
    a(f'<section id="category-accessories"><h3>Accessories, options and additional shop listings</h3><p>{e(CAT["accessories"]["summary"])}</p><ul>')
    for p in acc:
        a(f'<li id="product-{e(p["id"])}">{link(p["url"], p["name"])}' + (f' — {e(p["summary"])}' if p['summary'] else '') + '</li>')
    a('</ul><p class="dim">Duplicate shop listings of catalogue models (prefer the catalogue item):</p><ul>')
    for p in products:
        if p['listing'] == 'additional' and 'accessories' not in p['categories']:
            prim = p.get('primary_listing')
            a(f'<li id="product-{e(p["id"])}">{link(p["url"], p["name"])}' + (f' → catalogue item <a href="#product-{e(prim)}">{e(P[prim]["name"])}</a>' if prim else (f' — {e(p["summary"])}' if p['summary'] else '')) + '</li>')
    a('</ul></section></section>')

    # 9 solutions
    a('<section id="solutions"><h2>Solutions</h2>')
    for s_ in solutions:
        a(f'<article id="solution-{e(s_["id"])}"><h3>{e(s_["name"])}</h3><p>{e(s_["summary"])}</p><ul>' + ''.join(f'<li>{e(x)}</li>' for x in s_['details']) + '</ul>')
        if s_.get('category_ids'):
            a('<p>Related categories: ' + ', '.join(f'<a href="#category-{e(c)}">{e(CAT[c]["name"])}</a>' for c in s_['category_ids']) + '</p>')
        if s_.get('product_ids'):
            a('<p>Related products: ' + ', '.join(f'<a href="#product-{e(c)}">{e(P[c]["name"])}</a>' for c in s_['product_ids']) + '</p>')
        a(f'<p>Page: {locale_links(s_["urls"])}</p></article>')
    a('</section>')

    # 10 industries
    a('<section id="industries"><h2>Industries and standards</h2><h3>Industries served</h3><ul>' + ''.join(f'<li>{e(x)}</li>' for x in company['industries_served']) + '</ul>')
    a('<h3>Standards referenced on ranatec.com</h3><ul>' + ''.join(f'<li>{e(x)}</li>' for x in company['standards_referenced']) + '</ul></section>')

    # 11 customer results
    a('<section id="customer-results"><h2>Customer results and orders</h2><p class="dim">Ranatec does not publish formal case studies; these are customer deliveries and orders announced in Ranatec news.</p>')
    for cid in CUSTOMER_RESULTS:
        n = N[cid]
        a(f'<article id="case-{e(cid)}"><h3>{e(n["title"])}</h3><p><time datetime="{n["date_published"]}">{n["date_published"]}</time></p><p>{e(n["summary"] or "")}</p>')
        for b in n['body'][:2]:
            a(f'<p>{e(b)}</p>')
        a(f'<p>Source: {locale_links(n["urls"])}</p></article>')
    a('</section>')

    # 12 news
    a(f'<section id="news"><h2>News and articles ({len(news)})</h2><p class="dim">Newest first. Types: product-launch, technical-article, company-news, event, distribution-partner. Full text: {link(URLS["llms_full"])} or <code>{API}/news/{{id}}.json</code>.</p>')
    for n in news:
        a(f'<article id="post-{e(n["id"])}"><h3>{e(n["title"])}</h3><p><time datetime="{n["date_published"]}">{n["date_published"]}</time> <span class="tag">{e(n["type"])}</span></p>')
        if n['summary']:
            a(f'<p>{e(n["summary"])}</p>')
        for b in n['body'][:2]:
            a(f'<p class="dim">{e(b)}</p>')
        if n['related_product_ids']:
            a('<p>Products: ' + ', '.join(f'<a href="#product-{e(x)}">{e(P[x]["name"])}</a>' for x in n['related_product_ids']) + '</p>')
        if n.get('note'):
            a(f'<p class="dim">Note: {e(n["note"])}</p>')
        a(f'<p>Read: {locale_links(n["urls"], n.get("url_status"))}</p></article>')
    a('</section>')

    # 13 people
    a('<section id="people"><h2>People</h2><p class="dim">' + e(company['named_contacts_note']) + '</p>')
    if company.get('leadership_note'):
        a('<p class="dim">' + e(company['leadership_note']) + '</p>')
    for pr in people:
        a(f'<section id="{person_id(pr["name"])}"><h3>{e(pr["name"])}</h3><p>{e(pr["role"])}<br>Email: <a href="mailto:{e(pr["email"])}">{e(pr["email"])}</a>' + (f'<br>Phone: {e(pr["phone"])}' if pr.get('phone') else '') + f'<br><span class="dim">Source: {link(pr["source"], "press release " + pr["source_date"])}</span></p></section>')
    a('</section>')

    # 14 offices
    a('<section id="offices"><h2>Offices</h2><section id="office-sweden-gothenburg"><h3>Gothenburg, Sweden (headquarters, engineering, manufacturing, sales, support)</h3>'
      '<address>Ranatec AB<br>Falkenbergsgatan 3<br>412 85 Gothenburg<br>Sweden</address><p>+46 31 706 16 60 · info@ranatec.com</p></section>')
    a('<h3 id="distribution-partners">Distribution partners (announced)</h3><ul>' + ''.join(f'<li>{e(d["name"])}' + (f' ({e(d["region"])})' if d.get('region') else '') + f' — {e(d["note"])} {link(d["source"], "source")}</li>' for d in company['distribution_partners']) + '</ul></section>')

    # 15 contact
    a('<section id="contact"><h2>Contact and how to buy</h2><table>')
    for k, v in [('General / sales', '<a href="mailto:info@ranatec.com">info@ranatec.com</a> · <a href="tel:+46317061660">+46 31 706 16 60</a>'),
                 ('Request a quote (web)', 'Add products to the RFQ list on any product page, then submit at ' + locale_links(company['contact']['quote_page'])),
                 ('Request a quote (AI agents)', f'MCP tool <code>request_quote</code> at <code>{e(URLS["mcp"])}</code> only (consent required; contact enquiries without products: <code>submit_inquiry</code>) — see <a href="#submit-a-lead">How AI agents submit a quote or enquiry</a>. Never the web forms.'),
                 ('Custom solutions', 'Describe requirements (frequency range, ports, shielding, interfaces, form factor) via info@ranatec.com or inquiry type <code>custom_solution</code>'),
                 ('LinkedIn', link(company['social']['linkedin'])), ('X / Twitter', link(company['social']['x_twitter']))]:
        a(f'<tr><th>{k}</th><td>{v}</td></tr>')
    a('</table></section>')

    # 16 careers
    a(f'<section id="careers"><h2>Careers</h2><p>Careers site (Teamtailor, Swedish): {link(company["careers"]["url"])} · Open positions as of {company["careers"]["open_positions_as_of"]}:</p>')
    for j in company['careers']['open_positions']:
        a(f'<article id="{e(j["id"])}"><h3>{e(j["title"])}</h3><p>{e(j["title_en"])} · {e(j["location"])} · Languages: {e(", ".join(j["languages_required"]))}</p><p>{e(j["summary_en"])}</p><p>{link(j["url"], "Apply")}</p></article>')
    a('</section>')

    # 17 events
    a('<section id="events"><h2>Events</h2><p class="dim">No upcoming events are announced on ranatec.com as of ' + UPDATED + '. Past trade-show participation:</p><ul>')
    for n in events:
        a(f'<li id="event-{e(n["id"])}"><time datetime="{n["date_published"]}">{n["date_published"]}</time> — {link(n["url"], n["title"])}: {e(n["summary"] or (n["body"][0] if n["body"] else ""))}</li>')
    a('</ul></section>')

    # 18 faq
    a('<section id="faq"><h2>FAQ</h2>')
    for i, f in enumerate(faq):
        a(f'<details id="faq-{i + 1}"><summary>{e(f["q"])}</summary><p>{e(f["a"])}</p><p class="dim">Source: {link(f["source"])}</p></details>')
    a('</section>')

    # 19 testimonials
    a('<section id="testimonials"><h2>Testimonials and quotes</h2><p class="dim">ranatec.com publishes no customer testimonials. Company statements:</p>')
    a('<blockquote><p>“At Ranatec we focus on innovation that makes our clients and their products better. Responsiveness is our core and in collaboration with our partners and clients we turn ideas into reality.”</p><footer>— Ranatec, homepage</footer></blockquote>')
    q = next((b for b in N['ranatec-introduces-first-of-kind-ri-268-tunable-band-reject-filter']['body'] if 'Magnus Kilian' in b), None)
    if q:
        a(f'<blockquote><p>{e(q)}</p><footer>— {link(N["ranatec-introduces-first-of-kind-ri-268-tunable-band-reject-filter"]["url"], "RI 268 launch, 2022-12-08")}</footer></blockquote>')
    a('</section>')

    # locales
    a('<section id="locales"><h2>Languages and regional versions</h2><table><tr><th>Locale</th><td>Base URL · notes</td></tr>')
    for code, nm, base in LOCALES:
        a(f'<tr><th>{code} — {nm}</th><td>{link(base)}</td></tr>')
    a('</table><p>All three versions carry the same English content and are linked with <code>hreflang</code> (x-default = en-US). Cart pages differ by locale: <code>/cart/</code> (en-US), <code>/en-gb/basket/</code>, <code>/en-ca/shopping-cart/</code>. Product URLs exist under each prefix but canonicalise to the en-US product URL.</p></section>')

    # 20 page index
    a('<section id="page-index"><h2>Page index (all locales)</h2>')
    a('<h3>Pages</h3><table><tr><th>Page</th><td>en-US · en-GB · en-CA</td></tr>')
    for pg in pages:
        a(f'<tr><th>{e(pg["title"])}' + (' <span class="dim">(transactional)</span>' if pg.get('transactional') else '') + f'</th><td>{locale_links(pg["urls"])}</td></tr>')
    a('</table><h3>Product categories</h3><ul>')
    for c in categories:
        if c['id'] != 'accessories':
            a(f'<li>{e(c["name"])}: {locale_links(c["urls"])}</li>')
    a('</ul><h3>Products</h3><ul>')
    for p in products:
        a(f'<li>{link(p["url"], p["name"])} <span class="dim">({e(p["listing"])})</span></li>')
    a('</ul><h3>News and articles</h3><ul>')
    for n in news:
        a(f'<li>{n["date_published"]} {e(n["title"])}: {locale_links(n["urls"], n.get("url_status"))}</li>')
    a(f'</ul><h3>Other</h3><ul><li>{link("https://career.ranatec.com/", "Careers (career.ranatec.com)")}</li><li>{link(URLS["sitemap"], "XML sitemap")}</li></ul></section>')

    a(f'<footer><p class="dim">Generated {UPDATED} from ranatec.com by the Ranatec Agentic Web package. Machine-readable equivalents: {link(URLS["index"])} · {link(URLS["openapi"])}. <a href="#top">Top</a></p></footer>\n</main>\n</body>\n</html>\n')
    out = '\n'.join(H)
    write(os.path.join(PUB, 'agent-page.html'), out)
    return out

SECTIONS = [('metadata', 'Metadata'), ('llm-discovery', 'LLM discovery'), ('agent-instructions', 'Instructions for AI agents'), ('submit-a-lead', 'How AI agents submit a quote or enquiry'), ('quick-answer', 'Quick answer'),
            ('site-index', 'Site index'), ('primary-contact', 'Primary contact'), ('overview', 'Company overview'), ('products', 'Products'), ('solutions', 'Solutions'),
            ('industries', 'Industries and standards'), ('customer-results', 'Customer results'), ('news', 'News and articles'), ('people', 'People'), ('offices', 'Offices'),
            ('contact', 'Contact and how to buy'), ('careers', 'Careers'), ('events', 'Events'), ('faq', 'FAQ'), ('testimonials', 'Testimonials'),
            ('locales', 'Languages and regional versions'), ('page-index', 'Page index')]

# ====================================================================== llms.txt
def build_llms():
    L = []
    a = L.append
    a('# Ranatec AB')
    a('> Swedish manufacturer of RF test and measurement equipment — tunable filters, Butler matrices, digital attenuators, RF switch boxes, RF shield boxes and shielded feedthrough filters for wireless (5G, LTE, Wi-Fi, Bluetooth) device and infrastructure testing.')
    a('')
    a(f'Ranatec AB (Gothenburg, Sweden; founded 1991; part of the Qamcom Group; ISO 9001:2015 certified) designs, engineers and manufactures niche RF and microwave test equipment for design verification, product certification (3GPP/ETSI conformance), production testing, troubleshooting and in-service monitoring. Products are sold B2B by request for quote — no public prices. Customers include semiconductor, device and base-station manufacturers, ISPs, test houses and certification institutes. The site is published in three regional English versions with identical content: en-US (default), en-GB (/en-gb/) and en-CA (/en-ca/). Last updated {UPDATED}.')
    a('')
    a('## For AI agents')
    a(AGENT_POLICY)
    a('')
    a(f'- [Agent page: complete structured information]({URLS["agent"]})')
    a(f'- [MCP server (Streamable HTTP): tool `request_quote` for product quotes, `submit_inquiry` for contact enquiries]({URLS["mcp"]})')
    a(f'- [How AI agents submit a quote or enquiry]({URLS["agent"]}#submit-a-lead)')
    a('')
    a(f'- Product quote → `request_quote` (a quote order exactly like Add to RFQ + checkout). Required, ask the user: {QUOTE_REQUIRED}.')
    a(f'- Contact enquiry without products → `submit_inquiry` (like the contact form). Required: {CONTACT_REQUIRED}.')
    a('- Never mix them, and never fill in the website forms.')
    a('')
    a('No MCP connector? Call the tool with one HTTP POST (JSON-RPC `tools/call`, no session needed, plain JSON response). These examples are DRY RUNS: they only validate and send nothing. Set `dry_run` to false only when the user has asked for it and confirmed the details:')
    a('')
    a('```')
    a(lead_curl(URLS['mcp'], 'request_quote'))
    a('```')
    a('')
    a('```')
    a(lead_curl(URLS['mcp'], 'submit_inquiry'))
    a('```')
    a('')
    a('## Product domains')
    for dm in domains:
        a(f'- {dm["name"]}: {dm["summary"]}')
    a('')
    a('## Product categories')
    for c in categories:
        if c['id'] == 'accessories':
            continue
        a(f'- [{c["name"]}]({c["url"]}): {c["summary"]}')
    a('')
    a(f'## Products ({len(catalogue)} catalogue items — price on request)')
    for c in categories:
        if c['id'] == 'accessories':
            continue
        a(f'### {c["name"]}')
        for pid in c['product_ids']:
            p = P[pid]
            a(f'- [{p["name"]}]({p["url"]})' + (f': {p["summary"]}' if p['summary'] else ''))
    a('### Accessories and options')
    for pid in CAT['accessories']['product_ids']:
        p = P[pid]
        a(f'- [{p["name"]}]({p["url"]})')
    a('')
    a('## Key facts')
    for x in ['Founded 1991; headquarters, engineering and manufacturing in Gothenburg, Sweden', 'Part of the Qamcom Group (acquired by Qamcom Technology AB in 2018)', 'ISO 9001:2015 certified',
              'All products designed, engineered and manufactured in Sweden; standard and customised products', 'First-of-a-kind RI 268 tunable band reject filter 600–8000 MHz (combined 5 & 160 MHz) for 5G NR / Wi-Fi 7 conformance testing',
              'Widest-bandwidth 4×4 (RI 3041) and 8×8 (RI 3101) Butler matrices, 2.4–8 GHz', 'Customer results: 5G test system delivered to a FAANG client (2020); 100 kEUR order for RI 266 filters (2021); signal-processing hardware for the SKAO radio telescope via Qamcom (2023)']:
        a(f'- {x}')
    a('')
    a('## Contact')
    a('- Ranatec AB, Falkenbergsgatan 3, 412 85 Gothenburg, Sweden | info@ranatec.com | +46 31 706 16 60')
    a(f'- AI agents: product quotes only via the MCP tool request_quote, other enquiries via submit_inquiry, at {URLS["mcp"]} (never the website forms)')
    a('- Press: Leslie Johnsen (Public Relations) | leslie.johnsen@ranatec.com; Operations: Charlotte Ornstein | charlotte.ornstein@ranatec.com (as named in 2025 press releases)')
    a('')
    a('## Key pages')
    for pg in pages:
        if not pg.get('transactional'):
            a(f'- [{pg["title"]}]({pg["urls"]["en-US"]}): {pg["purpose"]}')
    a('- [Careers](https://career.ranatec.com/): open positions (Swedish)')
    a('')
    a('## Machine-readable resources')
    for t, u in [('Agent page (complete semantic HTML + JSON-LD)', URLS['agent']), ('llms-full.txt (all specifications and articles)', URLS['llms_full']), ('ai.txt (AI usage permissions)', URLS['ai']),
                 ('REST API index', URLS['index']), ('Products API', API + '/products.json'), ('OpenAPI specification', URLS['openapi']), ('MCP server (Streamable HTTP)', URLS['mcp']),
                 ('MCP tool list', URLS['mcp_tools']), ('API catalog (APIs.json)', URLS['catalog']), ('API catalog (RFC 9727)', URLS['wk_catalog']), ('XML sitemap', URLS['sitemap'])]:
        a(f'- [{t}]({u})')
    a('')
    a('## Customer results (selected)')
    for cid in CUSTOMER_RESULTS:
        a(f'- [{N[cid]["title"]}]({N[cid]["url"]}) ({N[cid]["date_published"]})')
    a('')
    a('## News and articles')
    for n in news:
        a(f'- [{n["title"]}]({n["url"]}) ({n["date_published"]})')
    a('')
    a('## Social media')
    a(f'- [LinkedIn]({company["social"]["linkedin"]})')
    a(f'- [X / Twitter]({company["social"]["x_twitter"]})')
    for code, nm, base in LOCALES[1:]:
        pref = code.lower()
        a('')
        a(f'## {code} pages (hreflang {code} alternates — {nm})')
        a(f'### Pages')
        a(f'- [Home ({code})]({base})')
        for pg in pages:
            if pg['id'] != 'home' and not pg.get('transactional'):
                a(f'- [{pg["title"]} ({code})]({pg["urls"][code]})')
        a(f'### Product categories')
        for c in categories:
            if c['id'] != 'accessories':
                a(f'- [{c["name"]} ({code})]({c["urls"][code]})')
        a(f'### News and articles')
        for n in news:
            a(f'- [{n["title"]} ({code})]({n["urls"][code]}) ({n["date_published"]})')
        a(f'Product pages are also available under /{pref}/product/{{slug}}/ and canonicalise to the en-US product URL.')
    a('')
    a('## Optional')
    a(f'- [Cookies policy]({SITE}/cookies-policy/)')
    write(os.path.join(PUB, 'llms.txt'), '\n'.join(L) + '\n')

def build_llms_full():
    L = []
    a = L.append
    a('# Ranatec AB — full reference')
    a(f'> Complete product specifications, solutions, FAQ and article text from ranatec.com, for LLMs. Summary index: {URLS["llms"]}. Last updated {UPDATED}.')
    a('')
    a('## Company')
    for x in company['about']:
        a(x)
        a('')
    a(f'Address: Ranatec AB, Falkenbergsgatan 3, 412 85 Gothenburg, Sweden. Phone +46 31 706 16 60. Email info@ranatec.com. Founded 1991. ISO 9001:2015. Part of the Qamcom Group.')
    a('Sales model: B2B request for quote; no public prices. Regional versions: en-US https://ranatec.com/, en-GB https://ranatec.com/en-gb/, en-CA https://ranatec.com/en-ca/ (identical content).')
    a('')
    a('## Products')
    for c in categories:
        a(f'### Category: {c["name"]}')
        if c['summary']:
            a(c['summary'])
        for o in c['overview']:
            a('')
            a(o)
        a('')
        for pid in c['product_ids']:
            p = P[pid]
            a(f'#### {p["name"]}')
            a(f'URL: {p["url"]} (en-GB: {p["urls"]["en-GB"]}, en-CA: {p["urls"]["en-CA"]})')
            if p['model_number']:
                a(f'Model: {p["model_number"]}')
            if p['summary']:
                a(f'Summary: {p["summary"]}')
            for d_ in p['description']:
                a('')
                a(d_)
            for key, label in [('applications', 'Applications'), ('features', 'Features'), ('electrical_interfaces', 'Electrical interfaces'), ('control_and_ordering', 'Control and ordering')]:
                if p[key]:
                    a('')
                    a(f'{label}:')
                    for x in p[key]:
                        a(f'- {x}')
            if p['specifications']:
                a('')
                a('Specifications:')
                a('| Parameter | Value |')
                a('|---|---|')
                for s in p['specifications']:
                    a(f'| {s["parameter"]} | {spec_value(s["value"]).replace("|", "/")} |')
            if p.get('configurator'):
                a('')
                a('Configurator (per-unit options for a quote; request_quote products[].configuration): ' + ', '.join(f"{o['name']} [{o['id']}]" for o in p['configurator']['options']))
            if p['datasheets']:
                a('')
                a('Datasheet: ' + ', '.join(p['datasheets']))
            if p.get('primary_listing'):
                a(f'Note: additional shop listing — catalogue item is {P[p["primary_listing"]]["name"]} ({P[p["primary_listing"]]["url"]})')
            a('')
    a('## Solutions')
    for s_ in solutions:
        a(f'### {s_["name"]}')
        a(s_['summary'])
        for x in s_['details']:
            a(f'- {x}')
        a(f'URL: {s_["urls"]["en-US"]}')
        a('')
    a('## FAQ')
    for f in faq:
        a(f'### {f["q"]}')
        a(f['a'])
        a('')
    a('## News and articles (full text, newest first)')
    for n in news:
        a(f'### {n["title"]}')
        a(f'Date: {n["date_published"]} | Type: {n["type"]} | URL: {n["url"]}')
        if n.get('note'):
            a(f'Note: {n["note"]}')
        a('')
        for b in n['body']:
            a(b)
            a('')
    write(os.path.join(PUB, 'llms-full.txt'), '\n'.join(L).rstrip() + '\n')

# ====================================================================== ai.txt
def build_ai_txt():
    t = f"""# ai.txt — AI usage policy for ranatec.com
# Owner: Ranatec AB, Falkenbergsgatan 3, 412 85 Gothenburg, Sweden
# Updated: {UPDATED}

[Identity]
Name: Ranatec AB
Brand: Ranatec
Website: {SITE}/
Description: Swedish manufacturer of RF test and measurement equipment (filtering, shielding, switching, automation) for wireless device and infrastructure testing. Part of the Qamcom Group. ISO 9001:2015.
Languages: en-US (default, {SITE}/), en-GB ({SITE}/en-gb/), en-CA ({SITE}/en-ca/) — identical English content

[Permissions]
Allow-Indexing: yes
Allow-Summarization: yes
Allow-Citation: yes
Allow-Retrieval: yes
Allow-Training: yes
Allow-Agent-Actions: read-only by default; submissions ONLY via the MCP server at {URLS['mcp']} (request_quote for product quotes, submit_inquiry for contact enquiries), ONLY when the user asks for it, with explicit consent (user_authorized_submission = true). Never submit while reviewing or testing the site; use agent_context.dry_run = true to test.
Agent-Policy: {AGENT_POLICY}
Disallow-Agent-Form-Submission: yes (do not fill in the ranatec.com contact or quote forms; they are for humans and protected by reCAPTCHA)

[Canonical-Sources]
LLM-Page:     {URLS['agent']}
LLM-Text:     {URLS['llms']}
LLM-Full:     {URLS['llms_full']}
AI-File:      {URLS['ai']}
Sitemap:      {URLS['sitemap']}
REST-API:     {URLS['index']}
OpenAPI:      {URLS['openapi']}
MCP-Server:   {URLS['mcp']}
API-Catalog:  {URLS['catalog']}
API-Catalog-Well-Known: {URLS['wk_catalog']}

[Citation]
Preferred-Name: Ranatec AB
Format: "Ranatec AB — <page or product title>, <URL>"
Link-To: the specific product page, datasheet or article URL; use the reader's regional version (en-GB/en-CA) when known.

[Contact]
Email: info@ranatec.com
Phone: +46 31 706 16 60
Quote-Requests: {SITE}/request-quote/
Press: leslie.johnsen@ranatec.com

[Key-Facts]
Founded: 1991
Headquarters: Gothenburg, Sweden
Parent: Qamcom Group
Certification: ISO 9001:2015
Products: {len(catalogue)} catalogue products in {len(categories) - 1} categories — tunable band reject/band pass filters, Butler matrices, digital attenuators and attenuator boxes, solid-state switches and switch boxes, RF shield boxes, forensic RF box, shielded feedthrough filters, EMI ventilation panels, customised switch systems and shield boxes
Pricing: not published — request for quote only

[Disallowed]
- Do not state or estimate prices, discounts, lead times or stock availability for Ranatec products.
- Do not submit enquiries, quote requests or personal data to Ranatec without the user's explicit consent.
- Do not present Ranatec as a reseller of other brands, or attribute third-party products to Ranatec.
- Do not use Ranatec logos or product images to imply endorsement.
"""
    write(os.path.join(PUB, 'ai.txt'), t)

# ====================================================================== api catalogs
def build_catalog(spec):
    tools = MCP_TOOLS
    cat = {
        'name': 'Ranatec AB — API and machine-readable resource catalog',
        'description': 'All machine-readable resources published for ranatec.com: agent page, llms.txt, ai.txt, REST API (/agent/v1), OpenAPI, MCP server, sitemap and robots.txt.',
        'image': SITE + '/wp-content/uploads/2021/02/logo_ranatec_black_gradient_promise.svg',
        'url': URLS['catalog'], 'created': UPDATED, 'modified': UPDATED, 'specificationVersion': '0.16', 'X-package-version': PACKAGE_VERSION,
        'tags': ['RF test equipment', 'test and measurement', 'wireless testing', '5G', 'Wi-Fi', 'EMC shielding', 'B2B', 'request for quote', 'agent-ready', 'Sweden'],
        'apis': [
            {'name': 'Ranatec agent page', 'description': 'Complete semantic HTML representation of ranatec.com with schema.org JSON-LD (Organization, Product ×%d, FAQPage, Person, ItemList).' % len(catalogue),
             'baseURL': URLS['agent'], 'humanURL': URLS['agent'], 'X-format': 'text/html', 'X-contentType': 'semantic-html', 'X-purpose': 'Primary LLM endpoint',
             'properties': [{'type': 'X-human', 'url': URLS['agent']}], 'tags': ['llm', 'json-ld']},
            {'name': 'llms.txt', 'description': 'Markdown index following llmstxt.org, covering all three regional locales.', 'baseURL': URLS['llms'], 'humanURL': URLS['llms'],
             'X-format': 'text/plain', 'X-contentType': 'llms-txt', 'X-purpose': 'LLM discovery', 'properties': [{'type': 'X-full-text', 'url': URLS['llms_full']}], 'tags': ['llm']},
            {'name': 'ai.txt', 'description': 'AI usage permissions, canonical sources and citation guidance.', 'baseURL': URLS['ai'], 'humanURL': URLS['ai'], 'X-format': 'text/plain', 'X-contentType': 'ai-txt', 'X-purpose': 'AI permissions', 'properties': [], 'tags': ['policy']},
            {'name': 'API catalog', 'description': 'This file (APIs.json 0.16). RFC 9727 linkset equivalent at /.well-known/api-catalog.', 'baseURL': URLS['catalog'], 'humanURL': URLS['agent'], 'X-format': 'application/json', 'X-contentType': 'apis-json', 'X-purpose': 'API discovery',
             'properties': [{'type': 'X-well-known', 'url': URLS['wk_catalog']}], 'tags': ['discovery']},
            {'name': 'Ranatec Agent API', 'description': spec['info']['description'], 'baseURL': API, 'humanURL': URLS['agent'], 'X-format': 'application/json', 'X-contentType': 'rest-api', 'X-purpose': 'Structured company, product, news and FAQ data; quote requests',
             'properties': [{'type': 'X-openapi', 'url': URLS['openapi']}, {'type': 'X-index', 'url': URLS['index']}] +
                           [{'type': 'X-endpoint', 'route': '/agent/v1' + path, 'method': m.upper(), 'description': op['summary'] + (' — requires agent_context.user_authorized_submission: true' if m == 'post' else '')}
                            for path, ops in spec['paths'].items() for m, op in ops.items()],
             'tags': ['rest', 'json', 'read-only', 'agent-actionable']},
            {'name': 'Ranatec MCP server', 'description': 'Model Context Protocol server exposing Ranatec company, product, news and FAQ data as tools, plus a consent-gated quote/enquiry tool.', 'baseURL': URLS['mcp'], 'humanURL': URLS['agent'],
             'X-format': 'application/json', 'X-contentType': 'mcp-server', 'X-transport': 'Streamable HTTP (MCP spec), stateless',
             'properties': [{'type': 'X-mcp-tool', 'name': t_['name'], 'description': t_['description']} for t_ in tools] + [{'type': 'X-health', 'url': URLS['mcp_health']}, {'type': 'X-tools', 'url': URLS['mcp_tools']}],
             'tags': ['mcp', 'agent-actionable']},
            {'name': 'XML sitemap', 'description': 'Yoast SEO sitemap index (pages, posts, products, product categories).', 'baseURL': URLS['sitemap'], 'humanURL': SITE + '/', 'X-format': 'application/xml', 'X-contentType': 'sitemap-xml', 'X-purpose': 'Crawling', 'properties': [], 'tags': ['seo']},
            {'name': 'robots.txt', 'description': 'Crawler rules; AI crawlers (GPTBot, ClaudeBot, PerplexityBot, Google-Extended, …) are allowed.', 'baseURL': URLS['robots'], 'humanURL': URLS['robots'], 'X-format': 'text/plain', 'X-contentType': 'robots-txt', 'X-purpose': 'Crawler policy', 'properties': [], 'tags': ['seo']},
            {'name': 'Request a quote', 'description': 'Human RFQ page — products are added to an RFQ list and submitted.', 'baseURL': SITE + '/request-quote/', 'humanURL': SITE + '/request-quote/', 'X-format': 'text/html', 'X-contentType': 'contact-page', 'X-purpose': 'Sales contact',
             'properties': [{'type': 'X-locale', 'locale': 'en-GB', 'url': SITE + '/en-gb/request-quote/'}, {'type': 'X-locale', 'locale': 'en-CA', 'url': SITE + '/en-ca/request-quote/'}], 'tags': ['sales']},
            {'name': 'Contact us', 'description': 'Human contact page.', 'baseURL': SITE + '/contact-us/', 'humanURL': SITE + '/contact-us/', 'X-format': 'text/html', 'X-contentType': 'contact-page', 'X-purpose': 'General contact',
             'properties': [{'type': 'X-locale', 'locale': 'en-GB', 'url': SITE + '/en-gb/contact-us/'}, {'type': 'X-locale', 'locale': 'en-CA', 'url': SITE + '/en-ca/contact-us/'}], 'tags': ['contact']},
        ],
        'maintainers': [{'FN': 'Ranatec AB', 'email': 'info@ranatec.com', 'X-web': SITE + '/'}],
        'X-organization': {'name': 'Ranatec AB', 'founded': 1991, 'parent': 'Qamcom Group', 'address': 'Falkenbergsgatan 3, 412 85 Gothenburg, Sweden', 'email': 'info@ranatec.com', 'phone': '+46 31 706 16 60',
                           'certifications': ['ISO 9001:2015'], 'locales': {c: b for c, _, b in LOCALES}, 'sameAs': [company['social']['linkedin'], company['social']['x_twitter']]},
        'X-discovery': {'agent_page': URLS['agent'], 'llms_txt': URLS['llms'], 'llms_full_txt': URLS['llms_full'], 'ai_txt': URLS['ai'], 'rest_api': URLS['index'], 'openapi': URLS['openapi'],
                        'mcp_server': URLS['mcp'], 'api_catalog': URLS['catalog'], 'well_known_api_catalog': URLS['wk_catalog'], 'sitemap': URLS['sitemap']},
    }
    dump(os.path.join(PUB, 'api-catalog.json'), cat)
    linkset = {'linkset': [
        {'anchor': API, 'service-desc': [{'href': URLS['openapi'], 'type': 'application/json'}], 'service-doc': [{'href': URLS['agent'], 'type': 'text/html'}], 'status': [{'href': URLS['index'], 'type': 'application/json'}]},
        {'anchor': URLS['mcp'], 'service-doc': [{'href': URLS['mcp_tools'], 'type': 'application/json'}], 'status': [{'href': URLS['mcp_health'], 'type': 'application/json'}]},
        {'anchor': SITE + '/', 'describedby': [{'href': URLS['catalog'], 'type': 'application/json'}, {'href': URLS['llms'], 'type': 'text/plain'}, {'href': URLS['ai'], 'type': 'text/plain'}]},
    ]}
    dump(os.path.join(PUB, 'well-known-api-catalog.json'), linkset)

def build_robots():
    t = f"""# ─── Ranatec Agentic Web — additions for https://ranatec.com/robots.txt ───
# The live robots.txt (Yoast) already allows GPTBot, ClaudeBot, Claude-Web, Claude-User,
# Claude-SearchBot, anthropic-ai, PerplexityBot, Google-Extended, DeepseekBot, FacebookBot and
# a group of other AI crawlers. Add the user-agents below (not yet listed) and the discovery lines
# at the END of the file. Keep the existing "User-agent: *" rules unchanged.

User-agent: OAI-SearchBot
User-agent: ChatGPT-User
User-agent: Perplexity-User
User-agent: Applebot-Extended
User-agent: GoogleOther
User-agent: Amazonbot
User-agent: MistralAI-User
User-agent: Meta-ExternalAgent
User-agent: Diffbot
Allow: /
Allow: /agent/
Allow: /llms.txt
Allow: /llms-full.txt
Allow: /ai.txt
Allow: /openapi.json
Allow: /api-catalog.json
Disallow: /cart/
Disallow: /checkout/
Disallow: /my-account/
Disallow: /en-gb/basket/
Disallow: /en-ca/shopping-cart/

# Discovery (non-standard directives are ignored by crawlers that don't support them)
LLM-Readable: {URLS['agent']}
LLM-Text: {URLS['llms']}
AI-Policy: {URLS['ai']}
Sitemap: {URLS['sitemap']}
"""
    write(os.path.join(WEB, 'robots-addition.txt'), t)

MCP_TOOLS = [
    {'name': 'get_company', 'description': 'Company profile, contact details, certifications, locales, partners and careers.'},
    {'name': 'list_products', 'description': 'List products, filterable by category, domain, listing, model or text query.'},
    {'name': 'get_product', 'description': 'Full specifications for one product by id or model number (e.g. "RI 268").'},
    {'name': 'compare_products', 'description': 'Side-by-side specification comparison of 2–6 products.'},
    {'name': 'list_categories', 'description': 'Product categories with summaries and product ids.'},
    {'name': 'list_solutions', 'description': 'Product domains and solution areas (wireless test automation, shielded enclosures, custom equipment).'},
    {'name': 'list_news', 'description': 'News, product launches, technical articles and events; filter by type, year, product or text.'},
    {'name': 'get_news_item', 'description': 'Full text of one news item or article.'},
    {'name': 'search', 'description': 'Full-text search across products, news and FAQ.'},
    {'name': 'get_faq', 'description': 'Frequently asked questions with answers and sources.'},
    {'name': 'list_pages', 'description': 'Site pages with en-US, en-GB and en-CA URLs.'},
    {'name': 'request_quote', 'description': 'The only supported way for AI agents to request a product quote — creates a quote order like Add to RFQ + checkout; needs the checkout fields and explicit user consent (user_authorized_submission: true).'},
    {'name': 'submit_inquiry', 'description': 'The only supported way for AI agents to send a contact enquiry without products (technical question, custom solution, distributor, general) — requires explicit user consent. Callable via an MCP connector or a plain HTTP JSON-RPC tools/call.'},
]

if __name__ == '__main__':
    spec = build_openapi()
    build_agent_page()
    build_llms()
    build_llms_full()
    build_ai_txt()
    build_catalog(spec)
    build_robots()
    for f in ['agent-page.html', 'llms.txt', 'llms-full.txt', 'ai.txt', 'api-catalog.json']:
        shutil.copy(os.path.join(PUB, f), os.path.join(WEB, f))
    shutil.copy(os.path.join(ROOT, 'ranatec-api', 'openapi.json'), os.path.join(WEB, 'openapi.json'))
    os.makedirs(os.path.join(WEB, '.well-known'), exist_ok=True)
    shutil.copy(os.path.join(PUB, 'well-known-api-catalog.json'), os.path.join(WEB, '.well-known', 'api-catalog'))
    for f in sorted(os.listdir(PUB)):
        print(f'{os.path.getsize(os.path.join(PUB, f)):>9}  ranatec-api/public/{f}')
    print(f'{os.path.getsize(os.path.join(ROOT, "ranatec-api", "openapi.json")):>9}  ranatec-api/openapi.json')
