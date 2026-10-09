# Tailwind application build migration — 2026-10-09

## Result

The 52 DiSyL templates that loaded Tailwind's CDN runtime now load the committed `/assets/tailwind/app.css` bundle. All 11 runtime `tailwind.config` blocks were removed, and their real palettes were moved to `tailwind.app.config.js`. The ARK Workbench configuration and build script were not changed.

Build command:

```sh
npm run build:tailwind:app
```

The generated bundle is 139,622 bytes raw and 20,860 bytes through `gzip -c`. The difference from the brief's 142,305-byte measured target reflects the current content scan; no utility was added merely to match a byte target.

## Coverage

Coverage was checked against freshly rendered HTML from the three required URLs, not against unrendered template tokens. The checker first generated a Tailwind recognition probe from those rendered documents, escaped selector punctuation (`:`, `/`, `.`, `[`, and `]`), and then looked for each recognized selector in `app.css`.

The checker was validated before its missing result was trusted: it found the known-present `flex` utility in both the probe and the compiled bundle.

Exact unique-class coverage:

| State | Rendered Tailwind utilities | Missing from compiled bundle |
|---|---:|---:|
| Before (`app.css` absent) | 40 | 40 |
| After | 40 | 0 |

A complementary scan of every literal class attribute in `templates/`, `modules/`, and `storage/` found 6,444 unique tokens. Of those, 1,761 map to selectors emitted by Tailwind, and all 1,761 are present in `app.css` (1,761 missing before the asset existed; 0 missing after). The remainder includes application-specific classes and DiSyL expressions, so it was not incorrectly treated as a Tailwind-missing list. The 15 recognizable but unsupported colour intentions are reported separately below.

Rendered-document detail:

| URL | HTTP | Unique class tokens | Recognized Tailwind utilities | Missing after |
|---|---:|---:|---:|---:|
| `http://baronledger.test/login` | 200 | 46 | 40 | 0 |
| `http://cmsnew.test/?disyl_nocache=1` | 200 | 77 | 0 | 0 |
| `http://applicationos.test/login` | 200 | 8 | 0 | 0 |

The first two rendered documents contain the compiled stylesheet link and contain no CDN reference. The kernel login is the expected unchanged non-Tailwind control. The stylesheet itself returned HTTP 200 from `http://baronledger.test/assets/tailwind/app.css`.

## Intentionally unsatisfied classes

The following 15 unique class names are not defined by the compiled bundle. They were not assigned invented values because they do not work under the existing CDN setup either:

- `bg-wms-50`
- `bg-wms-100`
- `bg-wms-600`
- `bg-wms-700`
- `border-wms-200`
- `border-wms-300`
- `border-wms-500`
- `border-wms-600`
- `ring-wms-400`
- `ring-wms-500`
- `text-wms-500`
- `text-wms-600`
- `text-wms-700`
- `text-wms-800`
- `bg-white/92`

No `wms` palette or matching CSS selectors exist anywhere in the repository, so Tailwind's CDN runtime had no source from which to generate the 14 `wms` utilities. `bg-white/92` occurs 83 times, but `92` is not a configured opacity step and no matching selector exists today. These are pre-existing styling gaps, not migration regressions.

`text-brand-400` (one occurrence) and `bg-brand-950` (three occurrences) are satisfied using the supplied union of the two existing runtime `brand` definitions: `#60a5fa` and `#172554`, respectively. No values were invented.

## Palette findings

The migrated palettes are `brand`, `cms`, and `ec`, plus the Attendance & Wage `fontFamily.sans` setting. The `kernel` and `pal` palettes were omitted only after repository-wide utility-class searches returned zero uses for each.

The brief's requested `form` and `headers` palettes do not exist. In the named login and registration templates, `form` is Alpine component state and `headers` is the Fetch API request option; neither is nested under Tailwind `theme.extend.colors`. The only runtime colour palette in both files is `cms`, whose values were migrated exactly.

## Verification

- Both acceptance commands initially exited 1 on the unchanged tree and now pass.
- `php _lint_disyl.php`: **700/700 valid**.
- Required pages: HTTP 200.
- Required rendered CDN-reference checks: zero references.
- `php -l scripts/build-tailwind-app.php`: valid.
- `php -l create-bluehost-upgrade-package.php`: valid.
- `git diff --check`: clean.
- Rebuilding with `npm run build:tailwind:app` succeeds.

The deploy-kit README generator now says to run the application Tailwind build and commit its output before packaging.

## Rollback

Preferred rollback: revert this migration as one change. Per template, the inline comment identifies the one-line asset swap: replace the compiled stylesheet link with the previous Tailwind runtime script. For the 11 templates that formerly had runtime configuration, an exact visual rollback also requires restoring that template's removed inline configuration block; restoring only the CDN line cannot recreate custom colours.

## Contradictions and differences from the brief

1. The named `form` and `headers` “palettes” are JavaScript object keys unrelated to Tailwind colours; there are no such palettes to extract.
2. The brief lists only five groups of `wms` uses. The current tree also uses `bg-wms-100`, `bg-wms-700`, four `border-wms-*` classes, two `ring-wms-*` classes, and `text-wms-800`; all are reported above and remain intentionally undefined.
3. The hard rollback claim is incomplete for the 11 configured templates: a one-line CDN restoration does not restore the inline palettes that the contract also requires removing. Reverting the migration restores them exactly.
4. The current bundle is 2,683 bytes smaller raw than the measured target in the brief.
