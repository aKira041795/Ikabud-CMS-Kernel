# SOL — Header Band and UI Inspection

## A — defect

**Fixed.** `ThemeRegionRenderer::renderWidgets()` now passes `region`, `location`, `orientation`, and `presentation` to the existing six-argument widget renderer (no renderer signature changed). Horizontal `header`/`topbar` calls use `compact-inline`; vertical regions use `card`. No `!important` rule was added.

Header presentations: `text` remains inline; `custom_html` maps to the equivalent builder text renderer; `social_links` is icon-only without card title/chrome; `contact_info` is phone + email only (no title, address, labels, or card); `nav_menu` is horizontal without title/card chrome; `button` remains the small inline button; `opening_hours` is one text/icon row without title/card chrome. The matrix also exposed and fixed the pre-existing Customizer vocabulary aliases `custom_html -> text` and `cta_button -> button`.

Before/after byte comparison for the affected card renderer proves its vertical output did not change:

- sidebar `contact_info`: 1442 bytes, SHA-256 `06e0cbcf75a2f129de1d2b5c749f7c044aadadfa970e04107eaadaadb2d445bc`, byte-identical
- footer `contact_info`: 1441 bytes, SHA-256 `968c89a732ca87d168fa26ce684c5dedcd17d6802e56839502c2de68454eb651`, byte-identical

## B1 — context-matrix ratchet

- Browser test: `tests/browser/ark-region-widget-context-matrix.spec.js`
- Production-render fixture: `tests/fixtures/ark_region_widget_matrix_fixture.php`
- One command: `npx playwright test tests/browser/ark-region-widget-context-matrix.spec.js --reporter=line`
- Coverage is parsed from the actual `headerWidgetTypes`, `sidebarWidgetTypes`, and footer `widgetTypes` declarations; header types are also exercised in topbar. There is no allowlist.
- Header ceiling is **96px**: normal 40px brand + 24px vertical padding = 64px, with 32px platform/font tolerance but no room for a card row. Topbar ceiling is **64px**, twice its normal ~32px line.
- Every case checks rendering, band and widget horizontal overflow, page horizontal scrollbar, and horizontal-band height.
- Base-tree full offender list: header/custom_html, header/social_links, header/contact_info, header/nav_menu, header/opening_hours, topbar/custom_html, topbar/social_links, topbar/contact_info, topbar/nav_menu, topbar/opening_hours, sidebar/custom_html, sidebar/cta_button, footer/custom_html. No offenders were allowlisted.

### BASE-TREE FAILURE VERBATIM

