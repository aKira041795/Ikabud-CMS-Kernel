<?php
if (!function_exists('morenews_loop_grid')) :
  /**
   * Banner Slider
   *
   * @since Newsical 1.0.0
   *
   */
  function morenews_loop_grid($morenews_post_id, $morenews_grid_design = 'grid-design-default', $morenews_thumbnail_size = 'medium_large', $morenews_show_excerpt = false, $archive_content_view = 'archive-content-excerpt', $morenews_title_position = 'bottom', $archive_title_tag = 'h3')
  {
    $morenews_post_display = 'spotlight-post';
    if ($morenews_thumbnail_size == 'medium') {
      $morenews_post_display = 'grid-post';
    }

    // Get the post thumbnail and check if it exists
    $morenews_post_thumbnail = morenews_the_post_thumbnail($morenews_thumbnail_size, $morenews_post_id, true);
    $morenews_no_thumbnail_class = "has-post-image";
    if (!isset($morenews_post_thumbnail) || empty($morenews_post_thumbnail)) {
      $morenews_no_thumbnail_class = "no-post-image";
    }
?>

    <div class="pos-rel read-single color-pad clearfix af-cat-widget-carousel <?php echo esc_attr($morenews_grid_design); ?> <?php echo esc_attr($morenews_no_thumbnail_class); ?>">
      <?php if ($morenews_title_position == 'top'): ?>
        <div class="read-title">
          <h3>
            <a href="<?php the_permalink(); ?>"><?php the_title(); ?></a>
          </h3>
        </div>
        <div class="post-item-metadata entry-meta author-links">
          <?php morenews_post_item_meta($morenews_post_display); ?>
          <?php morenews_get_comments_views_share($morenews_post_id); ?>
        </div>
      <?php endif; ?>

      <div class="read-img pos-rel read-bg-img">
        <a class="aft-post-image-link" aria-label="<?php echo esc_attr(get_the_title($morenews_post_id, 'morenews')); ?>" href="<?php the_permalink(); ?>"></a>
        <?php
        if ($morenews_post_thumbnail) {
          echo wp_kses_post($morenews_post_thumbnail);
        }
        ?>
        <div class="post-format-and-min-read-wrap">
          <?php morenews_post_format($morenews_post_id); ?>
          <?php morenews_count_content_words($morenews_post_id); ?>
        </div>

        <?php if ($morenews_grid_design == 'grid-design-default'): ?>
          <div class="category-min-read-wrap">
            <div class="read-categories categories-inside-image">
              <?php morenews_post_categories(); ?>
            </div>
          </div>
        <?php endif; ?>

      </div>

      <div class="pad read-details color-tp-pad">
        <?php if ($morenews_grid_design == 'grid-design-texts-over-image'): ?>
          <div class="read-categories categories-inside-image">
            <?php morenews_post_categories(); ?>
          </div>
        <?php endif; ?>

        <?php if ($morenews_title_position == 'bottom'): ?>
          <div class="read-title">
            <h3>
              <a href="<?php the_permalink(); ?>"><?php the_title(); ?></a>
            </h3>
          </div>
          <div class="post-item-metadata entry-meta author-links">
            <?php morenews_post_item_meta($morenews_post_display); ?>
            <?php morenews_get_comments_views_share($morenews_post_id); ?>
          </div>
        <?php endif; ?>

        <?php if ($morenews_show_excerpt == true): ?>
          <div class="post-description">
            <?php
            if ($archive_content_view == 'archive-content-full') {
              the_content();
            } else {
              echo wp_kses_post(morenews_get_the_excerpt($morenews_post_id));
            }
            ?>
          </div>
        <?php endif; ?>
      </div>
    </div>

<?php
  }
endif;
add_action('morenews_action_loop_grid', 'morenews_loop_grid', 10, 8);
