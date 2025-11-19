# Deploy to Bluehost - Step by Step

## ✅ Status
**Fix is ready and tested locally**
- Version: ikabud-cors.php v1.2.0
- Instance: inst_5ca59a2151e98cd1
- Date: 2025-11-20

## 📋 Deployment Checklist

### Step 1: Backup Current File (IMPORTANT!)
```bash
# On Bluehost, via SSH or File Manager
cd /path/to/wordpress/wp-content/mu-plugins/
cp ikabud-cors.php ikabud-cors.php.backup.$(date +%Y%m%d)
```

Or via File Manager:
1. Login to Bluehost cPanel
2. Navigate to File Manager
3. Go to `public_html/wp-content/mu-plugins/`
4. Right-click `ikabud-cors.php` → Copy
5. Rename copy to `ikabud-cors.php.backup`

### Step 2: Upload New File

**Option A: Via File Manager (Easiest)**
1. Download from local server:
   - File: `/var/www/html/ikabud-kernel/instances/inst_5ca59a2151e98cd1/wp-content/mu-plugins/ikabud-cors.php`
2. Login to Bluehost cPanel
3. Open File Manager
4. Navigate to `public_html/wp-content/mu-plugins/`
5. Click "Upload"
6. Select the downloaded `ikabud-cors.php`
7. Overwrite existing file

**Option B: Via SSH/SFTP**
```bash
# From your local machine
scp /var/www/html/ikabud-kernel/instances/inst_5ca59a2151e98cd1/wp-content/mu-plugins/ikabud-cors.php \
    user@ikabudkernel.com:/path/to/wordpress/wp-content/mu-plugins/
```

**Option C: Copy/Paste Content**
1. Open local file in editor
2. Copy all content
3. Login to Bluehost File Manager
4. Edit `ikabud-cors.php`
5. Paste new content
6. Save

### Step 3: Verify File Permissions
```bash
# Should be 644
chmod 644 /path/to/wordpress/wp-content/mu-plugins/ikabud-cors.php
```

Or via File Manager:
- Right-click file → Permissions
- Set to `644` (rw-r--r--)

### Step 4: Clear ALL Caches

#### A. Browser Cache
1. Open browser (Chrome/Firefox)
2. Press `Ctrl+Shift+Delete` (Windows) or `Cmd+Shift+Delete` (Mac)
3. Select "All time"
4. Check all boxes
5. Clear data

#### B. WordPress Cache (if using cache plugin)
1. Login to WordPress admin: `https://admin.ikabudkernel.com/wp-admin`
2. Find cache plugin (W3 Total Cache, WP Super Cache, etc.)
3. Click "Purge All Caches" or "Clear Cache"

#### C. Bluehost Cache
1. Login to Bluehost cPanel
2. Find "Caching" section
3. Click "Flush Cache" or "Clear Cache"

#### D. Cloudflare Cache (if using)
1. Login to Cloudflare
2. Select your domain
3. Go to "Caching"
4. Click "Purge Everything"

### Step 5: Test the Fix

#### A. Open Browser DevTools
1. Open browser
2. Press `F12` to open DevTools
3. Go to **Network** tab
4. Check "Preserve log"

#### B. Try to Add a Post/Page
1. Go to `https://admin.ikabudkernel.com/wp-admin`
2. Click "Posts" → "Add New" or "Pages" → "Add New"
3. Watch the Network tab

#### C. Verify Success
Look for these in Network tab:

**✅ CORRECT (After Fix):**
```
GET https://admin.ikabudkernel.com/wp-json/wp/v2/settings?_locale=user
Status: 200 OK
```

**❌ WRONG (Before Fix):**
```
OPTIONS https://ikabudkernel.com/wp-json/wp/v2/settings?_locale=user
Status: Failed (CORS error)
```

#### D. Check Console
- **✅ Success**: No CORS errors
- **❌ Still broken**: Red CORS error messages

### Step 6: Verify Version
1. Open the file on Bluehost
2. Check line 5: Should say `Version: 1.2.0`
3. Check line 327: Should have `function ikabud_force_backend_rest_url`

## 🔍 Troubleshooting

### If Still Not Working:

#### 1. Check WordPress Settings
```bash
# Via SSH or phpMyAdmin
mysql -u username -p database_name
SELECT option_value FROM wp_options WHERE option_name IN ('siteurl', 'home');
```

Should show:
- `siteurl`: `https://admin.ikabudkernel.com`
- `home`: `https://ikabudkernel.com`

If wrong, update:
```sql
UPDATE wp_options SET option_value='https://admin.ikabudkernel.com' WHERE option_name='siteurl';
UPDATE wp_options SET option_value='https://ikabudkernel.com' WHERE option_name='home';
```

#### 2. Check wp-config.php
Ensure these lines exist:
```php
define('WP_SITEURL', 'https://admin.ikabudkernel.com');
define('WP_HOME', 'https://ikabudkernel.com');
```

#### 3. Check .htaccess
The `.htaccess` you provided looks good, but ensure it's on the **backend** domain, not frontend.

#### 4. Check File Location
File MUST be in:
```
/path/to/wordpress/wp-content/mu-plugins/ikabud-cors.php
```

NOT in:
- `/wp-content/plugins/` ❌
- `/wp-content/themes/` ❌

#### 5. Re-upload File
Sometimes file upload gets corrupted. Try uploading again.

## 📊 Expected Results

### Before Fix
- ❌ Cannot add posts/pages
- ❌ CORS errors in console
- ❌ Requests to `ikabudkernel.com/wp-json/` fail
- ❌ 404 errors on REST API

### After Fix
- ✅ Can add posts/pages normally
- ✅ No CORS errors
- ✅ Requests to `admin.ikabudkernel.com/wp-json/` succeed
- ✅ 200 OK responses

## 🆘 Still Need Help?

If the fix doesn't work after following all steps:

1. **Check Network Tab**: Screenshot the failed request
2. **Check Console**: Copy the exact error message
3. **Verify File**: Confirm version 1.2.0 is uploaded
4. **Check Domains**: Confirm both domains point to correct directories
5. **Test Direct**: Try accessing `https://admin.ikabudkernel.com/wp-json/` directly in browser

## 📝 Files Reference

**Local Files:**
- Template: `/var/www/html/ikabud-kernel/templates/ikabud-cors.php`
- Instance: `/var/www/html/ikabud-kernel/instances/inst_5ca59a2151e98cd1/wp-content/mu-plugins/ikabud-cors.php`

**Bluehost Files:**
- Live: `/home/username/public_html/wp-content/mu-plugins/ikabud-cors.php`

**Documentation:**
- `ROOT_CAUSE_ANALYSIS.md` - Explains what was wrong
- `QUICK_FIX_GUIDE.md` - Quick reference
- `BLUEHOST_CORS_FIX.md` - Technical details

---
**Ready to deploy!** Follow the steps above and your site should work perfectly.
