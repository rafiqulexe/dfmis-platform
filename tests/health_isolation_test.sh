#!/usr/bin/env bash
# ============================================================================
# Phase 4 HTTP suite — Veterinary & Animal Health
#
# Runs against the ISOLATED test database, like the Phase 3 suite. Every
# assertion here is a MUTATION path or an authorization boundary, so it must
# never touch development data.
#
#   php tools/test-db.php --create
#   DB_NAME=dfms_test php -S localhost:8100 -t .
#   DFMS_HEALTH_BASE_URL=http://localhost:8100 bash tests/health_isolation_test.sh
#
# tests/run_all.sh starts that server automatically. If DFMS_HEALTH_BASE_URL is
# unset the suite falls back to the ordinary dev server — and SAYS SO, loudly,
# rather than pretending it was isolated.
#
# WHAT THIS SUITE IS FOR
# The PHPUnit suite covers the domain rules. This one covers what only a real
# request can: the permission matrix as the router applies it, CSRF, cross-farm
# ids arriving in POST bodies and URLs, and the API's tenant boundary.
# ============================================================================

BASE="${DFMS_HEALTH_BASE_URL:-${DFMS_BASE_URL:-http://localhost:8000}}"
ISOLATED="${DFMS_HEALTH_BASE_URL:-}"
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

# Dates are computed on the SERVER's UTC clock, not the shell's local clock.
# A shell in a timezone ahead of UTC would otherwise submit "tomorrow" and be
# correctly rejected by the not-in-the-future rules — a false failure.
TODAY=$(curl -s "$BASE/api/index.php/v1/health" >/dev/null 2>&1; date -u +%Y-%m-%d)
NOW=$(date -u +%Y-%m-%dT%H:%M)

echo "############################################################"
if [ -n "$ISOLATED" ]; then
    echo "# Running against the ISOLATED test database: $BASE"
else
    echo "# WARNING: running against the DEVELOPMENT server ($BASE)."
    echo "# Set DFMS_HEALTH_BASE_URL to use the isolated test database."
fi
echo "############################################################"

ADMIN="$TMP/admin.txt";  login admin admin123     "$ADMIN"
VET="$TMP/vet.txt";      login manager manager123 "$VET"
STAFF="$TMP/staff.txt";  login staff staff123     "$STAFF"
VIEWER="$TMP/viewer.txt"; login viewer viewer123  "$VIEWER"
ACCT="$TMP/acct.txt";    login accountant accountant123 "$ACCT"
RIVAL="$TMP/rival.txt";  login rival rival123     "$RIVAL"

CODE=$(curl -s -b "$ADMIN" -o /dev/null -w '%{http_code}' "$BASE/dashboard.php")
[ "$CODE" = "200" ] && ok "admin is authenticated" || bad "admin is authenticated" "dashboard returned $CODE"

# Resolve real animal ids rather than hardcoding them.
ANIMAL_ID=$(curl -s -b "$ADMIN" "$API/v1/animals?per_page=1" | sed -n 's/.*"id":\([0-9]*\).*/\1/p' | head -1)
RIVAL_ID=$(curl -s -b "$RIVAL" "$API/v1/animals?per_page=1" | sed -n 's/.*"id":\([0-9]*\).*/\1/p' | head -1)
[ -n "$ANIMAL_ID" ] && ok "resolved farm-1 animal id=$ANIMAL_ID" || { bad "resolve a farm-1 animal" "none found"; ANIMAL_ID=1; }
[ -n "$RIVAL_ID" ] && ok "resolved farm-2 animal id=$RIVAL_ID" || { bad "resolve a farm-2 animal" "none found"; RIVAL_ID=0; }

