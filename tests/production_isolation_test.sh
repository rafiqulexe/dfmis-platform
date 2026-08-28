#!/usr/bin/env bash
# ============================================================================
# Phase 6 HTTP suite — Production & Feeding operations
#
# Runs against the ISOLATED test database, like the Phase 3/4/5 suites. Every
# assertion here is a MUTATION path or an authorization boundary, so it must
# never touch development data.
#
#   php tools/test-db.php --create
#   DB_NAME=dfms_test php -S localhost:8100 -t .
#   DFMS_PRODUCTION_BASE_URL=http://localhost:8100 bash tests/production_isolation_test.sh
#
# tests/run_all.sh starts that server automatically. If the variable is unset
# the suite falls back to the ordinary dev server — and SAYS SO, loudly,
# rather than pretending it was isolated.
#
# WHAT THIS SUITE IS FOR
# The PHPUnit suite covers the domain rules. This one covers what only a real
# request can: the permission matrix as the router applies it, CSRF on every
# mutating form, cross-farm ids arriving in POST bodies and URLs, and whether a
# POSTed farm_id can move a record between tenants.
# ============================================================================

BASE="${DFMS_PRODUCTION_BASE_URL:-${DFMS_BASE_URL:-http://localhost:8000}}"
ISOLATED="${DFMS_PRODUCTION_BASE_URL:-}"
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

# Dates on the SERVER's UTC clock, not the shell's — a shell ahead of UTC would
# otherwise submit "tomorrow" and be correctly rejected as a future date.
TODAY=$(date -u +%Y-%m-%d)

echo "############################################################"
if [ -n "$ISOLATED" ]; then
    echo "# Running against the ISOLATED test database: $BASE"
else
    echo "# WARNING: running against the DEVELOPMENT server ($BASE)."
    echo "# Set DFMS_PRODUCTION_BASE_URL to use the isolated test database."
fi
echo "############################################################"

ADMIN="$TMP/admin.txt";   login admin admin123           "$ADMIN"
STAFF="$TMP/staff.txt";   login staff staff123           "$STAFF"
VIEWER="$TMP/viewer.txt"; login viewer viewer123         "$VIEWER"
ACCT="$TMP/acct.txt";     login accountant accountant123 "$ACCT"
RIVAL="$TMP/rival.txt";   login rival rival123           "$RIVAL"

# ============================================================================
section "Pages render for an authorised user"
# ============================================================================
for PATHNAME in "modules/milk/" "modules/milk/?tab=animals" "modules/milk/?tab=farm" \
                "modules/milk/record.php" "modules/feeding/" "modules/feeding/?tab=usage" \
                "modules/feeding/?tab=schedule" "modules/feeding/consume.php" \
                "modules/feeding/rations.php"; do
    CODE=$(curl -s -b "$ADMIN" -o "$TMP/page.html" -w '%{http_code}' "$BASE/$PATHNAME")
    check_status "$PATHNAME renders" "$CODE" "200"
    check_absent "$PATHNAME has no PHP errors" "$TMP/page.html" "Fatal error"
    check_absent "$PATHNAME leaks no SQL" "$TMP/page.html" "SQLSTATE"
done

# ============================================================================
section "Authorization — the permission matrix as the router applies it"
# ============================================================================
# viewer has milk.view but NOT milk.create.
check_status "viewer can see production" \
    "$(curl -s -b "$VIEWER" -o /dev/null -w '%{http_code}' "$BASE/modules/milk/")" "200"
check_status "viewer cannot reach the recording form" \
    "$(curl -s -b "$VIEWER" -o /dev/null -w '%{http_code}' "$BASE/modules/milk/record.php")" "403"

# accountant has milk.view but no feeding.view at all.
check_status "accountant can see production" \
    "$(curl -s -b "$ACCT" -o /dev/null -w '%{http_code}' "$BASE/modules/milk/")" "200"
check_status "accountant is blocked from feeding" \
    "$(curl -s -b "$ACCT" -o /dev/null -w '%{http_code}' "$BASE/modules/feeding/")" "403"
check_status "accountant cannot record feed use" \
    "$(curl -s -b "$ACCT" -o /dev/null -w '%{http_code}' "$BASE/modules/feeding/consume.php")" "403"

# staff may record but must not DELETE — milk.delete is farm-manager only.
check_status "staff can reach the recording form" \
    "$(curl -s -b "$STAFF" -o /dev/null -w '%{http_code}' "$BASE/modules/milk/record.php")" "200"

check_status "anonymous production access is redirected" \
    "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/modules/milk/")" "302"
check_status "anonymous feeding access is redirected" \
    "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/modules/feeding/")" "302"

