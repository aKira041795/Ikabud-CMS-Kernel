<?php

/**
 * Fired during plugin activation
 *
 * @link       https://https://wp-royal-themes.com/
 * @since      1.0.0
 *
 * @package    Newsx_Core
 * @subpackage Newsx_Core/includes
 */

/**
 * Fired during plugin activation.
 *
 * This class defines all code necessary to run during the plugin's activation.
 *
 * @since      1.0.0
 * @package    Newsx_Core
 * @subpackage Newsx_Core/includes
 * @author     WP Royal <info.wproyal@gmail.com>
 */
class Newsx_Core_Activator {

	/**
	 * Short Description. (use period)
	 *
	 * Long Description.
	 *
	 * @since    1.0.0
	 */
	public static function activate() {

		// Delete Kirki Font Transients
		Newsx_Core_Helpers::delete_kirki_font_transients();
		
	}

}
