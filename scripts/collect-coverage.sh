#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────
# Code Coverage Collection Script
#
# Usage:
#   bash scripts/collect-coverage.sh              # All tests
#   bash scripts/collect-coverage.sh tests/foo.php # Single test file
#
# Requires: pcov or xdebug extension
# ─────────────────────────────────────────────────────────────────────────

set -euo pipefail

cd "$(dirname "$0")/.."

COVERAGE_DIR="storage/coverage"
mkdir -p "$COVERAGE_DIR"

# Detect coverage driver
if php -m 2>/dev/null | grep -qi pcov; then
    DRIVER="pcov"
    DRIVER_FLAGS="-d pcov.enabled=1 -d pcov.directory=$(pwd)/kernel -d pcov.directory=$(pwd)/src -d pcov.directory=$(pwd)/modules"
elif php -m 2>/dev/null | grep -qi xdebug; then
    DRIVER="xdebug"
    DRIVER_FLAGS="-d xdebug.mode=coverage"
else
    echo "ERROR: No coverage driver found. Install pcov (recommended) or xdebug."
    echo "  pecl install pcov"
    exit 1
fi

echo "Coverage driver: $DRIVER"
echo "Output directory: $COVERAGE_DIR"
echo ""

if [ $# -gt 0 ]; then
    TEST_FILES=("$@")
else
    mapfile -t TEST_FILES < <(find tests/ -name '*.php' -type f | sort)
fi

PASS=0
FAIL=0

for f in "${TEST_FILES[@]}"; do
    echo -n "  Running $f ... "
    if php $DRIVER_FLAGS "$f" > /dev/null 2>&1; then
        echo "OK"
        ((PASS++))
    else
        echo "FAILED"
        ((FAIL++))
    fi
done

echo ""
echo "Coverage collection complete: $PASS passed, $FAIL failed"
echo "Coverage reports written to: $COVERAGE_DIR/"

if [ "$FAIL" -gt 0 ]; then
    exit 1
fi
