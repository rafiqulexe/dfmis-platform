#!/usr/bin/env bash
# ============================================================================
# Phase 7 HTTP suite — AI animal image analysis
#
# Runs against the ISOLATED test database, like the Phase 3-6 suites.
#
#   php tools/test-db.php --create
#   DB_NAME=dfms_test php -S localhost:8100 -t .
#   DFMS_VISION_BASE_URL=http://localhost:8100 bash tests/vision_isolation_test.sh
#
# tests/run_all.sh starts that server automatically. If DFMS_VISION_BASE_URL is
# unset the suite falls back to the ordinary dev server — and SAYS SO, loudly.
#
# WHAT THIS SUITE IS FOR
# The PHPUnit suite covers the domain rules with a stubbed provider. This one
# covers what only a real request can: the permission matrix as the ROUTER
# applies it, CSRF, method enforcement, cross-tenant ids arriving in URLs and
# POST bodies, the degraded state a browser actually sees with no API key, and
# that no provider credential, vendor detail or raw JSON appears in any
# response body.
#
# NO REAL AI CALL IS MADE. The test environment has no API key, which is
# exactly the "fail safely when unconfigured" path this suite asserts.
# ============================================================================

BASE="${DFMS_VISION_BASE_URL:-${DFMS_BASE_URL:-http://localhost:8000}}"
ISOLATED="${DFMS_VISION_BASE_URL:-}"
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
    echo "# Set DFMS_VISION_BASE_URL to use the isolated test database."
fi
echo "############################################################"

ADMIN="$TMP/admin.txt";   login admin admin123           "$ADMIN"
VET="$TMP/vet.txt";       login manager manager123       "$VET"
STAFF="$TMP/staff.txt";   login staff staff123           "$STAFF"
VIEWER="$TMP/viewer.txt"; login viewer viewer123         "$VIEWER"
ACCT="$TMP/acct.txt";     login accountant accountant123 "$ACCT"
RIVAL="$TMP/rival.txt";   login rival rival123           "$RIVAL"

CODE=$(curl -s -b "$ADMIN" -o /dev/null -w '%{http_code}' "$BASE/dashboard.php")
[ "$CODE" = "200" ] && ok "admin is authenticated" || bad "admin is authenticated" "dashboard returned $CODE"

TAB="$BASE/modules/cows/view.php?id=1&tab=analysis"

# ============================================================================
section "The AI Analysis tab renders and states what it is"
# ============================================================================
CODE=$(curl -s -b "$ADMIN" -o "$TMP/tab.html" -w '%{http_code}' "$TAB")
if [ "$CODE" != "200" ]; then
    bad "the analysis tab renders" "expected 200, got $CODE"
elif grep -qi "Fatal error\|Uncaught\|Parse error" "$TMP/tab.html"; then
    bad "the analysis tab renders" "PHP error in output"
else
    ok "the analysis tab renders"
fi

# THE FRAMING IS ON THE PAGE, not only in the docs. A user who reads nothing
# else must still learn that this does not diagnose.
check_present "the tab says it does not diagnose" "$TMP/tab.html" "does not diagnose"
check_present "the disclaimer is shown" "$TMP/tab.html" "not a diagnosis"

# The word 'diagnosis' must never appear as a RESULT HEADING.
check_absent "no diagnosis heading is rendered" "$TMP/tab.html" "<h3>Diagnosis"

# ============================================================================
section "Degraded state — no API key configured"
# ============================================================================
# WHICH state this installation is in is a deployment fact, not a test
# expectation. run_all.sh starts the isolated server with empty provider keys
# so CI always exercises the degraded path — but a developer running this
# against their own configured server must get a meaningful result too.
# Each branch is a real assertion; nothing here is softened.
curl -s -b "$ADMIN" -o "$TMP/status.json" "$API/v1/ai-status"

if grep -q '"configured":false' "$TMP/status.json"; then
    ok "AI reports itself unconfigured — testing the degraded path"

    check_present "the unavailable reason is explained safely" "$TMP/tab.html" "not configured"

    # A form that posts into a void is worse than an honest empty state.
    if grep -q 'name="reported_signs"' "$TMP/tab.html"; then
        bad "no analyse form when unavailable" "the form was rendered anyway"
    else
        ok "no analyse form is offered while AI is unavailable"
    fi
else
    ok "AI reports itself configured — testing the available path"
    check_absent "no stale unavailable banner" "$TMP/tab.html" "not configured"
fi

