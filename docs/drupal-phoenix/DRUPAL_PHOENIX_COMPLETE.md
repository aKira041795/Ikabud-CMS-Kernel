# Drupal Phoenix Theme - Complete Implementation Guide

## Overview

The Phoenix theme is a fully functional Drupal 10/11 theme powered by DiSyL (Declarative Ikabud Syntax Language), demonstrating complete cross-CMS compatibility. This theme showcases DiSyL's ability to work seamlessly with Drupal's architecture while maintaining a clean, modern design.

**Status**: ✅ Production Ready  
**Site URL**: http://genesis.test  
**Version**: 2.0 (Drupal-Native)

---

## Features

### ✅ Complete DiSyL Integration

1. **Core Components**
   - `ikb_text` - Text styling and typography
   - `ikb_container` - Responsive containers
   - `ikb_section` - Page sections with padding/spacing
   - `ikb_image` - Optimized image rendering with lazy loading
   - `ikb_include` - Template composition
   - `ikb_query` - Data fetching and loops

2. **Drupal-Specific Components**
   - `drupal_block` - Render Drupal blocks
   - `drupal_region` - Render Drupal regions
   - `drupal_menu` - Render Drupal menus
   - `drupal_view` - Render Drupal views
   - `drupal_form` - Render Drupal forms

3. **Filter System**
   - `esc_html` - HTML escaping for security
   - `esc_url` - URL sanitization
   - `esc_attr` - Attribute escaping
   - `date:format="M j, Y"` - Date formatting with custom formats
   - `truncate:length=150,append="..."` - Text truncation
   - `strip_tags` - HTML tag removal
   - `t` - Translation wrapper

4. **Conditional Rendering**
   - `{if condition="item.thumbnail"}` - Simple truthy checks
   - Comparison operators: `>=`, `<=`, `==`, `!=`, `>`, `<`
   - Logical operators: `||` (OR), `&&` (AND)
   - Expression evaluation in conditions

5. **Expression System**
   - Variable interpolation: `{item.title}`, `{site.name}`
   - Nested properties: `{item.author.name}`
   - Filter chains: `{item.date | date:format="M j, Y" | esc_html}`

---

## Architecture

### File Structure

```
instances/dpl-now-drupal/themes/phoenix/
├── assets/
│   ├── css/
│   │   ├── style.css              # Main theme styles
│   │   ├── disyl-components.css   # DiSyL component styles
│   │   └── customizer-dynamic.css # Dynamic customizer styles
│   ├── js/
│   │   └── theme.js               # Theme JavaScript
│   └── images/
├── disyl/
│   ├── components/
│   │   ├── header.disyl           # Site header
│   │   ├── footer.disyl           # Site footer
│   │   ├── sidebar.disyl          # Sidebar widget area
│   │   ├── slider.disyl           # Homepage slider
│   │   └── comments.disyl         # Comment section
│   ├── home.disyl                 # Homepage template
│   ├── single.disyl               # Single post template
│   ├── page.disyl                 # Page template
│   ├── archive.disyl              # Archive template
│   ├── blog.disyl                 # Blog listing
│   ├── category.disyl             # Category archive
│   ├── search.disyl               # Search results
│   └── 404.disyl                  # 404 error page
├── includes/
│   └── disyl-integration.php      # DiSyL integration layer
├── templates/
│   └── page.html.twig             # Drupal page template
├── phoenix.info.yml               # Theme metadata
├── phoenix.libraries.yml          # Asset libraries
├── phoenix.theme                  # Theme hooks and preprocessing
├── logo.svg                       # Theme logo
└── screenshot.png                 # Theme screenshot
```

### DiSyL Kernel Integration

```
kernel/DiSyL/
├── Engine.php                     # DiSyL compiler/renderer
├── Parser.php                     # Template parser
├── Compiler.php                   # AST compiler
├── Renderers/
│   ├── BaseRenderer.php           # Base renderer with filter system
│   ├── DrupalRenderer.php         # Drupal-specific renderer (545 lines)
│   ├── WordPressRenderer.php      # WordPress renderer
│   └── JoomlaRenderer.php         # Joomla renderer
└── ComponentRegistry.php          # Component registration
```

---

## Implementation Details

### 1. DiSyL Integration (`includes/disyl-integration.php`)

**Purpose**: Bridge between Drupal and DiSyL engine

**Key Functions**:

```php
function phoenix_render_disyl($template_name, array $context = [])
```
- Loads DiSyL template files
- Initializes DrupalRenderer
- Merges Drupal context with template context
- Returns rendered HTML as Drupal Markup

