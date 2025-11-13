# DiSyL WordPress Theme Example

This document shows how to create a WordPress theme using DiSyL templates.

## Theme Structure

```
wp-content/themes/disyl-theme/
├── style.css              # Theme metadata
├── functions.php          # Theme setup
├── index.php              # Main template (loads DiSyL)
├── header.php             # Header
├── footer.php             # Footer
└── disyl/
    ├── home.disyl         # Homepage template
    ├── single.disyl       # Single post template
    ├── archive.disyl      # Archive template
    └── components/
        ├── header.disyl   # Header component
        └── footer.disyl   # Footer component
```

## style.css

```css
/*
Theme Name: DiSyL Theme
Theme URI: https://ikabud.com/themes/disyl
Description: A WordPress theme powered by DiSyL templates
Version: 1.0.0
Author: Ikabud
Author URI: https://ikabud.com
License: MIT
*/

/* Your custom styles here */
```

## functions.php

```php
<?php
/**
 * DiSyL Theme Functions
 */

// Load DiSyL engine
require_once ABSPATH . '../../../kernel/DiSyL/Lexer.php';
require_once ABSPATH . '../../../kernel/DiSyL/Parser.php';
require_once ABSPATH . '../../../kernel/DiSyL/Compiler.php';
require_once ABSPATH . '../../../kernel/DiSyL/Grammar.php';
require_once ABSPATH . '../../../kernel/DiSyL/ComponentRegistry.php';

use IkabudKernel\Core\DiSyL\{Lexer, Parser, Compiler};

/**
 * Render DiSyL template
 */
function disyl_render_template($template_name, $context = []) {
    $template_path = get_template_directory() . '/disyl/' . $template_name . '.disyl';
    
    if (!file_exists($template_path)) {
        return '<!-- Template not found: ' . esc_html($template_name) . ' -->';
    }
    
    $template_content = file_get_contents($template_path);
    
    // Compile template
    $lexer = new Lexer();
    $parser = new Parser();
    $compiler = new Compiler();
    
    $tokens = $lexer->tokenize($template_content);
    $ast = $parser->parse($tokens);
    $compiled = $compiler->compile($ast);
    
    // Render through WordPress adapter
    global $wp_cms_adapter;
    if ($wp_cms_adapter) {
        return $wp_cms_adapter->renderDisyl($compiled, $context);
    }
    
    return '<!-- WordPress adapter not available -->';
}

/**
 * Theme setup
 */
function disyl_theme_setup() {
    // Add theme support
    add_theme_support('title-tag');
    add_theme_support('post-thumbnails');
    add_theme_support('html5', ['search-form', 'comment-form', 'comment-list', 'gallery', 'caption']);
    
    // Register menus
    register_nav_menus([
        'primary' => __('Primary Menu', 'disyl-theme'),
        'footer' => __('Footer Menu', 'disyl-theme')
    ]);
}
add_action('after_setup_theme', 'disyl_theme_setup');

/**
 * Enqueue scripts and styles
 */
function disyl_theme_scripts() {
    wp_enqueue_style('disyl-theme-style', get_stylesheet_uri());
}
add_action('wp_enqueue_scripts', 'disyl_theme_scripts');
```

## index.php

```php
<?php
/**
 * Main Template File
 */

get_header();

// Determine which DiSyL template to use
if (is_home() || is_front_page()) {
    echo disyl_render_template('home');
} elseif (is_single()) {
    echo disyl_render_template('single');
} elseif (is_archive()) {
    echo disyl_render_template('archive');
} else {
    echo disyl_render_template('home');
}

get_footer();
```

## disyl/home.disyl

```disyl
{ikb_section type="hero" bg="#f0f0f0" padding="large"}
    {ikb_container width="xl" center=true}
        {ikb_text size="2xl" weight="bold" align="center"}
            Welcome to Our Blog
        {/ikb_text}
        {ikb_text size="lg" align="center" color="#666"}
            Discover amazing content
        {/ikb_text}
    {/ikb_container}
{/ikb_section}

{ikb_section type="content"}
    {ikb_container width="lg"}
        {ikb_query type="post" limit=6 orderby="date" order="desc"}
            {ikb_block cols=3 gap=2}
                {ikb_card 
                    title="{item.title}" 
                    image="{item.thumbnail}"
                    link="{item.url}"
                    variant="elevated"
                }
                    {ikb_text size="sm" color="#666"}
                        {item.date} by {item.author}
                    {/ikb_text}
                    {ikb_text}
                        {item.excerpt}
                    {/ikb_text}
                {/ikb_card}
            {/ikb_block}
        {/ikb_query}
    {/ikb_container}
{/ikb_section}
```

## disyl/single.disyl

```disyl
{ikb_section type="content"}
    {ikb_container width="md"}
        {ikb_query type="post" limit=1}
            {ikb_text size="2xl" weight="bold"}
                {item.title}
            {/ikb_text}
            
            {ikb_text size="sm" color="#666"}
                Published on {item.date} by {item.author}
            {/ikb_text}
            
            {if condition="item.thumbnail"}
                {ikb_image 
                    src="{item.thumbnail}" 
                    alt="{item.title}"
                    responsive=true
                    lazy=true
                }
            {/if}
            
            {ikb_text}
                {item.content}
            {/ikb_text}
            
            {!-- Categories --}
            {if condition="item.categories"}
                {ikb_text size="sm"}
                    Categories: {item.categories}
                {/ikb_text}
            {/if}
        {/ikb_query}
    {/ikb_container}
{/ikb_section}
```

## disyl/archive.disyl

```disyl
{ikb_section type="content"}
    {ikb_container width="lg"}
        {ikb_text size="2xl" weight="bold"}
            Blog Archive
        {/ikb_text}
        
        {ikb_query type="post" limit=12 orderby="date" order="desc"}
            {ikb_block cols=2 gap=2}
                {ikb_card 
                    title="{item.title}"
                    image="{item.thumbnail}"
                    link="{item.url}"
                    variant="outlined"
                }
                    {ikb_text size="sm"}
                        {item.excerpt}
                    {/ikb_text}
                {/ikb_card}
            {/ikb_block}
        {/ikb_query}
    {/ikb_container}
{/ikb_section}
```

## Usage

1. **Install the theme** in `wp-content/themes/disyl-theme/`
2. **Activate** the theme in WordPress admin
3. **Create DiSyL templates** in the `disyl/` directory
4. **View your site** - DiSyL templates will be rendered automatically

## Benefits

- **No PHP in templates** - Pure declarative syntax
- **Component-based** - Reusable UI components
- **Type-safe** - Validated attributes
- **Fast** - Compiled and cached
- **WordPress integration** - Full access to WP_Query, post data, etc.

## Advanced: Custom Components

Register custom components in `functions.php`:

```php
use IkabudKernel\Core\DiSyL\ComponentRegistry;
use IkabudKernel\Core\DiSyL\Grammar;

ComponentRegistry::register('wp_menu', [
    'category' => ComponentRegistry::CATEGORY_UI,
    'description' => 'WordPress navigation menu',
    'attributes' => [
        'location' => [
            'type' => Grammar::TYPE_STRING,
            'required' => true,
            'description' => 'Menu location'
        ]
    ],
    'leaf' => true
]);
```

Then use in templates:

```disyl
{wp_menu location="primary"}
```
