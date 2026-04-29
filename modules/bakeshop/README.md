# Bakeshop Module

Bakeshop is the tenant-scoped bakery operations workspace: branches, ingredients, products, recipes, deliveries, production runs, usage reporting, staff management, and module-owned authentication.

Canonical documentation lives in [docs/bakeshop/bakeshop-module.md](../../docs/bakeshop/bakeshop-module.md). That document covers the manifest, migrations, routes, auth provider, trusted provisioning, trusted admin recovery, self-service password reset, and the focused test suite.

Runtime source files:

- manifest: [module.json](module.json)
- routes: [routes.php](routes.php)
- handler loader: [handlers.php](handlers.php)
- auth flow: [handlers/05-auth.php](handlers/05-auth.php)
- templates: [../../templates/modules/bakeshop](../../templates/modules/bakeshop)