<?php
/**
 * Bloghash Customizer custom select control class.
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

if ( ! class_exists( 'Bloghash_Customizer_Control_Select' ) ) :
	/**
	 * Bloghash Customizer custom select control class.
	 */
	class Bloghash_Customizer_Control_Select extends Bloghash_Customizer_Control {

		/**
		 * The control type.
		 *
		 * @var string
		 */
		public $type = 'bloghash-select';

		/**
		 * Placeholder text.
		 *
		 * @since 1.0.0
		 * @var string|false
		 */
		public $placeholder = false;

		/**
		 * Select2 flag.
		 *
		 * @since 1.0.0
		 * @var boolean
		 */
		public $is_select2 = false;

		/**
		 * Data source.
		 *
		 * @since 1.0.0
		 * @var string|false
		 */
		public $data_source = false;

		/**
		 * Source from where we will show data like custom taxonomy.
		 *
		 * @var boolean
		 */
		public $data_source_name = null;

		/**
		 * Multiple items.
		 *
		 * @since 1.0.0
		 * @var boolean
		 */
		public $multiple = false;

		/**
		 * Set the default typography options.
		 *
		 * @since 1.0.0
		 * @param WP_Customize_Manager $manager Customizer bootstrap instance.
		 * @param string               $id      Control ID.
		 * @param array                $args    Default parent's arguments.
		 */
		public function __construct( $manager, $id, $args = array() ) {

			parent::__construct( $manager, $id, $args );

			if ( $this->is_select2 ) {

				switch ( $this->data_source ) {

					case 'category':
						$args       = array(
							'hide_empty' => true,
							'taxonomy'   => $this->data_source_name ?? 'category',
						);
						$categories = get_terms( $args );

						$choices = array();

						if ( ! empty( $categories ) ) {

							foreach ( $categories as $category ) {
								if ( ! is_object( $category ) ) {
									continue;
								}
								$choices[ $category->slug ] = $category->name;
							}
						}

						$this->choices = $choices;

						break;
					case 'page':
						$pages = get_pages();

						$choices = array();
						if ( ! empty( $pages ) ) {

							foreach ( $pages as $page ) {
								$choices[ $page->post_name ] = $page->post_title;
							}
						}

						$this->choices = $choices;

						break;

					case 'post':
						$args = array(
							'post_type' => 'post',
							'posts_per_page' => -1, // Get all posts
							'post_status' => 'publish', // Only get published posts
						);
						$posts = get_posts( $args );
				
						$choices = array();
						if ( ! empty( $posts ) ) {
							foreach ( $posts as $post ) {
								// Limit the post title to 3 words
								$title_words = explode(' ', $post->post_title);
								$limited_title = implode(' ', array_slice($title_words, 0, 3)) . '...';
				
								$choices[ $post->post_name ] = $limited_title;
							}
						}
				
						$this->choices = $choices;
				
						break;

					default:
						// code...
						break;
				}
			}
		}

		/**
		 * Refresh the parameters passed to the JavaScript via JSON.
		 *
		 * @see WP_Customize_Control::to_json()
		 */
		public function to_json() {
			parent::to_json();

			$this->json['choices']     = $this->choices;
			$this->json['placeholder'] = $this->placeholder;
			$this->json['is_select2']  = $this->is_select2;
			$this->json['multiple']    = $this->multiple ? ' multiple="multiple"' : '';

			if ( $this->multiple ) {
				$this->json['value'] = implode( ',', (array) $this->json['value'] );
			}
		}

		/**
		 * Enqueue control related scripts/styles.
		 *
		 * @access public
		 */
		public function enqueue() {

			parent::enqueue();

			if ( $this->is_select2 ) {

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
			<div class="bloghash-control-wrapper bloghash-select-wrapper">

			<label>
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

				<select class="bloghash-select-control" {{{ data.link }}}{{{ data.multiple }}}>

					<# if ( data.is_select2 ) { #>

						<# _.each( data.choices, function( label, choice ) {
							if(data.value) { #>

							<option title="{{ label }}" value="{{ choice }}" <# if ( -1 !== data.value.indexOf( choice ) ) { #> selected="selected" <# } #>>{{ label }}</option>

						<# } } ) #>

					<#  } else { #>
						<# for ( key in data.choices ) { #>
							<option title="{{ data.choices[ key ] }}" value="{{ key }}" <# if ( key === data.value ) { #> checked="checked" <# } #>>{{ data.choices[ key ] }}</option>
						<# } #>
					<# } #>
				</select>

			</label>

			</div><!-- END .bloghash-control-wrapper -->
																							<?php
		}
	}
endif;
