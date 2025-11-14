# DiSyL Legacy Files

This directory contains superseded versions of DiSyL components kept for reference and rollback purposes.

## Files

### v0.1 (Original Implementation)
- **ComponentManifest.v0.1.backup.json** - Original manifest without capabilities, filters, inheritance
- **ManifestLoader.v0.1.backup.php** - Original loader without caching, inheritance resolution

### v0.2 (Source Files - Now Integrated)
- **ComponentManifest.v0.2.json** - Enhanced manifest (now active as ComponentManifest.json)
- **ManifestLoader.v0.2.php** - Enhanced loader (now active as ManifestLoader.php)

## Current Active Files

The following files are now active in the parent directory:
- `../ComponentManifest.json` - v0.2 manifest (with capabilities, filters, inheritance)
- `../ManifestLoader.php` - v0.2 loader (with caching, validation, inheritance)

## Version History

### v0.1.0 (November 13, 2025)
- Basic CMS component mapping
- Simple attribute transformation
- No validation
- No caching

### v0.2.0 (November 14, 2025)
- ✅ Component capabilities layer
- ✅ Component inheritance (base_components)
- ✅ Expression filters (7 built-in)
- ✅ Hash-based caching (OPcache)
- ✅ Event hook system
- ✅ JSON Schema validation
- ✅ Preview modes
- ✅ Deprecation system
- ✅ Transform pipelines
- ✅ Multi-renderer support

## Rollback Instructions

If you need to rollback to v0.1:

```bash
cd /var/www/html/ikabud-kernel/kernel/DiSyL

# Backup current v0.2
cp ComponentManifest.json ComponentManifest.v0.2.current.json
cp ManifestLoader.php ManifestLoader.v0.2.current.php

# Restore v0.1
cp Legacy/ComponentManifest.v0.1.backup.json ComponentManifest.json
cp Legacy/ManifestLoader.v0.1.backup.php ManifestLoader.php

# Clear cache
rm -f ../../storage/cache/manifest.*.compiled
```

## Migration Guide

See: `/docs/DISYL_MANIFEST_V0.2_MIGRATION.md`

## Do Not Delete

These files are kept for:
- Reference during development
- Emergency rollback
- Version comparison
- Historical documentation

**Last Updated:** November 14, 2025