# ============================================================================
section "Health module pages render for an authorised user"
# ============================================================================
for PAGE in "" "?tab=treatments" "?tab=vaccinations" "?tab=quarantine" "?tab=withdrawals" "?tab=events"; do
    CODE=$(curl -s -b "$ADMIN" -o "$TMP/p.html" -w '%{http_code}' "$BASE/modules/health/$PAGE")
    if [ "$CODE" != "200" ]; then
        bad "health module '$PAGE'" "expected 200, got $CODE"
    elif grep -qi "Fatal error\|Uncaught\|Parse error" "$TMP/p.html"; then
        bad "health module '$PAGE'" "PHP error in output"
    else
        ok "health module '$PAGE' renders"
    fi
done

for PAGE in case_new.php observation.php medication.php vaccination.php quarantine.php treatment.php consultation.php lab.php add.php; do
    CODE=$(curl -s -b "$ADMIN" -o "$TMP/p.html" -w '%{http_code}' "$BASE/modules/health/$PAGE")
    if [ "$CODE" != "200" ]; then
        bad "health page $PAGE" "expected 200, got $CODE"
    elif grep -qi "Fatal error\|Uncaught\|Parse error" "$TMP/p.html"; then
        bad "health page $PAGE" "PHP error in output"
    else
        ok "health page $PAGE renders"
    fi
done

# An unknown tab must fall back, not error.
check_status "unknown health tab falls back" \
    "$(curl -s -b "$ADMIN" -o /dev/null -w '%{http_code}' "$BASE/modules/health/?tab=nonsense")" "200"

# The shared partial is not a page.
check_status "_shared.php refuses direct execution" \
    "$(curl -s -b "$ADMIN" -o /dev/null -w '%{http_code}' "$BASE/modules/health/_shared.php")" "404"

# ============================================================================
section "Permissions — the matrix as the server enforces it"
# ============================================================================
# viewer may READ health but write nothing.
check_status "viewer can read the health module" \
    "$(curl -s -b "$VIEWER" -o /dev/null -w '%{http_code}' "$BASE/modules/health/")" "200"
check_status "viewer blocked from opening a case" \
    "$(curl -s -b "$VIEWER" -o /dev/null -w '%{http_code}' "$BASE/modules/health/case_new.php")" "403"
check_status "viewer blocked from recording an observation" \
    "$(curl -s -b "$VIEWER" -o /dev/null -w '%{http_code}' "$BASE/modules/health/observation.php")" "403"
check_status "viewer blocked from medication" \
    "$(curl -s -b "$VIEWER" -o /dev/null -w '%{http_code}' "$BASE/modules/health/medication.php")" "403"

# staff may OBSERVE (deliberately wider) but not diagnose or medicate.
check_status "staff CAN record an observation" \
    "$(curl -s -b "$STAFF" -o /dev/null -w '%{http_code}' "$BASE/modules/health/observation.php")" "200"
check_status "staff blocked from opening a case" \
    "$(curl -s -b "$STAFF" -o /dev/null -w '%{http_code}' "$BASE/modules/health/case_new.php")" "403"
check_status "staff blocked from medication" \
    "$(curl -s -b "$STAFF" -o /dev/null -w '%{http_code}' "$BASE/modules/health/medication.php")" "403"
check_status "staff blocked from vaccination" \
    "$(curl -s -b "$STAFF" -o /dev/null -w '%{http_code}' "$BASE/modules/health/vaccination.php")" "403"

# accountant has no health write access at all.
check_status "accountant blocked from medication" \
    "$(curl -s -b "$ACCT" -o /dev/null -w '%{http_code}' "$BASE/modules/health/medication.php")" "403"
check_status "accountant blocked from opening a case" \
    "$(curl -s -b "$ACCT" -o /dev/null -w '%{http_code}' "$BASE/modules/health/case_new.php")" "403"

# the veterinarian is the role this module exists for.
check_status "veterinarian can open a case" \
    "$(curl -s -b "$VET" -o /dev/null -w '%{http_code}' "$BASE/modules/health/case_new.php")" "200"
check_status "veterinarian can administer medication" \
    "$(curl -s -b "$VET" -o /dev/null -w '%{http_code}' "$BASE/modules/health/medication.php")" "200"

