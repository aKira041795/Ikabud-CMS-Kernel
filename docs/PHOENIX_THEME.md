# Phoenix Theme - Universal DiSyL Theme for WordPress, Joomla & Drupal

**Version**: 2.0  
**Status**: ✅ Production Ready  
**DiSyL Version**: 0.5.0 Beta

---

## 🎯 Overview

Phoenix is a **universal theme** powered by DiSyL (Declarative Ikabud Syntax Language) that works seamlessly across WordPress, Joomla, and Drupal. Write your theme templates once using DiSyL syntax, and deploy them across all three major CMS platforms without modification.

### Live Demo Sites

- **WordPress**: https://wpdemo.zdnorte.net/
- **Joomla**: https://itsolutions.zdnorte.net/
- **Drupal**: https://drupaldemo.zdnorte.net/

All three sites use the **exact same DiSyL templates** with platform-specific renderers.

---

## ✨ Key Features

### Universal Compatibility
- ✅ **One Codebase** - Same `.disyl` templates work across all CMS platforms
- ✅ **Platform-Specific Renderers** - Optimized rendering for each CMS
- ✅ **Shared Components** - Reusable components across platforms
- ✅ **Unified Syntax** - Learn once, deploy everywhere

### Modern Design
- 🎨 **Gradient-Rich** - Modern gradient backgrounds and effects
- 📱 **Fully Responsive** - Mobile-first design approach
- ⚡ **Performance Optimized** - Fast loading with lazy loading support
- 🎯 **Accessibility** - WCAG 2.1 compliant

### DiSyL Powered
- 🔧 **Component-Based** - Modular, reusable components
- 🔄 **Dynamic Content** - Query and loop through content
- 🎨 **Filter System** - Transform data with filters
- 🔀 **Conditional Rendering** - Show/hide content based on conditions
- 📦 **Template Includes** - Compose templates from components

---

## 🏗️ Architecture

### File Structure

```
phoenix/
├── assets/
│   ├── css/
│   │   ├── style.css              # Main theme styles
│   │   ├── disyl-components.css   # DiSyL component styles
│   │   └── animations.css         # Animation effects
│   ├── js/
│   │   └── theme.js               # Theme JavaScript
│   └── images/
│       ├── slide-1.png            # Slider images
│       ├── slide-2.png
│       └── slide-3.png
├── disyl/
│   ├── components/
│   │   ├── header.disyl           # Site header
│   │   ├── footer.disyl           # Site footer
│   │   ├── sidebar.disyl          # Sidebar widget area
│   │   ├── slider.disyl           # Homepage slider
│   │   └── comments.disyl         # Comment section
│   ├── home.disyl                 # Homepage template
│   ├── single.disyl               # Single post/article template
│   ├── page.disyl                 # Page template
│   ├── archive.disyl              # Archive template
│   ├── blog.disyl                 # Blog listing
│   ├── category.disyl             # Category archive
│   ├── search.disyl               # Search results
│   └── 404.disyl                  # 404 error page
└── includes/
    └── disyl-integration.php      # CMS integration layer
```

### Platform-Specific Files

#### WordPress
```
wp-content/themes/phoenix/
├── functions.php                  # Theme setup and hooks
├── style.css                      # Theme metadata
├── index.php                      # Main template file
└── [shared files above]
```

#### Joomla
```
templates/phoenix/
├── index.php                      # Main template file
├── templateDetails.xml            # Theme metadata
├── includes/disyl-integration.php # Joomla integration
└── [shared files above]
```

#### Drupal
```
themes/phoenix/
├── phoenix.info.yml               # Theme metadata
├── phoenix.libraries.yml          # Asset libraries
├── phoenix.theme                  # Theme hooks
├── templates/page.html.twig       # Page template
├── includes/disyl-integration.php # Drupal integration
└── [shared files above]
```

---

## 🎨 DiSyL Template Examples

### Homepage Template (`disyl/home.disyl`)