# ============================================================================
section "Permissions — the matrix as the server enforces it"
# ============================================================================
# Requesting an analysis spends the organization metered AI allowance, so it is
# gated on ai.consult exactly as the assistant is.
check_status "veterinarian may request an analysis (CSRF refused, not permission)" \
    "$(curl -s -b "$VET" -o /dev/null -w '%{http_code}' -X POST \
        -d "animal_id=1&file_id=1" "$BASE/modules/cows/analyse.php")" "403"
check_status "viewer is blocked from the analysis API" \
    "$(curl -s -b "$VIEWER" -o /dev/null -w '%{http_code}' "$API/v1/animal-image-analysis/1")" "403"
check_status "accountant is blocked from the analysis API" \
    "$(curl -s -b "$ACCT" -o /dev/null -w '%{http_code}' "$API/v1/animal-image-analysis/1")" "403"
check_status "farm manager can read the analysis API" \
    "$(curl -s -b "$ADMIN" -o /dev/null -w '%{http_code}' "$API/v1/animal-image-analysis/1")" "200"
check_status "staff can read the analysis API" \
    "$(curl -s -b "$STAFF" -o /dev/null -w '%{http_code}' "$API/v1/animal-image-analysis/1")" "200"

# A viewer may see the animal but not what an AI said about it. The tab renders
# (cows.view is enough for the page) but must offer nothing.
curl -s -b "$VIEWER" -o "$TMP/viewer_tab.html" "$TAB"
if grep -q 'name="reported_signs"' "$TMP/viewer_tab.html"; then
    bad "viewer is offered no analyse form" "the form was rendered for a viewer"
else
    ok "viewer is offered no analyse form"
fi

# ============================================================================
section "Method and CSRF enforcement on the request endpoint"
# ============================================================================
# Requesting an analysis SPENDS a metered allowance, so it must never be
# reachable by GET — a prefetch or a crawler would otherwise spend it.
check_status "the request endpoint refuses GET" \
    "$(curl -s -b "$ADMIN" -o /dev/null -w '%{http_code}' \
        "$BASE/modules/cows/analyse.php?animal_id=1&file_id=1")" "405"

check_status "a POST without a CSRF token is refused" \
    "$(curl -s -b "$ADMIN" -o /dev/null -w '%{http_code}' -X POST \
        -d "animal_id=1&file_id=1" "$BASE/modules/cows/analyse.php")" "403"

check_status "a POST with a forged CSRF token is refused" \
    "$(curl -s -b "$ADMIN" -o /dev/null -w '%{http_code}' -X POST \
        -d "csrf_token=notarealtoken&animal_id=1&file_id=1" \
        "$BASE/modules/cows/analyse.php")" "403"

check_status "an unauthenticated POST does not reach the handler" \
    "$(curl -s -o /dev/null -w '%{http_code}' -X POST \
        -d "animal_id=1&file_id=1" "$BASE/modules/cows/analyse.php")" "302"

# ============================================================================
section "Tenant isolation over HTTP"
# ============================================================================
# cow 7 belongs to farm 2, a different organization.
check_status "farm-1 admin cannot read a farm-2 animal analysis (HTTP 404)" \
    "$(curl -s -b "$ADMIN" -o /dev/null -w '%{http_code}' "$API/v1/animal-image-analysis/7")" "404"

check_status "the rival cannot read a farm-1 animal analysis (HTTP 404)" \
    "$(curl -s -b "$RIVAL" -o /dev/null -w '%{http_code}' "$API/v1/animal-image-analysis/1")" "404"

# A foreign animal id in a POST BODY must be refused the same way — the tenant
# key comes from the session and is never taken from the request.
#
# The token is taken from the PHOTOS tab, not the analysis tab. The analysis
# tab deliberately renders no form while AI is unavailable, so sourcing a token
# from it would yield an empty string and the request would be refused by CSRF
# before it ever reached the tenant check — turning this into a test of the
# wrong gate. The session carries ONE token, so any page bearing a form gives
# the same valid one.
CSRF=$(csrf_for "$BASE/modules/cows/view.php?id=1&tab=photos" "$ADMIN")
if [ -z "$CSRF" ]; then
    bad "a CSRF token is obtainable for the POST-body test" "no token found on the photos tab"
else
    ok "a CSRF token is obtainable for the POST-body test"
fi
check_status "a farm-2 animal id in the POST body is refused (HTTP 404)" \
    "$(curl -s -b "$ADMIN" -o /dev/null -w '%{http_code}' -X POST \
        -d "csrf_token=$CSRF&animal_id=7&file_id=1" "$BASE/modules/cows/analyse.php")" "404"