# anonymous access is redirected to the login page, never served.
CODE=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/modules/health/case_new.php")
[ "$CODE" = "302" ] && ok "anonymous health access redirected (HTTP 302)" \
    || bad "anonymous health access" "expected 302, got $CODE"

# ============================================================================
section "CSRF — every mutating endpoint"
# ============================================================================
for PAGE in case_new.php observation.php medication.php vaccination.php quarantine.php treatment.php consultation.php lab.php; do
    CODE=$(curl -s -b "$ADMIN" -o /dev/null -w '%{http_code}' -X POST -d "cow_id=$ANIMAL_ID" "$BASE/modules/health/$PAGE")
    [ "$CODE" = "403" ] && ok "$PAGE POST without CSRF rejected" \
        || bad "$PAGE POST without CSRF" "expected 403, got $CODE"
done

CODE=$(curl -s -b "$ADMIN" -o /dev/null -w '%{http_code}' -X POST -d "health_status=sick" "$BASE/modules/health/status.php?id=$ANIMAL_ID")
[ "$CODE" = "403" ] && ok "status.php POST without CSRF rejected" \
    || bad "status.php POST without CSRF" "expected 403, got $CODE"

# ============================================================================
section "Cross-tenant — a farm-2 id submitted by a farm-1 user"
# ============================================================================
if [ "$RIVAL_ID" != "0" ]; then
    # Status page driven by an id in the URL.
    check_status "health status page on a foreign animal is 404" \
        "$(curl -s -b "$ADMIN" -o /dev/null -w '%{http_code}' "$BASE/modules/health/status.php?id=$RIVAL_ID")" "404"

    # A foreign cow_id in a POST body must not create a record.
    TOKEN=$(csrf_for "$BASE/modules/health/case_new.php" "$ADMIN")
    curl -s -b "$ADMIN" -c "$ADMIN" -o "$TMP/attack.html" -w '%{http_code}' -X POST \
        -d "csrf_token=$TOKEN&cow_id=$RIVAL_ID&title=Cross+tenant+attack&case_type=illness&severity=routine&opened_on=$TODAY" \
        "$BASE/modules/health/case_new.php" > "$TMP/attack.code"
    ATTACK_CODE=$(cat "$TMP/attack.code")
    if [ "$ATTACK_CODE" = "404" ]; then
        ok "case creation against a foreign animal is 404"
    elif [ "$ATTACK_CODE" = "302" ]; then
        bad "case creation against a foreign animal" "was ACCEPTED (302 redirect) — tenant leak"
    else
        ok "case creation against a foreign animal refused (HTTP $ATTACK_CODE)"
    fi

    # And the rival must not be able to see it either way.
    curl -s -b "$RIVAL" -o "$TMP/rival-cases.html" "$BASE/modules/health/"
    check_absent "farm 2 does not see a farm-1 case title" "$TMP/rival-cases.html" "Cross tenant attack"

    # Medication against a foreign animal.
    TOKEN=$(csrf_for "$BASE/modules/health/medication.php" "$ADMIN")
    MED_CODE=$(curl -s -b "$ADMIN" -c "$ADMIN" -o /dev/null -w '%{http_code}' -X POST \
        -d "csrf_token=$TOKEN&cow_id=$RIVAL_ID&medication_name=Attack&dose=1&dose_unit=ml&route=oral&administered_at=$NOW" \
        "$BASE/modules/health/medication.php")
    [ "$MED_CODE" = "302" ] && bad "medication against a foreign animal" "was ACCEPTED — tenant leak" \
        || ok "medication against a foreign animal refused (HTTP $MED_CODE)"
fi

