# DiSyL Language Reference v0.1
**Declarative Ikabud Syntax Language**

Complete language specification and reference guide.

---

## Table of Contents

1. [Introduction](#introduction)
2. [Syntax Overview](#syntax-overview)
3. [Token Types](#token-types)
4. [Tag Syntax](#tag-syntax)
5. [Attributes](#attributes)
6. [Expressions](#expressions)
7. [Comments](#comments)
8. [Core Components](#core-components)
9. [Control Structures](#control-structures)
10. [Best Practices](#best-practices)

---

## Introduction

DiSyL (Declarative Ikabud Syntax Language) is a human-friendly, declarative template language designed for building CMS themes and layouts. It compiles to an Abstract Syntax Tree (AST) and renders to HTML through CMS-specific adapters.

### Design Goals

- **Declarative**: Describe what you want, not how to build it
- **Type-safe**: Validated attributes with schemas
- **CMS-agnostic**: Works with WordPress, Drupal, Joomla, Native
- **Fast**: Sub-millisecond compilation and rendering
- **Extensible**: Custom components and renderers

### Hello World

```disyl
{ikb_section type="hero"}
    {ikb_text size="xl" weight="bold"}Hello World{/ikb_text}
{/ikb_section}
```

---

## Syntax Overview

### Basic Structure

DiSyL uses curly braces `{}` for tags, similar to template languages like Handlebars or Twig.

```disyl
{component_name attribute="value"}
    Content here
{/component_name}
```

### Self-Closing Tags

Components without children can be self-closed:

```disyl
{ikb_image src="logo.png" alt="Logo" /}
```

### Nesting

Components can be nested to any depth:

```disyl
{ikb_section}
    {ikb_container}
        {ikb_block}
            {ikb_card /}
        {/ikb_block}
    {/ikb_container}
{/ikb_section}
```

---

## Token Types

DiSyL recognizes the following token types:

| Token | Description | Example |
|-------|-------------|---------|
| `LBRACE` | Opening brace | `{` |
| `RBRACE` | Closing brace | `}` |
| `SLASH` | Forward slash | `/` |
| `IDENT` | Identifier | `ikb_section`, `title` |
| `EQUAL` | Equals sign | `=` |
| `STRING` | String literal | `"Hello World"` |
| `NUMBER` | Number (int/float) | `123`, `3.14` |
| `BOOL` | Boolean | `true`, `false` |
| `NULL` | Null value | `null` |
| `TEXT` | Raw text | `Hello World` |
| `COMMENT` | Comment | `{!-- comment --}` |

---

## Tag Syntax

### Opening Tags

```disyl
{component_name}
```

### Closing Tags

```disyl
{/component_name}
```

### Self-Closing Tags

```disyl
{component_name /}
```

### Tags with Attributes

```disyl
{component_name attr1="value1" attr2=123 attr3=true}
```

### Tag Names

Tag names must:
- Start with a letter or underscore
- Contain only letters, numbers, underscores, hyphens, colons, or dots
- Be case-sensitive

**Valid**: `ikb_section`, `custom-component`, `ns:component`, `v1.0`  
**Invalid**: `123component`, `-component`, `component name`

---

## Attributes

### Attribute Syntax

```disyl
{component name="value"}
```

### Attribute Types

#### String

```disyl
{ikb_section title="Welcome to Our Site"}
```

Strings must be enclosed in double quotes. Escape sequences:
- `\"` - Double quote
- `\\` - Backslash
- `\n` - Newline
- `\t` - Tab

```disyl
{ikb_text}He said \"Hello\"{/ikb_text}
```

#### Number

```disyl
{ikb_block cols=3}
{ikb_block gap=1.5}
{ikb_query limit=-10}
```

Numbers can be:
- Integers: `123`, `-456`
- Floats: `3.14`, `-2.5`

#### Boolean

```disyl
{ikb_image lazy=true responsive=false}
```

Only `true` and `false` (lowercase) are recognized.

#### Null

```disyl
{ikb_card icon=null}
```

Use `null` to explicitly set no value.

### Multiple Attributes

```disyl
{ikb_section type="hero" title="Welcome" bg="#f0f0f0" padding="large"}
```

Attributes are space-separated.

### Attribute Validation

Attributes are validated against component schemas:

```disyl
{ikb_section type="hero"}        <!-- Valid: hero is in enum -->
{ikb_section type="invalid"}     <!-- Error: invalid not in enum -->
{ikb_block cols=5}               <!-- Valid: 5 is in range 1-12 -->
{ikb_block cols=20}              <!-- Error: 20 exceeds max 12 -->
```

---

## Expressions

### Variable Interpolation

Use expressions to insert dynamic values:

```disyl
{ikb_card title="{item.title}" link="{item.url}"}
```

### Dot Notation

Access nested properties:

```disyl
{item.author.name}
{post.meta.views}
```

### Context Variables

Variables come from the rendering context:

```disyl
{ikb_query type="post" limit=5}
    {ikb_card title="{item.title}"}  <!-- item provided by ikb_query -->
{/ikb_query}
```

---

## Comments

### Syntax

```disyl
{!-- This is a comment --}
```

### Multiline Comments

```disyl
{!--
    This is a
    multiline comment
--}
```

### Usage

Comments are removed during compilation and do not appear in output:

```disyl
{ikb_section}
    {!-- TODO: Add hero image --}
    {ikb_text}Welcome{/ikb_text}
{/ikb_section}
```

---

## Core Components

DiSyL v0.1 includes 10 core components:

### Structural Components

#### ikb_section

Main structural container for page sections.

**Attributes**:
- `type`: `"hero"` | `"content"` | `"footer"` | `"sidebar"` (default: `"content"`)
- `title`: string (optional)
- `bg`: string (default: `"transparent"`)
- `padding`: `"none"` | `"small"` | `"normal"` | `"large"` (default: `"normal"`)

**Example**:
```disyl
{ikb_section type="hero" title="Welcome" bg="#f0f0f0" padding="large"}
    Content here
{/ikb_section}
```

#### ikb_block

Generic content block with layout options.

**Attributes**:
- `cols`: integer 1-12 (default: `1`)
- `gap`: number 0-10 (default: `1`)
- `align`: `"left"` | `"center"` | `"right"` | `"justify"` (default: `"left"`)

**Example**:
```disyl
{ikb_block cols=3 gap=2 align="center"}
    {ikb_card /}
    {ikb_card /}
    {ikb_card /}
{/ikb_block}
```

#### ikb_container

Responsive container with max-width.

**Attributes**:
- `width`: `"sm"` | `"md"` | `"lg"` | `"xl"` | `"full"` (default: `"lg"`)
- `center`: boolean (default: `true`)

**Example**:
```disyl
{ikb_container width="xl" center=true}
    Content here
{/ikb_container}
```

### Data Components

#### ikb_query

Query and loop over content items.

**Attributes**:
- `type`: string (default: `"post"`)
- `limit`: integer 1-100 (default: `10`)
- `orderby`: `"date"` | `"title"` | `"modified"` | `"random"` (default: `"date"`)
- `order`: `"asc"` | `"desc"` (default: `"desc"`)
- `category`: string (optional)

**Example**:
```disyl
{ikb_query type="post" limit=6 orderby="date" order="desc"}
    {ikb_card title="{item.title}" link="{item.url}" /}
{/ikb_query}
```

**Item Context**:
- `item.id` - Content ID
- `item.title` - Title
- `item.content` - Full content
- `item.excerpt` - Excerpt
- `item.url` - Permalink
- `item.date` - Publish date
- `item.author` - Author name
- `item.thumbnail` - Featured image URL
- `item.categories` - Category names

### UI Components

#### ikb_card

Card component for displaying content.

**Attributes**:
- `title`: string (optional)
- `image`: string (optional)
- `link`: string (optional)
- `variant`: `"default"` | `"outlined"` | `"elevated"` (default: `"default"`)

**Example**:
```disyl
{ikb_card title="Card Title" image="image.jpg" link="/post" variant="elevated"}
    {ikb_text}Card content here{/ikb_text}
{/ikb_card}
```

#### ikb_text

Text content with formatting.

**Attributes**:
- `size`: `"xs"` | `"sm"` | `"md"` | `"lg"` | `"xl"` | `"2xl"` (default: `"md"`)
- `weight`: `"light"` | `"normal"` | `"medium"` | `"bold"` (default: `"normal"`)
- `color`: string (optional)
- `align`: `"left"` | `"center"` | `"right"` | `"justify"` (default: `"left"`)

**Example**:
```disyl
{ikb_text size="xl" weight="bold" align="center" color="#333"}
    Hello World
{/ikb_text}
```

### Media Components

#### ikb_image

Responsive image with optimization.

**Attributes**:
- `src`: string (required)
- `alt`: string (required)
- `width`: integer (optional)
- `height`: integer (optional)
- `lazy`: boolean (default: `true`)
- `responsive`: boolean (default: `true`)

**Example**:
```disyl
{ikb_image src="logo.png" alt="Logo" width=200 height=100 lazy=true /}
```

### Control Components

#### if

Conditional rendering.

**Attributes**:
- `condition`: string (required)

**Example**:
```disyl
{if condition="user.loggedIn"}
    {ikb_text}Welcome back!{/ikb_text}
{/if}
```

#### for

Loop over items.

**Attributes**:
- `items`: string (required)
- `as`: string (default: `"item"`)

**Example**:
```disyl
{for items="posts" as="post"}
    {ikb_card title="{post.title}" /}
{/for}
```

#### include

Include another template.

**Attributes**:
- `template`: string (required)

**Example**:
```disyl
{include template="header.disyl"}
```

---

## Control Structures

### Conditional Rendering

```disyl
{if condition="item.thumbnail"}
    {ikb_image src="{item.thumbnail}" alt="{item.title}" /}
{/if}
```

### Loops

```disyl
{for items="posts" as="post"}
    {ikb_card title="{post.title}" /}
{/for}
```

### Nested Conditions

```disyl
{if condition="user.loggedIn"}
    {if condition="user.isAdmin"}
        {ikb_text}Admin Panel{/ikb_text}
    {/if}
{/if}
```

---

## Best Practices

### 1. Use Semantic Components

```disyl
<!-- Good -->
{ikb_section type="hero"}
    {ikb_container width="lg"}
        {ikb_text size="2xl"}Title{/ikb_text}
    {/ikb_container}
{/ikb_section}

<!-- Avoid -->
{ikb_block}
    {ikb_block}
        {ikb_text}Title{/ikb_text}
    {/ikb_block}
{/ikb_block}
```

### 2. Provide Alt Text for Images

```disyl
<!-- Good -->
{ikb_image src="logo.png" alt="Company Logo" /}

<!-- Bad -->
{ikb_image src="logo.png" alt="" /}
```

### 3. Use Appropriate Text Sizes

```disyl
{ikb_text size="2xl" weight="bold"}Heading{/ikb_text}
{ikb_text size="md"}Body text{/ikb_text}
{ikb_text size="sm" color="#666"}Caption{/ikb_text}
```

### 4. Leverage Defaults

```disyl
<!-- Explicit -->
{ikb_section type="content" bg="transparent" padding="normal"}

<!-- Implicit (same result) -->
{ikb_section}
```

### 5. Keep Nesting Reasonable

```disyl
<!-- Good: 3-4 levels -->
{ikb_section}
    {ikb_container}
        {ikb_block}
            {ikb_card /}
        {/ikb_block}
    {/ikb_container}
{/ikb_section}

<!-- Avoid: Too deep -->
{ikb_section}
    {ikb_container}
        {ikb_block}
            {ikb_block}
                {ikb_block}
                    {ikb_card /}
                {/ikb_block}
            {/ikb_block}
        {/ikb_block}
    {/ikb_container}
{/ikb_section}
```

### 6. Use Comments for Clarity

```disyl
{!-- Hero Section --}
{ikb_section type="hero"}
    {!-- Main heading --}
    {ikb_text size="2xl"}Welcome{/ikb_text}
{/ikb_section}

{!-- Content Grid --}
{ikb_section type="content"}
    {ikb_query type="post" limit=6}
        {ikb_block cols=3}
            {ikb_card title="{item.title}" /}
        {/ikb_block}
    {/ikb_query}
{/ikb_section}
```

### 7. Validate Before Deploying

Always test templates in development before deploying to production. Use the compiler's error messages to fix issues.

---

## Error Messages

### Common Errors

**Invalid Enum Value**:
```
Parameter "type" must be one of [hero, content, footer, sidebar], got "invalid"
```

**Out of Range**:
```
Parameter "cols" must be >= 1 and <= 12, got 20
```

**Required Attribute Missing**:
```
Parameter "src" is required
```

**Unknown Component**:
```
Unknown component: custom_component
```

---

## Version History

### v0.1.0 (Current)
- Initial release
- 10 core components
- Basic control structures
- WordPress, Drupal, Native CMS support

---

## See Also

- [Component Catalog](DISYL_COMPONENT_CATALOG.md)
- [Code Examples](DISYL_CODE_EXAMPLES.md)
- [WordPress Integration](DISYL_WORDPRESS_THEME_EXAMPLE.md)
- [API Reference](DISYL_API_REFERENCE.md)
