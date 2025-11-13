# CMS Version Selection Feature

## Overview

The React Admin UI now supports selecting specific versions of Drupal and Joomla when creating instances, allowing users to choose between different major versions based on their server requirements.

## Available Versions

### Drupal
- **Drupal 10.3.10 (Default)** - `drupal`
  - MySQL 5.7+ / MariaDB 10.3+
  - PHP 8.1 - 8.3
  - Stable, widely compatible

- **Drupal 11.0.5 (Latest)** - `drupal11`
  - MySQL 8.0+ / MariaDB 10.6+
  - PHP 8.3+
  - Latest features, stricter requirements

### Joomla
- **Joomla 4.4.14 (Default)** - `joomla`
  - MySQL 5.7+ / MariaDB 10.1+
  - Stable, widely compatible

- **Joomla 5.2.1 (Latest)** - `joomla5`
  - MySQL 8.0.13+ / MariaDB 10.4+
  - Latest features, modern requirements

### WordPress
- **WordPress (Latest)** - `wordpress`
  - MySQL 5.7+ / MariaDB 10.3+
  - Single version (auto-updated)

## User Interface

### Version Selection Flow

1. **Select CMS Type**
   - User chooses WordPress, Joomla, or Drupal

2. **Version Dropdown Appears** (Drupal/Joomla only)
   - Shows available versions with labels
   - Default version pre-selected
   - Each option shows version number

3. **Requirements Display**
   - Shows database and PHP requirements
   - Warning for latest versions with stricter requirements
   - Color-coded alerts (yellow for warnings)

### UI Components

#### CMS Type Selection
```tsx
<select value={formData.cms_type}>
  <option value="">Select CMS</option>
  <option value="wordpress">WordPress</option>
  <option value="joomla">Joomla</option>
  <option value="drupal">Drupal</option>
</select>
```

#### Version Selection (Drupal/Joomla)
```tsx
<select value={formData.cms_version}>
  <option value="drupal">Drupal 10.3.10 (Default)</option>
  <option value="drupal11">Drupal 11.0.5 (Latest)</option>
</select>
```

#### Requirements Display
```tsx
<div className="bg-gray-50 border border-gray-200 rounded">
  <p>Requirements: MySQL 8.0+ / MariaDB 10.6+ | PHP 8.3+</p>
  {isLatestVersion && (
    <p className="text-yellow-700">
      ⚠️ Ensure your MySQL is 8.0+ and PHP is 8.3+ before installing
    </p>
  )}
</div>
```

## Backend Implementation

### API Changes

**File**: `/api/routes/instances-actions.php`

#### Version Parameter Handling
```php
// Get CMS version (defaults based on CMS type)
$cmsVersionDefaults = [
    'wordpress' => 'wordpress',
    'joomla' => 'joomla',
    'drupal' => 'drupal'
];
$cmsVersion = $body['cms_version'] ?? $cmsVersionDefaults[$cmsType] ?? 'wordpress';
```

#### Command Building
```php
// Joomla: Pass version as 8th parameter
if ($cmsType === 'joomla') {
    $command = "cd $rootPath && $scriptPath $instanceId $instanceName $domain $dbName $dbUser $dbPass $dbPrefix $cmsVersion 2>&1";
}

// Drupal: Pass version as 8th parameter
else if ($cmsType === 'drupal') {
    $command = "cd $rootPath && $scriptPath $instanceId $instanceName $domain $dbName $dbUser $dbPass $dbPrefix $cmsVersion 2>&1";
}
```

### Script Compatibility

Both `create-drupal-instance` and `create-joomla-instance` scripts already support version parameters:

**Drupal Script:**
```bash
./create-drupal-instance <instance_id> <instance_name> <domain> <db_name> <db_user> <db_pass> [db_prefix] [drupal_version]
```

**Joomla Script:**
```bash
./create-joomla-instance <instance_id> <instance_name> <domain> <db_name> <db_user> <db_pass> [db_prefix] [joomla_version]
```

## User Experience

### Creating a Drupal 11 Instance

1. Navigate to **Create Instance**
2. Enter instance details
3. Select **Drupal** as CMS type
4. Version dropdown appears automatically
5. Select **Drupal 11.0.5 (Latest)**
6. See requirements: "MySQL 8.0+ / MariaDB 10.6+ | PHP 8.3+"
7. See warning: "⚠️ Ensure your MySQL is 8.0+ and PHP is 8.3+ before installing"
8. Fill remaining fields
9. Click **Create Instance**
10. Drush auto-installs Drupal 11

### Creating a Joomla 5 Instance

1. Navigate to **Create Instance**
2. Enter instance details
3. Select **Joomla** as CMS type
4. Version dropdown appears automatically
5. Select **Joomla 5.2.1 (Latest)**
6. See requirements: "MySQL 8.0.13+ / MariaDB 10.4+"
7. See warning: "⚠️ Ensure your MySQL is 8.0.13+ or MariaDB is 10.4+ before installing"
8. Fill remaining fields
9. Click **Create Instance**
10. Complete installation via web interface

### Creating a WordPress Instance

1. Navigate to **Create Instance**
2. Enter instance details
3. Select **WordPress** as CMS type
4. **No version dropdown** (single version)
5. Fill remaining fields
6. Click **Create Instance**
7. Complete installation via web interface

## Visual Indicators

### Shared Core Path Display

The UI dynamically shows which shared core will be used:

