# WordPress Importer

CMS extension package for importing WordPress XML/WXR exports.

Includes:
- CMS admin page at `/cms/admin/wordpress-import`
- XML import API at `/api/v1/cms/wordpress-importer/import`
- Import support for posts, pages, categories, and tags

Build the uploadable ZIP with:

`php scripts/build-cms-wordpress-importer-package.php`

Then install the generated ZIP from the CMS Modules page.