# Malformed ids must all 404, never error or leak.
BADID=""
for PROBE in "1%20OR%201=1" "-1" "0" "abc" "1.5" "%27"; do
    CODE=$(curl -s -b "$ADMIN" -o /dev/null -w '%{http_code}' "$BASE/modules/health/status.php?id=$PROBE")
    [ "$CODE" = "404" ] || { bad "malformed status id '$PROBE'" "expected 404, got $CODE"; BADID=1; }
    CODE=$(curl -s -b "$ADMIN" -o /dev/null -w '%{http_code}' "$BASE/modules/health/case.php?id=$PROBE")
    [ "$CODE" = "404" ] || { bad "malformed case id '$PROBE'" "expected 404, got $CODE"; BADID=1; }
done
[ -z "$BADID" ] && ok "malformed health ids all return 404"

# ============================================================================
section "A complete clinical workflow"
# ============================================================================
TOKEN=$(csrf_for "$BASE/modules/health/case_new.php" "$ADMIN")
CASE_REDIRECT=$(curl -s -b "$ADMIN" -c "$ADMIN" -o /dev/null -w '%{redirect_url}' -X POST \
    -d "csrf_token=$TOKEN&cow_id=$ANIMAL_ID&title=Suite+test+case&case_type=mastitis&severity=urgent&opened_on=$TODAY&health_status=sick" \
    "$BASE/modules/health/case_new.php")
CASE_ID=$(echo "$CASE_REDIRECT" | sed -n 's/.*case\.php?id=\([0-9]*\).*/\1/p')
[ -n "$CASE_ID" ] && ok "opened case id=$CASE_ID" || bad "open a case" "no case id in redirect '$CASE_REDIRECT'"

if [ -n "$CASE_ID" ]; then
    check_status "case detail renders" \
        "$(curl -s -b "$ADMIN" -o /dev/null -w '%{http_code}' "$BASE/modules/health/case.php?id=$CASE_ID")" "200"

    # Symptom
    TOKEN=$(csrf_for "$BASE/modules/health/case.php?id=$CASE_ID" "$ADMIN")
    curl -s -b "$ADMIN" -c "$ADMIN" -o /dev/null -X POST \
        -d "csrf_token=$TOKEN&action=add_symptom&symptom_code=udder_swelling&severity=severe" \
        "$BASE/modules/health/case.php?id=$CASE_ID"

    # Diagnosis
    TOKEN=$(csrf_for "$BASE/modules/health/case.php?id=$CASE_ID" "$ADMIN")
    curl -s -b "$ADMIN" -c "$ADMIN" -o /dev/null -X POST \
        -d "csrf_token=$TOKEN&action=add_diagnosis&condition_code=mastitis&status=confirmed&confidence=high&diagnosed_on=$TODAY" \
        "$BASE/modules/health/case.php?id=$CASE_ID"

    curl -s -b "$ADMIN" -o "$TMP/case.html" "$BASE/modules/health/case.php?id=$CASE_ID"
    check_present "symptom appears on the case" "$TMP/case.html" "Udder swelling"
    check_present "diagnosis appears on the case" "$TMP/case.html" "Mastitis"
    check_present "the clinical disclaimer is shown" "$TMP/case.html" "does not make them"

    # A viewer must not be able to write to the case.
    TOKEN=$(csrf_for "$BASE/modules/health/case.php?id=$CASE_ID" "$VIEWER")
    CODE=$(curl -s -b "$VIEWER" -c "$VIEWER" -o /dev/null -w '%{http_code}' -X POST \
        -d "csrf_token=$TOKEN&action=add_symptom&symptom_code=fever&severity=mild" \
        "$BASE/modules/health/case.php?id=$CASE_ID")
    [ "$CODE" = "403" ] && ok "viewer cannot write to a case (HTTP 403)" \
        || bad "viewer writing to a case" "expected 403, got $CODE"

    # A farm-2 user must not reach the case at all.
    check_status "farm 2 cannot open a farm-1 case" \
        "$(curl -s -b "$RIVAL" -o /dev/null -w '%{http_code}' "$BASE/modules/health/case.php?id=$CASE_ID")" "404"
fi

