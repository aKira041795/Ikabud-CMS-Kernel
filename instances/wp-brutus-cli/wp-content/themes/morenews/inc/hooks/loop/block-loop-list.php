<?php
if (!function_exists('morenews_loop_list')) :
  /**
   * Post List Display
   *
   * @since Newsical 1.0.0
   */
  function morenews_loop_list($morenews_post_id, $morenews_thumbnail_size = 'thumbnail', $morenews_count = 0, $show_cat = false, $show_meta = true, $show_excerpt = false, $big_img = false, $archive_content_view = 'archive-content-excerpt')
  {
    $morenews_post_display = 'list-post';
    if ($big_img) {
      $morenews_post_display = 'spotlight-post';
    }

    // Get the post thumbnail and check if it exists
    $morenews_post_thumbnail = morenews_the_post_thumbnail($morenews_thumbnail_size, $morenews_post_id, true);
    $morenews_no_thumbnail_class = "has-post-image";
    if (!isset($morenews_post_thumbnail) || empty($morenews_post_thumbnail)) {
      $morenews_no_thumbnail_class = "no-post-image";
    }

?>
    <div class="af-double-column list-style clearfix aft-list-show-image <?php echo esc_attr($morenews_no_thumbnail_class); ?>">
      <div class="read-single color-pad">
        <div class="col-3 float-l pos-rel read-img read-bg-img">
          <a class="aft-post-image-link"
            href="<?php the_permalink(); ?>"><?php the_title(); ?></a>
          <?php
          // Display the post thumbnail if available
          if ($morenews_post_thumbnail) {
            echo wp_kses_post($morenews_post_thumbnail);
          }
          ?>
          <?php if (absint($morenews_count) > 0): ?>
            <span class="trending-no"><?php echo esc_html($morenews_count); ?></span>
          <?php endif; ?>
          <?php if ($big_img != false): ?>
            <div class="category-min-read-wrap af-cat-widget-carousel">
              <div class="post-format-and-min-read-wrap">
                <?php morenews_post_format($morenews_post_id); ?>
                <?php morenews_count_content_words($morenews_post_id); ?>
              </div>
              <div class="read-categories categories-inside-image">
                <?php morenews_post_categories(); ?>
              </div>
            </div>
          <?php endif; ?>
        </div>
        <div class="col-66 float-l pad read-details color-tp-pad">
          <?php if ($big_img == false): ?>
            <?php if ($show_cat != false): ?>
              <div class="read-categories">
                <?php morenews_post_categories(); ?>
              </div>
            <?php endif; ?>
          <?php endif; ?>

          <div class="read-title">
            <h3>
              <a href="<?php the_permalink(); ?>"><?php the_title(); ?></a>
            </h3>
          </div>
          <?php if ($show_meta != false): ?>
            <div class="post-item-metadata entry-meta author-links">
              <?php morenews_post_item_meta($morenews_post_display); ?>
              <?php //morenews_get_comments_views_share($morenews_post_id); 
              ?>
            </div>
          <?php endif; ?>

          <?php if ($show_excerpt != false):   ?>
            <div class="read-descprition full-item-discription">
              <div class="post-description">
                <?php
                if ($archive_content_view == 'archive-content-full') {
                  the_content();
                } else {
                  echo wp_kses_post(morenews_get_the_excerpt($morenews_post_id));
                }
                ?>
              </div>
            </div>
          <?php endif; ?>

        </div>
      </div>
    </div>

<?php
  }
endif;
add_action('morenews_action_loop_list', 'morenews_loop_list', 10, 8);
?>