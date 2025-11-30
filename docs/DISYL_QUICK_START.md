# DiSyL Quick Start Guide

**Version:** 0.5.1  
**Last Updated:** November 30, 2025

---

## What is DiSyL?

**DiSyL** (Declarative Ikabud Syntax Language) is a production-ready, CMS-agnostic templating language designed for modern web development.

### Key Features

- ✅ **CMS-Agnostic** - Works with WordPress, Joomla, Drupal, or any PHP CMS
- ✅ **Cross-Instance Federation** - Query content from any CMS instance *(NEW in v0.5.1)*
- ✅ **100% Plugin Compatible** - Works with ALL WordPress plugins (WooCommerce, ACF, Elementor, etc.)
- ✅ **Secure by Default** - Auto-escaping with explicit `| raw` for trusted content
- ✅ **Modern Syntax** - Clean, readable, component-based templates
- ✅ **Production Ready** - v0.5.1 grammar specification complete

---

## 5-Minute Quick Start

### 1. Basic Template

```disyl
{!-- page.disyl --}
{include file="components/header.disyl" /}

{ikb_section type="main" padding="large"}
    {ikb_container size="large"}
        
        {!-- Page Title --}
        {ikb_text size="3xl" weight="bold"}
            {page.title | esc_html}
        {/ikb_text}
        
        {!-- Page Content --}
        <div class="content">
            {page.content | raw}
        </div>
        
    {/ikb_container}
{/ikb_section}

{include file="components/footer.disyl" /}
```

### 2. Components

```disyl
{!-- Text Component --}
{ikb_text size="2xl" weight="bold" color="primary"}
    Hello World
{/ikb_text}

{!-- Image Component --}
{ikb_image 
    src="{post.thumbnail | esc_url}" 
    alt="{post.title | esc_attr}"
    responsive=true
/}

{!-- Container Component --}
{ikb_container size="medium"}
    <p>Centered content with max-width</p>
{/ikb_container}

{!-- Section Component --}
{ikb_section type="hero" padding="xlarge"}
    <h1>Hero Section</h1>
{/ikb_section}
```

### 3. Control Structures

```disyl
{!-- If Statement --}
{if condition="{user.logged_in}"}
    <p>Welcome back, {user.name | esc_html}!</p>
{else}
    <p>Please log in.</p>
{/if}

{!-- For Loop --}
{for items="{posts}" as="post"}
    <article>
        <h2>{post.title | esc_html}</h2>
        <p>{post.excerpt | esc_html}</p>
    </article>
{/for}

{!-- Query Component --}
{ikb_query type="post" limit=5 category="news"}
    <article>
        <h3>{item.title | esc_html}</h3>
        <time>{item.date | date:'M j, Y'}</time>
    </article>
{/ikb_query}

{!-- Cross-Instance Query (NEW in v0.5.1) --}
{!-- Pull Joomla articles into a WordPress site --}
{ikb_query cms="joomla" instance="joomla-content" type="article" limit=5}
    <article>
        <h3>{article.title | esc_html}</h3>
        <p>{article.introtext | truncate:150}</p>
    </article>
{/ikb_query}

{!-- Pull Drupal nodes --}
{ikb_query cms="drupal" instance="drupal-blog" type="article" limit=3}
    <article>
        <h3>{node.title | esc_html}</h3>
        <p>{node.body | strip_tags | truncate:200}</p>
    </article>
{/ikb_query}
```

### 4. Filters

```disyl
{!-- Escaping Filters --}
{post.title | esc_html}
{post.url | esc_url}
{post.title | esc_attr}

{!-- Formatting Filters --}
{post.title | upper}
{post.title | lower}
{post.title | capitalize}
{post.excerpt | truncate:150}

{!-- Date Filters --}
{post.date | date:'F j, Y'}
{post.date | date:'M j, Y'}

{!-- Filter Chaining --}
{post.excerpt | truncate:100 | esc_html}
{post.title | lower | capitalize | esc_html}

{!-- Raw Filter (for trusted content) --}
{post.content | raw}
```

