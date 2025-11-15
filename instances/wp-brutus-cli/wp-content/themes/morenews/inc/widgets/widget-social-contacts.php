<?php
if (!class_exists('MoreNews_Social_Contacts')) :
    /**
     * Adds MoreNews_Social_Contacts widget.
     */
    class MoreNews_Social_Contacts extends MoreNews_Widget_Base
    {
        /**
         * Sets up a new widget instance.
         *
         * @since 1.0.0
         */
        function __construct()
        {
            $this->text_fields = array('morenews-social-contacts-title');
            $this->select_fields = array('morenews-select-background', 'morenews-select-background-type');

            $widget_ops = array(
                'classname' => 'morenews_social_contacts_widget aft-widget',
                'description' => __('Displays social contacts lists from selected settings.', 'morenews'),
                'customize_selective_refresh' => false,
            );

            parent::__construct('morenews_social_contacts', __('AFTMN Social Contacts', 'morenews'), $widget_ops);
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
            $morenews_section_title = apply_filters('widget_title', $instance['morenews-social-contacts-title'], $instance, $this->id_base);
            $morenews_section_title = isset($morenews_section_title) ? $morenews_section_title : __('Connect with Us', 'morenews');
            
            
            // open the widget container
            echo $args['before_widget'];
            ?>
                <div  class="widget-social-contancts-area af-social-contacts pad-v">


                    <?php
                        if (!empty($morenews_section_title)) { ?>
                            <?php morenews_render_section_title($morenews_section_title); ?>
                        <?php }
                    ?>
                    <div class="social-widget-menu af-widget-body">
                        <?php
                            if (has_nav_menu('aft-social-nav')) {
                                wp_nav_menu(array(
                                    'theme_location' => 'aft-social-nav',
                                    'link_before' => '<span class="screen-reader-text">',
                                    'link_after' => '</span>',
                                ));
                            } ?>
                    </div>
                    <?php if (!has_nav_menu('aft-social-nav')) : ?>
                        <p>
                            <?php esc_html_e('Social menu is not set. You need to create menu and assign it to Social Menu on Menu Settings.', 'morenews'); ?>
                        </p>
                    <?php endif; ?>

                </div>

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

            // generate the text input for the title of the widget. Note that the first parameter matches text_fields array entry
            echo parent::morenews_generate_text_input('morenews-social-contacts-title', 'Title', 'Connect with Us');

        }


    }
endif;