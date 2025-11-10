<?php
/**
 * CoverNewws and Blockspare content pattern.
 *
 * @package MoreNews
 */

return array(
	'title'      => __( 'Section 4', 'morenews' ),
    'categories' => array( 'morenews' ),
	'content'    => '<!-- wp:group {"align":"full","className":"row","layout":{"type":"constrained"}} -->
    <div class="wp-block-group alignfull row">
    
    <!-- wp:columns {"align":"full"} -->
    <div class="wp-block-columns alignfull"><!-- wp:column {"width":"70%"} -->
    <div class="wp-block-column" style="flex-basis:70%"><!-- wp:group {"align":"wide","layout":{"type":"constrained"}} -->
    <div class="wp-block-group alignwide"><!-- wp:heading -->
    <h2 class="wp-block-heading">' . esc_html__( 'Express Tile', 'morenews' ) . '</h2>
    <!-- /wp:heading -->
    
    <!-- wp:blockspare/blockspare-banner-1 {"align":"full","uniqueClass":"blockspare-15c6dbca-9873-4","sliderCategoryFontWeight":"600","editorCategoryFontWeight":"600","marginTop":0,"marginBottom":28,"gutter":15} /--></div>
    <!-- /wp:group -->
    
    <!-- wp:group {"align":"wide","layout":{"type":"constrained"}} -->
    <div class="wp-block-group alignwide"><!-- wp:heading {"align":"wide"} -->
    <h2 class="wp-block-heading alignwide">' . esc_html__( 'Single Column', 'morenews' ) . ' </h2>
    <!-- /wp:heading -->
    
    <!-- wp:blockspare/blockspare-latest-posts-list {"uniqueClass":"blockspare-fee75435-bdee-4","postsToShow":3,"displayPostExcerpt":true,"postTitleFontSize":18,"linkColor":"#505050","align":"wide","imageSize":"medium","marginTop":0,"marginBottom":0,"backGroundColor":"#ffffff","contentPaddingTop":0,"contentPaddingBottom":0,"categoryBackgroundColor":"#003bb3","titleOnHoverColor":"#003bb3","animation":"AFTfadeInRight","ImageUnit":"75","gutterSpace":15} /--></div>
    <!-- /wp:group --></div>
    <!-- /wp:column -->
    
    <!-- wp:column {"width":"30%","className":"sidebar-sticky-top"} -->
    <div class="wp-block-column sidebar-sticky-top" style="flex-basis:30%"><!-- wp:group {"align":"wide","layout":{"type":"constrained"}} -->
    <div class="wp-block-group alignwide"><!-- wp:heading {"align":"wide"} -->
    <h2 class="wp-block-heading alignwide">' . esc_html__( 'Advertisement', 'morenews' ) . '</h2>
    <!-- /wp:heading -->
    
    <!-- wp:image {"id":1175,"sizeSlug":"medium","linkDestination":"none"} -->
    <figure class="wp-block-image size-medium"><img src="' . esc_url( get_template_directory_uri() ) . '/assets/img/square-promo.jpg" alt="" class="wp-image-1175"/></figure>
    <!-- /wp:image --></div>
    <!-- /wp:group -->
    
    <!-- wp:group {"align":"wide","layout":{"type":"constrained"}} -->
    <div class="wp-block-group alignwide"><!-- wp:heading -->
    <h2 class="wp-block-heading">' . esc_html__( 'Post Grid', 'morenews' ) . '</h2>
    <!-- /wp:heading -->
    
    <!-- wp:blockspare/blockspare-latest-posts-grid {"uniqueClass":"blockspare-187a9d93-7a33-4","displayPostAuthor":false,"postTitleFontSize":14,"linkColor":"#505050","displayPostCategory":false,"marginTop":0,"marginBottom":28,"backGroundColor":"#ffffff","enableComment":false,"titleOnHoverColor":"#1f1f1f","animation":"AFTfadeInUp","gutterSpace":15} /--></div>
    <!-- /wp:group -->
    
    <!-- wp:group -->
    <div class="wp-block-group"><!-- wp:heading -->
    <h2 class="wp-block-heading">' . esc_html__( 'Categories', 'morenews' ) . '</h2>
    <!-- /wp:heading -->
    
    <!-- wp:tag-cloud {"taxonomy":"category","className":"is-style-outline"} /--></div>
    <!-- /wp:group --></div>
    <!-- /wp:column --></div>
    <!-- /wp:columns --></div>
    <!-- /wp:group -->',
	
);
