<?php

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

if (!function_exists('awpa_get_author_role')) {

    /**
     * @param $author_id
     * @return mixed
     */
    function awpa_get_author_role($author_id)
    {
        $user = new WP_User($author_id);
        return array_shift($user->roles);
    }
}


if (!function_exists('awpa_get_author_contact_info')) {
    /**
     * @param $author_id
     * @return array
     */
    function awpa_get_author_contact_info($author_id)
    {
        $author_facebook = get_the_author_meta('awpa_contact_facebook', $author_id);
        $author_twitter = get_the_author_meta('awpa_contact_twitter', $author_id);
        $author_linkedin = get_the_author_meta('awpa_contact_linkedin', $author_id);
        $author_instagram = get_the_author_meta('awpa_contact_instagram', $author_id);
        $author_youtube = get_the_author_meta('awpa_contact_youtube', $author_id);
        $author_email = get_the_author_meta('user_email', $author_id);
        $author_website = get_the_author_meta('user_url', $author_id);

        $contact_info = array();

        if (!empty($author_facebook)) {
            $contact_info['facebook'] = esc_url($author_facebook);
        }

        if (!empty($author_twitter)) {
            $contact_info['twitter'] = esc_url($author_twitter);
        }

        if (!empty($author_linkedin)) {
            $contact_info['linkedin'] = esc_url($author_linkedin);
        }

        if (!empty($author_instagram)) {
            $contact_info['instagram'] = esc_url($author_instagram);
        }

        if (!empty($author_youtube)) {
            $contact_info['youtube'] = esc_url($author_youtube);
        }

        if (!empty($author_website)) {
            $contact_info['website'] = esc_url($author_website);
        }


        if (!empty($author_email)) {
            $contact_info['email'] = esc_attr($author_email);
        }

        return $contact_info;
    }
}


if (!function_exists('awpa_get_author_block')) {
    /**
     * @param $author_id
     * @return array
     */
    function awpa_get_author_block($author_id, $image_layout = 'square', $show_role = false, $show_email = false, $author_posts_link = 'square', $icon_shape = 'round', $multi_author = false)
    {
        global $post;
        if (empty($author_id)) {
            //global $post;
            $author_id = get_post_field('post_author', $post->ID);
        }

        $author_name = get_the_author_meta('display_name', $author_id);
        $author_website = get_the_author_meta('user_url', $author_id);

        $author_role = '';
        if (isset($show_role) && $show_role == true) {
            $author_role = awpa_get_author_role($author_id);
            $author_role = esc_attr($author_role);
        }

        $author_email = '';
        if (isset($show_email) && $show_email == true) {
            $author_email = get_the_author_meta('user_email', $author_id);
            $author_email = sanitize_email($author_email);
        }


        $contact_info = awpa_get_author_contact_info($author_id);
        $author_posts_url = get_author_posts_url($author_id);
        $author_avatar = get_avatar($author_id, 150);
        $author_desc = get_the_author_meta('description', $author_id);
?>
        <div class="wp-post-author">
            <div class="awpa-img awpa-author-block <?php echo esc_attr($image_layout); ?>">
                <a href="<?php echo get_author_posts_url($author_id); ?>"><?php echo $author_avatar; ?></a>
               
               
            </div>
            <div class="wp-post-author-meta awpa-author-block">
                <h4 class="awpa-display-name">
                    <a href="<?php echo esc_url($author_posts_url); ?>"><?php echo esc_attr($author_name); ?></a>
                    
                </h4>
                

                <?php if (!empty($author_role)) : ?>
                    <p class="awpa-role"><?php echo esc_html($author_role); ?></p>
                <?php endif; ?>

                <div class="wp-post-author-meta-bio">
                    <?php
                    $author_desc = wptexturize($author_desc);
                    $author_desc = wpautop($author_desc);
                    echo wp_kses_post($author_desc);
                    ?>
                </div>
                <div class="wp-post-author-meta-more-posts">
                    <p class="awpa-more-posts <?php echo esc_attr($author_posts_link); ?>">
                        <a href="<?php echo esc_url(get_author_posts_url($author_id)); ?>" class="awpa-more-posts"><?php esc_html_e("See author's posts", 'wp-post-author'); ?></a>
                    </p>
                </div>
                <?php if (!empty($contact_info)) : ?>
                    <ul class="awpa-contact-info <?php echo esc_attr($icon_shape); ?>">
                        <?php foreach ($contact_info as $key => $value) : ?>
                            <?php if ($key == 'email') : ?>
                                <?php if (isset($show_email) && $show_email == true) : ?>
                                    <li class="awpa-<?php echo esc_attr($key); ?>-li">
                                        <a href="mailto:<?php echo wp_kses_post($value); ?>" class="awpa-<?php echo esc_attr($key); ?> awpa-icon-<?php echo esc_attr($key); ?>"></a>
                                    </li>
                                <?php endif; ?>
                            <?php else : ?>

                                <li class="awpa-<?php echo esc_attr($key); ?>-li">
                                    <a href="<?php echo wp_kses_post($value); ?>" class="awpa-<?php echo esc_attr($key); ?> awpa-icon-<?php echo esc_attr($key); ?>"></a>
                                </li>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>

        <?php


    }
}

