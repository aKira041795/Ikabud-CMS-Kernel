#!/usr/bin/env bash
#
# Lane: module-update-from-zip (Sol leads)
#
# WHY THIS LANE EXISTS (measured 2026-10-31):
#   installModuleFromZip() REFUSES a package whose target directory already exists and tells
#   the caller to "use update" — but no update existed. Every caller therefore hand-rolled an
#   overwrite. The CMS installer (modules/cms/handlers/84-extensions.php, cmsApiModuleUpload)
#   renames the live directory to modules/<id>.bak_<stamp> and copies over it:
#     - no version comparison  -> a DOWNGRADE is indistinguishable from an upgrade;
#     - no signature gate       -> moduleVerifyPackageSignature() is never called on that path;
#     - no certification gate    -> validateModuleCertification() is never called either;
#     - the backup is written but never restored and never removed.
#
# Sol leads because the design is settled and the value is care, not volume: the new function
# must share the install path's sanitizer, gates and rollback instead of growing a third
# near-copy of them.
#
# ACCEPTANCE (authored before dispatch, fails on this tree):
#   php tests/module_update_from_zip_test.php   -> exit 1, "0 passed, 2 failed"
# PASS looks like: "Result: N passed, 0 failed" and exit 0.

cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

LANE_MODEL_CHAIN="openai-codex/gpt-5.6-sol,deepseek-v4-flash,openai-codex/gpt-5.6-terra"

PROMPT="$(cat <<'PROMPT_EOF'
You are implementing a MISSING KERNEL PRIMITIVE in the Ikabud repo at /var/www/html/applicationostest.
This is implementation work, not review. The design is settled — implement it exactly.

PHP 8.5 locally. Production is Bluehost MySQL 5.7: no window functions, no CTEs.

## THE DEFECT YOU ARE FIXING

src/helpers/module-manager.php:5324-5328 —

    // Safety: don't overwrite if already exists (use update flow instead)
    if (is_dir($targetDir)) {
        $zip->close();
        return moduleInstallFailure('module_already_exists', "Module '{$moduleId}' already exists. Remove it first or use update.");
    }

There is no `update`. `grep -n 'function updateModule\\|function upgradeModule'` returns nothing. So every
caller that needed to upgrade hand-rolled an overwrite, and the CMS installer's copy has no version gate, no
signature verification and no rollback (modules/cms/handlers/84-extensions.php around cmsApiModuleUpload).

## WHAT TO BUILD — all in src/helpers/module-manager.php, appended AFTER installModuleFromZip()

### 1. Hoist the two zip-safety closures out of installModuleFromZip so both paths share them

Inside installModuleFromZip today, two closures: `$sanitizeEntryName` (~line 5104) and
`$isSafeZipEntryType` (~line 5132). Hoist them to real functions and make installModuleFromZip USE them
(identical behaviour — do not change what either accepts):

    function moduleZipSanitizeEntryName(string $name): ?string
    function moduleZipEntryTypeAllowed(\ZipArchive $zip, int $index, bool $isDirectory): bool

Do not leave the closures behind as duplicates.

### 2. function modulePackagePreflightGates(array $manifest, array $options = []): array

Options: 'require_certification' (bool, default false), 'fleet' (?array, default null),
'module_dir' (string, default '').

Runs, in order, returning on first failure:
  a. validateModuleCapabilities($manifest) -> error_code 'manifest_invalid_capabilities'
  b. validateModuleSuiteContractForInstall($manifest, $fleet ?: discoverModules())
     -> its own error_code (this already enforces G6 kernel/suite compatibility ranges)
  c. when require_certification: validateModuleCertification($manifest) -> 'module_not_certified'
     (set $manifest['_path'] = module_dir first when module_dir is non-empty, because certification
      inspects the directory)
  d. return ['ok'=>true,'checks'=>[...]]

It must return ['ok'=>false,'error_code'=>...,'error'=>...] on failure — never throw, never emit a page.

### 3. function updateModuleFromZip(string $zipPath, array $options = []): array

Options: 'allow_downgrade' (bool, default false), 'require_certification' (bool, default true),
'packaged_templates' (bool, default true).