```php
function phoenix_get_drupal_context()
```
- Provides Drupal-specific context variables
- Site configuration (name, slogan, base_url)
- User information (logged_in, user_id, user_name)
- Current route and node information

**Path Resolution**:
```php
$drupal_root = \Drupal::root();
$theme_path = \Drupal::service('extension.list.theme')->getPath('phoenix');
$kernel_path = dirname($drupal_root) . '/kernel';
```

### 2. Theme Hooks (`phoenix.theme`)

**Preprocess Functions**:

```php
function phoenix_preprocess_page(&$variables)
```
- Determines which DiSyL template to render
- Routes: front page → `home.disyl`, article → `single.disyl`, page → `page.disyl`
- Adds layout classes based on sidebar configuration
- Injects DiSyL content into `$variables['disyl_content']`

**Template Routing Logic**:
```php
if ($is_front) {
    $variables['disyl_content'] = phoenix_render_disyl('home');
}
elseif ($node = $route_match->getParameter('node')) {
    if ($node->bundle() == 'article') {
        $variables['disyl_content'] = phoenix_render_disyl('single');
    }
    else {
        $variables['disyl_content'] = phoenix_render_disyl('page');
    }
}
```

### 3. DrupalRenderer Implementation

**Location**: `kernel/DiSyL/Renderers/DrupalRenderer.php`

**Key Features**:

#### Component Registration
```php
protected function registerCoreComponents(): void
{
    // ikb_query - Fetch and loop through Drupal nodes
    $this->registerComponent('ikb_query', function($node, $context) {
        $type = $attrs['type'] ?? 'post';
        $limit = isset($attrs['limit']) ? (int)$attrs['limit'] : 10;
        
        // Query Drupal nodes
        $query = \Drupal::entityTypeManager()->getStorage('node')->getQuery()
            ->condition('type', $bundle)
            ->condition('status', 1)
            ->sort('created', 'DESC')
            ->range(0, $limit)
            ->accessCheck(TRUE);
        
        // Loop through results and render children
        foreach ($nodes as $node_entity) {
            $item_context = [
                'id' => $node_entity->id(),
                'title' => $node_entity->getTitle(),
                'url' => $node_entity->toUrl()->toString(),
                'date' => $node_entity->getCreatedTime(),
                'author' => $node_entity->getOwner()->getDisplayName(),
                'thumbnail' => // ... field_image handling
                'excerpt' => // ... body field handling
            ];
            
            $output .= $this->renderChildren($children, $item_context);
        }
    });
}
```

#### Drupal Component Rendering
```php
protected function renderDrupalBlock(array $node, array $context): string
{
    $block_id = $attrs['id'] ?? '';
    $block = \Drupal\block\Entity\Block::load($block_id);
    $render = \Drupal::entityTypeManager()
        ->getViewBuilder('block')
        ->view($block);
    return \Drupal::service('renderer')->renderPlain($render);
}
```

#### Filter System
```php
protected function registerDrupalFilters(): void
{
    // Date formatting with custom formats
    $this->registerFilter('date', function($value, ...$args) {
        $format = $args[0]['format'] ?? 'medium';
        if (is_numeric($value)) {
            if (strpos($format, ' ') !== false) {
                return date($format, $value);
            }
            return \Drupal::service('date.formatter')->format($value, $format);
        }
        return $value;
    });
    
    // Truncate with named parameters
    $this->registerFilter('truncate', function($value, ...$args) {
        $length = $args[0]['length'] ?? 100;
        $append = $args[0]['append'] ?? '...';
        return mb_substr($value, 0, $length) . $append;
    });
}
```

#### Conditional Rendering
```php
protected function renderIf(array $node, array $attrs, array $children): string
{
    $condition = $attrs['condition'] ?? '';
    $result = $this->evaluateCondition($condition);
    
    if ($result) {
        return $this->renderChildren($children);
    }
    return '';
}

protected function evaluateCondition(string $condition): bool
{
    // Handle operators: ||, &&, >=, <=, ==, !=, >, <
    // Evaluate expressions and compare
    // Return boolean result
}
```

### 4. BaseRenderer Filter System

**Location**: `kernel/DiSyL/Renderers/BaseRenderer.php`

**Filter Registration**:
```php
protected array $filters = [];

public function registerFilter(string $name, callable $callback): void
{
    $this->filters[$name] = $callback;
}

public function applyFilter(string $name, mixed $value, array $params = []): mixed
{
    if (!isset($this->filters[$name])) {
        return $value;
    }
    return call_user_func($this->filters[$name], $value, ...$params);
}
```

