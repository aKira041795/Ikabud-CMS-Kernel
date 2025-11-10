<?php
/**
 * The template for displaying theme header search widget.
 *
 * @package     Bloghash
 * @author      Peregrine Themes
 * @since       1.0.0
 */

$bloghash_header_widgets = bloghash_option( 'header_widgets' );
$style_for_search        = '';
foreach ( $bloghash_header_widgets as $widget ) {
	// Check if the widget type is 'search'
	if ( $widget['type'] === 'search' ) {
		// Access the 'style' from the 'values' array
		$style_for_search = $widget['values']['style'] ?? 'rounded-fill';
		break; // Stop the loop if the search widget is found
	}
}

?>

<div aria-haspopup="true">
	<a href="#" class="bloghash-search <?php echo esc_attr( $style_for_search ); ?>">
		<?php echo bloghash()->icons->get_svg( 'search', array( 'aria-label' => esc_html__( 'Search', 'bloghash' ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
	</a><!-- END .bloghash-search -->

	<div class="bloghash-search-simple bloghash-search-container dropdown-item">
		<?php
			get_search_form(
				array(
					'aria_label' => __( 'Search for:', 'bloghash' ),
					'icon' => 'arrow'
				)
			);
		?>
	</div><!-- END .bloghash-search-simple -->
</div>
