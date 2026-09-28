# CMS + Akira — comprehensive review (2026-09-26)

## Executive verdict

**Blunt verdict: in Tree A the "CMS Akira suite" is a real, well-enforced extension *contract* wrapped around nine thin adapter providers and five empty scaffolds — it is not a decomposed CMS, and every document in `docs/cms/` and the release note that calls it one is wrong about the code.**

The legacy `modules/cms` is a working, feature-rich module (42,152 PHP LOC, 190 routes, 37 declared tables) with genuinely good seams (capability handler map, builder persistence, entity-view registration, tenant-scoped cache, CSRF coverage). It is also carrying real defects that a first-time reader would not expect: **duplicate `001`/`003` migrations that silently suppress the `cms_content` foreign keys**, an **undeclared `026` migration that never runs**, **god-files of 4,411 / 4,226 / 3,013 lines**, and **`module.json` claiming ownership of four kernel/workflow/search tables it cannot create**.

The product-suite extension model (Q4) is **enforced, not documented-only** — manifest validation, a fleet validator, an install gate, and certification C12/C13 all exist with tests. The direction is architecturally sound for the kernel. For *this* stage of the CMS it is **over-applied**: the code relationship is `cms-akira-core depends on cms` and delegates every read/write back through `cms.content.*`, while the docs describe a replacement. Tree B has actually started building the replacement (native posts/taxonomies/content-types/revisions/redirects, a `cms-akira-shell`, 31,469 LOC and 64 module tests); Tree A has not started it. Make the legacy CMS correct first; converge onto Akira by porting B's native modules, not by rewriting.

## Evidence base

All commands were run read-only. No product code, tests, migrations, config or templates were modified. DB-mutating tests, migrations and Playwright were **not executed** — all test/migration "passing" claims below are source-read claims unless explicitly shown as a command result.

### Git state (both trees)

```
TREE A (/var/www/html/applicationostest, master)
b3c7e3e4 cms: serve the native-default theme CSS as an asset, not a compiled template
a971e41c daily-ledger: dedup a withdrawal submission by identity, not by content
… (HEAD is b3c7e3e4; recent history is daily-ledger, not CMS)

TREE A git status --porcelain (product CMS trees are clean):
 M modules/harpp/helpers.php
 M modules/harpp/tests/run-all.sh
 M tools/harpp-bridge/harpp_client.py
 M tools/harpp-bridge/harpp_wake.py
 M tools/harpp-bridge/tests/test_harpp_wake.py
?? modules/harpp/tests/auth_abstention_pipeline_cli_test.php

TREE B (/var/www/html/ikabudsix, feat/akira-editorial-and-authority-coverage)
1d1215d cms-akira: autosave, so an author stops losing work when the browser dies
1194888 tests/browser: no DB credentials in argv, and repair an impossible archive assertion
… (166 commits ahead of origin; working tree carries a very large uncommitted/staged set,
including `A modules/cms/...` re-adding a copy of the legacy CMS)
```

### Largest legacy CMS files (`find modules/cms -name '*.php' -exec wc -l {} + | sort -rn`)

```
42152 total
 4411 modules/cms/helpers/80-customizer.php
 4226 modules/cms/helpers/85-ai-automation.php
 3013 modules/cms/handlers/90-public.php
 2892 modules/cms/builder-renderers.php
 2600 modules/cms/helpers/50-builder.php
 2088 modules/cms/handlers/35-api-content.php
 1995 modules/cms/handlers/84-extensions.php
 1537 modules/cms/helpers/40-theme-settings.php
 …65 PHP files total; module dir 143M (builder-ui/node_modules is 126M, assets 15M)
```

### Cross-tree `diff -rq modules/cms-akira`

```
TREE A: 87 files, 3,407 PHP LOC
TREE B: 2,564 files, 31,469 PHP LOC
files real-only-in-B (excluding node_modules): 116
files only-in-A (removed in B):              24
files that differ:                           61
```

### MySQL 5.7 / Bluehost constraint scan

```
$ grep -rnE 'OVER\s*\(' modules/cms modules/cms-akira --include='*.php' --include='*.sql' | wc -l
0
$ grep -rnEi 'WITH\s+[a-z_]+\s+AS\s*\(' modules/cms modules/cms-akira --include='*.php' --include='*.sql' | wc -l
0
```
No window functions and no CTEs anywhere in either CMS tree. Redirect/feature-index SQL is MySQL-5.7 safe on this lexical check (it is not a proof that every migration executes on 5.7).

### Markers and tests

```
$ grep -rnE '\b(TODO|FIXME|HACK|XXX)\b' modules/cms modules/cms-akira \
      --include='*.php' --include='*.sql' --include='*.ts' --include='*.tsx' | grep -v node_modules
modules/cms/builder-ui/src/PageBuilder.reference.tsx:417: // TODO: Create a version on manual save …

$ grep -rnwi 'deprecated' modules/cms --include='*.php' --include='*.sql' | wc -l
5   (all benign @deprecated annotations)
$ grep -rnwi 'deprecated' modules/cms-akira | wc -l
0

Tree A tests:                          470 test files (tests/*.php)
Tree A *cms* tests:                     76 files, 1,964 t() assertions
Tree A *akira* tests:                   33 files,   548 t() assertions
Tree B cms-akira module-local tests:    64 files, 1,185 $check() assertions, 12,201 LOC
Tree B Akira Playwright specs:          26
```