# ============================================================================
section "CSRF is required on every mutating form"
# ============================================================================
check_status "recording without a CSRF token is rejected" \
    "$(curl -s -b "$ADMIN" -o /dev/null -w '%{http_code}' -X POST \
        -d "cow_id=1&production_date=$TODAY&session=morning&quantity=5" \
        "$BASE/modules/milk/record.php")" "403"
check_status "feed consumption without a CSRF token is rejected" \
    "$(curl -s -b "$ADMIN" -o /dev/null -w '%{http_code}' -X POST \
        -d "item_id=1&consumed_on=$TODAY&quantity=1&scope_type=farm" \
        "$BASE/modules/feeding/consume.php")" "403"
check_status "ration creation without a CSRF token is rejected" \
    "$(curl -s -b "$ADMIN" -o /dev/null -w '%{http_code}' -X POST \
        -d "action=create&name=Sneaky" "$BASE/modules/feeding/rations.php")" "403"

# ============================================================================
section "Recording a milking, end to end"
# ============================================================================
# Resolve a real farm-1 animal from the page the operator would actually use.
ANIMAL=$(curl -s -b "$ADMIN" "$BASE/modules/milk/record.php" \
    | sed -n 's/.*<option value="\([0-9][0-9]*\)".*/\1/p' | head -1)
if [ -z "$ANIMAL" ]; then
    bad "resolve a farm-1 animal" "no animal options on the recording form"
else
    ok "resolved a farm-1 animal id=$ANIMAL"

    TOKEN=$(csrf_for "$BASE/modules/milk/record.php" "$ADMIN")
    curl -s -b "$ADMIN" -c "$ADMIN" -o "$TMP/rec.html" -X POST \
        -d "csrf_token=$TOKEN&cow_id=$ANIMAL&production_date=$TODAY&session=midday&quantity=7.25" \
        "$BASE/modules/milk/record.php"
    check_present "a milking is recorded" "$TMP/rec.html" "Recorded"

    # The uniqueness rule, over a real request.
    TOKEN=$(csrf_for "$BASE/modules/milk/record.php" "$ADMIN")
    curl -s -b "$ADMIN" -c "$ADMIN" -o "$TMP/dup.html" -X POST \
        -d "csrf_token=$TOKEN&cow_id=$ANIMAL&production_date=$TODAY&session=midday&quantity=9" \
        "$BASE/modules/milk/record.php"
    check_present "a duplicate session is refused" "$TMP/dup.html" "already exists"

    # A future date, over a real request.
    TOKEN=$(csrf_for "$BASE/modules/milk/record.php" "$ADMIN")
    FUTURE=$(date -u -d '+3 days' +%Y-%m-%d 2>/dev/null || date -u -v+3d +%Y-%m-%d)
    curl -s -b "$ADMIN" -c "$ADMIN" -o "$TMP/future.html" -X POST \
        -d "csrf_token=$TOKEN&cow_id=$ANIMAL&production_date=$FUTURE&session=evening&quantity=5" \
        "$BASE/modules/milk/record.php"
    check_present "a future date is refused" "$TMP/future.html" "future"

    # The recorded figure reaches the day view and the animal profile.
    curl -s -b "$ADMIN" -o "$TMP/day.html" "$BASE/modules/milk/?tab=daily&date=$TODAY"
    check_present "the recorded milking appears in the day view" "$TMP/day.html" "Midday"

    # ---- clean up, and exercise the delete path while doing it -------------
    #
    # This suite must be RE-RUNNABLE. Leaving the row behind would make the
    # second run trip the one-per-session uniqueness rule and, worse, shift the
    # farm-wide totals the PHPUnit analytics tests assert against — they share
    # this database. Removing it here is both the cleanup and real coverage of
    # milk.delete, which no other assertion reaches.
    YIELD=$(sed -n 's/.*yield\.php?id=\([0-9][0-9]*\)".*/\1/p' "$TMP/day.html" | head -1)
    if [ -z "$YIELD" ]; then
        bad "resolve the recorded yield id" "no edit link in the day view"
    else
        # staff holds milk.edit but NOT milk.delete — the narrower permission.
        TOKEN=$(csrf_for "$BASE/modules/milk/yield.php?id=$YIELD" "$STAFF")
        check_status "staff cannot delete a production record" \
            "$(curl -s -b "$STAFF" -c "$STAFF" -o /dev/null -w '%{http_code}' -X POST \
                -d "csrf_token=$TOKEN&action=delete" \
                "$BASE/modules/milk/yield.php?id=$YIELD")" "403"

        TOKEN=$(csrf_for "$BASE/modules/milk/yield.php?id=$YIELD" "$ADMIN")
        curl -s -b "$ADMIN" -c "$ADMIN" -o /dev/null -X POST \
            -d "csrf_token=$TOKEN&action=delete" "$BASE/modules/milk/yield.php?id=$YIELD"
        check_status "a farm manager can delete a production record" \
            "$(curl -s -b "$ADMIN" -o /dev/null -w '%{http_code}' \
                "$BASE/modules/milk/yield.php?id=$YIELD")" "404"
    fi
    curl -s -b "$ADMIN" -o "$TMP/animal.html" "$BASE/modules/cows/view.php?id=$ANIMAL&tab=production"
    check_status "the animal production tab renders" \
        "$(curl -s -b "$ADMIN" -o /dev/null -w '%{http_code}' \
            "$BASE/modules/cows/view.php?id=$ANIMAL&tab=production")" "200"
    check_absent "the animal production tab has no PHP errors" "$TMP/animal.html" "Fatal error"
