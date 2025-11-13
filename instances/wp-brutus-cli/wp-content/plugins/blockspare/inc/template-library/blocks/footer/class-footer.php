<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Blockspare_Footer_Template_Block' ) ) {

	class Blockspare_Footer_Template_Block extends Blockspare_Import_Block_Base{
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
						'type'     => 'footer',
                        'pages'    =>'agency',
						'item'     => ['Footer'],
						'key'      => 'bs_footer_1',
						'name'     => 'Agency Footer',
						'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-footer/agency-footer/",
						'content'  => '<!-- wp:blockspare/blockspare-container {"paddingTop":50,"paddingBottom":50,"uniqueClass":"blockspare-a562e213-0640-4","backGroundColor":"#ffffff"} -->
                        <div class="wp-block-blockspare-blockspare-container alignfull blockspare-a562e213-0640-4" blockspare-animation=""><style>.blockspare-a562e213-0640-4 > .blockspare-block-container-wrapper{background-color:#ffffff;padding-top:50px;padding-right:20px;padding-bottom:50px;padding-left:20px;margin-top:30px;margin-right:0px;margin-bottom:30px;margin-left:0px;border-radius:0}.blockspare-a562e213-0640-4 .blockspare-image-wrap{background-image:none}</style><div class="blockspare-block-container-wrapper blockspare-hover-item"><div class="blockspare-container-background blockspare-image-wrap has-background-opacity-100 has-background-opacity"></div><div class="blockspare-container"><div class="blockspare-inner-blocks blockspare-inner-wrapper-blocks"><!-- wp:group {"align":"wide","layout":{"type":"constrained"}} -->
                        <div class="wp-block-group alignwide"><!-- wp:columns {"align":"wide"} -->
                        <div class="wp-block-columns alignwide"><!-- wp:column -->
                        <div class="wp-block-column"><!-- wp:blockspare/blockspare-section-header {"uniqueClass":"blockspare-0948a6be-28e6-4","headerTitle":"Online Course","headerSubTitle":"Lorem ipsum dolor sit amet, consetetur sadip scing elitr, sed di nonumy eirmod temporinvi dunt ut labore lorem ipsum.","headermarginRight":20,"subtitlePaddingTop":25,"titleFontWeight":"700","titleFontSubset":"latin","subTitleFontSize":16,"subTitleFontWeight":"default","subTitleFontSubset":"latin"} -->
                        <div class="wp-block-blockspare-blockspare-section-header aligncenter blockspare-0948a6be-28e6-4 blockspare-section-header-wrapper blockspare-blocks aligncenter" blockspare-animation=""><style>.blockspare-0948a6be-28e6-4 .blockspare-section-head-wrap{background-color:transparent;text-align:left;margin-top:30px;margin-right:20px;margin-bottom:30px;margin-left:0px}.blockspare-0948a6be-28e6-4 .blockspare-section-head-wrap .blockspare-title{color:#404040;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px;font-size:24px;font-weight:700}.blockspare-0948a6be-28e6-4 .blockspare-section-head-wrap .blockspare-subtitle{color:#6d6d6d;font-size:16px;font-weight:default;padding-top:25px;padding-right:0px;padding-bottom:0px;padding-left:0px}@media screen and (max-width:1025px){.blockspare-0948a6be-28e6-4 .blockspare-section-head-wrap .blockspare-title{font-size:22px}.blockspare-0948a6be-28e6-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}@media screen and (max-width:768px){.blockspare-0948a6be-28e6-4 .blockspare-section-head-wrap .blockspare-title{font-size:20px}.blockspare-0948a6be-28e6-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}</style><div class="blockspare-section-head-wrap blockspare-style1 blockspare-left blockspare-hover-item"><div class="blockspare-title-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><h2 class="blockspare-title">Online Course</h2><span class="blockspare-title-dash blockspare-lower-dash"></span></div><div class="blockspare-subtitle-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><p class="blockspare-subtitle">Lorem ipsum dolor sit amet, consetetur sadip scing elitr, sed di nonumy eirmod temporinvi dunt ut labore lorem ipsum.</p><span class="blockspare-title-dash blockspare-lower-dash"></span></div></div></div>
                        <!-- /wp:blockspare/blockspare-section-header -->
                        
                        <!-- wp:blockspare/blockspare-social-sharing {"sectionAlignments":"left","iconColorOption":"custom","custombackgroundColorOption":"#2e947d","uniqueClass":"blockspare-3157dd6b-67aa-4"} /--></div>
                        <!-- /wp:column -->
                        
                        <!-- wp:column -->
                        <div class="wp-block-column"><!-- wp:blockspare/blockspare-section-header {"uniqueClass":"blockspare-30ef6c01-1835-4","headerTitle":"Contact Us","headermarginBottom":15,"titleFontWeight":"700","titleFontSubset":"latin","subTitleFontSize":0,"subTitleFontWeight":"default","subTitleFontSubset":"latin"} -->
                        <div class="wp-block-blockspare-blockspare-section-header aligncenter blockspare-30ef6c01-1835-4 blockspare-section-header-wrapper blockspare-blocks aligncenter" blockspare-animation=""><style>.blockspare-30ef6c01-1835-4 .blockspare-section-head-wrap{background-color:transparent;text-align:left;margin-top:30px;margin-right:0px;margin-bottom:15px;margin-left:0px}.blockspare-30ef6c01-1835-4 .blockspare-section-head-wrap .blockspare-title{color:#404040;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px;font-size:24px;font-weight:700}.blockspare-30ef6c01-1835-4 .blockspare-section-head-wrap .blockspare-subtitle{color:#6d6d6d;font-size:0px;font-weight:default;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px}@media screen and (max-width:1025px){.blockspare-30ef6c01-1835-4 .blockspare-section-head-wrap .blockspare-title{font-size:22px}.blockspare-30ef6c01-1835-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}@media screen and (max-width:768px){.blockspare-30ef6c01-1835-4 .blockspare-section-head-wrap .blockspare-title{font-size:20px}.blockspare-30ef6c01-1835-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}</style><div class="blockspare-section-head-wrap blockspare-style1 blockspare-left blockspare-hover-item"><div class="blockspare-title-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><h2 class="blockspare-title">Contact Us</h2><span class="blockspare-title-dash blockspare-lower-dash"></span></div><div class="blockspare-subtitle-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><span class="blockspare-title-dash blockspare-lower-dash"></span></div></div></div>
                        <!-- /wp:blockspare/blockspare-section-header -->
                        
                        <!-- wp:blockspare/blockspare-list {"align":"","uniqueClass":"blockspare-9d8727d2-94c5-4","listType":"none","color":"#6d6d6d","descriptionFontWeight":"400","marginTop":0} -->
                        <div class="wp-block-blockspare-blockspare-list blockspare-9d8727d2-94c5-4 blockspare-block-iconlist-wrap" blockspare-animation=""><style>.blockspare-9d8727d2-94c5-4 .blockspare-list-wrap{border-radius:0px;margin-bottom:30px;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px}.blockspare-9d8727d2-94c5-4 .blockspare-list-wrap .listDescription li{color:#6d6d6d;text-align:left;font-size:16px;font-weight:400}.blockspare-9d8727d2-94c5-4 .blockspare-list-wrap .listDescription li:before{color:#404040}.blockspare-9d8727d2-94c5-4 .listDescription li:before{font-size:14px}@media screen and (max-width:1025px){.blockspare-9d8727d2-94c5-4 .blockspare-list-wrap .listDescription li{font-size:14px}.blockspare-9d8727d2-94c5-4 .listDescription li:before{font-size:14px}}@media screen and (max-width:768px){.blockspare-9d8727d2-94c5-4 .blockspare-list-wrap .listDescription li{font-size:14px}.blockspare-9d8727d2-94c5-4 .listDescription li:before{font-size:14px}}</style><div class="blockspare-blocks blockspare-list-wrap blockspare-hover-item"><ul class="listDescription"><!-- wp:list-item -->
                        <li>info@example.com</li>
                        <!-- /wp:list-item -->
                        
                        <!-- wp:list-item -->
                        <li>+00 235 695 58</li>
                        <!-- /wp:list-item -->
                        
                        <!-- wp:list-item -->
                        <li>570 8th Ave</li>
                        <!-- /wp:list-item -->
                        
                        <!-- wp:list-item -->
                        <li>New York, NY 10018</li>
                        <!-- /wp:list-item -->
                        
                        <!-- wp:list-item -->
                        <li>United States</li>
                        <!-- /wp:list-item --></ul></div></div>
                        <!-- /wp:blockspare/blockspare-list --></div>
                        <!-- /wp:column -->
                        
                        <!-- wp:column -->
                        <div class="wp-block-column"><!-- wp:blockspare/blockspare-section-header {"uniqueClass":"blockspare-57f63f78-42fa-4","headerTitle":"Quick Links","headermarginBottom":15,"titleFontWeight":"700","titleFontSubset":"latin","subTitleFontSize":0,"subTitleFontWeight":"default","subTitleFontSubset":"latin"} -->
                        <div class="wp-block-blockspare-blockspare-section-header aligncenter blockspare-57f63f78-42fa-4 blockspare-section-header-wrapper blockspare-blocks aligncenter" blockspare-animation=""><style>.blockspare-57f63f78-42fa-4 .blockspare-section-head-wrap{background-color:transparent;text-align:left;margin-top:30px;margin-right:0px;margin-bottom:15px;margin-left:0px}.blockspare-57f63f78-42fa-4 .blockspare-section-head-wrap .blockspare-title{color:#404040;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px;font-size:24px;font-weight:700}.blockspare-57f63f78-42fa-4 .blockspare-section-head-wrap .blockspare-subtitle{color:#6d6d6d;font-size:0px;font-weight:default;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px}@media screen and (max-width:1025px){.blockspare-57f63f78-42fa-4 .blockspare-section-head-wrap .blockspare-title{font-size:22px}.blockspare-57f63f78-42fa-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}@media screen and (max-width:768px){.blockspare-57f63f78-42fa-4 .blockspare-section-head-wrap .blockspare-title{font-size:20px}.blockspare-57f63f78-42fa-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}</style><div class="blockspare-section-head-wrap blockspare-style1 blockspare-left blockspare-hover-item"><div class="blockspare-title-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><h2 class="blockspare-title">Quick Links</h2><span class="blockspare-title-dash blockspare-lower-dash"></span></div><div class="blockspare-subtitle-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><span class="blockspare-title-dash blockspare-lower-dash"></span></div></div></div>
                        <!-- /wp:blockspare/blockspare-section-header -->
                        
                        <!-- wp:blockspare/blockspare-list {"align":"","uniqueClass":"blockspare-4c3d42bd-6b2d-4","listType":"none","color":"#6d6d6d","descriptionFontWeight":"400","marginTop":0} -->
                        <div class="wp-block-blockspare-blockspare-list blockspare-4c3d42bd-6b2d-4 blockspare-block-iconlist-wrap" blockspare-animation=""><style>.blockspare-4c3d42bd-6b2d-4 .blockspare-list-wrap{border-radius:0px;margin-bottom:30px;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px}.blockspare-4c3d42bd-6b2d-4 .blockspare-list-wrap .listDescription li{color:#6d6d6d;text-align:left;font-size:16px;font-weight:400}.blockspare-4c3d42bd-6b2d-4 .blockspare-list-wrap .listDescription li:before{color:#404040}.blockspare-4c3d42bd-6b2d-4 .listDescription li:before{font-size:14px}@media screen and (max-width:1025px){.blockspare-4c3d42bd-6b2d-4 .blockspare-list-wrap .listDescription li{font-size:14px}.blockspare-4c3d42bd-6b2d-4 .listDescription li:before{font-size:14px}}@media screen and (max-width:768px){.blockspare-4c3d42bd-6b2d-4 .blockspare-list-wrap .listDescription li{font-size:14px}.blockspare-4c3d42bd-6b2d-4 .listDescription li:before{font-size:14px}}</style><div class="blockspare-blocks blockspare-list-wrap blockspare-hover-item"><ul class="listDescription"><!-- wp:list-item -->
                        <li>About Us</li>
                        <!-- /wp:list-item -->
                        
                        <!-- wp:list-item -->
                        <li>Explore Pages</li>
                        <!-- /wp:list-item -->
                        
                        <!-- wp:list-item -->
                        <li>our Services</li>
                        <!-- /wp:list-item -->
                        
                        <!-- wp:list-item -->
                        <li>Training Center</li>
                        <!-- /wp:list-item --></ul></div></div>
                        <!-- /wp:blockspare/blockspare-list --></div>
                        <!-- /wp:column -->
                        
                        <!-- wp:column -->
                        <div class="wp-block-column"><!-- wp:blockspare/blockspare-section-header {"uniqueClass":"blockspare-fb39d55a-a3b7-4","headerTitle":"Features","headermarginBottom":15,"titleFontWeight":"700","titleFontSubset":"latin","subTitleFontSize":0,"subTitleFontWeight":"default","subTitleFontSubset":"latin"} -->
                        <div class="wp-block-blockspare-blockspare-section-header aligncenter blockspare-fb39d55a-a3b7-4 blockspare-section-header-wrapper blockspare-blocks aligncenter" blockspare-animation=""><style>.blockspare-fb39d55a-a3b7-4 .blockspare-section-head-wrap{background-color:transparent;text-align:left;margin-top:30px;margin-right:0px;margin-bottom:15px;margin-left:0px}.blockspare-fb39d55a-a3b7-4 .blockspare-section-head-wrap .blockspare-title{color:#404040;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px;font-size:24px;font-weight:700}.blockspare-fb39d55a-a3b7-4 .blockspare-section-head-wrap .blockspare-subtitle{color:#6d6d6d;font-size:0px;font-weight:default;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px}@media screen and (max-width:1025px){.blockspare-fb39d55a-a3b7-4 .blockspare-section-head-wrap .blockspare-title{font-size:22px}.blockspare-fb39d55a-a3b7-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}@media screen and (max-width:768px){.blockspare-fb39d55a-a3b7-4 .blockspare-section-head-wrap .blockspare-title{font-size:20px}.blockspare-fb39d55a-a3b7-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}</style><div class="blockspare-section-head-wrap blockspare-style1 blockspare-left blockspare-hover-item"><div class="blockspare-title-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><h2 class="blockspare-title">Features</h2><span class="blockspare-title-dash blockspare-lower-dash"></span></div><div class="blockspare-subtitle-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><span class="blockspare-title-dash blockspare-lower-dash"></span></div></div></div>
                        <!-- /wp:blockspare/blockspare-section-header -->
                        
                        <!-- wp:blockspare/blockspare-list {"align":"","uniqueClass":"blockspare-8971e16d-73cb-4","listType":"none","color":"#6d6d6d","descriptionFontWeight":"400","marginTop":0} -->
                        <div class="wp-block-blockspare-blockspare-list blockspare-8971e16d-73cb-4 blockspare-block-iconlist-wrap" blockspare-animation=""><style>.blockspare-8971e16d-73cb-4 .blockspare-list-wrap{border-radius:0px;margin-bottom:30px;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px}.blockspare-8971e16d-73cb-4 .blockspare-list-wrap .listDescription li{color:#6d6d6d;text-align:left;font-size:16px;font-weight:400}.blockspare-8971e16d-73cb-4 .blockspare-list-wrap .listDescription li:before{color:#404040}.blockspare-8971e16d-73cb-4 .listDescription li:before{font-size:14px}@media screen and (max-width:1025px){.blockspare-8971e16d-73cb-4 .blockspare-list-wrap .listDescription li{font-size:14px}.blockspare-8971e16d-73cb-4 .listDescription li:before{font-size:14px}}@media screen and (max-width:768px){.blockspare-8971e16d-73cb-4 .blockspare-list-wrap .listDescription li{font-size:14px}.blockspare-8971e16d-73cb-4 .listDescription li:before{font-size:14px}}</style><div class="blockspare-blocks blockspare-list-wrap blockspare-hover-item"><ul class="listDescription"><!-- wp:list-item -->
                        <li>Home page</li>
                        <!-- /wp:list-item -->
                        
                        <!-- wp:list-item -->
                        <li>Testimonials</li>
                        <!-- /wp:list-item -->
                        
                        <!-- wp:list-item -->
                        <li>Latest News</li>
                        <!-- /wp:list-item -->
                        
                        <!-- wp:list-item -->
                        <li>Help Center</li>
                        <!-- /wp:list-item --></ul></div></div>
                        <!-- /wp:blockspare/blockspare-list --></div>
                        <!-- /wp:column --></div>
                        <!-- /wp:columns --></div>
                        <!-- /wp:group --></div></div></div></div>
                        <!-- /wp:blockspare/blockspare-container -->',
						'imagePath'    => 'footer',

                    ),
					array(
						'type'     => 'footer',
                        'pages'    =>'lawyer',
						'item'     => ['Footer'],
						'key'      => 'bs_footer_2',
						'name'     => 'Lawyer Footer',
						'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-footer/lawyer-footer/",
						'content'  => BLOCKSPARE_PRO_PATH,
						'imagePath'    => 'footer',

                    ),
					array(
						'type'     => 'footer',
                        'pages'    =>'restaurant',
						'item'     => ['Footer'],
						'key'      => 'bs_footer_3',
						'name'     => 'Restaurant Footer',
						'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-footer/restaurant-footer/",
						'content'  => BLOCKSPARE_PRO_PATH,
						'imagePath'    => 'footer',

                    ),
					array(
						'type'     => 'footer',
                        'pages'    =>'apps',
						'item'     => ['Footer'],
						'key'      => 'bs_footer_4',
						'name'     => 'Apps Footer',
						'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-footer/apps-footer/",
						'content'  => '<!-- wp:blockspare/blockspare-container {"paddingTop":80,"paddingBottom":80,"marginTop":0,"marginBottom":0,"uniqueClass":"blockspare-b69adc3d-b578-4","backGroundColor":"#000000"} -->
                        <div class="wp-block-blockspare-blockspare-container alignfull blockspare-b69adc3d-b578-4" blockspare-animation=""><style>.blockspare-b69adc3d-b578-4 > .blockspare-block-container-wrapper{background-color:#000000;padding-top:80px;padding-right:20px;padding-bottom:80px;padding-left:20px;margin-top:0px;margin-right:0px;margin-bottom:0px;margin-left:0px;border-radius:0}.blockspare-b69adc3d-b578-4 .blockspare-image-wrap{background-image:none}</style><div class="blockspare-block-container-wrapper blockspare-hover-item"><div class="blockspare-container-background blockspare-image-wrap has-background-opacity-100 has-background-opacity"></div><div class="blockspare-container"><div class="blockspare-inner-blocks blockspare-inner-wrapper-blocks"><!-- wp:group {"align":"wide","layout":{"type":"constrained"}} -->
                        <div class="wp-block-group alignwide"><!-- wp:columns {"align":"wide"} -->
                        <div class="wp-block-columns alignwide"><!-- wp:column -->
                        <div class="wp-block-column"><!-- wp:columns -->
                        <div class="wp-block-columns"><!-- wp:column {"width":"66.66%"} -->
                        <div class="wp-block-column" style="flex-basis:66.66%"><!-- wp:columns -->
                        <div class="wp-block-columns"><!-- wp:column -->
                        <div class="wp-block-column"><!-- wp:blockspare/blockspare-section-header {"uniqueClass":"blockspare-87aef984-0ae2-4","headerTitle":"Alan Dunkan","headerSubTitle":"Lorem ipsum dolor sit amet, consectetur adipiscing elit. Sed non sem congue, dapibus mauris quis, tincidunt mi.","headertitleColor":"#ffffff","headersubtitleColor":"#cccccc","headermarginBottom":0,"subtitlePaddingTop":20,"titleFontFamily":"Poppins","titleFontWeight":"700","titleLoadGoogleFonts":true,"subTitleFontSize":16,"subTitleFontFamily":"Helvetica"} -->
                        <div class="wp-block-blockspare-blockspare-section-header aligncenter blockspare-87aef984-0ae2-4 blockspare-section-header-wrapper blockspare-blocks aligncenter" blockspare-animation=""><style>.blockspare-87aef984-0ae2-4 .blockspare-section-head-wrap{background-color:transparent;text-align:left;margin-top:30px;margin-right:0px;margin-bottom:0px;margin-left:0px}.blockspare-87aef984-0ae2-4 .blockspare-section-head-wrap .blockspare-title{color:#ffffff;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px;font-size:24px;font-family:Poppins;font-weight:700}.blockspare-87aef984-0ae2-4 .blockspare-section-head-wrap .blockspare-subtitle{color:#cccccc;font-size:16px;font-family:Helvetica;padding-top:20px;padding-right:0px;padding-bottom:0px;padding-left:0px}@media screen and (max-width:1025px){.blockspare-87aef984-0ae2-4 .blockspare-section-head-wrap .blockspare-title{font-size:22px}.blockspare-87aef984-0ae2-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}@media screen and (max-width:768px){.blockspare-87aef984-0ae2-4 .blockspare-section-head-wrap .blockspare-title{font-size:20px}.blockspare-87aef984-0ae2-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}</style><div class="blockspare-section-head-wrap blockspare-style1 blockspare-left blockspare-hover-item"><div class="blockspare-title-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><h2 class="blockspare-title">Alan Dunkan</h2><span class="blockspare-title-dash blockspare-lower-dash"></span></div><div class="blockspare-subtitle-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><p class="blockspare-subtitle">Lorem ipsum dolor sit amet, consectetur adipiscing elit. Sed non sem congue, dapibus mauris quis, tincidunt mi.</p><span class="blockspare-title-dash blockspare-lower-dash"></span></div></div></div>
                        <!-- /wp:blockspare/blockspare-section-header --></div>
                        <!-- /wp:column -->
                        
                        <!-- wp:column -->
                        <div class="wp-block-column"><!-- wp:blockspare/blockspare-section-header {"uniqueClass":"blockspare-6cc331f1-8748-4","headerTitle":"Office","headerSubTitle":"653 Jett Lane, Suite 50,\u003cbr\u003eBeverly Hills, CA 90210\u003cbr\u003e\u003cstrong\u003eHours\u003c/strong\u003e Mon – Fri 7:30AM-10:30PM PST","headertitleColor":"#ffffff","headersubtitleColor":"#cccccc","subtitlePaddingTop":20,"titleFontFamily":"Poppins","titleFontWeight":"700","titleLoadGoogleFonts":true,"subTitleFontSize":16,"subTitleFontFamily":"Helvetica"} -->
                        <div class="wp-block-blockspare-blockspare-section-header aligncenter blockspare-6cc331f1-8748-4 blockspare-section-header-wrapper blockspare-blocks aligncenter" blockspare-animation=""><style>.blockspare-6cc331f1-8748-4 .blockspare-section-head-wrap{background-color:transparent;text-align:left;margin-top:30px;margin-right:0px;margin-bottom:30px;margin-left:0px}.blockspare-6cc331f1-8748-4 .blockspare-section-head-wrap .blockspare-title{color:#ffffff;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px;font-size:24px;font-family:Poppins;font-weight:700}.blockspare-6cc331f1-8748-4 .blockspare-section-head-wrap .blockspare-subtitle{color:#cccccc;font-size:16px;font-family:Helvetica;padding-top:20px;padding-right:0px;padding-bottom:0px;padding-left:0px}@media screen and (max-width:1025px){.blockspare-6cc331f1-8748-4 .blockspare-section-head-wrap .blockspare-title{font-size:22px}.blockspare-6cc331f1-8748-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}@media screen and (max-width:768px){.blockspare-6cc331f1-8748-4 .blockspare-section-head-wrap .blockspare-title{font-size:20px}.blockspare-6cc331f1-8748-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}</style><div class="blockspare-section-head-wrap blockspare-style1 blockspare-left blockspare-hover-item"><div class="blockspare-title-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><h2 class="blockspare-title">Office</h2><span class="blockspare-title-dash blockspare-lower-dash"></span></div><div class="blockspare-subtitle-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><p class="blockspare-subtitle">653 Jett Lane, Suite 50,<br>Beverly Hills, CA 90210<br><strong>Hours</strong> Mon – Fri 7:30AM-10:30PM PST</p><span class="blockspare-title-dash blockspare-lower-dash"></span></div></div></div>
                        <!-- /wp:blockspare/blockspare-section-header --></div>
                        <!-- /wp:column --></div>
                        <!-- /wp:columns --></div>
                        <!-- /wp:column -->
                        
                        <!-- wp:column {"width":"33.33%"} -->
                        <div class="wp-block-column" style="flex-basis:33.33%"><!-- wp:blockspare/blockspare-section-header {"uniqueClass":"blockspare-51f53ac1-22a3-4","headerTitle":"Stay in Touch","headertitleColor":"#ffffff","titleFontFamily":"Poppins","titleFontWeight":"700","titleLoadGoogleFonts":true,"subTitleFontSize":0} -->
                        <div class="wp-block-blockspare-blockspare-section-header aligncenter blockspare-51f53ac1-22a3-4 blockspare-section-header-wrapper blockspare-blocks aligncenter" blockspare-animation=""><style>.blockspare-51f53ac1-22a3-4 .blockspare-section-head-wrap{background-color:transparent;text-align:left;margin-top:30px;margin-right:0px;margin-bottom:30px;margin-left:0px}.blockspare-51f53ac1-22a3-4 .blockspare-section-head-wrap .blockspare-title{color:#ffffff;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px;font-size:24px;font-family:Poppins;font-weight:700}.blockspare-51f53ac1-22a3-4 .blockspare-section-head-wrap .blockspare-subtitle{color:#6d6d6d;font-size:0px;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px}@media screen and (max-width:1025px){.blockspare-51f53ac1-22a3-4 .blockspare-section-head-wrap .blockspare-title{font-size:22px}.blockspare-51f53ac1-22a3-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}@media screen and (max-width:768px){.blockspare-51f53ac1-22a3-4 .blockspare-section-head-wrap .blockspare-title{font-size:20px}.blockspare-51f53ac1-22a3-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}</style><div class="blockspare-section-head-wrap blockspare-style1 blockspare-left blockspare-hover-item"><div class="blockspare-title-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><h2 class="blockspare-title">Stay in Touch</h2><span class="blockspare-title-dash blockspare-lower-dash"></span></div><div class="blockspare-subtitle-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><span class="blockspare-title-dash blockspare-lower-dash"></span></div></div></div>
                        <!-- /wp:blockspare/blockspare-section-header -->
                        
                        <!-- wp:blockspare/blockspare-social-sharing {"sectionAlignments":"left","iconColorOption":"custom","custombackgroundColorOption":"#ea4b50","buttonShapes":"blockspare-social-icon-circle","uniqueClass":"blockspare-65c24b87-7092-4"} /--></div>
                        <!-- /wp:column --></div>
                        <!-- /wp:columns --></div>
                        <!-- /wp:column --></div>
                        <!-- /wp:columns --></div>
                        <!-- /wp:group --></div></div></div></div>
                        <!-- /wp:blockspare/blockspare-container -->',
						'imagePath'    => 'footer',

                    ),
					array(
						'type'     => 'footer',
                        'pages'    =>'education',
						'item'     => ['Footer'],
						'key'      => 'bs_footer_5',
						'name'     => 'Education Footer',
						'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-footer/education-footer/",
						'content'  => BLOCKSPARE_PRO_PATH,
						'imagePath'    => 'footer',

                    ),
					array(
						'type'     => 'footer',
                        'pages'    =>'fitness',
						'item'     => ['Footer'],
						'key'      => 'bs_footer_6',
						'name'     => 'Fitness Footer',
						'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-footer/fitness-footer/",
						'content'  => BLOCKSPARE_PRO_PATH,
						'imagePath'    => 'footer',

                    ),
					array(
						'type'     => 'footer',
                        'pages'    =>'real-estate',
						'item'     => ['Footer'],
						'key'      => 'bs_footer_7',
						'name'     => 'Real Estate Footer',
						'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-footer/real-estate-footer/",
						'content'  => BLOCKSPARE_PRO_PATH,
						'imagePath'    => 'footer',

                    ),
					array(
						'type'     => 'footer',
                        'pages'    =>'medical',
						'item'     => ['Footer'],
						'key'      => 'bs_footer_8',
						'name'     => 'Medical Footer',
						'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-footer/medical-footer/",
						'content'  => BLOCKSPARE_PRO_PATH,
						'imagePath'    => 'footer',

                    ),
					array(
						'type'     => 'footer',
                        'pages'    =>'charity',
						'item'     => ['Footer'],
						'key'      => 'bs_footer_9',
						'name'     => 'Charity Footer',
						'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-footer/charity-footer/",
						'content'  => BLOCKSPARE_PRO_PATH,
						'imagePath'    => 'footer',

                    ),
					array(
						'type'     => 'footer',
                        'pages'    =>'general',
						'item'     => ['Footer'],
						'key'      => 'bs_footer_10',
						'name'     => 'General Footer',
						'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-footer/general-footer/",
						'content'  => BLOCKSPARE_PRO_PATH,
						'imagePath'    => 'footer',

                    ),
					array(
						'type'     => 'footer',
                        'pages'    =>'sport',
						'item'     => ['Footer'],
						'key'      => 'bs_footer_11',
						'name'     => 'Sport Footer',
						'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-footer/sport-footer/",
						'content'  => BLOCKSPARE_PRO_PATH,
						'imagePath'    => 'footer',

                    ),
					array(
						'type'     => 'footer',
                        'pages'    =>'fashion',
						'item'     => ['Footer'],
						'key'      => 'bs_footer_12',
						'name'     => 'Fashion Footer',
						'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-footer/fashion-footer/",
						'content'  => BLOCKSPARE_PRO_PATH,
						'imagePath'    => 'footer',

                    ),
					array(
						'type'     => 'footer',
                        'pages'    =>'recipe',
						'item'     => ['Footer'],
						'key'      => 'bs_footer_13',
						'name'     => 'Recipe Footer',
						'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-footer/recipe-footer/",
						'content'  => BLOCKSPARE_PRO_PATH,
						'imagePath'    => 'footer',

                    ),
					array(
						'type'     => 'footer',
                        'pages'    =>'chinese',
						'item'     => ['Footer'],
						'key'      => 'bs_footer_14',
						'name'     => 'Chinese Footer',
						'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-footer/chinese-footer/",
						'content'  => BLOCKSPARE_PRO_PATH,
						'imagePath'    => 'footer',

                    ),
					array(
						'type'     => 'footer',
                        'pages'    =>'pet-care',
						'item'     => ['Footer'],
						'key'      => 'bs_footer_15',
						'name'     => 'Pet Care Footer',
						'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-footer/pet-care-footer/",
						'content'  => BLOCKSPARE_PRO_PATH,
						'imagePath'    => 'footer',

                    ),
					array(
						'type'     => 'footer',
                        'pages'    =>'gadgets',
						'item'     => ['Footer'],
						'key'      => 'bs_footer_16',
						'name'     => 'Gadgets Footer',
						'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-footer/gadgets-footer/",
						'content'  => BLOCKSPARE_PRO_PATH,
						'imagePath'    => 'footer',

                    ),
					array(
						'type'     => 'footer',
                        'pages'    =>'construction',
						'item'     => ['Footer'],
						'key'      => 'bs_footer_17',
						'name'     => 'Construction Footer',
						'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-footer/construction-footer/",
						'content'  => BLOCKSPARE_PRO_PATH,
						'imagePath'    => 'footer',

                    ),
					array(
						'type'     => 'footer',
                        'pages'    =>'florista',
						'item'     => ['Footer'],
						'key'      => 'bs_footer_18',
						'name'     => 'Florista Footer',
						'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-footer/florista-footer/",
						'content'  => BLOCKSPARE_PRO_PATH,
						'imagePath'    => 'footer',

                    ),
					array(
						'type'     => 'footer',
                        'pages'    =>'autoservice',
						'item'     => ['Footer'],
						'key'      => 'bs_footer_19',
						'name'     => 'Auto Service Footer',
						'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-footer/auto-service-footer/",
						'content'  => '<!-- wp:blockspare/blockspare-container {"paddingTop":60,"paddingBottom":60,"marginTop":0,"marginBottom":0,"uniqueClass":"blockspare-89a7dfb9-aab6-4","opacityRatio":0,"backGroundColor":"#000000"} -->
                        <div class="wp-block-blockspare-blockspare-container alignfull blockspare-89a7dfb9-aab6-4" blockspare-animation=""><style>.blockspare-89a7dfb9-aab6-4 > .blockspare-block-container-wrapper{background-color:#000000;padding-top:60px;padding-right:20px;padding-bottom:60px;padding-left:20px;margin-top:0px;margin-right:0px;margin-bottom:0px;margin-left:0px;border-radius:0}.blockspare-89a7dfb9-aab6-4 .blockspare-image-wrap{background-image:none}</style><div class="blockspare-block-container-wrapper blockspare-hover-item"><div class="blockspare-container-background blockspare-image-wrap"></div><div class="blockspare-container"><div class="blockspare-inner-blocks blockspare-inner-wrapper-blocks"><!-- wp:spacer {"height":"30px"} -->
                        <div style="height:30px" aria-hidden="true" class="wp-block-spacer"></div>
                        <!-- /wp:spacer -->
                        
                        <!-- wp:group {"align":"wide","layout":{"type":"constrained"}} -->
                        <div class="wp-block-group alignwide"><!-- wp:columns {"align":"wide"} -->
                        <div class="wp-block-columns alignwide"><!-- wp:column -->
                        <div class="wp-block-column"><!-- wp:spacer {"height":"0px"} -->
                        <div style="height:0px" aria-hidden="true" class="wp-block-spacer"></div>
                        <!-- /wp:spacer -->
                        
                        <!-- wp:image {"id":1054,"width":"220px","sizeSlug":"full","linkDestination":"none","className":"is-resized"} -->
                        <figure class="wp-block-image size-full is-resized"><img src="https://blockspare.com/demo/default/automotive/wp-content/uploads/sites/25/2023/05/download.png" alt="" class="wp-image-1054" style="width:220px"/></figure>
                        <!-- /wp:image -->
                        
                        <!-- wp:blockspare/blockspare-social-sharing {"sectionAlignments":"left","iconColorOption":"custom","customfontColorOption":"#ffffff","custombackgroundColorOption":"#076eeb","buttonShapes":"blockspare-social-icon-rounded","uniqueClass":"blockspare-ed25d135-fdda-4"} /--></div>
                        <!-- /wp:column -->
                        
                        <!-- wp:column -->
                        <div class="wp-block-column"><!-- wp:blockspare/blockspare-section-header {"uniqueClass":"blockspare-47a51c64-834f-4","headerTitle":"About \u003cmark style=\u0022background-color:rgba(0, 0, 0, 0);color:#076eeb\u0022 class=\u0022has-inline-color\u0022\u003eUs\u003c/mark\u003e","titleFontSize":20,"headertitleColor":"#ffffff","headersubtitleColor":"#8e95a3","subtitlePaddingTop":10,"titleFontFamily":"Montserrat","titleFontWeight":"600","titleLoadGoogleFonts":true,"subTitleFontSize":0,"subTitleFontFamily":"Montserrat","subTitleLoadGoogleFonts":true} -->
                        <div class="wp-block-blockspare-blockspare-section-header aligncenter blockspare-47a51c64-834f-4 blockspare-section-header-wrapper blockspare-blocks aligncenter" blockspare-animation=""><style>.blockspare-47a51c64-834f-4 .blockspare-section-head-wrap{background-color:transparent;text-align:left;margin-top:30px;margin-right:0px;margin-bottom:30px;margin-left:0px}.blockspare-47a51c64-834f-4 .blockspare-section-head-wrap .blockspare-title{color:#ffffff;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px;font-size:20px;font-family:Montserrat;font-weight:600}.blockspare-47a51c64-834f-4 .blockspare-section-head-wrap .blockspare-subtitle{color:#8e95a3;font-size:0px;font-family:Montserrat;padding-top:10px;padding-right:0px;padding-bottom:0px;padding-left:0px}@media screen and (max-width:1025px){.blockspare-47a51c64-834f-4 .blockspare-section-head-wrap .blockspare-title{font-size:22px}.blockspare-47a51c64-834f-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}@media screen and (max-width:768px){.blockspare-47a51c64-834f-4 .blockspare-section-head-wrap .blockspare-title{font-size:20px}.blockspare-47a51c64-834f-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}</style><div class="blockspare-section-head-wrap blockspare-style1 blockspare-left blockspare-hover-item"><div class="blockspare-title-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><h2 class="blockspare-title">About <mark style="background-color:rgba(0, 0, 0, 0);color:#076eeb" class="has-inline-color">Us</mark></h2><span class="blockspare-title-dash blockspare-lower-dash"></span></div><div class="blockspare-subtitle-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><span class="blockspare-title-dash blockspare-lower-dash"></span></div></div></div>
                        <!-- /wp:blockspare/blockspare-section-header -->
                        
                        <!-- wp:blockspare/blockspare-list {"className":"aligncenter","uniqueClass":"blockspare-231aeb5a-4b77-4","listType":"none","color":"#8e95a3","descriptionFontSize":15,"descriptionFontFamily":"Montserrat","descriptionLoadGoogleFonts":true,"marginTop":0} -->
                        <div class="wp-block-blockspare-blockspare-list aligncenter blockspare-231aeb5a-4b77-4 blockspare-block-iconlist-wrap" blockspare-animation=""><style>.blockspare-231aeb5a-4b77-4 .blockspare-list-wrap{border-radius:0px;margin-bottom:30px;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px}.blockspare-231aeb5a-4b77-4 .blockspare-list-wrap .listDescription li{color:#8e95a3;text-align:left;font-size:15px;font-family:Montserrat}.blockspare-231aeb5a-4b77-4 .blockspare-list-wrap .listDescription li:before{color:#404040}.blockspare-231aeb5a-4b77-4 .listDescription li:before{font-size:13px}@media screen and (max-width:1025px){.blockspare-231aeb5a-4b77-4 .blockspare-list-wrap .listDescription li{font-size:14px}.blockspare-231aeb5a-4b77-4 .listDescription li:before{font-size:13px}}@media screen and (max-width:768px){.blockspare-231aeb5a-4b77-4 .blockspare-list-wrap .listDescription li{font-size:14px}.blockspare-231aeb5a-4b77-4 .listDescription li:before{font-size:13px}}</style><div class="blockspare-blocks blockspare-list-wrap blockspare-hover-item"><ul class="listDescription"><!-- wp:list-item -->
                        <li>Make an appointment</li>
                        <!-- /wp:list-item -->
                        
                        <!-- wp:list-item -->
                        <li>Servicing working hours</li>
                        <!-- /wp:list-item -->
                        
                        <!-- wp:list-item -->
                        <li>Calculate repairing parts cost</li>
                        <!-- /wp:list-item -->
                        
                        <!-- wp:list-item -->
                        <li>Our services</li>
                        <!-- /wp:list-item --></ul></div></div>
                        <!-- /wp:blockspare/blockspare-list --></div>
                        <!-- /wp:column -->
                        
                        <!-- wp:column -->
                        <div class="wp-block-column"><!-- wp:blockspare/blockspare-section-header {"uniqueClass":"blockspare-6339fa8b-437d-4","headerTitle":"Our \u003cmark style=\u0022background-color:rgba(0, 0, 0, 0);color:#076eeb\u0022 class=\u0022has-inline-color\u0022\u003eServices\u003c/mark\u003e ","titleFontSize":20,"headertitleColor":"#ffffff","headersubtitleColor":"#8e95a3","subtitlePaddingTop":10,"titleFontFamily":"Montserrat","titleFontWeight":"600","titleLoadGoogleFonts":true,"subTitleFontSize":0,"subTitleFontFamily":"Montserrat","subTitleLoadGoogleFonts":true} -->
                        <div class="wp-block-blockspare-blockspare-section-header aligncenter blockspare-6339fa8b-437d-4 blockspare-section-header-wrapper blockspare-blocks aligncenter" blockspare-animation=""><style>.blockspare-6339fa8b-437d-4 .blockspare-section-head-wrap{background-color:transparent;text-align:left;margin-top:30px;margin-right:0px;margin-bottom:30px;margin-left:0px}.blockspare-6339fa8b-437d-4 .blockspare-section-head-wrap .blockspare-title{color:#ffffff;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px;font-size:20px;font-family:Montserrat;font-weight:600}.blockspare-6339fa8b-437d-4 .blockspare-section-head-wrap .blockspare-subtitle{color:#8e95a3;font-size:0px;font-family:Montserrat;padding-top:10px;padding-right:0px;padding-bottom:0px;padding-left:0px}@media screen and (max-width:1025px){.blockspare-6339fa8b-437d-4 .blockspare-section-head-wrap .blockspare-title{font-size:22px}.blockspare-6339fa8b-437d-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}@media screen and (max-width:768px){.blockspare-6339fa8b-437d-4 .blockspare-section-head-wrap .blockspare-title{font-size:20px}.blockspare-6339fa8b-437d-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}</style><div class="blockspare-section-head-wrap blockspare-style1 blockspare-left blockspare-hover-item"><div class="blockspare-title-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><h2 class="blockspare-title">Our <mark style="background-color:rgba(0, 0, 0, 0);color:#076eeb" class="has-inline-color">Services</mark> </h2><span class="blockspare-title-dash blockspare-lower-dash"></span></div><div class="blockspare-subtitle-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><span class="blockspare-title-dash blockspare-lower-dash"></span></div></div></div>
                        <!-- /wp:blockspare/blockspare-section-header -->
                        
                        <!-- wp:blockspare/blockspare-list {"className":"aligncenter","uniqueClass":"blockspare-0b7f3678-917e-4","listType":"none","color":"#8e95a3","descriptionFontSize":15,"descriptionFontFamily":"Montserrat","descriptionLoadGoogleFonts":true,"marginTop":0} -->
                        <div class="wp-block-blockspare-blockspare-list aligncenter blockspare-0b7f3678-917e-4 blockspare-block-iconlist-wrap" blockspare-animation=""><style>.blockspare-0b7f3678-917e-4 .blockspare-list-wrap{border-radius:0px;margin-bottom:30px;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px}.blockspare-0b7f3678-917e-4 .blockspare-list-wrap .listDescription li{color:#8e95a3;text-align:left;font-size:15px;font-family:Montserrat}.blockspare-0b7f3678-917e-4 .blockspare-list-wrap .listDescription li:before{color:#404040}.blockspare-0b7f3678-917e-4 .listDescription li:before{font-size:13px}@media screen and (max-width:1025px){.blockspare-0b7f3678-917e-4 .blockspare-list-wrap .listDescription li{font-size:14px}.blockspare-0b7f3678-917e-4 .listDescription li:before{font-size:13px}}@media screen and (max-width:768px){.blockspare-0b7f3678-917e-4 .blockspare-list-wrap .listDescription li{font-size:14px}.blockspare-0b7f3678-917e-4 .listDescription li:before{font-size:13px}}</style><div class="blockspare-blocks blockspare-list-wrap blockspare-hover-item"><ul class="listDescription"><!-- wp:list-item -->
                        <li>Parts Repairs</li>
                        <!-- /wp:list-item -->
                        
                        <!-- wp:list-item -->
                        <li>Car Washing</li>
                        <!-- /wp:list-item -->
                        
                        <!-- wp:list-item -->
                        <li>Mobil Change</li>
                        <!-- /wp:list-item -->
                        
                        <!-- wp:list-item -->
                        <li>Car Paint</li>
                        <!-- /wp:list-item -->
                        
                        <!-- wp:list-item -->
                        <li></li>
                        <!-- /wp:list-item --></ul></div></div>
                        <!-- /wp:blockspare/blockspare-list --></div>
                        <!-- /wp:column -->
                        
                        <!-- wp:column {"backgroundColor":"white"} -->
                        <div class="wp-block-column has-white-background-color has-background"><!-- wp:blockspare/blockspare-section-header {"uniqueClass":"blockspare-538338eb-6bd0-4","headerTitle":"Contact \u003cmark style=\u0022background-color:rgba(0, 0, 0, 0);color:#076eeb\u0022 class=\u0022has-inline-color\u0022\u003eUs\u003c/mark\u003e","titleFontSize":20,"headertitleColor":"#343750","headersubtitleColor":"#8e95a3","headermarginRight":20,"headermarginBottom":20,"headermarginLeft":20,"subtitlePaddingTop":10,"titleFontFamily":"Montserrat","titleFontWeight":"600","titleLoadGoogleFonts":true,"subTitleFontSize":0,"subTitleFontFamily":"Montserrat","subTitleFontWeight":"400","subTitleLoadGoogleFonts":true} -->
                        <div class="wp-block-blockspare-blockspare-section-header aligncenter blockspare-538338eb-6bd0-4 blockspare-section-header-wrapper blockspare-blocks aligncenter" blockspare-animation=""><style>.blockspare-538338eb-6bd0-4 .blockspare-section-head-wrap{background-color:transparent;text-align:left;margin-top:30px;margin-right:20px;margin-bottom:20px;margin-left:20px}.blockspare-538338eb-6bd0-4 .blockspare-section-head-wrap .blockspare-title{color:#343750;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px;font-size:20px;font-family:Montserrat;font-weight:600}.blockspare-538338eb-6bd0-4 .blockspare-section-head-wrap .blockspare-subtitle{color:#8e95a3;font-size:0px;font-family:Montserrat;font-weight:400;padding-top:10px;padding-right:0px;padding-bottom:0px;padding-left:0px}@media screen and (max-width:1025px){.blockspare-538338eb-6bd0-4 .blockspare-section-head-wrap .blockspare-title{font-size:22px}.blockspare-538338eb-6bd0-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}@media screen and (max-width:768px){.blockspare-538338eb-6bd0-4 .blockspare-section-head-wrap .blockspare-title{font-size:20px}.blockspare-538338eb-6bd0-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}</style><div class="blockspare-section-head-wrap blockspare-style1 blockspare-left blockspare-hover-item"><div class="blockspare-title-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><h2 class="blockspare-title">Contact <mark style="background-color:rgba(0, 0, 0, 0);color:#076eeb" class="has-inline-color">Us</mark></h2><span class="blockspare-title-dash blockspare-lower-dash"></span></div><div class="blockspare-subtitle-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><span class="blockspare-title-dash blockspare-lower-dash"></span></div></div></div>
                        <!-- /wp:blockspare/blockspare-section-header -->
                        
                        <!-- wp:blockspare/blockspare-list {"className":"aligncenter","uniqueClass":"blockspare-c877ba16-47a1-4","listType":"none","color":"#8e95a3","descriptionFontSize":15,"descriptionFontFamily":"Montserrat","descriptionLoadGoogleFonts":true,"marginTop":0,"marginRight":20,"marginLeft":20} -->
                        <div class="wp-block-blockspare-blockspare-list aligncenter blockspare-c877ba16-47a1-4 blockspare-block-iconlist-wrap" blockspare-animation=""><style>.blockspare-c877ba16-47a1-4 .blockspare-list-wrap{border-radius:0px;margin-right:20px;margin-bottom:30px;margin-left:20px;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px}.blockspare-c877ba16-47a1-4 .blockspare-list-wrap .listDescription li{color:#8e95a3;text-align:left;font-size:15px;font-family:Montserrat}.blockspare-c877ba16-47a1-4 .blockspare-list-wrap .listDescription li:before{color:#404040}.blockspare-c877ba16-47a1-4 .listDescription li:before{font-size:13px}@media screen and (max-width:1025px){.blockspare-c877ba16-47a1-4 .blockspare-list-wrap .listDescription li{font-size:14px}.blockspare-c877ba16-47a1-4 .listDescription li:before{font-size:13px}}@media screen and (max-width:768px){.blockspare-c877ba16-47a1-4 .blockspare-list-wrap .listDescription li{font-size:14px}.blockspare-c877ba16-47a1-4 .listDescription li:before{font-size:13px}}</style><div class="blockspare-blocks blockspare-list-wrap blockspare-hover-item"><ul class="listDescription"><!-- wp:list-item -->
                        <li>Brooklyn, New York, United States</li>
                        <!-- /wp:list-item -->
                        
                        <!-- wp:list-item -->
                        <li>+0123-456789</li>
                        <!-- /wp:list-item -->
                        
                        <!-- wp:list-item -->
                        <li>example.comple.com</li>
                        <!-- /wp:list-item -->
                        
                        <!-- wp:list-item -->
                        <li></li>
                        <!-- /wp:list-item --></ul></div></div>
                        <!-- /wp:blockspare/blockspare-list -->
                        
                        <!-- wp:blockspare/blockspare-container {"paddingTop":0,"paddingBottom":0,"marginTop":0,"marginBottom":0,"uniqueClass":"blockspare-3c7bb4f9-2a73-4","backGroundColor":"#ffffff"} -->
                        <div class="wp-block-blockspare-blockspare-container alignfull blockspare-3c7bb4f9-2a73-4" blockspare-animation=""><style>.blockspare-3c7bb4f9-2a73-4 > .blockspare-block-container-wrapper{background-color:#ffffff;padding-top:0px;padding-right:20px;padding-bottom:0px;padding-left:20px;margin-top:0px;margin-right:0px;margin-bottom:0px;margin-left:0px;border-radius:0}.blockspare-3c7bb4f9-2a73-4 .blockspare-image-wrap{background-image:none}</style><div class="blockspare-block-container-wrapper blockspare-hover-item"><div class="blockspare-container-background blockspare-image-wrap has-background-opacity-100 has-background-opacity"></div><div class="blockspare-container"><div class="blockspare-inner-blocks blockspare-inner-wrapper-blocks"><!-- wp:blockspare/blockspare-buttons {"sectionAlignment":"left","uniqueClass":"blockspare-040e21b9-c0c9-4","buttonText":"Call Us","buttonBackgroundColor":"#343750","buttonFontFamily":"Montserrat","buttonFontWeight":"500","buttonLoadGoogleFonts":true,"marginTop":0} -->
                        <div class="wp-block-blockspare-blockspare-buttons blockspare-040e21b9-c0c9-4 blockspare-block-button-wrap" blockspare-animation=""><style>.blockspare-040e21b9-c0c9-4 .blockspare-block-button{text-align:left;margin-top:0px;margin-bottom:30px;margin-left:0px;margin-right:0px}.blockspare-040e21b9-c0c9-4 .blockspare-block-button span{color:#fff;border-width:2px;font-size:16px;font-family:Montserrat;font-weight:500}.blockspare-040e21b9-c0c9-4 .blockspare-block-button .blockspare-button{background-color:#343750}.blockspare-040e21b9-c0c9-4 .blockspare-block-button .blockspare-button:visited{background-color:#343750}.blockspare-040e21b9-c0c9-4 .blockspare-block-button .blockspare-button:focus{background-color:#343750}.blockspare-040e21b9-c0c9-4 .blockspare-block-button i{font-size:16px}.blockspare-040e21b9-c0c9-4 .blockspare-block-button a{padding-top:12px;padding-bottom:12px;padding-right:22px;padding-left:22px}@media screen and (max-width:1025px){.blockspare-040e21b9-c0c9-4 .blockspare-block-button span{font-size:14px}.blockspare-040e21b9-c0c9-4 .blockspare-block-button i{font-size:14px}}@media screen and (max-width:768px){.blockspare-040e21b9-c0c9-4 .blockspare-block-button span{font-size:14px}.blockspare-040e21b9-c0c9-4 .blockspare-block-button i{font-size:14px}}</style><div class="blockspare-block-button"><a class="blockspare-button blockspare-button-shape-rounded blockspare-button-size-small"><span>Call Us</span></a></div></div>
                        <!-- /wp:blockspare/blockspare-buttons --></div></div></div></div>
                        <!-- /wp:blockspare/blockspare-container --></div>
                        <!-- /wp:column --></div>
                        <!-- /wp:columns --></div>
                        <!-- /wp:group --></div></div></div></div>
                        <!-- /wp:blockspare/blockspare-container -->',
						'imagePath'    => 'footer',

                    ),
					array(
						'type'     => 'footer',
                        'pages'    =>'autodeal',
						'item'     => ['Footer'],
						'key'      => 'bs_footer_20',
						'name'     => 'Auto Deal Footer',
						'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-footer/auto-deal-footer/",
						'content'  => BLOCKSPARE_PRO_PATH,
						'imagePath'    => 'footer',

                    ),
					array(
						'type'     => 'footer',
                        'pages'    =>'gardener',
						'item'     => ['Footer'],
						'key'      => 'bs_footer_21',
						'name'     => 'Gardener Footer',
						'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-footer/gardener-footer/",
						'content'  => BLOCKSPARE_PRO_PATH,
						'imagePath'    => 'footer',

                    ),
					array(
						'type'     => 'footer',
                        'pages'    =>'montessori',
						'item'     => ['Footer'],
						'key'      => 'bs_footer_22',
						'name'     => 'Montessori Footer',
						'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-footer/montessori-footer/",
						'content'  => BLOCKSPARE_PRO_PATH,
						'imagePath'    => 'footer',

                    ),
					array(
						'type'     => 'footer',
                        'pages'    =>'travel',
						'item'     => ['Footer'],
						'key'      => 'bs_footer_23',
						'name'     => 'Travel Footer',
						'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-footer/travel-footer/",
						'content'  => BLOCKSPARE_PRO_PATH,
						'imagePath'    => 'footer',

                    ),
					array(
						'type'     => 'footer',
                        'pages'    =>'cinema',
						'item'     => ['Footer'],
						'key'      => 'bs_footer_24',
						'name'     => 'Cinema Footer',
						'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-footer/cinema-footer/",
						'content'  => '<!-- wp:blockspare/blockspare-container {"paddingTop":60,"paddingBottom":60,"marginTop":0,"marginBottom":0,"uniqueClass":"blockspare-8c66e62c-0c1a-4","backGroundColor":"#1e1e1e"} -->
                        <div class="wp-block-blockspare-blockspare-container alignfull blockspare-8c66e62c-0c1a-4" blockspare-animation=""><style>.blockspare-8c66e62c-0c1a-4 > .blockspare-block-container-wrapper{background-color:#1e1e1e;padding-top:60px;padding-right:20px;padding-bottom:60px;padding-left:20px;margin-top:0px;margin-right:0px;margin-bottom:0px;margin-left:0px;border-radius:0}.blockspare-8c66e62c-0c1a-4 .blockspare-image-wrap{background-image:none}</style><div class="blockspare-block-container-wrapper blockspare-hover-item"><div class="blockspare-container-background blockspare-image-wrap has-background-opacity-100 has-background-opacity"></div><div class="blockspare-container"><div class="blockspare-inner-blocks blockspare-inner-wrapper-blocks"><!-- wp:group {"layout":{"type":"constrained"}} -->
                        <div class="wp-block-group"><!-- wp:columns {"align":"wide"} -->
                        <div class="wp-block-columns alignwide"><!-- wp:column {"width":"75%"} -->
                        <div class="wp-block-column" style="flex-basis:75%"><!-- wp:columns -->
                        <div class="wp-block-columns"><!-- wp:column {"width":"35%"} -->
                        <div class="wp-block-column" style="flex-basis:35%"><!-- wp:blockspare/blockspare-section-header {"uniqueClass":"blockspare-de2a73ba-18ff-4","headerTitle":"ALAN \u003cmark style=\u0022background-color:rgba(0, 0, 0, 0);color:#dd0000\u0022 class=\u0022has-inline-color\u0022\u003eCINEMAX\u003c/mark\u003e","headerSubTitle":"Lorem ipsum dolor sit amet, consectetur adipiscing elit. Sed non sem congue, dapibus mauris quis, tincidunt mi.","headertitleColor":"#fcb900","headersubtitleColor":"#cccccc","headermarginBottom":0,"subtitlePaddingTop":20,"titleFontFamily":"Open Sans","titleFontWeight":"800","titleLoadGoogleFonts":true,"subTitleFontFamily":"Lato","subTitleFontWeight":"400","subTitleLoadGoogleFonts":true} -->
                        <div class="wp-block-blockspare-blockspare-section-header aligncenter blockspare-de2a73ba-18ff-4 blockspare-section-header-wrapper blockspare-blocks aligncenter" blockspare-animation=""><style>.blockspare-de2a73ba-18ff-4 .blockspare-section-head-wrap{background-color:transparent;text-align:left;margin-top:30px;margin-right:0px;margin-bottom:0px;margin-left:0px}.blockspare-de2a73ba-18ff-4 .blockspare-section-head-wrap .blockspare-title{color:#fcb900;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px;font-size:24px;font-family:Open Sans;font-weight:800}.blockspare-de2a73ba-18ff-4 .blockspare-section-head-wrap .blockspare-subtitle{color:#cccccc;font-size:14px;font-family:Lato;font-weight:400;padding-top:20px;padding-right:0px;padding-bottom:0px;padding-left:0px}@media screen and (max-width:1025px){.blockspare-de2a73ba-18ff-4 .blockspare-section-head-wrap .blockspare-title{font-size:22px}.blockspare-de2a73ba-18ff-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}@media screen and (max-width:768px){.blockspare-de2a73ba-18ff-4 .blockspare-section-head-wrap .blockspare-title{font-size:20px}.blockspare-de2a73ba-18ff-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}</style><div class="blockspare-section-head-wrap blockspare-style1 blockspare-left blockspare-hover-item"><div class="blockspare-title-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><h2 class="blockspare-title">ALAN <mark style="background-color:rgba(0, 0, 0, 0);color:#dd0000" class="has-inline-color">CINEMAX</mark></h2><span class="blockspare-title-dash blockspare-lower-dash"></span></div><div class="blockspare-subtitle-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><p class="blockspare-subtitle">Lorem ipsum dolor sit amet, consectetur adipiscing elit. Sed non sem congue, dapibus mauris quis, tincidunt mi.</p><span class="blockspare-title-dash blockspare-lower-dash"></span></div></div></div>
                        <!-- /wp:blockspare/blockspare-section-header -->
                        
                        <!-- wp:blockspare/blockspare-social-sharing {"sectionAlignments":"left","buttonFills":"blockspare-social-icon-border","iconColorOption":"custom","customfontColorOption":"#cccccc","custombackgroundColorOption":"#cccccc","uniqueClass":"blockspare-13c4db8b-a01c-4"} /--></div>
                        <!-- /wp:column -->
                        
                        <!-- wp:column {"width":"30%"} -->
                        <div class="wp-block-column" style="flex-basis:30%"><!-- wp:blockspare/blockspare-section-header {"uniqueClass":"blockspare-b82817a2-6522-4","headerTitle":"WATCH \u003cmark style=\u0022background-color:rgba(0, 0, 0, 0);color:#dd0000\u0022 class=\u0022has-inline-color\u0022\u003eNOW\u003c/mark\u003e","headertitleColor":"#fcb900","headersubtitleColor":"#cccccc","headermarginBottom":20,"subtitlePaddingTop":20,"titleFontFamily":"Open Sans","titleFontWeight":"800","titleLoadGoogleFonts":true,"subTitleFontSize":0,"subTitleFontFamily":"Lato","subTitleLoadGoogleFonts":true} -->
                        <div class="wp-block-blockspare-blockspare-section-header aligncenter blockspare-b82817a2-6522-4 blockspare-section-header-wrapper blockspare-blocks aligncenter" blockspare-animation=""><style>.blockspare-b82817a2-6522-4 .blockspare-section-head-wrap{background-color:transparent;text-align:left;margin-top:30px;margin-right:0px;margin-bottom:20px;margin-left:0px}.blockspare-b82817a2-6522-4 .blockspare-section-head-wrap .blockspare-title{color:#fcb900;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px;font-size:24px;font-family:Open Sans;font-weight:800}.blockspare-b82817a2-6522-4 .blockspare-section-head-wrap .blockspare-subtitle{color:#cccccc;font-size:0px;font-family:Lato;padding-top:20px;padding-right:0px;padding-bottom:0px;padding-left:0px}@media screen and (max-width:1025px){.blockspare-b82817a2-6522-4 .blockspare-section-head-wrap .blockspare-title{font-size:22px}.blockspare-b82817a2-6522-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}@media screen and (max-width:768px){.blockspare-b82817a2-6522-4 .blockspare-section-head-wrap .blockspare-title{font-size:20px}.blockspare-b82817a2-6522-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}</style><div class="blockspare-section-head-wrap blockspare-style1 blockspare-left blockspare-hover-item"><div class="blockspare-title-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><h2 class="blockspare-title">WATCH <mark style="background-color:rgba(0, 0, 0, 0);color:#dd0000" class="has-inline-color">NOW</mark></h2><span class="blockspare-title-dash blockspare-lower-dash"></span></div><div class="blockspare-subtitle-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><span class="blockspare-title-dash blockspare-lower-dash"></span></div></div></div>
                        <!-- /wp:blockspare/blockspare-section-header -->
                        
                        <!-- wp:blockspare/blockspare-list {"className":"aligncenter","uniqueClass":"blockspare-142934eb-36a9-4","color":"#cccccc","descriptionIconColor":"#cccccc","descriptionFontSize":14,"descriptionFontFamily":"Lato","descriptionFontWeight":"400","descriptionLoadGoogleFonts":true,"marginTop":20} -->
                        <div class="wp-block-blockspare-blockspare-list aligncenter blockspare-142934eb-36a9-4 blockspare-block-iconlist-wrap" blockspare-animation=""><style>.blockspare-142934eb-36a9-4 .blockspare-list-wrap{border-radius:0px;margin-top:20px;margin-bottom:30px;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px}.blockspare-142934eb-36a9-4 .blockspare-list-wrap .listDescription li{color:#cccccc;text-align:left;font-size:14px;font-family:Lato;font-weight:400}.blockspare-142934eb-36a9-4 .blockspare-list-wrap .listDescription li:before{color:#cccccc}.blockspare-142934eb-36a9-4 .listDescription li:before{font-size:12px}@media screen and (max-width:1025px){.blockspare-142934eb-36a9-4 .blockspare-list-wrap .listDescription li{font-size:14px}.blockspare-142934eb-36a9-4 .listDescription li:before{font-size:12px}}@media screen and (max-width:768px){.blockspare-142934eb-36a9-4 .blockspare-list-wrap .listDescription li{font-size:14px}.blockspare-142934eb-36a9-4 .listDescription li:before{font-size:12px}}</style><div class="blockspare-blocks blockspare-list-wrap blockspare-hover-item"><ul class="blockspare-list-check listDescription"><!-- wp:list-item -->
                        <li>Best Price</li>
                        <!-- /wp:list-item -->
                        
                        <!-- wp:list-item -->
                        <li>Give Us Feedback</li>
                        <!-- /wp:list-item -->
                        
                        <!-- wp:list-item -->
                        <li>Contact Us</li>
                        <!-- /wp:list-item -->
                        
                        <!-- wp:list-item -->
                        <li>Our Blog</li>
                        <!-- /wp:list-item -->
                        
                        <!-- wp:list-item -->
                        <li>FAQ</li>
                        <!-- /wp:list-item --></ul></div></div>
                        <!-- /wp:blockspare/blockspare-list --></div>
                        <!-- /wp:column -->
                        
                        <!-- wp:column {"width":"35%"} -->
                        <div class="wp-block-column" style="flex-basis:35%"><!-- wp:blockspare/blockspare-section-header {"uniqueClass":"blockspare-dcf2d71c-4158-4","headerTitle":"NEWSLETTER \u003cmark style=\u0022background-color:rgba(0, 0, 0, 0);color:#dd0000\u0022 class=\u0022has-inline-color\u0022\u003eOUR\u003c/mark\u003e","headerSubTitle":"Subscribe our newsletter to get our latest update \u0026amp; news.","headertitleColor":"#fcb900","headersubtitleColor":"#cccccc","subtitlePaddingTop":20,"titleFontFamily":"Open Sans","titleFontWeight":"800","titleLoadGoogleFonts":true,"subTitleFontFamily":"Lato","subTitleLoadGoogleFonts":true} -->
                        <div class="wp-block-blockspare-blockspare-section-header aligncenter blockspare-dcf2d71c-4158-4 blockspare-section-header-wrapper blockspare-blocks aligncenter" blockspare-animation=""><style>.blockspare-dcf2d71c-4158-4 .blockspare-section-head-wrap{background-color:transparent;text-align:left;margin-top:30px;margin-right:0px;margin-bottom:30px;margin-left:0px}.blockspare-dcf2d71c-4158-4 .blockspare-section-head-wrap .blockspare-title{color:#fcb900;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px;font-size:24px;font-family:Open Sans;font-weight:800}.blockspare-dcf2d71c-4158-4 .blockspare-section-head-wrap .blockspare-subtitle{color:#cccccc;font-size:14px;font-family:Lato;padding-top:20px;padding-right:0px;padding-bottom:0px;padding-left:0px}@media screen and (max-width:1025px){.blockspare-dcf2d71c-4158-4 .blockspare-section-head-wrap .blockspare-title{font-size:22px}.blockspare-dcf2d71c-4158-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}@media screen and (max-width:768px){.blockspare-dcf2d71c-4158-4 .blockspare-section-head-wrap .blockspare-title{font-size:20px}.blockspare-dcf2d71c-4158-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}</style><div class="blockspare-section-head-wrap blockspare-style1 blockspare-left blockspare-hover-item"><div class="blockspare-title-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><h2 class="blockspare-title">NEWSLETTER <mark style="background-color:rgba(0, 0, 0, 0);color:#dd0000" class="has-inline-color">OUR</mark></h2><span class="blockspare-title-dash blockspare-lower-dash"></span></div><div class="blockspare-subtitle-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><p class="blockspare-subtitle">Subscribe our newsletter to get our latest update &amp; news.</p><span class="blockspare-title-dash blockspare-lower-dash"></span></div></div></div>
                        <!-- /wp:blockspare/blockspare-section-header -->
                        
                        <!-- wp:blockspare/search {"buttonLabel":"SEARCH","placeholderFontFamily":"Lato","placeholderLoadGoogleFonts":true,"searchFontSize":14,"searchFontFamily":"Open Sans","searchFontWeight":"700","searchLoadGoogleFonts":true,"inputFontFamily":"Lato","inputLoadGoogleFonts":true,"bgColor":"#000000","buttonBgColor":"#dd0000","buttonTextColor":"#ffffff","placeholderTextColor":"#ffffff","inputTextColor":"#ffffff","searchFormPaddingTop":0,"searchFormPaddingBottom":0,"searchFormPaddingRight":0,"searchFormPaddingLeft":0,"uniqueClass":"blockspare-20885ddc-09c3-4"} /--></div>
                        <!-- /wp:column --></div>
                        <!-- /wp:columns --></div>
                        <!-- /wp:column -->
                        
                        <!-- wp:column {"width":"25%"} -->
                        <div class="wp-block-column" style="flex-basis:25%"><!-- wp:blockspare/blockspare-container {"paddingTop":0,"paddingBottom":0,"marginTop":0,"marginBottom":0,"uniqueClass":"blockspare-c4bfae00-1101-4","backGroundColor":"#ffffff00"} -->
                        <div class="wp-block-blockspare-blockspare-container alignfull blockspare-c4bfae00-1101-4" blockspare-animation=""><style>.blockspare-c4bfae00-1101-4 > .blockspare-block-container-wrapper{background-color:#ffffff00;padding-top:0px;padding-right:20px;padding-bottom:0px;padding-left:20px;margin-top:0px;margin-right:0px;margin-bottom:0px;margin-left:0px;border-radius:0}.blockspare-c4bfae00-1101-4 .blockspare-image-wrap{background-image:none}</style><div class="blockspare-block-container-wrapper blockspare-hover-item"><div class="blockspare-container-background blockspare-image-wrap has-background-opacity-100 has-background-opacity"></div><div class="blockspare-container"><div class="blockspare-inner-blocks blockspare-inner-wrapper-blocks"><!-- wp:blockspare/blockspare-section-header {"uniqueClass":"blockspare-bae8a449-651f-4","headerTitle":"STAY IN \u003cmark style=\u0022background-color:rgba(0, 0, 0, 0);color:#dd0000\u0022 class=\u0022has-inline-color\u0022\u003eTOUCH\u003c/mark\u003e","headertitleColor":"#fcb900","titleFontFamily":"Open Sans","titleFontWeight":"800","titleLoadGoogleFonts":true,"subTitleFontSize":0} -->
                        <div class="wp-block-blockspare-blockspare-section-header aligncenter blockspare-bae8a449-651f-4 blockspare-section-header-wrapper blockspare-blocks aligncenter" blockspare-animation=""><style>.blockspare-bae8a449-651f-4 .blockspare-section-head-wrap{background-color:transparent;text-align:left;margin-top:30px;margin-right:0px;margin-bottom:30px;margin-left:0px}.blockspare-bae8a449-651f-4 .blockspare-section-head-wrap .blockspare-title{color:#fcb900;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px;font-size:24px;font-family:Open Sans;font-weight:800}.blockspare-bae8a449-651f-4 .blockspare-section-head-wrap .blockspare-subtitle{color:#6d6d6d;font-size:0px;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px}@media screen and (max-width:1025px){.blockspare-bae8a449-651f-4 .blockspare-section-head-wrap .blockspare-title{font-size:22px}.blockspare-bae8a449-651f-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}@media screen and (max-width:768px){.blockspare-bae8a449-651f-4 .blockspare-section-head-wrap .blockspare-title{font-size:20px}.blockspare-bae8a449-651f-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}</style><div class="blockspare-section-head-wrap blockspare-style1 blockspare-left blockspare-hover-item"><div class="blockspare-title-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><h2 class="blockspare-title">STAY IN <mark style="background-color:rgba(0, 0, 0, 0);color:#dd0000" class="has-inline-color">TOUCH</mark></h2><span class="blockspare-title-dash blockspare-lower-dash"></span></div><div class="blockspare-subtitle-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><span class="blockspare-title-dash blockspare-lower-dash"></span></div></div></div>
                        <!-- /wp:blockspare/blockspare-section-header -->
                        
                        <!-- wp:columns {"verticalAlignment":"center"} -->
                        <div class="wp-block-columns are-vertically-aligned-center"><!-- wp:column {"verticalAlignment":"center","width":"20%","layout":{"type":"default"}} -->
                        <div class="wp-block-column is-vertically-aligned-center" style="flex-basis:20%"><!-- wp:image {"id":23,"sizeSlug":"thumbnail","linkDestination":"none","style":{"color":{"duotone":["#ffffff","#ffffff"]}},"className":"is-resized"} -->
                        <figure class="wp-block-image size-thumbnail is-resized"><img src="https://blockspare.com/demo/default/cinemax/wp-content/uploads/sites/30/2023/06/phone-150x150.png" alt="" class="wp-image-23"/></figure>
                        <!-- /wp:image --></div>
                        <!-- /wp:column -->
                        
                        <!-- wp:column {"verticalAlignment":"center","width":"80%"} -->
                        <div class="wp-block-column is-vertically-aligned-center" style="flex-basis:80%"><!-- wp:blockspare/blockspare-section-header {"uniqueClass":"blockspare-37085895-57d2-4","headerTitle":"+99 (321) 987 654","titleFontSize":16,"headerSubTitle":"Call Us","headertitleColor":"#ffffff","headersubtitleColor":"#cccccc","headermarginTop":0,"headermarginBottom":0,"headerlayoutOption":"blockspare-style3","titleFontFamily":"Open Sans","titleFontWeight":"700","titleLoadGoogleFonts":true,"subTitleFontFamily":"Lato","subTitleLoadGoogleFonts":true} -->
                        <div class="wp-block-blockspare-blockspare-section-header aligncenter blockspare-37085895-57d2-4 blockspare-section-header-wrapper blockspare-blocks aligncenter" blockspare-animation=""><style>.blockspare-37085895-57d2-4 .blockspare-section-head-wrap{background-color:transparent;text-align:left;margin-top:0px;margin-right:0px;margin-bottom:0px;margin-left:0px}.blockspare-37085895-57d2-4 .blockspare-section-head-wrap .blockspare-title{color:#ffffff;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px;font-size:16px;font-family:Open Sans;font-weight:700}.blockspare-37085895-57d2-4 .blockspare-section-head-wrap .blockspare-subtitle{color:#cccccc;font-size:14px;font-family:Lato;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px}@media screen and (max-width:1025px){.blockspare-37085895-57d2-4 .blockspare-section-head-wrap .blockspare-title{font-size:22px}.blockspare-37085895-57d2-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}@media screen and (max-width:768px){.blockspare-37085895-57d2-4 .blockspare-section-head-wrap .blockspare-title{font-size:20px}.blockspare-37085895-57d2-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}</style><div class="blockspare-section-head-wrap blockspare-style3 blockspare-left blockspare-hover-item"><div class="blockspare-title-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><h2 class="blockspare-title">+99 (321) 987 654</h2><span class="blockspare-title-dash blockspare-lower-dash"></span></div><div class="blockspare-subtitle-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><p class="blockspare-subtitle">Call Us</p><span class="blockspare-title-dash blockspare-lower-dash"></span></div></div></div>
                        <!-- /wp:blockspare/blockspare-section-header --></div>
                        <!-- /wp:column --></div>
                        <!-- /wp:columns -->
                        
                        <!-- wp:columns {"verticalAlignment":"center"} -->
                        <div class="wp-block-columns are-vertically-aligned-center"><!-- wp:column {"verticalAlignment":"center","width":"20%","layout":{"type":"default"}} -->
                        <div class="wp-block-column is-vertically-aligned-center" style="flex-basis:20%"><!-- wp:image {"id":22,"sizeSlug":"thumbnail","linkDestination":"none","style":{"color":{"duotone":["#ffffff","#ffffff"]}},"className":"is-resized"} -->
                        <figure class="wp-block-image size-thumbnail is-resized"><img src="https://blockspare.com/demo/default/cinemax/wp-content/uploads/sites/30/2023/06/email-1-150x150.png" alt="" class="wp-image-22"/></figure>
                        <!-- /wp:image --></div>
                        <!-- /wp:column -->
                        
                        <!-- wp:column {"verticalAlignment":"center","width":"80%"} -->
                        <div class="wp-block-column is-vertically-aligned-center" style="flex-basis:80%"><!-- wp:blockspare/blockspare-section-header {"uniqueClass":"blockspare-e578f4cb-b3fb-4","headerTitle":"contact@example.com","titleFontSize":16,"headerSubTitle":"Mail Us","headertitleColor":"#ffffff","headersubtitleColor":"#cccccc","headermarginTop":0,"headermarginBottom":0,"headerlayoutOption":"blockspare-style3","titleFontFamily":"Open Sans","titleFontWeight":"700","titleLoadGoogleFonts":true,"subTitleFontFamily":"Lato","subTitleLoadGoogleFonts":true} -->
                        <div class="wp-block-blockspare-blockspare-section-header aligncenter blockspare-e578f4cb-b3fb-4 blockspare-section-header-wrapper blockspare-blocks aligncenter" blockspare-animation=""><style>.blockspare-e578f4cb-b3fb-4 .blockspare-section-head-wrap{background-color:transparent;text-align:left;margin-top:0px;margin-right:0px;margin-bottom:0px;margin-left:0px}.blockspare-e578f4cb-b3fb-4 .blockspare-section-head-wrap .blockspare-title{color:#ffffff;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px;font-size:16px;font-family:Open Sans;font-weight:700}.blockspare-e578f4cb-b3fb-4 .blockspare-section-head-wrap .blockspare-subtitle{color:#cccccc;font-size:14px;font-family:Lato;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px}@media screen and (max-width:1025px){.blockspare-e578f4cb-b3fb-4 .blockspare-section-head-wrap .blockspare-title{font-size:22px}.blockspare-e578f4cb-b3fb-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}@media screen and (max-width:768px){.blockspare-e578f4cb-b3fb-4 .blockspare-section-head-wrap .blockspare-title{font-size:20px}.blockspare-e578f4cb-b3fb-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}</style><div class="blockspare-section-head-wrap blockspare-style3 blockspare-left blockspare-hover-item"><div class="blockspare-title-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><h2 class="blockspare-title">contact@example.com</h2><span class="blockspare-title-dash blockspare-lower-dash"></span></div><div class="blockspare-subtitle-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><p class="blockspare-subtitle">Mail Us</p><span class="blockspare-title-dash blockspare-lower-dash"></span></div></div></div>
                        <!-- /wp:blockspare/blockspare-section-header --></div>
                        <!-- /wp:column --></div>
                        <!-- /wp:columns -->
                        
                        <!-- wp:columns {"verticalAlignment":"center"} -->
                        <div class="wp-block-columns are-vertically-aligned-center"><!-- wp:column {"verticalAlignment":"center","width":"20%","layout":{"type":"default"}} -->
                        <div class="wp-block-column is-vertically-aligned-center" style="flex-basis:20%"><!-- wp:image {"id":24,"sizeSlug":"thumbnail","linkDestination":"none","style":{"color":{"duotone":["#ffffff","#ffffff"]}},"className":"is-resized"} -->
                        <figure class="wp-block-image size-thumbnail is-resized"><img src="https://blockspare.com/demo/default/cinemax/wp-content/uploads/sites/30/2023/06/map-location-150x150.png" alt="" class="wp-image-24"/></figure>
                        <!-- /wp:image --></div>
                        <!-- /wp:column -->
                        
                        <!-- wp:column {"verticalAlignment":"center","width":"80%"} -->
                        <div class="wp-block-column is-vertically-aligned-center" style="flex-basis:80%"><!-- wp:blockspare/blockspare-section-header {"uniqueClass":"blockspare-fd8a6248-fa00-4","headerTitle":"Beverly Hills, CA 90210","titleFontSize":16,"headerSubTitle":"Our Location","headertitleColor":"#ffffff","headersubtitleColor":"#cccccc","headermarginTop":0,"headermarginBottom":0,"headerlayoutOption":"blockspare-style3","titleFontFamily":"Open Sans","titleFontWeight":"700","titleLoadGoogleFonts":true,"subTitleFontFamily":"Lato","subTitleLoadGoogleFonts":true} -->
                        <div class="wp-block-blockspare-blockspare-section-header aligncenter blockspare-fd8a6248-fa00-4 blockspare-section-header-wrapper blockspare-blocks aligncenter" blockspare-animation=""><style>.blockspare-fd8a6248-fa00-4 .blockspare-section-head-wrap{background-color:transparent;text-align:left;margin-top:0px;margin-right:0px;margin-bottom:0px;margin-left:0px}.blockspare-fd8a6248-fa00-4 .blockspare-section-head-wrap .blockspare-title{color:#ffffff;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px;font-size:16px;font-family:Open Sans;font-weight:700}.blockspare-fd8a6248-fa00-4 .blockspare-section-head-wrap .blockspare-subtitle{color:#cccccc;font-size:14px;font-family:Lato;padding-top:0px;padding-right:0px;padding-bottom:0px;padding-left:0px}@media screen and (max-width:1025px){.blockspare-fd8a6248-fa00-4 .blockspare-section-head-wrap .blockspare-title{font-size:22px}.blockspare-fd8a6248-fa00-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}@media screen and (max-width:768px){.blockspare-fd8a6248-fa00-4 .blockspare-section-head-wrap .blockspare-title{font-size:20px}.blockspare-fd8a6248-fa00-4 .blockspare-section-head-wrap .blockspare-subtitle{font-size:14px}}</style><div class="blockspare-section-head-wrap blockspare-style3 blockspare-left blockspare-hover-item"><div class="blockspare-title-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><h2 class="blockspare-title">Beverly Hills, CA 90210</h2><span class="blockspare-title-dash blockspare-lower-dash"></span></div><div class="blockspare-subtitle-wrapper"><span class="blockspare-title-dash blockspare-upper-dash"></span><p class="blockspare-subtitle">Our Location</p><span class="blockspare-title-dash blockspare-lower-dash"></span></div></div></div>
                        <!-- /wp:blockspare/blockspare-section-header --></div>
                        <!-- /wp:column --></div>
                        <!-- /wp:columns --></div></div></div></div>
                        <!-- /wp:blockspare/blockspare-container --></div>
                        <!-- /wp:column --></div>
                        <!-- /wp:columns --></div>
                        <!-- /wp:group --></div></div></div></div>
                        <!-- /wp:blockspare/blockspare-container -->',
						'imagePath'    => 'footer',

                    ),
					array(
						'type'     => 'footer',
                        'pages'    =>'dentlo',
						'item'     => ['Footer'],
						'key'      => 'bs_footer_25',
						'name'     => 'Dentlo Footer',
						'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-footer/dentlo-footer/",
						'content'  => BLOCKSPARE_PRO_PATH,
						'imagePath'    => 'footer',

                    ),
					array(
						'type'     => 'footer',
                        'pages'    =>'hair-salon',
						'item'     => ['Footer'],
						'key'      => 'bs_footer_26',
						'name'     => 'Hair Salon Footer',
						'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-footer/hair-salon-footer/",
						'content'  => BLOCKSPARE_PRO_PATH,
						'imagePath'    => 'footer',

                    ),
					array(
						'type'     => 'footer',
                        'pages'    =>'interior-design',
						'item'     => ['Footer'],
						'key'      => 'bs_footer_27',
						'name'     => 'Interior Design Footer',
						'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-footer/interior-design-footer/",
						'content'  => BLOCKSPARE_PRO_PATH,
						'imagePath'    => 'footer',

                    ),
					array(
						'type'     => 'footer',
                        'pages'    =>'yoga',
						'item'     => ['Footer'],
						'key'      => 'bs_footer_28',
						'name'     => 'Yoga Footer',
						'blockLink'=>"https://www.blockspare.com/demo/playground/blog/blockspare-footer/yoga-footer/",
						'content'  => BLOCKSPARE_PRO_PATH,
						'imagePath'    => 'footer',

                    )
				);

            return array_merge_recursive( $blocks_lists, $block_library_list );
        }
	}
}
Blockspare_Footer_Template_Block::get_instance()->run();