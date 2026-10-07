# ARK shell responsive fix and assembled-page ratchet

## A — root cause and fix

The main flex item already had `min-width: 0`; making it shrink harder would not fix this. The governed sidebar was being assigned the same layout twice: `.ark-sidebar-region` reserved 300px, the component renderer added a padded `.ark-sidebar` card, and the region template then emitted another fixed 300px `.ark-region-sidebar` plus a 32px margin. The outer 300px box therefore contained 407px of descendants. The search input's intrinsic minimum added the final overflow. Separately, the existing `max-width: 768px` column breakpoint retained `align-items:flex-start`, so the no-sidebar builder main used its 689px intrinsic width at 375px.

The fix keeps the existing 768px architecture breakpoint rather than inventing another one. The renderer wrapper is now the sole owner of the configured sidebar width, placement, and gap; the inner region is fluid and remains the card owner. The governed shell removes only its duplicate wrapper card/spacing. The content shell is explicitly viewport-relative below its max-width, the stacked main is 100% wide, and the search input may shrink. Left/right placement is applied to the shell flex item. There is no `!important`.

Files/lines:

- `modules/cms/helpers/78-public-context.php:433-471` carries configured gap alongside placement/width.
- `kernel/DiSyL/Component/ComponentRenderer.php:970-987` exposes those three configured values on the renderer wrapper.
- `storage/cms-themes/ark/templates/regions/sidebar.disyl:15-33` leaves geometry to that wrapper while retaining card variables and targeting metadata.
- `storage/cms-themes/ark/style.css:218-230,487-553` and published twin `public/assets/cms/themes/ark/style.css` establish one geometry owner, preserve left/right placement, make the search row shrink, and use the existing 768px stack.

Widths chosen: 375 (compact mobile), 768 (the existing stack boundary), 1024 and 1152 (tablet/small desktop, including the failing range), 1280 (the configured content cap), and 1440/1920 (wide shells/alignment). A separate 1077px reproduction retains the owner's exact diagnostic width.

### Geometry before → after (`clientW/scrollW`)

| page | 375 | 768 | 1024 | 1152 | 1280 | 1440 | 1920 |
|---|---|---|---|---|---|---|---|
| contact | 375/376 → 375/375 | 768/768 → same | 1024/1131 → 1024/1024 | 1152/1259 → 1152/1152 | 1280/1387 → 1280/1280 | 1440/1467 → 1440/1440 | 1920/1920 → same |
| blog | 375/407 → 375/375 | 768/768 → same | 1024/1131 → 1024/1024 | 1152/1259 → 1152/1152 | 1280/1387 → 1280/1280 | 1440/1467 → 1440/1440 | 1920/1920 → same |
| home | 375/689 → 375/375 | 768/768 → same | 1024/1024 → same | 1152/1152 → same | 1280/1280 → same | 1440/1440 → same | 1920/1920 → same |