- **Drupal 10**: `📦 Drupal - Shared core from shared-cores/drupal/`
- **Drupal 11**: `📦 Drupal - Shared core from shared-cores/drupal11/`
- **Joomla 4**: `📦 Joomla - Shared core from shared-cores/joomla/`
- **Joomla 5**: `📦 Joomla - Shared core from shared-cores/joomla5/`

### Installation Method Indicators

- **Drupal**: ✨ Auto-install via Drush
- **Joomla**: ⚙️ Manual setup via web interface
- **WordPress**: ⚙️ Manual setup via web interface

## Form Data Structure

```typescript
interface FormData {
  instance_id: string;
  instance_name: string;
  cms_type: 'wordpress' | 'joomla' | 'drupal';
  cms_version: 'wordpress' | 'joomla' | 'joomla5' | 'drupal' | 'drupal11';
  domain: string;
  admin_subdomain: string;
  database_name: string;
  database_user: string;
  database_password: string;
  database_host: string;
  database_prefix: string;
  memory_limit: string;
  max_execution_time: number;
  max_children: number;
}
```

## API Request Example

### Drupal 11 Instance
```json
{
  "instance_id": "dpl-mysite",
  "instance_name": "My Drupal 11 Site",
  "cms_type": "drupal",
  "cms_version": "drupal11",
  "domain": "mysite.com",
  "admin_subdomain": "admin.mysite.com",
  "database_name": "mysite_db",
  "database_user": "root",
  "database_password": "password",
  "database_prefix": ""
}
```

### Joomla 5 Instance
```json
{
  "instance_id": "jml-mysite",
  "instance_name": "My Joomla 5 Site",
  "cms_type": "joomla",
  "cms_version": "joomla5",
  "domain": "mysite.com",
  "admin_subdomain": "admin.mysite.com",
  "database_name": "mysite_db",
  "database_user": "root",
  "database_password": "password",
  "database_prefix": "jml_"
}
```

## Validation & Error Handling

### Version Validation
- Version dropdown only appears for Drupal and Joomla
- Default version auto-selected when CMS type changes
- Version is required when dropdown is visible

### Requirement Warnings
- **Drupal 11**: Shows MySQL 8.0+ and PHP 8.3+ warning
- **Joomla 5**: Shows MySQL 8.0.13+ or MariaDB 10.4+ warning
- Warnings are informational, not blocking

### Fallback Behavior
- If `cms_version` not provided, uses default for CMS type
- Backend validates version exists in shared-cores directory
- Script handles missing version parameter gracefully

## Testing

### Test Drupal Version Selection

1. **Test Default Version**
```bash
# Via UI
1. Select Drupal
2. Verify "Drupal 10.3.10 (Default)" is selected
3. Create instance
4. Verify uses shared-cores/drupal/
```

2. **Test Drupal 11**
```bash
# Via UI
1. Select Drupal
2. Change to "Drupal 11.0.5 (Latest)"
3. Verify warning appears
4. Create instance
5. Verify uses shared-cores/drupal11/
```

### Test Joomla Version Selection

1. **Test Default Version**
```bash
# Via UI
1. Select Joomla
2. Verify "Joomla 4.4.14 (Default)" is selected
3. Create instance
4. Verify uses shared-cores/joomla/
```

2. **Test Joomla 5**
```bash
# Via UI
1. Select Joomla
2. Change to "Joomla 5.2.1 (Latest)"
3. Verify warning appears
4. Create instance
5. Verify uses shared-cores/joomla5/
```

### Test WordPress (No Version Selection)

```bash
# Via UI
1. Select WordPress
2. Verify NO version dropdown appears
3. Create instance
4. Verify uses shared-cores/wordpress/
```

## Troubleshooting

### Version Dropdown Not Appearing
**Cause**: CMS type not selected or is WordPress
**Solution**: Select Drupal or Joomla to see version dropdown

### Wrong Shared Core Used
**Cause**: `cms_version` not passed to backend
**Check**:
1. Browser console for API request payload
2. Verify `cms_version` field in request
3. Check backend logs for command being executed

### Installation Fails with Version Error
**Cause**: Selected version's shared core doesn't exist
**Solution**:
```bash
# Verify shared cores exist
ls -la shared-cores/
# Should see: drupal, drupal11, joomla, joomla5, wordpress

# If missing, download and set up
./setup-drupal-versions.sh
./setup-joomla-versions.sh
```

## Future Enhancements

### Potential Improvements

1. **Auto-detect Server Capabilities**
   - Check PHP version via API
   - Check MySQL version via API
   - Disable incompatible versions

2. **Version-specific Features**
   - Show changelog/new features
   - Migration guides between versions
   - Compatibility matrix

3. **More Versions**
   - Add Drupal 9.x support
   - Add Joomla 3.x support (legacy)
   - Multiple WordPress versions

4. **Smart Recommendations**
   - Recommend version based on server specs
   - Highlight "Recommended" version
   - Show popularity/usage stats

## Related Files

- `/admin/src/pages/CreateInstance.tsx` - React UI component
- `/api/routes/instances-actions.php` - Backend API
- `/bin/create-drupal-instance` - Drupal creation script
- `/bin/create-joomla-instance` - Joomla creation script
- `/DRUPAL_VERSIONS.md` - Drupal version documentation
- `/JOOMLA_VERSIONS.md` - Joomla version documentation

## Summary

The CMS version selection feature provides:
- ✅ User-friendly version selection for Drupal and Joomla
- ✅ Clear requirement warnings for latest versions
- ✅ Automatic default version selection
- ✅ Dynamic shared core path display
- ✅ Backward compatible with existing instances
- ✅ Seamless integration with creation scripts
