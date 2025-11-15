# DiSyL Conversion Examples

**Version:** 1.0.0  
**Last Updated:** November 14, 2025  
**Related:** [DISYL_CONVERSION_ROADMAP.md](DISYL_CONVERSION_ROADMAP.md)

---

## Table of Contents

1. [Simple Conversions](#simple-conversions)
2. [Loop Conversions](#loop-conversions)
3. [Conditional Logic](#conditional-logic)
4. [Complex Queries](#complex-queries)
5. [Template Parts](#template-parts)
6. [Custom Post Types](#custom-post-types)
7. [Joomla Examples](#joomla-examples)
8. [Drupal Examples](#drupal-examples)

---

## Simple Conversions

### Example 1: Basic Post Display

**WordPress PHP:**
```php
<article>
    <h2><?php the_title(); ?></h2>
    <div class="meta">
        <span><?php the_date(); ?></span>
        <span><?php the_author(); ?></span>
    </div>
    <div class="content">
        <?php the_content(); ?>
    </div>
</article>
```

**DiSyL:**
```disyl
<article>
    {ikb_text size="xl" weight="bold"}
        {post.title | esc_html}
    {/ikb_text}
    <div class="meta">
        <span>{post.date | date:format='F j, Y'}</span>
        <span>{post.author | esc_html}</span>
    </div>
    <div class="content">
        {post.content}
    </div>
</article>
```

### Example 2: Featured Image

**WordPress PHP:**
```php
<?php if (has_post_thumbnail()): ?>
    <div class="featured-image">
        <img src="<?php echo get_the_post_thumbnail_url(); ?>" 
             alt="<?php echo esc_attr(get_the_title()); ?>">
    </div>
<?php endif; ?>
```

**DiSyL:**
```disyl
{if condition="post.thumbnail"}
    <div class="featured-image">
        {ikb_image 
            src="{post.thumbnail | esc_url}"
            alt="{post.title | esc_attr}"
            lazy=true
        /}
    </div>
{/if}
```

### Example 3: Post Excerpt

**WordPress PHP:**
```php
<div class="excerpt">
    <?php echo wp_trim_words(get_the_excerpt(), 30, '...'); ?>
</div>
<a href="<?php the_permalink(); ?>">Read More</a>
```

**DiSyL:**
```disyl
<div class="excerpt">
    {post.excerpt | wp_trim_words:num_words=30}
</div>
<a href="{post.url | esc_url}">Read More</a>
```

---

## Loop Conversions

### Example 4: Standard Loop

**WordPress PHP:**
```php
<?php if (have_posts()): ?>
    <div class="posts">
        <?php while (have_posts()): the_post(); ?>
            <article>
                <h2><?php the_title(); ?></h2>
                <div><?php the_excerpt(); ?></div>
            </article>
        <?php endwhile; ?>
    </div>
<?php endif; ?>
```

**DiSyL:**
```disyl
<div class="posts">
    {ikb_query type="post"}
        <article>
            {ikb_text size="xl" weight="bold"}
                {item.title | esc_html}
            {/ikb_text}
            <div>{item.excerpt}</div>
        </article>
    {/ikb_query}
</div>
```

### Example 5: WP_Query with Parameters

**WordPress PHP:**
```php
<?php
$args = array(
    'post_type' => 'post',
    'posts_per_page' => 5,
    'category_name' => 'news',
    'orderby' => 'date',
    'order' => 'DESC'
);
$query = new WP_Query($args);

if ($query->have_posts()): 
    while ($query->have_posts()): $query->the_post(); ?>
        <article>
            <h3><?php the_title(); ?></h3>
            <p><?php the_excerpt(); ?></p>
        </article>
    <?php endwhile;
    wp_reset_postdata();
endif;
?>
```

**DiSyL:**
```disyl
{ikb_query 
    type="post" 
    limit=5 
    category="news"
    orderby="date"
    order="desc"
}
    <article>
        {ikb_text size="lg" weight="semibold"}
            {item.title | esc_html}
        {/ikb_text}
        <p>{item.excerpt}</p>
    </article>
{/ikb_query}
```

### Example 6: Nested Loops

**WordPress PHP:**
```php
<?php
$categories = get_categories();
foreach ($categories as $category):
    $posts = get_posts(array('category' => $category->term_id, 'numberposts' => 3));
    ?>
    <section>
        <h2><?php echo $category->name; ?></h2>
        <div class="posts">
            <?php foreach ($posts as $post): setup_postdata($post); ?>
                <article>
                    <h3><?php the_title(); ?></h3>
                </article>
            <?php endforeach; wp_reset_postdata(); ?>
        </div>
    </section>
<?php endforeach; ?>
```

**DiSyL:**
```disyl
{ikb_query type="category"}
    <section>
        {ikb_text size="xl" weight="bold"}
            {item.name | esc_html}
        {/ikb_text}
        <div class="posts">
            {ikb_query type="post" category="{item.slug}" limit=3}
                <article>
                    {ikb_text size="lg" weight="semibold"}
                        {item.title | esc_html}
                    {/ikb_text}
                </article>
            {/ikb_query}
        </div>
    </section>
{/ikb_query}
```

---

## Conditional Logic

### Example 7: Multiple Conditions

**WordPress PHP:**
```php
<?php if (is_home() || is_front_page()): ?>
    <h1>Welcome to Our Blog</h1>
<?php elseif (is_single()): ?>
    <h1><?php the_title(); ?></h1>
<?php else: ?>
    <h1><?php wp_title(''); ?></h1>
<?php endif; ?>
```

**DiSyL:**
```disyl
{if condition="is_home || is_front_page"}
    {ikb_text size="3xl" weight="bold"}Welcome to Our Blog{/ikb_text}
{/if}

{if condition="is_single"}
    {ikb_text size="3xl" weight="bold"}{post.title | esc_html}{/ikb_text}
{/if}

{if condition="!is_home && !is_front_page && !is_single"}
    {ikb_text size="3xl" weight="bold"}{page.title | esc_html}{/ikb_text}
{/if}
```

### Example 8: Complex Conditionals

**WordPress PHP:**
```php
<?php if (has_post_thumbnail() && !is_single()): ?>
    <a href="<?php the_permalink(); ?>">
        <?php the_post_thumbnail('medium'); ?>
    </a>
<?php elseif (has_post_thumbnail() && is_single()): ?>
    <?php the_post_thumbnail('large'); ?>
<?php endif; ?>
```

**DiSyL:**
```disyl
{if condition="post.thumbnail && !is_single"}
    <a href="{post.url | esc_url}">
        {ikb_image src="{post.thumbnail | esc_url}" size="medium" /}
    </a>
{/if}

{if condition="post.thumbnail && is_single"}
    {ikb_image src="{post.thumbnail | esc_url}" size="large" /}
{/if}
```

### Example 9: User Capability Checks

**WordPress PHP:**
```php
<?php if (current_user_can('edit_posts')): ?>
    <a href="<?php echo get_edit_post_link(); ?>">Edit Post</a>
<?php endif; ?>
```

**DiSyL:**
```disyl
{if condition="user.can_edit_posts"}
    <a href="{post.edit_url | esc_url}">Edit Post</a>
{/if}
```

---

## Complex Queries

### Example 10: Meta Query

**WordPress PHP:**
```php
<?php
$args = array(
    'post_type' => 'product',
    'meta_query' => array(
        array(
            'key' => 'price',
            'value' => 100,
            'compare' => '<',
            'type' => 'NUMERIC'
        )
    )
);
$query = new WP_Query($args);

if ($query->have_posts()):
    while ($query->have_posts()): $query->the_post(); ?>
        <div class="product">
            <h3><?php the_title(); ?></h3>
            <p>Price: $<?php echo get_post_meta(get_the_ID(), 'price', true); ?></p>
        </div>
    <?php endwhile;
    wp_reset_postdata();
endif;
?>
```

**DiSyL:**
```disyl
{ikb_query 
    type="product"
    meta_key="price"
    meta_value="100"
    meta_compare="<"
    meta_type="numeric"
}
    <div class="product">
        {ikb_text size="lg" weight="semibold"}
            {item.title | esc_html}
        {/ikb_text}
        <p>Price: ${item.meta.price}</p>
    </div>
{/ikb_query}
```

### Example 11: Tax Query

**WordPress PHP:**
```php
<?php
$args = array(
    'post_type' => 'post',
    'tax_query' => array(
        array(
            'taxonomy' => 'category',
            'field' => 'slug',
            'terms' => array('news', 'updates')
        )
    )
);
$query = new WP_Query($args);
?>
```

**DiSyL:**
```disyl
{ikb_query 
    type="post"
    taxonomy="category"
    terms="news,updates"
}
    {!-- Query results --}
{/ikb_query}
```

### Example 12: Date Query

**WordPress PHP:**
```php
<?php
$args = array(
    'post_type' => 'post',
    'date_query' => array(
        array(
            'after' => '1 month ago',
            'inclusive' => true
        )
    )
);
$query = new WP_Query($args);
?>
```

**DiSyL:**
```disyl
{ikb_query 
    type="post"
    date_after="1 month ago"
}
    {!-- Recent posts --}
{/ikb_query}
```

---

## Template Parts

### Example 13: Header Template

**WordPress PHP (header.php):**
```php
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php wp_title('|', true, 'right'); ?></title>
    <?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
    <header>
        <h1><?php bloginfo('name'); ?></h1>
        <nav>
            <?php wp_nav_menu(array('theme_location' => 'primary')); ?>
        </nav>
    </header>
```

**DiSyL (header.disyl):**
```disyl
<!DOCTYPE html>
<html lang="{site.language}">
<head>
    <meta charset="{site.charset}">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{page.title} | {site.name}</title>
    {wp_head /}
</head>
<body class="{body_class}">
    {ikb_section type="header"}
        {ikb_container}
            {ikb_text size="2xl" weight="bold"}
                {site.name | esc_html}
            {/ikb_text}
            {ikb_menu location="primary" /}
        {/ikb_container}
    {/ikb_section}
```

### Example 14: Footer Template

**WordPress PHP (footer.php):**
```php
    <footer>
        <div class="footer-widgets">
            <?php dynamic_sidebar('footer-1'); ?>
            <?php dynamic_sidebar('footer-2'); ?>
        </div>
        <div class="copyright">
            <p>&copy; <?php echo date('Y'); ?> <?php bloginfo('name'); ?></p>
        </div>
    </footer>
    <?php wp_footer(); ?>
</body>
</html>
```

**DiSyL (footer.disyl):**
```disyl
    {ikb_section type="footer"}
        {ikb_container}
            <div class="footer-widgets">
                {ikb_sidebar id="footer-1" /}
                {ikb_sidebar id="footer-2" /}
            </div>
            <div class="copyright">
                <p>&copy; {current_year} {site.name | esc_html}</p>
            </div>
        {/ikb_container}
    {/ikb_section}
    {wp_footer /}
</body>
</html>
```

### Example 15: Template Part Include

**WordPress PHP:**
```php
<?php get_template_part('template-parts/content', 'post'); ?>
```

**DiSyL:**
```disyl
{include file="template-parts/content-post.disyl"}
```

---

## Custom Post Types

### Example 16: Portfolio Items

**WordPress PHP:**
```php
<?php
$args = array(
    'post_type' => 'portfolio',
    'posts_per_page' => 12
);
$query = new WP_Query($args);

if ($query->have_posts()): ?>
    <div class="portfolio-grid">
        <?php while ($query->have_posts()): $query->the_post(); ?>
            <div class="portfolio-item">
                <?php if (has_post_thumbnail()): ?>
                    <a href="<?php the_permalink(); ?>">
                        <?php the_post_thumbnail('portfolio-thumb'); ?>
                    </a>
                <?php endif; ?>
                <h3><?php the_title(); ?></h3>
                <div class="project-meta">
                    <span><?php echo get_post_meta(get_the_ID(), 'client', true); ?></span>
                    <span><?php echo get_post_meta(get_the_ID(), 'year', true); ?></span>
                </div>
            </div>
        <?php endwhile; ?>
    </div>
<?php
    wp_reset_postdata();
endif;
?>
```

**DiSyL:**
```disyl
<div class="portfolio-grid">
    {ikb_query type="portfolio" limit=12}
        <div class="portfolio-item">
            {if condition="item.thumbnail"}
                <a href="{item.url | esc_url}">
                    {ikb_image 
                        src="{item.thumbnail | esc_url}"
                        size="portfolio-thumb"
                        alt="{item.title | esc_attr}"
                    /}
                </a>
            {/if}
            {ikb_text size="lg" weight="semibold"}
                {item.title | esc_html}
            {/ikb_text}
            <div class="project-meta">
                <span>{item.meta.client | esc_html}</span>
                <span>{item.meta.year}</span>
            </div>
        </div>
    {/ikb_query}
</div>
```

### Example 17: Events with Custom Fields

**WordPress PHP:**
```php
<?php
$args = array(
    'post_type' => 'event',
    'meta_key' => 'event_date',
    'orderby' => 'meta_value',
    'order' => 'ASC',
    'meta_query' => array(
        array(
            'key' => 'event_date',
            'value' => date('Y-m-d'),
            'compare' => '>=',
            'type' => 'DATE'
        )
    )
);
$query = new WP_Query($args);

if ($query->have_posts()):
    while ($query->have_posts()): $query->the_post();
        $event_date = get_post_meta(get_the_ID(), 'event_date', true);
        $location = get_post_meta(get_the_ID(), 'location', true);
        ?>
        <div class="event">
            <h3><?php the_title(); ?></h3>
            <p>Date: <?php echo date('F j, Y', strtotime($event_date)); ?></p>
            <p>Location: <?php echo esc_html($location); ?></p>
        </div>
    <?php endwhile;
    wp_reset_postdata();
endif;
?>
```

**DiSyL:**
```disyl
{ikb_query 
    type="event"
    meta_key="event_date"
    meta_compare=">="
    meta_value="{current_date}"
    meta_type="date"
    orderby="meta_value"
    order="asc"
}
    <div class="event">
        {ikb_text size="lg" weight="semibold"}
            {item.title | esc_html}
        {/ikb_text}
        <p>Date: {item.meta.event_date | date:format='F j, Y'}</p>
        <p>Location: {item.meta.location | esc_html}</p>
    </div>
{/ikb_query}
```

---

## Joomla Examples

### Example 18: Joomla Article List

**Joomla PHP:**
```php
<?php
$db = JFactory::getDbo();
$query = $db->getQuery(true)
    ->select('*')
    ->from('#__content')
    ->where('state = 1')
    ->order('created DESC')
    ->setLimit(10);

$db->setQuery($query);
$articles = $db->loadObjectList();

foreach ($articles as $article): ?>
    <article>
        <h2><?php echo $article->title; ?></h2>
        <div><?php echo $article->introtext; ?></div>
        <a href="<?php echo JRoute::_('index.php?option=com_content&view=article&id=' . $article->id); ?>">
            Read More
        </a>
    </article>
<?php endforeach; ?>
```

**DiSyL (Joomla):**
```disyl
{ikb_query type="article" limit=10}
    <article>
        {ikb_text size="xl" weight="bold"}
            {item.title | esc_html}
        {/ikb_text}
        <div>{item.content}</div>
        <a href="{item.url | esc_url}">Read More</a>
    </article>
{/ikb_query}
```

### Example 19: Joomla Module Position

**Joomla PHP:**
```php
<jdoc:include type="modules" name="sidebar" style="xhtml" />
```

**DiSyL (Joomla):**
```disyl
{joomla_modules position="sidebar" style="xhtml" /}
```

---

## Drupal Examples

### Example 20: Drupal Node List

**Drupal PHP:**
```php
<?php
$query = \Drupal::entityQuery('node')
    ->condition('type', 'article')
    ->condition('status', 1)
    ->sort('created', 'DESC')
    ->range(0, 10);

$nids = $query->execute();
$nodes = \Drupal\node\Entity\Node::loadMultiple($nids);

foreach ($nodes as $node): ?>
    <article>
        <h2><?php echo $node->getTitle(); ?></h2>
        <div><?php echo $node->get('body')->value; ?></div>
        <a href="<?php echo $node->toUrl()->toString(); ?>">Read More</a>
    </article>
<?php endforeach; ?>
```

**DiSyL (Drupal):**
```disyl
{ikb_query type="article" limit=10}
    <article>
        {ikb_text size="xl" weight="bold"}
            {item.title | esc_html}
        {/ikb_text}
        <div>{item.content}</div>
        <a href="{item.url | esc_url}">Read More</a>
    </article>
{/ikb_query}
```

### Example 21: Drupal Block Region

**Drupal PHP:**
```php
<?php echo $page['sidebar_first']; ?>
```

**DiSyL (Drupal):**
```disyl
{drupal_region name="sidebar_first" /}
```

---

## Conversion Patterns Summary

### Function Mappings

| WordPress | DiSyL | Notes |
|-----------|-------|-------|
| `the_title()` | `{post.title}` | Add `\| esc_html` filter |
| `the_content()` | `{post.content}` | Already sanitized |
| `the_excerpt()` | `{post.excerpt}` | Can add `\| wp_trim_words` |
| `get_permalink()` | `{post.url}` | Add `\| esc_url` filter |
| `has_post_thumbnail()` | `{if condition="post.thumbnail"}` | Conditional check |
| `the_post_thumbnail()` | `{ikb_image src="{post.thumbnail}"}` | Component-based |
| `wp_nav_menu()` | `{ikb_menu location="primary"}` | Component-based |
| `dynamic_sidebar()` | `{ikb_sidebar id="sidebar-1"}` | Component-based |

### Loop Patterns

| WordPress | DiSyL |
|-----------|-------|
| `while (have_posts())` | `{ikb_query type="post"}` |
| `new WP_Query($args)` | `{ikb_query ...attrs}` |
| `foreach ($items as $item)` | `{for items="..." as="item"}` |

### Conditional Patterns

| WordPress | DiSyL |
|-----------|-------|
| `if (condition)` | `{if condition="..."}` |
| `is_home()` | `{if condition="is_home"}` |
| `current_user_can()` | `{if condition="user.can_..."}` |

---

**Document Version:** 1.0.0  
**Last Updated:** November 14, 2025  
**Maintained By:** Ikabud Kernel Team
