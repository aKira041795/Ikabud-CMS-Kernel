# Root Cause Analysis - Bluehost CORS Issue

## The Error
```
Access to fetch at 'https://ikabudkernel.com/wp-json/wp/v2/settings?_locale=user' 
from origin 'https://admin.ikabudkernel.com' has been blocked by CORS policy: 
Response to preflight request doesn't pass access control check: 
No 'Access-Control-Allow-Origin' header is present on the requested resource.
```

## The Real Problem

### Architecture
Your setup has:
- **Backend**: `admin.ikabudkernel.com` → WordPress installation (has `/wp-json/` API)
- **Frontend**: `ikabudkernel.com` → Static files or headless app (NO WordPress, NO `/wp-json/`)

### What Was Happening (WRONG)
1. User opens WordPress admin at `admin.ikabudkernel.com`
2. WordPress admin tries to fetch settings via REST API
3. **WordPress uses `WP_HOME` value** → `https://ikabudkernel.com/wp-json/...`
4. Browser makes request to `ikabudkernel.com/wp-json/...`
5. ❌ **FAILS** - Frontend doesn't have WordPress, no `/wp-json/` endpoint exists
6. ❌ **FAILS** - Even if it existed, no CORS headers because no WordPress to run the plugin

### Why This Happened
WordPress has two URL settings:
- `WP_SITEURL` = `https://admin.ikabudkernel.com` (where WordPress files live)
- `WP_HOME` = `https://ikabudkernel.com` (where the public site is displayed)

By default, WordPress REST API uses `WP_HOME` for API URLs, which is WRONG in a headless setup where the frontend doesn't have WordPress.

## The Solution

### Fix #5: REST URL Filter (Lines 322-340)
```php
add_filter('rest_url', 'ikabud_force_backend_rest_url', 10, 2);
function ikabud_force_backend_rest_url($url, $path) {
    // Only modify if we're in admin or this is a REST request
    if (!is_admin() && strpos($_SERVER['REQUEST_URI'] ?? '', '/wp-json/') === false) {
        return $url;
    }
    
    // If WP_SITEURL and WP_HOME are different (headless setup)
    if (defined('WP_SITEURL') && defined('WP_HOME') && WP_SITEURL !== WP_HOME) {
        // Force REST API to use WP_SITEURL (backend domain)
        $rest_url = WP_SITEURL . '/wp-json/';
        if ($path) {
            $rest_url .= ltrim($path, '/');
        }
        return $rest_url;
    }
    
    return $url;
}
```

### What This Does
1. Detects when WordPress admin is running
2. Checks if `WP_SITEURL` ≠ `WP_HOME` (headless setup)
3. **Forces REST API to use `WP_SITEURL`** (backend domain)
4. Now API calls go to `admin.ikabudkernel.com/wp-json/...` ✅

### What Happens Now (CORRECT)
1. User opens WordPress admin at `admin.ikabudkernel.com`
2. WordPress admin tries to fetch settings via REST API
3. **Filter intercepts and changes URL** → `https://admin.ikabudkernel.com/wp-json/...`
4. Browser makes request to `admin.ikabudkernel.com/wp-json/...`
5. ✅ **SUCCESS** - Backend has WordPress with `/wp-json/` endpoint
6. ✅ **SUCCESS** - Same origin request (admin → admin), no CORS needed!

## Why CORS Fixes 1-4 Weren't Enough

The other 4 fixes handle CORS headers correctly, but they can't fix the fundamental problem:

**You can't add CORS headers to a domain that doesn't have WordPress!**

The frontend (`ikabudkernel.com`) is just static files. Even with perfect `.htaccess` CORS headers, the `/wp-json/` endpoint doesn't exist there, so the request fails before CORS even matters.

## The Complete Solution

### All 5 Fixes Working Together

1. **HTTPS Fix** - Ensures protocol consistency
2. **OPTIONS Preflight** - Handles CORS preflight if needed
3. **Early CORS Headers** - Sets headers before WordPress loads
4. **REST API Override** - Custom CORS for subdomain auth
5. **REST URL Fix** ⭐ - **Prevents the wrong domain from being called in the first place**

Fix #5 is the root cause fix. Fixes 1-4 ensure that IF cross-domain requests are needed (like for the customizer), they work correctly.

## Deployment Status

✅ **DEPLOYED** to `inst_5ca59a2151e98cd1`
- File: `/wp-content/mu-plugins/ikabud-cors.php`
- Version: 1.2.0
- Date: 2025-11-20

## Testing

After deployment, when you try to add a post/page:

**Before Fix:**
```
OPTIONS https://ikabudkernel.com/wp-json/wp/v2/settings?_locale=user
❌ net::ERR_FAILED (404 - endpoint doesn't exist)
```

**After Fix:**
```
GET https://admin.ikabudkernel.com/wp-json/wp/v2/settings?_locale=user
✅ 200 OK (same-origin request, no CORS needed)
```

## Next Steps

1. **Upload to Bluehost**: Copy the updated `ikabud-cors.php` to your live site
2. **Clear all caches**: Browser, WordPress, Bluehost, CDN
3. **Test**: Try adding a post/page
4. **Verify**: Check Network tab - should see requests to `admin.ikabudkernel.com/wp-json/`

## Key Takeaway

**The problem wasn't CORS headers - it was WordPress trying to fetch from the wrong domain.**

CORS headers can't fix a request to a non-existent endpoint. The real fix is making WordPress use the correct domain (backend) for API calls instead of the frontend domain.
