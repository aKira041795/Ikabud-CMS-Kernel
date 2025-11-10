<?php
/**
 * Customizer Control: custom.
 *
 * Creates a new custom control.
 * Custom controls accept raw HTML/JS.
 *
 * @package   kirki-framework/control-custom
 * @copyright Copyright (c) 2023, Themeum
 * @license   https://opensource.org/licenses/MIT
 * @since     1.0
 */

namespace Kirki\Control;

use Kirki\Control\Base;

/**
 * The "custom" control allows you to add any raw HTML.
 *
 * @since 1.0
 */
class Custom extends Base {

	/**
	 * The control type.
	 *
	 * @access public
	 * @since 1.0
	 * @var string
	 */
	public $type = 'kirki-custom';

	/**
	 * An Underscore (JS) template for this control's content (but not its container).
	 *
	 * Class variables for this control class are available in the `data` JS object;
	 * export custom variables by overriding {@see WP_Customize_Control::to_json()}.
	 *
	 * @see WP_Customize_Control::print_template()
	 *
	 * @access protected
	 * @since 1.0
	 * @return void
	 */
	protected function content_template() {
		?>
		<label>
			<# if ( data.label ) { #>
				<span class="customize-control-title">{{{ data.label }}}</span>
			<# } #>
			<# if ( data.description ) { #>
				<span class="customize-control-description">{{{ data.description }}}</span>
			<# } #>
			{{{ data.button }}}


			<# if ( 'kirki-background' == data.wrapper_attrs['data-kirki-parent-control-type'] ) { #>
				<div class="newsx-background-ctrl-trigger">
					<#
						var value = data.value,
							valueArr = value.split('|'),
							tabID = valueArr[0],
							style = '',
							icon = '';
							
						if ( 'image' === tabID ) {
							icon = '<dashicons class="dashicons dashicons-format-image"></dashicons>';
						} else if ( 'gradient' === tabID ) {
							style = 'background-image: linear-gradient('+ valueArr[1] +', '+ valueArr[2] +');';
						} else {
							style = 'background-color: ' + valueArr[1];
						}
						
					#>

					<div style="{{{ style }}}">{{{ icon }}}</div>
				</div>
				<# } #>

				
				<# if ( 'kirki-typography' == data.wrapper_attrs['data-kirki-parent-control-type'] ) { #>
					<div class="newsx-typography-ctrl-trigger">
						<span class="dashicons dashicons-edit"></span>
						<span class="dashicons dashicons-no-alt"></span>
					</div>
				<# } #>
			
			<?php
				/**
				 * The value is defined by the developer in the field configuration as 'default'.
				 * There is no user input on this field, it's a raw HTML/JS field and we do not sanitize it.
				 * Do not be alarmed, this is not a security issue.
				 * In order for someone to be able to change this they would have to have access to your filesystem.
				 * If that happens, they can change whatever they want anyways. This field is not a concern.
				 */
			?>
			
			<# if ( '' !== data.value ) { #>
				<input id="customize-input-{{ data.id }}" {{{ data.link }}} type="hidden" value="{{ data.value }}" element="input" />
			<# } #>
		</label>
			
		<# if ( 'kirki-background' == data.wrapper_attrs['data-kirki-parent-control-type'] ) { #>
			<?php

				echo '<ul class="newsx-background-ctrl-tabs">';
					echo '<li data-tab-id="color">'. esc_html__( 'Color', 'news-magazine-x' ) .'</li>';
					echo '<li data-tab-id="gradient">'. esc_html__( 'Gradient', 'news-magazine-x' ) .'</li>';
					echo '<li data-tab-id="image">'. esc_html__( 'Image', 'news-magazine-x' ) .'</li>';
				echo '</ul>';
			
			?>
		<# } #>
		<?php
	}
}
