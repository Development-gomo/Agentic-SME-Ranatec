#!/usr/bin/env bash
# One-command verification of the whole package (run from the repo root):
#   bash tests/run-all.sh
# Needs: php (CLI), node >= 20, npm. Installs MCP dependencies with `npm ci` if missing.
set -euo pipefail
cd "$(dirname "$0")/.."
PHP_PORT=${PHP_PORT:-8095}; MCP_PORT=${MCP_PORT:-3095}
TMP=$(mktemp -d); pass=0; fail=0
ok()  { echo "PASS $*"; pass=$((pass+1)); }
bad() { echo "FAIL $*"; fail=$((fail+1)); }
cleanup() { [[ -n "${PHP_PID:-}" ]] && kill "$PHP_PID" 2>/dev/null || true; [[ -n "${MCP_PID:-}" ]] && kill "$MCP_PID" 2>/dev/null || true; [[ -n "${GUARD_PID:-}" ]] && kill "$GUARD_PID" 2>/dev/null || true; [[ -n "${PL_PID:-}" ]] && kill "$PL_PID" 2>/dev/null || true; rm -rf "$TMP"; rm -f "$(php -r 'echo sys_get_temp_dir();')"/rl-ranatec_api_rl_* "$(php -r 'echo sys_get_temp_dir();')"/ranatec-mail.txt; }
trap cleanup EXIT
# Match a pattern against ALL of stdin. A plain "cmd | grep -q" lets grep exit at the first match; under pipefail the
# writer then dies of SIGPIPE and the check fails at random.
has() { local o; o=$(cat); grep -q "$@" <<<"$o"; }
cleanup_rl() { rm -f "$(php -r 'echo sys_get_temp_dir();')"/rl-ranatec_api_rl_*; }

