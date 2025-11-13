# DiSyL API Reference v0.1

Complete API documentation for DiSyL PHP classes.

---

## Table of Contents

1. [Lexer](#lexer)
2. [Parser](#parser)
3. [Compiler](#compiler)
4. [Grammar](#grammar)
5. [ComponentRegistry](#componentregistry)
6. [Renderers](#renderers)

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

## See Also

- [Language Reference](DISYL_LANGUAGE_REFERENCE.md)
- [Component Catalog](DISYL_COMPONENT_CATALOG.md)
- [Code Examples](DISYL_CODE_EXAMPLES.md)