At 1077px my Chromium environment measured contact and blog at `1077/1184` before and `1077/1077` after (the owner's scrollbar-bearing run was `1064/1163`). After the fix the sidebar geometry was: shell main 745px, configured gap 32px, region/card 300px, right edge 1077px; search input shrank from 244px to 172px and its button ended at 1060px.

Before screenshots I stated this expectation: “at 1077px the configured 300px sidebar remains a single right-hand card with a 32px content gap while the main column yields; at 375px it stacks below content, stays 300px wide within the viewport, and its search row remains inside the card.” Screenshots at both widths matched. The card border/radius/padding remained on `.ark-region-sidebar__widget`; targeting remained green.

## B — assembled-page ratchet

Test: `tests/browser/ark-real-page-responsive.spec.js`

It visits `/cms/page/contact`, `/cms/blog`, and `/`, flushes FPM APCu before every page/width check, verifies expected sidebar presence, and compares the real document `scrollWidth` and `clientWidth` at all seven widths. It reports the shallowest element reaching the overflow edge. This is additive to the unchanged 31-case fixture matrix.

### BASE-TREE FAILURE VERBATIM

Command: `APP_URL=http://cmsnew.test npx playwright test tests/browser/ark-real-page-responsive.spec.js --reporter=line`

```text
ARK assembled-page responsive measurements

  contact 375px: clientW=375 scrollW=376 overflow=true
  contact 768px: clientW=768 scrollW=768 overflow=false
  contact 1024px: clientW=1024 scrollW=1131 overflow=true
  contact 1152px: clientW=1152 scrollW=1259 overflow=true
  contact 1280px: clientW=1280 scrollW=1387 overflow=true
  contact 1440px: clientW=1440 scrollW=1467 overflow=true
  contact 1920px: clientW=1920 scrollW=1920 overflow=false
  blog 375px: clientW=375 scrollW=407 overflow=true
  blog 768px: clientW=768 scrollW=768 overflow=false
  blog 1024px: clientW=1024 scrollW=1131 overflow=true
  blog 1152px: clientW=1152 scrollW=1259 overflow=true
  blog 1280px: clientW=1280 scrollW=1387 overflow=true
  blog 1440px: clientW=1440 scrollW=1467 overflow=true
  blog 1920px: clientW=1920 scrollW=1920 overflow=false
  home 375px: clientW=375 scrollW=689 overflow=true
  home 768px: clientW=768 scrollW=768 overflow=false
  home 1024px: clientW=1024 scrollW=1024 overflow=false
  home 1152px: clientW=1152 scrollW=1152 overflow=false
  home 1280px: clientW=1280 scrollW=1280 overflow=false
  home 1440px: clientW=1440 scrollW=1440 overflow=false
  home 1920px: clientW=1920 scrollW=1920 overflow=false

RESPONSIVE OFFENDERS (11):

  contact 375px: header.ark-header right=375 clientW=375 scrollW=376
  contact 1024px: button right=1131 clientW=1024 scrollW=1131
  contact 1152px: button right=1259 clientW=1152 scrollW=1259
  contact 1280px: button right=1387 clientW=1280 scrollW=1387
  contact 1440px: button right=1467 clientW=1440 scrollW=1467
  blog 375px: button right=407 clientW=375 scrollW=407
  blog 1024px: button right=1131 clientW=1024 scrollW=1131
  blog 1152px: button right=1259 clientW=1152 scrollW=1259
  blog 1280px: button right=1387 clientW=1280 scrollW=1387
  blog 1440px: button right=1467 clientW=1440 scrollW=1467
  home 375px: main#main-content.ark-main right=689 clientW=375 scrollW=689
```

Final: all 21 assembled-page cases report exact `clientW == scrollW`; `RESPONSIVE OFFENDERS (0)`.

## Suites

- `ark_declared_control_honour` → 107 passed, 0 failed
- `ark_sidebar_targeting` → 3 passed, 0 failed
- `footer_bar_contrast` → 32 passed, 0 failed
- `cms_customizer_widgets_bridge` → 34 passed, 0 failed
- `cms_per_type_presentation` → 18 passed, 0 failed
- `theme_studio_preset_capability` → 7 passed, 0 failed
- `ark_region_persistence_vocabulary` → 3 passed, 0 failed
- `ark_footer_colour_vocabulary` → 14 passed, 0 failed
- `entity_presentation_renderer_bridge` → 6 passed, 0 failed
- `guidance_entity_view_test` → 44 passed, 0 failed
- `ark-region-widget-context-matrix.spec.js` → 31 cases, `OFFENDERS (0)`; alignment remains 31.5px at 1303 and 340px at 1920
- `ark-real-page-responsive.spec.js` → 21 cases, `RESPONSIVE OFFENDERS (0)`
- PHP lint, CSS source/published parity, and `git diff --check` → pass

## Blocked

Nothing. Colors/theme vocabulary divergence was not implemented.
