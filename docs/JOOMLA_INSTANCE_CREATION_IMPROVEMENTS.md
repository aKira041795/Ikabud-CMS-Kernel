# Joomla Instance Creation Script Improvements

## Date: November 10, 2025

## Issues Found in Previous Setup

### 1. **Incorrect Symlink Paths in Administrator Directory**
- **Problem**: Administrator symlinks used `../../` (2 levels up) instead of `../../../` (3 levels up)
- **Impact**: Joomla couldn't find core components, includes, language files, etc.
- **Fix**: Changed all administrator symlinks to use `../../../shared-cores/joomla/administrator/...`

### 2. **Cache, Tmp, and Images as Real Directories**
- **Problem**: These were created as real directories instead of symlinks to shared core
- **Impact**: Wasted disk space, inconsistent behavior across instances
- **Fix**: Changed to symlinks: `../../shared-cores/joomla/cache`, etc.

### 3. **Problematic autoload_psr4.php Symlink**
- **Problem**: Script tried to create a symlink in shared core pointing back to instance cache
- **Impact**: Caused autoloader issues and conflicts between instances
- **Fix**: Removed this symlink creation entirely

### 4. **Missing Database Creation**
- **Problem**: Script only showed instructions but didn't create the database
- **Impact**: Extra manual step required
- **Fix**: Added automatic database creation with proper error handling

### 5. **Missing configuration.php**
- **Problem**: No initial configuration file was created
- **Impact**: Joomla couldn't connect to database during setup
- **Fix**: Added automatic configuration.php generation with all necessary settings

### 6. **Installation URL Missing /setup**
- **Problem**: Instructions showed `/installation/` instead of `/installation/setup`
- **Impact**: Users got 404 or wrong page during installation
- **Fix**: Updated instructions to show correct URL: `http://domain.test/installation/setup`

## Improvements Made

### 1. **Better Error Handling**
```bash
set -e  # Exit on error
set -u  # Exit on undefined variable
```

### 2. **Validation Checks**
- Check if instance directory already exists
- Validate shared core directory exists
- Verify database creation (with graceful fallback)

### 3. **Automatic Configuration**
- Generates `configuration.php` with:
  - Correct database credentials
  - Proper instance-specific paths
  - Unique secret key (generated with openssl)
  - All Joomla configuration options

### 4. **Correct Directory Structure**
```
instances/[instance_id]/
├── administrator/
│   ├── cache/          (real directory, writable)
│   ├── logs/           (real directory, writable)
│   ├── manifests/      (real directory, writable)
│   ├── components/     (symlink: ../../../shared-cores/joomla/administrator/components)
│   ├── help/           (symlink: ../../../shared-cores/joomla/administrator/help)
│   ├── includes/       (symlink: ../../../shared-cores/joomla/administrator/includes)
│   ├── language/       (symlink: ../../../shared-cores/joomla/administrator/language)
│   ├── modules/        (symlink: ../../../shared-cores/joomla/administrator/modules)
│   └── templates/      (symlink: ../../../shared-cores/joomla/administrator/templates)
├── cache/              (symlink: ../../shared-cores/joomla/cache)
├── tmp/                (real directory, writable - for temp files)
├── images/             (real directory, writable - for user uploads)
│   ├── banners/
│   ├── headers/
│   └── sampledata/
├── media/              (real directory, copied from shared core - for component assets)
├── templates/          (real directory, copied from shared core - for customizations)
├── components/         (symlink: ../../shared-cores/joomla/components)
├── language/           (symlink: ../../shared-cores/joomla/language)
├── layouts/            (symlink: ../../shared-cores/joomla/layouts)
├── libraries/          (symlink: ../../shared-cores/joomla/libraries)
├── modules/            (symlink: ../../shared-cores/joomla/modules)
├── plugins/            (symlink: ../../shared-cores/joomla/plugins)
├── installation/       (symlink: ../../shared-cores/joomla/installation)
├── configuration.php   (real file, auto-generated)
├── defines.php         (real file, from template)
├── index.php           (real file, from template)
├── .htaccess           (real file, from template)
└── instance.json       (real file, auto-generated)
```

