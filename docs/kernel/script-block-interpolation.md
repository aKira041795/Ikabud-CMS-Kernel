# Script Block Interpolation — Security & Semantics

> **Applies to:** `TemplateEngine::compileScriptBody()` in `kernel/DiSyL/TemplateEngine.php`
> **Updated:** June 26, 2026

## Current Behavior

`<script>` and `<style>` blocks in DiSyL templates undergo expression evaluation.
Variables referenced via `{var}` or `${var}` syntax are resolved and substituted.

This was re-enabled in commit `2307aae` after a period where script bodies were
raw passthrough (to fix a JavaScript strict-mode regression).

## Security Considerations

### DiSyL expressions inside JavaScript strings

```javascript
var name = '{user.name}';
```

If `user.name` contains a single quote, backslash, or `</script>`, it can break
out of the JavaScript string context. **This is a potential XSS vector.**

### Braces in ordinary JavaScript

```javascript
if (x > 0 && y < 10) { doSomething(); }
```

The `{` and `}` in JavaScript are also DiSyL expression delimiters. The DiSyL
parser attempts to distinguish control flow from variable references, but edge
cases exist — particularly around destructuring, object literals, and arrow
functions with block bodies.

### Object literals with a colon — fixed at the engine (2026-10-10)

`compileScriptBody()` and `compileStyleBody()` match a variable tag with
`[a-zA-Z_][\w.]*(?!\s*:)`. The trailing guard is load-bearing: without it a
JavaScript object literal's first key was matched as a DiSyL variable and the
rest of the literal was evaluated as a DiSyL expression, collapsing to its false
branch.

```javascript
body: JSON.stringify({a: X ? 1 : 0})   // was served as: stringify(0)
```

That silently corrupted the request payload — the admin consignee save returned a
kernel 500 with **empty** logs, and the dc-cafe settings payload was mangled (a
previous session worked around the latter with an inline comment).

The guard is deliberately a **negative lookahead on the colon**, not an allow-list
of terminators. A real tag may be followed by anything (`"{title}"`, `{count};`,
`{n})`, `{user.name}`), so an allow-list rejects legitimate interpolation while
still passing the object-literal cases.

**Verification used for this change** (the pattern touches every script and style
body in the repository): `.ai/script-body-corpus.php` renders every `<script>` and
`<style>` body and writes a `sha1` manifest, so the before/after manifests can be
diffed. Measured over 556 files / 439 bodies: **0 bodies changed** — the fix
removes the false match without altering any current template's output. The
regression assertions live in `tests/disyl_engine_test.php` and are falsifiable —
removing the guard turns them red.

**Residual:** a colon preceded by whitespace is also rejected (`(?!\s*:)`), but a
key written as `{a : 1}` with the space *before* the colon is still matched, as it
was before this change. Fixing that needs a real JS-aware scanner rather than a
regex, and is out of scope here.

### Opt-in Model Recommended

For new templates, prefer explicit JSON serialization over implicit interpolation:

```disyl
<script type="application/json" id="chart-data">
    {{ chartData | json }}
</script>
```

Then read from the DOM in client code:

```javascript
const data = JSON.parse(document.getElementById('chart-data').textContent);
```

### Future Direction

A `disyl:compile` attribute is planned for opt-in evaluation:

```disyl
<script disyl:compile>
    // Expressions evaluated
    var config = { baseUrl: '{app.url}' };
</script>

<script>
    // Raw passthrough — no interpolation
    var x = {foo: 1, bar: 2};  // object literal, not DiSyL
</script>
```

Until this is implemented, exercise caution with user-controlled values in
`<script>` blocks. Escape or JSON-encode any value that originates from user
input or external data sources.

## Summary

| Concern | Status |
|---|---|
| Breaks JavaScript context from user data | 🔴 Mitigated by JSON approach |
| Braces confused with DiSyL expressions | � Object-literal colon fixed 2026-10-10 (`(?!\s*:)`); `{a : 1}` with a space before the colon remains |
| Opt-in `disyl:compile` attribute | 🔴 Not yet implemented |
| Official recommendation | Use `| json` filter and `<script type="application/json">` |
