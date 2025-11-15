<?php
if (!class_exists('MoreNews_Express_Posts_List')) :
    /**
     * Adds MoreNews_Express_Posts_List widget.
     */
    class MoreNews_Express_Posts_List extends MoreNews_Widget_Base
    {
        /**
         * Sets up a new widget instance.
         *
         * @since 1.0.0
         */
        function __construct()
        {
            $this->text_fields = array(
                'morenews-express-posts-section-title',
                'morenews-number-of-posts',

            );
            $this->select_fields = array(

                'morenews-select-category',

            );

            $widget_ops = array(
                'classname' => 'morenews_express_posts_list_widget',
                'description' => __('Displays Express Posts from selected categories.', 'morenews'),
                'customize_selective_refresh' => false,
            );

            parent::__construct('morenews_express_posts_list', __('AFTMN Express Posts List', 'morenews'), $widget_ops);
        }

        /**
         * Front-end display of widget.
         *
         * @param array $args Widget arguments.
         * @param array $instance Saved values from database.
         * @see WP_Widget::widget()
         *
         */

        public function widget($args, $instance)
        {

            $instance = parent::morenews_sanitize_data($instance, $instance);


            /** This filter is documented in wp-includes/default-widgets.php */

            $morenews_express_section_title = apply_filters('widget_title', $instance['morenews-express-posts-section-title'], $instance, $this->id_base);
            $morenews_express_section_title = $morenews_express_section_title ? $morenews_express_section_title : "Express Post";

            $morenews_category = !empty($instance['morenews-select-category']) ? $instance['morenews-select-category'] : '0';



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
            <section class="aft-blocks aft-featured-category-section af-list-post featured-cate-sec pad-v">
                <?php $morenews_featured_express_posts_one = morenews_get_posts(5, $morenews_category); ?>

                <div class="af-main-banner-categorized-posts express-posts layout-1">
                    <div class="section-wrapper clearfix">
                        <div class="small-grid-style clearfix">
                            <?php

                            if ($morenews_featured_express_posts_one->have_posts()) :
                                ?>
                                <?php if (!empty($morenews_express_section_title)): ?>
                                <?php morenews_render_section_title($morenews_express_section_title, $color_class); ?>
                            <?php endif; ?>
                                <div class="featured-post-items-wrap clearfix af-container-row af-widget-body">
                                    <?php
                                    $morenews_count = 1;
                                    while ($morenews_featured_express_posts_one->have_posts()) :
                                        $morenews_featured_express_posts_one->the_post();
                                        global $post;
                                        $morenews_first_section_class = '';
                                        if ($morenews_count == 1): ?>
                                            <div class="col-2 pad float-l af-sec-post <?php echo esc_html($morenews_first_section_class); ?>">
                                                <?php do_action('morenews_action_loop_grid', $post->ID, 'grid-design-default', 'morenews-medium', true); ?>
                                            </div>
                                        <?php else: ?>
                                            <div class="col-2 pad float-l list-part af-sec-post">
                                                <?php do_action('morenews_action_loop_list', $post->ID, 'thumbnail', 0, true, true, false); ?>
                                            </div>
                                        <?php endif; ?>
                                        <?php
                                        $morenews_count++;
                                    endwhile;
                                    wp_reset_postdata();
                                    ?>
                                </div>
                            <?php endif;
                            ?>
                        </div>

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
         * @param array $instance Previously saved values from database.
         * @see WP_Widget::form()
         *
         */
        public function form($instance)
        {
            $this->form_instance = $instance;


            //print_pre($terms);
            $categories = morenews_get_terms();


            if (isset($categories) && !empty($categories)) {
                // generate the text input for the title of the widget. Note that the first parameter matches text_fields array entry
                echo parent::morenews_generate_text_input('morenews-express-posts-section-title', __('Title', 'morenews'), 'Express Posts List');
                echo parent::morenews_generate_select_options('morenews-select-category', __('Select Category', 'morenews'), $categories);


            }

            //print_pre($terms);


        }

    }
endif;