#!/usr/bin/env bash
# ============================================================================
# Phase 3 HTTP suite — Animal Digital Twin
#
# TEST DATABASE ISOLATION (Phase 2B deferred item, addressed here)
#
# This suite is the FIRST to run against the isolated test database rather than
# the development one. It starts its own server:
#
#   php tools/test-db.php --create
#   DB_NAME=dfms_test php -S localhost:8100 -t .
#   DFMS_ANIMAL_BASE_URL=http://localhost:8100 bash tests/animal_isolation_test.sh
#
# tests/run_all.sh starts that server automatically. If DFMS_ANIMAL_BASE_URL is
# unset the suite falls back to the ordinary dev server — and SAYS SO, loudly,
# rather than pretending it was isolated.
#
# The four pre-Phase-3 HTTP suites still use the development database. They were
# not migrated: they pass, and moving them is a separate change.
# ============================================================================

BASE="${DFMS_ANIMAL_BASE_URL:-${DFMS_BASE_URL:-http://localhost:8000}}"
ISOLATED="${DFMS_ANIMAL_BASE_URL:-}"
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
check_present() { grep -q "$3" "$2" && ok "$1" || bad "$1" "expected '$3'"; }
check_absent()  { grep -qi "$3" "$2" && bad "$1" "leaked '$3'" || ok "$1"; }

login() {
    local token
    token=$(curl -s -c "$3" "$BASE/index.php" | sed -n 's/.*name="csrf_token" value="\([^"]*\)".*/\1/p' | head -1)
    curl -s -b "$3" -c "$3" -X POST -d "csrf_token=$token&username=$1&password=$2" "$BASE/index.php" -o /dev/null
}
csrf_for() { curl -s -b "$2" -c "$2" "$1" | sed -n 's/.*name="csrf_token" value="\([^"]*\)".*/\1/p' | head -1; }

echo "############################################################"
if [ -n "$ISOLATED" ]; then
    echo "# Running against the ISOLATED test database: $BASE"
else
    echo "# WARNING: running against the DEVELOPMENT server ($BASE)."
    echo "# Set DFMS_ANIMAL_BASE_URL to use the isolated test database."
fi
echo "############################################################"

ADMIN="$TMP/admin.txt";  login admin admin123 "$ADMIN"
VIEWER="$TMP/viewer.txt"; login viewer viewer123 "$VIEWER"
STAFF="$TMP/staff.txt";  login staff staff123 "$STAFF"
RIVAL="$TMP/rival.txt";  login rival rival123 "$RIVAL"

CODE=$(curl -s -b "$ADMIN" -o /dev/null -w '%{http_code}' "$BASE/dashboard.php")
[ "$CODE" = "200" ] && ok "admin is authenticated" || bad "admin is authenticated" "dashboard returned $CODE"

# Resolve a farm-1 animal id rather than hardcoding one.
ANIMAL_ID=$(curl -s -b "$ADMIN" "$API/v1/animals?per_page=1" | sed -n 's/.*"id":\([0-9]*\).*/\1/p' | head -1)
if [ -z "$ANIMAL_ID" ]; then
    bad "resolve a farm-1 animal" "could not read an animal id from the API"
    ANIMAL_ID=1
else
    ok "resolved farm-1 animal id=$ANIMAL_ID"
fi

# The cross-tenant fixture lives on farm 2.
RIVAL_ID=$(curl -s -b "$RIVAL" "$API/v1/animals?per_page=1" | sed -n 's/.*"id":\([0-9]*\).*/\1/p' | head -1)
[ -n "$RIVAL_ID" ] && ok "resolved farm-2 animal id=$RIVAL_ID" || bad "resolve a farm-2 animal" "none found"

# ============================================================================
section "Animal profile — access and tabs"
# ============================================================================
for TAB in overview timeline identity parentage health documents photos; do
    CODE=$(curl -s -b "$ADMIN" -o "$TMP/tab.html" -w '%{http_code}' "$BASE/modules/cows/view.php?id=$ANIMAL_ID&tab=$TAB")
    if [ "$CODE" != "200" ]; then
        bad "profile tab '$TAB'" "expected 200, got $CODE"
    elif grep -qi "Fatal error\|Uncaught\|Parse error" "$TMP/tab.html"; then
        bad "profile tab '$TAB'" "PHP error in output"
    else
        ok "profile tab '$TAB' renders"
    fi
