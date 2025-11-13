<?php

/**
 * EleSpare - News, Magazine and Blog Addons for Elementor
 *
 * @package Elespare
 *
 * Plugin Name: EleSpare - News, Magazine and Blog Addons for Elementor
 * Description: EleSpare provides pre-designed templates, header/footer builders, and various post layouts for creating stunning news, magazine, and blog sites with Elementor.

 * Plugin URI:  https://elespare.com/
 * Version:     3.3.8
 * Elementor tested up to:  3.32.2
 * Elementor Pro tested up to:  3.32.2
 * Author:      Elespare
 * Author URI:  https://elespare.com/
 * Text Domain: elespare
 * EleSpare provides pre-designed templates, header/footer builders, and various post layouts for creating stunning news, magazine, and blog sites with Elementor.
 */

if (!defined('ABSPATH')) {
    // Exit if accessed directly.
    exit;
}

defined('ELESPARE_VERSION') or define('ELESPARE_VERSION', '3.3.8');
defined('ELESPARE') or define('ELESPARE', __FILE__);
defined('ELESPARE_PLUGIN_BASE') or define('ELESPARE_PLUGIN_BASE', plugin_basename(ELESPARE));
defined('ELESPARE_DIR_PATH') or define('ELESPARE_DIR_PATH', plugin_dir_path(ELESPARE));
defined('ELESPARE_DIR_URL') or define('ELESPARE_DIR_URL', plugin_dir_url(ELESPARE));
defined('ELESPARE_SHOW_PRO_NOTICES') || define('ELESPARE_SHOW_PRO_NOTICES', false);

/**
 * Freemius.
 */
require_once ELESPARE_DIR_PATH . '/freemius.php';

/**
 * Main Elespare Class
 *
 * The init class that runs the Elementor Elespare plugin.
 * Intended To make sure that the plugin's minimum requirements are met.
 * You should only modify the constants to match your plugin's needs.
 *
 * Any custom code should go inside Plugin Class in the class-widgets.php file.
 */
final class Elespare
{

    /**
     * Plugin Version
     *
     * @since 1.0.0
     * @var string The plugin version.
     */
    const VERSION = '3.3.7';

    /**
     * Minimum Elementor Version
     *
     * @since 1.0.0
     * @var string Minimum Elementor version required to run the plugin.
     */
    const MINIMUM_ELEMENTOR_VERSION = '2.8.0';

    /**
     * Minimum PHP Version
     *
     * @since 1.0.0
     * @var string Minimum PHP version required to run the plugin.
     */
    const MINIMUM_PHP_VERSION = '5.4';

    /**
     * Constructor
     *
     * @since 1.0.0
     * @access public
     */
    public function __construct()
    {
        // Load the translation.
        add_action('init', array($this, 'i18n'));

        // Initialize the plugin.
        add_action('plugins_loaded', array($this, 'init'));

        // Register widget style
        add_action('elementor/frontend/after_enqueue_styles', [$this, 'ElespareWidgetStyle']);

        add_action('elementor/elements/categories_registered', [$this, 'register_widget_category']);

        add_action('activated_plugin', [$this, 'elespare_activation_redirect']);

        add_filter('plugin_row_meta', [$this, 'plugin_row_meta'], 10, 2);

        add_filter('plugin_action_links_' . ELESPARE_PLUGIN_BASE, [$this, 'plugin_action_links']);
    }

    public function ElespareWidgetStyle()
    {
        wp_register_style('elespare-posts-grid', plugins_url('dist/elespare.style.build.min.css', ELESPARE), array(), self::VERSION);
        wp_enqueue_style('elespare-posts-grid');
    }

    /**
     * Load Textdomain
     *
     * Load plugin localization files.
     * Fired by `init` action hook.
     *
     * @since 1.0.0
     * @access public
     */
    public function i18n()
    {
        load_plugin_textdomain('elespare');
    }

