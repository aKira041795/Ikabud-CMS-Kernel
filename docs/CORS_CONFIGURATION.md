# CORS Configuration for Ikabud Kernel

## Overview

Ikabud Kernel uses a **multi-layer CORS strategy** to enable cross-subdomain communication between admin dashboards and CMS instances. This allows domains like `admin.example.test` or `dashboard.example.test` to make API requests to `example.test`.

## CORS Layers

### 1. Apache Level (`.htaccess`)
**Location**: `instances/{instance_id}/.htaccess`  
**Template**: `templates/instance.htaccess`

Handles OPTIONS preflight requests at the Apache level before PHP code executes.

```apache
SetEnvIf Origin "^https?://(.+\.)?([^.]+\.test)$" ORIGIN_ALLOWED=$0
Header always set Access-Control-Allow-Origin "%{ORIGIN_ALLOWED}e" env=ORIGIN_ALLOWED
Header always set Access-Control-Allow-Methods "GET, POST, PUT, DELETE, OPTIONS" env=ORIGIN_ALLOWED
Header always set Access-Control-Allow-Headers "Origin, X-Requested-With, X-WP-Nonce, Content-Type, Accept, Authorization, X-HTTP-Method-Override" env=ORIGIN_ALLOWED
Header always set Access-Control-Allow-Credentials "true" env=ORIGIN_ALLOWED
```

### 2. Kernel Level (`public/index.php`)
**Location**: `public/index.php`

Two CORS handlers:
- **Slim Middleware** (lines 91-107): Handles API routes
- **CMS Route Handler** (lines 179-195): Handles WordPress/CMS requests

```php
if ($origin && preg_match('/^https?:\/\/(.+\.)?[^.]+\.test$/', $origin)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Authorization, Content-Type, X-WP-Nonce, X-Requested-With, Origin, Accept, X-HTTP-Method-Override');
    header('Access-Control-Allow-Credentials: true');
}
```

### 3. WordPress Plugin Level (MU Plugin)
**Location**: `instances/{instance_id}/wp-content/mu-plugins/ikabud-cors.php`  
**Template**: `templates/ikabud-cors.php`

Provides backup CORS coverage for WordPress REST API requests.

```php
add_action('send_headers', 'ikabud_handle_cors', 1);
add_filter('rest_pre_serve_request', 'ikabud_rest_cors_headers', 10, 4);
```

## Supported Patterns

The CORS configuration works with any `.test` domain and subdomain:

✅ `admin.thejake.test` → `thejake.test`  
✅ `dashboard.magic.test` → `magic.test`  
✅ `api.staging.example.test` → `example.test`  
✅ `thejake.test` → `thejake.test` (same domain)

## Required Headers

All CORS layers include these headers:

- **Access-Control-Allow-Origin**: Echoes the requesting origin
- **Access-Control-Allow-Methods**: `GET, POST, PUT, DELETE, OPTIONS`
- **Access-Control-Allow-Headers**: 
  - `Origin`
  - `X-Requested-With`
  - `X-WP-Nonce` (WordPress authentication)
  - `Content-Type`
  - `Accept`
  - `Authorization`
  - `X-HTTP-Method-Override` (WordPress REST API method override)
- **Access-Control-Allow-Credentials**: `true` (enables cookies/auth)

## Common Issues

### Issue 1: Multiple Access-Control-Allow-Origin Headers
**Symptom**: `The 'Access-Control-Allow-Origin' header contains multiple values`

**Cause**: Conflicting CORS rules (e.g., font-specific `*` rule + dynamic origin rule)

**Solution**: Remove any `<FilesMatch>` CORS rules that set `Access-Control-Allow-Origin: *`

### Issue 2: Missing X-HTTP-Method-Override Header
**Symptom**: `Request header field x-http-method-override is not allowed`

**Cause**: WordPress REST API sends this header but it's not in allowed list

**Solution**: Add `X-HTTP-Method-Override` to all `Access-Control-Allow-Headers`

### Issue 3: Preflight Request Fails
**Symptom**: `No 'Access-Control-Allow-Origin' header is present`

**Cause**: Apache handles OPTIONS before PHP, so PHP CORS handlers never run

**Solution**: Add CORS headers in `.htaccess` at Apache level

## Setup for New Instances

When creating a new instance:

1. **Copy `.htaccess` template**:
   ```bash
   cp templates/instance.htaccess instances/{instance_id}/.htaccess
   ```

2. **Copy WordPress CORS plugin** (for WordPress instances):
   ```bash
   cp templates/ikabud-cors.php instances/{instance_id}/wp-content/mu-plugins/
   ```

3. **Verify CORS is working**:
   - Open browser console on `admin.{domain}.test`
   - Make API request to `{domain}.test/wp-json/*`
   - Should see no CORS errors

## Production Considerations

For production environments (non-.test domains):

1. **Update regex pattern** in all CORS layers:
   ```apache
   # Change from:
   SetEnvIf Origin "^https?://(.+\.)?([^.]+\.test)$" ORIGIN_ALLOWED=$0
   
   # To (for .com domains):
   SetEnvIf Origin "^https?://(.+\.)?([^.]+\.com)$" ORIGIN_ALLOWED=$0
   ```

2. **Use HTTPS only**:
   ```apache
   SetEnvIf Origin "^https://(.+\.)?([^.]+\.com)$" ORIGIN_ALLOWED=$0
   ```

3. **Whitelist specific subdomains** (more secure):
   ```apache
   SetEnvIf Origin "^https://(admin|dashboard|api)\.example\.com$" ORIGIN_ALLOWED=$0
   ```

## Testing CORS

### Browser Console Test
```javascript
// From admin.example.test console:
fetch('http://example.test/wp-json/wp/v2/posts', {
  credentials: 'include',
  headers: {
    'Content-Type': 'application/json'
  }
})
.then(r => r.json())
.then(console.log)
.catch(console.error);
```

### cURL Test
```bash
curl -H "Origin: http://admin.example.test" \
     -H "Access-Control-Request-Method: POST" \
     -H "Access-Control-Request-Headers: X-WP-Nonce" \
     -X OPTIONS \
     -v \
     http://example.test/wp-json/wp/v2/posts
```

Should return:
```
Access-Control-Allow-Origin: http://admin.example.test
Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS
Access-Control-Allow-Credentials: true
```

## Files Modified

- ✅ `/var/www/html/ikabud-kernel/templates/instance.htaccess`
- ✅ `/var/www/html/ikabud-kernel/templates/ikabud-cors.php`
- ✅ `/var/www/html/ikabud-kernel/public/index.php`
- ✅ `/var/www/html/ikabud-kernel/instances/inst_58b72c1746710061/.htaccess`
- ✅ `/var/www/html/ikabud-kernel/instances/wp-test-001/.htaccess`
- ✅ `/var/www/html/ikabud-kernel/instances/*/wp-content/mu-plugins/ikabud-cors.php`
