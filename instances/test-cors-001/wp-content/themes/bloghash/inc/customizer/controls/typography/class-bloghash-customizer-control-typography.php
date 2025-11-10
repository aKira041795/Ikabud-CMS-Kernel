<?php
/**
 * Bloghash Customizer custom typography control class.
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

if ( ! class_exists( 'Bloghash_Customizer_Control_Typography' ) ) :
	/**
	 * Bloghash Customizer custom typography control class.
	 */
	class Bloghash_Customizer_Control_Typography extends Bloghash_Customizer_Control {

		/**
		 * The control type.
		 *
		 * @var string
		 */
		public $type = 'bloghash-typography';

		/**
		 * The control type.
		 *
		 * @var string
		 */
		public $display = array();

		/**
		 * Set the default typography options.
		 *
		 * @since 1.0.0
		 * @param WP_Customize_Manager $manager Customizer bootstrap instance.
		 * @param string               $id      Control ID.
		 * @param array                $args    Default parent's arguments.
		 */
		public function __construct( $manager, $id, $args = array() ) {

			$this->display = array(
				'font-family'     => array(),
				'font-subsets'    => array(),
				'font-weight'     => array(),
				'font-style'      => array(),
				'text-transform'  => array(),
				'letter-spacing'  => array(),
				'text-decoration' => array(),
				'font-size'       => array(),
				'line-height'     => array(),
			);

			parent::__construct( $manager, $id, $args );
		}

		/**
		 * Enqueue control related scripts/styles.
		 *
		 * @access public
		 */
		public function enqueue() {

			parent::enqueue();

			wp_localize_script(
				$this->type . '-js',
				'bloghash_typography_vars',
				array(
					'fonts'   => bloghash()->fonts->get_fonts(),
					'default' => bloghash()->fonts->get_default_system_font(),
				)
			);

			// Script debug.
			$bloghash_suffix = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';

			/**
			 * Enqueue select2 stylesheet.
			 */
			wp_enqueue_style(
				'bloghash-select2-style',
				BLOGHASH_THEME_URI . '/inc/admin/assets/css/select2' . $bloghash_suffix . '.css',
				false,
				BLOGHASH_THEME_VERSION,
				'all'
			);

			/**
			 * Enqueue select2 script.
			 */
			wp_enqueue_script(
				'bloghash-select2-js',
				BLOGHASH_THEME_URI . '/inc/admin/assets/js/libs/select2' . $bloghash_suffix . '.js',
				array( 'jquery' ),
				BLOGHASH_THEME_VERSION,
				true
			);
		}

		/**
		 * Refresh the parameters passed to the JavaScript via JSON.
		 *
		 * @see WP_Customize_Control::to_json()
		 */
		public function to_json() {
			parent::to_json();

			$this->json['display'] = $this->display;

			$this->json['l10n'] = array(
				'advanced'        => esc_html__( 'Advanced', 'bloghash' ),
				'font-family'     => esc_html__( 'Font Family', 'bloghash' ),
				'font-subsets'    => esc_html__( 'Languages', 'bloghash' ),
				'font-weight'     => esc_html__( 'Weight', 'bloghash' ),
				'font-size'       => esc_html__( 'Size', 'bloghash' ),
				'font-style'      => esc_html__( 'Style', 'bloghash' ),
				'text-transform'  => esc_html__( 'Transform', 'bloghash' ),
				'text-decoration' => esc_html__( 'Decoration', 'bloghash' ),
				'line-height'     => esc_html__( 'Line Height', 'bloghash' ),
				'letter-spacing'  => esc_html__( 'Letter Spacing', 'bloghash' ),
				'inherit'         => esc_html__( 'Inherit', 'bloghash' ),
				'default'         => esc_html__( 'Default System Font', 'bloghash' ),
				'weights'         => array(
					'inherit'   => esc_html__( 'Inherit', 'bloghash' ),
					'100'       => esc_html__( 'Thin 100', 'bloghash' ),
					'100italic' => esc_html__( 'Thin 100 Italic', 'bloghash' ),
					'200'       => esc_html__( 'Extra-Thin 200', 'bloghash' ),
					'200italic' => esc_html__( 'Extra-Thin 200 Italic', 'bloghash' ),
					'300'       => esc_html__( 'Light 300', 'bloghash' ),
					'300italic' => esc_html__( 'Light 300 Italic', 'bloghash' ),
					'400'       => esc_html__( 'Normal 400', 'bloghash' ),
					'400italic' => esc_html__( 'Normal 400 Italic', 'bloghash' ),
					'500'       => esc_html__( 'Medium 500', 'bloghash' ),
					'500italic' => esc_html__( 'Medium 500 Italic', 'bloghash' ),
					'600'       => esc_html__( 'Semi-Bold 600', 'bloghash' ),
					'600italic' => esc_html__( 'Semi-Bold 600 Italic', 'bloghash' ),
					'700'       => esc_html__( 'Bold 700', 'bloghash' ),
					'700italic' => esc_html__( 'Bold 700 Italic', 'bloghash' ),
					'800'       => esc_html__( 'Extra-Bold 800', 'bloghash' ),
					'800italic' => esc_html__( 'Extra-Bold 800 Italic', 'bloghash' ),
					'900'       => esc_html__( 'Black 900', 'bloghash' ),
					'900italic' => esc_html__( 'Black 900 Italic', 'bloghash' ),
				),
				'subsets'         => bloghash()->fonts->get_google_font_subsets(),
				'transforms'      => array(
					'inherit'    => esc_html__( 'Inherit', 'bloghash' ),
					'uppercase'  => esc_html__( 'Uppercase', 'bloghash' ),
					'lowercase'  => esc_html__( 'Lowercase', 'bloghash' ),
					'capitalize' => esc_html__( 'Capitalize', 'bloghash' ),
					'none'       => esc_html__( 'None', 'bloghash' ),
				),
				'decorations'     => array(
					'inherit'      => esc_html__( 'Inherit', 'bloghash' ),
					'underline'    => esc_html__( 'Underline', 'bloghash' ),
					'overline'     => esc_html__( 'Overline', 'bloghash' ),
					'line-through' => esc_html__( 'Line Through', 'bloghash' ),
					'none'         => esc_html__( 'None', 'bloghash' ),
				),
				'styles'          => array(
					'inherit' => esc_html__( 'Inherit', 'bloghash' ),
					'normal'  => esc_html__( 'Normal', 'bloghash' ),
					'italic'  => esc_html__( 'Italic', 'bloghash' ),
					'oblique' => esc_html__( 'Oblique', 'bloghash' ),
				),
			);

			$default_units = array(
				'font-size'      => array(
					array(
						'id'   => 'px',
						'name' => 'px',
						'min'  => 8,
						'max'  => 65,
						'step' => 1,
					),
					array(
						'id'   => 'em',
						'name' => 'em',
						'min'  => 0.5,
						'max'  => 6.5,
						'step' => 0.01,
					),
					array(
						'id'   => 'rem',
						'name' => 'rem',
						'min'  => 0.5,
						'max'  => 6.5,
						'step' => 0.01,
					),
				),
				'letter-spacing' => array(
					array(
						'id'   => 'px',
						'name' => 'px',
						'min'  => -10,
						'max'  => 10,
						'step' => 1,
					),
				),
				'line-height'    => array(
					array(
						'id'   => '',
						'name' => '',
						'min'  => 1,
						'max'  => 10,
						'step' => 0.1,
					),
				),
			);

			$this->json['units'] = array();

			foreach ( array( 'font-size', 'letter-spacing', 'line-height' ) as $key ) {
				if ( isset( $this->display[ $key ] ) && isset( $this->display[ $key ]['unit'] ) ) {
					$this->json['units'][ $key ] = $this->display[ $key ]['unit'];
				}
			}

			$this->json['units'] = wp_parse_args( $this->json['units'], $default_units );

			$this->json['responsive'] = array(
				'desktop' => array(
					'title' => esc_html__( 'Desktop', 'bloghash' ),
					'icon'  => 'dashicons dashicons-desktop',
				),
				'tablet'  => array(
					'title' => esc_html__( 'Tablet', 'bloghash' ),
					'icon'  => 'dashicons dashicons-tablet',
				),
				'mobile'  => array(
					'title' => esc_html__( 'Mobile', 'bloghash' ),
					'icon'  => 'dashicons dashicons-smartphone',
				),
			);
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
			<div class="bloghash-typography-wrapper bloghash-popup-options bloghash-control-wrapper">

				<div class="bloghash-typography-heading">
					<# if ( data.label ) { #>
						<div class="customize-control-title">
							<span>{{{ data.label }}}</span>

							<# if ( data.description ) { #>
								<i class="bloghash-info-icon">
									<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-help-circle">
										<circle cx="12" cy="12" r="10"></circle>
										<path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"></path>
										<line x1="12" y1="17" x2="12" y2="17"></line>
									</svg>
									<span class="bloghash-tooltip">{{{ data.description }}}</span>
								</i>
							<# } #>
					</div>
					<# } #>
				</div>

				<a href="#" class="reset-defaults">
					<span class="dashicons dashicons-image-rotate"></span>
				</a>

				<a href="#" class="popup-link">
					<span class="dashicons dashicons-edit"></span>
				</a>

				<div class="popup-content hidden">

					<# if ( 'font-family' in data.display ) { #>
						<!-- Font Family -->
						<div class="bloghash-select-wrapper bloghash-typography-font-family">
							<label for="font-family-{{ data.id }}">{{{ data.l10n['font-family'] }}}</label>
							<select data-option="font-family" id="font-family-{{ data.id }}" data-default="{{ data.default['font-family'] }}">
								<option value="{{ data.value['font-family'] }}" selected="selected">
									<# if ( 'default' === data.value['font-family'] ) { #>
										{{{ data.l10n['default'] }}}
									<# } else if ( 'inherit' === data.value['font-family'] ) { #>
										{{{ data.l10n['inherit'] }}}
									<# } else { #>
										{{{ data.value['font-family'] }}}
									<# } #>
								</option> 
							</select>
						</div>
					<# } #>

					<# if ( 'font-subsets' in data.display ) { #>
						<!-- Font subsets -->
						<div class="bloghash-select-wrapper bloghash-typography-font-subsets">
							<label for="font-subsets-{{ data.id }}">{{{ data.l10n['font-subsets'] }}}</label>
							<select data-option="font-subsets" id="font-subsets-{{ data.id }}" multiple="multiple">
								<# _.each( data.value['font-subsets'], function( subsets ){ #>
									<option value="{{ subsets }}" selected="selected">{{{ data.l10n['subsets'][ subsets ] }}}</option>
								<# }); #>
							</select>
						</div>
					<# } #>

					<# if ( 'font-size' in data.display ) { #>
						<!-- Font Size -->
						<div class="bloghash-range-wrapper bloghash-typography-font-size bloghash-control-responsive" data-option-id="font-size">
							<label for="font-size-{{ data.id }}">
								<span>{{{ data.l10n['font-size'] }}}</span>
								<?php $this->responsive_devices(); ?>
							</label>

							<div class="bloghash-control-wrap" data-unit="{{ data.value['font-size-unit'] }}">
								<# _.each( data.responsive, function( settings, device ){ #>

									<div class="{{ device }} control-responsive">

										<input 
											type="range" 
											{{{ data.inputAttrs }}} 
											value="{{ data.value[ 'font-size-' + device ] }}" 
											min="{{ data.units['font-size']['min'] }}" 
											max="{{ data.units['font-size']['max'] }}" 
											step="{{ data.units['font-size']['step'] }}"  
											data-device="{{ device }}" />

											<span 
												class="bloghash-reset-range"
												data-reset_value="{{ data.default[ 'font-size-' + device ] }}"
												data-reset_unit="{{ data.default[ 'font-size-unit'] }}">
												<span class="dashicons dashicons-image-rotate"></span>
											</span>

										<input 
											type="number" 
											{{{ data.inputAttrs }}} 
											class="bloghash-range-input" 
											data-option="font-size-{{ device }}" 
											value="{{ data.value[ 'font-size-' + device ] }}" />
									</div>

								<# } ); #>
							</div><!-- .bloghash-control-wrap -->
						</div><!-- .bloghash-range-wrapper -->
					<# } #>

					<# if ( 'font-weight' in data.display ) { #>
						<!-- Font Weight -->
						<div class="bloghash-select-wrapper bloghash-typography-font-weight">
							<label for="font-weight-{{ data.id }}">{{{ data.l10n['font-weight'] }}}</label>
							<select data-option="font-weight" id="font-weight-{{ data.id }}" data-default="{{ data.default['font-weight'] }}">
								<option value="{{ data.value['font-weight'] }}" selected="selected">{{{ data.l10n.weights[ data.value['font-weight'] ] }}}</option> 
							</select>
						</div>
					<# } #>

					<# if ( 'font-style' in data.display ) { #>
						<!-- Font Style -->
						<div class="bloghash-select-wrapper bloghash-typography-font-style">
							<label for="font-style-{{ data.id }}">{{{ data.l10n['font-style'] }}}</label>
							<select data-option="font-style" id="font-style-{{ data.id }}" data-default="{{ data.default['font-style'] }}">
								<# _.each( data.l10n['styles'], function( value, key ){ #>
									<option value="{{ key }}" <# if ( key === data.value['font-style'] ) { #> selected="selected"<# } #>>{{{ value }}}</option>
								<# }); #>
							</select>
						</div>
					<# } #>

					<# if ( 'text-transform' in data.display ) { #>
						<!-- Text Transform -->
						<div class="bloghash-select-wrapper bloghash-typography-text-transform">
							<label for="text-transform-{{ data.id }}">{{{ data.l10n['text-transform'] }}}</label>
							<select data-option="text-transform" id="text-transform-{{ data.id }}" data-default="{{ data.default['text-transform'] }}">
								<# _.each( data.l10n['transforms'], function( value, key ){ #>
									<option value="{{ key }}" <# if ( key === data.value['text-transform'] ) { #> selected="selected"<# } #>>{{{ value }}}</option>
								<# }); #>
							</select>
						</div>
					<# } #>

					<# if ( 'text-decoration' in data.display ) { #>
						<!-- Text Transform -->
						<div class="bloghash-select-wrapper bloghash-typography-text-decoration">
							<label for="text-decoration-{{ data.id }}">{{{ data.l10n['text-decoration'] }}}</label>
							<select data-option="text-decoration" id="text-decoration-{{ data.id }}" data-default="{{ data.default['text-decoration'] }}">
								<# _.each( data.l10n['decorations'], function( value, key ){ #>
									<option value="{{ key }}" <# if ( key === data.value['text-decoration'] ) { #> selected="selected"<# } #>>{{{ value }}}</option>
								<# }); #>
							</select>
						</div>
					<# } #>

					<# if ( 'line-height' in data.display ) { #>
						<!-- Line Height -->
						<div class="bloghash-range-wrapper bloghash-typography-line-height bloghash-control-responsive" data-option-id="line-height">
							<label for="line-height-{{ data.id }}">
								<span>{{{ data.l10n['line-height'] }}}</span>
								<?php $this->responsive_devices(); ?>
							</label>

							<div class="bloghash-control-wrap" data-unit="{{ data.value['line-height-unit'] }}">
								<# _.each( data.responsive, function( settings, device ){ #>

									<div class="{{ device }} control-responsive">

										<input 
											type="range" 
											{{{ data.inputAttrs }}} 
											value="{{ data.value[ 'line-height-' + device ] }}" 
											min="{{ data.units['line-height']['min'] }}" 
											max="{{ data.units['line-height']['max'] }}" 
											step="{{ data.units['line-height']['step'] }}"  
											data-device="{{ device }}" />

											<span 
												class="bloghash-reset-range"
												data-reset_value="{{ data.default[ 'line-height-' + device ] }}"
												data-reset_unit="{{ data.default[ 'line-height-unit'] }}">
												<span class="dashicons dashicons-image-rotate"></span>
											</span>

										<input 
											type="number" 
											{{{ data.inputAttrs }}} 
											class="bloghash-range-input" 
											data-option="line-height-{{ device }}" 
											value="{{ data.value[ 'line-height-' + device ] }}" />
									</div>

								<# } ); #>
							</div><!-- .bloghash-control-wrap -->
						</div><!-- .bloghash-range-wrapper -->
					<# } #>

					<# if ( 'letter-spacing' in data.display ) { #>
						<!-- Letter Spacing -->
						<div class="bloghash-range-wrapper bloghash-typography-letter-spacing bloghash-control-responsive" data-option-id="letter-spacing">
							<label for="letter-spacing-{{ data.id }}">
								<span>{{{ data.l10n['letter-spacing'] }}}</span>
							</label>

							<div class="bloghash-control-wrap" data-unit="{{ data.value['letter-spacing-unit'] }}">
								<input 
									type="range" 
									{{{ data.inputAttrs }}} 
									value="{{ data.value[ 'letter-spacing' ] }}" 
									min="{{ data.units['letter-spacing']['min'] }}" 
									max="{{ data.units['letter-spacing']['max'] }}" 
									step="{{ data.units['letter-spacing']['step'] }}" />
								<span 
									class="bloghash-reset-range"
									data-reset_value="{{ data.default[ 'letter-spacing' ] }}"
									data-reset_unit="{{ data.default[ 'letter-spacing-unit'] }}">
									<span class="dashicons dashicons-image-rotate"></span>
								</span>
								<input 
									type="number" 
									{{{ data.inputAttrs }}} 
									class="bloghash-range-input" 
									data-option="letter-spacing" 
									value="{{ data.value[ 'letter-spacing' ] }}" />
							</div><!-- .bloghash-control-wrap -->
						</div><!-- .bloghash-range-wrapper -->
					<# } #>
				</div><!-- .bloghash-typography-advanced -->

			</div><!-- .bloghash-control-wrapper -->
			<?php
		}
	}
endif;