### 5. WordPress Plugin Content

```disyl
{!-- WooCommerce Product --}
<div class="product">
    {post.content | raw}
</div>

{!-- Contact Form 7 --}
<div class="contact-form">
    {page.content | raw}
</div>

{!-- Elementor Page --}
<div class="elementor-page">
    {post.content | raw}
</div>

{!-- Any Plugin Shortcode --}
{page.content | raw}
```

---

## Common Patterns

### Blog Post Loop

```disyl
{ikb_section type="blog" padding="large"}
    {ikb_container size="xlarge"}
        
        {ikb_grid columns="3" gap="large"}
            {ikb_query type="post" limit=9}
                {ikb_card shadow="medium"}
                    {ikb_image 
                        src="{item.thumbnail | esc_url}" 
                        alt="{item.title | esc_attr}"
                    /}
                    
                    <h3>
                        <a href="{item.url | esc_url}">
                            {item.title | esc_html}
                        </a>
                    </h3>
                    
                    <time>{item.date | date:'M j, Y'}</time>
                    
                    <p>{item.excerpt | truncate:150 | esc_html}</p>
                {/ikb_card}
            {/ikb_query}
        {/ikb_grid}
        
    {/ikb_container}
{/ikb_section}
```

### Hero Section

```disyl
{ikb_section type="hero" padding="xlarge"}
    {ikb_container size="large"}
        
        {ikb_text size="4xl" weight="bold" align="center"}
            {page.hero_title | esc_html}
        {/ikb_text}
        
        {ikb_text size="xl" color="muted" align="center"}
            {page.hero_subtitle | esc_html}
        {/ikb_text}
        
        <div class="hero-actions">
            <a href="{page.cta_url | esc_url}" class="button primary">
                {page.cta_text | esc_html}
            </a>
        </div>
        
    {/ikb_container}
{/ikb_section}
```

### Sidebar with Widgets

```disyl
<aside class="sidebar">
    {!-- Recent Posts --}
    <div class="widget">
        {ikb_text size="xl" weight="bold"}
            Recent Posts
        {/ikb_text}
        
        {ikb_query type="post" limit=5}
            <article class="widget-post">
                <a href="{item.url | esc_url}">
                    {item.title | esc_html}
                </a>
                <time>{item.date | date:'M j, Y'}</time>
            </article>
        {/ikb_query}
    </div>
    
    {!-- WordPress Widget Area --}
    <div class="widget">
        {sidebar.widgets | raw}
    </div>
</aside>
```

---

## Security Best Practices

### ✅ Always Escape User Input

```disyl
{!-- ✅ CORRECT --}
{post.title | esc_html}
{post.url | esc_url}
{comment.author | esc_html}

{!-- ❌ WRONG - Never output unescaped user input --}
{post.title}
{comment.text}
```

### ✅ Use `| raw` Only for Trusted Content

```disyl
{!-- ✅ CORRECT - WordPress-sanitized content --}
{post.content | raw}

{!-- ✅ CORRECT - Plugin-generated content --}
{page.content | raw}

{!-- ❌ WRONG - User-submitted content --}
{comment.text | raw}
```

### ✅ Chain Filters Properly

```disyl
{!-- ✅ CORRECT - Truncate then escape --}
{post.excerpt | truncate:150 | esc_html}

{!-- ❌ WRONG - Escape then truncate (breaks HTML entities) --}
{post.excerpt | esc_html | truncate:150}
```

---

## File Structure

```
theme/
├── disyl/
│   ├── home.disyl           # Homepage template
│   ├── single.disyl         # Single post template
│   ├── page.disyl           # Page template
│   ├── archive.disyl        # Archive template
│   ├── search.disyl         # Search results
│   ├── 404.disyl            # 404 page
│   └── components/
│       ├── header.disyl     # Site header
│       ├── footer.disyl     # Site footer
│       ├── sidebar.disyl    # Sidebar
│       └── slider.disyl     # Hero slider
├── functions.php            # Theme setup & context
└── style.css                # Theme styles
```

