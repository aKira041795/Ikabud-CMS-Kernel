---
name: debug-workflow
description: Systematic debugging — logs, common errors, DiSyL warnings, module DB issues
---

# Debug Workflow

## Always check both logs first
- `storage/logs/app.log` — capability dispatch, module DB DENIED, DiSyL strict warnings, request IDs
- `storage/logs/error.log` — PHP fatal errors, stack traces, parse errors

Check **both** on every debugging session. Don't skip one.

## Common error patterns

| Error | What it means | Where to look |
|---|---|---|
| `ModuleDB DENIED: accessed undeclared table 'X'` | Table alias or table not in `module.json` owns_tables | The SQL in the warning, then `module.json` |
| `SQLSTATE[42S22]: Unknown column` | Column doesn't exist in table | Migration not run, or typo in column name |
| `SQLSTATE[42S02]: Base table not found` | Table doesn't exist | Module migrations not run for this tenant |
| `SQLSTATE[HY093]: Invalid parameter number` | PDO param count mismatch | Count `:params` in SQL vs execute array |
| `[strict] Undefined variable: X` | Template references variable not passed | Add `|default:''` or pass from handler |
| `Class "X" not found` | Autoloader can't find class | Run `composer dump-autoload` |

## DiSyL debugging
- Strict mode warnings are logged to `app.log` — check there first
- Undefined variables render as empty string in non-strict, warning in strict
- `{forelse}` is NOT supported — it's treated as an undefined variable reference
- Table aliases in SQL inside `{ikb_entity_list}` sources are fine (PHP queries), but module DB layer blocks them in handler SQL

## Request tracing
- Every request gets an `X-Request-Id` header and `request_id` in logs
- Correlate API failures by searching the request_id across both logs
- Capability calls are logged with `ok:true/false` and `duration_ms`
