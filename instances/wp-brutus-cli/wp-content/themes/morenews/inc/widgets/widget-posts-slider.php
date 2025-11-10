<?php
if (!class_exists('MoreNews_Posts_Slider')) :
    /**
     * Adds MoreNews_Posts_Slider widget.
     */
    class MoreNews_Posts_Slider extends MoreNews_Widget_Base
    {
        /**
         * Sets up a new widget instance.
         *
         * @since 1.0.0
         */
        function __construct()
        {
            $this->text_fields = array('morenews-posts-slider-title','morenews-number-of-posts');
            $this->select_fields = array('morenews-select-category');

            $widget_ops = array(
                'classname' => 'morenews_posts_slider_widget aft-widget',
                'description' => __('Displays posts slider from selected category.', 'morenews'),
                'customize_selective_refresh' => false,
            );

            parent::__construct('morenews_posts_slider', __('AFTMN Posts Slider', 'morenews'), $widget_ops);
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
            $instance = parent::morenews_sanitize_data($instance, $instance);


            /** This filter is documented in wp-includes/default-widgets.php */
            $morenews_posts_slider_title = apply_filters('widget_title', $instance['morenews-posts-slider-title'], $instance, $this->id_base);
            $widget_no_title_class = empty($morenews_posts_slider_title) ? 'aft-widgets-no-title' : '';
            $morenews_category = isset($instance['morenews-select-category']) ? $instance['morenews-select-category'] : 0;


            $color_class = '';
            if(absint($morenews_category) > 0){
                $color_id = "category_color_" . $morenews_category;
                // retrieve the existing value(s) for this meta field. This returns an array
                $term_meta = get_option($color_id);
                $color_class = ($term_meta) ? $term_meta['color_class_term_meta'] : 'category-color-1';
            }
          
            // open the widget container
            echo $args['before_widget'];
            ?>
            <?php
            
            ?>
            <section class="aft-blocks pad-v <?php echo esc_attr($widget_no_title_class)?>">
                <div class="af-slider-wrap">
    
                    <?php if (!empty($morenews_posts_slider_title)): ?>
                        <?php morenews_render_section_title($morenews_posts_slider_title, $color_class); ?>
                    <?php endif; ?>
                    <div class="widget-block widget-wrapper af-widget-body">
                        <div class="af-posts-slider af-widget-post-slider posts-slider banner-slider-2  af-posts-slider af-widget-carousel af-cat-widget-carousel slick-wrapper">
                            <?php
                                $morenews_slider_posts = morenews_get_posts(5, $morenews_category);
                                if ($morenews_slider_posts->have_posts()) :
                                    while ($morenews_slider_posts->have_posts()) : $morenews_slider_posts->the_post();
            
                                        global $post;

                                        ?>
                                        <div class="slick-item">
                                            <?php do_action('morenews_action_loop_grid', $post->ID, 'grid-design-texts-over-image', 'morenews-large'); ?>
                                        </div>
                                    <?php
                                    endwhile;
                                endif;
                                wp_reset_postdata();
                            ?>
                        </div>
                        <div class="af-widget-post-slider-navcontrols af-slick-navcontrols"></div>
                    </div>
                </div>
            </section>

            <?php
            // close the widget container
            echo $args['after_widget'];
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
            

            $categories = morenews_get_terms();
            if (isset($categories) && !empty($categories)) {
                // generate the text input for the title of the widget. Note that the first parameter matches text_fields array entry
                echo parent::morenews_generate_text_input('morenews-posts-slider-title', __('Title', 'morenews'), 'Posts Slider');

                echo parent::morenews_generate_select_options('morenews-select-category', __('Select category', 'morenews'), $categories);

            }
        }
    }
endif;