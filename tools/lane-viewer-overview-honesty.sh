#!/usr/bin/env bash
#
# Lane: viewer-overview-honesty
#
# Source: docs/daily-ledger/VIEWER-SURFACE-ASSESSMENT-PLAN-2026-10-12.md — §4 F5/F6/F7/F10/F11,
# §6 W3/W4/W5/W6/W15, §11 chair rulings D4 (rename verdict -> "Ledger Control Status"),
# D4b (statistics that do not lie: coverage beside aggregates, ledger-control vocabulary),
# D5 (read-only rename table).
#
# Criterion (8 RED on the base, proven by the dispatch gate):
#   tools/acceptance-viewer-overview-honesty.php
#
# Owner directive driving this dispatch (2026-10-12): "delegate to sol some code edits until rate
# is exhausted." Standard chain kept for mid-lane fallback; the run reports the model that completed.

cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

LANE_MODEL_CHAIN="openai-codex/gpt-5.6-sol,deepseek-v4-flash,openai-codex/gpt-5.6-terra"

PROMPT="$(cat <<'PROMPT_EOF'
You are working in the Ikabud repo at /var/www/html/applicationostest. PHP 8.5 local; production is
MySQL 5.7 (label any 5.7 claim INSPECTION-ONLY). Local site http://baronledger.test runs tenant 207
(a copy of production); the surface under review is the daily-ledger Business Health page
(/daily-ledger/admin/overview) seen as role=viewer (admin_view / viewer123). All renders in the
oracle run as viewer on tenant 207 and are DETERMINISTIC:
  R0 default (today 2026-10-12)   -> 0 recorded / 22 silent; verdict cause = CLOSURE
  R2 (2026-10-11..10-11)          -> cause = PENDING ("103 ledger ending(s) are pending; Rizal has 103")
  R4 (branch 8, 2026-10-05..10-11)-> sales finalized; page's own aggregate forecast target = 2026-10-12
  R5 (all, 2026-09-28..10-11)     -> 7 recorded / 15 silent (the multi-branch coverage shape)

# CONTEXT (read first)

- docs/daily-ledger/VIEWER-SURFACE-ASSESSMENT-PLAN-2026-10-12.md - §4 F5-F7/F10-F11, §6 W3-W6/W15,
  §11 D4/D4b/D5. The design is SETTLED; do not re-litigate it.
- tools/acceptance-viewer-overview-honesty.php - YOUR ORACLE. 8 RED right now. Make it 0 RED WITHOUT
  weakening a single assertion; editing the oracle means rejection. Its comments explain each check
  and quote the chair rulings it encodes.

# REQUIRED OUTCOMES (per oracle check)

H1 (W5/D4). The verdict section is renamed. Template overview.disyl:84 marker
  '2 · Is it healthy?' -> '2 · Ledger Control Status' (keep the 'N · Label' convention; do NOT
  rename the other section markers - 3 · Why? / 4 · / 5 · are slice anchors).