/**
 * Guest Author Details
 */
if (!function_exists('awpa_get_guest_author_block')) {
    /**
     * @param $author_id
     * `@return array
     */
    function awpa_get_guest_author_block($author_id, $image_layout = 'square', $show_role = false, $show_email = true, $author_posts_link = 'square', $icon_shape = 'round',$multi_author=false)
    {

        
       
        $wp_amulti_authors = new WPAMultiAuthors();
        $guest_user_data = $wp_amulti_authors->get_guest_by_id($author_id);

        $author_name = $guest_user_data->display_name;
        $author_website = $guest_user_data->website;

        $author_email = '';
        if (isset($show_email) && $show_email == true) {
            $author_email = $guest_user_data->user_email;
            $author_email = sanitize_email($author_email);
        }

        $author_posts_url = get_author_posts_url($author_id, $guest_user_data->user_nicename);
        $author_avatar =  content_url() . '/uploads/wpa-post-author/guest-avatar/' . $guest_user_data->avatar_name;
        $author_desc = $guest_user_data->description;
        $author_avatar = get_avatar($guest_user_data->user_email, 150);
        $is_active = $guest_user_data->is_active;
        if ($is_active == 1) {
        ?>

            <div class="wp-post-author">

                <?php if ($author_name) : ?>
                    <div class="awpa-img awpa-author-block <?php echo esc_attr($image_layout); ?>">
                        <?php echo wp_kses_post($author_avatar); ?>
                    </div>
                <?php endif; ?>
                <div class="wp-post-author-meta awpa-author-block">
                    <h4 class="awpa-display-name">
                        <?php echo esc_html($author_name); ?>
                    </h4>

                   

                    <div class="wp-post-author-meta-bio">
                        <?php
                        $author_desc = wptexturize($author_desc);
                        $author_desc = wpautop($author_desc);
                        echo wp_kses_post($author_desc);
                        ?>
                    </div>

                    <ul class="awpa-contact-info <?php echo esc_attr($icon_shape); ?>">

                        <?php if ($show_email == true) :  ?>
                            <li class="awpa-email-li">
                                <a href="mailto:<?php echo esc_url($author_email); ?>" class="awpa-email awpa-icon-email"></a>
                            </li>
                        <?php endif; ?>


                        <?php
                           // var_dump($guest_user_data->user_meta);
                        if (!empty($guest_user_data->user_meta)) {
                            $social_links = json_decode($guest_user_data->user_meta);
                           
                            foreach ($social_links as $key => $value) {
                                if (!empty($value) && $key !='posts') { ?>
                                    <li class="awpa-<?php echo esc_attr($key); ?>-li">
                                        <a href="<?php echo esc_url($value); ?>" target="_blank" class="awpa-<?php echo esc_attr($key); ?> awpa-icon-<?php echo esc_attr($key); ?>"></a>
                                    </li>
                        <?php }
                            }
                        }
                        ?>
                    </ul>

                </div>
            </div>

        <?php
        }
    }
}


