# ARK Follow-Up Audit — UNVERIFIED Seams Resolved (2026-08-22)

Follow-up to `.ai/ark-audit-report.md` + `.ai/ark-direction.md`, per the
`READY_FOR_AUDIT` contract. Resolves every seam previously marked UNVERIFIED.
Status: **AUDIT COMPLETE** (read-only; no files modified).

---

## Resolved seams (was UNVERIFIED → now evidenced)

### 1. ThemeManifestValidator coverage — **confirmed gap**
`kernel/Services/ThemeManifestValidator.php` core key schema validates only:
`schema` `supported_surfaces` (enum public/admin/print/email/export),
`schema` `supported_slots` (array of strings), `tokens` (path), `shell` (path),
`fallback_views` (object), plus `layouts/` dir existence, `tokens.json`
validity, `validateArkContracts()`, `validateThemeSafetyPolicy()`.
- **`customizer`, `regions`, `capabilities`, `theme`, `layout` are NOT in the
  validator's key schema** → loaded-but-unvalidated / documentation-only.
  Confirms the debate finding; validator coverage must be extended for these
  five key groups (type/schema, unknown-key, path-safety, cross-reference).

### 2. SlotRegistry — **exists**
`kernel/Services/SlotRegistry.php` (also referenced from
`kernel/App.php`, `kernel/DiSyL/ComponentRegistry.php`). Governed slots are a
real Kernel service; ARK's `slots.json`/`{ikb_slot}` map onto it.

### 3. `cmsThemeManifestForSlug` — **does not exist**
The contract assumed this name; the actual loader is **CMS-owned**:
`modules/cms/helpers/40-theme-settings.php` →
- `cmsActiveTheme(): ?string` (L627)
- `cmsActiveThemeManifest(): array` (L931)
- `cmsResolveThemeTemplateAliasPath()` (L717) — resolves `_cms_active_theme/`
  aliases used by `TemplateCache` / `TemplateEngine`.
Kernel `ThemeCustomizerOrchestrator` uses them via `function_exists()`
conditional (L74, `activeThemePath()` L419).

### 4. Activation state — **CMS-owned, not ARK, not kernel**
Active-theme state + alias resolution live in the CMS module
(`40-theme-settings.php`). ARK has no persistence hooks; activation/selection is
a CMS (module) responsibility. The three persistence concerns are distinct:
- ARK package assets: immutable files under `storage/cms-themes/ark/`
- Activation state: CMS-owned setting resolved via `cmsActiveTheme()`
- Module capability-handler queries: module-owned, tenant-scoped (see #6)

### 5. ARK test files — **all exist**
`tests/`: `ark_theme_test.php`, `ark_capability_bridge_test.php`,
`ark_renderer_contract_test.php`, `ark_safety_test.php`,
`ark_a11y_audit_test.php`, `theme_manifest_validation_test.php`,
`theme_manifest_direct_test.php`, `theme_manifest_integration_test.php`.
(Existence confirmed; pass-state not re-run in this read-only audit.)

### 6. Capability-handler tenant behavior — **tenant-isolated by design**
`kernel/EntityContext/EntityListQuery.php`: module-scoped `PDO` (L36) + optional
`tenant_id = :tid` binding via `tenantId()` (L24). Entity list/get handlers
therefore query the owning tenant DB with tenant-context binding available.

### 7. P0 safe-fallback lines — **revalidated**
`EntityViewResolver.php` L112 (`'fields'=>'*'` default), L229 (wildcard
fallback), L369 (`array_keys($rows[0])` display fields), L696/L713; 
`DefaultEntityRenderer.php` L104, L121–128 (`visible_fields` intersection only
when non-empty; `*` → `array_keys($rows[0])`). Stands.

---

## Remaining notes
- JWT/role/CSRF request-seam shape: not exhaustively traced here (would require
  reading the CMS public route auth flow); tests should adapt to the discovered
  seam rather than assume JWT. Marked **UNVERIFIED-by-choice** (out of the 8-call
  budget focus; low risk since themes don't authorize).
- All other UNVERIFIED markers in `.ai/current-task.md` (ARK contract) are now
  RESOLVED above; implementation can proceed with evidence for the seams.

## Net effect on the direction
- P0 (safe-fallback enforcement) unaffected and remains the top priority.
- P1 validator work is now concrete: extend `ThemeManifestValidator` for
  `customizer/regions/capabilities/theme/layout`.
- Slot work targets the real `SlotRegistry`.
- Activation/persistence questions answered: CMS owns activation; ARK owns no
  persistence; module handlers are tenant-scoped.
