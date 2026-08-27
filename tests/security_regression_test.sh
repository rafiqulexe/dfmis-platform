#!/usr/bin/env bash
# ============================================================================
# DFMIS security regression suite.
#
# One test per security fix made in Phase 1. Every case here failed (or was
# untested) before hardening; each maps to a finding id in
# docs/DFMIS_BASELINE_AUDIT.md.
#
# Requires the dev server and the seeded demo database:
#   php database/migrate.php
#   php database/seed_admin.php && php database/seed_demo_data.php
#   php -S localhost:8000 -t .
#   bash tests/security_regression_test.sh
# ============================================================================

BASE="${DFMS_BASE_URL:-http://localhost:8000}"
TMP=$(mktemp -d)
PASS=0
FAIL=0

cleanup() { rm -rf "$TMP"; }
trap cleanup EXIT

# ---- helpers ---------------------------------------------------------------

login() { # login <user> <pass> <cookiejar>
    local token
    token=$(curl -s -c "$3" "$BASE/index.php" | sed -n 's/.*name="csrf_token" value="\([^"]*\)".*/\1/p' | head -1)
    curl -s -b "$3" -c "$3" -X POST \
        -d "csrf_token=$token&username=$1&password=$2" \
        "$BASE/index.php" -o /dev/null
}

# Confirms a login actually succeeded, so a later "403" cannot pass for the
# wrong reason (an unauthenticated request would 302 instead).
assert_logged_in() { # assert_logged_in <label> <cookiejar>
    local code
    code=$(curl -s -b "$2" -o /dev/null -w "%{http_code}" "$BASE/dashboard.php")
    if [ "$code" = "200" ]; then
        ok "$1 is authenticated"
    else
        bad "$1 is authenticated" "expected 200 from dashboard, got $code"
    fi
}

csrf_for() { # csrf_for <url> <cookiejar>
    curl -s -b "$2" -c "$2" "$1" | sed -n 's/.*name="csrf_token" value="\([^"]*\)".*/\1/p' | head -1
}

ok()  { echo "  pass  $1"; PASS=$((PASS + 1)); }
bad() { echo "  FAIL  $1 — $2"; FAIL=$((FAIL + 1)); }

check_status() { # check_status <label> <actual> <expected>
    [ "$2" = "$3" ] && ok "$1 (HTTP $2)" || bad "$1" "expected HTTP $3, got $2"
}

check_absent() { # check_absent <label> <file> <needle>
    grep -qi "$3" "$2" && bad "$1" "leaked '$3'" || ok "$1"
}

check_present() { # check_present <label> <file> <needle>
    grep -qi "$3" "$2" && ok "$1" || bad "$1" "expected to find '$3'"
}

section() { echo ""; echo "== $1 =="; }

# Resolve fixture ids rather than hardcoding autoincrement values (F-4).
RIVAL_COW_ID="${DFMS_RIVAL_COW_ID:-7}"

# ============================================================================
section "Missing tenant context (C-1) — must be handled, never a fatal"
# ============================================================================
ORPHAN="$TMP/orphan.txt"
login orphan orphan123 "$ORPHAN"

CODE=$(curl -s -b "$ORPHAN" -o "$TMP/orphan.html" -w "%{http_code}" "$BASE/dashboard.php")
check_status "membership-less user gets a handled response" "$CODE" "403"
check_absent  "no TypeError leaked"        "$TMP/orphan.html" "TypeError"
check_absent  "no server path leaked"      "$TMP/orphan.html" "includes[\\/]insights.php"
check_absent  "no stack trace leaked"      "$TMP/orphan.html" "Stack trace"
check_present "explains the actual problem" "$TMP/orphan.html" "No farm assigned"

# A membership-less user must also be refused module data, not just the dashboard.
CODE=$(curl -s -b "$ORPHAN" -o /dev/null -w "%{http_code}" "$BASE/modules/cows/")
check_status "membership-less user blocked from cow list" "$CODE" "403"

