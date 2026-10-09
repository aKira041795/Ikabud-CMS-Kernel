# DiSyL — Compiled `{extends}` Rendering Enabled

> **Released:** 2026-10-09
> **Theme:** Every template that uses `{extends}` — 366 of 743 in this repository — was rendering on the legacy interpreted pipeline, and saying so in the production log without anyone being told what the gate was. One environment flag moves them onto the compiled path: **cashier ledger 1436 ms → 464 ms**.
> **Scope:** `kernel/DiSyL` rendering configuration (no engine code change), measured on `modules/daily-ledger`
> **Previous:** [Daily Ledger — Governed Day Lifecycle, Verified Settlement & Provisional Sales](release-notes-2026-10-04-daily-ledger-verified-settlement.md)

---

## Executive Summary

DiSyL has two render paths. Compiled mode turns a template into a PHP class and reuses it; the interpreted
pipeline re-reads and re-evaluates the source on every request and remains the fallback for constructs the
compiler does not support.

Since the compiled path landed, **every template containing `{extends}` has been ineligible for it** unless
the env flag `DISYL_EXTENDS_COMPILED` was set — and nothing in this repository set it: not `.env`, not
`.env.example`, not the docs. Only the engine read it. So 366 of 743 templates, including nearly every page
of every module, rendered interpreted in production, and the engine logged this once per template:

```
[warning] disyl.interpreted.deprecated {"template":"modules/daily-ledger/admin/dashboard.disyl",
  "reason":"template rendered via the legacy interpreted pipeline; migrate to compiled-eligible syntax"}
```

The reason string points at the template. The template was never the problem.

**Delivered release facts:**
- `DISYL_EXTENDS_COMPILED=1` is enabled on the live site; extending templates now take the compiled path
- the cashier ledger improved **3.1×** (1436 ms → 464 ms median, local A/B/A); dashboard ~10%; the
  data-heavy commissary sheet is unchanged, because its cost is building rows, not parsing templates
- live login TTFB tightened from **median 1.017 s / max 1.501 s** to **median 0.969 s / max 1.119 s**
- `disyl.compile.fallback` remains **0** — no template threw in the compiled path and fell back
- parity **178/178 with the flag off and on**; conformance green both ways; the whole `tests/disyl_*.php`
  family produces identical results in both modes
- the flag is now documented in `.env.example`, in
  [`docs/kernel/disyl-development-workflow.md`](../kernel/disyl-development-workflow.md), and in the
  upgrade kit's README

---

## Why extending templates were ineligible

`TemplateEngine::render()` takes the compiled path only when all three hold: compiled mode is on, the
compiled cache booted, and **`isCompiledEligibleTemplate()`** passes. That check walks the template graph
(`kernel/DiSyL/TemplateEngine.php:4097`) and rejects a source that contains:

| Construct | Status |
|---|---|
| `{extends }` | gated by `DISYL_EXTENDS_COMPILED`, default **off** (`:4125` → `compiledExtendsEnabled()` `:4156`) |
| `{ikb_*}` / `{island` | always interpreted — component tags require the interpreted pipeline |
| `{macro }` / `{call }` | always interpreted — user macros are not implemented in the compiler |

`{cache `, `{parallel` and `{ai_*` also stay interpreted.

Two details made this hard to see:

- **The notice is production-only.** It is wrapped in an `APP_ENV`/`IKABUD_ENV` = `production`/`prod` check
  (`:505`), so the behaviour is identical locally and only becomes visible on the live site.
- **The reason string describes the symptom.** "migrate to compiled-eligible syntax" reads as a template
  defect; nothing in the template needed changing.

Verified directly: with the flag unset, `dashboard.disyl` and `settings.disyl` report `eligible=false`; with
`DISYL_EXTENDS_COMPILED=1`, both report `eligible=true`.

## Why the flag was off

The compiled `{extends}` path had eight genuine divergences when it was built — multi-level nearest-ancestor
blocks, includes of extends-files, chain depth, and missing inheritance diagnostics. They were fixed in
`814c88d6`, `8fb6e358` and `bead7377`, after which parity reached 178/178 in both modes.

It was kept opt-in rather than defaulted on because the flip is global — every module's extending templates —
while browser coverage was concentrated on daily-ledger. That reasoning is recorded here rather than
discarded: the flag remains off by default, and enabling it is a deliberate operational step.

## Measurement

