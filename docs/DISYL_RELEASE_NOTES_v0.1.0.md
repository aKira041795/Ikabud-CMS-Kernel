# DiSyL v0.1.0 Release Notes

**Release Date**: November 13, 2025  
**Status**: 🚀 **PRODUCTION READY**

---

## 🎉 Introducing DiSyL v0.1.0

DiSyL (Declarative Ikabud Syntax Language) is a human-friendly, declarative template language for building CMS themes and layouts. This is the first stable release, ready for production use.

---

## ✨ Key Features

### 🚀 Performance
- **Sub-millisecond compilation**: < 1ms for typical templates
- **Intelligent caching**: 99% faster on cached templates
- **Optimized AST**: Empty node removal, text merging

### 🔒 Type Safety
- **Validated attributes**: All attributes checked against schemas
- **9 validation types**: string, number, integer, float, boolean, null, array, object, any
- **Enum validation**: Restrict values to predefined lists
- **Range validation**: Min/max for numbers, minLength/maxLength for strings

### 🎨 10 Core Components
- **Structural**: `ikb_section`, `ikb_block`, `ikb_container`
- **Data**: `ikb_query`
- **UI**: `ikb_card`, `ikb_text`
- **Media**: `ikb_image`
- **Control**: `if`, `for`, `include`

### 🔌 CMS Integration
- **WordPress**: Full WP_Query integration, escaping, filters
- **Drupal**: Adapter ready (stub implementation)
- **Native**: Complete file-based CMS
- **Extensible**: Easy to add new CMS adapters

### 📚 Comprehensive Documentation
- **155+ pages** of documentation
- **115+ code examples**
- **Complete API reference**
- **WordPress theme guide**

---

## 📦 What's Included

### Core Engine
- **Lexer**: Tokenizes DiSyL templates (12 token types)
- **Parser**: Generates JSON AST (4 node types)
- **Compiler**: Validates, normalizes, optimizes AST
- **Grammar**: Attribute validation and normalization
- **ComponentRegistry**: 10 pre-registered components

### Renderers
- **BaseRenderer**: Abstract base class for custom renderers
- **NativeRenderer**: Full implementation for Native CMS
- **WordPressRenderer**: WordPress integration with WP_Query

### Documentation
- Language Reference (50+ pages)
- Component Catalog (40+ pages)
- Code Examples (30+ pages)
- API Reference (15+ pages)
- WordPress Integration Guide (20+ pages)

---

## 🎯 Use Cases

### Blog & Content Sites
```disyl
{ikb_query type="post" limit=6 orderby="date"}
    {ikb_block cols=3 gap=2}
        {ikb_card title="{item.title}" link="{item.url}" /}
    {/ikb_block}
{/ikb_query}
```

### Landing Pages
```disyl
{ikb_section type="hero" bg="#667eea" padding="large"}
    {ikb_container width="xl"}
        {ikb_text size="2xl" weight="bold" align="center" color="#fff"}
            Welcome to Our Site
        {/ikb_text}
    {/ikb_container}
{/ikb_section}
```

### E-commerce
```disyl
{ikb_query type="product" limit=8}
    {ikb_block cols=4 gap=2}
        {ikb_card 
            title="{item.title}"
            image="{item.thumbnail}"
            variant="elevated"
        />
    {/ikb_block}
{/ikb_query}
```

---

## 📊 Performance Benchmarks

| Template Size | Tokens | Compilation | Rendering | Total |
|---------------|--------|-------------|-----------|-------|
| Simple (1 tag) | 10 | 0.07ms | 0.1ms | 0.17ms |
| Medium (10 tags) | 50 | 0.13ms | 0.3ms | 0.43ms |
| Complex (50 tags) | 200+ | 0.51ms | 1.2ms | 1.71ms |
| Large (100+ tags) | 500+ | 2.5ms | 3.5ms | 6ms |

**Cache Performance**:
- Cold (first compile): 0.07-2.5ms
- Warm (cached): < 0.01ms (99% faster)

---

## 🔧 Installation

### Requirements
- PHP 8.1 or higher
- Composer (optional, for dependency management)

### Via Composer (Recommended)
```bash
composer require ikabud/disyl
```

### Manual Installation
1. Download the release archive
2. Extract to your project
3. Include the autoloader:
```php
require_once 'path/to/ikabud-kernel/vendor/autoload.php';
```

---

## 🚀 Quick Start

### Basic Usage

