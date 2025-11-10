<?php

/**
 * Plugin Name: TemplateSpare – Build Your Website in Minutes, Not Months
 * Plugin URI: https://templatespare.com/?uri=plugin
 * Description: No more endless design tweaks, plugin hunts, or migration nightmares. TemplateSpare simplifies your WordPress experience with 1-click imports, 1000+ professional demos, and seamless export options—making site-building fast, easy, and fun.
 * Version: 3.2.0.1
 * Author:            TemplateSpare
 * Author URI:        https://templatespare.com/
 * Text Domain:       templatespare
 * License:           GPLv3 or later
 * License URI:       https://www.gnu.org/licenses/gpl.html
 */

/**
 * Exit if accessed directly
 */
if (!defined('ABSPATH')) {
  exit;
}

defined('AFTMLS_BASE_FILE') or define('AFTMLS_BASE_FILE', __FILE__);
defined('AFTMLS_PLUGIN_BASE') or define('AFTMLS_PLUGIN_BASE', plugin_basename(AFTMLS_BASE_FILE));
defined('AFTMLS_BASE_DIR') or define('AFTMLS_BASE_DIR', dirname(AFTMLS_BASE_FILE));
defined('AFTMLS_PLUGIN_URL') or define('AFTMLS_PLUGIN_URL', plugin_dir_url(__FILE__));
define('AFTMLS_PLUGIN_DIR', plugin_dir_path(__FILE__));

if (!function_exists('templatespare_main_plugin_file')) {
  /**
   * Returns the full path and filename of the main Afthemes Templates  plugin file.
   *
   * @return string
   */
  function templatespare_main_plugin_file()
  {
    return __FILE__;
  }

  // Load the rest of the plugin.
  //require_once plugin_dir_path( __FILE__ ) . 'loader.php';

  $aftmls_includes_dir = AFTMLS_PLUGIN_DIR . 'includes/';
  require $aftmls_includes_dir . 'templatespare-kit.php';
  require_once $aftmls_includes_dir . 'layouts/demo-data-lists.php';
  require_once $aftmls_includes_dir . 'layouts/theme-bundle-list.php';
  require $aftmls_includes_dir . 'init.php';
  require $aftmls_includes_dir . 'companion/class-aftc-main.php';

  // Instantiate the main plugin class *Singleton*.
  $AFMLS_Companion = AFTMLS_Companion::getInstance();

  /**
   * Layout Component Registry.
   */
  if (PHP_VERSION_ID >= 50600) {
    require_once AFTMLS_PLUGIN_DIR . 'includes/layouts/layout-endpoints.php';
  }

  /**
   * Freemius.
   */
  require_once(AFTMLS_PLUGIN_DIR . '/freemius.php');
}
add_action('init', 'templatespare_main_plugin_file');

function templatespare_activation_redirect($plugin)
{

  if (defined('DOING_AJAX') && DOING_AJAX) {
    // If activation is done via AJAX, don't perform redirection here
    return;
  }

  // if ($plugin == plugin_basename(AFTMLS_BASE_FILE)) {
  //   $redirect_url = add_query_arg(array('page' => 'wizard-page'), admin_url('admin.php'));
  //   $redirect_url = esc_url_raw($redirect_url); // Sanitize the URL

  //   if (wp_safe_redirect($redirect_url)) {
  //     exit;
  //   } else {
  //     // Redirect failed, handle error gracefully
  //     error_log('Redirect failed after plugin activation: ' . $plugin);
  //   }
  // }
}

add_action('activated_plugin', 'templatespare_activation_redirect');