    /**
     * Initialize the plugin
     *
     * Validates that Elementor is already loaded.
     * Checks for basic plugin requirements, if one check fail don't continue,
     * if all check have passed include the plugin class.
     *
     * Fired by `plugins_loaded` action hook.
     *
     * @since 1.0.0
     * @access public
     */
    public function init()
    {

        add_action('elementor/editor/before_enqueue_scripts', [$this, 'editor_enqueue']);

        include_once ELESPARE_DIR_PATH . 'inc/admin/class-admin-dashboard.php';

        // Check if Elementor installed and activated.
        if (!did_action('elementor/loaded')) {
            add_action('admin_notices', array($this, 'admin_notice_missing_main_plugin'));
            return;
        }

        // Check for required Elementor version.
        if (!version_compare(ELEMENTOR_VERSION, self::MINIMUM_ELEMENTOR_VERSION, '>=')) {
            add_action('admin_notices', array($this, 'admin_notice_minimum_elementor_version'));
            return;
        }

        // Check for required PHP version.
        if (version_compare(PHP_VERSION, self::MINIMUM_PHP_VERSION, '<')) {
            add_action('admin_notices', array($this, 'admin_notice_minimum_php_version'));
            return;
        }

        // Once we get here, We have passed all validation checks so we can safely include our widgets.
        require_once 'class-elespare.php';
        include_once ELESPARE_DIR_PATH . 'header-footer/init.php';
        require ELESPARE_DIR_PATH . 'inc/functions.php';
        require ELESPARE_DIR_PATH . 'inc/init.php';
    }

    public function plugin_action_links($links)
    {
        // $settings_link = sprintf('<a href="%1$s">%2$s</a>', 'https://elespare.com/layout-page/', esc_html__('Demos', 'elespare'));

        // array_unshift($links, $settings_link);

        $links['espro'] = sprintf('<a href="%1$s" target="_blank" class="elespare-pro-link">%2$s</a>', 'https://elespare.com/pricing/', esc_html__('Template Kits', 'elespare'));

        return $links;
    }
    public function plugin_row_meta($plugin_meta, $plugin_file)
    {
        if (ELESPARE_PLUGIN_BASE === $plugin_file) {
            $row_meta = [
                'templates' => '<a href="https://elespare.com/layout-page/" aria-label="' . esc_attr(esc_html__('View Elespare Template Kits', 'elespare')) . '" target="_blank">' . esc_html__('Template Kits', 'elespare') . '</a>',
                'widgets' => '<a href="https://elespare.com/layout-page/" aria-label="' . esc_attr(esc_html__('View Elespare Widgets', 'elespare')) . '" target="_blank">' . esc_html__('Widgets', 'elespare') . '</a>',
                'docs' => '<a href="https://elespare.com/elespare-docs/" aria-label="' . esc_attr(esc_html__('View Elespare Documentation', 'elespare')) . '" target="_blank">' . esc_html__('Docs', 'elespare') . '</a>',
                'video' => '<a href="https://afthemes.com/all-themes-plan/" aria-label="' . esc_attr(esc_html__('Access All Themes and Plugins', 'elespare')) . '" target="_blank">' . esc_html__('All Themes Plan', 'elespare') . '</a>',
                'support' => '<a href="https://afthemes.com/supports/" aria-label="' . esc_attr(esc_html__('Need help for Elespare?', 'elespare')) . '" target="_blank">' . esc_html__('Support', 'elespare') . '</a>',

            ];

            $plugin_meta = array_merge($plugin_meta, $row_meta);
        }

        return $plugin_meta;
    }

