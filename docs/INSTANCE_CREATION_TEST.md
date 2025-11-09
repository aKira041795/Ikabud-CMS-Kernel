# Instance Creation Test - CORS Configuration

## Test Date
November 9, 2025

## Test Instance
- **Instance ID**: `test-cors-001`
- **Instance Name**: Test CORS Instance
- **Database**: `ikabud_test_cors`
- **Domain**: `testcors.test`

## Test Results

### ✅ Instance Creation Script Updated
The `create-instance.sh` script now includes:

1. **MU-Plugins Directory** - Created automatically for WordPress plugins
2. **CORS .htaccess** - Copied from `templates/instance.htaccess`
3. **WordPress CORS Plugin** - Copied from `templates/ikabud-cors.php`
4. **Cookie Domain Fix** - Updated to support subdomain sharing
5. **Database Name Fix** - Reads from `.env` instead of hardcoded value

### ✅ Files Created Successfully

```bash
instances/test-cors-001/
├── .htaccess                          # ✓ CORS configuration
├── wp-config.php                      # ✓ Cookie domain updated
├── wp-content/
│   ├── mu-plugins/
│   │   └── ikabud-cors.php           # ✓ WordPress CORS plugin
│   ├── plugins/
│   ├── themes/
│   └── uploads/
└── [WordPress core symlinks]
```

### ✅ CORS Configuration Verified

#### Apache Level (.htaccess)
```apache
SetEnvIf Origin "^https?://(.+\.)?([^.]+\.test)$" ORIGIN_ALLOWED=$0
Header always set Access-Control-Allow-Origin "%{ORIGIN_ALLOWED}e" env=ORIGIN_ALLOWED
Header always set Access-Control-Allow-Methods "GET, POST, PUT, DELETE, OPTIONS" env=ORIGIN_ALLOWED
Header always set Access-Control-Allow-Headers "Origin, X-Requested-With, X-WP-Nonce, Content-Type, Accept, Authorization, X-HTTP-Method-Override" env=ORIGIN_ALLOWED
Header always set Access-Control-Allow-Credentials "true" env=ORIGIN_ALLOWED
```

#### WordPress Level (mu-plugins/ikabud-cors.php)
- ✓ `send_headers` hook for early CORS
- ✓ `rest_pre_serve_request` filter for REST API
- ✓ All required headers including `X-HTTP-Method-Override`

#### Cookie Configuration (wp-config.php)
```php
$base_domain = preg_replace('/^(admin|dashboard)\./', '', $current_host);
define('COOKIE_DOMAIN', '.' . $base_domain);
```

### ✅ Database Registration
```sql
mysql> SELECT instance_id, domain, status FROM instances WHERE instance_id = 'test-cors-001';
+---------------+---------------+--------+
| instance_id   | domain        | status |
+---------------+---------------+--------+
| test-cors-001 | testcors.test | active |
+---------------+---------------+--------+
```

## Expected Behavior

### Cross-Subdomain Requests
The new instance will support:

1. **Admin Dashboard Access**
   - `http://admin.testcors.test` → `http://testcors.test/wp-json/*`
   - `http://dashboard.testcors.test` → `http://testcors.test/wp-json/*`

2. **CORS Headers Sent**
   - `Access-Control-Allow-Origin: http://admin.testcors.test`
   - `Access-Control-Allow-Credentials: true`
   - `Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS`
   - `Access-Control-Allow-Headers: [all required headers]`

3. **WordPress Block Editor**
   - ✓ No CORS errors when publishing/updating
   - ✓ Font loading works correctly
   - ✓ REST API requests succeed
   - ✓ Media uploads work

## Next Steps for Testing

1. **Add to /etc/hosts**:
   ```bash
   sudo nano /etc/hosts
   # Add:
   127.0.0.1 testcors.test
   127.0.0.1 admin.testcors.test
   127.0.0.1 dashboard.testcors.test
   ```

2. **Install WordPress**:
   ```bash
   http://testcors.test/wp-admin/install.php
   ```

3. **Test CORS from Admin Dashboard**:
   - Access `http://admin.testcors.test`
   - Open browser console
   - Verify no CORS errors
   - Test WordPress block editor
   - Try publishing a page/post

4. **Verify Headers**:
   ```bash
   curl -H "Origin: http://admin.testcors.test" \
        -H "Access-Control-Request-Method: POST" \
        -H "Access-Control-Request-Headers: X-WP-Nonce" \
        -X OPTIONS \
        -v \
        http://testcors.test/wp-json/wp/v2/posts
   ```

## Summary

✅ **Instance creation script successfully updated**  
✅ **CORS configuration automatically applied**  
✅ **Test instance created and registered**  
✅ **All CORS files in place**  
✅ **Ready for WordPress installation and testing**

The instance creation process now includes complete CORS configuration out of the box, eliminating the need for manual CORS setup on new instances.