# ============================================================================
section "Cross-ORGANIZATION isolation (rival owns org 2 / farm 2)"
# ============================================================================
RIVAL="$TMP/rival.txt"
login rival rival123 "$RIVAL"
assert_logged_in "rival" "$RIVAL"

# Farm 1's animals belong to organization 1. The rival is an org-2 owner —
# the highest role available — and must still see nothing of org 1.
for id in 1 2 3 4 5 6; do
    CODE=$(curl -s -b "$RIVAL" -o /dev/null -w "%{http_code}" "$BASE/modules/cows/view.php?id=$id")
    if [ "$CODE" != "404" ]; then
        bad "org-2 owner blocked from org-1 cow #$id" "expected 404, got $CODE"
        break
    fi
done
[ "$CODE" = "404" ] && ok "org-2 owner blocked from every org-1 animal (404)"

curl -s -b "$RIVAL" -o "$TMP/rivallist.html" "$BASE/modules/cows/"
check_absent "org-2 cow list excludes org-1 animals" "$TMP/rivallist.html" "BD003"

curl -s -b "$RIVAL" -o "$TMP/rivalmilk.html" "$BASE/modules/milk/"
check_present "org-2 user sees their own (empty) milk view" "$TMP/rivalmilk.html" "Milk Production"

# ============================================================================
section "Cross-FARM isolation (farm-1 admin vs farm-2 data)"
# ============================================================================
JAR="$TMP/admin.txt"
login admin admin123 "$JAR"
assert_logged_in "admin" "$JAR"

CODE=$(curl -s -b "$JAR" -o "$TMP/view.html" -w "%{http_code}" "$BASE/modules/cows/view.php?id=$RIVAL_COW_ID")
check_status "view.php cross-farm rejected" "$CODE" "404"
check_absent "view.php body has no foreign data" "$TMP/view.html" "RIVAL001"

CODE=$(curl -s -b "$JAR" -o /dev/null -w "%{http_code}" "$BASE/modules/cows/edit.php?id=$RIVAL_COW_ID")
check_status "edit.php cross-farm rejected" "$CODE" "404"

CODE=$(curl -s -b "$JAR" -o /dev/null -w "%{http_code}" "$BASE/modules/cows/delete.php?id=$RIVAL_COW_ID")
check_status "delete.php cross-farm rejected" "$CODE" "404"

# ============================================================================
section "Manipulated / malformed IDs must never reach a query"
# ============================================================================
# Every probe must be fully URL-encoded — a literal space makes curl refuse the
# URL outright (HTTP 000), which would look like a failure without ever having
# sent a request.
for probe in "1%20OR%201=1" "-1" "0" "abc" "1.5" "%27" "1%3B%20DROP%20TABLE%20cows" "999999999999999999999" "null" ""; do
    CODE=$(curl -s -b "$JAR" -o /dev/null -w "%{http_code}" "$BASE/modules/cows/view.php?id=$probe")
    if [ "$CODE" != "404" ]; then
        bad "malformed id '$probe' rejected" "expected 404, got $CODE"
        BADPROBE=1
    fi
done
[ -z "$BADPROBE" ] && ok "all 10 malformed id probes return 404"

# The table must still exist after the injection probes.
CODE=$(curl -s -b "$JAR" -o /dev/null -w "%{http_code}" "$BASE/modules/cows/")
check_status "cow list still works after injection probes" "$CODE" "200"

# ============================================================================
section "IDOR through POST bodies (foreign key smuggling)"
# ============================================================================
TOKEN=$(csrf_for "$BASE/modules/health/add.php" "$JAR")
curl -s -b "$JAR" -X POST \
    -d "csrf_token=$TOKEN&cow_id=$RIVAL_COW_ID&event_date=2026-01-01&event_type=Treatment&severity=urgent&description=idor-probe" \
    "$BASE/modules/health/add.php" -o "$TMP/post.html"
check_absent "foreign cow_id in POST rejected" "$TMP/post.html" "added successfully"

