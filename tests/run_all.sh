#!/usr/bin/env bash
# ============================================================================
# DFMIS full verification run.
#
#   bash tests/run_all.sh
#
# Runs, in order:
#   1. syntax check   — php -l over every PHP file
#   2. unit tests     — validation layer (no server)
#   3. unit tests     — PHPUnit, against the isolated dfms_test database
#   4. unit tests     — international foundation (needs the database)
#   5. integration    — tenant isolation + RBAC       (needs the dev server)
#   6. integration    — security regression            (needs the dev server)
#   7. integration    — international settings         (needs the dev server)
#   8. integration    — API foundation                 (needs the dev server)
#   9. integration    — Animal Digital Twin (ISOLATED dfms_test server)
#  10. integration    — Veterinary & Animal Health (ISOLATED dfms_test server)
#  11. integration    — AI Veterinarian Assistant (ISOLATED dfms_test server)
#
# The integration suites FAIL rather than skip when no server is running, so a
# green result can never mean "half of this did not run".
#
# Set DFMS_PHP if PHP is not on PATH, e.g.
#   DFMS_PHP=C:/xampp/php/php.exe bash tests/run_all.sh
# ============================================================================

set -u

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
BASE="${DFMS_BASE_URL:-http://localhost:8000}"

# Locate PHP: explicit override, then PATH, then the common XAMPP location.
if [ -n "${DFMS_PHP:-}" ]; then
    PHP="$DFMS_PHP"
elif command -v php >/dev/null 2>&1; then
    PHP="php"
elif [ -x "C:/xampp/php/php.exe" ]; then
    PHP="C:/xampp/php/php.exe"
else
    echo "ERROR: PHP not found. Set DFMS_PHP to the php binary." >&2
    exit 1
fi

SUITES_RUN=0
SUITES_FAILED=0

banner() {
    echo ""
    echo "############################################################"
    echo "# $1"
    echo "############################################################"
}

record() { # record <suite name> <exit code>
    SUITES_RUN=$((SUITES_RUN + 1))
    if [ "$2" -ne 0 ]; then
        SUITES_FAILED=$((SUITES_FAILED + 1))
        echo ">>> SUITE FAILED: $1"
    fi
}

# ---- 1. syntax ------------------------------------------------------------
banner "1/11 Syntax check (php -l)"
LINT_FAIL=0
LINT_OK=0
while IFS= read -r file; do
    if "$PHP" -l "$file" 2>&1 | grep -q "No syntax errors"; then
        LINT_OK=$((LINT_OK + 1))
    else
        LINT_FAIL=$((LINT_FAIL + 1))
        echo "  FAIL  $file"
        "$PHP" -l "$file" 2>&1 | head -3
    fi
done < <(find "$ROOT" -name "*.php" -not -path "*/.claude/*" -not -path "*/vendor/*")
echo "  clean: $LINT_OK   failing: $LINT_FAIL"
record "syntax check" "$LINT_FAIL"

# ---- 2. unit --------------------------------------------------------------
banner "2/11 Unit tests — validation layer"
"$PHP" "$ROOT/tests/validation_unit_test.php"
record "validation unit tests" "$?"

# ---- 3. unit: PHPUnit (Phase 2B) -----------------------------------------
# Runs against the ISOLATED test database (dfms_test), never the development
# one. tests/bootstrap.php refuses to proceed otherwise.
banner "3/11 Unit tests — PHPUnit"
if [ -f "$ROOT/vendor/bin/phpunit" ]; then
    "$PHP" "$ROOT/vendor/bin/phpunit" --colors=never
    record "phpunit" "$?"
else
    echo "  PHPUnit is not installed. Run: composer install"
    record "phpunit" 1
fi

# ---- 4. unit: international foundation (Phase 2) --------------------------
# Needs the database (it exercises the settings hierarchy against real rows)
# but not the web server, so it runs before the server check.
banner "4/11 Unit tests — international platform foundation"
"$PHP" "$ROOT/tests/international_unit_test.php"
record "international unit tests" "$?"

# ---- server required from here on ----------------------------------------
if ! curl -s -o /dev/null --max-time 5 "$BASE/index.php" 2>/dev/null; then
    echo ""
    echo "############################################################"
    echo "# SKIPPED integration suites — no dev server at $BASE"
    echo "# Start one with:  $PHP -S localhost:8000 -t ."
    echo "############################################################"
    echo ""
    echo "Suites run: $SUITES_RUN   failed: $SUITES_FAILED   (4 skipped)"
    # A skipped integration suite is not a pass. Fail loudly so CI cannot go
    # green just because nobody started the server.
    exit 1
fi

# ---- 3. tenant isolation --------------------------------------------------
banner "5/11 Integration — tenant isolation + RBAC"
bash "$ROOT/tests/tenant_isolation_test.sh"
record "tenant isolation" "$?"

