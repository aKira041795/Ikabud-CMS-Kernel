<?php

/**
 * Bloghash Customizer sanitization callback functions.
 *
 * @package     Bloghash
 * @author      Peregrine Themes
 * @since       1.0.0
 */

/**
 * Do not allow direct script access.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Customizer callback for the Site Title.
 *
 * @since 1.0.0
 */
function bloghash_partial_blogname() {
	return get_bloginfo( 'name', 'display' );
}

/**
 * Customizer callback for the Site Tagline.
 *
 * @since 1.0.0
 */
function bloghash_partial_blogdescription() {
	return get_bloginfo( 'description', 'display' );
}
