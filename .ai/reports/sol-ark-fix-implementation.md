# SOL ARK fix implementation

Base: `165cf368fd5d05abca408093160d52b0f4e63a3a`

## Baseline and tenant preservation

Before editing, I ran the required fetch:

```text
curl -s http://cmsnew.test/cms/page/contact > /tmp/before.html
53119 bytes
sha256 69428ed76a327b8ca2b216f2e7a27b37bd05e978bc8fef182d373f6205a32fe3
```

The existing header row contained, in order, `hw_1774864156711_9k7lrb` (`FREE SHIPPING`) and `hw_1774865535104_8wdar1` (`contact_info`). The top bar contained no widgets. The stored header widget JSON had no `location` or `style` keys.

After implementation, mutation probes were restored, cache was flushed, and `/tmp/final.html` had the same widget placement and order:

```text
before topbar=[] header=[hw_1774864156711_9k7lrb, hw_1774865535104_8wdar1]
after  topbar=[] header=[hw_1774864156711_9k7lrb, hw_1774865535104_8wdar1]
```

Relevant markup diff:

```diff
 <div class="ark-topbar__left">
-<div class="ark-topbar__right" aria-hidden="true">
 <div class="ark-region-header__widgets" style="display:flex;flex-wrap:wrap;gap:1rem 1.5rem;align-items:center;">
 FREE SHIPPING
```

Thus existing tenant widget content did **not** move. The only top-bar structural change is the specified removal of the empty, aria-hidden right column. FIX 2 also deliberately removes the shared default `width:100%` from the existing header `contact_info` widget's emitted style; its content and region are unchanged.

## Implemented fixes

### FIX 1 / `topbar-widgets-render-in-wrong-region` — done

Files/lines:

- `kernel/Services/ThemeRegionRenderer.php:50-80,110-129`: split header widgets explicitly; absent `location` means `header`; preserve `widgets_html`; add `widgets_topbar_html`.
- `storage/cms-themes/ark/templates/regions/header.disyl:105-107`: conditionally emit top-bar widgets in `.ark-topbar__right`, without `aria-hidden`.
- `storage/cms-themes/ark/safety-policy.json:24`: permit the kernel-produced raw key.
- `templates/modules/cms/admin/theme-customizer.disyl:4694-4704`: “Add Top Bar Widget” persists `location: 'topbar'`.
- `tests/cms_customizer_widgets_bridge_test.php:124-164`: compatibility, split, template, and policy coverage.

Emitted-markup falsification:

1. Temporarily added `location:'topbar'` to the live `FREE SHIPPING` widget, flushed cache, and fetched the page.
2. The emitted markup put that ID under `.ark-topbar__right`, removed it from `.ark-region-header__widgets`, and emitted no `aria-hidden`:

```text
topbar_right=FREE SHIPPING
topbar_ids=[hw_1774864156711_9k7lrb]
header_ids=[hw_1774865535104_8wdar1]
aria_hidden=None
```

3. Removed the mutation, flushed, and fetched again. `.ark-topbar__right` was absent and both IDs returned to the header row in their original order. This demonstrates both sides of the location discriminator and falsifies any implementation that ignores `location` or treats missing location as topbar.

I did not add a move-location editor. That would require introducing a distinct main-header add/move UX into a panel currently describing all of these controls as top-bar widgets; it is larger than the requested wiring correction.

### FIX 2 / `header-width-important-overrides-explicit-style` — done

Files/lines:

- `kernel/Services/ThemeRegionRenderer.php:138-184`: `renderWidgets()` now receives region context and removes only the default width for a header widget lacking explicit width.
- `storage/cms-themes/ark/style.css:969` and `public/assets/cms/themes/ark/style.css:969`: the former `width:auto !important` rule is gone.
- Republished with `php ikabud theme:publish ark`.
- `tests/cms_customizer_widgets_bridge_test.php:126-136`: default removal, explicit width preservation, and non-header preservation.

