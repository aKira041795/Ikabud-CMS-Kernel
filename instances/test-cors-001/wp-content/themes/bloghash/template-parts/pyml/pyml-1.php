<?php
/**
 * The template for displaying PYML Slider.
 *
 * @package     Bloghash
 * @author      Peregrine Themes
 * @since       1.0.0
 */


// Setup PYML posts.
$bloghash_pyml_orderby = bloghash_option( 'pyml_orderby' );
$bloghash_pyml_order   = explode( '-', $bloghash_pyml_orderby );

$bloghash_args = array(
	'post_type'           => 'post',
	'post_status'         => 'publish',
	'posts_per_page'      => bloghash_option( 'pyml_post_number' ), // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page
	'order'               => $bloghash_pyml_order[1],
	'orderby'             => $bloghash_pyml_order[0],
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

$bloghash_pyml_categories = bloghash_option( 'pyml_category' );

if ( ! empty( $bloghash_pyml_categories ) ) {
	$bloghash_args['category_name'] = implode( ', ', $bloghash_pyml_categories );
}

$bloghash_args = apply_filters( 'bloghash_pyml_query_args', $bloghash_args );

$bloghash_posts = new WP_Query( $bloghash_args );

// No posts found.
if ( ! $bloghash_posts->have_posts() ) {
	return;
}

// $bloghash_pyml_bgs_html   = '';
$bloghash_pyml_items_html = '';

$bloghash_pyml_elements = (array) bloghash_option( 'pyml_elements' );

$bloghash_posts_per_page = 'col-md-' . ceil( esc_attr( 12 / $bloghash_args['posts_per_page'] ) ) . ' col-sm-6 col-xs-12';

while ( $bloghash_posts->have_posts() ) :
	$bloghash_posts->the_post();

	// Post items HTML markup.
	ob_start();
	?>
	<div class="<?php echo esc_attr( $bloghash_posts_per_page ); ?>">
		<div class="bloghash-post-item style-1 end rounded">
			<div class="bloghash-post-thumb">
				<a href="<?php echo esc_url( bloghash_entry_get_permalink() ); ?>" tabindex="0"></a>
				<div class="inner"><?php the_post_thumbnail( get_the_ID(), 'full' ); ?></div>
			</div><!-- END .bloghash-post-thumb -->
			<div class="bloghash-post-content">
							
				<?php if ( isset( $bloghash_pyml_elements['category'] ) && $bloghash_pyml_elements['category'] ) { ?>
					<div class="post-category">
						<?php bloghash_entry_meta_category( ' ', false, apply_filters( 'bloghash_pyml_category_limit', 3 ) ); ?>
					</div>
				<?php } ?>

				<?php get_template_part( 'template-parts/entry/entry-header' ); ?>

				<?php if ( isset( $bloghash_pyml_elements['meta'] ) && $bloghash_pyml_elements['meta'] ) { ?>
					<div class="entry-meta">
						<div class="entry-meta-elements">
							<?php
							bloghash_entry_meta_author();

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

			</div><!-- END .bloghash-post-content -->			
		</div><!-- END .bloghash-post-item -->
	</div>
	<?php
	$bloghash_pyml_items_html .= ob_get_clean();
endwhile;

// Restore original Post Data.
wp_reset_postdata();

// Container.
$bloghash_pyml_container = bloghash_option( 'pyml_container' );
$bloghash_pyml_container = 'full-width' === $bloghash_pyml_container ? 'bloghash-container bloghash-container__wide' : 'bloghash-container';

// Title.
$bloghash_pyml_title = bloghash_option( 'pyml_title' );

// Classes.
$bloghash_classes  = '';
$bloghash_classes .= bloghash_option( 'pyml_card_border' ) ? ' bloghash-card__boxed' : '';
$bloghash_classes .= bloghash_option( 'pyml_card_shadow' ) ? ' bloghash-card-shadow' : '';

?>

<div class="bloghash-pyml slider-overlay-1 <?php echo esc_attr( $bloghash_classes ); ?>">
	<div class="bloghash-pyml-container <?php echo esc_attr( $bloghash_pyml_container ); ?>">
		<div class="bloghash-flex-row">
			<div class="col-xs-12">
				<div class="bloghash-card-items">
					<div class="h4 widget-title">
						<?php if ( $bloghash_pyml_title ) : ?>
						<span><?php echo esc_html( $bloghash_pyml_title ); ?></span>
						<?php endif; ?>
					</div>
					<div class="bloghash-flex-row gy-4">
						<?php echo wp_kses_post( $bloghash_pyml_items_html ); ?>
					</div>
				</div>
			</div>
		</div><!-- END .bloghash-card-items -->
	</div>
</div><!-- END .bloghash-pyml -->