done

# An unknown tab must fall back, not error.
check_status "unknown tab falls back" "$(curl -s -b "$ADMIN" -o /dev/null -w '%{http_code}' "$BASE/modules/cows/view.php?id=$ANIMAL_ID&tab=nonsense")" "200"

# ============================================================================
section "Cross-tenant animal access"
# ============================================================================
check_status "farm-1 user cannot open a farm-2 animal profile" \
    "$(curl -s -b "$ADMIN" -o /dev/null -w '%{http_code}' "$BASE/modules/cows/view.php?id=$RIVAL_ID")" "404"
check_status "farm-2 user cannot open a farm-1 animal profile" \
    "$(curl -s -b "$RIVAL" -o /dev/null -w '%{http_code}' "$BASE/modules/cows/view.php?id=$ANIMAL_ID")" "404"

curl -s -b "$ADMIN" -o "$TMP/list.html" "$BASE/modules/cows/"
check_absent "animal list excludes the other farm" "$TMP/list.html" "RIVAL001"

# Search must not become a cross-tenant oracle.
curl -s -b "$ADMIN" -o "$TMP/search.html" "$BASE/modules/cows/?search=RIVAL001"
check_absent "search cannot find another farm's animal" "$TMP/search.html" "Should Be Invisible"

for PROBE in "1%20OR%201=1" "-1" "0" "abc" "1.5" "%27"; do
    CODE=$(curl -s -b "$ADMIN" -o /dev/null -w '%{http_code}' "$BASE/modules/cows/view.php?id=$PROBE")
    [ "$CODE" = "404" ] || { bad "malformed animal id '$PROBE'" "expected 404, got $CODE"; BADID=1; }
done
[ -z "$BADID" ] && ok "malformed animal ids all return 404"

# ============================================================================
section "Lifecycle — authorization and tenant scope"
# ============================================================================
check_status "admin can open the lifecycle page" \
    "$(curl -s -b "$ADMIN" -o /dev/null -w '%{http_code}' "$BASE/modules/cows/lifecycle.php?id=$ANIMAL_ID")" "200"
check_status "viewer blocked from lifecycle" \
    "$(curl -s -b "$VIEWER" -o /dev/null -w '%{http_code}' "$BASE/modules/cows/lifecycle.php?id=$ANIMAL_ID")" "403"
check_status "staff blocked from lifecycle" \
    "$(curl -s -b "$STAFF" -o /dev/null -w '%{http_code}' "$BASE/modules/cows/lifecycle.php?id=$ANIMAL_ID")" "403"
check_status "lifecycle on a foreign animal is 404" \
    "$(curl -s -b "$ADMIN" -o /dev/null -w '%{http_code}' "$BASE/modules/cows/lifecycle.php?id=$RIVAL_ID")" "404"

# CSRF is required.
check_status "lifecycle POST without CSRF" \
    "$(curl -s -b "$ADMIN" -X POST -d "new_status=sold" "$BASE/modules/cows/lifecycle.php?id=$ANIMAL_ID" -o /dev/null -w '%{http_code}')" "403"

# An invalid transition must be refused server-side.
TOKEN=$(csrf_for "$BASE/modules/cows/lifecycle.php?id=$ANIMAL_ID" "$ADMIN")
curl -s -b "$ADMIN" -X POST -d "csrf_token=$TOKEN&new_status=abducted&event_date=2026-01-01" \
    "$BASE/modules/cows/lifecycle.php?id=$ANIMAL_ID" -o "$TMP/bad.html"
check_absent "invalid lifecycle status rejected" "$TMP/bad.html" "Status updated"

# ============================================================================
section "Transfer — authorization and tenant scope"
# ============================================================================
check_status "admin can open the transfer page" \
    "$(curl -s -b "$ADMIN" -o /dev/null -w '%{http_code}' "$BASE/modules/cows/transfer.php?id=$ANIMAL_ID")" "200"
