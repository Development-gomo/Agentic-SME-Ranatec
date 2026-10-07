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
cleanup() { [[ -n "${PHP_PID:-}" ]] && kill "$PHP_PID" 2>/dev/null || true; [[ -n "${MCP_PID:-}" ]] && kill "$MCP_PID" 2>/dev/null || true; rm -rf "$TMP"; rm -f "$(php -r 'echo sys_get_temp_dir();')"/rl-ranatec_api_rl_* "$(php -r 'echo sys_get_temp_dir();')"/ranatec-mail.txt; }
trap cleanup EXIT
cleanup_rl() { rm -f "$(php -r 'echo sys_get_temp_dir();')"/rl-ranatec_api_rl_*; }

echo "== PHP"
for f in ranatec-api/ranatec-api.php ranatec-api/includes/*.php tests/*.php; do php -l "$f" >/dev/null && ok "lint $f" || bad "lint $f"; done
for f in ranatec-api/data/*.json ranatec-api/openapi.json ranatec-api/public/*.json; do python3 -c "import json,sys;json.load(open(sys.argv[1]))" "$f" && ok "json $f" || bad "json $f"; done

# --- contact-form enquiry (contact.json / submit_inquiry) → Advanced CF7 DB only
BODY='{"agent_context":{"user_authorized_submission":true,"user_request_summary":"QA: user asked whether the RI 181 can get a USB-C feedthrough"},"person":{"name":"Jörg Ångström","email":"qa@gomogroup.com","phone":"+46 31 000 00 00"},"company":{"name":"Prüf GmbH"},"inquiry":{"type":"custom_solution","message":"Kann die RI 181 mit USB-C-Durchführung geliefert werden?"}}'
cleanup_rl; echo "$BODY" | php tests/wp-stub-harness.php contact '' POST | grep -q '"status": "received"' && ok "contact enquiry (mbstring on)" || bad "contact enquiry (mbstring on)"
if ! php -n -m | grep -qi mbstring; then
  cleanup_rl; echo "$BODY" | php -n tests/wp-stub-harness.php contact '' POST | grep -q '"status": "received"' && ok "contact enquiry (mbstring OFF)" || bad "contact enquiry (mbstring OFF)"
fi
cleanup_rl; echo "${BODY/true/false}" | php tests/wp-stub-harness.php contact '' POST | grep -q 'STATUS 403' && ok "contact without consent → 403" || bad "contact without consent → 403"
TMPD=$(php -r 'echo sys_get_temp_dir();')
cleanup_rl; rm -f "$TMPD/ranatec-acf7db.jsonl" "$TMPD/ranatec-wc-orders.jsonl"; out=$(echo "$BODY" | TEST_ACF7DB=1 php tests/wp-stub-harness.php contact '' POST)
if echo "$out" | grep -q '"entry_id": 101' && grep -q '"name":"your-name","value":"J' "$TMPD/ranatec-acf7db.jsonl" && grep -q '"name":"tel-882"' "$TMPD/ranatec-acf7db.jsonl" && grep -q '"name":"text-863","value":"Pr' "$TMPD/ranatec-acf7db.jsonl" && [[ ! -e "$TMPD/ranatec-wc-orders.jsonl" ]]; then ok "contact enquiry stored in Advanced CF7 DB (form 50 fields), no order created"; else echo "$out"; bad "Advanced CF7 DB storage"; fi
for f in name email phone; do
  case $f in name) b="${BODY/\"name\":\"Jörg Ångström\",/}";; email) b="${BODY/\"email\":\"qa@gomogroup.com\",/}";; phone) b="${BODY/,\"phone\":\"+46 31 000 00 00\"/}";; esac
  cleanup_rl; echo "$b" | php tests/wp-stub-harness.php contact '' POST | grep -q "person.$f" && ok "contact: $f required" || bad "contact: $f required"
done
cleanup_rl; echo "${BODY/\"name\":\"Prüf GmbH\"/\"name\":\"\"}" | php tests/wp-stub-harness.php contact '' POST | grep -q 'company.name' && ok "contact: company required" || bad "contact: company required"
cleanup_rl; echo "${BODY/\"type\":\"custom_solution\"/\"type\":\"quote_request\"}" | php tests/wp-stub-harness.php contact '' POST | grep -q 'use_request_quote' && ok "contact refuses quote_request → use_request_quote" || bad "contact refuses quote_request"
b=$(python3 -c "import json,sys; d=json.loads(sys.argv[1]); d['inquiry'].update(type='general', products=[{'id': 'ri-268'}]); print(json.dumps(d))" "$BODY")
cleanup_rl; echo "$b" | php tests/wp-stub-harness.php contact '' POST | grep -q 'use_request_quote' && ok "contact refuses products → use_request_quote" || bad "contact refuses products"
rm -f "$TMPD/ranatec-acf7db.jsonl"
cleanup_rl; echo "${BODY/\"user_authorized_submission\":true/\"user_authorized_submission\":true,\"dry_run\":true}" | php tests/wp-stub-harness.php contact '' POST | grep -q '"submitted": false' && ok "contact dry run validates without submitting" || bad "contact dry run"
cleanup_rl; echo "${BODY/qa@gomogroup.com/john.doe@example.com}" | php tests/wp-stub-harness.php contact '' POST | grep -q 'example/test addresses' && ok "example.com address rejected" || bad "example.com address"
cleanup_rl; echo "${BODY/QA: user asked whether the RI 181 can get a USB-C feedthrough/}" | php tests/wp-stub-harness.php contact '' POST | grep -q 'user_request_summary' && ok "missing user request summary rejected" || bad "user request summary"
cleanup_rl; echo "${BODY/Jörg Ångström/<full name>}" | php tests/wp-stub-harness.php contact '' POST | grep -q 'placeholders' && ok "unfilled <placeholder> rejected" || bad "placeholder"

# --- product quote (quote.json / request_quote) → WooCommerce order only, checkout fields required
QBODY='{"agent_context":{"user_authorized_submission":true,"user_request_summary":"QA: user asked for a quote for two configured RI 181 shield boxes"},"customer":{"first_name":"Jörg","last_name":"Ångström","email":"qa@gomogroup.com","phone":"+46 31 000 00 00","company":"Prüf GmbH","country":"Germany","address_1":"Teststraße 1","city":"München","postcode":"80331","state":"Bavaria (Bayern)"},"products":[{"id":"shield-box-ri-181","quantity":2,"configuration":[{"id":"feedthrough-filter-ri-4182","quantity":2}]}],"note":"Lieferung Q1"}'
cleanup_rl; rm -f "$TMPD/ranatec-wc-orders.jsonl" "$TMPD/ranatec-acf7db.jsonl"; out=$(echo "$QBODY" | TEST_ACF7DB=1 php tests/wp-stub-harness.php quote '' POST)
if echo "$out" | grep -q '"flow": "product_quote"' && echo "$out" | grep -q '"order_number": "4242"' && [[ ! -e "$TMPD/ranatec-acf7db.jsonl" ]] \
   && python3 -c "
import json,sys; o=json.loads(open(sys.argv[1]).readline())
assert o['args']['status']=='ywraq-new' and o['payment_method']=='yith-request-a-quote', o
assert (o['billing_first_name'],o['billing_last_name'],o['billing_company'],o['billing_country'],o['billing_state'],o['billing_postcode'],o['billing_city'],o['billing_address_1'],o['billing_phone'],o['billing_email'])==('Jörg','Ångström','Prüf GmbH','DE','BY','80331','München','Teststraße 1','+46 31 000 00 00','qa@gomogroup.com'), o
assert o['shipping_country']=='DE' and o['items']==[['shield-box-ri-181',2],['feedthrough-filter-ri-4182',4]] and o['item_meta']['2']['Addon/Accessory for']=='Product shield-box-ri-181', o
assert o['meta']['_wc_other/custom-checkout/additional-note']=='Lieferung Q1' and o['customer_note']=='Lieferung Q1' and 'request_quote' in o['notes'][0], o
" "$TMPD/ranatec-wc-orders.jsonl"; then ok "quote → WooCommerce order with checkout fields (billing+shipping, state code, Additional Note, RFQ payment, configured option line), not a contact lead"; else echo "$out"; cat "$TMPD/ranatec-wc-orders.jsonl" 2>/dev/null; bad "quote order"; fi
for f in first_name last_name email phone company country address_1 city postcode; do
  b=$(python3 -c "import json,sys; d=json.loads(sys.argv[1]); del d['customer'][sys.argv[2]]; print(json.dumps(d))" "$QBODY" "$f")
  cleanup_rl; echo "$b" | php tests/wp-stub-harness.php quote '' POST | grep -q "customer.$f" && ok "quote: $f required" || bad "quote: $f required"
done
b=$(python3 -c "import json,sys; d=json.loads(sys.argv[1]); d['customer'].update(country='US', state='', postcode='94105'); print(json.dumps(d))" "$QBODY")
cleanup_rl; echo "$b" | php tests/wp-stub-harness.php quote '' POST | grep -q 'customer.state' && ok "quote: state required for US" || bad "quote: state required for US"
b=$(python3 -c "import json,sys; d=json.loads(sys.argv[1]); d['customer'].update(country='SE', postcode='41250'); d['customer'].pop('state'); print(json.dumps(d))" "$QBODY")
cleanup_rl; echo "$b" | php tests/wp-stub-harness.php quote '' POST | grep -q '"status": "received"' && ok "quote: no state needed for Sweden" || bad "quote: Sweden without state"
b=$(python3 -c "import json,sys; d=json.loads(sys.argv[1]); d['customer'].update(country='Narnia'); print(json.dumps(d))" "$QBODY")
cleanup_rl; echo "$b" | php tests/wp-stub-harness.php quote '' POST | grep -q 'customer.country' && ok "quote: unknown country rejected" || bad "quote: unknown country"
b=$(python3 -c "import json,sys; d=json.loads(sys.argv[1]); d['products']=[]; print(json.dumps(d))" "$QBODY")
cleanup_rl; echo "$b" | php tests/wp-stub-harness.php quote '' POST | grep -q '"products"' && ok "quote: at least one product required" || bad "quote: products required"
cleanup_rl; rm -f "$TMPD/ranatec-wc-orders.jsonl"; echo "${QBODY/\"user_authorized_submission\":true/\"user_authorized_submission\":true,\"dry_run\":true}" | php tests/wp-stub-harness.php quote '' POST | grep -q '"submitted": false' && [[ ! -e "$TMPD/ranatec-wc-orders.jsonl" ]] && ok "quote dry run validates without creating an order" || bad "quote dry run"
cleanup_rl; echo "$QBODY" | TEST_WC_MISSING=feedthrough-filter-ri-4182 php tests/wp-stub-harness.php quote '' POST | grep -q 'product_not_in_shop' && ok "quote: product missing in shop → 409, nothing half-built" || bad "quote: missing product"
cleanup_rl; rm -f "$TMPD/ranatec-acf7db.jsonl"; out=$(echo "$QBODY" | TEST_WC=0 TEST_ACF7DB=1 php tests/wp-stub-harness.php quote '' POST); echo "$out" | grep -q 'quotes_unavailable' && [[ ! -e "$TMPD/ranatec-acf7db.jsonl" ]] && ok "quote without WooCommerce → 503, never stored as a contact lead" || { echo "$out"; bad "quote without WooCommerce"; }
cleanup_rl; echo "${QBODY/true/false}" | php tests/wp-stub-harness.php quote '' POST | grep -q 'STATUS 403' && ok "quote without consent → 403" || bad "quote without consent"
rm -f "$TMPD/ranatec-wc-orders.jsonl"

LOCK=lock-key-0123456789abcdefghijkl
cleanup_rl; echo "$BODY" | WP_OPTION_ranatec_api_mcp_key=$LOCK php tests/wp-stub-harness.php contact '' POST | grep -q 'use_mcp_submit_inquiry' && ok "MCP-only lock: direct contact call without key → 403 use_mcp_submit_inquiry" || bad "MCP-only lock (no key)"
cleanup_rl; echo "$QBODY" | WP_OPTION_ranatec_api_mcp_key=$LOCK php tests/wp-stub-harness.php quote '' POST | grep -q 'use_mcp_request_quote' && ok "MCP-only lock: direct quote call without key → 403 use_mcp_request_quote" || bad "MCP-only lock quote (no key)"
cleanup_rl; echo "$BODY" | WP_OPTION_ranatec_api_mcp_key=$LOCK HTTP_X_RANATEC_MCP_KEY=$LOCK php tests/wp-stub-harness.php contact '' POST | grep -q '"status": "received"' && ok "MCP-only lock: call with MCP key → received" || bad "MCP-only lock (with key)"

echo "== Versions"
python3 tools/check_versions.py >"$TMP/versions.log" 2>&1 && ok "all component versions match VERSION ($(tr -d '[:space:]' < VERSION))" || { cat "$TMP/versions.log"; bad "version mismatch"; }

echo "== HTTP (PHP built-in server)"
php -S 127.0.0.1:$PHP_PORT tests/php-router.php >"$TMP/php.log" 2>&1 & PHP_PID=$!
ready=0; for i in $(seq 1 80); do curl -s -o /dev/null "http://127.0.0.1:$PHP_PORT/agent/v1/index.json" && { ready=1; break; }; sleep 0.25; done
[[ $ready == 1 ]] && ok "PHP server ready on :$PHP_PORT" || { bad "PHP server did not start on :$PHP_PORT (port in use?)"; cat "$TMP/php.log"; }
for p in agent/ agent/v1/index.json agent/v1/products.json agent/v1/products/ri-268.json agent/v1/categories/butler-matrices.json agent/v1/news.json agent/v1/faq.json openapi.json llms.txt llms-full.txt ai.txt api-catalog.json .well-known/api-catalog; do
  code=$(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:$PHP_PORT/$p"); [[ $code == 200 ]] && ok "GET /$p" || bad "GET /$p ($code)"
done

echo "== MCP server"
( cd ranatec-mcp && { [[ -d node_modules ]] || npm ci --no-audit --no-fund >/dev/null; } && npm run build >/dev/null ) && ok "npm ci + build" || bad "npm ci + build"
RANATEC_API_BASE="http://127.0.0.1:$PHP_PORT/agent/v1" PORT=$MCP_PORT node ranatec-mcp/dist/index.js >"$TMP/mcp.log" 2>&1 & MCP_PID=$!
ready=0; for i in $(seq 1 80); do curl -s -o /dev/null "http://127.0.0.1:$MCP_PORT/health" && { ready=1; break; }; sleep 0.25; done
[[ $ready == 1 ]] && ok "MCP server ready on :$MCP_PORT" || { bad "MCP server did not start on :$MCP_PORT (port in use?)"; cat "$TMP/mcp.log"; }
cleanup_rl
if ( cd ranatec-mcp && MCP_URL="http://127.0.0.1:$MCP_PORT/mcp" npm test --silent ) >"$TMP/smoke.log" 2>&1; then
  cat "$TMP/smoke.log"; ok "MCP smoke test"
else
  cat "$TMP/smoke.log"; echo "--- mcp server log:"; tail -20 "$TMP/mcp.log"; bad "MCP smoke test"
fi

echo; echo "$pass passed, $fail failed"; [[ $fail -eq 0 ]]
