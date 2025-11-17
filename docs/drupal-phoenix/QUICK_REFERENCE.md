# Drupal Phoenix Theme - Quick Reference

## DiSyL Components

### Core Components

```disyl
{!-- Text Styling --}
{ikb_text size="xl" weight="bold" align="center" class="custom-class"}
    Your text content
{/ikb_text}

{!-- Container --}
{ikb_container size="large"}
    Content here
{/ikb_container}

{!-- Section --}
{ikb_section type="hero" padding="large" class="custom-section"}
    Section content
{/ikb_section}

{!-- Image --}
{ikb_image 
    src="{image.url | esc_url}"
    alt="{image.alt | esc_attr}"
    class="featured-image"
    lazy=true
/}

{!-- Include Template --}
{ikb_include template="components/header.disyl" /}

{!-- Query & Loop --}
{ikb_query type="post" limit=6 orderby="created" order="DESC"}
    <div class="item">
        <h2>{item.title | esc_html}</h2>
        <p>{item.excerpt | truncate:length=150}</p>
    </div>
{/ikb_query}
```

### Drupal Components

```disyl
{!-- Render Block --}
{drupal_block id="phoenix_site_branding" /}

{!-- Render Region --}
{drupal_region name="header" /}

{!-- Render Menu --}
{drupal_menu name="main" /}

{!-- Render View --}
{drupal_view name="frontpage" display="block_1" /}

{!-- Render Form --}
{drupal_form id="search_block_form" /}
```

## Conditionals

```disyl
{!-- Simple Truthy Check --}
{if condition="item.thumbnail"}
    <img src="{item.thumbnail}" />
{/if}

{!-- Comparison --}
{if condition="item.count > 5"}
    Many items
{/if}

{!-- Logical Operators --}
{if condition="user.logged_in && user.role == 'admin'"}
    Admin content
{/if}

{if condition="item.featured || item.sticky"}
    Featured content
{/if}
```

## Filters

```disyl
{!-- HTML Escaping --}
{item.title | esc_html}

{!-- URL Sanitization --}
<a href="{item.url | esc_url}">Link</a>

{!-- Attribute Escaping --}
<img alt="{item.title | esc_attr}" />

{!-- Date Formatting --}
{item.date | date:format="M j, Y"}
{item.date | date:format="F d, Y g:i A"}

{!-- Text Truncation --}
{item.excerpt | truncate:length=150,append="..."}

{!-- Strip HTML Tags --}
{item.content | strip_tags}

{!-- Translation --}
{text | t}

{!-- Filter Chains --}
{item.excerpt | strip_tags | truncate:length=200 | esc_html}
```

## Context Variables

### Site Context
```disyl
{site.name}           - Site name
{site.slogan}         - Site slogan
{site.base_url}       - Base URL
{site.logo}           - Logo URL
```

### User Context
```disyl
{user.logged_in}      - Boolean: user logged in
{user.id}             - User ID
{user.name}           - User display name
{user.email}          - User email
{user.roles}          - User roles array
```

### Node Context (in single templates)
```disyl
{node.id}             - Node ID
{node.title}          - Node title
{node.type}           - Content type
{node.created}        - Creation timestamp
{node.changed}        - Last modified timestamp
{node.author}         - Author name
{node.published}      - Boolean: published status
```

### Query Item Context (in ikb_query loops)
```disyl
{item.id}             - Node ID
{item.title}          - Node title
{item.url}            - Node URL
{item.date}           - Creation timestamp
{item.changed}        - Modified timestamp
{item.author}         - Author name
{item.author_id}      - Author ID
{item.type}           - Content type
{item.thumbnail}      - Featured image URL
{item.excerpt}        - Body excerpt (plain text)
{item.content}        - Full body content
```

## Common Patterns