Local, tenant 207, `DISYL_SHARED_OUTPUT_TTL=0` (so every request really renders), medians of 8 warmed
requests, A/B/A — measured with the flag off, then on, then off again to rule out drift:

| Page | Flag off | Flag on |
|---|---|---|
| cashier ledger | 1436 / 1532 ms | **511 / 508 ms** |
| dashboard | 418 / 434 ms | 406 / 377 ms |
| commissary sheet | 608 / 603 ms | 802 / 603 ms (the 802 was noise) |

Steady state with the compiled cache warm and the flag active: **cashier ledger 464 ms**, dashboard 419 ms,
commissary sheet 601 ms.

The gain tracks the template-graph work that disappears, not page size. The ledger has the deepest include
graph, so the interpreted engine re-parsed all of it on every request; the commissary sheet is dominated by
building and rendering its data, which both engines do similarly.

Live, after enabling (public pages only, 10 samples): login TTFB **min 0.879 s / median 0.969 s / max
1.119 s**, against **min 0.958 s / median 1.017 s / max 1.501 s** before. The median gain on that page is
modest because boot and database work dominate it; the tail — the worst case someone actually waits
through — improved by ~25%.

## What was verified before the flip

- **Parity:** `tests/disyl_parity_test.php` — 178/178 with the flag off **and** on
- **Conformance:** `tools/disyl-conformance-check.php` — `disagreements: none`, `promoted=41 partial=0`,
  `runtime_proof_interpreted=PASS` and `runtime_proof_compiled=PASS`, both modes
- **Test family:** every `tests/disyl_*.php` suite produces identical results in both modes
- **Output equivalence on real pages:** rendered HTML compared across modes; the differences are per-request
  `window.DL_CSRF` / `window.DL_TOKEN` values and new activity-feed rows. A same-mode comparison produced
  *more* differences (46 lines vs 18), which is what identified them as per-request rather than mode-related
- **Browser:** 10/10 daily-ledger browser specs pass with compiled rendering active (sheet sticky header,
  drag-to-pan, and the destination picker)
- **Leak check:** a 9-route smoke test across modules returned no page containing raw DiSyL markers
  (`{if `, `{foreach `, `{for `) and no console errors — this is the failure mode the eligibility gate exists
  to prevent, so it was checked directly rather than assumed
- **Fallbacks:** `disyl.compile.fallback` = 0 throughout

**Known, unrelated, disclosed:** `tests/disyl_v4_test.php` is 35 passed / 1 failed
(`|json in <script> outputs raw JSON (no &quot;)`). Identical in both modes and present with a clean tree, so
it predates this change and is not caused by it.

## Operating it

Enable by appending to `.env`. It must be `.env`: `bootstrap.php` loads that file per request via `putenv()`,
so a shell variable does not reach php-fpm. No restart is required.

```
DISYL_EXTENDS_COMPILED=1
```

**Verify after enabling:**
- `grep -c disyl.compile.fallback storage/logs/app.log` must stay **0**. A line there means a template threw
  in the compiled path and fell back — that is the revert signal.
- `grep -c disyl.interpreted.deprecated storage/logs/app.log` should fall, but **will not reach zero**: the
  ~108 templates using component tags (plus any `{macro}`/`{call}` users) stay interpreted by design and keep
  logging it.

**Rollback:** remove the line. Nothing needs cleaning up — compiled artifacts simply stop being used.

**First-request cost:** each template compiles once and is cached under `storage/cache/compiled`; after that
the fast path applies.

## Related changes in the same window

Not part of this change, listed so the day's history is not silently incomplete:
`89d0e23d` backups stop dumping GENERATED columns, `cfabeed6` backup preflight, `6d5e30e7` backups carry
module settings, `2bf34370` settings panel states what a backup carries, `9361e7f2` sidebar state, `df6cdc5d`
Cash & Paper Check setting, `f6d54f47` / `790aac50` / `843970f3` branch product visibility, `c1406733` /
`4a92cdc2` / `e5780d66` the branch products picker (including a real destination-switch bug),
`162ddde5` / `68c2baa1` / `0585e44e` Daily Sheet readability.

## Files

- `.env.example` — the flag and its default, next to `DISYL_COMPILED_MODE`
- `docs/kernel/disyl-development-workflow.md` — "Compiled vs interpreted rendering", plus troubleshooting rows
  keyed to both log lines
- `create-bluehost-upgrade-package.php` — an optional enable step in the upgrade kit's README
