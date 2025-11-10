<?php
/**
 * The template for displaying Hero Horizontal Slider.
 *
 * @package     Bloghash
 * @author      Peregrine Themes
 * @since       1.0.0
 */


// Setup Hero posts.
$bloghash_hero_slider_orderby = bloghash_option( 'hero_slider_orderby' );
$bloghash_hero_slider_order   = explode( '-', $bloghash_hero_slider_orderby );

$bloghash_args = array(
	'post_type'           => 'post',
	'post_status'         => 'publish',
	'posts_per_page'      => bloghash_option( 'hero_slider_post_number' ), // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page
	'order'               => $bloghash_hero_slider_order[1],
	'orderby'             => $bloghash_hero_slider_order[0],
	'ignore_sticky_posts' => true,
	'tax_query'           => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
		array(
			'taxonomy' => 'post_format',
			'field'    => 'slug',
			'terms'    => array( 'post-format-quote' ),
			'operator' => 'NOT IN',
		),
	),
);

$bloghash_hero_categories = bloghash_option( 'hero_slider_category' );

if ( ! empty( $bloghash_hero_categories ) ) {
	$bloghash_args['category_name'] = implode( ', ', $bloghash_hero_categories );
}

$bloghash_args = apply_filters( 'bloghash_hero_slider_query_args', $bloghash_args );

$bloghash_posts = new WP_Query( $bloghash_args );

// No posts found.
if ( ! $bloghash_posts->have_posts() ) {
	return;
}

$bloghash_hero_items_html = '';

$bloghash_hero_elements       = (array) bloghash_option( 'hero_slider_elements' );
$bloghash_hero_readmore       = isset( $bloghash_hero_elements['read_more'] ) && $bloghash_hero_elements['read_more'] ? ' bloghash-hero-readmore' : '';
$bloghash_hero_read_more_text = bloghash_option( 'hero_slider_read_more' );

while ( $bloghash_posts->have_posts() ) :
	$bloghash_posts->the_post();

	// Post items HTML markup.
	ob_start();

	?>
	<div class="swiper-slide">
		<article id="post-<?php the_ID(); ?>" <?php post_class( 'bloghash-article' ); ?><?php bloghash_schema_markup( 'article' ); ?>>
			<div class="bloghash-blog-entry-wrapper bloghash-thumb-hero bloghash-thumb-left">
				<div class="post-thumb entry-media thumbnail">
					<a href="<?php echo esc_url( bloghash_entry_get_permalink() ); ?>" class="entry-image-link">
						<?php the_post_thumbnail( get_the_ID(), 'full' ); ?>
					</a>
				</div>
				<div class="bloghash-entry-content-wrapper">

				<?php if ( isset( $bloghash_hero_elements['category'] ) && $bloghash_hero_elements['category'] ) { ?>
					<div class="post-category">
						<?php bloghash_entry_meta_category( ' ', false, apply_filters( 'bloghash_hero_horizontal_category_limit', 3 ) ); ?>
					</div>
				<?php } ?>

				<?php if ( get_the_title() ) { ?>
				<header class="entry-header">
					<h4 class="entry-title"><a href="<?php echo esc_url( bloghash_entry_get_permalink() ); ?>"><?php the_title(); ?></a></h4>
				</header>
				<?php } ?>

				<?php get_template_part( 'template-parts/entry/entry-summary' ); ?>

				<?php if ( $bloghash_hero_readmore ) { ?>
					<footer class="entry-footer">
						<a href="<?php echo esc_url( bloghash_entry_get_permalink() ); ?>" class="bloghash-btn btn-text-1" role="button"><span><?php echo esc_html( $bloghash_hero_read_more_text ); ?></span></a>
					</footer>
				<?php } ?>

				<?php if ( isset( $bloghash_hero_elements['meta'] ) && $bloghash_hero_elements['meta'] ) { ?>
					<?php
						get_template_part( 'template-parts/entry/entry', 'meta', array( 'bloghash_meta_callback' => 'bloghash_get_hero_entry_meta_elements' ) );
					?>
					<!-- END .entry-meta -->
				<?php } ?>

			</div><!-- END .slide-inner -->
		</article><!-- END article -->
	</div>
	<?php
	$bloghash_hero_items_html .= ob_get_clean();
endwhile;

// Restore original Post Data.
wp_reset_postdata();

// Hero container. {"delay": 8000, "disableOnInteraction": false}

?>
<div class="bloghash-hero-slider bloghash-blog-horizontal">
	<div class="bloghash-horizontal-slider">

		<div class="bloghash-hero-container bloghash-container">
			<div class="bloghash-flex-row">
				<div class="col-xs-12">
					<div class="bloghash-swiper swiper" data-swiper-options='{
						"spaceBetween": 24,
						"slidesPerView": 1,
						"breakpoints": {
							"0": {
								"spaceBetween": 16
							},
							"768": {
								"spaceBetween": 16
							},
							"1200": {
								"spaceBetween": 24
							}
						},
						"loop": true,
						"autoHeight": true,
						"autoplay": {"delay": 12000, "disableOnInteraction": false},
						"speed": 1000,
						"navigation": {"nextEl": ".hero-next", "prevEl": ".hero-prev"}
					}'>
						<div class="swiper-wrapper">
							<?php echo wp_kses( $bloghash_hero_items_html, bloghash_get_allowed_html_tags() ); ?> 
						</div>
						<div class="swiper-button-next hero-next"></div>
						<div class="swiper-button-prev hero-prev"></div>
					</div>
				</div>
			</div>
		</div>

		<div class="bloghash-spinner visible">
			<div></div>
			<div></div>
		</div>
	</div>
</div><!-- END .bloghash-hero-slider -->