# The rival must not be able to acknowledge a farm-1 alert.
# The token is taken from alerts/create.php, which always renders a form for a
# farm_manager. The alerts LIST only renders a CSRF field when unread alerts
# exist, and farm 2 has none — scraping it would yield an empty token and the
# request would be refused by CSRF (403) before ever reaching the tenant check,
# testing the wrong control.
RTOKEN=$(csrf_for "$BASE/modules/alerts/create.php" "$RIVAL")
if [ -z "$RTOKEN" ]; then
    bad "cross-farm alert acknowledge rejected" "could not obtain a CSRF token for the rival user"
else
    CODE=$(curl -s -b "$RIVAL" -X POST \
        -d "csrf_token=$RTOKEN&scope=one&alert_id=1" \
        "$BASE/modules/alerts/read.php" -o /dev/null -w "%{http_code}")
    check_status "cross-farm alert acknowledge rejected" "$CODE" "404"
fi

# ============================================================================
section "CSRF enforcement on state-changing endpoints"
# ============================================================================
CODE=$(curl -s -b "$JAR" -X POST -d "unique_id=CSRFPROBE" \
    "$BASE/modules/cows/add.php" -o /dev/null -w "%{http_code}")
check_status "cows/add.php without CSRF token" "$CODE" "403"

CODE=$(curl -s -b "$JAR" -X POST -d "scope=all" \
    "$BASE/modules/alerts/read.php" -o /dev/null -w "%{http_code}")
check_status "alerts/read.php without CSRF token" "$CODE" "403"

CODE=$(curl -s -b "$JAR" -X POST -d "csrf_token=deadbeef&unique_id=CSRFPROBE2" \
    "$BASE/modules/cows/add.php" -o /dev/null -w "%{http_code}")
check_status "cows/add.php with a forged CSRF token" "$CODE" "403"

# ============================================================================
section "Method enforcement — mutations must not be reachable by GET"
# ============================================================================
CODE=$(curl -s -b "$JAR" -o /dev/null -w "%{http_code}" "$BASE/modules/alerts/read.php?scope=all")
check_status "alerts/read.php rejects GET" "$CODE" "405"

CODE=$(curl -s -b "$JAR" -o /dev/null -w "%{http_code}" "$BASE/modules/farm/switch.php?farm_id=2")
check_status "farm/switch.php rejects GET" "$CODE" "405"

# ============================================================================
section "Farm switching cannot cross a tenant boundary (D-2)"
# ============================================================================
STOKEN=$(csrf_for "$BASE/dashboard.php" "$JAR")
CODE=$(curl -s -b "$JAR" -X POST -d "csrf_token=$STOKEN&farm_id=2" \
    "$BASE/modules/farm/switch.php" -o /dev/null -w "%{http_code}")
check_status "admin cannot switch to a farm they don't belong to" "$CODE" "403"

# And having tried, they must still be scoped to farm 1.
curl -s -b "$JAR" -o "$TMP/afterswitch.html" "$BASE/modules/cows/"
check_absent "still scoped to farm 1 after failed switch" "$TMP/afterswitch.html" "RIVAL001"

# ============================================================================
section "RBAC — server-side enforcement, not UI hiding"
# ============================================================================
VET="$TMP/vet.txt"
login manager manager123 "$VET"
assert_logged_in "manager (vet)" "$VET"
check_status "vet blocked from cows/add.php"    "$(curl -s -b "$VET" -o /dev/null -w "%{http_code}" "$BASE/modules/cows/add.php")" "403"
check_status "vet blocked from cows/delete.php" "$(curl -s -b "$VET" -o /dev/null -w "%{http_code}" "$BASE/modules/cows/delete.php?id=1")" "403"
check_status "vet blocked from audit log"       "$(curl -s -b "$VET" -o /dev/null -w "%{http_code}" "$BASE/modules/audit/")" "403"
check_status "vet allowed health/add.php"       "$(curl -s -b "$VET" -o /dev/null -w "%{http_code}" "$BASE/modules/health/add.php")" "200"

