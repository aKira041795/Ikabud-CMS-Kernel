# CMS Akira Search Adapter

cms-akira-search-adapter is a CMS Akira submodule.

## Responsibility

Search document adapter/provider for indexing pipelines.

## Suite Placement

- Module path: modules/cms-akira/cms-akira-search-adapter
- Templates path: templates/modules/cms-akira-search-adapter

## Quick Start

1. Run migrations: php ikabud migrate cms-akira-search-adapter
2. Enable module: php ikabud module:enable cms-akira-search-adapter
3. Open admin route: /admin/cms-akira-search-adapter

## Validation

- Module scaffold test: php tests/cms_akira_search_adapter_module_test.php
- Suite-wide checks:
	- php tests/cms_akira_deploy_readiness_test.php
	- php tests/cms_akira_provider_boundary_health_test.php
	- php ikabud architecture:check

## Notes

- Keep dependencies explicit in module.json capabilities.depends.
- Keep shared behavior capability-exposed; do not rely on suite-folder implicit coupling.
- For suite overview, see modules/cms-akira/README.md.
