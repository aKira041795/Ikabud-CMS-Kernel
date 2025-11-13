<?php

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}


/**
 * WP Post Author
 *
 * Allows user to get WP Post Author.
 *
 * @class   WP_Post_Author_Backend
 */
class WP_Post_Author_Backend
{

    /**
     * Init and hook in the integration.
     *
     * @return void
     */

     public $id;
     public $method_title;
     public $method_description;
    public function __construct()
    {
        $this->id = 'WP_Post_Author_Backend';
        $this->method_title = __('WP Post Author Backend', 'wp-post-author');
        $this->method_description = __('WP Post Author Backend', 'wp-post-author');

        include_once 'awpa-user-fields.php';

        include_once AWPA_PLUGIN_DIR . '/includes/admin/awpa-form-register.php';
        include_once AWPA_PLUGIN_DIR . '/includes/admin/awpa-form-meta.php';
        include_once AWPA_PLUGIN_DIR . '/includes/admin/awpa-form-menu.php';
        
        include_once AWPA_PLUGIN_DIR . '/includes/awpa-widget-base.php';
        include_once AWPA_PLUGIN_DIR . '/includes/awpa-widget.php';
        include_once AWPA_PLUGIN_DIR . '/includes/awpa-widget-custom.php';
        include_once AWPA_PLUGIN_DIR . '/includes/awpa-widget-specific.php';
        

        add_action('widgets_init', array($this, 'awpa_widgets_init'));

        add_action('admin_menu', array($this, 'awpa_register_settings_menu_page'));
        //add_action('admin_init', array($this, 'awpa_display_options'));

        // Actions
        add_action('admin_enqueue_scripts', array($this, 'awpa_post_author_enqueue_admin_style'));

        add_filter('plugin_row_meta', [$this, 'plugin_row_meta'], 10, 2);

        add_filter('plugin_action_links_' . AWPA_PLUGIN_BASE, [$this, 'plugin_action_links']);
    }