STAFF="$TMP/staff.txt"
login staff staff123 "$STAFF"
assert_logged_in "staff" "$STAFF"
check_status "staff blocked from cows/delete.php" "$(curl -s -b "$STAFF" -o /dev/null -w "%{http_code}" "$BASE/modules/cows/delete.php?id=1")" "403"
check_status "staff blocked from health/add.php"  "$(curl -s -b "$STAFF" -o /dev/null -w "%{http_code}" "$BASE/modules/health/add.php")" "403"
check_status "staff blocked from audit log"       "$(curl -s -b "$STAFF" -o /dev/null -w "%{http_code}" "$BASE/modules/audit/")" "403"
check_status "staff allowed cows/add.php"         "$(curl -s -b "$STAFF" -o /dev/null -w "%{http_code}" "$BASE/modules/cows/add.php")" "200"

VIEWER="$TMP/viewer.txt"
login viewer viewer123 "$VIEWER"
assert_logged_in "viewer" "$VIEWER"
check_status "viewer blocked from cows/add.php"   "$(curl -s -b "$VIEWER" -o /dev/null -w "%{http_code}" "$BASE/modules/cows/add.php")" "403"
check_status "viewer blocked from milk/add.php"   "$(curl -s -b "$VIEWER" -o /dev/null -w "%{http_code}" "$BASE/modules/milk/add.php")" "403"
check_status "viewer blocked from alerts/create"  "$(curl -s -b "$VIEWER" -o /dev/null -w "%{http_code}" "$BASE/modules/alerts/create.php")" "403"
check_status "viewer allowed cows list"           "$(curl -s -b "$VIEWER" -o /dev/null -w "%{http_code}" "$BASE/modules/cows/")" "200"

ACC="$TMP/acc.txt"
login accountant accountant123 "$ACC"
assert_logged_in "accountant" "$ACC"
check_status "accountant blocked from health view" "$(curl -s -b "$ACC" -o /dev/null -w "%{http_code}" "$BASE/modules/health/")" "403"
check_status "accountant allowed milk view"        "$(curl -s -b "$ACC" -o /dev/null -w "%{http_code}" "$BASE/modules/milk/")" "200"

# ============================================================================
section "Input validation (C-6, C-7, C-8)"
# ============================================================================
TOKEN=$(csrf_for "$BASE/modules/cows/add.php" "$JAR")
curl -s -b "$JAR" -X POST -d "csrf_token=$TOKEN&unique_id=&date_of_birth=2020-01-01" \
    "$BASE/modules/cows/add.php" -o "$TMP/v1.html"
check_absent "blank animal tag rejected" "$TMP/v1.html" "added successfully"

TOKEN=$(csrf_for "$BASE/modules/cows/add.php" "$JAR")
curl -s -b "$JAR" -X POST -d "csrf_token=$TOKEN&unique_id=VALIDATE1&date_of_birth=2024-02-31" \
    "$BASE/modules/cows/add.php" -o "$TMP/v2.html"
check_absent "impossible date (Feb 31) rejected" "$TMP/v2.html" "added successfully"

TOKEN=$(csrf_for "$BASE/modules/cows/add.php" "$JAR")
curl -s -b "$JAR" -X POST -d "csrf_token=$TOKEN&unique_id=VALIDATE2&date_of_birth=2099-01-01" \
    "$BASE/modules/cows/add.php" -o "$TMP/v3.html"
check_absent "future birth date rejected" "$TMP/v3.html" "added successfully"

TOKEN=$(csrf_for "$BASE/modules/milk/add.php" "$JAR")
curl -s -b "$JAR" -X POST -d "csrf_token=$TOKEN&production_date=2026-08-01&total_milk_yield=-50" \
    "$BASE/modules/milk/add.php" -o "$TMP/v4.html"
check_absent "negative milk yield rejected" "$TMP/v4.html" "added successfully"

