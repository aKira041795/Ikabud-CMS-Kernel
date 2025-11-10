<?php

/**
 * Full block part for displaying page content in page.php
 *
 * @package MoreNews
 */

global $post;
$morenews_img_url = morenews_get_freatured_image_url($post->ID, 'large');
$morenews_img_thumb_id = get_post_thumbnail_id($post->ID);
$morenews_show_excerpt = morenews_get_option('archive_content_view');
?>
<div class="read-single color-pad">
  <div class="read-item">
    <div class="data-bg read-img pos-rel read-bg-img"
      data-background="<?php echo esc_url($morenews_img_url); ?>">
      <img src="<?php echo esc_url($morenews_img_url); ?>"
        alt="<?php echo esc_attr(morenews_get_img_alt($morenews_img_thumb_id)); ?>">
      <a class="aft-post-image-link" href="<?php the_permalink(); ?>"><?php the_title(); ?></a>
      <?php morenews_archive_social_share_icons($post->ID); ?>
      <?php morenews_post_format($post->ID); ?>
      <?php morenews_count_content_words($post->ID); ?>

    </div>

    <div class="read-details pad-archive">

      <?php if ('post' === get_post_type()) : ?>
        <div class="read-categories">
          <?php morenews_post_categories(); ?>
        </div>
      <?php endif; ?>
      <div class="read-title">
        <?php the_title('<h3 class="entry-title">
                    <a href="' . esc_url(get_permalink()) . '" aria-label="' . esc_attr(get_the_title($morenews_post_id, 'morenews')) . '" rel="bookmark">', '</a>
                </h3>'); ?>
      </div>

      <?php if ('post' === get_post_type()) : ?>
        <div class="post-item-metadata entry-meta author-links">
          <?php morenews_post_item_meta(); ?>
          <?php morenews_get_comments_views_share($post->ID); ?>
        </div>
      <?php endif; ?>
    </div>

  </div>
  <?php if ($morenews_show_excerpt != 'archive-content-none'): ?>
    <div class="read-descprition full-item-discription">
      <div class="post-description">
        <?php
        if ($morenews_show_excerpt == 'archive-content-excerpt') {
          echo wp_kses_post(morenews_get_the_excerpt($post->ID));
        } else {
          the_content();
        }
        ?>
      </div>
    </div>
  <?php endif; ?>

</div>