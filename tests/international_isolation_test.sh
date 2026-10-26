#!/usr/bin/env bash
# ============================================================================
# Phase 2 HTTP isolation suite — settings, features and notifications.
#
# The unit suite proves the RESOLVERS are correct. This suite proves the
# SURFACES are safe: that one organization cannot reach another's settings,
# that unauthorized roles cannot write configuration, and that the existing
# alert system still works alongside the new notification preferences.
#
#   bash tests/international_isolation_test.sh
# ============================================================================

BASE="${DFMS_BASE_URL:-http://localhost:8000}"
TMP=$(mktemp -d)
PASS=0
FAIL=0

cleanup() { rm -rf "$TMP"; }
trap cleanup EXIT

login() { # login <user> <pass> <cookiejar>
    local token
    token=$(curl -s -c "$3" "$BASE/index.php" | sed -n 's/.*name="csrf_token" value="\([^"]*\)".*/\1/p' | head -1)
    curl -s -b "$3" -c "$3" -X POST -d "csrf_token=$token&username=$1&password=$2" "$BASE/index.php" -o /dev/null
}

csrf_for() { curl -s -b "$2" -c "$2" "$1" | sed -n 's/.*name="csrf_token" value="\([^"]*\)".*/\1/p' | head -1; }

ok()  { echo "  pass  $1"; PASS=$((PASS + 1)); }
bad() { echo "  FAIL  $1 — $2"; FAIL=$((FAIL + 1)); }

check_status() { [ "$2" = "$3" ] && ok "$1 (HTTP $2)" || bad "$1" "expected HTTP $3, got $2"; }
check_absent()  { grep -qi "$3" "$2" && bad "$1" "leaked '$3'" || ok "$1"; }
check_present() { grep -qi "$3" "$2" && ok "$1" || bad "$1" "expected to find '$3'"; }
section() { echo ""; echo "== $1 =="; }

# Literal (non-regex) variants, for needles read from the database rather than
# written in this file.
absent_literal()  { grep -Fqi "$3" "$2" && bad "$1" "leaked '$3'" || ok "$1"; }
present_literal() { grep -Fqi "$3" "$2" && ok "$1" || bad "$1" "expected to find '$3'"; }

# Tenant 1's own names, read from the running application. This suite runs
# against the DEVELOPMENT database, where an operator may rename their farm or
# organization through the UI at any time; hardcoding the seed names turns an
# ordinary rename into a false isolation failure.
# The value attribute sits on the line after the id, so the markup is flattened
# before matching. The name comes back HTML-escaped, which is correct: it is
# compared against rendered pages.
settings_name_of() { # settings_name_of <url> <cookiejar>
    curl -s -b "$2" "$1" | tr '\n' ' ' | sed -n 's/.*id="name"[^>]*value="\([^"]*\)".*/\1/p' | head -1
}

assert_logged_in() {
    local code
    code=$(curl -s -b "$2" -o /dev/null -w "%{http_code}" "$BASE/dashboard.php")
    [ "$code" = "200" ] && ok "$1 is authenticated" || bad "$1 is authenticated" "dashboard returned $code"
}

ADMIN="$TMP/admin.txt";  login admin admin123 "$ADMIN"
RIVAL="$TMP/rival.txt";  login rival rival123 "$RIVAL"
STAFF="$TMP/staff.txt";  login staff staff123 "$STAFF"
VIEWER="$TMP/viewer.txt"; login viewer viewer123 "$VIEWER"
ORPHAN="$TMP/orphan.txt"; login orphan orphan123 "$ORPHAN"

# ============================================================================
section "Settings pages reachable and authorization enforced"
# ============================================================================
assert_logged_in "admin" "$ADMIN"
assert_logged_in "rival" "$RIVAL"

check_status "admin can view the settings hub"        "$(curl -s -b "$ADMIN" -o /dev/null -w '%{http_code}' "$BASE/modules/settings/")" "200"
check_status "admin (org owner) can view org settings" "$(curl -s -b "$ADMIN" -o /dev/null -w '%{http_code}' "$BASE/modules/settings/organization.php")" "200"
check_status "admin can view farm settings"           "$(curl -s -b "$ADMIN" -o /dev/null -w '%{http_code}' "$BASE/modules/settings/farm.php")" "200"

