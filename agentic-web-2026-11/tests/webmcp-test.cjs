// Loads the /agent/ page from the test PHP server in Chrome with WebMCP switched on, serves the plugin's webmcp.js
// for the script tag, and checks that the 5 read-only tools register and answer from the same-origin API.
// Usage: node tests/webmcp-test.cjs <base-url> <path-to-webmcp.js>. Prints PASS/FAIL lines; exit 3 = no browser.
const fs = require('fs');
let chromium;
try { ({ chromium } = require('playwright')); } catch { console.log('SKIP playwright not installed'); process.exit(3); }
const [base, script] = process.argv.slice(2);
(async () => {
  const b = await chromium.launch({ args: ['--enable-features=WebMCPTesting'] });
  const p = await b.newPage();
  const errors = [];
  p.on('pageerror', (e) => errors.push(String(e)));
  await p.route('**/wp-content/plugins/ranatec-api/public/webmcp.js*', (r) => r.fulfill({ contentType: 'application/javascript', body: fs.readFileSync(script) }));
  await p.goto(base + '/agent/', { waitUntil: 'load' });
  await p.waitForFunction(async () => document.modelContext && (await document.modelContext.getTools()).length >= 5, null, { timeout: 5000 }).catch(() => {});
  const r = await p.evaluate(async () => {
    const mc = document.modelContext;
    if (!mc) return { unsupported: true };
    const tools = await mc.getTools();
    const T = (n) => tools.find((t) => t.name === n);
    const ex = async (n, a) => JSON.parse(JSON.parse(await mc.executeTool(T(n), JSON.stringify(a))).content[0].text);
    return {
      tools: tools.map((t) => ({ name: t.name, readOnly: !!(t.annotations && t.annotations.readOnlyHint), schema: JSON.parse(t.inputSchema) })),
      search: await ex('search_ranatec', { query: 'shield box', limit: 3 }),
      get: await ex('get_product', { id: 'RI 268' }),
      list: await ex('list_products', { category: 'butler-matrices' }),
      compare: await ex('compare_products', { ids: ['RF2037', 'RF2038', 'switch-box-rf2022b'] }),
      company: await ex('get_company_info', {}),
      missing: await ex('get_product', { id: 'no-such-product' }),
    };
  });
  await b.close();
  const check = (name, ok, info = '') => console.log(`${ok ? 'PASS' : 'FAIL'} ${name}${info ? ' (' + info + ')' : ''}`);
  if (r.unsupported) { console.log('SKIP this Chrome has no WebMCP (document.modelContext)'); process.exit(3); }
  const names = r.tools.map((t) => t.name).sort().join(',');
  check('5 WebMCP tools registered on the /agent/ page', names === 'compare_products,get_company_info,get_product,list_products,search_ranatec', names);
  check('every tool is read-only with an object input schema', r.tools.every((t) => t.readOnly && t.schema.type === 'object'));
  check('search_ranatec ranks name matches first', r.search.products?.length === 3 && /shield box/i.test(r.search.products[0].name), r.search.products?.map((x) => x.name).join(' | '));
  check('get_product resolves a model number', r.get.model_number === 'RI 268' && r.get.specifications?.length > 0);
  check('list_products filters by category', r.list.count === 2);
  check('compare_products returns one row per parameter', r.compare.products?.length === 3 && r.compare.specifications?.length > 3);
  check('get_company_info', r.company.legal_name === 'Ranatec AB');
  check('an unknown product returns the API\'s explanation, not a crash', /no-such-product/.test(r.missing.error || ''));
  check('no page errors', errors.length === 0, errors.join(' | '));
})().catch((e) => { console.log('FAIL webmcp browser test: ' + e); process.exit(1); });
