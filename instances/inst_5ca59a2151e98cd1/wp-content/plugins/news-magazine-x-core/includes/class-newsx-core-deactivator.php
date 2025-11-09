<?php

/**
 * Fired during plugin deactivation
 *
 * @link       https://https://wp-royal-themes.com/
 * @since      1.0.0
 *
 * @package    Newsx_Core
 * @subpackage Newsx_Core/includes
 */

/**
 * Fired during plugin deactivation.
 *
 * This class defines all code necessary to run during the plugin's deactivation.
 *
 * @since      1.0.0
 * @package    Newsx_Core
 * @subpackage Newsx_Core/includes
 * @author     WP Royal <info.wproyal@gmail.com>
 */
class Newsx_Core_Deactivator {

	/**
	 * Short Description. (use period)
	 *
	 * Long Description.
	 *
	 * @since    1.0.0
	 */
	public static function deactivate() {

		// Delete Kirki Font Transients
		Newsx_Core_Helpers::delete_kirki_font_transients();
		
	}

}