# ---- 4. security regression ----------------------------------------------
banner "6/11 Integration — security regression"
bash "$ROOT/tests/security_regression_test.sh"
record "security regression" "$?"

# ---- 6. international isolation (Phase 2) --------------------------------
banner "7/11 Integration — international settings isolation"
bash "$ROOT/tests/international_isolation_test.sh"
record "international isolation" "$?"

# ---- 8. API integration (Phase 2B) ---------------------------------------
banner "8/11 Integration — API foundation"
bash "$ROOT/tests/api_test.sh"
record "api foundation" "$?"

# ---- 9. Animal Digital Twin (Phase 3) ------------------------------------
#
# This suite runs against the ISOLATED test database, not the development one.
# A dedicated server is started here pointing at dfms_test, so the many
# data-mutating Phase 3 assertions cannot touch development data.
#
# If the test database or its server cannot be brought up, the suite FAILS.
# It does not fall back to the development database silently — that would be
# the exact hazard this isolation exists to remove.
banner "9/11 Integration — Animal Digital Twin (isolated database)"

ANIMAL_PORT="${DFMS_ANIMAL_PORT:-8100}"
ANIMAL_URL="http://localhost:${ANIMAL_PORT}"
ANIMAL_SERVER_PID=""

if curl -s -o /dev/null --max-time 3 "$ANIMAL_URL/index.php" 2>/dev/null; then
    echo "  Reusing the test server already running on :$ANIMAL_PORT"
else
    echo "  Starting an isolated test server on :$ANIMAL_PORT (DB_NAME=dfms_test)"
    DB_NAME=dfms_test "$PHP" -S "localhost:$ANIMAL_PORT" -t "$ROOT" > /tmp/dfms-animal-server.log 2>&1 &
    ANIMAL_SERVER_PID=$!

    for _ in $(seq 1 30); do
        curl -s -o /dev/null "$ANIMAL_URL/index.php" 2>/dev/null && break
        sleep 0.5
    done
fi

if ! curl -s -o /dev/null --max-time 3 "$ANIMAL_URL/index.php" 2>/dev/null; then
    echo "  ERROR: the isolated test server did not start."
    echo "  Create the test database first:  $PHP tools/test-db.php --create"
    cat /tmp/dfms-animal-server.log 2>/dev/null || true
    record "animal digital twin" 1
else
    DFMS_ANIMAL_BASE_URL="$ANIMAL_URL" bash "$ROOT/tests/animal_isolation_test.sh"
    record "animal digital twin" "$?"
fi

# ---- 10. Veterinary & Animal Health (Phase 4) -----------------------------
#
# Shares the isolated test server started for suite 9 rather than starting a
# second one on another port: both suites need exactly the same thing (a server
# pointed at dfms_test), and two servers would be two things to leak.
#
# Like every other integration suite here it FAILS rather than skips when the
# server is unavailable, so a green run can never mean "this did not execute".
banner "10/11 Integration — Veterinary & Animal Health (isolated database)"

if ! curl -s -o /dev/null --max-time 3 "$ANIMAL_URL/index.php" 2>/dev/null; then
    echo "  ERROR: the isolated test server is not available."
    echo "  Create the test database first:  $PHP tools/test-db.php --create"
    record "veterinary health" 1
else
    DFMS_HEALTH_BASE_URL="$ANIMAL_URL" bash "$ROOT/tests/health_isolation_test.sh"
    record "veterinary health" "$?"
fi

# ---- 11. AI Veterinarian Assistant (Phase 5D) -----------------------------
#
# Shares the isolated test server suites 9 and 10 already started. No real
# AI call is made: the test environment has no API key, and asserting the
# unconfigured degraded path is precisely the point.
#
# FAILS rather than skips when the server is unavailable, like every other
# integration suite here.
banner "11/11 Integration — AI Veterinarian Assistant (isolated database)"

if ! curl -s -o /dev/null --max-time 3 "$ANIMAL_URL/index.php" 2>/dev/null; then
    echo "  ERROR: the isolated test server is not available."
    echo "  Create the test database first:  $PHP tools/test-db.php --create"
    record "ai assistant" 1
else
    DFMS_AI_BASE_URL="$ANIMAL_URL" bash "$ROOT/tests/ai_isolation_test.sh"
    record "ai assistant" "$?"
fi

if [ -n "$ANIMAL_SERVER_PID" ]; then
    kill "$ANIMAL_SERVER_PID" 2>/dev/null || true
fi

# ---- summary --------------------------------------------------------------
echo ""
echo "############################################################"
if [ "$SUITES_FAILED" -eq 0 ]; then
    echo "# ALL $SUITES_RUN SUITES PASSED"
else
    echo "# $SUITES_FAILED of $SUITES_RUN SUITES FAILED"
fi
echo "############################################################"
exit "$SUITES_FAILED"
