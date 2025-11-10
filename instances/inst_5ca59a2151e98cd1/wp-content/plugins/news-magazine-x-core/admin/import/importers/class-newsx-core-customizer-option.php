<?php
/**
 * A class that extends WP_Customize_Setting so we can access
 * the protected updated method when importing options.
 *
 * Used in the Customizer importer.
 */

final class Newsx_Core_Customizer_Option extends \WP_Customize_Setting {
	/**
	 * Import an option value for this setting.
	 */
	public function import( $value ) {
		$this->update( $value );
	}
}
