<?php
/**
 * The template for displaying Featured Links.
 *
 * @package     Bloghash
 * @author      Peregrine Themes
 * @since       1.0.0
 */


$bloghash_featured_links_title_type = bloghash_option( 'featured_links_title_type' );
$bloghash_featured_links_items_html = '';

$bloghash_featured_column = 'col-md-4 col-sm-6 col-xs-12';
foreach ( $args['features'] as $key => $feature ) :

	// Post items HTML markup.
	ob_start();

	?>
	
	<div id="bloghsah-featured-item-<?php echo esc_attr( $key ); ?>" class="<?php echo esc_attr( $bloghash_featured_column ); ?>">
		<div class="bloghash-post-item style-1 center">
			<div class="bloghash-post-thumb">
				<div class="inner bloghsah-featured-item-image">
					<?php
					if ( ! empty( $feature['image']['id'] ) ) :
						echo wp_get_attachment_image( $feature['image']['id'], 'large' );
					endif;
					?>
				</div>
			</div><!-- END .bloghash-post-thumb-->
			<div class="bloghash-post-content">

				<?php
				if ( ! empty( $feature['link'] ) ) :
					if ( '1' == $bloghash_featured_links_title_type ) :
						printf( '<a href="%1$s" class="bloghash-btn btn-small btn-white" title="%2$s" target="%3$s">%4$s</a>', esc_url_raw( $feature['link']['url'] ), esc_attr( $feature['link']['title'] ), esc_attr( $feature['link']['target'] ), esc_html( $feature['link']['title'] ) );
						?>
						<?php
					endif;
				endif;
				?>
			</div><!-- END .bloghash-post-content -->
		</div><!-- END .bloghash-post-item -->
	</div>
	<?php
	$bloghash_featured_links_items_html .= ob_get_clean();
endforeach;

// Restore original Post Data.
wp_reset_postdata();

// Title.
$bloghash_featured_links_title = bloghash_option( 'featured_links_title' );

// Classes.
$bloghash_classes  = '';
$bloghash_classes .= bloghash_option( 'featured_links_card_border' ) ? ' bloghash-card__boxed' : '';
$bloghash_classes .= bloghash_option( 'featured_links_card_shadow' ) ? ' bloghash-card-shadow' : '';

?>

<div class="bloghash-featured featured-one slider-overlay-1 <?php echo esc_attr( $bloghash_classes ); ?>">
	<div class="bloghash-featured-container bloghash-container">
		<div class="bloghash-flex-row g-0">
			<div class="col-xs-12">
				<div class="bloghash-card-items">
					<?php if ( $bloghash_featured_links_title ) : ?>
					<div class="h4 widget-title">							
						<span><?php echo esc_html( $bloghash_featured_links_title ); ?></span>
					</div>
					<?php endif; ?>
					<div class="bloghash-flex-row gy-4">
						<?php echo wp_kses_post( $bloghash_featured_links_items_html ); ?>
					</div>
				</div>
			</div>
		</div><!-- END .bloghash-card-items -->
	</div>
</div><!-- END .bloghash-featured -->