# A viewer may SEE the resolved configuration but must not reach any editor.
check_status "viewer can view the settings hub"        "$(curl -s -b "$VIEWER" -o /dev/null -w '%{http_code}' "$BASE/modules/settings/")" "200"
check_status "viewer blocked from farm settings"       "$(curl -s -b "$VIEWER" -o /dev/null -w '%{http_code}' "$BASE/modules/settings/farm.php")" "403"
check_status "viewer blocked from org settings"        "$(curl -s -b "$VIEWER" -o /dev/null -w '%{http_code}' "$BASE/modules/settings/organization.php")" "403"
check_status "staff blocked from farm settings"        "$(curl -s -b "$STAFF" -o /dev/null -w '%{http_code}' "$BASE/modules/settings/farm.php")" "403"
check_status "staff blocked from org settings"         "$(curl -s -b "$STAFF" -o /dev/null -w '%{http_code}' "$BASE/modules/settings/organization.php")" "403"
check_status "staff blocked from feature config"       "$(curl -s -b "$STAFF" -o /dev/null -w '%{http_code}' "$BASE/modules/settings/features.php")" "403"

# Membership-less user: handled, not a crash.
check_status "membership-less user blocked from settings" "$(curl -s -b "$ORPHAN" -o /dev/null -w '%{http_code}' "$BASE/modules/settings/")" "403"

# Anonymous.
check_status "anonymous settings redirected"  "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/modules/settings/")" "302"
check_status "anonymous org settings redirected" "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/modules/settings/organization.php")" "302"

# ============================================================================
section "Cross-ORGANIZATION settings isolation"
# ============================================================================
# rival owns organization 2. Their settings page must show THEIR organization
# and never organization 1's name or configuration.
ORG1=$(settings_name_of "$BASE/modules/settings/organization.php" "$ADMIN")
FARM1=$(settings_name_of "$BASE/modules/settings/farm.php" "$ADMIN")
[ -n "$ORG1" ]  && ok "organization 1 names itself ($ORG1)" \
                || bad "organization 1 names itself" "no name rendered on the org settings page"
[ -n "$FARM1" ] && ok "farm 1 names itself ($FARM1)" \
                || bad "farm 1 names itself" "no name rendered on the farm settings page"

curl -s -b "$RIVAL" -o "$TMP/rivalorg.html" "$BASE/modules/settings/organization.php"
absent_literal "rival's org page does not show org 1's name" "$TMP/rivalorg.html" "$ORG1"
check_present  "rival's org page shows their own org"        "$TMP/rivalorg.html" "Rival Holdings"

curl -s -b "$ADMIN" -o "$TMP/adminorg.html" "$BASE/modules/settings/organization.php"
check_absent   "admin's org page does not show org 2's name" "$TMP/adminorg.html" "Rival Holdings"
present_literal "admin's org page shows their own org"       "$TMP/adminorg.html" "$ORG1"

# A write from rival must land on organization 2 only. The organization id is
# never read from the request, so there is no parameter to tamper with — this
# asserts that adding one has no effect.
RTOKEN=$(csrf_for "$BASE/modules/settings/organization.php" "$RIVAL")
curl -s -b "$RIVAL" -X POST \
    -d "csrf_token=$RTOKEN&name=Rival Holdings (test fixture)&legal_name=&country_code=US&timezone=America/New_York&locale=en&currency=USD&measurement_system=imperial&organization_id=1&org_id=1" \
    "$BASE/modules/settings/organization.php" -o /dev/null

# Organization 1 must be untouched by that write.
#
# The assertion must look for the SELECTED marker, not the bare string: the
# timezone <select> renders all ~419 IANA zones as options, so every zone name
# appears in the page whether or not it is the stored value.
curl -s -b "$ADMIN" -o "$TMP/org1after.html" "$BASE/modules/settings/organization.php"
check_absent "org 1 timezone not changed by a crafted organization_id" "$TMP/org1after.html" 'value="America/New_York" selected'
check_absent "org 1 currency not changed by a crafted organization_id"  "$TMP/org1after.html" 'value="USD" selected'
check_absent "org 1 country not changed by a crafted organization_id"   "$TMP/org1after.html" 'value="US" selected'

# And the write must have landed on the RIVAL's organization — proving the
# request was scoped, not merely discarded.
curl -s -b "$RIVAL" -o "$TMP/rivalorgafter.html" "$BASE/modules/settings/organization.php"
check_present "rival's own org received the write" "$TMP/rivalorgafter.html" 'value="America/New_York" selected'

