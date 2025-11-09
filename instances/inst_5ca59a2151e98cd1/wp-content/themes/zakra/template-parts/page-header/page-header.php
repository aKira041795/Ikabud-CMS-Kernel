<?php
/**
 * Page header template file.
 *
 * @package zakra
 *
 * TODO: @since.
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;
?>

<?php
$page_header_meta = get_post_meta( zakra_get_post_id(), 'zakra_page_header' );
$page_header_enable = get_theme_mod('zakra_enable_page_header', true);

/**
 * Return if,
 *  Page header doesn't have content (page title or breadcrumbs), OR
 *  Page header is not enabled in page settings.
 */
if (
	( 'page-header' !== zakra_page_title_position() && ! zakra_is_breadcrumbs_enabled() ) ||
	( isset( $page_header_meta[0] ) && ! $page_header_meta[0] )
) {

	return;
}

// Return if it's front page with the latest Posts.
if ( is_front_page() && is_home() ) {

	return;
}

$layout = get_theme_mod( 'zakra_page_header_layout', 'style-1' );
$layout = 'zak-' . $layout;

$style = apply_filters(
	'zakra_page_title_align_filter',
	$layout
);


do_action( 'zakra_before_page_header' );

/**
 * If,
 *  WooCommerce pages AND
 *  Page title in content area, OR
 *  Static front page with page builder template.
 */
if (
	( zakra_is_woocommerce_active() && function_exists( 'is_woocommerce' ) && is_woocommerce() ) &&
	'content-area' === get_theme_mod( 'zakra_page_title_position', 'page-header' ) ||
	( is_front_page() && is_page_template( 'page-templates/pagebuilder.php' ) )
) {

	return;
}
?>

<?php
if ( $page_header_enable ) :
?>

<div class="zak-page-header <?php echo esc_attr( $style ); ?>">
	<div class="<?php zakra_css_class( 'zakra_page_header_container_class' ); ?>">
		<div class="zak-row">
			<?php
			if ( 'page-header' === zakra_page_title_position() ) {

				zakra_page_title();
			}

			// Page header breadcrumb.
			if ( function_exists( 'breadcrumb_trail' ) && zakra_is_breadcrumbs_enabled() ) {

				// Use WooCommerce breadcrumb.
				if ( zakra_is_woocommerce_active() && function_exists( 'is_woocommerce' ) && is_woocommerce() ) {

					// Show WC breadcrumb on page header.
					if ( 'page-header' === get_theme_mod( 'zakra_page_title_position', 'page-header' ) ) {

						// Remove Breadcrumb from content.
						remove_action( 'woocommerce_before_main_content', 'woocommerce_breadcrumb', 20, 0 );

						// Make WC breadcrumb with the theme.
						woocommerce_breadcrumb(
							array(
								'wrap_before' => '<nav role="navigation" aria-label="' . esc_html__( 'Breadcrumbs', 'zakra' ) . '" class="breadcrumb-trail breadcrumbs"><ul class="trail-items">',
								'wrap_after'  => '</ul></nav>',
								'before'      => '<li class="trail-item">',
								'after'       => '</li>',
								'delimiter'   => '',
							)
						);
					}
				} else { // Theme breadcrumb.

					/**
					 * Hook - zakra_action_breadcrumbs
					 *
					 * @hooked zakra_breadcrumbs - 10
					 */
					do_action( 'zakra_action_breadcrumbs' );
				}
			}
			?>
		</div> <!-- /.zak-row-->
	</div> <!-- /.zak-container-->
</div>
<!-- /.page-header -->
<?php
endif;
