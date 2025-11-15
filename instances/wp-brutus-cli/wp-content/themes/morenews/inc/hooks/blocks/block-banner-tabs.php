<div class="af-main-banner-tabbed-posts aft-posts-tabs-panel">
  <div class="section-wrapper">
    <div class="small-grid-style clearfix">
      <?php
      // wp_enqueue_style('bootstrap');
      // wp_enqueue_script('bootstrap');
      $dir = 'ltr';
      if (is_rtl()) {
        $dir = 'rtl';
      }
      $tab_id = 'aft-main-banner-latest-trending-popular';

      $latest_title = morenews_get_option('main_latest_news_section_title');
      $latest_post_filterby = morenews_get_option('select_banner_latest_post_filterby');
      $latest_category = 0;
      if ($latest_post_filterby == 'tag') {
        $latest_category = morenews_get_option('select_latest_post_tag');
      } elseif ($latest_post_filterby == 'cat') {
        $latest_category = morenews_get_option('select_banner_latest_post_category');
      }

      $popular_title = morenews_get_option('main_popular_news_section_title');
      $popular_post_filterby = morenews_get_option('select_popular_post_filterby');
      $popular_category = 0;
      if ($popular_post_filterby == 'tag') {
        $popular_category = morenews_get_option('select_popular_post_tag');
      } elseif ($popular_post_filterby == 'cat') {
        $popular_category = morenews_get_option('select_popular_post_category');
      }

      $update_title = morenews_get_option('main_update_news_section_title');
      $update_post_filterby = morenews_get_option('select_update_post_filterby');
      $update_category = 0;
      if ($update_post_filterby == 'tag') {
        $update_category = morenews_get_option('select_update_post_tag');
      } elseif ($update_post_filterby == 'cat') {
        $update_category = morenews_get_option('select_update_post_category');
      }

      $number_of_posts = 4;

      $latest_color_class = '';
      if (absint($latest_category) > 0) {
        $color_id = "category_color_" . $latest_category;
        // retrieve the existing value(s) for this meta field. This returns an array
        $term_meta = get_option($color_id);
        $latest_color_class = ($term_meta) ? $term_meta['color_class_term_meta'] : 'category-color-1';
      }

      $popular_color_class = '';
      if (absint($popular_category) > 0) {
        $color_id = "category_color_" . $popular_category;
        // retrieve the existing value(s) for this meta field. This returns an array
        $term_meta = get_option($color_id);
        $popular_color_class = ($term_meta) ? $term_meta['color_class_term_meta'] : 'category-color-1';
      }

      $update_color_class = '';
      if (absint($update_category) > 0) {
        $color_id = "category_color_" . $update_category;
        // retrieve the existing value(s) for this meta field. This returns an array
        $term_meta = get_option($color_id);
        $update_color_class = ($term_meta) ? $term_meta['color_class_term_meta'] : 'category-color-1';
      }

      morenews_render_tabbed_posts($tab_id, $latest_title, $latest_post_filterby, $latest_category, $latest_color_class, $popular_title, $popular_post_filterby, $popular_category, $popular_color_class, $update_title, $update_post_filterby, $update_category, $update_color_class, $number_of_posts);
      ?>
    </div>
  </div>
</div>
<!-- Editors Pick line END -->