# SOL — Customizer widget placement truthfulness + preview/render parity

## Result

Implemented and green. No tenant data was read-modified-written. The browser fixture exists only in the page's Alpine state and is never saved.

- Loaded header widgets now receive `location: 'header'` only when `location` is missing/null, matching `ThemeRegionRenderer::headerWidgetsAt`.
- Every header widget card has a `Placement` select with exactly `topbar` / `header`.
- The preview uses one placement helper for both bands; top-bar widgets are excluded from the header row and header widgets are rendered between brand and nav.
- The `nav_menu` hint now refers to the selected placement.

## Required browser-check sequence

The browser spec was written first at `tests/browser/cms-customizer-header-widget-placement.spec.js`. It authenticates to the customizer, injects one isolated in-browser fixture with effective placement `header`, renders the live preview, and measures both preview bands.

### Unchanged-template baseline — expected FAIL

Command:

```text
npx playwright test tests/browser/cms-customizer-header-widget-placement.spec.js
```

Failure output (verbatim):

```text
Running 1 test using 1 worker

  ✘  1 [chromium] › tests/browser/cms-customizer-header-widget-placement.spec.js:15:1 › header widget preview uses the same placement band as the renderer (20.5s)

  📄 /var/www/html/applicationostest/test_results/browser/runs/20261007140941-1058f922/cms-customizer-header-widget-placement--chromium.json
  📄 test_results/browser/manifest.json
  🧠 Pattern intelligence: /var/www/html/applicationostest/test_results/browser/runs/20261007140941-1058f922/pattern-intelligence.json
  ✅ No issues found
  ⚠ Baselines file no longer fingerprinted: modules/daily-ledger/module.json
  ⚠ Baselines file no longer fingerprinted: modules/daily-ledger/routes.php
  ⚠ Baselines file no longer fingerprinted: modules/daily-ledger/workbench-contract.json
  ⚠ Baselines file no longer fingerprinted: templates/modules/daily-ledger/layouts/app.disyl
  ⚠ Baselines file no longer fingerprinted: templates/modules/daily-ledger/cashier/ledger.disyl

  1) [chromium] › tests/browser/cms-customizer-header-widget-placement.spec.js:15:1 › header widget preview uses the same placement band as the renderer 

    Error: widget="Placement parity fixture"; topbar band must exclude it; header band must include it

    widget="Placement parity fixture"; topbar band must exclude it; header band must include it

    expect(received).toEqual(expected) // deep equality

    - Expected  - 2
    + Received  + 2

      Object {
    -   "header": true,
    -   "topbar": false,
    +   "header": false,
    +   "topbar": true,
        "widget": "Placement parity fixture",
      }

    Call Log:
    - Timeout 5000ms exceeded while waiting on the predicate

      42 |         topbar: (await topbarBand.innerText()).includes(WIDGET_TEXT),
      43 |         header: (await headerBand.innerText()).includes(WIDGET_TEXT),
    > 44 |     }), `widget="${WIDGET_TEXT}"; topbar band must exclude it; header band must include it`).toEqual({
         |                                                                                              ^
      45 |         widget: WIDGET_TEXT,
      46 |         topbar: false,
      47 |         header: true,
        at /var/www/html/applicationostest/tests/browser/cms-customizer-header-widget-placement.spec.js:44:94

    attachment #1: screenshot (image/png) ──────────────────────────────────────────────────────────
    test-results/cms-customizer-header-widg-b3b1a-cement-band-as-the-renderer-chromium/test-failed-1.png
    ────────────────────────────────────────────────────────────────────────────────────────────────

    Error Context: test-results/cms-customizer-header-widg-b3b1a-cement-band-as-the-renderer-chromium/error-context.md

    attachment #3: trace (application/zip) ─────────────────────────────────────────────────────────
    test-results/cms-customizer-header-widg-b3b1a-cement-band-as-the-renderer-chromium/trace.zip
    Usage:

        npx playwright show-trace test-results/cms-customizer-header-widg-b3b1a-cement-band-as-the-renderer-chromium/trace.zip

    ────────────────────────────────────────────────────────────────────────────────────────────────

  1 failed
    [chromium] › tests/browser/cms-customizer-header-widget-placement.spec.js:15:1 › header widget preview uses the same placement band as the renderer 
```

This is the intended mismatch: the `header` fixture appeared in `topbar=true`, `header=false`.

### Implemented-template result — PASS

```text
✓ 1 [chromium] › tests/browser/cms-customizer-header-widget-placement.spec.js:15:1 › header widget preview uses the same placement band as the renderer
1 passed (19.6s)
```

## Verification

| Check | Result |
|---|---|
| New browser parity check | PASS, 1 test |
| `php tests/ark_declared_control_honour_test.php` | PASS, 107/0 |
| `php tests/ark_sidebar_targeting_test.php` | PASS, 3/0 |
| `php tests/cms_customizer_widgets_bridge_test.php` | PASS, 34/0 |
| `php tests/ark_theme_test.php` | PASS, 113/0 |
| `npx playwright test tests/browser/ark-region-widget-context-matrix.spec.js` | PASS; `OFFENDERS (0)` |
| `npx playwright test tests/browser/ark-real-page-responsive.spec.js` | PASS; `RESPONSIVE OFFENDERS (0)` |
| `php ikabud disyl:lint templates/modules/cms/admin/theme-customizer.disyl` | PASS; 1 file, no issues |
| `php -l` | N/A; no PHP files touched |
| `git diff --check` | PASS |

Cache handling was performed before browser regression checks. `php ikabud cache:clear` cleared the DiSyL/file/APCu caches but exited 1 because of its reported warnings; the required full web flush then returned HTTP 200 with:

```text
cmsCacheFlushAll=ok
pageCacheFlushAll=ok
apcu_clear_cache=ok
opcache_reset=ok
```

## Commits

- `c1889421` — `test(cms): expose header widget preview placement mismatch`
- `75dd17d0` — `fix(cms): align widget preview placement with renderer`
