<?php
if (!class_exists('MoreNews_Express_Posts_Double_Column')) :
    /**
     * Adds MoreNews_Express_Posts_Double_Column widget.
     */
    class MoreNews_Express_Posts_Double_Column extends MoreNews_Widget_Base
    {
        /**
         * Sets up a new widget instance.
         *
         * @since 1.0.0
         */
        function __construct()
        {
            $this->text_fields = array(
                'morenews-posts-list-title-1',
                'morenews-posts-list-title-2',
                'morenews-posts-slider-number'

            );
            $this->select_fields = array(

                'morenews-select-category-1',
                'morenews-select-category-2',

            );

            $widget_ops = array(
                'classname' => 'morenews_posts_double_columns_widget',
                'description' => __('Displays grid from selected categories.', 'morenews'),
                'customize_selective_refresh' => false,
            );

            parent::__construct('morenews_posts_double_column', __('AFTMN Post Double Columns', 'morenews'), $widget_ops);
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




            for ($morenews_i = 1; $morenews_i <= 2; $morenews_i++) {
                $morenews_section_title = apply_filters('widget_title', $instance['morenews-posts-list-title-' . $morenews_i], $instance, $this->id_base);
                $morenews_category = !empty($instance['morenews-select-category-' . $morenews_i]) ? $instance['morenews-select-category-' . $morenews_i] : '0';
                $morenews_featured_categories['feature_' . $morenews_i][] = $morenews_category;
                $morenews_featured_categories['feature_' . $morenews_i][] = $morenews_section_title;



                $color_class = '';
                if(absint($morenews_category) > 0){
                    $color_id = "category_color_" . $morenews_category;
                    // retrieve the existing value(s) for this meta field. This returns an array
                    $term_meta = get_option($color_id);
                    $color_class = ($term_meta) ? $term_meta['color_class_term_meta'] : 'category-color-1';
                }
                $morenews_featured_categories['feature_' . $morenews_i][] = $color_class;

            }

            // open the widget container
            echo $args['before_widget'];
            if (isset($morenews_featured_categories)): ?>

                <div class="af-container-row pad-v clearfix">
                    <?php
                    foreach ($morenews_featured_categories as $morenews_fc): ?>
                        <div class="col-2 pad float-l af-sec-post">
                            <?php if (!empty($morenews_fc[1])): ?>
                                <?php morenews_render_section_title($morenews_fc[1], $morenews_fc[2]); ?>
                            <?php endif; ?>

                            <?php $morenews_all_posts_vertical = morenews_get_posts(3, $morenews_fc[0]); ?>
                            <div class="full-wid-resp af-widget-body">
                                <?php
                                if ($morenews_all_posts_vertical->have_posts()) :
                                    $morenews_count = 1;
                                    while ($morenews_all_posts_vertical->have_posts()) : $morenews_all_posts_vertical->the_post();
                                        global $post;
                                        if ($morenews_count == 1):
                                            ?>
                                            <div class="af-sec-post">
                                                <?php do_action('morenews_action_loop_grid', $post->ID, 'grid-design-default', 'morenews-medium'); ?>
                                            </div>
                                        <?php else: ?>
                                            <?php do_action('morenews_action_loop_list', $post->ID, 'thumbnail', 0, true, true, false); ?>
                                        <?php
                                        endif;
                                        $morenews_count++;
                                    endwhile;
                                endif;
                                wp_reset_postdata();
                                ?>
                            </div>
                        </div><!--featured-category-item-->
                    <?php

                    endforeach; ?>

                </div>
            <?php
            endif;

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
                echo parent::morenews_generate_text_input('morenews-posts-list-title-1', __('Title', 'morenews'), 'Post Double Columns 1');
                echo parent::morenews_generate_text_input('morenews-posts-list-title-2', __('Title', 'morenews'), 'Post Double Columns 2');
                echo parent::morenews_generate_select_options('morenews-select-category-1', __('Select Category 1', 'morenews'), $categories);
                echo parent::morenews_generate_select_options('morenews-select-category-2', __('Select Category 2', 'morenews'), $categories);
                     }

            //print_pre($terms);


        }

    }
endif;