# Example Notes Module

> **Reference module — do not enable in production.**
> This module exists as a clean, idiomatic example of how to build an Ikabud module.
> Keep it disabled. Use it as your source of truth when writing real modules.

## What This Demonstrates

| Pattern | Where to see it |
|---------|----------------|
| Scoped context helpers | `helpers.php` — `enCtx()`, `enDb()`, `enInput()`, `enRender()` |
| ModuleContext auth guard | `handlers.php` — `enCtx()->requireAnyRole('admin')` |
| Input reading (never `$_POST`) | `handlers.php` — `enInput()` |
| Scoped database access (never `app()->db()`) | `handlers.php` — `enDb()->prepare()` |
| Event emission after state change | `handlers.php` — `app()->events()->fire(...)` |
| Route handler format | `routes.php` — `'module-id:functionName'` |
| SQL migration (not PHP closures) | `database/migrations/001_initial.sql` |
| DiSyL template extends/block | `templates/modules/example-notes/pages/list.disyl` |
| Partial includes | `templates/modules/example-notes/partials/note-row.disyl` |
| JSON API handler | `handlers.php` — `apiExampleNotesCreate()` |

## File Structure

```
modules/example-notes/
├── module.json                        ← manifest: id, capabilities, events, tables
├── routes.php                         ← GET/POST route map
├── handlers.php                       ← one function per route
├── helpers.php                        ← scoped en*() helper functions
├── database/migrations/
│   └── 001_initial.sql                ← en_notes table
└── README.md                          ← this file

templates/modules/example-notes/
├── pages/
│   ├── list.disyl                     ← admin: note list
│   ├── new.disyl                      ← admin: create form
│   └── view.disyl                     ← admin: edit/delete form
└── partials/
    └── note-row.disyl                 ← reusable table row

tests/
└── example_notes_module_test.php      ← scaffold + contract test
```

## Routes

| Method | Path | Handler |
|--------|------|---------|
| GET | `/admin/example-notes` | `pageExampleNotesList` |
| GET | `/admin/example-notes/new` | `pageExampleNotesNew` |
| GET | `/admin/example-notes/{id}` | `pageExampleNotesView` |
| POST | `/api/v1/example-notes/notes` | `apiExampleNotesCreate` |
| POST | `/api/v1/example-notes/notes/{id}` | `apiExampleNotesUpdate` |
| POST | `/api/v1/example-notes/notes/{id}/delete` | `apiExampleNotesDelete` |

## CLI Commands

```bash
# Validate the module against all kernel contracts
php ikabud module:validate example-notes

# Run the module test
php tests/example_notes_module_test.php

# Run migration (only if you enable this module)
php ikabud migrate example-notes
```

## Learning Resources

- [Module Quickstart Tutorial](../../docs/kernel/module-quickstart.md)
- [Module Development Guide](../../docs/kernel/module-development-guide.md)