**Filter Chain Processing**:
```php
protected function applyFilters($value, string $filterChain)
{
    $filters = explode('|', $filterChain);
    
    foreach ($filters as $filter) {
        // Parse: date:format="M j, Y"
        if (strpos($filter, ':') !== false) {
            list($filterName, $paramStr) = explode(':', $filter, 2);
            $params = $this->parseFilterArguments($paramStr);
            $value = $this->applyFilter($filterName, $value, $params);
        } else {
            $value = $this->applyFilter($filter, $value);
        }
    }
    
    return $value;
}
```

---

## Template Examples

### Home Template (`disyl/home.disyl`)

```disyl
{!-- Phoenix Theme v2 - Homepage Template --}
{ikb_include template="components/header.disyl" /}

{!-- Hero Section --}
{ikb_section type="hero" class="hero-section" padding="large"}
    {drupal_region name="hero" /}
{/ikb_section}

{!-- Features Section --}
{ikb_section type="features" id="features" padding="large"}
    {ikb_container size="xlarge"}
        <div class="section-header">
            {ikb_text size="3xl" weight="bold" class="gradient-text"}
                <h3>Powerful Features</h3>
            {/ikb_text}
        </div>
        
        <div class="card-grid">
            {!-- Feature cards... --}
        </div>
    {/ikb_container}
{/ikb_section}

{!-- Latest Blog Posts --}
{ikb_section type="blog" id="blog" padding="xlarge"}
    {ikb_container size="xlarge"}
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
                            <a href="{item.url | esc_url}">{item.title | esc_html}</a>
                        {/ikb_text}
                        
                        {ikb_text class="post-excerpt"}
                            {item.excerpt | strip_tags | truncate:length=150,append="..."}
                        {/ikb_text}
                    </div>
                </article>
            {/ikb_query}
        </div>
    {/ikb_container}
{/ikb_section}

{ikb_include template="components/footer.disyl" /}
```

### Header Component (`disyl/components/header.disyl`)

```disyl
{!-- Phoenix Theme v2 - Header Component --}
<header class="site-header sticky-header">
    <div class="header-container">
        {!-- Site Branding --}
        <div class="site-branding">
            {drupal_block id="phoenix_site_branding" /}
        </div>
        
        {!-- Header Region --}
        {drupal_region name="header" /}
        
        {!-- Mobile Menu Toggle --}
        <button class="menu-toggle" aria-label="Toggle navigation">
            <span class="menu-icon">☰</span>
        </button>
        
        {!-- Primary Navigation --}
        <nav class="main-nav" role="navigation">
            {drupal_region name="primary_menu" /}
        </nav>
        
        {!-- Search Block --}
        <div class="header-search">
            {drupal_block id="phoenix_search_form_narrow" /}
        </div>
    </div>
</header>
```

### Single Post Template (`disyl/single.disyl`)

```disyl
{!-- Phoenix Theme v2 - Single Post Template --}
{ikb_include template="components/header.disyl" /}

<article class="single-post">
    {ikb_container size="large"}
        {!-- Post Header --}
        <header class="post-header">
            {ikb_text size="4xl" weight="bold" class="post-title"}
                <h1>{node.title | esc_html}</h1>
            {/ikb_text}
            
            <div class="post-meta">
                <span class="post-date">{node.created | date:format="F j, Y"}</span>
                <span class="post-author">By {node.author | esc_html}</span>
            </div>
        </header>
        
        {!-- Featured Image --}
        {if condition="node.field_image"}
            {ikb_image 
                src="{node.field_image | esc_url}"
                alt="{node.title | esc_attr}"
                class="featured-image"
            /}
        {/if}
        
        {!-- Post Content --}
        <div class="post-content">
            {drupal_region name="content" /}
        </div>
        
        {!-- Comments --}
        {if condition="node.comment_count > 0"}
            {ikb_include template="components/comments.disyl" /}
        {/if}
    {/ikb_container}
</article>

{ikb_include template="components/footer.disyl" /}
```

---

## Configuration

### Theme Info (`phoenix.info.yml`)

