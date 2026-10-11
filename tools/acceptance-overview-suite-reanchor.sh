cd /var/www/html/applicationostest
php tests/daily-ledger/daily_ledger_overview_test.php >/dev/null 2>&1
F=$(grep -oE '"status": "(fail|error)"' test_results/daily-ledger-overview.json | wc -l)
T=$(grep -oE '"status": "(pass|fail|error)"' test_results/daily-ledger-overview.json | wc -l)
echo "  fail=$F total=$T (floor 104)"
[ "$F" -eq 0 ] && [ "$T" -ge 104 ]