TOKEN=$(csrf_for "$BASE/modules/milk/add.php" "$JAR")
curl -s -b "$JAR" -X POST -d "csrf_token=$TOKEN&production_date=2026-08-02&total_milk_yield=notanumber" \
    "$BASE/modules/milk/add.php" -o "$TMP/v5.html"
check_absent "non-numeric milk yield rejected" "$TMP/v5.html" "added successfully"

TOKEN=$(csrf_for "$BASE/modules/health/add.php" "$JAR")
curl -s -b "$JAR" -X POST \
    -d "csrf_token=$TOKEN&cow_id=1&event_date=2026-01-01&event_type=Checkup&severity=catastrophic" \
    "$BASE/modules/health/add.php" -o "$TMP/v6.html"
check_absent "invalid severity enum rejected" "$TMP/v6.html" "added successfully"

# C-7: an unrecognised adjustment type used to silently SUBTRACT stock.
INV_ID=$(curl -s -b "$JAR" "$BASE/modules/inventory/" | sed -n 's/.*update\.php?id=\([0-9]*\).*/\1/p' | head -1)
if [ -n "$INV_ID" ]; then
    TOKEN=$(csrf_for "$BASE/modules/inventory/update.php?id=$INV_ID" "$JAR")
    curl -s -b "$JAR" -X POST -d "csrf_token=$TOKEN&adjustment_type=bogus&adjustment_amount=5" \
        "$BASE/modules/inventory/update.php?id=$INV_ID" -o "$TMP/v7.html"
    check_absent "unknown adjustment_type rejected" "$TMP/v7.html" "updated successfully"
else
    bad "unknown adjustment_type rejected" "could not resolve an inventory id"
fi

# ============================================================================
section "Error handling — no internals ever reach the browser (S-6)"
# ============================================================================
curl -s -b "$JAR" -o "$TMP/e1.html" "$BASE/modules/cows/view.php?id=999999"
check_absent "404 page leaks no path"       "$TMP/e1.html" "D:[\\/]Projects"
check_absent "404 page leaks no SQL"        "$TMP/e1.html" "SELECT"
check_absent "404 page leaks no stack trace" "$TMP/e1.html" "Stack trace"

# ============================================================================
section "Session cookie hardening (S-3)"
# ============================================================================
curl -s -D "$TMP/headers.txt" -o /dev/null "$BASE/index.php"
check_present "session cookie is HttpOnly" "$TMP/headers.txt" "HttpOnly"
check_present "session cookie is SameSite" "$TMP/headers.txt" "SameSite"

# ============================================================================
section "Host header cannot be reflected into redirects (S-1)"
# ============================================================================
# Grep ONLY the Location header. PHP's built-in dev server echoes the request
# Host back as a response header, which Apache and nginx do not — scanning the
# whole dump would flag that echo as an application leak.
curl -s -D - -o /dev/null -H "Host: evil.example.com" "$BASE/dashboard.php" \
    | grep -i '^Location:' > "$TMP/hostloc.txt"
check_absent  "Location header never contains an unlisted host" "$TMP/hostloc.txt" "evil.example.com"
check_present "Location falls back to an allow-listed host"     "$TMP/hostloc.txt" "localhost"

# ============================================================================
section "Unauthenticated access"
# ============================================================================
check_status "anonymous dashboard redirected"  "$(curl -s -o /dev/null -w "%{http_code}" "$BASE/dashboard.php")" "302"
check_status "anonymous cow list redirected"   "$(curl -s -o /dev/null -w "%{http_code}" "$BASE/modules/cows/")" "302"
check_status "anonymous audit log redirected"  "$(curl -s -o /dev/null -w "%{http_code}" "$BASE/modules/audit/")" "302"
check_status "anonymous file serve redirected" "$(curl -s -o /dev/null -w "%{http_code}" "$BASE/modules/files/serve.php?id=1")" "302"

echo ""
echo "================================"
echo " passed: $PASS   failed: $FAIL"
echo "================================"
[ "$FAIL" -eq 0 ]