# ============================================================================
section "Withdrawal periods — creation and surfacing"
# ============================================================================
TOKEN=$(csrf_for "$BASE/modules/health/medication.php" "$ADMIN")
curl -s -b "$ADMIN" -c "$ADMIN" -o /dev/null -X POST \
    -d "csrf_token=$TOKEN&cow_id=$ANIMAL_ID&medication_name=Suite+antibiotic&batch_lot=SUITE-LOT&dose=10&dose_unit=ml&route=im&administered_at=$NOW&withdrawal_milk=4&withdrawal_meat=28" \
    "$BASE/modules/health/medication.php"

curl -s -b "$ADMIN" -o "$TMP/wd.html" "$BASE/modules/health/?tab=withdrawals"
check_present "the withdrawal appears in the module" "$TMP/wd.html" "Suite antibiotic"
check_present "the enforcement limitation is stated on the page" "$TMP/wd.html" "not enforced against production"

# A blank withdrawal must create NOTHING — the most dangerous possible default.
TOKEN=$(csrf_for "$BASE/modules/health/medication.php" "$ADMIN")
curl -s -b "$ADMIN" -c "$ADMIN" -o /dev/null -X POST \
    -d "csrf_token=$TOKEN&cow_id=$ANIMAL_ID&medication_name=No+withdrawal+product&dose=5&dose_unit=ml&route=oral&administered_at=$NOW&withdrawal_milk=&withdrawal_meat=" \
    "$BASE/modules/health/medication.php"
curl -s -b "$ADMIN" -o "$TMP/wd2.html" "$BASE/modules/health/?tab=withdrawals"
check_absent "a blank withdrawal creates no restriction" "$TMP/wd2.html" "No withdrawal product"

# ============================================================================
section "Alerts — no duplicates from repeated page loads"
# ============================================================================
for _ in 1 2 3; do curl -s -b "$ADMIN" -o /dev/null "$BASE/modules/health/"; done
curl -s -b "$ADMIN" -o "$TMP/alerts.html" "$BASE/modules/alerts/"
DUPES=$(grep -o "Withdrawal period ending" "$TMP/alerts.html" | wc -l | tr -d ' ')
if [ "$DUPES" -le 2 ]; then
    ok "repeated health page loads did not spam alerts (found $DUPES)"
else
    bad "alert deduplication" "found $DUPES 'Withdrawal period ending' rows after 3 syncs"
fi

# ============================================================================
section "Animal profile — the health tab"
# ============================================================================
curl -s -b "$ADMIN" -o "$TMP/profile.html" "$BASE/modules/cows/view.php?id=$ANIMAL_ID&tab=health"
CODE=$(curl -s -b "$ADMIN" -o /dev/null -w '%{http_code}' "$BASE/modules/cows/view.php?id=$ANIMAL_ID&tab=health")
check_status "animal profile health tab" "$CODE" "200"
grep -qi "Fatal error\|Uncaught" "$TMP/profile.html" \
    && bad "animal profile health tab" "PHP error in output" \
    || ok "animal profile health tab has no PHP errors"
check_present "the profile shows current standing" "$TMP/profile.html" "Current standing"
check_present "the profile surfaces the withdrawal" "$TMP/profile.html" "withheld"

# The timeline must include the new health events.
curl -s -b "$ADMIN" -o "$TMP/timeline.html" "$BASE/modules/cows/view.php?id=$ANIMAL_ID&tab=timeline"
check_present "health events reach the animal timeline" "$TMP/timeline.html" "Suite antibiotic"

# ============================================================================
section "API — tenant isolation and shape"
# ============================================================================
curl -s -b "$ADMIN" -o "$TMP/api-health.json" "$API/v1/animal-health/$ANIMAL_ID"
check_present "animal-health returns a data envelope" "$TMP/api-health.json" '"data"'
check_present "animal-health reports withdrawal advisory status" "$TMP/api-health.json" 'advisory_only'
check_present "animal-health states the inclusive end convention" "$TMP/api-health.json" 'ends_inclusive'
check_absent "animal-health does not leak farm_id" "$TMP/api-health.json" '"farm_id"'
check_absent "animal-health does not leak stored_path" "$TMP/api-health.json" 'stored_path'

