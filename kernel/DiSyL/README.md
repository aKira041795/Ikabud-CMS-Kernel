# DiSyL - Declarative Ikabud Syntax Language

**Version:** 0.5.0  
**Namespace:** `IkabudKernel\Core\DiSyL`  
**License:** MIT

---

## 🎯 Overview

DiSyL (Declarative Ikabud Syntax Language) is a universal, CMS-agnostic template language that enables developers to write templates once and deploy them across multiple CMS platforms (WordPress, Joomla, Drupal, and Ikabud CMS).

### Key Features

✨ **Universal Templates**
- Write once, deploy everywhere
- CMS-agnostic component system
- Consistent syntax across platforms

⚡ **High Performance**
- Compiled AST with caching
- Optimized rendering pipeline
- Lazy loading support

🎨 **Rich Component Library**
- Layout components (sections, containers, grids)
- Content components (text, images, buttons)
- CMS-specific integrations (menus, widgets, modules)

🔒 **Security First**
- Built-in XSS prevention
- Sanitized outputs
- Secure by default

---

## 📦 Architecture

DiSyL follows a dual-layer architecture:

### Layer 1: Kernel DiSyL Engine (`/kernel/DiSyL/`)
- Core parser, compiler, and component registry
- Serves all CMS adapters (WordPress, Drupal, Joomla, Ikabud CMS)
- Universal cross-CMS abstraction layer

### Layer 2: CMS Renderers
- **WordPressRenderer** - WordPress-specific rendering
- **JoomlaRenderer** - Joomla-specific rendering
- **DrupalRenderer** - Drupal-specific rendering (planned)
- **NativeRenderer** - Ikabud CMS native rendering

---

## 🏗️ Components

### Core Components

```
kernel/DiSyL/
├── Engine.php                    # Main DiSyL engine
├── Lexer.php                     # Tokenization
├── Parser.php                    # AST generation
├── Compiler.php                  # AST compilation
├── Token.php                     # Token definitions
├── Grammar.php                   # Grammar rules
├── ParserError.php              # Error handling
├── KernelIntegration.php        # Kernel integration
├── ManifestLoader.php           # Manifest loading
├── ModularManifestLoader.php    # Modular manifests
├── ComponentRegistry.php        # Component registry
├── Renderers/
│   ├── BaseRenderer.php         # Base renderer
│   ├── WordPressRenderer.php    # WordPress renderer
│   ├── JoomlaRenderer.php       # Joomla renderer
│   ├── NativeRenderer.php       # Native renderer
│   └── ManifestDrivenRenderer.php
├── Exceptions/
│   ├── LexerException.php
│   ├── ParserException.php
│   └── CompilerException.php
├── Manifests/                   # Component manifests
└── manifest.schema.json         # Manifest schema
```

---

## 🚀 Usage

### Basic Usage

```php
use IkabudKernel\Core\DiSyL\Engine;
use IkabudKernel\Core\DiSyL\Renderers\WordPressRenderer;

// Initialize engine
$engine = new Engine();

// Create renderer
$renderer = new WordPressRenderer();

// Compile and render
$template = file_get_contents('template.disyl');
$context = ['site' => ['name' => 'My Site']];
$html = $engine->compileAndRender($template, $renderer, $context);

echo $html;
```

### WordPress Integration

```php
// In WordPress theme functions.php
require_once '/path/to/ikabud-kernel/vendor/autoload.php';

use IkabudKernel\Core\DiSyL\Engine;
use IkabudKernel\Core\DiSyL\Renderers\WordPressRenderer;

function render_disyl_template($template_file, $context = []) {
    $engine = new Engine();
    $renderer = new WordPressRenderer();
    return $engine->renderFile($template_file, $renderer, $context);
}
```

### Joomla Integration