For comparison, the earlier 1,922 "TODO/FIXME/deprecated" hit in `modules/cms` is `builder-ui/node_modules`, not product code.

### `module:list`, `module:certify` (read-only CLI

```
$ php ikabud module:list | grep -i akira
cms-akira-ai           disabled
cms-akira-builder      enabled
cms-akira-core         enabled
cms-akira-editor       enabled
cms-akira-media        disabled
cms-akira-navigation   disabled
cms-akira-profile-headless  enabled
cms-akira-profile-minimal   enabled
cms-akira-profile-standard  enabled
cms-akira-profile-visual    enabled
cms-akira-search-adapter    disabled
cms-akira-seo          disabled
cms-akira-theme        disabled
cms-akira-workflow     disabled

$ php ikabud module:certify cms-akira-seo      # single-module form
✗ C3b: Capability handlers: Missing handler reference(s): akira.seo.meta.build@1.
FAILED (12/13 — 92%)

$ php ikabud module:certify --all | grep -i akira
✓ PASS cms-akira-core (13/13)
✓ PASS cms-akira-seo  (13/13)
✓ PASS cms-akira-theme (12/13)
… every other cms-akira module 13/13
```
The two forms disagree because the single-module branch of `ikabud:6738` passes a raw `json_decode(file_get_contents(module.json))` to `validateModuleCertification()`, which lacks `_path`, so `loadModuleHelpers()` (`src/helpers/module-manager.php:2509`) cannot find `helpers.php` and every declared capability looks unhandled. Loading the same file directly proves the handlers are real:

```
$ php -r 'require "modules/cms-akira/cms-akira-core/helpers.php";
          $m=cms_akira_core_capability_handlers(); var_dump(is_callable($m["akira.content.get@1"]));'
bool(true)
```

This is a tooling false-negative, not a product defect — but it is the exact gate the 2026-08-05 release note markets as "✅".

## Architecture as-built

### Legacy CMS (`modules/cms`)

**Data model / table ownership.** `modules/cms/module.json:29` declares 37 `owns_tables`. Content core is `cms_content` (`001_cms_content_core.sql:4`) with `cms_content_meta`, `cms_field_definitions`, `cms_content_fields`, `cms_blocks`, `cms_content_types`, `cms_relations`, `cms_categories`/`cms_content_categories`, `cms_tags`/`cms_content_tags`. Builder domain: `cms_builder_documents`, `cms_builder_revisions`, `cms_builder_reusable_sections`, `cms_builder_templates`. Navigation: `cms_menus`, `cms_menu_items`, `cms_menu_locations`. Identity: `cms_users`, `cms_user_services`, `cms_service_tokens`, `cms_password_resets`, `cms_auth_verifications`. Presentation: `cms_theme_customizer`, `cms_saved_blocks`, `cms_slug_redirects`, `cms_revisions`, `cms_media`/`cms_media_usage`. AI: `cms_ai_content_plans`, `cms_ai_content_runs`. Entity: `cms_entity_capabilities`, `cms_entity_progress`. **It also claims `audit_logs`, `kernel_search_index`, `workflow_instances`, `workflow_transition_logs` (`module.json:57-60`)**, none of which any `modules/cms/database/migrations/*.sql` creates (grep for those identifiers returns nothing). `search/module.json:8` independently declares `co_owns_tables: ["kernel_search_index"]`.

**Capability surface.** `cms_capability_handlers()` (`helpers/55-capabilities.php:5`) maps 34 handler entries to 29 declared `capabilities.exposes` (`module.json:111`), 9 `depends`, plus `policy.allow_callers` narrowing `cms.content.*` to an explicit caller list (`module.json:333`). The core provider bodies are real: `cms_cap_cms_content_get_1` (`:46`) does a scoped `SELECT … LEFT JOIN cms_users` and calls `cmsCanReadContent`; `cms.content.list@1` (`:84`) builds an author-scoped `WHERE` for logged-in callers and a public-visibility `WHERE` otherwise.

**Routing.** `routes.php` returns 190 entries — GET 103, POST 84, PUT 3 (`php -r` over the returned array), dispatched as `cms:handlerFunction`. Admin routes are `/cms/admin/*` (`routes.php:6-33`), the headless API is `/api/v1/cms/public/*` (`routes.php:88-91`), public delivery is `/cms/*` (`routes.php:94-113`).

**Rendering path.** Public handlers live in `handlers/90-public.php` (3,013 lines). They resolve content, call `cmsPublicContext()` (`helpers/78-public-context.php`, 1,059 lines), resolve a template, and finish through `cmsPublicRespond()` (`90-public.php:13`) so session locks release after render. Visibility is `cmsPublicVisibilitySql()` (`helpers/15-utils.php:73`): `published OR (scheduled AND published_at <= NOW())`.

**Entity-view integration.** `helpers/58-entity-views.php` registers views at module load through the kernel `EntityViewResolver`: `cms_page`, `cms_post`, `cms_product` with `default/compact/card_grid/admin_row`, capability gates and exportable flags (`58-entity-views.php:35+`). It also registers a duplicate dotted namespace (`cms.post`, `cms.page`) — a compatibility alias, but two live keys.

