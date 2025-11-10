<?php
/**
 * Template part for displaying post format image entry.
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

$bloghash_media = bloghash_get_post_media( 'image' );

if ( ! $bloghash_media || post_password_required() ) {
	return;
}

?>

<div class="post-thumb entry-media thumbnail">

	<?php
	if ( ! is_single( get_the_ID() ) ) {
		$bloghash_media = sprintf(
			'<a href="%1$s" class="entry-image-link">%2$s</a>',
			esc_url( bloghash_entry_get_permalink() ),
			$bloghash_media
		);
	}

	echo $bloghash_media; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	?>
</div>
