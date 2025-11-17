# DiSyL Architecture: Extensible Declarative Syntax

**Date:** November 18, 2025  
**Version:** 0.6.0

## Core Philosophy

**DiSyL is an extensible declarative syntax language that maintains simplicity yet produces elegant code.**

### Design Principles

1. **Extensibility First**: Each CMS can define custom components without modifying core
2. **Declarative Over Imperative**: Write what you want, not how to achieve it
3. **Simplicity in Syntax**: Clean, readable, easy to learn
4. **Elegance in Output**: Produces maintainable, beautiful code
5. **Adaptation When Needed**: Direct CMS integration when abstractions become complex

## Two-Tier Architecture

DiSyL uses a layered approach that balances **portability** with **power**:

```
┌──────────────────────────────────────────────────────────┐
│                  CORE DiSyL LAYER                        │
│                                                          │
│  Universal Components:                                   │
│  - Layout: ikb_text, ikb_section, ikb_container        │
│  - Control: if/else, include                           │
│  - Data: ikb_query (generic abstraction)               │
│                                                          │
│  Universal Filters:                                      │
│  - Security: esc_html, esc_url, esc_attr              │
│  - Text: strip_tags, truncate, upper, lower           │
│                                                          │
│  ✓ Works everywhere                                     │
│  ✓ Theme portability                                    │
│  ✗ May be complex for specific needs                   │
└──────────────────────────────────────────────────────────┘
                           ↓
┌──────────────────────────────────────────────────────────┐
│              CMS-SPECIFIC EXTENSION LAYER                │
│                                                          │
│  Drupal Manifest:                                        │
│  - drupal_articles, drupal_menu, drupal_region          │
│  - drupal_block, drupal_view, drupal_form               │
│  - raw_html (unescaped content)                         │
│                                                          │
│  WordPress Manifest:                                     │
│  - wp_posts, wp_menu, wp_sidebar                        │
│  - wp_widget, wp_shortcode                              │
│                                                          │
│  Joomla Manifest:                                        │
│  - joomla_articles, joomla_menu, joomla_module          │
│                                                          │
│  ✓ Optimized for specific CMS                           │
│  ✓ Simple, direct implementation                        │
│  ✗ Not portable across CMSs                            │
└──────────────────────────────────────────────────────────┘
```

## When to Use Each Layer

### Use Core Components When:
- Building portable themes that work across multiple CMSs
- Layout and styling are the primary concerns
- Generic data queries are sufficient
- Theme will be distributed to unknown environments

**Example:**
```disyl
{ikb_section padding="large"}
    {ikb_text size="2xl" weight="bold"}
        <h1>{page.title | esc_html}</h1>
    {/ikb_text}
{/ikb_section}
```

### Use CMS-Specific Components When:
- Building for a single CMS
- Need direct access to CMS features
- Generic abstractions are too complex
- Performance is critical

**Example:**
```disyl
{!-- Drupal-specific: Direct, simple, fast --}
{drupal_articles limit=6 /}

{!-- vs Generic: Complex, many failure points --}
{ikb_query type="post" limit=6}
    {!-- Template for each item --}
{/ikb_query}
```

## Manifest-Driven Development

Each CMS provides manifests that define available components and filters:

### Component Manifest Structure

```json
{
  "components": {
    "drupal_articles": {
      "description": "Render a list of Drupal articles",
      "attributes": {
        "limit": {
          "type": "integer",
          "required": false,
          "default": 6
        }
      },
      "example": "{drupal_articles limit=6 /}"
    }
  }
}
```

### Filter Manifest Structure

```json
{
  "filters": {
    "raw": {
      "description": "Output raw HTML without escaping",
      "category": "security",
      "parameters": ["value"],
      "returns": "string",
      "php": "{value}"
    }
  }
}
```

## Implementation Strategy

### 1. Start Generic
Begin with core components for maximum portability:

```disyl
{ikb_query type="post" limit=6}
    <article>
        <h2>{item.title | esc_html}</h2>
        <p>{item.excerpt | strip_tags}</p>
    </article>
{/ikb_query}
```

### 2. Identify Pain Points
If generic approach becomes complex or fragile:
- Too many edge cases
- Performance issues
- Maintenance burden
- Frequent bugs

### 3. Create CMS-Specific Component
Build direct integration:

```php
protected function renderDrupalArticles(array $node, array $context): string
{
    // Direct Drupal entity query
    $query = \Drupal::entityQuery('node')
        ->condition('type', 'article')
        ->condition('status', 1)
        ->sort('created', 'DESC')
        ->range(0, $limit);
    
    $nids = $query->execute();
    $nodes = \Drupal::entityTypeManager()
        ->getStorage('node')
        ->loadMultiple($nids);
    
    // Direct HTML rendering
    $output = '';
    foreach ($nodes as $node) {
        $output .= $this->renderArticleCard($node);
    }
    
    return $output;
}
```

### 4. Register in Manifest
Make it discoverable:

```json
{
  "components": {
    "drupal_articles": {
      "description": "Render Drupal articles with optimized query",
      "attributes": {
        "limit": {"type": "integer", "default": 6}
      }
    }
  }
}
```

### 5. Use in Templates
Simple, elegant syntax:

```disyl
{drupal_articles limit=6 /}
```

## Benefits of This Architecture

### For Theme Developers
- **Choice**: Use portable or optimized components
- **Simplicity**: Clean syntax regardless of approach
- **Power**: Direct CMS access when needed
- **Gradual Migration**: Start generic, optimize later

### For CMS Integrators
- **Extensibility**: Add components without core changes
- **Maintainability**: Isolated, testable components
- **Performance**: Optimize critical paths
- **Documentation**: Self-documenting via manifests

### For End Users
- **Reliability**: Components work as expected
- **Performance**: Fast page loads
- **Flexibility**: Choose themes that fit needs
- **Consistency**: Familiar syntax across themes

## Real-World Example: Article Listing

### The Journey

**Attempt 1: Generic ikb_query**
```disyl
{ikb_query type="post" limit=6}
    {!-- Complex template with filters --}
    <span>{item.date | date:format="M j, Y"}</span>
{/ikb_query}
```
**Result**: ❌ Filter system issues, cache problems, access control conflicts

**Attempt 2: CMS-Specific drupal_articles**
```disyl
{drupal_articles limit=6 /}
```
**Result**: ✅ Works immediately, simple, fast, maintainable

### Lesson Learned

**When abstractions become more complex than the problem they solve, go direct.**

This is not a failure of the generic approach—it's the **strength** of an extensible architecture that allows adaptation.

## Future: Rich DiSyL Ecosystem

As more CMS-specific components are created, DiSyL becomes a **rich lexer** with:

1. **Universal Syntax**: Same learning curve everywhere
2. **CMS Optimization**: Best-in-class performance per platform
3. **Community Extensions**: Developers share manifests
4. **Visual Builder**: Drag-drop components from any manifest
5. **Type Safety**: Manifest validation at compile time

## Conclusion

**DiSyL is not just a templating language—it's an extensible ecosystem.**

- Core provides simplicity and portability
- Manifests enable power and optimization
- Adaptation strategy ensures real-world success
- Result: Elegant code that actually works

This architecture positions DiSyL as a true "write once, adapt anywhere" language that doesn't sacrifice pragmatism for purity.
