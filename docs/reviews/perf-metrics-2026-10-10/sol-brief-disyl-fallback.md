# TASK: fix the DiSyL compiled-path fallback at the engine level

You are working in `/var/www/html/applicationostest` (PHP 8.5, DiSyL 4.8, Kernel OS 6.2).
This is a real, measured defect with a known blast radius. Fix the ENGINE. Do not patch templates.

## The defect

The DiSyL compiled render path runs `<style>` bodies through its **expression compiler**. A hyphen in a
CSS property name inside a `{...}` CSS rule is then parsed as DiSyL **subtraction** between two string
literals, PHP throws `Unsupported operand types: string - string`, the compiled render fails, and the
engine **silently falls back** to the interpreted pipeline — which is roughly **10x slower**.

Ground truth: the generated artifact for `templates/modules/harpp/runners.disyl` (1119 B) contains

```php
$output .= '<style>.runner-card';
$output .= (string)(('display:flex;flex' - 'direction:column;gap:.5rem'));
$output .= '.runner-status';
$output .= (string)((((('display:inline' - 'block;border') - 'radius:2px;padding:.2rem .6rem;font') - 'size:.75rem;font') - 'weight:600;background:var(--surface-raised)'));
$output .= '.runner-cap';
$output .= (string)(((('display:inline' - 'block;background:var(--surface-raised);color:var(--text);border') - 'radius:2px;padding:.15rem .5rem;font') - 'size:.75rem'));
$output .= '.runner-meta';
$output .= (string)((('display:flex;flex' - 'wrap:wrap;gap:.5rem;color:var(--muted);font') - 'size:.85rem'));
$output .= '.empty-state';
$output .= (string)(('color:var(--muted);text' - 'align:center;padding:2rem 1rem'));
```

Note what did NOT get evaluated: `$output .= '{'; $output .= 'color:var(--ok)}';`. So a rule *without* a
hyphen is left alone, and the hyphen is what makes the parser commit to treating the region as an
expression. The fix must make `<style>`/`<script>` bodies never be expression-compiled at all, while
**preserving the documented DiSyL script/style interpolation feature** (see
`docs/kernel/script-block-interpolation.md` and `processScriptVariables()` — interpolating `{known}` in
`<script>` IS supported and must keep working).

## Blast radius — measured, not estimated

`php docs/reviews/perf-metrics-2026-10-10/probe-fallback-sweep.php` renders all 555 `.disyl` templates
under `templates/` through the compiled path and classifies each from its own log line. Current result:

```
compiled 472 | fallback 7 | interpreted 72 | no timing line 0 | THREW 4
```

The seven fallbacks:

| template | reason |
|---|---|
| `modules/cms/admin/theme-customizer.disyl` | `string + null` (JavaScript concatenation in an attribute) |
| `modules/harpp/deploy.disyl` | `string - string` |
| `modules/harpp/messenger.disyl` | `string - null` |
| `modules/harpp/runners.disyl` | `string - string` |
| `modules/harpp/settings.disyl` | `string - string` |
| `modules/harpp/status.disyl` | `null - string` |
| `modules/harpp/workspaces.disyl` | `null - string` |

Two operator classes: CSS hyphens (`-`) and JavaScript concatenation (`+`). Both are "an operator
character in a non-DiSyL context being evaluated as DiSyL". **Treat the general class, not just `-`.**

## Acceptance criteria — all must hold

1. **`probe-fallback-sweep.php` reports `fallback 0`** (was 7), with `compiled` rising accordingly and
   `THREW` not increasing. This is the primary acceptance test. Paste the full tally in your report.
2. **A minimal reproduction exists as a committed test** that FAILS on the current engine and PASSES
   after your fix. Run it against the unfixed tree FIRST and record that it fails — a test that passes
   before the change proves nothing.
3. **The 4 affected HARPP templates render on the compiled path**, verified via the sweep, not by
   reading code.
