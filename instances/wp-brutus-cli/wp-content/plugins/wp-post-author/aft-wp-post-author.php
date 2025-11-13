<?php
/** 
 * Plugin Name:       WP Post Author
 * Plugin URI:        https://afthemes.com/plugins/wp-post-author/
 * Description:       Display author boxes, social links, co-authors, guest authors, post rating system, and user registration forms.
 * Version:           3.8.5
 * Author:            AF themes
 * Author URI:        https://afthemes.com
 * Text Domain:       wp-post-author
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.html
 */

defined('ABSPATH') or die('No script kiddies please!'); // prevent direct access

if (!class_exists('WP_Post_Author')):

    class WP_Post_Author
{

        /**
         * Plugin version.
         *
         * @var string
         */
        const VERSION = '3.8.5';

        /**
         * Instance of this class.
         *
         * @var object
         */
        protected static $instance = null;

        /**
         * Return an instance of this class.
         *
         * @return object A single instance of this class.
         */
        public static function get_instance()
    {
            // If the single instance hasn't been set, set it now.
            if (null == self::$instance) {
                self::$instance = new self;
            }

            return self::$instance;
        }

        /**
         * Initialize the plugin.
         */
        public function __construct()
    {
            /**
             * Define global constants
             **/
            define('AWPA_VERSION', '3.6.4');
            defined('AWPA_BASE_FILE') or define('AWPA_BASE_FILE', __FILE__);
            defined('AWPA_BASE_DIR') or define('AWPA_BASE_DIR', dirname(AWPA_BASE_FILE));
            defined('AWPA_PLUGIN_BASE') or define('AWPA_PLUGIN_BASE', plugin_basename(AWPA_BASE_FILE));
            defined('AWPA_PLUGIN_URL') or define('AWPA_PLUGIN_URL', plugin_dir_url(__FILE__));
            defined('AWPA_PLUGIN_DIR') or define('AWPA_PLUGIN_DIR', plugin_dir_path(__FILE__));

            /**
             * Freemius.
             */
            require_once AWPA_PLUGIN_DIR . '/freemius.php';

            include_once 'includes/init.php';
            $awpa_seed_inserted = get_option('awpa_seed_insert');
            if ($awpa_seed_inserted == false) {
                include_once 'includes/database/create-db.php';
            }
            add_action('init', [$this, 'awpa_load_core_files']);
            add_action('awpa_call_seeder_function', array($this, 'awpa_hook_call_seeder_function'), 10);

            
        } // end of contructor

        public function awpa_load_core_files()
    {

            include_once 'includes/core.php';
            include_once 'includes/fonts.php';
            include_once 'includes/themes/multi-authors-list.php';
            include_once 'includes/api-request/free/request-add.php';
            include_once 'includes/rating/awpa-rating.php';
            include_once 'includes/top-rated-post.php';
            include_once AWPA_BASE_DIR . '/includes/multi-authors/wpa-multi-authors.php';
            $options = get_option('awpa_setting_options');
            if ($options) {
                if (!array_key_exists('awpa_also_visibile_in_', $options)) {
                    $options['awpa_also_visibile_in_'] = array(
                        'post' => true,
                        'page' => false,
                    );
                    update_option('awpa_setting_options', $options);
                }
            }

            // error_log(json_encode($options));
            if (!$options) {
                $options = awpa_post_author_default_options();
                update_option('awpa_setting_options', $options);
            }
        }

        public function awpa_hook_call_seeder_function()
    {
            $awpa_seed_inserted = get_option('awpa_seed_insert');
            if (!$awpa_seed_inserted) {
                include_once 'includes/database/seeder-db.php';
                update_option('awpa_seed_insert', true);
            }
        }
    } // end of the class
    add_action('plugins_loaded', array('WP_Post_Author', 'get_instance'), 0);
endif;
