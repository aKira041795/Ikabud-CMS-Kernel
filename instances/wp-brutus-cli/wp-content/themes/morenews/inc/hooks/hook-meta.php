<?php
/**
 * Implement theme metabox.
 *
 * @package MoreNews
 */

if (!function_exists('morenews_add_theme_meta_box')) :

    /**
     * Add the Meta Box
     *
     * @since 1.0.0
     */
    function morenews_add_theme_meta_box()
    {

        $morenews_screens = array('post', 'page');

        foreach ($morenews_screens as $screen) {
            add_meta_box(
                'morenews-theme-settings',
                esc_html__('Layout Options', 'morenews'),
                'morenews_render_layout_options_metabox',
                $screen,
                'side',
                'low'


            );
        }

    }

endif;

add_action('add_meta_boxes', 'morenews_add_theme_meta_box');

if (!function_exists('morenews_render_layout_options_metabox')) :

    /**
     * Render theme settings meta box.
     *
     * @since 1.0.0
     */
    function morenews_render_layout_options_metabox($post, $metabox)
    {

        $morenews_post_id = $post->ID;

        // Meta box nonce for verification.
        wp_nonce_field(basename(__FILE__), 'morenews_meta_box_nonce');
        // Fetch Options list.
        $morenews_content_layout = get_post_meta($morenews_post_id, 'morenews-meta-content-alignment', true);
        $morenews_global_single_content_mode = get_post_meta($morenews_post_id, 'morenews-meta-content-mode', true);

        if (empty($morenews_content_layout)) {
            $morenews_content_layout = morenews_get_option('global_content_alignment');
        }

        if (empty($morenews_global_single_content_mode)) {
            $morenews_global_single_content_mode = morenews_get_option('global_single_content_mode');
        }


        ?>
        <div id="morenews-settings-metabox-container" class="morenews-settings-metabox-container">
            <div id="morenews-settings-metabox-tab-layout">

                <div class="morenews-row-content">
                    <!-- Select Field-->
                    <h3><?php esc_html_e('Content Options', 'morenews') ?></h3>
                    <p>
                        <select name="morenews-meta-content-mode" id="morenews-meta-content-mode">

                            <option value="" <?php selected('', $morenews_global_single_content_mode); ?>>
                                <?php esc_html_e('Set as global layout', 'morenews') ?>
                            </option>
                            <option value="single-content-mode-default" <?php selected('single-content-mode-default', $morenews_global_single_content_mode); ?>>
                                <?php esc_html_e('Default', 'morenews') ?>
                            </option>
                            <option value="single-content-mode-boxed" <?php selected('single-content-mode-boxed', $morenews_global_single_content_mode); ?>>
                                <?php esc_html_e('Spacious', 'morenews') ?>
                            </option>

                        </select>
                    </p>
                    <small><?php esc_html_e('Please go to Customize>Themes Options for Single Post/Page.', 'morenews')?></small>

                </div><!-- .morenews-row-content -->
                <div class="morenews-row-content">
                    <!-- Select Field-->
                    <h3><?php esc_html_e('Sidebar Options', 'morenews') ?></h3>
                    <p>
                        <select name="morenews-meta-content-alignment" id="morenews-meta-content-alignment">

                            <option value="" <?php selected('', $morenews_content_layout); ?>>
                                <?php esc_html_e('Set as global layout', 'morenews') ?>
                            </option>
                            <option value="align-content-left" <?php selected('align-content-left', $morenews_content_layout); ?>>
                                <?php esc_html_e('Content - Primary Sidebar', 'morenews') ?>
                            </option>
                            <option value="align-content-right" <?php selected('align-content-right', $morenews_content_layout); ?>>
                                <?php esc_html_e('Primary Sidebar - Content', 'morenews') ?>
                            </option>
                            <option value="full-width-content" <?php selected('full-width-content', $morenews_content_layout); ?>>
                                <?php esc_html_e('No Sidebar', 'morenews') ?>
                            </option>
                        </select>
                    </p>
                    <small><?php esc_html_e('Please go to Customize>Front-page Options for Homepage.', 'morenews')?></small>

                </div><!-- .morenews-row-content -->

            </div><!-- #morenews-settings-metabox-tab-layout -->
        </div><!-- #morenews-settings-metabox-container -->

        <?php
    }

endif;