```disyl
{!-- Phoenix Theme - Homepage Template --}
{ikb_include template="components/header.disyl" /}

{!-- Hero Section --}
{ikb_section type="hero" class="hero-section" padding="large"}
    {ikb_container size="xlarge"}
        <div class="hero-content">
            {ikb_text size="5xl" weight="bold" class="gradient-text"}
                <h1>Welcome to {site.name}</h1>
            {/ikb_text}
            
            {ikb_text size="xl" class="hero-subtitle"}
                <p>{site.description}</p>
            {/ikb_text}
            
            <div class="hero-buttons">
                <a href="#features" class="btn btn-primary">Explore Features</a>
                <a href="#blog" class="btn btn-secondary">Read Blog</a>
            </div>
        </div>
    {/ikb_container}
{/ikb_section}

{!-- Slider Section --}
{ikb_include template="components/slider.disyl" /}

{!-- Features Section --}
{ikb_section type="features" id="features" padding="large"}
    {ikb_container size="xlarge"}
        <div class="section-header">
            {ikb_text size="3xl" weight="bold" class="gradient-text"}
                <h2>Powerful Features</h2>
            {/ikb_text}
        </div>
        
        <div class="card-grid">
            <div class="feature-card">
                <div class="feature-icon">⚡</div>
                <h3>Lightning Fast</h3>
                <p>Optimized for performance with lazy loading and caching</p>
            </div>
            
            <div class="feature-card">
                <div class="feature-icon">🎨</div>
                <h3>Beautiful Design</h3>
                <p>Modern gradient-rich design with smooth animations</p>
            </div>
            
            <div class="feature-card">
                <div class="feature-icon">📱</div>
                <h3>Fully Responsive</h3>
                <p>Perfect on all devices from mobile to desktop</p>
            </div>
        </div>
    {/ikb_container}
{/ikb_section}

{!-- Latest Blog Posts --}
{ikb_section type="blog" id="blog" padding="xlarge"}
    {ikb_container size="xlarge"}
        <div class="section-header">
            {ikb_text size="3xl" weight="bold"}
                <h2>Latest Posts</h2>
            {/ikb_text}
        </div>
        
        <div class="post-grid">
            {ikb_query type="post" limit=6}
                <article class="post-card">
                    {if condition="item.thumbnail"}
                        <a href="{item.url | esc_url}">
                            {ikb_image 
                                src="{item.thumbnail | esc_url}"
                                alt="{item.title | esc_attr}"
                                class="post-thumbnail"
                                lazy=true
                            /}
                        </a>
                    {/if}
                    
                    <div class="post-content">
                        <div class="post-meta">
                            <span class="post-date">{item.date | date:format="M j, Y"}</span>
                            <span class="post-author">{item.author | esc_html}</span>
                        </div>
                        
                        {ikb_text size="xl" weight="semibold"}
                            <h3><a href="{item.url | esc_url}">{item.title | esc_html}</a></h3>
                        {/ikb_text}
                        
                        {ikb_text class="post-excerpt"}
                            <p>{item.excerpt | strip_tags | truncate:length=150,append="..."}</p>
                        {/ikb_text}
                        
                        <a href="{item.url | esc_url}" class="read-more">Read More →</a>
                    </div>
                </article>
            {/ikb_query}
        </div>
    {/ikb_container}
{/ikb_section}

{!-- Call to Action --}
{ikb_section type="cta" class="cta-section" padding="xlarge"}
    {ikb_container size="large"}
        <div class="cta-content">
            {ikb_text size="4xl" weight="bold"}
                <h2>Ready to Get Started?</h2>
            {/ikb_text}
            
            {ikb_text size="lg"}
                <p>Join thousands of users who trust Phoenix for their websites</p>
            {/ikb_text}
            
            <a href="/contact" class="btn btn-primary btn-large">Get Started Today</a>
        </div>
    {/ikb_container}
{/ikb_section}

{ikb_include template="components/footer.disyl" /}
```

### Header Component (`disyl/components/header.disyl`)

```disyl
{!-- Phoenix Theme - Header Component --}
<header class="site-header sticky-header">
    <div class="header-container">
        {!-- Site Branding --}
        <div class="site-branding">
            <a href="{site.url | esc_url}" class="site-logo">
                <span class="site-name">{site.name | esc_html}</span>
            </a>
        </div>
        
        {!-- Mobile Menu Toggle --}
        <button class="menu-toggle" aria-label="Toggle navigation">
            <span class="menu-icon">☰</span>
        </button>
        
        {!-- Primary Navigation --}
        <nav class="main-nav" role="navigation">
            <ul class="nav-menu">
                {for item in menu.primary}
                    <li class="menu-item {if condition="item.active"}active{/if}">
                        <a href="{item.url | esc_url}">{item.title | esc_html}</a>
                    </li>
                {/for}
            </ul>
        </nav>
        
        {!-- Search --}
        <div class="header-search">
            <button class="search-toggle" aria-label="Toggle search">
                <span class="search-icon">🔍</span>
            </button>
        </div>
    </div>
</header>
```

