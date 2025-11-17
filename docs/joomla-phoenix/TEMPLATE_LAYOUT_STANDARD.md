# Phoenix Template Layout Standard

**Date:** November 17, 2025  
**Status:** ✅ Implemented Across All Templates

---

## 🎯 Standardized Layout Pattern

All Phoenix templates now follow a consistent structure for maintainability and predictability.

### Core Structure

```disyl
{!-- Phoenix Theme v2 - [Template Name] (Joomla-Native) --}
{ikb_include template="components/header.disyl" /}

{!-- Breadcrumbs Module Position --}
{joomla_module position="breadcrumbs" style="none" /}

{!-- Main Content Section --}
{ikb_section type="[section-type]" padding="xlarge"}
    {ikb_container size="xlarge"}
        <div class="content-layout">
            {!-- Main Content --}
            <main class="main-content">
                <!-- Template-specific content -->
            </main>
            
            {!-- Sidebar (Joomla Module Positions) --}
            {ikb_include template="components/sidebar.disyl" /}
        </div>
    {/ikb_container}
{/ikb_section}

{ikb_include template="components/footer.disyl" /}
```

---

## 📋 Template Consistency

### ✅ All Templates Updated

1. **home.disyl** - Homepage (no sidebar, multiple sections)
2. **single.disyl** - Single article/post view
3. **category.disyl** - Category archive view
4. **page.disyl** - Static page view
5. **blog.disyl** - Blog archive view
6. **archive.disyl** - General archive view

### Common Elements

**Every template includes:**
- ✅ Header component via `{ikb_include}`
- ✅ Breadcrumbs module position
- ✅ Consistent `{ikb_section}` wrapper
- ✅ Consistent `{ikb_container}` sizing
- ✅ Standardized `.content-layout` div
- ✅ Semantic `<main class="main-content">` wrapper
- ✅ Sidebar component via `{ikb_include}`
- ✅ Footer component via `{ikb_include}`

---

## 🎨 Layout Classes

### `.content-layout`
Main layout wrapper for content + sidebar grid

**Usage:**
```css
.content-layout {
    display: grid;
    grid-template-columns: 1fr 300px;
    gap: 2rem;
}
```

### `.main-content`
Semantic main content area

**Usage:**
```html
<main class="main-content">
    <!-- Article, posts, page content -->
</main>
```

---

## 📐 Section Types

### Standard Section Types
- `post-single` - Single article view
- `category-header` - Category page header
- `category-posts` - Category posts grid
- `page` - Static page content
- `blog-archive` - Blog listing
- `archive-content` - Archive listing

### Container Sizes
- `size="large"` - Headers, narrow content (max-width: 1200px)
- `size="xlarge"` - Main content areas (max-width: 1400px)

### Padding Options
- `padding="large"` - Headers (3rem)
- `padding="xlarge"` - Main sections (4rem)

---

## 🔄 Before vs After

### ❌ Before (Inconsistent)

**category.disyl:**
```disyl
<section style="padding: 4rem 0;">
    <div class="ikb-container ikb-container-lg">
        <div class="archive-layout">
            <div>
                <!-- content -->
            </div>
            {ikb_include template="components/sidebar.disyl"}
        </div>
    </div>
</section>
```

### ✅ After (Standardized)

**category.disyl:**
```disyl
{ikb_section type="category-posts" padding="xlarge"}
    {ikb_container size="xlarge"}
        <div class="content-layout">
            <main class="main-content">
                <!-- content -->
            </main>
            {ikb_include template="components/sidebar.disyl" /}
        </div>
    {/ikb_container}
{/ikb_section}
```

---

## ✨ Benefits

1. **Consistency** - All templates follow same pattern
2. **Maintainability** - Easy to update styles globally
3. **Semantic HTML** - Proper `<main>` tags for accessibility
4. **DiSyL Components** - Using framework components properly
5. **Joomla Integration** - Consistent breadcrumbs and module positions
6. **Responsive** - Grid layout works across devices

---

## 📝 Template Checklist

When creating new templates, ensure:

- [ ] Uses `{ikb_section}` with proper `type` attribute
- [ ] Uses `{ikb_container}` with appropriate `size`
- [ ] Has `.content-layout` wrapper div
- [ ] Has semantic `<main class="main-content">` tag
- [ ] Includes breadcrumbs module position
- [ ] Includes sidebar component
- [ ] Uses consistent padding values
- [ ] Follows header → content → footer structure
- [ ] Has proper HTML heading hierarchy (h1, h2, etc.)

---

**All templates now standardized and production-ready! 🎉**
