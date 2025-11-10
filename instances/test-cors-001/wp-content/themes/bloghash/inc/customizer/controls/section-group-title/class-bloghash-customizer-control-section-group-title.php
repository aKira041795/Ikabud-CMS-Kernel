<?php

class Bloghash_Customizer_Control_Section_Group_Title extends WP_Customize_Section {

	/**
	 * The type of customize section being rendered.
	 *
	 * @since  1.0.0
	 * @access public
	 * @var    string
	 */
	public $type = 'bloghash-section-group-title';

	/**
	 * Special categorization for the section.
	 *
	 * @since  1.0.0
	 * @access public
	 * @var    string
	 */
	public string $kind = 'default';

	/**
	 * Add custom parameters to pass to the JS via JSON.
	 *
	 * @since  1.0.0
	 * @access public
	 */

	/**
	 * Bloghash_Customizer_Control_Section_Group_Title constructor.
	 *
	 * @param WP_Customize_Manager $manager Customizer Manager.
	 * @param string               $id Control id.
	 * @param array                $args Arguments.
	 */
	public function __construct( WP_Customize_Manager $manager, $id, array $args = array() ) {
		parent::__construct( $manager, $id, $args );

		add_action( 'customize_controls_enqueue_scripts', array( $this, 'enqueue' ) );
	}

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
	public function json() {
		$json         = parent::json();
		$json['kind'] = $this->kind;
		return $json;
	}

	/**
	 * Outputs the Underscore.js template.
	 *
	 * @since  1.0.0
	 * @access public
	 * @return void
	 */
	protected function render_template() { ?>
		<li id="accordion-section-{{ data.id }}" class="accordion-section <# if (data.kind==='divider') { #> bloghash-group-divider <# } else if (data.kind==='option') { #> bloghash-option-title <# } else { #> bloghash-group-title <# } #>">
		<# if ( data.title && data.title.indexOf('</div>') === -1 || data.kind === 'divider' ) { #>
				<h3>{{ data.title }}</h3>
			<# }else{ #>
				{{ data.title }}
			<# } #>

			<# if ( data.description && data.description_hidden ) { #>
			<span class="description">{{ data.description }}</span>
			<# } #>
		</li>

		<?php
	}
}
