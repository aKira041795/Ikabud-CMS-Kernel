#!/usr/bin/env bash
#
# Lane: report-archive-split
#
# Closes the last open D3 item (chair ruling §11 D3 of the viewer surface assessment): "persistent
# report creation in a shared archive requires explicit separate authorization". Today EVERY role
# that can export silently writes into the shared archive. The split: a read-only role's export
# downloads + audits but does NOT persist a shared archive entry unless the tenant explicitly grants
# 'reports.archive.create'; operational roles AND configured scheduled exports keep archiving
# unchanged (their records are the point of the archive).
#
# Criterion (2 RED on the base, proven by the dispatch gate):
#   tools/acceptance-viewer-reports-archive.php   (P1 viewer-no-archive + P8 grantable-action;
#   P1b/P2-P6 pins: authorized export archives, control renders, viewer can download the archive,
#   anonymous 401, unknown id clean, page states exports are audited)
#
# Owner directive driving this dispatch (2026-10-12): "close remaining open items, test after".

cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

LANE_MODEL_CHAIN="openai-codex/gpt-5.6-sol,deepseek-v4-flash,openai-codex/gpt-5.6-terra"

PROMPT="$(cat <<'PROMPT_EOF'
You are working in the Ikabud repo at /var/www/html/applicationostest. PHP 8.5 local; production is
MySQL 5.7. Local site http://baronledger.test runs tenant 207 (a copy of production).

# CONTEXT (read first)

- docs/daily-ledger/VIEWER-SURFACE-ASSESSMENT-PLAN-2026-10-12.md - §11 D3 (the ruling this lane
  closes) and the W17 block (current documented behaviour).
- tools/acceptance-viewer-reports-archive.php - YOUR ORACLE. 2 RED right now: P1 (a viewer export
  still creates a shared archive row - measured: "new archive(s) from the viewer: 1, must be 0")
  and P8 ('reports.archive.create' is not a grantable action yet). Make it 0 RED WITHOUT weakening
  it. P1b/P2-P6 are pins and must stay green:
    P1b an authorized role's export still archives;
    P2  the archive row carries the Download control;
    P3  a viewer can download an authorized archive (identical bytes - READING shared archives
        stays allowed);
    P4  anonymous download is 401/403;
    P5  unknown id is a clean refusal;
    P6  the reports page still states exports are audited.

# MEASURED FACTS (2026-10-12)

- dl_handleAdminReportExport() admits admin/supervisor/auditor/viewer; dl_generateGovernedReport()
  (helpers/reporting.php ~788-910) ALWAYS archives (ReportManager::archiveReport copies the file;
  the audit row's entity_id is the archive id; the audit-persistence rollback unlinks the archive
  when the trail cannot be written).
- The scheduled/background caller (dl_runScheduledReports -> dl_generateGovernedReport(..., true))
  REQUIRES archived exports; tests/daily-ledger/daily_ledger_reporting_test.php pins that
  (getArchivedReport of the scheduled ids) and its direct governed calls use role 'administrator'.
- The role-permission maps (handlers.php dl_allPermissionActions ~507, dl_defaultRolePermissions
  ~521) have EXACT-ARRAY pins in tests/daily-ledger/daily_ledger_authz_test.php: viewer, auditor and
  production_in_charge defaults must stay EXACTLY []. So you must NOT change the default maps -
  the new action goes in the WHITELIST only (that is also what makes it a tenant-grantable
  "explicit authorization" via the existing role-permissions settings UI).

# REQUIRED OUTCOME

1. handlers.php - dl_allPermissionActions(): add 'reports.archive.create'. Do NOT touch
   dl_defaultRolePermissions().
2. handlers.php - NEW helper with an explicit, commented list (house pattern, like
   dl_sharedBranchAccountRoles):
     dl_reportArchiveReadOnlyRoles(): ['viewer']  - roles that may not persist into the shared
     archive without an explicit grant; comment the D3 rationale and that operational roles +
     scheduled exports keep archiving.
3. helpers/reporting.php - dl_generateGovernedReport(): compute
     $canArchive = $background
         || !in_array((string)($user['role'] ?? ''), dl_reportArchiveReadOnlyRoles(), true)
         || dl_roleHasPermission((string)($user['role'] ?? ''), 'reports.archive.create');
   - $canArchive === true  -> the current path, byte-for-byte behaviour: archiveReport + audit row
     tied to the archive id + the audit-persistence rollback + 'archive_id' in the return.
   - $canArchive === false -> NO archiveReport call and NO 'archive_id'; STILL write the export
     audit row (action 'report_export'; pick an entity_id with a clearly distinct prefix, e.g.
     'dlrep_' + uniqid - never 'rpt_', which is the kernel archive namespace) and KEEP the same
     governance rule: if that audit row cannot be persisted, unlink the temp export and throw
     "Report audit could not be persisted; export was cancelled." (downloads must not become
     unaudited). Return the same array shape otherwise (path/mime/filename/size) so
     dl_handleAdminReportExport needs no change.
   - grep 'archive_id' across modules/ before finishing: nothing outside the archive path and the
     background caller may dereference it unguarded.
4. templates/modules/daily-ledger/admin/reports.disyl - make the copy honest about the split while
   keeping the word 'audited' (P6 pins it): the landing note ("Exports are archived and audited.")
   and the export-area note ("Each download is archived, listed in Recent Archived Reports, and
   audited.") currently promise archiving unconditionally. Reword both, e.g. "Exports are audited;
   with archive permission they are also stored in Recent Archived Reports." Keep the Download
   controls themselves unchanged.

# CONSTRAINTS

- Files you may change: modules/daily-ledger/handlers.php, modules/daily-ledger/helpers/reporting.php,
  templates/modules/daily-ledger/admin/reports.disyl. Nothing else - especially NOT the default
  role-permission maps, NOT the download endpoint, NOT tests, NOT the oracle.
- MySQL 5.7: no window functions / CTEs / JSON_TABLE.
- Do NOT git add / git commit; leave the tree for review.
- DiSyL lint: `php _lint_disyl.php` must stay at 703/703.

# VERIFY (report the actual output lines)

1. php tools/acceptance-viewer-reports-archive.php        -> must end '0 RED'
2. php tests/daily-ledger/daily_ledger_reporting_test.php -> must stay green (scheduled archives!)
3. php tests/daily-ledger/daily_ledger_authz_test.php     -> must stay green (exact-array pins)
4. php tests/daily-ledger/daily_ledger_handlers_test.php  -> must stay green
5. php _lint_disyl.php                                    -> 703/703

# REPORT (exactly this shape)

status: PASS|FAIL|PARTIAL|BLOCKED
changed: file:line each
design_calls: the non-archive audit reference format; the copy rewording; confirmation that
  background/scheduled exports still archive
verification: the five commands' result lines, verbatim
unresolved:
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/lane-report-archive-split
