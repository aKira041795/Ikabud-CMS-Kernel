<?php
/**
 * Page header hooks.
 *
 * @package zakra
 *
 * TODO: @since
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/*========================================= Hooks > Page Header ==========================================*/

if ( ! function_exists( 'zakra_page_header' ) ) :

	/**
	 * Page header.
	 */
	function zakra_page_header() {

		get_template_part( 'template-parts/page-header/page', 'header' );
	}
endif;

add_action( 'zakra_page_header', 'zakra_page_header' );


if ( ! function_exists( 'zakra_breadcrumbs' ) ) :

	/**
	 * Breadcrumbs.
	 */
	function zakra_breadcrumbs() {
		?>
		<div class="zak-breadcrumbs">

			<?php
			breadcrumb_trail(
				array(
					'show_browse' => false,
				)
			);
			?>

		</div> <!-- /.zak-breadcrumbs -->
		<?php
	}
endif;

add_action( 'zakra_action_breadcrumbs', 'zakra_breadcrumbs', 10 );