### Single Post Template (`disyl/single.disyl`)

```disyl
{!-- Phoenix Theme - Single Post Template --}
{ikb_include template="components/header.disyl" /}

<article class="single-post">
    {ikb_container size="large"}
        {!-- Post Header --}
        <header class="post-header">
            {ikb_text size="4xl" weight="bold" class="post-title"}
                <h1>{post.title | esc_html}</h1>
            {/ikb_text}
            
            <div class="post-meta">
                <span class="post-date">{post.date | date:format="F j, Y"}</span>
                <span class="post-author">By {post.author | esc_html}</span>
                {if condition="post.category"}
                    <span class="post-category">
                        in <a href="{post.category_url | esc_url}">{post.category | esc_html}</a>
                    </span>
                {/if}
            </div>
        </header>
        
        {!-- Featured Image --}
        {if condition="post.thumbnail"}
            {ikb_image 
                src="{post.thumbnail | esc_url}"
                alt="{post.title | esc_attr}"
                class="featured-image"
            /}
        {/if}
        
        {!-- Post Content --}
        <div class="post-content">
            {post.content}
        </div>
        
        {!-- Post Tags --}
        {if condition="post.tags"}
            <div class="post-tags">
                <strong>Tags:</strong>
                {for tag in post.tags}
                    <a href="{tag.url | esc_url}" class="tag">{tag.name | esc_html}</a>
                {/for}
            </div>
        {/if}
        
        {!-- Comments --}
        {if condition="post.comments_open"}
            {ikb_include template="components/comments.disyl" /}
        {/if}
    {/ikb_container}
</article>

{ikb_include template="components/footer.disyl" /}
```

---

## 🔧 DiSyL Components

### Core Components

#### `ikb_section`
Creates semantic page sections with padding and styling.

```disyl
{ikb_section type="hero" class="custom-class" padding="large"}
    Content here
{/ikb_section}
```

**Attributes:**
- `type` - Section type (hero, features, blog, cta)
- `class` - Additional CSS classes
- `padding` - Padding size (small, medium, large, xlarge)
- `id` - Section ID for anchors

#### `ikb_container`
Responsive container with max-width constraints.

```disyl
{ikb_container size="large"}
    Content here
{/ikb_container}
```

**Attributes:**
- `size` - Container size (small, medium, large, xlarge, full)
- `class` - Additional CSS classes

#### `ikb_text`
Typography component with size and weight control.

```disyl
{ikb_text size="3xl" weight="bold" class="gradient-text"}
    <h2>Heading Text</h2>
{/ikb_text}
```

**Attributes:**
- `size` - Text size (sm, base, lg, xl, 2xl, 3xl, 4xl, 5xl)
- `weight` - Font weight (normal, medium, semibold, bold)
- `class` - Additional CSS classes

#### `ikb_image`
Optimized image rendering with lazy loading.

```disyl
{ikb_image 
    src="{image_url | esc_url}"
    alt="{image_alt | esc_attr}"
    class="custom-class"
    lazy=true
/}
```

**Attributes:**
- `src` - Image URL
- `alt` - Alt text for accessibility
- `class` - Additional CSS classes
- `lazy` - Enable lazy loading (true/false)

#### `ikb_include`
Include other DiSyL templates.

```disyl
{ikb_include template="components/header.disyl" /}
```

**Attributes:**
- `template` - Path to template file (relative to disyl directory)

#### `ikb_query`
Query and loop through content.

```disyl
{ikb_query type="post" limit=6}
    <div class="item">
        <h3>{item.title | esc_html}</h3>
        <p>{item.excerpt | truncate:length=150}</p>
    </div>
{/ikb_query}
```

**Attributes:**
- `type` - Content type (post, page, article, etc.)
- `limit` - Maximum number of items
- `category` - Filter by category
- `tag` - Filter by tag

**Context Variables:**
- `item.id` - Content ID
- `item.title` - Content title
- `item.url` - Content URL
- `item.date` - Publication date
- `item.author` - Author name
- `item.excerpt` - Content excerpt
- `item.thumbnail` - Featured image URL
- `item.category` - Category name
- `item.tags` - Array of tags

