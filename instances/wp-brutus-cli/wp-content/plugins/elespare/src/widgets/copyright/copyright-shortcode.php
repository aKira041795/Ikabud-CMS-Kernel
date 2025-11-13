<?php

namespace Elespare\Widgets;

/**
 * Exit if accessed directly.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit();
}

class Copyright_Shortcode {

	
	public function __construct() {

		add_shortcode( 'es_current_year', [ $this, 'display_current_year' ] );
		add_shortcode( 'es_site_title', [ $this, 'display_site_title' ] );
	}

	public function display_current_year() {

		$es_current_year = gmdate( 'Y' );
		$es_current_year = do_shortcode( shortcode_unautop( $es_current_year ) );
		if ( ! empty( $es_current_year ) ) {
			return $es_current_year;
		}
	}

	public function display_site_title() {

		$es_site_title = get_bloginfo( 'name' );

		if ( ! empty( $es_site_title ) ) {
			return $es_site_title;
		}
	}

}

new Copyright_Shortcode();