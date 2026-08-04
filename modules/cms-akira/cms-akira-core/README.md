# CMS Akira Core

cms-akira-core is a CMS Akira submodule.

## Responsibility

Core content orchestration and provider-boundary contracts.

## Suite Placement

- Module path: modules/cms-akira/cms-akira-core
- Templates path: templates/modules/cms-akira-core

## Quick Start

1. Run migrations: php ikabud migrate cms-akira-core
2. Enable module: php ikabud module:enable cms-akira-core
3. Open admin route: /admin/cms-akira-core

## Validation

- Module scaffold test: php tests/cms_akira_core_module_test.php
- Core/suite checks:
	- php tests/cms_akira_core_adapter_contract_test.php
	- php tests/cms_akira_provider_boundary_health_test.php
	- php tests/cms_akira_deploy_readiness_test.php
	- php ikabud architecture:check

## Notes

- Keep optional providers runtime-boundary based; avoid hard coupling.
- Keep dependencies explicit in module.json capabilities.depends.
- For suite overview, see modules/cms-akira/README.md.
