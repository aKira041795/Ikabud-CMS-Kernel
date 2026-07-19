# Runtime Debug Workflow

Systematic approach for diagnosing runtime bugs (form submissions, API failures, data-not-saved issues, JS errors) in the Ikabud application.

## Trigger phrases

"not saved", "doesn't work", "nothing happens", "no response", "fails silently", "check why", "debug", "investigate", "fix this bug", "data not persisting"

## When to use this skill

Any time a user reports a runtime behavior issue — form submission failure, API returning unexpected results, data not persisting, button doing nothing, page showing wrong data. This skill applies BEFORE reading source code or making changes.

## Protocol (follow in order — do not skip steps)

### Step 1: Reproduce and observe

Ask the user:
- What exact URL are you on?
- What exact steps did you take?
- What did you see? (screenshot if possible)
- What did you expect to see?
- Is this a regression or has it never worked?

### Step 2: Clear logs and capture

```bash
# Clear both logs
echo "" > storage/logs/app.log
echo "" > storage/logs/error.log
```

Have the user reproduce the issue, then:

```bash
# Check what was logged
tail -50 storage/logs/app.log
tail -50 storage/logs/error.log
```

### Step 3: Narrow the failure point

Check each layer from front to back:

| Layer | Check | Tool |
|---|---|---|
| JS error | Console errors, `typeof functionName` | Browser DevTools Console |
| Network request | Did the POST fire? Status? Response body? | DevTools Network tab |
| PHP handler | Did `write_log()` output appear? | `storage/logs/app.log` |
| POST data | What keys/values reached the handler? | Add temp `write_log('handler received', 'info', ['post' => array_keys($_POST)])` |
| DB query | Did the SQL execute? Row count? | Add temp logging around `$stmt->execute()` |

### Step 4: Direct PHP test (isolate backend from frontend)

Before modifying production code, prove the backend works:

```bash
php -r '
require_once "bootstrap.php";
// Set up minimal context
app()->tenant()->setTenantId(TENANT_ID);
$db = palDb();  // or relevant module DB
// Call the suspect function directly with known-good data
$result = someService->update($id, ["title" => "TEST"]);
echo $result ? "WORKS" : "FAILS";
'
```

- If direct PHP test PASSES → problem is in HTTP/JS layer (frontend, fetch, FormData, routing)
- If direct PHP test FAILS → problem is in PHP logic (fix the backend code)

### Step 5: Fix at the right layer

- Frontend issue → check browser Console, Network tab, CSP headers, script load order
- HTTP/routing issue → check route patterns, URL construction, `.htaccess` rewrites
- PHP logic issue → fix the backend function, add validation, fix SQL

### Step 6: Verify the fix

- Have user reproduce with fix in place
- Check logs for new errors
- Run relevant tests: `php tests/something_test.php`

## Frontend debugging checklist

When the symptom is "no toast," "page reloads," or "nothing happens on submit":

1. Open DevTools Console — any red errors?
2. Type `typeof ajaxSubmit` in Console — does it return `"function"`?
3. Network tab — filter to XHR/Fetch — does a POST request fire on submit?
4. If POST fires — what is the response status and body?
5. If POST does NOT fire — the `onsubmit` handler or event listener is failing
6. Check `<script src="/assets/workbench/workbench-core.js">` loaded (200, not 404)
7. Check CSP headers: `script-src` must include `'unsafe-inline'` for inline handlers

## Common failure patterns

| Symptom | Likely cause | Check |
|---|---|---|
| No toast, page reloads, data not saved | `ajaxSubmit` not called, form submits normally | Console: `typeof ajaxSubmit` |
| No toast, no reload, no network request | JS error prevents submit handler | Console errors |
| 419 response | CSRF token expired or page cached | Check `_token` in form vs session |
| 400 response | Missing required fields or invalid ENUM value | Check response body |
| 500 response | PHP fatal error | Check `error.log` |
| POST fires but `$_POST` is empty | `Content-Type` header issue with `fetch` | Check request headers in Network tab |

## Anti-patterns (learned 2026-07-19)

- ❌ Reading 500+ lines of PHP when the browser Console shows a JS `ReferenceError`
- ❌ Fixing edge-case data integrity bugs (duplicate SQL columns) that have nothing to do with the symptom
- ❌ Rewriting the form's JS handler without first checking if the original is even called
- ❌ Analyzing CSRF managers, input parsers, route matchers when `$_POST` never reaches PHP
- ❌ Making changes across 4+ files without a single piece of runtime evidence narrowing the failure point

## DiSyL template debugging (learned 2026-07-19)

When a DiSyL template change doesn't appear on the page:

1. **Check for compilation errors** — `grep render_failure storage/logs/app.log`. A single broken expression (like `{keyof missing_entity}`) blocks the ENTIRE template from compiling. The old compiled cache masks this until cleared.

2. **Verify the compiled output** — after clearing cache, check `ls -lt storage/cache/compiled/`. If the template file is missing, it failed to compile. If it exists, `grep` for your changes in the compiled `.php` file.

3. **Test rendering via CLI first** — `app()->render($tpl, $ctx)` from a PHP one-liner. If buttons appear in CLI but not browser, the issue is cache-related (OPcache, page cache, browser cache).

4. **Understand component contracts before editing** — DiSyL components like `workbench:detail_header` expect specific parameter formats (e.g., `actions` must be an array of `{url, label, variant}` objects, NOT raw HTML strings). Read the component's source template before passing data to it.

5. **Use `?disyl_nocache=1`** on every test page load after template changes. The compiled cache does NOT detect all source changes (e.g., ancestor layout changes).

6. **`{_dh_actions|raw}` is the safe way to render pre-built HTML** in templates. Component `{for}` loops silently skip non-iterable values — your HTML string will be discarded with no error.

## Verify with real data, not test data (learned 2026-07-19)

- ❌ Testing with project #613 (CLI-modified record) when the user is looking at project #620
- ❌ Assuming `project.status == 'pending'` without checking the actual DB value
- ❌ Assuming `project.client_email` is set without verifying against the real record
- ✅ Always ask: *"What exact ID/URL are you looking at?"* before running any test
- ✅ Query the actual database record to verify conditions before claiming a fix works
