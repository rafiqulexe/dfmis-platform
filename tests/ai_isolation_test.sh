#!/usr/bin/env bash
# ============================================================================
# Phase 5D HTTP suite — AI Veterinarian Assistant + RAG
#
# Runs against the ISOLATED test database, like the Phase 3 and 4 suites.
#
#   php tools/test-db.php --create
#   DB_NAME=dfms_test php -S localhost:8100 -t .
#   DFMS_AI_BASE_URL=http://localhost:8100 bash tests/ai_isolation_test.sh
#
# tests/run_all.sh starts that server automatically. If DFMS_AI_BASE_URL is
# unset the suite falls back to the ordinary dev server — and SAYS SO, loudly.
#
# WHAT THIS SUITE IS FOR
# The PHPUnit suite covers domain rules with a stubbed provider. This one
# covers what only a real request can: the permission matrix as the router
# applies it, CSRF, cross-tenant ids arriving in URLs and POST bodies, the
# degraded state a browser actually sees with no API key, and — most
# importantly — that no provider credential or vendor detail appears in any
# response body.
#
# NO REAL AI CALL IS MADE. The test environment has no API key, which is
# exactly the "fail safely when unconfigured" path this suite asserts.
# ============================================================================

BASE="${DFMS_AI_BASE_URL:-${DFMS_BASE_URL:-http://localhost:8000}}"
ISOLATED="${DFMS_AI_BASE_URL:-}"
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
    echo "# Set DFMS_AI_BASE_URL to use the isolated test database."
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

# ============================================================================
section "Pages render and the partial is not a page"
# ============================================================================
for PAGE in "modules/ai/" "modules/ai/knowledge.php"; do
    CODE=$(curl -s -b "$ADMIN" -o "$TMP/p.html" -w '%{http_code}' "$BASE/$PAGE")
    if [ "$CODE" != "200" ]; then
        bad "page $PAGE" "expected 200, got $CODE"
    elif grep -qi "Fatal error\|Uncaught\|Parse error" "$TMP/p.html"; then
        bad "page $PAGE" "PHP error in output"
    else
        ok "page $PAGE renders"
    fi
done

check_status "modules/ai/_shared.php refuses direct execution" \
    "$(curl -s -b "$ADMIN" -o /dev/null -w '%{http_code}' "$BASE/modules/ai/_shared.php")" "404"

# ============================================================================
section "Degraded state — no API key configured"
# ============================================================================
curl -s -b "$ADMIN" -o "$TMP/ai.html" "$BASE/modules/ai/"
check_present "the unavailable state is shown" "$TMP/ai.html" "assistant is unavailable"
check_present "the reason is explained safely" "$TMP/ai.html" "not configured"

# The ask form must NOT be offered when the assistant cannot answer — a form
# that posts into a void is worse than an honest empty state.
if grep -q 'name="question"' "$TMP/ai.html"; then
    bad "no ask form when unavailable" "the question form was rendered anyway"
else
    ok "no ask form is offered while the assistant is unavailable"
fi

# And absolutely no fabricated answer anywhere on the page.
#
# Matched on the message MARKUP, not the CSS class name: `.ai-msg-assistant`
# appears in the scoped stylesheet on every AI page, so grepping the class
# alone is a false positive. Only aiRenderMessage() emits `<article class="ai-msg`.
check_absent "no fabricated answer is rendered" "$TMP/ai.html" '<article class="ai-msg'

# ============================================================================
section "Provider credentials never appear anywhere"
# ============================================================================
# Every AI surface, HTML and JSON, checked for vendor and credential leakage.
for URL in "modules/ai/" "modules/ai/knowledge.php" "api/index.php/v1/ai-status" "api/index.php/v1/ai-conversations"; do
    curl -s -b "$ADMIN" -o "$TMP/leak.txt" "$BASE/$URL"
    LEAKED=""
    for TERM in "sk-" "ANTHROPIC_API_KEY" "x-api-key" "api.anthropic.com"; do
        if grep -qi -- "$TERM" "$TMP/leak.txt"; then
            LEAKED="$LEAKED $TERM"
        fi
    done
    if [ -n "$LEAKED" ]; then
        bad "no credential material in $URL" "found:$LEAKED"
    else
        ok "no credential material in $URL"
    fi