fi

# ============================================================================
section "Cross-tenant writes are refused"
# ============================================================================
# A real farm-2 animal id, POSTed by a farm-1 user. The id is well-formed and
# exists — only the tenant lookup can catch it.
FOREIGN=$(curl -s -b "$RIVAL" "$BASE/modules/milk/record.php" \
    | sed -n 's/.*<option value="\([0-9][0-9]*\)".*/\1/p' | head -1)
if [ -z "$FOREIGN" ]; then
    bad "resolve a farm-2 animal" "no animal options for the rival user"
else
    ok "resolved a farm-2 animal id=$FOREIGN"
    TOKEN=$(csrf_for "$BASE/modules/milk/record.php" "$ADMIN")
    curl -s -b "$ADMIN" -c "$ADMIN" -o "$TMP/cross.html" -X POST \
        -d "csrf_token=$TOKEN&cow_id=$FOREIGN&production_date=$TODAY&session=evening&quantity=5" \
        "$BASE/modules/milk/record.php"
    check_present "a farm-2 animal is refused" "$TMP/cross.html" "not on this farm"
fi

# A POSTed farm_id must be IGNORED, never trusted. tenantInsert() supplies the
# farm from the session, so this extra field can only be inert.
TOKEN=$(csrf_for "$BASE/modules/feeding/rations.php" "$ADMIN")
curl -s -b "$ADMIN" -c "$ADMIN" -o "$TMP/tamper.html" -X POST \
    -d "csrf_token=$TOKEN&action=create&name=Tamper $$&farm_id=2&purpose=x" \
    "$BASE/modules/feeding/rations.php"
curl -s -b "$RIVAL" -o "$TMP/rival-rations.html" "$BASE/modules/feeding/rations.php"
check_absent "a POSTed farm_id cannot move a record to another farm" "$TMP/rival-rations.html" "Tamper"

# ============================================================================
section "Malformed and foreign resource ids"
# ============================================================================
for BADID in "abc" "0" "-1" "1%20OR%201=1" "99999999"; do
    check_status "malformed/unknown yield id '$BADID' is 404" \
        "$(curl -s -b "$ADMIN" -o /dev/null -w '%{http_code}' "$BASE/modules/milk/yield.php?id=$BADID")" "404"
done
check_status "malformed ration id is 404" \
    "$(curl -s -b "$ADMIN" -o /dev/null -w '%{http_code}' "$BASE/modules/feeding/rations.php?id=abc")" "404"
check_status "unknown ration id is 404" \
    "$(curl -s -b "$ADMIN" -o /dev/null -w '%{http_code}' "$BASE/modules/feeding/rations.php?id=99999999")" "404"

# ============================================================================
section "Feed consumption and the single stock ledger"
# ============================================================================
curl -s -b "$ADMIN" -o "$TMP/consume.html" "$BASE/modules/feeding/consume.php"
ITEM=$(sed -n 's/.*<option value="\([0-9][0-9]*\)".*/\1/p' "$TMP/consume.html" | head -1)
if [ -z "$ITEM" ]; then
    ok "no feed item configured on this farm — consumption path not exercised"
