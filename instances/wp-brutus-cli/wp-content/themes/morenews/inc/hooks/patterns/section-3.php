<?php
/**
 * CoverNewws and Blockspare content pattern.
 *
 * @package MoreNews
 */

return array(
	'title'      => __( 'Section 3', 'morenews' ),
    'categories' => array( 'morenews' ),
	'content'    => '<!-- wp:group {"align":"full","className":"row","layout":{"type":"constrained"}} -->
    <div class="wp-block-group alignfull row">
    
       
    <!-- wp:columns {"align":"full"} -->
    <div class="wp-block-columns alignfull"><!-- wp:column {"width":"70%"} -->
    <div class="wp-block-column" style="flex-basis:70%"><!-- wp:group {"align":"wide","layout":{"type":"constrained"}} -->
    <div class="wp-block-group alignwide"><!-- wp:heading -->
    <h2 class="wp-block-heading">' . esc_html__( 'Post Slider', 'morenews' ) . '</h2>
    <!-- /wp:heading -->
    
    <!-- wp:blockspare/blockspare-posts-block-slider {"uniqueClass":"blockspare-789ea444-7e83-4","postTitleFontSize":32,"slider":"blockspare-posts-block-full-layout-4","marginTop":0,"marginBottom":28} /--></div>
    <!-- /wp:group -->
    
    <!-- wp:group {"align":"wide","layout":{"type":"constrained"}} -->
    <div class="wp-block-group alignwide"><!-- wp:heading -->
    <h2 class="wp-block-heading">' . esc_html__( 'Post Grid', 'morenews' ) . '</h2>
    <!-- /wp:heading -->
    
    <!-- wp:blockspare/blockspare-latest-posts-grid {"uniqueClass":"blockspare-5606f516-29c9-4","postsToShow":6,"linkColor":"#505050","columns":3,"marginTop":0,"marginBottom":0,"backGroundColor":"#ffffff","categoryBackgroundColor":"#e91802","titleOnHoverColor":"#e91802","animation":"AFTfadeInUp","gutterSpace":15} /--></div>
    <!-- /wp:group --></div>
    <!-- /wp:column -->
    
    <!-- wp:column {"width":"30%","className":"sidebar-sticky-top"} -->
    <div class="wp-block-column sidebar-sticky-top" style="flex-basis:30%"><!-- wp:group {"align":"wide","layout":{"type":"constrained"}} -->
    <div class="wp-block-group alignwide"><!-- wp:heading {"align":"wide"} -->
    <h2 class="wp-block-heading alignwide">' . esc_html__( 'Post List', 'morenews' ) . '</h2>
    <!-- /wp:heading -->
    
    <!-- wp:blockspare/blockspare-latest-posts-list {"uniqueClass":"blockspare-beaa1b5d-2a0d-4","displayPostDate":false,"displayPostAuthor":false,"postTitleFontSize":14,"displayPostCategory":false,"align":"wide","imageSize":"thumbnail","marginTop":0,"marginBottom":28,"backGroundColor":"#ffffff","contentPaddingTop":0,"contentPaddingBottom":0,"enableComment":false,"titleOnHoverColor":"#1f1f1f","animation":"AFTfadeInRight","ImageUnit":"75","gutterSpace":15} /--></div>
    <!-- /wp:group -->
    
    <!-- wp:group {"align":"wide","layout":{"type":"constrained"}} -->
    <div class="wp-block-group alignwide"><!-- wp:heading {"align":"wide"} -->
    <h2 class="wp-block-heading alignwide">' . esc_html__( 'Connect to Us', 'morenews' ) . '</h2>
    <!-- /wp:heading -->
    
    <!-- wp:social-links {"iconBackgroundColor":{},"className":"is-style-pill-shape","layout":{"type":"flex","justifyContent":"left"}} -->
    <ul class="wp-block-social-links is-style-pill-shape"><!-- wp:social-link {"url":"#","service":"facebook"} /-->
    
    <!-- wp:social-link {"url":"#","service":"pinterest"} /-->
    
    <!-- wp:social-link {"url":"#","service":"google"} /-->
    
    <!-- wp:social-link {"url":"#","service":"flickr"} /-->
    
    <!-- wp:social-link {"url":"#","service":"linkedin"} /-->
    
    <!-- wp:social-link {"url":"#","service":"soundcloud"} /-->
    
    <!-- wp:social-link {"url":"#","service":"telegram"} /-->
    
    <!-- wp:social-link {"url":"#","service":"tiktok"} /-->
    
    <!-- wp:social-link {"url":"#","service":"yelp"} /-->
    
    <!-- wp:social-link {"url":"#","service":"vimeo"} /-->
    
    <!-- wp:social-link {"url":"#","service":"tumblr"} /-->
    
    <!-- wp:social-link {"url":"#","service":"youtube"} /-->
    
    <!-- wp:social-link {"url":"#","service":"vimeo"} /-->
    
    <!-- wp:social-link {"url":"#","service":"vk"} /-->
    
    <!-- wp:social-link {"url":"#","service":"twitter"} /--></ul>
    <!-- /wp:social-links --></div>
    <!-- /wp:group -->
    
    <!-- wp:group {"align":"wide","layout":{"type":"constrained"}} -->
    <div class="wp-block-group alignwide"><!-- wp:heading {"align":"wide"} -->
    <h2 class="wp-block-heading alignwide">' . esc_html__( 'Advertisement', 'morenews' ) . '</h2>
    <!-- /wp:heading -->
    
    <!-- wp:image {"id":1175,"sizeSlug":"medium","linkDestination":"none"} -->
    <figure class="wp-block-image size-medium"><img src="' . esc_url( get_template_directory_uri() ) . '/assets/img/square-promo.jpg" alt="" class="wp-image-1175"/></figure>
    <!-- /wp:image --></div>
    <!-- /wp:group --></div>
    <!-- /wp:column --></div>
    <!-- /wp:columns -->
    
   
    
    </div>
    <!-- /wp:group -->',
	
);
