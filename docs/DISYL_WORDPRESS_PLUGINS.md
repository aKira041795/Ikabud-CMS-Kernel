# DiSyL WordPress Plugin Compatibility Guide

**Version:** 0.3.0  
**Last Updated:** November 15, 2025

---

## Philosophy

DiSyL is **100% compatible** with all WordPress plugins out of the box. No configuration, no dependencies, no breaking changes.

### How It Works

```
WordPress processes plugins → DiSyL renders the result
```

1. WordPress loads plugins and processes their content
2. Plugins add shortcodes, modify content, inject HTML
3. WordPress applies filters (`the_content`, `the_excerpt`, etc.)
4. DiSyL receives the final processed content
5. DiSyL renders it using the `| raw` filter

**Result:** Any plugin that works in WordPress works in DiSyL.

---

## The `| raw` Filter

The `| raw` filter is your gateway to WordPress plugin compatibility.

### Basic Usage

```disyl
{!-- Render WordPress-processed content --}
<div class="content">
    {post.content | raw}
</div>
```

### What Gets Rendered

- ✅ **Shortcodes** - `[gallery]`, `[contact-form-7]`, etc.
- ✅ **Page Builders** - Elementor, Divi, Beaver Builder, etc.
- ✅ **E-commerce** - WooCommerce product content
- ✅ **Forms** - Contact Form 7, Gravity Forms, WPForms
- ✅ **Custom Fields** - ACF fields embedded in content
- ✅ **SEO** - Yoast, Rank Math meta content
- ✅ **Multilingual** - WPML, Polylang translations
- ✅ **Any WordPress plugin** that modifies content

---

## Plugin Integration Examples

### 1. Contact Form 7

**WordPress Shortcode:**
```
[contact-form-7 id="123" title="Contact form"]
```

**DiSyL Template:**
```disyl
{ikb_section type="contact" padding="large"}
    {ikb_container size="medium"}
        {ikb_text size="2xl" weight="bold"}
            Get In Touch
        {/ikb_text}
        
        {!-- Contact Form 7 shortcode processed by WordPress --}
        <div class="contact-form">
            {page.content | raw}
        </div>
    {/ikb_container}
{/ikb_section}
```

**Result:** Contact Form 7 renders normally with all its features (validation, AJAX, spam protection).

---

### 2. WooCommerce

#### Product Page

```disyl
{!-- single-product.disyl --}
{include file="components/header.disyl" /}

{ikb_section type="product" padding="large"}
    {ikb_container size="xlarge"}
        
        {!-- Product Title --}
        {ikb_text size="3xl" weight="bold"}
            {post.title | esc_html}
        {/ikb_text}
        
        {!-- WooCommerce Product Content --}
        {!-- Includes: images, price, add to cart, tabs, reviews --}
        <div class="woocommerce-product">
            {post.content | raw}
        </div>
        
    {/ikb_container}
{/ikb_section}

{include file="components/footer.disyl" /}
```

#### Shop Page

```disyl
{!-- archive-product.disyl --}
{include file="components/header.disyl" /}

{ikb_section type="shop" padding="large"}
    {ikb_container size="xlarge"}
        
        <header class="shop-header">
            {ikb_text size="3xl" weight="bold"}
                {archive.title | esc_html}
            {/ikb_text}
        </header>
        
        {!-- WooCommerce Product Loop --}
        {ikb_grid columns="4" gap="large"}
            {ikb_query type="post" limit=12}
                <article class="product-card">
                    {!-- Product content with WooCommerce hooks --}
                    {item.content | raw}
                </article>
            {/ikb_query}
        {/ikb_grid}
        
    {/ikb_container}
{/ikb_section}

{include file="components/footer.disyl" /}
```

#### Cart Widget

```disyl
{!-- In header.disyl --}
<div class="cart-widget">
    {!-- WooCommerce cart shortcode --}
    {woocommerce.cart_widget | raw}
</div>
```

