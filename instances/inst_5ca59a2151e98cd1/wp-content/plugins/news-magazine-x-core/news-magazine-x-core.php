<?php

/*
Plugin Name: News Magazine X Core
Plugin URI: http://wordpress.org/plugins/news-magazine-x-core/
Description: One Click Demo Content Import.
Author: WP Royal
Author URI: https://wp-royal-themes.com/
Version: 1.0.9
License: GPLv2 or later
Text Domain: news-magazine-x-core
*/

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Currently plugin version.
 * Start at version 1.0.0 and use SemVer - https://semver.org
 * Rename this for your plugin and update it as you release new versions.
 */
define( 'NEWSX_CORE_VERSION', '1.0.9' );
define( 'NEWSX_CORE__FILE__', __FILE__ );
define( 'NEWSX_CORE_PLUGIN_BASE', plugin_basename( NEWSX_CORE__FILE__ ) );
define( 'NEWSX_CORE_PATH', plugin_dir_path( NEWSX_CORE__FILE__ ) );
define( 'NEWSX_CORE_URL', plugins_url( '/', NEWSX_CORE__FILE__ ) );

/**
 * The code that runs during plugin activation.
 * This action is documented in includes/class-newsx-core-activator.php
 */
function newsx_core_activate() {
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-newsx-core-activator.php';
	Newsx_Core_Activator::activate();
}

/**
 * The code that runs during plugin deactivation.
 * This action is documented in includes/class-newsx-core-deactivator.php
 */
function newsx_core_deactivate() {
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-newsx-core-deactivator.php';
	Newsx_Core_Deactivator::deactivate();
}

register_activation_hook( __FILE__, 'newsx_core_activate' );
register_deactivation_hook( __FILE__, 'newsx_core_deactivate' );

/**
 * The core plugin class that is used to define internationalization,
 * admin-specific hooks, and public-facing site hooks.
 */
require plugin_dir_path( __FILE__ ) . 'includes/class-newsx-core.php';

/**
 * Begins execution of the plugin.
 *
 * Since everything within the plugin is registered via hooks,
 * then kicking off the plugin from this point in the file does
 * not affect the page life cycle.
 *
 * @since    1.0.0
 */
function newsx_core_run() {

	$plugin = new Newsx_Core();
	$plugin->run();

}
newsx_core_run();