done

# ============================================================================
section "Permissions — the matrix as the server enforces it"
# ============================================================================
check_status "veterinarian can reach the assistant" \
    "$(curl -s -b "$VET" -o /dev/null -w '%{http_code}' "$BASE/modules/ai/")" "200"
check_status "staff can reach the assistant" \
    "$(curl -s -b "$STAFF" -o /dev/null -w '%{http_code}' "$BASE/modules/ai/")" "200"
check_status "viewer is blocked from the assistant" \
    "$(curl -s -b "$VIEWER" -o /dev/null -w '%{http_code}' "$BASE/modules/ai/")" "403"
check_status "accountant is blocked from the assistant" \
    "$(curl -s -b "$ACCT" -o /dev/null -w '%{http_code}' "$BASE/modules/ai/")" "403"

# Knowledge curation is narrower than consulting.
check_status "farm manager can curate the knowledge base" \
    "$(curl -s -b "$ADMIN" -o /dev/null -w '%{http_code}' "$BASE/modules/ai/knowledge.php")" "200"
check_status "veterinarian is blocked from curating knowledge" \
    "$(curl -s -b "$VET" -o /dev/null -w '%{http_code}' "$BASE/modules/ai/knowledge.php")" "403"
check_status "staff is blocked from curating knowledge" \
    "$(curl -s -b "$STAFF" -o /dev/null -w '%{http_code}' "$BASE/modules/ai/knowledge.php")" "403"

check_status "viewer is blocked from the AI API" \
    "$(curl -s -b "$VIEWER" -o /dev/null -w '%{http_code}' "$API/v1/ai-status")" "403"
check_status "accountant is blocked from the AI API" \
    "$(curl -s -b "$ACCT" -o /dev/null -w '%{http_code}' "$API/v1/ai-conversations")" "403"

CODE=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/modules/ai/")
[ "$CODE" = "302" ] && ok "anonymous AI access redirected (HTTP 302)" \
    || bad "anonymous AI access" "expected 302, got $CODE"

check_status "AI API requires authentication" \
    "$(curl -s -o /dev/null -w '%{http_code}' "$API/v1/ai-status")" "401"

# ============================================================================
section "CSRF on every mutating AI endpoint"
# ============================================================================
CODE=$(curl -s -b "$ADMIN" -o /dev/null -w '%{http_code}' -X POST \
    -d "action=create_source&name=NoCSRF" "$BASE/modules/ai/knowledge.php")
[ "$CODE" = "403" ] && ok "knowledge POST without CSRF rejected" \
    || bad "knowledge POST without CSRF" "expected 403, got $CODE"

CODE=$(curl -s -b "$ADMIN" -o /dev/null -w '%{http_code}' -X POST \
    -d "question=NoCSRF" "$BASE/modules/ai/")
[ "$CODE" = "403" ] && ok "consultation POST without CSRF rejected" \
    || bad "consultation POST without CSRF" "expected 403, got $CODE"

# ============================================================================
section "Knowledge base — real RAG ingestion"
# ============================================================================
TOKEN=$(csrf_for "$BASE/modules/ai/knowledge.php" "$ADMIN")
curl -s -b "$ADMIN" -c "$ADMIN" -o /dev/null -X POST \
    --data-urlencode "csrf_token=$TOKEN" \
    --data-urlencode "action=create_source" \
    --data-urlencode "name=Suite protocols" \
    --data-urlencode "source_type=farm_protocol" \
    --data-urlencode "attribution=Suite Veterinary Practice" \
    "$BASE/modules/ai/knowledge.php"

curl -s -b "$ADMIN" -o "$TMP/kb.html" "$BASE/modules/ai/knowledge.php"
check_present "the source appears in the knowledge base" "$TMP/kb.html" "Suite protocols"

