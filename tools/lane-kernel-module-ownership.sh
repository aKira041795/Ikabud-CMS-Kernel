#!/usr/bin/env bash
#
# Lane: kernel-module-ownership (Sol leads)
#
# OWNER DIRECTION (2026-10-31):
#   "we must resolve this at the kernel level where the kernel admin lists all modules for
#    enable/disable. My negative with this is that this will bloat the kernel admin view as
#    more modules/extension modules are created. it's best to be left with the parent modules
#    to handle the installation, configuration, control, uninstall, etc.
#    Then there are parent modules with which other parent modules can use thru capability bus.
#    This is not well defined with the ikabud kernel. It's high time this is properly managed."
#
# TWO CONSULTANTS, ONE CONTRACT:
#   Chair  (.ai/consult/kernel-ownership-capability.md): derive ownership from the manifest
#          `extends` chain; `suite` is grouping, `kind` is classification, neither is authority;
#          REJECT a manifest-authored `managed_by` (it can contradict `extends`); kernel admin
#          lists roots with a read-only ownership tree plus an Unresolved Ownership section;
#          "a root with 13 children occupies one primary row, not 14".
#   Claude (.ai/consult/claude-redteam-kernel-ownership.md): that derivation is blind to the
#          case this repo actually has - a third-party extension installed from a ZIP cannot
#          name a host it has never heard of, so it stays a root and the bloat returns.
#          Smallest fix: kernel-WRITTEN install provenance, used as parent only when `extends`
#          is absent, with a conflict becoming Unresolved Ownership rather than a silent winner.
#
# AGREED CONTRACT (implement exactly this):
#   extends (manifest)      -> direct lifecycle parent, when declared
#   provenance (kernel)     -> direct lifecycle parent, ONLY when extends is absent
#   both, disagreeing       -> unresolved: ownership_conflict
#   parent not installed    -> unresolved: parent_missing
#   cycle                   -> unresolved: ownership_cycle
#   suite                   -> NEVER authority (4 cms-akira profiles declare suite, no extends)
#   capability consumption  -> NEVER ownership
#
# MEASURED TODAY: 70 modules installed. extends covers 13 of them (cms-akira-core owns 12 by
# kind=extension/adapter, pal-core owns 1); 54 are standalone roots, 4 profiles declare suite
# with no extends. So roots today are ~60 and every future CMS-installed extension becomes a
# CHILD instead of a new row - which is the actual multiplying case.
#
# ACCEPTANCE (authored before dispatch, fails on this tree: "0 passed, 5 failed"):
#   php tests/module_ownership_resolver_test.php   -> "Result: N passed, 0 failed", exit 0

cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

LANE_MODEL_CHAIN="openai-codex/gpt-5.6-sol,deepseek-v4-flash,openai-codex/gpt-5.6-terra"

PROMPT="$(cat <<'PROMPT_EOF'
You are implementing a KERNEL contract in the Ikabud repo at /var/www/html/applicationostest.
This is implementation, not review. The design is settled by a chair consult and a Claude
red-team; implement it exactly and do not redesign it.

PHP 8.5 locally; production is Bluehost MySQL 5.7 (no window functions, no CTEs). No new
dependencies.

## THE PROBLEM (measured)

src/http/page-handlers.php:766 — kernelHandlePageAdminModules() iterates discoverModules() with
NO filtering. All 70 installed modules appear in the kernel admin, and the list grows with every
extension. The owner wants lifecycle (install / configure / control / uninstall) to belong to the
parent module, with the kernel governing ROOTS.

## WHAT TO BUILD

### 1. New file src/helpers/module-ownership.php

Require it from src/helpers/module-manager.php exactly the way module-catalog.php is required
there (`require_once __DIR__ . '/module-catalog.php';` around line 1461). Read that pattern first
and follow it.

Public functions (signatures are fixed - the acceptance test calls them):

    moduleOwnershipResolveAll(?array \$fleet = null, ?array \$provenance = null): array
    moduleOwnershipRoot(string \$moduleId, ?array \$fleet = null, ?array \$provenance = null): ?string
    moduleOwnershipChildren(string \$rootId, ?array \$fleet = null, ?array \$provenance = null): array
    moduleOwnershipProvenance(?string \$moduleId = null): array
    moduleOwnershipRecordInstall(string \$moduleId, string \$installedBy, array \$extra = []): bool
    moduleOwnershipForget(string \$moduleId): bool

Rules the resolver must enforce:

- DIRECT PARENT: manifest 'extends' (or its existing alias 'parent') when present and non-empty.
  Otherwise provenance[moduleId]['installed_by'] when that names an installed module other than
  itself. Otherwise the module is a root.
- ROOT: walk the direct-parent chain to the module with no direct parent. Depth-bounded (e.g. 32)
  so a pathological graph cannot hang. `moduleOwnershipRoot()` returns the root id, or null when
  the module is unresolved / unknown.
- CONFLICT: manifest declares extends=P AND provenance records installed_by=Q, P !== Q
  -> unresolved with reason_code 'ownership_conflict'. Do NOT pick a winner.
- PARENT MISSING: the direct parent is not in the fleet -> unresolved 'parent_missing'.
- CYCLE: any module on a cycle -> unresolved 'ownership_cycle' (every member, not just one).
- `suite` is NEVER authority. Four cms-akira profile modules declare suite without extends and
  must remain roots. Do not group by suite.
