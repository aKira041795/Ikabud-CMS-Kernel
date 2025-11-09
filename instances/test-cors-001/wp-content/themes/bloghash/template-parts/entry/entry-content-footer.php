<?php
/**
 * Template part for displaying entry tags.
 *
 * @package     Bloghash
 * @author      Peregrine Themes
 * @since       1.0.0
 */

$bloghash_entry_elements    = bloghash_option( 'single_post_elements' );
$bloghash_entry_footer_tags = isset( $bloghash_entry_elements['tags'] ) && $bloghash_entry_elements['tags'] && has_tag();
$bloghash_entry_footer_date = isset( $bloghash_entry_elements['last-updated'] ) && $bloghash_entry_elements['last-updated'] && get_the_time( 'U' ) !== get_the_modified_time( 'U' );

$bloghash_entry_footer_tags = apply_filters( 'bloghash_display_entry_footer_tags', $bloghash_entry_footer_tags );
$bloghash_entry_footer_date = apply_filters( 'bloghash_display_entry_footer_date', $bloghash_entry_footer_date );

// Nothing is enabled, don't display the div.
if ( ! $bloghash_entry_footer_tags && ! $bloghash_entry_footer_date ) {
	return;
}
?>

<?php do_action( 'bloghash_before_entry_footer' ); ?>

<div class="entry-footer">

	<?php
	// Post Tags.
	if ( $bloghash_entry_footer_tags ) {
		bloghash_entry_meta_tag(
			'<div class="post-tags"><span class="cat-links">',
			'',
			'</span></div>',
			0,
			false
		);
	}

	// Last Updated Date.
	if ( $bloghash_entry_footer_date ) {

		$bloghash_before = '<span class="last-updated bloghash-iflex-center">';

		if ( true === bloghash_option( 'single_entry_meta_icons' ) ) {
			$bloghash_before .= bloghash()->icons->get_svg( 'edit-3' );
		}

		bloghash_entry_meta_date(
			array(
				'show_published' => false,
				'show_modified'  => true,
				'before'         => $bloghash_before,
				'after'          => '</span>',
			)
		);
	}
	?>

</div>

<?php do_action( 'bloghash_after_entry_footer' ); ?>
