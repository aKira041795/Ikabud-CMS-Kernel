<?php
/**
 * CoverNewws and Blockspare content pattern.
 *
 * @package MoreNews
 */

return array(
	'title'      => __( 'Section 1', 'morenews' ),
    'categories' => array( 'morenews' ),
	'content'    => '<!-- wp:group {"align":"full","className":"row","layout":{"type":"constrained"}} -->
    <div class="wp-block-group alignfull row"><!-- wp:group {"align":"full","layout":{"type":"constrained"}} -->
    <div class="wp-block-group alignfull"><!-- wp:blockspare/latest-posts-flash {"align":"full","uniqueClass":"blockspare-1bd68af2-6529-4","exclusiveColor":"#ffffff","exclusiveBgColor":"#e91802","marginTop":40,"backGroundColor":"#ffffff","titleOnHoverColor":"#003bb3","animation":"AFTfadeInDown","exclusiveSubtitle":true,"exclusiveFontWeight":"700","newsBgColor":"#003bb3"} /-->
    
    <!-- wp:blockspare/blockspare-banner-2 {"align":"full","uniqueClass":"blockspare-9d067edc-de4b-4","sliderCategoryBackgroundColor":"#e91802","sliderTitleFontSizeTablet":27,"sliderEnableAutoPlay":false,"sliderCategoryFontWeight":"600","editorCategoryBackgroundColor":"#003bb3","editorCategoryFontWeight":"600","animation":"AFTfadeInUp","marginTop":20,"marginBottom":28,"gutter":15} /--></div>
    <!-- /wp:group -->   
      
    </div>
    <!-- /wp:group -->',
	
);
