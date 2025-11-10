<?php
/**
 * Mobile toggle icon template file.
 *
 * @package zakra
 *
 * @since 3.0.0
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;
?>

<?php
$mobile_menu_label      = get_theme_mod( 'zakra_mobile_menu_text', '' );
$enable_header_button   = get_theme_mod( 'zakra_enable_mobile_header_button', '' );
$enable_header_button_2 = get_theme_mod( 'zakra_enable_mobile_header_button_2', '' );

?>

<div class="zak-toggle-menu <?php zakra_css_class( 'zakra_header_mobile_menu_toggle_class' ); ?>"

	<?php echo wp_kses_post( apply_filters( 'zakra_nav_toggle_data_attrs', '' ) ); ?>>

	<?php
	// @codingStandardsIgnoreStart
	echo apply_filters( 'zakra_before_mobile_menu_toggle', '' ); // WPCS: CSRF ok.
	// @codingStandardsIgnoreEnd
	?>

	<button class="zak-menu-toggle"
			aria-label="<?php esc_attr_e( 'Primary Menu', 'zakra' ); ?>" >

		<?php
		if ( get_theme_mod( 'zakra_enable_header_search', true ) ) {

			zakra_get_icon( 'magnifying-glass-bars' );
		} else {

			zakra_get_icon( 'bars' );
		}

		?>

	</button> <!-- /.zak-menu-toggle -->

	<nav id="zak-mobile-nav" class="<?php zakra_css_class( 'zakra_mobile_nav_class' ); ?>"

		<?php echo wp_kses_post( apply_filters( 'zakra_nav_data_attrs', '' ) ); ?>>

		<div class="zak-mobile-nav__header">
			<?php if ( get_theme_mod( 'zakra_enable_header_search', true ) ) : ?>
				<?php get_search_form(); // Header search. ?>
			<?php endif; ?>

			<!-- Mobile nav close icon. -->
			<button id="zak-mobile-nav-close" class="zak-mobile-nav-close" aria-label="<?php esc_attr_e( 'Close Button', 'zakra' ); ?>">
				<?php zakra_get_icon( 'x-mark' ); ?>
			</button>
		</div> <!-- /.zak-mobile-nav__header -->

		<?php
		wp_nav_menu(
			array(
				'theme_location' => apply_filters( 'zakra_mobile_menu_location', 'menu-primary' ),
				'menu_id'        => 'zak-mobile-menu',
				'menu_class'     => 'zak-mobile-menu',
				'container'      => '',

				'fallback_cb'    => function() {
					require get_template_directory() . '/inc/class-zakra-walker-page.php';
					$output = '<ul id="zak-mobile-menu" class="zak-mobile-menu">';

					$output .= wp_list_pages(
						array(
							'echo'               => false,
							'title_li'           => false,
							'walker'             => new Zakra_Walker_Page(),
							'has_children_class' => 'menu-item-has-children',
							'current_class'      => 'current-menu-item',
						)
					);

					$output .= '</ul>';

					// @codingStandardsIgnoreStart
					echo $output;
				},

			)
		);
		?>

			<div class="zak-mobile-menu-label">
				<?php echo esc_html( $mobile_menu_label ); ?>
			</div>

		<?php if ( class_exists( 'WooCommerce' ) || $enable_header_button || $enable_header_button_2 ) { ?>

		<div class="zak-mobile-nav__footer">

			<?php
			/**
			 * Hook - zakra_header_actions
			 *
			 * @hooked zakra_header_actions - 10
			 */
			do_action( 'zakra_header_actions' );
			?>

			<?php
			/**
			 * Hook - zakra_header_buttons
			 *
			 * @hooked zakra_header_buttons - 10
			 */
			if ( $enable_header_button || $enable_header_button_2 ) {

				do_action( 'zakra_header_buttons' );
			}
			?>
		</div> <!-- /.zak-mobile-nav__footer -->

		<?php } ?>

	</nav> <!-- /#zak-mobile-nav-->

</div> <!-- /.zak-toggle-menu -->
