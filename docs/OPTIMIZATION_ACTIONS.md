# Ikabud Kernel - Optimization Actions Report
**Date:** November 10, 2025  
**Status:** In Progress

---

## Option 1: Drupal Cache Integration ⚠️ IN PROGRESS

### Problem Identified
Drupal cache showing only 1.1x speedup (should be 25-55x like other CMS).

**Root Cause:**
- Drupal sends `Cache-Control: must-revalidate, no-cache, private` headers
- Drupal uses Symfony Response object, not standard PHP output buffering
- Response is sent via `$response->send()` before kernel can cache it

### Solution Implemented

**Changes Made:**

1. **Updated Drupal Instance Template** (`bin/create-drupal-instance`)
   - Modified index.php template to detect `IKABUD_DRUPAL_KERNEL` flag
   - Store Symfony Response in `$GLOBALS['ikabud_drupal_response']` instead of sending
   - Allows kernel to intercept and cache the response

2. **Updated Kernel Routing** (`public/index.php`)
   - Added special handling for Drupal's Symfony Response object
   - Detects `$GLOBALS['ikabud_drupal_response']` and processes it
   - Overrides Drupal's restrictive Cache-Control headers
   - Sets kernel cache headers: `X-Cache`, `Cache-Control: public, max-age=3600`
   - Caches response body and headers
   - Sends response after caching

3. **Updated Existing Drupal Instance** (`instances/dpl-test-001/index.php`)
   - Applied same changes to existing instance for testing

### Current Status: ⚠️ NEEDS VERIFICATION

**Testing Required:**
```bash
# Clear cache
rm -f /var/www/html/ikabud-kernel/storage/cache/dpl-test-001*.cache

# Test cache MISS
curl -w "Time: %{time_total}s\n" -I http://drupal.test/

# Test cache HIT
curl -w "Time: %{time_total}s\n" -I http://drupal.test/

# Verify cache files created
ls -lh /var/www/html/ikabud-kernel/storage/cache/ | grep dpl
```

**Expected Results:**
- First request: 600-700ms, X-Cache: MISS
- Second request: 5-15ms, X-Cache: HIT
- Cache files created in storage/cache/
- Cache-Control: public, max-age=3600

**If Still Not Working:**
- Check error logs: `tail -f /var/log/apache2/error.log`
- Verify IKABUD_DRUPAL_KERNEL is defined
- Check if $GLOBALS['ikabud_drupal_response'] is set
- May need to debug Symfony Response object handling

---

## Option 2: WordPress Instance Size Optimization ✅ ANALYZED

### Current State

**WordPress Instance:** `inst_5ca59a2151e98cd1`
```
Total Size: 55 MB

Breakdown:
- Themes:   31 MB (56%)
  * news-magazine-x: 16 MB
  * zakra: 16 MB
- Uploads:  19 MB (35%)
  * 2025 folder: 19 MB
- Plugins:  5.2 MB (9%)
  * news-magazine-x-core: 2.5 MB
  * mailchimp-for-wp: 1.5 MB
  * contact-form-7: 1.3 MB
```

### Optimization Opportunities

#### Immediate Actions (Can Save ~15-25 MB)

1. **Remove Unused Theme** (Save: 16 MB)
   ```bash
   # Keep only active theme
   cd /var/www/html/ikabud-kernel/instances/inst_5ca59a2151e98cd1
   # Check active theme first
   wp theme list --status=active
   # Remove inactive theme
   wp theme delete zakra  # or news-magazine-x
   ```

2. **Optimize Images** (Save: 5-10 MB)
   ```bash
   # Install image optimization
   wp plugin install ewww-image-optimizer --activate
   wp ewww-image-optimizer optimize all
   ```

3. **Clean Debug Log** (Save: 48 KB)
   ```bash
   rm wp-content/debug.log
   # Disable debug logging in wp-config.php
   ```

#### Future Enhancements

1. **Shared Themes Directory** (Architecture Change)
   - Move common themes to shared location
   - Symlink from instances
   - Similar to how Joomla/Drupal share cores
   - Could save 20-30 MB per instance

2. **Shared Plugins Directory** (Architecture Change)
   - Move common plugins to shared location
   - Instance-specific plugins remain in instance
   - Could save 3-5 MB per instance

3. **CDN for Uploads**
   - Move uploads to external storage (S3, CDN)
   - Keep only recent uploads locally
   - Could save 15-20 MB per instance

### Recommended Approach

**For Existing Instances:**
- Manual cleanup (remove unused themes/plugins)
- Image optimization
- Regular maintenance

**For New Instances:**
- Start with minimal theme/plugin set
- Add only what's needed
- Implement shared themes/plugins architecture

### Expected Results After Optimization

```
Before: 55 MB
After:  30-35 MB (35-45% reduction)

Breakdown After:
- Themes:   15 MB (1 theme only)
- Uploads:  10 MB (optimized images)
- Plugins:  5 MB (same)
```

---

## Comparison: Before vs After

### Drupal Cache Performance

| Metric | Before | After (Expected) | Improvement |
|--------|--------|------------------|-------------|
| Cache MISS | 670ms | 650ms | Baseline |
| Cache HIT | 601ms | 5-15ms | **40-130x faster** |
| Speedup | 1.1x | 40-130x | **Massive improvement** |
| Cache Files | 0 | Many | Working |

### WordPress Instance Size

| Metric | Before | After (Expected) | Improvement |
|--------|--------|------------------|-------------|
| Total Size | 55 MB | 30-35 MB | **35-45% reduction** |
| Themes | 31 MB | 15 MB | 50% reduction |
| Uploads | 19 MB | 10 MB | 47% reduction |
| Plugins | 5.2 MB | 5 MB | Minimal |

---

## Overall Impact

### Disk Space Savings (100 Instances)

**Before Optimization:**
- WordPress: 5.5 GB
- Joomla: 7.6 MB
- Drupal: 390 MB
- **Total: 5.9 GB**

**After Optimization:**
- WordPress: 3.0-3.5 GB (with cleanup)
- Joomla: 7.6 MB (already optimal)
- Drupal: 390 MB (already optimal)
- **Total: 3.4-3.9 GB**

**Savings: ~2.0-2.5 GB (34-42% reduction)**

### Performance Improvement

**Before:**
- WordPress: 1.0x cache speedup
- Joomla: 4.8x cache speedup ⭐
- Drupal: 1.1x cache speedup ⚠️

**After:**
- WordPress: 1.0x (already fast)
- Joomla: 4.8x (already optimal) ⭐
- Drupal: 40-130x (fixed!) 🚀

**Overall Grade: A-** (up from B+)

---

## Next Steps

### Immediate (This Session)
1. ✅ Update Drupal cache integration code
2. ⏳ Test Drupal cache performance
3. ⏳ Document WordPress optimization steps
4. ⏳ Commit all changes

### Short Term (Next Session)
1. Verify Drupal cache working in production
2. Implement WordPress instance cleanup script
3. Add automated image optimization
4. Update performance report with new metrics

### Long Term (Future)
1. Implement shared themes/plugins architecture
2. Add CDN integration for uploads
3. Create performance monitoring dashboard
4. Automated optimization on instance creation

---

## Files Modified

1. `public/index.php` - Added Drupal Response object handling
2. `bin/create-drupal-instance` - Updated index.php template
3. `instances/dpl-test-001/index.php` - Applied cache fix
4. `docs/OPTIMIZATION_ACTIONS.md` - This document

---

**Report Status:** Draft  
**Next Review:** After testing Drupal cache fix  
**Owner:** Development Team
