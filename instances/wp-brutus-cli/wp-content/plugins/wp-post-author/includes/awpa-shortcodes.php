<?php

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

if (!function_exists('awpa_get_author_shortcode')) {
    /**
     * @param $author_id
     * @return array
     */
    function awpa_get_author_shortcode($atts)
    {
        $awpa = shortcode_atts(array(
            'title' => __('About The Author', 'wp-post-author'),
            'author-id' => '',
            'align' => 'left',
            'image-layout' => 'square',
            'author-posts-link' => 'square',
            'icon-shape' => 'round',
            'show-role' => 'false',
            'show-email' => 'false'
        ), $atts);

        // Sanitize input parameters
        $author_id = !empty($awpa['author-id']) ? absint($awpa['author-id']) : '';
        $title = isset($awpa['title']) ? sanitize_text_field($awpa['title']) : '';
        $align = !empty($awpa['align']) ? sanitize_text_field($awpa['align']) : 'left';
        $image_layout = !empty($awpa['image-layout']) ? sanitize_text_field($awpa['image-layout']) : 'square';
        $author_posts_link = !empty($awpa['author-posts-link']) ? sanitize_text_field($awpa['author-posts-link']) : 'square';
        $icon_shape = !empty($awpa['icon-shape']) ? sanitize_text_field($awpa['icon-shape']) : 'round';
        $show_role = !empty($awpa['show-role']) ? sanitize_text_field($awpa['show-role']) : 'false';
        $show_email = !empty($awpa['show-email']) ? sanitize_text_field($awpa['show-email']) : 'false';

        $show_role = ($show_role == 'true') ? true : false;
        $show_email = ($show_email == 'true') ? true : false;

        ob_start();
        $multi_author = false;
        $post_id = get_the_ID();
        $options = get_option('awpa_author_metabox_integration');
        if ($options && array_key_exists('enable_author_metabox', $options)) {
            if ($options['enable_author_metabox']) {
                $awpa_post_authors = get_post_meta($post_id, 'wpma_author');
                if (isset($awpa_post_authors) && !empty($awpa_post_authors)) {
                    $multi_author = true;
                }
            } else {
                $awpa_post_authors = array(get_post()->post_author);
            }
        } else {
            $awpa_post_authors = array(get_post()->post_author);
        }
        ?>
        <h3 class="awpa-title"><?php echo esc_html($title); ?></h3>
        <?php
        if (isset($awpa_post_authors) && !empty($awpa_post_authors)) :
            foreach ($awpa_post_authors as $author_id) :
                $needle = 'guest-';
                if (strpos($author_id, $needle) !== false) {
                    $filter_id = substr($author_id, strpos($author_id, "-") + 1);
                    $author_id = $filter_id;
                    $author_type = 'guest';
                } else {
                    $author_id = $author_id;
                    $author_type = 'default';
                }
                $author_name = get_the_author_meta('display_name', $author_id);
                ?>
                <div class="wp-post-author-wrap wp-post-author-shortcode <?php echo esc_attr($align); ?>">
                    <?php do_action('before_wp_post_author'); ?>
                    <?php
                    if ($author_type == 'default') {
                        ?>
                        <div class="awpa-tab-content active" id="<?php echo esc_attr($author_id); ?>_awpa-tab1">
                            <?php awpa_get_author_block($author_id, $image_layout, $show_role, $show_email, $author_posts_link, $icon_shape, $multi_author); ?>
                        </div>
                        <?php
                    }
                    if ($author_type == 'guest') {
                        global $wpdb;
                        $table_name = $wpdb->prefix . "wpa_guest_authors";
                        $wp_amulti_authors = new WPAMultiAuthors();
                        $guest_user_data = $wp_amulti_authors->get_guest_by_id($author_id);
                        ?>
                        <div class="awpa-tab-content active" id="guest-<?php echo esc_attr($author_id); ?>_awpa-tab1">
                            <?php awpa_get_guest_author_block($author_id, $image_layout, $show_role, $show_email, $author_posts_link, $icon_shape); ?>
                        </div>
                        <?php
                    }
                    ?>
                    <?php do_action('after_wp_post_author'); ?>
                </div>
                <?php
            endforeach;
        else :
            $author_name = get_the_author_meta('display_name', $author_id);
            ?>
            <div class="wp-post-author-wrap wp-post-author-shortcode <?php echo esc_attr($align); ?>">
                <?php do_action('before_wp_post_author'); ?>
                <div class="awpa-tab-content active" id="<?php echo esc_attr($author_id); ?>_awpa-tab1">
                    <?php awpa_get_author_block($author_id, $image_layout, $show_role, $show_email, $author_posts_link, $icon_shape); ?>
                </div>
                <?php do_action('after_wp_post_author'); ?>
            </div>
            <?php
        endif;

        return ob_get_clean();
    }
}
add_shortcode('wp-post-author', 'awpa_get_author_shortcode');

/*
* user registration short code
*/
function awpa_add_shortcode_registration_form($atts)
{
    $atts = array_change_key_case((array) $atts, CASE_LOWER);
    $wporg_atts = shortcode_atts(
        array(
            'title' => __('Registration Form', 'wp-post-author'),
            'form_id' => array_key_exists('form_id', $atts) ? absint($atts['form_id']) : 1
        ),
        $atts
    );

    if ($wporg_atts['form_id']) {
        $attributes = array(
            'btnText' => __('Register', 'wp-post-author'),
            'imgURL' => null,
            'enableBgImage' => null
        );
        return "<div class='awpa-user-registration-wrapper'><div class='awpa-user-registration' id='render-block' value='" . esc_attr($wporg_atts['form_id']) . "' attributes='" . esc_attr(json_encode($attributes)) . "'></div></div>";
    }
}
add_shortcode('awpa-registration-form', 'awpa_add_shortcode_registration_form');