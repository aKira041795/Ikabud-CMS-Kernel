<?php
/**
 * Template part for displaying entry header.
 *
 * @link https://codex.wordpress.org/Template_Hierarchy
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

?>

<?php do_action( 'bloghash_before_entry_header' ); ?>
<header class="entry-header">

	<?php
	$bloghash_tag = is_single( get_the_ID() ) && ! bloghash_page_header_has_title() ? 'h1' : 'h4';
	$bloghash_tag = apply_filters( 'bloghash_entry_header_tag', $bloghash_tag );

	$bloghash_title_string = '%2$s%1$s';

	if ( 'link' === get_post_format() ) {
		$bloghash_title_string = '<a href="%3$s" title="%3$s" rel="bookmark">%2$s%1$s</a>';
	} elseif ( ! is_single( get_the_ID() ) ) {
		$bloghash_title_string = '<a href="%3$s" title="%4$s" rel="bookmark">%2$s%1$s</a>';
	}

	$bloghash_title_icon = apply_filters( 'bloghash_post_title_icon', '' );
	$bloghash_title_icon = bloghash()->icons->get_svg( $bloghash_title_icon );
	?>

	<<?php echo tag_escape( $bloghash_tag ); ?> class="entry-title"<?php bloghash_schema_markup( 'headline' ); ?>>
		<?php
		echo sprintf(
			wp_kses_post( $bloghash_title_string ),
			wp_kses_post( get_the_title() ),
			wp_kses_post( (string) $bloghash_title_icon ),
			esc_url( bloghash_entry_get_permalink() ),
			the_title_attribute( array( 'echo' => false ) )
		);
		?>
	</<?php echo tag_escape( $bloghash_tag ); ?>>

</header>
<?php do_action( 'bloghash_after_entry_header' ); ?>