**Builder (React ↔ PHP persistence contract).** The React app in `builder-ui` (Vite + TS) loads/saves through `/api/v1/cms/content/{id}/builder` (GET) and the same path POST (`routes.php:56,143`). `cmsApiBuilderDocumentSave()` (`20-api-builder.php:76`) gates `builder.save`, CSRF (skipped only for bearer service tokens), rejects `_json_error`, validates the document, refuses to overwrite a non-empty document with an empty one, and calls `cmsBuilderPersistDocument()` (`helpers/50-builder.php:708`): normalize → `cmsBuilderApplyDefaultProps` → `cmsBuilderEmitDiSyLContract` → `sha256` `render_hash` → versioned upsert in `cms_builder_documents` → revision → slug redirect → tag cache invalidation → `cms.builder.document.saved`. Rendering is server-side and deterministic via `builder-renderers.php` (`cmsBuilderWidgetRenderers()` at `:22`, ~70 widget renderers) and `cmsBuilderRenderDocument` (`50-builder.php:1688`).

**Theme / customizer.** `helpers/40-theme-settings.php` (1,537 lines) resolves the active theme and settings; `helpers/80-customizer.php` (4,411 lines) owns the `cms_theme_customizer` store with sections footer/header/sidebar/colors/custom_code/theme/entity_presentation. `cmsValidateCustomCodeSettings()` (`:1673`) strips `</style>` from CSS but deliberately allows arbitrary `head_code`/`body_end_code`; `cmsRenderCustomCodeOutput()` (`:1734`) emits them raw. The gate is `customizer.manage` = `administrator` (`05-permissions.php:110`).

**Admin IA.** `handlers/15-admin.php` + `templates/modules/cms/layouts/admin.disyl`. The sidebar is *both* hardcoded (Page Builder, Theme, Theme Library, Navigation, AI Automation at `admin.disyl:318-334` and again `:445-461`) *and* dynamic (`ext_nav_items`, `:372` and `:491`), fed by `cmsGetExtensionNavItems()` (`helpers/76-extensions-editor.php:258`) which filters `cms.admin.nav_items`, to which the kernel bridges manifest `admin_contributions` (`src/helpers/module-manager.php:3223`).

### Akira in Tree A (`modules/cms-akira`)

14 sub-modules, 87 files, 3,407 PHP LOC, all migrations are the same 13-line commented scaffold (`for f in $(find … -name 001_initial.sql); do cat "$f"; done` prints only comments). `cms-akira-core/module.json:8` declares `depends: ["cms"]`; only core depends on `cms`. Every real capability body delegates to the legacy module through the capability bus:

- `cms-akira-core/helpers/capabilities.php` — 8 handlers; `akira.content.*` pass-through to `cms.content.*` (`cacLegacyCmsContentAdapter`, `helpers/providers.php:33`), adding editor preparation on create/update.
- `cms-akira-seo/helpers.php:66` — `akira.seo.meta.build@1` delegates to `cms.seo.resolve@1`.
- `cms-akira-media/helpers.php:80` — delegates to `cms.media.get@1`.
- `cms-akira-navigation/helpers.php:70` — delegates to `cms.menus.get@1`/`cms.menus.tree@1`.
- `cms-akira-theme/helpers.php:62` — delegates via `cmsThemeRuntimeDiagnostics()` (a **named foreign helper**, not a capability; the README admits this).
- `cms-akira-editor/helpers.php:88,128,193` — `cmsEditorNormalizeHtml`, `cmsEditorSanitizeHtml`, `cmsTinyMceAssets` (named foreign helpers).
- `cms-akira-workflow/helpers.php:81` — delegates to `workflow.state.get@1` under a pushed `cms` context.
- `cms-akira-search-adapter/helpers.php:78` — uses `searchStrip()` and optional `search.index.upsert@1`.
- `cms-akira-core/helpers/capabilities.php:148` — `app()->db()` reads **`kernel_application_profile_registry`**, a kernel table not in `reads_tables` (which is `[]`).

No `modules/cms-akira/module.json` suite container exists. `cms_akira_*_capability_handlers()` maps exist for 9 of 14; `cms-akira-builder/helpers.php` has no capability map at all. Every `handlers.php` serves a scaffold admin page plus a dependency-free `/health` endpoint (63 lines, e.g. `cms-akira-editor/handlers.php`).

### The relationship between them

Code: **Akira is an adapter/facade layer over the legacy CMS.** `cms-akira-core` names the legacy module as a hard dependency and every provider calls back into it. The only "new" behaviour is a provider/fallback switch (`cacProviderRuntimeStatus()`) and editor normalization/sanitization. Nothing in Tree A's Akira suite owns a table, a post, a taxonomy, a revision, a redirect or a public route that the legacy module does not already own.

Tree B: **Akira is becoming the replacement.** B deleted the 14 Tree-A scaffold modules and the four profile shells, renamed `cms-akira-search-adapter` → `cms-akira-search`, added `cms-akira-shell`, and gave core ten real migrations (`002_create_posts` … `010_add_content_type_url_prefix`), 9 helpers (backup, bundle, capabilities, entity-views, extensions, governance, modules, redirects, settings) and 24 tests. That rename is the tell: B is moving to **native ownership**; A has not begun.

