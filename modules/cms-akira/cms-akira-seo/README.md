# CMS Akira SEO

cms-akira-seo is a CMS Akira submodule.

## Responsibility

SEO metadata provider for CMS Akira composition.

## Suite Placement

- Module path: modules/cms-akira/cms-akira-seo
- Templates path: templates/modules/cms-akira-seo

## Quick Start

1. Run migrations: php ikabud migrate cms-akira-seo
2. Enable module: php ikabud module:enable cms-akira-seo
3. Open admin route: /admin/cms-akira-seo

## Validation

- Module scaffold test: php tests/cms_akira_seo_module_test.php
- Suite-wide checks:
	- php tests/cms_akira_deploy_readiness_test.php
	- php tests/cms_akira_provider_boundary_health_test.php
	- php ikabud architecture:check

## Notes

- Keep dependencies explicit in module.json capabilities.depends.
- Keep shared behavior capability-exposed; do not rely on suite-folder implicit coupling.
- For suite overview, see modules/cms-akira/README.md.
