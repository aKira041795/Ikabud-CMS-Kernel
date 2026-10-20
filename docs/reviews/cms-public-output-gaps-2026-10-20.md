# CMS public output gaps — 2026-10-20

## Method

- Read-only Code Reviewer audit of `modules/cms` (public rendering, theme integration, tests).
- Independent probes at HEAD — every finding below was reproduced, not inferred.
- Chair consultation: `.ai/consult/cms-gaps-2026-10-20.md`.
- Claude red-team: `.ai/consult/cms-gaps-redteam-2026-10-20.md`.

No refactor was proposed or performed. Two autonomous lanes (Sol first in the model chain) implemented
the fixes from contracts written by `tools/autonomy-contract.py`.

## Findings

### P0 — Public JSON-LD could be broken out of (stored XSS)

`modules/cms/helpers/20-seo-public.php:326` encoded the JSON-LD payload with
`JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT`, and line 329 wrapped it raw in
`<script type="application/ld+json">`. A content title of `</script><script>alert(1)</script>` closed
the element; the CSP allows inline script, so the payload executed.

Probe at HEAD: `BREAKOUT=YES`, three closing `</script>` tags in the output.

**Fix:** add `JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT` — the same flags the kernel
uses in `kernel/DiSyL/Hydration/IslandManifest.php`. The decoded payload is unchanged.

### P1 — Sitemap advertised 404 URLs for products and custom types

`cmsBuildSitemapUrls()` mapped every non-page row to `/cms/blog/<slug>`. Posts live there, but products
live at `/ecommerce/shop/<slug>` and custom types at `/cms/<type>/<slug>` (`modules/cms/routes.php:114`).
The sitemap therefore disagreed with each row's canonical URL and sent crawlers to 404s.

**Fix:** `cmsSitemapPathForType(type, slug)` used by `cmsBuildSitemapUrls()` only. Canonical and
JSON-LD paths are deliberately untouched (the red-team's main regression concern).

### P2 — Four theme layouts rendered builder content without the CMS stylesheet

`cms-public.css` is the CMS-owned contract for builder node geometry, prose typography, lightbox and UI
primitives. ARK and native-default loaded it; `minimalist`, `entity-commerce-poc`, `entity-native` and
`minimal` did not. Builder pages under those themes lost the structural styles.

**Fix:** load `cms_public_css_url` exactly once inside `<head>`, before the theme stylesheet, in all four
layouts. The link uses the same `|default:'/assets/cms/cms-public.css'` fallback as the working themes.

## Guards

| Test | Assertions | Red at HEAD |
|---|---|---|
| `tests/cms_public_jsonld_escape_test.php` | 7 | 4 failures (breakout, round-trip, extra script, count) |
| `tests/cms_sitemap_type_url_test.php` | 7 | 7 failures (helper missing, old mapping still present) |
| `tests/cms_theme_builder_stylesheet_test.php` | 22 after fix | 4 missing layouts |

The JSON-LD test parses the emitted block and round-trips hostile, special-character and invalid-UTF-8
titles. It does not compare raw source, so the encoding change is allowed while the data contract holds.
The theme guard requires a real `<link rel="stylesheet">` before `</head>`, not a comment, and exactly
once.

## Remaining risks and boundaries

- **Page cache:** anonymous CMS pages are cached. A deploy does not necessarily invalidate cached HTML,
  so the JSON-LD and theme changes need a cache flush (or content/settings touch) before the live
  exposure is gone.
- **Sitemap eligibility:** the fix makes the paths match the router. A product URL still depends on the
  ecommerce module serving it; a custom type still depends on the type having a public view. The fix
  removes the guaranteed `/cms/blog/` 404, not every eligibility case.
- **Theme guard is static:** it proves the source layout loads the stylesheet before `</head>`. A live
  render check (exactly one stylesheet on a builder page, zero on a non-builder page) remains the
  stronger runtime assertion.
- **`entity-native` / `minimal`** do not declare builder mode, but they render `body`/content, so the
  stylesheet is loaded on all public pages under those themes (one small CMS-owned stylesheet request).

## Harness finding: a caller write can fail the lane policy

The first P1/P2 lane (`cms-public-output-gaps`) passed both acceptance tests but exited 97 with
`completion=BLOCKED`: this review document was created in the repository while the lane was running,
so `lane-policy.sh` attributed the unexpected `docs/reviews/...` path to the model. The work was
correct, and the policy was correct too — it refused to certify a path it could not attribute.

A clean rerun (`cms-public-output-gaps-r1`) with the document outside the tree passed on attempt 1:
acceptance 7/7 + 22/22, `completion=PASS`, exit 0.

**Operating rule:** prepare tests, contracts, and documentation before dispatch. Do not create or edit
repository files while a lane is running; the lane policy cannot distinguish a caller's write from the
model's.

## Outcome

- P0: commit `580063e9`; ledger `cms-jsonld-xss` = routine, acceptance PASS, gate PASS, merged, 0
  interventions, 1 attempt.
- P1/P2: commit `408a1a21`; ledger `cms-public-output-gaps` (logical task, 2 attempts) and its clean
  rerun `cms-public-output-gaps-r1` (1 attempt) are both acceptance PASS, gate PASS, merged, 0 owner
  interventions.
- Autonomy ledger: 3 entries — 2 logical tasks plus the clean rerun — all autonomous, rate 100% on the
  targeted CMS gate. The full application release gate was not run.