```yaml
name: Phoenix
type: theme
description: 'Modern, gradient-rich theme powered by DiSyL'
package: Custom
core_version_requirement: ^10 || ^11
base theme: false

# Theme libraries
libraries:
  - phoenix/global
  - phoenix/animations

# Regions
regions:
  header: 'Header'
  primary_menu: 'Primary Menu'
  hero: 'Hero'
  slider: 'Slider'
  highlighted: 'Highlighted'
  breadcrumb: 'Breadcrumb'
  content: 'Content'
  sidebar_first: 'Left Sidebar'
  sidebar_second: 'Right Sidebar'
  content_bottom: 'Content Bottom'
  footer_first: 'Footer First'
  footer_second: 'Footer Second'
  footer_third: 'Footer Third'
  footer_fourth: 'Footer Fourth'
  footer: 'Footer'
```

### Libraries (`phoenix.libraries.yml`)

```yaml
global:
  version: 2.0
  css:
    theme:
      assets/css/style.css: {}
      assets/css/disyl-components.css: {}
  js:
    assets/js/theme.js: {}

animations:
  version: 2.0
  css:
    theme:
      assets/css/animations.css: {}
```

---

## Usage Guide

### 1. Installation

```bash
# Theme is already in place at:
cd /var/www/html/ikabud-kernel/instances/dpl-now-drupal/themes/phoenix

# Enable the theme via Drush
drush theme:enable phoenix
drush config-set system.theme default phoenix

# Or enable via admin UI:
# Navigate to: /admin/appearance
# Click "Install and set as default" for Phoenix theme
```

### 2. Creating Custom Templates

Create a new DiSyL template:

```bash
cd themes/phoenix/disyl
nano my-custom-template.disyl
```

Example custom template:
```disyl
{ikb_include template="components/header.disyl" /}

{ikb_section padding="large"}
    {ikb_container size="medium"}
        <h1>My Custom Page</h1>
        
        {!-- Query custom content type --}
        {ikb_query type="my_content_type" limit=10}
            <div class="item">
                <h2>{item.title | esc_html}</h2>
                <p>{item.excerpt | truncate:length=200}</p>
            </div>
        {/ikb_query}
    {/ikb_container}
{/ikb_section}

{ikb_include template="components/footer.disyl" /}
```

Render it in your theme:
```php
// In phoenix.theme
function phoenix_preprocess_page(&$variables) {
    // ... existing code ...
    
    if ($route_match->getRouteName() == 'my.custom.route') {
        $variables['disyl_content'] = phoenix_render_disyl('my-custom-template');
    }
}
```

### 3. Adding Custom Components

Register a custom component in DrupalRenderer:

```php
// In kernel/DiSyL/Renderers/DrupalRenderer.php
protected function registerCoreComponents(): void
{
    // ... existing components ...
    
    // Custom component
    $this->registerComponent('my_custom_component', function($node, $context) {
        $attrs = $node['attrs'] ?? [];
        $children = $node['children'] ?? [];
        
        // Your component logic here
        $output = '<div class="my-component">';
        $output .= $this->renderChildren($children);
        $output .= '</div>';
        
        return $output;
    });
}
```

Use it in templates:
```disyl
{my_custom_component attr="value"}
    Content here
{/my_custom_component}
```

### 4. Adding Custom Filters

Register a custom filter:

```php
// In kernel/DiSyL/Renderers/DrupalRenderer.php
protected function registerDrupalFilters(): void
{
    // ... existing filters ...
    
    // Custom filter
    $this->registerFilter('my_filter', function($value, ...$args) {
        // Your filter logic
        return strtoupper($value);
    });
}
```

Use it in templates:
```disyl
{item.title | my_filter}
```

---

## Testing

### Verify Installation

```bash
# Check theme is enabled
drush theme:list | grep phoenix

# Clear caches
drush cache:rebuild

# Visit the site
curl -s http://genesis.test | grep "Phoenix"
```

### Test DiSyL Rendering

```bash
# Check for DiSyL components in output
curl -s http://genesis.test | grep -E "(ikb-section|ikb-container)"

# Check for conditional rendering
curl -s http://genesis.test | grep "post-card"

# Check logs for errors
drush watchdog:show --type=phoenix --count=10
```

### Debug Mode

Enable debug logging in `disyl-integration.php`:

```php
function phoenix_render_disyl($template_name, array $context = []) {
    // Add at start of function
    \Drupal::logger('phoenix')->debug('Rendering template: @name', [
        '@name' => $template_name
    ]);
    
    // ... rest of function ...
    
    // Add before return
    \Drupal::logger('phoenix')->debug('Output length: @len', [
        '@len' => strlen($output)
    ]);
}
```

---

## Performance

### Optimization Tips