**functions.php:**
```php
function phoenix_woocommerce_context($context) {
    if (class_exists('WooCommerce')) {
        $context['woocommerce'] = [
            'cart_widget' => do_shortcode('[woocommerce_cart]'),
            'cart_count' => WC()->cart->get_cart_contents_count(),
            'cart_total' => WC()->cart->get_cart_total(),
        ];
    }
    return $context;
}
add_filter('disyl_context', 'phoenix_woocommerce_context');
```

---

### 3. Advanced Custom Fields (ACF)

#### Method 1: ACF in Content (Automatic)

```disyl
{!-- If ACF fields are added to post content via shortcodes --}
<div class="post-content">
    {post.content | raw}
</div>
```

#### Method 2: ACF via Context (Recommended)

**functions.php:**
```php
function phoenix_acf_context($context) {
    if (function_exists('get_field')) {
        // Add ACF fields to post context
        $context['post']['acf'] = [
            'subtitle' => get_field('subtitle'),
            'featured_image' => get_field('featured_image'),
            'author_bio' => get_field('author_bio'),
            'custom_link' => get_field('custom_link'),
        ];
    }
    return $context;
}
add_filter('disyl_context', 'phoenix_acf_context');
```

**DiSyL Template:**
```disyl
{!-- single.disyl --}
{include file="components/header.disyl" /}

{ikb_section type="post" padding="large"}
    {ikb_container size="large"}
        
        {!-- Post Title --}
        {ikb_text size="3xl" weight="bold"}
            {post.title | esc_html}
        {/ikb_text}
        
        {!-- ACF Subtitle --}
        {if condition="{post.acf.subtitle}"}
            {ikb_text size="xl" color="muted"}
                {post.acf.subtitle | esc_html}
            {/ikb_text}
        {/if}
        
        {!-- ACF Featured Image --}
        {if condition="{post.acf.featured_image}"}
            {ikb_image 
                src="{post.acf.featured_image | esc_url}" 
                alt="{post.title | esc_attr}"
                responsive=true
            /}
        {/if}
        
        {!-- Post Content --}
        <div class="content">
            {post.content | raw}
        </div>
        
        {!-- ACF Author Bio --}
        {if condition="{post.acf.author_bio}"}
            <aside class="author-bio">
                {post.acf.author_bio | esc_html}
            </aside>
        {/if}
        
    {/ikb_container}
{/ikb_section}

{include file="components/footer.disyl" /}
```

---

### 4. Elementor Page Builder

```disyl
{!-- page.disyl --}
{include file="components/header.disyl" /}

{!-- Elementor content renders with full functionality --}
<div class="elementor-page">
    {post.content | raw}
</div>

{include file="components/footer.disyl" /}
```

**Result:** Elementor pages render with all widgets, animations, and responsive features intact.

---

### 5. Yoast SEO

**functions.php:**
```php
function phoenix_yoast_context($context) {
    if (function_exists('yoast_breadcrumb')) {
        $context['yoast'] = [
            'breadcrumbs' => yoast_breadcrumb('<div id="breadcrumbs">', '</div>', false),
            'title' => get_post_meta(get_the_ID(), '_yoast_wpseo_title', true),
            'description' => get_post_meta(get_the_ID(), '_yoast_wpseo_metadesc', true),
        ];
    }
    return $context;
}
add_filter('disyl_context', 'phoenix_yoast_context');
```

**DiSyL Template:**
```disyl
{!-- Yoast Breadcrumbs --}
{if condition="{yoast.breadcrumbs}"}
    {yoast.breadcrumbs | raw}
{/if}

{!-- Page Content --}
<div class="content">
    {post.content | raw}
</div>
```

---

### 6. WPML (Multilingual)

**functions.php:**
```php
function phoenix_wpml_context($context) {
    if (function_exists('icl_get_languages')) {
        $context['wpml'] = [
            'languages' => icl_get_languages('skip_missing=0'),
            'current_lang' => ICL_LANGUAGE_CODE,
        ];
    }
    return $context;
}
add_filter('disyl_context', 'phoenix_wpml_context');
```