SOURCE_ID=$(sed -n 's/.*name="source_id" value="\([0-9]*\)".*/\1/p' "$TMP/kb.html" | head -1)
if [ -n "$SOURCE_ID" ]; then
    ok "resolved source id=$SOURCE_ID"

    TOKEN=$(csrf_for "$BASE/modules/ai/knowledge.php" "$ADMIN")
    curl -s -b "$ADMIN" -c "$ADMIN" -o /dev/null -X POST \
        --data-urlencode "csrf_token=$TOKEN" \
        --data-urlencode "action=save_document" \
        --data-urlencode "source_id=$SOURCE_ID" \
        --data-urlencode "title=Suite lameness protocol" \
        --data-urlencode "body=Lameness scoring is carried out weekly on the milking herd.

Any cow scoring three or above is moved to the hospital pen and examined the same day.

Hoof trimming is recorded against the animal so the interval can be reviewed." \
        "$BASE/modules/ai/knowledge.php"

    curl -s -b "$ADMIN" -o "$TMP/kb2.html" "$BASE/modules/ai/knowledge.php"
    check_present "the document appears" "$TMP/kb2.html" "Suite lameness protocol"
    # A document must be chunked, not stored whole — that is what makes it RAG.
    check_present "the document reports indexed passages" "$TMP/kb2.html" "Passages"
else
    bad "resolve a source id" "could not read one from the knowledge page"
fi

# The PDF limitation is stated on the page, not hidden in documentation.
check_present "the PDF limitation is stated in the UI" "$TMP/kb.html" "no PDF text extractor"

# ============================================================================
section "Cross-tenant — knowledge and conversations"
# ============================================================================
# Farm 2 must not see farm 1's knowledge.
curl -s -b "$RIVAL" -o "$TMP/rival-kb.html" "$BASE/modules/ai/knowledge.php"
check_absent "farm 2 does not see farm-1 knowledge sources" "$TMP/rival-kb.html" "Suite protocols"
check_absent "farm 2 does not see farm-1 documents" "$TMP/rival-kb.html" "Suite lameness protocol"

# A farm-1 document id must be unreachable from farm 2.
DOC_ID=$(sed -n 's/.*knowledge\.php?edit=\([0-9]*\).*/\1/p' "$TMP/kb2.html" | head -1)
if [ -n "$DOC_ID" ]; then
    check_status "farm 2 cannot open a farm-1 document" \
        "$(curl -s -b "$RIVAL" -o /dev/null -w '%{http_code}' "$BASE/modules/ai/knowledge.php?edit=$DOC_ID")" "404"
else
    ok "no document id exposed to probe (nothing to leak)"
fi

# Conversations: malformed and foreign ids must all 404.
BADID=""
for PROBE in "1%20OR%201=1" "-1" "0" "abc" "1.5" "%27" "99999"; do
    CODE=$(curl -s -b "$ADMIN" -o /dev/null -w '%{http_code}' "$BASE/modules/ai/?c=$PROBE")
    [ "$CODE" = "404" ] || { bad "malformed conversation id '$PROBE'" "expected 404, got $CODE"; BADID=1; }
done
[ -z "$BADID" ] && ok "malformed and unknown conversation ids all return 404"

# The knowledge page takes resource ids too, and they follow the same rule:
# present-but-malformed is a 404, never a silent fall-through to the default
# view. This was a real defect caught by the conversation probes above.
BADEDIT=""
for PROBE in "1%20OR%201=1" "-1" "0" "abc" "1.5" "%27"; do
    CODE=$(curl -s -b "$ADMIN" -o /dev/null -w '%{http_code}' "$BASE/modules/ai/knowledge.php?edit=$PROBE")
    [ "$CODE" = "404" ] || { bad "malformed document id '$PROBE'" "expected 404, got $CODE"; BADEDIT=1; }
    CODE=$(curl -s -b "$ADMIN" -o /dev/null -w '%{http_code}' "$BASE/modules/ai/knowledge.php?source=$PROBE")
    [ "$CODE" = "404" ] || { bad "malformed source filter '$PROBE'" "expected 404, got $CODE"; BADEDIT=1; }
done
[ -z "$BADEDIT" ] && ok "malformed knowledge resource ids all return 404"