Behaviour, in this order:

 1. Reuse installModuleFromZip's zip validation verbatim in spirit: is_file, PK signature bytes,
    class_exists('ZipArchive'), open, 2000-entry and 200 MiB uncompressed caps, sanitize/type preflight
    of every entry, reject files outside the module root.
 2. Locate module.json (root or single top-level folder) -> $prefix.
 3. validateModuleManifest() on the manifest bytes (check_filesystem=false) + validateModuleCapabilities().
 4. $moduleId = manifest id. Resolve the INSTALLED directory with modulePathForId($moduleId).
    If null -> return ['ok'=>false,'error_code'=>'module_not_installed','error'=>...].
    IMPORTANT: use modulePathForId, NOT moduleInstallTargetDirForId — a module nested in a suite
    (modules/cms-akira/cms-akira-seo) must be updated in place.
 5. $fromVersion = installed module.json 'version'; $toVersion = package 'version'.
    version_compare(to, from, '>')  -> upgrade
    version_compare(to, from, '==') -> reinstall, set $reinstalled = true
    version_compare(to, from, '<')  -> if empty($options['allow_downgrade']) return
        ['ok'=>false,'error_code'=>'module_downgrade_blocked','error'=>"Installed version X is newer than
        package Y. Pass allow_downgrade to force.",'from_version'=>X,'to_version'=>Y]
 6. moduleVerifyPackageSignature($zip, $prefix) — a non-empty 'error' is fatal
    ('module_signature_verification_failed'). A 'warning' is logged, not fatal (same as install).
 7. Extract to a STAGING directory (sys_get_temp_dir) using the shared sanitizer, then
    validateModuleManifest($staging . '/module.json') WITH filesystem checks — the package must be
    complete before it may touch the live tree.
 8. modulePackagePreflightGates($manifest, ['require_certification'=>$options['require_certification'],
    'fleet'=>$fleetWithNewManifest, 'module_dir'=>$targetDir]) where $fleetWithNewManifest is
    discoverModules() with [$moduleId] REPLACED by the new manifest (an extension must not be measured
    against its own previous version).
 9. Swap, atomically enough to recover:
      - rename $targetDir -> $targetDir . '.bak_' . date('Ymd_His')
      - move staging into $targetDir; if that fails, copy recursively
      - when the package ships templates/__ikabud_package/templates/**, back up
        templates/modules/<id> to templates/modules/<id>.bak_<same stamp> and install the packaged
        templates there (installModuleFromZip writes them; read lines ~5381-5385 for the exact
        destination rule)
    ANY failure after the rename must RESTORE both directories from their backups and return the
    failure with error_code 'module_update_failed_rolled_back'. A half-updated module is not acceptable.
10. Call kernelFlushCodeCaches() when it exists, and re-enable the module ONLY if it was enabled before
    the swap (read isModuleEnabled() first — do not silently enable a disabled module).
11. Remove the staging directory in every exit path.
12. Return ['ok'=>true,'module_id'=>..,'from_version'=>..,'to_version'=>..,'reinstalled'=>bool,
    'backup'=>string,'templates_backup'=>?string,'manifest'=>array]

### 4. function modulePackageBackupGc(int $keepDays = 7): int

Find directories named `<something>.bak_<Ymd_His>` directly under modulesPath() and at depth 2
(modules/<suite>/<id>.bak_<stamp>). Delete those whose embedded timestamp is older than $keepDays days
(keepDays 0 deletes all). Return the number deleted. NEVER delete a path that does not match
/\\.bak_\\d{8}_\\d{6}$/ — this function removes directories, so a near-match must be refused, not removed.
Do not touch the newest backup when keepDays === 0? No: keepDays 0 means delete every matching backup.

## HARD CONSTRAINTS

- Do NOT change installModuleFromZip's external behaviour, its error codes, or its refusal to overwrite.
- Do NOT touch modules/cms/** in this lane. Kernel only. Another lane wires the CMS to these functions.
- Do NOT add new files outside src/helpers/module-manager.php.
- MySQL 5.7 compatible. No new dependencies.
- Every function you add needs a docblock stating what it refuses and why.
- Keep `php -l src/helpers/module-manager.php` clean.

## ACCEPTANCE — run it yourself, it must be green before you report

    php tests/module_update_from_zip_test.php

It currently prints "0 passed, 2 failed" because both functions are missing. It must end with
"Result: N passed, 0 failed" and exit 0. The test scaffolds a real module with `php ikabud make:module`,
packs three versions with `module:pack`, installs 1.0.0, then asserts:
downgrade refused and files untouched; 1.0.0 -> 1.1.0 replaces files; backup holds 1.0.0; same version
is a reinstall; explicit downgrade works; GC runs; preflight refuses an extension with a missing host
and accepts a self-contained module.

Then run these and make sure you did not break them:

    php -l src/helpers/module-manager.php
    php tests/module_scan_cache_test.php
    php tests/infrastructure_test.php 2>&1 | tail -5

If infrastructure_test.php already fails on HEAD for reasons unrelated to your change, say so explicitly
and name the failing assertion — do not "fix" it.

## REPORT — tight

    STATUS: PASS | PARTIAL | BLOCKED
    CHANGED: files + line ranges
    DESIGN NOTE: how updateModuleFromZip shares the install path instead of copying it (bullets)
    EVIDENCE: the verbatim final line of the acceptance test output
    GATES: what preflight actually rejects, in one list
    UNVERIFIED: anything you could not check
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/module-update-from-zip
rc=$?
echo "lane: module-update-from-zip — completed by: ${LANE_MODEL_USED:-<none>} (rc=$rc)"
exit "$rc"