**DiSyL Template:**
```disyl
{!-- Language Switcher --}
<nav class="language-switcher">
    {for items="{wpml.languages}" as="lang"}
        <a href="{lang.url | esc_url}" 
           class="lang-link {if condition='{lang.active}'}active{/if}">
            {lang.native_name | esc_html}
        </a>
    {/for}
</nav>

{!-- Translated Content --}
<div class="content">
    {post.content | raw}
</div>
```

---

### 7. The Events Calendar

**functions.php:**
```php
function phoenix_events_context($context) {
    if (function_exists('tribe_get_events')) {
        $events = tribe_get_events([
            'posts_per_page' => 5,
            'start_date' => 'now',
        ]);
        
        $context['events'] = [
            'upcoming' => array_map(function($event) {
                return [
                    'id' => $event->ID,
                    'title' => get_the_title($event->ID),
                    'url' => get_permalink($event->ID),
                    'start_date' => tribe_get_start_date($event->ID),
                    'venue' => tribe_get_venue($event->ID),
                ];
            }, $events),
        ];
    }
    return $context;
}
add_filter('disyl_context', 'phoenix_events_context');
```

**DiSyL Template:**
```disyl
{!-- Upcoming Events Widget --}
<aside class="upcoming-events">
    {ikb_text size="xl" weight="bold"}
        Upcoming Events
    {/ikb_text}
    
    {for items="{events.upcoming}" as="event"}
        <article class="event-card">
            <h3>
                <a href="{event.url | esc_url}">
                    {event.title | esc_html}
                </a>
            </h3>
            <time>{event.start_date | esc_html}</time>
            <span class="venue">{event.venue | esc_html}</span>
        </article>
    {/for}
</aside>
```

---

### 8. Gravity Forms

```disyl
{!-- Contact Page --}
{ikb_section type="contact" padding="large"}
    {ikb_container size="medium"}
        
        {ikb_text size="2xl" weight="bold"}
            {page.title | esc_html}
        {/ikb_text}
        
        {!-- Gravity Form shortcode: [gravityform id="1"] --}
        <div class="gravity-form">
            {page.content | raw}
        </div>
        
    {/ikb_container}
{/ikb_section}
```

---

### 9. WordPress Menus

WordPress navigation menus can be rendered dynamically in DiSyL templates.

**functions.php:**
```php
function theme_get_menu_items($location) {
    $locations = get_nav_menu_locations();
    
    if (!isset($locations[$location])) {
        return array();
    }
    
    $menu = wp_get_nav_menu_object($locations[$location]);
    
    if (!$menu) {
        return array();
    }
    
    $menu_items = wp_get_nav_menu_items($menu->term_id);
    
    if (!$menu_items) {
        return array();
    }
    
    $items = array();
    
    foreach ($menu_items as $item) {
        $items[] = array(
            'id' => $item->ID,
            'title' => $item->title,
            'url' => $item->url,
            'target' => $item->target,
            'classes' => implode(' ', $item->classes),
            'active' => ($item->url === home_url($_SERVER['REQUEST_URI'])),
            'parent_id' => $item->menu_item_parent,
            'order' => $item->menu_order,
        );
    }
    
    return $items;
}

// Add to context
function theme_context($context) {
    $context['menu'] = [
        'primary' => theme_get_menu_items('primary'),
        'footer' => theme_get_menu_items('footer'),
        'social' => theme_get_menu_items('social'),
    ];
    return $context;
}
add_filter('disyl_context', 'theme_context');
```

**DiSyL Template:**
```disyl
{!-- Primary Navigation --}
<nav class="main-nav">
    <ul>
        {for items="{menu.primary}" as="item"}
            <li class="{item.classes}">
                <a href="{item.url | esc_url}" 
                   {if condition="{item.target}"}target="{item.target | esc_attr}"{/if}
                   {if condition="{item.active}"}class="active"{/if}>
                    {item.title | esc_html}
                </a>
            </li>
        {/for}
    </ul>
</nav>

{!-- Footer Menu --}
<nav class="footer-nav">
    <ul>
        {for items="{menu.footer}" as="item"}
            <li>
                <a href="{item.url | esc_url}">
                    {item.title | esc_html}
                </a>
            </li>
        {/for}
    </ul>
</nav>

{!-- Social Links --}
<div class="social-links">
    {for items="{menu.social}" as="item"}
        <a href="{item.url | esc_url}" 
           target="_blank" 
           rel="noopener noreferrer"
           class="social-link {item.classes}">
            {item.title | esc_html}
        </a>
    {/for}
</div>
```

