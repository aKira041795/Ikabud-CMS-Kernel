# DiSyL Language Specification
## Universal Declarative Template Language for Multi-Platform Development

**Version:** 1.0.0  
**Last Updated:** November 26, 2025  
**Specification Level:** Formal  
**License:** MIT

---

## Table of Contents

1. [Introduction](#introduction)
2. [Formal Grammar (EBNF)](#formal-grammar-ebnf)
3. [Core Language Constructs](#core-language-constructs)
4. [Platform Abstraction Layer](#platform-abstraction-layer)
5. [Universal Components](#universal-components)
6. [Platform-Specific Extensions](#platform-specific-extensions)
7. [Filter System](#filter-system)
8. [Control Flow](#control-flow)
9. [Data Binding & Queries](#data-binding--queries)
10. [Visual Builder Integration](#visual-builder-integration)
11. [Mobile & App Development](#mobile--app-development)
12. [Desktop Application Support](#desktop-application-support)
13. [Extensibility & Custom Platforms](#extensibility--custom-platforms)
14. [Best Practices](#best-practices)
15. [Appendix](#appendix)

---

## Introduction

DiSyL (Declarative Ikabud Syntax Language) is a **universal, platform-agnostic declarative language** designed for building user interfaces across any platform: web CMSs, mobile applications, desktop software, visual builders, and custom rendering targets.

### Design Philosophy

1. **Platform Independence** - Write once, render anywhere
2. **Declarative First** - Describe *what* you want, not *how* to build it
3. **Progressive Enhancement** - Core features work everywhere; platform-specific features enhance
4. **Type Safety** - Optional strong typing for enterprise applications
5. **Extensibility** - Plugin architecture for custom platforms and components

### Supported Platforms

#### Web CMS
- ✅ WordPress
- ✅ Joomla
- ✅ Drupal
- ✅ Ikabud CMS (native)
- 🔌 Custom CMS (via adapter)

#### Mobile & Apps
- 📱 React Native (via transpiler)
- 📱 Flutter (via transpiler)
- 📱 Native iOS/Android (via code generation)

#### Desktop
- 🖥️ Electron
- 🖥️ Tauri
- 🖥️ Native (via code generation)

#### Visual Builders
- 🎨 Drag-and-drop editors
- 🎨 No-code platforms
- 🎨 Design system integration

---

## Formal Grammar (EBNF)

The following Extended Backus-Naur Form (EBNF) defines the complete DiSyL grammar.

### Lexical Grammar

```ebnf
(* Character Classes *)
letter          = "A" | "B" | ... | "Z" | "a" | "b" | ... | "z" ;
digit           = "0" | "1" | "2" | "3" | "4" | "5" | "6" | "7" | "8" | "9" ;
unicode_char    = ? any Unicode character except control characters ? ;
whitespace      = " " | "\t" | "\n" | "\r" ;

(* Identifiers *)
identifier_start = letter | "_" ;
identifier_char  = letter | digit | "_" | "-" | "." ;
identifier       = identifier_start , { identifier_char } ;

(* Namespaced Identifiers - for platform-specific components *)
namespace        = identifier ;
namespaced_id    = [ namespace , ":" ] , identifier ;

(* Literals *)
integer_literal  = [ "-" ] , digit , { digit } ;
float_literal    = integer_literal , "." , digit , { digit } ;
number_literal   = float_literal | integer_literal ;

string_char      = unicode_char - ( '"' | "'" | "\\" ) | escape_sequence ;
escape_sequence  = "\\" , ( '"' | "'" | "\\" | "n" | "r" | "t" | "{" | "}" | "0" ) ;
string_literal   = ( '"' , { string_char } , '"' ) 
                 | ( "'" , { string_char } , "'" ) ;

boolean_literal  = "true" | "false" ;
null_literal     = "null" ;

literal          = string_literal | number_literal | boolean_literal | null_literal ;
```

### Syntactic Grammar

```ebnf
(* Document Structure *)
document         = [ platform_header ] , { node } ;
platform_header  = "{" , "ikb_platform" , platform_attrs , "/" , "}" ;
platform_attrs   = { platform_attr } ;
platform_attr    = identifier , "=" , ( string_literal | identifier ) ;

(* Nodes *)
node             = text_node | comment_node | tag_node | expression_node ;

text_node        = { unicode_char - "{" } ;

comment_node     = "{!--" , { unicode_char } , "--}" ;

(* Tags *)
tag_node         = opening_tag | self_closing_tag ;
opening_tag      = "{" , namespaced_id , attributes , "}" , 
                   { node } , 
                   "{/" , namespaced_id , "}" ;
self_closing_tag = "{" , namespaced_id , attributes , "/" , "}" ;

(* Attributes *)
attributes       = { attribute } ;
attribute        = identifier , [ "=" , attribute_value ] ;
attribute_value  = literal | expression | typed_value ;
typed_value      = "(" , type_annotation , ")" , literal ;
type_annotation  = "string" | "number" | "boolean" | "array" | "object" | custom_type ;
custom_type      = identifier ;

(* Expressions *)
expression_node  = "{" , expression , "}" ;
expression       = primary_expr , { filter_chain } ;
primary_expr     = property_access | literal | function_call | grouped_expr ;
property_access  = identifier , { "." , identifier } , { "[" , expression , "]" } ;
function_call    = identifier , "(" , [ argument_list ] , ")" ;
grouped_expr     = "(" , expression , ")" ;

(* Filter Chain *)
filter_chain     = "|" , filter_expr , { "|" , filter_expr } ;
filter_expr      = identifier , [ ":" , filter_args ] ;
filter_args      = filter_arg , { "," , filter_arg } ;
filter_arg       = [ identifier , "=" ] , ( literal | expression ) ;

(* Argument List *)
argument_list    = argument , { "," , argument } ;
argument         = [ identifier , "=" ] , expression ;

(* Control Structures *)
if_statement     = "{if" , condition_attr , "}" , 
                   { node } ,
                   [ else_clause ] ,
                   "{/if}" ;
else_clause      = "{else}" , { node } 
                 | "{elseif" , condition_attr , "}" , { node } , [ else_clause ] ;
condition_attr   = "condition" , "=" , string_literal ;

for_statement    = "{for" , for_attrs , "}" , 
                   { node } , 
                   [ empty_clause ] ,
                   "{/for}" ;
for_attrs        = "items" , "=" , expression , "as" , "=" , identifier , 
                   [ "," , "key" , "=" , identifier ] ;
empty_clause     = "{empty}" , { node } ;

(* Query Statement - Universal Data Fetching *)
query_statement  = "{" , query_component , query_attrs , "}" ,
                   { node } ,
                   "{/" , query_component , "}" ;
query_component  = "ikb_query" | platform_query ;
platform_query   = namespace , ":" , "query" ;
query_attrs      = { query_attr } ;
query_attr       = identifier , "=" , ( literal | expression ) ;

(* Slot System - For Component Composition *)
slot_definition  = "{slot" , [ slot_attrs ] , "/" , "}" 
                 | "{slot" , [ slot_attrs ] , "}" , { node } , "{/slot}" ;
slot_attrs       = "name" , "=" , string_literal , [ "," , "fallback" , "=" , string_literal ] ;

(* Template Inclusion *)
include_stmt     = "{ikb_include" , include_attrs , "/" , "}" ;
include_attrs    = "template" , "=" , string_literal , { "," , attribute } ;

(* Component Definition - For Reusable Components *)
component_def    = "{ikb_component" , component_attrs , "}" ,
                   [ props_def ] ,
                   [ slots_def ] ,
                   template_body ,
                   "{/ikb_component}" ;
component_attrs  = "name" , "=" , string_literal , { "," , attribute } ;
props_def        = "{props}" , { prop_def } , "{/props}" ;
prop_def         = "{prop" , prop_attrs , "/" , "}" ;
prop_attrs       = "name" , "=" , string_literal , 
                   [ "," , "type" , "=" , type_annotation ]
                   [ "," , "default" , "=" , literal ]
                   [ "," , "required" , "=" , boolean_literal ] ;
slots_def        = "{slots}" , { slot_def } , "{/slots}" ;
slot_def         = "{slot" , slot_def_attrs , "/" , "}" ;
slot_def_attrs   = "name" , "=" , string_literal , [ "," , "required" , "=" , boolean_literal ] ;
template_body    = "{template}" , { node } , "{/template}" ;
```

### Semantic Constraints

```ebnf
(* Platform Compatibility Rules *)
platform_rule    = "PLATFORM" , "(" , platform_list , ")" , ":" , rule_body ;
platform_list    = platform_id , { "," , platform_id } ;
platform_id      = "wordpress" | "joomla" | "drupal" | "react_native" 
                 | "flutter" | "electron" | "tauri" | "native" | "*" ;
rule_body        = component_rule | filter_rule | attribute_rule ;

(* Type Constraints *)
type_constraint  = "TYPE" , "(" , identifier , ")" , ":" , type_spec ;
type_spec        = primitive_type | array_type | object_type | union_type ;
primitive_type   = "string" | "number" | "boolean" | "null" ;
array_type       = "array" , "<" , type_spec , ">" ;
object_type      = "object" , "<" , { prop_type } , ">" ;
prop_type        = identifier , ":" , type_spec ;
union_type       = type_spec , "|" , type_spec , { "|" , type_spec } ;
```

---

## Core Language Constructs

### Comments

```disyl
{!-- This is a comment --}
{!-- 
  Multi-line comment
  Can span multiple lines
--}
```

---

## Platform Abstraction Layer

DiSyL uses a **Platform Abstraction Layer (PAL)** to enable cross-platform compatibility while allowing platform-specific optimizations.

### Platform Declaration

Every DiSyL document can declare its target platform(s):

```disyl
{ikb_platform 
    type="web"
    targets="wordpress,joomla,drupal"
    fallback="generic"
    version="1.0"
/}
```

**Attributes:**

| Attribute | Type | Description |
|-----------|------|-------------|
| `type` | string | Platform category: `web`, `mobile`, `desktop`, `universal` |
| `targets` | string | Comma-separated target platforms |
| `fallback` | string | Fallback platform if target unavailable |
| `version` | string | DiSyL version requirement |
| `strict` | boolean | Fail on unsupported features (default: false) |
| `features` | string | Required features: `components`, `filters`, `queries`, `slots` |

### Platform Categories

#### Web Platforms

```disyl
{!-- WordPress --}
{ikb_platform type="web" targets="wordpress" /}

{!-- Joomla --}
{ikb_platform type="web" targets="joomla" /}

{!-- Drupal --}
{ikb_platform type="web" targets="drupal" /}

{!-- Multi-CMS (renders on any) --}
{ikb_platform type="web" targets="wordpress,joomla,drupal" fallback="generic" /}
```

#### Mobile Platforms

```disyl
{!-- React Native --}
{ikb_platform type="mobile" targets="react_native" /}

{!-- Flutter --}
{ikb_platform type="mobile" targets="flutter" /}

{!-- Native iOS/Android --}
{ikb_platform type="mobile" targets="ios,android" /}

{!-- Cross-platform mobile --}
{ikb_platform type="mobile" targets="react_native,flutter" /}
```

#### Desktop Platforms

```disyl
{!-- Electron --}
{ikb_platform type="desktop" targets="electron" /}

{!-- Tauri --}
{ikb_platform type="desktop" targets="tauri" /}

{!-- Native desktop --}
{ikb_platform type="desktop" targets="windows,macos,linux" /}
```

#### Universal (All Platforms)

```disyl
{!-- Works everywhere --}
{ikb_platform type="universal" /}
```

### Legacy CMS Declaration (Backward Compatible)

For backward compatibility, the legacy `ikb_cms` syntax is still supported:

```disyl
{ikb_cms type="wordpress" set="components,filters" /}
```

**Attributes:**
- `type` - CMS type: `wordpress`, `joomla`, `drupal`, or `generic`
- `set` - Comma-separated list: `filters`, `components`, `hooks`, `functions`

### Platform Feature Detection

```disyl
{!-- Check if platform supports a feature --}
{if condition="platform.supports('slots')"}
    {slot name="header" /}
{else}
    {ikb_include template="header.disyl" /}
{/if}

{!-- Platform-specific rendering --}
{if condition="platform.is('wordpress')"}
    {wp:sidebar id="main" /}
{elseif condition="platform.is('joomla')"}
    {joomla:module position="sidebar" /}
{else}
    <aside class="sidebar">Default sidebar</aside>
{/if}
```

### Platform Capabilities Matrix

| Feature | WordPress | Joomla | Drupal | React Native | Flutter | Electron |
|---------|-----------|--------|--------|--------------|---------|----------|
| `ikb_section` | ✅ | ✅ | ✅ | ✅ (View) | ✅ (Container) | ✅ |
| `ikb_grid` | ✅ | ✅ | ✅ | ✅ (FlatList) | ✅ (GridView) | ✅ |
| `ikb_query` | ✅ | ✅ | ✅ | ✅ (API) | ✅ (API) | ✅ (API) |
| `ikb_button` | ✅ | ✅ | ✅ | ✅ (Pressable) | ✅ (ElevatedButton) | ✅ |
| `slots` | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| `native_widgets` | ❌ | ❌ | ❌ | ✅ | ✅ | ✅ |

### Variables & Expressions

```disyl
{!-- Simple variable --}
{site.name}

{!-- With filter --}
{post.title | esc_html}

{!-- Multiple filters --}
{post.excerpt | strip_tags | truncate:length=150}

{!-- Nested properties --}
{user.profile.avatar_url | esc_url}
```

---

## Universal Components

These components work across all CMS platforms.

### Layout Components

#### ikb_section

Creates a semantic section with optional styling.

```disyl
{ikb_section type="hero" padding="large" background="gradient"}
    Content here
{/ikb_section}
```

**Attributes:**
- `type` - Section type: `hero`, `content`, `features`, `cta`, `footer`
- `padding` - Padding size: `none`, `small`, `medium`, `large`, `xlarge`
- `background` - Background style: `light`, `dark`, `gradient`, `image`
- `class` - Additional CSS classes
- `id` - Section ID

**Examples:**

```disyl
{!-- Hero section --}
{ikb_section type="hero" padding="xlarge" class="home-hero"}
    <h1>Welcome</h1>
{/ikb_section}

{!-- Content section --}
{ikb_section type="content" padding="large"}
    <p>Main content</p>
{/ikb_section}
```

#### ikb_container

Creates a centered container with max-width.

```disyl
{ikb_container size="large"}
    Content here
{/ikb_container}
```

**Attributes:**
- `size` - Container size: `small`, `medium`, `large`, `xlarge`, `full`
- `class` - Additional CSS classes

**Examples:**

```disyl
{ikb_container size="xlarge"}
    <h2>Wide Container</h2>
{/ikb_container}

{ikb_container size="small" class="centered-content"}
    <p>Narrow content</p>
{/ikb_container}
```

#### ikb_grid

Creates a responsive grid layout.

```disyl
{ikb_grid columns="3" gap="medium"}
    {ikb_card}Card 1{/ikb_card}
    {ikb_card}Card 2{/ikb_card}
    {ikb_card}Card 3{/ikb_card}
{/ikb_grid}
```

**Attributes:**
- `columns` - Number of columns: `1`, `2`, `3`, `4`, `6`
- `gap` - Gap size: `none`, `small`, `medium`, `large`
- `responsive` - Enable responsive behavior: `true`, `false`
- `class` - Additional CSS classes

**Examples:**

```disyl
{!-- 3-column grid --}
{ikb_grid columns="3" gap="large"}
    <div>Column 1</div>
    <div>Column 2</div>
    <div>Column 3</div>
{/ikb_grid}

{!-- Responsive 2-column grid --}
{ikb_grid columns="2" gap="medium" responsive="true"}
    <div>Item 1</div>
    <div>Item 2</div>
{/ikb_grid}
```

### Content Components

#### ikb_text

Styled text component with typography controls.

```disyl
{ikb_text size="xl" weight="bold" align="center"}
    Heading Text
{/ikb_text}
```

**Attributes:**
- `size` - Text size: `xs`, `sm`, `base`, `lg`, `xl`, `2xl`, `3xl`, `4xl`
- `weight` - Font weight: `light`, `normal`, `medium`, `semibold`, `bold`
- `align` - Text alignment: `left`, `center`, `right`, `justify`
- `tag` - HTML tag: `p`, `h1`, `h2`, `h3`, `h4`, `h5`, `h6`, `span`
- `class` - Additional CSS classes
- `margin` - Margin: `none`, `top`, `bottom`, `both`

**Examples:**

```disyl
{!-- Large heading --}
{ikb_text tag="h1" size="4xl" weight="bold" align="center"}
    Welcome to Our Site
{/ikb_text}

{!-- Body text --}
{ikb_text size="base" margin="bottom"}
    This is a paragraph of text.
{/ikb_text}
```

#### ikb_button

Styled button or link component.

```disyl
{ikb_button href="/contact" variant="primary" size="large"}
    Contact Us
{/ikb_button}
```

**Attributes:**
- `href` - Link URL
- `variant` - Button style: `primary`, `secondary`, `outline`, `text`
- `size` - Button size: `small`, `medium`, `large`
- `class` - Additional CSS classes
- `target` - Link target: `_self`, `_blank`

**Examples:**

```disyl
{!-- Primary button --}
{ikb_button href="/signup" variant="primary" size="large"}
    Sign Up Now
{/ikb_button}

{!-- Secondary button --}
{ikb_button href="/learn-more" variant="secondary"}
    Learn More
{/ikb_button}

{!-- External link --}
{ikb_button href="https://example.com" target="_blank" variant="outline"}
    Visit Website
{/ikb_button}
```

#### ikb_image

Optimized image component with lazy loading.

```disyl
{ikb_image 
    src="{post.thumbnail | esc_url}" 
    alt="{post.title | esc_attr}"
    lazy=true
/}
```

**Attributes:**
- `src` - Image source URL
- `alt` - Alt text for accessibility
- `lazy` - Enable lazy loading: `true`, `false`
- `class` - Additional CSS classes
- `width` - Image width
- `height` - Image height

**Examples:**

```disyl
{!-- Basic image --}
{ikb_image src="/images/hero.jpg" alt="Hero Image" /}

{!-- Lazy-loaded image --}
{ikb_image 
    src="{item.thumbnail | esc_url}" 
    alt="{item.title | esc_attr}"
    lazy=true
    class="post-thumbnail"
/}
```

#### ikb_card

Card component for content blocks.

```disyl
{ikb_card variant="elevated" padding="medium"}
    <h3>Card Title</h3>
    <p>Card content</p>
{/ikb_card}
```

**Attributes:**
- `variant` - Card style: `flat`, `elevated`, `outlined`
- `padding` - Card padding: `none`, `small`, `medium`, `large`
- `class` - Additional CSS classes

**Examples:**

```disyl
{!-- Elevated card --}
{ikb_card variant="elevated" padding="large"}
    <h3>Feature Title</h3>
    <p>Feature description</p>
{/ikb_card}

{!-- Outlined card --}
{ikb_card variant="outlined" padding="medium"}
    <p>Simple card content</p>
{/ikb_card}
```

---

## WordPress-Specific Syntax

### CMS Declaration

```disyl
{ikb_cms type="wordpress" set="components,filters" /}
```

### Components

#### ikb_query (WordPress)

Query and loop through WordPress posts.

```disyl
{ikb_query type="post" limit="6" category="news"}
    <article>
        <h2>{item.title | esc_html}</h2>
        <p>{item.excerpt | wp_trim_words:num_words=30}</p>
        <a href="{item.url | esc_url}">Read More</a>
    </article>
{/ikb_query}
```

**Attributes:**
- `type` - Post type: `post`, `page`, or custom post type
- `limit` - Number of posts to display
- `category` - Category slug or ID
- `tag` - Tag slug or ID
- `orderby` - Order by: `date`, `title`, `rand`, `menu_order`
- `order` - Sort order: `ASC`, `DESC`

**Available Variables:**
- `{item.title}` - Post title
- `{item.excerpt}` - Post excerpt
- `{item.content}` - Post content
- `{item.url}` - Post permalink
- `{item.thumbnail}` - Featured image URL
- `{item.author}` - Author name
- `{item.date}` - Publication date
- `{item.categories}` - Post categories
- `{item.tags}` - Post tags

**Examples:**

```disyl
{!-- Latest 5 posts --}
{ikb_query type="post" limit=5}
    <article class="post-card">
        {if condition="item.thumbnail"}
            <img src="{item.thumbnail | esc_url}" alt="{item.title | esc_attr}">
        {/if}
        <h3>{item.title | esc_html}</h3>
        <p>{item.excerpt | wp_trim_words:num_words=20}</p>
        <a href="{item.url | esc_url}">Read More</a>
    </article>
{/ikb_query}

{!-- Posts from specific category --}
{ikb_query type="post" limit=10 category="technology" orderby="date" order="DESC"}
    <h2>{item.title | esc_html}</h2>
    <p>Posted on {item.date | date:format="F j, Y"}</p>
{/ikb_query}
```

#### ikb_menu (WordPress)

Display WordPress navigation menu.

```disyl
{ikb_menu location="primary" class="main-nav"}
```

**Attributes:**
- `location` - Menu location: `primary`, `footer`, or custom location
- `class` - CSS class for menu container

**Example:**

```disyl
{ikb_menu location="primary" class="main-navigation"}
{ikb_menu location="footer" class="footer-menu"}
```

#### ikb_widget_area (WordPress)

Display WordPress widget area/sidebar.

```disyl
{ikb_widget_area id="sidebar-1" class="sidebar"}
```

**Attributes:**
- `id` - Widget area ID
- `class` - CSS class for widget area

**Example:**

```disyl
{ikb_widget_area id="sidebar-1" class="primary-sidebar"}
{ikb_widget_area id="footer-1" class="footer-widgets"}
```

### WordPress Filters

#### wp_trim_words

Trim text to specified word count.

```disyl
{post.excerpt | wp_trim_words:num_words=30}
{post.content | wp_trim_words:num_words=50,more="..."}
```

#### wp_kses_post

Sanitize content allowing safe HTML.

```disyl
{post.content | wp_kses_post}
```

---

## Joomla-Specific Syntax

### CMS Declaration

```disyl
{ikb_cms type="joomla" set="components,filters" /}
```

### Components

#### ikb_query (Joomla)

Query and loop through Joomla articles.

```disyl
{ikb_query type="post" limit=6}
    <article>
        <h2>{item.title | esc_html}</h2>
        <p>{item.excerpt | strip_tags | truncate:length=150}</p>
        <a href="{item.url | esc_url}">Read More</a>
    </article>
{/ikb_query}
```

**Attributes:**
- `type` - Content type: `post` (articles)
- `limit` - Number of articles to display
- `category` - Category ID
- `featured` - Show only featured: `true`, `false`
- `orderby` - Order by: `date`, `title`, `hits`
- `order` - Sort order: `ASC`, `DESC`

**Available Variables:**
- `{item.title}` - Article title
- `{item.excerpt}` - Article intro text
- `{item.content}` - Full article text
- `{item.url}` - Article URL
- `{item.thumbnail}` - Featured image
- `{item.author}` - Author name
- `{item.date}` - Publication date
- `{item.category}` - Category name
- `{item.hits}` - View count

**Examples:**

```disyl
{!-- Latest 6 articles --}
{ikb_query type="post" limit=6}
    <div class="article-card">
        {if condition="item.thumbnail"}
            <img src="{item.thumbnail | esc_url}" alt="{item.title | esc_attr}">
        {/if}
        <h3>{item.title | esc_html}</h3>
        <p>{item.excerpt | strip_tags | truncate:length=150}</p>
        <a href="{item.url | esc_url}">Read More →</a>
    </div>
{/ikb_query}

{!-- Featured articles only --}
{ikb_query type="post" limit=3 featured=true}
    <h2>{item.title | esc_html}</h2>
{/ikb_query}
```

#### joomla_module

Display Joomla module position.

```disyl
{joomla_module position="sidebar-left" style="card" /}
```

**Attributes:**
- `position` - Module position name
- `style` - Module chrome style: `none`, `card`, `xhtml`, `html5`
- `limit` - Limit number of modules

**Examples:**

```disyl
{!-- Sidebar modules --}
{joomla_module position="sidebar-left" style="card" /}

{!-- Header modules --}
{joomla_module position="header" style="none" /}

{!-- Footer modules (first 4 only) --}
{joomla_module position="footer-1" style="none" limit=4 /}
```

#### joomla_component

Display Joomla component output.

```disyl
{joomla_component /}
```

**Example:**

```disyl
<main class="site-content">
    {joomla_component /}
</main>
```

#### joomla_message

Display Joomla system messages.

```disyl
{joomla_message /}
```

**Example:**

```disyl
<div class="container">
    {joomla_message /}
    {joomla_component /}
</div>
```

#### joomla_params

Access Joomla template parameters.

```disyl
{joomla_params name="logoFile" /}
{joomla_params name="siteDescription" default="Welcome" /}
```

**Attributes:**
- `name` - Parameter name
- `default` - Default value if parameter not set

**Examples:**

```disyl
{!-- Logo image --}
{if condition="joomla.params.logoFile"}
    <img src="{joomla_params name="logoFile" /}" alt="Logo">
{/if}

{!-- Site description --}
<p>{joomla_params name="siteDescription" default="My Website" /}</p>
```

---

## Drupal-Specific Syntax

### CMS Declaration

```disyl
{ikb_cms type="drupal" set="components,filters" /}
```

### Components (Planned)

#### drupal_articles

Query Drupal nodes.

```disyl
{drupal_articles limit=6 type="article"}
    <h2>{item.title | esc_html}</h2>
    <p>{item.body | strip_tags | truncate:length=150}</p>
{/drupal_articles}
```

#### drupal_menu

Display Drupal menu.

```disyl
{drupal_menu name="main" /}
```

#### drupal_block

Display Drupal block.

```disyl
{drupal_block id="system_branding_block" /}
```

---

## Filters Reference

### Security Filters

#### esc_html

Escape HTML entities.

```disyl
{post.title | esc_html}
```

#### esc_url

Escape and validate URLs.

```disyl
{post.url | esc_url}
```

#### esc_attr

Escape HTML attributes.

```disyl
<img alt="{post.title | esc_attr}">
```

#### strip_tags

Remove HTML tags.

```disyl
{post.content | strip_tags}
```

### Text Manipulation

#### upper

Convert to uppercase.

```disyl
{post.title | upper}
```

#### lower

Convert to lowercase.

```disyl
{post.title | lower}
```

#### capitalize

Capitalize first letter.

```disyl
{post.title | capitalize}
```

#### truncate

Truncate text to specified length.

```disyl
{post.excerpt | truncate:length=150}
{post.excerpt | truncate:length=100,append="..."}
```

**Parameters:**
- `length` - Maximum length
- `append` - Text to append (default: "...")

### Date Formatting

#### date

Format date/time.

```disyl
{post.date | date:format="F j, Y"}
{post.date | date:format="Y-m-d H:i:s"}
```

**Format Options:**
- `F j, Y` - January 1, 2025
- `Y-m-d` - 2025-01-01
- `M j` - Jan 1
- `H:i` - 14:30

### WordPress-Specific Filters

#### wp_trim_words

Trim to word count (WordPress).

```disyl
{post.excerpt | wp_trim_words:num_words=30}
```

#### wp_kses_post

Sanitize allowing safe HTML (WordPress).

```disyl
{post.content | wp_kses_post}
```

---

## Conditional Logic

### Basic Conditionals

```disyl
{if condition="post.thumbnail"}
    <img src="{post.thumbnail | esc_url}">
{/if}
```

### If-Else

```disyl
{if condition="user.logged_in"}
    <p>Welcome back, {user.name | esc_html}!</p>
{else}
    <p><a href="/login">Please log in</a></p>
{/if}
```

### Negation

```disyl
{if condition="!user.logged_in"}
    <a href="/login">Login</a>
{/if}
```

### Multiple Conditions

```disyl
{if condition="post.thumbnail"}
    <img src="{post.thumbnail | esc_url}">
{/if}

{if condition="post.category == 'featured'"}
    <span class="badge">Featured</span>
{/if}
```

### Checking Module Positions (Joomla)

```disyl
{if condition="joomla.module_positions.sidebar > 0"}
    <aside class="sidebar">
        {joomla_module position="sidebar" style="card" /}
    </aside>
{/if}
```

---

## Loops & Queries

### For Loop

```disyl
{for items="menu.primary" as="item"}
    <li>
        <a href="{item.url | esc_url}">{item.title | esc_html}</a>
    </li>
{/for}
```

### Nested Loops

```disyl
{for items="menu.primary" as="item"}
    <li>
        <a href="{item.url | esc_url}">{item.title | esc_html}</a>
        {if condition="item.children"}
            <ul class="submenu">
                {for items="item.children" as="child"}
                    <li>
                        <a href="{child.url | esc_url}">{child.title | esc_html}</a>
                    </li>
                {/for}
            </ul>
        {/if}
    </li>
{/for}
```

### Query with Conditionals

```disyl
{ikb_query type="post" limit=6}
    <article class="post-card">
        {if condition="item.thumbnail"}
            <img src="{item.thumbnail | esc_url}" alt="{item.title | esc_attr}">
        {/if}
        
        <h3>{item.title | esc_html}</h3>
        
        <div class="post-meta">
            <span>{item.date | date:format="M j, Y"}</span>
            <span>•</span>
            <span>{item.author | esc_html}</span>
        </div>
        
        <p>{item.excerpt | strip_tags | truncate:length=150}</p>
        
        <a href="{item.url | esc_url}" class="read-more">
            Read More →
        </a>
    </article>
{/ikb_query}
```

---

## Best Practices

### 1. Always Use Security Filters

```disyl
{!-- ✅ GOOD --}
<h1>{post.title | esc_html}</h1>
<a href="{post.url | esc_url}">Link</a>
<img alt="{post.title | esc_attr}">

{!-- ❌ BAD --}
<h1>{post.title}</h1>
<a href="{post.url}">Link</a>
```

### 2. Declare CMS Type

```disyl
{!-- ✅ GOOD --}
{ikb_cms type="wordpress" set="components,filters" /}

{wp_posts limit=5 /}
```

### 3. Use Semantic Components

```disyl
{!-- ✅ GOOD --}
{ikb_section type="hero" padding="large"}
    {ikb_container size="xlarge"}
        {ikb_text tag="h1" size="4xl" weight="bold"}
            Welcome
        {/ikb_text}
    {/ikb_container}
{/ikb_section}

{!-- ❌ BAD --}
<div class="hero">
    <div class="container">
        <h1>Welcome</h1>
    </div>
</div>
```

### 4. Check Existence Before Use

```disyl
{!-- ✅ GOOD --}
{if condition="post.thumbnail"}
    <img src="{post.thumbnail | esc_url}">
{/if}

{!-- ❌ BAD --}
<img src="{post.thumbnail | esc_url}">
```

### 5. Use Descriptive Variable Names

```disyl
{!-- ✅ GOOD --}
{for items="menu.primary" as="menuItem"}
    <a href="{menuItem.url | esc_url}">{menuItem.title | esc_html}</a>
{/for}

{!-- ❌ BAD --}
{for items="menu.primary" as="i"}
    <a href="{i.url | esc_url}">{i.title | esc_html}</a>
{/for}
```

### 6. Organize Templates by CMS

```
theme/
├── wordpress/
│   ├── home.disyl
│   └── single.disyl
├── joomla/
│   ├── home.disyl
│   └── article.disyl
└── shared/
    ├── header.disyl
    └── footer.disyl
```

---

## Complete Examples

### WordPress Blog Homepage

```disyl
{ikb_cms type="wordpress" set="components,filters" /}
{ikb_include template="components/header.disyl" /}

{!-- Hero Section --}
{ikb_section type="hero" padding="xlarge" class="section-gradient"}
    {ikb_container size="large"}
        {ikb_text tag="h1" size="4xl" weight="bold" align="center"}
            {site.name | esc_html}
        {/ikb_text}
        {ikb_text tag="p" size="xl" align="center"}
            {site.description | esc_html}
        {/ikb_text}
    {/ikb_container}
{/ikb_section}

{!-- Latest Posts --}
{ikb_section type="content" padding="large"}
    {ikb_container size="xlarge"}
        <div class="section-header">
            {ikb_text tag="h2" size="3xl" weight="bold" align="center"}
                Latest Articles
            {/ikb_text}
        </div>
        
        {ikb_grid columns="3" gap="large"}
            {ikb_query type="post" limit=6}
                {ikb_card variant="elevated" padding="medium"}
                    {if condition="item.thumbnail"}
                        {ikb_image 
                            src="{item.thumbnail | esc_url}" 
                            alt="{item.title | esc_attr}"
                            lazy=true
                        /}
                    {/if}
                    
                    {ikb_text tag="h3" size="xl" weight="semibold"}
                        {item.title | esc_html}
                    {/ikb_text}
                    
                    {ikb_text}
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

{ikb_include template="components/footer.disyl" /}
```

### Joomla Article Page

```disyl
{ikb_cms type="joomla" set="components,filters" /}
{ikb_include template="components/header.disyl" /}

{!-- Breadcrumbs --}
{joomla_module position="breadcrumbs" style="none" /}

{!-- Main Content --}
{ikb_section type="content" padding="large"}
    {ikb_container size="large"}
        <div class="content-layout">
            <main class="main-content">
                {joomla_message /}
                {joomla_component /}
            </main>
            
            {if condition="joomla.module_positions.sidebar > 0"}
                <aside class="sidebar">
                    {joomla_module position="sidebar" style="card" /}
                </aside>
            {/if}
        </div>
    {/ikb_container}
{/ikb_section}

{ikb_include template="components/footer.disyl" /}
```

---

## Visual Builder Integration

DiSyL is designed to work seamlessly with visual/drag-and-drop builders.

### Component Metadata for Builders

Every DiSyL component can include metadata for visual editors:

```disyl
{ikb_component name="hero-section"}
    {props}
        {prop name="title" type="string" label="Hero Title" 
              placeholder="Enter title..." required=true /}
        {prop name="subtitle" type="string" label="Subtitle" /}
        {prop name="background" type="image" label="Background Image" /}
        {prop name="cta_text" type="string" label="Button Text" default="Learn More" /}
        {prop name="cta_url" type="url" label="Button URL" /}
    {/props}
    
    {template}
        {ikb_section type="hero" background="{props.background}"}
            {ikb_container size="large"}
                {ikb_text tag="h1" size="4xl"}{props.title}{/ikb_text}
                {ikb_text tag="p" size="xl"}{props.subtitle}{/ikb_text}
                {ikb_button href="{props.cta_url}" variant="primary"}
                    {props.cta_text}
                {/ikb_button}
            {/ikb_container}
        {/ikb_section}
    {/template}
{/ikb_component}
```

### Visual Builder Schema Export

```json
{
  "component": "hero-section",
  "category": "sections",
  "icon": "layout-hero",
  "props": [
    {
      "name": "title",
      "type": "string",
      "label": "Hero Title",
      "required": true,
      "ui": "text-input"
    },
    {
      "name": "background",
      "type": "image",
      "label": "Background Image",
      "ui": "image-picker"
    }
  ],
  "slots": ["content", "actions"],
  "preview": "hero-section-preview.png"
}
```

### Drag-and-Drop Zones

```disyl
{ikb_dropzone id="main-content" accepts="section,card,text,image"}
    {slot name="content" /}
{/ikb_dropzone}
```

---

## Mobile & App Development

DiSyL can be transpiled to native mobile code.

### React Native Output

```disyl
{!-- DiSyL Source --}
{ikb_platform type="mobile" targets="react_native" /}

{ikb_section padding="large"}
    {ikb_text size="xl" weight="bold"}Welcome{/ikb_text}
    {ikb_button onPress="handlePress" variant="primary"}
        Get Started
    {/ikb_button}
{/ikb_section}
```

**Transpiles to:**

```jsx
// React Native Output
import { View, Text, Pressable, StyleSheet } from 'react-native';

export default function Section() {
  return (
    <View style={styles.section}>
      <Text style={styles.heading}>Welcome</Text>
      <Pressable style={styles.button} onPress={handlePress}>
        <Text style={styles.buttonText}>Get Started</Text>
      </Pressable>
    </View>
  );
}
```

### Flutter Output

```disyl
{!-- DiSyL Source --}
{ikb_platform type="mobile" targets="flutter" /}

{ikb_section padding="large"}
    {ikb_text size="xl" weight="bold"}Welcome{/ikb_text}
    {ikb_button onTap="handleTap" variant="primary"}
        Get Started
    {/ikb_button}
{/ikb_section}
```

**Transpiles to:**

```dart
// Flutter Output
import 'package:flutter/material.dart';

class Section extends StatelessWidget {
  @override
  Widget build(BuildContext context) {
    return Container(
      padding: EdgeInsets.all(24),
      child: Column(
        children: [
          Text('Welcome', style: TextStyle(fontSize: 24, fontWeight: FontWeight.bold)),
          ElevatedButton(
            onPressed: handleTap,
            child: Text('Get Started'),
          ),
        ],
      ),
    );
  }
}
```

### Mobile-Specific Components

```disyl
{!-- Navigation --}
{mobile:navigation type="bottom-tabs"}
    {mobile:tab icon="home" label="Home" screen="HomeScreen" /}
    {mobile:tab icon="search" label="Search" screen="SearchScreen" /}
    {mobile:tab icon="profile" label="Profile" screen="ProfileScreen" /}
{/mobile:navigation}

{!-- Native List --}
{mobile:list data="{items}" renderItem="ItemComponent" keyExtractor="id" /}

{!-- Pull to Refresh --}
{mobile:refresh onRefresh="handleRefresh"}
    {ikb_query type="post" limit=10}
        {!-- Content --}
    {/ikb_query}
{/mobile:refresh}

{!-- Native Input --}
{mobile:input 
    type="text" 
    placeholder="Enter name..."
    onChangeText="handleChange"
    autoCapitalize="words"
/}
```

---

## Desktop Application Support

DiSyL supports desktop application development via Electron and Tauri.

### Electron Integration

```disyl
{ikb_platform type="desktop" targets="electron" /}

{desktop:window title="My App" width=1200 height=800}
    {desktop:menubar}
        {desktop:menu label="File"}
            {desktop:menuitem label="New" accelerator="CmdOrCtrl+N" onClick="handleNew" /}
            {desktop:menuitem label="Open" accelerator="CmdOrCtrl+O" onClick="handleOpen" /}
            {desktop:separator /}
            {desktop:menuitem label="Exit" onClick="handleExit" /}
        {/desktop:menu}
    {/desktop:menubar}
    
    {desktop:titlebar draggable=true}
        {ikb_text size="sm"}{app.name}{/ikb_text}
        {desktop:window-controls /}
    {/desktop:titlebar}
    
    {ikb_section class="main-content"}
        {slot name="content" /}
    {/ikb_section}
{/desktop:window}
```

### Tauri Integration

```disyl
{ikb_platform type="desktop" targets="tauri" /}

{desktop:invoke command="greet" args="{name}" onSuccess="handleGreeting" /}

{desktop:dialog type="open" filters="*.txt,*.md" onSelect="handleFileSelect" /}

{desktop:notification title="Update Available" body="A new version is ready." /}
```

### Native System Access

```disyl
{!-- File System --}
{desktop:fs action="read" path="{filePath}" onData="handleFileData" /}

{!-- System Tray --}
{desktop:tray icon="icon.png" tooltip="My App"}
    {desktop:tray-menu}
        {desktop:menuitem label="Show" onClick="showWindow" /}
        {desktop:menuitem label="Quit" onClick="quitApp" /}
    {/desktop:tray-menu}
{/desktop:tray}

{!-- Keyboard Shortcuts --}
{desktop:shortcut keys="CmdOrCtrl+Shift+P" onTrigger="openCommandPalette" /}
```

---

## Extensibility & Custom Platforms

### Creating a Custom Platform Adapter

```php
<?php
namespace MyCompany\DiSyL\Platforms;

use IkabudKernel\Core\DiSyL\Renderers\BaseRenderer;

class MyCustomRenderer extends BaseRenderer
{
    protected function initializeCMS(): void
    {
        // Register platform-specific components
        $this->registerComponent('myplatform:widget', [$this, 'renderWidget']);
        
        // Register platform-specific filters
        $this->registerFilter('myformat', [$this, 'applyMyFormat']);
    }
    
    protected function renderWidget(array $node, array $attrs): string
    {
        // Custom rendering logic
        return '<my-widget>' . $this->renderChildren($node['children']) . '</my-widget>';
    }
}
```

### Platform Manifest

```json
{
  "platform": {
    "id": "myplatform",
    "name": "My Custom Platform",
    "version": "1.0.0",
    "type": "web",
    "renderer": "MyCompany\\DiSyL\\Platforms\\MyCustomRenderer"
  },
  "components": {
    "myplatform:widget": {
      "description": "Custom widget component",
      "attributes": {
        "type": { "type": "string", "required": true },
        "data": { "type": "object" }
      }
    }
  },
  "filters": {
    "myformat": {
      "description": "Custom formatting filter",
      "params": ["style"]
    }
  },
  "capabilities": ["components", "filters", "queries"]
}
```

### Registering Custom Components

```disyl
{!-- Define a reusable component --}
{ikb_component name="pricing-card" platforms="*"}
    {props}
        {prop name="plan" type="string" required=true /}
        {prop name="price" type="number" required=true /}
        {prop name="features" type="array" default="[]" /}
        {prop name="highlighted" type="boolean" default=false /}
    {/props}
    
    {template}
        {ikb_card variant="{props.highlighted ? 'elevated' : 'outlined'}" padding="large"}
            {ikb_text tag="h3" size="xl" weight="bold"}
                {props.plan}
            {/ikb_text}
            
            {ikb_text tag="p" size="3xl" weight="bold"}
                ${props.price}/mo
            {/ikb_text}
            
            <ul class="features">
                {for items="props.features" as="feature"}
                    <li>{feature | esc_html}</li>
                {/for}
            </ul>
            
            {ikb_button variant="primary" class="full-width"}
                Choose Plan
            {/ikb_button}
        {/ikb_card}
    {/template}
{/ikb_component}

{!-- Use the component --}
{pricing-card plan="Pro" price=29 features="['Unlimited projects', '24/7 support']" highlighted=true /}
```

---

## Appendix

### A. Component Reference

| Component | Category | Platforms | Description |
|-----------|----------|-----------|-------------|
| `ikb_section` | Layout | All | Semantic page section |
| `ikb_container` | Layout | All | Centered container |
| `ikb_grid` | Layout | All | Responsive grid |
| `ikb_text` | Content | All | Styled text |
| `ikb_button` | Interactive | All | Button/link |
| `ikb_image` | Media | All | Optimized image |
| `ikb_card` | Content | All | Card container |
| `ikb_query` | Data | All | Content query |
| `wp:*` | CMS | WordPress | WordPress-specific |
| `joomla:*` | CMS | Joomla | Joomla-specific |
| `drupal:*` | CMS | Drupal | Drupal-specific |
| `mobile:*` | Mobile | React Native, Flutter | Mobile-specific |
| `desktop:*` | Desktop | Electron, Tauri | Desktop-specific |

### B. Filter Reference

| Filter | Category | Platforms | Description |
|--------|----------|-----------|-------------|
| `esc_html` | Security | All | Escape HTML entities |
| `esc_url` | Security | All | Escape/validate URLs |
| `esc_attr` | Security | All | Escape HTML attributes |
| `strip_tags` | Text | All | Remove HTML tags |
| `upper` | Text | All | Uppercase |
| `lower` | Text | All | Lowercase |
| `capitalize` | Text | All | Capitalize first letter |
| `truncate` | Text | All | Truncate to length |
| `date` | Date | All | Format date/time |
| `json` | Data | All | JSON encode |
| `default` | Logic | All | Default value |
| `wp_trim_words` | Text | WordPress | Trim to word count |
| `wp_kses_post` | Security | WordPress | Sanitize HTML |
| `t` | i18n | Drupal | Translation |

### C. Type System

| Type | Description | Example |
|------|-------------|---------|
| `string` | Text value | `"Hello"` |
| `number` | Numeric value | `42`, `3.14` |
| `boolean` | True/false | `true`, `false` |
| `null` | Null value | `null` |
| `array` | List of values | `[1, 2, 3]` |
| `object` | Key-value pairs | `{name: "John"}` |
| `url` | URL string | `"https://..."` |
| `image` | Image URL/path | `"/images/hero.jpg"` |
| `color` | Color value | `"#ff0000"` |
| `date` | Date/time | `"2025-01-01"` |

### D. Platform Identifiers

| ID | Platform | Type |
|----|----------|------|
| `wordpress` | WordPress | Web CMS |
| `joomla` | Joomla | Web CMS |
| `drupal` | Drupal | Web CMS |
| `ikabud` | Ikabud CMS | Web CMS |
| `generic` | Generic HTML | Web |
| `react_native` | React Native | Mobile |
| `flutter` | Flutter | Mobile |
| `ios` | Native iOS | Mobile |
| `android` | Native Android | Mobile |
| `electron` | Electron | Desktop |
| `tauri` | Tauri | Desktop |
| `windows` | Native Windows | Desktop |
| `macos` | Native macOS | Desktop |
| `linux` | Native Linux | Desktop |

---

**DiSyL v1.0.0 - Universal Declarative Template Language** 🚀

*Write Once, Render Anywhere*

© 2025 Ikabud Team | MIT License
