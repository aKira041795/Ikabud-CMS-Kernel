<?php

/**
 * Page layout blocks section additions.
 */
require get_template_directory() . '/inc/hooks/hook-tgm.php';

/**
 * Header section.
 */
require get_template_directory() . '/inc/hooks/hook-header-section.php';


/*
 * Load front page template hook
 */

require get_template_directory() . '/inc/hooks/hook-frontpage.php';

/**
 * Exclusive posts additions.
 */
require get_template_directory() . '/inc/hooks/hook-front-page-banner-exclusive-posts.php';



/**
 * ticker additions.
 */
require get_template_directory() . '/inc/hooks/hook-front-page-banner-promotions.php';

/**
 * Post grid loop addition.
 */
require get_template_directory() . '/inc/hooks/loop/block-loop-grid.php';

/**
 * Post list loop addition.
 */
require get_template_directory() . '/inc/hooks/loop/block-loop-list.php';

/**
 * banner additions.
 */
require get_template_directory() . '/inc/hooks/hook-front-page-main-banner-section.php';

/**
 * Featured posts additions.
 */
require get_template_directory() . '/inc/hooks/hook-front-page-banner-featured-posts.php';
/**
 * Featured posts additions.
 */
require get_template_directory() . '/inc/hooks/hook-front-page-banner-featured-section.php';


/**
 * Front page section additions.
 */
require get_template_directory() . '/inc/hooks/hook-single-header.php';

/**
 * Front page section additions.
 */
require get_template_directory() . '/inc/hooks/hook-full-width-upper-footer.php';

/**
 * Front page section additions.
 */
require get_template_directory() . '/inc/hooks/hook-meta.php';

/**
 * Page layout blocks section additions.
 */
require get_template_directory() . '/inc/hooks/hook-blocks.php';

/**
 * Patterns section additions. */

require get_template_directory() . '/inc/hooks/hook-patterns.php';


/**
 * Elementor compatible class load if plugin exist
 */

// If plugin - 'Elementor' not exist then return.
// if (!class_exists('\Elementor\Plugin')) {
// 	return;
// }
