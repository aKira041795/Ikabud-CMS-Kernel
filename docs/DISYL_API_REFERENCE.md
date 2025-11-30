# DiSyL API Reference v0.5.1

Complete API documentation for DiSyL PHP classes and REST endpoints.

**Last Updated:** November 30, 2025

---

## Table of Contents

1. [Lexer](#lexer)
2. [Parser](#parser)
3. [Compiler](#compiler)
4. [Grammar](#grammar)
5. [ComponentRegistry](#componentregistry)
6. [Renderers](#renderers)
7. [CrossInstanceDataProvider](#crossinstancedataprovider) *(NEW)*
8. [REST API Endpoints](#rest-api-endpoints) *(NEW)*

---

## Lexer

**Namespace**: `IkabudKernel\Core\DiSyL\Lexer`

### Methods

#### `tokenize(string $input): array`

Tokenizes a DiSyL template string into an array of tokens.

**Parameters**:
- `$input` (string): DiSyL template string

**Returns**: `array<Token>` - Array of Token objects

**Throws**: `LexerException` - If lexical error occurs

**Example**:
```php
use IkabudKernel\Core\DiSyL\Lexer;

$lexer = new Lexer();
$tokens = $lexer->tokenize('{ikb_section}');
```

---

## Parser

**Namespace**: `IkabudKernel\Core\DiSyL\Parser`

### Methods

#### `parse(array $tokens): array`

Parses tokens into an Abstract Syntax Tree (AST).

**Parameters**:
- `$tokens` (array): Array of Token objects from Lexer

**Returns**: `array` - JSON AST structure

**Throws**: `ParserException` - If parsing fails

**Example**:
```php
use IkabudKernel\Core\DiSyL\{Lexer, Parser};

$lexer = new Lexer();
$parser = new Parser();

$tokens = $lexer->tokenize($template);
$ast = $parser->parse($tokens);
```

---

## Compiler

**Namespace**: `IkabudKernel\Core\DiSyL\Compiler`

### Constructor

#### `__construct(?Cache $cache = null)`

**Parameters**:
- `$cache` (Cache|null): Optional cache instance

### Methods

#### `compile(array $ast, array $context = []): array`

Compiles and validates AST.

**Parameters**:
- `$ast` (array): AST from Parser
- `$context` (array): Compilation context

**Returns**: `array` - Compiled AST with metadata

**Example**:
```php
use IkabudKernel\Core\DiSyL\Compiler;

$compiler = new Compiler();
$compiled = $compiler->compile($ast);
```

#### `getErrors(): array`

Returns compilation errors.

#### `getWarnings(): array`

Returns compilation warnings.

#### `hasErrors(): bool`

Checks if compilation has errors.

#### `hasWarnings(): bool`

Checks if compilation has warnings.

---

## Grammar

**Namespace**: `IkabudKernel\Core\DiSyL\Grammar`

### Methods

#### `validate(mixed $value, array $schema): bool`

Validates a value against a schema.

**Parameters**:
- `$value` (mixed): Value to validate
- `$schema` (array): Validation schema

**Returns**: `bool` - True if valid

#### `normalize(mixed $value, array $schema): mixed`

Normalizes a value (applies defaults, coerces types).

**Parameters**:
- `$value` (mixed): Value to normalize
- `$schema` (array): Schema with defaults

**Returns**: `mixed` - Normalized value

#### `validateAttributes(array $attributes, array $schemas): array`

Validates multiple attributes.

**Returns**: `array` - Array of error messages (empty if valid)

#### `normalizeAttributes(array $attributes, array $schemas): array`

Normalizes multiple attributes.

**Returns**: `array` - Normalized attributes

---

## ComponentRegistry

**Namespace**: `IkabudKernel\Core\DiSyL\ComponentRegistry`

### Static Methods

#### `register(string $name, array $definition): void`

Registers a component.

**Parameters**:
- `$name` (string): Component name
- `$definition` (array): Component definition

**Example**:
```php
use IkabudKernel\Core\DiSyL\ComponentRegistry;

ComponentRegistry::register('custom_button', [
    'category' => ComponentRegistry::CATEGORY_UI,
    'attributes' => [
        'label' => ['type' => 'string', 'required' => true]
    ]
]);
```

#### `get(string $name): ?array`

Gets component definition.

#### `has(string $name): bool`

Checks if component exists.

#### `all(): array`

Gets all registered components.

#### `getByCategory(string $category): array`

Gets components by category.

#### `getAttributeSchemas(string $name): array`

Gets attribute schemas for a component.

---

## Renderers

### BaseRenderer

**Namespace**: `IkabudKernel\Core\DiSyL\Renderers\BaseRenderer`

#### `render(array $ast, array $context = []): string`

Renders AST to HTML.

**Parameters**:
- `$ast` (array): Compiled AST
- `$context` (array): Rendering context

**Returns**: `string` - Rendered HTML

#### `registerComponent(string $name, callable $renderer): void`

Registers a custom component renderer.

**Example**:
```php
$renderer->registerComponent('custom_tag', function($node, $context) {
    return '<div class="custom">' . $node['attrs']['content'] . '</div>';
});
```

### NativeRenderer

**Namespace**: `IkabudKernel\Core\DiSyL\Renderers\NativeRenderer`

Renders for Native Ikabud CMS.

### WordPressRenderer

**Namespace**: `IkabudKernel\Core\DiSyL\Renderers\WordPressRenderer`

Renders for WordPress with WP_Query integration.

---

## Complete Example

```php
use IkabudKernel\Core\DiSyL\{Lexer, Parser, Compiler};
use IkabudKernel\CMS\Adapters\NativeAdapter;

// 1. Lexer
$lexer = new Lexer();
$tokens = $lexer->tokenize($template);

// 2. Parser
$parser = new Parser();
$ast = $parser->parse($tokens);

// 3. Compiler
$compiler = new Compiler();
$compiled = $compiler->compile($ast);

// Check for errors
if ($compiler->hasErrors()) {
    foreach ($compiler->getErrors() as $error) {
        echo $error['message'];
    }
}

// 4. Render
$cms = new NativeAdapter();
$html = $cms->renderDisyl($compiled);

echo $html;
```

---

## CrossInstanceDataProvider

**Namespace**: `IkabudKernel\Core\DiSyL\CrossInstanceDataProvider`

*(New in v0.5.1)*

Global service for fetching data from any registered CMS instance, enabling cross-instance content federation.

### Methods

#### `init(?string $instancesPath = null): void`

Initialize the provider with the instances directory path.

**Parameters**:
- `$instancesPath` (string|null): Path to instances directory (defaults to `/instances`)

**Example**:
```php
use IkabudKernel\Core\DiSyL\CrossInstanceDataProvider;

CrossInstanceDataProvider::init('/var/www/html/ikabud-kernel/instances');
```

#### `isCrossInstanceQuery(array $attrs): bool`

Check if query attributes indicate a cross-instance query.

**Parameters**:
- `$attrs` (array): Query attributes

**Returns**: `bool` - True if `instance` or `cms` attribute is present

#### `query(array $attrs): array`

Execute a cross-instance query.

**Parameters**:
- `$attrs` (array): Query attributes including:
  - `instance` (string): Target instance ID
  - `cms` (string): Target CMS type (wordpress, joomla, drupal)
  - `type` (string): Content type (post, article, node)
  - `limit` (int): Number of items
  - `orderby` (string): Sort field
  - `order` (string): Sort direction (ASC, DESC)
  - `category` (string): Category filter

**Returns**: `array` - Array of normalized content items

**Example**:
```php
use IkabudKernel\Core\DiSyL\CrossInstanceDataProvider;

CrossInstanceDataProvider::init();

$items = CrossInstanceDataProvider::query([
    'cms' => 'joomla',
    'instance' => 'joomla-content',
    'type' => 'article',
    'limit' => 5,
    'orderby' => 'created',
    'order' => 'DESC'
]);

foreach ($items as $item) {
    echo $item['title'];           // Common field
    echo $item['article']['hits']; // Joomla-specific field
}
```

### Returned Data Structure

Each item includes:

**Common Fields** (all CMS types):
```php
[
    'id' => 123,
    'title' => 'Article Title',
    'content' => 'Full content...',
    'excerpt' => 'Summary text...',
    'date' => '2025-11-30 14:00:00',
    'modified' => '2025-11-30 15:00:00',
    'author' => 'John Doe',
    'slug' => 'article-title',
    'status' => 'publish',
    'type' => 'article',
    '_source' => [
        'instance' => 'joomla-content',
        'cms' => 'joomla'
    ]
]
```

**CMS-Specific Fields**:
- WordPress: `post.*` (ID, title, content, excerpt, thumbnail, permalink)
- Joomla: `article.*` (id, title, introtext, fulltext, alias, hits, images)
- Drupal: `node.*` (nid, title, body, type, created, changed)

---

## REST API Endpoints

### Filesystem API

#### List Theme Files

```
GET /api/v1/filesystem/instances/{instanceId}/themes/{themeName}/files
```

**Response**:
```json
{
  "success": true,
  "data": {
    "files": [
      {
        "name": "home.disyl",
        "path": "disyl/home.disyl",
        "type": "file",
        "size": 2048
      },
      {
        "name": "components",
        "path": "disyl/components",
        "type": "directory",
        "children": [...]
      }
    ]
  }
}
```

#### Read File

```
GET /api/v1/filesystem/instances/{instanceId}/themes/{themeName}/files/{path}
```

**Response**:
```json
{
  "success": true,
  "data": {
    "content": "{ikb_section type=\"hero\"}\n  ...\n{/ikb_section}",
    "path": "disyl/home.disyl",
    "language": "disyl"
  }
}
```

#### Write File

```
PUT /api/v1/filesystem/instances/{instanceId}/themes/{themeName}/files/{path}
Content-Type: application/json

{
  "content": "{ikb_section type=\"hero\"}\n  Updated content\n{/ikb_section}"
}
```

**Response**:
```json
{
  "success": true,
  "message": "File saved successfully"
}
```

#### Create File

```
POST /api/v1/filesystem/instances/{instanceId}/themes/{themeName}/files
Content-Type: application/json

{
  "path": "disyl/new-template.disyl",
  "content": "{!-- New template --}"
}
```

#### Delete File

```
DELETE /api/v1/filesystem/instances/{instanceId}/themes/{themeName}/files/{path}
```

### Instance Context API

#### Get Database Context

```
GET /api/v1/filesystem/instances/{instanceId}/context
```

Returns database schema and sample data for autocomplete.

**Response**:
```json
{
  "success": true,
  "data": {
    "cms_type": "wordpress",
    "instance_name": "wp-main",
    "variables": {
      "site": ["name", "description", "url", "admin_email"],
      "post": ["ID", "title", "content", "excerpt", "thumbnail", "permalink"],
      "user": ["ID", "login", "email", "display_name", "logged_in"],
      "query": ["found_posts", "max_num_pages", "current_page"]
    },
    "filters": [
      {"name": "esc_html", "description": "Escape HTML entities"},
      {"name": "esc_attr", "description": "Escape for attributes"},
      {"name": "esc_url", "description": "Escape URLs"},
      {"name": "truncate", "description": "Truncate text", "params": ["length"]},
      {"name": "date", "description": "Format date", "params": ["format"]}
    ],
    "operators": ["==", "!=", ">", "<", ">=", "<=", "&&", "||", "!"],
    "cms_specific": {
      "post_types": ["post", "page", "product", "attachment"],
      "taxonomies": ["category", "post_tag", "product_cat"],
      "menus": ["primary", "footer", "mobile"],
      "widgets": ["sidebar-1", "footer-1", "footer-2"],
      "categories": [
        {"id": 1, "name": "Uncategorized", "slug": "uncategorized"},
        {"id": 2, "name": "News", "slug": "news"}
      ]
    }
  }
}
```

### Theme Generation API

#### Generate Theme

```
POST /api/theme/generate
Content-Type: application/json

{
  "instance_id": "wp-main",
  "theme_name": "phoenix",
  "cms_type": "wordpress",
  "options": {
    "include_assets": true,
    "minify_css": false,
    "generate_screenshot": true
  }
}
```

**Response**:
```json
{
  "success": true,
  "message": "Theme generated successfully",
  "data": {
    "theme_path": "/instances/wp-main/wp-content/themes/phoenix",
    "files_created": 15,
    "warnings": []
  }
}
```

#### Export Theme

```
GET /api/theme/export/{instanceId}/{themeName}?format=zip
```

Returns downloadable ZIP file of the theme.

---

## See Also

- [Theme Builder Guide](THEME_BUILDER_GUIDE.md)
- [Language Reference](DISYL_LANGUAGE_REFERENCE.md)
- [Component Catalog](DISYL_COMPONENT_CATALOG.md)
- [Code Examples](DISYL_CODE_EXAMPLES.md)