check_status "viewer blocked from transfer" \
    "$(curl -s -b "$VIEWER" -o /dev/null -w '%{http_code}' "$BASE/modules/cows/transfer.php?id=$ANIMAL_ID")" "403"
check_status "staff blocked from transfer" \
    "$(curl -s -b "$STAFF" -o /dev/null -w '%{http_code}' "$BASE/modules/cows/transfer.php?id=$ANIMAL_ID")" "403"
check_status "transfer of a foreign animal is 404" \
    "$(curl -s -b "$ADMIN" -o /dev/null -w '%{http_code}' "$BASE/modules/cows/transfer.php?id=$RIVAL_ID")" "404"

# The decisive one: farm 2 is a DIFFERENT organization and admin is not a
# member. A crafted destination must be refused and the animal must not move.
TOKEN=$(csrf_for "$BASE/modules/cows/transfer.php?id=$ANIMAL_ID" "$ADMIN")
curl -s -b "$ADMIN" -X POST \
    -d "csrf_token=$TOKEN&mode=inter_farm&destination_farm_id=2&transferred_on=2026-01-01" \
    "$BASE/modules/cows/transfer.php?id=$ANIMAL_ID" -o "$TMP/xfer.html"
check_absent "unauthorized inter-farm transfer refused" "$TMP/xfer.html" "transferred to the destination farm"

# And the animal is still here.
check_status "animal remains on farm 1 after a refused transfer" \
    "$(curl -s -b "$ADMIN" -o /dev/null -w '%{http_code}' "$BASE/modules/cows/view.php?id=$ANIMAL_ID")" "200"

# Assert on the animal's ACTUAL tag, not a generic word. The rival's farm is
# named "Rival Farm (isolation test)", so a needle like "TEST" matches the farm
# name and reports a leak that never happened.
ANIMAL_TAG=$(curl -s -b "$ADMIN" "$API/v1/animals/$ANIMAL_ID" | sed -n 's/.*"tag":"\([^"]*\)".*/\1/p' | head -1)
if [ -n "$ANIMAL_TAG" ]; then
    curl -s -b "$RIVAL" -o "$TMP/rivallist.html" "$BASE/modules/cows/"
    check_absent "the animal did not appear on farm 2" "$TMP/rivallist.html" "$ANIMAL_TAG"
else
    bad "the animal did not appear on farm 2" "could not resolve the animal's tag"
fi

# ============================================================================
section "Identifiers"
# ============================================================================
check_status "identifiers endpoint rejects GET" \
    "$(curl -s -b "$ADMIN" -o /dev/null -w '%{http_code}' "$BASE/modules/cows/identifiers.php")" "405"
check_status "identifiers POST without CSRF" \
    "$(curl -s -b "$ADMIN" -X POST -d "action=add&animal_id=$ANIMAL_ID" "$BASE/modules/cows/identifiers.php" -o /dev/null -w '%{http_code}')" "403"
check_status "viewer blocked from identifiers" \
    "$(curl -s -b "$VIEWER" -X POST -d "action=add" "$BASE/modules/cows/identifiers.php" -o /dev/null -w '%{http_code}')" "403"

# A crafted animal_id must not reach another tenant.
TOKEN=$(csrf_for "$BASE/modules/cows/view.php?id=$ANIMAL_ID&tab=identity" "$ADMIN")
check_status "identifier cannot target a foreign animal" \
    "$(curl -s -b "$ADMIN" -X POST -d "csrf_token=$TOKEN&action=add&animal_id=$RIVAL_ID&identifier_type=rfid&identifier_value=X" \
        "$BASE/modules/cows/identifiers.php" -o /dev/null -w '%{http_code}')" "404"

# ============================================================================
section "Files — authorization and cross-tenant access"
# ============================================================================
check_status "file upload endpoint rejects GET" \
    "$(curl -s -b "$ADMIN" -o /dev/null -w '%{http_code}' "$BASE/modules/cows/files.php")" "405"