if (!function_exists('awpa_get_author_block_custom')) {
    /**
     * @param $author_id
     * @return array
     */
    function awpa_get_author_block_custom($instance)
    {

        $image_layout = isset($instance['awpa-post-author-image-layout']) ? $instance['awpa-post-author-image-layout'] : 'square';
        $social_icon = isset($instance['awpa-post-author-social-icon-layout']) ? $instance['awpa-post-author-social-icon-layout'] : 'round';
        $author_name = isset($instance['awpa-post-author-name']) ? $instance['awpa-post-author-name'] : '';
        $author_role = isset($instance['awpa-post-author-role']) ? $instance['awpa-post-author-role'] : '';
        $author_email = isset($instance['awpa-post-author-email']) ? $instance['awpa-post-author-email'] : '';
        $author_website = isset($instance['awpa-post-author-website']) ? $instance['awpa-post-author-website'] : '';
        $author_desc = isset($instance['awpa-post-author-desc']) ? $instance['awpa-post-author-desc'] : '';
        $author_facebook = isset($instance['awpa-post-author-facebook']) ? $instance['awpa-post-author-facebook'] : '';
        $author_twitter = isset($instance['awpa-post-author-twitter']) ? $instance['awpa-post-author-twitter'] : '';
        $author_instagram = isset($instance['awpa-post-author-instagram']) ? $instance['awpa-post-author-instagram'] : '';
        $author_youtube = isset($instance['awpa-post-author-youtube']) ? $instance['awpa-post-author-youtube'] : '';
        $author_linkedin = isset($instance['awpa-post-author-linkedin']) ? $instance['awpa-post-author-linkedin'] : '';

        $image_id = isset($instance['awpa-post-author-image']) ? $instance['awpa-post-author-image'] : '';
        $image_src = '';
        if (!empty($image_id)) {
            $image_attributes = wp_get_attachment_image_src($image_id, 'large');
            $image_src = $image_attributes[0];
        }


        $contact_info = array();

        if (!empty($author_facebook)) {
            $contact_info['facebook'] = esc_url($author_facebook);
        }

        if (!empty($author_twitter)) {
            $contact_info['twitter'] = esc_url($author_twitter);
        }

        if (!empty($author_linkedin)) {
            $contact_info['linkedin'] = esc_url($author_linkedin);
        }

        if (!empty($author_instagram)) {
            $contact_info['instagram'] = esc_url($author_instagram);
        }

        if (!empty($author_youtube)) {
            $contact_info['youtube'] = esc_url($author_youtube);
        }

        if (!empty($author_website)) {
            $contact_info['website'] = esc_url($author_website);
        }

        if (!empty($author_email)) {
            $contact_info['email'] = esc_attr($author_email);
        }

        ?>

        <div class="wp-post-author">

            <?php if (!empty($image_src)) : ?>

                <figure class="awpa-img awpa-author-block awpa-bg-image awpa-data-bg <?php echo esc_attr($image_layout); ?>">
                    <img src="<?php echo esc_attr($image_src); ?>" alt="<?php echo esc_attr($author_name); ?>" />
                </figure>

            <?php endif; ?>

            <div class="wp-post-author-meta awpa-author-block">
                <h4 class="awpa-display-name">
                    <?php echo esc_attr($author_name); ?>
                </h4>

                <?php if (!empty($author_role)) : ?>
                    <p class="awpa-role"><?php echo esc_html($author_role); ?></p>
                <?php endif; ?>


                <div class="wp-post-author-meta-bio">
                    <?php
                    $author_desc = wptexturize($author_desc);
                    $author_desc = wpautop($author_desc);
                    echo wp_kses_post($author_desc);
                    ?>
                </div>
                <?php if (!empty($contact_info)) : ?>
                    <ul class="awpa-contact-info <?php echo esc_attr($social_icon); ?>">
                        <?php foreach ($contact_info as $key => $value) : ?>
                            <?php if ($key == 'email') : ?>
                                <li class="awpa-<?php echo esc_attr($key); ?>-li">
                                    <a href="mailto:<?php echo wp_kses_post($value); ?>" class="awpa-<?php echo esc_attr($key); ?> awpa-icon-<?php echo esc_attr($key); ?>"></a>
                                </li>
                            <?php else : ?>

                                <li class="awpa-<?php echo esc_attr($key); ?>-li">
                                    <a href="<?php echo wp_kses_post($value); ?>" class="awpa-<?php echo esc_attr($key); ?> awpa-icon-<?php echo esc_attr($key); ?>"></a>
                                </li>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>

    <?php


    }
}