## What is good

### Legacy CMS
- **Capability handler map is a real seam.** `helpers/55-capabilities.php:5` returns a single id→function map consumed by the kernel; `policy.allow_callers` (`module.json:333`) restricts `cms.content.*` callers by module id. This is the pattern Akira reuses and it is worth keeping.
- **Builder persistence is disciplined.** `cmsBuilderPersistDocument` (`helpers/50-builder.php:708`) normalizes → applies defaults → emits a DiSyL contract tree → hashes the rendered document → increments `document_version` → writes revisions. The save handler refuses to overwrite a populated document with an empty one (`20-api-builder.php:119-131`). This is materially better than last-write-wins.
- **Entity-view registration is the first module adoption of the kernel contract** (`helpers/58-entity-views.php`), including capability gates and empty-state text.
- **CSRF coverage is broad** — 33 `csrfEnforce()` call sites across handlers (`grep -r "csrfEnforce" modules/cms | wc -l` = 33), and the builder explicitly documents why service-token writes skip it (`20-api-builder.php:80-83`).
- **Tenant-scoped cache and settings** — `cmsCacheInstance()` returns `cms_t{tid}` (`helpers/60-cache.php:8`); `readCmsSettings()` keys its in-process cache by tenant (`helpers/40-theme-settings.php:86`).
- **Behavioural tests exist and assert real outcomes.** `tests/cms_crud_test.php` performs authenticated CRUD, soft-delete/restore, unique-slug enforcement, media create/delete, role hierarchy and capability error shapes; `tests/cms_akira_core_adapter_contract_test.php` creates/gets/lists/updates content through `akira.content.*` and verifies persistence; `tests/cms_akira_phase5_6_compose_test.php` enables all providers, asserts `provider_mode=provider`, disables them, asserts `fallback`, and restores prior state in `finally`.
- **No TODO/FIXME/HACK in product PHP, and no MySQL-5.7 window/CTE constructs.**

### Akira (Tree A)
- **Boundaries are explicit and the contract is enforced.** `suite`/`kind`/`extends`/`profile installs` are validated per manifest and fleet-wide; the suite graph is derived, not implied by folders (see Q4).
- **Provider/fallback is a clean seam.** `cacProviderRuntimeMap()` (`cms-akira-core/helpers/providers.php:154`) plus the `resolved_from` field on every adapter give honest degradation: a missing provider returns `resolved_from: fallback` rather than an empty page.
- **The static foreign-DB gate is the right idea** (`tests/cms_akira_foreign_db_access_static_test.php`): token-aware, not regex, and it proves delegation through the capability bus.

### What Akira has *not* replicated from legacy
Content types, field definitions, taxonomies, revisions, slug redirects, media upload/transform pipeline, themes/customizer, import/export, AI plans/runs, entity-view registration, and the React builder backend. Akira's builder module is entirely empty (no capability map, empty migration), while B's builder has composition tables, a Vite admin UI and four tests.

## What is bad

Ranked by severity. "Real" = reproducible from the code as it stands; "cosmetic" is labelled.