# An ABSENT optional id must still render the default view, not 404.
check_status "absent conversation id renders the default view" \
    "$(curl -s -b "$ADMIN" -o /dev/null -w '%{http_code}' "$BASE/modules/ai/")" "200"
check_status "empty conversation id renders the default view" \
    "$(curl -s -b "$ADMIN" -o /dev/null -w '%{http_code}' "$BASE/modules/ai/?c=")" "200"

check_status "API malformed conversation id is 404" \
    "$(curl -s -b "$ADMIN" -o /dev/null -w '%{http_code}' "$API/v1/ai-conversations/abc")" "404"
check_status "API unknown conversation id is 404" \
    "$(curl -s -b "$ADMIN" -o /dev/null -w '%{http_code}' "$API/v1/ai-conversations/99999")" "404"
check_status "API deeper conversation path does not match" \
    "$(curl -s -b "$ADMIN" -o /dev/null -w '%{http_code}' "$API/v1/ai-conversations/1/messages")" "404"

# ============================================================================
section "API shape and safety metadata"
# ============================================================================
curl -s -b "$ADMIN" -o "$TMP/status.json" "$API/v1/ai-status"
check_present "ai-status returns a data envelope" "$TMP/status.json" '"data"'
check_present "ai-status reports availability" "$TMP/status.json" '"available"'
check_present "ai-status reports configured as a boolean" "$TMP/status.json" '"configured":false'
check_present "ai-status states the assistant is decision support only" "$TMP/status.json" 'decision_support_only'
check_present "ai-status states it does not replace a veterinarian" "$TMP/status.json" 'replace a licensed veterinarian'
check_absent "ai-status does not leak farm_id" "$TMP/status.json" '"farm_id"'

curl -s -b "$ADMIN" -o "$TMP/convs.json" "$API/v1/ai-conversations"
check_present "ai-conversations returns a data envelope" "$TMP/convs.json" '"data"'
check_absent "ai-conversations does not leak farm_id" "$TMP/convs.json" '"farm_id"'
check_absent "ai-conversations does not leak admin_id" "$TMP/convs.json" '"admin_id"'

# ---- Phase 5E: the platform block on the SAME endpoint --------------------
# No new route was added by Phase 5E. These assertions exist to prove both that
# the infrastructure is reachable and that it did not become a leak.
check_present "ai-status reports the platform block" "$TMP/status.json" '"platform"'
check_present "ai-status names the active provider" "$TMP/status.json" '"provider":"anthropic"'
check_present "ai-status reports the prompt version" "$TMP/status.json" '"veterinarian_assistant"'
check_present "ai-status publishes the rate limit" "$TMP/status.json" '"user_per_minute"'
check_present "ai-status lists the tools this caller may use" "$TMP/status.json" '"get_animal"'

# The credential, in every form it could take. `configured` is a boolean and
# that remains the whole of what any caller learns about it.
check_absent "ai-status leaks no api key"        "$TMP/status.json" 'sk-'
check_absent "ai-status leaks no key variable"   "$TMP/status.json" 'ANTHROPIC_API_KEY'
check_absent "ai-status leaks no auth header"    "$TMP/status.json" 'x-api-key'
check_absent "ai-status leaks no provider host"  "$TMP/status.json" 'api.anthropic.com'
check_absent "ai-status leaks no prompt body"    "$TMP/status.json" 'ABSOLUTE RULES'

# There is deliberately NO endpoint that executes a tool by name. A generic
# remote tool runner would hand a caller the whole read surface behind one
# allow-list, so none exists — and these prove none was added by accident.
#
# Phase 5F ADDED tool calling and still adds no such route: tool execution
# happens only inside the server-side orchestration, never on request.
check_status "no generic tool-execution endpoint exists" \
    "$(curl -s -b "$ADMIN" -o /dev/null -w '%{http_code}' "$API/v1/ai-tools")" "404"
check_status "a tool cannot be invoked over HTTP" \
    "$(curl -s -b "$ADMIN" -o /dev/null -w '%{http_code}' -X POST "$API/v1/ai-tools/get_animal")" "404"