### 5. **Instance-Specific vs Shared Directories**

**Instance-Specific (Physical Directories):**
- `administrator/cache/` - Instance-specific cache files
- `administrator/logs/` - Instance-specific log files
- `administrator/manifests/` - Instance-specific extension manifests
- `images/` - User-uploaded images (unique per instance)
- `tmp/` - Temporary files (unique per instance)
- `media/` - Component media assets (can be customized per instance)
- `templates/` - Site templates (can be customized per instance)

**Shared (Symlinks to Core):**
- `cache/` - Shared Joomla system cache
- `components/` - Core components (read-only)
- `language/` - Language files (read-only)
- `layouts/` - Layout files (read-only)
- `libraries/` - Core libraries (read-only)
- `modules/` - Core modules (read-only)
- `plugins/` - Core plugins (read-only)
- `api/` - API files (read-only)
- `cli/` - CLI scripts (read-only)
- `includes/` - Core includes (read-only)
- `installation/` - Installation wizard (read-only)

**Why This Matters:**
- **images/**: Each instance needs its own uploaded images
- **media/**: Each instance may customize component CSS/JS
- **templates/**: Each instance may customize theme files
- **tmp/**: Each instance needs its own temp file space
- **Core files**: Shared to save disk space and simplify updates

### 6. **Proper Permissions**
- Instance files: 755
- configuration.php: 644
- Writable directories: 775
- Ownership set to www-data:www-data for writable directories

### 7. **Better User Instructions**
- Clear step-by-step next steps
- Correct installation URL with `/setup` suffix
- Database details summary
- Virtual host configuration reminder

## Usage

```bash
./create-joomla-instance.sh <instance_id> <domain> <db_name> <db_user> <db_pass> [db_prefix]
```

### Example:
```bash
./create-joomla-instance.sh inst_new joomlanew.test ikabud_joomla_new root 'password' jml_
```

### After Running Script:
1. Configure Apache virtual host for the domain
2. Add domain to `/etc/hosts` if testing locally
3. Visit: `http://domain.test/installation/setup`
4. Complete Joomla installation wizard
5. Installation directory will be automatically removed after completion

## Key Lessons Learned

1. **Symlink Path Depth Matters**: Always count the directory levels carefully
2. **Shared vs Instance-Specific**: Only writable directories should be instance-specific
3. **Configuration First**: Generate configuration.php before installation to avoid issues
4. **Validation is Critical**: Check for existing instances and required dependencies
5. **Clear Instructions**: Include the exact URL with `/setup` suffix

## Testing Checklist

When creating a new instance, verify:
- [ ] All symlinks resolve correctly (`ls -la` shows no broken links)
- [ ] Administrator symlinks use `../../../` (three levels up)
- [ ] Root symlinks use `../../` (two levels up)
- [ ] Database was created successfully
- [ ] configuration.php exists with correct credentials
- [ ] Writable directories have proper permissions (775)
- [ ] Installation URL works: `http://domain.test/installation/setup`
- [ ] After installation, site loads without errors

## Integration with React Admin UI

The script is designed to be called from the React Create Instance interface:

```javascript
// Example API call from React
const createInstance = async (instanceData) => {
  const response = await fetch('/api/instances/create', {
    method: 'POST',
    body: JSON.stringify({
      instance_id: instanceData.id,
      domain: instanceData.domain,
      db_name: instanceData.dbName,
      db_user: instanceData.dbUser,
      db_pass: instanceData.dbPass,
      db_prefix: instanceData.dbPrefix || 'jml_'
    })
  });
  
  return response.json();
};
```

The backend API should:
1. Validate input parameters
2. Execute the bash script
3. Return success/failure status
4. Provide the installation URL to the user
