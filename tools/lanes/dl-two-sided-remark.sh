#!/usr/bin/env bash
#
# Lane: concern #3 — give BOTH branches a surface to record their side of a delivery
# discrepancy. Client, verbatim 2026-11-10:
#
#   "gusto ni sir naay correcting area, ug naay remark both branch about sa issue then
#    makita pod na namo kauban sa variance."
#   "no need makausab ang branch ... need lang naay correcting area both branch ug need
#    naay remarks if unsay nahitabo, then kami ra diri sa office ang makausab."
#
# THE GAP, MEASURED (do not re-derive it, but DO verify before editing):
#   apiSaveDeliveryVarianceRemark (handlers.php ~19279) already exists, already authorizes
#   each SIDE independently against that side's branch, and dl_variance_flags already stores
#   sender_remark / receiver_remark. The variance page already RENDERS both, with author,
#   branch and time. NOTHING CALLS THE ENDPOINT. So the office reads "No sender remark
#   recorded." for ever and neither branch can say what happened.
#
# WHAT TO BUILD — surgical, in the table you already render:
#   On each DELIVERY variance row of admin/variances.disyl (there are two render paths, the
#   list view and the grouped view; fix BOTH), offer an input that lets the current user
#   record THEIR OWN branch's side and posts JSON to
#       /daily-ledger/api/v1/variances/delivery-remark
#   with  { variance_id, side: "sender"|"receiver", remark }.
#
# HARD CONSTRAINTS
#   1. A branch remark records WORDS. It must never change the discrepancy, the quantities,
#      the resolution status or any ledger row. Do not add a correction control.
#   2. Only the side the user's branch actually is may be submitted. The server rejects the
#      wrong side (403, "Only the branch on this side of the delivery may write this
#      remark."). Do not offer a side the user cannot write; if the payload does not expose
#      the branch ids you need, expose them from the handler rather than guessing.
#   3. A user who covers BOTH sides of one delivery (a supervisor assigned to both branches)
#      must be able to write both, clearly labelled - a bare ambiguous box would let the
#      wrong branch's words be recorded as the other side's.
#   4. Both sides must be capturable. One box only is a fail.
#   5. Refresh the row after saving so the stored remark and its author/branch/time appear,
#      the same way the existing office review note does (saves on blur, Enter saves).
#
# STYLE
#   Match the surrounding page exactly - same form-input classes, same row rhythm, same
#   toast/reload behaviour as the existing review-note control on this page. Read that
#   control first and copy its wiring rather than inventing a second pattern.
#
# VERIFY BEFORE YOU FINISH
#   php -l on every PHP file you touch; the DiSyL linter on the template
#   (php _lint_disyl.php templates/modules/daily-ledger/admin/variances.disyl);
#   php tools/checks/dl-two-sided-remark-acceptance.php must print RESULT: PASS;
#   and re-run tests/daily-ledger/daily_ledger_area_and_delivery_remarks_test.php
#   (8/8) plus daily_ledger_variance_test.php (112/112) - they must stay green.
#   Both storage/logs/app.log and storage/logs/error.log must stay at 0 new lines.
#
set -euo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"

source "$HERE/../lane-model.sh"

PROMPT="$(cat <<'PROMPT_EOF'
You are working in /var/www/html/applicationostest, the Ikabud Kernel OS Daily Ledger module
(PHP 8, DiSyL templates, MySQL 5.7). Do NOT run composer/npm installs, do NOT change schema,
do NOT touch any file outside the scope you were given.

TASK: concern #3 — both branches must be able to record their own side of a delivery
discrepancy, without gaining the power to change it.

CLIENT, VERBATIM:
  example: 6 packs of burger dispatched by RIZAL, but PRINCE actually received 4. Prince edits
  the received qty to 4 and the office sees the variance. Sir wants the two branches to settle
  it between themselves: a correcting area and a remark from BOTH branches about what happened,
  visible to the office alongside the variance. The branch must NOT be able to make the
  correction — only the office can.

WHAT ALREADY EXISTS (verify by reading, do not re-implement):
  - apiSaveDeliveryVarianceRemark in modules/daily-ledger/handlers.php (search for it). It
    takes variance_id + side ('sender'|'receiver') + remark, resolves the branch that owns the
    named side, authorizes the caller against THAT branch, and stores sender_remark or
    receiver_remark on dl_variance_flags.
  - templates/modules/daily-ledger/admin/variances.disyl already DISPLAYS both remarks with
    author, branch and timestamp, in two render paths (a list view and a grouped view).
  - The route is already registered:
        /daily-ledger/api/v1/variances/delivery-remark

THE GAP: no template posts to that endpoint. Grep proves it. So neither branch can record
anything and the office reads "No sender remark recorded." indefinitely.

WHAT TO IMPLEMENT:
  In BOTH delivery-variance render paths of admin/variances.disyl, add a control that lets the
  current user record their OWN branch's side of that discrepancy and POST it as JSON to
  /daily-ledger/api/v1/variances/delivery-remark with
      { variance_id: <int>, side: 'sender' | 'receiver', remark: <string> }
  then refresh the row so the saved remark shows with its author/branch/time.

RULES:
 1. WORDS ONLY. The control must not change quantities, resolution status, or any ledger row,
    and must not call any correction or resolution endpoint.
 2. Correct side only. Server decides who may write which side and answers 403 otherwise. Do
    not let a user write the other branch's remark. If the row payload lacks the branch ids you
    need to decide, expose them from the handler (that file is in scope) rather than guessing
    from names.
 3. A user who covers both branches of one delivery must be able to write both, clearly
    labelled per side.
 4. BOTH sides must be capturable. A single box is a fail.
 5. Match the page's existing patterns exactly: read the existing office review-note control on
    the same page and copy its save-on-blur / Enter-to-save / toast / refresh wiring. Reuse
    window.dlWriteTimeout if that is what the page already uses.
 6. DiSyL trap: DiSyL eats { } inside inline HTML event handlers. Put JS in the <script> block
    or use Alpine, never inline handlers with braces.
 7. Empty remark must not be sent; respect the existing max length constant.

ACCEPTANCE (must pass before you stop):
    php tools/checks/dl-two-sided-remark-acceptance.php   ->  RESULT: PASS
    php -l on every PHP file you changed
    php _lint_disyl.php templates/modules/daily-ledger/admin/variances.disyl  -> valid
    php tests/daily-ledger/daily_ledger_area_and_delivery_remarks_test.php   -> 8/8
    php tests/daily-ledger/daily_ledger_variance_test.php                    -> 112/112
    storage/logs/app.log and storage/logs/error.log: no new lines

Report exactly which files you changed and the final output of each command above.
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/lane-dl-two-sided-remark
