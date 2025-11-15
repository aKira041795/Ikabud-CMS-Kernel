<?php
/**
 * CoverNewws and Blockspare content pattern.
 *
 * @package MoreNews
 */

return array(
	'title'      => __( 'Post List with Dark Background', 'morenews' ),
    'categories' => array( 'morenews' ),
	'content'    => '<!-- wp:query {"queryId":20,"query":{"perPage":6,"pages":0,"offset":0,"postType":"post","order":"desc","orderBy":"date","author":"","search":"","exclude":[],"sticky":"exclude","inherit":false},"metadata":{"categories":["morenews"],"patternName":"morenews/theme-block-10","name":"Grid"}} -->
    <div class="wp-block-query"><!-- wp:group {"style":{"spacing":{"margin":{"bottom":"20px"},"blockGap":"20px","padding":{"top":"20px","bottom":"20px","left":"20px","right":"20px"}},"color":{"background":"#252525"}},"layout":{"type":"constrained"}} -->
    <div class="wp-block-group has-background" style="background-color:#252525;margin-bottom:20px;padding-top:20px;padding-right:20px;padding-bottom:20px;padding-left:20px"><!-- wp:heading {"align":"wide","style":{"elements":{"link":{"color":{"text":"#fefefe"}}},"color":{"text":"#fefefe"}}} -->
    <h2 class="wp-block-heading alignwide has-text-color has-link-color" style="color:#fefefe">Post List</h2>
    <!-- /wp:heading -->
    
    <!-- wp:post-template {"align":"wide","style":{"spacing":{"blockGap":"20px"}},"fontSize":"small","layout":{"type":"grid","columnCount":"2"}} -->
    <!-- wp:group {"style":{"spacing":{"padding":{"top":"0px","right":"0px","bottom":"0px","left":"0px"},"margin":{"top":"0","bottom":"0"},"blockGap":"15px"}},"layout":{"type":"flex","flexWrap":"nowrap","justifyContent":"space-between"}} -->
    <div class="wp-block-group" style="margin-top:0;margin-bottom:0;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px"><!-- wp:post-featured-image {"isLink":true,"aspectRatio":"3/2","sizeSlug":"medium","style":{"layout":{"selfStretch":"fixed","flexSize":"50%"},"spacing":{"margin":{"top":"0","bottom":"0","left":"0","right":"0"}}}} /-->
    
    <!-- wp:group {"style":{"layout":{"selfStretch":"fixed","flexSize":"50%"},"spacing":{"blockGap":"10px"}},"layout":{"type":"constrained"}} -->
    <div class="wp-block-group"><!-- wp:post-title {"isLink":true,"style":{"typography":{"fontSize":"16px"},"elements":{"link":{"color":{"text":"#fefefe"},":hover":{"color":{"text":"#007acc"}}}},"color":{"text":"#fefefe"}}} /-->
    
    <!-- wp:post-date {"style":{"typography":{"fontSize":"12px"},"elements":{"link":{"color":{"text":"#fefefe"}}},"color":{"text":"#fefefe"}}} /--></div>
    <!-- /wp:group --></div>
    <!-- /wp:group -->
    <!-- /wp:post-template --></div>
    <!-- /wp:group --></div>
    <!-- /wp:query -->',
	
);
