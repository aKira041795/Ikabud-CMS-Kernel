# DiSyL Component Catalog v0.1

Visual catalog of all DiSyL components with examples and use cases.

---

## Table of Contents

1. [Structural Components](#structural-components)
2. [Data Components](#data-components)
3. [UI Components](#ui-components)
4. [Media Components](#media-components)
5. [Control Components](#control-components)

---

## Structural Components

### ikb_section

**Purpose**: Main structural container for page sections

**Category**: Structural  
**Leaf**: No (can have children)

#### Attributes

| Attribute | Type | Values | Default | Required |
|-----------|------|--------|---------|----------|
| `type` | string | `hero`, `content`, `footer`, `sidebar` | `content` | No |
| `title` | string | Any string | - | No |
| `bg` | string | Any color | `transparent` | No |
| `padding` | string | `none`, `small`, `normal`, `large` | `normal` | No |

#### Examples

**Hero Section**:
```disyl
{ikb_section type="hero" bg="#f0f0f0" padding="large"}
    {ikb_text size="2xl" weight="bold"}Welcome to Our Site{/ikb_text}
{/ikb_section}
```

**Content Section**:
```disyl
{ikb_section type="content" title="Latest Posts"}
    {ikb_query type="post" limit=6}
        {ikb_card title="{item.title}" /}
    {/ikb_query}
{/ikb_section}
```

**Footer Section**:
```disyl
{ikb_section type="footer" bg="#333" padding="normal"}
    {ikb_text color="#fff" align="center"}© 2025 Company{/ikb_text}
{/ikb_section}
```

#### Use Cases

- Page sections (hero, content, footer)
- Landing page layouts
- Multi-section pages
- Themed areas

---

### ikb_block

**Purpose**: Generic content block with layout options

**Category**: Structural  
**Leaf**: No (can have children)

#### Attributes

| Attribute | Type | Values | Default | Required |
|-----------|------|--------|---------|----------|
| `cols` | integer | 1-12 | `1` | No |
| `gap` | number | 0-10 | `1` | No |
| `align` | string | `left`, `center`, `right`, `justify` | `left` | No |

#### Examples

**2-Column Layout**:
```disyl
{ikb_block cols=2 gap=2}
    {ikb_card title="Left Column" /}
    {ikb_card title="Right Column" /}
{/ikb_block}
```

**3-Column Grid**:
```disyl
{ikb_block cols=3 gap=1.5}
    {ikb_card title="Card 1" /}
    {ikb_card title="Card 2" /}
    {ikb_card title="Card 3" /}
{/ikb_block}
```

**4-Column with Large Gap**:
```disyl
{ikb_block cols=4 gap=3 align="center"}
    {ikb_image src="icon1.png" alt="Icon 1" /}
    {ikb_image src="icon2.png" alt="Icon 2" /}
    {ikb_image src="icon3.png" alt="Icon 3" /}
    {ikb_image src="icon4.png" alt="Icon 4" /}
{/ikb_block}
```

#### Use Cases

- Grid layouts
- Multi-column content
- Card grids
- Feature lists
- Icon grids

---

### ikb_container

**Purpose**: Responsive container with max-width

**Category**: Structural  
**Leaf**: No (can have children)

#### Attributes

| Attribute | Type | Values | Default | Required |
|-----------|------|--------|---------|----------|
| `width` | string | `sm`, `md`, `lg`, `xl`, `full` | `lg` | No |
| `center` | boolean | `true`, `false` | `true` | No |

#### Width Values

- `sm`: 640px
- `md`: 768px
- `lg`: 1024px
- `xl`: 1280px
- `full`: 100%

#### Examples

**Large Centered Container**:
```disyl
{ikb_container width="lg" center=true}
    {ikb_text}Content here{/ikb_text}
{/ikb_container}
```

**Extra Large Container**:
```disyl
{ikb_container width="xl"}
    {ikb_block cols=4}
        {ikb_card /}
        {ikb_card /}
        {ikb_card /}
        {ikb_card /}
    {/ikb_block}
{/ikb_container}
```

**Full Width Container**:
```disyl
{ikb_container width="full" center=false}
    {ikb_text}Full width content{/ikb_text}
{/ikb_container}
```

#### Use Cases

- Page width constraints
- Responsive layouts
- Content centering
- Reading width optimization

---

## Data Components

### ikb_query

**Purpose**: Query and loop over content items

**Category**: Data  
**Leaf**: No (can have children)

#### Attributes

| Attribute | Type | Values | Default | Required |
|-----------|------|--------|---------|----------|
| `type` | string | Any content type | `post` | No |
| `limit` | integer | 1-100 | `10` | No |
| `orderby` | string | `date`, `title`, `modified`, `random` | `date` | No |
| `order` | string | `asc`, `desc` | `desc` | No |
| `category` | string | Category name | - | No |

#### Item Context

Children have access to `item` variable with:
- `item.id` - Content ID
- `item.title` - Title
- `item.content` - Full content
- `item.excerpt` - Excerpt
- `item.url` - Permalink
- `item.date` - Publish date
- `item.author` - Author name
- `item.thumbnail` - Featured image URL
- `item.categories` - Category names

#### Examples

**Latest 6 Posts**:
```disyl
{ikb_query type="post" limit=6 orderby="date" order="desc"}
    {ikb_card title="{item.title}" link="{item.url}" image="{item.thumbnail}" /}
{/ikb_query}
```

**Posts by Category**:
```disyl
{ikb_query type="post" limit=10 category="news"}
    {ikb_block cols=2}
        {ikb_card title="{item.title}" link="{item.url}"}
            {ikb_text size="sm"}{item.excerpt}{/ikb_text}
        {/ikb_card}
    {/ikb_block}
{/ikb_query}
```

**Random Posts**:
```disyl
{ikb_query type="post" limit=3 orderby="random"}
    {ikb_card title="{item.title}" link="{item.url}" variant="elevated" /}
{/ikb_query}
```

#### Use Cases

- Blog post listings
- Product grids
- News feeds
- Portfolio items
- Related content

---

## UI Components

### ikb_card

**Purpose**: Card component for displaying content

**Category**: UI  
**Leaf**: No (can have children)

#### Attributes

| Attribute | Type | Values | Default | Required |
|-----------|------|--------|---------|----------|
| `title` | string | Any string | - | No |
| `image` | string | Image URL | - | No |
| `link` | string | URL | - | No |
| `variant` | string | `default`, `outlined`, `elevated` | `default` | No |

#### Variants

- `default`: Simple border
- `outlined`: Bold border
- `elevated`: Box shadow

#### Examples

**Simple Card**:
```disyl
{ikb_card title="Card Title"}
    {ikb_text}Card content here{/ikb_text}
{/ikb_card}
```

**Card with Image**:
```disyl
{ikb_card title="Product Name" image="product.jpg" variant="elevated"}
    {ikb_text}$99.99{/ikb_text}
{/ikb_card}
```

**Linked Card**:
```disyl
{ikb_card title="Read More" link="/article" variant="outlined"}
    {ikb_text size="sm"}Click to read the full article{/ikb_text}
{/ikb_card}
```

**Self-Closing Card**:
```disyl
{ikb_card title="Quick Card" variant="elevated" /}
```

#### Use Cases

- Product cards
- Blog post previews
- Feature highlights
- Team member cards
- Pricing cards

---

### ikb_text

**Purpose**: Text content with formatting

**Category**: UI  
**Leaf**: No (can have children)

#### Attributes

| Attribute | Type | Values | Default | Required |
|-----------|------|--------|---------|----------|
| `size` | string | `xs`, `sm`, `md`, `lg`, `xl`, `2xl` | `md` | No |
| `weight` | string | `light`, `normal`, `medium`, `bold` | `normal` | No |
| `color` | string | Any color | - | No |
| `align` | string | `left`, `center`, `right`, `justify` | `left` | No |

#### Size Reference

- `xs`: 0.75rem (12px)
- `sm`: 0.875rem (14px)
- `md`: 1rem (16px)
- `lg`: 1.125rem (18px)
- `xl`: 1.25rem (20px)
- `2xl`: 1.5rem (24px)

#### Examples

**Heading**:
```disyl
{ikb_text size="2xl" weight="bold"}Main Heading{/ikb_text}
```

**Subheading**:
```disyl
{ikb_text size="xl" weight="medium" color="#666"}Subheading{/ikb_text}
```

**Body Text**:
```disyl
{ikb_text size="md"}This is regular body text.{/ikb_text}
```

**Caption**:
```disyl
{ikb_text size="sm" color="#999" align="center"}Image caption{/ikb_text}
```

**Centered Text**:
```disyl
{ikb_text size="lg" weight="bold" align="center" color="#333"}
    Centered Bold Text
{/ikb_text}
```

#### Use Cases

- Headings and titles
- Body content
- Captions and labels
- Quotes and callouts
- Descriptions

---

## Media Components

### ikb_image

**Purpose**: Responsive image with optimization

**Category**: Media  
**Leaf**: Yes (no children)

#### Attributes

| Attribute | Type | Values | Default | Required |
|-----------|------|--------|---------|----------|
| `src` | string | Image URL | - | **Yes** |
| `alt` | string | Alt text | - | **Yes** |
| `width` | integer | Pixels | - | No |
| `height` | integer | Pixels | - | No |
| `lazy` | boolean | `true`, `false` | `true` | No |
| `responsive` | boolean | `true`, `false` | `true` | No |

#### Examples

**Basic Image**:
```disyl
{ikb_image src="logo.png" alt="Company Logo" /}
```

**Fixed Size Image**:
```disyl
{ikb_image src="avatar.jpg" alt="User Avatar" width=100 height=100 /}
```

**Non-Lazy Image**:
```disyl
{ikb_image src="hero.jpg" alt="Hero Image" lazy=false /}
```

**Non-Responsive Image**:
```disyl
{ikb_image src="icon.png" alt="Icon" width=32 height=32 responsive=false /}
```

#### Use Cases

- Logos
- Hero images
- Product photos
- Avatars
- Icons
- Gallery images

---

## Control Components

### if

**Purpose**: Conditional rendering

**Category**: Control  
**Leaf**: No (can have children)

#### Attributes

| Attribute | Type | Values | Default | Required |
|-----------|------|--------|---------|----------|
| `condition` | string | Expression | - | **Yes** |

#### Examples

**Simple Condition**:
```disyl
{if condition="user.loggedIn"}
    {ikb_text}Welcome back!{/ikb_text}
{/if}
```

**Check for Value**:
```disyl
{if condition="item.thumbnail"}
    {ikb_image src="{item.thumbnail}" alt="{item.title}" /}
{/if}
```

**Nested Conditions**:
```disyl
{if condition="user.loggedIn"}
    {if condition="user.isAdmin"}
        {ikb_text}Admin Panel{/ikb_text}
    {/if}
{/if}
```

#### Use Cases

- Show/hide content
- User-specific content
- Feature flags
- Conditional layouts

---

### for

**Purpose**: Loop over items

**Category**: Control  
**Leaf**: No (can have children)

#### Attributes

| Attribute | Type | Values | Default | Required |
|-----------|------|--------|---------|----------|
| `items` | string | Variable name | - | **Yes** |
| `as` | string | Iterator name | `item` | No |

#### Examples

**Basic Loop**:
```disyl
{for items="posts" as="post"}
    {ikb_card title="{post.title}" /}
{/for}
```

**Custom Iterator Name**:
```disyl
{for items="products" as="product"}
    {ikb_card title="{product.name}" image="{product.image}" /}
{/for}
```

**Nested Loops**:
```disyl
{for items="categories" as="category"}
    {ikb_text size="xl"}{category.name}{/ikb_text}
    {for items="category.posts" as="post"}
        {ikb_card title="{post.title}" /}
    {/for}
{/for}
```

#### Use Cases

- Iterate over arrays
- Render lists
- Dynamic content
- Repeated elements

---

### include

**Purpose**: Include another template

**Category**: Control  
**Leaf**: Yes (no children)

#### Attributes

| Attribute | Type | Values | Default | Required |
|-----------|------|--------|---------|----------|
| `template` | string | Template path | - | **Yes** |

#### Examples

**Include Header**:
```disyl
{include template="header.disyl"}
```

**Include Component**:
```disyl
{include template="components/navigation.disyl"}
```

**Include Footer**:
```disyl
{include template="footer.disyl"}
```

#### Use Cases

- Reusable components
- Template partials
- Headers and footers
- Shared layouts

---

## Component Summary

| Component | Category | Leaf | Primary Use |
|-----------|----------|------|-------------|
| `ikb_section` | Structural | No | Page sections |
| `ikb_block` | Structural | No | Grid layouts |
| `ikb_container` | Structural | No | Width constraints |
| `ikb_query` | Data | No | Content queries |
| `ikb_card` | UI | No | Content cards |
| `ikb_text` | UI | No | Formatted text |
| `ikb_image` | Media | Yes | Images |
| `if` | Control | No | Conditionals |
| `for` | Control | No | Loops |
| `include` | Control | Yes | Template inclusion |

---

## See Also

- [Language Reference](DISYL_LANGUAGE_REFERENCE.md)
- [Code Examples](DISYL_CODE_EXAMPLES.md)
- [API Reference](DISYL_API_REFERENCE.md)