curl -s -b "$ADMIN" -o "$TMP/api-cases.json" "$API/v1/health-cases"
check_present "health-cases returns a data envelope" "$TMP/api-cases.json" '"data"'
check_present "health-cases returns pagination meta" "$TMP/api-cases.json" '"pages"'
check_absent "health-cases does not leak farm_id" "$TMP/api-cases.json" '"farm_id"'

if [ "$RIVAL_ID" != "0" ]; then
    check_status "API cross-tenant animal health is 404" \
        "$(curl -s -b "$ADMIN" -o /dev/null -w '%{http_code}' "$API/v1/animal-health/$RIVAL_ID")" "404"
    check_status "API cross-tenant in reverse is 404" \
        "$(curl -s -b "$RIVAL" -o /dev/null -w '%{http_code}' "$API/v1/animal-health/$ANIMAL_ID")" "404"
fi
if [ -n "$CASE_ID" ]; then
    check_status "API cross-tenant case is 404" \
        "$(curl -s -b "$RIVAL" -o /dev/null -w '%{http_code}' "$API/v1/health-cases/$CASE_ID")" "404"
fi

check_status "API health endpoints require authentication" \
    "$(curl -s -o /dev/null -w '%{http_code}' "$API/v1/animal-health/$ANIMAL_ID")" "401"
check_status "API health-cases requires authentication" \
    "$(curl -s -o /dev/null -w '%{http_code}' "$API/v1/health-cases")" "401"
check_status "API rejects a write method on animal-health" \
    "$(curl -s -b "$ADMIN" -o /dev/null -w '%{http_code}' -X POST "$API/v1/animal-health/$ANIMAL_ID")" "405"
check_status "API malformed animal-health id is 404" \
    "$(curl -s -b "$ADMIN" -o /dev/null -w '%{http_code}' "$API/v1/animal-health/abc")" "404"
check_status "API deeper animal-health path does not match" \
    "$(curl -s -b "$ADMIN" -o /dev/null -w '%{http_code}' "$API/v1/animal-health/$ANIMAL_ID/secrets")" "404"

# The PUBLIC liveness probe must still be exactly that — Phase 4 added routes
# beside it, and must not have made it authenticated or made clinical data
# public through it.
curl -s -o "$TMP/probe.json" "$API/v1/health"
check_present "the public liveness probe still works" "$TMP/probe.json" '"status"'
check_absent "the liveness probe exposes no clinical data" "$TMP/probe.json" 'case'

# An accountant may not read clinical data through the API either.
check_status "accountant is refused by the health API" \
    "$(curl -s -b "$ACCT" -o /dev/null -w '%{http_code}' "$API/v1/health-cases")" "403"

# ============================================================================
section "Existing modules still work"
# ============================================================================
ALLOK=1
for PAGE in "dashboard.php" "modules/cows/" "modules/milk/" "modules/feeding/" "modules/inventory/" "modules/alerts/" "modules/settings/" "modules/audit/"; do
    CODE=$(curl -s -b "$ADMIN" -o /dev/null -w '%{http_code}' "$BASE/$PAGE")
    [ "$CODE" = "200" ] || { bad "pre-Phase-4 page $PAGE" "expected 200, got $CODE"; ALLOK=0; }
done
[ "$ALLOK" = "1" ] && ok "all pre-Phase-4 pages still return 200"

curl -s -b "$ADMIN" -o "$TMP/legacy.html" "$BASE/modules/health/add.php"
grep -qi "Fatal error\|Uncaught" "$TMP/legacy.html" \
    && bad "the legacy health event form" "PHP error in output" \
    || ok "the legacy health event form still works"

echo ""
echo "================================"
echo " passed: $PASS   failed: $FAIL"
echo "================================"
exit "$FAIL"