---

## 🔄 DiSyL Filters

### Security Filters

#### `esc_html`
Escape HTML entities for safe output.

```disyl
{post.title | esc_html}
```

#### `esc_url`
Sanitize URLs.

```disyl
<a href="{post.url | esc_url}">Link</a>
```

#### `esc_attr`
Escape HTML attributes.

```disyl
<img alt="{post.title | esc_attr}">
```

### Text Filters

#### `truncate`
Truncate text to specified length.

```disyl
{post.excerpt | truncate:length=150,append="..."}
```

**Parameters:**
- `length` - Maximum length (default: 100)
- `append` - String to append (default: "...")

#### `strip_tags`
Remove HTML tags from text.

```disyl
{post.content | strip_tags}
```

#### `date`
Format dates with custom format.

```disyl
{post.date | date:format="F j, Y"}
{post.date | date:format="M j, Y"}
```

**Parameters:**
- `format` - PHP date format string

#### `default`
Provide fallback value if empty.

```disyl
{post.title | default:"Untitled Post"}
```

### Filter Chaining

Combine multiple filters:

```disyl
{post.excerpt | strip_tags | truncate:length=200 | esc_html}
```

---

## 🎯 Conditional Rendering

### Simple Conditionals

```disyl
{if condition="post.thumbnail"}
    <img src="{post.thumbnail | esc_url}">
{/if}
```

### Comparison Operators

```disyl
{if condition="post.comment_count > 0"}
    <span>{post.comment_count} Comments</span>
{/if}
```

**Supported Operators:**
- `>` - Greater than
- `<` - Less than
- `>=` - Greater than or equal
- `<=` - Less than or equal
- `==` - Equal
- `!=` - Not equal

### Logical Operators

```disyl
{if condition="post.published && post.featured"}
    <span class="badge">Featured</span>
{/if}
```

**Supported Operators:**
- `&&` - AND
- `||` - OR

---

## 🚀 Installation

### WordPress

1. Copy theme to WordPress themes directory:
   ```bash
   cp -r phoenix /var/www/html/wp-content/themes/
   ```

2. Activate via WordPress admin:
   - Navigate to Appearance → Themes
   - Click "Activate" on Phoenix theme

3. Configure theme settings:
   - Navigate to Appearance → Customize
   - Configure colors, fonts, and layout

### Joomla

1. Copy template to Joomla templates directory:
   ```bash
   cp -r phoenix /var/www/html/templates/
   ```

2. Install via Joomla admin:
   - Navigate to Extensions → Templates
   - Click "Install from Folder"
   - Select Phoenix template

3. Set as default template:
   - Navigate to Extensions → Templates → Styles
   - Click on Phoenix and set as default

### Drupal

1. Copy theme to Drupal themes directory:
   ```bash
   cp -r phoenix /var/www/html/themes/
   ```

2. Enable via Drush:
   ```bash
   drush theme:enable phoenix
   drush config-set system.theme default phoenix
   ```

3. Or enable via admin UI:
   - Navigate to Appearance
   - Click "Install and set as default" for Phoenix

---

## 📊 Performance

- **DiSyL Compilation**: ~0.2ms
- **Page Load**: < 1 second
- **Lighthouse Score**: 95+
- **Mobile Performance**: Optimized
- **Lazy Loading**: Enabled for images
- **Cache Support**: Full caching support

---

## 🎓 Best Practices

1. **Always escape output** - Use `esc_html`, `esc_url`, `esc_attr`
2. **Limit queries** - Use `limit` attribute to control result count
3. **Use lazy loading** - Enable `lazy=true` for images
4. **Component reuse** - Create reusable components in `components/`
5. **Filter chains** - Combine filters for data transformation
6. **Semantic HTML** - Use appropriate HTML5 elements
7. **Accessibility** - Include alt text, ARIA labels
8. **Performance** - Minimize nested queries

---

## 📝 License

MIT License - See LICENSE file for details

---

## 🙏 Credits

**Theme**: Phoenix v2.0  
**Engine**: DiSyL (Declarative Ikabud Syntax Language)  
**Compatible**: WordPress, Joomla, Drupal  
**Status**: Production Ready ✅

---

*Last Updated: November 18, 2025*
