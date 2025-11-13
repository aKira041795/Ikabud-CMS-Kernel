<?php
    /*
     * Plugin Name:       BlockSpare - Gutenberg Site Builder Blocks & Starter Sites
     * Plugin URI:        https://blockspare.com/
     * Description:       Gutenberg Blocks & Patterns for Blogs, Magazines, Business Sites – Post Grids, Sliders, Carousels, Counters, Page Builder & Starter Site Imports, No Coding Needed
     * Version:           3.2.14
     * Author:            BlockSpare
     * Author URI:        https://blockspare.com/
     * Text Domain:       blockspare
     * License:           GPL-2.0+
     * License URI:       http://www.gnu.org/licenses/gpl-2.0.html
     */
    
    defined('ABSPATH') or die('No script kiddies please!');  // prevent direct access
    
                 /**
                 * Define global constants
                 **/
                defined('BLOCKSPARE_BASE_FILE') or define('BLOCKSPARE_BASE_FILE', __FILE__);
                defined('BLOCKSPARE_PLUGIN_BASE') or define('BLOCKSPARE_PLUGIN_BASE', plugin_basename( BLOCKSPARE_BASE_FILE ) );
                defined('BLOCKSPARE_BASE_DIR') or define('BLOCKSPARE_BASE_DIR', dirname(BLOCKSPARE_BASE_FILE));
                defined('BLOCKSPARE_PLUGIN_URL') or define('BLOCKSPARE_PLUGIN_URL', plugin_dir_url(__FILE__));
                defined('BLOCKSPARE_PLUGIN_DIR') or define('BLOCKSPARE_PLUGIN_DIR', plugin_dir_path(__FILE__));
                defined('BLOCKSPARE_PRO_PATH') || define('BLOCKSPARE_PRO_PATH','https://www.blockspare.com/');
                defined('BLOCKSPARE_SHOW_PRO_NOTICES' ) || define('BLOCKSPARE_SHOW_PRO_NOTICES', true );
                defined('BLOCKSPARE_VERSION' ) || define('BLOCKSPARE_VERSION',  '3.2.14');


                if ( ! version_compare( PHP_VERSION, '5.6', '>=' ) ) {
                    add_action( 'admin_notices', 'blockspare_fail_php_version' );
                } elseif ( ! version_compare( get_bloginfo( 'version' ), '4.7', '>=' ) ) {
                    add_action( 'admin_notices', 'blockspare_fail_wp_version' );
                } else {
                    /**
                 * Freemius.
                 */
                require_once(BLOCKSPARE_PLUGIN_DIR.'/freemius.php');

                /**
                 * Plugin init and welcome.
                 */
                
                include_once BLOCKSPARE_PLUGIN_DIR. 'inc/init.php';
                include_once BLOCKSPARE_PLUGIN_DIR.'inc/welcome.php';
                include_once BLOCKSPARE_PLUGIN_DIR.'inc/fonts.php';
                }


                function blockspare_fail_php_version() {
                    /* translators: %s: PHP version */
                    $message      = sprintf( esc_html__( 'BlockSpare for Gutenberg requires PHP version %s+, plugin is currently NOT RUNNING.', 'blockspare' ), '5.6' );
                    $html_message = sprintf( '<div class="error">%s</div>', wpautop( $message ) );
                    echo wp_kses_post( $html_message );
                }

                function blockspare_fail_wp_version() {
                    /* translators: %s: WordPress version */
                    $message      = sprintf( esc_html__( 'BlockSpare for Gutenberg requires WordPress version %s+. Because you are using an earlier version, the plugin is currently NOT RUNNING.', 'blockspare' ), '4.7' );
                    $html_message = sprintf( '<div class="error">%s</div>', wpautop( $message ) );
                    echo wp_kses_post( $html_message );
                }