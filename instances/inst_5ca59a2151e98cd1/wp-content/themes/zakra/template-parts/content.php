<?php
/**
 * Template part for displaying posts
 *
 * @link    https://developer.wordpress.org/themes/basics/template-hierarchy/
 *
 * @package zakra
 */

$meta_style = get_theme_mod( 'zakra_post_meta_style', 'style-1' );
$meta_style = 'zak-' . $meta_style;
?>

<article id="post-<?php the_ID(); ?>" <?php post_class( $meta_style ); ?>>

	<?php
	/**
	 * Hook - zakra_action_entry_content
	 *
	 * Functions hooked into zakra_action_entry_content action
	 *
	 * @hooked zakra_entry_content - 10
	 */
	do_action( 'zakra_action_entry_content' );
	?>

</article><!-- #post-<?php the_ID(); ?> -->
