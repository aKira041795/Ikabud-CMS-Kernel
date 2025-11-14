# DiSyL Manifest v0.2 Testing Guide

**Version:** 0.2.0  
**Date:** November 14, 2025  
**Status:** Ready for Testing

---

## 🎯 What to Test

DiSyL Manifest v0.2 introduces 10 major features. This guide helps you test all of them.

### Features to Validate

1. ✅ **Expression Filters** - 7 built-in filters
2. ✅ **Component Capabilities** - Compile-time validation
3. ✅ **Component Inheritance** - base_components
4. ✅ **Manifest Caching** - OPcache optimization
5. ✅ **Filter Validation** - Unknown filter detection
6. ✅ **Deprecation Warnings** - Component lifecycle
7. ✅ **Event Hooks** - before/after render
8. ✅ **Transform Pipelines** - Attribute processing
9. ✅ **Preview Modes** - Multiple output modes
10. ✅ **Multi-Renderer** - HTML, JSON, SSR

---

## 🚀 Quick Start

### Option 1: Use Test Template (Recommended)

**Step 1: Create Test Page in WordPress**

1. Go to WordPress admin: `http://brutus.test/wp-admin`
2. Pages → Add New
3. Title: "DiSyL v0.2 Feature Test"
4. Template: Select "DiSyL v0.2 Test"
5. Publish

**Step 2: View the Page**

Visit: `http://brutus.test/disyl-v02-feature-test/`

**What You'll See:**
- 🔥 Filter tests with real WordPress data
- ✅ Component capabilities showcase
- 🧬 Inheritance demonstration
- 🎨 All 7 filters in action
- ⚡ Performance metrics

### Option 2: Manual Testing

Create your own test templates in:
```
instances/wp-brutus-cli/wp-content/themes/disyl-poc/disyl/
```

---

## 📋 Feature Testing Checklist

### 1. Expression Filters

**Test Template:**
```disyl
{ikb_query type="post" limit=1}
    {ikb_text}{item.title | upper}{/ikb_text}
    {ikb_text}{item.author | lower}{/ikb_text}
    {ikb_text}{item.date | date:format="F j, Y"}{/ikb_text}
    {ikb_text}{item.excerpt | truncate:length=100}{/ikb_text}
    {ikb_text}{item.content | escape}{/ikb_text}
{/ikb_query}
```

**Expected Results:**
- ✅ Title in UPPERCASE
- ✅ Author in lowercase
- ✅ Date formatted as "November 14, 2025"
- ✅ Excerpt truncated to 100 chars with "..."
- ✅ Content HTML-escaped

**Check Logs:**
```bash
tail -f instances/wp-brutus-cli/wp-content/debug.log | grep -i filter
```

### 2. Component Capabilities Validation

**Test Invalid Component:**
```disyl
{include template="test"}
    This should fail - include doesn't support children
{/include}
```

**Expected Results:**
- ❌ Compiler error: "Component 'include' does not support children"
- ✅ Error shown at compile time, not runtime

**Check Compilation:**
```bash
tail -f instances/wp-brutus-cli/wp-content/debug.log | grep -i "does not support"
```

### 3. Component Inheritance

**Check Manifest Loading:**
```bash
tail -f instances/wp-brutus-cli/wp-content/debug.log | grep "DiSyL Manifest"
```

**Expected Log:**
```
[Ikabud] DiSyL Manifest v0.2.0 loaded: 3 CMS adapters, 7 filters, caching enabled
```

**Verify Inheritance:**
- ✅ `ikb_query` inherits from `base_query`
- ✅ WordPress-specific attributes added
- ✅ Base capabilities preserved

### 4. Manifest Caching

**First Request:**
```bash
# Clear cache
rm -f storage/cache/manifest.*.compiled

# Make request
curl http://brutus.test/

# Check cache created
ls -lh storage/cache/manifest.*.compiled
```

**Expected Results:**
- ✅ Cache file created: `manifest.{hash}.compiled`
- ✅ First load: ~5ms
- ✅ Cached load: ~0.1ms (50x faster)

**Verify Cache:**
```bash
cat storage/cache/manifest.*.compiled | head -20
```

Should see PHP array with manifest data.

### 5. Filter Validation

**Test Unknown Filter:**
```disyl
{ikb_text title="{item.title | invalidfilter}" /}
```

**Expected Results:**
- ❌ Compiler error: "Unknown filter 'invalidfilter' in attribute 'title'"
- ✅ Error at compile time
- ✅ Template doesn't render

**Check Logs:**
```bash
tail -f instances/wp-brutus-cli/wp-content/debug.log | grep "Unknown filter"
```

### 6. Deprecation Warnings

**Add Deprecated Component to Manifest:**
```json
{
  "deprecated": {
    "ikb_oldcard": {
      "message": "Use ikb_card instead",
      "remove_in_version": "0.4.0",
      "replacement": "ikb_card"
    }
  }
}
```

**Test Template:**
```disyl
{ikb_oldcard}Content{/ikb_oldcard}
```

**Expected Results:**
- ⚠️  Warning: "Component 'ikb_oldcard' is deprecated. Use 'ikb_card' instead."
- ✅ Still renders (non-blocking)
- ✅ Warning logged

### 7. Event Hooks

