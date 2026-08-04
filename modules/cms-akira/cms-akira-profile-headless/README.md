# CMS Akira Profile Headless

cms-akira-profile-headless is a CMS Akira submodule.

## Responsibility

Headless profile bundle for API-first deployments.

## Suite Placement

- Module path: modules/cms-akira/cms-akira-profile-headless
- Templates path: templates/modules/cms-akira-profile-headless

## Quick Start

1. Run migrations: php ikabud migrate cms-akira-profile-headless
2. Enable module: php ikabud module:enable cms-akira-profile-headless
3. Open admin route: /admin/cms-akira-profile-headless

## Validation

- Module scaffold test: php tests/cms_akira_profile_headless_module_test.php
- Suite-wide checks:
	- php tests/cms_akira_deploy_readiness_test.php
	- php tests/cms_akira_provider_boundary_health_test.php
	- php ikabud architecture:check

## Notes

- Keep dependencies explicit in module.json capabilities.depends.
- Keep shared behavior capability-exposed; do not rely on suite-folder implicit coupling.
- For suite overview, see modules/cms-akira/README.md.
