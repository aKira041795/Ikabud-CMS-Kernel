#!/usr/bin/env bash
#
# Lane: viewer-products-pe-controls
#
# Source: docs/daily-ledger/VIEWER-SURFACE-ASSESSMENT-PLAN-2026-10-12.md — §4 F4/F8/F9,
# §6 W2 (products overview: one sorting mechanism) / W7 (empty-state copy) / W8 (PE single apply),
# §11 chair rulings D1/D2 (one active apply model per page).
#
# Criterion (3 RED on the base, proven by the dispatch gate):
#   tools/acceptance-viewer-products-pe-controls.php
#
# Owner directive driving this dispatch (2026-10-12): delegate to Sol until rate is exhausted.

cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

LANE_MODEL_CHAIN="openai-codex/gpt-5.6-sol,deepseek-v4-flash,openai-codex/gpt-5.6-terra"

PROMPT="$(cat <<'PROMPT_EOF'
You are working in the Ikabud repo at /var/www/html/applicationostest. PHP 8.5 local; production is
MySQL 5.7 (label any 5.7 claim INSPECTION-ONLY). Local site http://baronledger.test runs tenant 207
(a copy of production); the surface under review is the daily-ledger module seen as role=viewer
(admin_view / viewer123).

# CONTEXT (read first)

- docs/daily-ledger/VIEWER-SURFACE-ASSESSMENT-PLAN-2026-10-12.md - §4 F4/F8/F9, §6 W2/W7/W8,
  §11 D1/D2. The design is SETTLED; do not re-litigate it.
- tools/acceptance-viewer-products-pe-controls.php - YOUR ORACLE. 3 RED right now. Make it 0 RED
  WITHOUT weakening a single assertion - C2 (the cumulative explainer stays) and C5 (the movement
  control round-trips) are regression pins that must STAY green.

# REQUIRED OUTCOMES

W2 (F4) - templates/modules/daily-ledger/admin/products-performance.disyl:
  The Products Overview currently has THREE sorting mechanisms. Keep exactly one: the Rank-by +
  Order selects in the filter form.
  a) Delete the prose link at :145 ('Build it from the least/biggest instead') - the whole anchor.
     The explainer block around :139-144 stays; make sure the surrounding sentence still reads
     cleanly after the anchor is gone.
  b) The 'Cumulative share' column header at :171 is a sort link emitting sort_by=cumulative
     (which is not a cumulative sort - the server maps it to amount). Replace it with plain-text
     'Cumulative share'; if you keep a title tooltip, reword it so it does not talk about clicking.
  c) No anchor on the page may emit sort state (no href containing 'sort_by=').
  d) Server-side normalization is NOT yours to change: an incoming legacy sort_by=cumulative URL may
     keep resolving; only the EMISSION stops.
  e) If cumulative_toggle_dir (handler render context) becomes unread by the template, remove it
     from the context only after proving nothing else reads it; when in doubt, leave it.
     ranking_from is still read by the explainer text - keep it.

W7 (F8) - same template, :62: the empty state says '... Widen the date range or include pending
  rows.' There is no such control on this page (the handler forces pending_rows_mode='exclude',
  handlers.php:13003). Remove ' or include pending rows'; keep the date-range guidance.

W8 (F9) - templates/modules/daily-ledger/admin/production-efficiency.disyl, :37: the Movement select
  auto-submits (onchange="this.form.submit()") while the form has its own Apply button at :46 -
  two apply models on one page, and the immediate submit silently applies any other unapplied
  change. Remove the onchange; Movement must keep round-tripping through Apply (asserted).

# CONSTRAINTS

- Files you may change: templates/modules/daily-ledger/admin/products-performance.disyl,
  templates/modules/daily-ledger/admin/production-efficiency.disyl; plus (optionally, per W2e) the
  handleAdminProductsPerformance render context in modules/daily-ledger/handlers.php.
- Do NOT modify the oracle, other tools/acceptance-*.php, or tests/**. If an existing oracle/test
  disagrees with the settled design, report it as unresolved - the harness re-anchors oracles after
  the lane when a replacement invalidated one (that protocol was used twice already today).
- MySQL 5.7: no window functions / CTEs / JSON_TABLE (not expected - this is a template change).
- Do NOT git add / git commit; leave the tree for review.
- DiSyL lint: `php _lint_disyl.php` must stay at 703/703.

# VERIFY (report the actual output lines)

1. php tools/acceptance-viewer-products-pe-controls.php   -> must end '0 RED'
2. php tools/acceptance-products-overview.php             -> must stay '0 RED'
3. php tools/acceptance-production-efficiency.php         -> must stay exit 0 / '0 RED'
4. php _lint_disyl.php                                    -> 703/703

# REPORT (exactly this shape)

status: PASS|FAIL|PARTIAL|BLOCKED
changed: file:line each
design_calls: the header/tooltip decision; any handler render-context removal
verification: the four commands' result lines, verbatim
unresolved:
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/lane-viewer-products-pe-controls
