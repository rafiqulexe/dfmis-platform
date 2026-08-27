#!/usr/bin/env bash
# ============================================================================
# API HTTP integration suite (Phase 2B).
#
# Proves the API foundation over real HTTP: routing, the JSON contract, the
# error envelope, method enforcement, and — most importantly — that the API
# reuses the Phase 1 security model rather than becoming a way around it.
#
# Lives in shell rather than PHPUnit for the same reason the other integration
# suites do: it needs a running server, and this project FAILS rather than
# skips when one is absent. PHPUnit's idiom is markTestSkipped, which is the
# silent-green failure this project deliberately avoids.
#
#   bash tests/api_test.sh
# ============================================================================

BASE="${DFMS_BASE_URL:-http://localhost:8000}"
API="$BASE/api/index.php"
TMP=$(mktemp -d)
PASS=0
FAIL=0

cleanup() { rm -rf "$TMP"; }
trap cleanup EXIT

ok()  { echo "  pass  $1"; PASS=$((PASS + 1)); }
bad() { echo "  FAIL  $1 — $2"; FAIL=$((FAIL + 1)); }
section() { echo ""; echo "== $1 =="; }

check_status()  { [ "$2" = "$3" ] && ok "$1 (HTTP $2)" || bad "$1" "expected HTTP $3, got $2"; }
check_present() { grep -q "$3" "$2" && ok "$1" || bad "$1" "expected to find '$3' in $(head -c 200 "$2")"; }
check_absent()  { grep -qi "$3" "$2" && bad "$1" "leaked '$3'" || ok "$1"; }

login() {
    local token
    token=$(curl -s -c "$3" "$BASE/index.php" | sed -n 's/.*name="csrf_token" value="\([^"]*\)".*/\1/p' | head -1)
    curl -s -b "$3" -c "$3" -X POST -d "csrf_token=$token&username=$1&password=$2" "$BASE/index.php" -o /dev/null
}

# A JSON field reader that does not require jq (not present on every dev box).
json_field() { # json_field <file> <key>
    sed -n "s/.*\"$2\"[[:space:]]*:[[:space:]]*\"\{0,1\}\([^\",}]*\)\"\{0,1\}.*/\1/p" "$1" | head -1
}

ADMIN="$TMP/admin.txt";  login admin admin123 "$ADMIN"
RIVAL="$TMP/rival.txt";  login rival rival123 "$RIVAL"
ORPHAN="$TMP/orphan.txt"; login orphan orphan123 "$ORPHAN"

# ============================================================================
section "Routing and versioning"
# ============================================================================
CODE=$(curl -s -o "$TMP/health.json" -w '%{http_code}' "$API/v1/health")
check_status "GET /v1/health" "$CODE" "200"
check_present "health reports status" "$TMP/health.json" '"status"'
check_present "health reports database" "$TMP/health.json" '"database"'

# A public health endpoint must not become a reconnaissance tool.
check_absent "health leaks no version"  "$TMP/health.json" "version"
check_absent "health leaks no hostname" "$TMP/health.json" "localhost"
check_absent "health leaks no db name"  "$TMP/health.json" "dfms"
check_absent "health leaks no path"     "$TMP/health.json" "Projects"

CODE=$(curl -s -o "$TMP/404.json" -w '%{http_code}' "$API/v1/does-not-exist")
check_status "unknown route" "$CODE" "404"
check_present "unknown route uses the error envelope" "$TMP/404.json" '"code":"NOT_FOUND"'

CODE=$(curl -s -o /dev/null -w '%{http_code}' "$API/v2/health")
check_status "unversioned/unknown version rejected" "$CODE" "404"

# ============================================================================
section "JSON response contract"
# ============================================================================
curl -s -D "$TMP/headers.txt" -o /dev/null "$API/v1/health"
check_present "content type is JSON"        "$TMP/headers.txt" "application/json"
check_present "responses are not cacheable" "$TMP/headers.txt" "no-store"
check_present "nosniff is set"              "$TMP/headers.txt" "X-Content-Type-Options"
check_present "correlation id is returned"  "$TMP/headers.txt" "X-Request-Id"

# Success payloads are always under `data`, so the shape never varies.
curl -s -b "$ADMIN" -o "$TMP/me.json" "$API/v1/me"
check_present "success payloads use a data envelope" "$TMP/me.json" '"data"'

# ============================================================================
section "Error contract"
# ============================================================================
CODE=$(curl -s -o "$TMP/method.json" -w '%{http_code}' -X POST "$API/v1/health")
check_status "wrong method" "$CODE" "405"
check_present "error has a machine code" "$TMP/method.json" '"code":"METHOD_NOT_ALLOWED"'
check_present "error has a message"      "$TMP/method.json" '"message"'
check_present "error has a correlation id" "$TMP/method.json" '"correlation_id"'

CODE=$(curl -s -o /dev/null -w '%{http_code}' -X DELETE "$API/v1/me")
check_status "DELETE on a GET-only route" "$CODE" "405"

# OPTIONS is answered by the foundation, never by endpoint logic.
CODE=$(curl -s -o /dev/null -w '%{http_code}' -X OPTIONS "$API/v1/health")
check_status "OPTIONS handled by the foundation" "$CODE" "204"

# Correlation ids must differ per request, or they cannot identify one.
A=$(curl -s "$API/v1/does-not-exist" | sed -n 's/.*"correlation_id":"\([^"]*\)".*/\1/p')
B=$(curl -s "$API/v1/does-not-exist" | sed -n 's/.*"correlation_id":"\([^"]*\)".*/\1/p')
if [ -n "$A" ] && [ -n "$B" ] && [ "$A" != "$B" ]; then
    ok "correlation ids are unique per request"
