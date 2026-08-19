#!/usr/bin/env bash
# Tenant isolation + IDOR + RBAC regression suite.
#
# Verifies that a user of one farm cannot read, edit or delete another
# farm's data by any route — URL id, POST body, or list page — and that
# role permissions are enforced server-side rather than only hidden in UI.
#
# Requires the dev server on :8000 and the seeded demo database.
#   bash tests/tenant_isolation_test.sh

BASE="${DFMS_BASE_URL:-http://localhost:8000}"
TMP=$(mktemp -d)
PASS=0
FAIL=0

login() { # login <user> <pass> <cookiejar>
    local token
    token=$(curl -s -c "$3" "$BASE/index.php" | sed -n 's/.*name="csrf_token" value="\([^"]*\)".*/\1/p' | head -1)
    curl -s -b "$3" -c "$3" -X POST \
        -d "csrf_token=$token&username=$1&password=$2" \
        "$BASE/index.php" -o /dev/null
}

csrf_for() { # csrf_for <url> <cookiejar>
    curl -s -b "$2" -c "$2" "$1" | sed -n 's/.*name="csrf_token" value="\([^"]*\)".*/\1/p' | head -1
}

check_absent() { # check_absent <label> <file> <needle>
    if grep -q "$3" "$2"; then
        echo "  FAIL  $1 — leaked '$3'"
        FAIL=$((FAIL + 1))
    else
        echo "  pass  $1"
        PASS=$((PASS + 1))
    fi
}

check_status() { # check_status <label> <actual> <expected>
    if [ "$2" = "$3" ]; then
        echo "  pass  $1 (HTTP $2)"
        PASS=$((PASS + 1))
    else
        echo "  FAIL  $1 — expected HTTP $3, got $2"
        FAIL=$((FAIL + 1))
    fi
}

echo "== Tenant isolation: farm 1 user vs farm 2 data (cow_id=7) =="
JAR="$TMP/admin.txt"
login admin admin123 "$JAR"

CODE=$(curl -s -b "$JAR" -o "$TMP/view.html" -w "%{http_code}" "$BASE/modules/cows/view.php?id=7")
check_status "view.php?id=7 rejected" "$CODE" "404"
check_absent "view.php body" "$TMP/view.html" "RIVAL001"

CODE=$(curl -s -b "$JAR" -o "$TMP/edit.html" -w "%{http_code}" "$BASE/modules/cows/edit.php?id=7")
check_status "edit.php?id=7 rejected" "$CODE" "404"

CODE=$(curl -s -b "$JAR" -o "$TMP/del.html" -w "%{http_code}" "$BASE/modules/cows/delete.php?id=7")
check_status "delete.php?id=7 rejected" "$CODE" "404"

curl -s -b "$JAR" -o "$TMP/list.html" "$BASE/modules/cows/"
check_absent "cow list excludes other farm" "$TMP/list.html" "RIVAL001"

echo ""
echo "== IDOR via POST body: attach health event to another farm's cow =="
TOKEN=$(csrf_for "$BASE/modules/health/add.php" "$JAR")
curl -s -b "$JAR" -X POST \
    -d "csrf_token=$TOKEN&cow_id=7&event_date=2026-01-01&event_type=Treatment&severity=urgent&description=idor-probe" \
    "$BASE/modules/health/add.php" -o "$TMP/post.html"
check_absent "rejected foreign cow_id in POST" "$TMP/post.html" "added successfully"

echo ""
echo "== RBAC: veterinarian may not create or delete cows =="
VET="$TMP/vet.txt"
login manager manager123 "$VET"
CODE=$(curl -s -b "$VET" -o /dev/null -w "%{http_code}" "$BASE/modules/cows/add.php")
check_status "vet blocked from cows/add.php" "$CODE" "403"
CODE=$(curl -s -b "$VET" -o /dev/null -w "%{http_code}" "$BASE/modules/cows/delete.php?id=1")
check_status "vet blocked from cows/delete.php" "$CODE" "403"
CODE=$(curl -s -b "$VET" -o /dev/null -w "%{http_code}" "$BASE/modules/health/add.php")
check_status "vet allowed health/add.php" "$CODE" "200"

echo ""
echo "== RBAC: staff may not delete cows or edit alerts =="
STAFF="$TMP/staff.txt"
login staff staff123 "$STAFF"
CODE=$(curl -s -b "$STAFF" -o /dev/null -w "%{http_code}" "$BASE/modules/cows/delete.php?id=1")
check_status "staff blocked from cows/delete.php" "$CODE" "403"
CODE=$(curl -s -b "$STAFF" -o /dev/null -w "%{http_code}" "$BASE/modules/health/add.php")
check_status "staff blocked from health/add.php" "$CODE" "403"
CODE=$(curl -s -b "$STAFF" -o /dev/null -w "%{http_code}" "$BASE/modules/cows/add.php")
check_status "staff allowed cows/add.php" "$CODE" "200"

echo ""
echo "== Unauthenticated access is redirected to login =="
CODE=$(curl -s -o /dev/null -w "%{http_code}" "$BASE/dashboard.php")
check_status "anonymous dashboard redirected" "$CODE" "302"
CODE=$(curl -s -o /dev/null -w "%{http_code}" "$BASE/modules/cows/")
check_status "anonymous cow list redirected" "$CODE" "302"

rm -rf "$TMP"
echo ""
echo "================================"
echo " passed: $PASS   failed: $FAIL"
echo "================================"
[ "$FAIL" -eq 0 ]
