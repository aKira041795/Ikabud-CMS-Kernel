<?php
/**
 * Bloghash Customizer widgets class.
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

if ( ! class_exists( 'Bloghash_Customizer_Widget_Nav' ) ) :

	/**
	 * Bloghash Customizer widget class
	 */
	class Bloghash_Customizer_Widget_Nav extends Bloghash_Customizer_Widget {

		/**
		 * Primary class constructor.
		 *
		 * @since 1.0.0
		 * @param array $args An array of the values for this widget.
		 */
		public function __construct( $args = array() ) {

			$values = array(
				'menu'       => '',
				'visibility' => 'all',
			);

			$args['values'] = isset( $args['values'] ) ? wp_parse_args( $args['values'], $values ) : $values;

			parent::__construct( $args );

			$this->name        = __( 'Navigation', 'bloghash' );
			$this->description = __( 'Add a navigation menu.', 'bloghash' );
			$this->icon        = 'dashicons dashicons-menu';
			$this->type        = 'nav';
		}

		/**
		 * Displays the form for this widget on the Widgets page of the WP Admin area.
		 *
		 * @since 1.0.0
		 * @return void
		 */
		public function form() {

			$menus = wp_get_nav_menus();

			$has_menu_class = ! empty( $menus ) ? 'bloghash-widget-nav-has-menu' : '';
			?>
			<div class="bloghash-widget-nav-container <?php echo esc_attr( $has_menu_class ); ?>">
				<p class="bloghash-widget-nav-selected">
					<label for="widget-nav-<?php echo esc_attr( $this->id ); ?>-<?php echo esc_attr( $this->number ); ?>-menu">
						<?php echo esc_html_x( 'Select Menu:', 'Widget', 'bloghash' ); ?>
					</label>

					<select id="widget-nav-<?php echo esc_attr( $this->id ); ?>-<?php echo esc_attr( $this->number ); ?>-menu" name="widget-nav-<?php echo esc_attr( $this->number ); ?>-menu" data-option-name="menu">
						<option value="0"><?php echo esc_html_x( '&mdash; Select &mdash;', 'Widget', 'bloghash' ); ?></option>

						<?php if ( ! empty( $menus ) ) { ?>
							<?php foreach ( $menus as $menu ) { ?>
							<option value="<?php echo esc_attr( $menu->slug ); ?>" <?php selected( $this->values['menu'], $menu->slug ); ?>>
								<?php echo esc_html( $menu->name ); ?>
							</option>
							<?php } ?>
						<?php } ?>
					</select>
				</p>

				<p class="bloghash-widget-nav-empty">
					<?php
					printf(
						/* Translators: %1$s - anchor tag start. %2$s anchor tag end. */
						esc_html__( 'No menus found: %1$sCreate a new menu?%2$s', 'bloghash' ),
						'<a href="' . esc_url( admin_url( 'nav-menus.php' ) ) . '">',
						'</a>'
					);
					?>
				</p>

			</div>
			<?php
		}
	}
endif;
