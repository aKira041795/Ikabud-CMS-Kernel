# Drupal Drush Installation Troubleshooting

## Issue: Drush Not Triggering in Live Staging

The `create-drupal-instance` script has been updated to fix drush execution issues in live staging environments.

## Changes Made

### 1. **Improved PHP Path Detection**
- Uses `PHP_BINARY` as the primary source
- Falls back to common PHP locations:
  - `/usr/bin/php`
  - `/usr/local/bin/php`
  - `/opt/php/bin/php`
  - Result of `which php` command
- Verifies PHP executable exists and is executable before use

### 2. **Better Directory Handling**
- Uses `chdir()` instead of shell `cd` command
- Preserves and restores original working directory
- Ensures Drush runs from the correct instance path

### 3. **Environment Variables**
- Sets `DRUSH_PHP` to the detected PHP path
- Preserves `HOME` environment variable for Drush

### 4. **Improved Output Visibility**
- Changed from `exec()` to `passthru()` for real-time output
- Shows all Drush output during installation
- Better error diagnostics

### 5. **Proper Argument Escaping**
- All shell arguments are properly escaped using `escapeshellarg()`
- Prevents issues with special characters in passwords or paths

## Testing the Fix

Run the instance creation script:

```bash
cd /var/www/html/ikabud-kernel
./bin/create-drupal-instance test-001 "Test Site" test.example.com ikabud_test dbuser dbpass
```

### Expected Output

You should see:
```
[9/9] Installing Drupal via Drush...
   Using PHP: /usr/bin/php
   Using Drush: /var/www/html/ikabud-kernel/shared-cores/drupal/vendor/bin/drush
   Instance Path: /var/www/html/ikabud-kernel/instances/test-001
   Installing Drupal (this may take 2-3 minutes)...
   Running: drush site:install standard...

[... Drush output ...]

✓ Drupal installed successfully via Drush
```

## Common Issues and Solutions

### Issue 1: PHP Not Found
**Symptom**: "PHP executable not found or not executable"

**Solution**: 
```bash
# Check PHP path
which php
php --version

# If PHP is in a non-standard location, update the script's $possiblePaths array
```

### Issue 2: Drush Not Found
**Symptom**: "Drush not found at: /path/to/drush"

**Solution**:
```bash
cd shared-cores/drupal
composer require drush/drush --no-dev
```

### Issue 3: Permission Denied
**Symptom**: "Permission denied" when running drush

**Solution**:
```bash
chmod +x shared-cores/drupal/vendor/bin/drush
```

### Issue 4: Database Connection Failed
**Symptom**: Drush fails with database connection error

**Solution**:
- Verify database credentials
- Ensure database exists or user has CREATE DATABASE privilege
- Check MySQL is running: `systemctl status mysql`

### Issue 5: Memory Limit
**Symptom**: "Allowed memory size exhausted"

**Solution**:
```bash
# Increase PHP memory limit temporarily
php -d memory_limit=512M shared-cores/drupal/vendor/bin/drush site:install ...
```

## Manual Installation Fallback

If Drush installation fails, you can always complete installation manually:

1. Visit: `http://admin.yourdomain.com/core/install.php`
2. Follow the installation wizard
3. Database credentials are already configured in `sites/default/settings.php`

## Debugging Commands

### Check if Drush is working
```bash
cd instances/your-instance-id
php ../../shared-cores/drupal/vendor/bin/drush status
```

### Test database connection
```bash
php -r "new PDO('mysql:host=localhost;dbname=yourdb', 'user', 'pass');"
```

### Check PHP CLI version
```bash
php --version
php -m  # Show loaded modules
```

### Verify instance structure
```bash
ls -la instances/your-instance-id/
ls -la instances/your-instance-id/sites/default/
```

## Environment-Specific Notes

### Shared Hosting
- `shell_exec()` may be disabled - script handles this gracefully
- PHP path detection uses multiple fallbacks
- Manual installation is always available as fallback

### Live Staging
- Ensure PHP CLI is available (not just PHP-FPM)
- Check that `passthru()` is not disabled in `php.ini`
- Verify file permissions allow script execution

### Docker/Containers
- `PHP_BINARY` should work correctly
- Ensure container has MySQL client libraries
- May need to adjust database host from `localhost`

## Related Files

- `/var/www/html/ikabud-kernel/bin/create-drupal-instance` - Main script
- `/var/www/html/ikabud-kernel/DRUPAL_VERSIONS.md` - Drupal version info
- `/var/www/html/ikabud-kernel/SHARED_HOSTING_GUIDE.md` - Shared hosting setup