# ============================================================================
section "Cross-FARM settings isolation"
# ============================================================================
curl -s -b "$RIVAL" -o "$TMP/rivalfarm.html" "$BASE/modules/settings/farm.php"
absent_literal "rival's farm page does not show farm 1" "$TMP/rivalfarm.html" "$FARM1"
check_present  "rival's farm page shows their own farm" "$TMP/rivalfarm.html" "Rival Farm"

# A crafted farm_id must not redirect the write to another farm.
RTOKEN=$(csrf_for "$BASE/modules/settings/farm.php" "$RIVAL")
curl -s -b "$RIVAL" -X POST \
    -d "csrf_token=$RTOKEN&name=Rival Farm (isolation test)&country_code=&timezone=Pacific/Auckland&locale=&currency=&measurement_system=&farm_id=1" \
    "$BASE/modules/settings/farm.php" -o /dev/null

curl -s -b "$ADMIN" -o "$TMP/farm1after.html" "$BASE/modules/settings/farm.php"
check_absent "farm 1 timezone not changed by a crafted farm_id" "$TMP/farm1after.html" 'value="Pacific/Auckland" selected'

# The write landed on the rival's own farm, confirming it was rescoped rather
# than dropped.
curl -s -b "$RIVAL" -o "$TMP/rivalfarmafter.html" "$BASE/modules/settings/farm.php"
check_present "rival's own farm received the write" "$TMP/rivalfarmafter.html" 'value="Pacific/Auckland" selected'

# ============================================================================
section "Settings validation is server-side"
# ============================================================================
ATOKEN=$(csrf_for "$BASE/modules/settings/farm.php" "$ADMIN")
curl -s -b "$ADMIN" -X POST \
    -d "csrf_token=$ATOKEN&name=Green Valley Dairy Farm&country_code=ZZ&timezone=&locale=&currency=&measurement_system=" \
    "$BASE/modules/settings/farm.php" -o "$TMP/badcountry.html"
check_present "invalid country code rejected" "$TMP/badcountry.html" "valid country"

ATOKEN=$(csrf_for "$BASE/modules/settings/farm.php" "$ADMIN")
curl -s -b "$ADMIN" -X POST \
    -d "csrf_token=$ATOKEN&name=Green Valley Dairy Farm&country_code=&timezone=Mars/Olympus&locale=&currency=&measurement_system=" \
    "$BASE/modules/settings/farm.php" -o "$TMP/badtz.html"
check_present "invalid timezone rejected" "$TMP/badtz.html" "valid timezone"

ATOKEN=$(csrf_for "$BASE/modules/settings/farm.php" "$ADMIN")
curl -s -b "$ADMIN" -X POST \
    -d "csrf_token=$ATOKEN&name=Green Valley Dairy Farm&country_code=&timezone=&locale=&currency=XXX&measurement_system=" \
    "$BASE/modules/settings/farm.php" -o "$TMP/badcur.html"
check_present "invalid currency rejected" "$TMP/badcur.html" "ISO 4217"

ATOKEN=$(csrf_for "$BASE/modules/settings/farm.php" "$ADMIN")
curl -s -b "$ADMIN" -X POST \
    -d "csrf_token=$ATOKEN&name=Green Valley Dairy Farm&country_code=&timezone=&locale=&currency=&measurement_system=nautical" \
    "$BASE/modules/settings/farm.php" -o "$TMP/badunit.html"
check_present "invalid measurement system rejected" "$TMP/badunit.html" "measurement system"

# CSRF must be enforced on every settings write.
check_status "farm settings without CSRF" "$(curl -s -b "$ADMIN" -X POST -d 'name=x' "$BASE/modules/settings/farm.php" -o /dev/null -w '%{http_code}')" "403"
check_status "org settings without CSRF"  "$(curl -s -b "$ADMIN" -X POST -d 'name=x' "$BASE/modules/settings/organization.php" -o /dev/null -w '%{http_code}')" "403"
check_status "features without CSRF"      "$(curl -s -b "$ADMIN" -X POST -d 'flag_key=future_iot&scope=farm&state=on' "$BASE/modules/settings/features.php" -o /dev/null -w '%{http_code}')" "403"

# ============================================================================
section "Feature flags — authorization and tenant scope"
# ============================================================================
check_status "admin can view feature config" "$(curl -s -b "$ADMIN" -o /dev/null -w '%{http_code}' "$BASE/modules/settings/features.php")" "200"
check_status "viewer blocked from feature config" "$(curl -s -b "$VIEWER" -o /dev/null -w '%{http_code}' "$BASE/modules/settings/features.php")" "403"

