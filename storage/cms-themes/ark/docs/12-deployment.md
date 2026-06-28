# ARK Theme Deployment

## Packaging

ARK ships as part of the Ikabud application. For standalone distribution:

```bash
# Create distributable ZIP
cd storage/cms-themes
zip -r ark-theme-v1.0.0.zip ark/ -x "ark/docs/*" "ark/screenshots/*"
```

## Installation

```bash
# Option 1: CLI activation (already installed)
php ikabud theme:activate ark

# Option 2: Upload + extract
cp ark-theme-v1.0.0.zip storage/cms-themes/
cd storage/cms-themes/
unzip ark-theme-v1.0.0.zip
php ikabud theme:activate ark
```

## Activation Flow

1. CMS reads `theme.manifest.json`
2. ThemeManifestValidator validates schema, files, tokens, anti-patterns
3. Theme directory hardlinked to `templates/_cms_active_theme/`
4. Assets copied to `public/assets/cms/themes/ark/`
5. Customizer scope `native_ark` initialized with defaults from `tokens.json`

## Validation

```bash
# Full certification
php ikabud theme:validate ark

# Quick summary
php ikabud theme:inspect ark

# DiSyL lint only
php _lint_disyl.php --path storage/cms-themes/ark
```

## Upgrade Checklist

When upgrading ARK:

1. Increment `version` in `theme.manifest.json`
2. Update `kernel_os_compat` / `disyl_compat` if needed
3. Run `php ikabud theme:validate ark` — all checks must pass
4. Verify production test matrix (see README)
5. Clear compiled template cache: delete `storage/cache/disyl/`

## Production Test Matrix

Before certifying ARK as production-ready, verify under:

- [ ] Kernel OS with no optional business modules
- [ ] CMS module only
- [ ] CMS + ecommerce module
- [ ] Guidance public booking pages
- [ ] Unknown third-party entity type (fallbacks)
- [ ] Theme Studio disabled (ARK complete without it)
- [ ] Theme Studio enabled with token overrides
- [ ] Invalid slot contribution (graceful degradation)
- [ ] Missing optional capability (block hides)
- [ ] JavaScript disabled (no required JS)
- [ ] Mobile and keyboard navigation
- [ ] High-contrast and reduced-motion modes
- [ ] Long content, long names, empty entities
- [ ] Module removal after contribution was registered
- [ ] Kernel upgrade with manifest/schema migration

## Files Not for Distribution

- `docs/` — reference only, not loaded at runtime
- `screenshots/` — marketing assets
- `presets/` — theme presets (Theme Studio companion)
- `preview/` — development preview assets