**Add Hook in functions.php:**
```php
add_filter('disyl_before_render', function($output) {
    error_log('[DiSyL Hook] Before render: ' . strlen($output) . ' bytes');
    return $output;
});

add_filter('disyl_after_render', function($output) {
    error_log('[DiSyL Hook] After render: ' . strlen($output) . ' bytes');
    return $output;
});
```

**Expected Logs:**
```
[DiSyL Hook] Before render: 1234 bytes
[DiSyL Hook] After render: 1234 bytes
```

### 8. Transform Pipelines

**Check Attribute Processing:**
```disyl
{ikb_section bg="#667eea" padding="large"}
    Content
{/ikb_section}
```

**Expected HTML:**
```html
<section class="ikb-section" style="background: #667eea; padding: 4rem;">
    Content
</section>
```

**Pipeline Steps:**
1. `sanitize_color` → Validates color
2. `apply_style` → Formats as CSS

### 9. Preview Modes

**Test Static Mode:**
```php
$renderer->setMode('static');
$output = $renderer->render($ast);
```

**Expected:**
- ✅ No CMS queries
- ✅ Static HTML only
- ✅ Fast rendering

### 10. Multi-Renderer Support

**Test JSON Output:**
```php
$renderer->setMode('json');
$output = $renderer->render($ast);
```

**Expected:**
```json
{
  "type": "section",
  "children": [...]
}
```

---

## 🐛 Debugging

### Enable Debug Mode

**In wp-config.php:**
```php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);
```

### Check Logs

**DiSyL Logs:**
```bash
tail -f instances/wp-brutus-cli/wp-content/debug.log | grep -i disyl
```

**Manifest Logs:**
```bash
tail -f instances/wp-brutus-cli/wp-content/debug.log | grep -i manifest
```

**Filter Logs:**
```bash
tail -f instances/wp-brutus-cli/wp-content/debug.log | grep -i filter
```

### Common Issues

**Issue: Filters not working**
- Check: ManifestLoader path correct
- Check: Filters defined in manifest
- Check: Parser recognizes PIPE token

**Issue: Capabilities validation failing**
- Check: Compiler using v0.2
- Check: Manifest loaded successfully
- Check: CMS type set correctly

**Issue: Cache not working**
- Check: storage/cache/ writable
- Check: OPcache enabled
- Check: Hash generation working

---

## 📊 Performance Benchmarks

### Expected Performance

| Metric | Target | Acceptable | Poor |
|--------|--------|------------|------|
| **Manifest Load (cached)** | <0.1ms | <1ms | >5ms |
| **Filter Application** | <0.01ms | <0.1ms | >1ms |
| **Compilation** | <5ms | <20ms | >50ms |
| **Full Page Render** | <100ms | <500ms | >1s |

### Benchmark Commands

**Manifest Loading:**
```bash
ab -n 100 -c 10 http://brutus.test/
```

**Filter Performance:**
```php
$start = microtime(true);
ManifestLoader::applyFilter('upper', 'test');
$time = (microtime(true) - $start) * 1000;
echo "Filter time: {$time}ms\n";
```

---

## ✅ Success Criteria

### All Tests Pass When:

1. ✅ All 7 filters render correctly
2. ✅ Invalid components caught at compile time
3. ✅ Inheritance resolves properly
4. ✅ Cache files generated
5. ✅ Unknown filters detected
6. ✅ Deprecation warnings shown
7. ✅ Event hooks execute
8. ✅ Transform pipelines work
9. ✅ Preview modes render
10. ✅ Multi-renderer outputs correct format

### Performance Targets:

- ✅ Manifest loads in <0.1ms (cached)
- ✅ Filters apply in <0.01ms
- ✅ Compilation in <5ms
- ✅ Full page in <100ms

---

## 📝 Test Results Template

```markdown
# DiSyL v0.2 Test Results

**Date:** YYYY-MM-DD
**Tester:** Your Name
**Environment:** WordPress 6.x / PHP 8.x

## Feature Tests

- [ ] Expression Filters: PASS/FAIL
- [ ] Capabilities Validation: PASS/FAIL
- [ ] Component Inheritance: PASS/FAIL
- [ ] Manifest Caching: PASS/FAIL
- [ ] Filter Validation: PASS/FAIL
- [ ] Deprecation Warnings: PASS/FAIL
- [ ] Event Hooks: PASS/FAIL
- [ ] Transform Pipelines: PASS/FAIL
- [ ] Preview Modes: PASS/FAIL
- [ ] Multi-Renderer: PASS/FAIL

## Performance

- Manifest Load (cached): ___ms
- Filter Application: ___ms
- Compilation: ___ms
- Full Page Render: ___ms

## Issues Found

1. [Issue description]
2. [Issue description]

## Notes

[Additional observations]
```

---

## 🚀 Next Steps

After successful testing:

1. ✅ Document any issues found
2. ✅ Create performance benchmarks
3. ✅ Update documentation
4. ✅ Prepare for production release
5. ✅ Community feedback

---

**Happy Testing!** 🎉

For issues or questions, check:
- [Migration Guide](DISYL_MANIFEST_V0.2_MIGRATION.md)
- [Manifest Architecture](DISYL_MANIFEST_ARCHITECTURE.md)
- [Complete Guide](DISYL_COMPLETE_GUIDE.md)
