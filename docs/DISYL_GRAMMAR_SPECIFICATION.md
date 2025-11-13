# DiSyL Grammar Specification v0.1

**Version:** 0.1.0  
**Date:** November 13, 2025  
**Status:** Formal Specification

---

## Table of Contents

1. [Overview](#overview)
2. [Lexical Structure](#lexical-structure)
3. [Syntax Rules](#syntax-rules)
4. [Component Types](#component-types)
5. [Expression Syntax](#expression-syntax)
6. [Error Handling](#error-handling)
7. [Examples](#examples)
8. [Validation Rules](#validation-rules)

---

## Overview

DiSyL (Declarative Ikabud Syntax Language) is a component-based templating language with declarative syntax. This document defines the formal grammar and syntax rules for DiSyL v0.1.

### Design Principles

1. **Declarative** - Describe what, not how
2. **Component-Based** - Everything is a component
3. **Type-Safe** - Attributes are validated
4. **Secure** - HTML escaping by default
5. **Readable** - Clean, minimal syntax

### File Extension

DiSyL templates use the `.disyl` file extension.

---

## Lexical Structure

### Tokens

DiSyL recognizes the following token types:

```
LBRACE          {
RBRACE          }
SLASH           /
EQUAL           =
DOT             .
COMMA           ,
LPAREN          (
RPAREN          )
IDENT           [a-zA-Z_][a-zA-Z0-9_]*
STRING          "..." or '...'
NUMBER          [0-9]+ or [0-9]+\.[0-9]+
BOOLEAN         true | false
COMMENT_START   {!--
COMMENT_END     --}
TEXT            any characters outside tags
EOF             end of file
```

### Reserved Keywords

**Control Structures:**
- `if` - Conditional rendering
- `for` - Loop iteration
- `include` - Template inclusion

**Boolean Literals:**
- `true`
- `false`

**Component Prefix:**
- `ikb_` - All component names must start with this prefix

### Identifiers

**Valid Identifiers:**
```
identifier ::= [a-zA-Z_][a-zA-Z0-9_]*
```

**Examples:**
- ✅ `title`
- ✅ `item_title`
- ✅ `postTitle`
- ✅ `_private`
- ❌ `123invalid` (starts with digit)
- ❌ `my-var` (contains hyphen)

### Component Names

**Format:**
```
component_name ::= "ikb_" identifier
```

**Examples:**
- ✅ `ikb_section`
- ✅ `ikb_text`
- ✅ `ikb_card`
- ❌ `section` (missing prefix)
- ❌ `IKB_Section` (wrong case)

---

## Syntax Rules

### 1. Tags

#### Self-Closing Tags

Used for void components (no children).

**Syntax:**
```disyl
{component_name attribute="value" /}
```

**Examples:**
```disyl
{ikb_image src="photo.jpg" alt="Photo" /}
{ikb_input type="text" name="username" /}
{include template="header" /}
```

**Rules:**
- MUST end with `/}`
- Cannot have children
- Attributes are optional

#### Paired Tags

Used for components with children.

**Syntax:**
```disyl
{component_name attribute="value"}
    children
{/component_name}
```

**Examples:**
```disyl
{ikb_text size="lg"}
    Hello World!
{/ikb_text}

{ikb_section type="hero"}
    {ikb_container width="md"}
        Content here
    {/ikb_container}
{/ikb_section}
```

**Rules:**
- Opening and closing tags MUST match exactly
- Closing tag uses `{/` prefix
- Tags MUST be properly nested

### 2. Attributes

**Syntax:**
```
attribute ::= name "=" value
```

**Attribute Values:**

1. **Quoted Strings:**
   ```disyl
   {ikb_text size="lg" color="#333"}
   ```

2. **Expression Strings:**
   ```disyl
   {ikb_card title="{item.title}" image="{item.thumbnail}"}
   ```

3. **Boolean Literals:**
   ```disyl
   {ikb_image responsive=true lazy=false}
   ```

4. **Number Literals:**
   ```disyl
   {ikb_block cols=3 gap=2}
   ```

**Rules:**
- Attribute names are case-sensitive
- String values MUST be quoted
- Expression values MUST be wrapped in `{}`
- Boolean and number values are unquoted

### 3. Expressions

#### Standalone Expressions

**Syntax:**
```disyl
{expression}
```

**Examples:**
```disyl
{title}
{item.content}
{post.author.name}
```

#### Interpolated Expressions

**In Text:**
```disyl
{ikb_text}
    Published on {item.date} by {item.author}
{/ikb_text}
```

**In Attributes:**
```disyl
{ikb_card title="{item.title}" link="{item.url}" /}
```

#### Expression Types

1. **Simple Variable:**
   ```disyl
   {title}
   ```

2. **Property Access:**
   ```disyl
   {item.title}
   {post.author.name}
   {user.profile.avatar.url}
   ```

3. **Method Call (future):**
   ```disyl
   {formatDate(item.date, "Y-m-d")}
   ```

### 4. Comments

**Syntax:**
```disyl
{!-- comment text --}
```

**Examples:**
```disyl
{!-- This is a single-line comment --}

{!-- 
    This is a
    multi-line comment
--}

{ikb_text}Visible{/ikb_text} {!-- Hidden --}
```

**Rules:**
- Comments are NOT rendered in output
- Comments CANNOT be nested
- Comments CAN span multiple lines

### 5. Text Nodes

**Syntax:**
```
Any text outside of tags
```

**Examples:**
```disyl
Plain text here

{ikb_text}
    Text with {expressions} inside
{/ikb_text}
```

**Rules:**
- Text is preserved as-is (whitespace maintained)
- Expressions in text are interpolated
- HTML is escaped by default (use `ikb_content` for raw HTML)

---

## Component Types

### Layout Components

#### `ikb_section`
Container for major page sections.

```disyl
{ikb_section type="hero" bg="#667eea" padding="large"}
    Content
{/ikb_section}
```

**Attributes:**
- `type`: `hero`, `content`, `header`, `footer`
- `bg`: Background color
- `padding`: `none`, `small`, `normal`, `large`

#### `ikb_container`
Centered content container.

```disyl
{ikb_container width="lg"}
    Content
{/ikb_container}
```

**Attributes:**
- `width`: `sm`, `md`, `lg`, `xl`

#### `ikb_block`
Grid layout.

```disyl
{ikb_block cols=3 gap=2}
    {ikb_card /}
    {ikb_card /}
    {ikb_card /}
{/ikb_block}
```

**Attributes:**
- `cols`: Number of columns (1-4)
- `gap`: Gap size in rem (0-4)

### Content Components

#### `ikb_text`
Styled text content.

```disyl
{ikb_text size="xl" weight="bold" color="#333"}
    Text content
{/ikb_text}
```

**Attributes:**
- `size`: `xs`, `sm`, `md`, `lg`, `xl`, `2xl`
- `weight`: `light`, `normal`, `medium`, `bold`
- `color`: Color value
- `align`: `left`, `center`, `right`

#### `ikb_content`
Raw HTML content (unescaped).

```disyl
{ikb_content}
    {item.content}
{/ikb_content}
```

**Security Note:** Only use for trusted content.

#### `ikb_image`
Responsive image (self-closing).

```disyl
{ikb_image 
    src="{item.thumbnail}"
    alt="{item.title}"
    responsive=true
    lazy=true
/}
```

**Attributes:**
- `src`: Image URL
- `alt`: Alt text
- `responsive`: Boolean
- `lazy`: Boolean

#### `ikb_card`
Content card.

```disyl
{ikb_card 
    title="{item.title}"
    image="{item.thumbnail}"
    link="{item.url}"
    variant="elevated"
}
    {ikb_text}{item.excerpt}{/ikb_text}
{/ikb_card}
```

**Attributes:**
- `title`: Card title
- `image`: Card image URL
- `link`: Card link URL
- `variant`: `elevated`, `outlined`, `flat`

### Data Components

#### `ikb_query`
WordPress post query.

```disyl
{ikb_query type="post" limit=10 orderby="date" category="news"}
    {ikb_card title="{item.title}" /}
{/ikb_query}
```

**Attributes:**
- `type`: Post type
- `limit`: Number of posts
- `orderby`: `date`, `title`, `random`
- `order`: `asc`, `desc`
- `category`: Category slug

**Context Variables:**
- `{item.id}` - Post ID
- `{item.title}` - Post title
- `{item.content}` - Post content
- `{item.excerpt}` - Post excerpt
- `{item.url}` - Post URL
- `{item.date}` - Post date
- `{item.author}` - Post author
- `{item.thumbnail}` - Featured image
- `{item.categories}` - Categories

### Control Structures

#### `if`
Conditional rendering.

```disyl
{if condition="item.thumbnail"}
    {ikb_image src="{item.thumbnail}" /}
{/if}
```

**Attributes:**
- `condition`: Variable to check for truthiness

#### `for`
Loop iteration.

```disyl
{for items="posts" as="post"}
    {ikb_text}{post.title}{/ikb_text}
{/for}
```

**Attributes:**
- `items`: Array variable name
- `as`: Iterator variable name

#### `include`
Template inclusion (self-closing).

```disyl
{include template="components/header" /}
```

**Attributes:**
- `template`: Template path (relative to `disyl/` directory)

---

## Expression Syntax

### Simple Expressions

```disyl
{variable}
```

**Evaluation:**
1. Look up `variable` in current context
2. Convert to string
3. Escape HTML (unless in `ikb_content`)

### Property Access

```disyl
{object.property}
{object.nested.property}
```

**Evaluation:**
1. Look up `object` in context
2. Access `property` on object
3. Repeat for nested properties
4. Convert to string
5. Escape HTML

### In Attributes

```disyl
{ikb_card title="{item.title}"}
```

**Evaluation:**
1. Parse attribute value
2. Extract expression from `{}`
3. Evaluate expression
4. Use result as attribute value

### In Text

```disyl
{ikb_text}
    Hello {name}, you have {count} messages.
{/ikb_text}
```

**Evaluation:**
1. Parse text node
2. Find all `{expression}` patterns
3. Evaluate each expression
4. Replace with result
5. Escape HTML

---

## Error Handling

### Parser Errors

#### 1. Missing Closing Tag

**Invalid:**
```disyl
{ikb_text}
    Content here
```

**Error:**
```
Line 3: Expected {/ikb_text} before end of file
```

#### 2. Mismatched Closing Tag

**Invalid:**
```disyl
{ikb_section}
    Content
{/ikb_container}
```

**Error:**
```
Line 3: Mismatched closing tag
Expected: {/ikb_section}
Got: {/ikb_container}
```

#### 3. Non-Self-Closing Void Component

**Invalid:**
```disyl
{ikb_image src="photo.jpg"}
```

**Error:**
```
Line 1: ikb_image must be self-closing
Use: {ikb_image src="photo.jpg" /}
```

#### 4. Invalid Attribute Syntax

**Invalid:**
```disyl
{ikb_text size=lg}
```

**Error:**
```
Line 1: Attribute value must be quoted
Use: size="lg"
```

#### 5. Unclosed Expression

**Invalid:**
```disyl
{item.title
```

**Error:**
```
Line 1, Col 12: Expected } to close expression
```

### Compiler Errors

#### 1. Unknown Component

**Invalid:**
```disyl
{ikb_unknown}
```

**Error:**
```
Line 1: Unknown component 'ikb_unknown'
Available components: ikb_section, ikb_text, ...
```

#### 2. Invalid Attribute

**Invalid:**
```disyl
{ikb_text invalid="value"}
```

**Error:**
```
Line 1: Invalid attribute 'invalid' for ikb_text
Valid attributes: size, weight, color, align
```

#### 3. Missing Required Attribute

**Invalid:**
```disyl
{include /}
```

**Error:**
```
Line 1: Missing required attribute 'template' for include
```

---

## Examples

### Complete Template

```disyl
{include template="components/header" /}

{ikb_section type="hero" bg="#667eea"}
    {ikb_container width="lg"}
        {ikb_text size="2xl" weight="bold" align="center"}
            Welcome to My Site
        {/ikb_text}
    {/ikb_container}
{/ikb_section}

{ikb_section type="content"}
    {ikb_container width="md"}
        {ikb_query type="post" limit=10}
            {ikb_card 
                title="{item.title}"
                image="{item.thumbnail}"
                link="{item.url}"
                variant="elevated"
            }
                {ikb_text size="sm"}
                    {item.excerpt}
                {/ikb_text}
                {ikb_text size="xs" color="#999"}
                    Published on {item.date}
                {/ikb_text}
            {/ikb_card}
        {/ikb_query}
    {/ikb_container}
{/ikb_section}

{include template="components/footer" /}
```

---

## Validation Rules

### 1. Tag Matching

- Opening and closing tags MUST match exactly
- Tags MUST be properly nested (no overlapping)
- Self-closing tags MUST NOT have closing tags

### 2. Component Names

- MUST start with `ikb_` prefix (except control structures)
- MUST be lowercase
- MUST be registered in ComponentRegistry

### 3. Attributes

- Attribute names MUST be valid identifiers
- String values MUST be quoted
- Expression values MUST be wrapped in `{}`
- Required attributes MUST be present

### 4. Expressions

- MUST be valid property access chains
- Variables MUST exist in context
- MUST NOT contain arbitrary code execution

### 5. Nesting

- Maximum nesting depth: 10 levels (recommended)
- Circular includes are NOT allowed
- Include depth: maximum 3 levels

---

## Version History

### v0.1 (November 2025)
- Initial grammar specification
- Basic components and control structures
- Expression interpolation
- Attribute evaluation
- Comments

---

**Last Updated:** November 13, 2025  
**Status:** Formal Specification ✅