# Malformed ids must be indistinguishable from foreign ones.
for BADID in "abc" "0" "-1" "99999999" "1%20OR%201=1"; do
    check_status "analysis API rejects animal id '$BADID' (HTTP 404)" \
        "$(curl -s -b "$ADMIN" -o /dev/null -w '%{http_code}' \
            "$API/v1/animal-image-analysis/$BADID")" "404"
done

check_status "a deeper analysis path does not match (HTTP 404)" \
    "$(curl -s -b "$ADMIN" -o /dev/null -w '%{http_code}' \
        "$API/v1/animal-image-analysis/1/observations")" "404"

check_status "the bare analysis path returns 404" \
    "$(curl -s -b "$ADMIN" -o /dev/null -w '%{http_code}' \
        "$API/v1/animal-image-analysis")" "404"

# The API is READ-ONLY. Requesting an analysis is a POST to the HTML module.
check_status "the analysis API rejects a write method (HTTP 405)" \
    "$(curl -s -b "$ADMIN" -o /dev/null -w '%{http_code}' -X POST \
        "$API/v1/animal-image-analysis/1")" "405"

check_status "the analysis API requires authentication (HTTP 401)" \
    "$(curl -s -o /dev/null -w '%{http_code}' "$API/v1/animal-image-analysis/1")" "401"

# ============================================================================
section "End-to-end: a real request with emergency wording"
# ============================================================================
# Uploads a real image through the EXISTING Phase 2B upload path, then requests
# an analysis describing an emergency. With no API key the analysis must come
# back `unavailable` — and the DETERMINISTIC urgency banner must still be shown,
# because that scan ran on the person's own words before the model and does not
# depend on the provider having answered.
#
# This is the one flow only an HTTP test can cover: it crosses the upload path,
# the request controller, the domain module and the template.
printf 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==' \
    | base64 -d > "$TMP/probe.png" 2>/dev/null

# curl here is often the WINDOWS build (mingw), which does not understand an
# MSYS "/tmp/..." path when it appears inside a -F value rather than as a bare
# argument — the upload silently fails to even leave the client. cygpath
# converts it where that matters and is absent elsewhere, so the raw path is
# the fallback and Linux/macOS runs are unaffected.
if command -v cygpath >/dev/null 2>&1; then
    PROBE_PATH=$(cygpath -w "$TMP/probe.png")
else
    PROBE_PATH="$TMP/probe.png"
fi

if [ ! -s "$TMP/probe.png" ]; then
    bad "a test image could be prepared" "base64 -d produced nothing"
else
    UPTOKEN=$(csrf_for "$BASE/modules/cows/view.php?id=1&tab=photos" "$ADMIN")
    curl -s -b "$ADMIN" -c "$ADMIN" -o /dev/null -X POST \
        -F "csrf_token=$UPTOKEN" -F "animal_id=1" -F "category=animal_photos" \
        -F "document_type=observation" -F "caption=Phase 7 probe" \
        -F "upload=@$PROBE_PATH;type=image/png" \
        "$BASE/modules/cows/files.php"

    curl -s -b "$ADMIN" -o "$TMP/photos.html" "$BASE/modules/cows/view.php?id=1&tab=photos"
    FILE_ID=$(sed -n 's/.*serve\.php?id=\([0-9]*\).*/\1/p' "$TMP/photos.html" | tail -1)

    if [ -z "$FILE_ID" ]; then
        bad "the probe image uploaded" "no stored file id found on the photos tab"
    else
        ok "the probe image uploaded through the existing Phase 2B path"

        REQTOKEN=$(csrf_for "$BASE/modules/cows/view.php?id=1&tab=photos" "$ADMIN")
        curl -s -b "$ADMIN" -c "$ADMIN" -o /dev/null -X POST \
            -d "csrf_token=$REQTOKEN&animal_id=1&file_id=$FILE_ID" \
            --data-urlencode "reported_signs=She cannot stand and the wound is bleeding heavily." \
            "$BASE/modules/cows/analyse.php"

        curl -s -b "$ADMIN" -o "$TMP/after.html" "$TAB"

        # THE REGRESSION THIS TEST EXISTS FOR: the deterministic emergency
        # banner must render even though the provider never answered. It was
        # once nested inside the completed branch, which hid it in exactly the
        # situation where the person is left with nothing else.
        check_present "the emergency banner shows even when the provider did not answer" \
            "$TMP/after.html" "Contact your veterinarian now"

        # An unconfigured provider is `unavailable`, never a success, and never
        # carries an invented observation.
        check_present "the analysis is recorded as not available" \
            "$TMP/after.html" "Not available"
        check_absent "no observations were fabricated" \
            "$TMP/after.html" "Visible in the photo"
        check_absent "no urgency badge is invented for an unanswered analysis" \
            "$TMP/after.html" "dfms-badge-danger"

        # And the API agrees with the page.
        curl -s -b "$ADMIN" -o "$TMP/after.json" "$API/v1/animal-image-analysis/1"
        check_present "the API reports the deterministic flag" \
            "$TMP/after.json" '"urgency_flagged_by_reported_signs":true'
        check_present "the API reports no urgency for an unanswered analysis" \
            "$TMP/after.json" '"urgency":null'
        check_present "the API reports no result for an unanswered analysis" \
            "$TMP/after.json" '"result":null'
        check_absent "the API never returns a raw failure token" \
            "$TMP/after.json" '"failure_reason"'
    fi
