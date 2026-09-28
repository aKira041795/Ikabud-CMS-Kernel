# Pending import — authority tests that cannot run in this repository

These four suites were added by commit `bf25ec86` ("kernel: declare authority scope, and make the
Workbench censuses real"). They were **copied from `/var/www/html/ikabudsix`** — the repository that
`docs/kernel/authority-and-governance.md` names as the origin of the authority/governance kernel
files. That repository and this one have **separate histories with no merge base**.

The copy was **incomplete**. The commit brought the tests across but left behind the implementation
they exercise, so none of the four has ever passed here.

They are held back rather than deleted because the work may be worth finishing later. They are
renamed `.pending.php` so `tools/run-tests.php` does not discover them — its `RecursiveDirectoryIterator`
accepts any `*_test.php`, so a subdirectory alone would not have excluded them.

## Why each one cannot pass here

| Suite | Blocker |
|---|---|
| `module_route_authority` | Calls nine `moduleRouteAuthority*` helpers that were never ported. They live at `src/helpers/module-manager.php:2861-3220` in ikabudsix. **Caveat:** those helpers have zero production callers in *either* repository — only tests call them — so porting them adds dead code. |
| `read_authority_probe` | Requires the module **`cms-akira-shell`**, which does not exist in this repository at all. It cannot pass without porting an entire module. |
| `cms_akira_policy_authority_contract` | Requires policy rows and editor-role declarations not present here (2 assertions fail). |
| `gui_settings_route_authority_declaration` | Requires `gui-settings` `capabilities.routes` declarations plus policy rows and a registry seeder (7 assertions fail). |

## What is NOT missing

The feature itself is implemented and wired, and it is covered by suites that do pass:

- `tests/module_route_declaration_runtime_test.php` — the original, older (Aug 2026) suite for the
  same feature. It uses the **real** class-based API, passes, and is the one CI runs.
- `tests/authority_scope_test.php`, `tests/authority_dispatch_enforcement_test.php`,
  `tests/authority_census_contract_test.php` — all pass.

Runtime enforcement lives in `Ikabud\Kernel\Http\AuthorityDispatchGuard`, called from
`executeModuleHandler()` at `src/helpers/module-manager.php`. It is not dead code.

## To adopt one of these

1. Port the subject the suite needs (module, declarations, or policy rows) — not just the test.
2. Rename it back to `*_test.php` and confirm it is green **before** committing.
3. If porting `module_route_authority`, decide deliberately whether a test-only API should exist in
   production; it would be dead code until something calls it.

## Related: the false-pass hazard these tests exposed

While triaging these suites I found that a PHP fatal error under this application's `bootstrap.php`
rendered a 500 page and **exited 0**, so a test that died looked like a test that passed. That is
fixed: `bootstrap.php` now reports to stderr and exits 1 under `PHP_SAPI === 'cli'`.

`read_authority_probe_test` was one of six suites that had been silently false-passing because of it
(the others were five `academic_similarity_*` suites and `ai_text_generate_capability_test`, which die
because those modules are not enabled locally).