    public function plugin_action_links($links)
    {
        // $settings_link = sprintf('<a href="%1$s">%2$s</a>', 'https://elespare.com/layout-page/', esc_html__('Demos', 'wp-post-author'));

        // array_unshift($links, $settings_link);

        $links['wpapro'] = sprintf('<a href="%1$s" target="_blank" class="wpa-pro-link">%2$s</a>', 'https://afthemes.com/plugins/wp-post-author/pricing/', esc_html__('Unlock All Features', 'wp-post-author'));

        return $links;
    }
    public function plugin_row_meta($plugin_meta, $plugin_file)
    {
        if (AWPA_PLUGIN_BASE === $plugin_file) {
            $row_meta = [
                'home' => '<a href="https://afthemes.com/plugins/wp-post-author/" aria-label="' . esc_attr(esc_html__('Explore More About WP Post Authors', 'wp-post-author')) . '" target="_blank">' . esc_html__('Explore More', 'wp-post-author') . '</a>',                
                'docs' => '<a href="https://afthemes.com/plugins/wp-post-author/docs/" aria-label="' . esc_attr(esc_html__('View Documentation', 'wp-post-author')) . '" target="_blank">' . esc_html__('Docs', 'wp-post-author') . '</a>',
                'all-themes-plan' => '<a href="https://afthemes.com/all-themes-plan/" aria-label="' . esc_attr(esc_html__('Access All Themes and Plugins', 'wp-post-author')) . '" target="_blank">' . esc_html__('All Themes Plan', 'wp-post-author') . '</a>',
                'support' => '<a href="https://afthemes.com/supports/" aria-label="' . esc_attr(esc_html__('Need help for Elespare?', 'wp-post-author')) . '" target="_blank">' . esc_html__('Support', 'wp-post-author') . '</a>',
                'pricing' => '<a href="https://afthemes.com/plugins/wp-post-author/pricing" aria-label="' . esc_attr(esc_html__('View Pricing', 'wp-post-author')) . '" target="_blank">' . esc_html__('Pricing', 'wp-post-author') . '</a>',
                
            ];

            $plugin_meta = array_merge($plugin_meta, $row_meta);
        }

        return $plugin_meta;
    }
    public function awpa_post_author_enqueue_admin_style($hook)
    {
        
        wp_register_style('awpa-admin-style', AWPA_PLUGIN_URL . 'assets/css/awpa-backend-style.css', array(), AWPA_VERSION, 'all');

        wp_enqueue_style('awpa-admin-style');
       
        if ('widgets.php' === $hook) {
            wp_enqueue_media();
            wp_register_script('awpa-admin-scripts', AWPA_PLUGIN_URL . 'assets/js/awpa-backend-scripts.js', array('jquery'), AWPA_VERSION, true);
            wp_enqueue_script('awpa-admin-scripts');
        }

       

        if ('wp-post-author_page_awpa-multi-authors' == $hook) {
            wp_enqueue_script(
                'awpa-guest-authors',
                AWPA_PLUGIN_URL . 'assets/dist/guest_authors.build.js',
                array(),
                AWPA_VERSION,
                true
            );
        }

        if ('wp-post-author_page_awpa-members' == $hook) {

            wp_enqueue_script(
                'wpauthor-membership-build-js',
                AWPA_PLUGIN_URL . 'assets/dist/membership.build.js',
                array('wp-blocks', 'wp-i18n', 'wp-api-fetch', 'wp-element', 'wp-components', 'wp-editor'),
                AWPA_VERSION,
                true
            );
           
            wp_localize_script(
                'wpauthor-membership-build-js',
                'wpauthor_member_data',
                array(
                    'adminUrl' => site_url()
                )
            );
        }
        
        if ('toplevel_page_wp-post-author' == $hook) {
            wp_enqueue_style('react-toggle-styles-admin', AWPA_PLUGIN_URL . '/assets/css/react-toggle.css', array(), AWPA_VERSION);
            wp_enqueue_script(
                'wpauthor-settings-build-js',
                AWPA_PLUGIN_URL . 'assets/dist/settings.build.js',
                array('wp-i18n'),
                AWPA_VERSION,
                true
            );
        }

        

        if ('wp-post-author_page_awpa-registration-form' == $hook) {
            
            wp_enqueue_script(
                'wpauthor-builder-build-js',
                AWPA_PLUGIN_URL . 'assets/dist/builder.build.js',
                array('wp-i18n'),
                AWPA_VERSION,
                true
            );
        }
        if ('wp-post-author_page_awpa-registration-form' === $hook) {
            
            wp_enqueue_script(
                'wpauthor-form-builder-list-block-js',
                AWPA_PLUGIN_URL . 'assets/dist/form_builder_list.build.js',
                array('wp-i18n'),
                AWPA_VERSION
            );

            wp_localize_script(
                'wpauthor-form-builder-list-block-js',
                'wpauthor_globals_listing',
                array(
                    'pluginDir' => AWPA_PLUGIN_URL
                )
            );
            wp_enqueue_script(
                'wpauthor-blocks-block-js',
                AWPA_PLUGIN_URL . 'assets/dist/blocks.build.js',
                array('wp-blocks', 'wp-i18n', 'wp-element', 'wp-components', 'wp-editor'),
                AWPA_VERSION
            );

            wp_localize_script(
                'wpauthor-blocks-block-js',
                'wpauthor_globals',
                array(
                    'srcUrl' => untrailingslashit(plugins_url('/', AWPA_BASE_DIR . '/dist/')),
                    'rest_url' => esc_url(rest_url()),
                    
                )
            );
        }
    }



    public function awpa_widgets_init()
    {
        register_widget('AWPA_Widget');
        register_widget('AWPA_Widget_Custom');
        register_widget('AWPA_Widget_Specific');
    }

    /**
     * Register a awpa settings page
     */
    public function awpa_register_settings_menu_page()
    {
        add_menu_page(
            __('WP Post Author', 'wp-post-author'),
            'WP Post Author',
            'manage_options',
            'wp-post-author',
            '',
            'dashicons-id-alt',
            70
        );
    }
    
}

$awpa_backend = new WP_Post_Author_Backend();