else
    ok "resolved a feed item id=$ITEM"
    TOKEN=$(csrf_for "$BASE/modules/feeding/consume.php" "$ADMIN")
    curl -s -b "$ADMIN" -c "$ADMIN" -o /dev/null -X POST \
        -d "csrf_token=$TOKEN&item_id=$ITEM&consumed_on=$TODAY&quantity=1&scope_type=farm" \
        "$BASE/modules/feeding/consume.php"
    curl -s -b "$ADMIN" -o "$TMP/used.html" "$BASE/modules/feeding/?tab=consumption"
    check_status "the consumption list renders after a deduction" \
        "$(curl -s -b "$ADMIN" -o /dev/null -w '%{http_code}' "$BASE/modules/feeding/?tab=consumption")" "200"
    check_absent "the consumption list has no PHP errors" "$TMP/used.html" "Fatal error"

    # Absurd quantity: refused by the stock floor, and nothing is written.
    TOKEN=$(csrf_for "$BASE/modules/feeding/consume.php" "$ADMIN")
    curl -s -b "$ADMIN" -c "$ADMIN" -o "$TMP/over.html" -X POST \
        -d "csrf_token=$TOKEN&item_id=$ITEM&consumed_on=$TODAY&quantity=999999&scope_type=farm" \
        "$BASE/modules/feeding/consume.php"
    check_present "over-consumption is refused" "$TMP/over.html" "Not enough stock"
fi

# ============================================================================
section "API — read-only, tenant-scoped"
# ============================================================================
curl -s -b "$ADMIN" -o "$TMP/api.json" "$API/v1/production"
check_present "production API returns a data envelope" "$TMP/api.json" '"data"'
check_present "production API reports the recorded block" "$TMP/api.json" '"recorded"'
check_present "production API reports the farm aggregate separately" "$TMP/api.json" '"farm_aggregate"'
check_present "production API states the canonical unit" "$TMP/api.json" '"unit"'
check_absent "production API does not leak farm_id" "$TMP/api.json" '"farm_id"'

check_status "production API rejects a write method" \
    "$(curl -s -b "$ADMIN" -o /dev/null -w '%{http_code}' -X POST "$API/v1/production")" "405"
check_status "production API requires authentication" \
    "$(curl -s -o /dev/null -w '%{http_code}' "$API/v1/production")" "401"

# accountant holds milk.view, so the endpoint is open to them.
check_status "accountant can read the production API" \
    "$(curl -s -b "$ACCT" -o /dev/null -w '%{http_code}' "$API/v1/production")" "200"

for BADID in "abc" "0" "-1" "99999999"; do
    check_status "production API rejects animal id '$BADID'" \
        "$(curl -s -b "$ADMIN" -o /dev/null -w '%{http_code}' "$API/v1/production/$BADID")" "404"
done
if [ -n "$FOREIGN" ]; then
    check_status "production API refuses a farm-2 animal" \
        "$(curl -s -b "$ADMIN" -o /dev/null -w '%{http_code}' "$API/v1/production/$FOREIGN")" "404"
fi
check_status "a deeper production path does not match" \
    "$(curl -s -b "$ADMIN" -o /dev/null -w '%{http_code}' "$API/v1/production/1/yields")" "404"

# No write surface was created for feeding.
check_status "no feed API endpoint exists" \
    "$(curl -s -b "$ADMIN" -o /dev/null -w '%{http_code}' "$API/v1/feed")" "404"
check_status "no feed-consumption API endpoint exists" \
    "$(curl -s -b "$ADMIN" -o /dev/null -w '%{http_code}' -X POST "$API/v1/feed-consumption")" "404"

# ============================================================================
section "Existing behaviour is unchanged"
# ============================================================================
curl -s -b "$ADMIN" -o "$TMP/dash.html" "$BASE/dashboard.php"
check_status "the dashboard still renders" \
    "$(curl -s -b "$ADMIN" -o /dev/null -w '%{http_code}' "$BASE/dashboard.php")" "200"
check_absent "the dashboard has no PHP errors" "$TMP/dash.html" "Fatal error"
check_present "the deterministic advisor still produces insights" "$TMP/dash.html" "Farm Advisor"

# The legacy farm-level pages are untouched and still work.
check_status "the legacy farm-total form still works" \
    "$(curl -s -b "$ADMIN" -o /dev/null -w '%{http_code}' "$BASE/modules/milk/add.php")" "200"
check_status "the legacy feeding schedule form still works" \
    "$(curl -s -b "$ADMIN" -o /dev/null -w '%{http_code}' "$BASE/modules/feeding/add.php")" "200"
check_status "the inventory module still works" \
    "$(curl -s -b "$ADMIN" -o /dev/null -w '%{http_code}' "$BASE/modules/inventory/")" "200"
check_status "the health module still works" \
    "$(curl -s -b "$ADMIN" -o /dev/null -w '%{http_code}' "$BASE/modules/health/")" "200"

echo ""
echo "================================"
echo " passed: $PASS   failed: $FAIL"
echo "================================"
[ "$FAIL" -eq 0 ] || exit 1
