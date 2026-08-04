# CMS Akira Builder

cms-akira-builder is a CMS Akira submodule.

## Responsibility

Visual builder integration surface for CMS Akira.

## Suite Placement

- Module path: modules/cms-akira/cms-akira-builder
- Templates path: templates/modules/cms-akira-builder

## Quick Start

1. Run migrations: php ikabud migrate cms-akira-builder
2. Enable module: php ikabud module:enable cms-akira-builder
3. Open admin route: /admin/cms-akira-builder

## Validation

- Module scaffold test: php tests/cms_akira_builder_module_test.php
- Suite-wide checks:
	- php tests/cms_akira_deploy_readiness_test.php
	- php tests/cms_akira_provider_boundary_health_test.php
	- php ikabud architecture:check

## Notes

- Keep dependencies explicit in module.json capabilities.depends.
- Keep shared behavior capability-exposed; do not rely on suite-folder implicit coupling.
- For suite overview, see modules/cms-akira/README.md.