**Features:**
- ✅ Dynamic menu rendering from WordPress admin
- ✅ Active state detection for current page
- ✅ Support for external links (target attribute)
- ✅ Custom CSS classes from WordPress
- ✅ Multiple menu locations
- ✅ Graceful fallback if menu not assigned

---

## Common Patterns

### Pattern 1: Shortcode in Content

**WordPress Editor:**
```
Add your content here.

[plugin_shortcode id="123" option="value"]

More content below.
```

**DiSyL Template:**
```disyl
<div class="content">
    {post.content | raw}
</div>
```

**Result:** Shortcode is processed by WordPress, DiSyL renders the final HTML.

---

### Pattern 2: Plugin Data via Context

**functions.php:**
```php
function theme_plugin_context($context) {
    // Add plugin data to context
    if (function_exists('plugin_get_data')) {
        $context['plugin'] = plugin_get_data();
    }
    return $context;
}
add_filter('disyl_context', 'theme_plugin_context');
```

**DiSyL Template:**
```disyl
{if condition="{plugin.data}"}
    <div class="plugin-content">
        {plugin.data | esc_html}
    </div>
{/if}
```

---

### Pattern 3: Plugin Widget Areas

**functions.php:**
```php
function theme_sidebar_context($context) {
    ob_start();
    dynamic_sidebar('sidebar-1');
    $context['sidebar'] = ob_get_clean();
    return $context;
}
add_filter('disyl_context', 'theme_sidebar_context');
```

**DiSyL Template:**
```disyl
<aside class="sidebar">
    {sidebar | raw}
</aside>
```

---

## Best Practices

### 1. Always Use `| raw` for Plugin Content

```disyl
{!-- ✅ CORRECT --}
{post.content | raw}

{!-- ❌ WRONG - Will escape HTML --}
{post.content | esc_html}
```

### 2. Trust WordPress Sanitization

WordPress plugins sanitize their output. When using `| raw`, you're rendering content that's already been sanitized by WordPress.

```disyl
{!-- WordPress sanitizes this via the_content filter --}
{post.content | raw}

{!-- No need for additional escaping --}
```

### 3. Use Context for Plugin Data

Don't call plugin functions directly in templates. Add plugin data to context in `functions.php`.

```php
// ✅ CORRECT - In functions.php
function theme_context($context) {
    $context['plugin_data'] = plugin_get_data();
    return $context;
}
add_filter('disyl_context', 'theme_context');
```

```disyl
<!-- ✅ CORRECT - In template -->
{plugin_data | raw}
```

### 4. Check Plugin Existence

Always check if a plugin is active before using its data.

```php
// In functions.php
function theme_context($context) {
    // Check if WooCommerce is active
    if (class_exists('WooCommerce')) {
        $context['woocommerce'] = [
            'cart_count' => WC()->cart->get_cart_contents_count(),
        ];
    }
    
    // Check if ACF is active
    if (function_exists('get_field')) {
        $context['post']['acf'] = get_fields();
    }
    
    return $context;
}
add_filter('disyl_context', 'theme_context');
```

### 5. Provide Fallbacks

```disyl
{!-- Check if plugin data exists --}
{if condition="{woocommerce.cart_count}"}
    <span class="cart-count">{woocommerce.cart_count}</span>
{else}
    <span class="cart-count">0</span>
{/if}
```

---

## Plugin Compatibility Matrix

