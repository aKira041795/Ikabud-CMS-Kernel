<?php
/**
 * Entry thumbnail template file.
 *
 * @link    https://developer.wordpress.org/themes/basics/template-hierarchy/
 *
 * @package zakra
 *
 * TODO: @since.
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

$readmore_alignment = get_theme_mod( 'zakra_blog_button_alignment', 'style-1' );
$readmore_alignment = 'alignment-' . $readmore_alignment;


if ( get_theme_mod( 'zakra_enable_blog_button', true ) ) {

	do_action( 'zakra_post_readmore', $readmore_alignment );
}
?>

</div>
