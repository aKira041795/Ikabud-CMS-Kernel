<?php
/**
 * The template for displaying Related posts on post details page.
 *
 * @package     Bloghash
 * @author      Peregrine Themes
 * @since       1.0.0
 */


// Setup Related posts.

if ( ! bloghash_option( 'related_posts_enable' ) ) {
	return;
}
$numbre_of_posts = bloghash_option( 'related_post_number' );
$numbre_of_posts = $numbre_of_posts ? $numbre_of_posts : 3;
$bloghash_args   = array(
	'post_type'           => 'post',
	'post_status'         => 'publish',
	'posts_per_page'      => $numbre_of_posts, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page
	'orderby'             => 'date',
	'ignore_sticky_posts' => true,
	'category__in'        => wp_get_post_categories( get_the_ID() ),
	'post__not_in'        => array( get_the_ID() ),
	'tax_query'           => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
		array(
			'taxonomy' => 'post_format',
			'field'    => 'slug',
			'terms'    => array( 'post-format-quote' ),
			'operator' => 'NOT IN',
		),
	),
);

$bloghash_args = apply_filters( 'bloghash_related_posts_query_args', $bloghash_args );

$bloghash_posts = new WP_Query( $bloghash_args );

// No posts found.
if ( ! $bloghash_posts->have_posts() ) {
	return;
}

$bloghash_related_posts_items_html = '';
$col                               = bloghash_option( 'related_posts_column' );
while ( $bloghash_posts->have_posts() ) :
	$bloghash_posts->the_post();

	// Post items HTML markup.
	ob_start();
	?>

	<div class="col-md-<?php echo esc_attr( $col ); ?> col-sm-6 col-xs-12">
		<div class="bloghash-post-item style-1 end rounded">
			<div class="bloghash-post-thumb">
				<a href="<?php echo esc_url( bloghash_entry_get_permalink() ); ?>" tabindex="0"></a>
				<div class="inner"><?php the_post_thumbnail( get_the_ID(), 'full' ); ?></div>
			</div><!-- END .bloghash-post-thumb -->
			<div class="bloghash-post-content">
							
				<div class="post-category">
					<?php bloghash_entry_meta_category( ' ', false, apply_filters( 'bloghash_pyml_category_limit', 3 ) ); ?>
				</div>

				<?php get_template_part( 'template-parts/entry/entry-header' ); ?>

				<div class="entry-meta">
					<div class="entry-meta-elements">
						<?php
						bloghash_entry_meta_author();
						?>
					</div>
				</div><!-- END .entry-meta -->

			</div><!-- END .bloghash-post-content -->			
		</div><!-- END .bloghash-post-item -->
	</div>
	<?php
	$bloghash_related_posts_items_html .= ob_get_clean();
endwhile;

// Restore original Post Data.
wp_reset_postdata();

// Title.
$bloghash_related_posts_title = bloghash_option( 'related_posts_heading' );

?>
<div id="related_posts" class="mt-5">
	<div class="bloghash-rp slider-overlay-1 <?php echo esc_attr( $bloghash_classes ); ?>">
		<div class="bloghash-rp-container">
			<div class="bloghash-flex-row">
				<div class="col-xs-12">
					<div class="bloghash-card-items">
						<div class="h4 widget-title">
							<?php if ( $bloghash_related_posts_title ) : ?>
								<?php echo esc_html( $bloghash_related_posts_title ); ?>
							<?php endif; ?>
						</div>
						<div class="bloghash-flex-row gy-4">
							<?php echo $bloghash_related_posts_items_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						</div>
					</div>
				</div>
			</div><!-- END .bloghash-card-items -->
		</div>
	</div><!-- END .bloghash-rp -->
</div><!-- END #related_posts -->
