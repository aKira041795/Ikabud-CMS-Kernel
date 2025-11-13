<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Blockspare_Section_Template_Block' ) ) {

	class Blockspare_Section_Template_Block extends Blockspare_Import_Block_Base{
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
						'type'     => 'section',
                        'pages'    =>'agency',
                        'item'     => ['Agency', 'Content Box'],
						'key'      => 'bs_section_1',
						'name'     => 'Agency Section 1',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/agency-section-1/",
						'content'  => '<!-- wp:blockspare/blockspare-container {"paddingTop":100,"paddingBottom":100,"marginTop":0,"marginBottom":0,"uniqueClass":"blockspare-3e6d5052-f284-4","backGroundColor":"#fff8e4"} -->
                        <div class="wp-block-blockspare-blockspare-container alignfull blockspare-3e6d5052-f284-4" blockspare-animation=""><style>.blockspare-3e6d5052-f284-4 > .blockspare-block-container-wrapper{background-color:#fff8e4;padding-top:100px;padding-right:20px;padding-bottom:100px;padding-left:20px;margin-top:0px;margin-right:0px;margin-bottom:0px;margin-left:0px;border-radius:0}.blockspare-3e6d5052-f284-4 .blockspare-image-wrap{background-image:none}</style><div class="blockspare-block-container-wrapper blockspare-hover-item"><div class="blockspare-container-background blockspare-image-wrap has-background-opacity-100 has-background-opacity"></div><div class="blockspare-container"><div class="blockspare-inner-blocks blockspare-inner-wrapper-blocks"><!-- wp:group {"align":"full","layout":{"type":"constrained"}} -->
                        <div class="wp-block-group alignfull"><!-- wp:group {"align":"full","style":{"color":{"background":"#fff8e4"},"spacing":{"padding":{"top":"100px","bottom":"100px"},"margin":{"top":"0px","bottom":"0px"}}},"layout":{"inherit":true,"type":"constrained"}} -->
                        <div class="wp-block-group alignfull has-background" style="background-color:#fff8e4;margin-top:0px;margin-bottom:0px;padding-top:100px;padding-bottom:100px"><!-- wp:columns {"align":"wide","style":{"spacing":{"blockGap":"2rem"}}} -->
                        <div class="wp-block-columns alignwide"><!-- wp:column {"width":"66.66%"} -->
                        <div class="wp-block-column" style="flex-basis:66.66%"><!-- wp:image {"id":826,"sizeSlug":"large","linkDestination":"none","className":"is-resized"} -->
                        <figure class="wp-block-image size-large is-resized"><img src="https://blockspare.com/demo/default/agency/wp-content/uploads/sites/2/2023/12/9813-1024x622.png" alt="" class="wp-image-826"/></figure>
                        <!-- /wp:image --></div>
                        <!-- /wp:column -->
                        
                        <!-- wp:column {"width":"33.33%"} -->
                        <div class="wp-block-column" style="flex-basis:33.33%"><!-- wp:blockspare/blockspare-section-header {"uniqueClass":"blockspare-063bd53c-d32f-4","headerTitle":"Grow Your Business with Our Solutions.","titleFontSize":44,"headerSubTitle":"We help our clients to increase their website traffic, rankings, and visibility in search results.","headersubtitleColor":"#00000099","headermarginTop":0,"titlePaddingBottom":20,"titleFontWeight":"800"} -->
                        <div class="wp-block-blockspare-blockspare-section-header aligncenter blockspare-063bd53c-d32f-4 blockspare-section-header-wrapper blockspare-blocks aligncenter" blockspare-animation=""><style>.blockspare-063bd53c-d32f-4 .blockspare-section-head-wrap{background-color:transparent;text-align:left;margin-top:0px;margin-right:0px;margin-bottom:30px;margin-left:0px}.blockspare-063bd53c-d32f-4 .blockspare-section-head-wrap .blockspare-title{color:#404040;padding-top:0px;padding-right:0px;padding-bottom:20px;padding-left:0px;font-size:44px;font-weight:800}.blockspare-063bd53c-d32f-4 .blockspare-section-head-wrap .blockspare-subtitle{color:#00000099;font-size:14px;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px}@media screen and (max-width:1025px){.blockspare-063bd53c-d32f-4 .blockspare-section-head-wrap .blockspare-title{font-size:22px}.blockspare-063bd53c-d32f-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}@media screen and (max-width:768px){.blockspare-063bd53c-d32f-4 .blockspare-section-head-wrap .blockspare-title{font-size:20px}.blockspare-063bd53c-d32f-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}</style><div class="blockspare-section-head-wrap blockspare-style1 blockspare-left blockspare-hover-item"><div class="blockspare-title-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><h2 class="blockspare-title">Grow Your Business with Our Solutions.</h2><span class="blockspare-title-dash blockspare-lower-dash"></span></div><div class="blockspare-subtitle-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><p class="blockspare-subtitle">We help our clients to increase their website traffic, rankings, and visibility in search results.</p><span class="blockspare-title-dash blockspare-lower-dash"></span></div></div></div>
                        <!-- /wp:blockspare/blockspare-section-header -->
                        
                        <!-- wp:blockspare/blockspare-buttons {"sectionAlignment":"left","uniqueClass":"blockspare-6c12e889-a7e7-4","buttonBackgroundColor":"#2e947d","buttonShape":"blockspare-button-shape-square","marginBottom":-100} -->
                        <div class="wp-block-blockspare-blockspare-buttons blockspare-6c12e889-a7e7-4 blockspare-block-button-wrap" blockspare-animation=""><style>.blockspare-6c12e889-a7e7-4 .blockspare-block-button{text-align:left;margin-top:30px;margin-bottom:-100px;margin-left:0px;margin-right:0px}.blockspare-6c12e889-a7e7-4 .blockspare-block-button span{color:#fff;border-width:2px;font-size:16px}.blockspare-6c12e889-a7e7-4 .blockspare-block-button .blockspare-button{background-color:#2e947d}.blockspare-6c12e889-a7e7-4 .blockspare-block-button .blockspare-button:visited{background-color:#2e947d}.blockspare-6c12e889-a7e7-4 .blockspare-block-button .blockspare-button:focus{background-color:#2e947d}.blockspare-6c12e889-a7e7-4 .blockspare-block-button i{font-size:16px}.blockspare-6c12e889-a7e7-4 .blockspare-block-button a{padding-top:12px;padding-bottom:12px;padding-right:22px;padding-left:22px}@media screen and (max-width:1025px){.blockspare-6c12e889-a7e7-4 .blockspare-block-button span{font-size:14px}.blockspare-6c12e889-a7e7-4 .blockspare-block-button i{font-size:14px}}@media screen and (max-width:768px){.blockspare-6c12e889-a7e7-4 .blockspare-block-button span{font-size:14px}.blockspare-6c12e889-a7e7-4 .blockspare-block-button i{font-size:14px}}</style><div class="blockspare-block-button"><a class="blockspare-button blockspare-button-shape-square blockspare-button-size-small"><span>Get Started</span></a></div></div>
                        <!-- /wp:blockspare/blockspare-buttons --></div>
                        <!-- /wp:column --></div>
                        <!-- /wp:columns --></div>
                        <!-- /wp:group --></div>
                        <!-- /wp:group --></div></div></div></div>
                        <!-- /wp:blockspare/blockspare-container -->',
                        'imagePath'    => 'sections',
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'agency',
                        'item'     => ['Agency', 'Services'],
						'key'      => 'bs_section_2',
						'name'     => 'Agency Section 2',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/agency-section-2/",
						'content'  => BLOCKSPARE_PRO_PATH,
                        'imagePath'    => 'sections'
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'agency',
                        'item'     => ['Agency', 'Content Box'],
						'key'      => 'bs_section_3',
						'name'     => 'Agency Section 3',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/agency-section-3/",
						'content'  => '<!-- wp:group {"align":"full","style":{"color":{"background":"#fff8e4"},"spacing":{"padding":{"top":"100px","bottom":"100px"},"margin":{"top":"0px","bottom":"0px"}}},"layout":{"inherit":true,"type":"constrained"}} -->
                        <div class="wp-block-group alignfull has-background" style="background-color:#fff8e4;margin-top:0px;margin-bottom:0px;padding-top:100px;padding-bottom:100px"><!-- wp:blockspare/content-box {"uniqueClass":"blockspare-67084978-be4a-4","align":"wide","sectionAlignment":"left","headerTitle":"Wonder how much faster your website can go? Easily check your SEO Score now.","titleFontSize":36,"headerSubTitle":"ANALYZE NOW","headerlayoutOption":"blockspare-style3","titlePaddingBottom":30,"titleFontWeight":"600","sectionDescription":"Cum sociis natoque penatibus et magnis dis parturient montes, nascetur ridiculus mus. Cras justo odio, dapibus ac facilisis in, egestas eget quam. Praesent commodo cursus magna, vel scelerisque nisl consectetur et.","imgURL":"https://blockspare.com/demo/default/agency/wp-content/uploads/sites/2/2023/12/10060.png","imgID":825,"descriptionColor":"#00000099","layoutOption":true,"design":"style-3"} -->
                        <div class="wp-block-blockspare-content-box blockspare-67084978-be4a-4 blockspare-contentBox alignwide" blockspare-animation=""><style>.blockspare-67084978-be4a-4 .blockspare-content-wrapper{text-align:left;margin-top:30px;margin-right:0px;margin-bottom:30px;margin-left:0px}.blockspare-67084978-be4a-4 .blockspare-content-wrapper .blockspare-section-wrapper{padding-top:30px;padding-right:30px;padding-bottom:30px;padding-left:30px}.blockspare-67084978-be4a-4 .blockspare-desc-btn-wrap .blockspare-description{color:#00000099;font-size:16px}.blockspare-67084978-be4a-4 .blockspare-section-header-wrapper{border-color:#8b249c}.blockspare-67084978-be4a-4 .blockspare-content-wrapper .blockspare-desc-btn-wrap .blockspare-description{margin-top:10px;margin-right:0px;margin-bottom:0px;margin-left:0px}.blockspare-67084978-be4a-4 .blockspare-section-head-wrap{background-color:transparent;text-align:left;margin-top:0px;margin-right:0px;margin-bottom:0px;margin-left:0px}.blockspare-67084978-be4a-4 .blockspare-block-button a.blockspare-button{color:#fff;border-width:2px;font-size:16px}.blockspare-67084978-be4a-4.wp-block-blockspare-content-box .blockspare-block-button .blockspare-button{background-color:var(--bgcolor)}.blockspare-67084978-be4a-4.wp-block-blockspare-content-box .blockspare-block-button .blockspare-button:visited{background-color:var(--bgcolor)}.blockspare-67084978-be4a-4.wp-block-blockspare-content-box .blockspare-block-button .blockspare-button:focus{background-color:var(--bgcolor)}.blockspare-67084978-be4a-4 .blockspare-section-head-wrap .blockspare-title{color:#404040;padding-top:0px;padding-right:0px;padding-bottom:30px;padding-left:0px;font-size:36px;font-weight:600}.blockspare-67084978-be4a-4 .blockspare-section-head-wrap .blockspare-subtitle{color:#6d6d6d;font-size:14px;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px}@media screen and (max-width:1025px){.blockspare-67084978-be4a-4 .blockspare-block-button a.blockspare-button{font-size:14px}.blockspare-67084978-be4a-4 .blockspare-section-head-wrap .blockspare-title{font-size:22px}.blockspare-67084978-be4a-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}.blockspare-67084978-be4a-4 .blockspare-desc-btn-wrap .blockspare-description{font-size:16px}}@media screen and (max-width:768px){.blockspare-67084978-be4a-4 .blockspare-block-button a.blockspare-button{font-size:14px}.blockspare-67084978-be4a-4 .blockspare-section-head-wrap .blockspare-title{font-size:20px}.blockspare-67084978-be4a-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}.blockspare-67084978-be4a-4 .blockspare-desc-btn-wrap .blockspare-description{font-size:14px}}</style><div class="blockspare-content-wrapper blockspare-blocks blockspare-hover-item blockspare-hover-child reverse-img style-3"><div class="content-img-wrap"><img src="https://blockspare.com/demo/default/agency/wp-content/uploads/sites/2/2023/12/10060.png" alt="" class=" hover-child"/></div><div class="blockspare-section-wrapper blockspare-mainheader-wrap "><div class="blockspare-section-head-wrap"><div class="blockspare-title-subtitle-wrap"><div class="blockspare-section-header-wrapper blockspare-blocks"><div class="blockspare-section-head-wrap blockspare-style3 blockspare-left"><div class="blockspare-title-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><h2 class="blockspare-title">Wonder how much faster your website can go? Easily check your SEO Score now.</h2><span class="blockspare-title-dash blockspare-lower-dash"></span></div><div class="blockspare-subtitle-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><p class="blockspare-subtitle">ANALYZE NOW</p><span class="blockspare-title-dash blockspare-lower-dash"></span></div></div></div></div></div><div class="blockspare-desc-btn-wrap"><p class="blockspare-description">Cum sociis natoque penatibus et magnis dis parturient montes, nascetur ridiculus mus. Cras justo odio, dapibus ac facilisis in, egestas eget quam. Praesent commodo cursus magna, vel scelerisque nisl consectetur et.</p></div></div></div></div>
                        <!-- /wp:blockspare/content-box --></div>
                        <!-- /wp:group -->',
                        'imagePath'    => 'sections'
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'agency',
                        'item'     => ['Agency', 'Testimonial'],
						'key'      => 'bs_section_4',
						'name'     => 'Agency Section 4',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/agency-section-4/",
						'content'  => BLOCKSPARE_PRO_PATH,
                        'imagePath'    => 'sections',
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'agency',
                        'item'     => ['Agency', 'Pricing Table'],
						'key'      => 'bs_section_5',
						'name'     => 'Agency Section 5',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/agency-section-5/",
						'content'  => BLOCKSPARE_PRO_PATH,
                        'imagePath'    => 'sections',
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'agency',
                        'item'     => ['Agency', 'Logo Grid'],
						'key'      => 'bs_section_6',
						'name'     => 'Agency Section 6',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/agency-section-6/",
						'content'  => '<!-- wp:blockspare/blockspare-container {"paddingTop":100,"paddingBottom":100,"marginTop":0,"marginBottom":0,"uniqueClass":"blockspare-806f23fe-0422-4"} -->
                        <div class="wp-block-blockspare-blockspare-container alignfull blockspare-806f23fe-0422-4" blockspare-animation=""><style>.blockspare-806f23fe-0422-4 > .blockspare-block-container-wrapper{background-color:#f9f9f9;padding-top:100px;padding-right:20px;padding-bottom:100px;padding-left:20px;margin-top:0px;margin-right:0px;margin-bottom:0px;margin-left:0px;border-radius:0}.blockspare-806f23fe-0422-4 .blockspare-image-wrap{background-image:none}</style><div class="blockspare-block-container-wrapper blockspare-hover-item"><div class="blockspare-container-background blockspare-image-wrap has-background-opacity-100 has-background-opacity"></div><div class="blockspare-container"><div class="blockspare-inner-blocks blockspare-inner-wrapper-blocks"><!-- wp:group {"layout":{"inherit":true,"type":"constrained"}} -->
                        <div class="wp-block-group"><!-- wp:blockspare/blockspare-section-header {"uniqueClass":"blockspare-05f5e022-9b23-4","align":"wide","headerTitle":"Let make something great together. \u003cbr\u003eWe are trusted by over 5000+ clients.","titleFontSize":36,"headerSubTitle":"LET TALK","headerlayoutOption":"blockspare-style3","titleFontWeight":"600"} -->
                        <div class="wp-block-blockspare-blockspare-section-header alignwide blockspare-05f5e022-9b23-4 blockspare-section-header-wrapper blockspare-blocks alignwide" blockspare-animation=""><style>.blockspare-05f5e022-9b23-4 .blockspare-section-head-wrap{background-color:transparent;text-align:left;margin-top:30px;margin-right:0px;margin-bottom:30px;margin-left:0px}.blockspare-05f5e022-9b23-4 .blockspare-section-head-wrap .blockspare-title{color:#404040;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px;font-size:36px;font-weight:600}.blockspare-05f5e022-9b23-4 .blockspare-section-head-wrap .blockspare-subtitle{color:#6d6d6d;font-size:14px;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px}@media screen and (max-width:1025px){.blockspare-05f5e022-9b23-4 .blockspare-section-head-wrap .blockspare-title{font-size:22px}.blockspare-05f5e022-9b23-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}@media screen and (max-width:768px){.blockspare-05f5e022-9b23-4 .blockspare-section-head-wrap .blockspare-title{font-size:20px}.blockspare-05f5e022-9b23-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}</style><div class="blockspare-section-head-wrap blockspare-style3 blockspare-left blockspare-hover-item"><div class="blockspare-title-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><h2 class="blockspare-title">Let make something great together. <br>We are trusted by over 5000+ clients.</h2><span class="blockspare-title-dash blockspare-lower-dash"></span></div><div class="blockspare-subtitle-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><p class="blockspare-subtitle">LET TALK</p><span class="blockspare-title-dash blockspare-lower-dash"></span></div></div></div>
                        <!-- /wp:blockspare/blockspare-section-header -->
                        
                        <!-- wp:blockspare/blockspare-logos {"uniqueClass":"blockspare-c124ffb6-d8ec-4","align":"wide","images":[{"alt":"","id":732,"link":"https://blockspare.com/demo/default/agency/home/logoipsum-234/","caption":"","sizes":{"full":{"url":"https://blockspare.com/demo/default/agency/wp-content/uploads/sites/2/2023/06/logoipsum-234.png","height":46,"width":93,"orientation":"landscape"}},"url":"https://blockspare.com/demo/default/agency/wp-content/uploads/sites/2/2023/06/logoipsum-234.png","imgLink":""},{"alt":"","id":733,"link":"https://blockspare.com/demo/default/agency/home/logoipsum-219/","caption":"","sizes":{"full":{"url":"https://blockspare.com/demo/default/agency/wp-content/uploads/sites/2/2023/06/logoipsum-219.png","height":15,"width":150,"orientation":"landscape"}},"url":"https://blockspare.com/demo/default/agency/wp-content/uploads/sites/2/2023/06/logoipsum-219.png","imgLink":""},{"alt":"","id":735,"link":"https://blockspare.com/demo/default/agency/home/logoipsum-287/","caption":"","sizes":{"full":{"url":"https://blockspare.com/demo/default/agency/wp-content/uploads/sites/2/2023/06/logoipsum-287.png","height":40,"width":105,"orientation":"landscape"}},"url":"https://blockspare.com/demo/default/agency/wp-content/uploads/sites/2/2023/06/logoipsum-287.png","imgLink":""},{"alt":"","id":736,"link":"https://blockspare.com/demo/default/agency/home/logoipsum-215/","caption":"","sizes":{"full":{"url":"https://blockspare.com/demo/default/agency/wp-content/uploads/sites/2/2023/06/logoipsum-215.png","height":34,"width":128,"orientation":"landscape"}},"url":"https://blockspare.com/demo/default/agency/wp-content/uploads/sites/2/2023/06/logoipsum-215.png","imgLink":""},{"alt":"","id":737,"link":"https://blockspare.com/demo/default/agency/home/logoipsum-221-2/","caption":"","sizes":{"full":{"url":"https://blockspare.com/demo/default/agency/wp-content/uploads/sites/2/2023/06/logoipsum-221-1.png","height":26,"width":115,"orientation":"landscape"}},"url":"https://blockspare.com/demo/default/agency/wp-content/uploads/sites/2/2023/06/logoipsum-221-1.png","imgLink":""},{"alt":"","id":734,"link":"https://blockspare.com/demo/default/agency/home/logoipsum-232/","caption":"","sizes":{"full":{"url":"https://blockspare.com/demo/default/agency/wp-content/uploads/sites/2/2023/06/logoipsum-232.png","height":47,"width":98,"orientation":"landscape"}},"url":"https://blockspare.com/demo/default/agency/wp-content/uploads/sites/2/2023/06/logoipsum-232.png","imgLink":""}],"columns":6,"gutter":100,"marginTop":80,"className":"alignwide blockspare-2ad12675-67a6-4 blockspare-1564c2d5-89f1-4 blockspare-0b8d4d58-d4fb-4"} -->
                        <div class="blockspare-blocks blockspare-logos-wrapper has-gap-100 has-colums-6 wp-block-blockspare-blockspare-logos alignwide blockspare-2ad12675-67a6-4 blockspare-1564c2d5-89f1-4 blockspare-0b8d4d58-d4fb-4 blockspare-c124ffb6-d8ec-4" blockspare-animation=""><style>.blockspare-c124ffb6-d8ec-4 .blockspare-logo-grid-main{padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px;margin-top:80px;margin-right:0px;margin-bottom:30px;margin-left:0px}</style><div class="blockspare-logo-grid-main"><ul class="blockspare-logo-wrap"><li class="blockspare-gallery-item"><figure class="blockspare-gallery-figure blockspare-hover-item"><img src="https://blockspare.com/demo/default/agency/wp-content/uploads/sites/2/2023/06/logoipsum-234.png" alt="" data-id="732" data-imglink="" data-link="https://blockspare.com/demo/default/agency/home/logoipsum-234/" class="wp-image-732"/></figure></li><li class="blockspare-gallery-item"><figure class="blockspare-gallery-figure blockspare-hover-item"><img src="https://blockspare.com/demo/default/agency/wp-content/uploads/sites/2/2023/06/logoipsum-219.png" alt="" data-id="733" data-imglink="" data-link="https://blockspare.com/demo/default/agency/home/logoipsum-219/" class="wp-image-733"/></figure></li><li class="blockspare-gallery-item"><figure class="blockspare-gallery-figure blockspare-hover-item"><img src="https://blockspare.com/demo/default/agency/wp-content/uploads/sites/2/2023/06/logoipsum-287.png" alt="" data-id="735" data-imglink="" data-link="https://blockspare.com/demo/default/agency/home/logoipsum-287/" class="wp-image-735"/></figure></li><li class="blockspare-gallery-item"><figure class="blockspare-gallery-figure blockspare-hover-item"><img src="https://blockspare.com/demo/default/agency/wp-content/uploads/sites/2/2023/06/logoipsum-215.png" alt="" data-id="736" data-imglink="" data-link="https://blockspare.com/demo/default/agency/home/logoipsum-215/" class="wp-image-736"/></figure></li><li class="blockspare-gallery-item"><figure class="blockspare-gallery-figure blockspare-hover-item"><img src="https://blockspare.com/demo/default/agency/wp-content/uploads/sites/2/2023/06/logoipsum-221-1.png" alt="" data-id="737" data-imglink="" data-link="https://blockspare.com/demo/default/agency/home/logoipsum-221-2/" class="wp-image-737"/></figure></li><li class="blockspare-gallery-item"><figure class="blockspare-gallery-figure blockspare-hover-item"><img src="https://blockspare.com/demo/default/agency/wp-content/uploads/sites/2/2023/06/logoipsum-232.png" alt="" data-id="734" data-imglink="" data-link="https://blockspare.com/demo/default/agency/home/logoipsum-232/" class="wp-image-734"/></figure></li></ul></div></div>
                        <!-- /wp:blockspare/blockspare-logos --></div>
                        <!-- /wp:group --></div></div></div></div>
                        <!-- /wp:blockspare/blockspare-container -->',
                        'imagePath'    => 'sections',
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'agency',
                        'item'     => ['Agency', 'Counter'],
						'key'      => 'bs_section_7',
						'name'     => 'Agency Section 7',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/agency-section-7/",
						'content'  => BLOCKSPARE_PRO_PATH,
                        'imagePath'    => 'sections',
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'agency',
                        'item'     => ['Agency', 'Accordion'],
						'key'      => 'bs_section_8',
						'name'     => 'Agency Section 8',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/agency-section-8/",
						'content'  => BLOCKSPARE_PRO_PATH,
                        'imagePath'    => 'sections',
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'agency',
                        'item'     => ['Agency', 'Address'],
						'key'      => 'bs_section_9',
						'name'     => 'Agency Section 9',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/agency-section-9/",
						'content'  => BLOCKSPARE_PRO_PATH,
                        'imagePath'    => 'sections',
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'agency',
                        'item'     => ['Agency', 'Content Box'],
						'key'      => 'bs_section_10',
						'name'     => 'Agency Section 10',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/agency-section-10/",
						'content'  => BLOCKSPARE_PRO_PATH,
                        'imagePath'    => 'sections',
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'lawyer',
                        'item'     => ['Lawyer', 'Services'],
						'key'      => 'bs_section_11',
						'name'     => 'Lawyer Section 1',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/lawyer-section-1/",
						'content'  => BLOCKSPARE_PRO_PATH,
                        'imagePath'    => 'sections',
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'lawyer',
                        'item'     => ['Lawyer', 'Content Box'],
						'key'      => 'bs_section_12',
						'name'     => 'Lawyer Section 2',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/lawyer-section-2/",
						'content'  => '<!-- wp:blockspare/content-box {"marginTop":90,"marginBottom":90,"uniqueClass":"blockspare-b5a78648-16dc-4","align":"wide","sectionAlignment":"left","headerTitle":"\u003cstrong\u003eAbout Attorna\u003c/strong\u003e","headertitleColor":"#1f2839","headersubtitleColor":"#666666","headermarginBottom":20,"headerlayoutOption":"blockspare-style2","titlePaddingTop":10,"titlePaddingBottom":10,"subtitlePaddingTop":20,"subtitlePaddingBottom":10,"dashColor":"#b69d74","titleFontFamily":"Helvetica","titleFontWeight":"800","subTitleFontSize":0,"subTitleFontFamily":"Helvetica","sectionDescription":"Far far away, behind the word mountains, far from the countries Vokalia and Consonantia, there live the blind texts. Separated they live in Bookmarks grove right at the coast of the Semantics, a large language ocean. A small river.\u003cbr\u003e\u003cbr\u003eCEO \u0026amp; FOUNDER OF ATTORNA","imgURL":"https://blockspare.com/demo/default/lawyer/wp-content/uploads/sites/3/2023/12/pexels-august-de-richelieu-4427630.jpg","imgID":2390,"descriptionColor":"#666666","layoutOption":true,"design":"style-3","descriptionFontSize":15,"descriptionFontFamily":"Helvetica"} -->
                        <div class="wp-block-blockspare-content-box blockspare-b5a78648-16dc-4 blockspare-contentBox alignwide" blockspare-animation=""><style>.blockspare-b5a78648-16dc-4 .blockspare-content-wrapper{text-align:left;margin-top:90px;margin-right:0px;margin-bottom:90px;margin-left:0px}.blockspare-b5a78648-16dc-4 .blockspare-content-wrapper .blockspare-section-wrapper{padding-top:30px;padding-right:30px;padding-bottom:30px;padding-left:30px}.blockspare-b5a78648-16dc-4 .blockspare-desc-btn-wrap .blockspare-description{color:#666666;font-size:15px;font-family:Helvetica}.blockspare-b5a78648-16dc-4 .blockspare-section-header-wrapper{border-color:#b69d74}.blockspare-b5a78648-16dc-4 .blockspare-content-wrapper .blockspare-desc-btn-wrap .blockspare-description{margin-top:10px;margin-right:0px;margin-bottom:0px;margin-left:0px}.blockspare-b5a78648-16dc-4 .blockspare-section-head-wrap{background-color:transparent;text-align:left;margin-top:0px;margin-right:0px;margin-bottom:20px;margin-left:0px}.blockspare-b5a78648-16dc-4 .blockspare-block-button a.blockspare-button{color:#fff;border-width:2px;font-size:16px}.blockspare-b5a78648-16dc-4.wp-block-blockspare-content-box .blockspare-block-button .blockspare-button{background-color:var(--bgcolor)}.blockspare-b5a78648-16dc-4.wp-block-blockspare-content-box .blockspare-block-button .blockspare-button:visited{background-color:var(--bgcolor)}.blockspare-b5a78648-16dc-4.wp-block-blockspare-content-box .blockspare-block-button .blockspare-button:focus{background-color:var(--bgcolor)}.blockspare-b5a78648-16dc-4 .blockspare-section-head-wrap .blockspare-title{color:#1f2839;padding-top:10px;padding-right:0px;padding-bottom:10px;padding-left:0px;font-size:24px;font-family:Helvetica;font-weight:800}.blockspare-b5a78648-16dc-4 .blockspare-section-head-wrap .blockspare-title-wrapper .blockspare-title-dash{color:#b69d74!important}.blockspare-b5a78648-16dc-4 .blockspare-section-head-wrap .blockspare-subtitle{color:#666666;font-size:0px;font-family:Helvetica;padding-top:20px;padding-right:0px;padding-bottom:10px;padding-left:0px}@media screen and (max-width:1025px){.blockspare-b5a78648-16dc-4 .blockspare-block-button a.blockspare-button{font-size:14px}.blockspare-b5a78648-16dc-4 .blockspare-section-head-wrap .blockspare-title{font-size:22px}.blockspare-b5a78648-16dc-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}.blockspare-b5a78648-16dc-4 .blockspare-desc-btn-wrap .blockspare-description{font-size:16px}}@media screen and (max-width:768px){.blockspare-b5a78648-16dc-4 .blockspare-block-button a.blockspare-button{font-size:14px}.blockspare-b5a78648-16dc-4 .blockspare-section-head-wrap .blockspare-title{font-size:20px}.blockspare-b5a78648-16dc-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}.blockspare-b5a78648-16dc-4 .blockspare-desc-btn-wrap .blockspare-description{font-size:14px}}</style><div class="blockspare-content-wrapper blockspare-blocks blockspare-hover-item blockspare-hover-child reverse-img style-3"><div class="content-img-wrap"><img src="https://blockspare.com/demo/default/lawyer/wp-content/uploads/sites/3/2023/12/pexels-august-de-richelieu-4427630.jpg" alt="" class=" hover-child"/></div><div class="blockspare-section-wrapper blockspare-mainheader-wrap "><div class="blockspare-section-head-wrap"><div class="blockspare-title-subtitle-wrap"><div class="blockspare-section-header-wrapper blockspare-blocks"><div class="blockspare-section-head-wrap blockspare-style2 blockspare-left"><div class="blockspare-title-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><h2 class="blockspare-title"><strong>About Attorna</strong></h2><span class="blockspare-title-dash blockspare-lower-dash"></span></div><div class="blockspare-subtitle-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><span class="blockspare-title-dash blockspare-lower-dash"></span></div></div></div></div></div><div class="blockspare-desc-btn-wrap"><p class="blockspare-description">Far far away, behind the word mountains, far from the countries Vokalia and Consonantia, there live the blind texts. Separated they live in Bookmarks grove right at the coast of the Semantics, a large language ocean. A small river.<br><br>CEO &amp; FOUNDER OF ATTORNA</p></div></div></div></div>
                        <!-- /wp:blockspare/content-box -->',
                        'imagePath'    => 'sections',
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'lawyer',
                        'item'     => ['Lawyer', 'Counter'],
						'key'      => 'bs_section_13',
						'name'     => 'Lawyer Section 3',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/lawyer-section-3/",
						'content'  => BLOCKSPARE_PRO_PATH,
                        'imagePath'    => 'sections',
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'lawyer',
                        'item'     => ['Lawyer', 'Services'],
						'key'      => 'bs_section_14',
						'name'     => 'Lawyer Section 4',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/lawyer-section-4/",
						'content'  => BLOCKSPARE_PRO_PATH,
                        'imagePath'    => 'sections',
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'lawyer',
                        'item'     => ['Lawyer', 'User Profile'],
						'key'      => 'bs_section_15',
						'name'     => 'Lawyer Section 5',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/lawyer-section-5/",
						'content'  => BLOCKSPARE_PRO_PATH,
                        'imagePath'    => 'sections',
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'lawyer',
                        'item'     => ['Lawyer', 'Testimonial'],
						'key'      => 'bs_section_16',
						'name'     => 'Lawyer Section 6',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/lawyer-section-6/",
						'content'  => BLOCKSPARE_PRO_PATH,
                        'imagePath'    => 'sections',
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'lawyer',
                        'item'     => ['Lawyer', 'Logo Grid'],
						'key'      => 'bs_section_17',
						'name'     => 'Lawyer Section 7',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/lawyer-section-7/",
						'content'  => '<!-- wp:blockspare/blockspare-section-header {"uniqueClass":"blockspare-c70fb785-d262-4","sectionAlignment":"center","headerTitle":"Our Trusted Partners","headerSubTitle":"Our Partners","headertitleColor":"#1f2839","headersubtitleColor":"#666666","headerlayoutOption":"blockspare-style2","titlePaddingTop":5,"titlePaddingBottom":10,"subtitlePaddingTop":20,"dashColor":"#b69d74","titleFontFamily":"Helvetica","titleFontWeight":"800","subTitleFontSize":15,"subTitleFontFamily":"Helvetica"} -->
                        <div class="wp-block-blockspare-blockspare-section-header aligncenter blockspare-c70fb785-d262-4 blockspare-section-header-wrapper blockspare-blocks aligncenter" blockspare-animation=""><style>.blockspare-c70fb785-d262-4 .blockspare-section-head-wrap{background-color:transparent;text-align:center;margin-top:30px;margin-right:0px;margin-bottom:30px;margin-left:0px}.blockspare-c70fb785-d262-4 .blockspare-section-head-wrap .blockspare-title{color:#1f2839;padding-top:5px;padding-right:0px;padding-bottom:10px;padding-left:0px;font-size:24px;font-family:Helvetica;font-weight:800}.blockspare-c70fb785-d262-4 .blockspare-section-head-wrap .blockspare-title-wrapper .blockspare-title-dash{color:#b69d74!important}.blockspare-c70fb785-d262-4 .blockspare-section-head-wrap .blockspare-subtitle{color:#666666;font-size:15px;font-family:Helvetica;padding-top:20px;padding-right:0px;padding-bottom:0px;padding-left:0px}@media screen and (max-width:1025px){.blockspare-c70fb785-d262-4 .blockspare-section-head-wrap .blockspare-title{font-size:22px}.blockspare-c70fb785-d262-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}@media screen and (max-width:768px){.blockspare-c70fb785-d262-4 .blockspare-section-head-wrap .blockspare-title{font-size:20px}.blockspare-c70fb785-d262-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}</style><div class="blockspare-section-head-wrap blockspare-style2 blockspare-center blockspare-hover-item"><div class="blockspare-title-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><h2 class="blockspare-title">Our Trusted Partners</h2><span class="blockspare-title-dash blockspare-lower-dash"></span></div><div class="blockspare-subtitle-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><p class="blockspare-subtitle">Our Partners</p><span class="blockspare-title-dash blockspare-lower-dash"></span></div></div></div>
                        <!-- /wp:blockspare/blockspare-section-header -->
                        
                        <!-- wp:blockspare/blockspare-logos {"uniqueClass":"blockspare-af401bee-a079-4","align":"wide","images":[{"alt":"","id":2331,"link":"https://blockspare.com/demo/default/lawyer/lawyer-homepage2/logoipsum-214/","caption":"","sizes":{"full":{"url":"https://blockspare.com/demo/default/lawyer/wp-content/uploads/sites/3/2023/06/logoipsum-214.png","height":46,"width":96,"orientation":"landscape"}},"url":"https://blockspare.com/demo/default/lawyer/wp-content/uploads/sites/3/2023/06/logoipsum-214.png","imgLink":""},{"alt":"","id":2332,"link":"https://blockspare.com/demo/default/lawyer/lawyer-homepage2/logoipsum-219/","caption":"","sizes":{"full":{"url":"https://blockspare.com/demo/default/lawyer/wp-content/uploads/sites/3/2023/06/logoipsum-219.png","height":15,"width":150,"orientation":"landscape"}},"url":"https://blockspare.com/demo/default/lawyer/wp-content/uploads/sites/3/2023/06/logoipsum-219.png","imgLink":""},{"alt":"","id":2333,"link":"https://blockspare.com/demo/default/lawyer/lawyer-homepage2/logoipsum-232/","caption":"","sizes":{"full":{"url":"https://blockspare.com/demo/default/lawyer/wp-content/uploads/sites/3/2023/06/logoipsum-232.png","height":47,"width":98,"orientation":"landscape"}},"url":"https://blockspare.com/demo/default/lawyer/wp-content/uploads/sites/3/2023/06/logoipsum-232.png","imgLink":""},{"alt":"","id":2334,"link":"https://blockspare.com/demo/default/lawyer/lawyer-homepage2/logoipsum-215/","caption":"","sizes":{"full":{"url":"https://blockspare.com/demo/default/lawyer/wp-content/uploads/sites/3/2023/06/logoipsum-215.png","height":34,"width":128,"orientation":"landscape"}},"url":"https://blockspare.com/demo/default/lawyer/wp-content/uploads/sites/3/2023/06/logoipsum-215.png","imgLink":""},{"alt":"","id":2335,"link":"https://blockspare.com/demo/default/lawyer/lawyer-homepage2/logoipsum-287/","caption":"","sizes":{"full":{"url":"https://blockspare.com/demo/default/lawyer/wp-content/uploads/sites/3/2023/06/logoipsum-287.png","height":40,"width":105,"orientation":"landscape"}},"url":"https://blockspare.com/demo/default/lawyer/wp-content/uploads/sites/3/2023/06/logoipsum-287.png","imgLink":""},{"alt":"","id":2336,"link":"https://blockspare.com/demo/default/lawyer/lawyer-homepage2/logoipsum-221/","caption":"","sizes":{"full":{"url":"https://blockspare.com/demo/default/lawyer/wp-content/uploads/sites/3/2023/06/logoipsum-221.png","height":26,"width":115,"orientation":"landscape"}},"url":"https://blockspare.com/demo/default/lawyer/wp-content/uploads/sites/3/2023/06/logoipsum-221.png","imgLink":""}],"columns":6,"gutter":90,"className":"alignwide"} -->
                        <div class="blockspare-blocks blockspare-logos-wrapper has-gap-90 has-colums-6 wp-block-blockspare-blockspare-logos alignwide blockspare-af401bee-a079-4" blockspare-animation=""><style>.blockspare-af401bee-a079-4 .blockspare-logo-grid-main{padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px;margin-top:30px;margin-right:0px;margin-bottom:30px;margin-left:0px}</style><div class="blockspare-logo-grid-main"><ul class="blockspare-logo-wrap"><li class="blockspare-gallery-item"><figure class="blockspare-gallery-figure blockspare-hover-item"><img src="https://blockspare.com/demo/default/lawyer/wp-content/uploads/sites/3/2023/06/logoipsum-214.png" alt="" data-id="2331" data-imglink="" data-link="https://blockspare.com/demo/default/lawyer/lawyer-homepage2/logoipsum-214/" class="wp-image-2331"/></figure></li><li class="blockspare-gallery-item"><figure class="blockspare-gallery-figure blockspare-hover-item"><img src="https://blockspare.com/demo/default/lawyer/wp-content/uploads/sites/3/2023/06/logoipsum-219.png" alt="" data-id="2332" data-imglink="" data-link="https://blockspare.com/demo/default/lawyer/lawyer-homepage2/logoipsum-219/" class="wp-image-2332"/></figure></li><li class="blockspare-gallery-item"><figure class="blockspare-gallery-figure blockspare-hover-item"><img src="https://blockspare.com/demo/default/lawyer/wp-content/uploads/sites/3/2023/06/logoipsum-232.png" alt="" data-id="2333" data-imglink="" data-link="https://blockspare.com/demo/default/lawyer/lawyer-homepage2/logoipsum-232/" class="wp-image-2333"/></figure></li><li class="blockspare-gallery-item"><figure class="blockspare-gallery-figure blockspare-hover-item"><img src="https://blockspare.com/demo/default/lawyer/wp-content/uploads/sites/3/2023/06/logoipsum-215.png" alt="" data-id="2334" data-imglink="" data-link="https://blockspare.com/demo/default/lawyer/lawyer-homepage2/logoipsum-215/" class="wp-image-2334"/></figure></li><li class="blockspare-gallery-item"><figure class="blockspare-gallery-figure blockspare-hover-item"><img src="https://blockspare.com/demo/default/lawyer/wp-content/uploads/sites/3/2023/06/logoipsum-287.png" alt="" data-id="2335" data-imglink="" data-link="https://blockspare.com/demo/default/lawyer/lawyer-homepage2/logoipsum-287/" class="wp-image-2335"/></figure></li><li class="blockspare-gallery-item"><figure class="blockspare-gallery-figure blockspare-hover-item"><img src="https://blockspare.com/demo/default/lawyer/wp-content/uploads/sites/3/2023/06/logoipsum-221.png" alt="" data-id="2336" data-imglink="" data-link="https://blockspare.com/demo/default/lawyer/lawyer-homepage2/logoipsum-221/" class="wp-image-2336"/></figure></li></ul></div></div>
                        <!-- /wp:blockspare/blockspare-logos -->',
                        'imagePath'    => 'sections',
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'lawyer',
                        'item'     => ['Lawyer', 'Services'],
						'key'      => 'bs_section_18',
						'name'     => 'Lawyer Section 8',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/lawyer-section-8/",
						'content'  => BLOCKSPARE_PRO_PATH,
                        'imagePath'    => 'sections',
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'lawyer',
                        'item'     => ['Lawyer', 'Tab'],
						'key'      => 'bs_section_19',
						'name'     => 'Lawyer Section 9',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/lawyer-section-9/",
						'content'  => BLOCKSPARE_PRO_PATH,
                        'imagePath'    => 'sections',
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'lawyer',
                        'item'     => ['Lawyer', 'Accordion'],
						'key'      => 'bs_section_20',
						'name'     => 'Lawyer Section 10',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/lawyer-section-10/",
						'content'  => BLOCKSPARE_PRO_PATH,
                        'imagePath'    => 'sections',
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'restaurant',
                        'item'     => ['Restaurant', 'Content Box'],
						'key'      => 'bs_section_21',
						'name'     => 'Restaurant Section 1',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/restaurant-section-1/",
						'content'  => BLOCKSPARE_PRO_PATH,
                        'imagePath'    => 'sections',
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'restaurant',
                        'item'     => ['Restaurant', 'Services'],
						'key'      => 'bs_section_22',
						'name'     => 'Restaurant Section 2',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/restaurant-section-2/",
						'content'  => BLOCKSPARE_PRO_PATH,
                        'imagePath'    => 'sections',
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'restaurant',
                        'item'     => ['Restaurant', 'Content Box'],
						'key'      => 'bs_section_23',
						'name'     => 'Restaurant Section 3',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/restaurant-section-3/",
						'content'  => '<!-- wp:blockspare/blockspare-container {"paddingRight":0,"paddingBottom":10,"paddingLeft":0,"marginTop":0,"marginBottom":0,"uniqueClass":"blockspare-0d49bd72-a155-4","imgURL":"https://blockspare.com/demo/default/restaurant/wp-content/uploads/sites/4/2023/06/new-bg-pngimg.png","imgID":2956,"imgAlt":"","opacityRatio":90,"backGroundColor":"#a90409"} -->
                        <div class="wp-block-blockspare-blockspare-container alignfull blockspare-0d49bd72-a155-4" blockspare-animation=""><style>.blockspare-0d49bd72-a155-4 > .blockspare-block-container-wrapper{background-color:#a90409;padding-top:20px;padding-right:0px;padding-bottom:10px;padding-left:0px;margin-top:0px;margin-right:0px;margin-bottom:0px;margin-left:0px;border-radius:0}.blockspare-0d49bd72-a155-4 .blockspare-image-wrap{background-image:url(https://blockspare.com/demo/default/restaurant/wp-content/uploads/sites/4/2023/06/new-bg-pngimg.png)}</style><div class="blockspare-block-container-wrapper blockspare-hover-item"><div class="blockspare-container-background blockspare-image-wrap has-background-opacity-90 has-background-opacity"></div><div class="blockspare-container"><div class="blockspare-inner-blocks blockspare-inner-wrapper-blocks"><!-- wp:group {"align":"wide","layout":{"type":"constrained"}} -->
                        <div class="wp-block-group alignwide"><!-- wp:columns {"verticalAlignment":"center","align":"wide"} -->
                        <div class="wp-block-columns alignwide are-vertically-aligned-center"><!-- wp:column {"verticalAlignment":"center"} -->
                        <div class="wp-block-column is-vertically-aligned-center"><!-- wp:image {"id":705,"sizeSlug":"full","linkDestination":"none","className":"is-resized"} -->
                        <figure class="wp-block-image size-full is-resized"><img src="https://blockspare.com/demo/default/restaurant/wp-content/uploads/sites/4/2021/10/3.png" alt="" class="wp-image-705"/></figure>
                        <!-- /wp:image --></div>
                        <!-- /wp:column -->
                        
                        <!-- wp:column {"verticalAlignment":"center"} -->
                        <div class="wp-block-column is-vertically-aligned-center"><!-- wp:blockspare/blockspare-section-header {"uniqueClass":"blockspare-7f6504a7-83a4-4","headerTitle":"GET OUR APP NEW","titleFontSize":42,"headerSubTitle":"Separated they live in Bookmarks grove right at the coast of the Semantics, a large language ocean. A small river.","headertitleColor":"#ffffff","headersubtitleColor":"#ffffff","headermarginRight":30,"headermarginLeft":30,"headerlayoutOption":"blockspare-style2","titlePaddingBottom":10,"subtitlePaddingTop":20,"dashColor":"#ffffff","titleFontFamily":"Roboto Condensed","titleFontWeight":"700","titleFontSubset":"latin","titleLoadGoogleFonts":true,"subTitleFontFamily":"Roboto Condensed","subTitleFontWeight":"default","subTitleFontSubset":"latin","subTitleLoadGoogleFonts":true} -->
                        <div class="wp-block-blockspare-blockspare-section-header aligncenter blockspare-7f6504a7-83a4-4 blockspare-section-header-wrapper blockspare-blocks aligncenter" blockspare-animation=""><style>.blockspare-7f6504a7-83a4-4 .blockspare-section-head-wrap{background-color:transparent;text-align:left;margin-top:30px;margin-right:30px;margin-bottom:30px;margin-left:30px}.blockspare-7f6504a7-83a4-4 .blockspare-section-head-wrap .blockspare-title{color:#ffffff;padding-top:0px;padding-right:0px;padding-bottom:10px;padding-left:0px;font-size:42px;font-family:Roboto Condensed;font-weight:700}.blockspare-7f6504a7-83a4-4 .blockspare-section-head-wrap .blockspare-title-wrapper .blockspare-title-dash{color:#ffffff!important}.blockspare-7f6504a7-83a4-4 .blockspare-section-head-wrap .blockspare-subtitle{color:#ffffff;font-size:14px;font-family:Roboto Condensed;font-weight:default;padding-top:20px;padding-right:0px;padding-bottom:0px;padding-left:0px}@media screen and (max-width:1025px){.blockspare-7f6504a7-83a4-4 .blockspare-section-head-wrap .blockspare-title{font-size:22px}.blockspare-7f6504a7-83a4-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}@media screen and (max-width:768px){.blockspare-7f6504a7-83a4-4 .blockspare-section-head-wrap .blockspare-title{font-size:20px}.blockspare-7f6504a7-83a4-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}</style><div class="blockspare-section-head-wrap blockspare-style2 blockspare-left blockspare-hover-item"><div class="blockspare-title-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><h2 class="blockspare-title">GET OUR APP NEW</h2><span class="blockspare-title-dash blockspare-lower-dash"></span></div><div class="blockspare-subtitle-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><p class="blockspare-subtitle">Separated they live in Bookmarks grove right at the coast of the Semantics, a large language ocean. A small river.</p><span class="blockspare-title-dash blockspare-lower-dash"></span></div></div></div>
                        <!-- /wp:blockspare/blockspare-section-header -->
                        
                        <!-- wp:blockspare/blockspare-buttons {"sectionAlignment":"left","uniqueClass":"blockspare-3e202baa-5d7a-4","buttonText":"Download App","buttonBackgroundColor":"#ffffff","buttonTextColor":"#000000","buttonHoverEffect":"hover-style-2","buttonFontFamily":"Roboto Condensed","buttonLoadGoogleFonts":true,"paddingTop":10,"paddingBottom":10,"marginLeft":30,"marginRight":30} -->
                        <div class="wp-block-blockspare-blockspare-buttons blockspare-3e202baa-5d7a-4 blockspare-block-button-wrap" blockspare-animation=""><style>.blockspare-3e202baa-5d7a-4 .blockspare-block-button{text-align:left;margin-top:30px;margin-bottom:30px;margin-left:30px;margin-right:30px}.blockspare-3e202baa-5d7a-4 .blockspare-block-button span{color:#000000;border-width:2px;font-size:16px;font-family:Roboto Condensed}.blockspare-3e202baa-5d7a-4 .blockspare-block-button .blockspare-button{background-color:#ffffff}.blockspare-3e202baa-5d7a-4 .blockspare-block-button .blockspare-button:visited{background-color:#ffffff}.blockspare-3e202baa-5d7a-4 .blockspare-block-button .blockspare-button:focus{background-color:#ffffff}.blockspare-3e202baa-5d7a-4 .blockspare-block-button i{font-size:16px}.blockspare-3e202baa-5d7a-4 .blockspare-block-button a{padding-top:10px;padding-bottom:10px;padding-right:22px;padding-left:22px}@media screen and (max-width:1025px){.blockspare-3e202baa-5d7a-4 .blockspare-block-button span{font-size:14px}.blockspare-3e202baa-5d7a-4 .blockspare-block-button i{font-size:14px}}@media screen and (max-width:768px){.blockspare-3e202baa-5d7a-4 .blockspare-block-button span{font-size:14px}.blockspare-3e202baa-5d7a-4 .blockspare-block-button i{font-size:14px}}</style><div class="blockspare-block-button"><a class="blockspare-button blockspare-button-shape-rounded blockspare-button-size-small hover-style-2"><span>Download App</span></a></div></div>
                        <!-- /wp:blockspare/blockspare-buttons --></div>
                        <!-- /wp:column --></div>
                        <!-- /wp:columns --></div>
                        <!-- /wp:group --></div></div></div></div>
                        <!-- /wp:blockspare/blockspare-container -->',
                        'imagePath'    => 'sections',
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'restaurant',
                        'item'     => ['Restaurant', 'Price List'],
						'key'      => 'bs_section_24',
						'name'     => 'Restaurant Section 4',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/restaurant-section-4/",
						'content'  => BLOCKSPARE_PRO_PATH,
                        'imagePath'    => 'sections',
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'restaurant',
                        'item'     => ['Restaurant', 'Pricing Table'],
						'key'      => 'bs_section_25',
						'name'     => 'Restaurant Section 5',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/restaurant-section-5/",
						'content'  => BLOCKSPARE_PRO_PATH,
                        'imagePath'    => 'sections',
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'restaurant',
                        'item'     => ['Restaurant', 'Call To Action'],
						'key'      => 'bs_section_26',
						'name'     => 'Restaurant Section 6',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/restaurant-section-6/",
						'content'  => '<!-- wp:blockspare/blockspare-container {"paddingTop":60,"paddingBottom":60,"marginTop":0,"marginBottom":0,"uniqueClass":"blockspare-5f382c1e-3312-4","imgURL":"https://blockspare.com/demo/default/restaurant/wp-content/uploads/sites/4/2023/06/Artboard-1.png","imgID":2947,"imgAlt":"","backGroundColor":"#a90409"} -->
                        <div class="wp-block-blockspare-blockspare-container alignfull blockspare-5f382c1e-3312-4" blockspare-animation=""><style>.blockspare-5f382c1e-3312-4 > .blockspare-block-container-wrapper{background-color:#a90409;padding-top:60px;padding-right:20px;padding-bottom:60px;padding-left:20px;margin-top:0px;margin-right:0px;margin-bottom:0px;margin-left:0px;border-radius:0}.blockspare-5f382c1e-3312-4 .blockspare-image-wrap{background-image:url(https://blockspare.com/demo/default/restaurant/wp-content/uploads/sites/4/2023/06/Artboard-1.png)}</style><div class="blockspare-block-container-wrapper blockspare-hover-item"><div class="blockspare-container-background blockspare-image-wrap has-background-opacity-100 has-background-opacity"></div><div class="blockspare-container"><div class="blockspare-inner-blocks blockspare-inner-wrapper-blocks"><!-- wp:group {"layout":{"type":"constrained"}} -->
                        <div class="wp-block-group"><!-- wp:blockspare/blockspare-section-header {"uniqueClass":"blockspare-6bec73c6-e46b-4","sectionAlignment":"center","headerTitle":"RESERVATION","titleFontSize":42,"headerSubTitle":"Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua.","headertitleColor":"#ffffff","headersubtitleColor":"#ffffff","headermarginTop":0,"headermarginRight":50,"headermarginLeft":50,"headerlayoutOption":"blockspare-style2","titlePaddingBottom":10,"subtitlePaddingTop":20,"dashColor":"#ffffff","titleFontFamily":"Roboto Condensed","titleFontWeight":"700","titleFontSubset":"latin","titleLoadGoogleFonts":true,"subTitleFontFamily":"Roboto Condensed","subTitleFontWeight":"default","subTitleFontSubset":"latin","subTitleLoadGoogleFonts":true} -->
                        <div class="wp-block-blockspare-blockspare-section-header aligncenter blockspare-6bec73c6-e46b-4 blockspare-section-header-wrapper blockspare-blocks aligncenter" blockspare-animation=""><style>.blockspare-6bec73c6-e46b-4 .blockspare-section-head-wrap{background-color:transparent;text-align:center;margin-top:0px;margin-right:50px;margin-bottom:30px;margin-left:50px}.blockspare-6bec73c6-e46b-4 .blockspare-section-head-wrap .blockspare-title{color:#ffffff;padding-top:0px;padding-right:0px;padding-bottom:10px;padding-left:0px;font-size:42px;font-family:Roboto Condensed;font-weight:700}.blockspare-6bec73c6-e46b-4 .blockspare-section-head-wrap .blockspare-title-wrapper .blockspare-title-dash{color:#ffffff!important}.blockspare-6bec73c6-e46b-4 .blockspare-section-head-wrap .blockspare-subtitle{color:#ffffff;font-size:14px;font-family:Roboto Condensed;font-weight:default;padding-top:20px;padding-right:0px;padding-bottom:0px;padding-left:0px}@media screen and (max-width:1025px){.blockspare-6bec73c6-e46b-4 .blockspare-section-head-wrap .blockspare-title{font-size:22px}.blockspare-6bec73c6-e46b-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}@media screen and (max-width:768px){.blockspare-6bec73c6-e46b-4 .blockspare-section-head-wrap .blockspare-title{font-size:20px}.blockspare-6bec73c6-e46b-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}</style><div class="blockspare-section-head-wrap blockspare-style2 blockspare-center blockspare-hover-item"><div class="blockspare-title-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><h2 class="blockspare-title">RESERVATION</h2><span class="blockspare-title-dash blockspare-lower-dash"></span></div><div class="blockspare-subtitle-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><p class="blockspare-subtitle">Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua.</p><span class="blockspare-title-dash blockspare-lower-dash"></span></div></div></div>
                        <!-- /wp:blockspare/blockspare-section-header --></div>
                        <!-- /wp:group -->
                        
                        <!-- wp:blockspare/blockspare-buttons {"uniqueClass":"blockspare-b6b4d776-cd5a-4","buttonText":"Book Now","buttonBackgroundColor":"#ffffff","buttonTextColor":"#000000","buttonHoverEffect":"hover-style-2","buttonFontFamily":"Roboto Condensed","buttonLoadGoogleFonts":true,"paddingTop":10,"paddingBottom":10,"marginBottom":0} -->
                        <div class="wp-block-blockspare-blockspare-buttons blockspare-b6b4d776-cd5a-4 blockspare-block-button-wrap" blockspare-animation=""><style>.blockspare-b6b4d776-cd5a-4 .blockspare-block-button{text-align:center;margin-top:30px;margin-bottom:0px;margin-left:0px;margin-right:0px}.blockspare-b6b4d776-cd5a-4 .blockspare-block-button span{color:#000000;border-width:2px;font-size:16px;font-family:Roboto Condensed}.blockspare-b6b4d776-cd5a-4 .blockspare-block-button .blockspare-button{background-color:#ffffff}.blockspare-b6b4d776-cd5a-4 .blockspare-block-button .blockspare-button:visited{background-color:#ffffff}.blockspare-b6b4d776-cd5a-4 .blockspare-block-button .blockspare-button:focus{background-color:#ffffff}.blockspare-b6b4d776-cd5a-4 .blockspare-block-button i{font-size:16px}.blockspare-b6b4d776-cd5a-4 .blockspare-block-button a{padding-top:10px;padding-bottom:10px;padding-right:22px;padding-left:22px}@media screen and (max-width:1025px){.blockspare-b6b4d776-cd5a-4 .blockspare-block-button span{font-size:14px}.blockspare-b6b4d776-cd5a-4 .blockspare-block-button i{font-size:14px}}@media screen and (max-width:768px){.blockspare-b6b4d776-cd5a-4 .blockspare-block-button span{font-size:14px}.blockspare-b6b4d776-cd5a-4 .blockspare-block-button i{font-size:14px}}</style><div class="blockspare-block-button"><a class="blockspare-button blockspare-button-shape-rounded blockspare-button-size-small hover-style-2"><span>Book Now</span></a></div></div>
                        <!-- /wp:blockspare/blockspare-buttons --></div></div></div></div>
                        <!-- /wp:blockspare/blockspare-container -->',
                        'imagePath'    => 'sections',
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'restaurant',
                        'item'     => ['Restaurant', 'User Profile'],
						'key'      => 'bs_section_27',
						'name'     => 'Restaurant Section 7',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/restaurant-section-7/",
						'content'  => BLOCKSPARE_PRO_PATH,
                        'imagePath'    => 'sections',
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'restaurant',
                        'item'     => ['Restaurant', 'Testimonial'],
						'key'      => 'bs_section_28',
						'name'     => 'Restaurant Section 8',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/restaurant-section-8/",
						'content'  => BLOCKSPARE_PRO_PATH,
                        'imagePath'    => 'sections',
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'restaurant',
                        'item'     => ['Restaurant', 'Services'],
						'key'      => 'bs_section_29',
						'name'     => 'Restaurant Section 9',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/restaurant-section-9/",
						'content'  => BLOCKSPARE_PRO_PATH,
                        'imagePath'    => 'sections',
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'restaurant',
                        'item'     => ['Restaurant', 'Post Grid'],
						'key'      => 'bs_section_30',
						'name'     => 'Restaurant Section 10',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/restaurant-section-10/",
						'content'  => BLOCKSPARE_PRO_PATH,
                        'imagePath'    => 'sections',
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'restaurant',
                        'item'     => ['Restaurant', 'Logo Grid'],
						'key'      => 'bs_section_31',
						'name'     => 'Restaurant Section 11',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/restaurant-section-11/",
						'content'  => '<!-- wp:blockspare/blockspare-container {"paddingTop":60,"paddingBottom":60,"marginTop":0,"marginBottom":0,"uniqueClass":"blockspare-d2824df4-bf3a-4","backGroundColor":"#1b1b1b"} -->
                        <div class="wp-block-blockspare-blockspare-container alignfull blockspare-d2824df4-bf3a-4" blockspare-animation=""><style>.blockspare-d2824df4-bf3a-4 > .blockspare-block-container-wrapper{background-color:#1b1b1b;padding-top:60px;padding-right:20px;padding-bottom:60px;padding-left:20px;margin-top:0px;margin-right:0px;margin-bottom:0px;margin-left:0px;border-radius:0}.blockspare-d2824df4-bf3a-4 .blockspare-image-wrap{background-image:none}</style><div class="blockspare-block-container-wrapper blockspare-hover-item"><div class="blockspare-container-background blockspare-image-wrap has-background-opacity-100 has-background-opacity"></div><div class="blockspare-container"><div class="blockspare-inner-blocks blockspare-inner-wrapper-blocks"><!-- wp:group {"layout":{"type":"constrained"}} -->
                        <div class="wp-block-group"><!-- wp:blockspare/blockspare-section-header {"uniqueClass":"blockspare-d772821b-8c66-4","sectionAlignment":"center","headerTitle":"OUR PARTNERS","titleFontSize":42,"headerSubTitle":"Our Trusted Partners","headertitleColor":"#ffffff","headersubtitleColor":"#ffffff","headermarginTop":20,"headermarginRight":30,"headermarginLeft":30,"headerlayoutOption":"blockspare-style2","titlePaddingBottom":10,"subtitlePaddingTop":20,"dashColor":"#ffffff","titleFontFamily":"Roboto Condensed","titleFontWeight":"700","titleFontSubset":"vietnamese","titleLoadGoogleFonts":true,"subTitleFontFamily":"Roboto Condensed","subTitleFontWeight":"default","subTitleFontSubset":"vietnamese","subTitleLoadGoogleFonts":true} -->
                        <div class="wp-block-blockspare-blockspare-section-header aligncenter blockspare-d772821b-8c66-4 blockspare-section-header-wrapper blockspare-blocks aligncenter" blockspare-animation=""><style>.blockspare-d772821b-8c66-4 .blockspare-section-head-wrap{background-color:transparent;text-align:center;margin-top:20px;margin-right:30px;margin-bottom:30px;margin-left:30px}.blockspare-d772821b-8c66-4 .blockspare-section-head-wrap .blockspare-title{color:#ffffff;padding-top:0px;padding-right:0px;padding-bottom:10px;padding-left:0px;font-size:42px;font-family:Roboto Condensed;font-weight:700}.blockspare-d772821b-8c66-4 .blockspare-section-head-wrap .blockspare-title-wrapper .blockspare-title-dash{color:#ffffff!important}.blockspare-d772821b-8c66-4 .blockspare-section-head-wrap .blockspare-subtitle{color:#ffffff;font-size:14px;font-family:Roboto Condensed;font-weight:default;padding-top:20px;padding-right:0px;padding-bottom:0px;padding-left:0px}@media screen and (max-width:1025px){.blockspare-d772821b-8c66-4 .blockspare-section-head-wrap .blockspare-title{font-size:22px}.blockspare-d772821b-8c66-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}@media screen and (max-width:768px){.blockspare-d772821b-8c66-4 .blockspare-section-head-wrap .blockspare-title{font-size:20px}.blockspare-d772821b-8c66-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}</style><div class="blockspare-section-head-wrap blockspare-style2 blockspare-center blockspare-hover-item"><div class="blockspare-title-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><h2 class="blockspare-title">OUR PARTNERS</h2><span class="blockspare-title-dash blockspare-lower-dash"></span></div><div class="blockspare-subtitle-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><p class="blockspare-subtitle">Our Trusted Partners</p><span class="blockspare-title-dash blockspare-lower-dash"></span></div></div></div>
                        <!-- /wp:blockspare/blockspare-section-header -->
                        
                        <!-- wp:blockspare/blockspare-logos {"uniqueClass":"blockspare-be940913-0a9d-4","align":"wide","images":[{"alt":"","id":3140,"link":"https://blockspare.com/demo/default/restaurant/about-us-2/white-logoipsum-234/","caption":"","sizes":{"full":{"url":"https://blockspare.com/demo/default/restaurant/wp-content/uploads/sites/4/2023/06/white-logoipsum-234.png","height":46,"width":93,"orientation":"landscape"}},"url":"https://blockspare.com/demo/default/restaurant/wp-content/uploads/sites/4/2023/06/white-logoipsum-234.png","imgLink":""},{"alt":"","id":3141,"link":"https://blockspare.com/demo/default/restaurant/about-us-2/white-logoipsum-221/","caption":"","sizes":{"full":{"url":"https://blockspare.com/demo/default/restaurant/wp-content/uploads/sites/4/2023/06/white-logoipsum-221.png","height":26,"width":115,"orientation":"landscape"}},"url":"https://blockspare.com/demo/default/restaurant/wp-content/uploads/sites/4/2023/06/white-logoipsum-221.png","imgLink":""},{"alt":"","id":3142,"link":"https://blockspare.com/demo/default/restaurant/about-us-2/white-logoipsum-215/","caption":"","sizes":{"full":{"url":"https://blockspare.com/demo/default/restaurant/wp-content/uploads/sites/4/2023/06/white-logoipsum-215.png","height":34,"width":128,"orientation":"landscape"}},"url":"https://blockspare.com/demo/default/restaurant/wp-content/uploads/sites/4/2023/06/white-logoipsum-215.png","imgLink":""},{"alt":"","id":3143,"link":"https://blockspare.com/demo/default/restaurant/about-us-2/white-logoipsum-287/","caption":"","sizes":{"full":{"url":"https://blockspare.com/demo/default/restaurant/wp-content/uploads/sites/4/2023/06/white-logoipsum-287.png","height":40,"width":105,"orientation":"landscape"}},"url":"https://blockspare.com/demo/default/restaurant/wp-content/uploads/sites/4/2023/06/white-logoipsum-287.png","imgLink":""},{"alt":"","id":3144,"link":"https://blockspare.com/demo/default/restaurant/about-us-2/white-logoipsum-232/","caption":"","sizes":{"full":{"url":"https://blockspare.com/demo/default/restaurant/wp-content/uploads/sites/4/2023/06/white-logoipsum-232.png","height":47,"width":98,"orientation":"landscape"}},"url":"https://blockspare.com/demo/default/restaurant/wp-content/uploads/sites/4/2023/06/white-logoipsum-232.png","imgLink":""},{"alt":"","id":3145,"link":"https://blockspare.com/demo/default/restaurant/about-us-2/whitelogoipsum-219/","caption":"","sizes":{"full":{"url":"https://blockspare.com/demo/default/restaurant/wp-content/uploads/sites/4/2023/06/whitelogoipsum-219.png","height":15,"width":150,"orientation":"landscape"}},"url":"https://blockspare.com/demo/default/restaurant/wp-content/uploads/sites/4/2023/06/whitelogoipsum-219.png","imgLink":""},{"alt":"","id":3146,"link":"https://blockspare.com/demo/default/restaurant/about-us-2/white-logoipsum-214/","caption":"","sizes":{"full":{"url":"https://blockspare.com/demo/default/restaurant/wp-content/uploads/sites/4/2023/06/white-logoipsum-214.png","height":46,"width":96,"orientation":"landscape"}},"url":"https://blockspare.com/demo/default/restaurant/wp-content/uploads/sites/4/2023/06/white-logoipsum-214.png","imgLink":""}],"columns":7,"gutter":80,"marginTop":50,"className":"alignwide"} -->
                        <div class="blockspare-blocks blockspare-logos-wrapper has-gap-80 has-colums-7 wp-block-blockspare-blockspare-logos alignwide blockspare-be940913-0a9d-4" blockspare-animation=""><style>.blockspare-be940913-0a9d-4 .blockspare-logo-grid-main{padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px;margin-top:50px;margin-right:0px;margin-bottom:30px;margin-left:0px}</style><div class="blockspare-logo-grid-main"><ul class="blockspare-logo-wrap"><li class="blockspare-gallery-item"><figure class="blockspare-gallery-figure blockspare-hover-item"><img src="https://blockspare.com/demo/default/restaurant/wp-content/uploads/sites/4/2023/06/white-logoipsum-234.png" alt="" data-id="3140" data-imglink="" data-link="https://blockspare.com/demo/default/restaurant/about-us-2/white-logoipsum-234/" class="wp-image-3140"/></figure></li><li class="blockspare-gallery-item"><figure class="blockspare-gallery-figure blockspare-hover-item"><img src="https://blockspare.com/demo/default/restaurant/wp-content/uploads/sites/4/2023/06/white-logoipsum-221.png" alt="" data-id="3141" data-imglink="" data-link="https://blockspare.com/demo/default/restaurant/about-us-2/white-logoipsum-221/" class="wp-image-3141"/></figure></li><li class="blockspare-gallery-item"><figure class="blockspare-gallery-figure blockspare-hover-item"><img src="https://blockspare.com/demo/default/restaurant/wp-content/uploads/sites/4/2023/06/white-logoipsum-215.png" alt="" data-id="3142" data-imglink="" data-link="https://blockspare.com/demo/default/restaurant/about-us-2/white-logoipsum-215/" class="wp-image-3142"/></figure></li><li class="blockspare-gallery-item"><figure class="blockspare-gallery-figure blockspare-hover-item"><img src="https://blockspare.com/demo/default/restaurant/wp-content/uploads/sites/4/2023/06/white-logoipsum-287.png" alt="" data-id="3143" data-imglink="" data-link="https://blockspare.com/demo/default/restaurant/about-us-2/white-logoipsum-287/" class="wp-image-3143"/></figure></li><li class="blockspare-gallery-item"><figure class="blockspare-gallery-figure blockspare-hover-item"><img src="https://blockspare.com/demo/default/restaurant/wp-content/uploads/sites/4/2023/06/white-logoipsum-232.png" alt="" data-id="3144" data-imglink="" data-link="https://blockspare.com/demo/default/restaurant/about-us-2/white-logoipsum-232/" class="wp-image-3144"/></figure></li><li class="blockspare-gallery-item"><figure class="blockspare-gallery-figure blockspare-hover-item"><img src="https://blockspare.com/demo/default/restaurant/wp-content/uploads/sites/4/2023/06/whitelogoipsum-219.png" alt="" data-id="3145" data-imglink="" data-link="https://blockspare.com/demo/default/restaurant/about-us-2/whitelogoipsum-219/" class="wp-image-3145"/></figure></li><li class="blockspare-gallery-item"><figure class="blockspare-gallery-figure blockspare-hover-item"><img src="https://blockspare.com/demo/default/restaurant/wp-content/uploads/sites/4/2023/06/white-logoipsum-214.png" alt="" data-id="3146" data-imglink="" data-link="https://blockspare.com/demo/default/restaurant/about-us-2/white-logoipsum-214/" class="wp-image-3146"/></figure></li></ul></div></div>
                        <!-- /wp:blockspare/blockspare-logos --></div>
                        <!-- /wp:group --></div></div></div></div>
                        <!-- /wp:blockspare/blockspare-container -->',
                        'imagePath'    => 'sections',
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'restaurant',
                        'item'     => ['Restaurant', 'Counter'],
						'key'      => 'bs_section_32',
						'name'     => 'Restaurant Section 12',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/restaurant-section-12/",
						'content'  => BLOCKSPARE_PRO_PATH,
                        'imagePath'    => 'sections',
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'restaurant',
                        'item'     => ['Restaurant', 'Content Box'],
						'key'      => 'bs_section_33',
						'name'     => 'Restaurant Section 13',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/restaurant-section-13/",
						'content'  => '<!-- wp:blockspare/blockspare-container {"uniqueClass":"blockspare-a20ebbf9-d2f0-4","backGroundColor":"#a90409"} -->
                        <div class="wp-block-blockspare-blockspare-container alignfull blockspare-a20ebbf9-d2f0-4" blockspare-animation=""><style>.blockspare-a20ebbf9-d2f0-4 > .blockspare-block-container-wrapper{background-color:#a90409;padding-top:20px;padding-right:20px;padding-bottom:20px;padding-left:20px;margin-top:30px;margin-right:0px;margin-bottom:30px;margin-left:0px;border-radius:0}.blockspare-a20ebbf9-d2f0-4 .blockspare-image-wrap{background-image:none}</style><div class="blockspare-block-container-wrapper blockspare-hover-item"><div class="blockspare-container-background blockspare-image-wrap has-background-opacity-100 has-background-opacity"></div><div class="blockspare-container"><div class="blockspare-inner-blocks blockspare-inner-wrapper-blocks"><!-- wp:group {"align":"wide","layout":{"type":"constrained"}} -->
                        <div class="wp-block-group alignwide"><!-- wp:columns {"align":"wide"} -->
                        <div class="wp-block-columns alignwide"><!-- wp:column {"verticalAlignment":"center","width":"66.66%"} -->
                        <div class="wp-block-column is-vertically-aligned-center" style="flex-basis:66.66%"><!-- wp:blockspare/blockspare-section-header {"uniqueClass":"blockspare-2f3fdf60-a41e-4","headerTitle":"MAKE YOUR BIRTHDAY SPECIAL","titleFontSize":42,"headerSubTitle":"Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do \u003cbr\u003eeiusmod tempor incididunt ut labore et dolore magna aliqua.\u003cbr\u003ePosuere lorem.\u003cbr\u003e","headertitleColor":"#ffffff","headersubtitleColor":"#ffffff","headerlayoutOption":"blockspare-style2","titlePaddingBottom":10,"subtitlePaddingTop":20,"dashColor":"#ffffff","titleFontFamily":"Roboto Condensed","titleFontWeight":"700","titleFontSubset":"latin","titleLoadGoogleFonts":true,"subTitleFontSize":16,"subTitleFontFamily":"Helvetica"} -->
                        <div class="wp-block-blockspare-blockspare-section-header aligncenter blockspare-2f3fdf60-a41e-4 blockspare-section-header-wrapper blockspare-blocks aligncenter" blockspare-animation=""><style>.blockspare-2f3fdf60-a41e-4 .blockspare-section-head-wrap{background-color:transparent;text-align:left;margin-top:30px;margin-right:0px;margin-bottom:30px;margin-left:0px}.blockspare-2f3fdf60-a41e-4 .blockspare-section-head-wrap .blockspare-title{color:#ffffff;padding-top:0px;padding-right:0px;padding-bottom:10px;padding-left:0px;font-size:42px;font-family:Roboto Condensed;font-weight:700}.blockspare-2f3fdf60-a41e-4 .blockspare-section-head-wrap .blockspare-title-wrapper .blockspare-title-dash{color:#ffffff!important}.blockspare-2f3fdf60-a41e-4 .blockspare-section-head-wrap .blockspare-subtitle{color:#ffffff;font-size:16px;font-family:Helvetica;padding-top:20px;padding-right:0px;padding-bottom:0px;padding-left:0px}@media screen and (max-width:1025px){.blockspare-2f3fdf60-a41e-4 .blockspare-section-head-wrap .blockspare-title{font-size:22px}.blockspare-2f3fdf60-a41e-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}@media screen and (max-width:768px){.blockspare-2f3fdf60-a41e-4 .blockspare-section-head-wrap .blockspare-title{font-size:20px}.blockspare-2f3fdf60-a41e-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}</style><div class="blockspare-section-head-wrap blockspare-style2 blockspare-left blockspare-hover-item"><div class="blockspare-title-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><h2 class="blockspare-title">MAKE YOUR BIRTHDAY SPECIAL</h2><span class="blockspare-title-dash blockspare-lower-dash"></span></div><div class="blockspare-subtitle-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><p class="blockspare-subtitle">Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do <br>eiusmod tempor incididunt ut labore et dolore magna aliqua.<br>Posuere lorem.<br></p><span class="blockspare-title-dash blockspare-lower-dash"></span></div></div></div>
                        <!-- /wp:blockspare/blockspare-section-header -->
                        
                        <!-- wp:blockspare/blockspare-buttons {"sectionAlignment":"left","uniqueClass":"blockspare-573eae7d-b7b7-4","buttonText":"Book Now","buttonBackgroundColor":"#ffffff","buttonTextColor":"#a90409","buttonFontSize":18,"buttonFontFamily":"Helvetica","buttonFontWeight":"default","marginTop":0,"marginBottom":0} -->
                        <div class="wp-block-blockspare-blockspare-buttons blockspare-573eae7d-b7b7-4 blockspare-block-button-wrap" blockspare-animation=""><style>.blockspare-573eae7d-b7b7-4 .blockspare-block-button{text-align:left;margin-top:0px;margin-bottom:0px;margin-left:0px;margin-right:0px}.blockspare-573eae7d-b7b7-4 .blockspare-block-button span{color:#a90409;border-width:2px;font-size:18px;font-family:Helvetica;font-weight:default}.blockspare-573eae7d-b7b7-4 .blockspare-block-button .blockspare-button{background-color:#ffffff}.blockspare-573eae7d-b7b7-4 .blockspare-block-button .blockspare-button:visited{background-color:#ffffff}.blockspare-573eae7d-b7b7-4 .blockspare-block-button .blockspare-button:focus{background-color:#ffffff}.blockspare-573eae7d-b7b7-4 .blockspare-block-button i{font-size:18px}.blockspare-573eae7d-b7b7-4 .blockspare-block-button a{padding-top:12px;padding-bottom:12px;padding-right:22px;padding-left:22px}@media screen and (max-width:1025px){.blockspare-573eae7d-b7b7-4 .blockspare-block-button span{font-size:14px}.blockspare-573eae7d-b7b7-4 .blockspare-block-button i{font-size:14px}}@media screen and (max-width:768px){.blockspare-573eae7d-b7b7-4 .blockspare-block-button span{font-size:14px}.blockspare-573eae7d-b7b7-4 .blockspare-block-button i{font-size:14px}}</style><div class="blockspare-block-button"><a class="blockspare-button blockspare-button-shape-rounded blockspare-button-size-small"><span>Book Now</span></a></div></div>
                        <!-- /wp:blockspare/blockspare-buttons --></div>
                        <!-- /wp:column -->
                        
                        <!-- wp:column {"verticalAlignment":"center","width":"33.33%"} -->
                        <div class="wp-block-column is-vertically-aligned-center" style="flex-basis:33.33%"><!-- wp:cover {"url":"https://blockspare.com/demo/default/restaurant/wp-content/uploads/sites/4/2023/12/pexels-davide-de-giovanni-3171823.jpg","id":3222,"dimRatio":0,"contentPosition":"bottom center","isDark":false,"style":{"color":{"duotone":["#000000","#ff4747"]}}} -->
                        <div class="wp-block-cover is-light has-custom-content-position is-position-bottom-center"><span aria-hidden="true" class="wp-block-cover__background has-background-dim-0 has-background-dim"></span><img class="wp-block-cover__image-background wp-image-3222" alt="" src="https://blockspare.com/demo/default/restaurant/wp-content/uploads/sites/4/2023/12/pexels-davide-de-giovanni-3171823.jpg" data-object-fit="cover"/><div class="wp-block-cover__inner-container"><!-- wp:paragraph {"align":"center","placeholder":"Write title…","fontSize":"large"} -->
                        <p class="has-text-align-center has-large-font-size"></p>
                        <!-- /wp:paragraph --></div></div>
                        <!-- /wp:cover --></div>
                        <!-- /wp:column --></div>
                        <!-- /wp:columns --></div>
                        <!-- /wp:group --></div></div></div></div>
                        <!-- /wp:blockspare/blockspare-container -->',
                        'imagePath'    => 'sections',
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'restaurant',
                        'item'     => ['Restaurant', 'Accordion'],
						'key'      => 'bs_section_34',
						'name'     => 'Restaurant Section 14',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/restaurant-section-14/",
						'content'  => BLOCKSPARE_PRO_PATH,
                        'imagePath'    => 'sections',
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'restaurant',
                        'item'     => ['Restaurant', 'Call To Action'],
						'key'      => 'bs_section_35',
						'name'     => 'Restaurant Section 15',
                        'blockLink'=>"https://blockspare.com/demo/default/fitness/",
						'content'  => BLOCKSPARE_PRO_PATH,
                        'imagePath'    => 'sections',
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'restaurant',
                        'item'     => ['Restaurant', 'Call To Action'],
						'key'      => 'bs_section_36',
						'name'     => 'Restaurant Section 16',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/restaurant-section-16/",
						'content'  => '<!-- wp:blockspare/blockspare-container {"paddingTop":0,"paddingRight":0,"paddingBottom":0,"paddingLeft":0,"marginTop":0,"marginBottom":0,"uniqueClass":"blockspare-080b8c5e-ac26-4","backGroundColor":"#a9060b"} -->
                        <div class="wp-block-blockspare-blockspare-container alignfull blockspare-080b8c5e-ac26-4" blockspare-animation=""><style>.blockspare-080b8c5e-ac26-4 > .blockspare-block-container-wrapper{background-color:#a9060b;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px;margin-top:0px;margin-right:0px;margin-bottom:0px;margin-left:0px;border-radius:0}.blockspare-080b8c5e-ac26-4 .blockspare-image-wrap{background-image:none}</style><div class="blockspare-block-container-wrapper blockspare-hover-item"><div class="blockspare-container-background blockspare-image-wrap has-background-opacity-100 has-background-opacity"></div><div class="blockspare-container"><div class="blockspare-inner-blocks blockspare-inner-wrapper-blocks"><!-- wp:group {"align":"wide","layout":{"type":"constrained"}} -->
                        <div class="wp-block-group alignwide"><!-- wp:blockspare/blockspare-call-to-action {"uniqueClass":"blockspare-787d7853-a7a1-4","align":"wide","sectionAlignment":"left","headerTitle":"EVERYDAY IS WEEKEND","titleFontSize":48,"headerSubTitle":"Lorem Ipsum has been the industry standard dummy text ever \u003cbr\u003esince the 1500s when an unknown.","headerlayoutOption":"blockspare-style2","titlePaddingBottom":10,"subtitlePaddingTop":20,"subtitlePaddingBottom":20,"dashColor":"#ffffff","titleFontFamily":"Roboto Condensed","titleFontWeight":"700","titleFontSubset":"latin","titleLoadGoogleFonts":true,"subTitleFontFamily":"Roboto Condensed","subTitleFontWeight":"default","subTitleFontSubset":"latin","subTitleLoadGoogleFonts":true,"imgURL":"https://blockspare.com/demo/default/restaurant/wp-content/uploads/sites/4/2021/09/app-1.jpg","imgID":269,"imgAlt":"","opacityRatio":100,"ctaBackGroundColor":"#00000000","buttonText":"Get 50% off","buttonBackgroundColor":"#ffffff","buttonTextColor":"#000000","buttonFontFamily":"Helvetica","paddingTop":0,"paddingRight":0,"paddingBottom":0,"paddingLeft":0,"marginTop":0,"marginBottom":0,"btnMarginTop":0} -->
                        <div class="wp-block-blockspare-blockspare-call-to-action blockspare-787d7853-a7a1-4 alignwide blockspare-calltoaction" blockspare-animation=""><style>.blockspare-787d7853-a7a1-4 .blockspare-cta-wrapper{background-color:#00000000;text-align:left;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px;margin-top:0px;margin-right:0px;margin-bottom:0px;margin-left:0px;border-radius:null}.blockspare-787d7853-a7a1-4 .blockspare-block-button a.blockspare-button{padding-top:12px;padding-right:22px;padding-bottom:12px;padding-left:22px;margin-top:0px;margin-right:0px;margin-bottom:0px;margin-left:0px;color:#000000;border-width:2px;font-family:Helvetica;font-size:16px}.blockspare-787d7853-a7a1-4.wp-block-blockspare-blockspare-call-to-action .blockspare-block-button .blockspare-button{background-color:#ffffff}.blockspare-787d7853-a7a1-4.wp-block-blockspare-blockspare-call-to-action .blockspare-block-button .blockspare-button:visited{background-color:#ffffff}.blockspare-787d7853-a7a1-4.wp-block-blockspare-blockspare-call-to-action .blockspare-block-button .blockspare-button:focus{background-color:#ffffff}.blockspare-787d7853-a7a1-4 .blockspare-section-head-wrap{background-color:transparent;text-align:left;margin-top:5px;margin-right:0px;margin-bottom:5px;margin-left:0px}.blockspare-787d7853-a7a1-4 .blockspare-section-head-wrap .blockspare-title{color:#fff;padding-top:0px;padding-right:0px;padding-bottom:10px;padding-left:0px;font-size:48px;font-family:Roboto Condensed;font-weight:700}.blockspare-787d7853-a7a1-4 .blockspare-section-head-wrap .blockspare-title-wrapper .blockspare-title-dash{color:#ffffff!important}.blockspare-787d7853-a7a1-4 .blockspare-section-head-wrap .blockspare-subtitle{color:#fff;font-size:14px;font-family:Roboto Condensed;font-weight:default;padding-top:20px;padding-right:0px;padding-bottom:20px;padding-left:0px}@media screen and (max-width:1025px){.blockspare-787d7853-a7a1-4 .blockspare-block-button a.blockspare-button{font-size:14px}.blockspare-787d7853-a7a1-4 .blockspare-section-head-wrap .blockspare-title{font-size:22px}.blockspare-787d7853-a7a1-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}@media screen and (max-width:768px){.blockspare-787d7853-a7a1-4 .blockspare-block-button a.blockspare-button{font-size:14px}.blockspare-787d7853-a7a1-4 .blockspare-section-head-wrap .blockspare-title{font-size:20px}.blockspare-787d7853-a7a1-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}</style><div class="blockspare-cta-wrapper blockspare-blocks blockspare-hover-item"><div class="blockspare-image-wrap blockspare-cta-background has-background-opacity-100 has-background-opacity" style="background-image:url(https://blockspare.com/demo/default/restaurant/wp-content/uploads/sites/4/2021/09/app-1.jpg)"></div><div class="blockspare-section-header-wrapper blockspare-blocks"><div class="blockspare-section-head-wrap blockspare-style2 blockspare-left"><div class="blockspare-title-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><h2 class="blockspare-title">EVERYDAY IS WEEKEND</h2><span class="blockspare-title-dash blockspare-lower-dash"></span></div><div class="blockspare-subtitle-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><p class="blockspare-subtitle">Lorem Ipsum has been the industry standard dummy text ever <br>since the 1500s when an unknown.</p><span class="blockspare-title-dash blockspare-lower-dash"></span></div></div></div><div class="blockspare-block-button"><a class="blockspare-button blockspare-button-shape-rounded blockspare-button-size-small"><span>Get 50% off</span></a></div></div></div>
                        <!-- /wp:blockspare/blockspare-call-to-action --></div>
                        <!-- /wp:group --></div></div></div></div>
                        <!-- /wp:blockspare/blockspare-container -->',
                        'imagePath'    => 'sections',
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'restaurant',
                        'item'     => ['Restaurant', 'Price List'],
						'key'      => 'bs_section_37',
						'name'     => 'Restaurant Section 17',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/restaurant-section-17/",
						'content'  => BLOCKSPARE_PRO_PATH,
                        'imagePath'    => 'sections',
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'restaurant',
                        'item'     => ['Restaurant', 'Price List'],
						'key'      => 'bs_section_38',
						'name'     => 'Restaurant Section 18',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/restaurant-section-18/",
						'content'  => BLOCKSPARE_PRO_PATH,
                        'imagePath'    => 'sections',
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'restaurant',
                        'item'     => ['Restaurant', 'Price List'],
						'key'      => 'bs_section_39',
						'name'     => 'Restaurant Section 19',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/restaurant-section-19/",
						'content'  => BLOCKSPARE_PRO_PATH,
                        'imagePath'    => 'sections',
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'restaurant',
                        'item'     => ['Restaurant', 'Address'],
						'key'      => 'bs_section_40',
						'name'     => 'Restaurant Section 20',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/restaurant-section-20/",
						'content'  => BLOCKSPARE_PRO_PATH,
                        'imagePath'    => 'sections',
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'apps',
                        'item'     => ['Apps', 'Content Box', 'Counter'],
						'key'      => 'bs_section_41',
						'name'     => 'Apps Section 1',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/apps-section-1/",
						'content'  => BLOCKSPARE_PRO_PATH,
                        'imagePath'    => 'sections',
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'apps',
                        'item'     => ['Apps', 'Services'],
						'key'      => 'bs_section_42',
						'name'     => 'Apps Section 2',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/apps-section-2/",
						'content'  => BLOCKSPARE_PRO_PATH,
                        'imagePath'    => 'sections',
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'apps',
                        'item'     => ['Apps', 'Content Box'],
						'key'      => 'bs_section_43',
						'name'     => 'Apps Section 3',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/apps-section-3/",
						'content'  => '<!-- wp:blockspare/content-box {"marginTop":50,"marginBottom":50,"uniqueClass":"blockspare-51ce1bc6-fb73-4","align":"wide","sectionAlignment":"left","headerTitle":"Conversation security \u0026amp; violation shield.","titleFontSize":42,"headerSubTitle":"Feature One","headerlayoutOption":"blockspare-style3","titlePaddingBottom":12,"titleFontFamily":"Poppins","titleFontWeight":"700","titleFontSubset":"latin","titleLoadGoogleFonts":true,"subTitleFontFamily":"Poppins","subTitleFontWeight":"400","subTitleFontSubset":"latin","subTitleLoadGoogleFonts":true,"sectionDescription":"Fusce fermentum odio nec arcu. Nam at tortor in tellus interdum sagittis. Praesent egestas neque eu enim. Praesent blandit laoreet nibh. Nunc sed turpis.","imgURL":"https://blockspare.com/demo/default/app/wp-content/uploads/sites/5/2023/12/31745454_7856001-scaled.jpg","imgID":307,"design":"style-3","descriptionFontFamily":"Poppins","descriptionFontWeight":"400","descriptionFontSubset":"latin","descriptionLoadGoogleFonts":true} -->
                        <div class="wp-block-blockspare-content-box blockspare-51ce1bc6-fb73-4 blockspare-contentBox alignwide" blockspare-animation=""><style>.blockspare-51ce1bc6-fb73-4 .blockspare-content-wrapper{text-align:left;margin-top:50px;margin-right:0px;margin-bottom:50px;margin-left:0px}.blockspare-51ce1bc6-fb73-4 .blockspare-content-wrapper .blockspare-section-wrapper{padding-top:30px;padding-right:30px;padding-bottom:30px;padding-left:30px}.blockspare-51ce1bc6-fb73-4 .blockspare-desc-btn-wrap .blockspare-description{color:#6d6d6d;font-size:16px;font-family:Poppins;font-weight:400}.blockspare-51ce1bc6-fb73-4 .blockspare-section-header-wrapper{border-color:#8b249c}.blockspare-51ce1bc6-fb73-4 .blockspare-content-wrapper .blockspare-desc-btn-wrap .blockspare-description{margin-top:10px;margin-right:0px;margin-bottom:0px;margin-left:0px}.blockspare-51ce1bc6-fb73-4 .blockspare-section-head-wrap{background-color:transparent;text-align:left;margin-top:0px;margin-right:0px;margin-bottom:0px;margin-left:0px}.blockspare-51ce1bc6-fb73-4 .blockspare-block-button a.blockspare-button{color:#fff;border-width:2px;font-size:16px}.blockspare-51ce1bc6-fb73-4.wp-block-blockspare-content-box .blockspare-block-button .blockspare-button{background-color:var(--bgcolor)}.blockspare-51ce1bc6-fb73-4.wp-block-blockspare-content-box .blockspare-block-button .blockspare-button:visited{background-color:var(--bgcolor)}.blockspare-51ce1bc6-fb73-4.wp-block-blockspare-content-box .blockspare-block-button .blockspare-button:focus{background-color:var(--bgcolor)}.blockspare-51ce1bc6-fb73-4 .blockspare-section-head-wrap .blockspare-title{color:#404040;padding-top:0px;padding-right:0px;padding-bottom:12px;padding-left:0px;font-size:42px;font-family:Poppins;font-weight:700}.blockspare-51ce1bc6-fb73-4 .blockspare-section-head-wrap .blockspare-subtitle{color:#6d6d6d;font-size:14px;font-family:Poppins;font-weight:400;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px}@media screen and (max-width:1025px){.blockspare-51ce1bc6-fb73-4 .blockspare-block-button a.blockspare-button{font-size:14px}.blockspare-51ce1bc6-fb73-4 .blockspare-section-head-wrap .blockspare-title{font-size:22px}.blockspare-51ce1bc6-fb73-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}.blockspare-51ce1bc6-fb73-4 .blockspare-desc-btn-wrap .blockspare-description{font-size:16px}}@media screen and (max-width:768px){.blockspare-51ce1bc6-fb73-4 .blockspare-block-button a.blockspare-button{font-size:14px}.blockspare-51ce1bc6-fb73-4 .blockspare-section-head-wrap .blockspare-title{font-size:20px}.blockspare-51ce1bc6-fb73-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}.blockspare-51ce1bc6-fb73-4 .blockspare-desc-btn-wrap .blockspare-description{font-size:14px}}</style><div class="blockspare-content-wrapper blockspare-blocks blockspare-hover-item blockspare-hover-child style-3"><div class="content-img-wrap"><img src="https://blockspare.com/demo/default/app/wp-content/uploads/sites/5/2023/12/31745454_7856001-scaled.jpg" alt="" class=" hover-child"/></div><div class="blockspare-section-wrapper blockspare-mainheader-wrap "><div class="blockspare-section-head-wrap"><div class="blockspare-title-subtitle-wrap"><div class="blockspare-section-header-wrapper blockspare-blocks"><div class="blockspare-section-head-wrap blockspare-style3 blockspare-left"><div class="blockspare-title-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><h2 class="blockspare-title">Conversation security &amp; violation shield.</h2><span class="blockspare-title-dash blockspare-lower-dash"></span></div><div class="blockspare-subtitle-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><p class="blockspare-subtitle">Feature One</p><span class="blockspare-title-dash blockspare-lower-dash"></span></div></div></div></div></div><div class="blockspare-desc-btn-wrap"><p class="blockspare-description">Fusce fermentum odio nec arcu. Nam at tortor in tellus interdum sagittis. Praesent egestas neque eu enim. Praesent blandit laoreet nibh. Nunc sed turpis.</p></div></div></div></div>
                        <!-- /wp:blockspare/content-box -->',
                        'imagePath'    => 'sections',
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'apps',
                        'item'     => ['Apps', 'Call To Action'],
						'key'      => 'bs_section_44',
						'name'     => 'Apps Section 4',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/apps-section-4/",
						'content'  => '<!-- wp:blockspare/blockspare-call-to-action {"uniqueClass":"blockspare-f96616a0-5c06-4","align":"full","headerTitle":"Made For Creatives","titleFontSize":52,"titleFontFamily":"Poppins","titleFontWeight":"700","titleFontSubset":"latin","titleLoadGoogleFonts":true,"imgURL":"https://blockspare.com/demo/default/app/wp-content/uploads/sites/5/2023/12/11641780_4782112-scaled.jpg","imgID":304,"imgAlt":"","opacityRatio":10,"ctaBackGroundColor":"#ea4b50","buttonText":"Download","buttonBackgroundColor":"#ffffff","buttonTextColor":"#ea4b50","buttonShape":"blockspare-button-shape-circular","buttonHoverEffect":"hover-style-4","marginTop":0,"marginBottom":0} -->
                        <div class="wp-block-blockspare-blockspare-call-to-action blockspare-f96616a0-5c06-4 alignfull blockspare-calltoaction" blockspare-animation=""><style>.blockspare-f96616a0-5c06-4 .blockspare-cta-wrapper{background-color:#ea4b50;text-align:center;padding-top:20px;padding-right:20px;padding-bottom:20px;padding-left:20px;margin-top:0px;margin-right:0px;margin-bottom:0px;margin-left:0px;border-radius:null}.blockspare-f96616a0-5c06-4 .blockspare-block-button a.blockspare-button{padding-top:12px;padding-right:22px;padding-bottom:12px;padding-left:22px;margin-top:10px;margin-right:0px;margin-bottom:0px;margin-left:0px;color:#ea4b50;border-width:2px;font-size:16px}.blockspare-f96616a0-5c06-4.wp-block-blockspare-blockspare-call-to-action .blockspare-block-button .blockspare-button{background-color:#ffffff}.blockspare-f96616a0-5c06-4.wp-block-blockspare-blockspare-call-to-action .blockspare-block-button .blockspare-button:visited{background-color:#ffffff}.blockspare-f96616a0-5c06-4.wp-block-blockspare-blockspare-call-to-action .blockspare-block-button .blockspare-button:focus{background-color:#ffffff}.blockspare-f96616a0-5c06-4 .blockspare-section-head-wrap{background-color:transparent;text-align:center;margin-top:5px;margin-right:0px;margin-bottom:5px;margin-left:0px}.blockspare-f96616a0-5c06-4 .blockspare-section-head-wrap .blockspare-title{color:#fff;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px;font-size:52px;font-family:Poppins;font-weight:700}.blockspare-f96616a0-5c06-4 .blockspare-section-head-wrap .blockspare-subtitle{color:#fff;font-size:14px;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px}@media screen and (max-width:1025px){.blockspare-f96616a0-5c06-4 .blockspare-block-button a.blockspare-button{font-size:14px}.blockspare-f96616a0-5c06-4 .blockspare-section-head-wrap .blockspare-title{font-size:22px}.blockspare-f96616a0-5c06-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}@media screen and (max-width:768px){.blockspare-f96616a0-5c06-4 .blockspare-block-button a.blockspare-button{font-size:14px}.blockspare-f96616a0-5c06-4 .blockspare-section-head-wrap .blockspare-title{font-size:20px}.blockspare-f96616a0-5c06-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}</style><div class="blockspare-cta-wrapper blockspare-blocks blockspare-hover-item"><div class="blockspare-image-wrap blockspare-cta-background has-background-opacity-10 has-background-opacity" style="background-image:url(https://blockspare.com/demo/default/app/wp-content/uploads/sites/5/2023/12/11641780_4782112-scaled.jpg)"></div><div class="blockspare-section-header-wrapper blockspare-blocks"><div class="blockspare-section-head-wrap blockspare-style1 blockspare-center"><div class="blockspare-title-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><h2 class="blockspare-title">Made For Creatives</h2><span class="blockspare-title-dash blockspare-lower-dash"></span></div><div class="blockspare-subtitle-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><span class="blockspare-title-dash blockspare-lower-dash"></span></div></div></div><div class="blockspare-block-button"><a class="blockspare-button blockspare-button-shape-circular blockspare-button-size-small hover-style-4"><span>Download</span></a></div></div></div>
                        <!-- /wp:blockspare/blockspare-call-to-action -->',
                        'imagePath'    => 'sections',
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'apps',
                        'item'     => ['Apps', 'Pricing Table'],
						'key'      => 'bs_section_45',
						'name'     => 'Apps Section 5',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/apps-section-5/",
						'content'  => BLOCKSPARE_PRO_PATH,
                        'imagePath'    => 'sections',
                        
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'apps',
                        'item'     => ['Apps', 'Testimonial'],
						'key'      => 'bs_section_46',
						'name'     => 'Apps Section 6',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/apps-section-6/",
						'content'  => BLOCKSPARE_PRO_PATH,
                        'imagePath'    => 'sections',
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'apps',
                        'item'     => ['Apps', 'Services'],
						'key'      => 'bs_section_47',
						'name'     => 'Apps Section 7',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/apps-section-7/",
						'content'  => '<!-- wp:blockspare/blockspare-container {"paddingTop":50,"paddingBottom":50,"marginTop":0,"marginBottom":0,"uniqueClass":"blockspare-80fa43f3-8f2c-4"} -->
                        <div class="wp-block-blockspare-blockspare-container alignfull blockspare-80fa43f3-8f2c-4" blockspare-animation=""><style>.blockspare-80fa43f3-8f2c-4 > .blockspare-block-container-wrapper{background-color:#f9f9f9;padding-top:50px;padding-right:20px;padding-bottom:50px;padding-left:20px;margin-top:0px;margin-right:0px;margin-bottom:0px;margin-left:0px;border-radius:0}.blockspare-80fa43f3-8f2c-4 .blockspare-image-wrap{background-image:none}</style><div class="blockspare-block-container-wrapper blockspare-hover-item"><div class="blockspare-container-background blockspare-image-wrap has-background-opacity-100 has-background-opacity"></div><div class="blockspare-container"><div class="blockspare-inner-blocks blockspare-inner-wrapper-blocks"><!-- wp:group {"layout":{"type":"constrained"}} -->
                        <div class="wp-block-group"><!-- wp:blockspare/blockspare-section-header {"uniqueClass":"blockspare-78926e1e-5de1-4","sectionAlignment":"center","headerTitle":"Get in Touch","titleFontSize":42,"headerSubTitle":"Praesent congue erat at massa. Praesent blandit laoreet nibh. Sed in libero ut nibh placerat accumsan. Praesent metus adipiscing nec, purus.","titleFontFamily":"Poppins","titleFontWeight":"700","titleFontSubset":"latin","titleLoadGoogleFonts":true,"subTitleFontFamily":"Poppins","subTitleFontWeight":"400","subTitleFontSubset":"latin","subTitleLoadGoogleFonts":true} -->
                        <div class="wp-block-blockspare-blockspare-section-header aligncenter blockspare-78926e1e-5de1-4 blockspare-section-header-wrapper blockspare-blocks aligncenter" blockspare-animation=""><style>.blockspare-78926e1e-5de1-4 .blockspare-section-head-wrap{background-color:transparent;text-align:center;margin-top:30px;margin-right:0px;margin-bottom:30px;margin-left:0px}.blockspare-78926e1e-5de1-4 .blockspare-section-head-wrap .blockspare-title{color:#404040;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px;font-size:42px;font-family:Poppins;font-weight:700}.blockspare-78926e1e-5de1-4 .blockspare-section-head-wrap .blockspare-subtitle{color:#6d6d6d;font-size:14px;font-family:Poppins;font-weight:400;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px}@media screen and (max-width:1025px){.blockspare-78926e1e-5de1-4 .blockspare-section-head-wrap .blockspare-title{font-size:22px}.blockspare-78926e1e-5de1-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}@media screen and (max-width:768px){.blockspare-78926e1e-5de1-4 .blockspare-section-head-wrap .blockspare-title{font-size:20px}.blockspare-78926e1e-5de1-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}</style><div class="blockspare-section-head-wrap blockspare-style1 blockspare-center blockspare-hover-item"><div class="blockspare-title-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><h2 class="blockspare-title">Get in Touch</h2><span class="blockspare-title-dash blockspare-lower-dash"></span></div><div class="blockspare-subtitle-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><p class="blockspare-subtitle">Praesent congue erat at massa. Praesent blandit laoreet nibh. Sed in libero ut nibh placerat accumsan. Praesent metus adipiscing nec, purus.</p><span class="blockspare-title-dash blockspare-lower-dash"></span></div></div></div>
                        <!-- /wp:blockspare/blockspare-section-header -->
                        
                        <!-- wp:blockspare/blockspare-buttons {"uniqueClass":"blockspare-00f166d4-066e-4","buttonText":"Contact Now","buttonBackgroundColor":"#ea4b50","buttonShape":"blockspare-button-shape-circular","buttonHoverEffect":"hover-style-2","buttonFontFamily":"Poppins","buttonLoadGoogleFonts":true} -->
                        <div class="wp-block-blockspare-blockspare-buttons blockspare-00f166d4-066e-4 blockspare-block-button-wrap" blockspare-animation=""><style>.blockspare-00f166d4-066e-4 .blockspare-block-button{text-align:center;margin-top:30px;margin-bottom:30px;margin-left:0px;margin-right:0px}.blockspare-00f166d4-066e-4 .blockspare-block-button span{color:#fff;border-width:2px;font-size:16px;font-family:Poppins}.blockspare-00f166d4-066e-4 .blockspare-block-button .blockspare-button{background-color:#ea4b50}.blockspare-00f166d4-066e-4 .blockspare-block-button .blockspare-button:visited{background-color:#ea4b50}.blockspare-00f166d4-066e-4 .blockspare-block-button .blockspare-button:focus{background-color:#ea4b50}.blockspare-00f166d4-066e-4 .blockspare-block-button i{font-size:16px}.blockspare-00f166d4-066e-4 .blockspare-block-button a{padding-top:12px;padding-bottom:12px;padding-right:22px;padding-left:22px}@media screen and (max-width:1025px){.blockspare-00f166d4-066e-4 .blockspare-block-button span{font-size:14px}.blockspare-00f166d4-066e-4 .blockspare-block-button i{font-size:14px}}@media screen and (max-width:768px){.blockspare-00f166d4-066e-4 .blockspare-block-button span{font-size:14px}.blockspare-00f166d4-066e-4 .blockspare-block-button i{font-size:14px}}</style><div class="blockspare-block-button"><a class="blockspare-button blockspare-button-shape-circular blockspare-button-size-small hover-style-2"><span>Contact Now</span></a></div></div>
                        <!-- /wp:blockspare/blockspare-buttons --></div>
                        <!-- /wp:group --></div></div></div></div>
                        <!-- /wp:blockspare/blockspare-container -->',
                        'imagePath'    => 'sections',
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'apps',
                        'item'     => ['Apps','Services'],
						'key'      => 'bs_section_48',
						'name'     => 'Apps Section 8',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/apps-section-8/",
						'content'  => BLOCKSPARE_PRO_PATH,
                        'imagePath'    => 'sections',
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'education',
                        'item'     => ['Education','Call To Action'],
						'key'      => 'bs_section_49',
                        'imagePath'    => 'sections',
						'name'     => 'Education Section 1',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/education-section-1/",
						'content'  => '<!-- wp:blockspare/blockspare-call-to-action {"uniqueClass":"blockspare-0b12439c-2362-4","align":"full","headerTitle":" Build Skills with Online Courses \u003cbr\u003efrom Expert Instructor ","titleFontSize":36,"headerSubTitle":" Start streaming on-demand video lectures today from top level \u003cbr\u003einstructors Attention heatmaps. ","subtitlePaddingTop":20,"subtitlePaddingBottom":25,"titleFontFamily":"Helvetica","titleFontWeight":"800","titleFontSubset":"devanagari","subTitleFontFamily":"Helvetica","subTitleFontWeight":"400","subTitleFontSubset":"latin","imgURL":"https://blockspare.com/demo/default/education/wp-content/uploads/sites/6/2023/12/pexels-vlada-karpovich-4050466.jpg","imgID":2365,"imgAlt":"","opacityRatio":50,"buttonText":"Enroll Now","buttonBackgroundColor":"#275be2","buttonHoverEffect":"hover-style-2","buttonFontFamily":"Helvetica","buttonFontSubset":"latin","marginTop":0,"marginBottom":0} -->
                        <div class="wp-block-blockspare-blockspare-call-to-action blockspare-0b12439c-2362-4 alignfull blockspare-calltoaction" blockspare-animation=""><style>.blockspare-0b12439c-2362-4 .blockspare-cta-wrapper{background-color:#0e0e0e;text-align:center;padding-top:20px;padding-right:20px;padding-bottom:20px;padding-left:20px;margin-top:0px;margin-right:0px;margin-bottom:0px;margin-left:0px;border-radius:null}.blockspare-0b12439c-2362-4 .blockspare-block-button a.blockspare-button{padding-top:12px;padding-right:22px;padding-bottom:12px;padding-left:22px;margin-top:10px;margin-right:0px;margin-bottom:0px;margin-left:0px;color:#fff;border-width:2px;font-family:Helvetica;font-size:16px}.blockspare-0b12439c-2362-4.wp-block-blockspare-blockspare-call-to-action .blockspare-block-button .blockspare-button{background-color:#275be2}.blockspare-0b12439c-2362-4.wp-block-blockspare-blockspare-call-to-action .blockspare-block-button .blockspare-button:visited{background-color:#275be2}.blockspare-0b12439c-2362-4.wp-block-blockspare-blockspare-call-to-action .blockspare-block-button .blockspare-button:focus{background-color:#275be2}.blockspare-0b12439c-2362-4 .blockspare-section-head-wrap{background-color:transparent;text-align:center;margin-top:5px;margin-right:0px;margin-bottom:5px;margin-left:0px}.blockspare-0b12439c-2362-4 .blockspare-section-head-wrap .blockspare-title{color:#fff;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px;font-size:36px;font-family:Helvetica;font-weight:800}.blockspare-0b12439c-2362-4 .blockspare-section-head-wrap .blockspare-subtitle{color:#fff;font-size:14px;font-family:Helvetica;font-weight:400;padding-top:20px;padding-right:0px;padding-bottom:25px;padding-left:0px}@media screen and (max-width:1025px){.blockspare-0b12439c-2362-4 .blockspare-block-button a.blockspare-button{font-size:14px}.blockspare-0b12439c-2362-4 .blockspare-section-head-wrap .blockspare-title{font-size:22px}.blockspare-0b12439c-2362-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}@media screen and (max-width:768px){.blockspare-0b12439c-2362-4 .blockspare-block-button a.blockspare-button{font-size:14px}.blockspare-0b12439c-2362-4 .blockspare-section-head-wrap .blockspare-title{font-size:20px}.blockspare-0b12439c-2362-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}</style><div class="blockspare-cta-wrapper blockspare-blocks blockspare-hover-item"><div class="blockspare-image-wrap blockspare-cta-background has-background-opacity-50 has-background-opacity" style="background-image:url(https://blockspare.com/demo/default/education/wp-content/uploads/sites/6/2023/12/pexels-vlada-karpovich-4050466.jpg)"></div><div class="blockspare-section-header-wrapper blockspare-blocks"><div class="blockspare-section-head-wrap blockspare-style1 blockspare-center"><div class="blockspare-title-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><h2 class="blockspare-title"> Build Skills with Online Courses <br>from Expert Instructor </h2><span class="blockspare-title-dash blockspare-lower-dash"></span></div><div class="blockspare-subtitle-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><p class="blockspare-subtitle"> Start streaming on-demand video lectures today from top level <br>instructors Attention heatmaps. </p><span class="blockspare-title-dash blockspare-lower-dash"></span></div></div></div><div class="blockspare-block-button"><a class="blockspare-button blockspare-button-shape-rounded blockspare-button-size-small hover-style-2"><span>Enroll Now</span></a></div></div></div>
                        <!-- /wp:blockspare/blockspare-call-to-action -->',
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'education',
                        'item'     => ['Education','Services'],
						'key'      => 'bs_section_50',
                        'imagePath'    => 'sections',
						'name'     => 'Education Section 2',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/education-section-2/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'education',
                        'item'     => ['Education','Services'],
						'key'      => 'bs_section_51',
                        'imagePath'    => 'sections',
						'name'     => 'Education Section 3',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/education-section-3/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'education',
                        'item'     => ['Education','Counter'],
						'key'      => 'bs_section_52',
                        'imagePath'    => 'sections',
						'name'     => 'Education Section 4',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/education-section-4/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'education',
                        'item'     => ['Education','Call To Action'],
						'key'      => 'bs_section_53',
                        'imagePath'    => 'sections',
						'name'     => 'Education Section 5',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/education-section-5/",
						'content'  => '<!-- wp:blockspare/blockspare-call-to-action {"uniqueClass":"blockspare-9589aba8-adfa-4","align":"full","headerTitle":"Ready to Enroll Now?","titleFontSize":36,"headerSubTitle":"You can start and finish one of these popular \u003cbr\u003ecourses in under a day for free","subtitlePaddingTop":20,"subtitlePaddingBottom":20,"titleFontFamily":"Helvetica","titleFontWeight":"800","subTitleFontFamily":"Helvetica","imgURL":"https://blockspare.com/demo/default/education/wp-content/uploads/sites/6/2023/12/pexels-tima-miroshnichenko-5686056.jpg","imgID":2373,"imgAlt":"","opacityRatio":60,"buttonText":"View All Courses","buttonBackgroundColor":"#275be2","buttonHoverEffect":"hover-style-2","buttonFontFamily":"Helvetica","marginTop":0,"marginBottom":0} -->
                            <div class="wp-block-blockspare-blockspare-call-to-action blockspare-9589aba8-adfa-4 alignfull blockspare-calltoaction" blockspare-animation=""><style>.blockspare-9589aba8-adfa-4 .blockspare-cta-wrapper{background-color:#0e0e0e;text-align:center;padding-top:20px;padding-right:20px;padding-bottom:20px;padding-left:20px;margin-top:0px;margin-right:0px;margin-bottom:0px;margin-left:0px;border-radius:null}.blockspare-9589aba8-adfa-4 .blockspare-block-button a.blockspare-button{padding-top:12px;padding-right:22px;padding-bottom:12px;padding-left:22px;margin-top:10px;margin-right:0px;margin-bottom:0px;margin-left:0px;color:#fff;border-width:2px;font-family:Helvetica;font-size:16px}.blockspare-9589aba8-adfa-4.wp-block-blockspare-blockspare-call-to-action .blockspare-block-button .blockspare-button{background-color:#275be2}.blockspare-9589aba8-adfa-4.wp-block-blockspare-blockspare-call-to-action .blockspare-block-button .blockspare-button:visited{background-color:#275be2}.blockspare-9589aba8-adfa-4.wp-block-blockspare-blockspare-call-to-action .blockspare-block-button .blockspare-button:focus{background-color:#275be2}.blockspare-9589aba8-adfa-4 .blockspare-section-head-wrap{background-color:transparent;text-align:center;margin-top:5px;margin-right:0px;margin-bottom:5px;margin-left:0px}.blockspare-9589aba8-adfa-4 .blockspare-section-head-wrap .blockspare-title{color:#fff;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px;font-size:36px;font-family:Helvetica;font-weight:800}.blockspare-9589aba8-adfa-4 .blockspare-section-head-wrap .blockspare-subtitle{color:#fff;font-size:14px;font-family:Helvetica;padding-top:20px;padding-right:0px;padding-bottom:20px;padding-left:0px}@media screen and (max-width:1025px){.blockspare-9589aba8-adfa-4 .blockspare-block-button a.blockspare-button{font-size:14px}.blockspare-9589aba8-adfa-4 .blockspare-section-head-wrap .blockspare-title{font-size:22px}.blockspare-9589aba8-adfa-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}@media screen and (max-width:768px){.blockspare-9589aba8-adfa-4 .blockspare-block-button a.blockspare-button{font-size:14px}.blockspare-9589aba8-adfa-4 .blockspare-section-head-wrap .blockspare-title{font-size:20px}.blockspare-9589aba8-adfa-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}</style><div class="blockspare-cta-wrapper blockspare-blocks blockspare-hover-item"><div class="blockspare-image-wrap blockspare-cta-background has-background-opacity-60 has-background-opacity" style="background-image:url(https://blockspare.com/demo/default/education/wp-content/uploads/sites/6/2023/12/pexels-tima-miroshnichenko-5686056.jpg)"></div><div class="blockspare-section-header-wrapper blockspare-blocks"><div class="blockspare-section-head-wrap blockspare-style1 blockspare-center"><div class="blockspare-title-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><h2 class="blockspare-title">Ready to Enroll Now?</h2><span class="blockspare-title-dash blockspare-lower-dash"></span></div><div class="blockspare-subtitle-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><p class="blockspare-subtitle">You can start and finish one of these popular <br>courses in under a day for free</p><span class="blockspare-title-dash blockspare-lower-dash"></span></div></div></div><div class="blockspare-block-button"><a class="blockspare-button blockspare-button-shape-rounded blockspare-button-size-small hover-style-2"><span>View All Courses</span></a></div></div></div>
                            <!-- /wp:blockspare/blockspare-call-to-action -->',
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'education',
                        'item'     => ['Education','Logo Grid'],
						'key'      => 'bs_section_54',
                        'imagePath'    => 'sections',
						'name'     => 'Education Section 6',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/education-section-6/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'education',
                        'item'     => ['Education','User Profile'],
						'key'      => 'bs_section_55',
                        'imagePath'    => 'sections',
						'name'     => 'Education Section 7',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/education-section-7/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'education',
                        'item'     => ['Education','Pricing Table'],
						'key'      => 'bs_section_56',
                        'imagePath'    => 'sections',
						'name'     => 'Education Section 8',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/education-section-8/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'education',
                        'item'     => ['Education','Services'],
						'key'      => 'bs_section_57',
                        'imagePath'    => 'sections',
						'name'     => 'Education Section 9',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/education-section-9/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'fitness',
                        'item'     => ['Fitness','Call To Action'],
						'key'      => 'bs_section_58',
                        'imagePath'    => 'sections',
						'name'     => 'Fitness Section 1',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/fitness-section-1/",
						'content'  => '<!-- wp:blockspare/blockspare-call-to-action {"uniqueClass":"blockspare-0d32d80f-a07b-4","align":"full","headerTitle":" KEEP YOUR BODY\u003cbr\u003eFIT \u0026amp; STRONG ","titleFontSize":66,"headerSubTitle":" Lorem Ipsum has been the industry standard dummy text ever \u003cbr\u003esince the 1500s when an unknown ","headersubtitleColor":"#c4c4c4","subtitlePaddingBottom":20,"titleFontFamily":"Georgia","titleFontWeight":"800","titleFontSubset":"latin","subTitleFontFamily":"Poppins","subTitleFontWeight":"default","subTitleFontSubset":"latin","subTitleLoadGoogleFonts":true,"imgURL":"https://blockspare.com/demo/default/fitness/wp-content/uploads/sites/7/2023/12/pexels-shotpot-4046704.jpg","imgID":1224,"imgAlt":"","opacityRatio":40,"buttonText":"START NOW","buttonBackgroundColor":"#e42024","buttonHoverEffect":"hover-style-5","buttonFontFamily":"Poppins","buttonFontWeight":"500","buttonFontSubset":"latin","buttonLoadGoogleFonts":true,"marginTop":0,"marginBottom":0} -->
                        <div class="wp-block-blockspare-blockspare-call-to-action blockspare-0d32d80f-a07b-4 alignfull blockspare-calltoaction" blockspare-animation=""><style>.blockspare-0d32d80f-a07b-4 .blockspare-cta-wrapper{background-color:#0e0e0e;text-align:center;padding-top:20px;padding-right:20px;padding-bottom:20px;padding-left:20px;margin-top:0px;margin-right:0px;margin-bottom:0px;margin-left:0px;border-radius:null}.blockspare-0d32d80f-a07b-4 .blockspare-block-button a.blockspare-button{padding-top:12px;padding-right:22px;padding-bottom:12px;padding-left:22px;margin-top:10px;margin-right:0px;margin-bottom:0px;margin-left:0px;color:#fff;border-width:2px;font-family:Poppins;font-size:16px;font-weight:500}.blockspare-0d32d80f-a07b-4.wp-block-blockspare-blockspare-call-to-action .blockspare-block-button .blockspare-button{background-color:#e42024}.blockspare-0d32d80f-a07b-4.wp-block-blockspare-blockspare-call-to-action .blockspare-block-button .blockspare-button:visited{background-color:#e42024}.blockspare-0d32d80f-a07b-4.wp-block-blockspare-blockspare-call-to-action .blockspare-block-button .blockspare-button:focus{background-color:#e42024}.blockspare-0d32d80f-a07b-4 .blockspare-section-head-wrap{background-color:transparent;text-align:center;margin-top:5px;margin-right:0px;margin-bottom:5px;margin-left:0px}.blockspare-0d32d80f-a07b-4 .blockspare-section-head-wrap .blockspare-title{color:#fff;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px;font-size:66px;font-family:Georgia;font-weight:800}.blockspare-0d32d80f-a07b-4 .blockspare-section-head-wrap .blockspare-subtitle{color:#c4c4c4;font-size:14px;font-family:Poppins;font-weight:default;padding-top:0px;padding-right:0px;padding-bottom:20px;padding-left:0px}@media screen and (max-width:1025px){.blockspare-0d32d80f-a07b-4 .blockspare-block-button a.blockspare-button{font-size:14px}.blockspare-0d32d80f-a07b-4 .blockspare-section-head-wrap .blockspare-title{font-size:22px}.blockspare-0d32d80f-a07b-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}@media screen and (max-width:768px){.blockspare-0d32d80f-a07b-4 .blockspare-block-button a.blockspare-button{font-size:14px}.blockspare-0d32d80f-a07b-4 .blockspare-section-head-wrap .blockspare-title{font-size:20px}.blockspare-0d32d80f-a07b-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}</style><div class="blockspare-cta-wrapper blockspare-blocks blockspare-hover-item"><div class="blockspare-image-wrap blockspare-cta-background has-background-opacity-40 has-background-opacity" style="background-image:url(https://blockspare.com/demo/default/fitness/wp-content/uploads/sites/7/2023/12/pexels-shotpot-4046704.jpg)"></div><div class="blockspare-section-header-wrapper blockspare-blocks"><div class="blockspare-section-head-wrap blockspare-style1 blockspare-center"><div class="blockspare-title-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><h2 class="blockspare-title"> KEEP YOUR BODY<br>FIT &amp; STRONG </h2><span class="blockspare-title-dash blockspare-lower-dash"></span></div><div class="blockspare-subtitle-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><p class="blockspare-subtitle"> Lorem Ipsum has been the industry standard dummy text ever <br>since the 1500s when an unknown </p><span class="blockspare-title-dash blockspare-lower-dash"></span></div></div></div><div class="blockspare-block-button"><a class="blockspare-button blockspare-button-shape-rounded blockspare-button-size-small hover-style-5"><span>START NOW</span></a></div></div></div>
                        <!-- /wp:blockspare/blockspare-call-to-action -->',
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'fitness',
                        'item'     => ['Fitness','Services'],
						'key'      => 'bs_section_59',
                        'imagePath'    => 'sections',
						'name'     => 'Fitness Section 2',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/fitness-section-2/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'fitness',
                        'item'     => ['Fitness','Call To Action'],
						'key'      => 'bs_section_60',
                        'imagePath'    => 'sections',
						'name'     => 'Fitness Section 3',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/fitness-section-3/",
						'content'  => '<!-- wp:blockspare/blockspare-container {"paddingTop":100,"paddingBottom":100,"marginTop":0,"marginBottom":0,"uniqueClass":"blockspare-609696d4-6b6b-4","backGroundColor":"#000000"} -->
                        <div class="wp-block-blockspare-blockspare-container alignfull blockspare-609696d4-6b6b-4" blockspare-animation=""><style>.blockspare-609696d4-6b6b-4 > .blockspare-block-container-wrapper{background-color:#000000;padding-top:100px;padding-right:20px;padding-bottom:100px;padding-left:20px;margin-top:0px;margin-right:0px;margin-bottom:0px;margin-left:0px;border-radius:0}.blockspare-609696d4-6b6b-4 .blockspare-image-wrap{background-image:none}</style><div class="blockspare-block-container-wrapper blockspare-hover-item"><div class="blockspare-container-background blockspare-image-wrap has-background-opacity-100 has-background-opacity"></div><div class="blockspare-container"><div class="blockspare-inner-blocks blockspare-inner-wrapper-blocks"><!-- wp:group {"layout":{"type":"constrained"}} -->
                        <div class="wp-block-group"><!-- wp:blockspare/blockspare-section-header {"uniqueClass":"blockspare-8498a7c0-6d6d-4","sectionAlignment":"center","headerTitle":"WHO WE ARE","titleFontSize":52,"headerSubTitle":"Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. Quis ipsum suspendisse ultrices gravida. ","headertitleColor":"#ffffff","headersubtitleColor":"#c4c4c4","headermarginTop":0,"subtitlePaddingTop":10,"titleFontFamily":"Georgia","titleFontWeight":"800","titleFontSubset":"latin","subTitleFontFamily":"Poppins","subTitleFontWeight":"default","subTitleFontSubset":"latin","subTitleLoadGoogleFonts":true} -->
                        <div class="wp-block-blockspare-blockspare-section-header aligncenter blockspare-8498a7c0-6d6d-4 blockspare-section-header-wrapper blockspare-blocks aligncenter" blockspare-animation=""><style>.blockspare-8498a7c0-6d6d-4 .blockspare-section-head-wrap{background-color:transparent;text-align:center;margin-top:0px;margin-right:0px;margin-bottom:30px;margin-left:0px}.blockspare-8498a7c0-6d6d-4 .blockspare-section-head-wrap .blockspare-title{color:#ffffff;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px;font-size:52px;font-family:Georgia;font-weight:800}.blockspare-8498a7c0-6d6d-4 .blockspare-section-head-wrap .blockspare-subtitle{color:#c4c4c4;font-size:14px;font-family:Poppins;font-weight:default;padding-top:10px;padding-right:0px;padding-bottom:0px;padding-left:0px}@media screen and (max-width:1025px){.blockspare-8498a7c0-6d6d-4 .blockspare-section-head-wrap .blockspare-title{font-size:22px}.blockspare-8498a7c0-6d6d-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}@media screen and (max-width:768px){.blockspare-8498a7c0-6d6d-4 .blockspare-section-head-wrap .blockspare-title{font-size:20px}.blockspare-8498a7c0-6d6d-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}</style><div class="blockspare-section-head-wrap blockspare-style1 blockspare-center blockspare-hover-item"><div class="blockspare-title-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><h2 class="blockspare-title">WHO WE ARE</h2><span class="blockspare-title-dash blockspare-lower-dash"></span></div><div class="blockspare-subtitle-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><p class="blockspare-subtitle">Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. Quis ipsum suspendisse ultrices gravida. </p><span class="blockspare-title-dash blockspare-lower-dash"></span></div></div></div>
                        <!-- /wp:blockspare/blockspare-section-header --></div>
                        <!-- /wp:group -->
                        
                        <!-- wp:cover {"url":"https://blockspare.com/demo/default/fitness/wp-content/uploads/sites/7/2023/12/pexels-andrea-piacquadio-3757934-1.jpg","id":1233,"dimRatio":40,"align":"full","style":{"color":{}},"className":"is-light"} -->
                        <div class="wp-block-cover alignfull is-light"><span aria-hidden="true" class="wp-block-cover__background has-background-dim-40 has-background-dim"></span><img class="wp-block-cover__image-background wp-image-1233" alt="" src="https://blockspare.com/demo/default/fitness/wp-content/uploads/sites/7/2023/12/pexels-andrea-piacquadio-3757934-1.jpg" data-object-fit="cover"/><div class="wp-block-cover__inner-container"><!-- wp:paragraph {"fontSize":"large"} -->
                        <p class="has-large-font-size"></p>
                        <!-- /wp:paragraph -->
                        
                        <!-- wp:blockspare/blockspare-iconset {"uniqueClass":"blockspare-3d75fa38-dc17-4","name":"fas fa-play","iconBackgroundColor":"#ffffff","iconColor":"#e42024","enableIconLink":true,"CustomLink":"https://www.youtube.com/watch?v=NpTNdamPdI4\u0026ab_channel=PexBell"} -->
                        <div class="wp-block-blockspare-blockspare-iconset blockspare-3d75fa38-dc17-4 blockspare-blocks" blockspare-animation=""><style>.blockspare-3d75fa38-dc17-4 .blockspare-block-icon-wrapper{text-align:center;margin-top:30px;margin-right:0px;margin-bottom:30px;margin-left:0px}.blockspare-3d75fa38-dc17-4 .blockspare-block-icon-wrapper .blockspare-block-icon{padding-top:30px;padding-right:30px;padding-bottom:30px;padding-left:30px;color:#e42024;border-radius:50%}.blockspare-3d75fa38-dc17-4 .blockspare-block-icon-wrapper .blockspare-block-icon::after{background-color:#ffffff;opacity:1}.blockspare-3d75fa38-dc17-4 .blockspare-block-icon a{color:#e42024}</style><div class="blockspare-block-icon-wrapper"><div class="blockspare-block-icon blockspare-icon-size-small blockspare-icon-style2 blockspare-hover-item"><a href="https://www.youtube.com/watch?v=NpTNdamPdI4&amp;ab_channel=PexBell"><i class="fas fa-play"></i></a></div></div></div>
                        <!-- /wp:blockspare/blockspare-iconset --></div></div>
                        <!-- /wp:cover -->
                        
                        <!-- wp:blockspare/blockspare-buttons {"uniqueClass":"blockspare-c48cf034-e6b2-4","buttonText":"FREE CONSULTATION","buttonBackgroundColor":"#e42024","buttonTextColor":"#ffffff","buttonHoverEffect":"hover-style-5","buttonFontFamily":"Poppins","buttonFontWeight":"500","buttonFontSubset":"latin","buttonLoadGoogleFonts":true,"buttonIcon":"far fa-user","marginBottom":0} -->
                        <div class="wp-block-blockspare-blockspare-buttons blockspare-c48cf034-e6b2-4 blockspare-block-button-wrap" blockspare-animation=""><style>.blockspare-c48cf034-e6b2-4 .blockspare-block-button{text-align:center;margin-top:30px;margin-bottom:0px;margin-left:0px;margin-right:0px}.blockspare-c48cf034-e6b2-4 .blockspare-block-button span{color:#ffffff;border-width:2px;font-size:16px;font-family:Poppins;font-weight:500}.blockspare-c48cf034-e6b2-4 .blockspare-block-button .blockspare-button{background-color:#e42024}.blockspare-c48cf034-e6b2-4 .blockspare-block-button .blockspare-button:visited{background-color:#e42024}.blockspare-c48cf034-e6b2-4 .blockspare-block-button .blockspare-button:focus{background-color:#e42024}.blockspare-c48cf034-e6b2-4 .blockspare-block-button i{font-size:16px}.blockspare-c48cf034-e6b2-4 .blockspare-block-button a{padding-top:12px;padding-bottom:12px;padding-right:22px;padding-left:22px}@media screen and (max-width:1025px){.blockspare-c48cf034-e6b2-4 .blockspare-block-button span{font-size:14px}.blockspare-c48cf034-e6b2-4 .blockspare-block-button i{font-size:14px}}@media screen and (max-width:768px){.blockspare-c48cf034-e6b2-4 .blockspare-block-button span{font-size:14px}.blockspare-c48cf034-e6b2-4 .blockspare-block-button i{font-size:14px}}</style><div class="blockspare-block-button"><a class="blockspare-button blockspare-button-shape-rounded blockspare-button-size-small hover-style-5"><span>FREE CONSULTATION</span></a></div></div>
                        <!-- /wp:blockspare/blockspare-buttons --></div></div></div></div>
                        <!-- /wp:blockspare/blockspare-container -->',
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'fitness',
                        'item'     => ['Fitness','Services'],
						'key'      => 'bs_section_61',
                        'imagePath'    => 'sections',
						'name'     => 'Fitness Section 4',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/fitness-section-4/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'fitness',
                        'item'     => ['Fitness','Services'],
						'key'      => 'bs_section_62',
                        'imagePath'    => 'sections',
						'name'     => 'Fitness Section 5',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/fitness-section-5/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'fitness',
                        'item'     => ['Fitness','Pricing Table'],
						'key'      => 'bs_section_63',
                        'imagePath'    => 'sections',
						'name'     => 'Fitness Section 6',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/fitness-section-6/",
						'content'  => '<!-- wp:blockspare/blockspare-container {"paddingTop":100,"paddingBottom":100,"marginTop":0,"marginBottom":0,"uniqueClass":"blockspare-881c2bc5-f0d6-4","backGroundColor":"#212121"} -->
                        <div class="wp-block-blockspare-blockspare-container alignfull blockspare-881c2bc5-f0d6-4" blockspare-animation=""><style>.blockspare-881c2bc5-f0d6-4 > .blockspare-block-container-wrapper{background-color:#212121;padding-top:100px;padding-right:20px;padding-bottom:100px;padding-left:20px;margin-top:0px;margin-right:0px;margin-bottom:0px;margin-left:0px;border-radius:0}.blockspare-881c2bc5-f0d6-4 .blockspare-image-wrap{background-image:none}</style><div class="blockspare-block-container-wrapper blockspare-hover-item"><div class="blockspare-container-background blockspare-image-wrap has-background-opacity-100 has-background-opacity"></div><div class="blockspare-container"><div class="blockspare-inner-blocks blockspare-inner-wrapper-blocks"><!-- wp:group {"layout":{"type":"constrained"}} -->
                        <div class="wp-block-group"><!-- wp:blockspare/blockspare-section-header {"uniqueClass":"blockspare-019c93bc-c71a-4","sectionAlignment":"center","headerTitle":"SELECT YOUR PLAN","titleFontSize":52,"headerSubTitle":" Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt ut labore et dolore ","headertitleColor":"#ffffff","headersubtitleColor":"#c4c4c4","headermarginTop":0,"subtitlePaddingTop":10,"titleFontFamily":"Georgia","titleFontWeight":"800","titleFontSubset":"latin","subTitleFontFamily":"Poppins","subTitleFontWeight":"default","subTitleFontSubset":"latin","subTitleLoadGoogleFonts":true} -->
                        <div class="wp-block-blockspare-blockspare-section-header aligncenter blockspare-019c93bc-c71a-4 blockspare-section-header-wrapper blockspare-blocks aligncenter" blockspare-animation=""><style>.blockspare-019c93bc-c71a-4 .blockspare-section-head-wrap{background-color:transparent;text-align:center;margin-top:0px;margin-right:0px;margin-bottom:30px;margin-left:0px}.blockspare-019c93bc-c71a-4 .blockspare-section-head-wrap .blockspare-title{color:#ffffff;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px;font-size:52px;font-family:Georgia;font-weight:800}.blockspare-019c93bc-c71a-4 .blockspare-section-head-wrap .blockspare-subtitle{color:#c4c4c4;font-size:14px;font-family:Poppins;font-weight:default;padding-top:10px;padding-right:0px;padding-bottom:0px;padding-left:0px}@media screen and (max-width:1025px){.blockspare-019c93bc-c71a-4 .blockspare-section-head-wrap .blockspare-title{font-size:22px}.blockspare-019c93bc-c71a-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}@media screen and (max-width:768px){.blockspare-019c93bc-c71a-4 .blockspare-section-head-wrap .blockspare-title{font-size:20px}.blockspare-019c93bc-c71a-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}</style><div class="blockspare-section-head-wrap blockspare-style1 blockspare-center blockspare-hover-item"><div class="blockspare-title-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><h2 class="blockspare-title">SELECT YOUR PLAN</h2><span class="blockspare-title-dash blockspare-lower-dash"></span></div><div class="blockspare-subtitle-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><p class="blockspare-subtitle"> Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt ut labore et dolore </p><span class="blockspare-title-dash blockspare-lower-dash"></span></div></div></div>
                        <!-- /wp:blockspare/blockspare-section-header --></div>
                        <!-- /wp:group -->
                        
                        <!-- wp:group {"align":"wide","layout":{"type":"constrained"}} -->
                        <div class="wp-block-group alignwide"><!-- wp:blockspare/blockspare-services {"uniqueClass":"blockspare-16779690-129c-4"} -->
                        <div class="wp-block-blockspare-blockspare-services alignwide blockspare-16779690-129c-4 blockspare-main-blockwrapper" blockspare-animation=""><style>.blockspare-16779690-129c-4 .blockspare-service-block-wrapper{margin-top:30px;margin-bottom:30px}</style><div class="blockspare-blocks blockspare-service-block-wrapper blockspare-item blockspare-section-header-wrapper"><div class="blockspare-service-columns-3"><div class="blockspare-service-table-wrap  blockspare-block-service-table-gap-1 bs-layout-1"><!-- wp:blockspare/blockspare-services-inner-item {"enableIcon":false,"uniqueClass":"blockspare-e48f3f65-1d76-4","iconName":"fas fa-dumbbell","iconBackgroundColor":"#333333","iconColor":"#e42024","iconmarginTop":40,"headerTitle":"$59","titleFontSize":52,"headerSubTitle":"Standard Package","headertitleColor":"#ffffff","headersubtitleColor":"#ffffff","headerlayoutOption":"blockspare-style3","titleFontFamily":"Georgia","titleFontWeight":"700","titleFontSubset":"latin","subTitleFontFamily":"Poppins","subTitleFontWeight":"400","subTitleFontSubset":"latin","subTitleLoadGoogleFonts":true,"features":"Membership Card\u003cbr\u003eHealth and Fitness Tips\u003cbr\u003ePersonal Health Solution\u003cbr\u003eDiet Plan Included\u003cbr\u003e1 Week Subscription\u003cbr\u003eZumba Classes","textColor":"#c4c4c4","descriptionBacgroundColor":"#00000000","descriptionpaddingTop":30,"descriptionpaddingBottom":30,"descriptionFontFamily":"Poppins","descriptionFontWeight":"400","descriptionFontSubset":"latin","descriptionLoadGoogleFonts":true,"buttonText":"PURCHASE NOW","buttonBackgroundColor":"#e42024","buttonHoverEffect":"hover-style-5","buttonFontFamily":"Poppins","buttonFontWeight":"500","buttonLoadGoogleFonts":true,"buttonPaddingTop":0,"buttonPaddingBottom":40,"itemBorderColor":"#4a4a4a","primaryColor":"#000000","secondaryColor":"#e42024","tertianeryColor":"#000000"} -->
                        <div class="wp-block-blockspare-blockspare-services-inner-item blockspare-e48f3f65-1d76-4 blockspare-blocks blockspare-block-service-table-center blockspare-block-service-table blockspare-icon-size-small layout-item-1 order-1"><style>.blockspare-e48f3f65-1d76-4 .blockspare-block-service-table-inside{border-width:1px;border-style:solid;border-color:#4a4a4a;border-radius:null;background-color:#fff;padding:0px}.blockspare-e48f3f65-1d76-4 .blockspare-block-service-table-inside .blockspare-service-wrap-1:before{background-color:#000000}.blockspare-e48f3f65-1d76-4 .blockspare-block-service-table-inside .blockspare-service-wrap-2:before{background-color:#e42024}.blockspare-e48f3f65-1d76-4 .blockspare-block-service-table-inside .blockspare-service-wrap-3:before{background-color:#000000}</style><div class="blockspare-block-service-table-inside blockspare-hover-item"><div class="blockspare-service-wrap-1"></div><div class="blockspare-service-wrap-2"><div class="wp-block-blockspare-blockspare-services-inner-item blockspare-e48f3f65-1d76-4 blockspare-e48f3f65-1d76-4 blockspare-section-header-wrapper blockspare-blocks aligncenter"><style>.blockspare-e48f3f65-1d76-4 .blockspare-section-head-wrap{background-color:transparent;text-align:center;margin-top:30px;margin-right:0px;margin-bottom:30px;margin-left:0px}.blockspare-e48f3f65-1d76-4 .blockspare-section-head-wrap .blockspare-title{color:#ffffff;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px;font-size:52px;font-family:Georgia;font-weight:700}.blockspare-e48f3f65-1d76-4 .blockspare-section-head-wrap .blockspare-subtitle{color:#ffffff;font-size:14px;font-family:Poppins;font-weight:400;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px}@media screen and (max-width:1025px){.blockspare-e48f3f65-1d76-4 .blockspare-section-head-wrap .blockspare-title{font-size:22px}.blockspare-e48f3f65-1d76-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}@media screen and (max-width:768px){.blockspare-e48f3f65-1d76-4 .blockspare-section-head-wrap .blockspare-title{font-size:20px}.blockspare-e48f3f65-1d76-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}</style><div class="blockspare-section-head-wrap blockspare-style3 blockspare-center"><div class="blockspare-title-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><h2 class="blockspare-title">$59</h2><span class="blockspare-title-dash blockspare-lower-dash"></span></div><div class="blockspare-subtitle-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><p class="blockspare-subtitle">Standard Package</p><span class="blockspare-title-dash blockspare-lower-dash"></span></div></div></div></div><div class="blockspare-service-wrap-3"><div class="wp-block-blockspare-blockspare-services-inner-item blockspare-e48f3f65-1d76-4 blockspare-e48f3f65-1d76-4 blockspare-services-description"><style>.blockspare-e48f3f65-1d76-4 .blockspare-services-inner-description{font-size:16px;font-family:Poppins;font-weight:400;color:#c4c4c4;background-color:#00000000;padding-top:30px;padding-right:10px;padding-bottom:30px;padding-left:10px}@media screen and (max-width:1025px){.blockspare-e48f3f65-1d76-4 .blockspare-services-inner-description{font-size:14px}}@media screen and (max-width:768px){.blockspare-e48f3f65-1d76-4 .blockspare-services-inner-description{font-size:14px}}</style><p itemprop="description" class="blockspare-services-inner-description">Membership Card<br>Health and Fitness Tips<br>Personal Health Solution<br>Diet Plan Included<br>1 Week Subscription<br>Zumba Classes</p></div><div class="wp-block-blockspare-blockspare-services-inner-item blockspare-e48f3f65-1d76-4 blockspare-e48f3f65-1d76-4 blockspare-block-button-wrap"><style>.blockspare-e48f3f65-1d76-4 .blockspare-block-button{text-align:center}.blockspare-e48f3f65-1d76-4 .blockspare-pricing-table-button{padding-top:0px;padding-right:0px;padding-bottom:40px;padding-left:0px}.blockspare-e48f3f65-1d76-4 blocks-button__inline-link{text-align:center}.blockspare-e48f3f65-1d76-4 .blockspare-block-button span{color:#fff;border-width:1px;font-size:16px;font-family:Poppins;font-weight:500}.blockspare-e48f3f65-1d76-4 .wp-block-blockspare-blockspare-services-inner-item .blockspare-block-button .blockspare-button{background-color:#e42024}.blockspare-e48f3f65-1d76-4 .wp-block-blockspare-blockspare-services-inner-item .blockspare-block-button .blockspare-button:visited{background-color:#e42024}.blockspare-e48f3f65-1d76-4 .wp-block-blockspare-blockspare-services-inner-item .blockspare-block-button .blockspare-button:focus{background-color:#e42024}@media screen and (max-width:1025px){.blockspare-e48f3f65-1d76-4 .blockspare-block-button span{font-size:14px}}@media screen and (max-width:768px){.blockspare-e48f3f65-1d76-4 .blockspare-block-button span{font-size:14px}}</style><div class="blockspare-pricing-table-button"><div class="blockspare-block-button"><a class="blockspare-button blockspare-button-shape-rounded blockspare-button-size-small hover-style-5 btn-icon-left"><span>PURCHASE NOW</span></a></div></div></div></div></div></div>
                        <!-- /wp:blockspare/blockspare-services-inner-item -->
                        
                        <!-- wp:blockspare/blockspare-services-inner-item {"enableIcon":false,"uniqueClass":"blockspare-844b4cf4-a4cd-4","iconName":"fas fa-dumbbell","iconBackgroundColor":"#333333","iconColor":"#e42024","iconmarginTop":40,"headerTitle":"$199","titleFontSize":52,"headerSubTitle":"Professional Package","headertitleColor":"#ffffff","headersubtitleColor":"#ffffff","headerlayoutOption":"blockspare-style3","titleFontFamily":"Georgia","titleFontWeight":"700","titleFontSubset":"latin","subTitleFontFamily":"Poppins","subTitleFontWeight":"400","subTitleFontSubset":"latin","subTitleLoadGoogleFonts":true,"features":"Membership Card\u003cbr\u003eHealth and Fitness Tips\u003cbr\u003ePersonal Health Solution\u003cbr\u003eDiet Plan Included\u003cbr\u003e1 Month Subscription\u003cbr\u003eZumba Classes","textColor":"#c4c4c4","descriptionBacgroundColor":"#00000000","descriptionpaddingTop":30,"descriptionpaddingBottom":30,"descriptionFontFamily":"Poppins","descriptionFontWeight":"400","descriptionFontSubset":"latin","descriptionLoadGoogleFonts":true,"buttonText":"PURCHASE NOW","buttonBackgroundColor":"#e42024","buttonHoverEffect":"hover-style-5","buttonFontFamily":"Poppins","buttonFontWeight":"500","buttonLoadGoogleFonts":true,"buttonPaddingTop":0,"buttonPaddingBottom":40,"itemBorderColor":"#4a4a4a","primaryColor":"#262626","secondaryColor":"#e42024","tertianeryColor":"#000000"} -->
                        <div class="wp-block-blockspare-blockspare-services-inner-item blockspare-844b4cf4-a4cd-4 blockspare-blocks blockspare-block-service-table-center blockspare-block-service-table blockspare-icon-size-small layout-item-1 order-1"><style>.blockspare-844b4cf4-a4cd-4 .blockspare-block-service-table-inside{border-width:1px;border-style:solid;border-color:#4a4a4a;border-radius:null;background-color:#fff;padding:0px}.blockspare-844b4cf4-a4cd-4 .blockspare-block-service-table-inside .blockspare-service-wrap-1:before{background-color:#262626}.blockspare-844b4cf4-a4cd-4 .blockspare-block-service-table-inside .blockspare-service-wrap-2:before{background-color:#e42024}.blockspare-844b4cf4-a4cd-4 .blockspare-block-service-table-inside .blockspare-service-wrap-3:before{background-color:#000000}</style><div class="blockspare-block-service-table-inside blockspare-hover-item"><div class="blockspare-service-wrap-1"></div><div class="blockspare-service-wrap-2"><div class="wp-block-blockspare-blockspare-services-inner-item blockspare-844b4cf4-a4cd-4 blockspare-844b4cf4-a4cd-4 blockspare-section-header-wrapper blockspare-blocks aligncenter"><style>.blockspare-844b4cf4-a4cd-4 .blockspare-section-head-wrap{background-color:transparent;text-align:center;margin-top:30px;margin-right:0px;margin-bottom:30px;margin-left:0px}.blockspare-844b4cf4-a4cd-4 .blockspare-section-head-wrap .blockspare-title{color:#ffffff;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px;font-size:52px;font-family:Georgia;font-weight:700}.blockspare-844b4cf4-a4cd-4 .blockspare-section-head-wrap .blockspare-subtitle{color:#ffffff;font-size:14px;font-family:Poppins;font-weight:400;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px}@media screen and (max-width:1025px){.blockspare-844b4cf4-a4cd-4 .blockspare-section-head-wrap .blockspare-title{font-size:22px}.blockspare-844b4cf4-a4cd-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}@media screen and (max-width:768px){.blockspare-844b4cf4-a4cd-4 .blockspare-section-head-wrap .blockspare-title{font-size:20px}.blockspare-844b4cf4-a4cd-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}</style><div class="blockspare-section-head-wrap blockspare-style3 blockspare-center"><div class="blockspare-title-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><h2 class="blockspare-title">$199</h2><span class="blockspare-title-dash blockspare-lower-dash"></span></div><div class="blockspare-subtitle-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><p class="blockspare-subtitle">Professional Package</p><span class="blockspare-title-dash blockspare-lower-dash"></span></div></div></div></div><div class="blockspare-service-wrap-3"><div class="wp-block-blockspare-blockspare-services-inner-item blockspare-844b4cf4-a4cd-4 blockspare-844b4cf4-a4cd-4 blockspare-services-description"><style>.blockspare-844b4cf4-a4cd-4 .blockspare-services-inner-description{font-size:16px;font-family:Poppins;font-weight:400;color:#c4c4c4;background-color:#00000000;padding-top:30px;padding-right:10px;padding-bottom:30px;padding-left:10px}@media screen and (max-width:1025px){.blockspare-844b4cf4-a4cd-4 .blockspare-services-inner-description{font-size:14px}}@media screen and (max-width:768px){.blockspare-844b4cf4-a4cd-4 .blockspare-services-inner-description{font-size:14px}}</style><p itemprop="description" class="blockspare-services-inner-description">Membership Card<br>Health and Fitness Tips<br>Personal Health Solution<br>Diet Plan Included<br>1 Month Subscription<br>Zumba Classes</p></div><div class="wp-block-blockspare-blockspare-services-inner-item blockspare-844b4cf4-a4cd-4 blockspare-844b4cf4-a4cd-4 blockspare-block-button-wrap"><style>.blockspare-844b4cf4-a4cd-4 .blockspare-block-button{text-align:center}.blockspare-844b4cf4-a4cd-4 .blockspare-pricing-table-button{padding-top:0px;padding-right:0px;padding-bottom:40px;padding-left:0px}.blockspare-844b4cf4-a4cd-4 blocks-button__inline-link{text-align:center}.blockspare-844b4cf4-a4cd-4 .blockspare-block-button span{color:#fff;border-width:1px;font-size:16px;font-family:Poppins;font-weight:500}.blockspare-844b4cf4-a4cd-4 .wp-block-blockspare-blockspare-services-inner-item .blockspare-block-button .blockspare-button{background-color:#e42024}.blockspare-844b4cf4-a4cd-4 .wp-block-blockspare-blockspare-services-inner-item .blockspare-block-button .blockspare-button:visited{background-color:#e42024}.blockspare-844b4cf4-a4cd-4 .wp-block-blockspare-blockspare-services-inner-item .blockspare-block-button .blockspare-button:focus{background-color:#e42024}@media screen and (max-width:1025px){.blockspare-844b4cf4-a4cd-4 .blockspare-block-button span{font-size:14px}}@media screen and (max-width:768px){.blockspare-844b4cf4-a4cd-4 .blockspare-block-button span{font-size:14px}}</style><div class="blockspare-pricing-table-button"><div class="blockspare-block-button"><a class="blockspare-button blockspare-button-shape-rounded blockspare-button-size-small hover-style-5 btn-icon-left"><span>PURCHASE NOW</span></a></div></div></div></div></div></div>
                        <!-- /wp:blockspare/blockspare-services-inner-item -->
                        
                        <!-- wp:blockspare/blockspare-services-inner-item {"enableIcon":false,"uniqueClass":"blockspare-62fd7583-2d3a-4","iconName":"fas fa-dumbbell","iconBackgroundColor":"#333333","iconColor":"#e42024","iconmarginTop":40,"headerTitle":"$499","titleFontSize":52,"headerSubTitle":"Ultimate Package","headertitleColor":"#ffffff","headersubtitleColor":"#ffffff","headerlayoutOption":"blockspare-style3","titleFontFamily":"Georgia","titleFontWeight":"700","titleFontSubset":"latin","subTitleFontFamily":"Poppins","subTitleFontWeight":"400","subTitleFontSubset":"latin","subTitleLoadGoogleFonts":true,"features":"Membership Card\u003cbr\u003eHealth and Fitness Tips\u003cbr\u003ePersonal Health Solution\u003cbr\u003eDiet Plan Included\u003cbr\u003e3 Months Subscription\u003cbr\u003eZumba Classes","textColor":"#c4c4c4","descriptionBacgroundColor":"#00000000","descriptionpaddingTop":30,"descriptionpaddingBottom":30,"descriptionFontFamily":"Poppins","descriptionFontWeight":"400","descriptionFontSubset":"latin","descriptionLoadGoogleFonts":true,"buttonText":"PURCHASE NOW","buttonBackgroundColor":"#e42024","buttonHoverEffect":"hover-style-5","buttonFontFamily":"Poppins","buttonFontWeight":"500","buttonLoadGoogleFonts":true,"buttonPaddingTop":0,"buttonPaddingBottom":40,"itemBorderColor":"#4a4a4a","primaryColor":"#262626","secondaryColor":"#e42024","tertianeryColor":"#000000"} -->
                        <div class="wp-block-blockspare-blockspare-services-inner-item blockspare-62fd7583-2d3a-4 blockspare-blocks blockspare-block-service-table-center blockspare-block-service-table blockspare-icon-size-small layout-item-1 order-1"><style>.blockspare-62fd7583-2d3a-4 .blockspare-block-service-table-inside{border-width:1px;border-style:solid;border-color:#4a4a4a;border-radius:null;background-color:#fff;padding:0px}.blockspare-62fd7583-2d3a-4 .blockspare-block-service-table-inside .blockspare-service-wrap-1:before{background-color:#262626}.blockspare-62fd7583-2d3a-4 .blockspare-block-service-table-inside .blockspare-service-wrap-2:before{background-color:#e42024}.blockspare-62fd7583-2d3a-4 .blockspare-block-service-table-inside .blockspare-service-wrap-3:before{background-color:#000000}</style><div class="blockspare-block-service-table-inside blockspare-hover-item"><div class="blockspare-service-wrap-1"></div><div class="blockspare-service-wrap-2"><div class="wp-block-blockspare-blockspare-services-inner-item blockspare-62fd7583-2d3a-4 blockspare-62fd7583-2d3a-4 blockspare-section-header-wrapper blockspare-blocks aligncenter"><style>.blockspare-62fd7583-2d3a-4 .blockspare-section-head-wrap{background-color:transparent;text-align:center;margin-top:30px;margin-right:0px;margin-bottom:30px;margin-left:0px}.blockspare-62fd7583-2d3a-4 .blockspare-section-head-wrap .blockspare-title{color:#ffffff;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px;font-size:52px;font-family:Georgia;font-weight:700}.blockspare-62fd7583-2d3a-4 .blockspare-section-head-wrap .blockspare-subtitle{color:#ffffff;font-size:14px;font-family:Poppins;font-weight:400;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px}@media screen and (max-width:1025px){.blockspare-62fd7583-2d3a-4 .blockspare-section-head-wrap .blockspare-title{font-size:22px}.blockspare-62fd7583-2d3a-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}@media screen and (max-width:768px){.blockspare-62fd7583-2d3a-4 .blockspare-section-head-wrap .blockspare-title{font-size:20px}.blockspare-62fd7583-2d3a-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}</style><div class="blockspare-section-head-wrap blockspare-style3 blockspare-center"><div class="blockspare-title-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><h2 class="blockspare-title">$499</h2><span class="blockspare-title-dash blockspare-lower-dash"></span></div><div class="blockspare-subtitle-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><p class="blockspare-subtitle">Ultimate Package</p><span class="blockspare-title-dash blockspare-lower-dash"></span></div></div></div></div><div class="blockspare-service-wrap-3"><div class="wp-block-blockspare-blockspare-services-inner-item blockspare-62fd7583-2d3a-4 blockspare-62fd7583-2d3a-4 blockspare-services-description"><style>.blockspare-62fd7583-2d3a-4 .blockspare-services-inner-description{font-size:16px;font-family:Poppins;font-weight:400;color:#c4c4c4;background-color:#00000000;padding-top:30px;padding-right:10px;padding-bottom:30px;padding-left:10px}@media screen and (max-width:1025px){.blockspare-62fd7583-2d3a-4 .blockspare-services-inner-description{font-size:14px}}@media screen and (max-width:768px){.blockspare-62fd7583-2d3a-4 .blockspare-services-inner-description{font-size:14px}}</style><p itemprop="description" class="blockspare-services-inner-description">Membership Card<br>Health and Fitness Tips<br>Personal Health Solution<br>Diet Plan Included<br>3 Months Subscription<br>Zumba Classes</p></div><div class="wp-block-blockspare-blockspare-services-inner-item blockspare-62fd7583-2d3a-4 blockspare-62fd7583-2d3a-4 blockspare-block-button-wrap"><style>.blockspare-62fd7583-2d3a-4 .blockspare-block-button{text-align:center}.blockspare-62fd7583-2d3a-4 .blockspare-pricing-table-button{padding-top:0px;padding-right:0px;padding-bottom:40px;padding-left:0px}.blockspare-62fd7583-2d3a-4 blocks-button__inline-link{text-align:center}.blockspare-62fd7583-2d3a-4 .blockspare-block-button span{color:#fff;border-width:1px;font-size:16px;font-family:Poppins;font-weight:500}.blockspare-62fd7583-2d3a-4 .wp-block-blockspare-blockspare-services-inner-item .blockspare-block-button .blockspare-button{background-color:#e42024}.blockspare-62fd7583-2d3a-4 .wp-block-blockspare-blockspare-services-inner-item .blockspare-block-button .blockspare-button:visited{background-color:#e42024}.blockspare-62fd7583-2d3a-4 .wp-block-blockspare-blockspare-services-inner-item .blockspare-block-button .blockspare-button:focus{background-color:#e42024}@media screen and (max-width:1025px){.blockspare-62fd7583-2d3a-4 .blockspare-block-button span{font-size:14px}}@media screen and (max-width:768px){.blockspare-62fd7583-2d3a-4 .blockspare-block-button span{font-size:14px}}</style><div class="blockspare-pricing-table-button"><div class="blockspare-block-button"><a class="blockspare-button blockspare-button-shape-rounded blockspare-button-size-small hover-style-5 btn-icon-left"><span>PURCHASE NOW</span></a></div></div></div></div></div></div>
                        <!-- /wp:blockspare/blockspare-services-inner-item --></div></div></div></div>
                        <!-- /wp:blockspare/blockspare-services --></div>
                        <!-- /wp:group --></div></div></div></div>
                        <!-- /wp:blockspare/blockspare-container -->',
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'fitness',
                        'item'     => ['Fitness','User Profile'],
						'key'      => 'bs_section_64',
                        'imagePath'    => 'sections',
						'name'     => 'Fitness Section 7',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/fitness-section-7/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'fitness',
                        'item'     => ['Fitness','Testimonial'],
						'key'      => 'bs_section_65',
                        'imagePath'    => 'sections',
						'name'     => 'Fitness Section 8',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/fitness-section-8/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'fitness',
                        'item'     => ['Fitness','Content Box'],
						'key'      => 'bs_section_66',
                        'imagePath'    => 'sections',
						'name'     => 'Fitness Section 9',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/fitness-section-9/",
						'content'  => '<!-- wp:blockspare/blockspare-container {"paddingTop":40,"paddingBottom":40,"marginTop":0,"marginBottom":0,"uniqueClass":"blockspare-50896b10-95c8-4","backGroundColor":"#e42024"} -->
                        <div class="wp-block-blockspare-blockspare-container alignfull blockspare-50896b10-95c8-4" blockspare-animation=""><style>.blockspare-50896b10-95c8-4 > .blockspare-block-container-wrapper{background-color:#e42024;padding-top:40px;padding-right:20px;padding-bottom:40px;padding-left:20px;margin-top:0px;margin-right:0px;margin-bottom:0px;margin-left:0px;border-radius:0}.blockspare-50896b10-95c8-4 .blockspare-image-wrap{background-image:none}</style><div class="blockspare-block-container-wrapper blockspare-hover-item"><div class="blockspare-container-background blockspare-image-wrap has-background-opacity-100 has-background-opacity"></div><div class="blockspare-container"><div class="blockspare-inner-blocks blockspare-inner-wrapper-blocks"><!-- wp:group {"align":"wide","layout":{"type":"constrained"}} -->
                        <div class="wp-block-group alignwide"><!-- wp:columns {"verticalAlignment":"center","align":"wide"} -->
                        <div class="wp-block-columns alignwide are-vertically-aligned-center"><!-- wp:column {"verticalAlignment":"center","width":"50%"} -->
                        <div class="wp-block-column is-vertically-aligned-center" style="flex-basis:50%"><!-- wp:blockspare/blockspare-section-header {"uniqueClass":"blockspare-350b0a82-db0c-4","headerTitle":"STAY INFORMED \u0026amp; GET FIT","headerSubTitle":"Lorem ipsum Ut elit tellus, luctus nec pulvinar dapibus leo. ","headertitleColor":"#ffffff","headersubtitleColor":"#ffffff","subtitlePaddingTop":10,"titleFontFamily":"Georgia","titleFontWeight":"800","titleFontSubset":"latin","subTitleFontFamily":"Poppins","subTitleFontWeight":"default","subTitleFontSubset":"latin","subTitleLoadGoogleFonts":true} -->
                        <div class="wp-block-blockspare-blockspare-section-header aligncenter blockspare-350b0a82-db0c-4 blockspare-section-header-wrapper blockspare-blocks aligncenter" blockspare-animation=""><style>.blockspare-350b0a82-db0c-4 .blockspare-section-head-wrap{background-color:transparent;text-align:left;margin-top:30px;margin-right:0px;margin-bottom:30px;margin-left:0px}.blockspare-350b0a82-db0c-4 .blockspare-section-head-wrap .blockspare-title{color:#ffffff;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px;font-size:24px;font-family:Georgia;font-weight:800}.blockspare-350b0a82-db0c-4 .blockspare-section-head-wrap .blockspare-subtitle{color:#ffffff;font-size:14px;font-family:Poppins;font-weight:default;padding-top:10px;padding-right:0px;padding-bottom:0px;padding-left:0px}@media screen and (max-width:1025px){.blockspare-350b0a82-db0c-4 .blockspare-section-head-wrap .blockspare-title{font-size:22px}.blockspare-350b0a82-db0c-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}@media screen and (max-width:768px){.blockspare-350b0a82-db0c-4 .blockspare-section-head-wrap .blockspare-title{font-size:20px}.blockspare-350b0a82-db0c-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}</style><div class="blockspare-section-head-wrap blockspare-style1 blockspare-left blockspare-hover-item"><div class="blockspare-title-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><h2 class="blockspare-title">STAY INFORMED &amp; GET FIT</h2><span class="blockspare-title-dash blockspare-lower-dash"></span></div><div class="blockspare-subtitle-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><p class="blockspare-subtitle">Lorem ipsum Ut elit tellus, luctus nec pulvinar dapibus leo. </p><span class="blockspare-title-dash blockspare-lower-dash"></span></div></div></div>
                        <!-- /wp:blockspare/blockspare-section-header --></div>
                        <!-- /wp:column -->
                        
                        <!-- wp:column {"verticalAlignment":"center","width":"50%"} -->
                        <div class="wp-block-column is-vertically-aligned-center" style="flex-basis:50%"><!-- wp:blockspare/blockspare-buttons {"sectionAlignment":"right","uniqueClass":"blockspare-6d100a21-154d-4","buttonText":"GET STARTED","buttonBackgroundColor":"#ffffff","buttonTextColor":"#e42024","buttonHoverEffect":"hover-style-5","buttonFontFamily":"Poppins","buttonFontWeight":"500","buttonFontSubset":"latin","buttonLoadGoogleFonts":true} -->
                        <div class="wp-block-blockspare-blockspare-buttons blockspare-6d100a21-154d-4 blockspare-block-button-wrap" blockspare-animation=""><style>.blockspare-6d100a21-154d-4 .blockspare-block-button{text-align:right;margin-top:30px;margin-bottom:30px;margin-left:0px;margin-right:0px}.blockspare-6d100a21-154d-4 .blockspare-block-button span{color:#e42024;border-width:2px;font-size:16px;font-family:Poppins;font-weight:500}.blockspare-6d100a21-154d-4 .blockspare-block-button .blockspare-button{background-color:#ffffff}.blockspare-6d100a21-154d-4 .blockspare-block-button .blockspare-button:visited{background-color:#ffffff}.blockspare-6d100a21-154d-4 .blockspare-block-button .blockspare-button:focus{background-color:#ffffff}.blockspare-6d100a21-154d-4 .blockspare-block-button i{font-size:16px}.blockspare-6d100a21-154d-4 .blockspare-block-button a{padding-top:12px;padding-bottom:12px;padding-right:22px;padding-left:22px}@media screen and (max-width:1025px){.blockspare-6d100a21-154d-4 .blockspare-block-button span{font-size:14px}.blockspare-6d100a21-154d-4 .blockspare-block-button i{font-size:14px}}@media screen and (max-width:768px){.blockspare-6d100a21-154d-4 .blockspare-block-button span{font-size:14px}.blockspare-6d100a21-154d-4 .blockspare-block-button i{font-size:14px}}</style><div class="blockspare-block-button"><a class="blockspare-button blockspare-button-shape-rounded blockspare-button-size-small hover-style-5"><span>GET STARTED</span></a></div></div>
                        <!-- /wp:blockspare/blockspare-buttons --></div>
                        <!-- /wp:column --></div>
                        <!-- /wp:columns --></div>
                        <!-- /wp:group --></div></div></div></div>
                        <!-- /wp:blockspare/blockspare-container -->',
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'fitness',
                        'item'     => ['Fitness','Post Grid'],
						'key'      => 'bs_section_67',
                        'imagePath'    => 'sections',
						'name'     => 'Fitness Section 10',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/fitness-section-10/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'fitness',
                        'item'     => ['Fitness','Logo Grid'],
						'key'      => 'bs_section_68',
                        'imagePath'    => 'sections',
						'name'     => 'Fitness Section 11',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/fitness-section-11/",
						'content'  => '<!-- wp:blockspare/blockspare-container {"paddingTop":60,"paddingBottom":60,"marginTop":0,"marginBottom":0,"uniqueClass":"blockspare-e49057e3-202f-4","backGroundColor":"#212121"} -->
                        <div class="wp-block-blockspare-blockspare-container alignfull blockspare-e49057e3-202f-4" blockspare-animation=""><style>.blockspare-e49057e3-202f-4 > .blockspare-block-container-wrapper{background-color:#212121;padding-top:60px;padding-right:20px;padding-bottom:60px;padding-left:20px;margin-top:0px;margin-right:0px;margin-bottom:0px;margin-left:0px;border-radius:0}.blockspare-e49057e3-202f-4 .blockspare-image-wrap{background-image:none}</style><div class="blockspare-block-container-wrapper blockspare-hover-item"><div class="blockspare-container-background blockspare-image-wrap has-background-opacity-100 has-background-opacity"></div><div class="blockspare-container"><div class="blockspare-inner-blocks blockspare-inner-wrapper-blocks"><!-- wp:group {"align":"wide","layout":{"type":"constrained"}} -->
                        <div class="wp-block-group alignwide"><!-- wp:blockspare/blockspare-logos {"uniqueClass":"blockspare-93885731-0062-4","align":"wide","images":[{"alt":"","id":1086,"link":"https://blockspare.com/demo/default/fitness/home/white-logoipsum-234/","caption":"","sizes":{"full":{"url":"https://blockspare.com/demo/default/fitness/wp-content/uploads/sites/7/2023/06/white-logoipsum-234.png","height":46,"width":93,"orientation":"landscape"}},"url":"https://blockspare.com/demo/default/fitness/wp-content/uploads/sites/7/2023/06/white-logoipsum-234.png","imgLink":""},{"alt":"","id":1087,"link":"https://blockspare.com/demo/default/fitness/home/white-logoipsum-221/","caption":"","sizes":{"full":{"url":"https://blockspare.com/demo/default/fitness/wp-content/uploads/sites/7/2023/06/white-logoipsum-221.png","height":26,"width":115,"orientation":"landscape"}},"url":"https://blockspare.com/demo/default/fitness/wp-content/uploads/sites/7/2023/06/white-logoipsum-221.png","imgLink":""},{"alt":"","id":1089,"link":"https://blockspare.com/demo/default/fitness/home/white-logoipsum-287/","caption":"","sizes":{"full":{"url":"https://blockspare.com/demo/default/fitness/wp-content/uploads/sites/7/2023/06/white-logoipsum-287.png","height":40,"width":105,"orientation":"landscape"}},"url":"https://blockspare.com/demo/default/fitness/wp-content/uploads/sites/7/2023/06/white-logoipsum-287.png","imgLink":""},{"alt":"","id":1090,"link":"https://blockspare.com/demo/default/fitness/home/white-logoipsum-232/","caption":"","sizes":{"full":{"url":"https://blockspare.com/demo/default/fitness/wp-content/uploads/sites/7/2023/06/white-logoipsum-232.png","height":47,"width":98,"orientation":"landscape"}},"url":"https://blockspare.com/demo/default/fitness/wp-content/uploads/sites/7/2023/06/white-logoipsum-232.png","imgLink":""},{"alt":"","id":1091,"link":"https://blockspare.com/demo/default/fitness/home/whitelogoipsum-219/","caption":"","sizes":{"full":{"url":"https://blockspare.com/demo/default/fitness/wp-content/uploads/sites/7/2023/06/whitelogoipsum-219.png","height":15,"width":150,"orientation":"landscape"}},"url":"https://blockspare.com/demo/default/fitness/wp-content/uploads/sites/7/2023/06/whitelogoipsum-219.png","imgLink":""},{"alt":"","id":1092,"link":"https://blockspare.com/demo/default/fitness/home/white-logoipsum-214/","caption":"","sizes":{"full":{"url":"https://blockspare.com/demo/default/fitness/wp-content/uploads/sites/7/2023/06/white-logoipsum-214.png","height":46,"width":96,"orientation":"landscape"}},"url":"https://blockspare.com/demo/default/fitness/wp-content/uploads/sites/7/2023/06/white-logoipsum-214.png","imgLink":""}],"columns":6,"gutter":100,"className":"alignwide"} -->
                        <div class="blockspare-blocks blockspare-logos-wrapper has-gap-100 has-colums-6 wp-block-blockspare-blockspare-logos alignwide blockspare-93885731-0062-4" blockspare-animation=""><style>.blockspare-93885731-0062-4 .blockspare-logo-grid-main{padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px;margin-top:30px;margin-right:0px;margin-bottom:30px;margin-left:0px}</style><div class="blockspare-logo-grid-main"><ul class="blockspare-logo-wrap"><li class="blockspare-gallery-item"><figure class="blockspare-gallery-figure blockspare-hover-item"><img src="https://blockspare.com/demo/default/fitness/wp-content/uploads/sites/7/2023/06/white-logoipsum-234.png" alt="" data-id="1086" data-imglink="" data-link="https://blockspare.com/demo/default/fitness/home/white-logoipsum-234/" class="wp-image-1086"/></figure></li><li class="blockspare-gallery-item"><figure class="blockspare-gallery-figure blockspare-hover-item"><img src="https://blockspare.com/demo/default/fitness/wp-content/uploads/sites/7/2023/06/white-logoipsum-221.png" alt="" data-id="1087" data-imglink="" data-link="https://blockspare.com/demo/default/fitness/home/white-logoipsum-221/" class="wp-image-1087"/></figure></li><li class="blockspare-gallery-item"><figure class="blockspare-gallery-figure blockspare-hover-item"><img src="https://blockspare.com/demo/default/fitness/wp-content/uploads/sites/7/2023/06/white-logoipsum-287.png" alt="" data-id="1089" data-imglink="" data-link="https://blockspare.com/demo/default/fitness/home/white-logoipsum-287/" class="wp-image-1089"/></figure></li><li class="blockspare-gallery-item"><figure class="blockspare-gallery-figure blockspare-hover-item"><img src="https://blockspare.com/demo/default/fitness/wp-content/uploads/sites/7/2023/06/white-logoipsum-232.png" alt="" data-id="1090" data-imglink="" data-link="https://blockspare.com/demo/default/fitness/home/white-logoipsum-232/" class="wp-image-1090"/></figure></li><li class="blockspare-gallery-item"><figure class="blockspare-gallery-figure blockspare-hover-item"><img src="https://blockspare.com/demo/default/fitness/wp-content/uploads/sites/7/2023/06/whitelogoipsum-219.png" alt="" data-id="1091" data-imglink="" data-link="https://blockspare.com/demo/default/fitness/home/whitelogoipsum-219/" class="wp-image-1091"/></figure></li><li class="blockspare-gallery-item"><figure class="blockspare-gallery-figure blockspare-hover-item"><img src="https://blockspare.com/demo/default/fitness/wp-content/uploads/sites/7/2023/06/white-logoipsum-214.png" alt="" data-id="1092" data-imglink="" data-link="https://blockspare.com/demo/default/fitness/home/white-logoipsum-214/" class="wp-image-1092"/></figure></li></ul></div></div>
                        <!-- /wp:blockspare/blockspare-logos --></div>
                        <!-- /wp:group --></div></div></div></div>
                        <!-- /wp:blockspare/blockspare-container -->',
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'fitness',
                        'item'     => ['Fitness','Content Box'],
						'key'      => 'bs_section_69',
                        'imagePath'    => 'sections',
						'name'     => 'Fitness Section 12',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/fitness-section-12/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'fitness',
                        'item'     => ['Fitness','Services'],
						'key'      => 'bs_section_70',
                        'imagePath'    => 'sections',
						'name'     => 'Fitness Section 13',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/fitness-section-13/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'real-estate',
                        'item'     => ['Real Estate','Content Box'],
						'key'      => 'bs_section_71',
                        'imagePath'    => 'sections',
						'name'     => 'Real Estate Section 1',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/real-estate-section-1/",
						'content'  => '<!-- wp:blockspare/blockspare-container {"paddingTop":50,"paddingBottom":50,"marginTop":0,"marginBottom":0,"uniqueClass":"blockspare-2608d1a9-1775-4"} -->
                        <div class="wp-block-blockspare-blockspare-container alignfull blockspare-2608d1a9-1775-4" blockspare-animation=""><style>.blockspare-2608d1a9-1775-4 > .blockspare-block-container-wrapper{background-color:#f9f9f9;padding-top:50px;padding-right:20px;padding-bottom:50px;padding-left:20px;margin-top:0px;margin-right:0px;margin-bottom:0px;margin-left:0px;border-radius:0}.blockspare-2608d1a9-1775-4 .blockspare-image-wrap{background-image:none}</style><div class="blockspare-block-container-wrapper blockspare-hover-item"><div class="blockspare-container-background blockspare-image-wrap has-background-opacity-100 has-background-opacity"></div><div class="blockspare-container"><div class="blockspare-inner-blocks blockspare-inner-wrapper-blocks"><!-- wp:group {"align":"wide","layout":{"type":"constrained"}} -->
                        <div class="wp-block-group alignwide"><!-- wp:blockspare/content-box {"uniqueClass":"blockspare-1229a2e1-8d24-4","align":"wide","sectionAlignment":"left","headerTitle":"Search and Find \u003cbr\u003eLuxury House","titleFontSize":48,"headerSubTitle":"Real Estate Agency","headertitleColor":"#384260","headersubtitleColor":"#fcb332","headerlayoutOption":"blockspare-style3","headerTagOption":"h1","titlePaddingBottom":30,"titleFontFamily":"Helvetica","titleFontWeight":"800","subTitleFontFamily":"Helvetica","sectionDescription":"Over 39,000 people work for us in more than 70 countries all over the This breadth of global coverage, combined with specialist services\u003cbr\u003e\u003cbr\u003e","imgURL":"https://blockspare.com/demo/default/real-estate/wp-content/uploads/sites/8/2023/12/pexels-the-lazy-artist-gallery-1642125.jpg","imgID":2163,"showButton":true,"backGroundColor":"#00000000","descriptionColor":"#989eab","layoutOption":true,"design":"style-2","descriptionFontSize":18,"descriptionFontFamily":"Helvetica","buttonBackgroundColor":"#fcb332","buttonTextColor":"#384260","buttonFontFamily":"Helvetica","buttonFontWeight":"600"} -->
                        <div class="wp-block-blockspare-content-box blockspare-1229a2e1-8d24-4 blockspare-contentBox alignwide" blockspare-animation=""><style>.blockspare-1229a2e1-8d24-4 .blockspare-contentBox .blockspare-section-wrapper{background-color:#00000000}.blockspare-1229a2e1-8d24-4 .blockspare-content-wrapper{text-align:left;margin-top:30px;margin-right:0px;margin-bottom:30px;margin-left:0px}.blockspare-1229a2e1-8d24-4 .blockspare-content-wrapper .blockspare-section-wrapper{padding-top:30px;padding-right:30px;padding-bottom:30px;padding-left:30px}.blockspare-1229a2e1-8d24-4 .blockspare-desc-btn-wrap .blockspare-description{color:#989eab;font-size:18px;font-family:Helvetica}.blockspare-1229a2e1-8d24-4 .blockspare-mainheader-wrap{box-shadow:none;border-radius:0;background-color:#00000000}.blockspare-1229a2e1-8d24-4 .blockspare-section-header-wrapper{border-color:#8b249c}.blockspare-1229a2e1-8d24-4 .blockspare-content-wrapper .blockspare-desc-btn-wrap .blockspare-description{margin-top:10px;margin-right:0px;margin-bottom:0px;margin-left:0px}.blockspare-1229a2e1-8d24-4 .blockspare-block-button a.blockspare-button{margin-top:10px;margin-right:0px;margin-bottom:0px;margin-left:0px;padding-top:12px;padding-right:22px;padding-bottom:12px;padding-left:22px;color:#384260;border-width:2px;font-size:16px;font-family:Helvetica;font-weight:600}.blockspare-1229a2e1-8d24-4 .blockspare-section-head-wrap{background-color:transparent;text-align:left;margin-top:0px;margin-right:0px;margin-bottom:0px;margin-left:0px}.blockspare-1229a2e1-8d24-4.wp-block-blockspare-content-box .blockspare-block-button .blockspare-button{background-color:#fcb332}.blockspare-1229a2e1-8d24-4.wp-block-blockspare-content-box .blockspare-block-button .blockspare-button:visited{background-color:#fcb332}.blockspare-1229a2e1-8d24-4.wp-block-blockspare-content-box .blockspare-block-button .blockspare-button:focus{background-color:#fcb332}.blockspare-1229a2e1-8d24-4 .blockspare-section-head-wrap .blockspare-title{color:#384260;padding-top:0px;padding-right:0px;padding-bottom:30px;padding-left:0px;font-size:48px;font-family:Helvetica;font-weight:800}.blockspare-1229a2e1-8d24-4 .blockspare-section-head-wrap .blockspare-subtitle{color:#fcb332;font-size:14px;font-family:Helvetica;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px}@media screen and (max-width:1025px){.blockspare-1229a2e1-8d24-4 .blockspare-block-button a.blockspare-button{font-size:14px}.blockspare-1229a2e1-8d24-4 .blockspare-section-head-wrap .blockspare-title{font-size:22px}.blockspare-1229a2e1-8d24-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}.blockspare-1229a2e1-8d24-4 .blockspare-desc-btn-wrap .blockspare-description{font-size:16px}}@media screen and (max-width:768px){.blockspare-1229a2e1-8d24-4 .blockspare-block-button a.blockspare-button{font-size:14px}.blockspare-1229a2e1-8d24-4 .blockspare-section-head-wrap .blockspare-title{font-size:20px}.blockspare-1229a2e1-8d24-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}.blockspare-1229a2e1-8d24-4 .blockspare-desc-btn-wrap .blockspare-description{font-size:14px}}</style><div class="blockspare-content-wrapper blockspare-blocks blockspare-hover-item blockspare-hover-child reverse-img has-background style-2"><div class="content-img-wrap"><img src="https://blockspare.com/demo/default/real-estate/wp-content/uploads/sites/8/2023/12/pexels-the-lazy-artist-gallery-1642125.jpg" alt="" class=" hover-child"/></div><div class="blockspare-section-wrapper blockspare-mainheader-wrap hover-child"><div class="blockspare-section-head-wrap"><div class="blockspare-title-subtitle-wrap"><div class="blockspare-section-header-wrapper blockspare-blocks"><div class="blockspare-section-head-wrap blockspare-style3 blockspare-left"><div class="blockspare-title-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><h1 class="blockspare-title">Search and Find <br>Luxury House</h1><span class="blockspare-title-dash blockspare-lower-dash"></span></div><div class="blockspare-subtitle-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><p class="blockspare-subtitle">Real Estate Agency</p><span class="blockspare-title-dash blockspare-lower-dash"></span></div></div></div></div></div><div class="blockspare-desc-btn-wrap"><p class="blockspare-description">Over 39,000 people work for us in more than 70 countries all over the This breadth of global coverage, combined with specialist services<br><br></p><div class="blockspare-block-button"><a class="blockspare-button blockspare-button-shape-rounded blockspare-button-size-small"><span>Get Started</span></a></div></div></div></div></div>
                        <!-- /wp:blockspare/content-box --></div>
                        <!-- /wp:group --></div></div></div></div>
                        <!-- /wp:blockspare/blockspare-container -->',
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'real-estate',
                        'item'     => ['Real Estate','Services'],
						'key'      => 'bs_section_72',
                        'imagePath'    => 'sections',
						'name'     => 'Real Estate Section 2',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/real-estate-section-2/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'real-estate',
                        'item'     => ['Real Estate','Services'],
						'key'      => 'bs_section_73',
                        'imagePath'    => 'sections',
						'name'     => 'Real Estate Section 3',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/real-estate-section-3/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'real-estate',
                        'item'     => ['Real Estate','Content Box'],
						'key'      => 'bs_section_74',
                        'imagePath'    => 'sections',
						'name'     => 'Real Estate Section 4',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/real-estate-section-4/",
						'content'  => '<!-- wp:blockspare/blockspare-container {"marginTop":0,"marginBottom":0,"uniqueClass":"blockspare-e7382741-6ea0-4","backGroundColor":"#fcb332"} -->
                        <div class="wp-block-blockspare-blockspare-container alignfull blockspare-e7382741-6ea0-4" blockspare-animation=""><style>.blockspare-e7382741-6ea0-4 > .blockspare-block-container-wrapper{background-color:#fcb332;padding-top:20px;padding-right:20px;padding-bottom:20px;padding-left:20px;margin-top:0px;margin-right:0px;margin-bottom:0px;margin-left:0px;border-radius:0}.blockspare-e7382741-6ea0-4 .blockspare-image-wrap{background-image:none}</style><div class="blockspare-block-container-wrapper blockspare-hover-item"><div class="blockspare-container-background blockspare-image-wrap has-background-opacity-100 has-background-opacity"></div><div class="blockspare-container"><div class="blockspare-inner-blocks blockspare-inner-wrapper-blocks"><!-- wp:group {"align":"wide","layout":{"type":"constrained"}} -->
                        <div class="wp-block-group alignwide"><!-- wp:columns {"verticalAlignment":"center","align":"wide"} -->
                        <div class="wp-block-columns alignwide are-vertically-aligned-center"><!-- wp:column {"verticalAlignment":"center","width":"66.66%"} -->
                        <div class="wp-block-column is-vertically-aligned-center" style="flex-basis:66.66%"><!-- wp:blockspare/blockspare-section-header {"uniqueClass":"blockspare-0a424379-2d4b-4","headerTitle":"Looking for a dream home?","headerSubTitle":"We can help you realize your dream of a new home","headertitleColor":"#384260","headersubtitleColor":"#384260","subtitlePaddingTop":10,"titleFontFamily":"Helvetica","titleFontWeight":"800","subTitleFontFamily":"Helvetica"} -->
                        <div class="wp-block-blockspare-blockspare-section-header aligncenter blockspare-0a424379-2d4b-4 blockspare-section-header-wrapper blockspare-blocks aligncenter" blockspare-animation=""><style>.blockspare-0a424379-2d4b-4 .blockspare-section-head-wrap{background-color:transparent;text-align:left;margin-top:30px;margin-right:0px;margin-bottom:30px;margin-left:0px}.blockspare-0a424379-2d4b-4 .blockspare-section-head-wrap .blockspare-title{color:#384260;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px;font-size:24px;font-family:Helvetica;font-weight:800}.blockspare-0a424379-2d4b-4 .blockspare-section-head-wrap .blockspare-subtitle{color:#384260;font-size:14px;font-family:Helvetica;padding-top:10px;padding-right:0px;padding-bottom:0px;padding-left:0px}@media screen and (max-width:1025px){.blockspare-0a424379-2d4b-4 .blockspare-section-head-wrap .blockspare-title{font-size:22px}.blockspare-0a424379-2d4b-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}@media screen and (max-width:768px){.blockspare-0a424379-2d4b-4 .blockspare-section-head-wrap .blockspare-title{font-size:20px}.blockspare-0a424379-2d4b-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}</style><div class="blockspare-section-head-wrap blockspare-style1 blockspare-left blockspare-hover-item"><div class="blockspare-title-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><h2 class="blockspare-title">Looking for a dream home?</h2><span class="blockspare-title-dash blockspare-lower-dash"></span></div><div class="blockspare-subtitle-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><p class="blockspare-subtitle">We can help you realize your dream of a new home</p><span class="blockspare-title-dash blockspare-lower-dash"></span></div></div></div>
                        <!-- /wp:blockspare/blockspare-section-header --></div>
                        <!-- /wp:column -->
                        
                        <!-- wp:column {"verticalAlignment":"center","width":"33.33%"} -->
                        <div class="wp-block-column is-vertically-aligned-center" style="flex-basis:33.33%"><!-- wp:blockspare/blockspare-buttons {"sectionAlignment":"right","uniqueClass":"blockspare-f86c2c7a-9ecd-4","buttonBackgroundColor":"#FFFFFF","buttonTextColor":"#384260","buttonFontFamily":"Helvetica","buttonFontWeight":"600"} -->
                        <div class="wp-block-blockspare-blockspare-buttons blockspare-f86c2c7a-9ecd-4 blockspare-block-button-wrap" blockspare-animation=""><style>.blockspare-f86c2c7a-9ecd-4 .blockspare-block-button{text-align:right;margin-top:30px;margin-bottom:30px;margin-left:0px;margin-right:0px}.blockspare-f86c2c7a-9ecd-4 .blockspare-block-button span{color:#384260;border-width:2px;font-size:16px;font-family:Helvetica;font-weight:600}.blockspare-f86c2c7a-9ecd-4 .blockspare-block-button .blockspare-button{background-color:#FFFFFF}.blockspare-f86c2c7a-9ecd-4 .blockspare-block-button .blockspare-button:visited{background-color:#FFFFFF}.blockspare-f86c2c7a-9ecd-4 .blockspare-block-button .blockspare-button:focus{background-color:#FFFFFF}.blockspare-f86c2c7a-9ecd-4 .blockspare-block-button i{font-size:16px}.blockspare-f86c2c7a-9ecd-4 .blockspare-block-button a{padding-top:12px;padding-bottom:12px;padding-right:22px;padding-left:22px}@media screen and (max-width:1025px){.blockspare-f86c2c7a-9ecd-4 .blockspare-block-button span{font-size:14px}.blockspare-f86c2c7a-9ecd-4 .blockspare-block-button i{font-size:14px}}@media screen and (max-width:768px){.blockspare-f86c2c7a-9ecd-4 .blockspare-block-button span{font-size:14px}.blockspare-f86c2c7a-9ecd-4 .blockspare-block-button i{font-size:14px}}</style><div class="blockspare-block-button"><a class="blockspare-button blockspare-button-shape-rounded blockspare-button-size-small"><span>Get Started</span></a></div></div>
                        <!-- /wp:blockspare/blockspare-buttons --></div>
                        <!-- /wp:column --></div>
                        <!-- /wp:columns --></div>
                        <!-- /wp:group --></div></div></div></div>
                        <!-- /wp:blockspare/blockspare-container -->',
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'real-estate',
                        'item'     => ['Real Estate','Services'],
						'key'      => 'bs_section_75',
                        'imagePath'    => 'sections',
						'name'     => 'Real Estate Section 5',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/real-estate-section-5/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'real-estate',
                        'item'     => ['Real Estate','Testimonial'],
						'key'      => 'bs_section_76',
                        'imagePath'    => 'sections',
						'name'     => 'Real Estate Section 6',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/real-estate-section-6/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'real-estate',
                        'item'     => ['Real Estate','Post Grid'],
						'key'      => 'bs_section_77',
                        'imagePath'    => 'sections',
						'name'     => 'Real Estate Section 7',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/real-estate-section-7/",
						'content'  => '<!-- wp:blockspare/blockspare-container {"paddingTop":50,"paddingBottom":50,"marginTop":0,"marginBottom":0,"uniqueClass":"blockspare-50bcde0e-8f9a-4"} -->
                        <div class="wp-block-blockspare-blockspare-container alignfull blockspare-50bcde0e-8f9a-4" blockspare-animation=""><style>.blockspare-50bcde0e-8f9a-4 > .blockspare-block-container-wrapper{background-color:#f9f9f9;padding-top:50px;padding-right:20px;padding-bottom:50px;padding-left:20px;margin-top:0px;margin-right:0px;margin-bottom:0px;margin-left:0px;border-radius:0}.blockspare-50bcde0e-8f9a-4 .blockspare-image-wrap{background-image:none}</style><div class="blockspare-block-container-wrapper blockspare-hover-item"><div class="blockspare-container-background blockspare-image-wrap has-background-opacity-100 has-background-opacity"></div><div class="blockspare-container"><div class="blockspare-inner-blocks blockspare-inner-wrapper-blocks"><!-- wp:group {"align":"wide","layout":{"type":"constrained"}} -->
                        <div class="wp-block-group alignwide"><!-- wp:blockspare/blockspare-section-header {"uniqueClass":"blockspare-555a3cd1-4132-4","align":"wide","headerTitle":"Explore Our Properties","headerSubTitle":" It was popularised in the 1960s with the release of Letraset sheets containing Lorem Ipsum passages","headertitleColor":"#384260","headersubtitleColor":"#989eab","headermarginTop":0,"subtitlePaddingTop":10,"titleFontFamily":"Helvetica","titleFontWeight":"800","subTitleFontFamily":"Helvetica"} -->
                        <div class="wp-block-blockspare-blockspare-section-header alignwide blockspare-555a3cd1-4132-4 blockspare-section-header-wrapper blockspare-blocks alignwide" blockspare-animation=""><style>.blockspare-555a3cd1-4132-4 .blockspare-section-head-wrap{background-color:transparent;text-align:left;margin-top:0px;margin-right:0px;margin-bottom:30px;margin-left:0px}.blockspare-555a3cd1-4132-4 .blockspare-section-head-wrap .blockspare-title{color:#384260;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px;font-size:24px;font-family:Helvetica;font-weight:800}.blockspare-555a3cd1-4132-4 .blockspare-section-head-wrap .blockspare-subtitle{color:#989eab;font-size:14px;font-family:Helvetica;padding-top:10px;padding-right:0px;padding-bottom:0px;padding-left:0px}@media screen and (max-width:1025px){.blockspare-555a3cd1-4132-4 .blockspare-section-head-wrap .blockspare-title{font-size:22px}.blockspare-555a3cd1-4132-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}@media screen and (max-width:768px){.blockspare-555a3cd1-4132-4 .blockspare-section-head-wrap .blockspare-title{font-size:20px}.blockspare-555a3cd1-4132-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}</style><div class="blockspare-section-head-wrap blockspare-style1 blockspare-left blockspare-hover-item"><div class="blockspare-title-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><h2 class="blockspare-title">Explore Our Properties</h2><span class="blockspare-title-dash blockspare-lower-dash"></span></div><div class="blockspare-subtitle-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><p class="blockspare-subtitle"> It was popularised in the 1960s with the release of Letraset sheets containing Lorem Ipsum passages</p><span class="blockspare-title-dash blockspare-lower-dash"></span></div></div></div>
                        <!-- /wp:blockspare/blockspare-section-header --></div>
                        <!-- /wp:group -->
                        
                        <!-- wp:group {"align":"wide","layout":{"type":"constrained"}} -->
                        <div class="wp-block-group alignwide"><!-- wp:blockspare/blockspare-latest-posts-grid {"categories":[{"value":3,"label":"light"}],"uniqueClass":"blockspare-dfbb8e33-60ee-4","postsToShow":3,"displayPostDate":false,"displayPostExcerpt":true,"displayPostAuthor":false,"postTitleColor":"#384260","postTitleFontSize":20,"titleFontFamily":"Helvetica","titleFontWeight":"600","linkColor":"#fcb332","generalColor":"#989eab","design":"blockspare-grid-layout-3","columns":3,"align":"wide","marginBottom":0,"backGroundColor":"#ffffff","borderRadius":15,"descriptionFontFamily":"Helvetica","categoryBorderRadius":4,"enableComment":false} /--></div>
                        <!-- /wp:group --></div></div></div></div>
                        <!-- /wp:blockspare/blockspare-container -->',
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'real-estate',
                        'item'     => ['Real Estate','Post Grid'],
						'key'      => 'bs_section_78',
                        'imagePath'    => 'sections',
						'name'     => 'Real Estate Section 8',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/real-estate-section-8/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'real-estate',
                        'item'     => ['Real Estate','Container'],
						'key'      => 'bs_section_79',
                        'imagePath'    => 'sections',
						'name'     => 'Real Estate Section 9',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/real-estate-section-9/",
						'content'  => '<!-- wp:cover {"url":"https://blockspare.com/demo/default/real-estate/wp-content/uploads/sites/8/2023/12/pexels-alex-qian-2343465.jpg","id":2156,"hasParallax":true,"dimRatio":0,"minHeight":50,"minHeightUnit":"rem","contentPosition":"center center","isDark":false,"align":"wide","style":{"spacing":{"margin":{"top":"80px","bottom":"80px"}}}} -->
                        <div class="wp-block-cover alignwide is-light has-parallax" style="margin-top:80px;margin-bottom:80px;min-height:50rem"><span aria-hidden="true" class="wp-block-cover__background has-background-dim-0 has-background-dim"></span><div role="img" class="wp-block-cover__image-background wp-image-2156 has-parallax" style="background-position:50% 50%;background-image:url(https://blockspare.com/demo/default/real-estate/wp-content/uploads/sites/8/2023/12/pexels-alex-qian-2343465.jpg)"></div><div class="wp-block-cover__inner-container"><!-- wp:columns {"align":"full"} -->
                        <div class="wp-block-columns alignfull"><!-- wp:column {"verticalAlignment":"bottom","width":"50%"} -->
                        <div class="wp-block-column is-vertically-aligned-bottom" style="flex-basis:50%"><!-- wp:blockspare/blockspare-container {"paddingRight":40,"paddingLeft":40,"marginBottom":20,"uniqueClass":"blockspare-206da49f-d601-4","backGroundColor":"#ffffff","borderRadius":20} -->
                        <div class="wp-block-blockspare-blockspare-container alignfull blockspare-206da49f-d601-4" blockspare-animation=""><style>.blockspare-206da49f-d601-4 > .blockspare-block-container-wrapper{background-color:#ffffff;padding-top:20px;padding-right:40px;padding-bottom:20px;padding-left:40px;margin-top:30px;margin-right:0px;margin-bottom:20px;margin-left:0px;border-radius:20px}.blockspare-206da49f-d601-4 .blockspare-image-wrap{background-image:none;border-radius:20px}</style><div class="blockspare-block-container-wrapper blockspare-hover-item"><div class="blockspare-container-background blockspare-image-wrap has-background-opacity-100 has-background-opacity"></div><div class="blockspare-container"><div class="blockspare-inner-blocks blockspare-inner-wrapper-blocks"><!-- wp:blockspare/blockspare-section-header {"uniqueClass":"blockspare-b4f5d275-6e93-4","headerTitle":"Search Property Smarter,\u003cbr\u003eQuicker \u0026amp; Anywhere","headerSubTitle":"\u003cbr\u003eLET’S TAKE A TOUR","headertitleColor":"#384260","headersubtitleColor":"#fcb332","headerlayoutOption":"blockspare-style3","titlePaddingTop":10,"titleFontFamily":"Helvetica","titleFontWeight":"800","subTitleFontFamily":"Helvetica"} -->
                        <div class="wp-block-blockspare-blockspare-section-header aligncenter blockspare-b4f5d275-6e93-4 blockspare-section-header-wrapper blockspare-blocks aligncenter" blockspare-animation=""><style>.blockspare-b4f5d275-6e93-4 .blockspare-section-head-wrap{background-color:transparent;text-align:left;margin-top:30px;margin-right:0px;margin-bottom:30px;margin-left:0px}.blockspare-b4f5d275-6e93-4 .blockspare-section-head-wrap .blockspare-title{color:#384260;padding-top:10px;padding-right:0px;padding-bottom:0px;padding-left:0px;font-size:24px;font-family:Helvetica;font-weight:800}.blockspare-b4f5d275-6e93-4 .blockspare-section-head-wrap .blockspare-subtitle{color:#fcb332;font-size:14px;font-family:Helvetica;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px}@media screen and (max-width:1025px){.blockspare-b4f5d275-6e93-4 .blockspare-section-head-wrap .blockspare-title{font-size:22px}.blockspare-b4f5d275-6e93-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}@media screen and (max-width:768px){.blockspare-b4f5d275-6e93-4 .blockspare-section-head-wrap .blockspare-title{font-size:20px}.blockspare-b4f5d275-6e93-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}</style><div class="blockspare-section-head-wrap blockspare-style3 blockspare-left blockspare-hover-item"><div class="blockspare-title-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><h2 class="blockspare-title">Search Property Smarter,<br>Quicker &amp; Anywhere</h2><span class="blockspare-title-dash blockspare-lower-dash"></span></div><div class="blockspare-subtitle-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><p class="blockspare-subtitle"><br>LET’S TAKE A TOUR</p><span class="blockspare-title-dash blockspare-lower-dash"></span></div></div></div>
                        <!-- /wp:blockspare/blockspare-section-header -->
                        
                        <!-- wp:blockspare/blockspare-buttons {"sectionAlignment":"left","uniqueClass":"blockspare-99abd180-d79a-4","buttonText":"Search Now","buttonBackgroundColor":"#fcb332","buttonTextColor":"#000000","buttonFontWeight":"600","marginBottom":50} -->
                        <div class="wp-block-blockspare-blockspare-buttons blockspare-99abd180-d79a-4 blockspare-block-button-wrap" blockspare-animation=""><style>.blockspare-99abd180-d79a-4 .blockspare-block-button{text-align:left;margin-top:30px;margin-bottom:50px;margin-left:0px;margin-right:0px}.blockspare-99abd180-d79a-4 .blockspare-block-button span{color:#000000;border-width:2px;font-size:16px;font-weight:600}.blockspare-99abd180-d79a-4 .blockspare-block-button .blockspare-button{background-color:#fcb332}.blockspare-99abd180-d79a-4 .blockspare-block-button .blockspare-button:visited{background-color:#fcb332}.blockspare-99abd180-d79a-4 .blockspare-block-button .blockspare-button:focus{background-color:#fcb332}.blockspare-99abd180-d79a-4 .blockspare-block-button i{font-size:16px}.blockspare-99abd180-d79a-4 .blockspare-block-button a{padding-top:12px;padding-bottom:12px;padding-right:22px;padding-left:22px}@media screen and (max-width:1025px){.blockspare-99abd180-d79a-4 .blockspare-block-button span{font-size:14px}.blockspare-99abd180-d79a-4 .blockspare-block-button i{font-size:14px}}@media screen and (max-width:768px){.blockspare-99abd180-d79a-4 .blockspare-block-button span{font-size:14px}.blockspare-99abd180-d79a-4 .blockspare-block-button i{font-size:14px}}</style><div class="blockspare-block-button"><a class="blockspare-button blockspare-button-shape-rounded blockspare-button-size-small"><span>Search Now</span></a></div></div>
                        <!-- /wp:blockspare/blockspare-buttons --></div></div></div></div>
                        <!-- /wp:blockspare/blockspare-container --></div>
                        <!-- /wp:column --></div>
                        <!-- /wp:columns --></div></div>
                        <!-- /wp:cover -->',
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'real-estate',
                        'item'     => ['Real Estate','Testimonial'],
						'key'      => 'bs_section_80',
                        'imagePath'    => 'sections',
						'name'     => 'Real Estate Section 10',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/real-estate-section-10/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'real-estate',
                        'item'     => ['Real Estate','Icon','Container'],
						'key'      => 'bs_section_81',
                        'imagePath'    => 'sections',
						'name'     => 'Real Estate Section 11',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/real-estate-section-11/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'real-estate',
                        'item'     => ['Real Estate','Icon','Services'],
						'key'      => 'bs_section_82',
                        'imagePath'    => 'sections',
						'name'     => 'Real Estate Section 12',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/real-estate-section-12/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'medical',
                        'item'     => ['Medical','Content Box'],
						'key'      => 'bs_section_83',
                        'imagePath'    => 'sections',
						'name'     => 'Medical Section 1',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/medical-section-1/",
						'content'  => '<!-- wp:blockspare/content-box {"paddingTop":50,"paddingBottom":50,"marginTop":0,"marginBottom":0,"uniqueClass":"blockspare-e395a441-936e-4","align":"wide","sectionAlignment":"left","headerTitle":"We Create Solution \u003cbr\u003eFor Your Health","titleFontSize":52,"headertitleColor":"#09425a","subtitlePaddingBottom":30,"titleFontFamily":"Helvetica","titleFontWeight":"800","subTitleFontSize":0,"subTitleFontFamily":"Helvetica","sectionDescription":"Aliquam erat volutpat. In hac habitasse platea dictumst. Vestibulum turpis sem, aliquet eget, lobortis pellentesque, rutrum eu, nisl. Sed in libero ut nibh placerat accumsan. \u003cbr\u003e","imgURL":"https://blockspare.com/demo/default/medical/wp-content/uploads/sites/9/2023/12/pexels-chokniti-khongchum-2280547.png","imgID":1529,"showButton":true,"descriptionColor":"#8d9ea2","layoutOption":true,"imageShape":"blockspare-style2","design":"style-3","descriptionFontFamily":"Helvetica","buttonBackgroundColor":"#009abb","buttonHoverEffect":"hover-style-5","buttonFontFamily":"Helvetica","descriptionMarginRight":50} -->
                        <div class="wp-block-blockspare-content-box blockspare-e395a441-936e-4 blockspare-contentBox alignwide" blockspare-animation=""><style>.blockspare-e395a441-936e-4 .blockspare-content-wrapper{text-align:left;margin-top:0px;margin-right:0px;margin-bottom:0px;margin-left:0px}.blockspare-e395a441-936e-4 .blockspare-content-wrapper .blockspare-section-wrapper{padding-top:50px;padding-right:30px;padding-bottom:50px;padding-left:30px}.blockspare-e395a441-936e-4 .blockspare-desc-btn-wrap .blockspare-description{color:#8d9ea2;font-size:16px;font-family:Helvetica}.blockspare-e395a441-936e-4 .blockspare-section-header-wrapper{border-color:#8b249c}.blockspare-e395a441-936e-4 .blockspare-content-wrapper .blockspare-desc-btn-wrap .blockspare-description{margin-top:10px;margin-right:50px;margin-bottom:0px;margin-left:0px}.blockspare-e395a441-936e-4 .blockspare-block-button a.blockspare-button{margin-top:10px;margin-right:0px;margin-bottom:0px;margin-left:0px;padding-top:12px;padding-right:22px;padding-bottom:12px;padding-left:22px;color:#fff;border-width:2px;font-size:16px;font-family:Helvetica}.blockspare-e395a441-936e-4 .blockspare-section-head-wrap{background-color:transparent;text-align:left;margin-top:0px;margin-right:0px;margin-bottom:0px;margin-left:0px}.blockspare-e395a441-936e-4.wp-block-blockspare-content-box .blockspare-block-button .blockspare-button{background-color:#009abb}.blockspare-e395a441-936e-4.wp-block-blockspare-content-box .blockspare-block-button .blockspare-button:visited{background-color:#009abb}.blockspare-e395a441-936e-4.wp-block-blockspare-content-box .blockspare-block-button .blockspare-button:focus{background-color:#009abb}.blockspare-e395a441-936e-4 .blockspare-section-head-wrap .blockspare-title{color:#09425a;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px;font-size:52px;font-family:Helvetica;font-weight:800}.blockspare-e395a441-936e-4 .blockspare-section-head-wrap .blockspare-subtitle{color:#6d6d6d;font-size:0px;font-family:Helvetica;padding-top:0px;padding-right:0px;padding-bottom:30px;padding-left:0px}@media screen and (max-width:1025px){.blockspare-e395a441-936e-4 .blockspare-block-button a.blockspare-button{font-size:14px}.blockspare-e395a441-936e-4 .blockspare-section-head-wrap .blockspare-title{font-size:22px}.blockspare-e395a441-936e-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}.blockspare-e395a441-936e-4 .blockspare-desc-btn-wrap .blockspare-description{font-size:16px}}@media screen and (max-width:768px){.blockspare-e395a441-936e-4 .blockspare-block-button a.blockspare-button{font-size:14px}.blockspare-e395a441-936e-4 .blockspare-section-head-wrap .blockspare-title{font-size:20px}.blockspare-e395a441-936e-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}.blockspare-e395a441-936e-4 .blockspare-desc-btn-wrap .blockspare-description{font-size:14px}}</style><div class="blockspare-content-wrapper blockspare-blocks blockspare-hover-item blockspare-hover-child reverse-img style-3"><div class="content-img-wrap"><img src="https://blockspare.com/demo/default/medical/wp-content/uploads/sites/9/2023/12/pexels-chokniti-khongchum-2280547.png" alt="" class="blockspare-style2 hover-child"/></div><div class="blockspare-section-wrapper blockspare-mainheader-wrap "><div class="blockspare-section-head-wrap"><div class="blockspare-title-subtitle-wrap"><div class="blockspare-section-header-wrapper blockspare-blocks"><div class="blockspare-section-head-wrap blockspare-style1 blockspare-left"><div class="blockspare-title-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><h2 class="blockspare-title">We Create Solution <br>For Your Health</h2><span class="blockspare-title-dash blockspare-lower-dash"></span></div><div class="blockspare-subtitle-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><span class="blockspare-title-dash blockspare-lower-dash"></span></div></div></div></div></div><div class="blockspare-desc-btn-wrap"><p class="blockspare-description">Aliquam erat volutpat. In hac habitasse platea dictumst. Vestibulum turpis sem, aliquet eget, lobortis pellentesque, rutrum eu, nisl. Sed in libero ut nibh placerat accumsan. <br></p><div class="blockspare-block-button"><a class="blockspare-button blockspare-button-shape-rounded blockspare-button-size-small hover-style-5"><span>Get Started</span></a></div></div></div></div></div>
                        <!-- /wp:blockspare/content-box -->',
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'medical',
                        'item'     => ['Medical','Counter','Services'],
						'key'      => 'bs_section_84',
                        'imagePath'    => 'sections',
						'name'     => 'Medical Section 2',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/medical-section-2/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'medical',
                        'item'     => ['Medical','Services'],
						'key'      => 'bs_section_85',
                        'imagePath'    => 'sections',
						'name'     => 'Medical Section 3',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/medical-section-3/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'medical',
                        'item'     => ['Medical','Services'],
						'key'      => 'bs_section_86',
                        'imagePath'    => 'sections',
						'name'     => 'Medical Section 4',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/medical-section-4/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'medical',
                        'item'     => ['Medical','Content Box'],
						'key'      => 'bs_section_87',
                        'imagePath'    => 'sections',
						'name'     => 'Medical Section 5',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/medical-section-5/",
						'content'  => '<!-- wp:blockspare/blockspare-container {"paddingTop":50,"paddingBottom":50,"marginTop":0,"marginBottom":0,"uniqueClass":"blockspare-c8c684d1-a6b0-4","backGroundColor":"#f0f4f5"} -->
                        <div class="wp-block-blockspare-blockspare-container alignfull blockspare-c8c684d1-a6b0-4" blockspare-animation=""><style>.blockspare-c8c684d1-a6b0-4 > .blockspare-block-container-wrapper{background-color:#f0f4f5;padding-top:50px;padding-right:20px;padding-bottom:50px;padding-left:20px;margin-top:0px;margin-right:0px;margin-bottom:0px;margin-left:0px;border-radius:0}.blockspare-c8c684d1-a6b0-4 .blockspare-image-wrap{background-image:none}</style><div class="blockspare-block-container-wrapper blockspare-hover-item"><div class="blockspare-container-background blockspare-image-wrap has-background-opacity-100 has-background-opacity"></div><div class="blockspare-container"><div class="blockspare-inner-blocks blockspare-inner-wrapper-blocks"><!-- wp:group {"align":"wide","layout":{"type":"constrained"}} -->
                        <div class="wp-block-group alignwide"><!-- wp:columns {"verticalAlignment":"center","align":"wide"} -->
                        <div class="wp-block-columns alignwide are-vertically-aligned-center"><!-- wp:column {"verticalAlignment":"center","width":"80%"} -->
                        <div class="wp-block-column is-vertically-aligned-center" style="flex-basis:80%"><!-- wp:blockspare/blockspare-section-header {"uniqueClass":"blockspare-c7980d37-6d3c-4","align":"full","headerTitle":"Consultation","headerSubTitle":"Get your free consultation now by scheduling an appointment online.","headertitleColor":"#09425a","headersubtitleColor":"#8d9ea2","subtitlePaddingTop":10,"titleFontFamily":"Helvetica","titleFontWeight":"600","subTitleFontSize":16,"subTitleFontFamily":"Helvetica"} -->
                        <div class="wp-block-blockspare-blockspare-section-header alignfull blockspare-c7980d37-6d3c-4 blockspare-section-header-wrapper blockspare-blocks alignfull" blockspare-animation=""><style>.blockspare-c7980d37-6d3c-4 .blockspare-section-head-wrap{background-color:transparent;text-align:left;margin-top:30px;margin-right:0px;margin-bottom:30px;margin-left:0px}.blockspare-c7980d37-6d3c-4 .blockspare-section-head-wrap .blockspare-title{color:#09425a;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px;font-size:24px;font-family:Helvetica;font-weight:600}.blockspare-c7980d37-6d3c-4 .blockspare-section-head-wrap .blockspare-subtitle{color:#8d9ea2;font-size:16px;font-family:Helvetica;padding-top:10px;padding-right:0px;padding-bottom:0px;padding-left:0px}@media screen and (max-width:1025px){.blockspare-c7980d37-6d3c-4 .blockspare-section-head-wrap .blockspare-title{font-size:22px}.blockspare-c7980d37-6d3c-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}@media screen and (max-width:768px){.blockspare-c7980d37-6d3c-4 .blockspare-section-head-wrap .blockspare-title{font-size:20px}.blockspare-c7980d37-6d3c-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}</style><div class="blockspare-section-head-wrap blockspare-style1 blockspare-left blockspare-hover-item"><div class="blockspare-title-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><h2 class="blockspare-title">Consultation</h2><span class="blockspare-title-dash blockspare-lower-dash"></span></div><div class="blockspare-subtitle-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><p class="blockspare-subtitle">Get your free consultation now by scheduling an appointment online.</p><span class="blockspare-title-dash blockspare-lower-dash"></span></div></div></div>
                        <!-- /wp:blockspare/blockspare-section-header --></div>
                        <!-- /wp:column -->
                        
                        <!-- wp:column {"verticalAlignment":"center","width":"25%"} -->
                        <div class="wp-block-column is-vertically-aligned-center" style="flex-basis:25%"><!-- wp:blockspare/blockspare-buttons {"sectionAlignment":"right","uniqueClass":"blockspare-2f20fce8-1194-4","buttonText":"Make an Appointment","buttonBackgroundColor":"#009abb","buttonHoverEffect":"hover-style-5","buttonFontFamily":"Helvetica","buttonIcon":"fas fa-hourglass-half"} -->
                        <div class="wp-block-blockspare-blockspare-buttons blockspare-2f20fce8-1194-4 blockspare-block-button-wrap" blockspare-animation=""><style>.blockspare-2f20fce8-1194-4 .blockspare-block-button{text-align:right;margin-top:30px;margin-bottom:30px;margin-left:0px;margin-right:0px}.blockspare-2f20fce8-1194-4 .blockspare-block-button span{color:#fff;border-width:2px;font-size:16px;font-family:Helvetica}.blockspare-2f20fce8-1194-4 .blockspare-block-button .blockspare-button{background-color:#009abb}.blockspare-2f20fce8-1194-4 .blockspare-block-button .blockspare-button:visited{background-color:#009abb}.blockspare-2f20fce8-1194-4 .blockspare-block-button .blockspare-button:focus{background-color:#009abb}.blockspare-2f20fce8-1194-4 .blockspare-block-button i{font-size:16px}.blockspare-2f20fce8-1194-4 .blockspare-block-button a{padding-top:12px;padding-bottom:12px;padding-right:22px;padding-left:22px}@media screen and (max-width:1025px){.blockspare-2f20fce8-1194-4 .blockspare-block-button span{font-size:14px}.blockspare-2f20fce8-1194-4 .blockspare-block-button i{font-size:14px}}@media screen and (max-width:768px){.blockspare-2f20fce8-1194-4 .blockspare-block-button span{font-size:14px}.blockspare-2f20fce8-1194-4 .blockspare-block-button i{font-size:14px}}</style><div class="blockspare-block-button"><a class="blockspare-button blockspare-button-shape-rounded blockspare-button-size-small hover-style-5"><span>Make an Appointment</span></a></div></div>
                        <!-- /wp:blockspare/blockspare-buttons --></div>
                        <!-- /wp:column --></div>
                        <!-- /wp:columns --></div>
                        <!-- /wp:group --></div></div></div></div>
                        <!-- /wp:blockspare/blockspare-container -->',
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'medical',
                        'item'     => ['Medical','User Profile'],
						'key'      => 'bs_section_88',
                        'imagePath'    => 'sections',
						'name'     => 'Medical Section 6',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/medical-section-6/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'medical',
                        'item'     => ['Medical','Testimonial'],
						'key'      => 'bs_section_89',
                        'imagePath'    => 'sections',
						'name'     => 'Medical Section 7',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/medical-section-7/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'medical',
                        'item'     => ['Medical','Services'],
						'key'      => 'bs_section_90',
                        'imagePath'    => 'sections',
						'name'     => 'Medical Section 8',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/medical-section-8/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'medical',
                        'item'     => ['Medical','Services'],
						'key'      => 'bs_section_91',
                        'imagePath'    => 'sections',
						'name'     => 'Medical Section 9',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/medical-section-9/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'medical',
                        'item'     => ['Medical','Counter'],
						'key'      => 'bs_section_92',
                        'imagePath'    => 'sections',
						'name'     => 'Medical Section 10',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/medical-section-10/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'medical',
                        'item'     => ['Medical','Services'],
						'key'      => 'bs_section_93',
                        'imagePath'    => 'sections',
						'name'     => 'Medical Section 11',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/medical-section-11/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'medical',
                        'item'     => ['Medical','Pricing Table'],
						'key'      => 'bs_section_94',
                        'imagePath'    => 'sections',
						'name'     => 'Medical Section 12',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/medical-section-11/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'charity',
                        'item'     => ['Charity','Services','Call To Action'],
						'key'      => 'bs_section_95',
                        'imagePath'    => 'sections',
						'name'     => 'Charity Section 1',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/charity-section-1/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'charity',
                        'item'     => ['Charity','Content Box'],
						'key'      => 'bs_section_96',
                        'imagePath'    => 'sections',
						'name'     => 'Charity Section 2',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/charity-section-2/",
						'content'  => '<!-- wp:blockspare/blockspare-container {"paddingTop":80,"paddingBottom":80,"marginTop":2,"marginBottom":0,"align":"wide","uniqueClass":"blockspare-dabe7357-1cba-4","backGroundColor":"#ffffff"} -->
                        <div class="wp-block-blockspare-blockspare-container alignwide blockspare-dabe7357-1cba-4" blockspare-animation=""><style>.blockspare-dabe7357-1cba-4 > .blockspare-block-container-wrapper{background-color:#ffffff;padding-top:80px;padding-right:20px;padding-bottom:80px;padding-left:20px;margin-top:2px;margin-right:0px;margin-bottom:0px;margin-left:0px;border-radius:0}.blockspare-dabe7357-1cba-4 .blockspare-image-wrap{background-image:none}</style><div class="blockspare-block-container-wrapper blockspare-hover-item"><div class="blockspare-container-background blockspare-image-wrap has-background-opacity-100 has-background-opacity"></div><div class="blockspare-container"><div class="blockspare-inner-blocks blockspare-inner-wrapper-blocks"><!-- wp:group {"align":"wide","layout":{"type":"constrained"}} -->
                        <div class="wp-block-group alignwide"><!-- wp:columns {"align":"wide"} -->
                        <div class="wp-block-columns alignwide"><!-- wp:column {"verticalAlignment":"center","width":"66.66%"} -->
                        <div class="wp-block-column is-vertically-aligned-center" style="flex-basis:66.66%"><!-- wp:blockspare/blockspare-section-header {"uniqueClass":"blockspare-6ca8c11e-9fe8-4","headerTitle":"Together, \u003cbr\u003ewe are creating a better way \u003cbr\u003eto deal with hunger.","headerSubTitle":"For more than 40 years, we have led the global movement that aims to end\u003cbr\u003elife-threatening hunger for good within our lifetimes. Our teams have been\u003cbr\u003eon the front line.","headertitleColor":"#000000","headersubtitleColor":"#abb8c3","headermarginTop":0,"headermarginBottom":0,"subtitlePaddingTop":20,"titleFontFamily":"Helvetica","titleFontWeight":"700","subTitleFontFamily":"Helvetica"} -->
                        <div class="wp-block-blockspare-blockspare-section-header aligncenter blockspare-6ca8c11e-9fe8-4 blockspare-section-header-wrapper blockspare-blocks aligncenter" blockspare-animation=""><style>.blockspare-6ca8c11e-9fe8-4 .blockspare-section-head-wrap{background-color:transparent;text-align:left;margin-top:0px;margin-right:0px;margin-bottom:0px;margin-left:0px}.blockspare-6ca8c11e-9fe8-4 .blockspare-section-head-wrap .blockspare-title{color:#000000;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px;font-size:24px;font-family:Helvetica;font-weight:700}.blockspare-6ca8c11e-9fe8-4 .blockspare-section-head-wrap .blockspare-subtitle{color:#abb8c3;font-size:14px;font-family:Helvetica;padding-top:20px;padding-right:0px;padding-bottom:0px;padding-left:0px}@media screen and (max-width:1025px){.blockspare-6ca8c11e-9fe8-4 .blockspare-section-head-wrap .blockspare-title{font-size:22px}.blockspare-6ca8c11e-9fe8-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}@media screen and (max-width:768px){.blockspare-6ca8c11e-9fe8-4 .blockspare-section-head-wrap .blockspare-title{font-size:20px}.blockspare-6ca8c11e-9fe8-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}</style><div class="blockspare-section-head-wrap blockspare-style1 blockspare-left blockspare-hover-item"><div class="blockspare-title-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><h2 class="blockspare-title">Together, <br>we are creating a better way <br>to deal with hunger.</h2><span class="blockspare-title-dash blockspare-lower-dash"></span></div><div class="blockspare-subtitle-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><p class="blockspare-subtitle">For more than 40 years, we have led the global movement that aims to end<br>life-threatening hunger for good within our lifetimes. Our teams have been<br>on the front line.</p><span class="blockspare-title-dash blockspare-lower-dash"></span></div></div></div>
                        <!-- /wp:blockspare/blockspare-section-header -->
                        
                        <!-- wp:blockspare/blockspare-buttons {"sectionAlignment":"left","uniqueClass":"blockspare-1448ec80-0a36-4","buttonText":"Read More","buttonBackgroundColor":"#eb5310","buttonHoverEffect":"hover-style-2","buttonFontFamily":"Helvetica","marginTop":0,"marginBottom":0} -->
                        <div class="wp-block-blockspare-blockspare-buttons blockspare-1448ec80-0a36-4 blockspare-block-button-wrap" blockspare-animation=""><style>.blockspare-1448ec80-0a36-4 .blockspare-block-button{text-align:left;margin-top:0px;margin-bottom:0px;margin-left:0px;margin-right:0px}.blockspare-1448ec80-0a36-4 .blockspare-block-button span{color:#fff;border-width:2px;font-size:16px;font-family:Helvetica}.blockspare-1448ec80-0a36-4 .blockspare-block-button .blockspare-button{background-color:#eb5310}.blockspare-1448ec80-0a36-4 .blockspare-block-button .blockspare-button:visited{background-color:#eb5310}.blockspare-1448ec80-0a36-4 .blockspare-block-button .blockspare-button:focus{background-color:#eb5310}.blockspare-1448ec80-0a36-4 .blockspare-block-button i{font-size:16px}.blockspare-1448ec80-0a36-4 .blockspare-block-button a{padding-top:12px;padding-bottom:12px;padding-right:22px;padding-left:22px}@media screen and (max-width:1025px){.blockspare-1448ec80-0a36-4 .blockspare-block-button span{font-size:14px}.blockspare-1448ec80-0a36-4 .blockspare-block-button i{font-size:14px}}@media screen and (max-width:768px){.blockspare-1448ec80-0a36-4 .blockspare-block-button span{font-size:14px}.blockspare-1448ec80-0a36-4 .blockspare-block-button i{font-size:14px}}</style><div class="blockspare-block-button"><a class="blockspare-button blockspare-button-shape-rounded blockspare-button-size-small hover-style-2"><span>Read More</span></a></div></div>
                        <!-- /wp:blockspare/blockspare-buttons --></div>
                        <!-- /wp:column -->
                        
                        <!-- wp:column {"verticalAlignment":"center","width":"33.33%"} -->
                        <div class="wp-block-column is-vertically-aligned-center" style="flex-basis:33.33%"><!-- wp:image {"align":"right","id":1640,"width":"588px","height":"auto","sizeSlug":"full","linkDestination":"none","className":"is-style-default"} -->
                        <figure class="wp-block-image alignright size-full is-resized is-style-default"><img src="https://blockspare.com/demo/default/charity/wp-content/uploads/sites/10/2023/12/pexels-julia-m-cameron-6994993.jpg" alt="" class="wp-image-1640" style="width:588px;height:auto"/></figure>
                        <!-- /wp:image --></div>
                        <!-- /wp:column --></div>
                        <!-- /wp:columns --></div>
                        <!-- /wp:group --></div></div></div></div>
                        <!-- /wp:blockspare/blockspare-container -->',
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'charity',
                        'item'     => ['Charity','Services','Call To Action'],
						'key'      => 'bs_section_97',
                        'imagePath'    => 'sections',
						'name'     => 'Charity Section 3',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/charity-section-3/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'charity',
                        'item'     => ['Charity','User Profile'],
						'key'      => 'bs_section_98',
                        'imagePath'    => 'sections',
						'name'     => 'Charity Section 4',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/charity-section-4/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'charity',
                        'item'     => ['Charity','Services'],
						'key'      => 'bs_section_99',
                        'imagePath'    => 'sections',
						'name'     => 'Charity Section 5',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/charity-section-5/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'charity',
                        'item'     => ['Charity','Services','Progress Bar'],
						'key'      => 'bs_section_100',
                        'imagePath'    => 'sections',
						'name'     => 'Charity Section 6',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/charity-section-6/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'charity',
                        'item'     => ['Charity','Call To Action'],
						'key'      => 'bs_section_101',
                        'imagePath'    => 'sections',
						'name'     => 'Charity Section 7',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/charity-section-7/",
						'content'  => '<!-- wp:blockspare/blockspare-container {"paddingTop":80,"paddingBottom":80,"marginTop":0,"marginBottom":0,"uniqueClass":"blockspare-9e8783b9-e6b3-4","backGroundColor":"#eb5310"} -->
                        <div class="wp-block-blockspare-blockspare-container alignfull blockspare-9e8783b9-e6b3-4" blockspare-animation=""><style>.blockspare-9e8783b9-e6b3-4 > .blockspare-block-container-wrapper{background-color:#eb5310;padding-top:80px;padding-right:20px;padding-bottom:80px;padding-left:20px;margin-top:0px;margin-right:0px;margin-bottom:0px;margin-left:0px;border-radius:0}.blockspare-9e8783b9-e6b3-4 .blockspare-image-wrap{background-image:none}</style><div class="blockspare-block-container-wrapper blockspare-hover-item"><div class="blockspare-container-background blockspare-image-wrap has-background-opacity-100 has-background-opacity"></div><div class="blockspare-container"><div class="blockspare-inner-blocks blockspare-inner-wrapper-blocks"><!-- wp:group {"align":"wide","layout":{"type":"constrained"}} -->
                        <div class="wp-block-group alignwide"><!-- wp:blockspare/blockspare-section-header {"uniqueClass":"blockspare-398e625e-90b1-4","sectionAlignment":"center","headerTitle":"How Can You Help?","headerSubTitle":"Your donation will help us save and improve lives with research,\u003cbr\u003e education and emergency care.","headertitleColor":"#ffffff","headersubtitleColor":"#ffffff","headermarginTop":0,"subtitlePaddingTop":10,"titleFontFamily":"Helvetica","titleFontWeight":"700","subTitleFontSize":16,"subTitleFontFamily":"Helvetica"} -->
                        <div class="wp-block-blockspare-blockspare-section-header aligncenter blockspare-398e625e-90b1-4 blockspare-section-header-wrapper blockspare-blocks aligncenter" blockspare-animation=""><style>.blockspare-398e625e-90b1-4 .blockspare-section-head-wrap{background-color:transparent;text-align:center;margin-top:0px;margin-right:0px;margin-bottom:30px;margin-left:0px}.blockspare-398e625e-90b1-4 .blockspare-section-head-wrap .blockspare-title{color:#ffffff;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px;font-size:24px;font-family:Helvetica;font-weight:700}.blockspare-398e625e-90b1-4 .blockspare-section-head-wrap .blockspare-subtitle{color:#ffffff;font-size:16px;font-family:Helvetica;padding-top:10px;padding-right:0px;padding-bottom:0px;padding-left:0px}@media screen and (max-width:1025px){.blockspare-398e625e-90b1-4 .blockspare-section-head-wrap .blockspare-title{font-size:22px}.blockspare-398e625e-90b1-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}@media screen and (max-width:768px){.blockspare-398e625e-90b1-4 .blockspare-section-head-wrap .blockspare-title{font-size:20px}.blockspare-398e625e-90b1-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}</style><div class="blockspare-section-head-wrap blockspare-style1 blockspare-center blockspare-hover-item"><div class="blockspare-title-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><h2 class="blockspare-title">How Can You Help?</h2><span class="blockspare-title-dash blockspare-lower-dash"></span></div><div class="blockspare-subtitle-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><p class="blockspare-subtitle">Your donation will help us save and improve lives with research,<br> education and emergency care.</p><span class="blockspare-title-dash blockspare-lower-dash"></span></div></div></div>
                        <!-- /wp:blockspare/blockspare-section-header -->
                        
                        <!-- wp:columns {"verticalAlignment":"center","align":"wide"} -->
                        <div class="wp-block-columns alignwide are-vertically-aligned-center"><!-- wp:column {"verticalAlignment":"center"} -->
                        <div class="wp-block-column is-vertically-aligned-center"><!-- wp:blockspare/blockspare-buttons {"sectionAlignment":"right","uniqueClass":"blockspare-a6f3679d-e048-4","buttonText":"Donate Now","buttonBackgroundColor":"#ffffff","buttonTextColor":"#eb3510","buttonHoverEffect":"hover-style-2","enableButtonIcon":true,"buttonIcon":"fas fa-dollar-sign","buttonIconColor":"#eb3510","marginTop":0,"marginBottom":0} -->
                        <div class="wp-block-blockspare-blockspare-buttons blockspare-a6f3679d-e048-4 blockspare-block-button-wrap" blockspare-animation=""><style>.blockspare-a6f3679d-e048-4 .blockspare-block-button{text-align:right;margin-top:0px;margin-bottom:0px;margin-left:0px;margin-right:0px}.blockspare-a6f3679d-e048-4 .blockspare-block-button i{color:#eb3510;font-size:16px}.blockspare-a6f3679d-e048-4 .blockspare-block-button span{color:#eb3510;border-width:2px;font-size:16px}.blockspare-a6f3679d-e048-4 .blockspare-block-button .blockspare-button{background-color:#ffffff}.blockspare-a6f3679d-e048-4 .blockspare-block-button .blockspare-button:visited{background-color:#ffffff}.blockspare-a6f3679d-e048-4 .blockspare-block-button .blockspare-button:focus{background-color:#ffffff}.blockspare-a6f3679d-e048-4 .blockspare-block-button a{padding-top:12px;padding-bottom:12px;padding-right:22px;padding-left:22px}@media screen and (max-width:1025px){.blockspare-a6f3679d-e048-4 .blockspare-block-button span{font-size:14px}.blockspare-a6f3679d-e048-4 .blockspare-block-button i{font-size:14px}}@media screen and (max-width:768px){.blockspare-a6f3679d-e048-4 .blockspare-block-button span{font-size:14px}.blockspare-a6f3679d-e048-4 .blockspare-block-button i{font-size:14px}}</style><div class="blockspare-block-button"><a class="blockspare-button blockspare-button-shape-rounded blockspare-button-size-small hover-style-2 btn-icon-left"><i class="fas fa-dollar-sign"></i><span>Donate Now</span></a></div></div>
                        <!-- /wp:blockspare/blockspare-buttons --></div>
                        <!-- /wp:column -->
                        
                        <!-- wp:column {"verticalAlignment":"center"} -->
                        <div class="wp-block-column is-vertically-aligned-center"><!-- wp:blockspare/blockspare-buttons {"sectionAlignment":"left","uniqueClass":"blockspare-872843c6-0e6f-4","buttonText":"Join Us","buttonBackgroundColor":"#ffffff","buttonTextColor":"#eb3510","buttonHoverEffect":"hover-style-2","enableButtonIcon":true,"buttonIcon":"fas fa-users","buttonIconColor":"#eb3510","marginTop":0,"marginBottom":0} -->
                        <div class="wp-block-blockspare-blockspare-buttons blockspare-872843c6-0e6f-4 blockspare-block-button-wrap" blockspare-animation=""><style>.blockspare-872843c6-0e6f-4 .blockspare-block-button{text-align:left;margin-top:0px;margin-bottom:0px;margin-left:0px;margin-right:0px}.blockspare-872843c6-0e6f-4 .blockspare-block-button i{color:#eb3510;font-size:16px}.blockspare-872843c6-0e6f-4 .blockspare-block-button span{color:#eb3510;border-width:2px;font-size:16px}.blockspare-872843c6-0e6f-4 .blockspare-block-button .blockspare-button{background-color:#ffffff}.blockspare-872843c6-0e6f-4 .blockspare-block-button .blockspare-button:visited{background-color:#ffffff}.blockspare-872843c6-0e6f-4 .blockspare-block-button .blockspare-button:focus{background-color:#ffffff}.blockspare-872843c6-0e6f-4 .blockspare-block-button a{padding-top:12px;padding-bottom:12px;padding-right:22px;padding-left:22px}@media screen and (max-width:1025px){.blockspare-872843c6-0e6f-4 .blockspare-block-button span{font-size:14px}.blockspare-872843c6-0e6f-4 .blockspare-block-button i{font-size:14px}}@media screen and (max-width:768px){.blockspare-872843c6-0e6f-4 .blockspare-block-button span{font-size:14px}.blockspare-872843c6-0e6f-4 .blockspare-block-button i{font-size:14px}}</style><div class="blockspare-block-button"><a class="blockspare-button blockspare-button-shape-rounded blockspare-button-size-small hover-style-2 btn-icon-left"><i class="fas fa-users"></i><span>Join Us</span></a></div></div>
                        <!-- /wp:blockspare/blockspare-buttons --></div>
                        <!-- /wp:column --></div>
                        <!-- /wp:columns --></div>
                        <!-- /wp:group --></div></div></div></div>
                        <!-- /wp:blockspare/blockspare-container -->',
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'charity',
                        'item'     => ['Charity','Services'],
						'key'      => 'bs_section_102',
                        'imagePath'    => 'sections',
						'name'     => 'Charity Section 8',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/charity-section-8/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'charity',
                        'item'     => ['Charity','Call To Action'],
						'key'      => 'bs_section_103',
                        'imagePath'    => 'sections',
						'name'     => 'Charity Section 9',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/charity-section-9/",
						'content'  => '<!-- wp:blockspare/blockspare-container {"paddingTop":0,"paddingBottom":0,"marginTop":0,"marginBottom":0,"uniqueClass":"blockspare-93c8b496-ef97-4"} -->
                        <div class="wp-block-blockspare-blockspare-container alignfull blockspare-93c8b496-ef97-4" blockspare-animation=""><style>.blockspare-93c8b496-ef97-4 > .blockspare-block-container-wrapper{background-color:#f9f9f9;padding-top:0px;padding-right:20px;padding-bottom:0px;padding-left:20px;margin-top:0px;margin-right:0px;margin-bottom:0px;margin-left:0px;border-radius:0}.blockspare-93c8b496-ef97-4 .blockspare-image-wrap{background-image:none}</style><div class="blockspare-block-container-wrapper blockspare-hover-item"><div class="blockspare-container-background blockspare-image-wrap has-background-opacity-100 has-background-opacity"></div><div class="blockspare-container"><div class="blockspare-inner-blocks blockspare-inner-wrapper-blocks"><!-- wp:blockspare/blockspare-call-to-action {"uniqueClass":"blockspare-a7b33dec-9187-4","align":"full","headerTitle":"Start Your Own Initiative","headerSubTitle":"Support Us","headertitleColor":"#323230","headersubtitleColor":"#eb3510","headermarginBottom":30,"headerlayoutOption":"blockspare-style3","titlePaddingTop":10,"titleFontFamily":"Helvetica","titleFontWeight":"800","subTitleFontFamily":"Helvetica","imgURL":"https://blockspare.com/demo/default/charity/wp-content/uploads/sites/10/2021/10/map1.jpg","imgID":219,"imgAlt":"","opacityRatio":40,"ctaBackGroundColor":"#f0f4f5","buttonText":"Contact Us","buttonBackgroundColor":"#eb5310","buttonHoverEffect":"hover-style-2","paddingRight":0,"paddingLeft":0} -->
                        <div class="wp-block-blockspare-blockspare-call-to-action blockspare-a7b33dec-9187-4 alignfull blockspare-calltoaction" blockspare-animation=""><style>.blockspare-a7b33dec-9187-4 .blockspare-cta-wrapper{background-color:#f0f4f5;text-align:center;padding-top:20px;padding-right:0px;padding-bottom:20px;padding-left:0px;margin-top:30px;margin-right:0px;margin-bottom:30px;margin-left:0px;border-radius:null}.blockspare-a7b33dec-9187-4 .blockspare-block-button a.blockspare-button{padding-top:12px;padding-right:22px;padding-bottom:12px;padding-left:22px;margin-top:10px;margin-right:0px;margin-bottom:0px;margin-left:0px;color:#fff;border-width:2px;font-size:16px}.blockspare-a7b33dec-9187-4.wp-block-blockspare-blockspare-call-to-action .blockspare-block-button .blockspare-button{background-color:#eb5310}.blockspare-a7b33dec-9187-4.wp-block-blockspare-blockspare-call-to-action .blockspare-block-button .blockspare-button:visited{background-color:#eb5310}.blockspare-a7b33dec-9187-4.wp-block-blockspare-blockspare-call-to-action .blockspare-block-button .blockspare-button:focus{background-color:#eb5310}.blockspare-a7b33dec-9187-4 .blockspare-section-head-wrap{background-color:transparent;text-align:center;margin-top:5px;margin-right:0px;margin-bottom:30px;margin-left:0px}.blockspare-a7b33dec-9187-4 .blockspare-section-head-wrap .blockspare-title{color:#323230;padding-top:10px;padding-right:0px;padding-bottom:0px;padding-left:0px;font-size:24px;font-family:Helvetica;font-weight:800}.blockspare-a7b33dec-9187-4 .blockspare-section-head-wrap .blockspare-subtitle{color:#eb3510;font-size:14px;font-family:Helvetica;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px}@media screen and (max-width:1025px){.blockspare-a7b33dec-9187-4 .blockspare-block-button a.blockspare-button{font-size:14px}.blockspare-a7b33dec-9187-4 .blockspare-section-head-wrap .blockspare-title{font-size:22px}.blockspare-a7b33dec-9187-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}@media screen and (max-width:768px){.blockspare-a7b33dec-9187-4 .blockspare-block-button a.blockspare-button{font-size:14px}.blockspare-a7b33dec-9187-4 .blockspare-section-head-wrap .blockspare-title{font-size:20px}.blockspare-a7b33dec-9187-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}</style><div class="blockspare-cta-wrapper blockspare-blocks blockspare-hover-item"><div class="blockspare-image-wrap blockspare-cta-background has-background-opacity-40 has-background-opacity" style="background-image:url(https://blockspare.com/demo/default/charity/wp-content/uploads/sites/10/2021/10/map1.jpg)"></div><div class="blockspare-section-header-wrapper blockspare-blocks"><div class="blockspare-section-head-wrap blockspare-style3 blockspare-center"><div class="blockspare-title-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><h2 class="blockspare-title">Start Your Own Initiative</h2><span class="blockspare-title-dash blockspare-lower-dash"></span></div><div class="blockspare-subtitle-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><p class="blockspare-subtitle">Support Us</p><span class="blockspare-title-dash blockspare-lower-dash"></span></div></div></div><div class="blockspare-block-button"><a class="blockspare-button blockspare-button-shape-rounded blockspare-button-size-small hover-style-2"><span>Contact Us</span></a></div></div></div>
                        <!-- /wp:blockspare/blockspare-call-to-action --></div></div></div></div>
                        <!-- /wp:blockspare/blockspare-container -->',
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'charity',
                        'item'     => ['Charity','Tab','Services','Progress Bar'],
						'key'      => 'bs_section_104',
                        'imagePath'    => 'sections',
						'name'     => 'Charity Section 10',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/charity-section-10/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'charity',
                        'item'     => ['Charity','Services','Icon List'],
						'key'      => 'bs_section_105',
                        'imagePath'    => 'sections',
						'name'     => 'Charity Section 11',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/charity-section-11/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'charity',
                        'item'     => ['Charity','Services'],
						'key'      => 'bs_section_106',
                        'imagePath'    => 'sections',
						'name'     => 'Charity Section 12',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/charity-section-12/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'charity',
                        'item'     => ['Charity','Testimonial'],
						'key'      => 'bs_section_107',
                        'imagePath'    => 'sections',
						'name'     => 'Charity Section 13',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/charity-section-13/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'charity',
                        'item'     => ['Charity','Services'],
						'key'      => 'bs_section_108',
                        'imagePath'    => 'sections',
						'name'     => 'Charity Section 14',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/charity-section-14/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'charity',
                        'item'     => ['Charity','Post Grid','Post List'],
						'key'      => 'bs_section_109',
                        'imagePath'    => 'sections',
						'name'     => 'Charity Section 15',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/charity-section-15/",
						'content'  => '<!-- wp:blockspare/blockspare-container {"paddingTop":80,"paddingBottom":78,"marginTop":0,"marginBottom":0,"uniqueClass":"blockspare-80b0eca0-c651-4","backGroundColor":"#ffffff"} -->
                        <div class="wp-block-blockspare-blockspare-container alignfull blockspare-80b0eca0-c651-4" blockspare-animation=""><style>.blockspare-80b0eca0-c651-4 > .blockspare-block-container-wrapper{background-color:#ffffff;padding-top:80px;padding-right:20px;padding-bottom:78px;padding-left:20px;margin-top:0px;margin-right:0px;margin-bottom:0px;margin-left:0px;border-radius:0}.blockspare-80b0eca0-c651-4 .blockspare-image-wrap{background-image:none}</style><div class="blockspare-block-container-wrapper blockspare-hover-item"><div class="blockspare-container-background blockspare-image-wrap has-background-opacity-100 has-background-opacity"></div><div class="blockspare-container"><div class="blockspare-inner-blocks blockspare-inner-wrapper-blocks"><!-- wp:group {"align":"wide","layout":{"type":"constrained"}} -->
                        <div class="wp-block-group alignwide"><!-- wp:blockspare/blockspare-section-header {"uniqueClass":"blockspare-02582f7c-0e59-4","align":"wide","headerTitle":"How We served","headerSubTitle":"Our Blog","headersubtitleColor":"#eb5310","headermarginTop":0,"headerlayoutOption":"blockspare-style3","titleFontFamily":"Helvetica","titleFontWeight":"700","subTitleFontFamily":"Helvetica"} -->
                        <div class="wp-block-blockspare-blockspare-section-header alignwide blockspare-02582f7c-0e59-4 blockspare-section-header-wrapper blockspare-blocks alignwide" blockspare-animation=""><style>.blockspare-02582f7c-0e59-4 .blockspare-section-head-wrap{background-color:transparent;text-align:left;margin-top:0px;margin-right:0px;margin-bottom:30px;margin-left:0px}.blockspare-02582f7c-0e59-4 .blockspare-section-head-wrap .blockspare-title{color:#404040;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px;font-size:24px;font-family:Helvetica;font-weight:700}.blockspare-02582f7c-0e59-4 .blockspare-section-head-wrap .blockspare-subtitle{color:#eb5310;font-size:14px;font-family:Helvetica;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px}@media screen and (max-width:1025px){.blockspare-02582f7c-0e59-4 .blockspare-section-head-wrap .blockspare-title{font-size:22px}.blockspare-02582f7c-0e59-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}@media screen and (max-width:768px){.blockspare-02582f7c-0e59-4 .blockspare-section-head-wrap .blockspare-title{font-size:20px}.blockspare-02582f7c-0e59-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}</style><div class="blockspare-section-head-wrap blockspare-style3 blockspare-left blockspare-hover-item"><div class="blockspare-title-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><h2 class="blockspare-title">How We served</h2><span class="blockspare-title-dash blockspare-lower-dash"></span></div><div class="blockspare-subtitle-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><p class="blockspare-subtitle">Our Blog</p><span class="blockspare-title-dash blockspare-lower-dash"></span></div></div></div>
                        <!-- /wp:blockspare/blockspare-section-header -->
                        
                        <!-- wp:columns {"align":"wide"} -->
                        <div class="wp-block-columns alignwide"><!-- wp:column {"width":"66.66%"} -->
                        <div class="wp-block-column" style="flex-basis:66.66%"><!-- wp:blockspare/blockspare-latest-posts-grid {"uniqueClass":"blockspare-a39689ad-3a76-4","displayPostExcerpt":true,"displayPostAuthor":false,"postTitleFontSize":18,"titleFontFamily":"Helvetica","titleFontWeight":"700","linkColor":"#eb5310","generalColor":"#9ca9b5","imageSize":"medium","marginTop":0,"backGroundColor":"#f0f4f5","descriptionFontFamily":"Helvetica","enableEqualHeight":false} /-->
                        
                        <!-- wp:blockspare/blockspare-latest-posts-grid {"uniqueClass":"blockspare-175f1918-9d9a-4","displayPostExcerpt":true,"displayPostAuthor":false,"postTitleFontSize":18,"titleFontFamily":"Helvetica","titleFontWeight":"700","linkColor":"#eb5310","generalColor":"#9ca9b5","imageSize":"medium","backGroundColor":"#f0f4f5","descriptionFontFamily":"Helvetica","enableEqualHeight":false} /--></div>
                        <!-- /wp:column -->
                        
                        <!-- wp:column {"width":"33.33%"} -->
                        <div class="wp-block-column" style="flex-basis:33.33%"><!-- wp:blockspare/blockspare-section-header {"uniqueClass":"blockspare-75f92761-c87b-4","headerTitle":"Recent","headerSubTitle":"Our Recent Work","headersubtitleColor":"#eb5310","headermarginTop":0,"headerlayoutOption":"blockspare-style3","titleFontFamily":"Helvetica","titleFontWeight":"800","subTitleFontFamily":"Helvetica"} -->
                        <div class="wp-block-blockspare-blockspare-section-header aligncenter blockspare-75f92761-c87b-4 blockspare-section-header-wrapper blockspare-blocks aligncenter" blockspare-animation=""><style>.blockspare-75f92761-c87b-4 .blockspare-section-head-wrap{background-color:transparent;text-align:left;margin-top:0px;margin-right:0px;margin-bottom:30px;margin-left:0px}.blockspare-75f92761-c87b-4 .blockspare-section-head-wrap .blockspare-title{color:#404040;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px;font-size:24px;font-family:Helvetica;font-weight:800}.blockspare-75f92761-c87b-4 .blockspare-section-head-wrap .blockspare-subtitle{color:#eb5310;font-size:14px;font-family:Helvetica;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px}@media screen and (max-width:1025px){.blockspare-75f92761-c87b-4 .blockspare-section-head-wrap .blockspare-title{font-size:22px}.blockspare-75f92761-c87b-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}@media screen and (max-width:768px){.blockspare-75f92761-c87b-4 .blockspare-section-head-wrap .blockspare-title{font-size:20px}.blockspare-75f92761-c87b-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}</style><div class="blockspare-section-head-wrap blockspare-style3 blockspare-left blockspare-hover-item"><div class="blockspare-title-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><h2 class="blockspare-title">Recent</h2><span class="blockspare-title-dash blockspare-lower-dash"></span></div><div class="blockspare-subtitle-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><p class="blockspare-subtitle">Our Recent Work</p><span class="blockspare-title-dash blockspare-lower-dash"></span></div></div></div>
                        <!-- /wp:blockspare/blockspare-section-header -->
                        
                        <!-- wp:blockspare/blockspare-latest-posts-list {"uniqueClass":"blockspare-daa7fe21-0f77-4","displayPostAuthor":false,"titleFontFamily":"Helvetica","linkColor":"#eb5310","generalColor":"#9ca9b5","backGroundColor":"#f0f4f5","enableEqualHeight":false} /-->
                        
                        <!-- wp:blockspare/blockspare-latest-posts-list {"uniqueClass":"blockspare-8d24fad6-712d-4","displayPostAuthor":false,"titleFontFamily":"Helvetica","linkColor":"#eb5310","generalColor":"#9ca9b5","backGroundColor":"#f0f4f5","enableEqualHeight":false} /--></div>
                        <!-- /wp:column --></div>
                        <!-- /wp:columns --></div>
                        <!-- /wp:group --></div></div></div></div>
                        <!-- /wp:blockspare/blockspare-container -->',
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'charity',
                        'item'     => ['Charity','Services','Post List'],
						'key'      => 'bs_section_110',
                        'imagePath'    => 'sections',
						'name'     => 'Charity Section 16',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/charity-section-16/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'general',
                        'item'     => ['General','Post Banner'],
						'key'      => 'bs_section_111',
                        'imagePath'    => 'sections',
						'name'     => 'General Section 1',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/general-section-1/",
						'content'  => '<!-- wp:blockspare/blockspare-banner-2 {"uniqueClass":"blockspare-bf907d53-f5f2-4"} /-->',
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'general',
                        'item'     => ['General','Post Express Grid','Social Link','Call To Action'],
						'key'      => 'bs_section_112',
                        'imagePath'    => 'sections',
						'name'     => 'General Section 2',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/general-section-2/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'general',
                        'item'     => ['General','Post Grid'],
						'key'      => 'bs_section_113',
                        'imagePath'    => 'sections',
						'name'     => 'General Section 3',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/general-section-3/",
						'content'  => '<!-- wp:group {"align":"wide","layout":{"type":"default"}} -->
                        <div class="wp-block-group alignwide"><!-- wp:blockspare/blockspare-section-header {"uniqueClass":"blockspare-7572db41-b32c-4","align":"wide","headerTitle":"Popular","titleFontSize":14,"headermarginTop":0,"headermarginBottom":0,"headerlayoutOption":"blockspare-style18","dashColor":"#3a863d"} -->
                        <div class="wp-block-blockspare-blockspare-section-header alignwide blockspare-7572db41-b32c-4 blockspare-section-header-wrapper blockspare-blocks alignwide" blockspare-animation=""><style>.blockspare-7572db41-b32c-4 .blockspare-section-head-wrap{background-color:transparent;text-align:left;margin-top:0px;margin-right:0px;margin-bottom:0px;margin-left:0px}.blockspare-7572db41-b32c-4 .blockspare-section-head-wrap .blockspare-title{color:#fff;font-size:14px}.blockspare-7572db41-b32c-4 .blockspare-section-head-wrap .blockspare-title-wrapper{padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px}.blockspare-7572db41-b32c-4 .blockspare-section-head-wrap .blockspare-title-wrapper .blockspare-title{background-color:#3a863d!important}.blockspare-7572db41-b32c-4 .blockspare-section-head-wrap .blockspare-title-wrapper .blockspare-lower-dash{border-bottom-color:#3a863d!important}.blockspare-7572db41-b32c-4 .blockspare-section-head-wrap .blockspare-subtitle{color:#6d6d6d;font-size:14px;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px}@media screen and (max-width:1025px){.blockspare-7572db41-b32c-4 .blockspare-section-head-wrap .blockspare-title{font-size:22px}.blockspare-7572db41-b32c-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}@media screen and (max-width:768px){.blockspare-7572db41-b32c-4 .blockspare-section-head-wrap .blockspare-title{font-size:20px}.blockspare-7572db41-b32c-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}</style><div class="blockspare-section-head-wrap blockspare-style18 blockspare-left blockspare-hover-item"><div class="blockspare-title-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><h2 class="blockspare-title">Popular</h2><span class="blockspare-title-dash blockspare-lower-dash"></span></div><div class="blockspare-subtitle-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><span class="blockspare-title-dash blockspare-lower-dash"></span></div></div></div>
                        <!-- /wp:blockspare/blockspare-section-header -->
                        
                        <!-- wp:blockspare/latest-posts-block-carousel-grid {"uniqueClass":"blockspare-4baebe50-6da4-4","postsToShow":5,"grid":"blockspare-posts-block-grid-layout-3","align":"wide","categoryBackgroundColor":"#3a863d","numberofSlide":4,"titleOnHoverColor":"#3a863d"} /--></div>
                        <!-- /wp:group -->',
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'general',
                        'item'     => ['General','Post Grid','Post List'],
						'key'      => 'bs_section_114',
                        'imagePath'    => 'sections',
						'name'     => 'General Section 4',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/general-section-4/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'general',
                        'item'     => ['General','Post Grid','Post List','Post Author'],
						'key'      => 'bs_section_115',
                        'imagePath'    => 'sections',
						'name'     => 'General Section 5',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/general-section-5/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'general',
                        'item'     => ['General','Post List'],
						'key'      => 'bs_section_116',
                        'imagePath'    => 'sections',
						'name'     => 'General Section 6',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/general-section-6/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'general',
                        'item'     => ['General','Post List'],
						'key'      => 'bs_section_117',
                        'imagePath'    => 'sections',
						'name'     => 'General Section 7',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/general-section-7/",
						'content'  => '<!-- wp:group {"align":"wide","layout":{"type":"default"}} -->
                        <div class="wp-block-group alignwide"><!-- wp:blockspare/blockspare-section-header {"uniqueClass":"blockspare-75b5ef9a-bb83-4","align":"","headerTitle":"Latest","titleFontSize":14,"headermarginTop":0,"headermarginBottom":0,"headerlayoutOption":"blockspare-style18","dashColor":"#cf2e2e"} -->
                        <div class="wp-block-blockspare-blockspare-section-header blockspare-75b5ef9a-bb83-4 blockspare-section-header-wrapper blockspare-blocks align" blockspare-animation=""><style>.blockspare-75b5ef9a-bb83-4 .blockspare-section-head-wrap{background-color:transparent;text-align:left;margin-top:0px;margin-right:0px;margin-bottom:0px;margin-left:0px}.blockspare-75b5ef9a-bb83-4 .blockspare-section-head-wrap .blockspare-title{color:#fff;font-size:14px}.blockspare-75b5ef9a-bb83-4 .blockspare-section-head-wrap .blockspare-title-wrapper{padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px}.blockspare-75b5ef9a-bb83-4 .blockspare-section-head-wrap .blockspare-title-wrapper .blockspare-title{background-color:#cf2e2e!important}.blockspare-75b5ef9a-bb83-4 .blockspare-section-head-wrap .blockspare-title-wrapper .blockspare-lower-dash{border-bottom-color:#cf2e2e!important}.blockspare-75b5ef9a-bb83-4 .blockspare-section-head-wrap .blockspare-subtitle{color:#6d6d6d;font-size:14px;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px}@media screen and (max-width:1025px){.blockspare-75b5ef9a-bb83-4 .blockspare-section-head-wrap .blockspare-title{font-size:22px}.blockspare-75b5ef9a-bb83-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}@media screen and (max-width:768px){.blockspare-75b5ef9a-bb83-4 .blockspare-section-head-wrap .blockspare-title{font-size:20px}.blockspare-75b5ef9a-bb83-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}</style><div class="blockspare-section-head-wrap blockspare-style18 blockspare-left blockspare-hover-item"><div class="blockspare-title-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><h2 class="blockspare-title">Latest</h2><span class="blockspare-title-dash blockspare-lower-dash"></span></div><div class="blockspare-subtitle-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><span class="blockspare-title-dash blockspare-lower-dash"></span></div></div></div>
                        <!-- /wp:blockspare/blockspare-section-header -->
                        
                        <!-- wp:blockspare/blockspare-latest-posts-grid {"uniqueClass":"blockspare-bd89a165-0500-4","grid":"blockspare-posts-block-grid-layout-4","columns":4,"categoryBackgroundColor":"#cf2e2e","titleOnHoverColor":"#cf2e2e","enablePagination":true} /--></div>
                        <!-- /wp:group -->',
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'sport',
                        'item'     => ['Sport','Post Banner','Flash Post'],
						'key'      => 'bs_section_118',
                        'imagePath'    => 'sections',
						'name'     => 'Sport Section 1',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/sport-section-1/",
						'content'  => '<!-- wp:cover {"url":"https://blockspare.com/demo/default/sport-news/wp-content/uploads/sites/12/2022/01/pexels-fwstudio-131634-1.jpg","id":284,"dimRatio":50,"isDark":false,"align":"full"} -->
                        <div class="wp-block-cover alignfull is-light"><span aria-hidden="true" class="wp-block-cover__background has-background-dim"></span><img class="wp-block-cover__image-background wp-image-284" alt="" src="https://blockspare.com/demo/default/sport-news/wp-content/uploads/sites/12/2022/01/pexels-fwstudio-131634-1.jpg" data-object-fit="cover"/><div class="wp-block-cover__inner-container"><!-- wp:group {"layout":{"type":"constrained"}} -->
                        <div class="wp-block-group"><!-- wp:blockspare/latest-posts-flash {"uniqueClass":"blockspare-8b49d7fc-5639-4","postTitleColor":"#ffffff","backGroundColor":"#26512a","titleOnHoverColor":"#F6F6F6","exclusiveText":"Live Update","exclusiveSubtitle":true,"newsColor":"#ffffff"} /-->
                        
                        <!-- wp:blockspare/blockspare-banner-1 {"uniqueClass":"blockspare-0759c16a-a3f4-4"} /--></div>
                        <!-- /wp:group --></div></div>
                        <!-- /wp:cover -->',
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'sport',
                        'item'     => ['Sport','Posts'],
						'key'      => 'bs_section_119',
                        'imagePath'    => 'sections',
						'name'     => 'Sport Section 2',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/sport-section-2/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'sport',
                        'item'     => ['Sport','Post Express Grid'],
						'key'      => 'bs_section_120',
                        'imagePath'    => 'sections',
						'name'     => 'Sport Section 3',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/sport-section-3/",
						'content'  => '<!-- wp:group {"align":"wide","layout":{"type":"default"}} -->
                        <div class="wp-block-group alignwide"><!-- wp:blockspare/blockspare-section-header {"uniqueClass":"blockspare-8de52f2e-7617-4","headerTitle":"INTERNATIONAL","titleFontSize":18,"headerSubTitle":"Tournaments","headerlayoutOption":"blockspare-style10","titlePaddingBottom":5,"subtitlePaddingTop":5,"dashColor":"#689f38","titleFontSizeMobile":18,"titleFontSizeTablet":18} -->
                        <div class="wp-block-blockspare-blockspare-section-header aligncenter blockspare-8de52f2e-7617-4 blockspare-section-header-wrapper blockspare-blocks aligncenter" blockspare-animation=""><style>.blockspare-8de52f2e-7617-4 .blockspare-section-head-wrap{background-color:transparent;text-align:left;margin-top:30px;margin-right:0px;margin-bottom:30px;margin-left:0px}.blockspare-8de52f2e-7617-4 .blockspare-section-head-wrap .blockspare-title{color:#404040;padding-top:0px;padding-right:0px;padding-bottom:5px;padding-left:0px;font-size:18px}.blockspare-8de52f2e-7617-4 .blockspare-section-head-wrap .blockspare-title-wrapper .blockspare-title-dash.blockspare-lower-dash::before{background-color:#689f38!important}.blockspare-8de52f2e-7617-4 .blockspare-section-head-wrap .blockspare-title-wrapper .blockspare-lower-dash{background-color:#E5EFE3!important}.blockspare-8de52f2e-7617-4 .blockspare-section-head-wrap .blockspare-subtitle{color:#6d6d6d;font-size:14px;padding-top:5px;padding-right:0px;padding-bottom:0px;padding-left:0px}@media screen and (max-width:1025px){.blockspare-8de52f2e-7617-4 .blockspare-section-head-wrap .blockspare-title{font-size:18px}.blockspare-8de52f2e-7617-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}@media screen and (max-width:768px){.blockspare-8de52f2e-7617-4 .blockspare-section-head-wrap .blockspare-title{font-size:18px}.blockspare-8de52f2e-7617-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}</style><div class="blockspare-section-head-wrap blockspare-style10 blockspare-left blockspare-hover-item"><div class="blockspare-title-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><h2 class="blockspare-title">INTERNATIONAL</h2><span class="blockspare-title-dash blockspare-lower-dash"></span></div><div class="blockspare-subtitle-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><p class="blockspare-subtitle">Tournaments</p><span class="blockspare-title-dash blockspare-lower-dash"></span></div></div></div>
                        <!-- /wp:blockspare/blockspare-section-header -->
                        
                        <!-- wp:blockspare/latest-posts-express-grid {"uniqueClass":"blockspare-859ea2b0-520f-4","postsToShow":3,"postTitleFontSize":14,"express":"blockspare-posts-block-express-grid-layout-2","excerptLength":1,"spostTitleFontSize":20} /--></div>
                        <!-- /wp:group -->',
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'sport',
                        'item'     => ['Sport','Post Carousel'],
						'key'      => 'bs_section_121',
                        'imagePath'    => 'sections',
						'name'     => 'Sport Section 4',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/sport-section-4/",
						'content'  => '<!-- wp:group {"layout":{"type":"default"}} -->
                        <div class="wp-block-group"><!-- wp:blockspare/blockspare-section-header {"uniqueClass":"blockspare-02c53828-141f-4","headerTitle":"INTERNATIONAL","titleFontSize":18,"headerSubTitle":"Tournaments","headerlayoutOption":"blockspare-style10","titlePaddingBottom":5,"subtitlePaddingTop":5,"dashColor":"#689f38","titleFontSizeMobile":18,"titleFontSizeTablet":18} -->
                        <div class="wp-block-blockspare-blockspare-section-header aligncenter blockspare-02c53828-141f-4 blockspare-section-header-wrapper blockspare-blocks aligncenter" blockspare-animation=""><style>.blockspare-02c53828-141f-4 .blockspare-section-head-wrap{background-color:transparent;text-align:left;margin-top:30px;margin-right:0px;margin-bottom:30px;margin-left:0px}.blockspare-02c53828-141f-4 .blockspare-section-head-wrap .blockspare-title{color:#404040;padding-top:0px;padding-right:0px;padding-bottom:5px;padding-left:0px;font-size:18px}.blockspare-02c53828-141f-4 .blockspare-section-head-wrap .blockspare-title-wrapper .blockspare-title-dash.blockspare-lower-dash::before{background-color:#689f38!important}.blockspare-02c53828-141f-4 .blockspare-section-head-wrap .blockspare-title-wrapper .blockspare-lower-dash{background-color:#E5EFE3!important}.blockspare-02c53828-141f-4 .blockspare-section-head-wrap .blockspare-subtitle{color:#6d6d6d;font-size:14px;padding-top:5px;padding-right:0px;padding-bottom:0px;padding-left:0px}@media screen and (max-width:1025px){.blockspare-02c53828-141f-4 .blockspare-section-head-wrap .blockspare-title{font-size:18px}.blockspare-02c53828-141f-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}@media screen and (max-width:768px){.blockspare-02c53828-141f-4 .blockspare-section-head-wrap .blockspare-title{font-size:18px}.blockspare-02c53828-141f-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}</style><div class="blockspare-section-head-wrap blockspare-style10 blockspare-left blockspare-hover-item"><div class="blockspare-title-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><h2 class="blockspare-title">INTERNATIONAL</h2><span class="blockspare-title-dash blockspare-lower-dash"></span></div><div class="blockspare-subtitle-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><p class="blockspare-subtitle">Tournaments</p><span class="blockspare-title-dash blockspare-lower-dash"></span></div></div></div>
                        <!-- /wp:blockspare/blockspare-section-header -->
                        
                        <!-- wp:blockspare/latest-posts-block-carousel-grid {"uniqueClass":"blockspare-ac8bf3bc-4428-4"} /--></div>
                        <!-- /wp:group -->',
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'sport',
                        'item'     => ['Sport','Post Slider'],
						'key'      => 'bs_section_122',
                        'imagePath'    => 'sections',
						'name'     => 'Sport Section 5',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/sport-section-5/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'sport',
                        'item'     => ['Sport','Post List'],
						'key'      => 'bs_section_123',
                        'imagePath'    => 'sections',
						'name'     => 'Sport Section 6',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/sport-section-6/",
						'content'  => '<!-- wp:group {"layout":{"type":"default"}} -->
                        <div class="wp-block-group"><!-- wp:blockspare/blockspare-section-header {"uniqueClass":"blockspare-e4892522-f61f-4","headerTitle":"LATEST BLOG POST","titleFontSize":18,"headerSubTitle":"Tournaments","headerlayoutOption":"blockspare-style10","titlePaddingBottom":5,"subtitlePaddingTop":5,"dashColor":"#689f38","titleFontSizeMobile":18,"titleFontSizeTablet":18} -->
                        <div class="wp-block-blockspare-blockspare-section-header aligncenter blockspare-e4892522-f61f-4 blockspare-section-header-wrapper blockspare-blocks aligncenter" blockspare-animation=""><style>.blockspare-e4892522-f61f-4 .blockspare-section-head-wrap{background-color:transparent;text-align:left;margin-top:30px;margin-right:0px;margin-bottom:30px;margin-left:0px}.blockspare-e4892522-f61f-4 .blockspare-section-head-wrap .blockspare-title{color:#404040;padding-top:0px;padding-right:0px;padding-bottom:5px;padding-left:0px;font-size:18px}.blockspare-e4892522-f61f-4 .blockspare-section-head-wrap .blockspare-title-wrapper .blockspare-title-dash.blockspare-lower-dash::before{background-color:#689f38!important}.blockspare-e4892522-f61f-4 .blockspare-section-head-wrap .blockspare-title-wrapper .blockspare-lower-dash{background-color:#E5EFE3!important}.blockspare-e4892522-f61f-4 .blockspare-section-head-wrap .blockspare-subtitle{color:#6d6d6d;font-size:14px;padding-top:5px;padding-right:0px;padding-bottom:0px;padding-left:0px}@media screen and (max-width:1025px){.blockspare-e4892522-f61f-4 .blockspare-section-head-wrap .blockspare-title{font-size:18px}.blockspare-e4892522-f61f-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}@media screen and (max-width:768px){.blockspare-e4892522-f61f-4 .blockspare-section-head-wrap .blockspare-title{font-size:18px}.blockspare-e4892522-f61f-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}</style><div class="blockspare-section-head-wrap blockspare-style10 blockspare-left blockspare-hover-item"><div class="blockspare-title-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><h2 class="blockspare-title">LATEST BLOG POST</h2><span class="blockspare-title-dash blockspare-lower-dash"></span></div><div class="blockspare-subtitle-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><p class="blockspare-subtitle">Tournaments</p><span class="blockspare-title-dash blockspare-lower-dash"></span></div></div></div>
                        <!-- /wp:blockspare/blockspare-section-header -->
                        
                        <!-- wp:blockspare/blockspare-latest-posts-list {"uniqueClass":"blockspare-2140af94-64aa-4","displayPostExcerpt":true,"ImageUnit":"75"} /--></div>
                        <!-- /wp:group -->',
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'sport',
                        'item'     => ['Sport','Post Author'],
						'key'      => 'bs_section_124',
                        'imagePath'    => 'sections',
						'name'     => 'Sport Section 7',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/sport-section-7/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'sport',
                        'item'     => ['Sport','Post List'],
						'key'      => 'bs_section_125',
                        'imagePath'    => 'sections',
						'name'     => 'Sport Section 8',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/sport-section-8/",
						'content'  => '<!-- wp:columns {"align":"wide"} -->
                        <div class="wp-block-columns alignwide"><!-- wp:column {"width":"33.33%"} -->
                        <div class="wp-block-column" style="flex-basis:33.33%"><!-- wp:group {"layout":{"type":"default"}} -->
                        <div class="wp-block-group"><!-- wp:blockspare/blockspare-section-header {"uniqueClass":"blockspare-26ae24df-b3a3-4","headerTitle":"INTERNATIONAL","titleFontSize":18,"headerSubTitle":"Tournaments","headerlayoutOption":"blockspare-style10","titlePaddingBottom":5,"subtitlePaddingTop":5,"dashColor":"#689f38","titleFontSizeMobile":18,"titleFontSizeTablet":18} -->
                        <div class="wp-block-blockspare-blockspare-section-header aligncenter blockspare-26ae24df-b3a3-4 blockspare-section-header-wrapper blockspare-blocks aligncenter" blockspare-animation=""><style>.blockspare-26ae24df-b3a3-4 .blockspare-section-head-wrap{background-color:transparent;text-align:left;margin-top:30px;margin-right:0px;margin-bottom:30px;margin-left:0px}.blockspare-26ae24df-b3a3-4 .blockspare-section-head-wrap .blockspare-title{color:#404040;padding-top:0px;padding-right:0px;padding-bottom:5px;padding-left:0px;font-size:18px}.blockspare-26ae24df-b3a3-4 .blockspare-section-head-wrap .blockspare-title-wrapper .blockspare-title-dash.blockspare-lower-dash::before{background-color:#689f38!important}.blockspare-26ae24df-b3a3-4 .blockspare-section-head-wrap .blockspare-title-wrapper .blockspare-lower-dash{background-color:#E5EFE3!important}.blockspare-26ae24df-b3a3-4 .blockspare-section-head-wrap .blockspare-subtitle{color:#6d6d6d;font-size:14px;padding-top:5px;padding-right:0px;padding-bottom:0px;padding-left:0px}@media screen and (max-width:1025px){.blockspare-26ae24df-b3a3-4 .blockspare-section-head-wrap .blockspare-title{font-size:18px}.blockspare-26ae24df-b3a3-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}@media screen and (max-width:768px){.blockspare-26ae24df-b3a3-4 .blockspare-section-head-wrap .blockspare-title{font-size:18px}.blockspare-26ae24df-b3a3-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}</style><div class="blockspare-section-head-wrap blockspare-style10 blockspare-left blockspare-hover-item"><div class="blockspare-title-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><h2 class="blockspare-title">INTERNATIONAL</h2><span class="blockspare-title-dash blockspare-lower-dash"></span></div><div class="blockspare-subtitle-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><p class="blockspare-subtitle">Tournaments</p><span class="blockspare-title-dash blockspare-lower-dash"></span></div></div></div>
                        <!-- /wp:blockspare/blockspare-section-header -->
                        
                        <!-- wp:blockspare/blockspare-latest-posts-list {"uniqueClass":"blockspare-1589dd28-9a8f-4","displayPostDate":false,"displayPostAuthor":false,"postTitleFontSize":14,"displayPostCategory":false,"enableComment":false,"ImageUnit":"75"} /--></div>
                        <!-- /wp:group --></div>
                        <!-- /wp:column -->
                        
                        <!-- wp:column {"width":"66.67%"} -->
                        <div class="wp-block-column" style="flex-basis:66.67%"></div>
                        <!-- /wp:column --></div>
                        <!-- /wp:columns -->',
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'sport',
                        'item'     => ['Sport','Post Grid'],
						'key'      => 'bs_section_126',
                        'imagePath'    => 'sections',
						'name'     => 'Sport Section 9',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/sport-section-9/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'fashion',
                        'item'     => ['Fashion','Post Flash','Post Banner'],
						'key'      => 'bs_section_127',
                        'imagePath'    => 'sections',
						'name'     => 'Fashion Section 1',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/fashion-section-1/",
						'content'  => '<!-- wp:group {"align":"full","style":{"color":{"background":"#212121"},"spacing":{"padding":{"top":"20px","bottom":"0px"},"blockGap":"0"}},"layout":{"type":"constrained"}} -->
                        <div class="wp-block-group alignfull has-background" style="background-color:#212121;padding-top:20px;padding-bottom:0px"><!-- wp:blockspare/latest-posts-flash {"uniqueClass":"blockspare-f285e14e-be25-4","postTitleColor":"#e8eaed","exclusiveColor":"#ffffff","exclusiveBgColor":"#4db2ec","titleOnHoverColor":"#ffffff","exclusiveText":"Trending Now","exclusiveSubtitleText":"","newsBgColor":"#000000","background":false} /-->
                        
                        <!-- wp:group {"align":"full","style":{"spacing":{"blockGap":"0"}},"layout":{"type":"default"}} -->
                        <div class="wp-block-group alignfull"><!-- wp:cover {"url":"https://blockspare.com/demo/default/fashion-news/wp-content/uploads/sites/13/2022/02/pexels-igor-haritanovich-1695050.jpg","id":866,"dimRatio":50,"align":"full"} -->
                        <div class="wp-block-cover alignfull"><span aria-hidden="true" class="wp-block-cover__background has-background-dim"></span><img class="wp-block-cover__image-background wp-image-866" alt="" src="https://blockspare.com/demo/default/fashion-news/wp-content/uploads/sites/13/2022/02/pexels-igor-haritanovich-1695050.jpg" data-object-fit="cover"/><div class="wp-block-cover__inner-container"><!-- wp:group {"align":"wide","layout":{"type":"constrained"}} -->
                        <div class="wp-block-group alignwide"><!-- wp:blockspare/blockspare-banner-2 {"align":"full","uniqueClass":"blockspare-091b98b3-173e-4","bannerTwoLayout":"banner-style-3 has-bg-layout","sliderEnableNavInHover":false} /--></div>
                        <!-- /wp:group --></div></div>
                        <!-- /wp:cover --></div>
                        <!-- /wp:group --></div>
                        <!-- /wp:group -->',
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'fashion',
                        'item'     => ['Fashion','Post Grid'],
						'key'      => 'bs_section_128',
                        'imagePath'    => 'sections',
						'name'     => 'Fashion Section 2',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/fashion-section-2/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'fashion',
                        'item'     => ['Fashion','Posts'],
						'key'      => 'bs_section_129',
                        'imagePath'    => 'sections',
						'name'     => 'Fashion Section 3',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/fashion-section-3/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'fashion',
                        'item'     => ['Fashion','Posts'],
						'key'      => 'bs_section_130',
                        'imagePath'    => 'sections',
						'name'     => 'Fashion Section 4',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/fashion-section-4/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'fashion',
                        'item'     => ['Fashion','Post List','Post Grid','Post Author'],
						'key'      => 'bs_section_131',
                        'imagePath'    => 'sections',
						'name'     => 'Fashion Section 5',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/fashion-section-5/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'fashion',
                        'item'     => ['Fashion','Post List','Post Express Grid'],
						'key'      => 'bs_section_132',
                        'imagePath'    => 'sections',
						'name'     => 'Fashion Section 6',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/fashion-section-6/",
						'content'  => '<!-- wp:columns {"align":"wide","style":{"color":{"background":"#363636"}}} -->
                        <div class="wp-block-columns alignwide has-background" style="background-color:#363636"><!-- wp:column {"width":"66.66%"} -->
                        <div class="wp-block-column" style="flex-basis:66.66%"><!-- wp:group {"align":"wide","layout":{"type":"default"}} -->
                        <div class="wp-block-group alignwide"><!-- wp:blockspare/blockspare-section-header {"uniqueClass":"blockspare-acdafb79-38d7-4","align":"","headerTitle":"Trending","titleFontSize":14,"headermarginTop":0,"headermarginBottom":0,"headerlayoutOption":"blockspare-style18","dashColor":"#000000"} -->
                        <div class="wp-block-blockspare-blockspare-section-header blockspare-acdafb79-38d7-4 blockspare-section-header-wrapper blockspare-blocks align" blockspare-animation=""><style>.blockspare-acdafb79-38d7-4 .blockspare-section-head-wrap{background-color:transparent;text-align:left;margin-top:0px;margin-right:0px;margin-bottom:0px;margin-left:0px}.blockspare-acdafb79-38d7-4 .blockspare-section-head-wrap .blockspare-title{color:#fff;font-size:14px}.blockspare-acdafb79-38d7-4 .blockspare-section-head-wrap .blockspare-title-wrapper{padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px}.blockspare-acdafb79-38d7-4 .blockspare-section-head-wrap .blockspare-title-wrapper .blockspare-title{background-color:#000000!important}.blockspare-acdafb79-38d7-4 .blockspare-section-head-wrap .blockspare-title-wrapper .blockspare-lower-dash{border-bottom-color:#000000!important}.blockspare-acdafb79-38d7-4 .blockspare-section-head-wrap .blockspare-subtitle{color:#6d6d6d;font-size:14px;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px}@media screen and (max-width:1025px){.blockspare-acdafb79-38d7-4 .blockspare-section-head-wrap .blockspare-title{font-size:22px}.blockspare-acdafb79-38d7-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}@media screen and (max-width:768px){.blockspare-acdafb79-38d7-4 .blockspare-section-head-wrap .blockspare-title{font-size:20px}.blockspare-acdafb79-38d7-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}</style><div class="blockspare-section-head-wrap blockspare-style18 blockspare-left blockspare-hover-item"><div class="blockspare-title-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><h2 class="blockspare-title">Trending</h2><span class="blockspare-title-dash blockspare-lower-dash"></span></div><div class="blockspare-subtitle-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><span class="blockspare-title-dash blockspare-lower-dash"></span></div></div></div>
                        <!-- /wp:blockspare/blockspare-section-header -->
                        
                        <!-- wp:blockspare/latest-posts-express-grid {"uniqueClass":"blockspare-1055c4b2-82d4-4","postsToShow":3,"postTitleColor":"#e8eaed","postTitleFontSize":14,"linkColor":"#e8eaed","generalColor":"#e8eaed","express":"blockspare-posts-block-express-grid-layout-2","backGroundColor":"#000000","titleOnHoverColor":"#ffffff"} /--></div>
                        <!-- /wp:group --></div>
                        <!-- /wp:column -->
                        
                        <!-- wp:column {"width":"33.33%"} -->
                        <div class="wp-block-column" style="flex-basis:33.33%"><!-- wp:group {"layout":{"type":"default"}} -->
                        <div class="wp-block-group"><!-- wp:blockspare/blockspare-section-header {"uniqueClass":"blockspare-8febbc18-08b6-4","align":"","headerTitle":"Sport","titleFontSize":14,"headermarginTop":0,"headermarginBottom":0,"headerlayoutOption":"blockspare-style18","dashColor":"#000000"} -->
                        <div class="wp-block-blockspare-blockspare-section-header blockspare-8febbc18-08b6-4 blockspare-section-header-wrapper blockspare-blocks align" blockspare-animation=""><style>.blockspare-8febbc18-08b6-4 .blockspare-section-head-wrap{background-color:transparent;text-align:left;margin-top:0px;margin-right:0px;margin-bottom:0px;margin-left:0px}.blockspare-8febbc18-08b6-4 .blockspare-section-head-wrap .blockspare-title{color:#fff;font-size:14px}.blockspare-8febbc18-08b6-4 .blockspare-section-head-wrap .blockspare-title-wrapper{padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px}.blockspare-8febbc18-08b6-4 .blockspare-section-head-wrap .blockspare-title-wrapper .blockspare-title{background-color:#000000!important}.blockspare-8febbc18-08b6-4 .blockspare-section-head-wrap .blockspare-title-wrapper .blockspare-lower-dash{border-bottom-color:#000000!important}.blockspare-8febbc18-08b6-4 .blockspare-section-head-wrap .blockspare-subtitle{color:#6d6d6d;font-size:14px;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px}@media screen and (max-width:1025px){.blockspare-8febbc18-08b6-4 .blockspare-section-head-wrap .blockspare-title{font-size:22px}.blockspare-8febbc18-08b6-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}@media screen and (max-width:768px){.blockspare-8febbc18-08b6-4 .blockspare-section-head-wrap .blockspare-title{font-size:20px}.blockspare-8febbc18-08b6-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}</style><div class="blockspare-section-head-wrap blockspare-style18 blockspare-left blockspare-hover-item"><div class="blockspare-title-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><h2 class="blockspare-title">Sport</h2><span class="blockspare-title-dash blockspare-lower-dash"></span></div><div class="blockspare-subtitle-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><span class="blockspare-title-dash blockspare-lower-dash"></span></div></div></div>
                        <!-- /wp:blockspare/blockspare-section-header -->
                        
                        <!-- wp:blockspare/blockspare-latest-posts-list {"uniqueClass":"blockspare-a5987d94-c54a-4","displayPostDate":false,"displayPostAuthor":false,"postTitleColor":"#e8eaed","postTitleFontSize":14,"linkColor":"#e8eaed","displayPostCategory":false,"imageSize":"medium","backGroundColor":"#000000","enableComment":false,"titleOnHoverColor":"#ffffff","ImageUnit":"75"} /--></div>
                        <!-- /wp:group --></div>
                        <!-- /wp:column --></div>
                        <!-- /wp:columns -->',
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'fashion',
                        'item'     => ['Fashion','Posts'],
						'key'      => 'bs_section_133',
                        'imagePath'    => 'sections',
						'name'     => 'Fashion Section 7',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/fashion-section-7/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'fashion',
                        'item'     => ['Fashion','Post Grid'],
						'key'      => 'bs_section_134',
                        'imagePath'    => 'sections',
						'name'     => 'Fashion Section 8',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/fashion-section-8/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'recipe',
                        'item'     => ['Recipe','Post Banner'],
						'key'      => 'bs_section_135',
                        'imagePath'    => 'sections',
						'name'     => 'Recipe Section 1',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/recipe-section-1/",
						'content'  => '<!-- wp:blockspare/blockspare-banner-1 {"uniqueClass":"blockspare-8b53b7f2-e3c4-4","bannerOneLayout":"banner-style-3 has-bg-layout","sliderPostTitleColor":"#000000","sliderPostGeneralColor":"#000000","sliderPostLinkColor":"#000000","sliderTitleFontSize":36,"sliderTitleOnHoverColor":"#000000","editorPostTitleColor":"#000000","editorTitleFontSize":24,"editorTitleOnHoverColor":"#000000","sliderPostOverlayColor":"#ffffff","editorPostOverlayColor":"#ffffff"} /-->',
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'recipe',
                        'item'     => ['Recipe','Posts'],
						'key'      => 'bs_section_136',
                        'imagePath'    => 'sections',
						'name'     => 'Recipe Section 2',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/recipe-section-2/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'recipe',
                        'item'     => ['Recipe','Posts'],
						'key'      => 'bs_section_137',
                        'imagePath'    => 'sections',
						'name'     => 'Recipe Section 3',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/recipe-section-3/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'recipe',
                        'item'     => ['Recipe','Post List'],
						'key'      => 'bs_section_138',
                        'imagePath'    => 'sections',
						'name'     => 'Recipe Section 4',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/recipe-section-4/",
						'content'  => '<!-- wp:group {"align":"wide","style":{"spacing":{"blockGap":"0"}},"layout":{"type":"constrained"}} -->
                        <div class="wp-block-group alignwide"><!-- wp:blockspare/blockspare-section-header {"uniqueClass":"blockspare-1c364896-d245-4","align":"wide","headerTitle":"National","titleFontSize":22,"headermarginTop":0,"headermarginBottom":0,"headerlayoutOption":"blockspare-style10","dashColor":"#9b51e0"} -->
                        <div class="wp-block-blockspare-blockspare-section-header alignwide blockspare-1c364896-d245-4 blockspare-section-header-wrapper blockspare-blocks alignwide" blockspare-animation=""><style>.blockspare-1c364896-d245-4 .blockspare-section-head-wrap{background-color:transparent;text-align:left;margin-top:0px;margin-right:0px;margin-bottom:0px;margin-left:0px}.blockspare-1c364896-d245-4 .blockspare-section-head-wrap .blockspare-title{color:#404040;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px;font-size:22px}.blockspare-1c364896-d245-4 .blockspare-section-head-wrap .blockspare-title-wrapper .blockspare-title-dash.blockspare-lower-dash::before{background-color:#9b51e0!important}.blockspare-1c364896-d245-4 .blockspare-section-head-wrap .blockspare-title-wrapper .blockspare-lower-dash{background-color:#E5EFE3!important}.blockspare-1c364896-d245-4 .blockspare-section-head-wrap .blockspare-subtitle{color:#6d6d6d;font-size:14px;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px}@media screen and (max-width:1025px){.blockspare-1c364896-d245-4 .blockspare-section-head-wrap .blockspare-title{font-size:22px}.blockspare-1c364896-d245-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}@media screen and (max-width:768px){.blockspare-1c364896-d245-4 .blockspare-section-head-wrap .blockspare-title{font-size:20px}.blockspare-1c364896-d245-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}</style><div class="blockspare-section-head-wrap blockspare-style10 blockspare-left blockspare-hover-item"><div class="blockspare-title-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><h2 class="blockspare-title">National</h2><span class="blockspare-title-dash blockspare-lower-dash"></span></div><div class="blockspare-subtitle-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><span class="blockspare-title-dash blockspare-lower-dash"></span></div></div></div>
                        <!-- /wp:blockspare/blockspare-section-header -->
                        
                        <!-- wp:blockspare/blockspare-latest-posts-list {"uniqueClass":"blockspare-f0859b8d-e8f1-4","displayPostExcerpt":true,"postTitleFontSize":18,"align":"wide","categoryBackgroundColor":"#9b51e0","titleOnHoverColor":"#9b51e0"} /--></div>
                        <!-- /wp:group -->',
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'recipe',
                        'item'     => ['Recipe','Post Express Grid'],
						'key'      => 'bs_section_139',
                        'imagePath'    => 'sections',
						'name'     => 'Recipe Section 5',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/recipe-section-5/",
						'content'  => '<!-- wp:group {"layout":{"type":"default"}} -->
                        <div class="wp-block-group"><!-- wp:blockspare/blockspare-section-header {"uniqueClass":"blockspare-cad1825f-a70b-4","align":"wide","headerTitle":"Popular","titleFontSize":22,"headermarginTop":0,"headermarginBottom":0,"headerlayoutOption":"blockspare-style10","dashColor":"#3a863d"} -->
                        <div class="wp-block-blockspare-blockspare-section-header alignwide blockspare-cad1825f-a70b-4 blockspare-section-header-wrapper blockspare-blocks alignwide" blockspare-animation=""><style>.blockspare-cad1825f-a70b-4 .blockspare-section-head-wrap{background-color:transparent;text-align:left;margin-top:0px;margin-right:0px;margin-bottom:0px;margin-left:0px}.blockspare-cad1825f-a70b-4 .blockspare-section-head-wrap .blockspare-title{color:#404040;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px;font-size:22px}.blockspare-cad1825f-a70b-4 .blockspare-section-head-wrap .blockspare-title-wrapper .blockspare-title-dash.blockspare-lower-dash::before{background-color:#3a863d!important}.blockspare-cad1825f-a70b-4 .blockspare-section-head-wrap .blockspare-title-wrapper .blockspare-lower-dash{background-color:#E5EFE3!important}.blockspare-cad1825f-a70b-4 .blockspare-section-head-wrap .blockspare-subtitle{color:#6d6d6d;font-size:14px;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px}@media screen and (max-width:1025px){.blockspare-cad1825f-a70b-4 .blockspare-section-head-wrap .blockspare-title{font-size:22px}.blockspare-cad1825f-a70b-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}@media screen and (max-width:768px){.blockspare-cad1825f-a70b-4 .blockspare-section-head-wrap .blockspare-title{font-size:20px}.blockspare-cad1825f-a70b-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}</style><div class="blockspare-section-head-wrap blockspare-style10 blockspare-left blockspare-hover-item"><div class="blockspare-title-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><h2 class="blockspare-title">Popular</h2><span class="blockspare-title-dash blockspare-lower-dash"></span></div><div class="blockspare-subtitle-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><span class="blockspare-title-dash blockspare-lower-dash"></span></div></div></div>
                        <!-- /wp:blockspare/blockspare-section-header -->
                        
                        <!-- wp:blockspare/latest-posts-block-carousel-grid {"uniqueClass":"blockspare-60069c0d-0b3c-4","postsToShow":5,"align":"wide","categoryBackgroundColor":"#3a863d","titleOnHoverColor":"#3a863d"} /--></div>
                        <!-- /wp:group -->',
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'recipe',
                        'item'     => ['Recipe','Post Grid'],
						'key'      => 'bs_section_140',
                        'imagePath'    => 'sections',
						'name'     => 'Recipe Section 6',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/recipe-section-6/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'recipe',
                        'item'     => ['Recipe','Post Author'],
						'key'      => 'bs_section_141',
                        'imagePath'    => 'sections',
						'name'     => 'Recipe Section 7',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/recipe-section-7/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'recipe',
                        'item'     => ['Recipe','Post Grid'],
						'key'      => 'bs_section_142',
                        'imagePath'    => 'sections',
						'name'     => 'Recipe Section 8',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/recipe-section-8/",
						'content'  => '<!-- wp:group {"layout":{"type":"default"}} -->
                        <div class="wp-block-group"><!-- wp:blockspare/blockspare-section-header {"uniqueClass":"blockspare-5ecfba5e-f6a3-4","align":"","headerTitle":"Entertainment","titleFontSize":22,"headermarginTop":0,"headermarginBottom":0,"headerlayoutOption":"blockspare-style10","dashColor":"#000000"} -->
                        <div class="wp-block-blockspare-blockspare-section-header blockspare-5ecfba5e-f6a3-4 blockspare-section-header-wrapper blockspare-blocks align" blockspare-animation=""><style>.blockspare-5ecfba5e-f6a3-4 .blockspare-section-head-wrap{background-color:transparent;text-align:left;margin-top:0px;margin-right:0px;margin-bottom:0px;margin-left:0px}.blockspare-5ecfba5e-f6a3-4 .blockspare-section-head-wrap .blockspare-title{color:#404040;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px;font-size:22px}.blockspare-5ecfba5e-f6a3-4 .blockspare-section-head-wrap .blockspare-title-wrapper .blockspare-title-dash.blockspare-lower-dash::before{background-color:#000000!important}.blockspare-5ecfba5e-f6a3-4 .blockspare-section-head-wrap .blockspare-title-wrapper .blockspare-lower-dash{background-color:#E5EFE3!important}.blockspare-5ecfba5e-f6a3-4 .blockspare-section-head-wrap .blockspare-subtitle{color:#6d6d6d;font-size:14px;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px}@media screen and (max-width:1025px){.blockspare-5ecfba5e-f6a3-4 .blockspare-section-head-wrap .blockspare-title{font-size:22px}.blockspare-5ecfba5e-f6a3-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}@media screen and (max-width:768px){.blockspare-5ecfba5e-f6a3-4 .blockspare-section-head-wrap .blockspare-title{font-size:20px}.blockspare-5ecfba5e-f6a3-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}</style><div class="blockspare-section-head-wrap blockspare-style10 blockspare-left blockspare-hover-item"><div class="blockspare-title-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><h2 class="blockspare-title">Entertainment</h2><span class="blockspare-title-dash blockspare-lower-dash"></span></div><div class="blockspare-subtitle-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><span class="blockspare-title-dash blockspare-lower-dash"></span></div></div></div>
                        <!-- /wp:blockspare/blockspare-section-header -->
                        
                        <!-- wp:blockspare/blockspare-latest-posts-grid {"uniqueClass":"blockspare-2cf8b20f-7dd4-4","displayPostAuthor":false,"postTitleFontSize":14,"displayPostCategory":false,"enableComment":false} /--></div>
                        <!-- /wp:group -->',
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'recipe',
                        'item'     => ['Recipe','Post List'],
						'key'      => 'bs_section_143',
                        'imagePath'    => 'sections',
						'name'     => 'Recipe Section 9',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/recipe-section-9/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'recipe',
                        'item'     => ['Recipe','Social Link'],
						'key'      => 'bs_section_144',
                        'imagePath'    => 'sections',
						'name'     => 'Recipe Section 10',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/recipe-section-10/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'chinese',
                        'item'     => ['Chinese','Post Banner','Post Flash'],
						'key'      => 'bs_section_145',
                        'imagePath'    => 'sections',
						'name'     => 'Chinese Section 1',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/chinese-section-1/",
						'content'  => '<!-- wp:group {"align":"full","layout":{"type":"default"}} -->
                        <div class="wp-block-group alignfull"><!-- wp:group {"align":"wide","style":{"spacing":{"padding":{"top":"var:preset|spacing|30","bottom":"var:preset|spacing|30"}},"color":{"background":"#ece2cd"}},"layout":{"type":"constrained"}} -->
                        <div class="wp-block-group alignwide has-background" style="background-color:#ece2cd;padding-top:var(--wp--preset--spacing--30);padding-bottom:var(--wp--preset--spacing--30)"><!-- wp:blockspare/latest-posts-flash {"uniqueClass":"blockspare-2b61bce2-0e8d-4","postTitleColor":"#fcb900","exclusiveColor":"#ffffff","exclusiveBgColor":"#4db2ec","backGroundColor":"#000000","titleOnHoverColor":"#fcb900","exclusiveSubtitleText":"","newsBgColor":"#cf2e2e"} /-->
                        
                        <!-- wp:blockspare/blockspare-banner-2 {"uniqueClass":"blockspare-21608447-beee-4","bannerTwoLayout":"banner-style-3 has-bg-layout","sliderCategoryLayoutOption":"border","sliderPostTitleColor":"#000000","sliderPostGeneralColor":"#000000","sliderPostLinkColor":"#000000","sliderEnableNavInHover":false,"sliderTitleOnHoverColor":"#000000","editorCategoryLayoutOption":"border","editorCategoryTextColor":"#ffffff","editorCategoryBorderColor":"#ffffff","editorPostTitleColor":"#000000","editorTitleOnHoverColor":"#000000","sliderPostOverlayColor":"#fcb900","editorPostOverlayColor":"#fcb900","marginBottom":0} /--></div>
                        <!-- /wp:group --></div>
                        <!-- /wp:group -->',
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'chinese',
                        'item'     => ['Chinese','Posts'],
						'key'      => 'bs_section_146',
                        'imagePath'    => 'sections',
						'name'     => 'Chinese Section 2',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/chinese-section-2/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'chinese',
                        'item'     => ['Chinese','Post Carousel'],
						'key'      => 'bs_section_147',
                        'imagePath'    => 'sections',
						'name'     => 'Chinese Section 3',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/chinese-section-3/",
						'content'  => '<!-- wp:group {"align":"wide","layout":{"type":"default"}} -->
                        <div class="wp-block-group alignwide"><!-- wp:blockspare/blockspare-section-header {"uniqueClass":"blockspare-db110ede-d185-4","align":"wide","headerTitle":"Latest News","titleFontSize":14,"headertitleColorDark":"#fcb900","headermarginTop":0,"headermarginBottom":0,"headerlayoutOption":"blockspare-style17","dashColor":"#000000"} -->
                        <div class="wp-block-blockspare-blockspare-section-header alignwide blockspare-db110ede-d185-4 blockspare-section-header-wrapper blockspare-blocks alignwide" blockspare-animation=""><style>.blockspare-db110ede-d185-4 .blockspare-section-head-wrap{background-color:transparent;text-align:left;margin-top:0px;margin-right:0px;margin-bottom:0px;margin-left:0px}.blockspare-db110ede-d185-4 .blockspare-section-head-wrap .blockspare-title{color:#fcb900;font-size:14px}.blockspare-db110ede-d185-4 .blockspare-section-head-wrap .blockspare-title-wrapper{padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px}.blockspare-db110ede-d185-4 .blockspare-section-head-wrap .blockspare-title-wrapper .blockspare-title{background-color:#000000!important}.blockspare-db110ede-d185-4 .blockspare-section-head-wrap .blockspare-title-wrapper .blockspare-title:before{border-top-color:#000000!important}.blockspare-db110ede-d185-4 .blockspare-section-head-wrap .blockspare-subtitle{color:#6d6d6d;font-size:14px;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px}@media screen and (max-width:1025px){.blockspare-db110ede-d185-4 .blockspare-section-head-wrap .blockspare-title{font-size:22px}.blockspare-db110ede-d185-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}@media screen and (max-width:768px){.blockspare-db110ede-d185-4 .blockspare-section-head-wrap .blockspare-title{font-size:20px}.blockspare-db110ede-d185-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}</style><div class="blockspare-section-head-wrap blockspare-style17 blockspare-left blockspare-hover-item"><div class="blockspare-title-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><h2 class="blockspare-title">Latest News</h2><span class="blockspare-title-dash blockspare-lower-dash"></span></div><div class="blockspare-subtitle-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><span class="blockspare-title-dash blockspare-lower-dash"></span></div></div></div>
                        <!-- /wp:blockspare/blockspare-section-header -->
                        
                        <!-- wp:blockspare/latest-posts-block-carousel-grid {"uniqueClass":"blockspare-1a86880f-9592-4","postsToShow":5,"postTitleColor":"#fcb900","linkColor":"#fcb900","generalColor":"#fcb900","align":"wide","backGroundColor":"#000000","categoryLayoutOption":"border","numberofSlide":4,"titleOnHoverColor":"#fcb900"} /--></div>
                        <!-- /wp:group -->',
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'chinese',
                        'item'     => ['Chinese','Post Express Grid'],
						'key'      => 'bs_section_148',
                        'imagePath'    => 'sections',
						'name'     => 'Chinese Section 4',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/chinese-section-4/",
						'content'  => '<!-- wp:blockspare/latest-posts-express-grid {"categories":[{"value":3,"label":"light"}],"uniqueClass":"blockspare-60a05f23-427d-4","postsToShow":3,"postTitleColor":"#e8eaed","postTitleFontSize":14,"linkColor":"#e8eaed","generalColor":"#e8eaed","express":"blockspare-posts-block-express-grid-layout-2","backGroundColor":"#cf2e2e","titleOnHoverColor":"#ffffff"} /-->',
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'chinese',
                        'item'     => ['Chinese','Post List'],
						'key'      => 'bs_section_149',
                        'imagePath'    => 'sections',
						'name'     => 'Chinese Section 5',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/chinese-section-5/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'chinese',
                        'item'     => ['Chinese','Social Link'],
						'key'      => 'bs_section_150',
                        'imagePath'    => 'sections',
						'name'     => 'Chinese Section 6',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/chinese-section-6/",
						'content'  => '<!-- wp:group {"layout":{"type":"default"}} -->
                        <div class="wp-block-group"><!-- wp:blockspare/blockspare-section-header {"uniqueClass":"blockspare-0c45b23a-1140-4","align":"","headerTitle":"Stay Connected","titleFontSize":14,"headertitleColorDark":"#fcb900","headermarginTop":0,"headermarginBottom":0,"headerlayoutOption":"blockspare-style17","dashColor":"#000000"} -->
                        <div class="wp-block-blockspare-blockspare-section-header blockspare-0c45b23a-1140-4 blockspare-section-header-wrapper blockspare-blocks align" blockspare-animation=""><style>.blockspare-0c45b23a-1140-4 .blockspare-section-head-wrap{background-color:transparent;text-align:left;margin-top:0px;margin-right:0px;margin-bottom:0px;margin-left:0px}.blockspare-0c45b23a-1140-4 .blockspare-section-head-wrap .blockspare-title{color:#fcb900;font-size:14px}.blockspare-0c45b23a-1140-4 .blockspare-section-head-wrap .blockspare-title-wrapper{padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px}.blockspare-0c45b23a-1140-4 .blockspare-section-head-wrap .blockspare-title-wrapper .blockspare-title{background-color:#000000!important}.blockspare-0c45b23a-1140-4 .blockspare-section-head-wrap .blockspare-title-wrapper .blockspare-title:before{border-top-color:#000000!important}.blockspare-0c45b23a-1140-4 .blockspare-section-head-wrap .blockspare-subtitle{color:#6d6d6d;font-size:14px;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px}@media screen and (max-width:1025px){.blockspare-0c45b23a-1140-4 .blockspare-section-head-wrap .blockspare-title{font-size:22px}.blockspare-0c45b23a-1140-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}@media screen and (max-width:768px){.blockspare-0c45b23a-1140-4 .blockspare-section-head-wrap .blockspare-title{font-size:20px}.blockspare-0c45b23a-1140-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}</style><div class="blockspare-section-head-wrap blockspare-style17 blockspare-left blockspare-hover-item"><div class="blockspare-title-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><h2 class="blockspare-title">Stay Connected</h2><span class="blockspare-title-dash blockspare-lower-dash"></span></div><div class="blockspare-subtitle-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><span class="blockspare-title-dash blockspare-lower-dash"></span></div></div></div>
                        <!-- /wp:blockspare/blockspare-section-header -->
                        
                        <!-- wp:blockspare/blockspare-social-links {"sectionAlignment":"left","uniqueClass":"blockspare-4ceccc21-d457-4","youtubeUrl":"youtube.com","linkedinUrl":"linkedin.com","pinterestUrl":"pinterest.com","buttonOptions":"blockspare-icon-with-texts","iconColorOption":"custom","customfontColorOption":"#000000","custombackgroundColorOption":"#fcb900"} -->
                        <div class="wp-block-blockspare-blockspare-social-links blockspare-4ceccc21-d457-4 blockspare-socaillink-block blockspare-sociallinks-left" blockspare-animation=""><style>.blockspare-4ceccc21-d457-4 .blockspare-social-wrapper{text-align:left;margin-top:30px;margin-right:0px;margin-bottom:30px;margin-left:0px}.blockspare-4ceccc21-d457-4 .blockspare-social-wrapper .blockspare-social-links a .blockspare-social-icons{color:#000000;background-color:#fcb900}.blockspare-4ceccc21-d457-4 .blockspare-social-wrapper .blockspare-social-icons > span{font-size:16px}@media screen and (max-width:1025px){.blockspare-4ceccc21-d457-4 .blockspare-social-wrapper .blockspare-social-icons > span{font-size:16px}}@media screen and (max-width:768px){.blockspare-4ceccc21-d457-4 .blockspare-social-wrapper .blockspare-social-icons > span{font-size:14px}}</style><div class="blockspare-social-wrapper"><ul class="blockspare-social-links custom blockspare-social-icon-square blockspare-social-icon-small blockspare-icon-with-texts blockspare-social-icon-solid blockspare-social-links-horizontal"><li class="blockspare-hover-item"><a href="https://facebook.com" class="bs-social-facebook" target="_blank" rel="noopener noreferrer"><span class="blockspare-social-icons"><i class="fab fa-facebook-f"></i> <span class="screen-reader-text">Facebook</span></span></a></li><li class="blockspare-hover-item"><a href="https://twitter.com" class="bs-social-twitter" target="_blank" rel="noopener noreferrer"><span class="blockspare-social-icons"><i class="fab fa-twitter"></i><span class="screen-reader-text">Twitter</span></span></a></li><li class="blockspare-hover-item"><a href="https://instagram.com" class="bs-social-instagram" target="_blank" rel="noopener noreferrer"><span class="blockspare-social-icons"><i class="fab fa-instagram"></i><span class="screen-reader-text">Instagram</span></span></a></li><li class="blockspare-hover-item"><a href="youtube.com" class="bs-social-youtube" target="_blank" rel="noopener noreferrer"><span class="blockspare-social-icons"><i class="fab fa-youtube"></i><span class="screen-reader-text">YouTube</span></span></a></li><li class="blockspare-hover-item"><a href="linkedin.com" class="bs-social-linkedin" target="_blank" rel="noopener noreferrer"><span class="blockspare-social-icons"><i class="fab fa-linkedin"></i><span class="screen-reader-text">LinkedIn</span></span></a></li><li class="blockspare-hover-item"><a href="pinterest.com" class="bs-social-pinterest" target="_blank" rel="noopener noreferrer"><span class="blockspare-social-icons"><i class="fab fa-pinterest"></i><span class="screen-reader-text">Pinterest</span></span></a></li></ul></div></div>
                        <!-- /wp:blockspare/blockspare-social-links --></div>
                        <!-- /wp:group -->',
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'chinese',
                        'item'     => ['Chinese','Post Author'],
						'key'      => 'bs_section_151',
                        'imagePath'    => 'sections',
						'name'     => 'Chinese Section 7',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/chinese-section-7/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'chinese',
                        'item'     => ['Chinese','Post Grid'],
						'key'      => 'bs_section_152',
                        'imagePath'    => 'sections',
						'name'     => 'Chinese Section 8',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/chinese-section-8/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'chinese',
                        'item'     => ['Chinese','Post List'],
						'key'      => 'bs_section_153',
                        'imagePath'    => 'sections',
						'name'     => 'Chinese Section 9',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/chinese-section-9/",
						'content'  => '<!-- wp:group {"layout":{"type":"default"}} -->
                        <div class="wp-block-group"><!-- wp:blockspare/blockspare-section-header {"uniqueClass":"blockspare-ffd8de8d-28cf-4","align":"","headerTitle":"Sport","titleFontSize":14,"headertitleColorDark":"#fcb900","headermarginTop":0,"headermarginBottom":0,"headerlayoutOption":"blockspare-style17","dashColor":"#000000"} -->
                        <div class="wp-block-blockspare-blockspare-section-header blockspare-ffd8de8d-28cf-4 blockspare-section-header-wrapper blockspare-blocks align" blockspare-animation=""><style>.blockspare-ffd8de8d-28cf-4 .blockspare-section-head-wrap{background-color:transparent;text-align:left;margin-top:0px;margin-right:0px;margin-bottom:0px;margin-left:0px}.blockspare-ffd8de8d-28cf-4 .blockspare-section-head-wrap .blockspare-title{color:#fcb900;font-size:14px}.blockspare-ffd8de8d-28cf-4 .blockspare-section-head-wrap .blockspare-title-wrapper{padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px}.blockspare-ffd8de8d-28cf-4 .blockspare-section-head-wrap .blockspare-title-wrapper .blockspare-title{background-color:#000000!important}.blockspare-ffd8de8d-28cf-4 .blockspare-section-head-wrap .blockspare-title-wrapper .blockspare-title:before{border-top-color:#000000!important}.blockspare-ffd8de8d-28cf-4 .blockspare-section-head-wrap .blockspare-subtitle{color:#6d6d6d;font-size:14px;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px}@media screen and (max-width:1025px){.blockspare-ffd8de8d-28cf-4 .blockspare-section-head-wrap .blockspare-title{font-size:22px}.blockspare-ffd8de8d-28cf-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}@media screen and (max-width:768px){.blockspare-ffd8de8d-28cf-4 .blockspare-section-head-wrap .blockspare-title{font-size:20px}.blockspare-ffd8de8d-28cf-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}</style><div class="blockspare-section-head-wrap blockspare-style17 blockspare-left blockspare-hover-item"><div class="blockspare-title-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><h2 class="blockspare-title">Sport</h2><span class="blockspare-title-dash blockspare-lower-dash"></span></div><div class="blockspare-subtitle-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><span class="blockspare-title-dash blockspare-lower-dash"></span></div></div></div>
                        <!-- /wp:blockspare/blockspare-section-header -->
                        
                        <!-- wp:blockspare/blockspare-latest-posts-list {"uniqueClass":"blockspare-53fb7915-d77e-4","postsToShow":5,"displayPostDate":false,"displayPostAuthor":false,"postTitleColor":"#e8eaed","postTitleFontSize":14,"linkColor":"#e8eaed","displayPostCategory":false,"imageSize":"medium","backGroundColor":"#cf2e2e","enableComment":false,"titleOnHoverColor":"#ffffff","ImageUnit":"75"} /--></div>
                        <!-- /wp:group -->',
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'pet-care',
                        'item'     => ['Pet','Call To Action'],
						'key'      => 'bs_section_154',
                        'imagePath'    => 'sections',
						'name'     => 'Pet Section 1',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/pet-section-1/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'pet-care',
                        'item'     => ['Pet','Image Masonry'],
						'key'      => 'bs_section_155',
                        'imagePath'    => 'sections',
						'name'     => 'Pet Section 2',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/pet-section-2/",
						'content'  => '<!-- wp:blockspare/blockspare-container {"paddingTop":60,"paddingBottom":60,"marginTop":0,"marginBottom":0,"uniqueClass":"blockspare-4270213c-d607-4","backGroundColor":"#ffebca"} -->
                        <div class="wp-block-blockspare-blockspare-container alignfull blockspare-4270213c-d607-4" blockspare-animation=""><style>.blockspare-4270213c-d607-4 > .blockspare-block-container-wrapper{background-color:#ffebca;padding-top:60px;padding-right:20px;padding-bottom:60px;padding-left:20px;margin-top:0px;margin-right:0px;margin-bottom:0px;margin-left:0px;border-radius:0}.blockspare-4270213c-d607-4 .blockspare-image-wrap{background-image:none}</style><div class="blockspare-block-container-wrapper blockspare-hover-item"><div class="blockspare-container-background blockspare-image-wrap has-background-opacity-100 has-background-opacity"></div><div class="blockspare-container"><div class="blockspare-inner-blocks blockspare-inner-wrapper-blocks"><!-- wp:blockspare/blockspare-section-header {"uniqueClass":"blockspare-f96ef89a-60e4-4","align":"wide","sectionAlignment":"center","headerTitle":" WELCOME TO PET CARE","titleFontSize":62,"headertitleColor":"#ff6900","headersubtitleColor":"#ffffff","headermarginTop":0,"titleFontFamily":"Oxygen","titleFontWeight":"700","titleLoadGoogleFonts":true,"subTitleFontFamily":"Oxygen","subTitleLoadGoogleFonts":true} -->
                        <div class="wp-block-blockspare-blockspare-section-header alignwide blockspare-f96ef89a-60e4-4 blockspare-section-header-wrapper blockspare-blocks alignwide" blockspare-animation=""><style>.blockspare-f96ef89a-60e4-4 .blockspare-section-head-wrap{background-color:transparent;text-align:center;margin-top:0px;margin-right:0px;margin-bottom:30px;margin-left:0px}.blockspare-f96ef89a-60e4-4 .blockspare-section-head-wrap .blockspare-title{color:#ff6900;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px;font-size:62px;font-family:Oxygen;font-weight:700}.blockspare-f96ef89a-60e4-4 .blockspare-section-head-wrap .blockspare-subtitle{color:#ffffff;font-size:14px;font-family:Oxygen;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px}@media screen and (max-width:1025px){.blockspare-f96ef89a-60e4-4 .blockspare-section-head-wrap .blockspare-title{font-size:22px}.blockspare-f96ef89a-60e4-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}@media screen and (max-width:768px){.blockspare-f96ef89a-60e4-4 .blockspare-section-head-wrap .blockspare-title{font-size:20px}.blockspare-f96ef89a-60e4-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}</style><div class="blockspare-section-head-wrap blockspare-style1 blockspare-center blockspare-hover-item"><div class="blockspare-title-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><h2 class="blockspare-title"> WELCOME TO PET CARE</h2><span class="blockspare-title-dash blockspare-lower-dash"></span></div><div class="blockspare-subtitle-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><span class="blockspare-title-dash blockspare-lower-dash"></span></div></div></div>
                        <!-- /wp:blockspare/blockspare-section-header -->
                        
                        <!-- wp:group {"align":"wide","layout":{"type":"constrained"}} -->
                        <div class="wp-block-group alignwide"><!-- wp:blockspare/blockspare-masonry {"align":"wide","uniqueClass":"blockspare-b6f17b73-052c-4","gridSize":"lrg"} -->
                        <div class="blockspare-blocks blockspare-masonry-wrapper blockspare-original wp-block-blockspare-blockspare-masonry alignwide blockspare-b6f17b73-052c-4" blockspare-animation=""><style>.blockspare-b6f17b73-052c-4 .blockspare-gutter-wrap{padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px;margin-top:30px;margin-bottom:30px}</style><div class="has-gutter blockspare-gutter-wrap"><ul class="has-grid-lrg has-gutter-15"><li class="blockspare-gallery-item"><figure class="blockspare-gallery-figure blockspare-hover-item"><img src="https://blockspare.com/demo/default/petcare/wp-content/uploads/sites/21/2023/05/image-6.png" alt="" data-id="13" data-imglink="" data-link="https://blockspare.com/demo/default/petcare/home/image-6/" class="wp-image-13"/></figure></li><li class="blockspare-gallery-item"><figure class="blockspare-gallery-figure blockspare-hover-item"><img src="https://blockspare.com/demo/default/petcare/wp-content/uploads/sites/21/2023/05/image-8-683x1024.png" alt="" data-id="11" data-imglink="" data-link="https://blockspare.com/demo/default/petcare/home/image-8/" class="wp-image-11"/></figure></li><li class="blockspare-gallery-item"><figure class="blockspare-gallery-figure blockspare-hover-item"><img src="https://blockspare.com/demo/default/petcare/wp-content/uploads/sites/21/2023/05/image-7.png" alt="" data-id="12" data-imglink="" data-link="https://blockspare.com/demo/default/petcare/home/image-7/" class="wp-image-12"/></figure></li><li class="blockspare-gallery-item"><figure class="blockspare-gallery-figure blockspare-hover-item"><img src="https://blockspare.com/demo/default/petcare/wp-content/uploads/sites/21/2023/05/funny-dog-standing-hind-legs-733x1024.jpg" alt="" data-id="17" data-imglink="" data-link="https://blockspare.com/demo/default/petcare/home/funny-dog-standing-hind-legs/" class="wp-image-17"/></figure></li><li class="blockspare-gallery-item"><figure class="blockspare-gallery-figure blockspare-hover-item"><img src="https://blockspare.com/demo/default/petcare/wp-content/uploads/sites/21/2023/05/image-5-1024x682.png" alt="" data-id="14" data-imglink="" data-link="https://blockspare.com/demo/default/petcare/home/image-5/" class="wp-image-14"/></figure></li><li class="blockspare-gallery-item"><figure class="blockspare-gallery-figure blockspare-hover-item"><img src="https://blockspare.com/demo/default/petcare/wp-content/uploads/sites/21/2023/05/1happy-cute-dog-looking-away.jpg" alt="" data-id="15" data-imglink="" data-link="https://blockspare.com/demo/default/petcare/home/1happy-cute-dog-looking-away/" class="wp-image-15"/></figure></li><li class="blockspare-gallery-item"><figure class="blockspare-gallery-figure blockspare-hover-item"><img src="https://blockspare.com/demo/default/petcare/wp-content/uploads/sites/21/2023/05/friendly-smart-basenji-dog-giving-his-paw-close-up-isolated-white.jpg" alt="" data-id="18" data-imglink="" data-link="https://blockspare.com/demo/default/petcare/home/friendly-smart-basenji-dog-giving-his-paw-close-up-isolated-white/" class="wp-image-18"/></figure></li><li class="blockspare-gallery-item"><figure class="blockspare-gallery-figure blockspare-hover-item"><img src="https://blockspare.com/demo/default/petcare/wp-content/uploads/sites/21/2023/05/image-4-1024x684.png" alt="" data-id="20" data-imglink="" data-link="https://blockspare.com/demo/default/petcare/home/image-4/" class="wp-image-20"/></figure></li><li class="blockspare-gallery-item"><figure class="blockspare-gallery-figure blockspare-hover-item"><img src="https://blockspare.com/demo/default/petcare/wp-content/uploads/sites/21/2023/05/1beautiful-pet-portrait-dog.jpg" alt="" data-id="29" data-imglink="" data-link="https://blockspare.com/demo/default/petcare/home/1beautiful-pet-portrait-dog/" class="wp-image-29"/></figure></li><li class="blockspare-gallery-item"><figure class="blockspare-gallery-figure blockspare-hover-item"><img src="https://blockspare.com/demo/default/petcare/wp-content/uploads/sites/21/2023/05/1pleased-happy-afro-girl-gets-lovely-puppy-plays-embraces-four-legged-friend-with-love-stands-against-yellow-background-1024x448.jpg" alt="" data-id="10" data-imglink="" data-link="https://blockspare.com/demo/default/petcare/home/1pleased-happy-afro-girl-gets-lovely-puppy-plays-embraces-four-legged-friend-with-love-stands-against-yellow-background/" class="wp-image-10"/></figure></li></ul></div></div>
                        <!-- /wp:blockspare/blockspare-masonry --></div>
                        <!-- /wp:group --></div></div></div></div>
                        <!-- /wp:blockspare/blockspare-container -->',
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'pet-care',
                        'item'     => ['Pet','Content Box'],
						'key'      => 'bs_section_156',
                        'imagePath'    => 'sections',
						'name'     => 'Pet Section 3',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/pet-section-3/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'pet-care',
                        'item'     => ['Pet','Services'],
						'key'      => 'bs_section_157',
                        'imagePath'    => 'sections',
						'name'     => 'Pet Section 4',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/pet-section-4/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'pet-care',
                        'item'     => ['Pet','Counter'],
						'key'      => 'bs_section_158',
                        'imagePath'    => 'sections',
						'name'     => 'Pet Section 5',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/pet-section-5/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'pet-care',
                        'item'     => ['Pet','Testimonial'],
						'key'      => 'bs_section_159',
                        'imagePath'    => 'sections',
						'name'     => 'Pet Section 6',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/pet-section-6/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'pet-care',
                        'item'     => ['Pet','Pricing Table'],
						'key'      => 'bs_section_160',
                        'imagePath'    => 'sections',
						'name'     => 'Pet Section 7',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/pet-section-7/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'pet-care',
                        'item'     => ['Pet','Post Grid'],
						'key'      => 'bs_section_161',
                        'imagePath'    => 'sections',
						'name'     => 'Pet Section 8',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/pet-section-8/",
						'content'  => '<!-- wp:blockspare/blockspare-container {"paddingTop":100,"paddingBottom":100,"marginTop":0,"marginBottom":0,"uniqueClass":"blockspare-83486283-6dea-4","backGroundColor":"#ffebca"} -->
                        <div class="wp-block-blockspare-blockspare-container alignfull blockspare-83486283-6dea-4" blockspare-animation=""><style>.blockspare-83486283-6dea-4 > .blockspare-block-container-wrapper{background-color:#ffebca;padding-top:100px;padding-right:20px;padding-bottom:100px;padding-left:20px;margin-top:0px;margin-right:0px;margin-bottom:0px;margin-left:0px;border-radius:0}.blockspare-83486283-6dea-4 .blockspare-image-wrap{background-image:none}</style><div class="blockspare-block-container-wrapper blockspare-hover-item"><div class="blockspare-container-background blockspare-image-wrap has-background-opacity-100 has-background-opacity"></div><div class="blockspare-container"><div class="blockspare-inner-blocks blockspare-inner-wrapper-blocks"><!-- wp:group {"layout":{"type":"constrained"}} -->
                        <div class="wp-block-group"><!-- wp:blockspare/blockspare-section-header {"uniqueClass":"blockspare-d3596449-3276-4","align":"wide","sectionAlignment":"center","headerTitle":"OUR LATEST BLOG","titleFontSize":62,"headertitleColor":"#ff6900","headersubtitleColor":"#ffffff","headermarginTop":0,"titleFontFamily":"Oxygen","titleFontWeight":"700","titleLoadGoogleFonts":true} -->
                        <div class="wp-block-blockspare-blockspare-section-header alignwide blockspare-d3596449-3276-4 blockspare-section-header-wrapper blockspare-blocks alignwide" blockspare-animation=""><style>.blockspare-d3596449-3276-4 .blockspare-section-head-wrap{background-color:transparent;text-align:center;margin-top:0px;margin-right:0px;margin-bottom:30px;margin-left:0px}.blockspare-d3596449-3276-4 .blockspare-section-head-wrap .blockspare-title{color:#ff6900;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px;font-size:62px;font-family:Oxygen;font-weight:700}.blockspare-d3596449-3276-4 .blockspare-section-head-wrap .blockspare-subtitle{color:#ffffff;font-size:14px;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px}@media screen and (max-width:1025px){.blockspare-d3596449-3276-4 .blockspare-section-head-wrap .blockspare-title{font-size:22px}.blockspare-d3596449-3276-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}@media screen and (max-width:768px){.blockspare-d3596449-3276-4 .blockspare-section-head-wrap .blockspare-title{font-size:20px}.blockspare-d3596449-3276-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}</style><div class="blockspare-section-head-wrap blockspare-style1 blockspare-center blockspare-hover-item"><div class="blockspare-title-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><h2 class="blockspare-title">OUR LATEST BLOG</h2><span class="blockspare-title-dash blockspare-lower-dash"></span></div><div class="blockspare-subtitle-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><span class="blockspare-title-dash blockspare-lower-dash"></span></div></div></div>
                        <!-- /wp:blockspare/blockspare-section-header -->
                        
                        <!-- wp:blockspare/blockspare-latest-posts-grid {"categories":[{"value":3,"label":"light"}],"order":"asc","uniqueClass":"blockspare-2c8372c1-d5c5-4","postsToShow":3,"displayPostExcerpt":true,"postTitleFontSize":24,"titleFontFamily":"Oxygen","titleFontWeight":"700","titleLoadGoogleFonts":true,"grid":"blockspare-posts-block-grid-layout-4","columns":3,"align":"wide","descriptionFontFamily":"Oxygen","descriptionLoadGoogleFonts":true,"categoryBackgroundColor":"#ff6900"} /--></div>
                        <!-- /wp:group --></div></div></div></div>
                        <!-- /wp:blockspare/blockspare-container -->',
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'gadgets',
                        'item'     => ['Gadgets','Container'],
						'key'      => 'bs_section_162',
                        'imagePath'    => 'sections',
						'name'     => 'Gadgets Section 1',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/gadgets-section-1/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'gadgets',
                        'item'     => ['Gadgets','Image Masonry'],
						'key'      => 'bs_section_163',
                        'imagePath'    => 'sections',
						'name'     => 'Gadgets Section 2',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/gadgets-section-2/",
						'content'  => '<!-- wp:blockspare/blockspare-container {"paddingTop":100,"paddingBottom":100,"marginTop":0,"marginBottom":0,"uniqueClass":"blockspare-544498ef-fd38-4"} -->
                        <div class="wp-block-blockspare-blockspare-container alignfull blockspare-544498ef-fd38-4" blockspare-animation=""><style>.blockspare-544498ef-fd38-4 > .blockspare-block-container-wrapper{background-color:#f9f9f9;padding-top:100px;padding-right:20px;padding-bottom:100px;padding-left:20px;margin-top:0px;margin-right:0px;margin-bottom:0px;margin-left:0px;border-radius:0}.blockspare-544498ef-fd38-4 .blockspare-image-wrap{background-image:none}</style><div class="blockspare-block-container-wrapper blockspare-hover-item"><div class="blockspare-container-background blockspare-image-wrap has-background-opacity-100 has-background-opacity"></div><div class="blockspare-container"><div class="blockspare-inner-blocks blockspare-inner-wrapper-blocks"><!-- wp:group {"align":"wide","layout":{"type":"constrained"}} -->
                        <div class="wp-block-group alignwide"><!-- wp:blockspare/blockspare-section-header {"uniqueClass":"blockspare-4b0f0b60-e10f-4","align":"wide","sectionAlignment":"center","headerTitle":"Our Latest Gadgets","titleFontSize":42,"headermarginTop":0,"titleFontFamily":"Helvetica","titleFontWeight":"700","subTitleFontFamily":"Helvetica"} -->
                        <div class="wp-block-blockspare-blockspare-section-header alignwide blockspare-4b0f0b60-e10f-4 blockspare-section-header-wrapper blockspare-blocks alignwide" blockspare-animation=""><style>.blockspare-4b0f0b60-e10f-4 .blockspare-section-head-wrap{background-color:transparent;text-align:center;margin-top:0px;margin-right:0px;margin-bottom:30px;margin-left:0px}.blockspare-4b0f0b60-e10f-4 .blockspare-section-head-wrap .blockspare-title{color:#404040;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px;font-size:42px;font-family:Helvetica;font-weight:700}.blockspare-4b0f0b60-e10f-4 .blockspare-section-head-wrap .blockspare-subtitle{color:#6d6d6d;font-size:14px;font-family:Helvetica;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px}@media screen and (max-width:1025px){.blockspare-4b0f0b60-e10f-4 .blockspare-section-head-wrap .blockspare-title{font-size:22px}.blockspare-4b0f0b60-e10f-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}@media screen and (max-width:768px){.blockspare-4b0f0b60-e10f-4 .blockspare-section-head-wrap .blockspare-title{font-size:20px}.blockspare-4b0f0b60-e10f-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}</style><div class="blockspare-section-head-wrap blockspare-style1 blockspare-center blockspare-hover-item"><div class="blockspare-title-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><h2 class="blockspare-title">Our Latest Gadgets</h2><span class="blockspare-title-dash blockspare-lower-dash"></span></div><div class="blockspare-subtitle-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><span class="blockspare-title-dash blockspare-lower-dash"></span></div></div></div>
                        <!-- /wp:blockspare/blockspare-section-header -->
                        
                        <!-- wp:blockspare/blockspare-masonry {"linkTo":"media","target":"_blank","align":"wide","uniqueClass":"blockspare-d4b77cf0-a2dd-4","gridSize":"lrg","animation":"AFTfadeInUp"} -->
                        <div class="blockspare-blocks blockspare-masonry-wrapper blockspare-original wp-block-blockspare-blockspare-masonry alignwide blockspare-d4b77cf0-a2dd-4 blockspare-block-animation" blockspare-animation="AFTfadeInUp"><style>.blockspare-d4b77cf0-a2dd-4 .blockspare-gutter-wrap{padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px;margin-top:30px;margin-bottom:30px}</style><div class="has-gutter blockspare-gutter-wrap"><ul class="has-grid-lrg has-gutter-15"><li class="blockspare-gallery-item"><figure class="blockspare-gallery-figure blockspare-hover-item"><a href="https://blockspare.com/demo/default/gadgets/wp-content/uploads/sites/22/2023/05/close-up-hand-wearing-smartwatch-1-1024x681.jpg" target="_blank" rel="noreferrer noopener"><img src="https://blockspare.com/demo/default/gadgets/wp-content/uploads/sites/22/2023/05/close-up-hand-wearing-smartwatch-1-1024x681.jpg" alt="" data-id="11" data-imglink="" data-link="https://blockspare.com/demo/default/gadgets/home/close-up-hand-wearing-smartwatch-1/" class="wp-image-11"/></a></figure></li><li class="blockspare-gallery-item"><figure class="blockspare-gallery-figure blockspare-hover-item"><a href="https://blockspare.com/demo/default/gadgets/wp-content/uploads/sites/22/2023/05/mod678ern-stationary-collection-arrangement1.jpg" target="_blank" rel="noreferrer noopener"><img src="https://blockspare.com/demo/default/gadgets/wp-content/uploads/sites/22/2023/05/mod678ern-stationary-collection-arrangement1.jpg" alt="" data-id="9" data-imglink="" data-link="https://blockspare.com/demo/default/gadgets/home/mod678ern-stationary-collection-arrangement1/" class="wp-image-9"/></a></figure></li><li class="blockspare-gallery-item"><figure class="blockspare-gallery-figure blockspare-hover-item"><a href="https://blockspare.com/demo/default/gadgets/wp-content/uploads/sites/22/2023/05/electronic-device-balancing-concept-2-791x1024.jpg" target="_blank" rel="noreferrer noopener"><img src="https://blockspare.com/demo/default/gadgets/wp-content/uploads/sites/22/2023/05/electronic-device-balancing-concept-2-791x1024.jpg" alt="" data-id="13" data-imglink="" data-link="https://blockspare.com/demo/default/gadgets/home/electronic-device-balancing-concept-2/" class="wp-image-13"/></a></figure></li><li class="blockspare-gallery-item"><figure class="blockspare-gallery-figure blockspare-hover-item"><a href="https://blockspare.com/demo/default/gadgets/wp-content/uploads/sites/22/2023/05/electronic-device-balancing-concept-1-1-791x1024.jpg" target="_blank" rel="noreferrer noopener"><img src="https://blockspare.com/demo/default/gadgets/wp-content/uploads/sites/22/2023/05/electronic-device-balancing-concept-1-1-791x1024.jpg" alt="" data-id="14" data-imglink="" data-link="https://blockspare.com/demo/default/gadgets/home/electronic-device-balancing-concept-1-1/" class="wp-image-14"/></a></figure></li><li class="blockspare-gallery-item"><figure class="blockspare-gallery-figure blockspare-hover-item"><a href="https://blockspare.com/demo/default/gadgets/wp-content/uploads/sites/22/2023/05/r123etro-cameras.jpg" target="_blank" rel="noreferrer noopener"><img src="https://blockspare.com/demo/default/gadgets/wp-content/uploads/sites/22/2023/05/r123etro-cameras.jpg" alt="" data-id="16" data-imglink="" data-link="https://blockspare.com/demo/default/gadgets/home/r123etro-cameras/" class="wp-image-16"/></a></figure></li><li class="blockspare-gallery-item"><figure class="blockspare-gallery-figure blockspare-hover-item"><a href="https://blockspare.com/demo/default/gadgets/wp-content/uploads/sites/22/2023/05/high-angle-shot-lens-headphones-gimbal-phone-1-1024x683.jpg" target="_blank" rel="noreferrer noopener"><img src="https://blockspare.com/demo/default/gadgets/wp-content/uploads/sites/22/2023/05/high-angle-shot-lens-headphones-gimbal-phone-1-1024x683.jpg" alt="" data-id="15" data-imglink="" data-link="https://blockspare.com/demo/default/gadgets/home/high-angle-shot-lens-headphones-gimbal-phone-1/" class="wp-image-15"/></a></figure></li><li class="blockspare-gallery-item"><figure class="blockspare-gallery-figure blockspare-hover-item"><a href="https://blockspare.com/demo/default/gadgets/wp-content/uploads/sites/22/2023/05/headphones-stereo-equipment-single-object-technology-generated-by-ai-1-1024x585.jpg" target="_blank" rel="noreferrer noopener"><img src="https://blockspare.com/demo/default/gadgets/wp-content/uploads/sites/22/2023/05/headphones-stereo-equipment-single-object-technology-generated-by-ai-1-1024x585.jpg" alt="" data-id="12" data-imglink="" data-link="https://blockspare.com/demo/default/gadgets/home/headphones-stereo-equipment-single-object-technology-generated-by-ai-1/" class="wp-image-12"/></a></figure></li></ul></div></div>
                        <!-- /wp:blockspare/blockspare-masonry --></div>
                        <!-- /wp:group --></div></div></div></div>
                        <!-- /wp:blockspare/blockspare-container -->',
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'gadgets',
                        'item'     => ['Gadgets','Content Box'],
						'key'      => 'bs_section_164',
                        'imagePath'    => 'sections',
						'name'     => 'Gadgets Section 3',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/gadgets-section-3/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'gadgets',
                        'item'     => ['Gadgets','Services'],
						'key'      => 'bs_section_165',
                        'imagePath'    => 'sections',
						'name'     => 'Gadgets Section 4',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/gadgets-section-4/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'gadgets',
                        'item'     => ['Gadgets','Content Box'],
						'key'      => 'bs_section_166',
                        'imagePath'    => 'sections',
						'name'     => 'Gadgets Section 5',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/gadgets-section-5/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'gadgets',
                        'item'     => ['Gadgets','Testimonial'],
						'key'      => 'bs_section_167',
                        'imagePath'    => 'sections',
						'name'     => 'Gadgets Section 6',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/gadgets-section-6/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'gadgets',
                        'item'     => ['Gadgets','Counter'],
						'key'      => 'bs_section_168',
                        'imagePath'    => 'sections',
						'name'     => 'Gadgets Section 7',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/gadgets-section-7/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'gadgets',
                        'item'     => ['Gadgets','Post Grid'],
						'key'      => 'bs_section_169',
                        'imagePath'    => 'sections',
						'name'     => 'Gadgets Section 8',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/gadgets-section-8/",
						'content'  => '<!-- wp:blockspare/blockspare-container {"paddingTop":100,"paddingBottom":100,"marginTop":0,"marginBottom":0,"uniqueClass":"blockspare-ab5be341-0650-4"} -->
                        <div class="wp-block-blockspare-blockspare-container alignfull blockspare-ab5be341-0650-4" blockspare-animation=""><style>.blockspare-ab5be341-0650-4 > .blockspare-block-container-wrapper{background-color:#f9f9f9;padding-top:100px;padding-right:20px;padding-bottom:100px;padding-left:20px;margin-top:0px;margin-right:0px;margin-bottom:0px;margin-left:0px;border-radius:0}.blockspare-ab5be341-0650-4 .blockspare-image-wrap{background-image:none}</style><div class="blockspare-block-container-wrapper blockspare-hover-item"><div class="blockspare-container-background blockspare-image-wrap has-background-opacity-100 has-background-opacity"></div><div class="blockspare-container"><div class="blockspare-inner-blocks blockspare-inner-wrapper-blocks"><!-- wp:group {"align":"wide","layout":{"type":"constrained"}} -->
                        <div class="wp-block-group alignwide"><!-- wp:blockspare/blockspare-section-header {"uniqueClass":"blockspare-120c478a-ac86-4","align":"wide","sectionAlignment":"center","headerTitle":"What Our Latest Posts Are","titleFontSize":42,"headermarginTop":0,"titleFontFamily":"Helvetica","titleFontWeight":"700","subTitleFontFamily":"Helvetica"} -->
                        <div class="wp-block-blockspare-blockspare-section-header alignwide blockspare-120c478a-ac86-4 blockspare-section-header-wrapper blockspare-blocks alignwide" blockspare-animation=""><style>.blockspare-120c478a-ac86-4 .blockspare-section-head-wrap{background-color:transparent;text-align:center;margin-top:0px;margin-right:0px;margin-bottom:30px;margin-left:0px}.blockspare-120c478a-ac86-4 .blockspare-section-head-wrap .blockspare-title{color:#404040;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px;font-size:42px;font-family:Helvetica;font-weight:700}.blockspare-120c478a-ac86-4 .blockspare-section-head-wrap .blockspare-subtitle{color:#6d6d6d;font-size:14px;font-family:Helvetica;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px}@media screen and (max-width:1025px){.blockspare-120c478a-ac86-4 .blockspare-section-head-wrap .blockspare-title{font-size:22px}.blockspare-120c478a-ac86-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}@media screen and (max-width:768px){.blockspare-120c478a-ac86-4 .blockspare-section-head-wrap .blockspare-title{font-size:20px}.blockspare-120c478a-ac86-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}</style><div class="blockspare-section-head-wrap blockspare-style1 blockspare-center blockspare-hover-item"><div class="blockspare-title-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><h2 class="blockspare-title">What Our Latest Posts Are</h2><span class="blockspare-title-dash blockspare-lower-dash"></span></div><div class="blockspare-subtitle-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><span class="blockspare-title-dash blockspare-lower-dash"></span></div></div></div>
                        <!-- /wp:blockspare/blockspare-section-header -->
                        
                        <!-- wp:blockspare/blockspare-latest-posts-grid {"uniqueClass":"blockspare-43b77fde-1229-4","postsToShow":3,"displayPostExcerpt":true,"displayPostLink":false,"postTitleFontSize":22,"titleFontFamily":"Helvetica","generalColor":"#6d6d6d","grid":"blockspare-posts-block-grid-layout-4","columns":3,"align":"wide","descriptionFontFamily":"Helvetica","animation":"AFTfadeInUp"} /--></div>
                        <!-- /wp:group --></div></div></div></div>
                        <!-- /wp:blockspare/blockspare-container -->',
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'gadgets',
                        'item'     => ['Gadgets','Pricing Table'],
						'key'      => 'bs_section_170',
                        'imagePath'    => 'sections',
						'name'     => 'Gadgets Section 9',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/gadgets-section-9/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'gadgets',
                        'item'     => ['Gadgets','Logo Grid'],
						'key'      => 'bs_section_171',
                        'imagePath'    => 'sections',
						'name'     => 'Gadgets Section 10',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/gadgets-section-10/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'construction',
                        'item'     => ['Construction','Call To Action'],
						'key'      => 'bs_section_172',
                        'imagePath'    => 'sections',
						'name'     => 'Construction Section 1',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/construction-section-1/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'construction',
                        'item'     => ['Construction','Counter'],
						'key'      => 'bs_section_173',
                        'imagePath'    => 'sections',
						'name'     => 'Construction Section 2',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/construction-section-2/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'construction',
                        'item'     => ['Construction','Content Box'],
						'key'      => 'bs_section_174',
                        'imagePath'    => 'sections',
						'name'     => 'Construction Section 3',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/construction-section-3/",
						'content'  => '<!-- wp:blockspare/blockspare-container {"paddingTop":60,"paddingBottom":60,"marginBottom":0,"uniqueClass":"blockspare-adf3816b-ac1e-4"} -->
                        <div class="wp-block-blockspare-blockspare-container alignfull blockspare-adf3816b-ac1e-4" blockspare-animation=""><style>.blockspare-adf3816b-ac1e-4 > .blockspare-block-container-wrapper{background-color:#f9f9f9;padding-top:60px;padding-right:20px;padding-bottom:60px;padding-left:20px;margin-top:30px;margin-right:0px;margin-bottom:0px;margin-left:0px;border-radius:0}.blockspare-adf3816b-ac1e-4 .blockspare-image-wrap{background-image:none}</style><div class="blockspare-block-container-wrapper blockspare-hover-item"><div class="blockspare-container-background blockspare-image-wrap has-background-opacity-100 has-background-opacity"></div><div class="blockspare-container"><div class="blockspare-inner-blocks blockspare-inner-wrapper-blocks"><!-- wp:group {"align":"wide","layout":{"type":"constrained"}} -->
                        <div class="wp-block-group alignwide"><!-- wp:blockspare/content-box {"uniqueClass":"blockspare-0378ca4b-b884-4","align":"wide","sectionAlignment":"left","headerTitle":"We bring solutions to make life easier for our clients.","titleFontSize":36,"headerSubTitle":"WHY CHOOSE US?","headertitleColor":"#384260","headersubtitleColor":"#aaaaaa","titlePaddingBottom":10,"subtitlePaddingBottom":30,"titleFontFamily":"Helvetica","titleFontWeight":"600","subTitleFontFamily":"Helvetica","sectionDescription":"Cum sociis natoque penatibus et magnis dis parturient montes, nascetur ridiculus mus. Cras justo odio, dapibus ac facilisis in, egestas eget quam. Praesent commodo cursus magna, vel scelerisque nisl consectetur et.","imgURL":"https://blockspare.com/demo/default/construction/wp-content/uploads/sites/23/2023/05/civil-en321gineer-construction-worker-manager-holding-digital-tablet-blueprints-talking-planing-about-construction-site-cooperation-teamwork-concept.jpg","imgID":15,"descriptionColor":"#384260","design":"style-3","descriptionFontFamily":"Helvetica","animation":"AFTfadeIn"} -->
                        <div class="wp-block-blockspare-content-box blockspare-0378ca4b-b884-4 blockspare-contentBox alignwide blockspare-block-animation" blockspare-animation="AFTfadeIn"><style>.blockspare-0378ca4b-b884-4 .blockspare-content-wrapper{text-align:left;margin-top:30px;margin-right:0px;margin-bottom:30px;margin-left:0px}.blockspare-0378ca4b-b884-4 .blockspare-content-wrapper .blockspare-section-wrapper{padding-top:30px;padding-right:30px;padding-bottom:30px;padding-left:30px}.blockspare-0378ca4b-b884-4 .blockspare-desc-btn-wrap .blockspare-description{color:#384260;font-size:16px;font-family:Helvetica}.blockspare-0378ca4b-b884-4 .blockspare-section-header-wrapper{border-color:#8b249c}.blockspare-0378ca4b-b884-4 .blockspare-content-wrapper .blockspare-desc-btn-wrap .blockspare-description{margin-top:10px;margin-right:0px;margin-bottom:0px;margin-left:0px}.blockspare-0378ca4b-b884-4 .blockspare-section-head-wrap{background-color:transparent;text-align:left;margin-top:0px;margin-right:0px;margin-bottom:0px;margin-left:0px}.blockspare-0378ca4b-b884-4 .blockspare-block-button a.blockspare-button{color:#fff;border-width:2px;font-size:16px}.blockspare-0378ca4b-b884-4.wp-block-blockspare-content-box .blockspare-block-button .blockspare-button{background-color:var(--bgcolor)}.blockspare-0378ca4b-b884-4.wp-block-blockspare-content-box .blockspare-block-button .blockspare-button:visited{background-color:var(--bgcolor)}.blockspare-0378ca4b-b884-4.wp-block-blockspare-content-box .blockspare-block-button .blockspare-button:focus{background-color:var(--bgcolor)}.blockspare-0378ca4b-b884-4 .blockspare-section-head-wrap .blockspare-title{color:#384260;padding-top:0px;padding-right:0px;padding-bottom:10px;padding-left:0px;font-size:36px;font-family:Helvetica;font-weight:600}.blockspare-0378ca4b-b884-4 .blockspare-section-head-wrap .blockspare-subtitle{color:#aaaaaa;font-size:14px;font-family:Helvetica;padding-top:0px;padding-right:0px;padding-bottom:30px;padding-left:0px}@media screen and (max-width:1025px){.blockspare-0378ca4b-b884-4 .blockspare-block-button a.blockspare-button{font-size:14px}.blockspare-0378ca4b-b884-4 .blockspare-section-head-wrap .blockspare-title{font-size:22px}.blockspare-0378ca4b-b884-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}.blockspare-0378ca4b-b884-4 .blockspare-desc-btn-wrap .blockspare-description{font-size:16px}}@media screen and (max-width:768px){.blockspare-0378ca4b-b884-4 .blockspare-block-button a.blockspare-button{font-size:14px}.blockspare-0378ca4b-b884-4 .blockspare-section-head-wrap .blockspare-title{font-size:20px}.blockspare-0378ca4b-b884-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}.blockspare-0378ca4b-b884-4 .blockspare-desc-btn-wrap .blockspare-description{font-size:14px}}</style><div class="blockspare-content-wrapper blockspare-blocks blockspare-hover-item blockspare-hover-child style-3"><div class="content-img-wrap"><img src="https://blockspare.com/demo/default/construction/wp-content/uploads/sites/23/2023/05/civil-en321gineer-construction-worker-manager-holding-digital-tablet-blueprints-talking-planing-about-construction-site-cooperation-teamwork-concept.jpg" alt="" class=" hover-child"/></div><div class="blockspare-section-wrapper blockspare-mainheader-wrap "><div class="blockspare-section-head-wrap"><div class="blockspare-title-subtitle-wrap"><div class="blockspare-section-header-wrapper blockspare-blocks"><div class="blockspare-section-head-wrap blockspare-style1 blockspare-left"><div class="blockspare-title-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><h2 class="blockspare-title">We bring solutions to make life easier for our clients.</h2><span class="blockspare-title-dash blockspare-lower-dash"></span></div><div class="blockspare-subtitle-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><p class="blockspare-subtitle">WHY CHOOSE US?</p><span class="blockspare-title-dash blockspare-lower-dash"></span></div></div></div></div></div><div class="blockspare-desc-btn-wrap"><p class="blockspare-description">Cum sociis natoque penatibus et magnis dis parturient montes, nascetur ridiculus mus. Cras justo odio, dapibus ac facilisis in, egestas eget quam. Praesent commodo cursus magna, vel scelerisque nisl consectetur et.</p></div></div></div></div>
                        <!-- /wp:blockspare/content-box --></div>
                        <!-- /wp:group --></div></div></div></div>
                        <!-- /wp:blockspare/blockspare-container -->',
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'construction',
                        'item'     => ['Construction','Testimonial'],
						'key'      => 'bs_section_175',
                        'imagePath'    => 'sections',
						'name'     => 'Construction Section 4',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/construction-section-4/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'construction',
                        'item'     => ['Construction','Call To Action'],
						'key'      => 'bs_section_176',
                        'imagePath'    => 'sections',
						'name'     => 'Construction Section 5',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/construction-section-4/",
						'content'  => '<!-- wp:blockspare/blockspare-call-to-action {"uniqueClass":"blockspare-c2c5a7b2-2f02-4","align":"full","headerTitle":" Build Skills with Online Courses \u003cbr\u003efrom Expert Instructor ","titleFontSize":52,"headerSubTitle":" Start streaming on-demand video lectures today from top level \u003cbr\u003einstructors Attention heatmaps. ","headersubtitleColor":"#ffffffed","subtitlePaddingTop":20,"subtitlePaddingBottom":25,"titleFontFamily":"Helvetica","titleFontWeight":"800","titleFontSubset":"devanagari","subTitleFontFamily":"Helvetica","subTitleFontWeight":"400","subTitleFontSubset":"latin","imgURL":"https://blockspare.com/demo/default/construction/wp-content/uploads/sites/23/2023/05/hardhat-wearing-mewssn-work-together-build-factory-generated-by-ai.jpg","imgID":17,"imgAlt":"","opacityRatio":70,"buttonBackgroundColor":"#ffae00","buttonTextColor":"#384260","buttonShape":"blockspare-button-shape-square","buttonStyle":"solid","borderColor":"#ffffff","borderBtnTextColor":"#ffffff","btnBorderWidth":3,"buttonHoverEffect":"hover-style-2","buttonFontFamily":"Helvetica","buttonFontWeight":"600","buttonFontSubset":"vietnamese","marginTop":0,"marginBottom":0} -->
                        <div class="wp-block-blockspare-blockspare-call-to-action blockspare-c2c5a7b2-2f02-4 alignfull blockspare-calltoaction" blockspare-animation=""><style>.blockspare-c2c5a7b2-2f02-4 .blockspare-cta-wrapper{background-color:#0e0e0e;text-align:center;padding-top:20px;padding-right:20px;padding-bottom:20px;padding-left:20px;margin-top:0px;margin-right:0px;margin-bottom:0px;margin-left:0px;border-radius:null}.blockspare-c2c5a7b2-2f02-4 .blockspare-block-button a.blockspare-button{padding-top:12px;padding-right:22px;padding-bottom:12px;padding-left:22px;margin-top:10px;margin-right:0px;margin-bottom:0px;margin-left:0px;color:#ffffff;background-color:transparent;border-color:#ffffff;border-style:solid;border-width:3px;font-family:Helvetica;font-size:16px;font-weight:600}.blockspare-c2c5a7b2-2f02-4 .blockspare-section-head-wrap{background-color:transparent;text-align:center;margin-top:5px;margin-right:0px;margin-bottom:5px;margin-left:0px}.blockspare-c2c5a7b2-2f02-4 .blockspare-section-head-wrap .blockspare-title{color:#fff;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px;font-size:52px;font-family:Helvetica;font-weight:800}.blockspare-c2c5a7b2-2f02-4 .blockspare-section-head-wrap .blockspare-subtitle{color:#ffffffed;font-size:14px;font-family:Helvetica;font-weight:400;padding-top:20px;padding-right:0px;padding-bottom:25px;padding-left:0px}@media screen and (max-width:1025px){.blockspare-c2c5a7b2-2f02-4 .blockspare-block-button a.blockspare-button{font-size:14px}.blockspare-c2c5a7b2-2f02-4 .blockspare-section-head-wrap .blockspare-title{font-size:22px}.blockspare-c2c5a7b2-2f02-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}@media screen and (max-width:768px){.blockspare-c2c5a7b2-2f02-4 .blockspare-block-button a.blockspare-button{font-size:14px}.blockspare-c2c5a7b2-2f02-4 .blockspare-section-head-wrap .blockspare-title{font-size:20px}.blockspare-c2c5a7b2-2f02-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}</style><div class="blockspare-cta-wrapper blockspare-blocks blockspare-hover-item"><div class="blockspare-image-wrap blockspare-cta-background has-background-opacity-70 has-background-opacity" style="background-image:url(https://blockspare.com/demo/default/construction/wp-content/uploads/sites/23/2023/05/hardhat-wearing-mewssn-work-together-build-factory-generated-by-ai.jpg)"></div><div class="blockspare-section-header-wrapper blockspare-blocks"><div class="blockspare-section-head-wrap blockspare-style1 blockspare-center"><div class="blockspare-title-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><h2 class="blockspare-title"> Build Skills with Online Courses <br>from Expert Instructor </h2><span class="blockspare-title-dash blockspare-lower-dash"></span></div><div class="blockspare-subtitle-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><p class="blockspare-subtitle"> Start streaming on-demand video lectures today from top level <br>instructors Attention heatmaps. </p><span class="blockspare-title-dash blockspare-lower-dash"></span></div></div></div><div class="blockspare-block-button"><a class="blockspare-button blockspare-button-shape-square blockspare-button-size-small hover-style-2"><span>Get Started</span></a></div></div></div>
                        <!-- /wp:blockspare/blockspare-call-to-action -->',
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'construction',
                        'item'     => ['Construction','Services'],
						'key'      => 'bs_section_177',
                        'imagePath'    => 'sections',
						'name'     => 'Construction Section 6',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/construction-section-6/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'construction',
                        'item'     => ['Construction','Post Grid'],
						'key'      => 'bs_section_178',
                        'imagePath'    => 'sections',
						'name'     => 'Construction Section 7',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/construction-section-7/",
						'content'  => '<!-- wp:blockspare/blockspare-container {"paddingTop":60,"paddingBottom":60,"marginTop":0,"marginBottom":0,"uniqueClass":"blockspare-4f94fe56-716f-4","backGroundColor":"#ffffff"} -->
                        <div class="wp-block-blockspare-blockspare-container alignfull blockspare-4f94fe56-716f-4" blockspare-animation=""><style>.blockspare-4f94fe56-716f-4 > .blockspare-block-container-wrapper{background-color:#ffffff;padding-top:60px;padding-right:20px;padding-bottom:60px;padding-left:20px;margin-top:0px;margin-right:0px;margin-bottom:0px;margin-left:0px;border-radius:0}.blockspare-4f94fe56-716f-4 .blockspare-image-wrap{background-image:none}</style><div class="blockspare-block-container-wrapper blockspare-hover-item"><div class="blockspare-container-background blockspare-image-wrap has-background-opacity-100 has-background-opacity"></div><div class="blockspare-container"><div class="blockspare-inner-blocks blockspare-inner-wrapper-blocks"><!-- wp:group {"align":"wide","layout":{"type":"constrained"}} -->
                        <div class="wp-block-group alignwide"><!-- wp:blockspare/blockspare-section-header {"uniqueClass":"blockspare-b533ab80-75b2-4","sectionAlignment":"center","headerTitle":"Latest Blog Insights","titleFontSize":52,"headerSubTitle":"What Do We Works On","headertitleColor":"#384260","headersubtitleColor":"#aaaaaa","titleFontFamily":"Helvetica","titleFontWeight":"700","subTitleFontFamily":"Helvetica","animation":"AFTfadeIn"} -->
                        <div class="wp-block-blockspare-blockspare-section-header aligncenter blockspare-b533ab80-75b2-4 blockspare-section-header-wrapper blockspare-blocks aligncenter blockspare-block-animation" blockspare-animation="AFTfadeIn"><style>.blockspare-b533ab80-75b2-4 .blockspare-section-head-wrap{background-color:transparent;text-align:center;margin-top:30px;margin-right:0px;margin-bottom:30px;margin-left:0px}.blockspare-b533ab80-75b2-4 .blockspare-section-head-wrap .blockspare-title{color:#384260;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px;font-size:52px;font-family:Helvetica;font-weight:700}.blockspare-b533ab80-75b2-4 .blockspare-section-head-wrap .blockspare-subtitle{color:#aaaaaa;font-size:14px;font-family:Helvetica;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px}@media screen and (max-width:1025px){.blockspare-b533ab80-75b2-4 .blockspare-section-head-wrap .blockspare-title{font-size:22px}.blockspare-b533ab80-75b2-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}@media screen and (max-width:768px){.blockspare-b533ab80-75b2-4 .blockspare-section-head-wrap .blockspare-title{font-size:20px}.blockspare-b533ab80-75b2-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}</style><div class="blockspare-section-head-wrap blockspare-style1 blockspare-center blockspare-hover-item"><div class="blockspare-title-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><h2 class="blockspare-title">Latest Blog Insights</h2><span class="blockspare-title-dash blockspare-lower-dash"></span></div><div class="blockspare-subtitle-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><p class="blockspare-subtitle">What Do We Works On</p><span class="blockspare-title-dash blockspare-lower-dash"></span></div></div></div>
                        <!-- /wp:blockspare/blockspare-section-header -->
                        
                        <!-- wp:blockspare/blockspare-latest-posts-grid {"categories":[{"value":4,"label":"dark"}],"uniqueClass":"blockspare-9fc18558-aa36-4","postsToShow":3,"postTitleFontSize":24,"titleFontFamily":"Helvetica","titleFontWeight":"700","columns":3,"align":"wide","contentOrder":"content-order-2"} /--></div>
                        <!-- /wp:group --></div></div></div></div>
                        <!-- /wp:blockspare/blockspare-container -->',
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'construction',
                        'item'     => ['Construction','Pricing Table'],
						'key'      => 'bs_section_179',
                        'imagePath'    => 'sections',
						'name'     => 'Construction Section 8',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/construction-section-8/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'construction',
                        'item'     => ['Construction','Content Box'],
						'key'      => 'bs_section_180',
                        'imagePath'    => 'sections',
						'name'     => 'Construction Section 9',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/construction-section-9/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'construction',
                        'item'     => ['Construction','Logo Grid'],
						'key'      => 'bs_section_181',
                        'imagePath'    => 'sections',
						'name'     => 'Construction Section 10',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/construction-section-10/",
						'content'  => '<!-- wp:blockspare/blockspare-container {"paddingTop":60,"paddingBottom":60,"marginTop":0,"marginBottom":0,"color":"#fcb332","enableBottomSeperator":true,"bottomColor":"#000000","enableBottomGap":true,"uniqueClass":"blockspare-2ea96a35-286c-4","separatorEnable":true,"enableGap":true} -->
                        <div class="wp-block-blockspare-blockspare-container alignfull blockspare-2ea96a35-286c-4" blockspare-animation=""><style>.blockspare-2ea96a35-286c-4 > .blockspare-block-container-wrapper{background-color:#f9f9f9;padding-top:60px;padding-right:20px;padding-bottom:60px;padding-left:20px;margin-top:0px;margin-right:0px;margin-bottom:0px;margin-left:0px;border-radius:0}.blockspare-2ea96a35-286c-4 .blockspare-image-wrap{background-image:none}.blockspare-2ea96a35-286c-4 .blockspare-top-separator{top:0px;z-index:}.blockspare-2ea96a35-286c-4 .blockspare-block-container-wrapper .blockspare-top-svg-wrap{color:#fcb332;height:150px}.blockspare-2ea96a35-286c-4 .blockspare-block-container-wrapper .blockspare-bottom-separator{bottom:0px;z-index:}.blockspare-2ea96a35-286c-4 .blockspare-block-container-wrapper .blockspare-bottom-svg-wrap{color:#000000;height:150px}</style><div class="blockspare-block-container-wrapper has-gap-enable has-bottom-gap-enable has-separator-top has-separator-bottom blockspare-hover-item"><div class="blockspare-container-background blockspare-image-wrap has-background-opacity-100 has-background-opacity"></div><div class="blockspare-top-separator"><div class="blockspare-blocks blockspare-svg-wrapper blockspare-top-svg-wrap has-width-1 wp-block-blockspare-blockspare-container alignfull blockspare-2ea96a35-286c-4 is-vertically-flipped"><div class="blockspare-svg-svg-inner blockspare-separator-wrapper"><svg class="double-wave" preserveAspectRatio="none" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1946 175"><path class="st0" d="M-.5 27.7v146.8h1946V10.7s-170.6 20.4-265 57.2c0 0-374.1 116.7-794.2 24.7 0 0-414.1-100.9-673.1-92-.1 0-102.9 5.2-213.7 27.1z"></path><path class="st1" d="M1945.5 69.9s-425.5-100-888 20.5c0 0-342.6 63.3-611.4 43.8 0 0-224.9-40.3-446.6-84.4V174h1946V69.9z"></path><path d="M-.5 88s425.5-100 888 20.5c0 0 342.6 63.3 611.4 43.8 0 0 224.9-20.2 446.6-64.3v87H-.5V88z"></path></svg></div></div></div><div class="blockspare-container"><div class="blockspare-inner-blocks blockspare-inner-wrapper-blocks"><!-- wp:group {"align":"wide","layout":{"type":"constrained"}} -->
                        <div class="wp-block-group alignwide"><!-- wp:blockspare/blockspare-logos {"uniqueClass":"blockspare-a7267987-3436-4","align":"wide","images":[{"alt":"","id":22,"link":"https://blockspare.com/demo/default/construction/home/logoipsum-214/","caption":"","sizes":{"full":{"url":"https://blockspare.com/demo/default/construction/wp-content/uploads/sites/23/2023/05/logoipsum-214.png","height":46,"width":96,"orientation":"landscape"}},"url":"https://blockspare.com/demo/default/construction/wp-content/uploads/sites/23/2023/05/logoipsum-214.png","imgLink":""},{"alt":"","id":23,"link":"https://blockspare.com/demo/default/construction/home/logoipsum-219/","caption":"","sizes":{"full":{"url":"https://blockspare.com/demo/default/construction/wp-content/uploads/sites/23/2023/05/logoipsum-219.png","height":15,"width":150,"orientation":"landscape"}},"url":"https://blockspare.com/demo/default/construction/wp-content/uploads/sites/23/2023/05/logoipsum-219.png","imgLink":""},{"alt":"","id":24,"link":"https://blockspare.com/demo/default/construction/home/logoipsum-232/","caption":"","sizes":{"full":{"url":"https://blockspare.com/demo/default/construction/wp-content/uploads/sites/23/2023/05/logoipsum-232.png","height":47,"width":98,"orientation":"landscape"}},"url":"https://blockspare.com/demo/default/construction/wp-content/uploads/sites/23/2023/05/logoipsum-232.png","imgLink":""},{"alt":"","id":25,"link":"https://blockspare.com/demo/default/construction/home/logoipsum-287/","caption":"","sizes":{"full":{"url":"https://blockspare.com/demo/default/construction/wp-content/uploads/sites/23/2023/05/logoipsum-287.png","height":40,"width":105,"orientation":"landscape"}},"url":"https://blockspare.com/demo/default/construction/wp-content/uploads/sites/23/2023/05/logoipsum-287.png","imgLink":""},{"alt":"","id":26,"link":"https://blockspare.com/demo/default/construction/home/logoipsum-215/","caption":"","sizes":{"full":{"url":"https://blockspare.com/demo/default/construction/wp-content/uploads/sites/23/2023/05/logoipsum-215.png","height":34,"width":128,"orientation":"landscape"}},"url":"https://blockspare.com/demo/default/construction/wp-content/uploads/sites/23/2023/05/logoipsum-215.png","imgLink":""},{"alt":"","id":27,"link":"https://blockspare.com/demo/default/construction/home/logoipsum-221/","caption":"","sizes":{"full":{"url":"https://blockspare.com/demo/default/construction/wp-content/uploads/sites/23/2023/05/logoipsum-221.png","height":26,"width":115,"orientation":"landscape"}},"url":"https://blockspare.com/demo/default/construction/wp-content/uploads/sites/23/2023/05/logoipsum-221.png","imgLink":""},{"alt":"","id":28,"link":"https://blockspare.com/demo/default/construction/home/logoipsum-234/","caption":"","sizes":{"full":{"url":"https://blockspare.com/demo/default/construction/wp-content/uploads/sites/23/2023/05/logoipsum-234.png","height":46,"width":93,"orientation":"landscape"}},"url":"https://blockspare.com/demo/default/construction/wp-content/uploads/sites/23/2023/05/logoipsum-234.png","imgLink":""}],"columns":7,"gutter":80,"className":"alignwide"} -->
                        <div class="blockspare-blocks blockspare-logos-wrapper has-gap-80 has-colums-7 wp-block-blockspare-blockspare-logos alignwide blockspare-a7267987-3436-4" blockspare-animation=""><style>.blockspare-a7267987-3436-4 .blockspare-logo-grid-main{padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px;margin-top:30px;margin-right:0px;margin-bottom:30px;margin-left:0px}</style><div class="blockspare-logo-grid-main"><ul class="blockspare-logo-wrap"><li class="blockspare-gallery-item"><figure class="blockspare-gallery-figure blockspare-hover-item"><img src="https://blockspare.com/demo/default/construction/wp-content/uploads/sites/23/2023/05/logoipsum-214.png" alt="" data-id="22" data-imglink="" data-link="https://blockspare.com/demo/default/construction/home/logoipsum-214/" class="wp-image-22"/></figure></li><li class="blockspare-gallery-item"><figure class="blockspare-gallery-figure blockspare-hover-item"><img src="https://blockspare.com/demo/default/construction/wp-content/uploads/sites/23/2023/05/logoipsum-219.png" alt="" data-id="23" data-imglink="" data-link="https://blockspare.com/demo/default/construction/home/logoipsum-219/" class="wp-image-23"/></figure></li><li class="blockspare-gallery-item"><figure class="blockspare-gallery-figure blockspare-hover-item"><img src="https://blockspare.com/demo/default/construction/wp-content/uploads/sites/23/2023/05/logoipsum-232.png" alt="" data-id="24" data-imglink="" data-link="https://blockspare.com/demo/default/construction/home/logoipsum-232/" class="wp-image-24"/></figure></li><li class="blockspare-gallery-item"><figure class="blockspare-gallery-figure blockspare-hover-item"><img src="https://blockspare.com/demo/default/construction/wp-content/uploads/sites/23/2023/05/logoipsum-287.png" alt="" data-id="25" data-imglink="" data-link="https://blockspare.com/demo/default/construction/home/logoipsum-287/" class="wp-image-25"/></figure></li><li class="blockspare-gallery-item"><figure class="blockspare-gallery-figure blockspare-hover-item"><img src="https://blockspare.com/demo/default/construction/wp-content/uploads/sites/23/2023/05/logoipsum-215.png" alt="" data-id="26" data-imglink="" data-link="https://blockspare.com/demo/default/construction/home/logoipsum-215/" class="wp-image-26"/></figure></li><li class="blockspare-gallery-item"><figure class="blockspare-gallery-figure blockspare-hover-item"><img src="https://blockspare.com/demo/default/construction/wp-content/uploads/sites/23/2023/05/logoipsum-221.png" alt="" data-id="27" data-imglink="" data-link="https://blockspare.com/demo/default/construction/home/logoipsum-221/" class="wp-image-27"/></figure></li><li class="blockspare-gallery-item"><figure class="blockspare-gallery-figure blockspare-hover-item"><img src="https://blockspare.com/demo/default/construction/wp-content/uploads/sites/23/2023/05/logoipsum-234.png" alt="" data-id="28" data-imglink="" data-link="https://blockspare.com/demo/default/construction/home/logoipsum-234/" class="wp-image-28"/></figure></li></ul></div></div>
                        <!-- /wp:blockspare/blockspare-logos --></div>
                        <!-- /wp:group --></div></div><div class="blockspare-bottom-separator"><div class="blockspare-blocks blockspare-svg-wrapper blockspare-bottom-svg-wrap has-width-1 wp-block-blockspare-blockspare-container alignfull blockspare-2ea96a35-286c-4"><div class="blockspare-svg-svg-inner blockspare-separator-wrapper"><svg class="double-wave" preserveAspectRatio="none" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1946 175"><path class="st0" d="M-.5 27.7v146.8h1946V10.7s-170.6 20.4-265 57.2c0 0-374.1 116.7-794.2 24.7 0 0-414.1-100.9-673.1-92-.1 0-102.9 5.2-213.7 27.1z"></path><path class="st1" d="M1945.5 69.9s-425.5-100-888 20.5c0 0-342.6 63.3-611.4 43.8 0 0-224.9-40.3-446.6-84.4V174h1946V69.9z"></path><path d="M-.5 88s425.5-100 888 20.5c0 0 342.6 63.3 611.4 43.8 0 0 224.9-20.2 446.6-64.3v87H-.5V88z"></path></svg></div></div></div></div></div>
                        <!-- /wp:blockspare/blockspare-container -->',
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'florista',
                        'item'     => ['Florista','Call To Action'],
						'key'      => 'bs_section_182',
                        'imagePath'    => 'sections',
						'name'     => 'Florista Section 1',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/florista-section-1/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'florista',
                        'item'     => ['Florista','Container'],
						'key'      => 'bs_section_183',
                        'imagePath'    => 'sections',
						'name'     => 'Florista Section 2',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/florista-section-2/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'florista',
                        'item'     => ['Florista','Pricing Table'],
						'key'      => 'bs_section_184',
                        'imagePath'    => 'sections',
						'name'     => 'Florista Section 3',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/florista-section-3/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'florista',
                        'item'     => ['Florista','Content Box'],
						'key'      => 'bs_section_185',
                        'imagePath'    => 'sections',
						'name'     => 'Florista Section 4',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/florista-section-4/",
						'content'  => '<!-- wp:columns {"verticalAlignment":"center","align":"full"} -->
                        <div class="wp-block-columns alignfull are-vertically-aligned-center"><!-- wp:column {"verticalAlignment":"center","width":"60%","style":{"spacing":{"padding":{"top":"0px","right":"0px","bottom":"0px","left":"0px"}}}} -->
                        <div class="wp-block-column is-vertically-aligned-center" style="padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px;flex-basis:60%"><!-- wp:image {"id":35,"sizeSlug":"full","linkDestination":"none"} -->
                        <figure class="wp-block-image size-full"><img src="https://blockspare.com/demo/default/florista/wp-content/uploads/sites/24/2023/05/wooden-plant-shelf-against-blank-wall.png" alt="" class="wp-image-35"/></figure>
                        <!-- /wp:image --></div>
                        <!-- /wp:column -->
                        
                        <!-- wp:column {"verticalAlignment":"center","width":"40%","style":{"spacing":{"padding":{"top":"var:preset|spacing|40","right":"var:preset|spacing|60","bottom":"var:preset|spacing|40","left":"var:preset|spacing|60"}}}} -->
                        <div class="wp-block-column is-vertically-aligned-center" style="padding-top:var(--wp--preset--spacing--40);padding-right:var(--wp--preset--spacing--60);padding-bottom:var(--wp--preset--spacing--40);padding-left:var(--wp--preset--spacing--60);flex-basis:40%"><!-- wp:blockspare/blockspare-section-header {"uniqueClass":"blockspare-ed2e511d-0c19-4","headerTitle":"For People Who Love Plants","headerSubTitle":"Our Story","headerlayoutOption":"blockspare-style3","subtitlePaddingBottom":10,"titleFontFamily":"Antic Didone","titleFontWeight":"400","titleFontSubset":"latin","titleLoadGoogleFonts":true,"subTitleFontFamily":"Antic Didone","subTitleFontWeight":"400","subTitleFontSubset":"latin","subTitleLoadGoogleFonts":true,"animation":"AFTfadeInDown"} -->
                        <div class="wp-block-blockspare-blockspare-section-header aligncenter blockspare-ed2e511d-0c19-4 blockspare-section-header-wrapper blockspare-blocks aligncenter blockspare-block-animation" blockspare-animation="AFTfadeInDown"><style>.blockspare-ed2e511d-0c19-4 .blockspare-section-head-wrap{background-color:transparent;text-align:left;margin-top:30px;margin-right:0px;margin-bottom:30px;margin-left:0px}.blockspare-ed2e511d-0c19-4 .blockspare-section-head-wrap .blockspare-title{color:#404040;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px;font-size:24px;font-family:Antic Didone;font-weight:400}.blockspare-ed2e511d-0c19-4 .blockspare-section-head-wrap .blockspare-subtitle{color:#6d6d6d;font-size:14px;font-family:Antic Didone;font-weight:400;padding-top:0px;padding-right:0px;padding-bottom:10px;padding-left:0px}@media screen and (max-width:1025px){.blockspare-ed2e511d-0c19-4 .blockspare-section-head-wrap .blockspare-title{font-size:22px}.blockspare-ed2e511d-0c19-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}@media screen and (max-width:768px){.blockspare-ed2e511d-0c19-4 .blockspare-section-head-wrap .blockspare-title{font-size:20px}.blockspare-ed2e511d-0c19-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}</style><div class="blockspare-section-head-wrap blockspare-style3 blockspare-left blockspare-hover-item"><div class="blockspare-title-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><h2 class="blockspare-title">For People Who Love Plants</h2><span class="blockspare-title-dash blockspare-lower-dash"></span></div><div class="blockspare-subtitle-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><p class="blockspare-subtitle">Our Story</p><span class="blockspare-title-dash blockspare-lower-dash"></span></div></div></div>
                        <!-- /wp:blockspare/blockspare-section-header -->
                        
                        <!-- wp:paragraph {"style":{"color":{"text":"#999999"},"typography":{"fontSize":"14px"}}} -->
                        <p class="has-text-color" style="color:#999999;font-size:14px">Vel dolor quae metus accusantium? Nisl occaecati voluptas? Libero quas voluptatem quas repellat libero sapiente dolor mattis cupidatat illum tempora quidem adipiscing maxime occaecati, non aspernatur quidem iaculis, aptent voluptatum ea tempora aenean, quas mattis! Quisquam sagittis parturient, etiam eum tortor varius.</p>
                        <!-- /wp:paragraph -->
                        
                        <!-- wp:paragraph {"style":{"color":{"text":"#999999"},"typography":{"fontSize":"14px"}}} -->
                        <p class="has-text-color" style="color:#999999;font-size:14px">Proin ridiculus magna integer pellentesque placeat mauris quasi blanditiis accusamus magnam corrupti maiores, unde corrupti nascetur nihil eu, dolorem sit venenatis netus sagittis excepturi, cras condimentum etiam! Placeat hic eleifend, porttitor luctus.</p>
                        <!-- /wp:paragraph --></div>
                        <!-- /wp:column --></div>
                        <!-- /wp:columns -->',
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'florista',
                        'item'     => ['Florista','Testimonial'],
						'key'      => 'bs_section_186',
                        'imagePath'    => 'sections',
						'name'     => 'Florista Section 5',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/florista-section-5/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'florista',
                        'item'     => ['Florista','User Profile'],
						'key'      => 'bs_section_187',
                        'imagePath'    => 'sections',
						'name'     => 'Florista Section 6',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/florista-section-6/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'florista',
                        'item'     => ['Florista','Call To Action'],
						'key'      => 'bs_section_188',
                        'imagePath'    => 'sections',
						'name'     => 'Florista Section 7',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/florista-section-7/",
						'content'  => '<!-- wp:blockspare/blockspare-call-to-action {"uniqueClass":"blockspare-1fea4f9b-4575-4","align":"full","headerTitle":"Surprise with Gift of Greenery","headerSubTitle":"Awesome Gift Card","headerlayoutOption":"blockspare-style3","titlePaddingBottom":15,"subtitlePaddingBottom":20,"titleFontFamily":"Antic Didone","titleFontWeight":"400","titleFontSubset":"latin","titleLoadGoogleFonts":true,"subTitleFontFamily":"Antic Didone","subTitleFontWeight":"400","subTitleFontSubset":"latin","subTitleLoadGoogleFonts":true,"imgURL":"https://blockspare.com/demo/default/florista/wp-content/uploads/sites/24/2023/05/banner-bg-1.png","imgID":37,"imgAlt":"","buttonText":"Purchase A Gift Card","buttonBackgroundColor":"#ffffff","buttonTextColor":"#333333","buttonHoverEffect":"hover-style-2","buttonFontFamily":"Lato","buttonFontWeight":"400","buttonFontSubset":"latin","buttonLoadGoogleFonts":true,"paddingTop":10,"paddingRight":24,"paddingBottom":10,"paddingLeft":24,"marginTop":0,"marginBottom":0} -->
                        <div class="wp-block-blockspare-blockspare-call-to-action blockspare-1fea4f9b-4575-4 alignfull blockspare-calltoaction" blockspare-animation=""><style>.blockspare-1fea4f9b-4575-4 .blockspare-cta-wrapper{background-color:#0e0e0e;text-align:center;padding-top:10px;padding-right:24px;padding-bottom:10px;padding-left:24px;margin-top:0px;margin-right:0px;margin-bottom:0px;margin-left:0px;border-radius:null}.blockspare-1fea4f9b-4575-4 .blockspare-block-button a.blockspare-button{padding-top:12px;padding-right:22px;padding-bottom:12px;padding-left:22px;margin-top:10px;margin-right:0px;margin-bottom:0px;margin-left:0px;color:#333333;border-width:2px;font-family:Lato;font-size:16px;font-weight:400}.blockspare-1fea4f9b-4575-4.wp-block-blockspare-blockspare-call-to-action .blockspare-block-button .blockspare-button{background-color:#ffffff}.blockspare-1fea4f9b-4575-4.wp-block-blockspare-blockspare-call-to-action .blockspare-block-button .blockspare-button:visited{background-color:#ffffff}.blockspare-1fea4f9b-4575-4.wp-block-blockspare-blockspare-call-to-action .blockspare-block-button .blockspare-button:focus{background-color:#ffffff}.blockspare-1fea4f9b-4575-4 .blockspare-section-head-wrap{background-color:transparent;text-align:center;margin-top:5px;margin-right:0px;margin-bottom:5px;margin-left:0px}.blockspare-1fea4f9b-4575-4 .blockspare-section-head-wrap .blockspare-title{color:#fff;padding-top:0px;padding-right:0px;padding-bottom:15px;padding-left:0px;font-size:24px;font-family:Antic Didone;font-weight:400}.blockspare-1fea4f9b-4575-4 .blockspare-section-head-wrap .blockspare-subtitle{color:#fff;font-size:14px;font-family:Antic Didone;font-weight:400;padding-top:0px;padding-right:0px;padding-bottom:20px;padding-left:0px}@media screen and (max-width:1025px){.blockspare-1fea4f9b-4575-4 .blockspare-block-button a.blockspare-button{font-size:14px}.blockspare-1fea4f9b-4575-4 .blockspare-section-head-wrap .blockspare-title{font-size:22px}.blockspare-1fea4f9b-4575-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}@media screen and (max-width:768px){.blockspare-1fea4f9b-4575-4 .blockspare-block-button a.blockspare-button{font-size:14px}.blockspare-1fea4f9b-4575-4 .blockspare-section-head-wrap .blockspare-title{font-size:20px}.blockspare-1fea4f9b-4575-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}</style><div class="blockspare-cta-wrapper blockspare-blocks blockspare-hover-item"><div class="blockspare-image-wrap blockspare-cta-background has-background-opacity-80 has-background-opacity" style="background-image:url(https://blockspare.com/demo/default/florista/wp-content/uploads/sites/24/2023/05/banner-bg-1.png)"></div><div class="blockspare-section-header-wrapper blockspare-blocks"><div class="blockspare-section-head-wrap blockspare-style3 blockspare-center"><div class="blockspare-title-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><h2 class="blockspare-title">Surprise with Gift of Greenery</h2><span class="blockspare-title-dash blockspare-lower-dash"></span></div><div class="blockspare-subtitle-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><p class="blockspare-subtitle">Awesome Gift Card</p><span class="blockspare-title-dash blockspare-lower-dash"></span></div></div></div><div class="blockspare-block-button"><a class="blockspare-button blockspare-button-shape-rounded blockspare-button-size-small hover-style-2"><span>Purchase A Gift Card</span></a></div></div></div>
                        <!-- /wp:blockspare/blockspare-call-to-action -->',
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'florista',
                        'item'     => ['Florista','Call To Action'],
						'key'      => 'bs_section_189',
                        'imagePath'    => 'sections',
						'name'     => 'Florista Section 8',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/florista-section-8/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'florista',
                        'item'     => ['Florista','Counter'],
						'key'      => 'bs_section_190',
                        'imagePath'    => 'sections',
						'name'     => 'Florista Section 9',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/florista-section-9/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'autoservice',
                        'item'     => ['Auto Service','Call To Action'],
						'key'      => 'bs_section_191',
                        'imagePath'    => 'sections',
						'name'     => 'Auto Service Section 1',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/auto-service-section-1/",
						'content'  => '<!-- wp:blockspare/blockspare-container {"paddingTop":220,"paddingBottom":220,"marginTop":0,"marginBottom":0,"uniqueClass":"blockspare-bbd4edca-44a3-4","imgURL":"https://blockspare.com/demo/default/automotive/wp-content/uploads/sites/25/2023/05/image-9.png","imgID":11,"imgAlt":"","opacityRatio":30,"backGroundColor":"#000000"} -->
                        <div class="wp-block-blockspare-blockspare-container alignfull blockspare-bbd4edca-44a3-4" blockspare-animation=""><style>.blockspare-bbd4edca-44a3-4 > .blockspare-block-container-wrapper{background-color:#000000;padding-top:220px;padding-right:20px;padding-bottom:220px;padding-left:20px;margin-top:0px;margin-right:0px;margin-bottom:0px;margin-left:0px;border-radius:0}.blockspare-bbd4edca-44a3-4 .blockspare-image-wrap{background-image:url(https://blockspare.com/demo/default/automotive/wp-content/uploads/sites/25/2023/05/image-9.png)}</style><div class="blockspare-block-container-wrapper blockspare-hover-item"><div class="blockspare-container-background blockspare-image-wrap has-background-opacity-30 has-background-opacity"></div><div class="blockspare-container"><div class="blockspare-inner-blocks blockspare-inner-wrapper-blocks"><!-- wp:group {"align":"wide","layout":{"type":"constrained"}} -->
                        <div class="wp-block-group alignwide"><!-- wp:blockspare/blockspare-section-header {"uniqueClass":"blockspare-387eebc7-5fc2-4","align":"wide","headerTitle":"Your Vehicle is \u003cbr\u003eSafe with \u003cmark style=\u0022background-color:rgba(0, 0, 0, 0);color:#076eeb\u0022 class=\u0022has-inline-color\u0022\u003eUs\u003c/mark\u003e ","titleFontSize":42,"headerSubTitle":"WE ARE THE BEST","headertitleColor":"#ffffff","headersubtitleColor":"#ffffff","headermarginTop":0,"headerlayoutOption":"blockspare-style4","subtitlePaddingBottom":10,"dashColor":"#076eeb","titleFontFamily":"Montserrat","titleFontWeight":"700","titleLoadGoogleFonts":true,"subTitleFontFamily":"Montserrat","subTitleFontWeight":"600","subTitleLoadGoogleFonts":true} -->
                        <div class="wp-block-blockspare-blockspare-section-header alignwide blockspare-387eebc7-5fc2-4 blockspare-section-header-wrapper blockspare-blocks alignwide" blockspare-animation=""><style>.blockspare-387eebc7-5fc2-4 .blockspare-section-head-wrap{background-color:transparent;text-align:left;margin-top:0px;margin-right:0px;margin-bottom:30px;margin-left:0px}.blockspare-387eebc7-5fc2-4 .blockspare-section-head-wrap .blockspare-title{color:#ffffff;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px;font-size:42px;font-family:Montserrat;font-weight:700}.blockspare-387eebc7-5fc2-4 .blockspare-section-head-wrap .blockspare-title-wrapper .blockspare-title-dash{color:#076eeb!important}.blockspare-387eebc7-5fc2-4 .blockspare-section-head-wrap .blockspare-subtitle{color:#ffffff;font-size:14px;font-family:Montserrat;font-weight:600;padding-top:0px;padding-right:0px;padding-bottom:10px;padding-left:0px}@media screen and (max-width:1025px){.blockspare-387eebc7-5fc2-4 .blockspare-section-head-wrap .blockspare-title{font-size:22px}.blockspare-387eebc7-5fc2-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}@media screen and (max-width:768px){.blockspare-387eebc7-5fc2-4 .blockspare-section-head-wrap .blockspare-title{font-size:20px}.blockspare-387eebc7-5fc2-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}</style><div class="blockspare-section-head-wrap blockspare-style4 blockspare-left blockspare-hover-item"><div class="blockspare-title-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><h2 class="blockspare-title">Your Vehicle is <br>Safe with <mark style="background-color:rgba(0, 0, 0, 0);color:#076eeb" class="has-inline-color">Us</mark> </h2><span class="blockspare-title-dash blockspare-lower-dash"></span></div><div class="blockspare-subtitle-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><p class="blockspare-subtitle">WE ARE THE BEST</p><span class="blockspare-title-dash blockspare-lower-dash"></span></div></div></div>
                        <!-- /wp:blockspare/blockspare-section-header -->
                        
                        <!-- wp:blockspare/blockspare-buttons {"sectionAlignment":"left","uniqueClass":"blockspare-a7a7761a-1d01-4","buttonText":"Start Now","buttonBackgroundColor":"#076eeb","buttonFontFamily":"Montserrat","buttonFontWeight":"500","buttonLoadGoogleFonts":true,"align":"wide"} -->
                        <div class="wp-block-blockspare-blockspare-buttons alignwide blockspare-a7a7761a-1d01-4 blockspare-block-button-wrap" blockspare-animation=""><style>.blockspare-a7a7761a-1d01-4 .blockspare-block-button{text-align:left;margin-top:30px;margin-bottom:30px;margin-left:0px;margin-right:0px}.blockspare-a7a7761a-1d01-4 .blockspare-block-button span{color:#fff;border-width:2px;font-size:16px;font-family:Montserrat;font-weight:500}.blockspare-a7a7761a-1d01-4 .blockspare-block-button .blockspare-button{background-color:#076eeb}.blockspare-a7a7761a-1d01-4 .blockspare-block-button .blockspare-button:visited{background-color:#076eeb}.blockspare-a7a7761a-1d01-4 .blockspare-block-button .blockspare-button:focus{background-color:#076eeb}.blockspare-a7a7761a-1d01-4 .blockspare-block-button i{font-size:16px}.blockspare-a7a7761a-1d01-4 .blockspare-block-button a{padding-top:12px;padding-bottom:12px;padding-right:22px;padding-left:22px}@media screen and (max-width:1025px){.blockspare-a7a7761a-1d01-4 .blockspare-block-button span{font-size:14px}.blockspare-a7a7761a-1d01-4 .blockspare-block-button i{font-size:14px}}@media screen and (max-width:768px){.blockspare-a7a7761a-1d01-4 .blockspare-block-button span{font-size:14px}.blockspare-a7a7761a-1d01-4 .blockspare-block-button i{font-size:14px}}</style><div class="blockspare-block-button"><a class="blockspare-button blockspare-button-shape-rounded blockspare-button-size-small"><span>Start Now</span></a></div></div>
                        <!-- /wp:blockspare/blockspare-buttons --></div>
                        <!-- /wp:group --></div></div></div></div>
                        <!-- /wp:blockspare/blockspare-container -->',
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'autoservice',
                        'item'     => ['Auto Service','Services'],
						'key'      => 'bs_section_192',
                        'imagePath'    => 'sections',
						'name'     => 'Auto Service Section 2',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/auto-service-section-2/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'autoservice',
                        'item'     => ['Auto Service','Testimonial'],
						'key'      => 'bs_section_193',
                        'imagePath'    => 'sections',
						'name'     => 'Auto Service Section 3',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/auto-service-section-3/",
						'content'  => '<!-- wp:blockspare/blockspare-container {"paddingTop":60,"paddingBottom":60,"marginTop":0,"marginBottom":0,"uniqueClass":"blockspare-39fefb0d-285e-4","backGroundColor":"#ffffff"} -->
                        <div class="wp-block-blockspare-blockspare-container alignfull blockspare-39fefb0d-285e-4" blockspare-animation=""><style>.blockspare-39fefb0d-285e-4 > .blockspare-block-container-wrapper{background-color:#ffffff;padding-top:60px;padding-right:20px;padding-bottom:60px;padding-left:20px;margin-top:0px;margin-right:0px;margin-bottom:0px;margin-left:0px;border-radius:0}.blockspare-39fefb0d-285e-4 .blockspare-image-wrap{background-image:none}</style><div class="blockspare-block-container-wrapper blockspare-hover-item"><div class="blockspare-container-background blockspare-image-wrap has-background-opacity-100 has-background-opacity"></div><div class="blockspare-container"><div class="blockspare-inner-blocks blockspare-inner-wrapper-blocks"><!-- wp:group {"align":"wide","layout":{"type":"constrained"}} -->
                        <div class="wp-block-group alignwide"><!-- wp:blockspare/blockspare-section-header {"uniqueClass":"blockspare-fe530103-12e9-4","sectionAlignment":"center","headerTitle":"Few Words from \u003cmark style=\u0022background-color:rgba(0, 0, 0, 0);color:#076eeb\u0022 class=\u0022has-inline-color\u0022\u003eClients\u003c/mark\u003e","titleFontSize":42,"headerSubTitle":"TESTIMONIALS","headertitleColor":"#343750","headersubtitleColor":"#343750","headermarginTop":0,"headerlayoutOption":"blockspare-style4","subtitlePaddingBottom":10,"dashColor":"#076eeb","titleFontFamily":"Montserrat","titleFontWeight":"700","titleLoadGoogleFonts":true,"subTitleFontFamily":"Montserrat","subTitleFontWeight":"600","subTitleLoadGoogleFonts":true} -->
                        <div class="wp-block-blockspare-blockspare-section-header aligncenter blockspare-fe530103-12e9-4 blockspare-section-header-wrapper blockspare-blocks aligncenter" blockspare-animation=""><style>.blockspare-fe530103-12e9-4 .blockspare-section-head-wrap{background-color:transparent;text-align:center;margin-top:0px;margin-right:0px;margin-bottom:30px;margin-left:0px}.blockspare-fe530103-12e9-4 .blockspare-section-head-wrap .blockspare-title{color:#343750;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px;font-size:42px;font-family:Montserrat;font-weight:700}.blockspare-fe530103-12e9-4 .blockspare-section-head-wrap .blockspare-title-wrapper .blockspare-title-dash{color:#076eeb!important}.blockspare-fe530103-12e9-4 .blockspare-section-head-wrap .blockspare-subtitle{color:#343750;font-size:14px;font-family:Montserrat;font-weight:600;padding-top:0px;padding-right:0px;padding-bottom:10px;padding-left:0px}@media screen and (max-width:1025px){.blockspare-fe530103-12e9-4 .blockspare-section-head-wrap .blockspare-title{font-size:22px}.blockspare-fe530103-12e9-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}@media screen and (max-width:768px){.blockspare-fe530103-12e9-4 .blockspare-section-head-wrap .blockspare-title{font-size:20px}.blockspare-fe530103-12e9-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}</style><div class="blockspare-section-head-wrap blockspare-style4 blockspare-center blockspare-hover-item"><div class="blockspare-title-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><h2 class="blockspare-title">Few Words from <mark style="background-color:rgba(0, 0, 0, 0);color:#076eeb" class="has-inline-color">Clients</mark></h2><span class="blockspare-title-dash blockspare-lower-dash"></span></div><div class="blockspare-subtitle-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><p class="blockspare-subtitle">TESTIMONIALS</p><span class="blockspare-title-dash blockspare-lower-dash"></span></div></div></div>
                        <!-- /wp:blockspare/blockspare-section-header -->
                        
                        <!-- wp:blockspare/blockspare-testimonial {"columnsGap":2,"marginTop":0,"marginBottom":0,"uniqueClass":"blockspare-06c68ffc-31ea-4","animation":"AFTfadeInUp"} -->
                        <div class="wp-block-blockspare-blockspare-testimonial alignwide blockspare-06c68ffc-31ea-4 alignwide blockspare-block-animation" blockspare-animation="AFTfadeInUp"><div class="blockspare-main-testimonial-wrapper blockspare-item blockspare-testimonial-columns-3 blockspare-testimonial-gap-2 bs-layout-1"><!-- wp:blockspare/testimonial-item {"uniqueClass":"blockspare-85a868ce-948c-4","sectionAlignment":"center","headerTitle":"Alex Simpson","titleFontSize":20,"headerSubTitle":"Chief Executive Officer","headertitleColor":"#343750","headersubtitleColor":"#8e95a3","headermarginTop":20,"headermarginBottom":10,"headerTagOption":"h4","titleFontFamily":"Montserrat","titleFontWeight":"600","titleLoadGoogleFonts":true,"subTitleFontFamily":"Lato","subTitleLoadGoogleFonts":true,"testimonialDescription":"Nunc nulla. Mauris turpis nunc, blandit et, volutpat molestie, porta ut, ligula. Aliquam lorem ante, dapibus in, viverra quis, feugiat a, tellus. Aenean massa. Etiam sit amet orci eget eros faucibus tincidunt.","testimonialDescriptionTextColor":"#8e95a3","backGroundColor":"#F6F6F6","padding":20,"descriptionFontSize":14,"descriptionFontFamily":"Lato","descriptionFontWeight":"400","descriptionLoadGoogleFonts":true} -->
                        <div class="wp-block-blockspare-testimonial-item blockspare-85a868ce-948c-4 blockspare-testimonials textalignment-center"><style>.blockspare-85a868ce-948c-4 .blockspare-block-testimonial-wrap{background-color:#F6F6F6;border-width:1px;border-style:solid;border-color:#ececec;border-radius:0;padding:20px}.blockspare-85a868ce-948c-4 .blockspare-star-inner-container{gap:3px}.blockspare-85a868ce-948c-4 .blockspare-section-head-wrap{background-color:transparent;text-align:center;margin-top:20px;margin-right:0px;margin-bottom:10px;margin-left:0px}.blockspare-85a868ce-948c-4 .blockspare-section-head-wrap .blockspare-title{color:#343750;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px;font-size:20px;font-family:Montserrat;font-weight:600}.blockspare-85a868ce-948c-4 .blockspare-section-head-wrap .blockspare-subtitle{color:#8e95a3;font-size:14px;font-family:Lato;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px}.blockspare-85a868ce-948c-4 .blockspare-description{color:#8e95a3;font-size:14px;font-family:Lato;font-weight:400;text-align:center}@media screen and (max-width:1025px){.blockspare-85a868ce-948c-4 .blockspare-section-head-wrap .blockspare-title{font-size:22px}.blockspare-85a868ce-948c-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}.blockspare-85a868ce-948c-4 .blockspare-description{font-size:14px}}@media screen and (max-width:768px){.blockspare-85a868ce-948c-4 .blockspare-section-head-wrap .blockspare-title{font-size:20px}.blockspare-85a868ce-948c-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}.blockspare-85a868ce-948c-4 .blockspare-description{font-size:14px}}</style><div class="blockspare-block-testimonial-wrap blockspare-hover-item"><div class="blockspare-author-wrap"><div class="blockspare-img-wrapper"><!-- wp:image {"align":"center","id":13,"sizeSlug":"thumbnail","linkDestination":"none","className":"is-style-rounded"} -->
                        <figure class="wp-block-image aligncenter size-thumbnail is-style-rounded"><img src="https://blockspare.com/demo/default/automotive/wp-content/uploads/sites/25/2023/05/image-7-e1685358663744-150x150.png" alt="" class="wp-image-13"/></figure>
                        <!-- /wp:image --></div></div><div class="blockspare-author-designation"><div class="blockspare-section-header-wrapper blockspare-blocks aligncenter"><div class="blockspare-section-head-wrap blockspare-style1 blockspare-center"><div class="blockspare-title-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><h4 class="blockspare-title">Alex Simpson</h4><span class="blockspare-title-dash blockspare-lower-dash"></span></div><div class="blockspare-subtitle-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><p class="blockspare-subtitle">Chief Executive Officer</p><span class="blockspare-title-dash blockspare-lower-dash"></span></div></div></div></div><div class="blockspare-testimonial-content"><p class="blockspare-description">Nunc nulla. Mauris turpis nunc, blandit et, volutpat molestie, porta ut, ligula. Aliquam lorem ante, dapibus in, viverra quis, feugiat a, tellus. Aenean massa. Etiam sit amet orci eget eros faucibus tincidunt.</p></div></div></div>
                        <!-- /wp:blockspare/testimonial-item -->
                        
                        <!-- wp:blockspare/testimonial-item {"uniqueClass":"blockspare-9057630e-1f32-4","sectionAlignment":"center","headerTitle":"Alex Simpson","titleFontSize":20,"headerSubTitle":"Chief Executive Officer","headertitleColor":"#343750","headersubtitleColor":"#8e95a3","headermarginTop":20,"headermarginBottom":10,"headerTagOption":"h4","titleFontFamily":"Montserrat","titleFontWeight":"600","titleLoadGoogleFonts":true,"subTitleFontFamily":"Lato","subTitleLoadGoogleFonts":true,"testimonialDescription":"Nunc nulla. Mauris turpis nunc, blandit et, volutpat molestie, porta ut, ligula. Aliquam lorem ante, dapibus in, viverra quis, feugiat a, tellus. Aenean massa. Etiam sit amet orci eget eros faucibus tincidunt.","testimonialDescriptionTextColor":"#8e95a3","backGroundColor":"#F6F6F6","padding":20,"descriptionFontSize":14,"descriptionFontFamily":"Lato","descriptionFontWeight":"400","descriptionLoadGoogleFonts":true} -->
                        <div class="wp-block-blockspare-testimonial-item blockspare-9057630e-1f32-4 blockspare-testimonials textalignment-center"><style>.blockspare-9057630e-1f32-4 .blockspare-block-testimonial-wrap{background-color:#F6F6F6;border-width:1px;border-style:solid;border-color:#ececec;border-radius:0;padding:20px}.blockspare-9057630e-1f32-4 .blockspare-star-inner-container{gap:3px}.blockspare-9057630e-1f32-4 .blockspare-section-head-wrap{background-color:transparent;text-align:center;margin-top:20px;margin-right:0px;margin-bottom:10px;margin-left:0px}.blockspare-9057630e-1f32-4 .blockspare-section-head-wrap .blockspare-title{color:#343750;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px;font-size:20px;font-family:Montserrat;font-weight:600}.blockspare-9057630e-1f32-4 .blockspare-section-head-wrap .blockspare-subtitle{color:#8e95a3;font-size:14px;font-family:Lato;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px}.blockspare-9057630e-1f32-4 .blockspare-description{color:#8e95a3;font-size:14px;font-family:Lato;font-weight:400;text-align:center}@media screen and (max-width:1025px){.blockspare-9057630e-1f32-4 .blockspare-section-head-wrap .blockspare-title{font-size:22px}.blockspare-9057630e-1f32-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}.blockspare-9057630e-1f32-4 .blockspare-description{font-size:14px}}@media screen and (max-width:768px){.blockspare-9057630e-1f32-4 .blockspare-section-head-wrap .blockspare-title{font-size:20px}.blockspare-9057630e-1f32-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}.blockspare-9057630e-1f32-4 .blockspare-description{font-size:14px}}</style><div class="blockspare-block-testimonial-wrap blockspare-hover-item"><div class="blockspare-author-wrap"><div class="blockspare-img-wrapper"><!-- wp:image {"align":"center","id":13,"sizeSlug":"thumbnail","linkDestination":"none","className":"is-style-rounded"} -->
                        <figure class="wp-block-image aligncenter size-thumbnail is-style-rounded"><img src="https://blockspare.com/demo/default/automotive/wp-content/uploads/sites/25/2023/05/image-7-e1685358663744-150x150.png" alt="" class="wp-image-13"/></figure>
                        <!-- /wp:image --></div></div><div class="blockspare-author-designation"><div class="blockspare-section-header-wrapper blockspare-blocks aligncenter"><div class="blockspare-section-head-wrap blockspare-style1 blockspare-center"><div class="blockspare-title-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><h4 class="blockspare-title">Alex Simpson</h4><span class="blockspare-title-dash blockspare-lower-dash"></span></div><div class="blockspare-subtitle-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><p class="blockspare-subtitle">Chief Executive Officer</p><span class="blockspare-title-dash blockspare-lower-dash"></span></div></div></div></div><div class="blockspare-testimonial-content"><p class="blockspare-description">Nunc nulla. Mauris turpis nunc, blandit et, volutpat molestie, porta ut, ligula. Aliquam lorem ante, dapibus in, viverra quis, feugiat a, tellus. Aenean massa. Etiam sit amet orci eget eros faucibus tincidunt.</p></div></div></div>
                        <!-- /wp:blockspare/testimonial-item -->
                        
                        <!-- wp:blockspare/testimonial-item {"uniqueClass":"blockspare-de4ad70d-210a-4","sectionAlignment":"center","headerTitle":"Alex Simpson","titleFontSize":20,"headerSubTitle":"Chief Executive Officer","headertitleColor":"#343750","headersubtitleColor":"#8e95a3","headermarginTop":20,"headermarginBottom":10,"headerTagOption":"h4","titleFontFamily":"Montserrat","titleFontWeight":"600","titleLoadGoogleFonts":true,"subTitleFontFamily":"Lato","subTitleLoadGoogleFonts":true,"testimonialDescription":"Nunc nulla. Mauris turpis nunc, blandit et, volutpat molestie, porta ut, ligula. Aliquam lorem ante, dapibus in, viverra quis, feugiat a, tellus. Aenean massa. Etiam sit amet orci eget eros faucibus tincidunt.","testimonialDescriptionTextColor":"#8e95a3","backGroundColor":"#F6F6F6","padding":20,"descriptionFontSize":14,"descriptionFontFamily":"Lato","descriptionFontWeight":"400","descriptionLoadGoogleFonts":true} -->
                        <div class="wp-block-blockspare-testimonial-item blockspare-de4ad70d-210a-4 blockspare-testimonials textalignment-center"><style>.blockspare-de4ad70d-210a-4 .blockspare-block-testimonial-wrap{background-color:#F6F6F6;border-width:1px;border-style:solid;border-color:#ececec;border-radius:0;padding:20px}.blockspare-de4ad70d-210a-4 .blockspare-star-inner-container{gap:3px}.blockspare-de4ad70d-210a-4 .blockspare-section-head-wrap{background-color:transparent;text-align:center;margin-top:20px;margin-right:0px;margin-bottom:10px;margin-left:0px}.blockspare-de4ad70d-210a-4 .blockspare-section-head-wrap .blockspare-title{color:#343750;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px;font-size:20px;font-family:Montserrat;font-weight:600}.blockspare-de4ad70d-210a-4 .blockspare-section-head-wrap .blockspare-subtitle{color:#8e95a3;font-size:14px;font-family:Lato;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px}.blockspare-de4ad70d-210a-4 .blockspare-description{color:#8e95a3;font-size:14px;font-family:Lato;font-weight:400;text-align:center}@media screen and (max-width:1025px){.blockspare-de4ad70d-210a-4 .blockspare-section-head-wrap .blockspare-title{font-size:22px}.blockspare-de4ad70d-210a-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}.blockspare-de4ad70d-210a-4 .blockspare-description{font-size:14px}}@media screen and (max-width:768px){.blockspare-de4ad70d-210a-4 .blockspare-section-head-wrap .blockspare-title{font-size:20px}.blockspare-de4ad70d-210a-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}.blockspare-de4ad70d-210a-4 .blockspare-description{font-size:14px}}</style><div class="blockspare-block-testimonial-wrap blockspare-hover-item"><div class="blockspare-author-wrap"><div class="blockspare-img-wrapper"><!-- wp:image {"align":"center","id":13,"sizeSlug":"thumbnail","linkDestination":"none","className":"is-style-rounded"} -->
                        <figure class="wp-block-image aligncenter size-thumbnail is-style-rounded"><img src="https://blockspare.com/demo/default/automotive/wp-content/uploads/sites/25/2023/05/image-7-e1685358663744-150x150.png" alt="" class="wp-image-13"/></figure>
                        <!-- /wp:image --></div></div><div class="blockspare-author-designation"><div class="blockspare-section-header-wrapper blockspare-blocks aligncenter"><div class="blockspare-section-head-wrap blockspare-style1 blockspare-center"><div class="blockspare-title-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><h4 class="blockspare-title">Alex Simpson</h4><span class="blockspare-title-dash blockspare-lower-dash"></span></div><div class="blockspare-subtitle-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><p class="blockspare-subtitle">Chief Executive Officer</p><span class="blockspare-title-dash blockspare-lower-dash"></span></div></div></div></div><div class="blockspare-testimonial-content"><p class="blockspare-description">Nunc nulla. Mauris turpis nunc, blandit et, volutpat molestie, porta ut, ligula. Aliquam lorem ante, dapibus in, viverra quis, feugiat a, tellus. Aenean massa. Etiam sit amet orci eget eros faucibus tincidunt.</p></div></div></div>
                        <!-- /wp:blockspare/testimonial-item --></div></div>
                        <!-- /wp:blockspare/blockspare-testimonial --></div>
                        <!-- /wp:group --></div></div></div></div>
                        <!-- /wp:blockspare/blockspare-container -->',
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'autoservice',
                        'item'     => ['Auto Service','Testimonial'],
						'key'      => 'bs_section_194',
                        'imagePath'    => 'sections',
						'name'     => 'Auto Service Section 4',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/auto-service-section-4/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'autoservice',
                        'item'     => ['Auto Service','Accordion'],
						'key'      => 'bs_section_195',
                        'imagePath'    => 'sections',
						'name'     => 'Auto Service Section 5',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/auto-service-section-5/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'autoservice',
                        'item'     => ['Auto Service','Logo Grid'],
						'key'      => 'bs_section_196',
                        'imagePath'    => 'sections',
						'name'     => 'Auto Service Section 6',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/auto-service-section-6/",
						'content'  => '<!-- wp:blockspare/blockspare-container {"paddingTop":60,"paddingBottom":60,"marginTop":0,"marginBottom":0,"uniqueClass":"blockspare-352710a0-81d9-4","backGroundColor":"#f1f1f1"} -->
                        <div class="wp-block-blockspare-blockspare-container alignfull blockspare-352710a0-81d9-4" blockspare-animation=""><style>.blockspare-352710a0-81d9-4 > .blockspare-block-container-wrapper{background-color:#f1f1f1;padding-top:60px;padding-right:20px;padding-bottom:60px;padding-left:20px;margin-top:0px;margin-right:0px;margin-bottom:0px;margin-left:0px;border-radius:0}.blockspare-352710a0-81d9-4 .blockspare-image-wrap{background-image:none}</style><div class="blockspare-block-container-wrapper blockspare-hover-item"><div class="blockspare-container-background blockspare-image-wrap has-background-opacity-100 has-background-opacity"></div><div class="blockspare-container"><div class="blockspare-inner-blocks blockspare-inner-wrapper-blocks"><!-- wp:group {"align":"wide","layout":{"type":"constrained"}} -->
                        <div class="wp-block-group alignwide"><!-- wp:blockspare/blockspare-section-header {"uniqueClass":"blockspare-0acd83ba-9ed4-4","sectionAlignment":"center","headerTitle":"Our Top \u003cmark style=\u0022background-color:rgba(0, 0, 0, 0);color:#076eeb\u0022 class=\u0022has-inline-color\u0022\u003eBrands\u003c/mark\u003e","titleFontSize":42,"headerSubTitle":"OUR BRANDS","headertitleColor":"#343750","headersubtitleColor":"#343750","headermarginTop":0,"headerlayoutOption":"blockspare-style4","subtitlePaddingBottom":10,"dashColor":"#076eeb","titleFontFamily":"Montserrat","titleFontWeight":"700","titleLoadGoogleFonts":true,"subTitleFontFamily":"Montserrat","subTitleFontWeight":"600","subTitleLoadGoogleFonts":true} -->
                        <div class="wp-block-blockspare-blockspare-section-header aligncenter blockspare-0acd83ba-9ed4-4 blockspare-section-header-wrapper blockspare-blocks aligncenter" blockspare-animation=""><style>.blockspare-0acd83ba-9ed4-4 .blockspare-section-head-wrap{background-color:transparent;text-align:center;margin-top:0px;margin-right:0px;margin-bottom:30px;margin-left:0px}.blockspare-0acd83ba-9ed4-4 .blockspare-section-head-wrap .blockspare-title{color:#343750;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px;font-size:42px;font-family:Montserrat;font-weight:700}.blockspare-0acd83ba-9ed4-4 .blockspare-section-head-wrap .blockspare-title-wrapper .blockspare-title-dash{color:#076eeb!important}.blockspare-0acd83ba-9ed4-4 .blockspare-section-head-wrap .blockspare-subtitle{color:#343750;font-size:14px;font-family:Montserrat;font-weight:600;padding-top:0px;padding-right:0px;padding-bottom:10px;padding-left:0px}@media screen and (max-width:1025px){.blockspare-0acd83ba-9ed4-4 .blockspare-section-head-wrap .blockspare-title{font-size:22px}.blockspare-0acd83ba-9ed4-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}@media screen and (max-width:768px){.blockspare-0acd83ba-9ed4-4 .blockspare-section-head-wrap .blockspare-title{font-size:20px}.blockspare-0acd83ba-9ed4-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}</style><div class="blockspare-section-head-wrap blockspare-style4 blockspare-center blockspare-hover-item"><div class="blockspare-title-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><h2 class="blockspare-title">Our Top <mark style="background-color:rgba(0, 0, 0, 0);color:#076eeb" class="has-inline-color">Brands</mark></h2><span class="blockspare-title-dash blockspare-lower-dash"></span></div><div class="blockspare-subtitle-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><p class="blockspare-subtitle">OUR BRANDS</p><span class="blockspare-title-dash blockspare-lower-dash"></span></div></div></div>
                        <!-- /wp:blockspare/blockspare-section-header -->
                        
                        <!-- wp:blockspare/blockspare-logos {"uniqueClass":"blockspare-3f4e9e1f-66ac-4","align":"wide","images":[{"alt":"","id":33,"link":"https://blockspare.com/demo/default/automotive/automotive/logoipsum-214/","caption":"","sizes":{"full":{"url":"https://blockspare.com/demo/default/automotive/wp-content/uploads/sites/25/2023/05/logoipsum-214.png","height":46,"width":96,"orientation":"landscape"}},"url":"https://blockspare.com/demo/default/automotive/wp-content/uploads/sites/25/2023/05/logoipsum-214.png","imgLink":""},{"alt":"","id":34,"link":"https://blockspare.com/demo/default/automotive/automotive/logoipsum-219/","caption":"","sizes":{"full":{"url":"https://blockspare.com/demo/default/automotive/wp-content/uploads/sites/25/2023/05/logoipsum-219.png","height":15,"width":150,"orientation":"landscape"}},"url":"https://blockspare.com/demo/default/automotive/wp-content/uploads/sites/25/2023/05/logoipsum-219.png","imgLink":""},{"alt":"","id":35,"link":"https://blockspare.com/demo/default/automotive/automotive/logoipsum-232/","caption":"","sizes":{"full":{"url":"https://blockspare.com/demo/default/automotive/wp-content/uploads/sites/25/2023/05/logoipsum-232.png","height":47,"width":98,"orientation":"landscape"}},"url":"https://blockspare.com/demo/default/automotive/wp-content/uploads/sites/25/2023/05/logoipsum-232.png","imgLink":""},{"alt":"","id":36,"link":"https://blockspare.com/demo/default/automotive/automotive/logoipsum-287/","caption":"","sizes":{"full":{"url":"https://blockspare.com/demo/default/automotive/wp-content/uploads/sites/25/2023/05/logoipsum-287.png","height":40,"width":105,"orientation":"landscape"}},"url":"https://blockspare.com/demo/default/automotive/wp-content/uploads/sites/25/2023/05/logoipsum-287.png","imgLink":""},{"alt":"","id":37,"link":"https://blockspare.com/demo/default/automotive/automotive/logoipsum-215/","caption":"","sizes":{"full":{"url":"https://blockspare.com/demo/default/automotive/wp-content/uploads/sites/25/2023/05/logoipsum-215.png","height":34,"width":128,"orientation":"landscape"}},"url":"https://blockspare.com/demo/default/automotive/wp-content/uploads/sites/25/2023/05/logoipsum-215.png","imgLink":""},{"alt":"","id":38,"link":"https://blockspare.com/demo/default/automotive/automotive/logoipsum-221/","caption":"","sizes":{"full":{"url":"https://blockspare.com/demo/default/automotive/wp-content/uploads/sites/25/2023/05/logoipsum-221.png","height":26,"width":115,"orientation":"landscape"}},"url":"https://blockspare.com/demo/default/automotive/wp-content/uploads/sites/25/2023/05/logoipsum-221.png","imgLink":""},{"alt":"","id":39,"link":"https://blockspare.com/demo/default/automotive/automotive/logoipsum-234/","caption":"","sizes":{"full":{"url":"https://blockspare.com/demo/default/automotive/wp-content/uploads/sites/25/2023/05/logoipsum-234.png","height":46,"width":93,"orientation":"landscape"}},"url":"https://blockspare.com/demo/default/automotive/wp-content/uploads/sites/25/2023/05/logoipsum-234.png","imgLink":""}],"columns":7,"gutter":70,"animation":"AFTfadeInUp","className":"alignwide"} -->
                        <div class="blockspare-blocks blockspare-logos-wrapper has-gap-70 has-colums-7 wp-block-blockspare-blockspare-logos alignwide blockspare-3f4e9e1f-66ac-4 blockspare-block-animation" blockspare-animation="AFTfadeInUp"><style>.blockspare-3f4e9e1f-66ac-4 .blockspare-logo-grid-main{padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px;margin-top:30px;margin-right:0px;margin-bottom:30px;margin-left:0px}</style><div class="blockspare-logo-grid-main"><ul class="blockspare-logo-wrap"><li class="blockspare-gallery-item"><figure class="blockspare-gallery-figure blockspare-hover-item"><img src="https://blockspare.com/demo/default/automotive/wp-content/uploads/sites/25/2023/05/logoipsum-214.png" alt="" data-id="33" data-imglink="" data-link="https://blockspare.com/demo/default/automotive/automotive/logoipsum-214/" class="wp-image-33"/></figure></li><li class="blockspare-gallery-item"><figure class="blockspare-gallery-figure blockspare-hover-item"><img src="https://blockspare.com/demo/default/automotive/wp-content/uploads/sites/25/2023/05/logoipsum-219.png" alt="" data-id="34" data-imglink="" data-link="https://blockspare.com/demo/default/automotive/automotive/logoipsum-219/" class="wp-image-34"/></figure></li><li class="blockspare-gallery-item"><figure class="blockspare-gallery-figure blockspare-hover-item"><img src="https://blockspare.com/demo/default/automotive/wp-content/uploads/sites/25/2023/05/logoipsum-232.png" alt="" data-id="35" data-imglink="" data-link="https://blockspare.com/demo/default/automotive/automotive/logoipsum-232/" class="wp-image-35"/></figure></li><li class="blockspare-gallery-item"><figure class="blockspare-gallery-figure blockspare-hover-item"><img src="https://blockspare.com/demo/default/automotive/wp-content/uploads/sites/25/2023/05/logoipsum-287.png" alt="" data-id="36" data-imglink="" data-link="https://blockspare.com/demo/default/automotive/automotive/logoipsum-287/" class="wp-image-36"/></figure></li><li class="blockspare-gallery-item"><figure class="blockspare-gallery-figure blockspare-hover-item"><img src="https://blockspare.com/demo/default/automotive/wp-content/uploads/sites/25/2023/05/logoipsum-215.png" alt="" data-id="37" data-imglink="" data-link="https://blockspare.com/demo/default/automotive/automotive/logoipsum-215/" class="wp-image-37"/></figure></li><li class="blockspare-gallery-item"><figure class="blockspare-gallery-figure blockspare-hover-item"><img src="https://blockspare.com/demo/default/automotive/wp-content/uploads/sites/25/2023/05/logoipsum-221.png" alt="" data-id="38" data-imglink="" data-link="https://blockspare.com/demo/default/automotive/automotive/logoipsum-221/" class="wp-image-38"/></figure></li><li class="blockspare-gallery-item"><figure class="blockspare-gallery-figure blockspare-hover-item"><img src="https://blockspare.com/demo/default/automotive/wp-content/uploads/sites/25/2023/05/logoipsum-234.png" alt="" data-id="39" data-imglink="" data-link="https://blockspare.com/demo/default/automotive/automotive/logoipsum-234/" class="wp-image-39"/></figure></li></ul></div></div>
                        <!-- /wp:blockspare/blockspare-logos --></div>
                        <!-- /wp:group --></div></div></div></div>
                        <!-- /wp:blockspare/blockspare-container -->',
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'autodeal',
                        'item'     => ['Auto Deal','Call To Action'],
						'key'      => 'bs_section_197',
                        'imagePath'    => 'sections',
						'name'     => 'Auto Deal Section 1',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/auto-deal-section-1/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'autodeal',
                        'item'     => ['Auto Deal','Services'],
						'key'      => 'bs_section_198',
                        'imagePath'    => 'sections',
						'name'     => 'Auto Deal Section 2',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/auto-deal-section-2/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'autodeal',
                        'item'     => ['Auto Deal','Testimonial'],
						'key'      => 'bs_section_199',
                        'imagePath'    => 'sections',
						'name'     => 'Auto Deal Section 3',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/auto-deal-section-3/",
						'content'  => '<!-- wp:blockspare/blockspare-container {"paddingTop":100,"paddingBottom":100,"marginTop":0,"marginBottom":0,"uniqueClass":"blockspare-772024fa-b4af-4","backGroundColor":"#22282d"} -->
                        <div class="wp-block-blockspare-blockspare-container alignfull blockspare-772024fa-b4af-4" blockspare-animation=""><style>.blockspare-772024fa-b4af-4 > .blockspare-block-container-wrapper{background-color:#22282d;padding-top:100px;padding-right:20px;padding-bottom:100px;padding-left:20px;margin-top:0px;margin-right:0px;margin-bottom:0px;margin-left:0px;border-radius:0}.blockspare-772024fa-b4af-4 .blockspare-image-wrap{background-image:none}</style><div class="blockspare-block-container-wrapper blockspare-hover-item"><div class="blockspare-container-background blockspare-image-wrap has-background-opacity-100 has-background-opacity"></div><div class="blockspare-container"><div class="blockspare-inner-blocks blockspare-inner-wrapper-blocks"><!-- wp:group {"align":"wide","layout":{"type":"constrained"}} -->
                        <div class="wp-block-group alignwide"><!-- wp:blockspare/blockspare-section-header {"uniqueClass":"blockspare-41f0fccb-dbdb-4","sectionAlignment":"center","headerTitle":"What Our Client \u003cmark style=\u0022background-color:rgba(0, 0, 0, 0);color:#e3181b\u0022 class=\u0022has-inline-color\u0022\u003eSay\u003c/mark\u003e","titleFontSize":42,"headerSubTitle":"TESTIMONIALS","headertitleColor":"#ffffff","headersubtitleColor":"#ffffff","headermarginTop":0,"headerlayoutOption":"blockspare-style6","subtitlePaddingBottom":10,"dashColor":"#e3181b","titleFontFamily":"Montserrat","titleFontWeight":"700","titleLoadGoogleFonts":true,"subTitleFontFamily":"Montserrat","subTitleFontWeight":"600","subTitleLoadGoogleFonts":true} -->
                        <div class="wp-block-blockspare-blockspare-section-header aligncenter blockspare-41f0fccb-dbdb-4 blockspare-section-header-wrapper blockspare-blocks aligncenter" blockspare-animation=""><style>.blockspare-41f0fccb-dbdb-4 .blockspare-section-head-wrap{background-color:transparent;text-align:center;margin-top:0px;margin-right:0px;margin-bottom:30px;margin-left:0px}.blockspare-41f0fccb-dbdb-4 .blockspare-section-head-wrap .blockspare-title{color:#ffffff;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px;font-size:42px;font-family:Montserrat;font-weight:700}.blockspare-41f0fccb-dbdb-4 .blockspare-section-head-wrap .blockspare-title-wrapper .blockspare-title-dash{color:#e3181b!important}.blockspare-41f0fccb-dbdb-4 .blockspare-section-head-wrap .blockspare-subtitle{color:#ffffff;font-size:14px;font-family:Montserrat;font-weight:600;padding-top:0px;padding-right:0px;padding-bottom:10px;padding-left:0px}@media screen and (max-width:1025px){.blockspare-41f0fccb-dbdb-4 .blockspare-section-head-wrap .blockspare-title{font-size:22px}.blockspare-41f0fccb-dbdb-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}@media screen and (max-width:768px){.blockspare-41f0fccb-dbdb-4 .blockspare-section-head-wrap .blockspare-title{font-size:20px}.blockspare-41f0fccb-dbdb-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}</style><div class="blockspare-section-head-wrap blockspare-style6 blockspare-center blockspare-hover-item"><div class="blockspare-title-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><h2 class="blockspare-title">What Our Client <mark style="background-color:rgba(0, 0, 0, 0);color:#e3181b" class="has-inline-color">Say</mark></h2><span class="blockspare-title-dash blockspare-lower-dash"></span></div><div class="blockspare-subtitle-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><p class="blockspare-subtitle">TESTIMONIALS</p><span class="blockspare-title-dash blockspare-lower-dash"></span></div></div></div>
                        <!-- /wp:blockspare/blockspare-section-header -->
                        
                        <!-- wp:blockspare/blockspare-testimonial {"columnsGap":2,"marginTop":0,"marginBottom":0,"uniqueClass":"blockspare-6c0d24c0-6bab-4","animation":"AFTfadeIn"} -->
                        <div class="wp-block-blockspare-blockspare-testimonial alignwide blockspare-6c0d24c0-6bab-4 alignwide blockspare-block-animation" blockspare-animation="AFTfadeIn"><div class="blockspare-main-testimonial-wrapper blockspare-item blockspare-testimonial-columns-3 blockspare-testimonial-gap-2 bs-layout-1"><!-- wp:blockspare/testimonial-item {"uniqueClass":"blockspare-32ce9ed1-0d66-4","sectionAlignment":"center","headerTitle":"Alex Simpson","titleFontSize":20,"headerSubTitle":"General Manager","headertitleColor":"#ffffff","headersubtitleColor":"#8e95a3","headermarginTop":20,"headermarginBottom":10,"headerTagOption":"h4","titleFontFamily":"Montserrat","titleFontWeight":"600","titleLoadGoogleFonts":true,"subTitleFontFamily":"Montserrat","subTitleLoadGoogleFonts":true,"testimonialDescription":"Nunc nulla. Mauris turpis nunc, blandit et, volutpat molestie, porta ut, ligula. Aliquam lorem ante, dapibus in, viverra quis, feugiat a, tellus. Aenean massa. Etiam sit amet orci eget eros faucibus tincidunt.","testimonialDescriptionTextColor":"#8e95a3","backGroundColor":"#35393b","borderColor":"#ffffff00","padding":20,"descriptionFontSize":13,"descriptionFontFamily":"Lato","descriptionFontWeight":"400","descriptionLoadGoogleFonts":true} -->
                        <div class="wp-block-blockspare-testimonial-item blockspare-32ce9ed1-0d66-4 blockspare-testimonials textalignment-center"><style>.blockspare-32ce9ed1-0d66-4 .blockspare-block-testimonial-wrap{background-color:#35393b;border-width:1px;border-style:solid;border-color:#ffffff00;border-radius:0;padding:20px}.blockspare-32ce9ed1-0d66-4 .blockspare-star-inner-container{gap:3px}.blockspare-32ce9ed1-0d66-4 .blockspare-section-head-wrap{background-color:transparent;text-align:center;margin-top:20px;margin-right:0px;margin-bottom:10px;margin-left:0px}.blockspare-32ce9ed1-0d66-4 .blockspare-section-head-wrap .blockspare-title{color:#ffffff;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px;font-size:20px;font-family:Montserrat;font-weight:600}.blockspare-32ce9ed1-0d66-4 .blockspare-section-head-wrap .blockspare-subtitle{color:#8e95a3;font-size:14px;font-family:Montserrat;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px}.blockspare-32ce9ed1-0d66-4 .blockspare-description{color:#8e95a3;font-size:13px;font-family:Lato;font-weight:400;text-align:center}@media screen and (max-width:1025px){.blockspare-32ce9ed1-0d66-4 .blockspare-section-head-wrap .blockspare-title{font-size:22px}.blockspare-32ce9ed1-0d66-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}.blockspare-32ce9ed1-0d66-4 .blockspare-description{font-size:14px}}@media screen and (max-width:768px){.blockspare-32ce9ed1-0d66-4 .blockspare-section-head-wrap .blockspare-title{font-size:20px}.blockspare-32ce9ed1-0d66-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}.blockspare-32ce9ed1-0d66-4 .blockspare-description{font-size:14px}}</style><div class="blockspare-block-testimonial-wrap blockspare-hover-item"><div class="blockspare-author-wrap"><div class="blockspare-img-wrapper"><!-- wp:image {"align":"center","id":13,"sizeSlug":"thumbnail","linkDestination":"none","style":{"color":{"duotone":["#000000","#ffffff"]},"border":{"radius":"100px"}},"className":"is-style-rounded"} -->
                        <figure class="wp-block-image aligncenter size-thumbnail has-custom-border is-style-rounded"><img src="https://blockspare.com/demo/default/automotive/wp-content/uploads/sites/25/2023/05/image-7-e1685358663744-150x150.png" alt="" class="wp-image-13" style="border-radius:100px"/></figure>
                        <!-- /wp:image --></div></div><div class="blockspare-author-designation"><div class="blockspare-section-header-wrapper blockspare-blocks aligncenter"><div class="blockspare-section-head-wrap blockspare-style1 blockspare-center"><div class="blockspare-title-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><h4 class="blockspare-title">Alex Simpson</h4><span class="blockspare-title-dash blockspare-lower-dash"></span></div><div class="blockspare-subtitle-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><p class="blockspare-subtitle">General Manager</p><span class="blockspare-title-dash blockspare-lower-dash"></span></div></div></div></div><div class="blockspare-testimonial-content"><p class="blockspare-description">Nunc nulla. Mauris turpis nunc, blandit et, volutpat molestie, porta ut, ligula. Aliquam lorem ante, dapibus in, viverra quis, feugiat a, tellus. Aenean massa. Etiam sit amet orci eget eros faucibus tincidunt.</p></div></div></div>
                        <!-- /wp:blockspare/testimonial-item -->
                        
                        <!-- wp:blockspare/testimonial-item {"uniqueClass":"blockspare-b4203e76-8b7b-4","sectionAlignment":"center","headerTitle":"Alex Simpson","titleFontSize":20,"headerSubTitle":"General Manager","headertitleColor":"#ffffff","headersubtitleColor":"#8e95a3","headermarginTop":20,"headermarginBottom":10,"headerTagOption":"h4","titleFontFamily":"Montserrat","titleFontWeight":"600","titleLoadGoogleFonts":true,"subTitleFontFamily":"Montserrat","subTitleLoadGoogleFonts":true,"testimonialDescription":"Nunc nulla. Mauris turpis nunc, blandit et, volutpat molestie, porta ut, ligula. Aliquam lorem ante, dapibus in, viverra quis, feugiat a, tellus. Aenean massa. Etiam sit amet orci eget eros faucibus tincidunt.","testimonialDescriptionTextColor":"#8e95a3","backGroundColor":"#35393b","borderColor":"#ffffff00","padding":20,"descriptionFontSize":13,"descriptionFontFamily":"Lato","descriptionFontWeight":"400","descriptionLoadGoogleFonts":true} -->
                        <div class="wp-block-blockspare-testimonial-item blockspare-b4203e76-8b7b-4 blockspare-testimonials textalignment-center"><style>.blockspare-b4203e76-8b7b-4 .blockspare-block-testimonial-wrap{background-color:#35393b;border-width:1px;border-style:solid;border-color:#ffffff00;border-radius:0;padding:20px}.blockspare-b4203e76-8b7b-4 .blockspare-star-inner-container{gap:3px}.blockspare-b4203e76-8b7b-4 .blockspare-section-head-wrap{background-color:transparent;text-align:center;margin-top:20px;margin-right:0px;margin-bottom:10px;margin-left:0px}.blockspare-b4203e76-8b7b-4 .blockspare-section-head-wrap .blockspare-title{color:#ffffff;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px;font-size:20px;font-family:Montserrat;font-weight:600}.blockspare-b4203e76-8b7b-4 .blockspare-section-head-wrap .blockspare-subtitle{color:#8e95a3;font-size:14px;font-family:Montserrat;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px}.blockspare-b4203e76-8b7b-4 .blockspare-description{color:#8e95a3;font-size:13px;font-family:Lato;font-weight:400;text-align:center}@media screen and (max-width:1025px){.blockspare-b4203e76-8b7b-4 .blockspare-section-head-wrap .blockspare-title{font-size:22px}.blockspare-b4203e76-8b7b-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}.blockspare-b4203e76-8b7b-4 .blockspare-description{font-size:14px}}@media screen and (max-width:768px){.blockspare-b4203e76-8b7b-4 .blockspare-section-head-wrap .blockspare-title{font-size:20px}.blockspare-b4203e76-8b7b-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}.blockspare-b4203e76-8b7b-4 .blockspare-description{font-size:14px}}</style><div class="blockspare-block-testimonial-wrap blockspare-hover-item"><div class="blockspare-author-wrap"><div class="blockspare-img-wrapper"><!-- wp:image {"align":"center","id":13,"sizeSlug":"thumbnail","linkDestination":"none","style":{"color":{"duotone":["#000000","#ffffff"]},"border":{"radius":"100px"}},"className":"is-style-rounded"} -->
                        <figure class="wp-block-image aligncenter size-thumbnail has-custom-border is-style-rounded"><img src="https://blockspare.com/demo/default/automotive/wp-content/uploads/sites/25/2023/05/image-7-e1685358663744-150x150.png" alt="" class="wp-image-13" style="border-radius:100px"/></figure>
                        <!-- /wp:image --></div></div><div class="blockspare-author-designation"><div class="blockspare-section-header-wrapper blockspare-blocks aligncenter"><div class="blockspare-section-head-wrap blockspare-style1 blockspare-center"><div class="blockspare-title-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><h4 class="blockspare-title">Alex Simpson</h4><span class="blockspare-title-dash blockspare-lower-dash"></span></div><div class="blockspare-subtitle-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><p class="blockspare-subtitle">General Manager</p><span class="blockspare-title-dash blockspare-lower-dash"></span></div></div></div></div><div class="blockspare-testimonial-content"><p class="blockspare-description">Nunc nulla. Mauris turpis nunc, blandit et, volutpat molestie, porta ut, ligula. Aliquam lorem ante, dapibus in, viverra quis, feugiat a, tellus. Aenean massa. Etiam sit amet orci eget eros faucibus tincidunt.</p></div></div></div>
                        <!-- /wp:blockspare/testimonial-item -->
                        
                        <!-- wp:blockspare/testimonial-item {"uniqueClass":"blockspare-e95b8616-c840-4","sectionAlignment":"center","headerTitle":"Alex Simpson","titleFontSize":20,"headerSubTitle":"General Manager","headertitleColor":"#ffffff","headersubtitleColor":"#8e95a3","headermarginTop":20,"headermarginBottom":10,"headerTagOption":"h4","titleFontFamily":"Montserrat","titleFontWeight":"600","titleLoadGoogleFonts":true,"subTitleFontFamily":"Montserrat","subTitleLoadGoogleFonts":true,"testimonialDescription":"Nunc nulla. Mauris turpis nunc, blandit et, volutpat molestie, porta ut, ligula. Aliquam lorem ante, dapibus in, viverra quis, feugiat a, tellus. Aenean massa. Etiam sit amet orci eget eros faucibus tincidunt.","testimonialDescriptionTextColor":"#8e95a3","backGroundColor":"#35393b","borderColor":"#ffffff00","padding":20,"descriptionFontSize":13,"descriptionFontFamily":"Lato","descriptionFontWeight":"400","descriptionLoadGoogleFonts":true} -->
                        <div class="wp-block-blockspare-testimonial-item blockspare-e95b8616-c840-4 blockspare-testimonials textalignment-center"><style>.blockspare-e95b8616-c840-4 .blockspare-block-testimonial-wrap{background-color:#35393b;border-width:1px;border-style:solid;border-color:#ffffff00;border-radius:0;padding:20px}.blockspare-e95b8616-c840-4 .blockspare-star-inner-container{gap:3px}.blockspare-e95b8616-c840-4 .blockspare-section-head-wrap{background-color:transparent;text-align:center;margin-top:20px;margin-right:0px;margin-bottom:10px;margin-left:0px}.blockspare-e95b8616-c840-4 .blockspare-section-head-wrap .blockspare-title{color:#ffffff;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px;font-size:20px;font-family:Montserrat;font-weight:600}.blockspare-e95b8616-c840-4 .blockspare-section-head-wrap .blockspare-subtitle{color:#8e95a3;font-size:14px;font-family:Montserrat;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px}.blockspare-e95b8616-c840-4 .blockspare-description{color:#8e95a3;font-size:13px;font-family:Lato;font-weight:400;text-align:center}@media screen and (max-width:1025px){.blockspare-e95b8616-c840-4 .blockspare-section-head-wrap .blockspare-title{font-size:22px}.blockspare-e95b8616-c840-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}.blockspare-e95b8616-c840-4 .blockspare-description{font-size:14px}}@media screen and (max-width:768px){.blockspare-e95b8616-c840-4 .blockspare-section-head-wrap .blockspare-title{font-size:20px}.blockspare-e95b8616-c840-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}.blockspare-e95b8616-c840-4 .blockspare-description{font-size:14px}}</style><div class="blockspare-block-testimonial-wrap blockspare-hover-item"><div class="blockspare-author-wrap"><div class="blockspare-img-wrapper"><!-- wp:image {"align":"center","id":13,"sizeSlug":"thumbnail","linkDestination":"none","style":{"color":{"duotone":["#000000","#ffffff"]},"border":{"radius":"100px"}},"className":"is-style-rounded"} -->
                        <figure class="wp-block-image aligncenter size-thumbnail has-custom-border is-style-rounded"><img src="https://blockspare.com/demo/default/automotive/wp-content/uploads/sites/25/2023/05/image-7-e1685358663744-150x150.png" alt="" class="wp-image-13" style="border-radius:100px"/></figure>
                        <!-- /wp:image --></div></div><div class="blockspare-author-designation"><div class="blockspare-section-header-wrapper blockspare-blocks aligncenter"><div class="blockspare-section-head-wrap blockspare-style1 blockspare-center"><div class="blockspare-title-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><h4 class="blockspare-title">Alex Simpson</h4><span class="blockspare-title-dash blockspare-lower-dash"></span></div><div class="blockspare-subtitle-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><p class="blockspare-subtitle">General Manager</p><span class="blockspare-title-dash blockspare-lower-dash"></span></div></div></div></div><div class="blockspare-testimonial-content"><p class="blockspare-description">Nunc nulla. Mauris turpis nunc, blandit et, volutpat molestie, porta ut, ligula. Aliquam lorem ante, dapibus in, viverra quis, feugiat a, tellus. Aenean massa. Etiam sit amet orci eget eros faucibus tincidunt.</p></div></div></div>
                        <!-- /wp:blockspare/testimonial-item --></div></div>
                        <!-- /wp:blockspare/blockspare-testimonial --></div>
                        <!-- /wp:group --></div></div></div></div>
                        <!-- /wp:blockspare/blockspare-container -->',
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'autodeal',
                        'item'     => ['Auto Deal','User Profile'],
						'key'      => 'bs_section_200',
                        'imagePath'    => 'sections',
						'name'     => 'Auto Deal Section 4',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/auto-deal-section-4/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'autodeal',
                        'item'     => ['Auto Deal','User Profile'],
						'key'      => 'bs_section_201',
                        'imagePath'    => 'sections',
						'name'     => 'Auto Deal Section 5',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/auto-deal-section-5/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'autodeal',
                        'item'     => ['Auto Deal','Logo Grid'],
						'key'      => 'bs_section_202',
                        'imagePath'    => 'sections',
						'name'     => 'Auto Deal Section 6',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/auto-deal-section-6/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'gardener',
                        'item'     => ['Gardener','Call To Action'],
						'key'      => 'bs_section_203',
                        'imagePath'    => 'sections',
						'name'     => 'Gardener Section 1',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/gardener-section-1/",
						'content'  => '<!-- wp:blockspare/blockspare-container {"paddingTop":220,"paddingBottom":220,"marginTop":0,"marginBottom":0,"uniqueClass":"blockspare-ceafb020-d9bb-4","imgURL":"https://blockspare.com/demo/default/gardener/wp-content/uploads/sites/27/2023/05/pexels-petr-ganaj-4112228.jpg","imgID":6,"imgAlt":"","opacityRatio":60,"backGroundColor":"#000000"} -->
                        <div class="wp-block-blockspare-blockspare-container alignfull blockspare-ceafb020-d9bb-4" blockspare-animation=""><style>.blockspare-ceafb020-d9bb-4 > .blockspare-block-container-wrapper{background-color:#000000;padding-top:220px;padding-right:20px;padding-bottom:220px;padding-left:20px;margin-top:0px;margin-right:0px;margin-bottom:0px;margin-left:0px;border-radius:0}.blockspare-ceafb020-d9bb-4 .blockspare-image-wrap{background-image:url(https://blockspare.com/demo/default/gardener/wp-content/uploads/sites/27/2023/05/pexels-petr-ganaj-4112228.jpg)}</style><div class="blockspare-block-container-wrapper blockspare-hover-item"><div class="blockspare-container-background blockspare-image-wrap has-background-opacity-60 has-background-opacity"></div><div class="blockspare-container"><div class="blockspare-inner-blocks blockspare-inner-wrapper-blocks"><!-- wp:group {"layout":{"type":"constrained"}} -->
                        <div class="wp-block-group"><!-- wp:blockspare/blockspare-section-header {"uniqueClass":"blockspare-f2dcee77-166b-4","align":"wide","headerTitle":"Let’s Bring the Spring to\u003cbr\u003eYour Garden","titleFontSize":82,"headerSubTitle":"Florista Garden","headertitleColor":"#ffffff","headersubtitleColor":"#ffffff","titleFontFamily":"Sora","titleFontWeight":"200","titleFontSubset":"latin","titleLoadGoogleFonts":true,"subTitleFontFamily":"Sora","subTitleFontWeight":"200","subTitleFontSubset":"latin","subTitleLoadGoogleFonts":true,"animation":"AFTslideInRight"} -->
                        <div class="wp-block-blockspare-blockspare-section-header alignwide blockspare-f2dcee77-166b-4 blockspare-section-header-wrapper blockspare-blocks alignwide blockspare-block-animation" blockspare-animation="AFTslideInRight"><style>.blockspare-f2dcee77-166b-4 .blockspare-section-head-wrap{background-color:transparent;text-align:left;margin-top:30px;margin-right:0px;margin-bottom:30px;margin-left:0px}.blockspare-f2dcee77-166b-4 .blockspare-section-head-wrap .blockspare-title{color:#ffffff;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px;font-size:82px;font-family:Sora;font-weight:200}.blockspare-f2dcee77-166b-4 .blockspare-section-head-wrap .blockspare-subtitle{color:#ffffff;font-size:14px;font-family:Sora;font-weight:200;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px}@media screen and (max-width:1025px){.blockspare-f2dcee77-166b-4 .blockspare-section-head-wrap .blockspare-title{font-size:22px}.blockspare-f2dcee77-166b-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}@media screen and (max-width:768px){.blockspare-f2dcee77-166b-4 .blockspare-section-head-wrap .blockspare-title{font-size:20px}.blockspare-f2dcee77-166b-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}</style><div class="blockspare-section-head-wrap blockspare-style1 blockspare-left blockspare-hover-item"><div class="blockspare-title-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><h2 class="blockspare-title">Let’s Bring the Spring to<br>Your Garden</h2><span class="blockspare-title-dash blockspare-lower-dash"></span></div><div class="blockspare-subtitle-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><p class="blockspare-subtitle">Florista Garden</p><span class="blockspare-title-dash blockspare-lower-dash"></span></div></div></div>
                        <!-- /wp:blockspare/blockspare-section-header -->
                        
                        <!-- wp:blockspare/blockspare-container {"paddingTop":0,"paddingRight":0,"paddingBottom":1,"paddingLeft":0,"marginTop":0,"marginBottom":0,"align":"wide","uniqueClass":"blockspare-cc1ddaae-26a8-4","backGroundColor":"#ffffff00"} -->
                        <div class="wp-block-blockspare-blockspare-container alignwide blockspare-cc1ddaae-26a8-4" blockspare-animation=""><style>.blockspare-cc1ddaae-26a8-4 > .blockspare-block-container-wrapper{background-color:#ffffff00;padding-top:0px;padding-right:0px;padding-bottom:1px;padding-left:0px;margin-top:0px;margin-right:0px;margin-bottom:0px;margin-left:0px;border-radius:0}.blockspare-cc1ddaae-26a8-4 .blockspare-image-wrap{background-image:none}</style><div class="blockspare-block-container-wrapper blockspare-hover-item"><div class="blockspare-container-background blockspare-image-wrap has-background-opacity-100 has-background-opacity"></div><div class="blockspare-container"><div class="blockspare-inner-blocks blockspare-inner-wrapper-blocks"><!-- wp:blockspare/blockspare-buttons {"sectionAlignment":"left","uniqueClass":"blockspare-282e82f0-bdf5-4","buttonText":"SHOP NOW","buttonBackgroundColor":"#b5c547","buttonTextColor":"#000000","buttonFontSize":14,"buttonFontFamily":"Sora","buttonFontWeight":"400","buttonFontSubset":"latin-ext","buttonLoadGoogleFonts":true,"paddingRight":24,"paddingLeft":24,"marginTop":0,"marginBottom":0,"animation":"AFTslideInRight"} -->
                        <div class="wp-block-blockspare-blockspare-buttons blockspare-282e82f0-bdf5-4 blockspare-block-button-wrap blockspare-block-animation" blockspare-animation="AFTslideInRight"><style>.blockspare-282e82f0-bdf5-4 .blockspare-block-button{text-align:left;margin-top:0px;margin-bottom:0px;margin-left:0px;margin-right:0px}.blockspare-282e82f0-bdf5-4 .blockspare-block-button span{color:#000000;border-width:2px;font-size:14px;font-family:Sora;font-weight:400}.blockspare-282e82f0-bdf5-4 .blockspare-block-button .blockspare-button{background-color:#b5c547}.blockspare-282e82f0-bdf5-4 .blockspare-block-button .blockspare-button:visited{background-color:#b5c547}.blockspare-282e82f0-bdf5-4 .blockspare-block-button .blockspare-button:focus{background-color:#b5c547}.blockspare-282e82f0-bdf5-4 .blockspare-block-button i{font-size:14px}.blockspare-282e82f0-bdf5-4 .blockspare-block-button a{padding-top:12px;padding-bottom:12px;padding-right:24px;padding-left:24px}@media screen and (max-width:1025px){.blockspare-282e82f0-bdf5-4 .blockspare-block-button span{font-size:14px}.blockspare-282e82f0-bdf5-4 .blockspare-block-button i{font-size:14px}}@media screen and (max-width:768px){.blockspare-282e82f0-bdf5-4 .blockspare-block-button span{font-size:14px}.blockspare-282e82f0-bdf5-4 .blockspare-block-button i{font-size:14px}}</style><div class="blockspare-block-button"><a class="blockspare-button blockspare-button-shape-rounded blockspare-button-size-small"><span>SHOP NOW</span></a></div></div>
                        <!-- /wp:blockspare/blockspare-buttons --></div></div></div></div>
                        <!-- /wp:blockspare/blockspare-container --></div>
                        <!-- /wp:group --></div></div></div></div>
                        <!-- /wp:blockspare/blockspare-container -->',
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'gardener',
                        'item'     => ['Gardener','Services'],
						'key'      => 'bs_section_204',
                        'imagePath'    => 'sections',
						'name'     => 'Gardener Section 2',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/gardener-section-2/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'gardener',
                        'item'     => ['Gardener','Pricing Table'],
						'key'      => 'bs_section_205',
                        'imagePath'    => 'sections',
						'name'     => 'Gardener Section 3',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/gardener-section-3/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'gardener',
                        'item'     => ['Gardener','Content Box'],
						'key'      => 'bs_section_206',
                        'imagePath'    => 'sections',
						'name'     => 'Gardener Section 4',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/gardener-section-4/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'gardener',
                        'item'     => ['Gardener','Testimonial'],
						'key'      => 'bs_section_207',
                        'imagePath'    => 'sections',
						'name'     => 'Gardener Section 5',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/gardener-section-5/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'gardener',
                        'item'     => ['Gardener','Accordion'],
						'key'      => 'bs_section_208',
                        'imagePath'    => 'sections',
						'name'     => 'Gardener Section 6',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/gardener-section-6/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'gardener',
                        'item'     => ['Gardener','User Profile'],
						'key'      => 'bs_section_209',
                        'imagePath'    => 'sections',
						'name'     => 'Gardener Section 7',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/gardener-section-7/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'gardener',
                        'item'     => ['Gardener','Call To Action'],
						'key'      => 'bs_section_210',
                        'imagePath'    => 'sections',
						'name'     => 'Gardener Section 8',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/gardener-section-8/",
						'content'  => '<!-- wp:blockspare/blockspare-call-to-action {"uniqueClass":"blockspare-4401b5f5-ba5f-4","align":"full","headerTitle":"Surprise with House full of Gardens","titleFontSize":42,"headerSubTitle":"Awesome Gift Card","headerlayoutOption":"blockspare-style3","titlePaddingBottom":15,"subtitlePaddingBottom":20,"titleFontFamily":"Sora","titleFontWeight":"300","titleFontSubset":"latin","titleLoadGoogleFonts":true,"subTitleFontSize":16,"subTitleFontFamily":"Sora","subTitleFontWeight":"300","subTitleFontSubset":"latin","subTitleLoadGoogleFonts":true,"imgURL":"https://blockspare.com/demo/default/gardener/wp-content/uploads/sites/27/2023/05/pexels-alex-staudinger-1732414.jpg","imgID":25,"imgAlt":"","opacityRatio":50,"buttonText":"PURCHASE A GIFT CARD","buttonBackgroundColor":"#b5c547","buttonTextColor":"#000000","buttonFontSize":14,"buttonFontFamily":"Sora","buttonFontWeight":"400","buttonFontSubset":"latin","buttonLoadGoogleFonts":true,"paddingTop":10,"paddingRight":24,"paddingBottom":10,"paddingLeft":24,"marginTop":0,"marginBottom":0} -->
                            <div class="wp-block-blockspare-blockspare-call-to-action blockspare-4401b5f5-ba5f-4 alignfull blockspare-calltoaction" blockspare-animation=""><style>.blockspare-4401b5f5-ba5f-4 .blockspare-cta-wrapper{background-color:#0e0e0e;text-align:center;padding-top:10px;padding-right:24px;padding-bottom:10px;padding-left:24px;margin-top:0px;margin-right:0px;margin-bottom:0px;margin-left:0px;border-radius:null}.blockspare-4401b5f5-ba5f-4 .blockspare-block-button a.blockspare-button{padding-top:12px;padding-right:22px;padding-bottom:12px;padding-left:22px;margin-top:10px;margin-right:0px;margin-bottom:0px;margin-left:0px;color:#000000;border-width:2px;font-family:Sora;font-size:14px;font-weight:400}.blockspare-4401b5f5-ba5f-4.wp-block-blockspare-blockspare-call-to-action .blockspare-block-button .blockspare-button{background-color:#b5c547}.blockspare-4401b5f5-ba5f-4.wp-block-blockspare-blockspare-call-to-action .blockspare-block-button .blockspare-button:visited{background-color:#b5c547}.blockspare-4401b5f5-ba5f-4.wp-block-blockspare-blockspare-call-to-action .blockspare-block-button .blockspare-button:focus{background-color:#b5c547}.blockspare-4401b5f5-ba5f-4 .blockspare-section-head-wrap{background-color:transparent;text-align:center;margin-top:5px;margin-right:0px;margin-bottom:5px;margin-left:0px}.blockspare-4401b5f5-ba5f-4 .blockspare-section-head-wrap .blockspare-title{color:#fff;padding-top:0px;padding-right:0px;padding-bottom:15px;padding-left:0px;font-size:42px;font-family:Sora;font-weight:300}.blockspare-4401b5f5-ba5f-4 .blockspare-section-head-wrap .blockspare-subtitle{color:#fff;font-size:16px;font-family:Sora;font-weight:300;padding-top:0px;padding-right:0px;padding-bottom:20px;padding-left:0px}@media screen and (max-width:1025px){.blockspare-4401b5f5-ba5f-4 .blockspare-block-button a.blockspare-button{font-size:14px}.blockspare-4401b5f5-ba5f-4 .blockspare-section-head-wrap .blockspare-title{font-size:22px}.blockspare-4401b5f5-ba5f-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}@media screen and (max-width:768px){.blockspare-4401b5f5-ba5f-4 .blockspare-block-button a.blockspare-button{font-size:14px}.blockspare-4401b5f5-ba5f-4 .blockspare-section-head-wrap .blockspare-title{font-size:20px}.blockspare-4401b5f5-ba5f-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}</style><div class="blockspare-cta-wrapper blockspare-blocks blockspare-hover-item"><div class="blockspare-image-wrap blockspare-cta-background has-background-opacity-50 has-background-opacity" style="background-image:url(https://blockspare.com/demo/default/gardener/wp-content/uploads/sites/27/2023/05/pexels-alex-staudinger-1732414.jpg)"></div><div class="blockspare-section-header-wrapper blockspare-blocks"><div class="blockspare-section-head-wrap blockspare-style3 blockspare-center"><div class="blockspare-title-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><h2 class="blockspare-title">Surprise with House full of Gardens</h2><span class="blockspare-title-dash blockspare-lower-dash"></span></div><div class="blockspare-subtitle-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><p class="blockspare-subtitle">Awesome Gift Card</p><span class="blockspare-title-dash blockspare-lower-dash"></span></div></div></div><div class="blockspare-block-button"><a class="blockspare-button blockspare-button-shape-rounded blockspare-button-size-small"><span>PURCHASE A GIFT CARD</span></a></div></div></div>
                            <!-- /wp:blockspare/blockspare-call-to-action -->',
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'gardener',
                        'item'     => ['Gardener','Counter'],
						'key'      => 'bs_section_211',
                        'imagePath'    => 'sections',
						'name'     => 'Gardener Section 9',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/gardener-section-9/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'gardener',
                        'item'     => ['Gardener','Logo Grid'],
						'key'      => 'bs_section_212',
                        'imagePath'    => 'sections',
						'name'     => 'Gardener Section 10',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/gardener-section-10/",
						'content'  => '<!-- wp:blockspare/blockspare-container {"paddingTop":60,"paddingBottom":60,"marginTop":0,"marginBottom":0,"uniqueClass":"blockspare-9f344e20-8936-4","backGroundColor":"#b5c547"} -->
                        <div class="wp-block-blockspare-blockspare-container alignfull blockspare-9f344e20-8936-4" blockspare-animation=""><style>.blockspare-9f344e20-8936-4 > .blockspare-block-container-wrapper{background-color:#b5c547;padding-top:60px;padding-right:20px;padding-bottom:60px;padding-left:20px;margin-top:0px;margin-right:0px;margin-bottom:0px;margin-left:0px;border-radius:0}.blockspare-9f344e20-8936-4 .blockspare-image-wrap{background-image:none}</style><div class="blockspare-block-container-wrapper blockspare-hover-item"><div class="blockspare-container-background blockspare-image-wrap has-background-opacity-100 has-background-opacity"></div><div class="blockspare-container"><div class="blockspare-inner-blocks blockspare-inner-wrapper-blocks"><!-- wp:group {"layout":{"type":"constrained"}} -->
                        <div class="wp-block-group"><!-- wp:blockspare/blockspare-logos {"align":"wide","uniqueClass":"blockspare-2778627d-cb71-4","animation":"AFTslideInBottom","images":[{"alt":"","id":40,"link":"https://blockspare.com/demo/default/gardener/home/logoipsum-221/","caption":"","sizes":{"full":{"url":"https://blockspare.com/demo/default/gardener/wp-content/uploads/sites/27/2023/05/logoipsum-221.png","height":26,"width":115,"orientation":"landscape"}},"url":"https://blockspare.com/demo/default/gardener/wp-content/uploads/sites/27/2023/05/logoipsum-221.png","imgLink":""},{"alt":"","id":41,"link":"https://blockspare.com/demo/default/gardener/home/logoipsum-234/","caption":"","sizes":{"full":{"url":"https://blockspare.com/demo/default/gardener/wp-content/uploads/sites/27/2023/05/logoipsum-234.png","height":46,"width":93,"orientation":"landscape"}},"url":"https://blockspare.com/demo/default/gardener/wp-content/uploads/sites/27/2023/05/logoipsum-234.png","imgLink":""},{"alt":"","id":39,"link":"https://blockspare.com/demo/default/gardener/home/logoipsum-215/","caption":"","sizes":{"full":{"url":"https://blockspare.com/demo/default/gardener/wp-content/uploads/sites/27/2023/05/logoipsum-215.png","height":34,"width":128,"orientation":"landscape"}},"url":"https://blockspare.com/demo/default/gardener/wp-content/uploads/sites/27/2023/05/logoipsum-215.png","imgLink":""},{"alt":"","id":38,"link":"https://blockspare.com/demo/default/gardener/home/logoipsum-287/","caption":"","sizes":{"full":{"url":"https://blockspare.com/demo/default/gardener/wp-content/uploads/sites/27/2023/05/logoipsum-287.png","height":40,"width":105,"orientation":"landscape"}},"url":"https://blockspare.com/demo/default/gardener/wp-content/uploads/sites/27/2023/05/logoipsum-287.png","imgLink":""},{"alt":"","id":37,"link":"https://blockspare.com/demo/default/gardener/home/logoipsum-232/","caption":"","sizes":{"full":{"url":"https://blockspare.com/demo/default/gardener/wp-content/uploads/sites/27/2023/05/logoipsum-232.png","height":47,"width":98,"orientation":"landscape"}},"url":"https://blockspare.com/demo/default/gardener/wp-content/uploads/sites/27/2023/05/logoipsum-232.png","imgLink":""},{"alt":"","id":36,"link":"https://blockspare.com/demo/default/gardener/home/logoipsum-219/","caption":"","sizes":{"full":{"url":"https://blockspare.com/demo/default/gardener/wp-content/uploads/sites/27/2023/05/logoipsum-219.png","height":15,"width":150,"orientation":"landscape"}},"url":"https://blockspare.com/demo/default/gardener/wp-content/uploads/sites/27/2023/05/logoipsum-219.png","imgLink":""},{"alt":"","id":35,"link":"https://blockspare.com/demo/default/gardener/home/logoipsum-214/","caption":"","sizes":{"full":{"url":"https://blockspare.com/demo/default/gardener/wp-content/uploads/sites/27/2023/05/logoipsum-214.png","height":46,"width":96,"orientation":"landscape"}},"url":"https://blockspare.com/demo/default/gardener/wp-content/uploads/sites/27/2023/05/logoipsum-214.png","imgLink":""}],"columns":7,"gutter":85} -->
                        <div class="blockspare-blocks blockspare-logos-wrapper has-gap-85 has-colums-7 wp-block-blockspare-blockspare-logos alignwide blockspare-2778627d-cb71-4 blockspare-block-animation" blockspare-animation="AFTslideInBottom"><style>.blockspare-2778627d-cb71-4 .blockspare-logo-grid-main{padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px;margin-top:30px;margin-right:0px;margin-bottom:30px;margin-left:0px}</style><div class="blockspare-logo-grid-main"><ul class="blockspare-logo-wrap"><li class="blockspare-gallery-item"><figure class="blockspare-gallery-figure"><img src="https://blockspare.com/demo/default/gardener/wp-content/uploads/sites/27/2023/05/logoipsum-221.png" alt="" data-id="40" data-imglink="" data-link="https://blockspare.com/demo/default/gardener/home/logoipsum-221/" class="wp-image-40 blockspare-hover-item"/></figure></li><li class="blockspare-gallery-item"><figure class="blockspare-gallery-figure"><img src="https://blockspare.com/demo/default/gardener/wp-content/uploads/sites/27/2023/05/logoipsum-234.png" alt="" data-id="41" data-imglink="" data-link="https://blockspare.com/demo/default/gardener/home/logoipsum-234/" class="wp-image-41 blockspare-hover-item"/></figure></li><li class="blockspare-gallery-item"><figure class="blockspare-gallery-figure"><img src="https://blockspare.com/demo/default/gardener/wp-content/uploads/sites/27/2023/05/logoipsum-215.png" alt="" data-id="39" data-imglink="" data-link="https://blockspare.com/demo/default/gardener/home/logoipsum-215/" class="wp-image-39 blockspare-hover-item"/></figure></li><li class="blockspare-gallery-item"><figure class="blockspare-gallery-figure"><img src="https://blockspare.com/demo/default/gardener/wp-content/uploads/sites/27/2023/05/logoipsum-287.png" alt="" data-id="38" data-imglink="" data-link="https://blockspare.com/demo/default/gardener/home/logoipsum-287/" class="wp-image-38 blockspare-hover-item"/></figure></li><li class="blockspare-gallery-item"><figure class="blockspare-gallery-figure"><img src="https://blockspare.com/demo/default/gardener/wp-content/uploads/sites/27/2023/05/logoipsum-232.png" alt="" data-id="37" data-imglink="" data-link="https://blockspare.com/demo/default/gardener/home/logoipsum-232/" class="wp-image-37 blockspare-hover-item"/></figure></li><li class="blockspare-gallery-item"><figure class="blockspare-gallery-figure"><img src="https://blockspare.com/demo/default/gardener/wp-content/uploads/sites/27/2023/05/logoipsum-219.png" alt="" data-id="36" data-imglink="" data-link="https://blockspare.com/demo/default/gardener/home/logoipsum-219/" class="wp-image-36 blockspare-hover-item"/></figure></li><li class="blockspare-gallery-item"><figure class="blockspare-gallery-figure"><img src="https://blockspare.com/demo/default/gardener/wp-content/uploads/sites/27/2023/05/logoipsum-214.png" alt="" data-id="35" data-imglink="" data-link="https://blockspare.com/demo/default/gardener/home/logoipsum-214/" class="wp-image-35 blockspare-hover-item"/></figure></li></ul></div></div>
                        <!-- /wp:blockspare/blockspare-logos --></div>
                        <!-- /wp:group --></div></div></div></div>
                        <!-- /wp:blockspare/blockspare-container -->',
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'montessori',
                        'item'     => ['Montessori','Call To Action'],
						'key'      => 'bs_section_213',
                        'imagePath'    => 'sections',
						'name'     => 'Montessori Section 1',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/montessori-section-1/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'montessori',
                        'item'     => ['Montessori','Services'],
						'key'      => 'bs_section_214',
                        'imagePath'    => 'sections',
						'name'     => 'Montessori Section 2',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/montessori-section-2/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'montessori',
                        'item'     => ['Montessori','Content Box'],
						'key'      => 'bs_section_215',
                        'imagePath'    => 'sections',
						'name'     => 'Montessori Section 3',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/montessori-section-3/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'montessori',
                        'item'     => ['Montessori','Content Box'],
						'key'      => 'bs_section_216',
                        'imagePath'    => 'sections',
						'name'     => 'Montessori Section 4',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/montessori-section-4/",
						'content'  => '<!-- wp:blockspare/blockspare-container {"paddingTop":0,"paddingRight":0,"paddingBottom":0,"paddingLeft":0,"marginTop":0,"marginBottom":0,"uniqueClass":"blockspare-c0f5c4d5-0f3b-4","backGroundColor":"#ec4444"} -->
                        <div class="wp-block-blockspare-blockspare-container alignfull blockspare-c0f5c4d5-0f3b-4" blockspare-animation=""><style>.blockspare-c0f5c4d5-0f3b-4 > .blockspare-block-container-wrapper{background-color:#ec4444;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px;margin-top:0px;margin-right:0px;margin-bottom:0px;margin-left:0px;border-radius:0}.blockspare-c0f5c4d5-0f3b-4 .blockspare-image-wrap{background-image:none}</style><div class="blockspare-block-container-wrapper blockspare-hover-item"><div class="blockspare-container-background blockspare-image-wrap has-background-opacity-100 has-background-opacity"></div><div class="blockspare-container"><div class="blockspare-inner-blocks blockspare-inner-wrapper-blocks"><!-- wp:group {"layout":{"type":"constrained"}} -->
                        <div class="wp-block-group"><!-- wp:columns {"verticalAlignment":"center","align":"full"} -->
                        <div class="wp-block-columns alignfull are-vertically-aligned-center"><!-- wp:column {"verticalAlignment":"center","style":{"spacing":{"padding":{"top":"0","right":"0","bottom":"0","left":"0"}}}} -->
                        <div class="wp-block-column is-vertically-aligned-center" style="padding-top:0;padding-right:0;padding-bottom:0;padding-left:0"><!-- wp:blockspare/blockspare-section-header {"uniqueClass":"blockspare-c1b002f0-d298-4","headerTitle":"Make your kid life special","titleFontSize":40,"headertitleColor":"#ffffff","headersubtitleColor":"#ffffff","headermarginTop":60,"headermarginRight":60,"headermarginBottom":0,"headermarginLeft":60,"titleFontFamily":"Poppins","titleLoadGoogleFonts":true,"subTitleFontSize":0} -->
                        <div class="wp-block-blockspare-blockspare-section-header aligncenter blockspare-c1b002f0-d298-4 blockspare-section-header-wrapper blockspare-blocks aligncenter" blockspare-animation=""><style>.blockspare-c1b002f0-d298-4 .blockspare-section-head-wrap{background-color:transparent;text-align:left;margin-top:60px;margin-right:60px;margin-bottom:0px;margin-left:60px}.blockspare-c1b002f0-d298-4 .blockspare-section-head-wrap .blockspare-title{color:#ffffff;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px;font-size:40px;font-family:Poppins}.blockspare-c1b002f0-d298-4 .blockspare-section-head-wrap .blockspare-subtitle{color:#ffffff;font-size:0px;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px}@media screen and (max-width:1025px){.blockspare-c1b002f0-d298-4 .blockspare-section-head-wrap .blockspare-title{font-size:22px}.blockspare-c1b002f0-d298-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}@media screen and (max-width:768px){.blockspare-c1b002f0-d298-4 .blockspare-section-head-wrap .blockspare-title{font-size:20px}.blockspare-c1b002f0-d298-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}</style><div class="blockspare-section-head-wrap blockspare-style1 blockspare-left blockspare-hover-item"><div class="blockspare-title-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><h2 class="blockspare-title">Make your kid life special</h2><span class="blockspare-title-dash blockspare-lower-dash"></span></div><div class="blockspare-subtitle-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><span class="blockspare-title-dash blockspare-lower-dash"></span></div></div></div>
                        <!-- /wp:blockspare/blockspare-section-header -->
                        
                        <!-- wp:blockspare/blockspare-container {"paddingTop":0,"paddingRight":60,"paddingBottom":0,"paddingLeft":60,"uniqueClass":"blockspare-a185165d-5fa3-4","backGroundColor":"#ffffff00"} -->
                        <div class="wp-block-blockspare-blockspare-container alignfull blockspare-a185165d-5fa3-4" blockspare-animation=""><style>.blockspare-a185165d-5fa3-4 > .blockspare-block-container-wrapper{background-color:#ffffff00;padding-top:0px;padding-right:60px;padding-bottom:0px;padding-left:60px;margin-top:30px;margin-right:0px;margin-bottom:30px;margin-left:0px;border-radius:0}.blockspare-a185165d-5fa3-4 .blockspare-image-wrap{background-image:none}</style><div class="blockspare-block-container-wrapper blockspare-hover-item"><div class="blockspare-container-background blockspare-image-wrap has-background-opacity-100 has-background-opacity"></div><div class="blockspare-container"><div class="blockspare-inner-blocks blockspare-inner-wrapper-blocks"><!-- wp:paragraph {"textColor":"base","fontSize":"small"} -->
                        <p class="has-base-color has-text-color has-small-font-size">Senectus maxime recusandae illum, facilis alias quis sint eligendi voluptatem risus tempore. Nisl reiciendis, egestas doloremque congue mus culpa corporis debitis, imperdiet torquent, molestie! Et torquent architecto! Fuga pede rem! Provident pede, integer fusce libero cursus dictumst anim suspendisse occaecat! Consequatur senectus, maecenas aenean sunt. Enim. Class, minim, minus voluptatum eiusmod? Curabitur excepteur lacus mi natoque! Pariatur excepturi leo lacinia? Hic quidem quam! Sem assumenda class, distinctio maecenas curae laboris, ligula curae. Inventore omnis dignissim lacus? </p>
                        <!-- /wp:paragraph -->
                        
                        <!-- wp:paragraph {"textColor":"base","fontSize":"small"} -->
                        <p class="has-base-color has-text-color has-small-font-size">Alias eum sequi fames perspiciatis mauris facilisis quis, nullam iaculis malesuada consequuntur, harum vestibulum sollicitudin! Magna massa facilisi repudiandae velit. Odio senectus. Error? Occaecat.</p>
                        <!-- /wp:paragraph --></div></div></div></div>
                        <!-- /wp:blockspare/blockspare-container --></div>
                        <!-- /wp:column -->
                        
                        <!-- wp:column {"verticalAlignment":"center","style":{"spacing":{"padding":{"top":"0px","right":"0px","bottom":"0px","left":"0px"}}}} -->
                        <div class="wp-block-column is-vertically-aligned-center" style="padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px"><!-- wp:image {"id":210,"sizeSlug":"full","linkDestination":"none"} -->
                        <figure class="wp-block-image size-full"><img src="https://blockspare.com/demo/default/montessori/wp-content/uploads/sites/28/2023/12/pexels-pixabay-255514.jpg" alt="" class="wp-image-210"/></figure>
                        <!-- /wp:image --></div>
                        <!-- /wp:column --></div>
                        <!-- /wp:columns --></div>
                        <!-- /wp:group --></div></div></div></div>
                        <!-- /wp:blockspare/blockspare-container -->',
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'montessori',
                        'item'     => ['Montessori','User Profile'],
						'key'      => 'bs_section_217',
                        'imagePath'    => 'sections',
						'name'     => 'Montessori Section 5',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/montessori-section-5/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'montessori',
                        'item'     => ['Montessori','Content Box'],
						'key'      => 'bs_section_218',
                        'imagePath'    => 'sections',
						'name'     => 'Montessori Section 6',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/montessori-section-6/",
						'content'  => '<!-- wp:blockspare/blockspare-container {"paddingTop":0,"paddingRight":0,"paddingBottom":0,"paddingLeft":0,"marginTop":0,"marginBottom":0,"uniqueClass":"blockspare-9eca5f1c-7173-4","backGroundColor":"#fa80a0"} -->
                        <div class="wp-block-blockspare-blockspare-container alignfull blockspare-9eca5f1c-7173-4" blockspare-animation=""><style>.blockspare-9eca5f1c-7173-4 > .blockspare-block-container-wrapper{background-color:#fa80a0;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px;margin-top:0px;margin-right:0px;margin-bottom:0px;margin-left:0px;border-radius:0}.blockspare-9eca5f1c-7173-4 .blockspare-image-wrap{background-image:none}</style><div class="blockspare-block-container-wrapper blockspare-hover-item"><div class="blockspare-container-background blockspare-image-wrap has-background-opacity-100 has-background-opacity"></div><div class="blockspare-container"><div class="blockspare-inner-blocks blockspare-inner-wrapper-blocks"><!-- wp:group {"align":"wide","layout":{"type":"constrained"}} -->
                        <div class="wp-block-group alignwide"><!-- wp:columns {"verticalAlignment":"center","align":"full"} -->
                        <div class="wp-block-columns alignfull are-vertically-aligned-center"><!-- wp:column {"verticalAlignment":"center","width":"50%","style":{"spacing":{"padding":{"top":"0px","right":"0px","bottom":"0px","left":"0px"}}}} -->
                        <div class="wp-block-column is-vertically-aligned-center" style="padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px;flex-basis:50%"><!-- wp:image {"id":14,"sizeSlug":"large","linkDestination":"none","style":{"color":{"duotone":"unset"}}} -->
                        <figure class="wp-block-image size-large"><img src="https://blockspare.com/demo/default/montessori/wp-content/uploads/sites/28/2023/05/pexels-polesie-toys-4487949-edited-e1685531416424-1024x397.jpg" alt="" class="wp-image-14"/></figure>
                        <!-- /wp:image --></div>
                        <!-- /wp:column -->
                        
                        <!-- wp:column {"verticalAlignment":"center"} -->
                        <div class="wp-block-column is-vertically-aligned-center"><!-- wp:blockspare/blockspare-container {"paddingRight":60,"paddingLeft":60,"marginTop":0,"marginBottom":0,"uniqueClass":"blockspare-7564a168-8255-4","backGroundColor":"#ffffff00"} -->
                        <div class="wp-block-blockspare-blockspare-container alignfull blockspare-7564a168-8255-4" blockspare-animation=""><style>.blockspare-7564a168-8255-4 > .blockspare-block-container-wrapper{background-color:#ffffff00;padding-top:20px;padding-right:60px;padding-bottom:20px;padding-left:60px;margin-top:0px;margin-right:0px;margin-bottom:0px;margin-left:0px;border-radius:0}.blockspare-7564a168-8255-4 .blockspare-image-wrap{background-image:none}</style><div class="blockspare-block-container-wrapper blockspare-hover-item"><div class="blockspare-container-background blockspare-image-wrap has-background-opacity-100 has-background-opacity"></div><div class="blockspare-container"><div class="blockspare-inner-blocks blockspare-inner-wrapper-blocks"><!-- wp:blockspare/blockspare-section-header {"uniqueClass":"blockspare-94d28e68-d260-4","headerTitle":"Make your kid life special","titleFontSize":40,"headerSubTitle":"A School with all the Greatest Lessons","headertitleColor":"#ffffff","headersubtitleColor":"#ffffff","headermarginTop":0,"headerlayoutOption":"blockspare-style3","titleFontFamily":"Poppins","titleLoadGoogleFonts":true,"subTitleFontSize":16,"subTitleFontFamily":"Poppins","subTitleLoadGoogleFonts":true} -->
                        <div class="wp-block-blockspare-blockspare-section-header aligncenter blockspare-94d28e68-d260-4 blockspare-section-header-wrapper blockspare-blocks aligncenter" blockspare-animation=""><style>.blockspare-94d28e68-d260-4 .blockspare-section-head-wrap{background-color:transparent;text-align:left;margin-top:0px;margin-right:0px;margin-bottom:30px;margin-left:0px}.blockspare-94d28e68-d260-4 .blockspare-section-head-wrap .blockspare-title{color:#ffffff;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px;font-size:40px;font-family:Poppins}.blockspare-94d28e68-d260-4 .blockspare-section-head-wrap .blockspare-subtitle{color:#ffffff;font-size:16px;font-family:Poppins;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px}@media screen and (max-width:1025px){.blockspare-94d28e68-d260-4 .blockspare-section-head-wrap .blockspare-title{font-size:22px}.blockspare-94d28e68-d260-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}@media screen and (max-width:768px){.blockspare-94d28e68-d260-4 .blockspare-section-head-wrap .blockspare-title{font-size:20px}.blockspare-94d28e68-d260-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}</style><div class="blockspare-section-head-wrap blockspare-style3 blockspare-left blockspare-hover-item"><div class="blockspare-title-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><h2 class="blockspare-title">Make your kid life special</h2><span class="blockspare-title-dash blockspare-lower-dash"></span></div><div class="blockspare-subtitle-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><p class="blockspare-subtitle">A School with all the Greatest Lessons</p><span class="blockspare-title-dash blockspare-lower-dash"></span></div></div></div>
                        <!-- /wp:blockspare/blockspare-section-header -->
                        
                        <!-- wp:blockspare/blockspare-buttons {"sectionAlignment":"left","uniqueClass":"blockspare-392fee1a-2cd8-4","buttonBackgroundColor":"#ffffff","buttonTextColor":"#404040","buttonShape":"blockspare-button-shape-square","buttonHoverEffect":"hover-style-2","marginBottom":0} -->
                        <div class="wp-block-blockspare-blockspare-buttons blockspare-392fee1a-2cd8-4 blockspare-block-button-wrap" blockspare-animation=""><style>.blockspare-392fee1a-2cd8-4 .blockspare-block-button{text-align:left;margin-top:30px;margin-bottom:0px;margin-left:0px;margin-right:0px}.blockspare-392fee1a-2cd8-4 .blockspare-block-button span{color:#404040;border-width:2px;font-size:16px}.blockspare-392fee1a-2cd8-4 .blockspare-block-button .blockspare-button{background-color:#ffffff}.blockspare-392fee1a-2cd8-4 .blockspare-block-button .blockspare-button:visited{background-color:#ffffff}.blockspare-392fee1a-2cd8-4 .blockspare-block-button .blockspare-button:focus{background-color:#ffffff}.blockspare-392fee1a-2cd8-4 .blockspare-block-button i{font-size:16px}.blockspare-392fee1a-2cd8-4 .blockspare-block-button a{padding-top:12px;padding-bottom:12px;padding-right:22px;padding-left:22px}@media screen and (max-width:1025px){.blockspare-392fee1a-2cd8-4 .blockspare-block-button span{font-size:14px}.blockspare-392fee1a-2cd8-4 .blockspare-block-button i{font-size:14px}}@media screen and (max-width:768px){.blockspare-392fee1a-2cd8-4 .blockspare-block-button span{font-size:14px}.blockspare-392fee1a-2cd8-4 .blockspare-block-button i{font-size:14px}}</style><div class="blockspare-block-button"><a class="blockspare-button blockspare-button-shape-square blockspare-button-size-small hover-style-2"><span>Get Started</span></a></div></div>
                        <!-- /wp:blockspare/blockspare-buttons --></div></div></div></div>
                        <!-- /wp:blockspare/blockspare-container --></div>
                        <!-- /wp:column --></div>
                        <!-- /wp:columns --></div>
                        <!-- /wp:group --></div></div></div></div>
                        <!-- /wp:blockspare/blockspare-container -->',
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'montessori',
                        'item'     => ['Montessori','Counter'],
						'key'      => 'bs_section_219',
                        'imagePath'    => 'sections',
						'name'     => 'Montessori Section 7',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/montessori-section-7/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'montessori',
                        'item'     => ['Montessori','Progress Bar'],
						'key'      => 'bs_section_220',
                        'imagePath'    => 'sections',
						'name'     => 'Montessori Section 8',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/montessori-section-8/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'montessori',
                        'item'     => ['Montessori','Accordion'],
						'key'      => 'bs_section_221',
                        'imagePath'    => 'sections',
						'name'     => 'Montessori Section 9',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/montessori-section-9/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'montessori',
                        'item'     => ['Montessori','Pricing Table'],
						'key'      => 'bs_section_222',
                        'imagePath'    => 'sections',
						'name'     => 'Montessori Section 10',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/montessori-section-10/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'montessori',
                        'item'     => ['Montessori','Call To Action'],
						'key'      => 'bs_section_223',
                        'imagePath'    => 'sections',
						'name'     => 'Montessori Section 11',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/montessori-section-11/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'travel',
                        'item'     => ['Travel','Call To Action'],
						'key'      => 'bs_section_224',
                        'imagePath'    => 'sections',
						'name'     => 'Travel Section 1',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/travel-section-1/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'travel',
                        'item'     => ['Travel','Services'], 
						'key'      => 'bs_section_225',
                        'imagePath'    => 'sections',
						'name'     => 'Travel Section 2',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/travel-section-2/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'travel',
                        'item'     => ['Travel','Container'], 
						'key'      => 'bs_section_226',
                        'imagePath'    => 'sections',
						'name'     => 'Travel Section 3',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/travel-section-3/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'travel',
                        'item'     => ['Travel','Services'], 
						'key'      => 'bs_section_227',
                        'imagePath'    => 'sections',
						'name'     => 'Travel Section 4',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/travel-section-4/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'travel',
                        'item'     => ['Travel','Content Box'], 
						'key'      => 'bs_section_228',
                        'imagePath'    => 'sections',
						'name'     => 'Travel Section 5',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/travel-section-5/",
						'content'  => '<!-- wp:blockspare/blockspare-container {"paddingTop":180,"paddingBottom":120,"marginTop":0,"marginBottom":0,"uniqueClass":"blockspare-e46441e4-923d-4","backGroundColor":"#000000"} -->
                        <div class="wp-block-blockspare-blockspare-container alignfull blockspare-e46441e4-923d-4" blockspare-animation=""><style>.blockspare-e46441e4-923d-4 > .blockspare-block-container-wrapper{background-color:#000000;padding-top:180px;padding-right:20px;padding-bottom:120px;padding-left:20px;margin-top:0px;margin-right:0px;margin-bottom:0px;margin-left:0px;border-radius:0}.blockspare-e46441e4-923d-4 .blockspare-image-wrap{background-image:none}</style><div class="blockspare-block-container-wrapper blockspare-hover-item"><div class="blockspare-container-background blockspare-image-wrap has-background-opacity-100 has-background-opacity"></div><div class="blockspare-container"><div class="blockspare-inner-blocks blockspare-inner-wrapper-blocks"><!-- wp:group {"layout":{"type":"constrained"}} -->
                        <div class="wp-block-group"><!-- wp:columns {"verticalAlignment":"center","align":"wide"} -->
                        <div class="wp-block-columns alignwide are-vertically-aligned-center"><!-- wp:column {"verticalAlignment":"center"} -->
                        <div class="wp-block-column is-vertically-aligned-center"><!-- wp:image {"id":15,"sizeSlug":"large","linkDestination":"none","style":{"border":{"radius":"20px"}}} -->
                        <figure class="wp-block-image size-large has-custom-border"><img src="https://blockspare.com/demo/default/travel/wp-content/uploads/sites/29/2023/06/image-16-1024x585.png" alt="" class="wp-image-15" style="border-radius:20px"/></figure>
                        <!-- /wp:image --></div>
                        <!-- /wp:column -->
                        
                        <!-- wp:column {"verticalAlignment":"center"} -->
                        <div class="wp-block-column is-vertically-aligned-center"><!-- wp:blockspare/blockspare-section-header {"uniqueClass":"blockspare-2021ebaa-9994-4","headerTitle":"WE CAN NOT WAIT TO SHARE \u003cmark style=\u0022background-color:rgba(0, 0, 0, 0);color:#00d9f2\u0022 class=\u0022has-inline-color\u0022\u003eADVENTURE\u003c/mark\u003e WITH YOU","titleFontSize":48,"headerSubTitle":"Sit amet consectetur integer tincidunt sceleries noda lesry volutpat neque fermentum malesuada scelequecy leocras odio blandit rhoncus eues feugiat.","headertitleColor":"#F6F6F6","headersubtitleColor":"#e3e3e3","headermarginTop":0,"headermarginRight":50,"headermarginBottom":0,"headermarginLeft":50,"subtitlePaddingTop":5,"titleFontFamily":"DM Sans","titleFontWeight":"700","titleLoadGoogleFonts":true,"subTitleFontFamily":"DM Sans","subTitleFontWeight":"400","subTitleLoadGoogleFonts":true,"animation":"AFTfadeInDown"} -->
                        <div class="wp-block-blockspare-blockspare-section-header aligncenter blockspare-2021ebaa-9994-4 blockspare-section-header-wrapper blockspare-blocks aligncenter blockspare-block-animation" blockspare-animation="AFTfadeInDown"><style>.blockspare-2021ebaa-9994-4 .blockspare-section-head-wrap{background-color:transparent;text-align:left;margin-top:0px;margin-right:50px;margin-bottom:0px;margin-left:50px}.blockspare-2021ebaa-9994-4 .blockspare-section-head-wrap .blockspare-title{color:#F6F6F6;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px;font-size:48px;font-family:DM Sans;font-weight:700}.blockspare-2021ebaa-9994-4 .blockspare-section-head-wrap .blockspare-subtitle{color:#e3e3e3;font-size:14px;font-family:DM Sans;font-weight:400;padding-top:5px;padding-right:0px;padding-bottom:0px;padding-left:0px}@media screen and (max-width:1025px){.blockspare-2021ebaa-9994-4 .blockspare-section-head-wrap .blockspare-title{font-size:22px}.blockspare-2021ebaa-9994-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}@media screen and (max-width:768px){.blockspare-2021ebaa-9994-4 .blockspare-section-head-wrap .blockspare-title{font-size:20px}.blockspare-2021ebaa-9994-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}</style><div class="blockspare-section-head-wrap blockspare-style1 blockspare-left blockspare-hover-item"><div class="blockspare-title-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><h2 class="blockspare-title">WE CAN NOT WAIT TO SHARE <mark style="background-color:rgba(0, 0, 0, 0);color:#00d9f2" class="has-inline-color">ADVENTURE</mark> WITH YOU</h2><span class="blockspare-title-dash blockspare-lower-dash"></span></div><div class="blockspare-subtitle-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><p class="blockspare-subtitle">Sit amet consectetur integer tincidunt sceleries noda lesry volutpat neque fermentum malesuada scelequecy leocras odio blandit rhoncus eues feugiat.</p><span class="blockspare-title-dash blockspare-lower-dash"></span></div></div></div>
                        <!-- /wp:blockspare/blockspare-section-header --></div>
                        <!-- /wp:column --></div>
                        <!-- /wp:columns --></div>
                        <!-- /wp:group --></div></div></div></div>
                        <!-- /wp:blockspare/blockspare-container -->',
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'travel',
                        'item'     => ['Travel','Counter'], 
						'key'      => 'bs_section_229',
                        'imagePath'    => 'sections',
						'name'     => 'Travel Section 6',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/travel-section-6/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'travel',
                        'item'     => ['Travel','User Profile'], 
						'key'      => 'bs_section_230',
                        'imagePath'    => 'sections',
						'name'     => 'Travel Section 7',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/travel-section-7/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'travel',
                        'item'     => ['Travel','Testimonial'], 
						'key'      => 'bs_section_231',
                        'imagePath'    => 'sections',
						'name'     => 'Travel Section 8',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/travel-section-8/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'travel',
                        'item'     => ['Travel','Pricing Table'], 
						'key'      => 'bs_section_232',
                        'imagePath'    => 'sections',
						'name'     => 'Travel Section 9',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/travel-section-9/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'travel',
                        'item'     => ['Travel','Call To Action'], 
						'key'      => 'bs_section_233',
                        'imagePath'    => 'sections',
						'name'     => 'Travel Section 10',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/travel-section-10/",
						'content'  => '<!-- wp:blockspare/blockspare-call-to-action {"uniqueClass":"blockspare-c6794fa3-3b84-4","align":"full","headerTitle":"READY TO ENJOY NATURE \u0026amp; \u003cbr\u003eEXPERIENCE REAL \u003cmark style=\u0022background-color:rgba(0, 0, 0, 0);color:#00d9f2\u0022 class=\u0022has-inline-color\u0022\u003eADVENTURE\u003c/mark\u003e","titleFontSize":48,"headerSubTitle":"Enjoy Real Adventure","subtitlePaddingTop":10,"subtitlePaddingBottom":20,"titleFontFamily":"DM Sans","titleFontWeight":"700","titleLoadGoogleFonts":true,"subTitleFontFamily":"DM Sans","subTitleLoadGoogleFonts":true,"imgURL":"https://blockspare.com/demo/default/travel/wp-content/uploads/sites/29/2023/06/image-19.png","imgID":45,"imgAlt":"","opacityRatio":70,"buttonText":"Experience Adventure","buttonBackgroundColor":"#ffffff","buttonTextColor":"#000000","marginTop":0,"marginBottom":0} -->
                        <div class="wp-block-blockspare-blockspare-call-to-action blockspare-c6794fa3-3b84-4 alignfull blockspare-calltoaction" blockspare-animation=""><style>.blockspare-c6794fa3-3b84-4 .blockspare-cta-wrapper{background-color:#0e0e0e;text-align:center;padding-top:20px;padding-right:20px;padding-bottom:20px;padding-left:20px;margin-top:0px;margin-right:0px;margin-bottom:0px;margin-left:0px;border-radius:null}.blockspare-c6794fa3-3b84-4 .blockspare-block-button a.blockspare-button{padding-top:12px;padding-right:22px;padding-bottom:12px;padding-left:22px;margin-top:10px;margin-right:0px;margin-bottom:0px;margin-left:0px;color:#000000;border-width:2px;font-size:16px}.blockspare-c6794fa3-3b84-4.wp-block-blockspare-blockspare-call-to-action .blockspare-block-button .blockspare-button{background-color:#ffffff}.blockspare-c6794fa3-3b84-4.wp-block-blockspare-blockspare-call-to-action .blockspare-block-button .blockspare-button:visited{background-color:#ffffff}.blockspare-c6794fa3-3b84-4.wp-block-blockspare-blockspare-call-to-action .blockspare-block-button .blockspare-button:focus{background-color:#ffffff}.blockspare-c6794fa3-3b84-4 .blockspare-section-head-wrap{background-color:transparent;text-align:center;margin-top:5px;margin-right:0px;margin-bottom:5px;margin-left:0px}.blockspare-c6794fa3-3b84-4 .blockspare-section-head-wrap .blockspare-title{color:#fff;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px;font-size:48px;font-family:DM Sans;font-weight:700}.blockspare-c6794fa3-3b84-4 .blockspare-section-head-wrap .blockspare-subtitle{color:#fff;font-size:14px;font-family:DM Sans;padding-top:10px;padding-right:0px;padding-bottom:20px;padding-left:0px}@media screen and (max-width:1025px){.blockspare-c6794fa3-3b84-4 .blockspare-block-button a.blockspare-button{font-size:14px}.blockspare-c6794fa3-3b84-4 .blockspare-section-head-wrap .blockspare-title{font-size:22px}.blockspare-c6794fa3-3b84-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}@media screen and (max-width:768px){.blockspare-c6794fa3-3b84-4 .blockspare-block-button a.blockspare-button{font-size:14px}.blockspare-c6794fa3-3b84-4 .blockspare-section-head-wrap .blockspare-title{font-size:20px}.blockspare-c6794fa3-3b84-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}</style><div class="blockspare-cta-wrapper blockspare-blocks blockspare-hover-item"><div class="blockspare-image-wrap blockspare-cta-background has-background-opacity-70 has-background-opacity" style="background-image:url(https://blockspare.com/demo/default/travel/wp-content/uploads/sites/29/2023/06/image-19.png)"></div><div class="blockspare-section-header-wrapper blockspare-blocks"><div class="blockspare-section-head-wrap blockspare-style1 blockspare-center"><div class="blockspare-title-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><h2 class="blockspare-title">READY TO ENJOY NATURE &amp; <br>EXPERIENCE REAL <mark style="background-color:rgba(0, 0, 0, 0);color:#00d9f2" class="has-inline-color">ADVENTURE</mark></h2><span class="blockspare-title-dash blockspare-lower-dash"></span></div><div class="blockspare-subtitle-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><p class="blockspare-subtitle">Enjoy Real Adventure</p><span class="blockspare-title-dash blockspare-lower-dash"></span></div></div></div><div class="blockspare-block-button"><a class="blockspare-button blockspare-button-shape-rounded blockspare-button-size-small"><span>Experience Adventure</span></a></div></div></div>
                        <!-- /wp:blockspare/blockspare-call-to-action -->',
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'travel',
                        'item'     => ['Travel','Accordion'], 
						'key'      => 'bs_section_234',
                        'imagePath'    => 'sections',
						'name'     => 'Travel Section 11',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/travel-section-11/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'cinema',
                        'item'     => ['Cinema','Image Slider'], 
						'key'      => 'bs_section_235',
                        'imagePath'    => 'sections',
						'name'     => 'Cinema Section 1',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/cinema-section-1/",
						'content'  => '<!-- wp:group {"align":"full","style":{"spacing":{"blockGap":"0"}},"layout":{"type":"constrained"}} -->
                        <div class="wp-block-group alignfull"><!-- wp:blockspare/blockspare-slider {"align":"full","uniqueClass":"blockspare-8324bd7e-8105-4","images":[{"alt":"","id":225,"link":"https://blockspare.com/demo/default/cinemax/home/cinema-banner1/","caption":"","sizes":{"thumbnail":{"height":150,"width":150,"url":"https://blockspare.com/demo/default/cinemax/wp-content/uploads/sites/30/2023/06/cinema-banner1-150x150.jpg","orientation":"landscape"},"medium":{"height":104,"width":300,"url":"https://blockspare.com/demo/default/cinemax/wp-content/uploads/sites/30/2023/06/cinema-banner1-300x104.jpg","orientation":"landscape"},"large":{"height":355,"width":1024,"url":"https://blockspare.com/demo/default/cinemax/wp-content/uploads/sites/30/2023/06/cinema-banner1-1024x355.jpg","orientation":"landscape"},"full":{"url":"https://blockspare.com/demo/default/cinemax/wp-content/uploads/sites/30/2023/06/cinema-banner1.jpg","height":665,"width":1920,"orientation":"landscape"}},"url":"https://blockspare.com/demo/default/cinemax/wp-content/uploads/sites/30/2023/06/cinema-banner1-1024x355.jpg","imgLink":""},{"alt":"","id":226,"link":"https://blockspare.com/demo/default/cinemax/home/cinema-banner2/","caption":"","sizes":{"thumbnail":{"height":150,"width":150,"url":"https://blockspare.com/demo/default/cinemax/wp-content/uploads/sites/30/2023/06/cinema-banner2-150x150.jpg","orientation":"landscape"},"medium":{"height":104,"width":300,"url":"https://blockspare.com/demo/default/cinemax/wp-content/uploads/sites/30/2023/06/cinema-banner2-300x104.jpg","orientation":"landscape"},"large":{"height":355,"width":1024,"url":"https://blockspare.com/demo/default/cinemax/wp-content/uploads/sites/30/2023/06/cinema-banner2-1024x355.jpg","orientation":"landscape"},"full":{"url":"https://blockspare.com/demo/default/cinemax/wp-content/uploads/sites/30/2023/06/cinema-banner2.jpg","height":665,"width":1920,"orientation":"landscape"}},"url":"https://blockspare.com/demo/default/cinemax/wp-content/uploads/sites/30/2023/06/cinema-banner2-1024x355.jpg","imgLink":""},{"alt":"","id":227,"link":"https://blockspare.com/demo/default/cinemax/home/banner-cinema3/","caption":"","sizes":{"thumbnail":{"height":150,"width":150,"url":"https://blockspare.com/demo/default/cinemax/wp-content/uploads/sites/30/2023/06/banner-cinema3-150x150.jpg","orientation":"landscape"},"medium":{"height":104,"width":300,"url":"https://blockspare.com/demo/default/cinemax/wp-content/uploads/sites/30/2023/06/banner-cinema3-300x104.jpg","orientation":"landscape"},"large":{"height":355,"width":1024,"url":"https://blockspare.com/demo/default/cinemax/wp-content/uploads/sites/30/2023/06/banner-cinema3-1024x355.jpg","orientation":"landscape"},"full":{"url":"https://blockspare.com/demo/default/cinemax/wp-content/uploads/sites/30/2023/06/banner-cinema3.jpg","height":665,"width":1920,"orientation":"landscape"}},"url":"https://blockspare.com/demo/default/cinemax/wp-content/uploads/sites/30/2023/06/banner-cinema3-1024x355.jpg","imgLink":""}],"enableEqualHeight":false,"marginTop":0,"marginBottom":0} -->
                        <div class="wp-block-blockspare-blockspare-slider alignfull blockspare-8324bd7e-8105-4" blockspare-animation=""><style>.blockspare-8324bd7e-8105-4 .blockspare-slider-wrap span:before{color:#000}.blockspare-8324bd7e-8105-4 .blockspare-slider-wrap ul li button{color:#000}.blockspare-8324bd7e-8105-4 .blockspare-slider-wrap .slick-slider .slick-dots > li button{background-color:#000}.blockspare-8324bd7e-8105-4 .blockspare-slider-wrap .blockspare-gallery-figure{border-radius:0px}.blockspare-8324bd7e-8105-4 .slick-slider .slick-arrow:after{background-color:#fff}.blockspare-8324bd7e-8105-4 .blockspare-slider-wrap{margin-top:0px;margin-right:0px;margin-bottom:0px;margin-left:0px}</style><div class="blockspare-blocks blockspare-slider-wrap has-gutter-space-1 blockspare-original lpc-navigation-1 blockspare-navigation-small"><div class="blockspare-carousel-items"><div data-next="fas fa-chevron-right" data-prev="fas fa-chevron-left" data-slick="{&quot;autoplay&quot;:true,&quot;slidesToShow&quot;:1,&quot;speed&quot;:&quot;1000&quot;,&quot;arrows&quot;:true,&quot;dots&quot;:false}"><div><div class="blockspare-gallery-figure blockspare-hover-item"> <img src="https://blockspare.com/demo/default/cinemax/wp-content/uploads/sites/30/2023/06/cinema-banner1.jpg"/></div></div><div><div class="blockspare-gallery-figure blockspare-hover-item"> <img src="https://blockspare.com/demo/default/cinemax/wp-content/uploads/sites/30/2023/06/cinema-banner2.jpg"/></div></div><div><div class="blockspare-gallery-figure blockspare-hover-item"> <img src="https://blockspare.com/demo/default/cinemax/wp-content/uploads/sites/30/2023/06/banner-cinema3.jpg"/></div></div></div></div></div></div>
                        <!-- /wp:blockspare/blockspare-slider -->
                        
                        <!-- wp:blockspare/blockspare-container {"paddingTop":50,"paddingBottom":50,"marginTop":0,"marginBottom":0,"uniqueClass":"blockspare-09687aaf-e25f-4","backGroundColor":"#000000"} -->
                        <div class="wp-block-blockspare-blockspare-container alignfull blockspare-09687aaf-e25f-4" blockspare-animation=""><style>.blockspare-09687aaf-e25f-4 > .blockspare-block-container-wrapper{background-color:#000000;padding-top:50px;padding-right:20px;padding-bottom:50px;padding-left:20px;margin-top:0px;margin-right:0px;margin-bottom:0px;margin-left:0px;border-radius:0}.blockspare-09687aaf-e25f-4 .blockspare-image-wrap{background-image:none}</style><div class="blockspare-block-container-wrapper blockspare-hover-item"><div class="blockspare-container-background blockspare-image-wrap has-background-opacity-100 has-background-opacity"></div><div class="blockspare-container"><div class="blockspare-inner-blocks blockspare-inner-wrapper-blocks"><!-- wp:group {"align":"wide","layout":{"type":"constrained"}} -->
                        <div class="wp-block-group alignwide"><!-- wp:blockspare/blockspare-section-header {"uniqueClass":"blockspare-9fb3731e-ad5b-4","align":"wide","sectionAlignment":"center","headerTitle":"You Can’t Decide What Movie to Watch Next? \u003cmark style=\u0022background-color:rgba(0, 0, 0, 0);color:#dd0000\u0022 class=\u0022has-inline-color\u0022\u003eWatch Here\u003c/mark\u003e","headertitleColor":"#fcb900","titleFontFamily":"Open Sans","titleFontWeight":"700","titleLoadGoogleFonts":true,"subTitleFontSize":0,"subTitleFontFamily":"Lato","subTitleLoadGoogleFonts":true} -->
                        <div class="wp-block-blockspare-blockspare-section-header alignwide blockspare-9fb3731e-ad5b-4 blockspare-section-header-wrapper blockspare-blocks alignwide" blockspare-animation=""><style>.blockspare-9fb3731e-ad5b-4 .blockspare-section-head-wrap{background-color:transparent;text-align:center;margin-top:30px;margin-right:0px;margin-bottom:30px;margin-left:0px}.blockspare-9fb3731e-ad5b-4 .blockspare-section-head-wrap .blockspare-title{color:#fcb900;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px;font-size:24px;font-family:Open Sans;font-weight:700}.blockspare-9fb3731e-ad5b-4 .blockspare-section-head-wrap .blockspare-subtitle{color:#6d6d6d;font-size:0px;font-family:Lato;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px}@media screen and (max-width:1025px){.blockspare-9fb3731e-ad5b-4 .blockspare-section-head-wrap .blockspare-title{font-size:22px}.blockspare-9fb3731e-ad5b-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}@media screen and (max-width:768px){.blockspare-9fb3731e-ad5b-4 .blockspare-section-head-wrap .blockspare-title{font-size:20px}.blockspare-9fb3731e-ad5b-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}</style><div class="blockspare-section-head-wrap blockspare-style1 blockspare-center blockspare-hover-item"><div class="blockspare-title-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><h2 class="blockspare-title">You Can’t Decide What Movie to Watch Next? <mark style="background-color:rgba(0, 0, 0, 0);color:#dd0000" class="has-inline-color">Watch Here</mark></h2><span class="blockspare-title-dash blockspare-lower-dash"></span></div><div class="blockspare-subtitle-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><span class="blockspare-title-dash blockspare-lower-dash"></span></div></div></div>
                        <!-- /wp:blockspare/blockspare-section-header --></div>
                        <!-- /wp:group --></div></div></div></div>
                        <!-- /wp:blockspare/blockspare-container --></div>
                        <!-- /wp:group -->',
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'cinema',
                        'item'     => ['Cinema','Tab'], 
						'key'      => 'bs_section_236',
                        'imagePath'    => 'sections',
						'name'     => 'Cinema Section 2',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/cinema-section-2/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'cinema',
                        'item'     => ['Cinema','Services'], 
						'key'      => 'bs_section_237',
                        'imagePath'    => 'sections',
						'name'     => 'Cinema Section 3',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/cinema-section-3/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'cinema',
                        'item'     => ['Cinema','Tab'], 
						'key'      => 'bs_section_238',
                        'imagePath'    => 'sections',
						'name'     => 'Cinema Section 4',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/cinema-section-4/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'cinema',
                        'item'     => ['Cinema','Testimonial'], 
						'key'      => 'bs_section_239',
                        'imagePath'    => 'sections',
						'name'     => 'Cinema Section 5',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/cinema-section-5/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'cinema',
                        'item'     => ['Cinema','User Profile'], 
						'key'      => 'bs_section_240',
                        'imagePath'    => 'sections',
						'name'     => 'Cinema Section 6',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/cinema-section-6/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'cinema',
                        'item'     => ['Cinema','Logo Grid'], 
						'key'      => 'bs_section_241',
                        'imagePath'    => 'sections',
						'name'     => 'Cinema Section 7',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/cinema-section-7/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'cinema',
                        'item'     => ['Cinema','Pricing Table'], 
						'key'      => 'bs_section_242',
                        'imagePath'    => 'sections',
						'name'     => 'Cinema Section 8',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/cinema-section-8/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'cinema',
                        'item'     => ['Cinema','Accordion'], 
						'key'      => 'bs_section_243',
                        'imagePath'    => 'sections',
						'name'     => 'Cinema Section 9',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/cinema-section-9/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'dentlo',
                        'item'     => ['Dentlo','Content Box'], 
						'key'      => 'bs_section_244',
                        'imagePath'    => 'sections',
						'name'     => 'Dentlo Section 1',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/dentol-section-1/",
						'content'  => '<!-- wp:blockspare/blockspare-container {"uniqueClass":"blockspare-483cda8e-8a86-4","backGroundColor":"#104099"} -->
                        <div class="wp-block-blockspare-blockspare-container alignfull blockspare-483cda8e-8a86-4" blockspare-animation=""><style>.blockspare-483cda8e-8a86-4 > .blockspare-block-container-wrapper{background-color:#104099;padding-top:20px;padding-right:20px;padding-bottom:20px;padding-left:20px;margin-top:30px;margin-right:0px;margin-bottom:30px;margin-left:0px;border-radius:0}.blockspare-483cda8e-8a86-4 .blockspare-image-wrap{background-image:none}</style><div class="blockspare-block-container-wrapper blockspare-hover-item"><div class="blockspare-container-background blockspare-image-wrap has-background-opacity-100 has-background-opacity"></div><div class="blockspare-container"><div class="blockspare-inner-blocks blockspare-inner-wrapper-blocks"><!-- wp:columns {"verticalAlignment":"center","align":"wide","style":{"spacing":{"padding":{"top":"var:preset|spacing|70","right":"0","bottom":"0","left":"0"}}}} -->
                        <div class="wp-block-columns alignwide are-vertically-aligned-center" style="padding-top:var(--wp--preset--spacing--70);padding-right:0;padding-bottom:0;padding-left:0"><!-- wp:column {"verticalAlignment":"center"} -->
                        <div class="wp-block-column is-vertically-aligned-center"><!-- wp:image {"id":377,"sizeSlug":"large","linkDestination":"none"} -->
                        <figure class="wp-block-image size-large"><img src="https://blockspare.com/demo/default/dentist/wp-content/uploads/sites/31/2023/08/home-1024x877.png" alt="" class="wp-image-377"/></figure>
                        <!-- /wp:image --></div>
                        <!-- /wp:column -->
                        
                        <!-- wp:column {"verticalAlignment":"center","style":{"spacing":{"blockGap":"0","padding":{"top":"0","right":"0","bottom":"0","left":"0"}}}} -->
                        <div class="wp-block-column is-vertically-aligned-center" style="padding-top:0;padding-right:0;padding-bottom:0;padding-left:0"><!-- wp:blockspare/blockspare-section-header {"uniqueClass":"blockspare-a07ff22f-5a3a-4","headerTitle":"Your Smile","titleFontSize":56,"headerSubTitle":"Our Passion","headertitleColor":"#00af89","headersubtitleColor":"#ffffff","headermarginTop":0,"headermarginBottom":0,"headerlayoutOption":"blockspare-style16","dashColor":"#ffffff","titleFontFamily":"Playfair Display","titleFontWeight":"400","titleFontSizeMobile":24,"titleFontSizeTablet":32,"titleLoadGoogleFonts":true,"subTitleFontSize":88,"subTitleFontFamily":"Playfair Display","subTitleFontWeight":"700","subTitleFontSizeMobile":40,"subTitleFontSizeTablet":56,"subTitleLoadGoogleFonts":true} -->
                        <div class="wp-block-blockspare-blockspare-section-header aligncenter blockspare-a07ff22f-5a3a-4 blockspare-section-header-wrapper blockspare-blocks aligncenter" blockspare-animation=""><style>.blockspare-a07ff22f-5a3a-4 .blockspare-section-head-wrap{background-color:transparent;text-align:left;margin-top:0px;margin-right:0px;margin-bottom:0px;margin-left:0px}.blockspare-a07ff22f-5a3a-4 .blockspare-section-head-wrap .blockspare-title{color:#00af89;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px;font-size:56px;font-family:Playfair Display;font-weight:400}.blockspare-a07ff22f-5a3a-4 .blockspare-section-head-wrap .blockspare-title-wrapper .blockspare-title-dash{color:#ffffff!important}.blockspare-a07ff22f-5a3a-4 .blockspare-section-head-wrap .blockspare-subtitle{color:#ffffff;font-size:88px;font-family:Playfair Display;font-weight:700;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px}@media screen and (max-width:1025px){.blockspare-a07ff22f-5a3a-4 .blockspare-section-head-wrap .blockspare-title{font-size:32px}.blockspare-a07ff22f-5a3a-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:56px}}@media screen and (max-width:768px){.blockspare-a07ff22f-5a3a-4 .blockspare-section-head-wrap .blockspare-title{font-size:24px}.blockspare-a07ff22f-5a3a-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:40px}}</style><div class="blockspare-section-head-wrap blockspare-style16 blockspare-left blockspare-hover-item"><div class="blockspare-title-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><h2 class="blockspare-title">Your Smile</h2><span class="blockspare-title-dash blockspare-lower-dash"></span></div><div class="blockspare-subtitle-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><p class="blockspare-subtitle">Our Passion</p><span class="blockspare-title-dash blockspare-lower-dash"></span></div></div></div>
                        <!-- /wp:blockspare/blockspare-section-header -->
                        
                        <!-- wp:blockspare/blockspare-list {"className":"aligncenter","uniqueClass":"blockspare-5cbd44ac-0c80-4","listType":"none","color":"#ffffff","descriptionFontSize":18,"descriptionFontFamily":"Maven Pro","descriptionFontWeight":"400","descriptionFontSizeMobile":16,"descriptionFontSizeTablet":16,"descriptionLoadGoogleFonts":true,"marginTop":0,"marginBottom":0} -->
                        <div class="wp-block-blockspare-blockspare-list aligncenter blockspare-5cbd44ac-0c80-4 blockspare-block-iconlist-wrap" blockspare-animation=""><style>.blockspare-5cbd44ac-0c80-4 .blockspare-list-wrap{border-radius:0px;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px}.blockspare-5cbd44ac-0c80-4 .blockspare-list-wrap .listDescription li{color:#ffffff;text-align:left;font-size:18px;font-family:Maven Pro;font-weight:400}.blockspare-5cbd44ac-0c80-4 .blockspare-list-wrap .listDescription li:before{color:#404040}.blockspare-5cbd44ac-0c80-4 .listDescription li:before{font-size:16px}@media screen and (max-width:1025px){.blockspare-5cbd44ac-0c80-4 .blockspare-list-wrap .listDescription li{font-size:16px}.blockspare-5cbd44ac-0c80-4 .listDescription li:before{font-size:16px}}@media screen and (max-width:768px){.blockspare-5cbd44ac-0c80-4 .blockspare-list-wrap .listDescription li{font-size:16px}.blockspare-5cbd44ac-0c80-4 .listDescription li:before{font-size:16px}}</style><div class="blockspare-blocks blockspare-list-wrap blockspare-hover-item"><ul class="listDescription"><!-- wp:list-item -->
                        <li>At Dentlo, we are dedicated to providing exceptional dental care to patients of all ages. Our team of experienced and caring dentists is committed to helping you achieve and maintain a healthy, beautiful smile that lasts a lifetime.</li>
                        <!-- /wp:list-item --></ul></div></div>
                        <!-- /wp:blockspare/blockspare-list -->
                        
                        <!-- wp:blockspare/blockspare-buttons {"sectionAlignment":"left","uniqueClass":"blockspare-f2cafcb7-a0f7-4","buttonText":"Schedule an Appointment","buttonBackgroundColor":"#ffffff","buttonTextColor":"#00af89","buttonShape":"blockspare-button-shape-circular","buttonHoverEffect":"hover-style-2","buttonFontSize":18,"buttonFontFamily":"Playfair Display","buttonFontWeight":"400","buttonFontSizeMobile":16,"buttonFontSizeTablet":16,"buttonLoadGoogleFonts":true,"enableButtonIcon":true,"buttonIcon":"fas fa-angle-right","buttonIconColor":"#00af89","paddingTop":16,"paddingRight":32,"paddingBottom":16,"paddingLeft":32,"marginTop":32,"marginBottom":0} -->
                        <div class="wp-block-blockspare-blockspare-buttons blockspare-f2cafcb7-a0f7-4 blockspare-block-button-wrap" blockspare-animation=""><style>.blockspare-f2cafcb7-a0f7-4 .blockspare-block-button{text-align:left;margin-top:32px;margin-bottom:0px;margin-left:0px;margin-right:0px}.blockspare-f2cafcb7-a0f7-4 .blockspare-block-button i{color:#00af89;font-size:18px}.blockspare-f2cafcb7-a0f7-4 .blockspare-block-button span{color:#00af89;border-width:2px;font-size:18px;font-family:Playfair Display;font-weight:400}.blockspare-f2cafcb7-a0f7-4 .blockspare-block-button .blockspare-button{background-color:#ffffff}.blockspare-f2cafcb7-a0f7-4 .blockspare-block-button .blockspare-button:visited{background-color:#ffffff}.blockspare-f2cafcb7-a0f7-4 .blockspare-block-button .blockspare-button:focus{background-color:#ffffff}.blockspare-f2cafcb7-a0f7-4 .blockspare-block-button a{padding-top:16px;padding-bottom:16px;padding-right:32px;padding-left:32px}@media screen and (max-width:1025px){.blockspare-f2cafcb7-a0f7-4 .blockspare-block-button span{font-size:16px}.blockspare-f2cafcb7-a0f7-4 .blockspare-block-button i{font-size:16px}}@media screen and (max-width:768px){.blockspare-f2cafcb7-a0f7-4 .blockspare-block-button span{font-size:16px}.blockspare-f2cafcb7-a0f7-4 .blockspare-block-button i{font-size:16px}}</style><div class="blockspare-block-button"><a class="blockspare-button blockspare-button-shape-circular blockspare-button-size-small hover-style-2 btn-icon-left"><i class="fas fa-angle-right"></i><span>Schedule an Appointment</span></a></div></div>
                        <!-- /wp:blockspare/blockspare-buttons --></div>
                        <!-- /wp:column --></div>
                        <!-- /wp:columns --></div></div></div></div>
                        <!-- /wp:blockspare/blockspare-container -->',
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'dentlo',
                        'item'     => ['Dentlo','Services'], 
						'key'      => 'bs_section_245',
                        'imagePath'    => 'sections',
						'name'     => 'Dentlo Section 2',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/dentol-section-2/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'dentlo',
                        'item'     => ['Dentlo','User Profile'], 
						'key'      => 'bs_section_246',
                        'imagePath'    => 'sections',
						'name'     => 'Dentlo Section 3',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/dentol-section-3",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'dentlo',
                        'item'     => ['Dentlo','Testimonial'], 
						'key'      => 'bs_section_247',
                        'imagePath'    => 'sections',
						'name'     => 'Dentlo Section 4',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/dentol-section-4",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'dentlo',
                        'item'     => ['Dentlo','Progress Bar'], 
						'key'      => 'bs_section_248',
                        'imagePath'    => 'sections',
						'name'     => 'Dentlo Section 5',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/dentol-section-5",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'hair-salon',
                        'item'     => ['Hair Salon','Call To Action'], 
						'key'      => 'bs_section_249',
                        'imagePath'    => 'sections',
						'name'     => 'Hair Salon Section 1',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/hair-salon-section-1/",
						'content'  => '<!-- wp:blockspare/blockspare-container {"paddingTop":0,"paddingRight":0,"paddingBottom":0,"paddingLeft":0,"marginTop":0,"marginBottom":0,"uniqueClass":"blockspare-ab384916-2af1-4","backGroundColor":"#ffffff"} -->
                        <div class="wp-block-blockspare-blockspare-container alignfull blockspare-ab384916-2af1-4" blockspare-animation=""><style>.blockspare-ab384916-2af1-4 > .blockspare-block-container-wrapper{background-color:#ffffff;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px;margin-top:0px;margin-right:0px;margin-bottom:0px;margin-left:0px;border-radius:0}.blockspare-ab384916-2af1-4 .blockspare-image-wrap{background-image:none}</style><div class="blockspare-block-container-wrapper blockspare-hover-item"><div class="blockspare-container-background blockspare-image-wrap has-background-opacity-100 has-background-opacity"></div><div class="blockspare-container"><div class="blockspare-inner-blocks blockspare-inner-wrapper-blocks"><!-- wp:cover {"url":"https://blockspare.com/demo/default/hair-salon/wp-content/uploads/sites/32/2023/08/pexels-ron-lach-10318045.jpg","id":39,"dimRatio":60,"customOverlayColor":"#0e2944","focalPoint":{"x":0.5,"y":0.56},"minHeight":60,"minHeightUnit":"vh"} -->
                        <div class="wp-block-cover" style="min-height:60vh"><span aria-hidden="true" class="wp-block-cover__background has-background-dim-60 has-background-dim" style="background-color:#0e2944"></span><img class="wp-block-cover__image-background wp-image-39" alt="" src="https://blockspare.com/demo/default/hair-salon/wp-content/uploads/sites/32/2023/08/pexels-ron-lach-10318045.jpg" style="object-position:50% 56%" data-object-fit="cover" data-object-position="50% 56%"/><div class="wp-block-cover__inner-container"><!-- wp:group {"style":{"spacing":{"blockGap":"var:preset|spacing|30"}},"layout":{"type":"constrained"}} -->
                        <div class="wp-block-group"><!-- wp:blockspare/blockspare-section-header {"uniqueClass":"blockspare-b27a9c59-0dcf-4","align":"wide","sectionAlignment":"center","headerTitle":"Unlock Your Hair’s Potential!","titleFontSize":72,"headertitleColor":"#ffffff","headermarginTop":0,"headermarginBottom":0,"titleFontFamily":"Cormorant Garamond","titleFontWeight":"500","titleFontSizeMobile":40,"titleFontSizeTablet":56,"titleLoadGoogleFonts":true} -->
                        <div class="wp-block-blockspare-blockspare-section-header alignwide blockspare-b27a9c59-0dcf-4 blockspare-section-header-wrapper blockspare-blocks alignwide" blockspare-animation=""><style>.blockspare-b27a9c59-0dcf-4 .blockspare-section-head-wrap{background-color:transparent;text-align:center;margin-top:0px;margin-right:0px;margin-bottom:0px;margin-left:0px}.blockspare-b27a9c59-0dcf-4 .blockspare-section-head-wrap .blockspare-title{color:#ffffff;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px;font-size:72px;font-family:Cormorant Garamond;font-weight:500}.blockspare-b27a9c59-0dcf-4 .blockspare-section-head-wrap .blockspare-subtitle{color:#6d6d6d;font-size:14px;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px}@media screen and (max-width:1025px){.blockspare-b27a9c59-0dcf-4 .blockspare-section-head-wrap .blockspare-title{font-size:56px}.blockspare-b27a9c59-0dcf-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}@media screen and (max-width:768px){.blockspare-b27a9c59-0dcf-4 .blockspare-section-head-wrap .blockspare-title{font-size:40px}.blockspare-b27a9c59-0dcf-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}</style><div class="blockspare-section-head-wrap blockspare-style1 blockspare-center blockspare-hover-item"><div class="blockspare-title-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><h2 class="blockspare-title">Unlock Your Hair’s Potential!</h2><span class="blockspare-title-dash blockspare-lower-dash"></span></div><div class="blockspare-subtitle-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><span class="blockspare-title-dash blockspare-lower-dash"></span></div></div></div>
                        <!-- /wp:blockspare/blockspare-section-header -->
                        
                        <!-- wp:blockspare/blockspare-buttons {"uniqueClass":"blockspare-feb4f269-c235-4","buttonText":"Book Now for Gorgeous Hair!","buttonBackgroundColor":"#c8ab74","buttonShape":"blockspare-button-shape-square","buttonFontFamily":"Poppins","buttonFontWeight":"500","buttonLoadGoogleFonts":true,"paddingRight":26,"paddingLeft":26,"marginTop":0,"marginBottom":0} -->
                        <div class="wp-block-blockspare-blockspare-buttons blockspare-feb4f269-c235-4 blockspare-block-button-wrap" blockspare-animation=""><style>.blockspare-feb4f269-c235-4 .blockspare-block-button{text-align:center;margin-top:0px;margin-bottom:0px;margin-left:0px;margin-right:0px}.blockspare-feb4f269-c235-4 .blockspare-block-button span{color:#fff;border-width:2px;font-size:16px;font-family:Poppins;font-weight:500}.blockspare-feb4f269-c235-4 .blockspare-block-button .blockspare-button{background-color:#c8ab74}.blockspare-feb4f269-c235-4 .blockspare-block-button .blockspare-button:visited{background-color:#c8ab74}.blockspare-feb4f269-c235-4 .blockspare-block-button .blockspare-button:focus{background-color:#c8ab74}.blockspare-feb4f269-c235-4 .blockspare-block-button i{font-size:16px}.blockspare-feb4f269-c235-4 .blockspare-block-button a{padding-top:12px;padding-bottom:12px;padding-right:26px;padding-left:26px}@media screen and (max-width:1025px){.blockspare-feb4f269-c235-4 .blockspare-block-button span{font-size:14px}.blockspare-feb4f269-c235-4 .blockspare-block-button i{font-size:14px}}@media screen and (max-width:768px){.blockspare-feb4f269-c235-4 .blockspare-block-button span{font-size:14px}.blockspare-feb4f269-c235-4 .blockspare-block-button i{font-size:14px}}</style><div class="blockspare-block-button"><a class="blockspare-button blockspare-button-shape-square blockspare-button-size-small"><span>Book Now for Gorgeous Hair!</span></a></div></div>
                        <!-- /wp:blockspare/blockspare-buttons --></div>
                        <!-- /wp:group --></div></div>
                        <!-- /wp:cover -->
                        
                        <!-- wp:blockspare/shape-divider {"design":"split","width":2,"height":76,"verticalFlip":true,"color":"#ffffff","marginTop":-76,"marginBottom":0,"uniqueClass":"blockspare-edbcbee2-30b5-4"} -->
                        <div class="wp-block-blockspare-shape-divider alignfull blockspare-edbcbee2-30b5-4" blockspare-animation=""><style>.blockspare-edbcbee2-30b5-4 .blockspare-svg-wrapper{color:#ffffff;height:76px;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px;margin-top:-76px;margin-right:0px;margin-bottom:0px;margin-left:0px}</style><div class="blockspare-blocks blockspare-svg-wrapper blockspare-hover-item is-vertically-flipped has-width-2"><div class="blockspare-svg-svg-inner blockspare-separator-wrapper"><svg class="split" preserveAspectRatio="none" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1000 20"><path d="M0 0v3h483.4c9.2 0 16.6 7.4 16.6 16.6 0-9.1 7.4-16.6 16.6-16.6H1000V0H0z"></path></svg></div></div></div>
                        <!-- /wp:blockspare/shape-divider --></div></div></div></div>
                        <!-- /wp:blockspare/blockspare-container -->',
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'hair-salon',
                        'item'     => ['Hair Salon','Image Masonry'], 
						'key'      => 'bs_section_250',
                        'imagePath'    => 'sections',
						'name'     => 'Hair Salon Section 2',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/hair-salon-section-2/",
						'content'  => '<!-- wp:blockspare/blockspare-container {"paddingTop":0,"paddingRight":0,"paddingBottom":0,"paddingLeft":0,"marginTop":-20,"marginBottom":0,"uniqueClass":"blockspare-83957751-2212-4","backGroundColor":"#ffffff"} -->
                        <div class="wp-block-blockspare-blockspare-container alignfull blockspare-83957751-2212-4" blockspare-animation=""><style>.blockspare-83957751-2212-4 > .blockspare-block-container-wrapper{background-color:#ffffff;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px;margin-top:-20px;margin-right:0px;margin-bottom:0px;margin-left:0px;border-radius:0}.blockspare-83957751-2212-4 .blockspare-image-wrap{background-image:none}</style><div class="blockspare-block-container-wrapper blockspare-hover-item"><div class="blockspare-container-background blockspare-image-wrap has-background-opacity-100 has-background-opacity"></div><div class="blockspare-container"><div class="blockspare-inner-blocks blockspare-inner-wrapper-blocks"><!-- wp:group {"style":{"spacing":{"padding":{"top":"var:preset|spacing|60","bottom":"var:preset|spacing|70","right":"var:preset|spacing|30","left":"var:preset|spacing|30"},"blockGap":"var:preset|spacing|50"}},"layout":{"type":"constrained"}} -->
                        <div class="wp-block-group" style="padding-top:var(--wp--preset--spacing--60);padding-right:var(--wp--preset--spacing--30);padding-bottom:var(--wp--preset--spacing--70);padding-left:var(--wp--preset--spacing--30)"><!-- wp:group {"layout":{"type":"constrained"}} -->
                        <div class="wp-block-group"><!-- wp:blockspare/blockspare-section-header {"uniqueClass":"blockspare-52ab4d99-79b1-4","sectionAlignment":"center","headerTitle":"Your Hair New Home","titleFontSize":56,"headerSubTitle":"Welcome to Infinity","headertitleColor":"#0e2944","headersubtitleColor":"#c8ab74","headermarginTop":0,"headermarginBottom":0,"headerlayoutOption":"blockspare-style20","titlePaddingBottom":16,"subtitlePaddingBottom":10,"dashColor":"#c8ab74","titleFontFamily":"Cormorant Garamond","titleFontWeight":"500","titleFontSizeMobile":32,"titleFontSizeTablet":48,"titleLoadGoogleFonts":true,"subTitleFontSize":24,"subTitleFontFamily":"Cormorant Garamond","subTitleFontWeight":"500","subTitleFontSizeMobile":20,"subTitleFontSizeTablet":22,"subTitleLoadGoogleFonts":true} -->
                        <div class="wp-block-blockspare-blockspare-section-header aligncenter blockspare-52ab4d99-79b1-4 blockspare-section-header-wrapper blockspare-blocks aligncenter" blockspare-animation=""><style>.blockspare-52ab4d99-79b1-4 .blockspare-section-head-wrap{background-color:transparent;text-align:center;margin-top:0px;margin-right:0px;margin-bottom:0px;margin-left:0px}.blockspare-52ab4d99-79b1-4 .blockspare-section-head-wrap .blockspare-title{color:#0e2944;padding-top:0px;padding-right:0px;padding-bottom:16px;padding-left:0px;font-size:56px;font-family:Cormorant Garamond;font-weight:500}.blockspare-52ab4d99-79b1-4 .blockspare-section-head-wrap .blockspare-title-wrapper .blockspare-lower-dash{background-color:#c8ab74!important}.blockspare-52ab4d99-79b1-4 .blockspare-section-head-wrap .blockspare-subtitle{color:#c8ab74;font-size:24px;font-family:Cormorant Garamond;font-weight:500;padding-top:0px;padding-right:0px;padding-bottom:10px;padding-left:0px}@media screen and (max-width:1025px){.blockspare-52ab4d99-79b1-4 .blockspare-section-head-wrap .blockspare-title{font-size:48px}.blockspare-52ab4d99-79b1-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:22px}}@media screen and (max-width:768px){.blockspare-52ab4d99-79b1-4 .blockspare-section-head-wrap .blockspare-title{font-size:32px}.blockspare-52ab4d99-79b1-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:20px}}</style><div class="blockspare-section-head-wrap blockspare-style20 blockspare-center blockspare-hover-item"><div class="blockspare-title-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><h2 class="blockspare-title">Your Hair New Home</h2><span class="blockspare-title-dash blockspare-lower-dash"></span></div><div class="blockspare-subtitle-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><p class="blockspare-subtitle">Welcome to Infinity</p><span class="blockspare-title-dash blockspare-lower-dash"></span></div></div></div>
                        <!-- /wp:blockspare/blockspare-section-header -->
                        
                        <!-- wp:blockspare/blockspare-list {"className":"aligncenter","uniqueClass":"blockspare-1d02983e-fa98-4","sectionAlignment":"center","listType":"none","color":"#6e6e6e","descriptionFontSize":20,"descriptionFontFamily":"Cormorant Garamond","descriptionFontWeight":"500","descriptionFontSizeMobile":18,"descriptionFontSizeTablet":18,"descriptionLoadGoogleFonts":true,"marginTop":0,"marginBottom":0} -->
                        <div class="wp-block-blockspare-blockspare-list aligncenter blockspare-1d02983e-fa98-4 blockspare-block-iconlist-wrap" blockspare-animation=""><style>.blockspare-1d02983e-fa98-4 .blockspare-list-wrap{border-radius:0px;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px}.blockspare-1d02983e-fa98-4 .blockspare-list-wrap .listDescription li{color:#6e6e6e;text-align:center;font-size:20px;font-family:Cormorant Garamond;font-weight:500}.blockspare-1d02983e-fa98-4 .blockspare-list-wrap .listDescription li:before{color:#404040}.blockspare-1d02983e-fa98-4 .listDescription li:before{font-size:18px}@media screen and (max-width:1025px){.blockspare-1d02983e-fa98-4 .blockspare-list-wrap .listDescription li{font-size:18px}.blockspare-1d02983e-fa98-4 .listDescription li:before{font-size:18px}}@media screen and (max-width:768px){.blockspare-1d02983e-fa98-4 .blockspare-list-wrap .listDescription li{font-size:18px}.blockspare-1d02983e-fa98-4 .listDescription li:before{font-size:18px}}</style><div class="blockspare-blocks blockspare-list-wrap blockspare-hover-item"><ul class="listDescription"><!-- wp:list-item -->
                        <li>Step into a world of beauty and luxury at Infinity Salon, where your hair dreams come true. We are thrilled to welcome you to our esteemed salon, where style, skill, and innovation come together to create unforgettable hair experiences.</li>
                        <!-- /wp:list-item --></ul></div></div>
                        <!-- /wp:blockspare/blockspare-list --></div>
                        <!-- /wp:group -->
                        
                        <!-- wp:blockspare/blockspare-masonry {"align":"wide","gutter":5,"uniqueClass":"blockspare-ab4023d0-5357-4","gridSize":"lrg","marginTop":0,"marginBottom":0,"blockHoverEffect":"bs-hover-style-3"} -->
                        <div class="blockspare-blocks blockspare-masonry-wrapper blockspare-original wp-block-blockspare-blockspare-masonry alignwide blockspare-ab4023d0-5357-4 bs-hover-style-3" blockspare-animation=""><style>.blockspare-ab4023d0-5357-4 .blockspare-gutter-wrap{padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px}</style><div class="has-gutter blockspare-gutter-wrap"><ul class="has-grid-lrg has-gutter-5"><li class="blockspare-gallery-item"><figure class="blockspare-gallery-figure blockspare-hover-item"><img src="https://blockspare.com/demo/default/hair-salon/wp-content/uploads/sites/32/2023/08/pexels-rdne-stock-project-7755223.jpg" alt="" data-id="140" data-imglink="" data-link="https://blockspare.com/demo/default/hair-salon/contact/pexels-rdne-stock-project-7755223/" class="wp-image-140"/></figure></li><li class="blockspare-gallery-item"><figure class="blockspare-gallery-figure blockspare-hover-item"><img src="https://blockspare.com/demo/default/hair-salon/wp-content/uploads/sites/32/2023/08/pexels-rdne-stock-project-7755207-1024x682.jpg" alt="" data-id="34" data-imglink="" data-link="https://blockspare.com/demo/default/hair-salon/contact/pexels-rdne-stock-project-7755207/" class="wp-image-34"/></figure></li><li class="blockspare-gallery-item"><figure class="blockspare-gallery-figure blockspare-hover-item"><img src="https://blockspare.com/demo/default/hair-salon/wp-content/uploads/sites/32/2023/08/pexels-rdne-stock-project-7755663-1024x682.jpg" alt="" data-id="91" data-imglink="" data-link="https://blockspare.com/demo/default/hair-salon/contact/pexels-rdne-stock-project-7755663/" class="wp-image-91"/></figure></li><li class="blockspare-gallery-item"><figure class="blockspare-gallery-figure blockspare-hover-item"><img src="https://blockspare.com/demo/default/hair-salon/wp-content/uploads/sites/32/2023/08/pexels-rdne-stock-project-7755502-1024x682.jpg" alt="" data-id="44" data-imglink="" data-link="https://blockspare.com/demo/default/hair-salon/contact/pexels-rdne-stock-project-7755502/" class="wp-image-44"/></figure></li><li class="blockspare-gallery-item"><figure class="blockspare-gallery-figure blockspare-hover-item"><img src="https://blockspare.com/demo/default/hair-salon/wp-content/uploads/sites/32/2023/08/pexels-rdne-stock-project-7755180-1024x682.jpg" alt="" data-id="42" data-imglink="" data-link="https://blockspare.com/demo/default/hair-salon/contact/pexels-rdne-stock-project-7755180/" class="wp-image-42"/></figure></li><li class="blockspare-gallery-item"><figure class="blockspare-gallery-figure blockspare-hover-item"><img src="https://blockspare.com/demo/default/hair-salon/wp-content/uploads/sites/32/2023/08/pexels-rdne-stock-project-7755482-1024x682.jpg" alt="" data-id="46" data-imglink="" data-link="https://blockspare.com/demo/default/hair-salon/contact/pexels-rdne-stock-project-7755482/" class="wp-image-46"/></figure></li></ul></div></div>
                        <!-- /wp:blockspare/blockspare-masonry --></div>
                        <!-- /wp:group --></div></div></div></div>
                        <!-- /wp:blockspare/blockspare-container -->',
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'hair-salon',
                        'item'     => ['Hair Salon','Testimonial'], 
						'key'      => 'bs_section_251',
                        'imagePath'    => 'sections',
						'name'     => 'Hair Salon Section 3',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/hair-salon-section-3/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'hair-salon',
                        'item'     => ['Hair Salon','Services'], 
						'key'      => 'bs_section_252',
                        'imagePath'    => 'sections',
						'name'     => 'Hair Salon Section 4',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/hair-salon-section-4/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'hair-salon',
                        'item'     => ['Hair Salon','Container'], 
						'key'      => 'bs_section_253',
                        'imagePath'    => 'sections',
						'name'     => 'Hair Salon Section 5',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/hair-salon-section-5/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'hair-salon',
                        'item'     => ['Hair Salon','Price List'], 
						'key'      => 'bs_section_254',
                        'imagePath'    => 'sections',
						'name'     => 'Hair Salon Section 6',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/hair-salon-section-6/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'hair-salon',
                        'item'     => ['Hair Salon','Image Carousel'], 
						'key'      => 'bs_section_255',
                        'imagePath'    => 'sections',
						'name'     => 'Hair Salon Section 7',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/hair-salon-section-7/",
						'content'  => '<!-- wp:blockspare/blockspare-container {"paddingTop":0,"paddingRight":0,"paddingBottom":0,"paddingLeft":0,"marginTop":0,"marginBottom":0,"uniqueClass":"blockspare-9500f250-ab4b-4","backGroundColor":"#ffffff"} -->
                        <div class="wp-block-blockspare-blockspare-container alignfull blockspare-9500f250-ab4b-4" blockspare-animation=""><style>.blockspare-9500f250-ab4b-4 > .blockspare-block-container-wrapper{background-color:#ffffff;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px;margin-top:0px;margin-right:0px;margin-bottom:0px;margin-left:0px;border-radius:0}.blockspare-9500f250-ab4b-4 .blockspare-image-wrap{background-image:none}</style><div class="blockspare-block-container-wrapper blockspare-hover-item"><div class="blockspare-container-background blockspare-image-wrap has-background-opacity-100 has-background-opacity"></div><div class="blockspare-container"><div class="blockspare-inner-blocks blockspare-inner-wrapper-blocks"><!-- wp:group {"style":{"spacing":{"padding":{"top":"0","bottom":"0","right":"0","left":"0"},"blockGap":"var:preset|spacing|50"},"color":{"background":"#ebebeb"}},"layout":{"type":"constrained"}} -->
                        <div class="wp-block-group has-background" style="background-color:#ebebeb;padding-top:0;padding-right:0;padding-bottom:0;padding-left:0"><!-- wp:blockspare/shape-divider {"design":"split","width":2,"height":76,"color":"#c8ab74","marginTop":-10,"marginBottom":0,"uniqueClass":"blockspare-2cec70b5-a002-4"} -->
                        <div class="wp-block-blockspare-shape-divider alignfull blockspare-2cec70b5-a002-4" blockspare-animation=""><style>.blockspare-2cec70b5-a002-4 .blockspare-svg-wrapper{color:#c8ab74;height:76px;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px;margin-top:-10px;margin-right:0px;margin-bottom:0px;margin-left:0px}</style><div class="blockspare-blocks blockspare-svg-wrapper blockspare-hover-item has-width-2"><div class="blockspare-svg-svg-inner blockspare-separator-wrapper"><svg class="split" preserveAspectRatio="none" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1000 20"><path d="M0 0v3h483.4c9.2 0 16.6 7.4 16.6 16.6 0-9.1 7.4-16.6 16.6-16.6H1000V0H0z"></path></svg></div></div></div>
                        <!-- /wp:blockspare/shape-divider -->
                        
                        <!-- wp:group {"align":"full","style":{"spacing":{"padding":{"right":"var:preset|spacing|30","left":"var:preset|spacing|30","bottom":"var:preset|spacing|70"},"blockGap":"var:preset|spacing|50"}},"layout":{"type":"constrained"}} -->
                        <div class="wp-block-group alignfull" style="padding-right:var(--wp--preset--spacing--30);padding-bottom:var(--wp--preset--spacing--70);padding-left:var(--wp--preset--spacing--30)"><!-- wp:group {"style":{"spacing":{"padding":{"top":"0","right":"0","left":"0"}}},"layout":{"type":"constrained"}} -->
                        <div class="wp-block-group" style="padding-top:0;padding-right:0;padding-left:0"><!-- wp:blockspare/blockspare-section-header {"uniqueClass":"blockspare-aed8ed4d-19ca-4","sectionAlignment":"center","headerTitle":"Unleash Your Inner Beauty","titleFontSize":56,"headerSubTitle":"Discover Your Perfect Hairstyle","headertitleColor":"#0e2944","headersubtitleColor":"#c8ab74","headermarginTop":0,"headermarginBottom":0,"headerlayoutOption":"blockspare-style20","titlePaddingBottom":16,"subtitlePaddingBottom":10,"dashColor":"#c8ab74","titleFontFamily":"Cormorant Garamond","titleFontWeight":"500","titleFontSizeMobile":32,"titleFontSizeTablet":48,"titleLoadGoogleFonts":true,"subTitleFontSize":24,"subTitleFontFamily":"Cormorant Garamond","subTitleFontWeight":"500","subTitleFontSizeMobile":20,"subTitleFontSizeTablet":22,"subTitleLoadGoogleFonts":true} -->
                        <div class="wp-block-blockspare-blockspare-section-header aligncenter blockspare-aed8ed4d-19ca-4 blockspare-section-header-wrapper blockspare-blocks aligncenter" blockspare-animation=""><style>.blockspare-aed8ed4d-19ca-4 .blockspare-section-head-wrap{background-color:transparent;text-align:center;margin-top:0px;margin-right:0px;margin-bottom:0px;margin-left:0px}.blockspare-aed8ed4d-19ca-4 .blockspare-section-head-wrap .blockspare-title{color:#0e2944;padding-top:0px;padding-right:0px;padding-bottom:16px;padding-left:0px;font-size:56px;font-family:Cormorant Garamond;font-weight:500}.blockspare-aed8ed4d-19ca-4 .blockspare-section-head-wrap .blockspare-title-wrapper .blockspare-lower-dash{background-color:#c8ab74!important}.blockspare-aed8ed4d-19ca-4 .blockspare-section-head-wrap .blockspare-subtitle{color:#c8ab74;font-size:24px;font-family:Cormorant Garamond;font-weight:500;padding-top:0px;padding-right:0px;padding-bottom:10px;padding-left:0px}@media screen and (max-width:1025px){.blockspare-aed8ed4d-19ca-4 .blockspare-section-head-wrap .blockspare-title{font-size:48px}.blockspare-aed8ed4d-19ca-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:22px}}@media screen and (max-width:768px){.blockspare-aed8ed4d-19ca-4 .blockspare-section-head-wrap .blockspare-title{font-size:32px}.blockspare-aed8ed4d-19ca-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:20px}}</style><div class="blockspare-section-head-wrap blockspare-style20 blockspare-center blockspare-hover-item"><div class="blockspare-title-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><h2 class="blockspare-title">Unleash Your Inner Beauty</h2><span class="blockspare-title-dash blockspare-lower-dash"></span></div><div class="blockspare-subtitle-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><p class="blockspare-subtitle">Discover Your Perfect Hairstyle</p><span class="blockspare-title-dash blockspare-lower-dash"></span></div></div></div>
                        <!-- /wp:blockspare/blockspare-section-header -->
                        
                        <!-- wp:blockspare/blockspare-list {"className":"aligncenter","uniqueClass":"blockspare-c81d3cee-c70e-4","sectionAlignment":"center","listType":"none","color":"#6e6e6e","descriptionFontSize":20,"descriptionFontFamily":"Cormorant Garamond","descriptionFontWeight":"500","descriptionFontSizeMobile":18,"descriptionFontSizeTablet":18,"descriptionLoadGoogleFonts":true,"marginTop":0,"marginBottom":0} -->
                        <div class="wp-block-blockspare-blockspare-list aligncenter blockspare-c81d3cee-c70e-4 blockspare-block-iconlist-wrap" blockspare-animation=""><style>.blockspare-c81d3cee-c70e-4 .blockspare-list-wrap{border-radius:0px;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px}.blockspare-c81d3cee-c70e-4 .blockspare-list-wrap .listDescription li{color:#6e6e6e;text-align:center;font-size:20px;font-family:Cormorant Garamond;font-weight:500}.blockspare-c81d3cee-c70e-4 .blockspare-list-wrap .listDescription li:before{color:#404040}.blockspare-c81d3cee-c70e-4 .listDescription li:before{font-size:18px}@media screen and (max-width:1025px){.blockspare-c81d3cee-c70e-4 .blockspare-list-wrap .listDescription li{font-size:18px}.blockspare-c81d3cee-c70e-4 .listDescription li:before{font-size:18px}}@media screen and (max-width:768px){.blockspare-c81d3cee-c70e-4 .blockspare-list-wrap .listDescription li{font-size:18px}.blockspare-c81d3cee-c70e-4 .listDescription li:before{font-size:18px}}</style><div class="blockspare-blocks blockspare-list-wrap blockspare-hover-item"><ul class="listDescription"><!-- wp:list-item -->
                        <li>At Infinity, we believe that your hair is a reflection of your unique personality and style. Our “Choose Your Hairstyle” section is here to help you explore a diverse range of looks and find the one that resonates with your individuality.</li>
                        <!-- /wp:list-item --></ul></div></div>
                        <!-- /wp:blockspare/blockspare-list --></div>
                        <!-- /wp:group -->
                        
                        <!-- wp:blockspare/blockspare-carousel {"align":"wide","images":[{"alt":"","id":388,"link":"https://blockspare.com/demo/default/hair-salon/home/pexels-pixabay-355063/","caption":"","sizes":{"thumbnail":{"height":150,"width":150,"url":"https://blockspare.com/demo/default/hair-salon/wp-content/uploads/sites/32/2023/08/pexels-pixabay-355063-150x150.jpg","orientation":"landscape"},"medium":{"height":200,"width":300,"url":"https://blockspare.com/demo/default/hair-salon/wp-content/uploads/sites/32/2023/08/pexels-pixabay-355063-300x200.jpg","orientation":"landscape"},"full":{"url":"https://blockspare.com/demo/default/hair-salon/wp-content/uploads/sites/32/2023/08/pexels-pixabay-355063.jpg","height":427,"width":640,"orientation":"landscape"}},"url":"https://blockspare.com/demo/default/hair-salon/wp-content/uploads/sites/32/2023/08/pexels-pixabay-355063.jpg","imgLink":""},{"alt":"","id":387,"link":"https://blockspare.com/demo/default/hair-salon/home/pexels-pixabay-371160/","caption":"","sizes":{"thumbnail":{"height":150,"width":150,"url":"https://blockspare.com/demo/default/hair-salon/wp-content/uploads/sites/32/2023/08/pexels-pixabay-371160-150x150.jpg","orientation":"landscape"},"medium":{"height":200,"width":300,"url":"https://blockspare.com/demo/default/hair-salon/wp-content/uploads/sites/32/2023/08/pexels-pixabay-371160-300x200.jpg","orientation":"landscape"},"full":{"url":"https://blockspare.com/demo/default/hair-salon/wp-content/uploads/sites/32/2023/08/pexels-pixabay-371160.jpg","height":427,"width":640,"orientation":"landscape"}},"url":"https://blockspare.com/demo/default/hair-salon/wp-content/uploads/sites/32/2023/08/pexels-pixabay-371160.jpg","imgLink":""},{"alt":"","id":386,"link":"https://blockspare.com/demo/default/hair-salon/home/pexels-pixabay-262173/","caption":"","sizes":{"thumbnail":{"height":150,"width":150,"url":"https://blockspare.com/demo/default/hair-salon/wp-content/uploads/sites/32/2023/08/pexels-pixabay-262173-150x150.jpg","orientation":"landscape"},"medium":{"height":200,"width":300,"url":"https://blockspare.com/demo/default/hair-salon/wp-content/uploads/sites/32/2023/08/pexels-pixabay-262173-300x200.jpg","orientation":"landscape"},"full":{"url":"https://blockspare.com/demo/default/hair-salon/wp-content/uploads/sites/32/2023/08/pexels-pixabay-262173.jpg","height":427,"width":640,"orientation":"landscape"}},"url":"https://blockspare.com/demo/default/hair-salon/wp-content/uploads/sites/32/2023/08/pexels-pixabay-262173.jpg","imgLink":""},{"alt":"","id":256,"link":"https://blockspare.com/demo/default/hair-salon/contact/pexels-engin-akyurt-3356211/","caption":"","sizes":{"thumbnail":{"height":150,"width":150,"url":"https://blockspare.com/demo/default/hair-salon/wp-content/uploads/sites/32/2023/08/pexels-engin-akyurt-3356211-150x150.jpg","orientation":"landscape"},"medium":{"height":200,"width":300,"url":"https://blockspare.com/demo/default/hair-salon/wp-content/uploads/sites/32/2023/08/pexels-engin-akyurt-3356211-300x200.jpg","orientation":"landscape"},"full":{"url":"https://blockspare.com/demo/default/hair-salon/wp-content/uploads/sites/32/2023/08/pexels-engin-akyurt-3356211.jpg","height":427,"width":640,"orientation":"landscape"}},"url":"https://blockspare.com/demo/default/hair-salon/wp-content/uploads/sites/32/2023/08/pexels-engin-akyurt-3356211.jpg","imgLink":""},{"alt":"","id":255,"link":"https://blockspare.com/demo/default/hair-salon/contact/pexels-engin-akyurt-3331486-1/","caption":"","sizes":{"thumbnail":{"height":150,"width":150,"url":"https://blockspare.com/demo/default/hair-salon/wp-content/uploads/sites/32/2023/08/pexels-engin-akyurt-3331486-1-150x150.jpg","orientation":"landscape"},"medium":{"height":200,"width":300,"url":"https://blockspare.com/demo/default/hair-salon/wp-content/uploads/sites/32/2023/08/pexels-engin-akyurt-3331486-1-300x200.jpg","orientation":"landscape"},"full":{"url":"https://blockspare.com/demo/default/hair-salon/wp-content/uploads/sites/32/2023/08/pexels-engin-akyurt-3331486-1.jpg","height":427,"width":640,"orientation":"landscape"}},"url":"https://blockspare.com/demo/default/hair-salon/wp-content/uploads/sites/32/2023/08/pexels-engin-akyurt-3331486-1.jpg","imgLink":""},{"alt":"","id":254,"link":"https://blockspare.com/demo/default/hair-salon/contact/pexels-engin-akyurt-3065209/","caption":"","sizes":{"thumbnail":{"height":150,"width":150,"url":"https://blockspare.com/demo/default/hair-salon/wp-content/uploads/sites/32/2023/08/pexels-engin-akyurt-3065209-150x150.jpg","orientation":"landscape"},"medium":{"height":200,"width":300,"url":"https://blockspare.com/demo/default/hair-salon/wp-content/uploads/sites/32/2023/08/pexels-engin-akyurt-3065209-300x200.jpg","orientation":"landscape"},"full":{"url":"https://blockspare.com/demo/default/hair-salon/wp-content/uploads/sites/32/2023/08/pexels-engin-akyurt-3065209.jpg","height":427,"width":640,"orientation":"landscape"}},"url":"https://blockspare.com/demo/default/hair-salon/wp-content/uploads/sites/32/2023/08/pexels-engin-akyurt-3065209.jpg","imgLink":""},{"alt":"","id":253,"link":"https://blockspare.com/demo/default/hair-salon/contact/pexels-engin-akyurt-3065171/","caption":"","sizes":{"thumbnail":{"height":150,"width":150,"url":"https://blockspare.com/demo/default/hair-salon/wp-content/uploads/sites/32/2023/08/pexels-engin-akyurt-3065171-150x150.jpg","orientation":"landscape"},"medium":{"height":200,"width":300,"url":"https://blockspare.com/demo/default/hair-salon/wp-content/uploads/sites/32/2023/08/pexels-engin-akyurt-3065171-300x200.jpg","orientation":"landscape"},"full":{"url":"https://blockspare.com/demo/default/hair-salon/wp-content/uploads/sites/32/2023/08/pexels-engin-akyurt-3065171.jpg","height":427,"width":640,"orientation":"landscape"}},"url":"https://blockspare.com/demo/default/hair-salon/wp-content/uploads/sites/32/2023/08/pexels-engin-akyurt-3065171.jpg","imgLink":""},{"alt":"","id":252,"link":"https://blockspare.com/demo/default/hair-salon/contact/pexels-engin-akyurt-3065170/","caption":"","sizes":{"thumbnail":{"height":150,"width":150,"url":"https://blockspare.com/demo/default/hair-salon/wp-content/uploads/sites/32/2023/08/pexels-engin-akyurt-3065170-150x150.jpg","orientation":"landscape"},"medium":{"height":200,"width":300,"url":"https://blockspare.com/demo/default/hair-salon/wp-content/uploads/sites/32/2023/08/pexels-engin-akyurt-3065170-300x200.jpg","orientation":"landscape"},"full":{"url":"https://blockspare.com/demo/default/hair-salon/wp-content/uploads/sites/32/2023/08/pexels-engin-akyurt-3065170.jpg","height":427,"width":640,"orientation":"landscape"}},"url":"https://blockspare.com/demo/default/hair-salon/wp-content/uploads/sites/32/2023/08/pexels-engin-akyurt-3065170.jpg","imgLink":""}],"numberofSlide":3,"navigationColor":"#0e2944","marginTop":0,"marginBottom":0,"uniqueClass":"blockspare-711938bd-fc5c-4"} -->
                        <div class="wp-block-blockspare-blockspare-carousel alignwide blockspare-711938bd-fc5c-4" blockspare-animation=""><style>.blockspare-711938bd-fc5c-4 .blockspare-carousel-wrap span:before,.blockspare-711938bd-fc5c-4 .blockspare-carousel-wrap ul li button{color:#0e2944}.blockspare-711938bd-fc5c-4 .blockspare-carousel-wrap .slick-slider .slick-dots > li button{background-color:#0e2944}.blockspare-711938bd-fc5c-4 .blockspare-carousel-wrap .blockspare-gallery-figure{border-radius:0px}.blockspare-711938bd-fc5c-4 .slick-slider .slick-arrow:after{background-color:#fff}</style><div class="blockspare-blocks blockspare-carousel-wrap has-gutter-space-1 blockspare-original blockspare-navigation-small lpc-navigation-1 bs-has-equal-height blockspare-slides-3"><div class="blockspare-carousel-items"><div data-next="fas fa-chevron-right" data-prev="fas fa-chevron-left" data-slick="{&quot;loop&quot;:true,&quot;autoplay&quot;:true,&quot;slidesToShow&quot;:3,&quot;speed&quot;:&quot;1000&quot;,&quot;arrows&quot;:true,&quot;dots&quot;:false}"><div><div class="blockspare-gallery-figure blockspare-hover-item"><img src="https://blockspare.com/demo/default/hair-salon/wp-content/uploads/sites/32/2023/08/pexels-pixabay-355063.jpg"/></div></div><div><div class="blockspare-gallery-figure blockspare-hover-item"><img src="https://blockspare.com/demo/default/hair-salon/wp-content/uploads/sites/32/2023/08/pexels-pixabay-371160.jpg"/></div></div><div><div class="blockspare-gallery-figure blockspare-hover-item"><img src="https://blockspare.com/demo/default/hair-salon/wp-content/uploads/sites/32/2023/08/pexels-pixabay-262173.jpg"/></div></div><div><div class="blockspare-gallery-figure blockspare-hover-item"><img src="https://blockspare.com/demo/default/hair-salon/wp-content/uploads/sites/32/2023/08/pexels-engin-akyurt-3356211.jpg"/></div></div><div><div class="blockspare-gallery-figure blockspare-hover-item"><img src="https://blockspare.com/demo/default/hair-salon/wp-content/uploads/sites/32/2023/08/pexels-engin-akyurt-3331486-1.jpg"/></div></div><div><div class="blockspare-gallery-figure blockspare-hover-item"><img src="https://blockspare.com/demo/default/hair-salon/wp-content/uploads/sites/32/2023/08/pexels-engin-akyurt-3065209.jpg"/></div></div><div><div class="blockspare-gallery-figure blockspare-hover-item"><img src="https://blockspare.com/demo/default/hair-salon/wp-content/uploads/sites/32/2023/08/pexels-engin-akyurt-3065171.jpg"/></div></div><div><div class="blockspare-gallery-figure blockspare-hover-item"><img src="https://blockspare.com/demo/default/hair-salon/wp-content/uploads/sites/32/2023/08/pexels-engin-akyurt-3065170.jpg"/></div></div></div></div></div></div>
                        <!-- /wp:blockspare/blockspare-carousel --></div>
                        <!-- /wp:group --></div>
                        <!-- /wp:group --></div></div></div></div>
                        <!-- /wp:blockspare/blockspare-container -->',
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'hair-salon',
                        'item'     => ['Hair Salon','Icon List'], 
						'key'      => 'bs_section_256',
                        'imagePath'    => 'sections',
						'name'     => 'Hair Salon Section 8',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/hair-salon-section-8/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'interior-design',
                        'item'     => ['Interior Design','Call To Action'], 
						'key'      => 'bs_section_257',
                        'imagePath'    => 'sections',
						'name'     => 'Interior Design Section 1',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/interior-design-section-1/",
						'content'  => '<!-- wp:blockspare/blockspare-container {"paddingTop":0,"paddingRight":0,"paddingBottom":0,"paddingLeft":0,"marginTop":0,"marginBottom":0,"uniqueClass":"blockspare-0f48d43d-316b-4","opacityRatio":30,"backGroundColor":"#000000"} -->
                        <div class="wp-block-blockspare-blockspare-container alignfull blockspare-0f48d43d-316b-4" blockspare-animation=""><style>.blockspare-0f48d43d-316b-4 > .blockspare-block-container-wrapper{background-color:#000000;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px;margin-top:0px;margin-right:0px;margin-bottom:0px;margin-left:0px;border-radius:0}.blockspare-0f48d43d-316b-4 .blockspare-image-wrap{background-image:none}</style><div class="blockspare-block-container-wrapper blockspare-hover-item"><div class="blockspare-container-background blockspare-image-wrap has-background-opacity-30 has-background-opacity"></div><div class="blockspare-container"><div class="blockspare-inner-blocks blockspare-inner-wrapper-blocks"><!-- wp:cover {"url":"https://blockspare.com/demo/default/interior-design/wp-content/uploads/sites/33/2023/08/pexels-max-rahubovskiy-6758242.jpg","id":20,"hasParallax":true,"dimRatio":80,"customOverlayColor":"#18191b","minHeight":50,"minHeightUnit":"vh","align":"wide","style":{"spacing":{"padding":{"top":"0","right":"0","bottom":"0","left":"0"}}}} -->
                        <div class="wp-block-cover alignwide has-parallax" style="padding-top:0;padding-right:0;padding-bottom:0;padding-left:0;min-height:50vh"><span aria-hidden="true" class="wp-block-cover__background has-background-dim-80 has-background-dim" style="background-color:#18191b"></span><div role="img" class="wp-block-cover__image-background wp-image-20 has-parallax" style="background-position:50% 50%;background-image:url(https://blockspare.com/demo/default/interior-design/wp-content/uploads/sites/33/2023/08/pexels-max-rahubovskiy-6758242.jpg)"></div><div class="wp-block-cover__inner-container"><!-- wp:group {"style":{"spacing":{"padding":{"right":"var:preset|spacing|30","left":"var:preset|spacing|30","top":"var:preset|spacing|70","bottom":"var:preset|spacing|70"}}},"layout":{"type":"constrained"}} -->
                        <div class="wp-block-group" style="padding-top:var(--wp--preset--spacing--70);padding-right:var(--wp--preset--spacing--30);padding-bottom:var(--wp--preset--spacing--70);padding-left:var(--wp--preset--spacing--30)"><!-- wp:columns {"verticalAlignment":"center","align":"wide","style":{"spacing":{"blockGap":{"top":"var:preset|spacing|40","left":"var:preset|spacing|40"}}}} -->
                        <div class="wp-block-columns alignwide are-vertically-aligned-center"><!-- wp:column {"verticalAlignment":"center","width":"33.33%","style":{"border":{"color":"#ffffff1a","width":"2px"}}} -->
                        <div class="wp-block-column is-vertically-aligned-center has-border-color" style="border-color:#ffffff1a;border-width:2px;flex-basis:33.33%"><!-- wp:blockspare/blockspare-counter {"uniqueClass":"blockspare-d8926012-c9c9-4","sectionAlignment":"center","headerTitle":"Years of Experience","headertitleColor":"#ffffff","headersubtitleColor":"#ffffff","titleFontFamily":"Dosis","titleFontWeight":"300","titleLoadGoogleFonts":true,"marginTop":0,"marginBottom":0,"counter":27,"counterFontColor":"#ffffff","backGroundColor":"#00000000","showImage":false,"design":"blockspare-layout2","counterFontSize":100,"counterFontFamily":"Dosis","counterFontWeight":"600","counterFontSizeMobile":64,"counterFontSizeTablet":80,"counterLoadGoogleFonts":true} -->
                        <div class="wp-block-blockspare-blockspare-counter blockspare-d8926012-c9c9-4 aligncenter" blockspare-animation=""><style>.blockspare-d8926012-c9c9-4 .blockspare-counter-wrapper{background-color:#00000000;text-align:center;padding-top:20px;padding-right:20px;padding-bottom:20px;padding-left:20px;margin-top:0px;margin-right:0px;margin-bottom:0px;margin-left:0px;border-radius:null}.blockspare-d8926012-c9c9-4 .blockspare-counter-wrapper .blockspare-block-icon{color:#fff;border-radius:50%;margin-top:0px;margin-right:0px;margin-bottom:0px;margin-left:0px;padding-top:30px;padding-right:30px;padding-bottom:30px;padding-left:30px}.blockspare-d8926012-c9c9-4 .blockspare-counter-wrapper .blockspare-block-icon::after{background-color:#8b249c;opacity:1}.blockspare-d8926012-c9c9-4 .blockspare-counters-item .blockspare-counter{font-size:100px;font-family:Dosis;font-weight:600;margin-top:0px;margin-right:0px;margin-bottom:0px;margin-left:0px}.blockspare-d8926012-c9c9-4 .blockspare-section-head-wrap{background-color:transparent;text-align:center;margin-top:5px;margin-right:0px;margin-bottom:5px;margin-left:0px}.blockspare-d8926012-c9c9-4 .blockspare-section-head-wrap .blockspare-title{color:#ffffff;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px;font-size:24px;font-family:Dosis;font-weight:300}.blockspare-d8926012-c9c9-4 .blockspare-section-head-wrap .blockspare-subtitle{color:#ffffff;font-size:14px;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px}.blockspare-d8926012-c9c9-4 .blockspare-counter{color:#ffffff;text-align:center}.blockspare-d8926012-c9c9-4 .blockspare-block-icon-wrapper{text-align:center}@media screen and (max-width:1025px){.blockspare-d8926012-c9c9-4 .blockspare-section-head-wrap .blockspare-title{font-size:22px}.blockspare-d8926012-c9c9-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}.blockspare-d8926012-c9c9-4 .blockspare-counters-item .blockspare-counter{font-size:80px}}@media screen and (max-width:768px){.blockspare-d8926012-c9c9-4 .blockspare-section-head-wrap .blockspare-title{font-size:20px}.blockspare-d8926012-c9c9-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}.blockspare-d8926012-c9c9-4 .blockspare-counters-item .blockspare-counter{font-size:64px}}</style><div class="blockspare-counter-wrapper blockspare-blocks blockspare-section-counter-bar blockspare-hover-item"><div class="blockspare-counter-section"><div class="blockspare-counters-item blockspare-layout2"><div class="blockspare-counters"><div class="blockspare-section-header-wrapper blockspare-blocks"><div class="blockspare-section-head-wrap blockspare-style1 blockspare-center"><div class="blockspare-title-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><h2 class="blockspare-title">Years of Experience</h2><span class="blockspare-title-dash blockspare-lower-dash"></span></div><div class="blockspare-subtitle-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><span class="blockspare-title-dash blockspare-lower-dash"></span></div></div></div></div><p class="blockspare-counter">27</p></div></div></div></div>
                        <!-- /wp:blockspare/blockspare-counter --></div>
                        <!-- /wp:column -->
                        
                        <!-- wp:column {"verticalAlignment":"center","width":"66.66%"} -->
                        <div class="wp-block-column is-vertically-aligned-center" style="flex-basis:66.66%"><!-- wp:blockspare/blockspare-section-header {"uniqueClass":"blockspare-f152b3ef-fc25-4","headerTitle":"Transform Your Space with Exquisite Interior Design","titleFontSize":48,"headerSubTitle":"Unveiling Elegance","headertitleColor":"#ffffff","headersubtitleColor":"#ffde00","headermarginTop":0,"headermarginBottom":0,"headerlayoutOption":"blockspare-style3","titleFontFamily":"Dosis","titleFontWeight":"300","titleFontSizeMobile":32,"titleFontSizeTablet":40,"titleLoadGoogleFonts":true,"subTitleFontSize":24,"subTitleFontFamily":"Dosis","subTitleFontWeight":"300","subTitleFontSizeMobile":20,"subTitleFontSizeTablet":22,"subTitleLoadGoogleFonts":true} -->
                        <div class="wp-block-blockspare-blockspare-section-header aligncenter blockspare-f152b3ef-fc25-4 blockspare-section-header-wrapper blockspare-blocks aligncenter" blockspare-animation=""><style>.blockspare-f152b3ef-fc25-4 .blockspare-section-head-wrap{background-color:transparent;text-align:left;margin-top:0px;margin-right:0px;margin-bottom:0px;margin-left:0px}.blockspare-f152b3ef-fc25-4 .blockspare-section-head-wrap .blockspare-title{color:#ffffff;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px;font-size:48px;font-family:Dosis;font-weight:300}.blockspare-f152b3ef-fc25-4 .blockspare-section-head-wrap .blockspare-subtitle{color:#ffde00;font-size:24px;font-family:Dosis;font-weight:300;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px}@media screen and (max-width:1025px){.blockspare-f152b3ef-fc25-4 .blockspare-section-head-wrap .blockspare-title{font-size:40px}.blockspare-f152b3ef-fc25-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:22px}}@media screen and (max-width:768px){.blockspare-f152b3ef-fc25-4 .blockspare-section-head-wrap .blockspare-title{font-size:32px}.blockspare-f152b3ef-fc25-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:20px}}</style><div class="blockspare-section-head-wrap blockspare-style3 blockspare-left blockspare-hover-item"><div class="blockspare-title-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><h2 class="blockspare-title">Transform Your Space with Exquisite Interior Design</h2><span class="blockspare-title-dash blockspare-lower-dash"></span></div><div class="blockspare-subtitle-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><p class="blockspare-subtitle">Unveiling Elegance</p><span class="blockspare-title-dash blockspare-lower-dash"></span></div></div></div>
                        <!-- /wp:blockspare/blockspare-section-header --></div>
                        <!-- /wp:column --></div>
                        <!-- /wp:columns --></div>
                        <!-- /wp:group --></div></div>
                        <!-- /wp:cover --></div></div></div></div>
                        <!-- /wp:blockspare/blockspare-container -->',
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'interior-design',
                        'item'     => ['Interior Design','Services'], 
						'key'      => 'bs_section_258',
                        'imagePath'    => 'sections',
						'name'     => 'Interior Design Section 2',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/interior-design-section-2/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'interior-design',
                        'item'     => ['Interior Design','Tab','Image Masonry'], 
						'key'      => 'bs_section_259',
                        'imagePath'    => 'sections',
						'name'     => 'Interior Design Section 3',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/interior-design-section-3/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'interior-design',
                        'item'     => ['Interior Design','Services'], 
						'key'      => 'bs_section_260',
                        'imagePath'    => 'sections',
						'name'     => 'Interior Design Section 4',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/interior-design-section-4/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'interior-design',
                        'item'     => ['Interior Design','Counter'], 
						'key'      => 'bs_section_261',
                        'imagePath'    => 'sections',
						'name'     => 'Interior Design Section 5',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/interior-design-section-5/",
						'content'  => '<!-- wp:blockspare/blockspare-container {"paddingTop":50,"paddingRight":0,"paddingBottom":50,"paddingLeft":0,"marginTop":0,"marginBottom":0,"uniqueClass":"blockspare-a865b46a-2f7f-4","backGroundColor":"#18191b"} -->
                        <div class="wp-block-blockspare-blockspare-container alignfull blockspare-a865b46a-2f7f-4" blockspare-animation=""><style>.blockspare-a865b46a-2f7f-4 > .blockspare-block-container-wrapper{background-color:#18191b;padding-top:50px;padding-right:0px;padding-bottom:50px;padding-left:0px;margin-top:0px;margin-right:0px;margin-bottom:0px;margin-left:0px;border-radius:0}.blockspare-a865b46a-2f7f-4 .blockspare-image-wrap{background-image:none}</style><div class="blockspare-block-container-wrapper blockspare-hover-item"><div class="blockspare-container-background blockspare-image-wrap has-background-opacity-100 has-background-opacity"></div><div class="blockspare-container"><div class="blockspare-inner-blocks blockspare-inner-wrapper-blocks"><!-- wp:group {"style":{"spacing":{"blockGap":"var:preset|spacing|50","padding":{"bottom":"var:preset|spacing|70","top":"var:preset|spacing|70","right":"var:preset|spacing|30","left":"var:preset|spacing|30"},"margin":{"top":"0","bottom":"0"}}},"layout":{"type":"constrained"}} -->
                        <div class="wp-block-group" style="margin-top:0;margin-bottom:0;padding-top:var(--wp--preset--spacing--70);padding-right:var(--wp--preset--spacing--30);padding-bottom:var(--wp--preset--spacing--70);padding-left:var(--wp--preset--spacing--30)"><!-- wp:columns {"align":"wide","style":{"spacing":{"blockGap":{"top":"var:preset|spacing|40","left":"var:preset|spacing|40"}}}} -->
                        <div class="wp-block-columns alignwide"><!-- wp:column -->
                        <div class="wp-block-column"><!-- wp:blockspare/blockspare-counter {"uniqueClass":"blockspare-fe95b658-c5eb-4","sectionAlignment":"center","headerTitle":"Projects Completed","headertitleColor":"#ffde00","headersubtitleColor":"#ffffff","titlePaddingTop":8,"titleFontFamily":"Dosis","titleFontWeight":"300","titleLoadGoogleFonts":true,"paddingTop":0,"paddingRight":0,"paddingBottom":0,"paddingLeft":0,"marginTop":0,"marginBottom":0,"counter":250,"counterFontColor":"#ffffff","backGroundColor":"#00000000","showImage":false,"design":"blockspare-layout2","iconPaddingTop":0,"iconPaddingBottom":0,"iconPaddingLeft":0,"iconPaddingRight":0,"counterFontSize":40,"counterFontFamily":"Dosis","counterFontWeight":"600","counterFontSizeMobile":32,"counterFontSizeTablet":32,"counterLoadGoogleFonts":true} -->
                        <div class="wp-block-blockspare-blockspare-counter blockspare-fe95b658-c5eb-4 aligncenter" blockspare-animation=""><style>.blockspare-fe95b658-c5eb-4 .blockspare-counter-wrapper{background-color:#00000000;text-align:center;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px;margin-top:0px;margin-right:0px;margin-bottom:0px;margin-left:0px;border-radius:null}.blockspare-fe95b658-c5eb-4 .blockspare-counter-wrapper .blockspare-block-icon{color:#fff;border-radius:50%;margin-top:0px;margin-right:0px;margin-bottom:0px;margin-left:0px;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px}.blockspare-fe95b658-c5eb-4 .blockspare-counter-wrapper .blockspare-block-icon::after{background-color:#8b249c;opacity:1}.blockspare-fe95b658-c5eb-4 .blockspare-counters-item .blockspare-counter{font-size:40px;font-family:Dosis;font-weight:600;margin-top:0px;margin-right:0px;margin-bottom:0px;margin-left:0px}.blockspare-fe95b658-c5eb-4 .blockspare-section-head-wrap{background-color:transparent;text-align:center;margin-top:5px;margin-right:0px;margin-bottom:5px;margin-left:0px}.blockspare-fe95b658-c5eb-4 .blockspare-section-head-wrap .blockspare-title{color:#ffde00;padding-top:8px;padding-right:0px;padding-bottom:0px;padding-left:0px;font-size:24px;font-family:Dosis;font-weight:300}.blockspare-fe95b658-c5eb-4 .blockspare-section-head-wrap .blockspare-subtitle{color:#ffffff;font-size:14px;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px}.blockspare-fe95b658-c5eb-4 .blockspare-counter{color:#ffffff;text-align:center}.blockspare-fe95b658-c5eb-4 .blockspare-block-icon-wrapper{text-align:center}@media screen and (max-width:1025px){.blockspare-fe95b658-c5eb-4 .blockspare-section-head-wrap .blockspare-title{font-size:22px}.blockspare-fe95b658-c5eb-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}.blockspare-fe95b658-c5eb-4 .blockspare-counters-item .blockspare-counter{font-size:32px}}@media screen and (max-width:768px){.blockspare-fe95b658-c5eb-4 .blockspare-section-head-wrap .blockspare-title{font-size:20px}.blockspare-fe95b658-c5eb-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}.blockspare-fe95b658-c5eb-4 .blockspare-counters-item .blockspare-counter{font-size:32px}}</style><div class="blockspare-counter-wrapper blockspare-blocks blockspare-section-counter-bar blockspare-hover-item"><div class="blockspare-counter-section"><div class="blockspare-counters-item blockspare-layout2"><div class="blockspare-counters"><div class="blockspare-section-header-wrapper blockspare-blocks"><div class="blockspare-section-head-wrap blockspare-style1 blockspare-center"><div class="blockspare-title-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><h2 class="blockspare-title">Projects Completed</h2><span class="blockspare-title-dash blockspare-lower-dash"></span></div><div class="blockspare-subtitle-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><span class="blockspare-title-dash blockspare-lower-dash"></span></div></div></div></div><p class="blockspare-counter">250</p></div></div></div></div>
                        <!-- /wp:blockspare/blockspare-counter --></div>
                        <!-- /wp:column -->
                        
                        <!-- wp:column -->
                        <div class="wp-block-column"><!-- wp:blockspare/blockspare-counter {"uniqueClass":"blockspare-46960e95-5501-4","sectionAlignment":"center","headerTitle":"Design Awards","headertitleColor":"#ffde00","headersubtitleColor":"#ffffff","titlePaddingTop":8,"titleFontFamily":"Dosis","titleFontWeight":"300","titleLoadGoogleFonts":true,"paddingTop":0,"paddingRight":0,"paddingBottom":0,"paddingLeft":0,"marginTop":0,"marginBottom":0,"counter":12,"counterFontColor":"#ffffff","backGroundColor":"#00000000","showImage":false,"design":"blockspare-layout2","iconPaddingTop":0,"iconPaddingBottom":0,"iconPaddingLeft":0,"iconPaddingRight":0,"counterFontSize":40,"counterFontFamily":"Dosis","counterFontWeight":"600","counterFontSizeMobile":32,"counterFontSizeTablet":32,"counterLoadGoogleFonts":true} -->
                        <div class="wp-block-blockspare-blockspare-counter blockspare-46960e95-5501-4 aligncenter" blockspare-animation=""><style>.blockspare-46960e95-5501-4 .blockspare-counter-wrapper{background-color:#00000000;text-align:center;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px;margin-top:0px;margin-right:0px;margin-bottom:0px;margin-left:0px;border-radius:null}.blockspare-46960e95-5501-4 .blockspare-counter-wrapper .blockspare-block-icon{color:#fff;border-radius:50%;margin-top:0px;margin-right:0px;margin-bottom:0px;margin-left:0px;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px}.blockspare-46960e95-5501-4 .blockspare-counter-wrapper .blockspare-block-icon::after{background-color:#8b249c;opacity:1}.blockspare-46960e95-5501-4 .blockspare-counters-item .blockspare-counter{font-size:40px;font-family:Dosis;font-weight:600;margin-top:0px;margin-right:0px;margin-bottom:0px;margin-left:0px}.blockspare-46960e95-5501-4 .blockspare-section-head-wrap{background-color:transparent;text-align:center;margin-top:5px;margin-right:0px;margin-bottom:5px;margin-left:0px}.blockspare-46960e95-5501-4 .blockspare-section-head-wrap .blockspare-title{color:#ffde00;padding-top:8px;padding-right:0px;padding-bottom:0px;padding-left:0px;font-size:24px;font-family:Dosis;font-weight:300}.blockspare-46960e95-5501-4 .blockspare-section-head-wrap .blockspare-subtitle{color:#ffffff;font-size:14px;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px}.blockspare-46960e95-5501-4 .blockspare-counter{color:#ffffff;text-align:center}.blockspare-46960e95-5501-4 .blockspare-block-icon-wrapper{text-align:center}@media screen and (max-width:1025px){.blockspare-46960e95-5501-4 .blockspare-section-head-wrap .blockspare-title{font-size:22px}.blockspare-46960e95-5501-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}.blockspare-46960e95-5501-4 .blockspare-counters-item .blockspare-counter{font-size:32px}}@media screen and (max-width:768px){.blockspare-46960e95-5501-4 .blockspare-section-head-wrap .blockspare-title{font-size:20px}.blockspare-46960e95-5501-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}.blockspare-46960e95-5501-4 .blockspare-counters-item .blockspare-counter{font-size:32px}}</style><div class="blockspare-counter-wrapper blockspare-blocks blockspare-section-counter-bar blockspare-hover-item"><div class="blockspare-counter-section"><div class="blockspare-counters-item blockspare-layout2"><div class="blockspare-counters"><div class="blockspare-section-header-wrapper blockspare-blocks"><div class="blockspare-section-head-wrap blockspare-style1 blockspare-center"><div class="blockspare-title-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><h2 class="blockspare-title">Design Awards</h2><span class="blockspare-title-dash blockspare-lower-dash"></span></div><div class="blockspare-subtitle-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><span class="blockspare-title-dash blockspare-lower-dash"></span></div></div></div></div><p class="blockspare-counter">12</p></div></div></div></div>
                        <!-- /wp:blockspare/blockspare-counter --></div>
                        <!-- /wp:column -->
                        
                        <!-- wp:column -->
                        <div class="wp-block-column"><!-- wp:blockspare/blockspare-counter {"uniqueClass":"blockspare-1adf110a-958f-4","sectionAlignment":"center","headerTitle":"Design Sty\u003cstrong\u003eles\u003c/strong\u003e","headertitleColor":"#ffde00","headersubtitleColor":"#ffffff","titlePaddingTop":8,"titleFontFamily":"Dosis","titleFontWeight":"300","titleLoadGoogleFonts":true,"paddingTop":0,"paddingRight":0,"paddingBottom":0,"paddingLeft":0,"marginTop":0,"marginBottom":0,"counter":5,"counterFontColor":"#ffffff","backGroundColor":"#00000000","showImage":false,"design":"blockspare-layout2","iconPaddingTop":0,"iconPaddingBottom":0,"iconPaddingLeft":0,"iconPaddingRight":0,"counterFontSize":40,"counterFontFamily":"Dosis","counterFontWeight":"600","counterFontSizeMobile":32,"counterFontSizeTablet":32,"counterLoadGoogleFonts":true} -->
                        <div class="wp-block-blockspare-blockspare-counter blockspare-1adf110a-958f-4 aligncenter" blockspare-animation=""><style>.blockspare-1adf110a-958f-4 .blockspare-counter-wrapper{background-color:#00000000;text-align:center;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px;margin-top:0px;margin-right:0px;margin-bottom:0px;margin-left:0px;border-radius:null}.blockspare-1adf110a-958f-4 .blockspare-counter-wrapper .blockspare-block-icon{color:#fff;border-radius:50%;margin-top:0px;margin-right:0px;margin-bottom:0px;margin-left:0px;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px}.blockspare-1adf110a-958f-4 .blockspare-counter-wrapper .blockspare-block-icon::after{background-color:#8b249c;opacity:1}.blockspare-1adf110a-958f-4 .blockspare-counters-item .blockspare-counter{font-size:40px;font-family:Dosis;font-weight:600;margin-top:0px;margin-right:0px;margin-bottom:0px;margin-left:0px}.blockspare-1adf110a-958f-4 .blockspare-section-head-wrap{background-color:transparent;text-align:center;margin-top:5px;margin-right:0px;margin-bottom:5px;margin-left:0px}.blockspare-1adf110a-958f-4 .blockspare-section-head-wrap .blockspare-title{color:#ffde00;padding-top:8px;padding-right:0px;padding-bottom:0px;padding-left:0px;font-size:24px;font-family:Dosis;font-weight:300}.blockspare-1adf110a-958f-4 .blockspare-section-head-wrap .blockspare-subtitle{color:#ffffff;font-size:14px;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px}.blockspare-1adf110a-958f-4 .blockspare-counter{color:#ffffff;text-align:center}.blockspare-1adf110a-958f-4 .blockspare-block-icon-wrapper{text-align:center}@media screen and (max-width:1025px){.blockspare-1adf110a-958f-4 .blockspare-section-head-wrap .blockspare-title{font-size:22px}.blockspare-1adf110a-958f-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}.blockspare-1adf110a-958f-4 .blockspare-counters-item .blockspare-counter{font-size:32px}}@media screen and (max-width:768px){.blockspare-1adf110a-958f-4 .blockspare-section-head-wrap .blockspare-title{font-size:20px}.blockspare-1adf110a-958f-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}.blockspare-1adf110a-958f-4 .blockspare-counters-item .blockspare-counter{font-size:32px}}</style><div class="blockspare-counter-wrapper blockspare-blocks blockspare-section-counter-bar blockspare-hover-item"><div class="blockspare-counter-section"><div class="blockspare-counters-item blockspare-layout2"><div class="blockspare-counters"><div class="blockspare-section-header-wrapper blockspare-blocks"><div class="blockspare-section-head-wrap blockspare-style1 blockspare-center"><div class="blockspare-title-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><h2 class="blockspare-title">Design Sty<strong>les</strong></h2><span class="blockspare-title-dash blockspare-lower-dash"></span></div><div class="blockspare-subtitle-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><span class="blockspare-title-dash blockspare-lower-dash"></span></div></div></div></div><p class="blockspare-counter">5</p></div></div></div></div>
                        <!-- /wp:blockspare/blockspare-counter --></div>
                        <!-- /wp:column -->
                        
                        <!-- wp:column -->
                        <div class="wp-block-column"><!-- wp:blockspare/blockspare-counter {"uniqueClass":"blockspare-aae9947a-cf7b-4","sectionAlignment":"center","headerTitle":"Square Feet Designed","headertitleColor":"#ffde00","headersubtitleColor":"#ffffff","titlePaddingTop":8,"titleFontFamily":"Dosis","titleFontWeight":"300","titleLoadGoogleFonts":true,"paddingTop":0,"paddingRight":0,"paddingBottom":0,"paddingLeft":0,"marginTop":0,"marginBottom":0,"counter":250000,"counterFontColor":"#ffffff","backGroundColor":"#00000000","showImage":false,"design":"blockspare-layout2","iconPaddingTop":0,"iconPaddingBottom":0,"iconPaddingLeft":0,"iconPaddingRight":0,"counterFontSize":40,"counterFontFamily":"Dosis","counterFontWeight":"600","counterFontSizeMobile":32,"counterFontSizeTablet":32,"counterLoadGoogleFonts":true} -->
                        <div class="wp-block-blockspare-blockspare-counter blockspare-aae9947a-cf7b-4 aligncenter" blockspare-animation=""><style>.blockspare-aae9947a-cf7b-4 .blockspare-counter-wrapper{background-color:#00000000;text-align:center;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px;margin-top:0px;margin-right:0px;margin-bottom:0px;margin-left:0px;border-radius:null}.blockspare-aae9947a-cf7b-4 .blockspare-counter-wrapper .blockspare-block-icon{color:#fff;border-radius:50%;margin-top:0px;margin-right:0px;margin-bottom:0px;margin-left:0px;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px}.blockspare-aae9947a-cf7b-4 .blockspare-counter-wrapper .blockspare-block-icon::after{background-color:#8b249c;opacity:1}.blockspare-aae9947a-cf7b-4 .blockspare-counters-item .blockspare-counter{font-size:40px;font-family:Dosis;font-weight:600;margin-top:0px;margin-right:0px;margin-bottom:0px;margin-left:0px}.blockspare-aae9947a-cf7b-4 .blockspare-section-head-wrap{background-color:transparent;text-align:center;margin-top:5px;margin-right:0px;margin-bottom:5px;margin-left:0px}.blockspare-aae9947a-cf7b-4 .blockspare-section-head-wrap .blockspare-title{color:#ffde00;padding-top:8px;padding-right:0px;padding-bottom:0px;padding-left:0px;font-size:24px;font-family:Dosis;font-weight:300}.blockspare-aae9947a-cf7b-4 .blockspare-section-head-wrap .blockspare-subtitle{color:#ffffff;font-size:14px;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px}.blockspare-aae9947a-cf7b-4 .blockspare-counter{color:#ffffff;text-align:center}.blockspare-aae9947a-cf7b-4 .blockspare-block-icon-wrapper{text-align:center}@media screen and (max-width:1025px){.blockspare-aae9947a-cf7b-4 .blockspare-section-head-wrap .blockspare-title{font-size:22px}.blockspare-aae9947a-cf7b-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}.blockspare-aae9947a-cf7b-4 .blockspare-counters-item .blockspare-counter{font-size:32px}}@media screen and (max-width:768px){.blockspare-aae9947a-cf7b-4 .blockspare-section-head-wrap .blockspare-title{font-size:20px}.blockspare-aae9947a-cf7b-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}.blockspare-aae9947a-cf7b-4 .blockspare-counters-item .blockspare-counter{font-size:32px}}</style><div class="blockspare-counter-wrapper blockspare-blocks blockspare-section-counter-bar blockspare-hover-item"><div class="blockspare-counter-section"><div class="blockspare-counters-item blockspare-layout2"><div class="blockspare-counters"><div class="blockspare-section-header-wrapper blockspare-blocks"><div class="blockspare-section-head-wrap blockspare-style1 blockspare-center"><div class="blockspare-title-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><h2 class="blockspare-title">Square Feet Designed</h2><span class="blockspare-title-dash blockspare-lower-dash"></span></div><div class="blockspare-subtitle-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><span class="blockspare-title-dash blockspare-lower-dash"></span></div></div></div></div><p class="blockspare-counter">250000</p></div></div></div></div>
                        <!-- /wp:blockspare/blockspare-counter --></div>
                        <!-- /wp:column --></div>
                        <!-- /wp:columns --></div>
                        <!-- /wp:group --></div></div></div></div>
                        <!-- /wp:blockspare/blockspare-container -->',
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'interior-design',
                        'item'     => ['Interior Design','Content Box'], 
						'key'      => 'bs_section_262',
                        'imagePath'    => 'sections',
						'name'     => 'Interior Design Section 6',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/interior-design-section-6/",
						'content'  => '<!-- wp:blockspare/blockspare-container {"paddingTop":0,"paddingRight":0,"paddingBottom":0,"paddingLeft":0,"marginTop":0,"marginBottom":0,"uniqueClass":"blockspare-73414ca0-8e6f-4","backGroundColor":"#ffde00"} -->
                        <div class="wp-block-blockspare-blockspare-container alignfull blockspare-73414ca0-8e6f-4" blockspare-animation=""><style>.blockspare-73414ca0-8e6f-4 > .blockspare-block-container-wrapper{background-color:#ffde00;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px;margin-top:0px;margin-right:0px;margin-bottom:0px;margin-left:0px;border-radius:0}.blockspare-73414ca0-8e6f-4 .blockspare-image-wrap{background-image:none}</style><div class="blockspare-block-container-wrapper blockspare-hover-item"><div class="blockspare-container-background blockspare-image-wrap has-background-opacity-100 has-background-opacity"></div><div class="blockspare-container"><div class="blockspare-inner-blocks blockspare-inner-wrapper-blocks"><!-- wp:group {"style":{"spacing":{"blockGap":"0","padding":{"bottom":"var:preset|spacing|30","top":"var:preset|spacing|30","right":"var:preset|spacing|30","left":"var:preset|spacing|30"},"margin":{"top":"0","bottom":"0"}}},"layout":{"type":"constrained"}} -->
                        <div class="wp-block-group" style="margin-top:0;margin-bottom:0;padding-top:var(--wp--preset--spacing--30);padding-right:var(--wp--preset--spacing--30);padding-bottom:var(--wp--preset--spacing--30);padding-left:var(--wp--preset--spacing--30)"><!-- wp:columns {"align":"wide","style":{"spacing":{"blockGap":{"top":"var:preset|spacing|30","left":"var:preset|spacing|30"}}}} -->
                        <div class="wp-block-columns alignwide"><!-- wp:column -->
                        <div class="wp-block-column"><!-- wp:blockspare/blockspare-section-header {"uniqueClass":"blockspare-cbc027ad-f5bd-4","headerTitle":"Ready to Transform Your Space?","headertitleColor":"#18191b","headermarginTop":0,"headermarginBottom":0,"titleFontFamily":"Dosis","titleFontWeight":"300","titleFontSizeMobile":24,"titleFontSizeTablet":24,"titleLoadGoogleFonts":true} -->
                        <div class="wp-block-blockspare-blockspare-section-header aligncenter blockspare-cbc027ad-f5bd-4 blockspare-section-header-wrapper blockspare-blocks aligncenter" blockspare-animation=""><style>.blockspare-cbc027ad-f5bd-4 .blockspare-section-head-wrap{background-color:transparent;text-align:left;margin-top:0px;margin-right:0px;margin-bottom:0px;margin-left:0px}.blockspare-cbc027ad-f5bd-4 .blockspare-section-head-wrap .blockspare-title{color:#18191b;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px;font-size:24px;font-family:Dosis;font-weight:300}.blockspare-cbc027ad-f5bd-4 .blockspare-section-head-wrap .blockspare-subtitle{color:#6d6d6d;font-size:14px;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px}@media screen and (max-width:1025px){.blockspare-cbc027ad-f5bd-4 .blockspare-section-head-wrap .blockspare-title{font-size:24px}.blockspare-cbc027ad-f5bd-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}@media screen and (max-width:768px){.blockspare-cbc027ad-f5bd-4 .blockspare-section-head-wrap .blockspare-title{font-size:24px}.blockspare-cbc027ad-f5bd-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}</style><div class="blockspare-section-head-wrap blockspare-style1 blockspare-left blockspare-hover-item"><div class="blockspare-title-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><h2 class="blockspare-title">Ready to Transform Your Space?</h2><span class="blockspare-title-dash blockspare-lower-dash"></span></div><div class="blockspare-subtitle-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><span class="blockspare-title-dash blockspare-lower-dash"></span></div></div></div>
                        <!-- /wp:blockspare/blockspare-section-header --></div>
                        <!-- /wp:column -->
                        
                        <!-- wp:column {"width":"183px"} -->
                        <div class="wp-block-column" style="flex-basis:183px"><!-- wp:blockspare/blockspare-buttons {"sectionAlignment":"left","uniqueClass":"blockspare-dc94b6f9-a874-4","buttonText":"Contact Us Now","buttonShape":"blockspare-button-shape-square","buttonStyle":"solid","borderColor":"#18191b","borderBtnTextColor":"#18191b","btnBorderWidth":1,"buttonFontFamily":"Roboto","buttonFontWeight":"400","buttonFontSizeMobile":16,"buttonFontSizeTablet":16,"buttonLoadGoogleFonts":true,"enableButtonIcon":true,"buttonIcon":"fas fa-angle-right","borderbuttonIconColor":"#18191b","paddingTop":8,"paddingRight":24,"paddingBottom":8,"paddingLeft":24,"marginTop":0,"marginBottom":0} -->
                        <div class="wp-block-blockspare-blockspare-buttons blockspare-dc94b6f9-a874-4 blockspare-block-button-wrap" blockspare-animation=""><style>.blockspare-dc94b6f9-a874-4 .blockspare-block-button{text-align:left;margin-top:0px;margin-bottom:0px;margin-left:0px;margin-right:0px}.blockspare-dc94b6f9-a874-4 .blockspare-block-button i{color:#18191b;font-size:16px}.blockspare-dc94b6f9-a874-4 .blockspare-block-button span{color:#18191b;font-size:16px;font-family:Roboto;font-weight:400}.blockspare-dc94b6f9-a874-4 .blockspare-button{border-color:#18191b;border-style:solid;border-width:1px}.blockspare-dc94b6f9-a874-4 .blockspare-block-button .blockspare-button{background-color:transparent}.blockspare-dc94b6f9-a874-4 .blockspare-block-button a{padding-top:8px;padding-bottom:8px;padding-right:24px;padding-left:24px}@media screen and (max-width:1025px){.blockspare-dc94b6f9-a874-4 .blockspare-block-button span{font-size:16px}.blockspare-dc94b6f9-a874-4 .blockspare-block-button i{font-size:16px}}@media screen and (max-width:768px){.blockspare-dc94b6f9-a874-4 .blockspare-block-button span{font-size:16px}.blockspare-dc94b6f9-a874-4 .blockspare-block-button i{font-size:16px}}</style><div class="blockspare-block-button"><a href="https://blockspare.com/demo/default/interior-design/contact/" class="blockspare-button blockspare-button-shape-square blockspare-button-size-small btn-icon-left"><i class="fas fa-angle-right"></i><span>Contact Us Now</span></a></div></div>
                        <!-- /wp:blockspare/blockspare-buttons --></div>
                        <!-- /wp:column --></div>
                        <!-- /wp:columns --></div>
                        <!-- /wp:group --></div></div></div></div>
                        <!-- /wp:blockspare/blockspare-container -->',
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'yoga',
                        'item'     => ['Yoga','Content Box'], 
						'key'      => 'bs_section_263',
                        'imagePath'    => 'sections',
						'name'     => 'Yoga Section 1',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/yoga-section-1/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'yoga',
                        'item'     => ['Yoga','Testimonial'], 
						'key'      => 'bs_section_264',
                        'imagePath'    => 'sections',
						'name'     => 'Yoga Section 2',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/yoga-section-2/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'yoga',
                        'item'     => ['Yoga','Services'], 
						'key'      => 'bs_section_265',
                        'imagePath'    => 'sections',
						'name'     => 'Yoga Section 3',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/yoga-section-3/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'yoga',
                        'item'     => ['Yoga','Container'], 
						'key'      => 'bs_section_266',
                        'imagePath'    => 'sections',
						'name'     => 'Yoga Section 4',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/yoga-section-4/",
						'content'  => '<!-- wp:blockspare/blockspare-container {"paddingTop":0,"paddingRight":0,"paddingBottom":0,"paddingLeft":0,"marginTop":0,"marginBottom":0,"uniqueClass":"blockspare-04000106-c02b-4","backGroundColor":"#ffffff"} -->
                        <div class="wp-block-blockspare-blockspare-container alignfull blockspare-04000106-c02b-4" blockspare-animation=""><style>.blockspare-04000106-c02b-4 > .blockspare-block-container-wrapper{background-color:#ffffff;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px;margin-top:0px;margin-right:0px;margin-bottom:0px;margin-left:0px;border-radius:0}.blockspare-04000106-c02b-4 .blockspare-image-wrap{background-image:none}</style><div class="blockspare-block-container-wrapper blockspare-hover-item"><div class="blockspare-container-background blockspare-image-wrap has-background-opacity-100 has-background-opacity"></div><div class="blockspare-container"><div class="blockspare-inner-blocks blockspare-inner-wrapper-blocks"><!-- wp:group {"style":{"spacing":{"padding":{"right":"var:preset|spacing|30","left":"var:preset|spacing|30","top":"var:preset|spacing|60","bottom":"var:preset|spacing|60"},"blockGap":"var:preset|spacing|50"}},"layout":{"type":"constrained"}} -->
                        <div class="wp-block-group" style="padding-top:var(--wp--preset--spacing--60);padding-right:var(--wp--preset--spacing--30);padding-bottom:var(--wp--preset--spacing--60);padding-left:var(--wp--preset--spacing--30)"><!-- wp:group {"style":{"spacing":{"blockGap":"var:preset|spacing|30","padding":{"top":"0","bottom":"0"}}},"layout":{"type":"constrained"}} -->
                        <div class="wp-block-group" style="padding-top:0;padding-bottom:0"><!-- wp:blockspare/blockspare-section-header {"uniqueClass":"blockspare-ab560d03-1c55-4","align":"","sectionAlignment":"center","headerTitle":"Your Path to Consistency and Growth","titleFontSize":40,"headerSubTitle":"Time Table","headertitleColor":"#333333","headersubtitleColor":"#f32b56","headermarginTop":0,"headermarginBottom":0,"headerlayoutOption":"blockspare-style3","subtitlePaddingBottom":16,"titleFontFamily":"Oswald","titleFontWeight":"700","titleFontSizeMobile":32,"titleFontSizeTablet":32,"titleLoadGoogleFonts":true,"subTitleFontSize":16,"subTitleFontFamily":"Poppins","subTitleFontWeight":"400","subTitleFontSizeMobile":16,"subTitleLoadGoogleFonts":true} -->
                        <div class="wp-block-blockspare-blockspare-section-header blockspare-ab560d03-1c55-4 blockspare-section-header-wrapper blockspare-blocks align" blockspare-animation=""><style>.blockspare-ab560d03-1c55-4 .blockspare-section-head-wrap{background-color:transparent;text-align:center;margin-top:0px;margin-right:0px;margin-bottom:0px;margin-left:0px}.blockspare-ab560d03-1c55-4 .blockspare-section-head-wrap .blockspare-title{color:#333333;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px;font-size:40px;font-family:Oswald;font-weight:700}.blockspare-ab560d03-1c55-4 .blockspare-section-head-wrap .blockspare-subtitle{color:#f32b56;font-size:16px;font-family:Poppins;font-weight:400;padding-top:0px;padding-right:0px;padding-bottom:16px;padding-left:0px}@media screen and (max-width:1025px){.blockspare-ab560d03-1c55-4 .blockspare-section-head-wrap .blockspare-title{font-size:32px}.blockspare-ab560d03-1c55-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}@media screen and (max-width:768px){.blockspare-ab560d03-1c55-4 .blockspare-section-head-wrap .blockspare-title{font-size:32px}.blockspare-ab560d03-1c55-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:16px}}</style><div class="blockspare-section-head-wrap blockspare-style3 blockspare-center blockspare-hover-item"><div class="blockspare-title-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><h2 class="blockspare-title">Your Path to Consistency and Growth</h2><span class="blockspare-title-dash blockspare-lower-dash"></span></div><div class="blockspare-subtitle-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><p class="blockspare-subtitle">Time Table</p><span class="blockspare-title-dash blockspare-lower-dash"></span></div></div></div>
                        <!-- /wp:blockspare/blockspare-section-header -->
                        
                        <!-- wp:blockspare/blockspare-list {"align":"","uniqueClass":"blockspare-ea51147b-e6f4-4","sectionAlignment":"center","listType":"none","color":"#636363","descriptionFontFamily":"Poppins","descriptionFontWeight":"400","descriptionFontSizeMobile":16,"descriptionFontSizeTablet":16,"descriptionLoadGoogleFonts":true,"marginTop":0,"marginBottom":0} -->
                        <div class="wp-block-blockspare-blockspare-list blockspare-ea51147b-e6f4-4 blockspare-block-iconlist-wrap" blockspare-animation=""><style>.blockspare-ea51147b-e6f4-4 .blockspare-list-wrap{border-radius:0px;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px}.blockspare-ea51147b-e6f4-4 .blockspare-list-wrap .listDescription li{color:#636363;text-align:center;font-size:16px;font-family:Poppins;font-weight:400}.blockspare-ea51147b-e6f4-4 .blockspare-list-wrap .listDescription li:before{color:#404040}.blockspare-ea51147b-e6f4-4 .listDescription li:before{font-size:14px}@media screen and (max-width:1025px){.blockspare-ea51147b-e6f4-4 .blockspare-list-wrap .listDescription li{font-size:16px}.blockspare-ea51147b-e6f4-4 .listDescription li:before{font-size:14px}}@media screen and (max-width:768px){.blockspare-ea51147b-e6f4-4 .blockspare-list-wrap .listDescription li{font-size:16px}.blockspare-ea51147b-e6f4-4 .listDescription li:before{font-size:14px}}</style><div class="blockspare-blocks blockspare-list-wrap blockspare-hover-item"><ul class="listDescription"><!-- wp:list-item -->
                        <li>Welcome to the Timetable section of Inner Balace Yoga, where we provide you with a structured schedule to guide your yoga practice. Explore the variety of classes and timings available, and create a harmonious balance between your practice and life demands.</li>
                        <!-- /wp:list-item --></ul></div></div>
                        <!-- /wp:blockspare/blockspare-list --></div>
                        <!-- /wp:group -->
                        
                        <!-- wp:table {"align":"wide","style":{"border":{"color":"#cccccc"},"color":{"text":"#636363"}},"className":"is-style-regular","fontSize":"small","fontFamily":"inter"} -->
                        <figure class="wp-block-table alignwide is-style-regular has-inter-font-family has-small-font-size"><table class="has-text-color has-border-color" style="color:#636363;border-color:#cccccc"><thead><tr><th class="has-text-align-center" data-align="center"><mark style="background-color:rgba(0, 0, 0, 0);color:#333333" class="has-inline-color">Monday</mark></th><th class="has-text-align-center" data-align="center"><mark style="background-color:rgba(0, 0, 0, 0);color:#333333" class="has-inline-color">Tuesday</mark></th><th class="has-text-align-center" data-align="center"><mark style="background-color:rgba(0, 0, 0, 0);color:#333333" class="has-inline-color">Wednesday</mark></th><th class="has-text-align-center" data-align="center"><mark style="background-color:rgba(0, 0, 0, 0);color:#333333" class="has-inline-color">Thursday</mark></th><th class="has-text-align-center" data-align="center"><mark style="background-color:rgba(0, 0, 0, 0);color:#333333" class="has-inline-color">Friday</mark></th><th class="has-text-align-center" data-align="center"><mark style="background-color:rgba(0, 0, 0, 0);color:#333333" class="has-inline-color">Saturday</mark></th><th class="has-text-align-center" data-align="center"><mark style="background-color:rgba(0, 0, 0, 0);color:#333333" class="has-inline-color">Sunday</mark></th></tr></thead><tbody><tr><td class="has-text-align-center" data-align="center"><strong>x</strong></td><td class="has-text-align-center" data-align="center">Gentle Flow<br><mark style="background-color:rgba(0, 0, 0, 0);color:#f32b56" class="has-inline-color">6 AM - 7 AM</mark></td><td class="has-text-align-center" data-align="center"><strong>x</strong></td><td class="has-text-align-center" data-align="center">Vinyasa Flow<br><mark style="background-color:rgba(0, 0, 0, 0);color:#f32b56" class="has-inline-color">6 AM - 7 AM</mark></td><td class="has-text-align-center" data-align="center"><strong>x</strong></td><td class="has-text-align-center" data-align="center">Hatha Yoga<br><mark style="background-color:rgba(0, 0, 0, 0);color:#f32b56" class="has-inline-color">6 AM - 7 AM</mark></td><td class="has-text-align-center" data-align="center"><strong>x</strong></td></tr><tr><td class="has-text-align-center" data-align="center">Power Yoga<br><mark style="background-color:rgba(0, 0, 0, 0);color:#f32b56" class="has-inline-color">6 AM - 7 AM</mark></td><td class="has-text-align-center" data-align="center"><strong>x</strong></td><td class="has-text-align-center" data-align="center">Restorative Yoga<br><mark style="background-color:rgba(0, 0, 0, 0);color:#f32b56" class="has-inline-color">6 AM - 7 AM</mark></td><td class="has-text-align-center" data-align="center"><strong>x</strong></td><td class="has-text-align-center" data-align="center">Yoga for Stress Relief<br><mark style="background-color:rgba(0, 0, 0, 0);color:#f32b56" class="has-inline-color">6 AM - 7 AM</mark></td><td class="has-text-align-center" data-align="center"><strong>x</strong></td><td class="has-text-align-center" data-align="center">Gentle Flow<br><mark style="background-color:rgba(0, 0, 0, 0);color:#f32b56" class="has-inline-color">6 AM - 7 AM</mark></td></tr><tr><td class="has-text-align-center" data-align="center"><strong>x</strong></td><td class="has-text-align-center" data-align="center">Vinyasa Flow<br><mark style="background-color:rgba(0, 0, 0, 0);color:#f32b56" class="has-inline-color">8 AM - 9 AM</mark></td><td class="has-text-align-center" data-align="center"><strong>x</strong></td><td class="has-text-align-center" data-align="center">Hatha Yoga<br><mark style="background-color:rgba(0, 0, 0, 0);color:#f32b56" class="has-inline-color"><mark style="background-color:rgba(0, 0, 0, 0);color:#f32b56" class="has-inline-color">8 AM - 9 AM</mark></mark></td><td class="has-text-align-center" data-align="center"><strong>x</strong></td><td class="has-text-align-center" data-align="center">Power Yoga<br><mark style="background-color:rgba(0, 0, 0, 0);color:#f32b56" class="has-inline-color"><mark style="background-color:rgba(0, 0, 0, 0);color:#f32b56" class="has-inline-color">8 AM - 9 AM</mark></mark></td><td class="has-text-align-center" data-align="center"></td></tr><tr><td class="has-text-align-center" data-align="center">Restorative Yoga<br><mark style="background-color:rgba(0, 0, 0, 0);color:#f32b56" class="has-inline-color">8 AM - 9 AM</mark></td><td class="has-text-align-center" data-align="center"><strong>x</strong></td><td class="has-text-align-center" data-align="center"><strong><strong>Yoga fo</strong></strong>r<strong><strong> Stress Relief</strong></strong><br><mark style="background-color:rgba(0, 0, 0, 0);color:#f32b56" class="has-inline-color"><mark style="background-color:rgba(0, 0, 0, 0);color:#f32b56" class="has-inline-color">8 AM - 9 AM</mark></mark></td><td class="has-text-align-center" data-align="center"><strong>x</strong></td><td class="has-text-align-center" data-align="center">Gentle Flow<br><mark style="background-color:rgba(0, 0, 0, 0);color:#f32b56" class="has-inline-color">8 AM - 9 AM</mark></td><td class="has-text-align-center" data-align="center"><strong>x</strong></td><td class="has-text-align-center" data-align="center">Vinyasa Flow<br><mark style="background-color:rgba(0, 0, 0, 0);color:#f32b56" class="has-inline-color">8 AM - 9 AM</mark></td></tr></tbody></table></figure>
                        <!-- /wp:table --></div>
                        <!-- /wp:group --></div></div></div></div>
                        <!-- /wp:blockspare/blockspare-container -->',
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'yoga',
                        'item'     => ['Yoga','Pricing Table'], 
						'key'      => 'bs_section_267',
                        'imagePath'    => 'sections',
						'name'     => 'Yoga Section 5',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/yoga-section-5/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'yoga',
                        'item'     => ['Yoga','Testimonial'], 
						'key'      => 'bs_section_268',
                        'imagePath'    => 'sections',
						'name'     => 'Yoga Section 6',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/yoga-section-6/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'yoga',
                        'item'     => ['Yoga','User Profile'], 
						'key'      => 'bs_section_269',
                        'imagePath'    => 'sections',
						'name'     => 'Yoga Section 7',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/yoga-section-7/",
						'content'  => BLOCKSPARE_PRO_PATH,
                    ),
                    array(
						'type'     => 'section',
                        'pages'    =>'yoga',
                        'item'     => ['Yoga','Post Grid'], 
						'key'      => 'bs_section_270',
                        'imagePath'    => 'sections',
						'name'     => 'Yoga Section 8',
                        'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-section/yoga-section-8/",
						'content'  => '<!-- wp:blockspare/blockspare-container {"paddingTop":0,"paddingRight":0,"paddingBottom":0,"paddingLeft":0,"marginTop":0,"marginBottom":0,"uniqueClass":"blockspare-fdf0f9cd-d00f-4","backGroundColor":"#ffffff"} -->
                        <div class="wp-block-blockspare-blockspare-container alignfull blockspare-fdf0f9cd-d00f-4" blockspare-animation=""><style>.blockspare-fdf0f9cd-d00f-4 > .blockspare-block-container-wrapper{background-color:#ffffff;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px;margin-top:0px;margin-right:0px;margin-bottom:0px;margin-left:0px;border-radius:0}.blockspare-fdf0f9cd-d00f-4 .blockspare-image-wrap{background-image:none}</style><div class="blockspare-block-container-wrapper blockspare-hover-item"><div class="blockspare-container-background blockspare-image-wrap has-background-opacity-100 has-background-opacity"></div><div class="blockspare-container"><div class="blockspare-inner-blocks blockspare-inner-wrapper-blocks"><!-- wp:group {"style":{"spacing":{"padding":{"right":"var:preset|spacing|30","left":"var:preset|spacing|30","top":"var:preset|spacing|60","bottom":"var:preset|spacing|60"},"blockGap":"var:preset|spacing|50"}},"layout":{"type":"constrained"}} -->
                        <div class="wp-block-group" style="padding-top:var(--wp--preset--spacing--60);padding-right:var(--wp--preset--spacing--30);padding-bottom:var(--wp--preset--spacing--60);padding-left:var(--wp--preset--spacing--30)"><!-- wp:group {"align":"wide","style":{"spacing":{"blockGap":"var:preset|spacing|30","padding":{"top":"0","bottom":"0"}}},"layout":{"type":"constrained"}} -->
                        <div class="wp-block-group alignwide" style="padding-top:0;padding-bottom:0"><!-- wp:blockspare/blockspare-section-header {"uniqueClass":"blockspare-c06c7f69-66da-4","align":"wide","sectionAlignment":"center","headerTitle":"\u003cstrong\u003eA Wealth of Wisdom for Your Wellness Journey\u003c/strong\u003e","titleFontSize":40,"headerSubTitle":"Explore the Inner Balance Blog","headertitleColor":"#333333","headersubtitleColor":"#f32b56","headermarginTop":0,"headermarginBottom":0,"headerlayoutOption":"blockspare-style3","subtitlePaddingBottom":16,"titleFontFamily":"Oswald","titleFontWeight":"700","titleFontSizeMobile":32,"titleFontSizeTablet":32,"titleLoadGoogleFonts":true,"subTitleFontSize":16,"subTitleFontFamily":"Poppins","subTitleFontWeight":"400","subTitleFontSizeMobile":16,"subTitleLoadGoogleFonts":true} -->
                        <div class="wp-block-blockspare-blockspare-section-header alignwide blockspare-c06c7f69-66da-4 blockspare-section-header-wrapper blockspare-blocks alignwide" blockspare-animation=""><style>.blockspare-c06c7f69-66da-4 .blockspare-section-head-wrap{background-color:transparent;text-align:center;margin-top:0px;margin-right:0px;margin-bottom:0px;margin-left:0px}.blockspare-c06c7f69-66da-4 .blockspare-section-head-wrap .blockspare-title{color:#333333;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px;font-size:40px;font-family:Oswald;font-weight:700}.blockspare-c06c7f69-66da-4 .blockspare-section-head-wrap .blockspare-subtitle{color:#f32b56;font-size:16px;font-family:Poppins;font-weight:400;padding-top:0px;padding-right:0px;padding-bottom:16px;padding-left:0px}@media screen and (max-width:1025px){.blockspare-c06c7f69-66da-4 .blockspare-section-head-wrap .blockspare-title{font-size:32px}.blockspare-c06c7f69-66da-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}@media screen and (max-width:768px){.blockspare-c06c7f69-66da-4 .blockspare-section-head-wrap .blockspare-title{font-size:32px}.blockspare-c06c7f69-66da-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:16px}}</style><div class="blockspare-section-head-wrap blockspare-style3 blockspare-center blockspare-hover-item"><div class="blockspare-title-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><h2 class="blockspare-title"><strong>A Wealth of Wisdom for Your Wellness Journey</strong></h2><span class="blockspare-title-dash blockspare-lower-dash"></span></div><div class="blockspare-subtitle-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><p class="blockspare-subtitle">Explore the Inner Balance Blog</p><span class="blockspare-title-dash blockspare-lower-dash"></span></div></div></div>
                        <!-- /wp:blockspare/blockspare-section-header -->
                        
                        <!-- wp:blockspare/blockspare-list {"align":"","uniqueClass":"blockspare-4ea5cce4-d764-4","sectionAlignment":"center","listType":"none","color":"#636363","descriptionFontFamily":"Poppins","descriptionFontWeight":"400","descriptionFontSizeMobile":16,"descriptionFontSizeTablet":16,"descriptionLoadGoogleFonts":true,"marginTop":0,"marginBottom":0} -->
                        <div class="wp-block-blockspare-blockspare-list blockspare-4ea5cce4-d764-4 blockspare-block-iconlist-wrap" blockspare-animation=""><style>.blockspare-4ea5cce4-d764-4 .blockspare-list-wrap{border-radius:0px;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px}.blockspare-4ea5cce4-d764-4 .blockspare-list-wrap .listDescription li{color:#636363;text-align:center;font-size:16px;font-family:Poppins;font-weight:400}.blockspare-4ea5cce4-d764-4 .blockspare-list-wrap .listDescription li:before{color:#404040}.blockspare-4ea5cce4-d764-4 .listDescription li:before{font-size:14px}@media screen and (max-width:1025px){.blockspare-4ea5cce4-d764-4 .blockspare-list-wrap .listDescription li{font-size:16px}.blockspare-4ea5cce4-d764-4 .listDescription li:before{font-size:14px}}@media screen and (max-width:768px){.blockspare-4ea5cce4-d764-4 .blockspare-list-wrap .listDescription li{font-size:16px}.blockspare-4ea5cce4-d764-4 .listDescription li:before{font-size:14px}}</style><div class="blockspare-blocks blockspare-list-wrap blockspare-hover-item"><ul class="listDescription"><!-- wp:list-item -->
                        <li>Welcome to our Blog section at Inner Balance Yoga, where we share a treasure trove of insights, inspiration, and practical tips to enhance your yoga practice and elevate your overall well-being.</li>
                        <!-- /wp:list-item --></ul></div></div>
                        <!-- /wp:blockspare/blockspare-list --></div>
                        <!-- /wp:group -->
                        
                        <!-- wp:blockspare/blockspare-latest-posts-grid {"uniqueClass":"blockspare-8be4ccc1-0f31-4","postsToShow":3,"displayPostExcerpt":true,"postTitleColor":"#333333","postTitleFontSize":20,"titleFontFamily":"Oswald","titleFontWeight":"500","titleFontSizeMobile":18,"titleFontSizeTablet":18,"titleLoadGoogleFonts":true,"linkColor":"#333333","generalColor":"#636363","columns":3,"align":"wide","excerptLength":17,"marginTop":0,"marginBottom":0,"backGroundColor":"#f7f7f7","descriptionFontSize":16,"descriptionFontFamily":"Poppins","descriptionFontWeight":"400","descriptionFontSizeMobile":16,"descriptionFontSizeTablet":16,"descriptionLoadGoogleFonts":true,"contentPaddingTop":24,"contentPaddingLeft":24,"contentPaddingBottom":24,"contentPaddingRight":24,"categoryMarginTop":0,"categoryMarginBottom":8,"categoryMarginLeft":8,"titleMarginTop":0,"titleMarginBottom":0,"metaMarginTop":16,"metaMarginBottom":16,"exceprtMarginTop":0,"exceprtMarginBottom":16,"moreLinkMarginTop":0,"moreLinkMarginBottom":0,"categoryBackgroundColor":"#333333","titleOnHoverColor":"#f32b56","gutterSpace":30} /--></div>
                        <!-- /wp:group --></div></div></div></div>
                        <!-- /wp:blockspare/blockspare-container -->',
                    )
                    
				);

            return array_merge_recursive( $blocks_lists, $block_library_list );
        }
	}
}
Blockspare_Section_Template_Block::get_instance()->run();