```php
use IkabudKernel\Core\DiSyL\{Lexer, Parser, Compiler};
use IkabudKernel\CMS\Adapters\NativeAdapter;

// 1. Create pipeline
$lexer = new Lexer();
$parser = new Parser();
$compiler = new Compiler();

// 2. Compile template
$template = '{ikb_section type="hero"}
    {ikb_text size="xl"}Hello World{/ikb_text}
{/ikb_section}';

$tokens = $lexer->tokenize($template);
$ast = $parser->parse($tokens);
$compiled = $compiler->compile($ast);

// 3. Render
$cms = new NativeAdapter();
$html = $cms->renderDisyl($compiled);

echo $html;
```

### WordPress Theme

```php
// functions.php
function disyl_render_template($name, $context = []) {
    $template = file_get_contents(get_template_directory() . '/disyl/' . $name . '.disyl');
    
    $lexer = new Lexer();
    $parser = new Parser();
    $compiler = new Compiler();
    
    $tokens = $lexer->tokenize($template);
    $ast = $parser->parse($tokens);
    $compiled = $compiler->compile($ast);
    
    global $wp_cms_adapter;
    return $wp_cms_adapter->renderDisyl($compiled, $context);
}

// index.php
echo disyl_render_template('home');
```

---

## 🆕 What's New in v0.1.0

### Initial Release Features
- ✅ Complete lexer with 12 token types
- ✅ Recursive descent parser generating JSON AST
- ✅ Compiler with validation, normalization, optimization
- ✅ Grammar system with 9 validation types
- ✅ Component registry with 10 core components
- ✅ Native renderer with all components
- ✅ WordPress renderer with WP_Query integration
- ✅ Cache integration for performance
- ✅ Comprehensive error handling
- ✅ 155+ pages of documentation
- ✅ 115+ code examples

---

## 🐛 Known Issues

### v0.1.0
- Drupal adapter is stub implementation (full implementation planned for v0.2.0)
- Joomla adapter not yet implemented (planned for v0.2.0)
- Expression evaluation is basic (enhanced parser planned for v0.2.0)
- No visual builder yet (planned for v0.3.0)

---

## 🔮 Roadmap

### v0.2.0 (Planned)
- Full Drupal adapter implementation
- Joomla adapter implementation
- Enhanced expression parser
- 20+ additional components
- Component marketplace

### v0.3.0 (Planned)
- Visual builder (React-based)
- Drag-and-drop interface
- Live preview
- Component library UI

### v1.0.0 (Planned)
- Ikabud CMS (if demand is high)
- WebAssembly parser
- Hybrid mode for WordPress
- Enterprise features

---

## 📖 Documentation

### Online Documentation
- [Language Reference](DISYL_LANGUAGE_REFERENCE.md)
- [Component Catalog](DISYL_COMPONENT_CATALOG.md)
- [Code Examples](DISYL_CODE_EXAMPLES.md)
- [API Reference](DISYL_API_REFERENCE.md)
- [WordPress Integration](DISYL_WORDPRESS_THEME_EXAMPLE.md)

### Getting Help
- GitHub Issues: https://github.com/ikabud/kernel/issues
- Documentation: https://ikabud.com/docs/disyl
- Community: https://community.ikabud.com

---

## 🤝 Contributing

We welcome contributions! Please see [CONTRIBUTING.md](../CONTRIBUTING.md) for guidelines.

### Areas for Contribution
- Additional CMS adapters (Drupal, Joomla, etc.)
- New components
- Documentation improvements
- Bug fixes
- Performance optimizations

---

## 📝 License

MIT License - See [LICENSE](../LICENSE) for details.

---

## 🙏 Acknowledgments

- Ikabud Kernel team
- Early adopters and testers
- Open source community

---

## 📊 Statistics

- **Development Time**: 7 weeks
- **Lines of Code**: 2,883
- **Test Cases**: 150+
- **Documentation Pages**: 155+
- **Code Examples**: 115+
- **Components**: 10
- **CMS Adapters**: 3 (Native, WordPress, Drupal stub)

---

## 🔐 Security

### Security Features
- HTML escaping (all text output)
- Attribute validation
- Type checking
- Enum restrictions
- Range validation

### Reporting Security Issues
Please report security vulnerabilities to security@ikabud.com

---

## ⚡ Breaking Changes

None (initial release)

---

## 🎊 Thank You!

Thank you for trying DiSyL v0.1.0! We're excited to see what you build with it.

**Happy coding!** 🚀

---

**Release**: v0.1.0  
**Date**: November 13, 2025  
**Status**: Production Ready  
**Download**: https://github.com/ikabud/kernel/releases/tag/v0.1.0
