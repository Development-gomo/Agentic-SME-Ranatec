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

BODY='{"agent_context":{"user_authorized_submission":true},"person":{"name":"Jörg Ångström","email":"qa@example.com"},"company":{"name":"Prüf GmbH"},"inquiry":{"type":"quote_request","message":"Angebot für 2 × RI 268 bitte.","products":[{"id":"tunable-band-reject-filter-ri-268","quantity":2}]}}'
cleanup_rl; echo "$BODY" | php tests/wp-stub-harness.php contact '' POST | grep -q '"status": "received"' && ok "contact (mbstring on)" || bad "contact (mbstring on)"
if ! php -n -m | grep -qi mbstring; then
  cleanup_rl; echo "$BODY" | php -n tests/wp-stub-harness.php contact '' POST | grep -q '"status": "received"' && ok "contact (mbstring OFF)" || bad "contact (mbstring OFF)"
fi
cleanup_rl; echo "${BODY/true/false}" | php tests/wp-stub-harness.php contact '' POST | grep -q 'STATUS 403' && ok "contact without consent → 403" || bad "contact without consent → 403"
LOCK=lock-key-0123456789abcdefghijkl
cleanup_rl; echo "$BODY" | WP_OPTION_ranatec_api_mcp_key=$LOCK php tests/wp-stub-harness.php contact '' POST | grep -q 'use_mcp_submit_inquiry' && ok "MCP-only lock: direct call without key → 403 use_mcp_submit_inquiry" || bad "MCP-only lock (no key)"
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
  cat "$TMP/smoke.log"; ok "MCP smoke test (14 checks)"
else
  cat "$TMP/smoke.log"; echo "--- mcp server log:"; tail -20 "$TMP/mcp.log"; bad "MCP smoke test"
fi

echo; echo "$pass passed, $fail failed"; [[ $fail -eq 0 ]]