check_status "file upload without CSRF" \
    "$(curl -s -b "$ADMIN" -X POST -d "animal_id=$ANIMAL_ID&category=documents" "$BASE/modules/cows/files.php" -o /dev/null -w '%{http_code}')" "403"
check_status "viewer blocked from uploading" \
    "$(curl -s -b "$VIEWER" -X POST -d "animal_id=$ANIMAL_ID" "$BASE/modules/cows/files.php" -o /dev/null -w '%{http_code}')" "403"

# The Phase 1 serve handler still refuses anonymous and cross-tenant reads.
check_status "anonymous file access redirected" \
    "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/modules/files/serve.php?id=1")" "302"
check_status "nonexistent file is 404" \
    "$(curl -s -b "$ADMIN" -o /dev/null -w '%{http_code}' "$BASE/modules/files/serve.php?id=999999")" "404"

# ============================================================================
section "Animal API"
# ============================================================================
curl -s -b "$ADMIN" -o "$TMP/api_list.json" "$API/v1/animals"
check_present "API returns a data envelope" "$TMP/api_list.json" '"data"'
check_present "API returns pagination meta"  "$TMP/api_list.json" '"total"'
check_absent  "API does not leak farm_id"    "$TMP/api_list.json" '"farm_id"'

check_status "API animal detail" "$(curl -s -b "$ADMIN" -o /dev/null -w '%{http_code}' "$API/v1/animals/$ANIMAL_ID")" "200"
check_status "API cross-tenant animal is 404" "$(curl -s -b "$ADMIN" -o /dev/null -w '%{http_code}' "$API/v1/animals/$RIVAL_ID")" "404"
check_status "API cross-tenant in reverse is 404" "$(curl -s -b "$RIVAL" -o /dev/null -w '%{http_code}' "$API/v1/animals/$ANIMAL_ID")" "404"
check_status "API unauthenticated" "$(curl -s -o /dev/null -w '%{http_code}' "$API/v1/animals")" "401"
check_status "API malformed id" "$(curl -s -b "$ADMIN" -o /dev/null -w '%{http_code}' "$API/v1/animals/abc")" "404"
check_status "API rejects a write method" "$(curl -s -b "$ADMIN" -X POST -o /dev/null -w '%{http_code}' "$API/v1/animals")" "405"
check_status "API deeper path does not match" "$(curl -s -b "$ADMIN" -o /dev/null -w '%{http_code}' "$API/v1/animals/1/private")" "404"

# The rival must see only their own herd.
curl -s -b "$RIVAL" -o "$TMP/rival_api.json" "$API/v1/animals"
check_present "rival sees their own animal" "$TMP/rival_api.json" "RIVAL001"
check_absent  "rival sees no farm-1 animal" "$TMP/rival_api.json" "BD001"

# No sensitive fields.
curl -s -b "$ADMIN" -o "$TMP/api_detail.json" "$API/v1/animals/$ANIMAL_ID"
for SECRET in "stored_path" "password" "farm_id" "deleted_at"; do
    check_absent "API detail excludes '$SECRET'" "$TMP/api_detail.json" "$SECRET"
done

# ============================================================================
section "Existing modules still work"
# ============================================================================
for PATH_ in dashboard.php modules/cows/ modules/milk/ modules/health/ modules/feeding/ modules/inventory/ modules/alerts/ modules/settings/; do
    CODE=$(curl -s -b "$ADMIN" -o /dev/null -w '%{http_code}' "$BASE/$PATH_")
    [ "$CODE" = "200" ] || { bad "existing page $PATH_" "expected 200, got $CODE"; BROKE=1; }
done
[ -z "$BROKE" ] && ok "all pre-Phase-3 pages still return 200"

# Health module still resolves its animal join after the schema change.
curl -s -b "$ADMIN" -o "$TMP/health.html" "$BASE/modules/health/"
check_absent "health module has no PHP errors" "$TMP/health.html" "Fatal error"

echo ""
echo "================================"
echo " passed: $PASS   failed: $FAIL"
echo "================================"
[ "$FAIL" -eq 0 ]