| Plugin | Status | Method | Notes |
|--------|--------|--------|-------|
| **WooCommerce** | ✅ Full | `{post.content \| raw}` | All features work |
| **Contact Form 7** | ✅ Full | `{page.content \| raw}` | AJAX, validation work |
| **Gravity Forms** | ✅ Full | `{page.content \| raw}` | All features work |
| **WPForms** | ✅ Full | `{page.content \| raw}` | All features work |
| **Elementor** | ✅ Full | `{post.content \| raw}` | Full editor support |
| **Divi Builder** | ✅ Full | `{post.content \| raw}` | Full builder support |
| **Beaver Builder** | ✅ Full | `{post.content \| raw}` | Full builder support |
| **ACF** | ✅ Full | Context + `\| raw` | Via context provider |
| **Yoast SEO** | ✅ Full | Context + `\| raw` | Meta, breadcrumbs work |
| **Rank Math** | ✅ Full | Context + `\| raw` | All SEO features work |
| **WPML** | ✅ Full | Context + `\| raw` | Translations work |
| **Polylang** | ✅ Full | Context + `\| raw` | Translations work |
| **The Events Calendar** | ✅ Full | Context + `\| raw` | All features work |
| **Easy Digital Downloads** | ✅ Full | `{post.content \| raw}` | All features work |
| **MemberPress** | ✅ Full | `{post.content \| raw}` | Restrictions work |
| **BuddyPress** | ✅ Full | `{post.content \| raw}` | Social features work |
| **bbPress** | ✅ Full | `{post.content \| raw}` | Forums work |
| **Jetpack** | ✅ Full | `{post.content \| raw}` | All modules work |
| **WordPress Menus** | ✅ Full | Context + `{for}` loop | Dynamic menu rendering |

**Compatibility:** If it works in WordPress, it works in DiSyL.

---

## Troubleshooting

### Issue: Plugin content not rendering

**Cause:** Not using `| raw` filter

**Solution:**
```disyl
{!-- ❌ WRONG --}
{post.content}

{!-- ✅ CORRECT --}
{post.content | raw}
```

---

### Issue: Shortcode appears as text

**Cause:** Shortcode not processed by WordPress

**Solution:** Ensure content goes through `the_content` filter in `functions.php`:

```php
$context['post']['content'] = apply_filters('the_content', $post->post_content);
```

---

### Issue: Plugin styles not loading

**Cause:** Plugin assets not enqueued

**Solution:** Ensure plugins can enqueue their assets in `functions.php`:

```php
// Let WordPress load plugin assets
add_action('wp_enqueue_scripts', function() {
    // Don't dequeue plugin styles/scripts
});
```

---

### Issue: AJAX not working

**Cause:** WordPress AJAX URL not available

**Solution:** Ensure `wp_footer()` is called in footer template:

```php
// In footer.php or functions.php
wp_footer();
```

---

## Future Enhancements

### Phase 2: Enhanced Integration (Optional)

For power users who want better DX with specific plugins:

```disyl
{!-- Optional enhanced components --}
{ikb_woo_product id="123" layout="grid" /}
{ikb_acf_field name="subtitle" /}
{ikb_cf7 id="123" /}
```

**Note:** These require the specific plugins to be installed.

### Phase 3: Plugin Ecosystem (Future)

DiSyL Plugin Manager with auto-discovery and smart fallbacks.

---

## Summary

✅ **DiSyL is 100% compatible with all WordPress plugins**  
✅ **No configuration needed**  
✅ **No dependencies required**  
✅ **Use `| raw` filter for plugin content**  
✅ **Add plugin data to context in `functions.php`**  
✅ **Trust WordPress sanitization**  

**The `| raw` filter is your superpower for WordPress plugin compatibility.**

---

## Related Documentation

- [DiSyL Grammar v0.3](DISYL_GRAMMAR_v0.3.ebnf)
- [DiSyL Filter Reference](DISYL_FILTERS.md)
- [Phoenix Theme Documentation](../instances/wp-brutus-cli/wp-content/themes/phoenix/PHOENIX_DOCUMENTATION.md)
- [WordPress Integration Guide](DISYL_WORDPRESS_INTEGRATION.md)

---

**Questions or issues?** Open an issue on GitHub or contact the DiSyL team.
