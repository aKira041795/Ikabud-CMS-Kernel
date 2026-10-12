#!/usr/bin/env bash
#
# Lane: viewer-reports-archive
#
# Source: docs/daily-ledger/VIEWER-SURFACE-ASSESSMENT-PLAN-2026-10-12.md — §4 F15/F16 (claims,
# now verified), §6 W11, §12 W17; chair ruling §11 D3 (an audit row documenting a download is
# compatible with read-only access; hiding write controls is not authorization).
#
# Verified truth this lane builds on (2026-10-12):
#   * ReportManager::archiveReport() COPIES the finished file to storage/report-archive/<id>.<format>
#     and keeps it; the module's @unlink is only the audit-rollback path.
#   * dl_handleAdminReportExport() already authorizes the viewer at the endpoint
#     (dlRequireAuth includes viewer + ReportManager::canExport gates generation). The criterion
#     proves that end to end (P1).
#   * So the archive table's missing action (F15) is a missing GOVERNED CONTROL: the bytes exist and
#     can be served under the same permissions, tenant-scoped, with a download audit row.
#
# Criterion (3 RED on the base, proven by the dispatch gate):
#   tools/acceptance-viewer-reports-archive.php
#
# Owner directive driving this dispatch (2026-10-12): delegate to Sol until rate is exhausted.

cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

LANE_MODEL_CHAIN="openai-codex/gpt-5.6-sol,deepseek-v4-flash,openai-codex/gpt-5.6-terra"

PROMPT="$(cat <<'PROMPT_EOF'
You are working in the Ikabud repo at /var/www/html/applicationostest. PHP 8.5 local; production is
MySQL 5.7. Local site http://baronledger.test runs tenant 207 (a copy of production); the surface is
the daily-ledger Reports area seen as role=viewer.

# CONTEXT (read first)

- docs/daily-ledger/VIEWER-SURFACE-ASSESSMENT-PLAN-2026-10-12.md - §4 F15/F16, §6 W11, §11 D3,
  §12 W17. The design is SETTLED; do not re-litigate it.
- tools/acceptance-viewer-reports-archive.php - YOUR ORACLE. 3 RED right now (P2 control, P3
  endpoint, P4 anonymous refusal). Make it 0 RED WITHOUT weakening it. P1 (viewer export succeeds
  and archives), P5 (unknown id -> clean refusal) and P6 (the page states exports are archived and
  audited) are pins that must stay green. The oracle seeds a probe viewer, creates a REAL archive
  via HTTP, downloads it, and cleans up; run it as-is.
- The kernel primitives you must use: ReportManager::getArchivedReport($id) (kernel/Services/
  ReportManager.php) returns the stored metadata (with 'file', 'format', 'filename', 'entity_type',
  'title'); dl_reportArchiveVisibleToTenant($archive, dl_reportTenantScope()) is the EXISTING
  tenant/ownership predicate the reports page already filters by (helpers/reporting.php ~108).

# REQUIRED OUTCOME

1. A governed download endpoint for archived reports:
   - modules/daily-ledger/routes.php: add a GET route
     '/daily-ledger/admin/reports/archive-download' => 'daily-ledger:handleAdminReportArchiveDownload'
     (place it with the other report routes).
   - modules/daily-ledger/handlers.php: implement that handler with EXACTLY these safety properties:
     a) Authorization at the endpoint, not by hiding buttons:
        $user = dlRequireAuth(['admin', 'supervisor', 'auditor', 'viewer']);
     b) id allowlist: accept only /^rpt_[A-Za-z0-9.]+$/ (the kernel's uniqid('rpt_', true) shape);
        anything else -> 404. Never interpolate the raw id into a filesystem path.
     c) Load via ReportManager::getArchivedReport($id); missing -> 404.
     d) Tenant/ownership: dl_reportArchiveVisibleToTenant($archive, dl_reportTenantScope()) must
        hold, else 404 (do not confirm existence to other tenants).
     e) Permission: ReportManager::canExport((string)$archive['entity_type'], (string)$archive['format'], $user)
        must hold, else 403.
     f) Path safety: resolve the stored file and require it INSIDE storage/report-archive:
        $root = realpath(STORAGE_PATH . '/report-archive'); $real = realpath((string)$archive['file']);
        require $real !== false && $root !== false && str_starts_with($real, $root . DIRECTORY_SEPARATOR)
        && is_file($real); else 404. (An archive whose metadata was tampered with must never leak an
        arbitrary path.)
     g) Serve it: Content-Type application/pdf for pdf, text/csv for csv; Content-Disposition:
        attachment with basename((string)($archive['filename'] ?? $real)); Content-Length; readfile.
        Do NOT unlink - this IS the archive.
     h) Audit the download (D3: compatible with read-only access): dl_auditLog('report_archive_download',
        null, (string)$archive['entity_type'], $id, null, ['format' => ..., 'filename' => ...]).
2. modules/daily-ledger/... the UI control: templates/modules/daily-ledger/admin/reports.disyl's
   "Recent Archived Reports" table (~lines 25-31) gets an action per row linking to
   {base_url}/admin/reports/archive-download?id={item.id} (a small outlined/link control labelled
   Download). Keep the existing columns and the "Exports are archived and audited" statement.
3. W17 documentation duty: if the per-report view in the same template renders the Download CSV /
   PDF buttons, add ONE short line beside them stating that each download is archived (listed in
   Recent Archived Reports) and audited - the viewer is allowed to export, and its persistence is
   now stated rather than implied.

# CONSTRAINTS

- Files you may change: modules/daily-ledger/routes.php, modules/daily-ledger/handlers.php (new
  handler only), templates/modules/daily-ledger/admin/reports.disyl. Nothing else.
- Do NOT modify the oracle, tests/**, or kernel files. If you believe a kernel change is needed,
  report BLOCKED with the reason instead.
- MySQL 5.7: no window functions / CTEs / JSON_TABLE.
- Do NOT git add / git commit; leave the tree for review.
- DiSyL lint: `php _lint_disyl.php` must stay at 703/703.

# VERIFY (report the actual output lines)

1. php tools/acceptance-viewer-reports-archive.php   -> must end '0 RED'
2. for f in tests/daily-ledger/*report*.php; do php "$f" >/dev/null 2>&1; echo "$f $?"; done
   -> report every exit code (there are known log-flush flakes; if one is red, rerun it alone
      once before reporting)
3. php _lint_disyl.php                                -> 703/703

# REPORT (exactly this shape)

status: PASS|FAIL|PARTIAL|BLOCKED
changed: file:line each
design_calls: the refusal semantics chosen per failure case (which 404 vs 403) and the control label
verification: the three commands' result lines, verbatim
unresolved:
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/lane-viewer-reports-archive
