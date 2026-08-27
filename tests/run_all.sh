#!/usr/bin/env bash
# ============================================================================
# DFMIS full verification run.
#
#   bash tests/run_all.sh
#
# Runs, in order:
#   1. syntax check   — php -l over every PHP file
#   2. unit tests     — validation layer, no server needed
#   3. integration    — tenant isolation + RBAC (needs the dev server)
#   4. integration    — security regression suite (needs the dev server)
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
banner "1/6  Syntax check (php -l)"
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
banner "2/6  Unit tests — validation layer"
"$PHP" "$ROOT/tests/validation_unit_test.php"
record "validation unit tests" "$?"

# ---- 3. unit: international foundation (Phase 2) --------------------------
# Needs the database (it exercises the settings hierarchy against real rows)
# but not the web server, so it runs before the server check.
banner "3/6  Unit tests — international platform foundation"
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
    echo "Suites run: $SUITES_RUN   failed: $SUITES_FAILED   (3 skipped)"
    # A skipped integration suite is not a pass. Fail loudly so CI cannot go
    # green just because nobody started the server.
    exit 1
fi

# ---- 3. tenant isolation --------------------------------------------------
banner "4/6  Integration — tenant isolation + RBAC"
bash "$ROOT/tests/tenant_isolation_test.sh"
record "tenant isolation" "$?"

# ---- 4. security regression ----------------------------------------------
banner "5/6  Integration — security regression"
bash "$ROOT/tests/security_regression_test.sh"
record "security regression" "$?"

# ---- 6. international isolation (Phase 2) --------------------------------
banner "6/6  Integration — international settings isolation"
bash "$ROOT/tests/international_isolation_test.sh"
record "international isolation" "$?"

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
