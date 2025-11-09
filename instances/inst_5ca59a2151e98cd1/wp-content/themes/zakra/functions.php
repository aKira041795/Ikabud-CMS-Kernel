<?php
/**
 * Zakra functions and definitions.
 *
 * @link    https://developer.wordpress.org/themes/basics/theme-functions/
 *
 * @package zakra
 *
 * @since 1.0.0
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Define constants.
 */
require get_template_directory() . '/inc/base/class-zakra-constants.php';

/**
 * Helpers functions.
 */
require ZAKRA_PARENT_INC_DIR . '/helper/utils.php';

/**
 * Base.
 */
// Generate WordPress filter hook dynamically.
require ZAKRA_PARENT_INC_DIR . '/base/class-zakra-dynamic-filter.php';

// Adds classes to appropriate places.
require ZAKRA_PARENT_INC_DIR . '/base/class-zakra-css-classes.php';

// Generate dynamic CSS from styling options.
require ZAKRA_PARENT_INC_DIR . '/base/class-zakra-dynamic-css.php';

/**
 * Core.
 */
// After setup theme.
require ZAKRA_PARENT_INC_DIR . '/core/class-zakra-after-setup-theme.php';

// Load scripts.
require ZAKRA_PARENT_INC_DIR . '/core/class-zakra-enqueue-scripts.php';

// Widget-related functionalities.
require ZAKRA_PARENT_INC_DIR . '/core/class-zakra-widgets.php';

// Header Media.
require ZAKRA_PARENT_INC_DIR . '/core/custom-header.php';

/**
 * Update migrations.
 */
require ZAKRA_PARENT_INC_DIR . '/migration/class-zakra-migration.php';

/**
 * Customizer.
 */
require ZAKRA_PARENT_INC_DIR . '/customizer/class-zakra-customizer.php';

/**
 * Deprecated.
 */
require ZAKRA_PARENT_INC_DIR . '/deprecated/deprecated-filters.php';
require ZAKRA_PARENT_INC_DIR . '/deprecated/deprecated-functions.php';
require ZAKRA_PARENT_INC_DIR . '/deprecated/deprecated-hooks.php';

/**
 * Override global builder by page setting.
 */
require ZAKRA_PARENT_INC_DIR . '/meta-boxes/builder-meta-box.php';

/**
 * Templates.
 */
require ZAKRA_PARENT_INC_DIR . '/template-tags.php';
require ZAKRA_PARENT_INC_DIR . '/builder-template-tags.php';
require ZAKRA_PARENT_INC_DIR . '/template-functions.php';

// Template hooks.
require ZAKRA_PARENT_DIR . '/template-parts/hooks/hook-functions.php';

require ZAKRA_PARENT_DIR . '/template-parts/hooks/header/header.php';
require ZAKRA_PARENT_DIR . '/template-parts/hooks/header/top-bar.php';
require ZAKRA_PARENT_DIR . '/template-parts/hooks/header/header-main.php';
require ZAKRA_PARENT_DIR . '/template-parts/hooks/header/primary-menu.php';
require ZAKRA_PARENT_DIR . '/template-parts/hooks/header/header-actions.php';
require ZAKRA_PARENT_DIR . '/template-parts/hooks/header/header-buttons.php';
require ZAKRA_PARENT_DIR . '/template-parts/hooks/header/transparent-header.php';
require ZAKRA_PARENT_DIR . '/template-parts/hooks/header/header-media.php';

require ZAKRA_PARENT_DIR . '/template-parts/hooks/page-header/page-header.php';

require ZAKRA_PARENT_DIR . '/template-parts/hooks/content/content.php';
require ZAKRA_PARENT_DIR . '/template-parts/hooks/blog/blog.php';

require ZAKRA_PARENT_DIR . '/template-parts/hooks/footer/footer.php';

require ZAKRA_PARENT_DIR . '/template-parts/hooks/footer/scroll-to-top.php';
require ZAKRA_PARENT_DIR . '/template-parts/hooks/footer/footer-widgets.php';
require ZAKRA_PARENT_DIR . '/template-parts/hooks/footer/footer-bar.php';

