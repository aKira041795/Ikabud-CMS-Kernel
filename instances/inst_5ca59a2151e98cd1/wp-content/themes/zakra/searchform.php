<?php
/**
 * The template for search form.
 *
 * @link    https://developer.wordpress.org/themes/basics/template-files/#template-partials
 *
 * @package Zakra
 *
 * @since 3.0.0
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;
$product_search = get_theme_mod( 'zakra_enable_product_search_search', false );
?>

<div class="zak-search-container">
<form role="search" method="get" class="zak-search-form" action="<?php echo esc_url( home_url( '/' ) ); ?>">
	<label class="zak-search-field-label">
		<div class="zak-icon--search">

			<?php zakra_get_icon( 'magnifying-glass' ); ?>

		</div>

		<span class="screen-reader-text"><?php echo esc_attr_x( 'Search for:', 'label', 'zakra' ); ?></span>

		<input type="search"
				class="zak-search-field"
				placeholder="<?php echo esc_attr_x( 'Type & hit Enter &hellip;', 'placeholder', 'zakra' ); ?>"
				value="<?php echo esc_attr( get_search_query() ); ?>"
				name="s"
				title="<?php echo esc_attr_x( 'Search for:', 'label', 'zakra' ); ?>"
		>
	</label>

	<input type="submit" class="zak-search-submit"
			value="<?php echo esc_attr_x( 'Search', 'submit button', 'zakra' ); ?>" />

	<?php if ( $product_search ) : ?>
	<input type="hidden" name="post_type" value="product" />
	<?php endif; ?>
</form>
<button class="zak-icon--close" role="button">
</button>
</div>
