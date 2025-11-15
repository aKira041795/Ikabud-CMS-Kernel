<?php


/**
 * Returns all categories.
 *
 * @since MoreNews 1.0.0
 */
if (!function_exists('morenews_get_terms')):
  function morenews_get_terms($category_id = 0, $taxonomy = 'category', $default = '')
  {
    $taxonomy = !empty($taxonomy) ? $taxonomy : 'category';

    if ($category_id > 0) {
      $term = get_term_by('id', absint($category_id), $taxonomy);
      if ($term)
        return esc_html($term->name);
    } else {
      $terms = get_terms(array(
        'taxonomy' => $taxonomy,
        'orderby' => 'name',
        'order' => 'ASC',
        'hide_empty' => true,
      ));
      $first_item = __('Select', 'morenews');


      if (isset($terms) && !empty($terms)) {
        foreach ($terms as $term) {
          if ($default != 'first') {
            $array['0'] = $first_item;
          }
          $array[$term->term_id] = esc_html($term->name);
        }

        return $array;
      }
    }
  }
endif;

/**
 * Outputs the tab posts
 *
 * @param array $args Post Arguments.
 * @since 1.0.0
 *
 */
if (!function_exists('morenews_render_posts')):
  function morenews_render_posts($morenews_number_of_posts, $morenews_category, $morenews_filterby)
  {
    $morenews_main_banner_layout_section = morenews_get_option('select_main_banner_layout_section');
    $show_cat = false;
    if ($morenews_main_banner_layout_section == 'layout-5') {
      $show_cat = true;
    }
    $all_posts = morenews_get_posts($morenews_number_of_posts, $morenews_category, $morenews_filterby);
    if ($all_posts->have_posts()):
      echo '<ul class="article-item article-list-item article-tabbed-list article-item-left">';
      while ($all_posts->have_posts()): $all_posts->the_post();
        global $post;
?>
        <li class="aft-trending-posts list-part af-sec-post">
          
            <?php do_action('morenews_action_loop_list', $post->ID, 'thumbnail', 0, $show_cat, true, false); ?>
          
        </li>
    <?php
      endwhile;
      wp_reset_postdata();
      echo '</ul>';
    endif;
  }
endif;

if (!function_exists('morenews_render_tabbed_posts')):
  function morenews_render_tabbed_posts($tab_id, $latest_title, $latest_post_filterby, $latest_category, $latest_color_class, $popular_title, $popular_post_filterby, $popular_category, $popular_color_class, $update_title, $update_post_filterby, $update_category, $update_color_class, $number_of_posts)
  {
    $is_recent_active = true;
    ?>
    <div class="tabbed-container three-column-tabs">
      <div class="tabbed-head">
        <ul class="nav nav-tabs af-tabs tab-warpper" role="tablist">
          <li class="tab tab-recent <?php echo esc_attr($latest_color_class); ?>" role="presentation">
            <a href="#<?php echo esc_attr($tab_id); ?>-recent"
              aria-label="<?php esc_attr_e('Recent', 'morenews'); ?>"
              role="tab"
              id="<?php echo esc_attr($tab_id); ?>-recent-tab"
              aria-controls="<?php echo esc_attr($tab_id); ?>-recent"
              aria-selected="<?php echo $is_recent_active ? 'true' : 'false'; ?>"
              data-toggle="tab"
              class="font-family-1 <?php echo $is_recent_active ? 'active' : ''; ?>">
              <span><i class="fas fa-clock"></i> <?php echo esc_html($latest_title); ?></span>
            </a>
          </li>

          <li class="tab tab-popular <?php echo esc_attr($popular_color_class); ?>" role="presentation">
            <a href="#<?php echo esc_attr($tab_id); ?>-popular"
              aria-label="<?php esc_attr_e('Popular', 'morenews'); ?>"
              role="tab"
              id="<?php echo esc_attr($tab_id); ?>-popular-tab"
              aria-controls="<?php echo esc_attr($tab_id); ?>-popular"
              aria-selected="<?php echo $is_recent_active ? 'false' : 'true'; ?>"
              data-toggle="tab"
              class="font-family-1 <?php echo $is_recent_active ? '' : 'active'; ?>">
              <span><i class="fas fa-bolt"></i> <?php echo esc_html($popular_title); ?></span>
            </a>
          </li>
          <li class="tab tab-update <?php echo esc_attr($update_color_class); ?>" role="presentation">
            <a href="#<?php echo esc_attr($tab_id); ?>-update"
              aria-label="<?php esc_attr_e('Update', 'morenews'); ?>"
              role="tab"
              id="<?php echo esc_attr($tab_id); ?>-update-tab"
              aria-controls="<?php echo esc_attr($tab_id); ?>-update"
              aria-selected="<?php echo $is_recent_active ? 'false' : 'true'; ?>"
              data-toggle="tab"
              class="font-family-1 <?php echo $is_recent_active ? '' : 'active'; ?>">
              <span><i class="fas fa-fire"></i> <?php echo esc_html($update_title); ?></span>
            </a>
          </li>
        </ul>
      </div>

      <div class="tab-content af-widget-body">
        <div id="<?php echo esc_attr($tab_id); ?>-recent"
          role="tabpanel"
          aria-labelledby="<?php echo esc_attr($tab_id); ?>-recent-tab"
          aria-hidden="<?php echo $is_recent_active ? 'false' : 'true'; ?>"
          class="tab-pane <?php echo $is_recent_active ? 'active' : ''; ?>">
          <?php morenews_render_posts($number_of_posts, $latest_category, $latest_post_filterby); ?>
        </div>

        <div id="<?php echo esc_attr($tab_id); ?>-popular"
          role="tabpanel"
          aria-labelledby="<?php echo esc_attr($tab_id); ?>-popular-tab"
          aria-hidden="<?php echo $is_recent_active ? 'true' : 'false'; ?>"
          class="tab-pane <?php echo $is_recent_active ? '' : 'active'; ?>">
          <?php morenews_render_posts($number_of_posts, $popular_category, $popular_post_filterby); ?>
        </div>

        <div id="<?php echo esc_attr($tab_id); ?>-update"
          role="tabpanel"
          aria-labelledby="<?php echo esc_attr($tab_id); ?>-update-tab"
          aria-hidden="<?php echo $is_recent_active ? 'true' : 'false'; ?>"
          class="tab-pane <?php echo $is_recent_active ? '' : 'active'; ?>">
          <?php morenews_render_posts($number_of_posts, $update_category, $update_post_filterby); ?>
        </div>
      </div>
    </div>


  <?php }
endif;

if (!function_exists('morenews_render_section_title')):
  function morenews_render_section_title($section_title, $color_class = '')
  { ?>

    <div class="af-title-subtitle-wrap">
      <h2 class="widget-title header-after1 <?php echo esc_attr($color_class); ?>">
        <span class="heading-line-before"></span>
        <span class="heading-line"><?php echo esc_html($section_title); ?></span>
        <span class="heading-line-after"></span>
      </h2>
    </div>
<?php
  }
endif;
