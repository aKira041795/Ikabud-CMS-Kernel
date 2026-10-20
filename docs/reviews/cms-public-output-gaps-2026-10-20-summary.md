# CMS public output gaps — completed summary

Date: 2026-10-20

This is the readable end-to-end summary. The detailed review is
[`cms-public-output-gaps-2026-10-20.md`](cms-public-output-gaps-2026-10-20.md). Gate logs and Sol lane reports were
originally in `/tmp`, which is not visible in the workspace; they are now copied under
[`evidence/`](evidence/).

## Result

Three verified gaps were found, fixed, and guarded:

1. **P0 — public JSON-LD script breakout (stored XSS).**
   `cmsStructuredDataJsonLd()` used `JSON_UNESCAPED_SLASHES`, so a content title containing
   `</script><script>alert(1)</script>` closed the JSON-LD block. Fixed with
   `JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT`; the decoded payload is unchanged.
2. **P1 — sitemap advertised 404 URLs for products and custom types.**
   Every non-page row was sent to `/cms/blog/<slug>`. Added `cmsSitemapPathForType()` and used it only
   in `cmsBuildSitemapUrls()`; canonical and JSON-LD paths are untouched.
3. **P2 — four theme layouts rendered builder content without `cms-public.css`.**
   Added the real stylesheet link once inside `<head>`, before the theme stylesheet, in `minimalist`,
   `entity-commerce-poc`, `entity-native`, and `minimal`.

## Commits

| Commit | What |
|---|---|
| `580063e9` | `cms: escape public JSON-LD against script breakout` |
| `408a1a21` | `cms: fix sitemap type URLs and builder stylesheet delivery` |
| `3116f885` | `harness: record the caller-write policy hazard` |
| `bb6cb8fd` | `docs: cite the CMS public-output gap commits` |
| this commit | `docs: add the readable completion summary and gate evidence` |

## Verification

Exact commands and results from the clean run:

```text
php tests/cms_public_jsonld_escape_test.php        -> OK: 7 passed, 0 failed
php tests/cms_sitemap_type_url_test.php            -> OK: 7 passed, 0 failed
php tests/cms_theme_builder_stylesheet_test.php    -> OK: 22 passed, 0 failed
php tests/builder_render_parity_test.php           -> Builder render parity: 22 passed, 0 failed
php tools/disyl-conformance-check.php              -> poc5: lane_green=YES
                                                      runtime_proof_interpreted=PASS
                                                      runtime_proof_compiled=PASS
                                                      disagreements: none
bash tools/lane-harness-bench.sh                   -> 7 passed, 0 failed
```

Full gate output: [`evidence/cms-public-gaps-gate.log`](evidence/cms-public-gaps-gate.log) and
[`evidence/cms-jsonld-gate.log`](evidence/cms-jsonld-gate.log).

## Autonomy harness test

Two tasks were dispatched through `tools/autonomy-contract.py` and `tools/lane-autonomy.py`, with Sol
first in `tools/model-chain.txt`.

| Ledger task | Class | Acceptance | Gate | Merged | Interventions | Attempts |
|---|---|---|---|---|---|---|
| `cms-jsonld-xss` | routine | PASS | PASS | true | 0 | 1 |
| `cms-public-output-gaps` | routine | PASS | PASS | true | 0 | 2 |
| `cms-public-output-gaps-r1` | routine | PASS | PASS | true | 0 | 1 |

`python3 tools/autonomy-ledger.py report`:

```text
routine: 3 | autonomous: 3 | rate: 100.0%
high-risk: 0 | owner interventions: 0
attempts avg: 1.33 | merged: 3 | rollbacks: 0 | cost: $0.0000
```

The three entries are **two logical tasks plus one clean rerun**. The first sitemap/theme lane
(`cms-public-output-gaps-a1`) passed acceptance 7/7 + 22/22 but exited 97 with
`completion=BLOCKED`: the review document was created in the repository while the lane was running,
so `lane-policy.sh` attributed that unexpected path to the model. The policy was correct; the caller
caused it. A clean rerun (`cms-public-output-gaps-r1`) with the document outside the tree passed on
attempt 1. The operating rule is documented in `docs/engineering/autonomy-loop.md`.

The 100% is on the **targeted CMS gate above**, not the full application release gate.

## Evidence

- Detailed review: [`cms-public-output-gaps-2026-10-20.md`](cms-public-output-gaps-2026-10-20.md)
- Chair consultation: `.ai/consult/cms-gaps-2026-10-20.md`
- Claude red-team: `.ai/consult/cms-gaps-redteam-2026-10-20.md`
- Autonomy ledger: `.ai/autonomy/ledger.jsonl`
- Lane markers: `.ai/runs/cms-jsonld-xss-a1.landed.json`,
  `.ai/runs/cms-public-output-gaps-a1.landed.json`,
  `.ai/runs/cms-public-output-gaps-r1-a1.landed.json`
- Sol reports: [`evidence/cms-jsonld-sol-report.log`](evidence/cms-jsonld-sol-report.log),
  [`evidence/cms-sitemap-themes-sol-report.log`](evidence/cms-sitemap-themes-sol-report.log)

## Remaining risks

- **Page cache:** anonymous CMS pages are cached; flush the cache (or touch content/settings) after
  deploy or the old JSON-LD/theme HTML can stay live.
- **Sitemap eligibility:** the paths now match the router, but product URLs still depend on the
  ecommerce module serving them, and custom types still depend on a public view existing.
- **Theme guard is static:** it proves the source layout has the real link before `</head>`. A live
  render assertion (one stylesheet on a builder page, zero on a non-builder page) is still stronger.
- The unrelated `tests/browser/daily-ledger-production-journey.spec.js` formatting change remains
  uncommitted.
