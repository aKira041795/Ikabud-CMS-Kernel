<?php
/**
 * CoverNewws and Blockspare content pattern.
 *
 * @package MoreNews
 */

return array(
	'title'      => __( 'Full', 'morenews' ),
    'categories' => array( 'morenews' ),
	'content'    => '<!-- wp:group {"align":"full","className":"row","layout":{"type":"constrained"}} -->
    <div class="wp-block-group alignfull row"><!-- wp:group {"align":"full","layout":{"type":"constrained"}} -->
    <div class="wp-block-group alignfull"><!-- wp:blockspare/latest-posts-flash {"align":"full","uniqueClass":"blockspare-1bd68af2-6529-4","exclusiveColor":"#ffffff","exclusiveBgColor":"#e91802","marginTop":40,"backGroundColor":"#ffffff","titleOnHoverColor":"#003bb3","animation":"AFTfadeInDown","exclusiveSubtitle":true,"exclusiveFontWeight":"700","newsBgColor":"#003bb3"} /-->
    
    <!-- wp:blockspare/blockspare-banner-2 {"align":"full","uniqueClass":"blockspare-9d067edc-de4b-4","sliderCategoryBackgroundColor":"#e91802","sliderTitleFontSizeTablet":27,"sliderEnableAutoPlay":false,"sliderCategoryFontWeight":"600","editorCategoryBackgroundColor":"#003bb3","editorCategoryFontWeight":"600","animation":"AFTfadeInUp","marginTop":20,"marginBottom":28,"gutter":15} /--></div>
    <!-- /wp:group -->
    
    <!-- wp:group {"align":"full","layout":{"type":"constrained"}} -->
    <div class="wp-block-group alignfull"><!-- wp:heading {"align":"full"} -->
    <h2 class="wp-block-heading alignfull">' . esc_html__( 'Featured News', 'morenews' ) . '</h2>
    <!-- /wp:heading -->
    
    <!-- wp:blockspare/blockspare-latest-posts-grid {"categories":[],"taxType":"","uniqueClass":"blockspare-84a98b29-8639-4","linkColor":"#505050","columns":4,"align":"full","imageSize":"medium","marginTop":0,"marginBottom":28,"backGroundColor":"#ffffff","categoryBorderRadius":1,"titleOnHoverColor":"#1f1f1f","animation":"AFTfadeInDown","gutterSpace":15} /--></div>
    <!-- /wp:group -->
    
    <!-- wp:group {"align":"full","layout":{"type":"constrained"}} -->
    <div class="wp-block-group alignfull"><!-- wp:columns {"align":"full"} -->
    <div class="wp-block-columns alignfull"><!-- wp:column {"width":"70%"} -->
    <div class="wp-block-column" style="flex-basis:70%"><!-- wp:group {"align":"wide","layout":{"type":"constrained"}} -->
    <div class="wp-block-group alignwide"><!-- wp:heading -->
    <h2 class="wp-block-heading">' . esc_html__( 'Express Grid', 'morenews' ) . '</h2>
    <!-- /wp:heading -->
    
    <!-- wp:blockspare/latest-posts-express-grid {"uniqueClass":"blockspare-a0448ea4-fd9f-4","postsToShow":3,"displayPostAuthor":false,"postTitleFontSize":14,"linkColor":"#505050","express":"blockspare-posts-block-express-grid-layout-2","excerptLength":30,"marginTop":0,"marginBottom":0,"backGroundColor":"#ffffff","categoryBackgroundColor":"#003bb3","spostTitleFontSize":27,"titleOnHoverColor":"#003bb3","animation":"AFTfadeInLeft","gutterSpace":15} /--></div>
    <!-- /wp:group --></div>
    <!-- /wp:column -->
    
    <!-- wp:column {"width":"30%","className":"sidebar-sticky-top"} -->
    <div class="wp-block-column sidebar-sticky-top" style="flex-basis:30%"><!-- wp:group -->
    <div class="wp-block-group"><!-- wp:heading -->
    <h2 class="wp-block-heading">' . esc_html__( 'About Author', 'morenews' ) . '</h2>
    <!-- /wp:heading -->
    
    <!-- wp:blockspare/user-profile {"uniqueClass":"blockspare-5bc1b8ab-e212-4","sectionAlignment":"center","headerTitle":"AF themes","titleFontSize":24,"headerSubTitle":"themes.plugins.support","subTitleFontSize":14,"profileContent":"\u003cp\u003eWe mainly focus on quality code and elegant design with incredible support. Our WordPress themes and plugins empower you to create an elegant, professional, and easy-to-maintain website in no time at all. Even on mobile and tablet screens, your website will look great\u003c/p\u003e","profileBackgroundColor":"#ffffff","facebook":"","twitter":"","instagram":"","linkedin":"","youtube":"","paddingTop":30,"paddingRight":30,"paddingBottom":30,"paddingLeft":30,"marginTop":0,"marginBottom":0,"descriptionFontSize":14,"descriptionMarginTop":30,"descriptionMarginBottom":20} -->
    <div class="wp-block-blockspare-user-profile aligncenter blockspare-5bc1b8ab-e212-4 blockspare-authorprofile authorbox" blockspare-animation=""><div class="blockspare-section-wrapper"><style>.blockspare-5bc1b8ab-e212-4 .blockspare-author-wrapper{background-color:#ffffff;padding-top:30px;padding-right:30px;padding-bottom:30px;padding-left:30px;border-radius:0px;margin-top:0px;margin-right:0px;margin-bottom:0px;margin-left:0px}.blockspare-5bc1b8ab-e212-4 .blockspare-author-wrapper .blockspare-user-profile-desc{margin-top:30px;margin-right:0px;margin-bottom:20px;margin-left:0px}.blockspare-5bc1b8ab-e212-4 .blockspare-block-profile{color:#6d6d6d}.blockspare-5bc1b8ab-e212-4 .blockspare-profile-text-description{font-size:14px}.blockspare-5bc1b8ab-e212-4 .blockspare-section-head-wrap{background-color:transparent;text-align:center;margin-top:0px;margin-right:0px;margin-bottom:0px;margin-left:0px}.blockspare-5bc1b8ab-e212-4 .blockspare-section-head-wrap .blockspare-title{color:#1f1f1f;font-size:24px;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px}.blockspare-5bc1b8ab-e212-4 .blockspare-section-head-wrap .blockspare-subtitle{color:#6d6d6d;font-size:14px;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px}.blockspare-5bc1b8ab-e212-4 .blockspare-user-profile-desc{font-size:14px}.blockspare-5bc1b8ab-e212-4 .blockspare-section-head-wrap.blockspare-style2 .blockspare-title-dash,.blockspare-5bc1b8ab-e212-4 .blockspare-section-head-wrap.blockspare-style4 .blockspare-title-dash,.blockspare-5bc1b8ab-e212-4 .blockspare-section-head-wrap.blockspare-style5 .blockspare-title-dash,.blockspare-5bc1b8ab-e212-4 .blockspare-section-head-wrap.blockspare-style6 .blockspare-title-dash,.blockspare-5bc1b8ab-e212-4 .blockspare-section-head-wrap.blockspare-style7 .blockspare-title-dash,.blockspare-5bc1b8ab-e212-4 .blockspare-section-head-wrap.blockspare-style8 .blockspare-title-dash,.blockspare-5bc1b8ab-e212-4 .blockspare-section-head-wrap.blockspare-style9 .blockspare-title-dash,.blockspare-5bc1b8ab-e212-4 .blockspare-section-head-wrap.blockspare-style16 .blockspare-title-wrapper .blockspare-lower-dash,.blockspare-5bc1b8ab-e212-4 .blockspare-section-head-wrap.blockspare-style16 .blockspare-title-wrapper .blockspare-upper-dash{color:#8b249c}.blockspare-5bc1b8ab-e212-4 .blockspare-section-head-wrap.blockspare-style10 .blockspare-title-wrapper .blockspare-lower-dash::before,.blockspare-5bc1b8ab-e212-4 .blockspare-section-head-wrap.blockspare-style11 .blockspare-title-wrapper .blockspare-lower-dash,.blockspare-5bc1b8ab-e212-4 .blockspare-section-head-wrap.blockspare-style13 .blockspare-title-wrapper .blockspare-upper-dash,.blockspare-5bc1b8ab-e212-4 .blockspare-section-head-wrap.blockspare-style14 .blockspare-title-wrapper .blockspare-lower-dash,.blockspare-5bc1b8ab-e212-4 .blockspare-section-head-wrap.blockspare-style15 .blockspare-title-wrapper .blockspare-lower-dash,.blockspare-5bc1b8ab-e212-4 .blockspare-section-head-wrap.blockspare-style15.blockspare-center .blockspare-title-wrapper .blockspare-upper-dash,.blockspare-5bc1b8ab-e212-4 .blockspare-section-head-wrap.blockspare-style17 .blockspare-title-wrapper .blockspare-title,.blockspare-5bc1b8ab-e212-4 .blockspare-section-head-wrap.blockspare-style18 .blockspare-title-wrapper .blockspare-title,.blockspare-5bc1b8ab-e212-4 .blockspare-section-head-wrap.blockspare-style17 .blockspare-title-wrapper .blockspare-title,.blockspare-5bc1b8ab-e212-4 .blockspare-section-head-wrap.blockspare-style19 .blockspare-title-wrapper .blockspare-title:before,.blockspare-5bc1b8ab-e212-4 .blockspare-section-head-wrap.blockspare-style20 .blockspare-title-wrapper .blockspare-lower-dash{background-color:#8b249c}.blockspare-5bc1b8ab-e212-4 .blockspare-section-head-wrap.blockspare-style12 .blockspare-title-wrapper .blockspare-title,.blockspare-5bc1b8ab-e212-4 .blockspare-section-head-wrap.blockspare-style18 .blockspare-title-wrapper .blockspare-lower-dash{border-bottom-color:#8b249c}.blockspare-5bc1b8ab-e212-4 .blockspare-section-head-wrap.blockspare-style17 .blockspare-title-wrapper .blockspare-title:before{border-top-color:#8b249c}.blockspare-5bc1b8ab-e212-4 .blockspare-section-head-wrap.blockspare-style10 .blockspare-title-wrapper .blockspare-lower-dash,.blockspare-5bc1b8ab-e212-4 .blockspare-section-head-wrap.blockspare-style12 .blockspare-title-wrapper .blockspare-lower-dash,.blockspare-5bc1b8ab-e212-4 .blockspare-section-head-wrap.blockspare-style13 .blockspare-title-wrapper .blockspare-lower-dash,.blockspare-5bc1b8ab-e212-4 .blockspare-section-head-wrap.blockspare-style14 .blockspare-title-wrapper .blockspare-upper-dash{background-color:#E5EFE3}@media screen and (max-width:1025px){.blockspare-5bc1b8ab-e212-4 .blockspare-section-head-wrap .blockspare-title{font-size:26px}.blockspare-5bc1b8ab-e212-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:16px}.blockspare-5bc1b8ab-e212-4 .blockspare-user-profile-desc{font-size:14px}}@media screen and (max-width:768px){.blockspare-5bc1b8ab-e212-4 .blockspare-section-head-wrap .blockspare-title{font-size:20px}.blockspare-5bc1b8ab-e212-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}.blockspare-5bc1b8ab-e212-4 .blockspare-user-profile-desc{font-size:14px}}</style><div class="blockspare-author-wrapper blockspare-blocks blockspare-hover-item"><div class="blockspare-layout-center blockspare-block-profile blockspare-profile-columns"><div class="blockspare-profile-column blockspare-profile-avatar-wrap"><div class="blockspare-profile-image-wrap"><!-- wp:image {"align":"center","id":552,"width":200,"height":200,"scale":"cover","sizeSlug":"medium","linkDestination":"none","className":"is-style-rounded"} -->
    <figure class="wp-block-image aligncenter size-medium is-resized is-style-rounded"><img src="' . esc_url( get_template_directory_uri() ) . '/assets/img/author-profile.jpg" alt="" class="wp-image-552" style="object-fit:cover;width:200px;height:200px" width="200" height="200"/></figure>
    <!-- /wp:image --></div></div><div class="blockspare-profile-column blockspare-profile-content-wrap"><div class="blockspare-section-header-wrapper blockspare-blocks"><div class="blockspare-section-head-wrap blockspare-style1 blockspare-center"><div class="blockspare-title-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><h2 class="blockspare-title">AF themes</h2><span class="blockspare-title-dash blockspare-lower-dash"></span></div><div class="blockspare-subtitle-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><p class="blockspare-subtitle">themes.plugins.support</p><span class="blockspare-title-dash blockspare-lower-dash"></span></div></div></div><div class="blockspare-profile-text blockspare-user-profile-desc"><p>' . esc_html__( 'We mainly focus on quality code and elegant design with incredible support. Our WordPress themes and plugins empower you to create an elegant, professional, and easy-to-maintain website in no time at all. Even on mobile and tablet screens, your website will look great', 'morenews' ) . '</p></div><ul class="blockspare-social-links blockspare-default-official-color blockspare-social-icon-square blockspare-social-icon-small blockspare-icon-only blockspare-social-icon-solid blockspare-social-links-horizontal"></ul></div></div></div></div></div>
    <!-- /wp:blockspare/user-profile --></div>
    <!-- /wp:group --></div>
    <!-- /wp:column --></div>
    <!-- /wp:columns --></div>
    <!-- /wp:group -->
    
    <!-- wp:group {"align":"full","layout":{"type":"constrained"}} -->
    <div class="wp-block-group alignfull"><!-- wp:heading {"align":"full"} -->
    <h2 class="wp-block-heading alignfull">' . esc_html__( 'Three Columns', 'morenews' ) . ' </h2>
    <!-- /wp:heading -->
    
    <!-- wp:blockspare/blockspare-latest-posts-list {"uniqueClass":"blockspare-b9cd4f9b-525d-4","postsToShow":9,"displayPostDate":false,"displayPostAuthor":false,"postTitleFontSize":14,"linkColor":"#505050","displayPostCategory":false,"columns":"list-col-3","align":"full","imageSize":"thumbnail","marginTop":20,"marginBottom":28,"backGroundColor":"#ffffff","contentPaddingTop":0,"contentPaddingBottom":0,"categoryBackgroundColor":"#003bb3","enableComment":false,"titleOnHoverColor":"#1f1f1f","animation":"AFTfadeInRight","ImageUnit":"75","gutterSpace":15} /--></div>
    <!-- /wp:group -->
    
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
    
    <!-- wp:group {"align":"full","layout":{"type":"constrained"}} -->
    <div class="wp-block-group alignfull"><!-- wp:heading {"align":"full"} -->
    <h2 class="wp-block-heading alignfull">' . esc_html__( 'Post Carousel', 'morenews' ) . '</h2>
    <!-- /wp:heading -->
    
    <!-- wp:blockspare/latest-posts-block-carousel-grid {"uniqueClass":"blockspare-eec4157b-b0ef-4","postsToShow":5,"linkColor":"#505050","align":"full","marginTop":0,"marginBottom":28,"backGroundColor":"#ffffff","categoryTextColor":"#ffffff","categoryBackgroundColor":"#003bb3","gutterSpace":3,"numberofSlide":4,"titleOnHoverColor":"#003bb3","animation":"AFTfadeInLeft"} /--></div>
    <!-- /wp:group -->
    
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
