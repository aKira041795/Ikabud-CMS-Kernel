---
name: code-review-checklist
description: Review checklist for PHP, SQL, templates, routes, and migrations
applyTo: "**/*.php,**/*.disyl,**/*.sql"
---

# Code Review Checklist

## SQL queries
- [ ] No table aliases? Use full table names (`FROM employee_profiles` not `FROM employee_profiles ep`)
- [ ] All referenced tables declared in `module.json` `owns_tables` or `reads_tables`?
- [ ] `CONCAT_WS` uses `NULLIF(col, '')` to skip empty name parts?
- [ ] Parameter count matches between SQL and `execute()` array?
- [ ] Benefit deduction queries include applicable flag checks (`AND sss_applicable = 1`)?
- [ ] `tenant_id` filter present on all tenant-scoped queries?

## Migrations
- [ ] File number is next sequential (check `database/migrations/` for latest)?
- [ ] Registered in `module.json` `migrations` array?
- [ ] `ALTER TABLE` uses idempotent pattern (check column exists first)?

## Routes
- [ ] Literal routes (`/create`) ordered before parameterized routes (`/{id}`)?
- [ ] No conflicting patterns that could match wrong handler?
- [ ] Route handler function exists and is exported in handlers file?

## Entity views
- [ ] Registered via `$views->registerView()` in `helpers/entity-views.php`?
- [ ] Capability handler exists? Registered in capability map?
- [ ] `key_field` set appropriately for action URL interpolation?
- [ ] Action URLs use `{id}` or proper placeholder?
- [ ] Renderers cover all display fields?
- [ ] Sort fields match actual column names in query results?

## Form handlers (create/update)
- [ ] Checkboxes use `isset()` or `array_key_exists()` with `$isFormPost` fallback for unchecked state?
- [ ] Text fields included in update handler's field list?
- [ ] New columns added to INSERT statement in create handler?
- [ ] File uploads handled (photo)?

## Templates
- [ ] No `{forelse}` — use `{if not list}` after `{/for}` instead?
- [ ] All variables have `|default:` fallback?
- [ ] `page_title` passed from handler?
- [ ] Action URLs correct for entity views?