echo "== PHP"
for f in ranatec-api/ranatec-api.php ranatec-api/includes/*.php tests/*.php; do php -l "$f" >/dev/null && ok "lint $f" || bad "lint $f"; done
for f in ranatec-api/data/*.json ranatec-api/openapi.json ranatec-api/public/*.json; do python3 -c "import json,sys;json.load(open(sys.argv[1]))" "$f" && ok "json $f" || bad "json $f"; done

# --- contact-form enquiry (contact.json / submit_inquiry) → Advanced CF7 DB only
BODY='{"agent_context":{"user_authorized_submission":true,"user_request_summary":"QA: user asked whether the RI 181 can get a USB-C feedthrough"},"person":{"name":"Jörg Ångström","email":"qa@gomogroup.com","phone":"+46 31 000 00 00"},"company":{"name":"Prüf GmbH"},"inquiry":{"type":"custom_solution","message":"Kann die RI 181 mit USB-C-Durchführung geliefert werden?"}}'
QBODY='{"agent_context":{"user_authorized_submission":true,"user_request_summary":"QA: user asked for a quote for two configured RI 181 shield boxes"},"customer":{"first_name":"Jörg","last_name":"Ångström","email":"qa@gomogroup.com","phone":"+46 31 000 00 00","company":"Prüf GmbH","country":"Germany","address_1":"Teststraße 1","city":"München","postcode":"80331","state":"Bavaria (Bayern)"},"products":[{"id":"shield-box-ri-181","quantity":2,"configuration":[{"id":"feedthrough-filter-ri-4182","quantity":2}]}],"note":"Lieferung Q1"}'
cleanup_rl; echo "$BODY" | php tests/wp-stub-harness.php contact '' POST | has '"status": "received"' && ok "contact enquiry (mbstring on)" || bad "contact enquiry (mbstring on)"
if ! php -n -m | has -i mbstring; then
  cleanup_rl; echo "$BODY" | php -n tests/wp-stub-harness.php contact '' POST | has '"status": "received"' && ok "contact enquiry (mbstring OFF)" || bad "contact enquiry (mbstring OFF)"
fi
cleanup_rl; echo "${BODY/true/false}" | php tests/wp-stub-harness.php contact '' POST | has 'STATUS 403' && ok "contact without consent → 403" || bad "contact without consent → 403"
TMPD=$(php -r 'echo sys_get_temp_dir();')
cleanup_rl; rm -f "$TMPD/ranatec-acf7db.jsonl" "$TMPD/ranatec-wc-orders.jsonl"; out=$(echo "$BODY" | TEST_ACF7DB=1 php tests/wp-stub-harness.php contact '' POST)
if echo "$out" | has '"entry_id": 101' && grep -q '"name":"your-name","value":"J' "$TMPD/ranatec-acf7db.jsonl" && grep -q '"name":"tel-882"' "$TMPD/ranatec-acf7db.jsonl" && grep -q '"name":"text-863","value":"Pr' "$TMPD/ranatec-acf7db.jsonl" && [[ ! -e "$TMPD/ranatec-wc-orders.jsonl" ]]; then ok "contact enquiry stored in Advanced CF7 DB (form 50 fields), no order created"; else echo "$out"; bad "Advanced CF7 DB storage"; fi
for f in name email phone; do
  case $f in name) b="${BODY/\"name\":\"Jörg Ångström\",/}";; email) b="${BODY/\"email\":\"qa@gomogroup.com\",/}";; phone) b="${BODY/,\"phone\":\"+46 31 000 00 00\"/}";; esac
  cleanup_rl; echo "$b" | php tests/wp-stub-harness.php contact '' POST | has "person.$f" && ok "contact: $f required" || bad "contact: $f required"
done
cleanup_rl; echo "${BODY/\"name\":\"Prüf GmbH\"/\"name\":\"\"}" | php tests/wp-stub-harness.php contact '' POST | has 'company.name' && ok "contact: company required" || bad "contact: company required"
cleanup_rl; echo "${BODY/\"type\":\"custom_solution\"/\"type\":\"quote_request\"}" | php tests/wp-stub-harness.php contact '' POST | has 'use_request_quote' && ok "contact refuses quote_request → use_request_quote" || bad "contact refuses quote_request"
b=$(python3 -c "import json,sys; d=json.loads(sys.argv[1]); d['inquiry'].update(type='general', products=[{'id': 'ri-268'}]); print(json.dumps(d))" "$BODY")
cleanup_rl; echo "$b" | php tests/wp-stub-harness.php contact '' POST | has 'use_request_quote' && ok "contact refuses products → use_request_quote" || bad "contact refuses products"
rm -f "$TMPD/ranatec-acf7db.jsonl"
cleanup_rl; echo "${BODY/\"user_authorized_submission\":true/\"user_authorized_submission\":true,\"dry_run\":true}" | php tests/wp-stub-harness.php contact '' POST | has '"submitted": false' && ok "contact dry run validates without submitting" || bad "contact dry run"
cleanup_rl; echo "${BODY/qa@gomogroup.com/john.doe@example.com}" | php tests/wp-stub-harness.php contact '' POST | has 'example/test addresses' && ok "example.com address rejected" || bad "example.com address"
cleanup_rl; echo "${BODY/QA: user asked whether the RI 181 can get a USB-C feedthrough/}" | php tests/wp-stub-harness.php contact '' POST | has 'user_request_summary' && ok "missing user request summary rejected" || bad "user request summary"
cleanup_rl; echo "${BODY/Jörg Ångström/<full name>}" | php tests/wp-stub-harness.php contact '' POST | has 'placeholders' && ok "unfilled <placeholder> rejected" || bad "placeholder"
# dry_run must be a JSON boolean: "true"/1/"yes" are rejected, never treated as a real submission (contact and quote)
for v in '"true"' '1' '"yes"' 'null'; do
  cleanup_rl; rm -f "$TMPD/ranatec-acf7db.jsonl" "$TMPD/ranatec-wc-orders.jsonl"
  out=$(echo "${BODY/\"user_authorized_submission\":true/\"user_authorized_submission\":true,\"dry_run\":$v}" | TEST_ACF7DB=1 php tests/wp-stub-harness.php contact '' POST 2>&1)
  qout=$(echo "${QBODY/\"user_authorized_submission\":true/\"user_authorized_submission\":true,\"dry_run\":$v}" | TEST_ACF7DB=1 php tests/wp-stub-harness.php quote '' POST 2>&1)
  grep -q 'STATUS 400' <<<"$out" && grep -q 'agent_context.dry_run' <<<"$out" && grep -q 'STATUS 400' <<<"$qout" && grep -q 'agent_context.dry_run' <<<"$qout" && [[ ! -s "$TMPD/ranatec-acf7db.jsonl" && ! -s "$TMPD/ranatec-wc-orders.jsonl" ]] && ok "dry_run $v → 400, nothing stored (contact + quote)" || bad "dry_run $v not rejected"
done
cleanup_rl; out=$(echo "${BODY/\"name\":\"Prüf GmbH\"/\"name\":\"Prüf GmbH\",\"website\":[\"https://a.example\"]}" | php tests/wp-stub-harness.php contact '' POST 2>&1)
grep -q 'STATUS 400' <<<"$out" && grep -q 'company.website' <<<"$out" && ! grep -qiE 'warning|fatal|deprecated' <<<"$out" && ok "contact: array company.website → 400 without PHP error" || bad "contact: array website ($out)"

# --- product quote (quote.json / request_quote) → WooCommerce order only, checkout fields required
cleanup_rl; rm -f "$TMPD/ranatec-wc-orders.jsonl" "$TMPD/ranatec-acf7db.jsonl"; out=$(echo "$QBODY" | TEST_ACF7DB=1 php tests/wp-stub-harness.php quote '' POST)
if echo "$out" | has '"flow": "product_quote"' && echo "$out" | has '"order_number": "4242"' && [[ ! -e "$TMPD/ranatec-acf7db.jsonl" ]] \
   && python3 -c "
import json,sys; o=json.loads(open(sys.argv[1]).readline())
assert o['args']['status']=='ywraq-new' and o['payment_method']=='yith-request-a-quote', o
assert (o['billing_first_name'],o['billing_last_name'],o['billing_company'],o['billing_country'],o['billing_state'],o['billing_postcode'],o['billing_city'],o['billing_address_1'],o['billing_phone'],o['billing_email'])==('Jörg','Ångström','Prüf GmbH','DE','BY','80331','München','Teststraße 1','+46 31 000 00 00','qa@gomogroup.com'), o
assert o['shipping_country']=='DE' and o['items']==[['shield-box-ri-181',2],['feedthrough-filter-ri-4182',4]] and o['item_meta']['2']['Addon/Accessory for']=='Product shield-box-ri-181', o
assert o['meta']['_wc_other/custom-checkout/additional-note']=='Lieferung Q1' and o['customer_note']=='Lieferung Q1' and 'request_quote' in o['notes'][0], o
" "$TMPD/ranatec-wc-orders.jsonl"; then ok "quote → WooCommerce order with checkout fields (billing+shipping, state code, Additional Note, RFQ payment, configured option line), not a contact lead"; else echo "$out"; cat "$TMPD/ranatec-wc-orders.jsonl" 2>/dev/null; bad "quote order"; fi
for f in first_name last_name email phone company country address_1 city postcode; do
  b=$(python3 -c "import json,sys; d=json.loads(sys.argv[1]); del d['customer'][sys.argv[2]]; print(json.dumps(d))" "$QBODY" "$f")
  cleanup_rl; echo "$b" | php tests/wp-stub-harness.php quote '' POST | has "customer.$f" && ok "quote: $f required" || bad "quote: $f required"
done
b=$(python3 -c "import json,sys; d=json.loads(sys.argv[1]); d['customer'].update(country='US', state='', postcode='94105'); print(json.dumps(d))" "$QBODY")
cleanup_rl; echo "$b" | php tests/wp-stub-harness.php quote '' POST | has 'customer.state' && ok "quote: state required for US" || bad "quote: state required for US"
b=$(python3 -c "import json,sys; d=json.loads(sys.argv[1]); d['customer'].update(country='SE', postcode='41250'); d['customer'].pop('state'); print(json.dumps(d))" "$QBODY")
cleanup_rl; echo "$b" | php tests/wp-stub-harness.php quote '' POST | has '"status": "received"' && ok "quote: no state needed for Sweden" || bad "quote: Sweden without state"
b=$(python3 -c "import json,sys; d=json.loads(sys.argv[1]); d['customer'].update(country='Narnia'); print(json.dumps(d))" "$QBODY")
cleanup_rl; echo "$b" | php tests/wp-stub-harness.php quote '' POST | has 'customer.country' && ok "quote: unknown country rejected" || bad "quote: unknown country"
b=$(python3 -c "import json,sys; d=json.loads(sys.argv[1]); d['products']=[]; print(json.dumps(d))" "$QBODY")
cleanup_rl; echo "$b" | php tests/wp-stub-harness.php quote '' POST | has '"products"' && ok "quote: at least one product required" || bad "quote: products required"
cleanup_rl; rm -f "$TMPD/ranatec-wc-orders.jsonl"; echo "${QBODY/\"user_authorized_submission\":true/\"user_authorized_submission\":true,\"dry_run\":true}" | php tests/wp-stub-harness.php quote '' POST | has '"submitted": false' && [[ ! -e "$TMPD/ranatec-wc-orders.jsonl" ]] && ok "quote dry run validates without creating an order" || bad "quote dry run"
cleanup_rl; echo "$QBODY" | TEST_WC_MISSING=feedthrough-filter-ri-4182 php tests/wp-stub-harness.php quote '' POST | has 'product_not_in_shop' && ok "quote: product missing in shop → 409, nothing half-built" || bad "quote: missing product"
cleanup_rl; rm -f "$TMPD/ranatec-acf7db.jsonl"; out=$(echo "$QBODY" | TEST_WC=0 TEST_ACF7DB=1 php tests/wp-stub-harness.php quote '' POST); echo "$out" | has 'quotes_unavailable' && [[ ! -e "$TMPD/ranatec-acf7db.jsonl" ]] && ok "quote without WooCommerce → 503, never stored as a contact lead" || { echo "$out"; bad "quote without WooCommerce"; }
cleanup_rl; echo "${QBODY/true/false}" | php tests/wp-stub-harness.php quote '' POST | has 'STATUS 403' && ok "quote without consent → 403" || bad "quote without consent"
rm -f "$TMPD/ranatec-wc-orders.jsonl"

# --- malformed submissions: must give a clean 400, with no PHP warning/notice (a list where text is expected crashed WordPress on PHP 8 before 1.3.0)
for case in 'contact|{"agent_context":{"user_authorized_submission":true},"person":{"name":"A","email":["a@b.c"],"phone":"+46 1 23"},"company":{"name":"X"},"inquiry":{"message":"hello there friend"}}' \
            'contact|{"agent_context":{"user_authorized_submission":true},"person":{"name":"A","email":"a@gomogroup.com","phone":"+46 1 23"},"company":{"name":"X"},"inquiry":{"type":["x"],"message":"hello there friend"}}' \
            'quote|{"agent_context":{"user_authorized_submission":true,"dry_run":true},"customer":{"first_name":"A","last_name":"B","email":{"x":1},"phone":"+46 1 23","company":"X","country":["SE"],"address_1":"a","city":"b","postcode":"1"},"products":[{"id":["x"],"configuration":[{"id":{"a":1}}]}]}'; do
  ep=${case%%|*}; body=${case#*|}
  out=$(cleanup_rl; echo "$body" | php -d error_reporting=-1 -d display_errors=stdout tests/wp-stub-harness.php "$ep" '' POST 2>&1)
  if grep -qE 'Warning|Notice|Deprecated|Fatal' <<<"$out" || ! grep -q 'STATUS 400' <<<"$out"; then echo "$out" | grep -E 'Warning|Notice|Fatal|STATUS' | head -3; bad "malformed $ep body → clean 400"; else ok "malformed $ep body (list/object instead of text) → clean 400, no PHP warning"; fi
done
q=$(php -d error_reporting=-1 -d display_errors=stdout tests/wp-stub-harness.php products '' GET 'model[]=x&q[]=y' </dev/null 2>&1); grep -qE 'Warning|Notice' <<<"$q" && bad "array query params" || ok "array query parameters (?model[]=…) ignored without PHP warning"

LOCK=lock-key-0123456789abcdefghijkl
cleanup_rl; echo "$BODY" | WP_OPTION_ranatec_api_mcp_key=$LOCK php tests/wp-stub-harness.php contact '' POST | has 'use_mcp_submit_inquiry' && ok "MCP-only lock: direct contact call without key → 403 use_mcp_submit_inquiry" || bad "MCP-only lock (no key)"
cleanup_rl; echo "$QBODY" | WP_OPTION_ranatec_api_mcp_key=$LOCK php tests/wp-stub-harness.php quote '' POST | has 'use_mcp_request_quote' && ok "MCP-only lock: direct quote call without key → 403 use_mcp_request_quote" || bad "MCP-only lock quote (no key)"
cleanup_rl; echo "$BODY" | WP_OPTION_ranatec_api_mcp_key=$LOCK HTTP_X_RANATEC_MCP_KEY=$LOCK php tests/wp-stub-harness.php contact '' POST | has '"status": "received"' && ok "MCP-only lock: call with MCP key → received" || bad "MCP-only lock (with key)"

echo "== ARD (Agentic Resource Discovery)"
if python3 -W error::ResourceWarning -m unittest tests/test_ard.py >"$TMP/ard-unit.log" 2>&1; then ok "ARD core + generated manifest: $(grep -o 'Ran [0-9]* tests' "$TMP/ard-unit.log") (official JSON Schema, conformance tool, any domain, disabled, missing metadata, no secrets)"; else cat "$TMP/ard-unit.log"; bad "ARD unit tests"; fi
out=$(php tests/wp-stub-harness.php ard '' GET </dev/null)
echo "$out" | has 'STATUS 200' && echo "$out" | sed '$d' | python3 -c "import json,sys; d=json.load(sys.stdin); assert d['entries']" && ok "/.well-known/ard.json served (200, JSON with entries)" || { echo "$out" | tail -3; bad "ARD route"; }
WP_OPTION_ranatec_api_ard_enabled=0 php tests/wp-stub-harness.php ard '' GET </dev/null | has 'STATUS 404' && ok "ARD switched off → /.well-known/ard.json 404" || bad "ARD off → 404"
headlinks() { php -r 'require "tests/wp-stubs.php"; require "ranatec-api/ranatec-api.php"; Ranatec_Agent_API::head_links();'; }
WP_OPTION_home=https://staging.example.org headlinks | has '<link rel="ard" type="application/json" href="https://staging.example.org/.well-known/ard.json" />' && ok "<link rel=\"ard\"> in page head, on the site's own domain" || bad "ARD head link"
WP_OPTION_ranatec_api_ard_enabled=0 headlinks | has 'rel="ard"' && bad "ARD off → head link still present" || ok "ARD off → no <link rel=\"ard\">"
robots() { php -r 'require "tests/wp-stubs.php"; require "ranatec-api/ranatec-api.php"; echo Ranatec_Agent_ARD::robots_txt("User-agent: *\nDisallow: /wp-admin/\n", "1");'; }
WP_OPTION_home=https://example.org robots | has -x 'Agentmap: https://example.org/.well-known/ard.json' && ok "virtual robots.txt gets Agentmap:" || bad "robots Agentmap"
WP_OPTION_ranatec_api_ard_enabled=0 robots | has 'Agentmap' && bad "ARD off → robots still has Agentmap" || ok "ARD off → no Agentmap in robots.txt"
php -r 'require "tests/wp-stubs.php"; require "ranatec-api/ranatec-api.php"; echo Ranatec_Agent_ARD::robots_txt("Agentmap: https://x.example/a.json\n", "1");' | grep -c Agentmap | has -x 1 && ok "existing Agentmap line not duplicated" || bad "Agentmap duplicated"
php tests/wp-stub-harness.php index '' GET </dev/null | has '"ard_manifest": "https://ranatec.com/.well-known/ard.json"' && ok "index.json discovery lists ard_manifest" || bad "index.json ard_manifest"
WP_OPTION_ranatec_api_ard_enabled=0 php tests/wp-stub-harness.php index '' GET </dev/null | has 'ard_manifest' && bad "ARD off → index.json still lists it" || ok "ARD off → index.json has no ard_manifest"
page=$(php tests/wp-stub-harness.php agent-page '' GET </dev/null); grep -q 'rel="ard"' <<<"$page" && ok "/agent/ page links the manifest" || bad "/agent/ rel=ard"
grep -q '<li data-ard><a href="https://ranatec.com/.well-known/ard.json">ard.json</a>' <<<"$page" && ok "/agent/ LLM Discovery list has a visible ard.json link" || bad "/agent/ visible ARD link"
grep -q '<tr data-ard><th>ARD manifest</th><td><a href="https://ranatec.com/.well-known/ard.json">' <<<"$page" && ok "/agent/ Metadata table has an ARD manifest row" || bad "/agent/ Metadata ard.json row"
page=$(WP_OPTION_home=https://staging.example.org php tests/wp-stub-harness.php agent-page '' GET </dev/null); grep -q '<link rel="ard" type="application/json" href="https://staging.example.org/.well-known/ard.json">' <<<"$page" && grep -q '<li data-ard><a href="https://staging.example.org/.well-known/ard.json">' <<<"$page" && grep -q '<tr data-ard><th>ARD manifest</th><td><a href="https://staging.example.org/.well-known/ard.json">https://staging.example.org/.well-known/ard.json</a>' <<<"$page" && ok "/agent/ page links this site's own manifest (staging domain)" || bad "/agent/ rel=ard on staging"
page=$(WP_OPTION_ranatec_api_ard_enabled=0 php tests/wp-stub-harness.php agent-page '' GET </dev/null); grep -qE 'rel="ard"|data-ard|ard.json</a>' <<<"$page" && bad "ARD off → /agent/ still links it" || ok "ARD off → /agent/ page link removed"

# Daily sync against WooCommerce's real WC_DateTime (UTC dates, protected content excluded, lock released, no temp files)
ST=$(mktemp -d); cp -r ranatec-api "$ST/"; sync_out=$(php tests/sync-test.php "$ST/ranatec-api" </dev/null 2>&1)
while IFS= read -r line; do case $line in PASS\ *) ok "sync: ${line#PASS }";; FAIL\ *) bad "sync: ${line#FAIL }";; *) bad "sync test output: $line";; esac; done <<<"$sync_out"
[[ $(grep -c '^PASS' <<<"$sync_out") -eq 34 ]] || bad "sync test: expected 34 checks"
rm -rf "$ST"
# --- content withdrawal: rebuilding the package uses the site's live data, never stale package data
# A stand-in site publishes the package data minus one product (withdrawn: referenced by a solution and news) and one post.
PL=$(mktemp -d); mkdir -p "$PL/pkg/tools" "$PL/pkg/ranatec-api" "$PL/site/agent/v1"; cp tools/pull_live_data.py "$PL/pkg/tools/"; cp -r ranatec-api/data "$PL/pkg/ranatec-api/"
python3 - "$PL" <<'PY'
import json, sys
pl = sys.argv[1]; d = lambda n: json.load(open(f'{pl}/pkg/ranatec-api/data/{n}.json', encoding='utf-8'))
gone_p, gone_n = 'customized-rf-shield-box', 'new-management-at-ranatec'
p = d('products'); p['products'] = [x for x in p['products'] if x['id'] != gone_p]; p['meta']['count'] = len(p['products'])
n = d('news'); n['news'] = [x for x in n['news'] if x['id'] != gone_n]; n['meta']['count'] = len(n['news'])
n['news'][0]['related_product_ids'] = n['news'][0]['related_product_ids'] + [gone_p]   # still referenced in news
c = d('categories')
for x in c['categories']:
    x['product_ids'] = [i for i in x['product_ids'] if i != gone_p]; x['additional_listing_ids'] = [i for i in x.get('additional_listing_ids', []) if i != gone_p]
for name, obj in [('products', p), ('news', n), ('categories', c)]:
    json.dump(obj, open(f'{pl}/site/agent/v1/{name}.json', 'w'))
assert gone_p in json.dumps(d('solutions')), 'test needs a solution that references the withdrawn product'
PY
PL_PORT=$((MCP_PORT + 2)); ( cd "$PL/site" && exec python3 -m http.server "$PL_PORT" --bind 127.0.0.1 >/dev/null 2>&1 ) & PL_PID=$!
for i in $(seq 1 40); do curl -s -o /dev/null "http://127.0.0.1:$PL_PORT/agent/v1/news.json" && break; sleep 0.1; done
PLB="http://127.0.0.1:$PL_PORT/agent/v1"
rc=0; out=$(python3 "$PL/pkg/tools/pull_live_data.py" --base "$PLB" --check 2>&1) || rc=$?
[[ $rc -eq 1 ]] && has 'no longer public: customized-rf-shield-box' <<<"$out" && has 'no longer public: new-management-at-ranatec' <<<"$out" && ok "pull_live_data --check: fails and names withdrawn items (release blocked)" || bad "pull_live_data --check (rc=$rc) $out"
rc=0; out=$(python3 "$PL/pkg/tools/pull_live_data.py" --base "$PLB" 2>&1) || rc=$?
python3 - "$PL" <<'PY' && [[ $rc -eq 0 ]] && ok "pull_live_data: package data = live data, withdrawn product no longer referenced anywhere" || bad "pull_live_data (rc=$rc) $out"
import json, sys
pl = sys.argv[1]; txt = ''.join(open(f'{pl}/pkg/ranatec-api/data/{n}.json', encoding='utf-8').read() for n in ['products', 'news', 'categories', 'solutions'])
assert 'customized-rf-shield-box' not in txt and '"new-management-at-ranatec"' not in txt
PY
rc=0; out=$(python3 "$PL/pkg/tools/pull_live_data.py" --base "$PLB" --check 2>&1) || rc=$?; [[ $rc -eq 0 ]] && ok "pull_live_data --check passes after the pull" || bad "pull_live_data --check after pull: $out"
before=$(md5sum "$PL/pkg/ranatec-api/data/products.json")
rc=0; out=$(python3 "$PL/pkg/tools/pull_live_data.py" --base "http://127.0.0.1:$PL_PORT/nothing-here" 2>&1) || rc=$?
[[ $rc -eq 2 && $(md5sum "$PL/pkg/ranatec-api/data/products.json") == "$before" ]] && ok "pull_live_data: unreachable/invalid site → error, nothing written" || bad "pull_live_data failure handling (rc=$rc)"
kill $PL_PID 2>/dev/null || true; wait $PL_PID 2>/dev/null || true; rm -rf "$PL"
# Rebuild with an empty catalogue while a solution and news still reference products: no crash, nothing withdrawn named
EB=$(mktemp -d); tar --exclude=./ranatec-mcp/node_modules --exclude=./release -cf - . | tar -xf - -C "$EB"
python3 - "$EB" <<'PY'
import json, sys
d = sys.argv[1] + '/ranatec-api/data/'
p = json.load(open(d + 'products.json')); p['products'] = []; p['meta']['count'] = 0; json.dump(p, open(d + 'products.json', 'w'))
c = json.load(open(d + 'categories.json'))
for x in c['categories']: x['product_ids'] = []; x['additional_listing_ids'] = []; x['product_count'] = 0
json.dump(c, open(d + 'categories.json', 'w'))
PY
rc=0; out=$( cd "$EB" && python3 tools/build_static.py 2>&1 ) || rc=$?
[[ $rc -eq 0 ]] && has 'WARNING: left out a reference' <<<"$out" && ! grep -q 'customized-rf-shield-box' "$EB/ranatec-api/public/llms.txt" "$EB/ranatec-api/public/llms-full.txt" "$EB/ranatec-api/public/agent-page.html" && ok "build_static: empty catalogue + dangling references → builds, warns, names no withdrawn product" || bad "build_static with empty catalogue (rc=$rc): $(tail -3 <<<"$out")"
rm -rf "$EB"

grep -qF -e "->date('c')" ranatec-api/includes/class-sync.php && bad "class-sync.php formats WooCommerce dates with ->date('c') (local time labelled UTC)" || ok "class-sync.php has no WC_DateTime::date('c')"

# --- manually uploaded copies in the web root: detected, and flagged when older than the plugin
WR=$(mktemp -d); mkdir -p "$WR/.well-known"; cp ranatec-api/public/llms.txt "$WR/llms.txt"; echo '{"entries":[]}' > "$WR/.well-known/ard.json"
copies=$(php -r 'define("ABSPATH", $argv[1] . "/"); require "tests/wp-stubs.php"; require "ranatec-api/ranatec-api.php"; echo json_encode(Ranatec_Agent_ARD::static_copies());' "$WR")
[[ $copies == '{".well-known\/ard.json":"outdated","llms.txt":"current"}' ]] && ok "web-root copies detected (outdated ard.json, current llms.txt)" || bad "web-root copy detection ($copies)"
notice=$(php -r 'define("ABSPATH", $argv[1] . "/"); require "tests/wp-stubs.php"; require "ranatec-api/ranatec-api.php"; Ranatec_Agent_ARD::stale_copy_notice();' "$WR")
grep -q 'notice-warning' <<<"$notice" && grep -q '.well-known/ard.json' <<<"$notice" && ! grep -q 'llms.txt' <<<"$notice" && ok "admin notice names only the outdated copy" || bad "stale-copy admin notice"
notice=$(TEST_ADMIN=0 php -r 'define("ABSPATH", $argv[1] . "/"); require "tests/wp-stubs.php"; require "ranatec-api/ranatec-api.php"; Ranatec_Agent_ARD::stale_copy_notice();' "$WR")
[[ -z $notice ]] && ok "stale-copy notice hidden from non-administrators" || bad "notice shown to non-admins"
rm -rf "$WR"

echo "== Versions"
python3 tools/check_versions.py >"$TMP/versions.log" 2>&1 && ok "all component versions match VERSION ($(tr -d '[:space:]' < VERSION))" || { cat "$TMP/versions.log"; bad "version mismatch"; }

echo "== HTTP (PHP built-in server)"
php -S 127.0.0.1:$PHP_PORT tests/php-router.php >"$TMP/php.log" 2>&1 & PHP_PID=$!
ready=0; for i in $(seq 1 80); do curl -s -o /dev/null "http://127.0.0.1:$PHP_PORT/agent/v1/index.json" && { ready=1; break; }; sleep 0.25; done
[[ $ready == 1 ]] && ok "PHP server ready on :$PHP_PORT" || { bad "PHP server did not start on :$PHP_PORT (port in use?)"; cat "$TMP/php.log"; }
wm_rc=0; wm_out=$(node tests/webmcp-test.cjs "http://127.0.0.1:$PHP_PORT" ranatec-api/public/webmcp.js 2>&1) || wm_rc=$?
if [[ $wm_rc -eq 3 ]]; then echo "SKIP WebMCP browser test: $wm_out"; else
  while IFS= read -r line; do case $line in PASS\ *) ok "WebMCP: ${line#PASS }";; FAIL\ *) bad "WebMCP: ${line#FAIL }";; esac; done <<<"$wm_out"
  [[ $(grep -c '^PASS' <<<"$wm_out") -eq 9 ]] || bad "WebMCP browser test: expected 9 checks ($wm_out)"
fi
for p in .well-known/ard.json agent/ agent/v1/index.json agent/v1/products.json agent/v1/products/ri-268.json agent/v1/categories/butler-matrices.json agent/v1/news.json agent/v1/faq.json openapi.json llms.txt llms-full.txt ai.txt api-catalog.json .well-known/api-catalog; do
  code=$(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:$PHP_PORT/$p"); [[ $code == 200 ]] && ok "GET /$p" || bad "GET /$p ($code)"
done

for b in agent/v1 agent/v1/; do loc=$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "http://127.0.0.1:$PHP_PORT/$b"); [[ $loc == "301 https://ranatec.com/agent/v1/index.json" ]] && ok "API base /$b → 301 index.json" || bad "API base /$b ($loc)"; done
hdr=$(curl -s -D - -o "$TMP/ard.json" "http://127.0.0.1:$PHP_PORT/.well-known/ard.json")
echo "$hdr" | has -i '^content-type: application/json' && echo "$hdr" | has -i '^access-control-allow-origin: \*' && ok "ARD over HTTP: application/json + CORS" || { echo "$hdr"; bad "ARD headers"; }
python3 tools/ard.py validate "$TMP/ard.json" >"$TMP/ard-validate.log" 2>&1 && grep -q 'VALID: 2 entries, 0 errors, 0 warnings' "$TMP/ard-validate.log" && ok "served manifest: official conformance PASS, 0 errors, 0 warnings" || { cat "$TMP/ard-validate.log"; bad "served ARD manifest validation"; }
# Every advertised url must resolve on the site that serves the manifest (production host swapped for the test server).
sed "s#https://ranatec.com#http://127.0.0.1:$PHP_PORT#g" "$TMP/ard.json" > "$TMP/ard-local.json"
python3 tools/ard.py validate "$TMP/ard-local.json" --check-urls >"$TMP/ard-urls.log" 2>&1 && ok "every advertised ARD url resolves (HTTP 200)" || { cat "$TMP/ard-urls.log"; bad "ARD urls"; }

echo "== Counts, ARD references, Product schema, alt text, static files"
counts=$(php -r 'require "tests/wp-stubs.php"; require "ranatec-api/ranatec-api.php"; $b=Ranatec_Agent_API::count_breakdown(); echo $b["summary"], "\n"; foreach (["llms.txt","llms-full.txt","ai.txt","agent-page"] as $e) { echo $e, "=", substr_count(Ranatec_Agent_API::render_static($e), $b["summary"]), "\n"; }' </dev/null)
sum=$(head -1 <<<"$counts")
[[ $sum == "49 catalogue products in 11 categories, plus 20 accessories and options; 82 shop listings in total, including 13 additional listings of catalogue products" ]] && ok "count wording computed from the API data ($sum)" || bad "count wording: $sum"
for e in llms.txt llms-full.txt ai.txt agent-page; do n=$(grep "^$e=" <<<"$counts" | cut -d= -f2); [[ ${n:-0} -ge 1 ]] && ok "$e carries the live count wording ($n×)" || bad "$e count wording missing"; done
idx=$(php tests/wp-stub-harness.php index '' GET </dev/null)
python3 -c "import json,sys; d=json.loads(sys.stdin.read().split('\n',1)[1] if sys.stdin else ''); c=d['counts']; assert c['summary']==sys.argv[1] and c['products']==82 and c['catalogue_products']==49 and c['product_categories']==11 and c['accessories_and_options']==20" "$sum" <<<"$idx" 2>/dev/null \
  || python3 -c "import json,sys,re; t=sys.stdin.read(); d=json.loads(t[t.index('{'):t.rindex('}')+1]); c=d['counts']; assert c['summary']==sys.argv[1] and c['products']==82 and c['catalogue_products']==49 and c['product_categories']==11 and c['accessories_and_options']==20" "$sum" <<<"$idx" \
  && ok "index.json counts: same numbers and sentence as the text files" || bad "index.json counts"
# render_static swaps in the live numbers when the data changes after the build
fake=$(php -r 'require "tests/wp-stubs.php"; require "ranatec-api/ranatec-api.php"; echo preg_replace(Ranatec_Agent_API::COUNT_RE, Ranatec_Agent_API::count_sentence(["catalogue_products"=>50,"product_categories"=>11,"accessories_and_options"=>21,"product_listings"=>84,"additional_listings"=>13]), "x 49 catalogue products in 11 categories, plus 20 accessories and options; 82 shop listings in total, including 13 additional listings of catalogue products y");' </dev/null)
[[ $fake == "x 50 catalogue products in 11 categories, plus 21 accessories and options; 84 shop listings in total, including 13 additional listings of catalogue products y" ]] && ok "served files take the numbers from the live data, not the build" || bad "count substitution: $fake"
grep -q '^- \[ARD manifest\](https://ranatec.com/.well-known/ard.json): lists the MCP server and Agent API$' ranatec-api/public/llms.txt && grep -q '^- \[ARD manifest\](https://ranatec.com/.well-known/ard.json): lists the MCP server and Agent API$' ranatec-api/public/llms-full.txt && ok "llms.txt and llms-full.txt list the ARD manifest" || bad "ARD line in llms files"
sed -n '/^\[Canonical-Sources\]/,/^\[/p' ranatec-api/public/ai.txt | grep -q '^ARD-Manifest: https://ranatec.com/.well-known/ard.json$' && ok "ai.txt [Canonical-Sources] has ARD-Manifest" || bad "ai.txt ARD-Manifest"
python3 -c "
import json; w=json.load(open('ranatec-api/public/well-known-api-catalog.json')); a=json.load(open('ranatec-api/public/api-catalog.json'))
root=[l for l in w['linkset'] if l['anchor']=='https://ranatec.com/'][0]
assert {'href':'https://ranatec.com/.well-known/ard.json','type':'application/json'} in root['describedby']
assert a['X-discovery']['ard_manifest']=='https://ranatec.com/.well-known/ard.json' and any(x['baseURL']=='https://ranatec.com/.well-known/ard.json' for x in a['apis'])" && ok "API catalogs reference the ARD manifest (describedby on https://ranatec.com/, APIs.json entry)" || bad "API catalog ARD links"
for f in llms.txt llms-full.txt ai.txt agent-page.html; do
  n=$(grep -cE '[Ss]hould use|use ONLY|ONLY (via|through|the MCP)|AI agents must|[Nn]ever (fill|mix|submit|invent|guess)|do not (state|submit|present|use|fill)|ask the user for' ranatec-api/public/$f || true)
  [[ $n -eq 0 ]] && ok "$f: agent text is descriptive (no commands)" || bad "$f: $n imperative agent lines"
  [[ $(grep -o "An MCP server is available for quote requests" ranatec-api/public/$f | wc -l) -le 1 ]] && ok "$f: the MCP/consent block appears once" || bad "$f: repeated MCP block"
done
sch=$(php tests/schema-test.php tests/fixtures/homepage-sample.html </dev/null 2>&1)
while IFS= read -r line; do case $line in PASS\ *) ok "schema/alt: ${line#PASS }";; FAIL\ *) bad "schema/alt: ${line#FAIL }";; *) [[ -n $line ]] && bad "schema test output: $line";; esac; done <<<"$sch"
rules=$(php -r 'require "tests/wp-stubs.php"; require "ranatec-api/ranatec-api.php"; foreach (Ranatec_Agent_Static_Files::rules() as $f => $l) echo basename(dirname($f)), "/", basename($f), ":", implode("|", $l), "\n";' </dev/null)
has 'ForceType "text/plain; charset=utf-8"' <<<"$rules" && has 'ForceType "application/linkset+json; charset=utf-8"' <<<"$rules" && has 'Header set Access-Control-Allow-Origin "\*"' <<<"$rules" && has 'Header set Cache-Control "public, max-age=3600"' <<<"$rules" && ok "static-file .htaccess rules carry the same headers as the plugin" || bad "static rules: $rules"

echo "== WebMCP"
node --check ranatec-api/public/webmcp.js && ok "webmcp.js is valid JavaScript" || bad "webmcp.js syntax"
head=$(php -r 'require "tests/wp-stubs.php"; require "ranatec-api/ranatec-api.php"; Ranatec_Agent_WebMCP::head();' </dev/null)
has 'data-ranatec-webmcp' <<<"$head" && has 'public/webmcp.js?ver=' <<<"$head" && ! has 'origin-trial' <<<"$head" && ok "WebMCP script in page heads (no origin-trial meta without a token)" || bad "WebMCP head tag: $head"
head=$(WP_OPTION_ranatec_api_webmcp_enabled=0 php -r 'require "tests/wp-stubs.php"; require "ranatec-api/ranatec-api.php"; Ranatec_Agent_WebMCP::head();' </dev/null)
[[ -z $head ]] && ok "WebMCP switched off → nothing added to pages" || bad "WebMCP off still outputs: $head"
head=$(WP_OPTION_ranatec_api_webmcp_ot_token='AnOriginTrialTokenAbc123+/=' php -r 'require "tests/wp-stubs.php"; require "ranatec-api/ranatec-api.php"; Ranatec_Agent_WebMCP::head();' </dev/null)
has '<meta http-equiv="origin-trial" content="AnOriginTrialTokenAbc123+/=" />' <<<"$head" && ok "origin-trial token printed as a meta tag" || bad "origin-trial meta: $head"
head=$(WP_OPTION_ranatec_api_webmcp_ot_token='"><script>alert(1)</script>' php -r 'require "tests/wp-stubs.php"; require "ranatec-api/ranatec-api.php"; Ranatec_Agent_WebMCP::head();' </dev/null)
! has 'origin-trial' <<<"$head" && ! has 'alert' <<<"$head" && ok "a malformed token is not printed" || bad "malformed token printed: $head"
page=$(php tests/wp-stub-harness.php agent-page '' GET </dev/null); has 'data-ranatec-webmcp' <<<"$page" && ok "/agent/ page loads the WebMCP script" || bad "/agent/ page WebMCP script"
page=$(WP_OPTION_ranatec_api_webmcp_enabled=0 php tests/wp-stub-harness.php agent-page '' GET </dev/null); ! has 'data-ranatec-webmcp' <<<"$page" && ok "WebMCP off → /agent/ page without the script" || bad "/agent/ page script while off"

echo "== MCP server"
( cd ranatec-mcp && { [[ -d node_modules ]] || npm ci --no-audit --no-fund >/dev/null; } && npm run build >/dev/null ) && ok "npm ci + build" || bad "npm ci + build"
RANATEC_API_BASE="http://127.0.0.1:$PHP_PORT/agent/v1" PORT=$MCP_PORT node ranatec-mcp/dist/index.js >"$TMP/mcp.log" 2>&1 & MCP_PID=$!
ready=0; for i in $(seq 1 80); do curl -s -o /dev/null "http://127.0.0.1:$MCP_PORT/health" && { ready=1; break; }; sleep 0.25; done
[[ $ready == 1 ]] && ok "MCP server ready on :$MCP_PORT" || { bad "MCP server did not start on :$MCP_PORT (port in use?)"; cat "$TMP/mcp.log"; }
cleanup_rl
curl -s "http://127.0.0.1:$MCP_PORT/mcp/tools" | python3 -c "
import json,sys; live=[t['name'] for t in json.load(sys.stdin)['tools']]; m=json.load(open('ranatec-api/public/ard.json'))
mcp=next(e for e in m['entries'] if e['type']=='application/mcp-server-card+json')
assert mcp['capabilities']==live, (mcp['capabilities'], live); assert mcp['data']['version']==json.load(open('ranatec-mcp/package.json'))['version']" && ok "ARD MCP entry matches the running MCP server (tools, version)" || bad "ARD MCP entry drifted from the MCP server"
if ( cd ranatec-mcp && SMOKE_MODE=stub MCP_URL="http://127.0.0.1:$MCP_PORT/mcp" npm test --silent ) >"$TMP/smoke.log" 2>&1; then
  cat "$TMP/smoke.log"; ok "MCP smoke test"
else
  cat "$TMP/smoke.log"; echo "--- mcp server log:"; tail -20 "$TMP/mcp.log"; bad "MCP smoke test"
fi

# Modes that really submit refuse to run when the server under test uses the live ranatec.com API. A stand-in
# server answers only /mcp/health (api_base = production) and counts every other request: none may arrive.
GUARD_PORT=$((MCP_PORT + 1))
node -e '
const http = require("http"); let other = 0;
http.createServer((q, r) => {
  if (q.url === "/mcp/health") { r.setHeader("Content-Type", "application/json"); return r.end(JSON.stringify({ status: "ok", version: "x", api_base: process.argv[2] })); }
  if (q.url === "/count") return r.end(String(other));
  other++; r.statusCode = 500; r.end();
}).listen(+process.argv[1]);' "$GUARD_PORT" "https://ranatec.com/agent/v1" & GUARD_PID=$!
for i in $(seq 1 40); do curl -s -o /dev/null "http://127.0.0.1:$GUARD_PORT/mcp/health" && break; sleep 0.1; done
[[ $(curl -s "http://127.0.0.1:$GUARD_PORT/count") == 0 ]] || bad "guard stand-in server did not start on :$GUARD_PORT (port in use?)"
for m in staging stub; do
  grc=0; gout=$( cd ranatec-mcp && SMOKE_MODE=$m MCP_URL="http://127.0.0.1:$GUARD_PORT/mcp" node test/smoke.mjs 2>&1 ) || grc=$?
  [[ $grc -eq 2 ]] && has 'REFUSED' <<<"$gout" && [[ $(curl -s "http://127.0.0.1:$GUARD_PORT/count") == 0 ]] && ok "smoke SMOKE_MODE=$m refuses a server that uses the live ranatec.com API (nothing sent)" || bad "smoke production guard ($m): rc=$grc $gout"
done
kill $GUARD_PID 2>/dev/null || true; wait $GUARD_PID 2>/dev/null || true

# Default (production-safe) smoke mode: everything passes, and nothing is stored, ordered or emailed.
cleanup_rl; rm -f "$TMPD/ranatec-acf7db.jsonl" "$TMPD/ranatec-wc-orders.jsonl" "$TMPD/ranatec-mail.txt"
if ( cd ranatec-mcp && MCP_URL="http://127.0.0.1:$MCP_PORT/mcp" npm test --silent ) >"$TMP/smoke-ro.log" 2>&1 && grep -q 'SMOKE_MODE=readonly' "$TMP/smoke-ro.log" \
   && [[ ! -e "$TMPD/ranatec-acf7db.jsonl" && ! -e "$TMPD/ranatec-wc-orders.jsonl" && ! -e "$TMPD/ranatec-mail.txt" ]]; then
  ok "MCP smoke test, default read-only mode: all checks pass, nothing stored, ordered or emailed"
else
  cat "$TMP/smoke-ro.log"; ls "$TMPD"/ranatec-* 2>/dev/null; bad "MCP smoke test read-only mode"
fi

echo; echo "$pass passed, $fail failed"; [[ $fail -eq 0 ]]
