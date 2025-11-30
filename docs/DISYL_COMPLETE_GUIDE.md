# DiSyL Complete Guide
**Declarative Ikabud Syntax Language**

**Version:** 0.5.1  
**Status:** Production Ready  
**Last Updated:** November 30, 2025

---

## Table of Contents

1. [Overview](#overview)
2. [Architecture](#architecture)
3. [Getting Started](#getting-started)
4. [Syntax Reference](#syntax-reference)
5. [Component Library](#component-library)
6. [Cross-Instance Federation](#cross-instance-federation) *(NEW)*
7. [WordPress Integration](#wordpress-integration)
8. [Performance](#performance)
9. [Troubleshooting](#troubleshooting)
10. [API Reference](#api-reference)
11. [Contributing](#contributing)

---

## Overview

### What is DiSyL?

DiSyL (Declarative Ikabud Syntax Language) is a declarative templating language designed for building modern, maintainable CMS themes. It provides a component-based approach to theme development with clean syntax and powerful features.

**Key Features:**
- 🎨 **Declarative Syntax** - Describe what you want, not how to build it
- 🧩 **Component-Based** - Reusable, composable building blocks
- ⚡ **Fast** - Compiled templates with caching support
- 🔒 **Secure** - HTML escaping by default, controlled raw output
- 🌐 **Cross-CMS** - Works with WordPress, Drupal, Joomla (adapters)
- 🔗 **Cross-Instance Federation** - Query content from any CMS instance *(NEW)*
- 📱 **Responsive** - Built-in responsive design utilities

### Why DiSyL?

**Traditional PHP Templates:**
```php
<?php get_header(); ?>
<div class="container">
    <?php if (have_posts()) : while (have_posts()) : the_post(); ?>
        <article>
            <h1><?php the_title(); ?></h1>
            <div><?php the_content(); ?></div>
        </article>
    <?php endwhile; endif; ?>
</div>
<?php get_footer(); ?>
```

**DiSyL Templates:**
```disyl
{include template="components/header" /}

{ikb_section type="content"}
    {ikb_container width="md"}
        {ikb_query type="post" limit=1}
            {ikb_text size="2xl" weight="bold"}
                {item.title}
            {/ikb_text}
            {ikb_content}
                {item.content}
            {/ikb_content}
        {/ikb_query}
    {/ikb_container}
{/ikb_section}

{include template="components/footer" /}
```

**Benefits:**
- ✅ Cleaner, more readable syntax
- ✅ No mixing of HTML and PHP
- ✅ Better separation of concerns
- ✅ Easier for designers to learn
- ✅ Type-safe component attributes
- ✅ Built-in security (auto-escaping)

---

## Architecture

### System Components

```
┌─────────────────────────────────────────────────────────────┐
│                      DiSyL Engine                           │
├─────────────────────────────────────────────────────────────┤
│  Lexer → Parser → Compiler → Renderer → HTML Output        │
└─────────────────────────────────────────────────────────────┘
                              ↓
┌─────────────────────────────────────────────────────────────┐
│                    CMS Adapters                             │
├──────────────┬──────────────┬──────────────┬────────────────┤
│  WordPress   │   Drupal     │   Joomla     │  Ikabud CMS   │
└──────────────┴──────────────┴──────────────┴────────────────┘
```

### Processing Pipeline

1. **Lexer** (`Lexer.php`)
   - Tokenizes `.disyl` template files
   - Identifies tags, attributes, text, expressions
   - ~5ms for typical template

2. **Parser** (`Parser.php`)
   - Builds Abstract Syntax Tree (AST)
   - Validates nesting and structure
   - Handles nested components
   - ~10ms for typical template

3. **Compiler** (`Compiler.php`)
   - Validates components against grammar
   - Optimizes AST
   - Reports errors and warnings
   - ~5ms for typical template

4. **Renderer** (`BaseRenderer.php`, `WordPressRenderer.php`)
   - Traverses AST and generates HTML
   - Evaluates expressions
   - Applies escaping
   - ~20ms for typical template

**Total Processing Time:** ~40ms (acceptable for POC)

### File Structure

```
/kernel/DiSyL/
├── Lexer.php                 # Tokenization
├── Parser.php                # AST generation
├── Compiler.php              # Validation & optimization
├── Grammar.php               # Component definitions
├── ComponentRegistry.php     # Component management
├── KernelIntegration.php     # WordPress hooks
├── Renderers/
│   ├── BaseRenderer.php      # Core rendering logic
│   └── WordPressRenderer.php # WordPress-specific rendering
└── Exceptions/
    ├── LexerException.php
    ├── ParserException.php
    └── CompilerException.php

/instances/wp-brutus-cli/wp-content/themes/disyl-poc/
├── style.css                 # Theme metadata
├── functions.php             # DiSyL initialization
├── index.php                 # Fallback template
└── disyl/
    ├── home.disyl            # Homepage template
    ├── single.disyl          # Single post template
    ├── archive.disyl         # Archive template
    ├── page.disyl            # Page template
    └── components/
        ├── header.disyl      # Site header
        └── footer.disyl      # Site footer
```

---

## Getting Started

### Installation

#### WordPress Theme

1. **Copy DiSyL Engine Files**
   ```bash
   # DiSyL engine is in kernel, already available
   ```

2. **Create Theme Directory**
   ```bash
   cd wp-content/themes
   mkdir my-disyl-theme
   cd my-disyl-theme
   ```

3. **Create `style.css`**
   ```css
   /*
   Theme Name: My DiSyL Theme
   Description: A theme built with DiSyL
   Version: 1.0.0
   Author: Your Name
   */
   ```

4. **Create `functions.php`**
   ```php
   <?php
   // Load DiSyL engine
   require_once $_SERVER['DOCUMENT_ROOT'] . '/kernel/DiSyL/Lexer.php';
   require_once $_SERVER['DOCUMENT_ROOT'] . '/kernel/DiSyL/Parser.php';
   require_once $_SERVER['DOCUMENT_ROOT'] . '/kernel/DiSyL/Compiler.php';
   require_once $_SERVER['DOCUMENT_ROOT'] . '/kernel/DiSyL/Grammar.php';
   require_once $_SERVER['DOCUMENT_ROOT'] . '/kernel/DiSyL/ComponentRegistry.php';
   require_once $_SERVER['DOCUMENT_ROOT'] . '/kernel/DiSyL/Renderers/BaseRenderer.php';
   require_once $_SERVER['DOCUMENT_ROOT'] . '/kernel/DiSyL/Renderers/WordPressRenderer.php';
   require_once $_SERVER['DOCUMENT_ROOT'] . '/kernel/DiSyL/KernelIntegration.php';

   // Initialize DiSyL WordPress integration
   add_action('after_setup_theme', function() {
       \IkabudKernel\Core\DiSyL\KernelIntegration::initWordPress();
   });
   ```

5. **Create Template Directory**
   ```bash
   mkdir disyl
   mkdir disyl/components
   ```

6. **Create Your First Template** (`disyl/home.disyl`)
   ```disyl
   {include template="components/header" /}

   {ikb_section type="hero" bg="#667eea"}
       {ikb_container width="lg"}
           {ikb_text size="2xl" weight="bold" align="center"}
               Welcome to My Site
           {/ikb_text}
       {/ikb_container}
   {/ikb_section}

   {include template="components/footer" /}
   ```

7. **Activate Theme**
   - Go to WordPress Admin → Appearance → Themes
   - Activate "My DiSyL Theme"

### Your First Component

Create `disyl/components/header.disyl`:

```disyl
{ikb_section type="header" bg="#2c3e50"}
    {ikb_container width="lg"}
        {ikb_block cols=2 gap=2}
            {ikb_text size="xl" weight="bold" color="#fff"}
                My Site
            {/ikb_text}
            {ikb_text align="right" color="#ecf0f1"}
                Navigation here
            {/ikb_text}
        {/ikb_block}
    {/ikb_container}
{/ikb_section}
```

---

## Syntax Reference

### Basic Syntax

#### Tags
```disyl
{component_name attribute="value"}
    Content here
{/component_name}
```

#### Self-Closing Tags
```disyl
{component_name attribute="value" /}
```

#### Expressions
```disyl
{variable_name}
{object.property}
{item.title}
```

#### Comments
```disyl
{!-- This is a comment --}
```

### Expressions

#### Simple Variables
```disyl
{title}
{excerpt}
{date}
```

#### Object Properties
```disyl
{item.title}
{item.content}
{item.author}
{post.thumbnail}
```

#### In Attributes
```disyl
{ikb_card title="{item.title}" image="{item.thumbnail}" /}
```

#### In Text
```disyl
{ikb_text}
    Published on {item.date} by {item.author}
{/ikb_text}
```

### Control Structures

#### Conditional Rendering
```disyl
{if condition="item.thumbnail"}
    {ikb_image src="{item.thumbnail}" /}
{/if}
```

#### Loops
```disyl
{for items="posts" as="post"}
    {ikb_text}{post.title}{/ikb_text}
{/for}
```

#### Queries
```disyl
{ikb_query type="post" limit=10 category="news"}
    {ikb_card title="{item.title}" /}
{/ikb_query}
```

### Includes

```disyl
{include template="components/header" /}
{include template="components/sidebar" /}
{include template="components/footer" /}
```

**Note:** Includes must be self-closing with `/}`

---

## Component Library

### Layout Components

#### `ikb_section`
Container for major page sections.

```disyl
{ikb_section type="hero" bg="#667eea" padding="large"}
    Content here
{/ikb_section}
```

**Attributes:**
- `type` - Section type: `hero`, `content`, `header`, `footer`
- `bg` - Background color (hex, rgb, or named)
- `padding` - Padding size: `none`, `small`, `normal`, `large`

#### `ikb_container`
Centered content container with max-width.

```disyl
{ikb_container width="lg"}
    Content here
{/ikb_container}
```

**Attributes:**
- `width` - Max width: `sm` (640px), `md` (768px), `lg` (1024px), `xl` (1280px)

#### `ikb_block`
Grid layout for columns.

```disyl
{ikb_block cols=3 gap=2}
    {ikb_card title="Card 1" /}
    {ikb_card title="Card 2" /}
    {ikb_card title="Card 3" /}
{/ikb_block}
```

**Attributes:**
- `cols` - Number of columns: `1`, `2`, `3`, `4`
- `gap` - Gap size in rem: `0`, `1`, `2`, `3`, `4`

### Content Components

#### `ikb_text`
Styled text content.

```disyl
{ikb_text size="xl" weight="bold" color="#333" align="center"}
    Your text here
{/ikb_text}
```

**Attributes:**
- `size` - Font size: `xs`, `sm`, `md`, `lg`, `xl`, `2xl`
- `weight` - Font weight: `light`, `normal`, `medium`, `bold`
- `color` - Text color (hex, rgb, or named)
- `align` - Text alignment: `left`, `center`, `right`

#### `ikb_content`
Raw HTML content (for post content).

```disyl
{ikb_content}
    {item.content}
{/ikb_content}
```

**Security Note:** Only use for trusted content. Outputs raw HTML without escaping.

#### `ikb_image`
Responsive image.

```disyl
{ikb_image 
    src="{item.thumbnail}"
    alt="{item.title}"
    responsive=true
    lazy=true
/}
```

**Attributes:**
- `src` - Image URL
- `alt` - Alt text
- `responsive` - Make responsive: `true`, `false`
- `lazy` - Lazy loading: `true`, `false`

#### `ikb_card`
Content card with optional image and link.

```disyl
{ikb_card 
    title="{item.title}"
    image="{item.thumbnail}"
    link="{item.url}"
    variant="elevated"
}
    {ikb_text size="sm"}
        {item.excerpt}
    {/ikb_text}
{/ikb_card}
```

**Attributes:**
- `title` - Card title
- `image` - Card image URL
- `link` - Card link URL
- `variant` - Style variant: `elevated`, `outlined`, `flat`

### Data Components

#### `ikb_query`
WordPress post query.

```disyl
{ikb_query type="post" limit=10 orderby="date" order="desc" category="news"}
    {ikb_card 
        title="{item.title}"
        image="{item.thumbnail}"
        link="{item.url}"
    /}
{/ikb_query}
```

**Attributes:**
- `type` - Post type: `post`, `page`, custom post types
- `limit` - Number of posts
- `orderby` - Order by: `date`, `title`, `random`
- `order` - Sort order: `asc`, `desc`
- `category` - Category slug

**Available Variables:**
- `{item.id}` - Post ID
- `{item.title}` - Post title
- `{item.content}` - Post content (HTML)
- `{item.excerpt}` - Post excerpt
- `{item.url}` - Post permalink
- `{item.date}` - Post date
- `{item.author}` - Post author
- `{item.thumbnail}` - Featured image URL
- `{item.categories}` - Category names (array)

### Control Components

#### `if`
Conditional rendering.

```disyl
{if condition="item.thumbnail"}
    {ikb_image src="{item.thumbnail}" /}
{/if}
```

**Attributes:**
- `condition` - Variable to check for truthiness

#### `for`
Loop over items.

```disyl
{for items="posts" as="post"}
    {ikb_text}{post.title}{/ikb_text}
{/for}
```

**Attributes:**
- `items` - Array variable name
- `as` - Iterator variable name

#### `include`
Include another template.

```disyl
{include template="components/header" /}
```

**Attributes:**
- `template` - Template path (relative to `disyl/` directory)

**Note:** Must be self-closing!

---

## Cross-Instance Federation

*(New in v0.5.1)*

Cross-instance federation allows you to query content from any registered CMS instance within your DiSyL templates. This enables true multi-CMS content aggregation.

### Basic Usage

```disyl
{!-- Query from a specific instance --}
{ikb_query instance="joomla-content" type="article" limit="5"}
    <article>
        <h2>{title | esc_html}</h2>
        <p>{excerpt | truncate(150)}</p>
    </article>
{/ikb_query}

{!-- Query by CMS type (uses first matching instance) --}
{ikb_query cms="joomla" type="article" limit="5"}
    <article>
        <h2>{article.title | esc_html}</h2>
    </article>
{/ikb_query}
```

### Attributes

| Attribute | Description | Example |
|-----------|-------------|---------|
| `instance` | Instance ID to query from | `instance="joomla-content"` |
| `cms` | CMS type (finds first matching instance) | `cms="joomla"` |
| `type` | Content type | `type="article"` |
| `limit` | Number of items | `limit="10"` |
| `orderby` | Sort field | `orderby="date"` |
| `order` | Sort direction | `order="DESC"` |
| `category` | Category filter | `category="news"` |

### Common Fields

These fields work across all CMS types:

| Field | Description |
|-------|-------------|
| `title` | Content title |
| `content` | Full content |
| `excerpt` | Summary/intro text |
| `date` | Publish date |
| `modified` | Last modified date |
| `author` | Author name |
| `slug` | URL slug |
| `id` | Content ID |

### CMS-Specific Fields

**WordPress:**
```disyl
{post.ID}
{post.title}
{post.content}
{post.excerpt}
{post.thumbnail}
{post.permalink}
{post.categories}
{post.tags}
```

**Joomla:**
```disyl
{article.id}
{article.title}
{article.introtext}
{article.fulltext}
{article.alias}
{article.hits}
{article.category}
{article.images}
```

**Drupal:**
```disyl
{node.nid}
{node.title}
{node.body}
{node.type}
{node.created}
{node.changed}
{node.author}
```

### Real-World Example

```disyl
{!-- WordPress site with WooCommerce products and Joomla news --}

{ikb_section type="products" padding="large"}
    {ikb_container size="large"}
        {ikb_text tag="h2" size="2xl"}Our Products{/ikb_text}
        {ikb_grid columns="4" gap="medium"}
            {ikb_query type="product" limit="8"}
                {ikb_card}
                    {ikb_image src="{post.thumbnail | esc_url}" /}
                    {ikb_text tag="h3"}{post.title | esc_html}{/ikb_text}
                {/ikb_card}
            {/ikb_query}
        {/ikb_grid}
    {/ikb_container}
{/ikb_section}

{ikb_section type="news" padding="large"}
    {ikb_container size="large"}
        {ikb_text tag="h2" size="2xl"}Latest News from Joomla{/ikb_text}
        {ikb_grid columns="3" gap="medium"}
            {ikb_query cms="joomla" instance="joomla-news" type="article" limit="6"}
                {ikb_card}
                    {ikb_text tag="h3"}{article.title | esc_html}{/ikb_text}
                    {ikb_text}{article.introtext | truncate(120)}{/ikb_text}
                    <small>Views: {article.hits}</small>
                {/ikb_card}
            {/ikb_query}
        {/ikb_grid}
    {/ikb_container}
{/ikb_section}
```

### How It Works

1. **Detection** - When `instance=""` or `cms=""` is present, the renderer detects a cross-instance query
2. **Config Parsing** - `CrossInstanceDataProvider` reads the target instance's database config (wp-config.php, configuration.php, etc.)
3. **Connection** - A PDO connection is established (connections are pooled and cached)
4. **Query Execution** - CMS-specific SQL queries are executed
5. **Normalization** - Results are normalized to common field names
6. **Rendering** - Children are rendered with the cross-instance data in context

---

## WordPress Integration

### Template Hierarchy

DiSyL follows WordPress template hierarchy:

```
home.disyl       → Homepage
single.disyl     → Single post
page.disyl       → Static page
archive.disyl    → Archive/category/tag pages
search.disyl     → Search results
404.disyl        → 404 error page
```

### WordPress Functions

DiSyL automatically applies WordPress filters and functions:

- `the_content` filter applied to post content
- `wp_head()` and `wp_footer()` hooks included
- `get_body_class()` for body classes
- `get_language_attributes()` for HTML lang

### Theme Initialization

In `functions.php`:

```php
add_action('after_setup_theme', function() {
    // Initialize DiSyL
    \IkabudKernel\Core\DiSyL\KernelIntegration::initWordPress();
    
    // Add theme support
    add_theme_support('title-tag');
    add_theme_support('post-thumbnails');
    add_theme_support('html5', ['search-form', 'comment-form', 'comment-list', 'gallery', 'caption']);
});
```

### Context Variables

Available in all templates:

- `{site.name}` - Site title
- `{site.description}` - Site tagline
- `{site.url}` - Site URL
- `{current_user.name}` - Current user name (if logged in)

### Debugging

Enable debug mode in `wp-config.php`:

```php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
```

Check logs at: `wp-content/debug.log`

---

## Performance

### Benchmarks

**POC Performance (typical template):**
- Lexer: ~5ms
- Parser: ~10ms
- Compiler: ~5ms
- Renderer: ~20ms
- **Total: ~40ms**

**Comparison:**
- Traditional PHP template: ~15ms
- DiSyL (no cache): ~40ms (2.6x slower)
- DiSyL (with cache): ~5ms (3x faster)

### Optimization Tips

1. **Enable Caching** (coming in Phase 4)
   ```php
   define('DISYL_CACHE_ENABLED', true);
   ```

2. **Limit Query Results**
   ```disyl
   {ikb_query type="post" limit=10}  {!-- Good --}
   {ikb_query type="post" limit=100} {!-- Slow --}
   ```

3. **Use Includes Wisely**
   - Include shared components (header, footer)
   - Don't over-nest includes (max 3 levels)

4. **Minimize Expression Complexity**
   ```disyl
   {item.title}              {!-- Good --}
   {item.meta.custom.field}  {!-- Slower --}
   ```

5. **Use Conditional Rendering**
   ```disyl
   {if condition="item.thumbnail"}
       {ikb_image src="{item.thumbnail}" /}
   {/if}
   ```

---

## Troubleshooting

### Common Issues

#### 1. Template Not Rendering

**Symptom:** Blank page or fallback PHP template showing

**Solutions:**
- Check that DiSyL is initialized in `functions.php`
- Verify template file exists: `disyl/home.disyl`
- Check file permissions (644 for files, 755 for directories)
- Look for errors in `wp-content/debug.log`

#### 2. Expressions Not Interpolating

**Symptom:** Seeing `{item.title}` as literal text

**Solutions:**
- Ensure expression is inside a component
- Check that variable exists in context
- Use `ikb_content` for raw HTML, not `ikb_text`

#### 3. Parser Errors

**Symptom:** "Unexpected token" or "Mismatched closing tag"

**Solutions:**
- Ensure all tags are properly closed
- Make void components self-closing: `{ikb_image /}`
- Check for typos in component names
- Validate nesting (no `{ikb_section}` inside `{ikb_text}`)

#### 4. Nested Components Not Rendering

**Symptom:** Only first few children render

**Solutions:**
- Ensure all components are self-closing or have closing tags
- Check for unclosed `{if}` or `{for}` blocks
- Verify `{include}` tags are self-closing

#### 5. Performance Issues

**Symptom:** Slow page load times

**Solutions:**
- Reduce query limits
- Enable caching (when available)
- Minimize nested includes
- Profile with debug logging

### Debug Logging

Add to your template:

```disyl
{!-- Debug: Check if variable exists --}
{if condition="item.title"}
    {ikb_text}Title exists: {item.title}{/ikb_text}
{/if}
```

Add to `WordPressRenderer.php`:

```php
error_log('[DiSyL] Rendering: ' . $tagName);
error_log('[DiSyL] Context: ' . print_r($this->context, true));
```

---

## API Reference

### Lexer

```php
use IkabudKernel\Core\DiSyL\Lexer;

$lexer = new Lexer();
$tokens = $lexer->tokenize($template);
```

**Methods:**
- `tokenize(string $input): array` - Tokenize template string

### Parser

```php
use IkabudKernel\Core\DiSyL\Parser;

$parser = new Parser();
$ast = $parser->parse($tokens);
```

**Methods:**
- `parse(array $tokens): array` - Parse tokens into AST

### Compiler

```php
use IkabudKernel\Core\DiSyL\Compiler;

$compiler = new Compiler($grammar, $registry);
$result = $compiler->compile($ast);
```

**Methods:**
- `compile(array $ast): array` - Validate and optimize AST

### Renderer

```php
use IkabudKernel\Core\DiSyL\Renderers\WordPressRenderer;

$renderer = new WordPressRenderer();
$html = $renderer->render($ast, $context);
```

**Methods:**
- `render(array $ast, array $context = []): string` - Render AST to HTML
- `setContext(array $context): void` - Set rendering context

### Custom Components

Register a custom component:

```php
$renderer->registerComponent('my_component', function($node, $context) {
    $attrs = $node['attrs'] ?? [];
    $children = $node['children'] ?? [];
    
    $html = '<div class="my-component">';
    $html .= $this->renderChildren($children);
    $html .= '</div>';
    
    return $html;
});
```

---

## Contributing

### Development Setup

1. **Clone Repository**
   ```bash
   git clone https://github.com/ikabud/ikabud-kernel.git
   cd ikabud-kernel
   ```

2. **Install Dependencies**
   ```bash
   composer install
   ```

3. **Run Tests**
   ```bash
   vendor/bin/phpunit tests/DiSyL/
   ```

### Code Style

- Follow PSR-12 coding standards
- Use type hints for all parameters and return types
- Document all public methods with PHPDoc
- Write tests for new features

### Pull Request Process

1. Create a feature branch: `git checkout -b feature/my-feature`
2. Make your changes
3. Add tests for new functionality
4. Run tests: `vendor/bin/phpunit`
5. Commit with conventional commits: `feat: add new component`
6. Push and create PR

### Commit Message Format

```
<type>(<scope>): <subject>

<body>

<footer>
```

**Types:**
- `feat` - New feature
- `fix` - Bug fix
- `docs` - Documentation
- `style` - Code style changes
- `refactor` - Code refactoring
- `test` - Adding tests
- `chore` - Maintenance

**Examples:**
```
feat(parser): add support for multi-line expressions
fix(renderer): escape HTML in text nodes
docs(readme): update installation instructions
```

---

## License

MIT License - See LICENSE file for details

---

## Support

- **Documentation:** https://disyl.ikabud.com
- **Issues:** https://github.com/ikabud/ikabud-kernel/issues
- **Discussions:** https://github.com/ikabud/ikabud-kernel/discussions
- **Discord:** https://discord.gg/ikabud

---

**Last Updated:** November 13, 2025  
**Version:** 0.1.0  
**Status:** POC Complete ✅