    /**
     * Admin notice
     *
     * Warning when the site doesn't have Elementor installed or activated.
     *
     * @since 1.0.0
     * @access public
     */
    public function admin_notice_missing_main_plugin()
    {
        $view_demos = __('View Demos', 'elespare');
        $docs_support = __('Documentations', 'elespare');
        $contact_support = __('Need Help?', 'elespare');
        if (file_exists(WP_PLUGIN_DIR . '/elementor/elementor.php')) {
            $notice_title = __('Activate Elementor', 'elespare');
            $notice_url = wp_nonce_url('plugins.php?action=activate&plugin=elementor/elementor.php&plugin_status=all&paged=1', 'activate-plugin_elementor/elementor.php');
        } else {
            $notice_title = __('Install Elementor', 'elespare');
            $notice_url = wp_nonce_url(self_admin_url('update.php?action=install-plugin&plugin=elementor'), 'install-plugin_elementor');
        }

        $messages = sprintf(
            /* translators: 1: Plugin name 2: Elementor */
            esc_html__('%3$s %1$s requires %2$s to be installed and activated to get the outstanding News,Magazine and Blog elements (widgets) and Header/Footer builder.%4$s %3$s %5$s %6$s %7$s %8$s %4$s', 'elespare'),
            '<strong>' . esc_html__('Elespare', 'elespare') . '</strong>',
            '<strong>' . esc_html__('Elementor', 'elespare') . '</strong>',
            '<p>',
            '</p>',
            '<a class="button-primary" href="' . esc_url($notice_url) . '">' . $notice_title . '</a>',
            '<a class="button-secondary" target="_blank" href="' . esc_url('https://elespare.com/') . '">' . $view_demos . '</a>',
            '<a class="button-secondary" target="_blank" href="' . esc_url('https://elespare.com/elespare-docs/') . '">' . $docs_support . '</a>',
            '<a class="button-secondary" target="_blank" href="' . esc_url('http://afthemes.com/supports/') . '">' . $contact_support . '</a>'

        );

        printf('<div class="notice notice-warning is-dismissible elespare-notice">%1$s</div>', $messages);
    }

    /**
     * Admin notice
     *
     * Warning when the site doesn't have a minimum required Elementor version.
     *
     * @since 1.0.0
     * @access public
     */
    public function admin_notice_minimum_elementor_version()
    {

        $ele_version = sprintf(
            '<div class="notice notice-warning is-dismissible"><p><strong>"%1$s"</strong> requires <strong>"%2$s"</strong> version %3$s or greater.</p></div>',
            'Elespare',
            'Elementor',
            self::MINIMUM_ELEMENTOR_VERSION
        );

        echo wp_kses($ele_version, array(
            'div' => array(
                'class' => array(),
                'p' => array(),
                'strong' => array(),
            ),
        ));
    }

    /**
     * Admin notice
     *
     * Warning when the site doesn't have a minimum required PHP version.
     *
     * @since 1.0.0
     * @access public
     */
    public function admin_notice_minimum_php_version()
    {

        $ele_php_version = sprintf(

            '<div class="notice notice-warning is-dismissible"><p><strong>"%1$s"</strong> requires <strong>"%2$s"</strong> version %3$s or greater.</p></div>',
            'Elespare',
            'PHP',
            self::MINIMUM_PHP_VERSION
        );

        echo wp_kses($ele_php_version, array(
            'div' => array(
                'class' => array(),
                'p' => array(),
                'strong' => array(),
            ),
        ));
    }

    public function register_widget_category($elements_manager)
    {

        $elements_manager->add_category(
            'elespare',
            [
                'title' => esc_html__('Elespare', 'elespare'),
                'icon' => 'fa fa-plug',
            ]
        );
    }

    public function editor_enqueue()
    {
        wp_enqueue_style(
            'elespare-icons',
            ELESPARE_DIR_URL . 'assets/font/elespare-icons.css',
            array(),
            ELESPARE_VERSION
        );

        global $wp_query;
        $id = $wp_query->post->ID;
        wp_enqueue_script('elespare-elementor-modal', ELESPARE_DIR_URL . 'assets/js/elementor-modal.js', ['jquery'], ELESPARE_VERSION);

        wp_enqueue_script(
            'elespare-library-react',
            ELESPARE_DIR_URL . 'dist/main_js.build.min.js',
            [
                'wp-editor',
                'elespare-elementor-modal',
            ],
            ELESPARE_VERSION,
            true
        );

        wp_localize_script('elespare-library-react', 'AFTLibrary', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'baseUrl' => ELESPARE_DIR_URL,
            'externalUrl' => 'https://raw.githubusercontent.com/afthemes/elespare-demo-data/master/free',
            'apiUrl' => site_url() . '/index.php?rest_route=/',
            'currentPage' => $id,
            'key' => '',
            'host' => $_SERVER['HTTP_HOST'],

        ));
    }

    public function elespare_activation_redirect($plugin)
    {
        if ($plugin == plugin_basename(ELESPARE)) {
            $redirect_url = esc_url_raw(add_query_arg(['page' => 'elespare_dashboard'], admin_url('admin.php')));
            wp_safe_redirect($redirect_url);
            exit;
        }
    }
}

// Instantiate Elespare.
new Elespare();