---

## WordPress Integration

### functions.php Setup

```php
<?php
// Load DiSyL Engine
require_once get_template_directory() . '/vendor/ikabud/disyl/autoload.php';

use Ikabud\DiSyL\Engine;
use Ikabud\DiSyL\Renderers\WordPressRenderer;

// Initialize DiSyL
function theme_init_disyl() {
    $engine = new Engine(
        get_template_directory() . '/disyl',
        get_template_directory() . '/disyl/cache'
    );
    
    $renderer = new WordPressRenderer();
    $engine->setRenderer($renderer);
    
    return $engine;
}

// Build DiSyL context
function theme_build_context() {
    global $post, $wp_query;
    
    $context = [
        'site' => [
            'name' => get_bloginfo('name'),
            'description' => get_bloginfo('description'),
            'url' => home_url('/'),
        ],
        'post' => [
            'id' => get_the_ID(),
            'title' => get_the_title(),
            'content' => apply_filters('the_content', $post->post_content),
            'excerpt' => get_the_excerpt(),
            'url' => get_permalink(),
            'date' => get_the_date('c'),
            'thumbnail' => get_the_post_thumbnail_url(null, 'large'),
        ],
        'user' => [
            'logged_in' => is_user_logged_in(),
            'name' => wp_get_current_user()->display_name,
        ],
    ];
    
    return apply_filters('disyl_context', $context);
}

// Render DiSyL template
function theme_render_template($template_name) {
    $engine = theme_init_disyl();
    $context = theme_build_context();
    
    echo $engine->render($template_name, $context);
}
```

### index.php Router

```php
<?php
// Determine which template to use
if (is_home() || is_front_page()) {
    theme_render_template('home.disyl');
} elseif (is_single()) {
    theme_render_template('single.disyl');
} elseif (is_page()) {
    theme_render_template('page.disyl');
} elseif (is_archive()) {
    theme_render_template('archive.disyl');
} elseif (is_search()) {
    theme_render_template('search.disyl');
} elseif (is_404()) {
    theme_render_template('404.disyl');
}
```

---

## VSCode Extension

DiSyL has full IDE support with syntax highlighting and snippets.

### Installation

```bash
# Copy extension to Windsurf/VSCode
cp -r vscode-disyl ~/.windsurf/extensions/ikabud.disyl-0.3.0

# Restart Windsurf/VSCode
```

### Snippets

Type these and press `Tab`:

- `section` → Creates `{ikb_section}`
- `container` → Creates `{ikb_container}`
- `if` → Creates `{if}...{/if}`
- `for` → Creates `{for}...{/for}`
- `query` → Creates `{ikb_query}`
- `fesc_html` → Creates `{var | esc_html}`
- `template` → Creates complete template

---

## Documentation

- **Grammar Specification:** [DISYL_GRAMMAR_v0.3.ebnf](DISYL_GRAMMAR_v0.3.ebnf)
- **WordPress Plugin Guide:** [DISYL_WORDPRESS_PLUGINS.md](DISYL_WORDPRESS_PLUGINS.md)
- **Phoenix Theme Docs:** [PHOENIX_DOCUMENTATION.md](../instances/wp-brutus-cli/wp-content/themes/phoenix/PHOENIX_DOCUMENTATION.md)
- **Filter Reference:** [DISYL_FILTERS.md](DISYL_FILTERS.md)

---

## Next Steps

1. ✅ Install DiSyL in your theme
2. ✅ Create your first template
3. ✅ Add components for reusable elements
4. ✅ Use filters for security and formatting
5. ✅ Test with WordPress plugins

**DiSyL is production-ready!** Start building CMS-agnostic themes today. 🚀

---

## Support

- **GitHub:** https://github.com/ikabud/disyl
- **Documentation:** `/docs/`
- **Examples:** Phoenix Theme (`/instances/wp-brutus-cli/wp-content/themes/phoenix/`)

---

**Happy templating with DiSyL!** 🎨
