<?php
    if (!class_exists('MoreNews_Featured_Post')) :
        /**
         * Adds MoreNews_Featured_Post widget.
         */
        class MoreNews_Featured_Post extends MoreNews_Widget_Base
        {
            /**
             * Sets up a new widget instance.
             *
             * @since 1.0.0
             */
            function __construct()
            {
                $this->text_fields = array(
                    'morenews-featured-posts-title',
                    'morenews-number-of-posts'
                
                );
                $this->select_fields = array(
                    
                    'morenews-select-category',
                
                );
                
                $widget_ops = array(
                    'classname' => 'morenews_featured_posts_widget',
                    'description' => __('Displays grid from selected categories.', 'morenews'),
                    'customize_selective_refresh' => false,
                );
                
                parent::__construct('morenews_featured_posts', __('AFTMN Post Grid', 'morenews'), $widget_ops);
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
    
                $morenews_featured_news_title = apply_filters('widget_title', $instance['morenews-featured-posts-title'], $instance, $this->id_base);
    

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
                <section class="aft-blocks af-main-banner-featured-posts pad-v">
                    <div class="af-main-banner-featured-posts featured-posts">
                        <?php if (!empty($morenews_featured_news_title)): ?>
                            <?php morenews_render_section_title($morenews_featured_news_title, $color_class); ?>
                        <?php endif; ?>
                        <div class="section-wrapper af-widget-body">
                            <div class="af-container-row clearfix">
                                <?php
                                    $morenews_featured_posts = morenews_get_posts(6, $morenews_category);
                                    if ($morenews_featured_posts->have_posts()) :
                                        while ($morenews_featured_posts->have_posts()) :
                                            $morenews_featured_posts->the_post();
                                            global $post;
                                            ?>
                                            <div class="col-4 pad float-l ">
                                                <?php do_action('morenews_action_loop_grid', $post->ID); ?>
                                            </div>
                                        <?php endwhile;
                                    endif;
                                    wp_reset_postdata();
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
                    echo parent::morenews_generate_text_input('morenews-featured-posts-title', __('Title', 'morenews'), 'Posts Grid');
                    echo parent::morenews_generate_select_options('morenews-select-category', __('Select Category', 'morenews'), $categories);

                }
                
                //print_pre($terms);
                
                
            }
            
        }
    endif;