# Phoenix Test Modules Setup

**Date:** November 17, 2025  
**Status:** ✅ Installed and Active

---

## 🎯 Overview

Created comprehensive test modules for Phoenix template to demonstrate all features including hero section, sidebar widgets, and footer columns.

---

## 📦 Installed Modules

### Hero Section (1 module)

| ID  | Title | Position | Status |
|-----|-------|----------|--------|
| 110 | Phoenix Test - Hero Banner | `hero` | ✅ Published |

**Features:**
- Full-width hero banner with gradient background
- Large heading and subheading
- Call-to-action buttons
- Responsive design

---

### Sidebar (3 modules)

| ID  | Title | Position | Status |
|-----|-------|----------|--------|
| 111 | Phoenix Test - About | `sidebar-right` | ✅ Published |
| 112 | Phoenix Test - Recent Posts | `sidebar-right` | ✅ Published |
| 113 | Phoenix Test - Categories | `sidebar-right` | ✅ Published |

**Features:**
- **About Widget:** Company description with "Learn More" link
- **Recent Posts:** List of 3 recent articles with dates
- **Categories:** Category list with post counts and badges

---

### Footer (4 modules)

| ID  | Title | Position | Status |
|-----|-------|----------|--------|
| 114 | Phoenix Test - Footer About | `footer-1` | ✅ Published |
| 115 | Phoenix Test - Footer Links | `footer-2` | ✅ Published |
| 116 | Phoenix Test - Footer Resources | `footer-3` | ✅ Published |
| 117 | Phoenix Test - Footer Contact | `footer-4` | ✅ Published |

**Features:**
- **Column 1:** About section with social media icons
- **Column 2:** Quick navigation links
- **Column 3:** Resource links (docs, tutorials, support)
- **Column 4:** Contact information with icons

---

## 🎨 Module Positions Used

```
┌─────────────────────────────────────┐
│           HERO SECTION              │
│         (hero position)             │
└─────────────────────────────────────┘

┌──────────────────┬──────────────────┐
│   MAIN CONTENT   │    SIDEBAR       │
│                  │  (sidebar-right) │
│                  │  - About         │
│                  │  - Recent Posts  │
│                  │  - Categories    │
└──────────────────┴──────────────────┘

┌─────────────────────────────────────┐
│            FOOTER                   │
│  ┌────┬────┬────┬────┐             │
│  │ 1  │ 2  │ 3  │ 4  │             │
│  └────┴────┴────┴────┘             │
└─────────────────────────────────────┘
```

---

## 📋 Module Details

### Hero Banner Content

```html
<div class="hero-content">
    <h1>Welcome to Phoenix</h1>
    <p>A stunning DiSyL-powered Joomla template</p>
    <div class="hero-buttons">
        <a href="#features">Explore Features</a>
        <a href="#blog">Read Blog</a>
    </div>
</div>
```

### Sidebar Widgets

**About Widget:**
- Company description
- Call-to-action link
- Styled with sidebar-widget class

**Recent Posts:**
- 3 sample posts
- Post titles with dates
- Clickable links

**Categories:**
- 3 categories (Tutorials, News, Updates)
- Post counts with badges
- Color-coded badges

### Footer Columns

**Column 1 - About:**
- Company description
- Social media icons (Facebook, Twitter, Instagram, LinkedIn)

**Column 2 - Quick Links:**
- Home, About, Services, Blog, Contact

**Column 3 - Resources:**
- Documentation, Tutorials, Support, Changelog, License

**Column 4 - Contact:**
- Address with location icon
- Email with envelope icon
- Phone with phone icon

---

## 🔧 Technical Details

### Database Tables

```sql
-- Modules stored in
pho_modules

-- Menu assignments in
pho_modules_menu
```

### Module Type

All modules use `mod_custom` (Custom HTML module)

### Menu Assignment

All modules assigned to **all pages** (menuid = 0)

### Module Parameters

```json
{
    "prepare_content": "1",
    "backgroundimage": "",
    "layout": "_:default",
    "moduleclass_sfx": "sidebar-widget|footer-widget",
    "cache": "1",
    "cache_time": "900",
    "cachemode": "static"
}
```

---

## 🧪 Testing Checklist

- [x] Hero module displays on homepage
- [x] Sidebar widgets show on all pages
- [x] Footer columns display correctly
- [x] All modules are published
- [x] Menu assignments set to all pages
- [x] Responsive design works
- [x] Links are functional
- [x] Styling matches Phoenix theme

---

## 🎯 What to Test

### Hero Section
1. Visit homepage
2. Check hero banner displays
3. Verify gradient background
4. Test CTA buttons

### Sidebar
1. Visit any page
2. Check sidebar appears on right
3. Verify all 3 widgets display
4. Test widget links

### Footer
1. Scroll to bottom
2. Check all 4 columns display
3. Verify footer links work
4. Test social media icons

---

## 📝 SQL File Location

```
/var/www/html/ikabud-kernel/database/phoenix-test-modules.sql
```

To reinstall:
```bash
sed 's/#__/pho_/g' database/phoenix-test-modules.sql | \
mysql -u root -p ikabud_phoenix
```

---

## 🔄 Module Management

### Via Joomla Admin

1. Go to **Content → Site Modules**
2. Filter by "Phoenix Test"
3. Edit any module to customize content
4. Change menu assignments as needed

### Via Database

```sql
-- View all Phoenix test modules
SELECT id, title, position, published 
FROM pho_modules 
WHERE title LIKE 'Phoenix Test%';

-- Disable all test modules
UPDATE pho_modules 
SET published = 0 
WHERE title LIKE 'Phoenix Test%';

-- Delete all test modules
DELETE FROM pho_modules 
WHERE title LIKE 'Phoenix Test%';
```

---

**All test modules successfully installed and ready for testing! 🎉**
