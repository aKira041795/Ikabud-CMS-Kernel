# Instance Creation Summary - Quick Reference

## Critical Fixes Applied

### 1. Symlink Path Corrections
- ✅ Administrator symlinks: `../../../` (3 levels up)
- ✅ Root symlinks: `../../` (2 levels up)

### 2. Instance-Specific Directories (Physical)
- ✅ `images/` - User uploads
- ✅ `media/` - Component assets (39MB copied)
- ✅ `templates/` - Site themes (copied for customization)
- ✅ `tmp/` - Temporary files
- ✅ `administrator/cache/` - Instance cache
- ✅ `administrator/logs/` - Instance logs
- ✅ `administrator/manifests/` - Extension manifests

### 3. Shared Directories (Symlinks)
- ✅ `components/`, `modules/`, `plugins/`, `libraries/`
- ✅ `language/`, `layouts/`, `includes/`
- ✅ `api/`, `cli/`, `installation/`
- ✅ `cache/` (shared system cache)

### 4. Auto-Configuration
- ✅ Database created automatically
- ✅ `configuration.php` generated with correct paths
- ✅ Unique secret key per instance
- ✅ Proper permissions set (775 for writable, 755 for others)

### 5. Installation URL
- ✅ Correct URL: `http://domain.test/installation/setup`
- ❌ Wrong: `http://domain.test/installation/`

## Script Usage

```bash
./create-joomla-instance.sh <instance_id> <domain> <db_name> <db_user> <db_pass> [db_prefix]
```

## Example

```bash
./create-joomla-instance.sh inst_new joomlanew.test ikabud_joomla_new root 'password' jml_
```

## Post-Creation Checklist

- [ ] Verify all symlinks resolve: `ls -la instances/[id]/`
- [ ] Check administrator symlinks use `../../../`
- [ ] Confirm database exists
- [ ] Verify `configuration.php` has correct credentials
- [ ] Test installation URL: `http://domain.test/installation/setup`
- [ ] Complete Joomla installation wizard
- [ ] Verify site loads without errors

## Disk Space Per Instance

- **Initial**: ~50-60MB
- **Grows with**: User uploads, extensions, logs
- **Savings vs full copy**: 75-80% (150-250MB saved per instance)

## Key Files Per Instance

**Must exist (physical):**
- `configuration.php`
- `defines.php`
- `index.php`
- `.htaccess`
- `instance.json`
- `administrator/index.php`

**Must be writable:**
- `images/` (775)
- `media/` (775)
- `tmp/` (775)
- `administrator/cache/` (775)
- `administrator/logs/` (775)
- `administrator/manifests/` (775)

## Common Issues & Solutions

### Issue: "Class not found" errors
**Cause**: Wrong symlink paths
**Fix**: Check administrator symlinks use `../../../`

### Issue: Can't upload images
**Cause**: `images/` is symlink or wrong permissions
**Fix**: Ensure `images/` is physical directory with 775 permissions

### Issue: Template changes don't save
**Cause**: `templates/` is symlink
**Fix**: Ensure `templates/` is copied from shared core

### Issue: Extension installation fails
**Cause**: `media/` is symlink or wrong permissions
**Fix**: Ensure `media/` is physical directory with 775 permissions

### Issue: Installation URL 404
**Cause**: Missing `/setup` in URL
**Fix**: Use `http://domain.test/installation/setup`

## React Integration

When creating instances from React admin UI:

```javascript
const response = await fetch('/api/instances/create', {
  method: 'POST',
  body: JSON.stringify({
    instance_id: generateInstanceId(),
    domain: formData.domain,
    db_name: formData.dbName,
    db_user: formData.dbUser,
    db_pass: formData.dbPass,
    db_prefix: formData.dbPrefix || 'jml_'
  })
});

const result = await response.json();
// result.installation_url = "http://domain.test/installation/setup"
```

## Backup Strategy

**What to backup:**
- Database (SQL dump)
- `configuration.php`
- `images/` directory
- `media/` directory (if customized)
- `templates/` directory (if customized)
- `administrator/logs/` (optional)

**What NOT to backup:**
- Symlinked directories
- Shared core files
- Temporary files

**Typical backup size**: 50-500MB per instance