```php
// In Joomla template
use IkabudKernel\Core\DiSyL\Engine;
use IkabudKernel\Core\DiSyL\Renderers\JoomlaRenderer;

class PhoenixDisylIntegration {
    private $engine;
    private $renderer;
    
    public function __construct() {
        $this->engine = new Engine();
        $this->renderer = new JoomlaRenderer();
    }
    
    public function render($templateFile, $context) {
        return $this->engine->renderFile($templateFile, $this->renderer, $context);
    }
}
```

---

## 📝 DiSyL Syntax

### Basic Syntax

```disyl
{!-- Comment --}

{ikb_section type="hero" padding="large"}
    {ikb_container size="xlarge"}
        {ikb_text size="3xl" weight="bold"}
            Welcome to {site.name | esc_html}
        {/ikb_text}
    {/ikb_container}
{/ikb_section}
```

### Components

#### Layout Components

```disyl
{!-- Section --}
{ikb_section type="hero" padding="large" background="gradient"}
    Content here
{/ikb_section}

{!-- Container --}
{ikb_container size="large"}
    Content here
{/ikb_container}

{!-- Grid --}
{ikb_grid columns="3" gap="medium"}
    {ikb_card}Card 1{/ikb_card}
    {ikb_card}Card 2{/ikb_card}
    {ikb_card}Card 3{/ikb_card}
{/ikb_grid}
```

#### Content Components

```disyl
{!-- Text --}
{ikb_text size="xl" weight="bold" align="center"}
    Heading Text
{/ikb_text}

{!-- Button --}
{ikb_button href="/contact" variant="primary" size="large"}
    Contact Us
{/ikb_button}

{!-- Image --}
{ikb_image src="{post.thumbnail | esc_url}" alt="{post.title | esc_attr}" /}
```

#### Query Components

```disyl
{!-- Loop through posts/articles --}
{ikb_query type="post" limit="6" category="news"}
    <article>
        <h2>{item.title | esc_html}</h2>
        <p>{item.excerpt | wp_trim_words:num_words=30}</p>
        <a href="{item.url | esc_url}">Read More</a>
    </article>
{/ikb_query}
```

#### Menu Components

```disyl
{!-- Navigation menu --}
{ikb_menu location="primary" class="main-nav"}
```

#### Widget/Module Areas

```disyl
{!-- WordPress widgets --}
{ikb_widget_area id="sidebar-1" class="sidebar"}

{!-- Joomla modules --}
{joomla_module position="sidebar-left" style="card"}
```

### Conditional Rendering

```disyl
{if condition="post.thumbnail"}
    {ikb_image src="{post.thumbnail | esc_url}" alt="{post.title | esc_attr}" /}
{/if}

{if condition="!user.logged_in"}
    <a href="/login">Login</a>
{/if}
```

### Filters

```disyl
{!-- Escape HTML --}
{post.title | esc_html}

{!-- Escape URL --}
{post.url | esc_url}

{!-- Escape attribute --}
{post.title | esc_attr}

{!-- Date formatting --}
{post.date | date:format='F j, Y'}

{!-- Trim words --}
{post.excerpt | wp_trim_words:num_words=20}

{!-- Uppercase --}
{post.title | upper}

{!-- Lowercase --}
{post.title | lower}
```

---

## 🎨 CMS-Specific Renderers

### WordPressRenderer

Provides WordPress-specific components:
- `ikb_query` - WP_Query integration
- `ikb_menu` - WordPress navigation menus
- `ikb_widget_area` - Widget areas
- WordPress filters (wp_trim_words, etc.)

### JoomlaRenderer

Provides Joomla-specific components:
- `ikb_query` - Joomla articles
- `ikb_menu` - Joomla menus
- `joomla_module` - Module positions
- `joomla_component` - Component output
- `joomla_message` - System messages

### NativeRenderer

Provides native Ikabud CMS components:
- File-based content
- Git-friendly storage
- JAMstack rendering

---

## 🔧 Creating Custom Renderers

Extend `BaseRenderer` to create custom renderers:

```php
namespace MyNamespace;

use IkabudKernel\Core\DiSyL\Renderers\BaseRenderer;

class MyCustomRenderer extends BaseRenderer
{
    /**
     * Render custom component
     */
    protected function renderMyComponent(array $node, array $attrs, array $children): string
    {
        $content = $this->renderChildren($children);
        return "<div class=\"my-component\">{$content}</div>";
    }
    
    /**
     * Override base rendering if needed
     */
    protected function renderIkbSection(array $node, array $attrs, array $children): string
    {
        // Custom section rendering
        return parent::renderIkbSection($node, $attrs, $children);
    }
}
```

---

## 📊 Performance

### Caching

DiSyL supports AST caching for improved performance:

```php
// With cache
$cache = new MyCache(); // Implement cache interface
$engine = new Engine($cache);

// Templates are compiled once and cached
$html = $engine->compileAndRender($template, $renderer, $context);
```

### Benchmarks

- **Compilation:** ~0.20ms per template
- **Rendering:** ~1-5ms depending on complexity
- **Cached:** ~0.05ms (AST cache hit)

---

## 🧪 Testing

Run DiSyL tests:

```bash
# Unit tests
php vendor/bin/phpunit tests/DiSyL/

# Integration tests
php tests/disyl-integration-test.php
```

---

## 📚 Examples

### Complete Theme Example

See the Phoenix theme for complete implementation:
- **WordPress:** `/instances/wp-brutus-cli/wp-content/themes/phoenix/`
- **Joomla:** `/instances/jml-joomla-the-beginning/templates/phoenix/`

### Template Examples

```disyl
{!-- Homepage Hero --}
{ikb_section type="hero" padding="xlarge"}
    {ikb_container size="large"}
        {ikb_text tag="h1" size="4xl" weight="bold" align="center"}
            {site.name | esc_html}
        {/ikb_text}
        {ikb_text tag="p" size="xl" align="center"}
            {site.description | esc_html}
        {/ikb_text}
        {ikb_button href="/about" variant="primary" size="large"}
            Learn More
        {/ikb_button}
    {/ikb_container}
{/ikb_section}

{!-- Blog Grid --}
{ikb_section type="content" padding="large"}
    {ikb_container size="large"}
        {ikb_grid columns="3" gap="large"}
            {ikb_query type="post" limit="6"}
                {ikb_card variant="elevated" padding="medium"}
                    {if condition="item.thumbnail"}
                        {ikb_image src="{item.thumbnail | esc_url}" alt="{item.title | esc_attr}" /}
                    {/if}
                    {ikb_text tag="h3" size="xl" weight="bold"}
                        {item.title | esc_html}
                    {/ikb_text}
                    {ikb_text tag="p"}
                        {item.excerpt | wp_trim_words:num_words=30}
                    {/ikb_text}
                    {ikb_button href="{item.url | esc_url}" variant="secondary"}
                        Read More
                    {/ikb_button}
                {/ikb_card}
            {/ikb_query}
        {/ikb_grid}
    {/ikb_container}
{/ikb_section}
```

---

## 🛣️ Roadmap

### Current Version (0.5.0)
- ✅ Core engine with lexer, parser, compiler
- ✅ WordPress renderer
- ✅ Joomla renderer
- ✅ Component registry
- ✅ Manifest system

### Upcoming (0.6.0)
- 🔄 Drupal renderer
- 🔄 Enhanced caching
- 🔄 Visual builder integration
- 🔄 Component marketplace

### Future (1.0.0)
- 📋 WebAssembly parser for client-side rendering
- 📋 Hybrid mode plugin for WordPress
- 📋 Real-time preview
- 📋 Component versioning

---

## 🤝 Contributing

Contributions are welcome! Please follow these guidelines:

1. Fork the repository
2. Create a feature branch
3. Write tests for new features
4. Submit a pull request

---

## 📄 License

DiSyL is licensed under the MIT License.

```
Copyright (c) 2025 Ikabud Team

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.
```

---

## 🌟 Credits

- **Created by:** Ikabud Team
- **Inspired by:** Twig, Liquid, Blade
- **Philosophy:** "Write once, deploy everywhere"

---

**Built with ❤️ for the CMS community**
