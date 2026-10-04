#!/usr/bin/env bash
#
# Lane: harpp-attachments
#
# Makes HARPP competitive with an IDE away from the workstation: the owner attaches a FILE
# from the phone (a spec, a receipt, a photo, a CSV) and the harness can fetch it and use it.
#
# Owner's instruction (2026-10-04):
#   "add upload files support so this becomes very competitive as tool outside of IDE's"
#
# MEASURED BASE (chair, 2026-10-04) - the criterion FAILS here, i.e. the capability is absent:
#   bash tools/harpp-attachments-probe.sh          -> exit 1, "no attachment route on either side" (0/0)
#   bash tools/harpp-attachments-probe.sh message  -> exit 0, lists owner POST + bridge GET
#     (so the probe genuinely detects BOTH sides and can reach green - it is not vacuous)
#
# VETTED BEFORE DISPATCH (chair):
#   highest harpp migration is 019_harpp_runner_wake_requests.sql; 020 is FREE (verified).
#   storage/ is OUTSIDE the webroot (public/ is the webroot). Mirrors exist to copy, e.g.
#   modules/academic_similarity, modules/guidance, modules/ticketing all handle $_FILES.
#
set -u
cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

# Canonical chain from tools/model-chain.txt (Sol -> DeepSeek Flash -> Terra).
PROMPT="$(cat <<'PROMPT_EOF'
Add FILE ATTACHMENTS to HARPP so the owner can send a file from a phone, away from the
workstation, and the harness can fetch and use it. This is the feature that makes HARPP
competitive with an IDE away from the workstation. It is also an UPLOAD SURFACE, so the
security rules below are the load-bearing part, not an afterthought.

============================================================
PART 1 - THE FEATURE (both directions)
============================================================
OWNER side (authenticated by the HARPP session, i.e. reachable from the phone):
  - attach a file to a conversation / message from the messenger UI (works in the PWA)
  - the attachment appears on the message and can be opened/downloaded by the owner
HARNESS side (authenticated by the bridge key):
  - list attachments for a conversation
  - download an attachment
  - `tools/harpp-bridge/harpp_client.py` gains list + download helpers that SAVE THE FILE
    INTO THE WORKSPACE, and `tools/harpp-bridge/harpp` (the CLI) gains a command to pull one
    by id, so the chair can go from "owner attached it on their phone" to "file is on disk"
    in one call. That last step is the whole point - do not stop at the API.

============================================================
PART 2 - SECURITY. THIS IS THE PART THAT MATTERS MOST.
============================================================
An unauthenticated or naive file-upload endpoint is the worst defect you could add here.
Every rule below needs a test that FAILS when the guard is removed (falsifiability), not
just a test that passes while the guard is present.

  - PATH TRAVERSAL: a filename like "../../etc/passwd", "..%2f..%2fetc%2fpasswd",
    "/etc/passwd" or "a/../../b" must never influence the path written to. Store under a
    generated name; keep the client name as METADATA ONLY. Test with the traversal names.
  - DANGEROUS TYPES: refuse .php/.phtml/.phar/.htaccess/executables and anything not on an
    explicit ALLOWLIST. Allowlist, never denylist.
  - DO NOT TRUST THE CLIENT MIME: derive the type from the stored bytes where feasible and
    store both claimed and detected.
  - SIZE: an explicit maximum, enforced before the file is written, with a clear error.
    Also refuse a missing/empty upload rather than creating a zero-byte row.
  - STORAGE LOCATION: files go OUTSIDE the webroot (public/ is the webroot - nothing under it).
    Use storage/ (a storage/uploads/harpp/... layout mirroring an existing module's convention).
    Downloads are served ONLY through an authenticated route that checks ownership/tenant -
    never by exposing a filesystem path.
  - AUTHORIZATION: an upload with no session is refused. A download by a different user, or
    against another tenant's row, is refused (empty conversation, wrong tenant, other id).
  - SQL: MySQL 5.7 safe (no CTE, no window function, no JSON_TABLE), ENGINE=InnoDB,
    utf8mb4_unicode_ci, FK column types matching exactly.

============================================================
PART 3 - CONSTRAINTS
============================================================
  - ONE migration: modules/harpp/database/migrations/020_harpp_attachments.sql. 020 IS FREE
    (checked: 019 is the highest). Guarded/idempotent like the others, and registered in
    modules/harpp/module.json.
  - MIRROR an existing upload handler instead of inventing one - modules/academic_similarity,
    modules/guidance, modules/ticketing and modules/contact-form all process $_FILES.
  - DO NOT touch: the decisions backend (a separate, deliberate decision), deploy, workspaces,
    or the existing chat send behaviour. Attachments are ADDITIVE.
  - DO NOT weaken, skip or reorder a test to reach green.
  - No half-wiring: no route without a handler, no handler that is never routed.

============================================================
PART 4 - ACCEPTANCE (run each, paste the actual result)
============================================================
  A. bash tools/harpp-attachments-probe.sh            -> exit 0  (exit 1 on the base)
  B. bash tools/harpp-attachments-probe.sh message    -> exit 0  (control, unchanged)
  C. ROUND TRIP, end to end: upload a file -> the row exists AND the file is on disk ->
     the bridge download returns bytes whose sha256 EQUALS the uploaded file's sha256.
     Paste both hashes. A round trip that only checks "a row exists" does not count.
  D. the PART 2 refusals, each demonstrated, with the mutation you used to prove the guard
     actually bites (state the mutation and the assertion that went red).
  E. bash modules/harpp/tests/run-all.sh              -> ALL HARPP CHECKS PASS
  F. node tools/harpp-ui-verify.js                    -> every page 200, 0 console errors
  G. php ikabud module:validate harpp
  H. php -l on every changed .php; python3 -m py_compile on every changed .py;
     node --check on every changed .js
  I. both logs: storage/logs/app.log and storage/logs/error.log - say what you found

============================================================
PART 5 - REPORT COMPACTLY
============================================================
  status: PASS | FAIL | BLOCKED
  changed:            grouped: migration / service / routes / handlers / ui / client / cli / tests
  owner_flow:         what the owner does on the phone, in steps
  harness_flow:       the exact command that lands the file in the workspace
  security:           each refusal + the mutation that proved it
  verification:       A-I with the actual output, including both sha256 values
  logs:
  risks / unresolved:

If the upload surface cannot be made safe within this scope - or a keeper subsystem breaks -
report BLOCKED with the evidence. A blocker is a correct answer; an insecure upload endpoint
is not.
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/harpp-attachments
rc=$?
echo "lane: harpp-attachments — completed by: ${LANE_MODEL_USED:-<none>} (rc=$rc)"
exit "$rc"
