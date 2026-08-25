#!/usr/bin/env bash
# Consolidated HARPP module test runner. Any test, lint, validation, or error.log finding fails.
set -u
cd "$(dirname "$0")/../../.." || exit 2
ROOT="$PWD"
fails=0
for t in phase2 phase3 phase4 phase5 review_remediation; do
  printf '=== %s_cli_test ===\n' "$t"
  : > "$ROOT/storage/logs/app.log"
  : > "$ROOT/storage/logs/error.log"
  if php "modules/harpp/tests/${t}_cli_test.php"; then echo "PASS ${t}_cli_test"; else echo "FAIL ${t}_cli_test"; fails=$((fails+1)); fi
  if [ -s "$ROOT/storage/logs/error.log" ]; then
    echo "FAIL ${t}_cli_test: error.log is non-empty"
    fails=$((fails+1))
  fi
done
printf '=== module:validate harpp ===\n'
if php ikabud module:validate harpp; then echo "PASS module:validate harpp"; else echo "FAIL module:validate harpp"; fails=$((fails+1)); fi
printf '=== lint ===\n'
for f in modules/harpp/*.php modules/harpp/services/*.php modules/harpp/tests/*.php; do php -l "$f" >/dev/null 2>&1 || { echo "LINT FAIL $f"; fails=$((fails+1)); }; done
for f in modules/harpp/assets/*.js; do node --check "$f" >/dev/null 2>&1 || { echo "JS LINT FAIL $f"; fails=$((fails+1)); }; done
printf '=== lint: currentTarget-after-await guard ===\n'
# e.currentTarget is null after an await (event dispatch has ended); any .reset()/.value
# on it after an await crashes the PWA UI. Capture the element before the await instead.
if grep -qE 'await [^;]{0,200}?currentTarget' modules/harpp/assets/*.js; then
  echo 'FAIL currentTarget used after await in JS asset'
  grep -nE 'await [^;]{0,200}?currentTarget' modules/harpp/assets/*.js
  fails=$((fails+1))
else
  echo 'PASS no currentTarget-after-await usage'
fi
if [ "${HARPP_TEST_INJECT_ERROR_LOG:-0}" = "1" ]; then echo 'injected runner gate test' >> "$ROOT/storage/logs/error.log"; fi
if [ -s "$ROOT/storage/logs/error.log" ]; then echo "FAIL error.log is non-empty ($(wc -l < "$ROOT/storage/logs/error.log") lines)"; fails=$((fails+1)); else echo 'PASS error.log is empty'; fi
if [ "$fails" -eq 0 ]; then echo "ALL HARPP CHECKS PASS"; exit 0; else echo "HARPP CHECKS FAILED: $fails"; exit 1; fi
