<?php

if (!class_exists('MoreNews_Trending_Posts')) :
  /**
   * Adds MoreNews_Prime_News widget.
   */
  class MoreNews_Trending_Posts extends MoreNews_Widget_Base
  {
    /**
     * Sets up a new widget instance.
     *
     * @since 1.0.0
     */
    function __construct()
    {
      $this->text_fields = array(
        'morenews-trending-news-title',
        'morenews-number-of-posts',

      );
      $this->select_fields = array(

        'morenews-news_filter-by',
        'morenews-select-category',

      );

      $widget_ops = array(
        'classname' => 'morenews_trending_news_widget',
        'description' => __('Displays grid from selected categories.', 'morenews'),
        'customize_selective_refresh' => false,
      );

      parent::__construct('morenews_trending_news', __('AFTMN Trending News', 'morenews'), $widget_ops);
    }

    /**
     * Front-end display of widget.
     *
     * @see WP_Widget::widget()
     *
     * @param array $args Widget arguments.
     * @param array $instance Saved values from database.
     */

    public function widget($args, $instance)
    {

      // wp_enqueue_style('slick');
      // wp_enqueue_script('slick');
      $instance = parent::morenews_sanitize_data($instance, $instance);

      $morenews_trending_news_section_title = apply_filters('widget_title', $instance['morenews-trending-news-title'], $instance, $this->id_base);
      $widget_no_title_class = empty($morenews_trending_news_section_title) ? 'aft-widgets-no-title' : '';
      $morenews_no_of_post = 5;
      $morenews_category = !empty($instance['morenews-select-category']) ? $instance['morenews-select-category'] : '0';

      $color_class = '';
      if (absint($morenews_category) > 0) {
        $color_id = "category_color_" . $morenews_category;
        // retrieve the existing value(s) for this meta field. This returns an array
        $term_meta = get_option($color_id);
        $color_class = ($term_meta) ? $term_meta['color_class_term_meta'] : 'category-color-1';
      }

      // open the widget container
      echo $args['before_widget']; ?>
      <div class="full-wid-resp pad-v <?php echo esc_attr($widget_no_title_class) ?>">
        <?php

        if (!empty($morenews_trending_news_section_title)) { ?>
          <?php morenews_render_section_title($morenews_trending_news_section_title, $color_class); ?>
        <?php }
        ?>
        <div class="slick-wrapper af-trending-widget-carousel af-post-carousel-list banner-vertical-slider af-widget-carousel af-widget-body">

          <?php

          $morenews_filterby = 'cat';
          $morenews_number_of_posts = 1;
          if ($morenews_no_of_post) {
            $morenews_number_of_posts = $morenews_no_of_post;
          }


          $morenews_featured_posts = morenews_get_posts($morenews_number_of_posts, $morenews_category, $morenews_filterby);
          if ($morenews_featured_posts->have_posts()) :
            $morenews_count = 1;
            while ($morenews_featured_posts->have_posts()) :
              $morenews_featured_posts->the_post();
              global $post;

          ?>
              <div class="slick-item pad">
                <div class="aft-trending-posts list-part af-sec-post">
                  <?php do_action('morenews_action_loop_list', $post->ID, 'thumbnail', $morenews_count, true, true, false); ?>
                </div>
              </div>
            <?php
              $morenews_count++;
            endwhile;
            wp_reset_postdata();
            ?>
          <?php endif; ?>

        </div>
        <div class="af-widget-trending-carousel-navcontrols af-slick-navcontrols"></div>
      </div>
<?php echo $args['after_widget'];
    }

    /**
     * Back-end widget form.
     *
     * @see WP_Widget::form()
     *
     * @param array $instance Previously saved values from database.
     */
    public function form($instance)
    {
      $this->form_instance = $instance;


      $trending_news_layout = array(
        'layout-1' => "Layout 1",
        'layout-2' => "Layout 1"
      );
      $trending_news_filterby = array(
        'cat' => "Category",
        'tag' => "Tag"
      );
      $featured_image = array(
        'yes' => 'Yes',
        'no' => 'No'
      );
      $categories = morenews_get_terms();

      echo parent::morenews_generate_text_input('morenews-trending-news-title', __('Title', 'morenews'), 'Trending News');
      echo parent::morenews_generate_select_options('morenews-select-category', __('Select Category', 'morenews'), $categories);
    }
  }

endif;
