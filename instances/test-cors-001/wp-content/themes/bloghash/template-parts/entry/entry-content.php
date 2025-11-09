<?php
/**
 * Template part for displaying entry content.
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

<?php do_action( 'bloghash_before_entry_content' ); ?>
<div class="entry-content bloghash-entry"<?php bloghash_schema_markup( 'text' ); ?>>
	<?php the_content(); ?>
</div>

<?php bloghash_link_pages(); ?>

<?php do_action( 'bloghash_after_entry_content' ); ?>