H2 (D4b). The verdict uses ledger-control vocabulary and cites coverage. In the verdict card
  (overview.disyl:85-91) and its computation (handlers.php:12741-12756 for values/reasons, :91 basis
  note in the template):
  - values move from 'Healthy'/'Needs attention' to the ruled vocabulary: Complete / Attention
    required / Incomplete / Unknown (choose per the gate that fails; document the mapping in the
    code). The words 'Healthy' and 'Needs attention' must be GONE from the verdict card - the basis
    note currently quotes "Healthy", so rewrite it in the new vocabulary.
  - when coverage is unestablished (silent branches > 0 - true on this tenant in both test renders),
    the verdict card cites it: 'N of M branches' and/or the word 'coverage' (e.g. "recording
    coverage: 0 of 22 branches; completeness not established"). D4b: no green/Complete verdict while
    coverage is unestablished.
  - basis note keeps the honest disclaimer (no target/budget exists in this module; not a verdict
    against plan).

H3 (W3/F6). The verdict-card action routes to the NAMED cause, not always to Variances. Template
  :89 currently renders 'Inspect {health_lead.name}' -> /admin/reports/variances unconditionally.
  Compute a cause-matched destination in the handler (mirror the opportunity mapping at
  handlers.php:12762-12782): pending cause -> /admin/reports/data-integrity; variance cause ->
  /admin/reports/variances; closure/open-day cause -> /admin/reports/branch-summary. Label it
  'View branch details' (D5; appending the branch name is fine). Keep date + branch_id params.
  R0 (closure) must link branch-summary; R2 (pending) must link data-integrity.

H4 (W4/D5). Section 5 card (overview.disyl:142): title 'Resolve what blocks the verdict' ->
  'Issues affecting ledger completion' (exact phrase, D5). Body "Finalize pending endings, inspect
  variance evidence, and verify day closure at the named branch." becomes a description of the
  unresolved conditions (no /\bFinalize\b/ verb, no 'Resolve', no 'Complete pending'). Keep the
  cause-matched primary CTA. The unconditional 'Variance report' secondary button is the same
  always-Variances pattern F6 flags but the oracle does not pin it: either make it cause-conditional
  or drop it, and say which you chose in your report.

H8 (D5, handler side). handlers.php:12764 opportunity title 'Complete pending endings at {name}' ->
  a description, e.g. '{name} - branch endings awaiting completion'. Apply the same descriptive rule
  to its siblings while you are there (:12769 'Resolve variance attention at ...', :12779 'Check
  recording coverage at ...'; :12775 'Review headroom at ...' may stay if you judge it a comparison
  statement - report your call). Only the pending title is asserted.

H5 (W3/F5, chair D5: keep, fix period). Template :10 renders 'Detailed sales analysis' in the page
  header, beside the all-periods lifetime headline, and opens a 0-row report for today. Remove it
  from the header (no /reports/sales link between the header block and id="dl-all-periods-summary")
  and provide a 'View sales report' control that carries the page's period (date_from/date_to as
  rendered). Natural options: relocate a period-labelled link into/near the period 'Gross sales'
  card, or relabel the section-5 'Sales report' CTA to 'View sales report' (it already carries the
  period and can stay where it is). Any placement outside the lifetime header with correct params
  passes.

H6 (W6/F10). Template :143: 'Detailed forecast' -> 'View production forecast' (D5), and the href
  must carry the viewing context: {base_url}/admin/forecast?scope={view_scope}&branch_id={branch_id}
  &target_date={forecast_target_date} - the SAME target the page's own aggregate forecast header
  already shows ({forecast_target_date} is in context; it is date_to + 1 day).

H7 (W15a/D4b). The aggregate must name branch coverage. In the period 'Gross sales' card
  (overview.disyl:79 area) or the 'Branches in scope' card (:76), state 'N of M branches' for
  recorded activity (the counts already exist in context: working_branches/silent_branches/
  scoped_branch_count - R5 renders '7 recorded / 15 silent', so '7 of 22 branches...'). When
  endings are still pending, add a completeness qualifier consistent with D4b ('completeness not
  established while N endings await finalization' or equivalent).

# CONSTRAINTS

- Files you may change: templates/modules/daily-ledger/admin/overview.disyl and the
  handleAdminOverview region of modules/daily-ledger/handlers.php (roughly lines 12440-12960).
  Nothing else. Not the layout, not other pages, not the oracle.
- Do NOT edit tools/acceptance-*.php or tests/** to reach PASS. If an existing oracle/test disagrees
  with the settled design, report it as unresolved - the harness re-anchors oracles itself (this
  happened for the forecast sort lane; oracles that pin replaced mechanisms are re-anchored AFTER
  the lane, by the harness).
- MySQL 5.7: no window functions / CTEs / JSON_TABLE. InnoDB + utf8mb4 conventions.
- Do NOT git add / git commit; leave the tree for review.
- DiSyL lint: `php _lint_disyl.php` must stay at 703/703.

# VERIFY (report the actual output lines)

1. php tools/acceptance-viewer-overview-honesty.php        -> must end '0 RED'
2. php tools/acceptance-overview-all-periods-summary.php   -> must stay exit 0 / '0 RED'
3. php tests/daily-ledger/daily_ledger_overview_test.php   -> must stay green (0 fail; it is the
   104-test suite for this page)
4. php _lint_disyl.php                                     -> 703/703

# REPORT (exactly this shape)

status: PASS|FAIL|PARTIAL|BLOCKED
changed: file:line each
design_calls: the verdict-state mapping; the section-5 secondary button choice; where the
  'View sales report' entry now lives
verification: the four commands' result lines, verbatim
unresolved:
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/lane-viewer-overview-honesty
