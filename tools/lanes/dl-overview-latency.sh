#!/usr/bin/env bash
#
# Lane: Health view latency, root cause.
#
# Owner, 2026-11-10: "for item 2 health view, fix and fix. do it at the root cause/s. might be
# kernel related or templateengine, disyl, no idea."
#
# THE PAGE IS UP AGAIN BUT SLOW. It used to be a 500 (memory exhaustion); that is already fixed
# (692ee2a8, 13d4277c). What remains is wall-clock: ~8.08s for a bare /daily-ledger/admin/overview
# as an admin, against a 3.00s budget.
#
# MEASURED, do not re-derive, but DO re-measure before and after your change:
#   In-process render of handleAdminOverview, default view, tenant 207 (a real 76-81 KB page):
#     completeness               0.13s
#     branchSignals              0.53s
#     forecastRows              10.53s   <-- almost the entire cost
#     priorAmount                0.02s
#     priorCompleteness          0.01s
#     (everything between the forecast and the template)   2.57s
#     total to render           13.77s   on the page's wider default range
#   Inside dl_forecastRows(), over the same window:
#     cells query                1.83s    SELECT DISTINCT ... plus the official-only predicate
#     movement query             1.13s    GROUP BY branch/product/shift
#     inventory query            1.06s    MAX(CONCAT(date,shift)) self-join
#     variance query             0.01s
#     dl_productionEfficiencyData  ~3.9s  (called with the same history window, for spoilage rates)
#   Tables are SMALL: dl_daily_ledger is 179,460 rows and already has idx_dl_branch_date and
#   idx_dl_date. Missing indexes are NOT the problem; the cost is repeated row processing of the
#   same window by several queries in sequence.
#
# WHAT IS ALREADY KNOWN AND MUST NOT BE REPEATED
#   * Replacing the official predicate's correlated EXISTS with a precomputed branch list was TESTED
#     AND REJECTED: it changed the row count (975 vs 977), so it is not an equivalent rewrite.
#   * Memoising dl_productionEfficiencyData per request does not help: the page calls it with two
#     different windows.
#   * A GROUP BY ordering bug was fixed in 13d4277c. Do not "fix" it again; verify filters still
#     apply (branch_id, product_id, shift) before and after your change.
#
# WHAT TO DO
#   Find where the same window is computed more than once and make it be computed once. The forecast
#   is a STATISTICAL aggregate: it needs per branch+product+shift sums and day counts, not a
#   materialised row per ledger cell, and it does not need the whole production-efficiency model to
#   learn a spoilage rate per product. Reduce the work, do not cache a slow path.
#
# HARD CONSTRAINTS
#   1. NUMBERS MUST NOT CHANGE. The forecast is a financial projection. Every row it produces for a
#      given input must be identical before and after. If you cannot prove that, do not ship it.
#   2. Do not weaken validation, authorization, area scope, or the official/provisional distinction.
#   3. Do not touch the template, DiSyL, or the kernel. If you believe the root cause is outside
#      modules/daily-ledger/helpers/reporting.php and modules/daily-ledger/handlers.php, STOP and say
#      so with evidence instead of editing outside scope.
#   4. Prefer deleting redundant work over adding a cache. If you add a cache, it must be keyed by
#      the full filter signature and must not survive a settings change within one request.
#   5. MySQL 5.7 only: no CTEs, no window functions, no JSON_TABLE. Under only_full_group_by, a
#      grouped column that the join coerces needs ANY_VALUE().
#   6. Leave both logs clean.
#
# ACCEPTANCE (must pass before you stop):
#    php tools/checks/dl-overview-latency-acceptance.php        -> RESULT: PASS  (budget 3.00s)
#    php tests/daily-ledger/daily_ledger_forecast_test.php      -> 23/23
#    php tests/daily-ledger/daily_ledger_overview_test.php      -> 106/106
#    php tests/daily-ledger/daily_ledger_reporting_test.php     -> 76/76
#    php tests/daily-ledger/daily_ledger_withdrawal_attribution_test.php -> 11/11
#    php -l on every PHP file you changed
#    storage/logs/app.log and storage/logs/error.log: no new lines
#
# Report: the before/after acceptance numbers, each suite's final line, the query or queries you
# removed or merged, and an explicit statement of how you proved the forecast numbers are unchanged.

set -euo pipefail
cd "$(dirname "$0")/../.."
source tools/lane-model.sh

PROMPT="$(cat <<'PROMPT_EOF'
You are fixing the LATENCY of the Daily Ledger Health view (/daily-ledger/admin/overview) at the root
cause. The page already renders correctly; it is too slow.

Read tools/checks/dl-overview-latency-acceptance.php first - that is the oracle. It renders the real
handler in-process and prints RESULT: PASS/FAIL against a 3.00s budget. It currently FAILS at ~8s.

The measured profile is in the header of tools/lanes/dl-overview-latency.sh. Use it to start, but
re-measure yourself before and after:

    php tools/checks/dl-overview-latency-acceptance.php

Scope: modules/daily-ledger/helpers/reporting.php and modules/daily-ledger/handlers.php ONLY.
Do not touch templates, DiSyL, the kernel, or any other module.

The dominant cost is dl_forecastRows(). It issues several separate passes over the same history
window (cells, movement, inventory, variance) and additionally calls dl_productionEfficiencyData()
over that window just to read one spoilage rate per product. Collapse the redundant passes and stop
paying for a full efficiency model when a much smaller aggregate answers the question.

NON-NEGOTIABLE: the forecast numbers must be IDENTICAL. Before you change anything, capture the
current output for a fixed input (same tenant, same window, same branch filter) and after your change
diff it field by field. State how you did that. A faster page that projects different units is worse
than a slow one.

MySQL 5.7 only. Under only_full_group_by, a grouped column whose collation the join coerces needs
ANY_VALUE(). Optional filters must be appended BEFORE the GROUP BY clause - appending them after it
does not error, MySQL parses them as grouping expressions and filters nothing (that bug shipped once
already and was fixed in 13d4277c).

Do not weaken validation, authorization, area scope, or the official/provisional distinction.

ACCEPTANCE, all must pass:
    php tools/checks/dl-overview-latency-acceptance.php        -> RESULT: PASS
    php tests/daily-ledger/daily_ledger_forecast_test.php      -> 23/23
    php tests/daily-ledger/daily_ledger_overview_test.php      -> 106/106
    php tests/daily-ledger/daily_ledger_reporting_test.php     -> 76/76
    php tests/daily-ledger/daily_ledger_withdrawal_attribution_test.php -> 11/11
    php -l on every changed PHP file
    both logs clean

If the root cause turns out to be outside your two files, STOP and report that with evidence instead
of editing outside scope.
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/lane-dl-overview-latency