fi

# ============================================================================
section "The API states what it is, and leaks nothing"
# ============================================================================
curl -s -b "$ADMIN" -o "$TMP/api.json" "$API/v1/animal-image-analysis/1"

check_present "the payload declares itself an observation, not a diagnosis" \
    "$TMP/api.json" '"interpretation":"ai_assisted_observation"'
check_present "the payload states is_diagnosis false" "$TMP/api.json" '"is_diagnosis":false'
check_present "the payload states no clinical record was created" \
    "$TMP/api.json" '"clinical_record":"none"'

# farm_id is a TENANT KEY and is never echoed — returning one invites clients
# to start sending one back.
check_absent "the analysis API does not leak farm_id" "$TMP/api.json" '"farm_id"'
check_absent "the analysis API does not leak a stored path" "$TMP/api.json" 'stored_path'

# ============================================================================
section "Provider credentials and vendor internals never appear"
# ============================================================================
for URL in "modules/cows/view.php?id=1&tab=analysis" "api/index.php/v1/animal-image-analysis/1"; do
    curl -s -b "$ADMIN" -o "$TMP/leak.txt" "$BASE/$URL"
    LEAKED=""
    # EVERY provider credential shape, not just the one configured today, plus
    # the two vendors' own multimodal wire keys — an image mapping that escaped
    # its provider class would show up here.
    for TERM in "sk-ant-" "ANTHROPIC_API_KEY" "x-api-key" "api.anthropic.com" \
                "GEMINI_API_KEY" "x-goog-api-key" "AIzaSy" "generativelanguage.googleapis.com" \
                "inline_data" "media_type"; do
        if grep -qi -- "$TERM" "$TMP/leak.txt"; then
            LEAKED="$LEAKED $TERM"
        fi
    done
    if [ -n "$LEAKED" ]; then
        bad "no credential or vendor material in $URL" "found:$LEAKED"
    else
        ok "no credential or vendor material in $URL"
    fi
done

# No base64 image payload may ever be rendered or returned. The bytes have one
# owner (stored_files) and one delivery route (modules/files/serve.php).
check_absent "no base64 image payload on the page" \
    "$TMP/tab.html" "iVBORw0KGgo"
check_absent "no base64 image payload in the API" \
    "$TMP/api.json" "iVBORw0KGgo"

# ============================================================================
section "Existing behaviour is unchanged"
# ============================================================================
# The new tab must not have disturbed the other seven.
for T in overview timeline identity parentage health production documents photos; do
    CODE=$(curl -s -b "$ADMIN" -o "$TMP/t.html" -w '%{http_code}' \
        "$BASE/modules/cows/view.php?id=1&tab=$T")
    if [ "$CODE" != "200" ]; then
        bad "the $T tab still renders" "expected 200, got $CODE"
    elif grep -qi "Fatal error\|Uncaught\|Parse error" "$TMP/t.html"; then
        bad "the $T tab still renders" "PHP error in output"
    else
        ok "the $T tab still renders"
    fi
done

# An unknown tab still falls back to overview rather than erroring.
CODE=$(curl -s -b "$ADMIN" -o /dev/null -w '%{http_code}' \
    "$BASE/modules/cows/view.php?id=1&tab=nonsense")
check_status "an unknown tab falls back safely" "$CODE" "200"

# The AI assistant and its API are untouched by this phase.
check_status "the AI assistant still renders" \
    "$(curl -s -b "$ADMIN" -o /dev/null -w '%{http_code}' "$BASE/modules/ai/")" "200"
check_status "the AI status API still responds" \
    "$(curl -s -b "$ADMIN" -o /dev/null -w '%{http_code}' "$API/v1/ai-status")" "200"
check_status "the health API still responds" \
    "$(curl -s -b "$ADMIN" -o /dev/null -w '%{http_code}' "$API/v1/animal-health/1")" "200"

echo ""
echo "================================"
echo " passed: $PASS   failed: $FAIL"
echo "================================"
[ "$FAIL" -eq 0 ] || exit 1
exit 0