require ZAKRA_PARENT_INC_DIR . '/hooks/hooks.php';
require ZAKRA_PARENT_INC_DIR . '/hooks/content.php';
require ZAKRA_PARENT_INC_DIR . '/hooks/customize.php';

require ZAKRA_PARENT_DIR . '/template-parts/hooks/builder.php';

/**
 * Plugins compatibility.
 */
// AMP.
if ( defined( 'AMP__VERSION' ) && ( ! version_compare( AMP__VERSION, '1.0.0', '<' ) ) ) {

	require_once ZAKRA_PARENT_INC_DIR . '/compatibility/amp/class-zakra-amp.php';
}

// Wishlist.
if ( class_exists( 'woocommerce' ) && defined( 'YITH_WCWL' ) ) {

		require ZAKRA_PARENT_INC_DIR . '/compatibility/yith/yith-wishlist.php';
}

// QuickView.
if ( class_exists( 'woocommerce' ) && defined( 'YITH_WCQV' ) ) {

	require ZAKRA_PARENT_INC_DIR . '/compatibility/yith/yith-quickview.php';
}

if ( defined( 'JETPACK__VERSION' ) ) {

	require ZAKRA_PARENT_INC_DIR . '/compatibility/jetpack/class-zakra-jetpack.php';
}

// WooCommerce.
if ( class_exists( 'WooCommerce' ) ) {

	require ZAKRA_PARENT_INC_DIR . '/compatibility/woocommerce/class-zakra-woocommerce.php';
}

// Elementor Pro.
require_once ZAKRA_PARENT_INC_DIR . '/compatibility/elementor/class-zakra-elementor-pro.php';

// Elementor Header Footer.
require_once ZAKRA_PARENT_INC_DIR . '/compatibility/elementor-header-footer/class-zakra-elementor-header-footer.php';

// Breadcrumbs class.
require_once ZAKRA_PARENT_INC_DIR . '/class-breadcrumb-trail.php';

// Svg icon class.
require_once ZAKRA_PARENT_INC_DIR . '/class-zakra-svg-icons.php';

// Load customind.
require_once ZAKRA_PARENT_INC_DIR . '/customizer/customind/init.php';

/**
 * @var \customizer\customind\core\Customind
 */
global $customind;
$customind->set_css_var_prefix( 'zakra' );
$customind->set_i18n_data(
	[
		'domain' => 'zakra',
	]
);
add_action(
	'after_setup_theme',
	function () use ( $customind ) {
		$customind->set_section_i18n(
			[
				/* Translators: 1: Panel Title. */
				'customizing-action' => __( 'Customizing ▶ %s', 'zakra' ),
				'customizing'        => __( 'Customizing', 'zakra' ),
			]
		);
	}
);

require ZAKRA_PARENT_INC_DIR . '/meta-boxes/class-zakra-meta-box.php';

// Admin screen.
require ZAKRA_PARENT_INC_DIR . '/admin/class-zakra-changelog-controller.php';
require ZAKRA_PARENT_INC_DIR . '/admin/class-zakra-admin.php';
if ( is_admin() ) {

	// Meta boxes.
	require ZAKRA_PARENT_INC_DIR . '/meta-boxes/class-zakra-meta-box-page-settings.php';

	// Theme options page.
	require ZAKRA_PARENT_INC_DIR . '/admin/class-zakra-notice.php';
	require ZAKRA_PARENT_INC_DIR . '/admin/class-zakra-welcome-notice.php';
	//  require ZAKRA_PARENT_INC_DIR . '/admin/class-zakra-upgrade-notice.php';
	require ZAKRA_PARENT_INC_DIR . '/admin/class-zakra-dashboard.php';
	require ZAKRA_PARENT_INC_DIR . '/admin/class-zakra-theme-review-notice.php';
	require ZAKRA_PARENT_INC_DIR . '/admin/class-zakra-demo-import-migration-notice.php';
	require ZAKRA_PARENT_INC_DIR . '/admin/class-zakra-pro-minimum-version-notice.php';
}

