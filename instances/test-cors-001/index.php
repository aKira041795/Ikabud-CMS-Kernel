<?php
/**
 * Front to the WordPress application. This file does not do anything, but loads
 * wp-blog-header.php which does and tells WordPress to load the theme.
 *
 * @package WordPress
 */

/**
 * Tells WordPress to load the WordPress theme and output it.
 *
 * @var bool
 */
define( 'WP_USE_THEMES', true );

// Ensure we load the instance-specific wp-config.php
if ( ! file_exists( __DIR__ . '/wp-config.php' ) ) {
    die( 'wp-config.php not found in instance directory' );
}

/** Loads the WordPress Environment and Template */
require __DIR__ . '/wp-blog-header.php';