check_status "no agent/tool-run endpoint exists" \
    "$(curl -s -b "$ADMIN" -o /dev/null -w '%{http_code}' -X POST "$API/v1/ai-agent")" "404"
check_status "the tool registry is not published as a route" \
    "$(curl -s -b "$ADMIN" -o /dev/null -w '%{http_code}' "$API/v1/ai-tool-registry")" "404"

check_status "API rejects a write method on ai-status" \
    "$(curl -s -b "$ADMIN" -o /dev/null -w '%{http_code}' -X POST "$API/v1/ai-status")" "405"
check_status "API rejects a write method on ai-conversations" \
    "$(curl -s -b "$ADMIN" -o /dev/null -w '%{http_code}' -X POST "$API/v1/ai-conversations")" "405"

# ---- Phase 5F: the tool loop leaves no protocol on any surface ------------
#
# The assistant page and the conversation API both render model output. Neither
# may ever show a user the tool protocol: raw tool names, JSON arguments,
# tool_use/tool_result envelopes or correlation ids.
curl -s -b "$ADMIN" -o "$TMP/ai-page.html" "$BASE/modules/ai/"
check_status "the assistant page still renders" \
    "$(curl -s -b "$ADMIN" -o /dev/null -w '%{http_code}' "$BASE/modules/ai/")" "200"
check_absent "the assistant page has no PHP errors" "$TMP/ai-page.html" 'Fatal error'
for TERM in "tool_use" "tool_result" "toolu_" "input_schema" "get_animal_health_summary"; do
    check_absent "the assistant page exposes no tool protocol ($TERM)" "$TMP/ai-page.html" "$TERM"
done

for TERM in "tool_use" "tool_result" "toolu_" "input_schema" "get_farm_summary"; do
    check_absent "the conversation API exposes no tool protocol ($TERM)" "$TMP/convs.json" "$TERM"
done

# The public liveness probe must be untouched by this phase and must not have
# become an AI surface.
curl -s -o "$TMP/probe.json" "$API/v1/health"
check_present "the public liveness probe still works" "$TMP/probe.json" '"status"'
check_absent "the liveness probe exposes no AI detail" "$TMP/probe.json" 'anthropic'

# ============================================================================
section "The deterministic Farm Advisor is unaffected"
# ============================================================================
curl -s -b "$ADMIN" -o "$TMP/dash.html" "$BASE/dashboard.php"
CODE=$(curl -s -b "$ADMIN" -o /dev/null -w '%{http_code}' "$BASE/dashboard.php")
check_status "dashboard still renders" "$CODE" "200"
grep -qi "Fatal error\|Uncaught" "$TMP/dash.html" \
    && bad "dashboard has no PHP errors" "PHP error in output" \
    || ok "dashboard has no PHP errors"
# insights.php computes these deterministically with no model call.
check_present "the deterministic advisor still produces insights" "$TMP/dash.html" "Farm Advisor"

# ============================================================================
section "Existing modules still work"
# ============================================================================
ALLOK=1
for PAGE in "modules/cows/" "modules/health/" "modules/milk/" "modules/feeding/" "modules/inventory/" "modules/alerts/" "modules/settings/" "modules/audit/"; do
    CODE=$(curl -s -b "$ADMIN" -o /dev/null -w '%{http_code}' "$BASE/$PAGE")
    [ "$CODE" = "200" ] || { bad "pre-Phase-5 page $PAGE" "expected 200, got $CODE"; ALLOK=0; }
done
[ "$ALLOK" = "1" ] && ok "all pre-Phase-5 pages still return 200"

# Phase 4 health APIs must be untouched.
check_status "the Phase 4 health-cases API still works" \
    "$(curl -s -b "$ADMIN" -o /dev/null -w '%{http_code}' "$API/v1/health-cases")" "200"
check_status "the Phase 3 animals API still works" \
    "$(curl -s -b "$ADMIN" -o /dev/null -w '%{http_code}' "$API/v1/animals")" "200"

echo ""
echo "================================"
echo " passed: $PASS   failed: $FAIL"
echo "================================"
exit "$FAIL"