```text

Running 1 test using 1 worker

[1/1] [chromium] › tests/browser/ark-region-widget-context-matrix.spec.js:32:1 › every widget offered by every ARK region preserves that region layout
[chromium] › tests/browser/ark-region-widget-context-matrix.spec.js:32:1 › every widget offered by every ARK region preserves that region layout
ARK region-widget context matrix measurements

  header/text: height=64px rendered=true bandOverflow=false widgetOverflow=false pageOverflow=false

  header/custom_html: height=64px rendered=false bandOverflow=false widgetOverflow=false pageOverflow=false

  header/social_links: height=125.19px rendered=true bandOverflow=false widgetOverflow=false pageOverflow=false

  header/contact_info: height=262.75px rendered=true bandOverflow=false widgetOverflow=false pageOverflow=false

  header/nav_menu: height=125.19px rendered=true bandOverflow=false widgetOverflow=false pageOverflow=false

  header/button: height=70.39px rendered=true bandOverflow=false widgetOverflow=false pageOverflow=false

  header/opening_hours: height=134.8px rendered=true bandOverflow=false widgetOverflow=false pageOverflow=false

  topbar/text: height=41.59px rendered=true bandOverflow=false widgetOverflow=false pageOverflow=false

  topbar/custom_html: height=36.8px rendered=false bandOverflow=false widgetOverflow=false pageOverflow=false

  topbar/social_links: height=117.19px rendered=true bandOverflow=false widgetOverflow=false pageOverflow=false

  topbar/contact_info: height=254.75px rendered=true bandOverflow=false widgetOverflow=false pageOverflow=false

  topbar/nav_menu: height=117.19px rendered=true bandOverflow=false widgetOverflow=false pageOverflow=false

  topbar/button: height=62.39px rendered=true bandOverflow=false widgetOverflow=false pageOverflow=false

  topbar/opening_hours: height=126.8px rendered=true bandOverflow=false widgetOverflow=false pageOverflow=false

  sidebar/text: height=25.59px rendered=true bandOverflow=false widgetOverflow=false pageOverflow=false

  sidebar/custom_html: height=25.59px rendered=false bandOverflow=false widgetOverflow=false pageOverflow=false

  sidebar/nav_menu: height=101.19px rendered=true bandOverflow=false widgetOverflow=false pageOverflow=false

  sidebar/recent_posts: height=101.19px rendered=true bandOverflow=false widgetOverflow=false pageOverflow=false

  sidebar/social_links: height=123.58px rendered=true bandOverflow=false widgetOverflow=false pageOverflow=false

  sidebar/contact_info: height=238.75px rendered=true bandOverflow=false widgetOverflow=false pageOverflow=false

  sidebar/search_box: height=44px rendered=true bandOverflow=false widgetOverflow=false pageOverflow=false

  sidebar/categories: height=101.19px rendered=true bandOverflow=false widgetOverflow=false pageOverflow=false

  sidebar/tag_cloud: height=101.19px rendered=true bandOverflow=false widgetOverflow=false pageOverflow=false

  sidebar/archives: height=101.19px rendered=true bandOverflow=false widgetOverflow=false pageOverflow=false

  sidebar/cta_button: height=25.59px rendered=false bandOverflow=false widgetOverflow=false pageOverflow=false

  footer/text: height=73.59px rendered=true bandOverflow=false widgetOverflow=false pageOverflow=false

  footer/custom_html: height=48px rendered=false bandOverflow=false widgetOverflow=false pageOverflow=false

  footer/nav_menu: height=149.19px rendered=true bandOverflow=false widgetOverflow=false pageOverflow=false

  footer/recent_posts: height=149.19px rendered=true bandOverflow=false widgetOverflow=false pageOverflow=false

  footer/social_links: height=149.19px rendered=true bandOverflow=false widgetOverflow=false pageOverflow=false

  footer/contact_info: height=286.75px rendered=true bandOverflow=false widgetOverflow=false pageOverflow=false

OFFENDERS (13):

  header/custom_html: not-rendered

  header/social_links: height=125.19>96

  header/contact_info: height=262.75>96

  header/nav_menu: height=125.19>96

  header/opening_hours: height=134.8>96

  topbar/custom_html: not-rendered

  topbar/social_links: height=117.19>64

  topbar/contact_info: height=254.75>64

  topbar/nav_menu: height=117.19>64

  topbar/opening_hours: height=126.8>64

  sidebar/custom_html: not-rendered

  sidebar/cta_button: not-rendered

  footer/custom_html: not-rendered

  1) [chromium] › tests/browser/ark-region-widget-context-matrix.spec.js:32:1 › every widget offered by every ARK region preserves that region layout 

    Error: Full offender list:
    header/custom_html: not-rendered
    header/social_links: height=125.19>96
    header/contact_info: height=262.75>96
    header/nav_menu: height=125.19>96
    header/opening_hours: height=134.8>96
    topbar/custom_html: not-rendered
    topbar/social_links: height=117.19>64
    topbar/contact_info: height=254.75>64
    topbar/nav_menu: height=117.19>64
    topbar/opening_hours: height=126.8>64
    sidebar/custom_html: not-rendered
    sidebar/cta_button: not-rendered
    footer/custom_html: not-rendered

    expect(received).toEqual(expected) // deep equality

    - Expected  -  1
    + Received  + 15

    - Array []
    + Array [
    +   "header/custom_html: not-rendered",
    +   "header/social_links: height=125.19>96",
    +   "header/contact_info: height=262.75>96",
    +   "header/nav_menu: height=125.19>96",
    +   "header/opening_hours: height=134.8>96",
    +   "topbar/custom_html: not-rendered",
    +   "topbar/social_links: height=117.19>64",
    +   "topbar/contact_info: height=254.75>64",
    +   "topbar/nav_menu: height=117.19>64",
    +   "topbar/opening_hours: height=126.8>64",
    +   "sidebar/custom_html: not-rendered",
    +   "sidebar/cta_button: not-rendered",
    +   "footer/custom_html: not-rendered",
    + ]

      73 |     for (const offender of offenders) console.log(`  ${offender}`);
      74 |
    > 75 |     expect(offenders, `Full offender list:\n${offenders.join('\n')}`).toEqual([]);
         |                                                                       ^
      76 | });
      77 |
        at /var/www/html/applicationostest/tests/browser/ark-region-widget-context-matrix.spec.js:75:71

    attachment #2: screenshot (image/png) ──────────────────────────────────────────────────────────
    test-results/ark-region-widget-context--8de16-reserves-that-region-layout-chromium/test-failed-1.png
    ────────────────────────────────────────────────────────────────────────────────────────────────

    Error Context: test-results/ark-region-widget-context--8de16-reserves-that-region-layout-chromium/error-context.md

    attachment #4: trace (application/zip) ─────────────────────────────────────────────────────────
    test-results/ark-region-widget-context--8de16-reserves-that-region-layout-chromium/trace.zip
    Usage:

        npx playwright show-trace test-results/ark-region-widget-context--8de16-reserves-that-region-layout-chromium/trace.zip

    ────────────────────────────────────────────────────────────────────────────────────────────────


  1 failed
    [chromium] › tests/browser/ark-region-widget-context-matrix.spec.js:32:1 › every widget offered by every ARK region preserves that region layout 
```