| severity | class | finding | path:line | why it matters |
|---|---|---|---|---|
| **HIGH (real)** | schema | **Duplicate `001` migrations silently suppress every `cms_content`/`cms_content_meta` foreign key.** `001_cms_content_core.sql` creates both tables first; `MigrationRunner` sorts by filename (`kernel/Database/MigrationRunner.php:11`, `:497`), so `…content_core` runs before `…foundation`, and foundation's `CREATE TABLE IF NOT EXISTS cms_content` (`001_cms_foundation.sql:23`) is a no-op — the `CONSTRAINT fk_cms_content_author` at `:47` and `fk_cms_meta_content` at `:57` never apply. | `001_cms_content_core.sql:4,31`; `001_cms_foundation.sql:23,47,57`; `MigrationRunner.php:11,497` | A fresh install has no author→content or meta→content referential integrity. Deleting a user or content does not cascade; orphan rows accumulate. This is not cosmetic: `cms_users` is auth-owned and deletable. |
| **HIGH (real)** | migration drift | **`026_cms_username_case_sensitive.sql` exists on disk but is absent from `module.json` `migrations`, so it never runs.** `comm -23` of disk files vs declared list returns exactly this file. | `modules/cms/database/migrations/026_cms_username_case_sensitive.sql:4`; `module.json` migrations array | Case-sensitive usernames are not enforced on installs that predate the `utf8mb4_bin` change folded into `001_cms_foundation.sql`. A silently-unapplied migration is worse than a failed one. |
| **HIGH (real)** | ownership | **`modules/cms/module.json` claims ownership of `audit_logs`, `kernel_search_index`, `workflow_instances`, `workflow_transition_logs` — none of which any CMS migration creates.** The search module independently declares `co_owns_tables: ["kernel_search_index"]`. | `module.json:57-60`; `modules/search/module.json:8`; no CMS migration contains those identifiers | Two modules believe they own the search index table. Purge/uninstall policy drops declared owned tables; this is the exact shape the kernel review flagged as destructive. The `cms-table-ownership-matrix.md` says the opposite (plans them for Search/Workflow). |
| **HIGH (real)** | cohesion | **God-files.** `80-customizer.php` 4,411 lines; `85-ai-automation.php` 4,226; `90-public.php` 3,013; `builder-renderers.php` 2,892; `50-builder.php` 2,600. | listed above | These are not "large by accident"; each mixes many concerns (customizer has 7 sections + render + cache + validation). A change to one section risks unrelated sections; review cost is dominated by navigation. |
| **HIGH (doc/code)** | admin IA | **The admin sidebar is hardcoded *and* dynamic.** Page Builder / Theme / Theme Library / Navigation / AI Automation are hardcoded in desktop (`admin.disyl:318-334`) and again in mobile (`:445-461`), while `ext_nav_items` renders manifest contributions in the same sidebar. The product-suite plan explicitly claims these hardcoded links were removed. | `templates/modules/cms/layouts/admin.disyl:318-334,445-461` vs `docs/architecture/product-suite-extension-plan.md` "Full dynamic sidebar migration (2026-08-05)" | Two authorities for the same nav. Enabling/disabling a suite module does not remove the hardcoded link; "no dead links from absent modules" is not true for the hardcoded set. |
| **MEDIUM (real)** | security/dead-path | **`html_embed` treats CMS `administrator` as untrusted.** Trusted roles are `['admin','superadmin']` (`builder-renderers.php:2820`), but the CMS role enum is `superadmin, administrator, editor, author, contributor, subscriber` (`001_cms_foundation.sql:9`). An administrator therefore never reaches the raw-output branch; the branch is effectively superadmin-only and the "trusted" path is dead for its documented role. | `builder-renderers.php:2810-2841`; `001_cms_foundation.sql:9` | Either a functional bug (admins cannot embed) or misleading intent. Sanitisation still runs, so it is not an XSS hole — it is a correctness bug with security-looking code. |
| **MEDIUM (real)** | cross-module | **Admin users screen reads `ec_store_users`/`ec_stores` directly.** It is guarded (`function_exists`, `ecStoreStorageAvailable()`) and uses `ecDb()`, but the module manifest classifies those as `reads_tables_deprecated`. | `handlers/15-admin.php:955-956`; `module.json:74` | A deprecated cross-module read that still ships. Declared debt, but debt. |
| **MEDIUM (real)** | contract drift | **Emitted events exceed declared events.** Code fires `cms.builder.document.saved`, `cms.builder.document.published`, `cms.builder.document.restored`, `cms.builder.reusable.saved`, `cms.content.bulk`; `module.json` declares only 7 events, none of the builder ones or bulk. | `module.json:379`; `helpers/50-builder.php:764`; `handlers/20-api-builder.php:207` | Integrations subscribing from the declared manifest cannot discover builder events. The architecture doc admits the gap; it has not been closed. |
| **MEDIUM (real)** | Akira boundary | **`cms-akira-core` reads a kernel table directly and does not declare it.** `app()->db()` → `kernel_application_profile_registry`, while `reads_tables` is `[]`. | `modules/cms-akira/cms-akira-core/helpers/capabilities.php:148`; `module.json` reads_tables | The README's headline rule is "capability-only calls — no direct foreign-table SQL". This is direct foreign SQL. |
| **MEDIUM (real)** | Akira boundary | **The static boundary gate scans only 3 of 9 provider files.** `$scanFiles` is media, navigation, seo only. Editor calls `cmsEditorNormalizeHtml`/`cmsEditorSanitizeHtml`/`cmsTinyMceAssets`; theme calls `cmsThemeRuntimeDiagnostics`; none are scanned, and none are capability calls. | `tests/cms_akira_foreign_db_access_static_test.php:58-62`; `cms-akira-editor/helpers.php:88,128,193`; `cms-akira-theme/helpers.php:62` | The README claims "no named foreign-helper calls in Akira provider code". False for editor and theme. The gate gives false confidence. |
| **MEDIUM (cosmetic→real)** | tooling | **`php ikabud module:certify <id>` reports spurious FAILED for 10/14 Akira modules** because the single-module branch omits `_path`; `--all` passes them. | `ikabud:6738`; `src/helpers/module-manager.php:2509,3595` | The release note markets this command as the certification proof. Anyone running it per-module concludes the suite is broken. Also masks the one real advisory finding (theme's unregistered `/admin/theme-studio`). |
| **LOW (real)** | state | **All four profiles are simultaneously enabled** (`module:list`), including mutually exclusive `visual` and `headless`; B's review already called the profiles "manifest-only selectors". | `module:list` output; `cms-akira-profile-*/module.json` | Profile is modelled as a module, not a selection; nothing prevents contradictory installs. |
| **LOW (real)** | dead nav | **Profile and provider `nav` entries point at scaffold pages.** e.g. `/admin/cms-akira-profile-minimal`, `/admin/cms-akira-profile-visual`. They resolve (scaffold handlers exist) but render placeholder content. The README explicitly forbids `admin_contributions` on scaffold admin pages, yet `nav` is the same problem by another field. | `cms-akira-profile-*/module.json` nav; README "Safety Rules" | Users get nav to empty pages. Low blast radius, but it is exactly the "five published interfaces nothing exercises" pattern. |
| **LOW (cosmetic)** | docs | Route inventory `/api/...:50` style line references are stale (content list is `routes.php:53`, not `:50`; POST content create is `:138`, not `:135`; workflow transition `:155`, not `:152`). Handler-line references are accurate. | `docs/cms/cms-route-inventory.md` vs `modules/cms/routes.php` | Misleads a reader who opens the cited line. |
| **LOW (cosmetic)** | data | Duplicate entity-view keys (`cms_page` and `cms.page`, `cms_post` and `cms.post`). | `helpers/58-entity-views.php:35,67,73,83` | Intentional alias, but two live registrations per type. |

## Doc-vs-code drift

Ranked by how much damage the stale claim does to someone acting on it.

| damage | doc claim | file:line | what the code actually does |
|---|---|---|---|
| **HIGH** | "The CMS is decomposed into the CMS Akira product suite (14 submodules) … provider modules contributing through provider boundaries" | `docs/cms/cms-architecture.md` §2; `docs/releases/release-notes-2026-08-05-cms-akira-product-suite.md` §1 | `cms-akira-core/module.json:8` declares `depends: ["cms"]`; every provider delegates to legacy `cms.*`. It is a facade, not a decomposition. A reader who plans ownership handoffs from this doc will find none exist. |
| **HIGH** | "Full dynamic sidebar migration (2026-08-05): the hardcoded optional sidebar links … were removed from `admin.disyl`" and release note "The CMS admin sidebar is now driven dynamically" | `docs/architecture/product-suite-extension-architecture-plan.md` (Phase 4, 2026-08-05) | Hardcoded Page Builder/Theme/Theme Library/Navigation/AI Automation remain at `admin.disyl:318-334` and `:445-461`. |
| **HIGH** | "`php ikabud module:certify <suite-module>` ✅" as a validation gate | `docs/releases/release-notes-2026-08-05-cms-akira-product-suite.md` §7 | Single-module form returns `FAILED` for core/seo/ai/editor/theme/navigation/workflow/search-adapter/media because `_path` is not passed. |
| **HIGH** | Example SEO `admin_contributions` with `route: /admin/cms-akira-seo` | `release-notes-2026-08-05…` §4 | `cms-akira-seo/module.json` has **no** `admin_contributions` key (`jq` returns none). Only core and theme declare any. |
| **MEDIUM** | "exposes 32 kernel-callable capabilities" | `docs/cms/cms-architecture.md` §5 | `jq '.capabilities.exposes|length' modules/cms/module.json` = **29**. |
| **MEDIUM** | "handler files split by concern (26 files)" | `docs/cms/cms-architecture.md` §2 | `ls modules/cms/handlers/*.php | wc -l` = **27**. |
| **MEDIUM** | "Total CMS route entries: 180 / owned tables 36 / exposes 25 / migrations 27" | `docs/cms/cms-current-state-inventory.md` §4; `docs/cms/cms-route-inventory.md` | Actual: **190 routes** (GET 103/POST 84/PUT 3), **37 owns_tables**, **29 exposes**, **29 declared migrations**. |
| **MEDIUM** | "No cross-module table access violations" / table ownership matrix assigns `kernel_search_index` elsewhere | `docs/cms/cms-table-ownership-matrix.md`; `docs/cms/cms-risk-register.md` R-006 | `modules/cms/module.json:57-60` claims four kernel/workflow/search tables; `modules/search/module.json:8` claims co-ownership of one. |
| **MEDIUM** | "delegation uses capability-only calls — no direct foreign-table SQL and no named foreign-helper calls in Akira provider code" | `modules/cms-akira/README.md` "Provider Adapters" | `cms-akira-editor/helpers.php:88,128,193` and `cms-akira-theme/helpers.php:62` are named foreign-helper calls; `cms-akira-core/helpers/capabilities.php:148` is direct foreign SQL. The README's own table admits the theme one. |
| **MEDIUM** | "If `modules/<suite>/module.json` exists, nested suite scaffolding is blocked" | `modules/cms-akira/README.md` "Safety Rules" | `modules/cms-akira/module.json` does not exist. |
| **LOW** | Route-test traceability matrix line references | `docs/cms/cms-route-inventory.md` | `/api/v1/cms/content` cited at `:50`, actual `:53`; POST cited `:135`, actual `:138`; workflow transition cited `:152`, actual `:155`. Handler references are correct. |
| **LOW** | "All capability handlers include … audit logging" (reconciliation review) | `docs/reviews/cms-kernel-reconciliation-review-2026.md` §1 | Several capability handlers return a bare `['ok'=>false,'error'=>'Database error']` (`55-capabilities.php:82`) without audit. |

## Is the Akira direction right?

**Position (opinion, backed by the code above): the direction is correct as a kernel/product-platform direction and is genuinely enforced. It is the wrong *next move for the CMS in Tree A*.**

Reasoning:
1. The contract is real. `validateModuleSuiteContractV1()` and `validateModuleSuiteFleetV1()` (`src/helpers/manifest-validation.php:300+`,`:480+`), the suite graph (`module-manager.php:152`), the contribution registry, the install gate at `module-manager.php:4694`, and certification C12/C13 (`:3587+`) are code with tests (`manifest_suite_contract_test`, `module_suite_graph_test`, `contribution_registry_test`, `module_suite_install_gate_test`, `module_suite_compatibility_test`). This is not a documentation exercise. The one weakness is that no shipped Akira manifest declares `contributes`, so that field is unexercised here.
2. But Tree A's Akira is not the target the docs describe. A product core whose only hard dependency is `cms` and whose providers are all pass-throughs adds a capability hop and an extra failure mode for no ownership gain. The Tree-A suite *does* prove the extension model; it does not deliver a CMS.
3. The direction is self-contradictory in two places. README says "capability-only calls, no named foreign-helper calls"; the code makes named foreign-helper calls. README says theme "TARGET: `cms.themes.list@1`"; the code stays on `cmsThemeRuntimeDiagnostics`. A direction with acknowledged TARGETs and an admitted exception is under-specified, not wrong.
4. The relationship is **adapter-over**, not replacement or sibling. `extends: cms-akira-core → depends: cms` is unambiguous. Tree B is converting it to replacement by giving core native tables and a shell, and by renaming `search-adapter`→`search` (an adapter becomes an owner). That rename is a better signal of intent than any doc: **B is the direction; A is the staging area.**

Consequence: do not start by growing Akira in A. Fix the legacy defects that a rewrite would otherwise carry into Akira (the FK-suppressing duplicate migrations are exactly the kind of thing a "port" would forget). Then port B's already-built native modules behind the legacy capabilities, one at a time, with B's tests as the acceptance harness.

## Next level — plan

≤10 items, ranked. ADOPT-FROM-B = already built in B; NEW WORK = not in B. Acceptance checks are runnable by a third party.

1. **Split the duplicate `001`/`003` migrations and add the missing FKs. (NEW WORK)**
   Rationale: the highest-severity real defect; a schema bug that every fresh install carries. Files: `modules/cms/database/migrations/001_cms_content_core.sql`, `001_cms_foundation.sql`, `003_cms_content_fields.sql`, `003_cms_content_types_and_fields.sql`, `module.json`.
   Risk: medium (touches install path). Preserve: 42k LOC untouched; only two DDL files renumbered.
   Acceptance: after a fresh `php ikabud migrate cms`, `SHOW CREATE TABLE cms_content` contains `fk_cms_content_author`; `ls modules/cms/database/migrations | sed -E 's/^([0-9]+)_.*/\1/' | sort | uniq -d` is empty. No duplicate first-number pair remains.
2. **Declare and run `026_cms_username_case_sensitive.sql`. (NEW WORK)**
   Rationale: silently-undelivered migration. Files: `module.json` migrations array.
   Risk: low. Acceptance: `comm -23 <(ls modules/cms/database/migrations | sort) <(jq -r '.migrations[]|split("/")|last' modules/cms/module.json | sort)` is empty.
3. **Remove the hardcoded optional nav from `admin.disyl`. (ADOPT-FROM-B — B's `cms-akira-shell` owns the admin surface).**
   Rationale: two nav authorities; exact plan claim is false. Files: `templates/modules/cms/layouts/admin.disyl:318-334,445-461,623`.
   Risk: medium (UI regression). Acceptance: `grep -c "cms/admin/react-builder/create\|cms/admin/customize\|cms/admin/themes\|cms/admin/menus\|cms/admin/ai-automation" templates/modules/cms/layouts/admin.disyl` returns only the dynamic references (or 0 hardcoded anchors); `tests/cms_admin_contribution_nav_test.php` still passes.
4. **Fix `module:certify <id>` to inject `_path` (use `discoverModules()`). (ADOPT-FROM-B if B fixed; else NEW WORK.)**
   Rationale: a published gate that lies. File: `ikabud:6738`; `src/helpers/module-manager.php`.
   Risk: low. Acceptance: `for m in cms-akira-{core,seo,ai,editor,theme,navigation,workflow,search-adapter,media}; do php ikabud module:certify $m >/dev/null || echo FAIL $m; done` prints only the genuine advisory failure (theme C13). Ideally `module:certify cms-akira-seo` exits 0.
5. **Fix `owner` declarations: remove `audit_logs`, `kernel_search_index`, `workflow_instances`, `workflow_transition_logs` from `modules/cms` `owns_tables`. (NEW WORK)**
   Rationale: double ownership of `kernel_search_index` and phantom ownership of three tables; purge safety. Files: `modules/cms/module.json:57-60`; reconcile `modules/search/module.json:8`.
   Risk: medium (purge/uninstall semantics). Acceptance: every id in `modules/cms` `owns_tables` appears in at least one `modules/cms/database/migrations/*.sql`; `python3 -c` cross-check finds no table owned by two modules.
6. **Fix the `html_embed` trusted-role list to include `administrator`. (NEW WORK)**
   Rationale: dead trusted path; correctness. File: `modules/cms/builder-renderers.php:2820`.
   Risk: low (security-sensitive; the sanitised branch remains the default for non-admins).
   Acceptance: a unit/HTTP test renders a raw `<script>` embed as a CMS `administrator` and asserts it is preserved, and as an `author` and asserts it is stripped.
7. **Port B's native content model behind the legacy `cms.content.*` contracts. (ADOPT-FROM-B.)**
   Rationale: B already implements posts/taxonomies/content-types/revisions/redirects with 24 tests; porting behind the frozen `@1` contracts (v1 freeze doc) preserves consumers. Files: `modules/cms-akira/cms-akira-core/database/migrations/002..010`, `helpers/{capabilities,redirects,entity-views,settings}.php`, plus B's `cms-akira-core/tests/*`.
   Risk: high (data ownership, dual-write). Preserve: `cms.content.*` contract; `cms_content` read path.
   Acceptance: `tests/cms_contract_freeze_v1_test.php`, `cms_content_consumer_compat_test.php` and B's `post_mutation_test.php`, `taxonomy_mutation_test.php`, `redirect_capability_test.php` all pass against Tree A.
8. **Port B's `cms-akira-shell` + admin IA + tests. (ADOPT-FROM-B.)**
   Rationale: gives the suite a real admin/public surface and 20 tests, and makes item 3 safe. Files: `modules/cms-akira/cms-akira-shell/**`.
   Risk: medium. Acceptance: the 20 shell tests run green; removing a hardcoded nav (item 3) does not change the rendered contribution set.
9. **Add a behavioural test at the public rendering seam and the tenant seam. (NEW WORK.)**
   Rationale: 1,964 `t()` assertions exist but the reviewed public handlers are not covered by an executed render test in this review. Files: `tests/cms_public_render_test.php`, `tests/cms_tenant_cache_isolation_test.php`.
   Risk: low. Acceptance: publishing a post through `cms.content.update@1` returns the rendered body at `/cms/blog/{slug}`; two tenants with different `active_theme` do not share a cached public page.
10. **Split `80-customizer.php` and `85-ai-automation.php` by section, mechanically. (NEW WORK; low priority.)**
    Rationale: god-files, but no behaviour change. Files: `modules/cms/helpers/80-customizer.php` → `80-customizer-{footer,header,sidebar,colors,custom-code}.php`; same for AI automation.
    Risk: low if mechanical. Acceptance: `git diff --stat` shows moves only; `php -l` clean; existing customizer tests pass; the public render byte-for-byte output (or a captured hash) is unchanged.

**Which comes first: "make legacy better" or "converge onto Akira"?** Make legacy better first (items 1, 2, 5, 6). They are small, verifiable, and are preconditions for any honest handoff. Convergence (items 3, 4, 7, 8) is ADOPT-FROM-B and should follow, not lead.

## What I would NOT do

- **Do not rewrite `modules/cms`.** 42,152 LOC, 190 routes, 37 tables and 1,964 assertions protect behaviour; B's 31,469 LOC is the eventual replacement but it is not a drop-in. A rewrite without a migration rehearsal risks public URLs, revisions and builder documents.
- **Do not delete the Akira providers in Tree A.** They are the executable proof that the extension contract works (`cms_akira_phase5_6_compose_test`, `cms_akira_core_adapter_contract_test`). They cost 3,407 LOC and provide a tested fallback seam.
- **Do not add a fifth profile or more provider modules.** Four profiles are already contradictory and enabled at once; the fragmentation is the problem, not the count.
- **Do not "fix" the suite contract by loosening validation.** The enforcement is the one thing in this area that is demonstrably correct.
- **Do not treat the 26 Playwright specs in B or the unexecuted tests here as passing evidence in this review.** They were not run (read-only).

## Unverified / uncertain

- **Not executed:** any DB-mutating test, any migration, any Playwright spec, any HTTP request. The FK-suppression finding is derived from the two DDL files plus `MigrationRunner.php:11,497` (filename sort); it was not confirmed with `SHOW CREATE TABLE` against a live database.
- **Not executed:** `php ikabud architecture:check`. The risk register claims it passes; I did not run it.
- **Unverified:** whether `MigrationRunner`'s `usort` key includes the directory prefix (it compares `$a['key']`, which may include the module path) — either way `001_cms_content_core` precedes `001_cms_foundation`, so the conclusion holds, but the precise ordering mechanism beyond filename sort is not confirmed line-by-line.
- **Unverified:** object-level author checks for `cms.content.update@1`. `cms_cap_cms_content_update_1` (`55-capabilities.php:455`) was read only in part; the B review flagged an "unproven but high-risk" guessed-slug update case. I did not disprove it.
- **Unverified:** whether `cms_admin_contribution_nav_test.php` would fail if the hardcoded nav were removed (it likely asserts presence, not absence).
- **Opinion, not measurement:** the severity ranking and the "legacy first" ordering.
- The Tree B working tree is very dirty (`git status --porcelain` shows hundreds of staged/untracked entries, including `A modules/cms/...`). B counts are as-found, not as-committed.

## Why I couldn't continue

I could continue reading indefinitely, but I could not turn the remaining source questions into live truth because **irreversibility** stopped me: every authoritative check left (fresh-install `SHOW CREATE TABLE`, the two-tenant cache-isolation test, the behavioural render test, the Playwright specs) provisions or drops databases, writes fixtures, caches, uploaded files and result artefacts, and this assignment permits writing only this report file. I therefore stopped at static evidence plus read-only CLI output rather than violate that boundary. Authority and boundary were themselves findings, not blockers: the code *does* enforce the suite contract, and the boundary violations I found (`kernel_application_profile_registry`, the `owns_tables` overreach, the named foreign-helper calls) are reportable defects, not reasons I was unable to proceed.
