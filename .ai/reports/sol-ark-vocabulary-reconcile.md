# ARK vocabulary reconciliation

## Deliverable 1 — footer colours: PASS

The canonical persisted vocabulary remains the live CMS spelling: `bg_color`, `text_color`, `link_color`, `link_hover_color`, and `title_color`. No row migration or rename was performed.

- `storage/cms-themes/ark/customizer.schema.json:670-699` now declares the canonical CMS keys.
- `storage/cms-themes/ark/templates/regions/footer.disyl:4-7,26` reads those canonical keys.
- `modules/cms/helpers/80-customizer.php:2969-2985` additively reads the five former `footer_*` spellings only when the corresponding canonical key is absent. Canonical values win conflicts; aliases are not written back.
- `storage/cms-themes/ark/src/ArkCustomizerProvider.php:114-134` provides the same additive compatibility at the provider context boundary for imported/direct contexts.
- `tests/ark_footer_colour_vocabulary_test.php` covers validator save -> JSON reload -> actual DiSyL markup, canonical-only writes, canonical conflict precedence, and all five old read aliases.

Live `cmsnewtest` row 1957 was confirmed to contain the canonical spellings. A reversible live probe saved all five distinctive values through `cmsUpsertCustomizerSection`, flushed `/_tmp_cache_flush.php?full=1`, fetched `/cms/page/contact`, and observed this emitted markup (the original row was then restored and cache flushed again):

```text
background:#123456;color:#abcdef
--footer-link-hover:#13579b
--footer-link:#fedcba
--footer-title:#2468ac
```

Post-fix deterministic save/reload/render probe: `14 passed, 0 failed`.

### Falsification on the starting tree (`1b60c34e`)

The final probe tests were copied into a detached worktree at the starting commit. The footer probe failed `7 passed, 7 failed`: four old read-alias assertions failed and all three emitted-markup assertions failed. Its rendered footer still emitted the defaults (`background:#1e293b;color:#cbd5e1`, `--footer-link-hover:#ffffff`, `--footer-link:#94a3b8`, `--footer-title:#f1f5f9`) despite the five canonical probe values. The post-fix tree emits all five distinctive values and passes 14/0.

## Deliverable 2 — persistence-vocabulary ratchet: PASS

Test: `tests/ark_region_persistence_vocabulary_test.php`.

It complements (and does not alter) `tests/ark_declared_control_honour_test.php`: that existing test checks declared control -> rendered output, while the new test enumerates every `*.disyl` file under ARK's `templates/regions`, extracts every `section_settings.<key>` read, parses the corresponding CMS defaults and validator bodies, and requires every read to be in the persisted vocabulary and explicitly handled by the validator. It is DB-free and deterministic.

Starting-tree failure output, verbatim:

```text
ARK region persistence vocabulary ratchet

  FAIL: footer template reads keys its validator cannot produce :: footer_bg_color, footer_link_color, footer_link_hover_color, footer_text_color, footer_title_color
  PASS: header template reads only validator-produced keys
  PASS: sidebar template reads only validator-produced keys

2 passed, 1 failed
```

Full starting-tree mismatch list: exactly `footer_bg_color`, `footer_link_color`, `footer_link_hover_color`, `footer_text_color`, and `footer_title_color`. No header or sidebar mismatches were found. Post-fix result: `3 passed, 0 failed`.

Allowlist: empty. The test includes a commented location for genuine template-only defaults, but no mismatch was hidden there.

## Deliverable 3 — colors/theme assessment only

No `colors` or `theme` behavior was changed.

### `colors`

Live evidence: `cmsnewtest.cms_theme_customizer` row 4 holds all 37 CMS spellings: `color_*`, `body_*`, `border_color`, `light_bg_color`, heading/typography/layout keys, and the storefront role keys. It holds none of ARK's 18 schema spellings.

| ARK spelling | Current CMS spelling | Assessment |
|---|---|---|
| `primary` | `color_primary` | safe additive alias |
| `secondary` | `color_secondary` | safe additive alias |
| `accent` | `color_accent` | safe additive alias |
| `background` | `body_bg_color` | safe additive alias |
| `text` | `body_text_color` | safe additive alias |
| `text_muted` | `body_text_light` | safe additive alias |
| `link` | `body_link_color` | safe additive alias |
| `link_hover` | `body_link_hover` | safe additive alias |
| `border` | `border_color` | safe additive alias |
| `surface_muted` | `light_bg_color` | plausible, but requires an owner semantics decision |
| `primary_dark`, `primary_light`, `surface`, `text_secondary`, `success`, `warning`, `danger`, `info` | none | no lossless alias |

The CMS storefront success/warning/danger background/text pairs are component roles and are not safe aliases for global semantic colours. The CMS row also owns typography, heading, layout, and storefront keys absent from the ARK schema. Therefore a few context-only aliases are safe, but complete reconciliation needs a contract-extension/migration decision: add dedicated canonical semantic keys, define conflict precedence (existing CMS values should win), audit/backfill only missing values, and retain dual reads during transition. A same-row rename is not safe.

### `theme`

Live evidence: `cmsnewtest.cms_theme_customizer` row 6 stores `layout_mode`, `site_max_width`, `content_max_width`, `content_padding_x`, `content_padding_top`, and `content_padding_bottom`. It holds none of ARK's seven schema spellings. Related typography values are in `colors` row 4.

| ARK spelling | Current canonical location | Assessment |
|---|---|---|
| `max_width` | `theme.site_max_width` | alias with integer-px normalization |
| `font_family` | `colors.font_body` | cross-section alias; normalize family syntax |
| `heading_font` | `colors.font_heading` | cross-section alias; normalize family syntax |
| `body_font_size` | `colors.font_size_base` | cross-section integer-px conversion |
| `line_height` | `colors.line_height` | cross-section numeric conversion |
| `border_radius` | `colors.border_radius` | unit conversion: schema px vs CMS rem |
| `heading_weight` | none | requires a new canonical key |

A complete `theme` reconciliation is not a safe alias-only change. It needs an owner decision and a transactional, auditable migration spanning `theme` and `colors`, including unit conversion, a home for `heading_weight`, explicit conflict handling, rollback output, and preservation of the five CMS-only layout keys. No migration was attempted.

## Suites

| Suite | Result |
|---|---:|
| `ark_region_persistence_vocabulary` | 3 passed / 0 failed |
| `ark_footer_colour_vocabulary` | 14 / 0 |
| `ark_declared_control_honour` | 107 / 0 |
| `ark_sidebar_targeting` | 3 / 0 |
| `footer_bar_contrast` | 32 / 0 |
| `cms_customizer_widgets_bridge` | 21 / 0 |
| `cms_per_type_presentation` | 18 / 0 |
| `theme_studio_preset_capability` | 7 / 0 |

`php -l` passed for all touched PHP files. `git diff --check` passed.

Log baseline was `app.log=85` and `error.log=43` lines. After live probes and suites, `error.log` remained 43 lines and no new error/fatal/parse entry appeared. `app.log` gained known test warnings: two strict-template `section_settings.template_rules` warnings and Theme Studio's missing `applicationostest.theme_studio_presets` test-DB warnings/capability audit entries.

## Blocked

No implementation blocker for footer. `colors` and `theme` are deliberately assessment-only; complete reconciliation is blocked on the owner decisions described above. Commit: `747d7b3a`.