else
    bad "correlation ids are unique per request" "got '$A' and '$B'"
fi

# No internals in any error response.
for path in "/v1/does-not-exist" "/v1/me"; do
    curl -s -o "$TMP/err.json" "$API$path"
    check_absent "no filesystem path in error ($path)" "$TMP/err.json" "Projects"
    check_absent "no SQL in error ($path)"             "$TMP/err.json" "SELECT"
    check_absent "no stack trace in error ($path)"     "$TMP/err.json" "#0"
done

# ============================================================================
section "Authentication"
# ============================================================================
# An API client must get 401 JSON, never an HTML redirect to the login page.
CODE=$(curl -s -o "$TMP/401.json" -w '%{http_code}' "$API/v1/me")
check_status "unauthenticated /v1/me" "$CODE" "401"
check_present "401 uses the error envelope" "$TMP/401.json" '"code":"UNAUTHENTICATED"'
check_absent  "401 is not an HTML page"     "$TMP/401.json" "<!DOCTYPE"

CODE=$(curl -s -o /dev/null -w '%{http_code}' "$API/v1/settings")
check_status "unauthenticated /v1/settings" "$CODE" "401"

CODE=$(curl -s -b "$ADMIN" -o /dev/null -w '%{http_code}' "$API/v1/me")
check_status "authenticated /v1/me" "$CODE" "200"

# ============================================================================
section "Authorization and tenant context"
# ============================================================================
# A membership-less user must get a handled JSON 403, not a crash or HTML.
CODE=$(curl -s -b "$ORPHAN" -o "$TMP/orphan.json" -w '%{http_code}' "$API/v1/me")
check_status "membership-less user refused" "$CODE" "403"
check_present "refusal uses the error envelope" "$TMP/orphan.json" '"code":"FORBIDDEN"'
check_absent  "refusal is not an HTML page"     "$TMP/orphan.json" "<!DOCTYPE"
check_absent  "refusal leaks no TypeError"      "$TMP/orphan.json" "TypeError"

CODE=$(curl -s -b "$ORPHAN" -o /dev/null -w '%{http_code}' "$API/v1/settings")
check_status "membership-less user refused settings" "$CODE" "403"

# ============================================================================
section "Tenant isolation — the API must not be a way around it"
# ============================================================================
curl -s -b "$ADMIN" -o "$TMP/admin_me.json" "$API/v1/me"
check_present "admin sees their own organization" "$TMP/admin_me.json" "Green Valley Dairy Group"
check_absent  "admin sees nothing of org 2"       "$TMP/admin_me.json" "Rival Holdings"

curl -s -b "$RIVAL" -o "$TMP/rival_me.json" "$API/v1/me"
check_present "rival sees their own organization" "$TMP/rival_me.json" "Rival Holdings"
check_absent  "rival sees nothing of org 1"       "$TMP/rival_me.json" "Green Valley Dairy Group"
check_absent  "rival sees nothing of farm 1"      "$TMP/rival_me.json" "Green Valley Dairy Farm"

# Crafted identifiers must be ignored: the API reads tenant context from the
# session, never from the request, so there is no parameter to pivot on.
curl -s -b "$RIVAL" -o "$TMP/rival_craft.json" "$API/v1/me?farm_id=1&organization_id=1&admin_id=1"
check_absent  "crafted farm_id does not cross tenants"  "$TMP/rival_craft.json" "Green Valley Dairy Farm"
check_absent  "crafted org_id does not cross tenants"   "$TMP/rival_craft.json" "Green Valley Dairy Group"
check_present "crafted request still returns own data"  "$TMP/rival_craft.json" "Rival"

curl -s -b "$RIVAL" -o "$TMP/rival_settings.json" "$API/v1/settings"
check_present "rival can read their own settings" "$TMP/rival_settings.json" '"settings"'
check_present "settings report their source"      "$TMP/rival_settings.json" '"source"'

# ============================================================================
section "No sensitive data in API responses"
# ============================================================================
curl -s -b "$ADMIN" -o "$TMP/me_full.json" "$API/v1/me"
for secret in "password" "hash" "csrf" "session" "\$2y\$" "is_platform_admin"; do
    check_absent "/v1/me excludes '$secret'" "$TMP/me_full.json" "$secret"
done

# ============================================================================
section "Handlers cannot be reached directly, bypassing the gates"
# ============================================================================
for handler in health me settings; do
    CODE=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/api/v1/$handler.php")
    check_status "direct hit on $handler.php refused" "$CODE" "404"
done

# ============================================================================
section "Existing HTML application is unaffected"
# ============================================================================
for path in dashboard.php modules/cows/ modules/settings/; do
    CODE=$(curl -s -b "$ADMIN" -o /dev/null -w '%{http_code}' "$BASE/$path")
    check_status "HTML page still works: $path" "$CODE" "200"
done

# An HTML page must still render HTML, not JSON — response mode must not leak.
curl -s -b "$ADMIN" -o "$TMP/dash.html" "$BASE/dashboard.php"
check_present "HTML pages still return HTML" "$TMP/dash.html" "<!DOCTYPE"

echo ""
echo "================================"
echo " passed: $PASS   failed: $FAIL"
echo "================================"
[ "$FAIL" -eq 0 ]
