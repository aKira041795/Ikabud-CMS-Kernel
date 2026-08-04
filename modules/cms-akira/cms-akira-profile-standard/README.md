# CMS Akira Profile Standard

cms-akira-profile-standard is a CMS Akira submodule.

## Responsibility

Standard profile bundle for default CMS Akira deployments.

## Suite Placement

- Module path: modules/cms-akira/cms-akira-profile-standard
- Templates path: templates/modules/cms-akira-profile-standard

## Quick Start

1. Run migrations: php ikabud migrate cms-akira-profile-standard
2. Enable module: php ikabud module:enable cms-akira-profile-standard
3. Open admin route: /admin/cms-akira-profile-standard

## Validation

- Module scaffold test: php tests/cms_akira_profile_standard_module_test.php
- Suite-wide checks:
	- php tests/cms_akira_deploy_readiness_test.php
	- php tests/cms_akira_provider_boundary_health_test.php
	- php ikabud architecture:check

## Notes

- Keep dependencies explicit in module.json capabilities.depends.
- Keep shared behavior capability-exposed; do not rely on suite-folder implicit coupling.
- For suite overview, see modules/cms-akira/README.md.