add_filter('the_content', 'awpa_add_author');
if (!function_exists('awpa_add_author')) {
    function awpa_add_author($content)
    {

        if (is_single() && in_the_loop() && is_main_query()) {
            $options = get_option('awpa_setting_options');

            if (!isset($options['awpa_hide_from_post_content']) || empty($options['awpa_hide_from_post_content'])) {

                $title = (isset($options['awpa_global_title'])) ? $options['awpa_global_title'] : '';
                $align = (isset($options['awpa_global_align'])) ? $options['awpa_global_align'] : '';
                $image_layout = (isset($options['awpa_global_image_layout'])) ? $options['awpa_global_image_layout'] : '';
                $show_role = (isset($options['awpa_global_show_role'])) ? $options['awpa_global_show_role'] : '';
                $show_email = (isset($options['awpa_global_show_email'])) ? $options['awpa_global_show_email'] : '';
                $author_posts_link = isset($options['awpa_author_posts_link_layout']) ? $options['awpa_author_posts_link_layout'] : '';
                $icon_shape = isset($options['awpa_social_icon_layout']) ? $options['awpa_social_icon_layout'] : '';

                $post_type = get_post_type();
                $awpa_also_visibile_in_ = $options['awpa_also_visibile_in_'];   
                if (array_key_exists($post_type, $awpa_also_visibile_in_)) {
                    $visibile = $awpa_also_visibile_in_[$post_type];
                    if ($visibile) {
                        $post_author = do_shortcode('[wp-post-author title="' . $title . '" align="' . $align . '" image-layout="' . $image_layout . '" show-role="' . $show_role . '" show-email="' . $show_email . '" author-posts-link="' . $author_posts_link . '" icon-shape="' . $icon_shape . '"]');
                        $content .= $post_author;
                        
                    }
                }
            }
        }


        return $content;
    }
}


if (!function_exists('awpa_post_author_add_custom_style')) {
    function awpa_post_author_add_custom_style() {
        // Get options from settings
        $options = get_option('awpa_setting_options');
        $primary_color = isset($options['awpa_highlight_color']) ? $options['awpa_highlight_color'] : '#af0000';
        $custom_css = isset($options['awpa_custom_css']) ? $options['awpa_custom_css'] : '';

        // Generate CSS string
        $inline_css = '';

        if (!empty($primary_color)) {
            $inline_css .= "
                .wp_post_author_widget .wp-post-author-meta .awpa-display-name > a:hover,
                body .wp-post-author-wrap .awpa-display-name > a:hover {
                    color: $primary_color;
                }
                .wp-post-author-meta .wp-post-author-meta-more-posts a.awpa-more-posts:hover, 
                .awpa-review-field .right-star .awpa-rating-button:not(:disabled):hover {
                    color: $primary_color;
                    border-color: $primary_color;
                }
            ";
        }

        if (!empty($custom_css)) {
            $inline_css .= wp_strip_all_tags($custom_css);
        }

        return $inline_css;
    }
}


if (!function_exists('awpa_admin_body_class')) {

    /**
     * @param $author_id
     * @return mixed
     */
    function awpa_admin_body_class($classes)
    {
        // $pagenow contains current admin-side php-file
    // absint converts type to int, so we can use strict comparison
    $current_screen = get_current_screen();
    if (isset($current_screen->base) && $current_screen->base === 'toplevel_page_wp-post-author') {
        // This is the main menu page of your plugin
        $classes .= ' awpa-dashboard awpa-general-page';
    } elseif (isset($current_screen->base) && $current_screen->base === 'wp-post-author_page_awpa-registration-form') {
        // This is the submenu page of your plugin
        $classes .= ' awpa-dashboard  awpa-registration-page';
    } elseif (isset($current_screen->base) && $current_screen->base === 'wp-post-author_page_awpa-members') {
        // This is the submenu page of your plugin
        $classes .= ' awpa-dashboard awpa-members-page';
    } elseif (isset($current_screen->base) && $current_screen->base === 'wp-post-author_page_awpa-multi-authors') {
        // This is the submenu page of your plugin
        $classes .= ' awpa-dashboard awpa-multi-authors-page';
    }

    $author_metabox = awpa_get_author_metabox_setting();
        if ($author_metabox && $author_metabox['enable_author_metabox'] == true) {
        $classes .= ' enable_author_metabox';
    }
    $classes .= ' enable_author_metabox';
    return $classes;
    }
    add_filter('admin_body_class', 'awpa_admin_body_class');
}


function awpa_print_pre($args){
    if(!empty($args)){
        echo "<pre>";
        print_r($args);
        echo "</pre>";
    }else{
        echo "<pre>";
        print_r('Nothing Found!');
        echo "</pre>";
    }
}


