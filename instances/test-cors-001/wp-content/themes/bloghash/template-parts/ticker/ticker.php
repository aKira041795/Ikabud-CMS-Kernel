<?php
/**
 * The template for displaying Ticker Slider.
 *
 * @package     Bloghash
 * @author      Peregrine Themes
 * @since       1.0.0
 */


// Setup Ticker posts.
$bloghash_args = array(
	'post_type'           => 'post',
	'post_status'         => 'publish',
	'posts_per_page'      => bloghash_option( 'ticker_post_number' ), // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page
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

$bloghash_ticker_categories = bloghash_option( 'ticker_category' );

if ( ! empty( $bloghash_ticker_categories ) ) {
	$bloghash_args['category_name'] = implode( ', ', $bloghash_ticker_categories );
}

$bloghash_args = apply_filters( 'bloghash_ticker_query_args', $bloghash_args );

$bloghash_posts = new WP_Query( $bloghash_args );

// No posts found.
if ( ! $bloghash_posts->have_posts() ) {
	return;
}

$bloghash_ticker_items_html = '';

$bloghash_ticker_elements = (array) bloghash_option( 'ticker_elements' );

$bloghash_ticker_type = bloghash_option( 'ticker_type' );

$bloghash_ticker_slide = $bloghash_ticker_type === 'one-ticker' ? 'ticker-item' : '';

while ( $bloghash_posts->have_posts() ) :
	$bloghash_posts->the_post();

	// Post items HTML markup.
	ob_start();
	?>
	<div class="<?php echo esc_attr( $bloghash_ticker_slide ); ?>">
		<div class="ticker-slide-item">

			<?php if ( has_post_thumbnail() ) { ?>
			<div class="ticker-slider-backgrounds">
				<a href="<?php echo esc_url( bloghash_entry_get_permalink() ); ?>">
					<?php the_post_thumbnail( 'thumbnail' ); ?>
				</a>
			</div><!-- END .ticker-slider-items -->
			<?php } ?>

			<div class="slide-inner">				

				<?php if ( get_the_title() ) { ?>
					<h6><a href="<?php echo esc_url( bloghash_entry_get_permalink() ); ?>"><?php the_title(); ?></a></h6>
				<?php } ?>

				<?php if ( isset( $bloghash_ticker_elements['meta'] ) && $bloghash_ticker_elements['meta'] ) { ?>
					<div class="entry-meta">
						<div class="entry-meta-elements">
							<?php
							bloghash_entry_meta_date(
								array(
									'show_modified'   => false,
									'published_label' => '',
								)
							);
							?>
						</div>
					</div><!-- END .entry-meta -->
				<?php } ?>

			</div><!-- END .slide-inner -->
		</div><!-- END .ticker-slide-item -->
	</div><!-- END .swiper-slide -->
	<?php
	$bloghash_ticker_items_html .= ob_get_clean();
endwhile;

// Restore original Post Data.
wp_reset_postdata();

$bloghash_ticker_title = bloghash_option( 'ticker_title' );

?>

<div class="bloghash-ticker <?php echo esc_attr( $bloghash_ticker_type ); ?>">
	<div class="bloghash-ticker-container bloghash-container">
		<div class="bloghash-flex-row">
			<div class="col-xs-12">
				<div class="bloghash-card-items">
					<?php if ( $bloghash_ticker_title ) : ?>
					<div class="h4 widget-title">
						<?php echo esc_html( $bloghash_ticker_title ); ?>
					</div>
					<?php endif; ?>
					<?php
						$bloghash_ticker_direction = 'left';
						$bloghash_ticker_dir       = 'ltr';
					if ( is_rtl() ) {
						$bloghash_ticker_direction = 'right';
						$bloghash_ticker_dir       = 'ltr';
					}
					?>
					<?php if ( 'one-ticker' === $bloghash_ticker_type ) : ?>
					<div class="ticker-slider-box">
						<div class="ticker-slider-wrap" direction="<?php echo esc_attr( $bloghash_ticker_direction ); ?>" dir="<?php echo esc_attr( $bloghash_ticker_dir ); ?>">
							<?php echo wp_kses_post( $bloghash_ticker_items_html ); ?>
						</div>
					</div>
					<div class="ticker-slider-controls">
						<button class="ticker-slider-pause"><i class="fas fa-pause"></i></button>						
					</div>
					<?php endif; ?>
				</div>
			</div>
		</div><!-- END .ticker-slider-items -->
	</div>
</div><!-- END .bloghash-ticker -->