## B2/B3 — testing lesson

Added a 4-line **UI Verification for Regions** rule to `.github/instructions/testing-conventions.instructions.md`: presence/one geometry metric is not visual verification; claims must name visual properties; expected appearance must be stated before a mandatory screenshot; and `REFUTED` must name both tested and deliberately untested properties. It records the concrete width-versus-height failure.

Expected before screenshot inspection: contact info would be one inline phone/email item in a <=96px header, without address/title/card/stacked labels. **Matched** (64px). Expected for the full-width screenshot: at 1920px the bands remain full bleed while header, topbar, footer, and page content begin at 340px. **Matched**. Screenshots were captured by the Playwright ratchet as named attachments.

## C — token and side gutters

Completed after A/B were committed and green. Region templates now use only `var(--ark-max-width)`; the sole 1280px token fallback remains at `storage/cms-themes/ark/style.css:31`. Full header, topbar, footer, and footer-bar inner content uses a max-expression side gutter aligned to the page content. Footer widget grid now occupies its inner row.

Alignment ratchet results:

- 1303px: content/header/topbar/footer left = **31.5 / 31.5 / 31.5 / 31.5px**, no page overflow
- 1920px: content/header/topbar/footer left = **340 / 340 / 340 / 340px**, no page overflow

## Layout numbers

- Before fixture: header `contact_info` **262.75px**; topbar **254.75px**. (Owner's live measurement was 235px; fixture differences come from deterministic content/font inputs.)
- After: header `contact_info` **64px**; topbar **36.8px**.
- Final matrix: all 31 declared region/type cases rendered; **0 offenders**, no band/widget overflow, no page horizontal scrollbar.

## Suites

- `ark_declared_control_honour` -> 107 passed, 0 failed
- `ark_sidebar_targeting` -> 3 passed, 0 failed
- `footer_bar_contrast` -> 32 passed, 0 failed
- `cms_customizer_widgets_bridge` -> 34 passed, 0 failed
- `cms_per_type_presentation` -> 18 passed, 0 failed
- `theme_studio_preset_capability` -> 7 passed, 0 failed
- `ark_region_persistence_vocabulary` -> 3 passed, 0 failed
- `ark_footer_colour_vocabulary` -> 14 passed, 0 failed
- `entity_presentation_renderer_bridge` -> 6 passed, 0 failed
- `guidance_entity_view_test` -> 44 passed, 0 failed
- `ark-region-widget-context-matrix` -> 1 passed, 0 failed (31 matrix cases + two alignment widths)
- Final matrix run: `error.log` empty; `app.log` contained only two DiSyL compile-info records and no warnings/errors.

## Commits / blocked

- `8e82a01f` — A, compact horizontal region presentation
- `2cbe155f` — B, declared-widget context matrix and testing convention
- `03c0c328` — C, max-width token use and full-width gutters
- Blocked: none. Colors/theme vocabulary divergence was not touched.
