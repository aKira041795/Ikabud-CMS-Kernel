# CMS Akira Profile Minimal

cms-akira-profile-minimal is a CMS Akira submodule.

## Responsibility

Minimal profile bundle for lean CMS Akira deployments.

## Suite Placement

- Module path: modules/cms-akira/cms-akira-profile-minimal
- Templates path: templates/modules/cms-akira-profile-minimal

## Quick Start

1. Run migrations: php ikabud migrate cms-akira-profile-minimal
2. Enable module: php ikabud module:enable cms-akira-profile-minimal
3. Open admin route: /admin/cms-akira-profile-minimal

## Validation

- Module scaffold test: php tests/cms_akira_profile_minimal_module_test.php
- Suite-wide checks:
	- php tests/cms_akira_deploy_readiness_test.php
	- php tests/cms_akira_provider_boundary_health_test.php
	- php ikabud architecture:check

## Notes

- Keep dependencies explicit in module.json capabilities.depends.
- Keep shared behavior capability-exposed; do not rely on suite-folder implicit coupling.
- For suite overview, see modules/cms-akira/README.md.