1. **Enable Drupal Caching**
   ```bash
   drush config-set system.performance css.preprocess 1
   drush config-set system.performance js.preprocess 1
   ```

2. **DiSyL Template Caching**
   - DiSyL templates are compiled on first render
   - Compiled output is cached by Drupal's render cache
   - Clear cache when templates change: `drush cache:rebuild`

3. **Image Optimization**
   - Use `lazy=true` attribute on `ikb_image` components
   - Configure Drupal image styles for responsive images

4. **Query Optimization**
   - Limit query results: `{ikb_query type="post" limit=6}`
   - Use appropriate indexes on custom fields

---

## Troubleshooting

### Common Issues

**Issue**: DiSyL content not rendering

**Solution**:
```bash
# Clear all caches
drush cache:rebuild

# Check for PHP errors
drush watchdog:show --type=php --count=20

# Verify template file exists
ls -la themes/phoenix/disyl/home.disyl
```

**Issue**: Filters not working

**Solution**:
- Verify filter is registered in `registerDrupalFilters()`
- Check filter syntax: `{value | filter:param="value"}`
- Clear cache after adding new filters

**Issue**: Conditionals not evaluating

**Solution**:
- Verify condition syntax: `{if condition="item.field"}`
- Check that field exists in context
- Use simple truthy checks for existence tests

**Issue**: Query returns no results

**Solution**:
```bash
# Check if content exists
drush sql:query "SELECT nid, title, type FROM node_field_data WHERE type='article' LIMIT 5"

# Verify content type mapping in ikb_query
# type="post" maps to bundle="article"
```

---

## Migration from Joomla

### Key Changes

1. **Component Mapping**
   - `joomla_module` → `drupal_block` or `drupal_region`
   - `joomla_params` → Drupal configuration system
   - `joomla_menu` → `drupal_menu`

2. **Context Variables**
   - `joomla.*` → `drupal.*` or `node.*`
   - Module positions → Drupal regions
   - Joomla articles → Drupal nodes

3. **Template Structure**
   - Remove `<jdoc:include>` tags
   - Use `drupal_region` for content areas
   - Maintain component-based structure

### Migration Checklist

- [ ] Replace all `joomla_*` components with `drupal_*` equivalents
- [ ] Update context variable references
- [ ] Map module positions to Drupal regions
- [ ] Test all conditionals with Drupal data
- [ ] Verify query results with Drupal content types
- [ ] Update filter usage for Drupal-specific needs
- [ ] Test all templates with real Drupal content

---

## Best Practices

### 1. Template Organization

- Keep components in `disyl/components/` directory
- Use descriptive template names
- One template per page type
- Reuse components via `ikb_include`

### 2. Security

- Always escape output: `{value | esc_html}`
- Use appropriate filters: `esc_url` for URLs, `esc_attr` for attributes
- Never output raw user input without filtering
- Leverage Drupal's XSS protection

### 3. Performance

- Limit query results to necessary items
- Use lazy loading for images
- Minimize nested queries
- Cache expensive operations

### 4. Maintainability

- Comment complex logic
- Use consistent naming conventions
- Keep templates focused and simple
- Document custom components and filters

---

## Resources

### Documentation
- DiSyL Syntax: `/docs/disyl/SYNTAX.md`
- Component Reference: `/docs/disyl/COMPONENTS.md`
- Filter Reference: `/docs/disyl/FILTERS.md`

### Code Locations
- DiSyL Engine: `/kernel/DiSyL/`
- DrupalRenderer: `/kernel/DiSyL/Renderers/DrupalRenderer.php`
- Theme Files: `/instances/dpl-now-drupal/themes/phoenix/`

### Support
- GitHub Issues: [Repository URL]
- Documentation: [Documentation URL]
- Community: [Community URL]

---

## Changelog

### Version 2.0 (Current)
- ✅ Complete Drupal integration
- ✅ All DiSyL components implemented
- ✅ Filter system with 7 filters
- ✅ Conditional rendering support
- ✅ Query system for Drupal nodes
- ✅ Expression evaluation with filter chains
- ✅ All templates refactored from Joomla
- ✅ Production-ready implementation

### Version 1.0 (Joomla)
- Initial Phoenix theme for Joomla
- Basic DiSyL integration
- Joomla-specific components

---

## License

[Your License Here]

---

## Credits

**Theme**: Phoenix v2.0  
**Engine**: DiSyL (Declarative Ikabud Syntax Language)  
**CMS**: Drupal 10/11  
**Status**: Production Ready ✅

---

*Last Updated: November 17, 2025*
