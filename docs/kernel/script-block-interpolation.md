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
| Braces confused with DiSyL expressions | 🟡 Edge cases |
| Opt-in `disyl:compile` attribute | 🔴 Not yet implemented |
| Official recommendation | Use `| json` filter and `<script type="application/json">` |