// Set default content width.
if ( ! isset( $content_width ) ) {

	$content_width = 812;
}

// Calculate $content_width value according to layout options from Customizer and meta boxes.
function zakra_content_width_rdr() {

	global $content_width;

	// Get layout type.
	$layout_type     = zakra_get_layout_type();
	$layouts_sidebar = [ 'left', 'right' ];

	/**
	 * Calculate content width.
	 */

	// Get required values from Customizer.
	$container_width = get_theme_mod(
		'zakra_container_width',
		[
			'size' => 1170,
			'unit' => 'px',
		]
	);
	$sidebar_width   = get_theme_mod(
		'zakra_sidebar_width',
		[
			'size' => 30,
			'unit' => '%',
		]
	);

	$container_width = isset( $container_width['size'] ) ? (int) $container_width['size'] : 1160;
	$content_width   = isset( $sidebar_width['size'] ) ? ( 100 - (float) $sidebar_width['size'] ) : 70;

	// Calculate Padding to reduce.
	$container_style = get_theme_mod( 'zakra_content_area_layout', 'bordered' );
	$content_padding = ( 'boxed' === $container_style ) ? 120 : 60;

	if ( in_array( $layout_type, $layouts_sidebar, true ) ) {

		$content_width = ( ( $container_width * $content_width ) / 100 ) - $content_padding;
	} else {

		$content_width = $container_width - $content_padding;
	}
}

add_action( 'template_redirect', 'zakra_content_width_rdr' );

function zakra_is_plugin_installed( $plugin_path ) {
	$plugins = get_plugins();
	return isset( $plugins[ $plugin_path ] );
}

function zakra_maybe_enable_builder() {

	if ( get_option( 'zakra_builder_migration' ) || get_option( 'zakra_maybe_enable_builder' ) ) {
		return true;
	}

	if ( get_option( 'zakra_stretched_style_transfer' ) || get_option( 'zakra_migrations' ) || get_option( 'zakra_customizer_migration_v3' ) ) {
		return false;
	}

	update_option( 'zakra_maybe_enable_builder', true );

	return true;
}


function zakra_demo_importer_route_url() {
	return ( zakra_is_zakra_pro_active() && zakra_plugin_version_compare( 'zakra-pro/zakra-pro.php', '3.1.0', '>=' ) )
		? 'admin.php?page=zakra-starter-templates'
		: 'themes.php?page=zakra-starter-templates';
}

add_filter( 'themegrill_demo_importer_routes', 'zakra_demo_importer_routes', 10, 1 );
function zakra_demo_importer_routes( $routes ) {
	$base_url = zakra_demo_importer_route_url();

	// Remove the existing routes from the TDI
	$routes_to_remove = [
		'themes.php?page=zakra-starter-templates&demo=:slug',
		'themes.php?page=zakra-starter-templates&browse=:sort',
		'themes.php?page=zakra-starter-templates&search=:query',
		'themes.php?page=zakra-starter-templates',
	];
	foreach ( $routes_to_remove as $route ) {
		unset( $routes[ $route ] );
	}

	// Add the new routes
	$new_routes = [
		"$base_url&demo=:slug"    => 'preview',
		"$base_url&browse=:sort"  => 'sort',
		"$base_url&search=:query" => 'search',
		$base_url                 => 'sort',
	];
	$routes     = array_merge( $routes, $new_routes );

	return $routes;
}

add_filter( 'themegrill_demo_importer_baseURL', 'zakra_demo_importer_baseURL', 10, 1 );
function zakra_demo_importer_baseURL( $base_url ) {
	return zakra_demo_importer_route_url();
}

add_filter( 'themegrill_demo_importer_redirect_link', 'zakra_demo_importer_redirect_url' );
function zakra_demo_importer_redirect_url( $redirect_url ) {
	return admin_url( zakra_demo_importer_route_url() . '&browse=all' );
}
