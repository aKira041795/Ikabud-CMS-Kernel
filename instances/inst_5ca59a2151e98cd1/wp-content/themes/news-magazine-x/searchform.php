<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

?>

<form role="search" method="get" class="search-form" action="<?php echo esc_url( home_url( '/' ) ); ?>">
	<label>
		<span class="screen-reader-text"><?php echo esc_html__( 'Search for:', 'news-magazine-x' ); ?></span>
		<input type="search" class="search-field" placeholder="<?php echo isset($args['placeholder']) ? esc_attr($args['placeholder']) : esc_attr__('Search...', 'news-magazine-x'); ?>" value="<?php echo esc_attr( get_search_query() ); ?>" name="s" tabindex="-1" autocomplete="off">
        <button class="search-submit newsx-search-submit newsx-flex" aria-label="<?php echo esc_attr__( 'Search Submit', 'news-magazine-x' ); ?>">
            <span hidden><?php echo esc_html__( 'Search', 'news-magazine-x' ); ?></span>
            <?php newsx_default_icon_markup('search', true); ?>
		</button>
	</label>
</form>