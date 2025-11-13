<?php
/**
 * Welcome screen.
 */

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('Blockspare_Admin_Dashboard')) {
    class Blockspare_Admin_Dashboard
    {
        public function __construct()
        {
            add_action('admin_menu', array($this, 'add_dashboard_page'));

            add_action('admin_enqueue_scripts', array($this, 'enqueue_dashboard_script'));

            add_action('admin_init', array($this, 'redirect_to_blockspare_page'));

            //add_action( 'init', array($this,'blockspare_load_api_files'));

            add_filter('plugin_row_meta', [$this, 'plugin_row_meta'], 10, 2);

            add_filter('plugin_action_links_' . BLOCKSPARE_PLUGIN_BASE, [$this, 'plugin_action_links']);
        }

        public function plugin_action_links($links)
        {
            $settings_link = sprintf('<a href="%1$s">%2$s</a>', admin_url('admin.php?page=blockspare'), esc_html__('Patterns Library', 'blockspare'));

            array_unshift($links, $settings_link);

            $links['bspro'] = sprintf('<a href="%1$s" target="_blank" class="blockspare-pro-link">%2$s</a>', 'https://www.blockspare.com/pricing/', esc_html__('Get Blockspare Pro', 'blockspare'));

            return $links;
        }
        public function plugin_row_meta($plugin_meta, $plugin_file)
        {
            if (BLOCKSPARE_PLUGIN_BASE === $plugin_file) {
                $row_meta = [
                    'starter' => '<a href="https://www.blockspare.com/starter-templates/" aria-label="' . esc_attr(esc_html__('View Blockspare Patterns Library', 'blockspare')) . '" target="_blank">' . esc_html__('Demos', 'blockspare') . '</a>',
                    'docs' => '<a href="https://www.blockspare.com/docs/" aria-label="' . esc_attr(esc_html__('View Blockspare Documentation', 'blockspare')) . '" target="_blank">' . esc_html__('Docs', 'blockspare') . '</a>',
                    'video' => '<a href="https://afthemes.com/all-themes-plan/" aria-label="' . esc_attr(esc_html__('Access All Themes and Plugins', 'blockspare')) . '" target="_blank">' . esc_html__('All Themes & Plugins', 'blockspare') . '</a>',
                    'support' => '<a href="https://afthemes.com/supports/" aria-label="' . esc_attr(esc_html__('Need help for Blockspare?', 'blockspare')) . '" target="_blank">' . esc_html__('Need Help?', 'blockspare') . '</a>',
                ];

                $plugin_meta = array_merge($plugin_meta, $row_meta);
            }

            return $plugin_meta;
        }

        public function add_dashboard_page()
        {

            // @see images/blockspare-icon.svg
            $svg = <<< SVG
<svg version="1.1" id="Layer_1" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" x="0px" y="0px"
	 viewBox="0 0 40 40" style="enable-background:new 0 0 40 40;" xml:space="preserve">
<g>
	<g>
		<path class="st0" d="M1.6,9.2v21.4l18.3-9.2L20.2,0L1.6,9.2z M16.9,19.8L4.9,26V10.9l12-6.1V19.8z"/>
		<polygon id="XMLID_3_" class="st1" points="19.9,21.4 16.9,23 26,27.7 13.8,33.8 4.9,29 1.6,30.7 13.9,36.9 32.4,27.7 		"/>
	</g>
	<g>
		<polygon id="XMLID_2_" class="st0" points="23,1.5 23,19.8 32.4,24.6 32.4,9.4 35.3,10.9 35.3,29 38.4,30.7 38.4,9.2 29.4,4.8
			29.2,19.7 26,18.4 26,3.1 		"/>
		<polygon id="XMLID_1_" class="st1" points="17,38.4 19.9,40 38.4,30.7 35.3,29 		"/>
	</g>
</g>
</svg>
SVG;

            add_menu_page(
                __('Blockspare', 'blockspare'), // Page Title.
                __('Blockspare', 'blockspare'), // Menu Title.
                'edit_posts', // Capability.
                'blockspare', // Menu slug.
                array($this, 'blockspare_admin_dashboard'), // Action.
                'data:image/svg+xml;base64,' . base64_encode($svg), // Blockspare icon.
                25
            );

            // Our getting started page.
            add_submenu_page(
                'blockspare', // Parent slug.
                __('Patterns Library', 'blockspare'), // Page title.
                __('Patterns Library', 'blockspare'), // Menu title.
                'manage_options', // Capability.
                'blockspare', // Menu slug.
                array($this, 'blockspare_admin_dashboard'), // Callback function.
                1// Position
            );

            add_submenu_page(
                'blockspare', // Parent slug.
                __('My Patterns', 'blockspare'), // Page title.
                __('My Patterns', 'blockspare'), // Menu title.
                'manage_options', // Capability.
                'edit.php?post_type=bs_templates', // Menu slug.

            );
        }

        public function enqueue_dashboard_script($hook)
        {
            wp_enqueue_style('blockspare-admin', BLOCKSPARE_PLUGIN_URL . 'admin/assets/css/style.css', '', '');
            wp_enqueue_script('blockspare_dashboard_js', BLOCKSPARE_PLUGIN_URL . 'dist/block_admin_dashboard.js', array('react', 'react-dom', 'wp-components', 'wp-element', 'wp-api-fetch', 'wp-polyfill'), '1.0');
            wp_enqueue_style('blockspare-admin-css', BLOCKSPARE_PLUGIN_URL . 'dist/style-block_admin_dashboard.css');
            $blockspare_dashboard_logo = BLOCKSPARE_PLUGIN_URL . 'admin/assets/images/blockspare-logo.png';
            wp_localize_script(
                'blockspare_dashboard_js',
                'blockspare_dashboard',
                array(

                    'logo' => $blockspare_dashboard_logo,
                    "imagePath" => "https://raw.githubusercontent.com/afthemes/blockspare-demo-data/master/blocks/",
                    "static_img" => BLOCKSPARE_PLUGIN_URL,
                    'newPageUrl' => admin_url('post-new.php?post_type=page&blockspare_create_block'),
                    'adminPath' => admin_url('post-new.php?post_type=page&blockspare_show_intro=true'),
                    'pluginVesion' => BLOCKSPARE_VERSION,

                )
            );
        }

        public function blockspare_admin_dashboard()
        {
            ?>
            <div id="bs-dashboard"></div>

            <?php
}

        /**
         * Adds a marker to remember to redirect after activation.
         * Redirecting right away will not work.
         */
        public static function start_redirect_to_blockspare_page()
        {
            update_option('blockspare_redirect_to_welcome', '1');
        }

        /**
         * Redirect to the welcome screen if our marker exists.
         */
        public function redirect_to_blockspare_page()
        {
            if (get_option('blockspare_redirect_to_welcome')) {
                delete_option('blockspare_redirect_to_welcome');
                wp_redirect(esc_url(admin_url('admin.php?page=blockspare')));
                die();
            }
        }

    }

    new Blockspare_Admin_Dashboard();
}

// Redirect to the welcome screen.
register_activation_hook(BLOCKSPARE_BASE_FILE, array('Blockspare_Admin_Dashboard', 'start_redirect_to_blockspare_page'));