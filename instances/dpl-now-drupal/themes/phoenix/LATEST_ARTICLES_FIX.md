# Latest Articles Feature Fix

## Changes Made

### 1. Enhanced `ikb_query` Component
**File**: `/var/www/html/ikabud-kernel/kernel/DiSyL/Renderers/DrupalRenderer.php`

Added support for `{empty}` block in the `ikb_query` component, similar to how `{if}` handles `{else}` blocks.

**How it works**:
- The component now splits children into two groups: query results and empty state
- When no articles are found, it renders the `{empty}` block instead of returning an HTML comment
- When articles exist, it renders each article using the query children

### 2. Updated Home Template
**File**: `/var/www/html/ikabud-kernel/instances/dpl-now-drupal/themes/phoenix/disyl/home.disyl`

**Changes**:
- Moved `<div class="post-grid">` wrapper outside the `{ikb_query}` loop
- Added `{empty}` block to handle the "no articles" state
- Removed the `var="articles"` attribute (not needed)

**Before**:
```disyl
<div class="post-grid">
    {ikb_query type="post" limit=6}
        <article>...</article>
    {/ikb_query}
</div>
<div class="text-center mt-large">
    <p>No articles found...</p>  <!-- Always shown -->
</div>
```

**After**:
```disyl
<div class="post-grid">
    {ikb_query type="post" limit=6}
        <article>...</article>
    {empty}
        <div class="text-center mt-large">
            <p>No articles found...</p>  <!-- Only shown when empty -->
        </div>
    {/ikb_query}
</div>
```

## How to Test

### 1. Clear Drupal Cache
```bash
cd /var/www/html/ikabud-kernel/instances/dpl-now-drupal
drush cr
```

### 2. Create Test Articles
Navigate to: `http://your-site.test/node/add/article`

Create at least 2-3 articles with:
- Title
- Body content
- Optional: Featured image (field_image)

### 3. View Homepage
Navigate to: `http://your-site.test/`

**Expected Results**:
- If articles exist: They should display in a grid layout (up to 6 articles)
- If no articles exist: "No articles found" message with link to create first article
- Articles should show: thumbnail (if available), date, author, title, excerpt, and "Read More" link

### 4. Debug Output
The DiSyL integration saves debug output to:
```
/var/www/html/ikabud-kernel/disyl_debug_output.html
```

You can inspect this file to see the raw HTML output from the DiSyL renderer.

## Query Details

The `ikb_query` component queries Drupal nodes with these parameters:
- **Type**: `article` (mapped from `post`)
- **Status**: Published only (`status = 1`)
- **Order**: By creation date, descending (newest first)
- **Limit**: 6 articles
- **Access Check**: Enabled (respects Drupal permissions)

## Article Data Available

Each article in the loop has access to:
- `item.id` - Node ID
- `item.title` - Article title
- `item.url` - Article URL
- `item.date` - Creation timestamp
- `item.changed` - Last modified timestamp
- `item.author` - Author display name
- `item.author_id` - Author user ID
- `item.type` - Content type (article)
- `item.published` - Published status
- `item.thumbnail` - Featured image URL (if field_image exists)
- `item.excerpt` - Plain text excerpt from body
- `item.content` - Full HTML body content

## Troubleshooting

### Articles Not Showing
1. **Check if articles are published**: Only published articles appear
2. **Clear cache**: Run `drush cr`
3. **Check permissions**: Ensure anonymous users can view articles
4. **Check debug output**: Look at `/var/www/html/ikabud-kernel/disyl_debug_output.html`

### Empty State Always Showing
1. **Verify articles exist**: Check `/admin/content`
2. **Check article type**: Must be "article" content type
3. **Check query parameters**: Verify `type="post"` in template

### Styling Issues
1. **Check CSS**: Ensure Phoenix theme CSS is loaded
2. **Check grid class**: `.post-grid` should have CSS grid/flexbox styles
3. **Check card class**: `.post-card` should have proper styling