4. **`modules/cms/admin/theme-customizer.disyl` also stops falling back.** Its cause is JS in an
   attribute (`theme-customizer.disyl` lines 1605/1607/1612/2024), so if that needs a second, separate
   change, do it — but say so clearly rather than claiming one fix covered both.
5. **Do not regress the fallback's honesty.** The fallback is currently logged as
   `disyl.compile.fallback` (warning). Keep that. Consider whether a compiled failure on a template
   that previously compiled should be louder, but do not remove the signal.

## IMPORTANT — reproduction is not trivial, read this before you start

**Simple isolated cases do NOT reproduce.** These were all tried and all pass:

- `<style>.x{flex-direction:column}</style>` — passes through untouched
- `@media(min-width:700px){.x{flex-direction:column}}` (nested braces) — fine
- `{extends "_layout.disyl"}{block head}<style>.x{flex-direction:column}</style>{/block}` — fine
- `<style>.x{a-b}</style>` — this one *is* evaluated and yields `0` (null minus null), which is the
  bug visible without a throw

So the trigger needs more context than any of those. **Start from the real template**: render
`modules/harpp/runners.disyl` (1119 B, small) and bisect its content until it fails minimally. The
existing harness `docs/reviews/perf-metrics-2026-10-10/probe-attr-bisect.php` shows a working
write-temp-template-delete-afterwards pattern you can copy. Read the generated artifact in
`/tmp/ikabud-fallback-sweep/compiled/` to see what the compiler produced.

Establish the real trigger before changing the compiler. A fix aimed at a plausible mechanism rather
than the measured one is how a wrong fix ships.

## Constraints

- **Engine-level fix only.** No edits to any `.disyl` template. If DiSyL does not support a construct,
  fix DiSyL — this is a standing repo rule.
- The compiler affects all 555 templates, so regressions matter more than the repro.
- Do NOT git commit and do NOT push. Leave the change in the working tree and report it.
- Keep changes minimal and in the existing style of the file you touch.

## Verification you must run and report

- `php docs/reviews/perf-metrics-2026-10-10/probe-fallback-sweep.php` (full tally)
- `php tools/disyl-conformance-check.php` (must stay green)
- `php .github/../_lint_disyl.php` if present, or `php -l` on every file you touched
- The DiSyL suites: `for t in tests/disyl_*_test.php tests/*disyl*_test.php; do php "$t"; done`
  **Four suites already fail before your change** — `disyl_assoc_test`, `disyl_engine_test`,
  `disyl_v4_compiler_test`, `disyl_v4_test` (exit 1), and `phase0_disyl_script_expression_leak_test`
  is `6 passed, 1 failed`. Confirm they fail identically after your change; do not try to fix them and
  do not be alarmed by them.
- Check BOTH `storage/logs/app.log` and `storage/logs/error.log` after your runs.
- Re-measure `modules/cms/admin/theme-customizer.disyl` with
  `php docs/reviews/perf-metrics-2026-10-10/probe-template-render-cost.php` and report its pipeline and
  timing before/after (it was `compiled->FAILED->interp`, 825-988 ms cold).

## Report format

```
STATUS: PASS | PARTIAL | BLOCKED
ROOT CAUSE: <the measured mechanism, with the evidence that established it>
TRIGGER: <the minimal reproduction you found>
FILES CHANGED: <paths>
FIX: <what you changed and why, in 3-6 lines>
SWEEP: <full tally before -> after>
TESTS: <each command and its result>
PRE-EXISTING COMPARISON: <the 5 known failures, unchanged?>
RISKS: <what could this change break, and how you checked>
UNRESOLVED: <anything you could not establish>
```

If you cannot establish the trigger, say so and report BLOCKED with what you ruled out. A precise
BLOCKED is worth more than a plausible fix — several wrong conclusions in this repo's history came from
trusting a mechanism that felt right instead of one that was measured.
