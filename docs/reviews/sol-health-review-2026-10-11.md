verdict: CHANGES_REQUIRED
finding_count: 4

severity: high  
commit: c1fbd6fd — tests/daily-ledger/daily_ledger_overview_test.php:578-590, 737-749  
claim: The re-anchored tests still prove that the viewer page displays configured net sales and that 0% renders a “Not configured” hint.  
falsified: `nl -ba tests/daily-ledger/daily_ledger_overview_test.php | sed -n '540,640p;735,770p'`  
evidence: “viewer page shows the configured net sales label/value” examines `$branchSummaryHtml`, not `$viewerHtml`. “0% net renders the configuration hint” performs only helper/config assertions and never examines `$zeroNetHtml`; its own comment admits the hint was removed. These are materially weaker assertions retaining their old names.  
impact: A green suite tells the owner that visible net-sales guidance exists when the Health page provides neither the value nor the zero-configuration warning.  
fix: Make the assertions inspect the actual intended rendered surface. If net sales belongs exclusively in Branch Consolidated Summary, verify that report renders the configured label/value and the 0% “Not configured” state, while Health explicitly identifies that destination.

severity: high  
commit: a44edb54 / b8467d61 — templates/modules/daily-ledger/admin/overview.disyl:40-42,92  
claim: The Health view distinctly and honestly renders “no sales recorded,” “pending finalization,” and finalized sales without turning absence or unfinished reporting into PHP 0.00.  
falsified: Directly render `handleAdminOverview()` for tenant 207 with `date_from=date_to=2026-10-11`, `2026-08-16`, and `2026-08-15` using the acceptance scripts’ CLI-bootstrap pattern.  
evidence:  
- 2026-10-11: headline says “Awaiting finalization,” but the pending Rizal branch row renders `PHP 0.00`.  
- 2026-08-16, which has no ledger rows: headline renders `0 units`, `PHP 0.00 official sales`, rather than a distinct “No sales recorded” state.  
- 2026-08-15: finalized data renders `1860 units` and `PHP 4,742.20`, so the finalized branch is reachable and genuinely distinct.  
The test at business_health_view_test.php:72 checks only one exact headline fragment, allowing the pending branch-level zero through and never exercising the no-row state.  
impact: An owner can interpret unfinished reporting as a zero-performing branch, and an unreported day as confirmed zero sales.  
fix: Derive an explicit three-way sales state from total/completed/pending counts. Render “No sales recorded” when total rows are zero, “Awaiting finalization” anywhere an all-pending amount would otherwise appear, and numeric sales only for finalized data.

severity: high  
commit: b8467d61 — modules/daily-ledger/handlers.php:12640-12665; templates/modules/daily-ledger/admin/overview.disyl:42,63  
claim: Directional arrows and percentages appear only when two periods have meaningful, finalized sales to compare.  
falsified: Direct CLI renders for tenant 207 with one-day ranges `2026-08-15`, `2026-08-16`, `2026-09-01`, and `2026-10-10`.  
evidence: `$trendComparable = !$reportingPending` checks only whether the current period is entirely pending. Observed DOM/text:  
- 2026-08-15 versus an empty 2026-08-14: `▲ 0.0%`, `PHP +4,742.20`, previous `PHP 0.00`.  
- 2026-09-01 versus all-pending 2026-08-31: `▲ 0.0%`, `PHP +7,445.37`, previous `PHP 0.00`.  
- No-row 2026-08-16 versus 2026-08-15: `▼ -100.0%`, despite the same page saying the day is “silence, not underperformance.”  
- Mixed 2026-10-10 has 28 pending rows but renders `▼ -24.5%` as real performance.  
The green criterion probes only the all-pending current day and one broad range.  
impact: The owner may treat missing or unfinished reporting as growth or decline and act on a fabricated performance trend.  
fix: Require meaningful finalized data and zero pending rows in both current and prior periods before showing a performance arrow/percentage. Use explicit “No baseline,” “No sales recorded,” or “Pending finalization” states otherwise; never calculate a percentage from a zero/unfinished baseline.

severity: high  
commit: 610e181a — templates/modules/daily-ledger/admin/products-performance.disyl:137-146,171; modules/daily-ledger/helpers/reporting.php:1666-1676  
claim: Reversing cumulative-share direction remains an honest, correctly explained sortable view of the 80/20 line.  
falsified: Render Products Overview with `date_from=2026-09-01&date_to=2026-10-11&sort_by=cumulative&sort_dir=asc` using the acceptance script’s CLI bootstrap.  
evidence: The first row is the least product (`TORTA ORD. BIG`, PHP 0.00, cumulative 0.00%) and the last reaches 100%, so recomputation itself works. However, the page still says “Products ranked, biggest contributor down to least” and says the first row crossing 80% “marks the products that drive 80% of sales.” In ascending order that crossing describes the accumulated long tail—nearly the entire product set—not the biggest products driving 80%. The header link also emits `sort_by=amount`, not `sort_by=cumulative`.  
impact: An owner using ascending mode can mistake the long-tail accumulation for the Pareto contributor set and identify the wrong products as sales drivers.  
fix: Make the heading and threshold explanation direction-dependent. In ascending mode describe the result strictly as long-tail accumulation and do not call its 80% crossing the Pareto driver line; preserve the cumulative-control identity in its URL/state.

weakest_claim: The least defensible claim is that the comparison is “honest.” Comparability is determined solely from the current period’s all-pending condition; empty baselines, pending baselines, no-row current periods, and mixed pending periods all receive authoritative arrows.

checked_and_held: The 42be23cd control-count re-anchor is legitimate. `a836fc2d` predates owner criterion `0cd79ce9`, which predates `42be23cd`; `git merge-base --is-ancestor` returned 0 for both links. One period field becoming two date pickers increases the exact primary-form count from four to five, and the accompanying test became stricter by requiring exactly five, two `type=date` fields, and no `period` field. No other assertion in that 42be23cd diff lost strictness. Legacy-period parsing and picked-range precedence also held. The pie’s rendered top-ten slice steps match differences between cumulative shares, the grey remainder reaches 100%, all ten contributor wedges map to rows, and the aggregate Other wedge correctly maps to none. The pie aria-label plus accessible legend truthfully describe the top-ten aggregate and remainder. All three requested acceptance scripts returned 0 RED, demonstrating the identified oracle gaps rather than disproving them.

unreached: A fully finalized, variance-free, fully closed period producing the overall “Healthy” verdict was not present in the probed tenant data, so that complete verdict branch was not validated. Actual pointer/keyboard interaction was not executed in a browser; hover wiring was inspected in rendered HTML/JavaScript only.
