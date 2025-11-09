<?php
/**
 * Bloghash Customizer info control class.
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

if ( ! class_exists( 'Bloghash_Customizer_Control_Info' ) ) :
	/**
	 * Bloghash Customizer info control class.
	 */
	class Bloghash_Customizer_Control_Info extends Bloghash_Customizer_Control {

		/**
		 * The control type.
		 *
		 * @var string
		 */
		public $type = 'bloghash-info';

		/**
		 * Custom URL.
		 *
		 * @since  1.0.0
		 * @var    string
		 */
		public $url = '';

		/**
		 * Link target.
		 *
		 * @since  1.0.0
		 * @var    string
		 */
		public $target = '_blank';

		/**
		 * Enqueue control related scripts/styles.
		 *
		 * @access public
		 */
		public function enqueue() {

			// Script debug.
			$bloghash_suffix = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';

			// Control type.
			$bloghash_type = str_replace( 'bloghash-', '', $this->type );

			/**
			 * Enqueue control stylesheet
			 */
			wp_enqueue_style(
				'bloghash-' . $bloghash_type . '-control-style',
				BLOGHASH_THEME_URI . '/inc/customizer/controls/' . $bloghash_type . '/' . $bloghash_type . $bloghash_suffix . '.css',
				false,
				BLOGHASH_THEME_VERSION,
				'all'
			);
		}

		/**
		 * Refresh the parameters passed to the JavaScript via JSON.
		 *
		 * @see WP_Customize_Control::to_json()
		 */
		public function to_json() {
			parent::to_json();

			$this->json['url']    = $this->url;
			$this->json['target'] = $this->target;
		}

		/**
		 * An Underscore (JS) template for this control's content (but not its container).
		 *
		 * Class variables for this control class are available in the `data` JS object;
		 * export custom variables by overriding {@see WP_Customize_Control::to_json()}.
		 *
		 * @see WP_Customize_Control::print_template()
		 */
		protected function content_template() {
			?>
			<div class="bloghash-info-wrapper bloghash-control-wrapper">

				<# if ( data.label ) { #>
					<span class="bloghash-control-heading customize-control-title bloghash-field">{{{ data.label }}}</span>
				<# } #>

				<# if ( data.description ) { #>
					<div class="description customize-control-description bloghash-field bloghash-info-description">{{{ data.description }}}</div>
				<# } #>

				<a href="{{ data.url }}" class="button button-primary" target="{{ data.target }}" rel="noopener noreferrer"><?php esc_html_e( 'Learn More', 'bloghash' ); ?></a>

			</div><!-- END .bloghash-control-wrapper -->
			<?php
		}

	}
endif;
