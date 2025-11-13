<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Blockspare_Header_Template_Block' ) ) {

	class Blockspare_Header_Template_Block extends Blockspare_Import_Block_Base{
		public static function get_instance() {

			static $instance = null;
			if ( null === $instance ) {
				$instance = new self();
			}
			return $instance;

		}
        public function add_block_template_library( $blocks_lists ){

            $block_library_list = array(
					array(
						'type'     => 'header',
                        'pages'    =>'agency',
						'item'     => ['Header'],
						'key'      => 'bs_header_1',
						'name'     => 'Agency Header',
						'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-header/agency-header/",
						'content'  => '<!-- wp:blockspare/blockspare-container {"paddingTop":0,"paddingRight":0,"paddingBottom":0,"paddingLeft":0,"marginTop":0,"marginBottom":0,"uniqueClass":"blockspare-79717f84-9c42-4","backGroundColor":"#ffffff"} -->
                        <div class="wp-block-blockspare-blockspare-container alignfull blockspare-79717f84-9c42-4" blockspare-animation=""><style>.blockspare-79717f84-9c42-4 > .blockspare-block-container-wrapper{background-color:#ffffff;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px;margin-top:0px;margin-right:0px;margin-bottom:0px;margin-left:0px;border-radius:0}.blockspare-79717f84-9c42-4 .blockspare-image-wrap{background-image:none}</style><div class="blockspare-block-container-wrapper blockspare-hover-item"><div class="blockspare-container-background blockspare-image-wrap has-background-opacity-100 has-background-opacity"></div><div class="blockspare-container"><div class="blockspare-inner-blocks blockspare-inner-wrapper-blocks"><!-- wp:group {"style":{"border":{"bottom":{"color":"#cccccc","width":"1px"}},"spacing":{"padding":{"top":"30px","bottom":"30px"}}},"backgroundColor":"base","layout":{"type":"constrained"}} -->
                        <div class="wp-block-group has-base-background-color has-background" style="border-bottom-color:#cccccc;border-bottom-width:1px;padding-top:30px;padding-bottom:30px"><!-- wp:columns {"verticalAlignment":"center","align":"wide"} -->
                        <div class="wp-block-columns alignwide are-vertically-aligned-center"><!-- wp:column {"verticalAlignment":"center","width":"70%"} -->
                        <div class="wp-block-column is-vertically-aligned-center" style="flex-basis:70%"><!-- wp:group {"layout":{"type":"flex","flexWrap":"nowrap"}} -->
                        <div class="wp-block-group"><!-- wp:site-logo {"shouldSyncIcon":false} /-->
                        
                        <!-- wp:navigation {"ref":331,"style":{"typography":{"fontStyle":"normal","fontWeight":"500","textTransform":"capitalize"}}} /--></div>
                        <!-- /wp:group --></div>
                        <!-- /wp:column -->
                        
                        <!-- wp:column {"verticalAlignment":"center","width":"30%"} -->
                        <div class="wp-block-column is-vertically-aligned-center" style="flex-basis:30%"><!-- wp:group {"layout":{"type":"constrained","justifyContent":"right"}} -->
                        <div class="wp-block-group"><!-- wp:blockspare/blockspare-buttons {"sectionAlignment":"right","uniqueClass":"blockspare-744c674c-5b95-4","buttonBackgroundColor":"#2e947d","buttonShape":"blockspare-button-shape-square","marginTop":0,"marginBottom":0} -->
                        <div class="wp-block-blockspare-blockspare-buttons blockspare-744c674c-5b95-4 blockspare-block-button-wrap" blockspare-animation=""><style>.blockspare-744c674c-5b95-4 .blockspare-block-button{text-align:right;margin-top:0px;margin-bottom:0px;margin-left:0px;margin-right:0px}.blockspare-744c674c-5b95-4 .blockspare-block-button span{color:#fff;border-width:2px;font-size:16px}.blockspare-744c674c-5b95-4 .blockspare-block-button .blockspare-button{background-color:#2e947d}.blockspare-744c674c-5b95-4 .blockspare-block-button .blockspare-button:visited{background-color:#2e947d}.blockspare-744c674c-5b95-4 .blockspare-block-button .blockspare-button:focus{background-color:#2e947d}.blockspare-744c674c-5b95-4 .blockspare-block-button i{font-size:16px}.blockspare-744c674c-5b95-4 .blockspare-block-button a{padding-top:12px;padding-bottom:12px;padding-right:22px;padding-left:22px}@media screen and (max-width:1025px){.blockspare-744c674c-5b95-4 .blockspare-block-button span{font-size:14px}.blockspare-744c674c-5b95-4 .blockspare-block-button i{font-size:14px}}@media screen and (max-width:768px){.blockspare-744c674c-5b95-4 .blockspare-block-button span{font-size:14px}.blockspare-744c674c-5b95-4 .blockspare-block-button i{font-size:14px}}</style><div class="blockspare-block-button"><a class="blockspare-button blockspare-button-shape-square blockspare-button-size-small"><span>Get Started</span></a></div></div>
                        <!-- /wp:blockspare/blockspare-buttons --></div>
                        <!-- /wp:group --></div>
                        <!-- /wp:column --></div>
                        <!-- /wp:columns --></div>
                        <!-- /wp:group --></div></div></div></div>
                        <!-- /wp:blockspare/blockspare-container -->',
						'imagePath'    => 'header',
                    ),
					array(
						'type'     => 'header',
                        'pages'    =>'lawyer',
						'item'     => ['Header'],
						'key'      => 'bs_header_2',
						'name'     => 'Lawyer Header',
						'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-header/lawyer-header/",
						'content'  => '<!-- wp:blockspare/blockspare-container {"paddingTop":0,"paddingRight":0,"paddingBottom":0,"paddingLeft":0,"marginTop":0,"marginBottom":0,"uniqueClass":"blockspare-eeeaf481-0d5c-4","backGroundColor":"#f6f6f6"} -->
                        <div class="wp-block-blockspare-blockspare-container alignfull blockspare-eeeaf481-0d5c-4" blockspare-animation=""><style>.blockspare-eeeaf481-0d5c-4 > .blockspare-block-container-wrapper{background-color:#f6f6f6;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px;margin-top:0px;margin-right:0px;margin-bottom:0px;margin-left:0px;border-radius:0}.blockspare-eeeaf481-0d5c-4 .blockspare-image-wrap{background-image:none}</style><div class="blockspare-block-container-wrapper blockspare-hover-item"><div class="blockspare-container-background blockspare-image-wrap has-background-opacity-100 has-background-opacity"></div><div class="blockspare-container"><div class="blockspare-inner-blocks blockspare-inner-wrapper-blocks"><!-- wp:group {"style":{"border":{"bottom":{"color":"#cccccc","width":"1px"}},"color":{"background":"#f5f5ef"}},"layout":{"type":"constrained"}} -->
                        <div class="wp-block-group has-background" style="border-bottom-color:#cccccc;border-bottom-width:1px;background-color:#f5f5ef"><!-- wp:columns {"verticalAlignment":"center","align":"wide"} -->
                        <div class="wp-block-columns alignwide are-vertically-aligned-center"><!-- wp:column {"verticalAlignment":"center","width":"70%"} -->
                        <div class="wp-block-column is-vertically-aligned-center" style="flex-basis:70%"><!-- wp:group {"layout":{"type":"flex","flexWrap":"nowrap"}} -->
                        <div class="wp-block-group"><!-- wp:site-logo {"shouldSyncIcon":false} /-->
                        
                        <!-- wp:navigation {"ref":408,"style":{"typography":{"fontStyle":"normal","fontWeight":"500","textTransform":"capitalize"}}} /--></div>
                        <!-- /wp:group --></div>
                        <!-- /wp:column -->
                        
                        <!-- wp:column {"verticalAlignment":"center","width":"30%"} -->
                        <div class="wp-block-column is-vertically-aligned-center" style="flex-basis:30%"><!-- wp:group {"layout":{"type":"constrained","justifyContent":"right"}} -->
                        <div class="wp-block-group"><!-- wp:blockspare/blockspare-buttons {"sectionAlignment":"right","uniqueClass":"blockspare-9242f689-cdc6-4","buttonText":"Take Appointment","buttonBackgroundColor":"#b69d74","buttonStyle":"solid","borderColor":"#b69d74"} -->
                        <div class="wp-block-blockspare-blockspare-buttons blockspare-9242f689-cdc6-4 blockspare-block-button-wrap" blockspare-animation=""><style>.blockspare-9242f689-cdc6-4 .blockspare-block-button{text-align:right;margin-top:30px;margin-bottom:30px;margin-left:0px;margin-right:0px}.blockspare-9242f689-cdc6-4 .blockspare-block-button span{color:#404040;font-size:16px}.blockspare-9242f689-cdc6-4 .blockspare-button{border-color:#b69d74;border-style:solid;border-width:2px}.blockspare-9242f689-cdc6-4 .blockspare-block-button .blockspare-button{background-color:transparent}.blockspare-9242f689-cdc6-4 .blockspare-block-button i{font-size:16px}.blockspare-9242f689-cdc6-4 .blockspare-block-button a{padding-top:12px;padding-bottom:12px;padding-right:22px;padding-left:22px}@media screen and (max-width:1025px){.blockspare-9242f689-cdc6-4 .blockspare-block-button span{font-size:14px}.blockspare-9242f689-cdc6-4 .blockspare-block-button i{font-size:14px}}@media screen and (max-width:768px){.blockspare-9242f689-cdc6-4 .blockspare-block-button span{font-size:14px}.blockspare-9242f689-cdc6-4 .blockspare-block-button i{font-size:14px}}</style><div class="blockspare-block-button"><a class="blockspare-button blockspare-button-shape-rounded blockspare-button-size-small"><span>Take Appointment</span></a></div></div>
                        <!-- /wp:blockspare/blockspare-buttons --></div>
                        <!-- /wp:group --></div>
                        <!-- /wp:column --></div>
                        <!-- /wp:columns --></div>
                        <!-- /wp:group --></div></div></div></div>
                        <!-- /wp:blockspare/blockspare-container -->',
						'imagePath'    => 'header',
                    ),
					array(
						'type'     => 'header',
                        'pages'    =>'restaurant',
						'item'     => ['Header'],
						'key'      => 'bs_header_3',
						'name'     => 'Restaurant Header',
						'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-header/restaurant-header/",
						'content'  => BLOCKSPARE_PRO_PATH,
						'imagePath'    => 'header',
                    ),
					array(
						'type'     => 'header',
                        'pages'    =>'apps',
						'item'     => ['Header'],
						'key'      => 'bs_header_4',
						'name'     => 'Apps Header',
						'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-header/apps-header/",
						'content'  => '<!-- wp:blockspare/blockspare-container {"paddingTop":0,"paddingRight":0,"paddingBottom":0,"paddingLeft":0,"marginTop":0,"marginBottom":0,"uniqueClass":"blockspare-acb217f6-bf23-4","backGroundColor":"#ffffff"} -->
                        <div class="wp-block-blockspare-blockspare-container alignfull blockspare-acb217f6-bf23-4" blockspare-animation=""><style>.blockspare-acb217f6-bf23-4 > .blockspare-block-container-wrapper{background-color:#ffffff;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px;margin-top:0px;margin-right:0px;margin-bottom:0px;margin-left:0px;border-radius:0}.blockspare-acb217f6-bf23-4 .blockspare-image-wrap{background-image:none}</style><div class="blockspare-block-container-wrapper blockspare-hover-item"><div class="blockspare-container-background blockspare-image-wrap has-background-opacity-100 has-background-opacity"></div><div class="blockspare-container"><div class="blockspare-inner-blocks blockspare-inner-wrapper-blocks"><!-- wp:group {"style":{"border":{"bottom":{"color":"#cccccc","width":"1px"}},"spacing":{"padding":{"top":"30px","bottom":"30px"}}},"backgroundColor":"base","layout":{"type":"constrained"}} -->
                        <div class="wp-block-group has-base-background-color has-background" style="border-bottom-color:#cccccc;border-bottom-width:1px;padding-top:30px;padding-bottom:30px"><!-- wp:columns {"verticalAlignment":"center","align":"wide"} -->
                        <div class="wp-block-columns alignwide are-vertically-aligned-center"><!-- wp:column {"verticalAlignment":"center","width":"70%"} -->
                        <div class="wp-block-column is-vertically-aligned-center" style="flex-basis:70%"><!-- wp:group {"layout":{"type":"flex","flexWrap":"nowrap"}} -->
                        <div class="wp-block-group"><!-- wp:site-logo {"shouldSyncIcon":false} /-->
                        
                        <!-- wp:navigation {"ref":421,"style":{"typography":{"fontStyle":"normal","fontWeight":"700","textTransform":"capitalize"}}} /--></div>
                        <!-- /wp:group --></div>
                        <!-- /wp:column -->
                        
                        <!-- wp:column {"verticalAlignment":"center","width":"30%"} -->
                        <div class="wp-block-column is-vertically-aligned-center" style="flex-basis:30%"><!-- wp:group {"layout":{"type":"constrained","justifyContent":"right"}} -->
                        <div class="wp-block-group"><!-- wp:blockspare/blockspare-buttons {"sectionAlignment":"right","uniqueClass":"blockspare-1c640cc4-5b76-4","buttonBackgroundColor":"#ea4b50","buttonShape":"blockspare-button-shape-circular","buttonFontFamily":"Poppins","buttonFontWeight":"400","buttonLoadGoogleFonts":true,"marginTop":0,"marginBottom":0} -->
                        <div class="wp-block-blockspare-blockspare-buttons blockspare-1c640cc4-5b76-4 blockspare-block-button-wrap" blockspare-animation=""><style>.blockspare-1c640cc4-5b76-4 .blockspare-block-button{text-align:right;margin-top:0px;margin-bottom:0px;margin-left:0px;margin-right:0px}.blockspare-1c640cc4-5b76-4 .blockspare-block-button span{color:#fff;border-width:2px;font-size:16px;font-family:Poppins;font-weight:400}.blockspare-1c640cc4-5b76-4 .blockspare-block-button .blockspare-button{background-color:#ea4b50}.blockspare-1c640cc4-5b76-4 .blockspare-block-button .blockspare-button:visited{background-color:#ea4b50}.blockspare-1c640cc4-5b76-4 .blockspare-block-button .blockspare-button:focus{background-color:#ea4b50}.blockspare-1c640cc4-5b76-4 .blockspare-block-button i{font-size:16px}.blockspare-1c640cc4-5b76-4 .blockspare-block-button a{padding-top:12px;padding-bottom:12px;padding-right:22px;padding-left:22px}@media screen and (max-width:1025px){.blockspare-1c640cc4-5b76-4 .blockspare-block-button span{font-size:14px}.blockspare-1c640cc4-5b76-4 .blockspare-block-button i{font-size:14px}}@media screen and (max-width:768px){.blockspare-1c640cc4-5b76-4 .blockspare-block-button span{font-size:14px}.blockspare-1c640cc4-5b76-4 .blockspare-block-button i{font-size:14px}}</style><div class="blockspare-block-button"><a class="blockspare-button blockspare-button-shape-circular blockspare-button-size-small"><span>Get Started</span></a></div></div>
                        <!-- /wp:blockspare/blockspare-buttons --></div>
                        <!-- /wp:group --></div>
                        <!-- /wp:column --></div>
                        <!-- /wp:columns --></div>
                        <!-- /wp:group --></div></div></div></div>
                        <!-- /wp:blockspare/blockspare-container -->',
						'imagePath'    => 'header',
                    ),
					array(
						'type'     => 'header',
                        'pages'    =>'education',
						'item'     => ['Header'],
						'key'      => 'bs_header_5',
						'name'     => 'Education Header',
						'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-header/education-header/",
						'content'  => BLOCKSPARE_PRO_PATH,
						'imagePath'    => 'header',
                    ),
					array(
						'type'     => 'header',
                        'pages'    =>'fitness',
						'item'     => ['Header'],
						'key'      => 'bs_header_6',
						'name'     => 'Fitness Header',
						'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-header/fitness-header/",
						'content'  => BLOCKSPARE_PRO_PATH,
						'imagePath'    => 'header',
                    ),
					array(
						'type'     => 'header',
                        'pages'    =>'real-estate',
						'item'     => ['Header'],
						'key'      => 'bs_header_7',
						'name'     => 'Real Estate Header',
						'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-header/real-estate-header/",
						'content'  => BLOCKSPARE_PRO_PATH,
						'imagePath'    => 'header',
                    ),
					array(
						'type'     => 'header',
                        'pages'    =>'medical',
						'item'     => ['Header'],
						'key'      => 'bs_header_8',
						'name'     => 'Medical Header',
						'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-header/medical-header/",
						'content'  => BLOCKSPARE_PRO_PATH,
						'imagePath'    => 'header',
                    ),
					array(
						'type'     => 'header',
                        'pages'    =>'charity',
						'item'     => ['Header'],
						'key'      => 'bs_header_9',
						'name'     => 'Charity Header',
						'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-header/charity-header/",
						'content'  => BLOCKSPARE_PRO_PATH,
						'imagePath'    => 'header',
                    ),
					array(
						'type'     => 'header',
                        'pages'    =>'general',
						'item'     => ['Header'],
						'key'      => 'bs_header_10',
						'name'     => 'General Header',
						'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-header/general-header/",
						'content'  => '<!-- wp:blockspare/blockspare-container {"paddingTop":0,"paddingRight":0,"paddingBottom":0,"paddingLeft":0,"marginTop":0,"marginBottom":0,"uniqueClass":"blockspare-b6238530-a520-4","imgURL":"https://blockspare.com/demo/default/general-news/wp-content/uploads/sites/11/2018/07/water-light-architecture-sky-bridge-skyline-661635-pxhere.com_-1.jpg","imgID":439,"imgAlt":"","opacityRatio":30,"backGroundColor":"#000000"} -->
                        <div class="wp-block-blockspare-blockspare-container alignfull blockspare-b6238530-a520-4" blockspare-animation=""><style>.blockspare-b6238530-a520-4 > .blockspare-block-container-wrapper{background-color:#000000;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px;margin-top:0px;margin-right:0px;margin-bottom:0px;margin-left:0px;border-radius:0}.blockspare-b6238530-a520-4 .blockspare-image-wrap{background-image:url(https://blockspare.com/demo/default/general-news/wp-content/uploads/sites/11/2018/07/water-light-architecture-sky-bridge-skyline-661635-pxhere.com_-1.jpg)}</style><div class="blockspare-block-container-wrapper blockspare-hover-item"><div class="blockspare-container-background blockspare-image-wrap has-background-opacity-30 has-background-opacity"></div><div class="blockspare-container"><div class="blockspare-inner-blocks blockspare-inner-wrapper-blocks"><!-- wp:group {"align":"full","style":{"spacing":{"padding":{"top":"10px","bottom":"10px"}}},"backgroundColor":"contrast","layout":{"type":"constrained"}} -->
                        <div class="wp-block-group alignfull has-contrast-background-color has-background" style="padding-top:10px;padding-bottom:10px"><!-- wp:columns {"verticalAlignment":"center","align":"wide","style":{"spacing":{"padding":{"top":"0px","right":"0px","bottom":"0px","left":"0px"}}}} -->
                        <div class="wp-block-columns alignwide are-vertically-aligned-center" style="padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px"><!-- wp:column {"verticalAlignment":"center","style":{"spacing":{"padding":{"top":"0px","right":"0px","bottom":"0px","left":"0px"}}}} -->
                        <div class="wp-block-column is-vertically-aligned-center" style="padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px"><!-- wp:blockspare/date-time {"uniqueClass":"blockspare-be315cc6-4678-4","dateIconToggle":false,"dateColor":"#ffffff","marginTop":0,"marginBottom":0} /--></div>
                        <!-- /wp:column -->
                        
                        <!-- wp:column {"verticalAlignment":"center","style":{"spacing":{"padding":{"top":"0px","right":"0px","bottom":"0px","left":"0px"}}}} -->
                        <div class="wp-block-column is-vertically-aligned-center" style="padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px"><!-- wp:blockspare/blockspare-social-links {"sectionAlignment":"right","uniqueClass":"blockspare-ee59922e-5fa9-4","buttonFills":"blockspare-social-icon-none","iconColorOption":"custom","customfontColorOption":"#ffffff","custombackgroundColorOption":"#ffffff","buttonShapes":"blockspare-social-icon-rounded","marginTop":0,"marginBottom":0} -->
                        <div class="wp-block-blockspare-blockspare-social-links blockspare-ee59922e-5fa9-4 blockspare-socaillink-block blockspare-sociallinks-right" blockspare-animation=""><style>.blockspare-ee59922e-5fa9-4 .blockspare-social-wrapper{text-align:right;margin-top:0px;margin-right:0px;margin-bottom:0px;margin-left:0px}.blockspare-ee59922e-5fa9-4 .blockspare-social-wrapper .blockspare-social-links a .blockspare-social-icons{color:#ffffff}.blockspare-ee59922e-5fa9-4 .blockspare-social-wrapper .blockspare-social-icons > span{font-size:16px}@media screen and (max-width:1025px){.blockspare-ee59922e-5fa9-4 .blockspare-social-wrapper .blockspare-social-icons > span{font-size:16px}}@media screen and (max-width:768px){.blockspare-ee59922e-5fa9-4 .blockspare-social-wrapper .blockspare-social-icons > span{font-size:14px}}</style><div class="blockspare-social-wrapper"><ul class="blockspare-social-links custom blockspare-social-icon-rounded blockspare-social-icon-small blockspare-icon-only blockspare-social-icon-none blockspare-social-links-horizontal"><li class="blockspare-hover-item"><a href="https://facebook.com" class="bs-social-facebook" target="_blank" rel="noopener noreferrer"><span class="blockspare-social-icons"><i class="fab fa-facebook-f"></i> <span class="screen-reader-text">Facebook</span></span></a></li><li class="blockspare-hover-item"><a href="https://twitter.com" class="bs-social-twitter" target="_blank" rel="noopener noreferrer"><span class="blockspare-social-icons"><i class="fab fa-twitter"></i><span class="screen-reader-text">Twitter</span></span></a></li><li class="blockspare-hover-item"><a href="https://instagram.com" class="bs-social-instagram" target="_blank" rel="noopener noreferrer"><span class="blockspare-social-icons"><i class="fab fa-instagram"></i><span class="screen-reader-text">Instagram</span></span></a></li></ul></div></div>
                        <!-- /wp:blockspare/blockspare-social-links --></div>
                        <!-- /wp:column --></div>
                        <!-- /wp:columns --></div>
                        <!-- /wp:group -->
                        
                        <!-- wp:group {"align":"wide","style":{"spacing":{"padding":{"top":"50px","bottom":"50px"}}},"layout":{"type":"constrained","justifyContent":"center"}} -->
                        <div class="wp-block-group alignwide" style="padding-top:50px;padding-bottom:50px"><!-- wp:columns {"verticalAlignment":"center","align":"wide"} -->
                        <div class="wp-block-columns alignwide are-vertically-aligned-center"><!-- wp:column {"verticalAlignment":"center","width":"33.33%"} -->
                        <div class="wp-block-column is-vertically-aligned-center" style="flex-basis:33.33%"><!-- wp:site-logo {"width":193,"shouldSyncIcon":false} /--></div>
                        <!-- /wp:column -->
                        
                        <!-- wp:column {"verticalAlignment":"center","width":"66.66%"} -->
                        <div class="wp-block-column is-vertically-aligned-center" style="flex-basis:66.66%"><!-- wp:image {"align":"right","id":795,"sizeSlug":"full","linkDestination":"none","style":{"color":{"duotone":"unset"}}} -->
                        <figure class="wp-block-image alignright size-full"><img src="https://blockspare.com/demo/default/general-news/wp-content/uploads/sites/11/2021/05/banner-promo-full-blue-revised.png" alt="" class="wp-image-795"/></figure>
                        <!-- /wp:image --></div>
                        <!-- /wp:column --></div>
                        <!-- /wp:columns --></div>
                        <!-- /wp:group -->
                        
                        <!-- wp:group {"style":{"spacing":{"padding":{"top":"15px","right":"15px","bottom":"15px","left":"15px"}}},"backgroundColor":"vivid-red","layout":{"type":"constrained"}} -->
                        <div class="wp-block-group has-vivid-red-background-color has-background" style="padding-top:15px;padding-right:15px;padding-bottom:15px;padding-left:15px"><!-- wp:navigation {"ref":421,"textColor":"base","align":"wide","layout":{"type":"flex","justifyContent":"left","orientation":"horizontal"},"style":{"spacing":{"blockGap":"var:preset|spacing|30"},"typography":{"textTransform":"capitalize","fontStyle":"normal","fontWeight":"600"}}} /--></div>
                        <!-- /wp:group --></div></div></div></div>
                        <!-- /wp:blockspare/blockspare-container -->',
						'imagePath'    => 'header',
                    ),
					array(
						'type'     => 'header',
                        'pages'    =>'sport',
						'item'     => ['Header'],
						'key'      => 'bs_header_11',
						'name'     => 'Sport Header',
						'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-header/sport-header/",
						'content'  => BLOCKSPARE_PRO_PATH,
						'imagePath'    => 'header',
                    ),
					array(
						'type'     => 'header',
                        'pages'    =>'fashion',
						'item'     => ['Header'],
						'key'      => 'bs_header_12',
						'name'     => 'Fashion Header',
						'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-header/fashion-header/",
						'content'  => BLOCKSPARE_PRO_PATH,
						'imagePath'    => 'header',
                    ),
					array(
						'type'     => 'header',
                        'pages'    =>'recipe',
						'item'     => ['Header'],
						'key'      => 'bs_header_13',
						'name'     => 'Recipe Header',
						'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-header/recipe-header/",
						'content'  => BLOCKSPARE_PRO_PATH,
						'imagePath'    => 'header',
                    ),
					array(
						'type'     => 'header',
                        'pages'    =>'chinese',
						'item'     => ['Header'],
						'key'      => 'bs_header_14',
						'name'     => 'Chinese Header',
						'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-header/chinese-header/",
						'content'  => BLOCKSPARE_PRO_PATH,
						'imagePath'    => 'header',
                    ),
					array(
						'type'     => 'header',
                        'pages'    =>'pet-care',
						'item'     => ['Header'],
						'key'      => 'bs_header_15',
						'name'     => 'Pet Care Header',
						'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-header/pet-care-header/",
						'content'  => '<!-- wp:blockspare/blockspare-container {"marginTop":0,"marginBottom":0,"uniqueClass":"blockspare-92b7fde0-e96c-4","backGroundColor":"#ff7e22"} -->
                        <div class="wp-block-blockspare-blockspare-container alignfull blockspare-92b7fde0-e96c-4" blockspare-animation=""><style>.blockspare-92b7fde0-e96c-4 > .blockspare-block-container-wrapper{background-color:#ff7e22;padding-top:20px;padding-right:20px;padding-bottom:20px;padding-left:20px;margin-top:0px;margin-right:0px;margin-bottom:0px;margin-left:0px;border-radius:0}.blockspare-92b7fde0-e96c-4 .blockspare-image-wrap{background-image:none}</style><div class="blockspare-block-container-wrapper blockspare-hover-item"><div class="blockspare-container-background blockspare-image-wrap has-background-opacity-100 has-background-opacity"></div><div class="blockspare-container"><div class="blockspare-inner-blocks blockspare-inner-wrapper-blocks"><!-- wp:group {"align":"wide","layout":{"type":"constrained"}} -->
                        <div class="wp-block-group alignwide"><!-- wp:columns {"verticalAlignment":"center","align":"wide"} -->
                        <div class="wp-block-columns alignwide are-vertically-aligned-center"><!-- wp:column {"verticalAlignment":"center","width":"33.33%"} -->
                        <div class="wp-block-column is-vertically-aligned-center" style="flex-basis:33.33%"><!-- wp:site-logo {"width":101,"shouldSyncIcon":false,"style":{"color":{"duotone":["rgb(208, 208, 208)","#CCC"]}}} /--></div>
                        <!-- /wp:column -->
                        
                        <!-- wp:column {"verticalAlignment":"center","width":"66.66%"} -->
                        <div class="wp-block-column is-vertically-aligned-center" style="flex-basis:66.66%"><!-- wp:navigation {"ref":408,"textColor":"base","layout":{"type":"flex","justifyContent":"right"},"style":{"typography":{"textTransform":"uppercase","fontStyle":"normal","fontWeight":"500"}}} /--></div>
                        <!-- /wp:column --></div>
                        <!-- /wp:columns --></div>
                        <!-- /wp:group --></div></div></div></div>
                        <!-- /wp:blockspare/blockspare-container -->',
						'imagePath'    => 'header',
                    ),
					array(
						'type'     => 'header',
                        'pages'    =>'gadgets',
						'item'     => ['Header'],
						'key'      => 'bs_header_16',
						'name'     => 'Gadgets Header',
						'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-header/gadgets-header/",
						'content'  => BLOCKSPARE_PRO_PATH,
						'imagePath'    => 'header',
                    ),
					array(
						'type'     => 'header',
                        'pages'    =>'construction',
						'item'     => ['Header'],
						'key'      => 'bs_header_17',
						'name'     => 'Construction Header',
						'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-header/construction-header/",
						'content'  => BLOCKSPARE_PRO_PATH,
						'imagePath'    => 'header',
                    ),
					array(
						'type'     => 'header',
                        'pages'    =>'florista',
						'item'     => ['Header'],
						'key'      => 'bs_header_18',
						'name'     => 'Florista Header',
						'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-header/florista-header/",
						'content'  => BLOCKSPARE_PRO_PATH,
						'imagePath'    => 'header',
                    ),
					array(
						'type'     => 'header',
                        'pages'    =>'autoservice',
						'item'     => ['Header'],
						'key'      => 'bs_header_19',
						'name'     => 'Auto Service Header',
						'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-header/auto-service-header/",
						'content'  => BLOCKSPARE_PRO_PATH,
						'imagePath'    => 'header',
                    ),
					array(
						'type'     => 'header',
                        'pages'    =>'autodeal',
						'item'     => ['Header'],
						'key'      => 'bs_header_20',
						'name'     => 'Auto Deal Header',
						'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-header/auto-deal-header/",
						'content'  => BLOCKSPARE_PRO_PATH,
						'imagePath'    => 'header',
                    ),
					array(
						'type'     => 'header',
                        'pages'    =>'gardener',
						'item'     => ['Header'],
						'key'      => 'bs_header_21',
						'name'     => 'Gardener Header',
						'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-header/gardener-header/",
						'content'  => BLOCKSPARE_PRO_PATH,
						'imagePath'    => 'header',
                    ),
					array(
						'type'     => 'header',
                        'pages'    =>'montessori',
						'item'     => ['Header'],
						'key'      => 'bs_header_22',
						'name'     => 'Montessori Header',
						'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-header/montessori-header/",
						'content'  => BLOCKSPARE_PRO_PATH,
						'imagePath'    => 'header',
                    ),
					array(
						'type'     => 'header',
                        'pages'    =>'travel',
						'item'     => ['Header'],
						'key'      => 'bs_header_23',
						'name'     => 'Travel Header',
						'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-header/travel-header/",
						'content'  => BLOCKSPARE_PRO_PATH,
						'imagePath'    => 'header',
                    ),
					array(
						'type'     => 'header',
                        'pages'    =>'cinema',
						'item'     => ['Header'],
						'key'      => 'bs_header_24',
						'name'     => 'Cinema Header',
						'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-header/cinema-header/",
						'content'  => BLOCKSPARE_PRO_PATH,
						'imagePath'    => 'header',
                    ),
					array(
						'type'     => 'header',
                        'pages'    =>'dentlo',
						'item'     => ['Header'],
						'key'      => 'bs_header_25',
						'name'     => 'Dentlo Header',
						'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-header/dentlo-header/",
						'content'  => BLOCKSPARE_PRO_PATH,
						'imagePath'    => 'header',
                    ),
					array(
						'type'     => 'header',
                        'pages'    =>'hair-salon',
						'item'     => ['Header'],
						'key'      => 'bs_header_26',
						'name'     => 'Hair Salon Header',
						'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-header/hair-salon-header/",
						'content'  => BLOCKSPARE_PRO_PATH,
						'imagePath'    => 'header',
                    ),
					array(
						'type'     => 'header',
                        'pages'    =>'interior-design',
						'item'     => ['Header'],
						'key'      => 'bs_header_27',
						'name'     => 'Interior Design Header',
						'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-header/interior-design-header/",
						'content'  => BLOCKSPARE_PRO_PATH,
						'imagePath'    => 'header',
                    ),
					array(
						'type'     => 'header',
                        'pages'    =>'yoga',
						'item'     => ['Header'],
						'key'      => 'bs_header_28',
						'name'     => 'Yoga Header',
						'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-header/yoga-header/",
						'content'  => '<!-- wp:blockspare/blockspare-container {"paddingTop":0,"paddingRight":0,"paddingBottom":0,"paddingLeft":0,"marginTop":0,"marginBottom":0,"uniqueClass":"blockspare-d3dbc0c7-daed-4","backGroundColor":"#faeac8"} -->
                        <div class="wp-block-blockspare-blockspare-container alignfull blockspare-d3dbc0c7-daed-4" blockspare-animation=""><style>.blockspare-d3dbc0c7-daed-4 > .blockspare-block-container-wrapper{background-color:#faeac8;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px;margin-top:0px;margin-right:0px;margin-bottom:0px;margin-left:0px;border-radius:0}.blockspare-d3dbc0c7-daed-4 .blockspare-image-wrap{background-image:none}</style><div class="blockspare-block-container-wrapper blockspare-hover-item"><div class="blockspare-container-background blockspare-image-wrap has-background-opacity-100 has-background-opacity"></div><div class="blockspare-container"><div class="blockspare-inner-blocks blockspare-inner-wrapper-blocks"><!-- wp:group {"style":{"spacing":{"padding":{"top":"var:preset|spacing|30","bottom":"0","right":"var:preset|spacing|30","left":"var:preset|spacing|30"}}},"layout":{"type":"constrained"}} -->
                        <div class="wp-block-group" style="padding-top:var(--wp--preset--spacing--30);padding-right:var(--wp--preset--spacing--30);padding-bottom:0;padding-left:var(--wp--preset--spacing--30)"><!-- wp:group {"align":"wide","layout":{"type":"flex","flexWrap":"nowrap","justifyContent":"space-between"}} -->
                        <div class="wp-block-group alignwide"><!-- wp:site-logo {"shouldSyncIcon":false} /-->
                        
                        <!-- wp:navigation {"ref":408,"customTextColor":"#333333","style":{"typography":{"fontStyle":"normal","fontWeight":"600","textTransform":"uppercase","fontSize":"0.9rem"},"layout":{"selfStretch":"fit","flexSize":null}}} /--></div>
                        <!-- /wp:group --></div>
                        <!-- /wp:group --></div></div></div></div>
                        <!-- /wp:blockspare/blockspare-container -->',
						'imagePath'    => 'header',
                    )
				);

            return array_merge_recursive( $blocks_lists, $block_library_list );
        }
	}
}
Blockspare_Header_Template_Block::get_instance()->run();