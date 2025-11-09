<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// Define constants
require get_parent_theme_file_path( '/includes/core/newsx-constants.php' );

// Admin Helper Functions
// This is not inside is_admin() because we need it in Widgets as well
require_once NEWSX_INCLUDES_DIR .'/admin/helpers/helper-functions.php';

if ( is_admin() ) {
	// Admin Menu
	require_once NEWSX_INCLUDES_DIR .'/admin/menu/class-newsx-admin-menu.php';

	// AJAX Actions
	require_once NEWSX_INCLUDES_DIR .'/actions/class-newsx-ajax-admin.php';

	// Welcome Notice
	require_once NEWSX_INCLUDES_DIR .'/admin/notices/class-newsx-welcome-notice.php';

	// Theme Review Notice
	require_once NEWSX_INCLUDES_DIR .'/admin/notices/class-newsx-theme-review-notice.php';
}

// Kirki Framework
require_once NEWSX_CUSTOMIZER_DIR .'/kirki/kirki.php';

// Theme Customizer
require_once NEWSX_CUSTOMIZER_DIR .'/customizer.php';

// Theme Default
require_once NEWSX_INCLUDES_DIR .'/base/theme-defaults.php';

// Extra Filters
require_once NEWSX_INCLUDES_DIR .'/extra-filters.php';

// Utilities
require_once NEWSX_INCLUDES_DIR .'/helpers/utilities.php';

// Custom Functions
require_once NEWSX_INCLUDES_DIR .'/custom-functions.php';

// Register Sidebars
require_once NEWSX_INCLUDES_DIR .'/widgets/register-sidebars.php';

// Dynamic CSS
require_once NEWSX_INCLUDES_DIR .'/base/dynamic-css.php';

// Google Fonts
require_once NEWSX_INCLUDES_DIR .'/base/google-fonts.php';

// Enqueue scripts and styles
require_once NEWSX_INCLUDES_DIR .'/base/enqueue-scripts.php';

// Theme Setup
require_once NEWSX_INCLUDES_DIR .'/core/after-theme-setup.php';

// AJAX Actions
require_once NEWSX_INCLUDES_DIR .'/actions/class-newsx-ajax-public.php';
