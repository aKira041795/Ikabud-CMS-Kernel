# Bluehost CORS Fix for Ikabud Kernel

## Problem
Cannot add pages or posts on Bluehost shared hosting with:
- **Frontend**: ikabudkernel.com
- **Backend**: admin.ikabudkernel.com

Browser blocks REST API requests with CORS errors.

## Root Causes
1. **HTTPS Protocol Mismatch** - Bluehost reports HTTP internally even when HTTPS is used
2. **OPTIONS Preflight Failures** - WordPress returns 403/404 on OPTIONS requests
3. **WordPress Default CORS** - Default headers don't support subdomain setups
4. **Header Timing** - Headers set too late, after WordPress overrides them

## Solution Applied

Updated `/var/www/html/ikabud-kernel/templates/ikabud-cors.php` with 4 critical fixes:

### Fix #1: Force HTTPS Detection
```php
if (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {
    $_SERVER['HTTPS'] = 'on';
}
```
**Why**: Bluehost uses reverse proxy. This ensures WordPress sees HTTPS correctly.

### Fix #2: Handle OPTIONS Preflight Early
```php
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    // Set CORS headers and exit BEFORE WordPress loads
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE, PATCH');
    header('Access-Control-Allow-Headers: Authorization, Content-Type, X-WP-Nonce, ...');
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Max-Age: 86400');
    exit(0);
}
```
**Why**: Prevents WordPress from blocking preflight requests.

### Fix #3: Set Headers Before WordPress Loads
```php
if (strpos($_SERVER['REQUEST_URI'], '/wp-json/') !== false) {
    // Set CORS headers immediately for REST API
    header('Access-Control-Allow-Origin: ' . $origin);
    // ... other headers
}
```
**Why**: Ensures headers are set before WordPress can override them.

### Fix #4: Override WordPress Default REST CORS
```php
add_action('rest_api_init', function() {
    remove_filter('rest_pre_serve_request', 'rest_send_cors_headers');
    add_filter('rest_pre_serve_request', function($value) use ($origin) {
        // Set custom CORS headers
        return $value;
    }, 15);
});
```
**Why**: WordPress default CORS doesn't support subdomain authentication.

## Deployment Steps

### Option 1: Automatic (If Kernel System is Running)
The updated template will be automatically deployed to all instances on next sync.

### Option 2: Manual (For Immediate Fix)
1. Copy the updated template to your WordPress instance:
   ```bash
   cp /var/www/html/ikabud-kernel/templates/ikabud-cors.php \
      /path/to/wordpress/wp-content/mu-plugins/ikabud-cors.php
   ```

2. For the current instance (inst_5ca59a2151e98cd1):
   ```bash
   cp /var/www/html/ikabud-kernel/templates/ikabud-cors.php \
      /var/www/html/ikabud-kernel/instances/inst_5ca59a2151e98cd1/wp-content/mu-plugins/ikabud-cors.php
   ```

3. Clear all caches:
   - Browser cache (Ctrl+Shift+Delete)
   - WordPress cache (if using caching plugin)
   - Bluehost cache (via cPanel)
   - CDN cache (if using Cloudflare, etc.)

## Testing

After deployment, test the following:

1. **Open Browser DevTools** (F12)
2. **Go to Network tab**
3. **Try to add a new post/page**
4. **Check for**:
   - ✅ OPTIONS request returns 200 (not 403/404)
   - ✅ Response headers include `Access-Control-Allow-Origin: https://ikabudkernel.com`
   - ✅ No CORS errors in console
   - ✅ POST request succeeds

## Additional Considerations

### If Still Not Working:

1. **Check Cloudflare Settings** (if using):
   - Disable "Browser Integrity Check"
   - Add Transform Rule for CORS headers
   - Disable "Origin Header Rewrite"

2. **Check .htaccess** on backend (admin.ikabudkernel.com):
   ```apache
   # Ensure these are NOT blocking CORS
   # Remove any conflicting Header directives
   ```

3. **Verify wp-config.php** on backend:
   ```php
   // Add these if not present
   define('FORCE_SSL_ADMIN', true);
   if (strpos($_SERVER['HTTP_X_FORWARDED_PROTO'], 'https') !== false) {
       $_SERVER['HTTPS'] = 'on';
   }
   ```

4. **Check PHP version**: Ensure PHP 7.4+ (Bluehost default should be fine)

## Expected Behavior

After fix:
- ✅ Can add/edit posts from frontend (ikabudkernel.com)
- ✅ Can add/edit pages from frontend
- ✅ REST API requests work cross-domain
- ✅ Authentication persists across subdomains
- ✅ No CORS errors in browser console

## Version History

- **v1.1.0** - Added Bluehost shared hosting fixes (4 critical fixes)
- **v1.0.0** - Initial CORS handler

## Support

If issues persist after applying these fixes:
1. Check browser console for specific error messages
2. Check Network tab for failed requests
3. Verify both domains are using HTTPS
4. Ensure cookies are being set with correct domain (`.ikabudkernel.com`)
