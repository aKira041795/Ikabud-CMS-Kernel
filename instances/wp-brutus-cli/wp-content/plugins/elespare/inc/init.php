<?php

defined('ABSPATH') or die('No script kiddies please!');

if (!class_exists('AFT_Elespare_Addons')) {

    class AFT_Elespare_Addons
    {
        /**
         * Plugin version.
         *
         * @var string
         */
        const VERSION = '1.0.0';

        /**
         * Instance of this class.
         *
         * @var object
         */
        protected static $instance = null;

        /**
         * Initialize the plugin.
         */

        public static function get_instance()
        {
            // If the single instance hasn't been set, set it now.
            if (null == self::$instance) {
                self::$instance = new self;
            }

            return self::$instance;
        }

        public function __construct()
        {

            add_action('elementor/preview/enqueue_styles', [$this, 'enqueue_editor_scripts']);
            add_action('elementor/init', array($this, 'elespare_load_files'));
            add_action('rest_api_init', array($this, 'elespare_register_plugins_routes'));
            add_action('admin_enqueue_scripts', array($this, 'elespare_register_backend_scripts'));

        }

        public function elespare_load_files()
        {

            require ELESPARE_DIR_PATH . 'inc/request-rest-api.php';
            require ELESPARE_DIR_PATH . 'inc/admin/class-base.php';

        }

        public function elespare_register_plugins_routes()
        {
            $afdl_rest = new Elespare_RestApi_Request();
            $afdl_rest->elespare_register_routes();
        }

        public function enqueue_editor_scripts()
        {
            wp_enqueue_style(
                'elespare-styles',
                ELESPARE_DIR_URL . 'assets/admin/css/admin-style.css',
                null,
                ELESPARE_VERSION
            );
        }

        public function elespare_register_backend_scripts()
        {

            wp_enqueue_script('starter-sites', ELESPARE_DIR_URL . 'dist/starter_sites.build.min.js', array('react', 'react-dom', 'wp-components', 'wp-element', 'wp-api-fetch', 'wp-polyfill'), ELESPARE_VERSION);
            wp_enqueue_script('elespare-iframe', ELESPARE_DIR_URL . 'dist/iframe_preview.build.min.js', array('jquery'), ELESPARE_VERSION);
            wp_localize_script('starter-sites', 'ELELibrary', array(
                'ajaxurl' => admin_url('admin-ajax.php'),
                'baseUrl' => ELESPARE_DIR_URL,
                'externalUrl' => 'https://raw.githubusercontent.com/afthemes/elespare-demo-data/master/free',
                'apiUrl' => site_url() . '/index.php?rest_route=/',
                'newPageUrl' => admin_url('post-new.php?post_type=page&elespare_create_block'),
                'logo' => ELESPARE_DIR_URL . 'inc/admin/svg/elespare.png',
            ));
        }

    }

    add_action('plugins_loaded', array('AFT_Elespare_Addons', 'get_instance'), 0);
}
new AFT_Elespare_Addons();