Emitted-markup/browser evidence: temporarily persisted `style.width='50%'` on the live header contact widget. Its emitted style contained `--b-width:50%;width:var(--b-width)`. Playwright measured the widget against its containing widget row:

```text
viewport 1303: 193.312 / 386.625 = 0.500
viewport  768: 193.312 / 386.625 = 0.500
viewport  375: 171.500 / 343.000 = 0.500
```

Falsification: injecting the deleted old rule (`width:auto !important`) into each browser page changed those ratios to `0.631`, `0.631`, and `0.711`; the 50% assertion failed at every required viewport. The DB mutation was then removed and cache flushed.

### FIX 3 / `widget-prop-alias-safe-but-not-general` — done

Files/lines:

- `kernel/Services/ThemeRegionRenderer.php:214-243`: retained mechanical aliases and added explicit translations:
  - `button_label` -> `buttonText`
  - `opening_hours.icon` -> boolean `showIcon`
  - truthy `new_tab` -> `target='_blank'` when `target` is not explicit
- `tests/cms_customizer_widgets_bridge_test.php:89-116`: emitted output assertions.

`font_size` and `font_weight` are intentionally excluded from conversion because those values belong to widget `style`, not renderer props.

Falsification through actual renderer output:

```text
search_box   direct=NOT-translated adapted=translated
opening_hours direct=NOT-translated adapted=translated
button       direct=NOT-translated adapted=translated
```

The direct builder renderer produced its default search label, retained the opening-hours icon for `icon=0`, and omitted `_blank`; the region adapter output produced `Find`, omitted the SVG, and emitted `target="_blank" rel="noopener noreferrer"` respectively.

### FIX 4 / `topbar-right-dead-column` — done via FIX 1

The right column is absent when empty and contains real widget markup when the topbar mutation is present. The FIX 1 mutation is also the falsification: removing/ignoring `widgets_topbar_html` makes the right-column/content assertion fail.

### FIX 5 / `topbar-data-attributes-dead` — done

- `storage/cms-themes/ark/templates/regions/header.disyl:31`: added the requested stable-hook comment.
- No attribute was removed.

Emitted markup had the same 13 `data-topbar-*` attributes before and after. The preservation check expects all 13 names; deleting any attribute from the fixture changes the set/count and falsifies it.

## Suites and checks

Baseline and final required suite results were green. Final results:

| Suite | Result |
|---|---:|
| `ark_declared_control_honour` | 107 passed / 0 failed |
| `ark_sidebar_targeting` | 3 / 0 |
| `footer_bar_contrast` | 32 / 0 |
| `cms_customizer_widgets_bridge` | 21 / 0 (baseline 11 / 0) |
| `cms_per_type_presentation` | 18 / 0 |
| `theme_studio_preset_capability` | 7 / 0 |

`php -l` passed for both touched PHP files: `kernel/Services/ThemeRegionRenderer.php` and `tests/cms_customizer_widgets_bridge_test.php`. `git diff --check` passed.

Log baseline sizes were `app.log=9917` and `error.log=4559`. No new error/critical/fatal/parse entries appeared after that point. Existing strict-template, missing test DB table, and PHP Reflection deprecation warnings recurred during probes/suites; none was introduced by runtime production code. The test no longer calls deprecated `ReflectionMethod::setAccessible()`.

## Separate migration analysis: schema/validator vocabulary divergence

No schema/validator behavior was changed.

### Current storage evidence

`cmsnewtest.cms_theme_customizer` has a unique `section` column, so this tenant has one row per section rather than theme/scope-specific rows:

- row `1957`, `footer`: all 20 keys use the CMS validator spelling. In particular it stores `bg_color`, `text_color`, `link_color`, `link_hover_color`, and `title_color`; none of the five `footer_*_color` schema spellings is present.
- row `4`, `colors`: all 37 keys use CMS spellings (`color_primary`, `body_bg_color`, `font_body`, storefront keys, etc.); none of the 18 ARK schema spellings is present.
- row `6`, `theme`: all 6 keys use CMS layout spellings (`layout_mode`, `site_max_width`, `content_max_width`, and three padding keys); none of the 7 ARK schema spellings is present.

