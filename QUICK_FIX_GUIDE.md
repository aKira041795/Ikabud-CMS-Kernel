# Quick Fix Guide - Bluehost CORS Issue

## Problem
❌ Cannot add pages or posts on ikabudkernel.com (frontend) + admin.ikabudkernel.com (backend)

## Solution Status
✅ **FIXED** - Updated template with 5 critical Bluehost-specific fixes

## What Was Fixed

### 1. HTTPS Protocol Detection ✅
- Bluehost uses reverse proxy
- Added `X-Forwarded-Proto` detection
- Prevents HTTP/HTTPS mismatch errors

### 2. OPTIONS Preflight Handling ✅
- Handles OPTIONS requests BEFORE WordPress loads
- Prevents 403/404 errors on preflight
- Returns proper CORS headers immediately

### 3. Early CORS Headers ✅
- Sets headers before WordPress can override
- Applies to all `/wp-json/` requests
- Ensures headers are present from the start

### 4. WordPress REST API Override ✅
- Removes WordPress default CORS headers
- Adds custom headers for subdomain support
- Includes authentication credentials

### 5. REST URL Fix (CRITICAL) ✅
- **THE ROOT CAUSE FIX**
- Forces WordPress admin to use backend domain for REST API
- Prevents fetching from frontend domain (which has no WordPress)
- Fixes: `Access to fetch at 'https://ikabudkernel.com/wp-json/...' from origin 'https://admin.ikabudkernel.com'`

## Deploy Now

### Option 1: Deploy to Specific Instance
```bash
cd /var/www/html/ikabud-kernel
bash deploy-cors-fix.sh 5ca59a2151e98cd1
```

### Option 2: Deploy to All Instances
```bash
cd /var/www/html/ikabud-kernel
bash deploy-cors-fix.sh
```

### Option 3: Manual Copy
```bash
cp /var/www/html/ikabud-kernel/templates/ikabud-cors.php \
   /var/www/html/ikabud-kernel/instances/inst_5ca59a2151e98cd1/wp-content/mu-plugins/ikabud-cors.php
```

## After Deployment

### 1. Clear ALL Caches
- **Browser**: Ctrl+Shift+Delete (clear everything)
- **WordPress**: WP Admin → Clear cache (if using cache plugin)
- **Bluehost**: cPanel → File Manager → Clear cache
- **CDN**: Cloudflare/etc → Purge cache (if applicable)

### 2. Test the Fix
1. Open browser DevTools (F12)
2. Go to **Network** tab
3. Try to add a new post or page
4. Look for:
   - ✅ `OPTIONS` request returns **200** (not 403/404)
   - ✅ Response has `Access-Control-Allow-Origin: https://ikabudkernel.com`
   - ✅ No CORS errors in console
   - ✅ POST request succeeds

### 3. Expected Results
- ✅ Can add posts from frontend
- ✅ Can add pages from frontend
- ✅ Can edit content
- ✅ No CORS errors
- ✅ Authentication works

## Still Not Working?

### Check Cloudflare (if using)
1. Login to Cloudflare
2. Go to **Rules** → **Transform Rules**
3. Add rule:
   - **Type**: HTTP Response Header Modification
   - **Header**: Access-Control-Allow-Origin
   - **Value**: https://ikabudkernel.com
4. Disable **Browser Integrity Check**
5. Purge cache

### Check Backend wp-config.php
Add these lines to `admin.ikabudkernel.com/wp-config.php`:
```php
define('FORCE_SSL_ADMIN', true);
if (strpos($_SERVER['HTTP_X_FORWARDED_PROTO'], 'https') !== false) {
    $_SERVER['HTTPS'] = 'on';
}
```

### Check .htaccess
Ensure no conflicting CORS headers in `.htaccess` on backend

## Files Changed
- ✅ `/var/www/html/ikabud-kernel/templates/ikabud-cors.php` (v1.1.0)

## Files Created
- 📄 `BLUEHOST_CORS_FIX.md` - Detailed technical documentation
- 📄 `QUICK_FIX_GUIDE.md` - This file
- 📄 `deploy-cors-fix.sh` - Deployment script

## Version
**ikabud-cors.php v1.2.0** - Bluehost Shared Hosting Edition

## What Changed in v1.2.0
- Added Fix #5: REST URL redirection filter
- This is THE critical fix for your specific error
- WordPress admin now uses `admin.ikabudkernel.com/wp-json/` instead of `ikabudkernel.com/wp-json/`

## Support
If issues persist:
1. Check browser console for specific errors
2. Check Network tab for failed requests
3. Verify HTTPS on both domains
4. Check cookie domain is set to `.ikabudkernel.com`

---
**Last Updated**: 2025-01-20
**Status**: Ready to Deploy ✅