- Capability consumption is NEVER ownership. Do not read capabilities.depends for this.
- `moduleOwnershipChildren(\$rootId)` returns ALL descendants (not only direct children), each as
  ['id','name','version','kind','direct_parent','root','depth'] so the admin can show a flattened
  tree where a grandchild still reports its DIRECT parent.
- `moduleOwnershipResolveAll()` returns:
    ['roots' => [rootId => ['id'=>..,'name'=>..,'version'=>..,'kind'=>..,'child_count'=>N,
                            'children'=>[ ...as above... ]], ...],
     'unresolved' => [ ['id'=>..,'reason_code'=>..,'detail'=>..], ... ],
     'facts' => [moduleId => ['direct_parent'=>?string,'root'=>?string,'depth'=>int,'source'=>'extends'|'provenance'|'root']]]
- When \$fleet is null use discoverModules(); when \$provenance is null use
  moduleOwnershipProvenance(). Passing them explicitly is what makes the acceptance test pure -
  keep that injectability, do not read globals when arguments are supplied.

### 2. Install provenance - KERNEL-WRITTEN, never manifest-authored

- Storage: storage/state/module-provenance.json (create storage/state with mkdir 0775 if absent).
  Shape: ['modules' => [moduleId => ['installed_by' => string, 'installed_at' => string, ...]]].
- moduleOwnershipRecordInstall() writes ONE entry atomically (temp file + rename, like
  moduleScanSharedCacheWrite does) and never throws; failure returns false and is logged.
- moduleOwnershipForget() removes one entry (called on uninstall).
- DO NOT add a manifest field for this. The chair rejected `managed_by` as a manifest-authored
  second parent; this record is kernel-written install fact instead. Do not accept it from a
  request body either - only from server-side code that knows which module is installing.
- Wire the writer into the two install paths that already exist in src/helpers/module-manager.php:
    installModuleFromZip(\$zipPath) and updateModuleFromZip(\$zipPath, \$options)
  add an OPTIONAL 'installed_by' option (default '' = do not record). When supplied, record it
  AFTER a successful install/update. Keep existing behaviour and return shapes unchanged when the
  option is absent - every current caller must keep working untouched.
- Wire moduleOwnershipForget() into uninstallModule() so an uninstalled module leaves no phantom
  record.

### 3. Kernel admin projection (src/http/page-handlers.php + templates/pages/admin-modules.disyl)

kernelHandlePageAdminModules() must stop listing every module as a top-level row:

- Primary list = ROOTS only. Each root row keeps everything it has today (name, version, icon,
  description, enabled state, enable/disable control, config opt-in row) plus a child count.
- Children are shown in a READ-ONLY expansion under their root: id, name, version, kind, DIRECT
  parent, and the owning module. NO per-child enable/disable, install, or uninstall control is
  rendered - the owning module's own surface performs lifecycle for them. Keep showing
  tenant_admin_url where a settings destination exists.
- Add a visible "Unresolved ownership" section listing every module the resolver could not place,
  with its reason_code in plain words. NEVER silently hide a module: if a module is neither a root,
  a child, nor unresolved, that is a bug - surface it there rather than dropping it.
- Roots keep their existing controls, including the install/upload zone. Do not remove existing
  page features (upload, toasts, config modal, icon, allow_kernel_admin row).
- The template is DiSyL. Follow the existing file's vanilla-JS style (no Alpine, no new
  libraries). Run: php _lint_disyl.php --path templates/pages --ci
- The acceptance test asserts the page handler references moduleOwnershipResolveAll, the template
  contains the word 'ownership', and the template contains 'Unresolved'. Those are minimum
  signals, not the spec - build the real thing described above.

## HARD CONSTRAINTS

- Do NOT touch modules/cms/** . Do not change capability policy, allow_callers, or any
  authorization behaviour: ownership must not feed authorization in this slice.
- Do NOT edit src/helpers/updates.php (a sibling contract lives there).
- Do NOT create a manifest field for ownership.
- Keep `php -l` clean on every file you touch.
- Every new function needs a docblock stating what it REFUSES and why.

## VERIFY BEFORE YOU REPORT

    php tests/module_ownership_resolver_test.php     # must end: Result: N passed, 0 failed
    php -l src/helpers/module-ownership.php
    php -l src/helpers/module-manager.php
    php -l src/http/page-handlers.php
    php _lint_disyl.php --path templates/pages --ci
    php tests/module_scan_cache_test.php             # must stay 19 passed
    php tests/cms_module_admin_surface_test.php      # must stay 28 passed

tests/infrastructure_test.php is ALREADY broken on HEAD (it never requires module-manager, so
moduleInstallTargetDirForId() is undefined at line 313). Do not fix it; just confirm you did not
make it worse.

## REPORT - tight

    STATUS: PASS | PARTIAL | BLOCKED
    CHANGED: files + line ranges
    CONTRACT NOTE: how a ZIP-installed extension becomes a child without any manifest field
    EVIDENCE: the verbatim final line of the acceptance test output + the root/child/unresolved
              counts on the REAL fleet
    GATES: what the resolver refuses, one line each
    UNVERIFIED: anything you could not check
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/kernel-module-ownership
rc=$?
echo "lane: kernel-module-ownership — completed by: ${LANE_MODEL_USED:-<none>} (rc=$rc)"
exit "$rc"