Reserved-section de-duplication means the current CMS UI writes these CMS vocabularies. A safe migration must therefore preserve them as the initial canonical source and must not merely switch validator whitelists to schema keys.

### Footer mapping

| ARK schema | CMS validator/storage | Migration rule |
|---|---|---|
| `footer_bg_color` | `bg_color` | exact alias |
| `footer_text_color` | `text_color` | exact alias |
| `footer_link_color` | `link_color` | exact alias |
| `footer_link_hover_color` | `link_hover_color` | exact alias |
| `footer_title_color` | `title_color` | exact alias |

The other 15 controls have matching names. Their domains/defaults still need normalization tests: schema `inner_width=0` versus CMS `contained|full-width`; schema `widget_inner_width_mode=inherit` versus CMS `boxed|contained|full|custom`; schema custom width `1200` versus CMS CSS length `960px`; and differing boolean/default values.

Safe path: on read, populate schema aliases from CMS keys only when the schema spelling is absent. On write, accept either spelling, reject conflicting dual values or apply a documented CMS-key precedence, validate once, and persist only CMS canonical names. After one compatibility release, a migration can remove any discovered schema aliases. The live row itself needs no rewrite.

### Colors mapping

Exact or defensible aliases:

| ARK schema | CMS key |
|---|---|
| `primary` | `color_primary` |
| `secondary` | `color_secondary` |
| `accent` | `color_accent` |
| `background` | `body_bg_color` |
| `text` | `body_text_color` |
| `text_muted` | `body_text_light` |
| `link` | `body_link_color` |
| `link_hover` | `body_link_hover` |
| `border` | `border_color` |
| `surface_muted` | `light_bg_color` (confirm product semantics before migration) |

There is no lossless CMS equivalent for `primary_dark`, `primary_light`, `surface`, `text_secondary`, `success`, `warning`, `danger`, or `info`. Storefront success/warning/danger pairs are component background/text roles and must **not** be silently repurposed as global semantic colors. Conversely, CMS has typography/layout, heading, and storefront keys not represented by this schema section.

Safe path: keep all 37 CMS keys canonical for current consumers; expose the exact aliases above at the context boundary. Before enabling the remaining schema controls, extend the canonical validator/storage contract with dedicated semantic-color keys and teach token/CSS generation to consume them. Backfill only missing canonical values from a legacy/schema key, record conflicts, and retain dual-read compatibility until all tenant rows are audited. Do not overwrite current CMS values with ARK defaults.

### Theme mapping

ARK's `theme` section mixes layout and typography that CMS stores in two sections:

| ARK schema | Current canonical location | Conversion |
|---|---|---|
| `max_width` | `theme.site_max_width` | integer px |
| `font_family` | `colors.font_body` | normalize family-list syntax |
| `heading_font` | `colors.font_heading` | normalize family-list syntax |
| `body_font_size` | `colors.font_size_base` | integer px |
| `line_height` | `colors.line_height` | numeric |
| `border_radius` | `colors.border_radius` | schema px -> CMS rem (for default equivalence, `8px / 16 = 0.5rem`) |
| `heading_weight` | no CMS key | add a dedicated canonical key before exposing/migrating |

The CMS theme row additionally owns `layout_mode`, `content_max_width`, `content_padding_x`, `content_padding_top`, and `content_padding_bottom`; these have no ARK schema counterpart and must remain untouched.

Safe path: do not implement this as a same-row rename. It requires a transaction spanning `theme` and `colors`, explicit unit conversion, conflict precedence favoring existing CMS keys for this tenant, and rollback/audit output. Add `heading_weight` to a chosen canonical section first. A dry-run migration should report old value, candidate converted value, canonical winner, and every conflict; only then dual-read aliases should be deployed, followed by backfill and eventual alias retirement.

## Blocked/stopped

None. The out-of-scope vocabulary divergence was analyzed only and not implemented.
