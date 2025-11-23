# DiSyL Syntax Reference Guide
## Complete Documentation for All Supported CMS Platforms

**Version:** 0.6.0  
**Last Updated:** November 23, 2025  
**License:** MIT

---

## Table of Contents

1. [Introduction](#introduction)
2. [Core Syntax](#core-syntax)
3. [Universal Components](#universal-components)
4. [WordPress-Specific Syntax](#wordpress-specific-syntax)
5. [Joomla-Specific Syntax](#joomla-specific-syntax)
6. [Drupal-Specific Syntax](#drupal-specific-syntax)
7. [Filters Reference](#filters-reference)
8. [Conditional Logic](#conditional-logic)
9. [Loops & Queries](#loops--queries)
10. [Best Practices](#best-practices)

---

## Introduction

DiSyL (Declarative Ikabud Syntax Language) is a universal, CMS-agnostic template language that enables developers to write templates once and deploy them across multiple CMS platforms.

### Key Features

- **Write Once, Deploy Everywhere** - Single template syntax for all CMSs
- **High Performance** - Compiled AST with caching
- **Security First** - Built-in XSS prevention
- **Rich Component Library** - 50+ components and filters

### Supported CMS Platforms

- ✅ WordPress
- ✅ Joomla
- ✅ Drupal (in development)
- ✅ Ikabud CMS (native)

---

## Core Syntax

### Comments

```disyl
{!-- This is a comment --}
{!-- 
  Multi-line comment
  Can span multiple lines
--}
```

### CMS Header Declaration

Declare which CMS your template targets (optional but recommended):

```disyl
{ikb_cms type="wordpress" set="components,filters" /}
```

**Attributes:**
- `type` - CMS type: `wordpress`, `joomla`, `drupal`, or `generic`
- `set` - Comma-separated list: `filters`, `components`, `hooks`, `functions`

**Examples:**

```disyl
{!-- WordPress template --}
{ikb_cms type="wordpress" /}

{!-- Joomla template --}
{ikb_cms type="joomla" set="components,filters" /}

{!-- Generic template --}
{ikb_cms type="generic" /}
```

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

## Appendix: Quick Reference

### Component Cheat Sheet

| Component | Purpose | Example |
|-----------|---------|---------|
| `ikb_section` | Page section | `{ikb_section type="hero"}...{/ikb_section}` |
| `ikb_container` | Centered container | `{ikb_container size="large"}...{/ikb_container}` |
| `ikb_grid` | Grid layout | `{ikb_grid columns="3"}...{/ikb_grid}` |
| `ikb_text` | Styled text | `{ikb_text size="xl"}...{/ikb_text}` |
| `ikb_button` | Button/link | `{ikb_button href="/link"}...{/ikb_button}` |
| `ikb_image` | Image | `{ikb_image src="..." alt="..." /}` |
| `ikb_card` | Card container | `{ikb_card variant="elevated"}...{/ikb_card}` |
| `ikb_query` | Content loop | `{ikb_query type="post" limit=6}...{/ikb_query}` |

### Filter Cheat Sheet

| Filter | Purpose | Example |
|--------|---------|---------|
| `esc_html` | Escape HTML | `{text | esc_html}` |
| `esc_url` | Escape URL | `{url | esc_url}` |
| `esc_attr` | Escape attribute | `{text | esc_attr}` |
| `strip_tags` | Remove HTML | `{html | strip_tags}` |
| `upper` | Uppercase | `{text | upper}` |
| `lower` | Lowercase | `{text | lower}` |
| `truncate` | Truncate text | `{text | truncate:length=150}` |
| `date` | Format date | `{date | date:format="F j, Y"}` |

---

**DiSyL v0.6.0 - Write Once, Deploy Everywhere** 🚀

© 2025 Ikabud Team | MIT License