# An unregistered flag key is a tampered request, not a user error.
ATOKEN=$(csrf_for "$BASE/modules/settings/features.php" "$ADMIN")
check_status "unregistered flag key rejected" \
    "$(curl -s -b "$ADMIN" -X POST -d "csrf_token=$ATOKEN&flag_key=made_up_flag&scope=farm&state=on" "$BASE/modules/settings/features.php" -o /dev/null -w '%{http_code}')" "400"

# A flag set by rival must not appear for admin.
RTOKEN=$(csrf_for "$BASE/modules/settings/features.php" "$RIVAL")
curl -s -b "$RIVAL" -X POST -d "csrf_token=$RTOKEN&flag_key=future_iot&scope=farm&state=on" "$BASE/modules/settings/features.php" -o /dev/null
curl -s -b "$ADMIN" -o "$TMP/adminflags.html" "$BASE/modules/settings/features.php"
# admin's IoT row must still read Disabled — rival's farm-scope flag is invisible here.
if grep -A4 "IoT & Sensor Ingestion" "$TMP/adminflags.html" | grep -qi "Enabled"; then
    bad "rival's farm flag does not leak to admin" "admin sees IoT as enabled"
else
    ok "rival's farm flag does not leak to admin"
fi

# ============================================================================
section "Notification preferences — scoped to the user"
# ============================================================================
check_status "admin can view notification preferences" "$(curl -s -b "$ADMIN" -o /dev/null -w '%{http_code}' "$BASE/modules/settings/notifications.php")" "200"
check_status "viewer can view their own preferences"   "$(curl -s -b "$VIEWER" -o /dev/null -w '%{http_code}' "$BASE/modules/settings/notifications.php")" "200"
check_status "notifications without CSRF"              "$(curl -s -b "$ADMIN" -X POST -d 'x=1' "$BASE/modules/settings/notifications.php" -o /dev/null -w '%{http_code}')" "403"

curl -s -b "$ADMIN" -o "$TMP/notif.html" "$BASE/modules/settings/notifications.php"
check_present "unavailable channels are labelled honestly" "$TMP/notif.html" "soon"

# ============================================================================
section "Existing alert system still works alongside notifications"
# ============================================================================
check_status "alerts list still reachable" "$(curl -s -b "$ADMIN" -o /dev/null -w '%{http_code}' "$BASE/modules/alerts/")" "200"
curl -s -b "$ADMIN" -o "$TMP/alerts.html" "$BASE/modules/alerts/"
check_present "alerts still render their content" "$TMP/alerts.html" "Alert"

# Mark-all-read (Phase 1 behaviour) must be unaffected by Phase 2.
ATOKEN=$(csrf_for "$BASE/modules/alerts/" "$ADMIN")
if [ -n "$ATOKEN" ]; then
    check_status "alert acknowledge still works" \
        "$(curl -s -b "$ADMIN" -X POST -d "csrf_token=$ATOKEN&scope=all" "$BASE/modules/alerts/read.php" -o /dev/null -w '%{http_code}')" "302"
else
    ok "alert acknowledge skipped (no unread alerts to clear)"
fi

# ============================================================================
section "Subscription does not restrict existing users"
# ============================================================================
# Every module an existing user could reach before Phase 2 must still be
# reachable. A subscription foundation that quietly gated access would be a
# regression, not a feature.
for path in dashboard.php modules/cows/ modules/milk/ modules/health/ modules/feeding/ modules/inventory/ modules/alerts/; do
    CODE=$(curl -s -b "$ADMIN" -o /dev/null -w '%{http_code}' "$BASE/$path")
    if [ "$CODE" != "200" ]; then
        bad "existing access preserved: $path" "expected 200, got $CODE"
        RESTRICTED=1
    fi
done
[ -z "$RESTRICTED" ] && ok "all pre-Phase-2 modules remain accessible"

curl -s -b "$ADMIN" -o "$TMP/hub.html" "$BASE/modules/settings/"
check_present "subscription status is displayed" "$TMP/hub.html" "Subscription"
check_present "limits are stated as unenforced"  "$TMP/hub.html" "not enforced"

echo ""
echo "================================"
echo " passed: $PASS   failed: $FAIL"
echo "================================"
[ "$FAIL" -eq 0 ]