if (!function_exists('morenews_save_layout_options_meta')) :

    /**
     * Save theme settings meta box value.
     *
     * @since 1.0.0
     *
     * @param int $morenews_post_id Post ID.
     * @param WP_Post $post Post object.
     */
    function morenews_save_layout_options_meta($morenews_post_id, $post)
    {

        // Verify nonce.
        if (!isset($_POST['morenews_meta_box_nonce']) || !wp_verify_nonce($_POST['morenews_meta_box_nonce'], basename(__FILE__))) {
            return;
        }

        // Bail if auto save or revision.
        if (defined('DOING_AUTOSAVE') || is_int(wp_is_post_revision($post)) || is_int(wp_is_post_autosave($post))) {
            return;
        }

        // Check the post being saved == the $morenews_post_id to prevent triggering this call for other save_post events.
        if (empty($_POST['post_ID']) || $_POST['post_ID'] != $morenews_post_id) {
            return;
        }

        // Check permission.
        if ('page' == $_POST['post_type']) {
            if (!current_user_can('edit_page', $morenews_post_id)) {
                return;
            }
        } else if (!current_user_can('edit_post', $morenews_post_id)) {
            return;
        }

        $morenews_content_layout = isset($_POST['morenews-meta-content-alignment']) ? $_POST['morenews-meta-content-alignment'] : '';
        $morenews_global_single_content_mode = isset($_POST['morenews-meta-content-mode']) ? $_POST['morenews-meta-content-mode'] : '';
        update_post_meta($morenews_post_id, 'morenews-meta-content-alignment', sanitize_text_field($morenews_content_layout));
        update_post_meta($morenews_post_id, 'morenews-meta-content-mode', sanitize_text_field($morenews_global_single_content_mode));


    }

endif;

add_action('save_post', 'morenews_save_layout_options_meta', 10, 2);


//Category fields meta starts


if (!function_exists('morenews_taxonomy_add_new_meta_field')) :
// Add term page
    function morenews_taxonomy_add_new_meta_field()
    {
        // this will add the custom meta field to the add new term page

        $morenews_cat_color = array(
            'category-color-1' => __('Category Color 1', 'morenews'),
            'category-color-2' => __('Category Color 2', 'morenews'),
            'category-color-3' => __('Category Color 3', 'morenews'),


        );
        ?>
        <div class="form-field">
            <label for="term_meta[color_class_term_meta]"><?php esc_html_e('Color Class', 'morenews'); ?></label>
            <select id="term_meta[color_class_term_meta]" name="term_meta[color_class_term_meta]">
                <?php foreach ($morenews_cat_color as $key => $value): ?>
                    <option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($value); ?></option>
                <?php endforeach; ?>
            </select>
            <p class="description"><?php esc_html_e('Select category color class. You can set appropriate categories color on "Categories" section of the theme customizer.', 'morenews'); ?></p>
        </div>
        <?php
    }
endif;
add_action('category_add_form_fields', 'morenews_taxonomy_add_new_meta_field', 10, 2);


if (!function_exists('morenews_taxonomy_edit_meta_field')) :
// Edit term page
    function morenews_taxonomy_edit_meta_field($term)
    {

        // put the term ID into a variable
        $morenews_t_id = $term->term_id;

        // retrieve the existing value(s) for this meta field. This returns an array
        $morenews_term_meta = get_option("category_color_$morenews_t_id");

        ?>
        <tr class="form-field">
            <th scope="row" valign="top"><label
                        for="term_meta[color_class_term_meta]"><?php esc_html_e('Color Class', 'morenews'); ?></label></th>
            <td>
                <?php
                $morenews_cat_color = array(
                    'category-color-1' => __('Category Color 1', 'morenews'),
                    'category-color-2' => __('Category Color 2', 'morenews'),
                    'category-color-3' => __('Category Color 3', 'morenews'),


                );
                ?>
                <select id="term_meta[color_class_term_meta]" name="term_meta[color_class_term_meta]">
                    <?php foreach ($morenews_cat_color as $key => $value): ?>
                        <option value="<?php echo esc_attr($key); ?>"<?php selected(isset($morenews_term_meta['color_class_term_meta'])?$morenews_term_meta['color_class_term_meta']:'', $key); ?>><?php echo esc_html($value); ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="description"><?php esc_html_e('Select category color class. You can set appropriate categories color on "Categories" section of the theme customizer.', 'morenews'); ?></p>
            </td>
        </tr>
        <?php
    }
endif;
add_action('category_edit_form_fields', 'morenews_taxonomy_edit_meta_field', 10, 2);




if (!function_exists('morenews_save_taxonomy_color_class_meta')) :
// Save extra taxonomy fields callback function.
    function morenews_save_taxonomy_color_class_meta($morenews_term_id)
    {
        if (isset($_POST['term_meta'])) {
            $morenews_t_id = $morenews_term_id;
            $morenews_term_meta = get_option("category_color_$morenews_t_id");
            $morenews_cat_keys = array_keys($_POST['term_meta']);
            foreach ($morenews_cat_keys as $key) {
                if (isset ($_POST['term_meta'][$key])) {
                    $morenews_term_meta[$key] = $_POST['term_meta'][$key];
                }
            }
            // Save the option array.
            update_option("category_color_$morenews_t_id", $morenews_term_meta);
        }
    }

endif;
add_action('edited_category', 'morenews_save_taxonomy_color_class_meta', 10, 2);
add_action('create_category', 'morenews_save_taxonomy_color_class_meta', 10, 2);