### Blog Post Grid
```disyl
{ikb_section type="blog" padding="large"}
    {ikb_container size="xlarge"}
        <div class="post-grid">
            {ikb_query type="post" limit=6}
                <article class="post-card">
                    {if condition="item.thumbnail"}
                        <a href="{item.url | esc_url}">
                            {ikb_image 
                                src="{item.thumbnail | esc_url}"
                                alt="{item.title | esc_attr}"
                                lazy=true
                            /}
                        </a>
                    {/if}
                    
                    <div class="post-content">
                        <div class="post-meta">
                            <span>{item.date | date:format="M j, Y"}</span>
                            <span>{item.author | esc_html}</span>
                        </div>
                        
                        <h3><a href="{item.url | esc_url}">{item.title | esc_html}</a></h3>
                        
                        <p>{item.excerpt | strip_tags | truncate:length=150}</p>
                        
                        <a href="{item.url | esc_url}" class="read-more">Read More →</a>
                    </div>
                </article>
            {/ikb_query}
        </div>
    {/ikb_container}
{/ikb_section}
```

### Hero Section with Region
```disyl
{ikb_section type="hero" class="hero-section" padding="xlarge"}
    {ikb_container size="large"}
        {drupal_region name="hero" /}
        
        <div class="hero-content">
            {ikb_text size="4xl" weight="bold" align="center"}
                <h1>Welcome to {site.name}</h1>
            {/ikb_text}
            
            {ikb_text size="xl" align="center"}
                <p>{site.slogan}</p>
            {/ikb_text}
            
            <div class="hero-actions">
                <a href="/contact" class="btn btn-primary">Get Started</a>
                <a href="/about" class="btn btn-outline">Learn More</a>
            </div>
        </div>
    {/ikb_container}
{/ikb_section}
```

### Sidebar with Conditional
```disyl
{if condition="has_sidebar_first"}
    <aside class="sidebar sidebar-first">
        {drupal_region name="sidebar_first" /}
    </aside>
{/if}
```

### Header with Navigation
```disyl
<header class="site-header">
    <div class="header-container">
        <div class="site-branding">
            {drupal_block id="phoenix_site_branding" /}
        </div>
        
        <nav class="main-nav">
            {drupal_region name="primary_menu" /}
        </nav>
        
        {if condition="user.logged_in"}
            <div class="user-menu">
                Welcome, {user.name | esc_html}
            </div>
        {/if}
    </div>
</header>
```

### Footer with Widgets
```disyl
<footer class="site-footer">
    {ikb_container size="xlarge"}
        <div class="footer-widgets">
            <div class="footer-widget">
                {drupal_region name="footer_first" /}
            </div>
            <div class="footer-widget">
                {drupal_region name="footer_second" /}
            </div>
            <div class="footer-widget">
                {drupal_region name="footer_third" /}
            </div>
        </div>
        
        <div class="footer-bottom">
            <p>© {date format="Y" /} {site.name | esc_html}. All rights reserved.</p>
        </div>
    {/ikb_container}
</footer>
```

## File Locations

```
Theme Files:
  /instances/dpl-now-drupal/themes/phoenix/

DiSyL Templates:
  /instances/dpl-now-drupal/themes/phoenix/disyl/

DiSyL Engine:
  /kernel/DiSyL/

DrupalRenderer:
  /kernel/DiSyL/Renderers/DrupalRenderer.php
```

## Useful Commands

```bash
# Clear cache
drush cache:rebuild

# Enable theme
drush theme:enable phoenix
drush config-set system.theme default phoenix

# Check logs
drush watchdog:show --type=phoenix

# Test rendering
curl -s http://genesis.test | grep "ikb-section"
```

## Debugging

```php
// In disyl-integration.php
\Drupal::logger('phoenix')->debug('Template: @name', ['@name' => $template_name]);

// In DrupalRenderer.php
error_log('DiSyL Debug: ' . print_r($context, true));
```

## Performance Tips

1. Use `limit` on queries: `{ikb_query type="post" limit=6}`
2. Enable lazy loading: `{ikb_image lazy=true /}`
3. Cache expensive operations
4. Enable Drupal CSS/JS aggregation

## Security Checklist

- ✅ Always use `| esc_html` for text output
- ✅ Always use `| esc_url` for URLs
- ✅ Always use `| esc_attr` for HTML attributes
- ✅ Use `| strip_tags` to remove HTML from user content
- ✅ Never output raw user input

---

*Quick Reference for Drupal Phoenix Theme v2.0*
