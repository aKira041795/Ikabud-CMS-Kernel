<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// Widgets Dynamic CSS
require_once NEWSX_INCLUDES_DIR .'/base/dynamic-css-widgets.php';

// Core Pro Dynamic CSS
if ( defined('NEWSX_CORE_PRO_VERSION') && newsx_core_pro_fs()->can_use_premium_code() ) {
	require_once NEWSX_CORE_PRO_PATH .'public/base/class-newsx-core-pro-dynamic-css.php';
}

// Main Dynamic CSS
function newsx_get_dynamic_css() {	
	// CSS Output
	$parse_css = '';
	
	/* Global > Color Options
    -------------------------------------------------- */
	$accent_color = newsx_get_option('global_color_accent');
	
	if ( defined('NEWSX_CORE_PRO_VERSION') && newsx_core_pro_fs()->can_use_premium_code() ) {
		$parse_css .= Newsx_Core_Pro_Dynamic_CSS::get_global_colors();
	} else {
		$accent_color_selector = '.newsx-social-icon:hover, .newsx-cta-button:hover a, .newsx-random-post:hover a, .newsx-tabs li.active, #newsx-back-to-top.newsx-trans-bg svg, .newsx-grid-filter:hover, .newsx-grid-filter.active, .newsx-random-post svg:hover, .newsx-grid-view-all a:hover';
		$accent_color_bg_selector = '.newsx-dark-mode-switcher .newsx-switch-to-light, .search-submit, .newsx-header-search .newsx-search-results-view-all a, .newsx-weather-header, .newsx-vplaylist-controller, .newsx-newsletter-form input[type="submit"], .newsx-s1.newsx-category-list .category-count, .newsx-post-index, .newsx-blog-pagination .current, .newsx-reading-progress-bar, .newsx-post-sources .source-tag a, #newsx-back-to-top:not(.newsx-trans-bg), .wp-block-search__button, .newsx-s0.newsx-widget-title-wrap .newsx-widget-title-text, .newsx-menu-item-label, .newsx-widget :not(.newsx-original-colors) .newsx-social-icon:hover';
		$accent_color_bd_selector = '.sub-menu, #newsx-back-to-top.newsx-trans-bg, blockquote, .newsx-widget-title-wrap, .newsx-widget-title-text, .newsx-widget-title-text:after, .newsx-widget .newsx-ring-loader div, .wp-block-quote, .newsx-menu-item-label';
		$accent_color_svg_selector = '.newsx-social-icon:hover svg, .newsx-cta-button:hover svg, .newsx-random-post:hover svg, .newsx-switch-to-dark:hover svg, #newsx-back-to-top.newsx-trans-bg svg, .newsx-site-header .newsx-header-social-icons svg:hover, .newsx-header-search .search-icon svg:hover, .newsx-offcanvas-btn svg:hover, .newsx-random-post svg:hover';
		$links_color_selector = 'a';
		$links_color_svg_selector = '.newsx-nav-menu a:hover svg, .newsx-nav-menu .current-menu-item > a svg';
		$link_hover_color_selector = 'a:hover, .newsx-nav-menu a:hover, .newsx-nav-menu .current-menu-item > a, .newsx-table-of-contents a:hover, .wp-block-tag-cloud.is-style-outline a:hover, .newsx-grid-read-more a:hover, .newsx-breadcrumbs a:hover, .newsx-post-meta-inner div a:hover, a.comment-reply-link:hover, .newsx-post-sources .post-source:not(.source-tag) a:hover, .widget_nav_menu li a:hover, .post-page-numbers.current, .newsx-category-list li a:hover';
		$link_hover_color_svg_selector = '.newsx-nav-menu a:hover svg, .newsx-nav-menu .current-menu-item > a svg';
		$headings_color_selector = 'h1, h1 a, h2, h2 a, h3, h3 a, h4, h4 a, h5, h5 a, h6, h6 a, .newsx-grid-title > :where(div, p, span) a, .newsx-ajax-search-results .search-results-content a, .newsx-table-of-contents a, .newsx-grid-read-more a, .newsx-weather-content .weather-location, .newsx-weather-content .weather-temp, .newsx-post-meta-inner .newsx-post-author a, .comment-author, .comment .comment-author a, .newsx-post-content + .newsx-static-sharing .sharing-header, .newsx-single-post-media .image-caption, .newsx-newsletter-title svg, .widget_block .wp-block-quote, .widget_block .wp-block-details:not(.has-text-color) summary, table:not(.has-text-color) thead th, .widget_block table:not(.has-text-color) thead th, table:not(.has-text-color) tfoot tr, .widget_block table:not(.has-text-color) tfoot tr, .newsx-widget .soc-brand, .newsx-widget .newsx-lt-s1 .soc-label1, .newsx-widget .soc-count';
		$heading_color_bg_selector = '.comment-form .form-submit .submit, .post-password-form input[type="submit"], .wpcf7-submit, .wp-block-file__button, .wp-block-loginout .button';
		$headings_color_svg_selector = '.newsx-post-author-box svg, .newsx-header-social-icons .newsx-social-icon svg, .newsx-widget .newsx-social-icon svg';
		$body_text_color_selector = 'body, button, input, select, textarea, .newsx-social-icon, .newsx-cta-button a, .newsx-random-post a, .newsx-blog-pagination > *, .newsx-breadcrumbs a, .newsx-post-sources a, .widget_nav_menu li a, .newsx-grid-view-all a, .newsx-category-list li a, .newsx-magazine-layout .newsx-no-posts p';
		$body_text_placeholder_color_selector = '.search-field::placeholder, .newsx-newsletter-form input::placeholder, .wp-block-search__input::placeholder';
		$body_text_color_svg_selector = '.newsx-social-icon svg, .newsx-search-icon svg, .newsx-cta-button svg, .newsx-switch-to-dark svg, .widget_nav_menu svg';
		$meta_color_selector = '.newsx-weather-content .weather-condition, .newsx-weather-content .weather-extra-info, .newsx-grid-date-time, .newsx-grid-author a, .newsx-grid-author a:hover, .newsx-grid-post-meta div:first-child:after, .wp-block-tag-cloud.is-style-outline a, .sharing-header, .newsx-post-meta-inner, .newsx-post-meta-inner a, .newsx-post-author-box .author-job, .newsx-related-posts .post-date, .comment-meta, .comment-meta a, .comment-respond .logged-in-as, .comment-respond .logged-in-as, .comment-respond .comment-notes, .comment-form .comment-form-cookies-consent label, .comment-form textarea::placeholder, .comment-form input::placeholder, .wpcf7-form-control::placeholder, .newsx-post-content + .newsx-static-sharing .sharing-header svg, .newsx-newsletter-form .agree-to-terms, .newsx-newsletter-policy, .newsx-archive-page-header .sub-categories span, .newsx-ajax-search-results .search-results-content span';
		$meta_color_svg_selector = '.sharing-header svg, .newsx-post-meta-inner svg';
		$borders_color_selector = 'pre, button, input, select, textarea, .newsx-mobile-menu li, .newsx-cta-button, .search-form, .search-field, .newsx-ajax-search-results, .newsx-grid-filters-dropdown, .newsx-prev, .newsx-next, .newsx-load-more, .newsx-category-list li a, .newsx-tabs li, .newsx-social-icon, .newsx-blog-pagination > *, article.entry-comments, .newsx-table-of-contents a, .newsx-post-navigation, .newsx-post-navigation .newsx-divider, .comments-pagination, .newsx-post-author-box, .newsx-newsletter-wrap, .newsx-related-posts-wrap, table, td, th, .widget_block table thead, .widget_block table th, .widget_block table td, .widget_block table tfoot, .wp-block-search__input, :where(.wp-block-search__button-inside .wp-block-search__inside-wrapper), .wp-block-tag-cloud.is-style-outline a, .widget_nav_menu li a, .wp-block-group, .wp-block-code, .wp-block-table thead, .wp-block-table tfoot, .wp-block-table td, .wp-block-table th';
		
		$site_background_color = newsx_get_option('global_color_site_background');
		$headings_color = '#292929';
		$body_text_color = '#67737e';
		$meta_color = '#8e9ba7';
		$borders_color = '#e8e8e8';

		// Accent
		$parse_css .= newsx_parse_css([
			$accent_color_selector => [
				'color' => $accent_color,
			],
			$accent_color_bg_selector => [
				'background-color' => $accent_color,
			],
			$accent_color_svg_selector => [
				'fill' => $accent_color,
			],
			$accent_color_bd_selector => [
				'border-color' => $accent_color,
			],
			'.newsx-tabs li.active' => [
				'border-bottom-color' => $accent_color .' !important',
			],
			'.newsx-post-sources .source-tag a:hover' => [
				'box-shadow' => '0 0 5px 3px ' . newsx_hex_to_rgba($accent_color, 0.2),
			],
			'.newsx-underline-hover:hover' => [
				'text-decoration-color' => $accent_color,
				'-webkit-text-decoration-color' => $accent_color,
			],
		]);

		// Links
		$parse_css .= newsx_parse_css([
			$links_color_selector => [
				'color' => $accent_color,
			],
			$links_color_svg_selector => [
				'fill' => $accent_color,
			],


			$link_hover_color_selector => [
				'color' => $accent_color,
			],
			$link_hover_color_svg_selector => [
				'fill' => $accent_color,
			],
			'.newsx-pointer-item:after' => [
				'background-color' => $accent_color,
			],
		]);

		// Headings
		$parse_css .= newsx_parse_css([
			$headings_color_selector => [
				'color' => $headings_color,
			],
			$heading_color_bg_selector => [
				'background-color' => $headings_color,
			],
			$headings_color_svg_selector => [
				'fill' => $headings_color,
			],
		]);

		// Body Text
		$parse_css .= newsx_parse_css([
			$body_text_color_selector => [
				'color' => $body_text_color,
			],
			$body_text_color_svg_selector => [
				'fill' => $body_text_color,
			],
			'.newsx-search-icon .newsx-ring-loader div' => [
				'border-left-color' => $body_text_color,
			],
		]);

		// Body Text - Placeholders
		$parse_css .= newsx_parse_css([
			$body_text_placeholder_color_selector => [
				'color' => newsx_hex_to_rgba($body_text_color, 0.6),
			],
		]);

		// Meta
		$parse_css .= newsx_parse_css([
			$meta_color_selector => [
				'color' => $meta_color,
			],
			$meta_color_svg_selector => [
				'fill' => $meta_color,
			],
			'.newsx-post-meta-inn-group > div:after, .newsx-grid-post-meta div:first-child:after' => [
				'background-color' => $meta_color,
			],
		]);

		// Borders
		$parse_css .= newsx_parse_css([
			$borders_color_selector => [
				'border-color' => $borders_color,
			],
			'.wp-block-separator' => [
				'color' => $borders_color,
			],
			'hr, .wp-block-separator, :where(.wp-block-calendar table:not(.has-background) th), code, kbd, samp' => [
				'background-color' => $borders_color,
			],
			'.newsx-dark-mode-switcher' => [
				'background-color' => newsx_hex_to_rgba($borders_color, 0.8),
			]
		]);

		$parse_css .= newsx_get_background_css($site_background_color, 'body');

		// Header Colors
		$parse_css .= newsx_parse_css([
			'.newsx-site-header .newsx-top-section-wrap' => [
				'background-color' => $accent_color,
				'border-bottom' => 'none',
			],
		]);
		$parse_css .= newsx_parse_css([
			'.newsx-site-header .newsx-middle-section-wrap, .newsx-site-header .newsx-bottom-section-wrap' => [
				'border-bottom' => '1px solid #e8e8e8',
			],
		]);

		// Footer Colors
		$parse_css .= newsx_parse_css([
			'.newsx-site-footer .newsx-middle-section-wrap, .newsx-site-footer .newsx-top-section-wrap' => [
				'background-color' => '#191919',
			],
		]);
		$parse_css .= newsx_parse_css([
			'.newsx-site-footer .newsx-bottom-section-wrap' => [
				'background-color' => '#111111',
			],
		]);
		$parse_css .= newsx_parse_css([
			'.newsx-site-footer .newsx-middle-section-wrap, .newsx-site-footer .newsx-bottom-section-wrap' => [
				'border-top' => '1px solid #222222',
			],
		]);

		$footer_headings_color_selector = '.newsx-site-footer h1, .newsx-site-footer h1 a, .newsx-site-footer h2, .newsx-site-footer h2 a, .newsx-site-footer h3, .newsx-site-footer h3 a, .newsx-site-footer h4, .newsx-site-footer h4 a, .newsx-site-footer h5, .newsx-site-footer h5 a, .newsx-site-footer h6, .newsx-site-footer h6 a, .newsx-site-footer .newsx-grid-title > :where(div, p, span) a, .newsx-site-footer a';
		$footer_heading_color_bg_selector = '';
		$footer_headings_color_svg_selector = '';
		$footer_text_color_selector = '.newsx-site-footer, .newsx-site-footer button, .newsx-site-footer input, .newsx-site-footer select, .newsx-site-footer textarea, .newsx-site-footer .newsx-social-icon, .newsx-site-footer .newsx-category-list li a';
		$footer_text_placeholder_color_selector = '.newsx-site-footer .search-field::placeholder, .newsx-site-footer .newsx-newsletter-form input::placeholder, .newsx-site-footer .wp-block-search__input::placeholder';
		$footer_text_color_svg_selector = '.newsx-site-footer .newsx-social-icon svg, .newsx-site-footer .newsx-search-icon svg';
		$footer_meta_color_selector = '.newsx-site-footer .newsx-post-meta';
		$footer_meta_color_svg_selector = '';
		$footer_borders_color_selector = '.newsx-site-footer pre, .newsx-site-footer button, .newsx-site-footer input, .newsx-site-footer select, .newsx-site-footer textarea, .newsx-site-footer .search-form, .newsx-site-footer .search-field, .newsx-site-footer .newsx-s1-ft.newsx-widget-title-wrap, .newsx-site-footer .newsx-category-list.newsx-s0 li a, .newsx-site-footer .newsx-tabs li';

		$footer_headings_color = '#ececec';
		$footer_text_color = '#adadad';
		$footer_meta_color = '#5b5b5b';
		$footer_borders_color = '#484848';

		// Footer Headings
		$parse_css .= newsx_parse_css([
			$footer_headings_color_selector => [
				'color' => $footer_headings_color,
			],
			// $footer_heading_color_bg_selector => [
			// 	'background-color' => $footer_headings_color,
			// ],
			// $footer_headings_color_svg_selector => [
			// 	'fill' => $footer_headings_color,
			// ],
		]);

		// Footer Text
		$parse_css .= newsx_parse_css([
			$footer_text_color_selector => [
				'color' => $footer_text_color,
			],
			$footer_text_color_svg_selector => [
				'fill' => $footer_text_color,
			],
		]);

		// Footer Text - Placeholders
		$parse_css .= newsx_parse_css([
			$footer_text_placeholder_color_selector => [
				'color' => newsx_hex_to_rgba($footer_text_color, 0.6),
			],
		]);

		// Meta
		$parse_css .= newsx_parse_css([
			$footer_meta_color_selector => [
				'color' => $footer_meta_color,
			],
			// $footer_meta_color_svg_selector => [
			// 	'fill' => $footer_meta_color,
			// ],
			'.newsx-site-footer .newsx-post-meta-inn-group > div:after, .newsx-site-footer .newsx-grid-post-meta div:first-child:after' => [
				'background-color' => $footer_meta_color,
			],
		]);

		// Footer Borders
		$parse_css .= newsx_parse_css([
			$footer_borders_color_selector => [
				'border-color' => $footer_borders_color,
			],
			'.wp-block-separator' => [
				'color' => $borders_color,
			],
			'.newsx-site-footer hr, .newsx-site-footer .wp-block-separator, .newsx-site-footer :where(.wp-block-calendar table:not(.has-background) th), .newsx-site-footer code, .newsx-site-footer kbd, .newsx-site-footer samp' => [
				'background-color' => $footer_borders_color,
			],
		]);

		// Footer Copyright and Menu
		$parse_css .= newsx_parse_css([
			'.newsx-copyright, .newsx-footer-menu a' => [
				'color' => '#888888',
			],
			'.newsx-copyright a' => [
				'color' => '#bcbcbc',
			],
			'.newsx-footer-menu a:hover, .newsx-footer-menu .current-menu-item > a' => [
				'color' => '#adaaaa',
			],
		]);
	}

    /* Global > Typography Options
    -------------------------------------------------- */
	// Body Font
	$body_font_selector = 'body, button, input, select, textarea';
	$global_font_body = newsx_get_option('global_font_body');

	if ( !defined('NEWSX_CORE_PRO_VERSION') || !newsx_core_pro_fs()->can_use_premium_code() ) {
		$global_font_body['font-size'] = [ 'desktop' => 14, 'tablet' => 14, 'mobile' => 14 ];
	}

	$parse_css .= newsx_get_typography_css($global_font_body, $body_font_selector);
	
	$global_font_body_selector_minus_1px = '.newsx-grid-author, .newsx-grid-date-time, .newsx-widget .newsx-post-meta, .newsx-grid-categories, .newsx-category-list.newsx-s2 .category-count, .wp-block-tag-cloud.is-style-outline, .newsx-breadcrumbs, .newsx-post-author-box .author-job, .comment-reply-link, .comment-form textarea, .comment-form input, .newsx-single-post-media .image-caption, .newsx-newsletter-form .mc4wp-form-fields input[type="email"], .newsx-newsletter-form input[type="submit"], .wpcf7-form-control, .wp-block-search__input, .wp-block-search__button';
	$parse_css .= newsx_get_resp_slider_control_css($global_font_body['font-size'], $global_font_body_selector_minus_1px, 'font-size', 'px', 1);
	
	$global_font_body_selector_minus_2px = '.newsx-grid-over-media .post-categories';
	$parse_css .= newsx_get_resp_slider_control_css($global_font_body['font-size'], $global_font_body_selector_minus_2px, 'font-size', 'px', 2);

	// Add Extra PX to Blockquote Widget
	$parse_css .= newsx_get_resp_slider_control_css($global_font_body['font-size'], '.widget_block .wp-block-quote p, blockquote p', 'font-size', 'px', -2);

	// Body Font SVG Size
	$body_font_svg_selector = '.newsx-post-author-box .author-socials svg, .newsx-post-sources span svg';

	$parse_css .= newsx_get_resp_slider_control_css($global_font_body['font-size'], $body_font_svg_selector, 'width', 'px');
	$parse_css .= newsx_get_resp_slider_control_css($global_font_body['font-size'], $body_font_svg_selector, 'height', 'px');

	// Headings Fonts
	if ( defined('NEWSX_CORE_PRO_VERSION') && newsx_core_pro_fs()->can_use_premium_code() ) {
		$parse_css .= Newsx_Core_Pro_Dynamic_CSS::get_global_typography();
	} else {
		$heading_font_selector = 'h1:not(.site-title), h1 .author-name, h2, h3, h4, h5, h6, .widget_block .wp-block-quote p, .widget_block table thead th, .widget_block table tfoot tr';
		$global_font_heading = newsx_get_option('global_font_heading');

		$parse_css .= newsx_get_typography_css($global_font_heading, $heading_font_selector);
	
		// Custom Heading Fonts 
		$parse_css .= newsx_get_typography_css(newsx_get_option('global_font_h1'), 'h1:not(.site-title)');
		$parse_css .= newsx_get_typography_css(newsx_get_option('global_font_h2'), 'h2');
		$parse_css .= newsx_get_typography_css(newsx_get_option('global_font_h3'), 'h3');
		$parse_css .= newsx_get_typography_css(newsx_get_option('global_font_h4'), 'h4');
		$parse_css .= newsx_get_typography_css(newsx_get_option('global_font_h5'), 'h5');
		$parse_css .= newsx_get_typography_css(newsx_get_option('global_font_h6'), 'h6');
	
		$parse_css .= newsx_parse_css([
			'.newsx-widget .newsx-social-icon .soc-brand, .newsx-widget .newsx-lt-s1 .newsx-social-icon .soc-label1, .newsx-widget .newsx-social-icon .soc-count' => [
				'font-family' => $global_font_heading['font-family'],
				'font-weight' => 'bold',
			],
		]);
		
		// Add Extra PX to Weather Widget
		$global_font_h2 = newsx_get_option('global_font_h2');
		$parse_css .= newsx_get_resp_slider_control_css($global_font_h2['font-size'], '.newsx-weather-content .weather-temp', 'font-size', 'px', -20); // -- hack to make +
	}

    /* Global > Layout Options
    -------------------------------------------------- */
	if ( defined('NEWSX_CORE_PRO_VERSION') && newsx_core_pro_fs()->can_use_premium_code() ) {
		$parse_css .= Newsx_Core_Pro_Dynamic_CSS::get_global_layout_styles();
	} else {
		$parse_css .= newsx_parse_css([
			'.newsx-container' => [
				'max-width' => '1300px'
			]
		]);

		$global_padding_selector = '.newsx-builder-grid-row, .newsx-row-inner, .newsx-archive-page-wrap, .newsx-blog-page-wrap, .newsx-single-wrap, .newsx-default-page-wrap, .newsx-mobile-menu-container, .newsx-widget .newsx-container';

		$parse_css .= newsx_get_resp_slider_control_css([ 'desktop' => 20, 'tablet' => 20, 'mobile' => 20 ], $global_padding_selector, 'padding-left', 'px');
		$parse_css .= newsx_get_resp_slider_control_css([ 'desktop' => 20, 'tablet' => 20, 'mobile' => 20 ], $global_padding_selector, 'padding-right', 'px');
	}

	// Global Checkboxes
	$global_island_style = newsx_get_option('global_island_style');
	$global_border_radius = newsx_get_option('global_border_radius');
	$global_image_hover_effects = newsx_get_option('global_image_hover_effects');

	// Global Island Style
	if ( $global_island_style ) {
		$island_style_selector = '.site-content :where(section.newsx-list-widget, section.newsx-grid-widget, section.newsx-social-icons-widget, section.newsx-featured-tabs-widget, section.newsx-featured-posts-widget, section.newsx-category-list-widget, .widget_tag_cloud, .widget_text, .widget_block .wp-block-table, .widget_search, .newsx-single-content-wrap, .newsx-default-page-wrap .primary), .primary > .newsx-posts-feed .newsx-grid-item, .primary > .newsx-posts-feed > .newsx-blog-pagination, .newsx-fp-row-extra .newsx-posts-feed .newsx-grid-item';

		$parse_css .= newsx_minify_static_css('
			body {
				background-color: #eeeef5;
			}

			'. $island_style_selector .' {
				box-shadow: 0 0 8px 1px rgba(0,0,0,0.05);
				padding: 20px;
			}

			/* Spacing */
			.home .newsx-main-content section.newsx-widget,
			.newsx-sidebar section.newsx-widget {
				margin-bottom: 25px !important;
			}

			.newsx-row-inner, .newsx-single-inner,
			.newsx-blog-page-inner,
			.newsx-archive-page-inner {
				gap: 20px !important;
			}

			.newsx-archive-page-header {
				margin-bottom: 25px;
			}
		');

		if ( $global_border_radius ) {
			$parse_css .= newsx_minify_static_css('
				'. $island_style_selector .', .site-content .newsx-single-content-wrap {
					border-radius: 5px;
				}
			');
		}

		if ( !defined('NEWSX_CORE_PRO_VERSION') || !newsx_core_pro_fs()->can_use_premium_code() ) {
			$parse_css .= newsx_minify_static_css('
				'. $island_style_selector .' {
					background-color: #ffffff;
				}
			');

			$parse_css .= newsx_minify_static_css('

				.newsx-site-header .newsx-middle-section-wrap,
				.newsx-site-header .newsx-bottom-section-wrap {
					background-color: #ffffff;
				}
			');
		}
	
		$parse_css .= newsx_minify_static_css('
			#content {
				padding-top: 30px;
				padding-bottom: 30px;
			}
		');
	
	// Island Style Off
	} else {
		$parse_css .= newsx_minify_static_css('
			.site-main .newsx-row:first-child .newsx-row-inner,
			.customize-partial-edit-shortcuts-shown .site-main .newsx-row:nth-child(2) .newsx-row-inner{
				padding-top: 30px;
			}
			.site-main .newsx-row:last-child .newsx-row-inner {
				margin-bottom: 0;
			}

			.newsx-single-wrap,
			.newsx-blog-page-wrap,
			.newsx-archive-page-wrap,
			.newsx-default-page-wrap {
				padding-top: 30px;
				padding-bottom: 30px;
			}
		');
	}

	// Global Border Radius
	if ( $global_border_radius ) {
		$parse_css .= newsx_minify_static_css('
			.newsx-grid-media,
			.newsx-category-list li,
			.newsx-slider-media,
			:where(.single-format-video, .single-format-audio) .newsx-single-post-media,
			.wp-block-image {
				overflow: hidden;
			}

			/* Media 3px */
			.newsx-grid-media,
			.newsx-grid-media img,
			.newsx-slider-media,
			.newsx-post-image img,
			.newsx-s2.newsx-category-list li,
			.newsx-s1.newsx-category-list li,
			.newsx-vplaylist-wrap,
			.newsx-vplaylist-thumbs img,
			.wp-block-image,
			.newsx-ajax-search-results img,

			.newsx-single-post-media img,
			:where(.single-format-video, .single-format-audio) .newsx-single-post-media,
			.swiper-wrapper img,
			.newsx-s1.newsx-post-navigation img,
			.newsx-related-posts img {
				border-radius: 3px;
			}

			/* UI 2px */
			.newsx-s0.newsx-widget-title-wrap .newsx-widget-title-text {
				border-top-left-radius: 2px;
				border-top-right-radius: 2px;
			}

			.newsx-nav-menu .newsx-menu-item-label {
				border-radius: 2px;
			}

			/* UI 3px */
			input,
			textarea,
			button,
			.newsx-grid-read-more a,
			.newsx-grid-next-prev > div,
			.newsx-widget .newsx-social-icon,
			.wp-block-tag-cloud.is-style-outline a,
			#newsx-back-to-top,
			.header-search-s0 .search-form,
			.header-search-s2 .search-form,
			.newsx-ajax-search-results,
			.newsx-header-search .newsx-search-results-view-all a,
			.newsx-post-categories.newsx-s0 a,
			.newsx-post-categories.newsx-s1 a:before,

			.newsx-s0.newsx-float-sharing:not(.pos-outside) .sharing-icons,
			.newsx-s0.newsx-float-sharing.pos-outside .newsx-post-sharing,
			.newsx-post-meta .newsx-s0.newsx-static-sharing,
			.newsx-post-sources .source-tag a,
			
			.newsx-single-continue-reading a {
				border-radius: 3px;
			}
			.newsx-tabs li:first-child {
				border-top-left-radius: 3px;
			}
			.newsx-tabs li:last-child {
				border-top-right-radius: 3px;
			}

			.newsx-vplaylist-controller {
				border-bottom-left-radius: 3px;
			}

			/* UI 5px */
			.newsx-newsletter-wrap,
			.newsx-weather-wrap,
			.newsx-weather-header,
			.newsx-table-of-contents {
				border-radius: 5px;
			}
		');

		if ( $global_island_style ) {
			$parse_css .= newsx_minify_static_css('
				/* Media 12px */
				.newsx-grid-media,
				.newsx-grid-media img,
				.newsx-slider-media,
				.newsx-post-image img,
				.newsx-s2.newsx-category-list li,
				.newsx-s1.newsx-category-list li,
				.newsx-vplaylist-wrap,
				.newsx-vplaylist-thumbs img,
				.wp-block-image,
				.newsx-ajax-search-results img,

				.newsx-single-post-media img,
				:where(.single-format-video, .single-format-audio) .newsx-single-post-media,
				.swiper-wrapper img,
				.newsx-s1.newsx-post-navigation img,
				.newsx-related-posts img {
					border-radius: 12px;
				}
			');
		}
	}

	// Global Image Hover Effects
	if ( $global_image_hover_effects ) {
		$parse_css .= newsx_minify_static_css('
			/* Zoom */
			.newsx-magazine-layout .newsx-grid-image {
				transition: transform .5s cubic-bezier(0, 0, .42, 1.21);
			}
			.newsx-magazine-layout .newsx-grid-media:hover .newsx-grid-image {
				transform: scale(1.05);
			}
			.newsx-magazine-layout .newsx-grid-media:hover .newsx-media-hover-link {
				opacity: 1;
			}

			/* Overlay */
			:where(.newsx-grid-layout, .newsx-list-layout) .newsx-grid-image,
			:where(.newsx-featured-posts, .newsx-featured-tabs-widget) .newsx-post-image a,
			.newsx-category-list.newsx-s2 li > a {
				position: relative;
			}

			:where(.newsx-grid-layout, .newsx-list-layout) .newsx-grid-media > a:after,
			:where(.newsx-grid-layout, .newsx-list-layout) .newsx-grid-over-media > a:after,
			:where(.newsx-featured-posts, .newsx-featured-tabs-widget) .newsx-post-image > a:after,
			.newsx-category-list.newsx-s2 li > a:after {
				content: " ";
				position: absolute;
				top: 0;
				left: 0;
				width: 100%;
				height: 100%;
				background: rgba(255,255,255,0);
				transition: background .2s;
			}

			:where(.newsx-grid-layout, .newsx-list-layout) .newsx-grid-media > a:hover:after,
			:where(.newsx-grid-layout, .newsx-list-layout) .newsx-grid-over-media > a:hover:after,
			:where(.newsx-featured-posts, .newsx-featured-tabs-widget) .newsx-post-image > a:hover:after,
			.newsx-category-list.newsx-s2 li > a:hover:after {
				background: rgba(255,255,255,0.15);
			}

			.newsx-s1.newsx-category-list li a {
				transition: background .3s;
			}
			.newsx-s1.newsx-category-list li a:hover {
				background: rgba(0, 0, 0, 0.2);
			}
		');
	}

    /* Global > Sidebar Options
    -------------------------------------------------- */
	if ( defined('NEWSX_CORE_PRO_VERSION') && newsx_core_pro_fs()->can_use_premium_code() ) {
		$parse_css .= Newsx_Core_Pro_Dynamic_CSS::get_global_sidebar_styles();
	} else {
		$parse_css .= newsx_parse_css([
			'.newsx-sidebar' => [
				'width' => '30%'
			],
		]);

		
		$sidebar_content_distance_selector = '.newsx-row-inner, .newsx-single-inner, .newsx-blog-page-inner, .newsx-archive-page-inner';
		$parse_css .= newsx_get_resp_slider_control_css(['desktop' => 60, 'tablet' => 30, 'mobile' => 30], $sidebar_content_distance_selector, 'gap', 'px');

	}

    /* Global > Widget Options
    -------------------------------------------------- */
	if ( defined('NEWSX_CORE_PRO_VERSION') && newsx_core_pro_fs()->can_use_premium_code() ) {
		$parse_css .= Newsx_Core_Pro_Dynamic_CSS::get_global_widget_styles();
	}

    /* Global > Categories Options
    -------------------------------------------------- */
    if ( newsx_get_option('global_category_enable_cc') ) {
		// Get all Categories
		$categories = get_categories( array(
			'orderby' => 'name',
			'order'   => 'ASC'
		) );

		foreach ( $categories as $category ) {
			$category_color = newsx_get_option('global_category_'. $category->term_id, '#333333');
			$parse_css .= newsx_minify_static_css('
				.newsx-post-categories:not(.newsx-s1) a.newsx-cat-'. $category->term_id .',
				.newsx-post-categories.newsx-s1 a.newsx-cat-'. $category->term_id .':before {
					background-color: '. $category_color .';
				}
				.newsx-post-categories:not(.newsx-s1) a.newsx-cat-'. $category->term_id .':hover,
				.newsx-post-categories.newsx-s1 a.newsx-cat-'. $category->term_id .':hover:before {
					background-color: '. $category_color .';
					box-shadow: 0 0 5px 3px '. newsx_hex_to_rgba($category_color, 0.2) .';
				}
			');
		}
	}
	
    /* Header Top Section Options
    -------------------------------------------------- */
    $parse_css .= newsx_get_resp_slider_control_css(newsx_get_option('section_hd_top_height'), '.newsx-site-header .newsx-top-section-wrap .newsx-builder-grid-row', 'min-height');
	$parse_css .= newsx_get_element_visibility_css( newsx_get_option('section_hd_top_visibility'), '.newsx-site-header .newsx-top-section-wrap');

	if ( defined('NEWSX_CORE_PRO_VERSION') && newsx_core_pro_fs()->can_use_premium_code() ) {
		$parse_css .= Newsx_Core_Pro_Dynamic_CSS::get_header_footer_section_styles('header', 'top');
	}

	$parse_css .= newsx_get_resp_spacing_css(newsx_get_option('hd_top_padding'), '.newsx-site-header .newsx-top-section-wrap', 'padding');

    /* Header Middle Section Options
    -------------------------------------------------- */
    $parse_css .= newsx_get_resp_slider_control_css(newsx_get_option('section_hd_middle_height'), '.newsx-site-header .newsx-middle-section-wrap .newsx-builder-grid-row', 'min-height');
	$parse_css .= newsx_get_element_visibility_css( newsx_get_option('section_hd_middle_visibility'), '.newsx-site-header .newsx-middle-section-wrap');
	
	if ( defined('NEWSX_CORE_PRO_VERSION') && newsx_core_pro_fs()->can_use_premium_code() ) {
		$parse_css .= Newsx_Core_Pro_Dynamic_CSS::get_header_footer_section_styles('header', 'middle');
	}

	$parse_css .= newsx_get_resp_spacing_css(newsx_get_option('hd_middle_padding'), '.newsx-site-header .newsx-middle-section-wrap', 'padding');
	
    /* Header Bottom Section Options
    -------------------------------------------------- */
    $parse_css .= newsx_get_resp_slider_control_css(newsx_get_option('section_hd_bottom_height'), '.newsx-site-header .newsx-bottom-section-wrap .newsx-builder-grid-row', 'min-height');
	$parse_css .= newsx_get_element_visibility_css( newsx_get_option('section_hd_bottom_visibility'), '.newsx-site-header .newsx-bottom-section-wrap');
	
	if ( defined('NEWSX_CORE_PRO_VERSION') && newsx_core_pro_fs()->can_use_premium_code() ) {
		$parse_css .= Newsx_Core_Pro_Dynamic_CSS::get_header_footer_section_styles('header', 'bottom');
	}

	$parse_css .= newsx_get_resp_spacing_css(newsx_get_option('hd_bottom_padding'), '.newsx-site-header .newsx-bottom-section-wrap', 'padding');
	
    /* Logo / Site Title Options
    -------------------------------------------------- */
    $parse_css .= newsx_get_resp_slider_control_css(newsx_get_option('logo_width'), '.newsx-site-identity .site-logo', 'max-width');

	$parse_css .= newsx_parse_css([
		'.newsx-site-identity' => [
			'flex-direction' => newsx_get_option('inline_logo_title'),
		],
	]);

	$parse_css .= newsx_get_element_visibility_css(newsx_get_option('site_title_visibility'), '.newsx-site-title-tagline .site-title');
	$parse_css .= newsx_get_element_visibility_css(newsx_get_option('site_tagline_visibility'), '.newsx-site-title-tagline .site-description');
	$parse_css .= newsx_get_element_visibility_css(newsx_get_option('logo_title_visibility'), '.newsx-site-identity', 'flex');

	if ( defined('NEWSX_CORE_PRO_VERSION') && newsx_core_pro_fs()->can_use_premium_code() ) {
		$parse_css .= Newsx_Core_Pro_Dynamic_CSS::get_header_site_identity_styles();
	} else {
		$parse_css .= newsx_parse_css([
			'.newsx-site-title-tagline .site-title a' => [
				'color' => $headings_color,
			],
			'.newsx-site-title-tagline .site-title a:hover' => [
				'color' => $headings_color,
			],
			'.newsx-site-title-tagline .site-description' => [
				'color' => $body_text_color,
			],
		]);
	}

	$parse_css .= newsx_get_typography_css(newsx_get_option('logo_title_font'), '.newsx-site-title-tagline, .newsx-site-title-tagline .site-title');
	$parse_css .= newsx_get_resp_slider_control_css(newsx_get_option('logo_title_font_size'), '.newsx-site-title-tagline .site-title', 'font-size', 'px');
	$parse_css .= newsx_get_resp_slider_control_css(newsx_get_option('logo_tagline_font_size'), '.newsx-site-title-tagline .site-description', 'font-size', 'px');
	$parse_css .= newsx_get_resp_spacing_css(newsx_get_option('logo_margin'), '.newsx-site-identity', 'margin');
	
    /* Primary Menu Options
    -------------------------------------------------- */
	$parse_css .= newsx_get_typography_css(newsx_get_option('header_pm_font'), '.newsx-header-menu-primary a, .newsx-header-menu-primary + .newsx-mobile-menu-toggle span:last-of-type');

	if ( defined('NEWSX_CORE_PRO_VERSION') && newsx_core_pro_fs()->can_use_premium_code() ) {
		$parse_css .= Newsx_Core_Pro_Dynamic_CSS::get_header_primary_menu_styles();
	} else {
		$parse_css .= newsx_parse_css([
			'.newsx-header-menu-primary a' => [
				'color' => $headings_color,
			],
			'.newsx-header-menu-primary .newsx-nav-menu > .menu-item > a' => [
				'font-size' => '19px',
			],
			'.newsx-header-menu-primary .sub-menu a' => [
				'font-size' => '13px',
			],
			'.newsx-header-menu-primary .newsx-desktop-menu.newsx-nav-menu > .menu-item > .sub-menu:before' => [
				'height' => '15px',
			],
			'.newsx-header-menu-primary .newsx-desktop-menu.newsx-nav-menu > .menu-item > .sub-menu' => [
				'margin-top' => '13px',
			],
			'.newsx-header-menu-primary .newsx-desktop-menu .sub-menu' => [
				'border-top' => '2px solid '. $accent_color,
			],
			'.newsx-desktop-menu .sub-menu .menu-item:not(:last-child)' => [
				'border-bottom-width' => '1px',
				'border-bottom-style' => 'solid',
				'border-bottom-color' => '#f6f6f6',
			],
			'.newsx-dark-mode .newsx-desktop-menu .sub-menu .menu-item:not(:last-child)' => [
				'border-bottom-color' => '#383838',
			],
			'.newsx-mobile-menu-toggle svg' => [
				'width' => '14px',
				'height' => '14px',
			],
			'.newsx-dark-mode .newsx-mobile-menu-toggle + .newsx-mobile-menu-container' => [
				'background-color' => '#272727',
			],
		]);

		// Mobile menu
		$parse_css .= newsx_parse_css(
			[
				'.newsx-mobile-menu-toggle' => [
					'color' => $headings_color,
				],
				'.newsx-mobile-menu-toggle span:nth-child(3)' => [
					'font-size' => '15px',
				],
				'.newsx-header-menu-primary .newsx-nav-menu > .menu-item > a' => [
					'font-size' => '15px',
				],
				'.newsx-mobile-menu li:not(:last-child)' => [
					'border-bottom-width' => '1px',
					'border-bottom-style' => 'solid',
				],
			],
			'',
			newsx_get_tablet_breakpoint()
		);

		// Top Section
		$parse_css .= newsx_parse_css([
			'.newsx-top-section-wrap .newsx-desktop-menu-container .newsx-nav-menu > .menu-item > a, .newsx-top-section-wrap .newsx-mobile-menu-toggle' => [
				'color' => '#ffffff',
			],
			'.newsx-top-section-wrap .newsx-desktop-menu-container .newsx-nav-menu > .menu-item > a svg' => [
				'fill' => '#ffffff',
			],
		]);
	}

	// Mobile menu
	$parse_css .= newsx_parse_css(
		[
			'.newsx-mobile-menu-toggle' => [
				'display' => 'flex',
			],
			'.newsx-desktop-menu-container' => [
				'display' => 'none',
			],
		],
		'',
		newsx_get_tablet_breakpoint()
	);

	$parse_css .= newsx_get_resp_spacing_css(newsx_get_option('header_pm_item_padding'), '.newsx-header-menu-primary .newsx-nav-menu > .menu-item > a', 'padding');
	$parse_css .= newsx_get_resp_spacing_css(newsx_get_option('header_pm_item_margin'), '.newsx-header-menu-primary .newsx-nav-menu > .menu-item', 'margin');
	$parse_css .= newsx_get_resp_spacing_css(newsx_get_option('header_pm_margin'), '.newsx-header-menu-primary', 'margin');


    /* Secondary Menu Options
    -------------------------------------------------- */
	$parse_css .= newsx_get_element_visibility_css( newsx_get_option('header_sm_visibility'), '.newsx-header-menu-secondary-wrapper');

	$parse_css .= newsx_get_typography_css(newsx_get_option('header_sm_font'), '.newsx-header-menu-secondary a, .newsx-header-menu-secondary + .newsx-mobile-menu-toggle span:last-child');

	if ( defined('NEWSX_CORE_PRO_VERSION') && newsx_core_pro_fs()->can_use_premium_code() ) {
		$parse_css .= Newsx_Core_Pro_Dynamic_CSS::get_header_secondary_menu_styles();
	} else {
		$parse_css .= newsx_parse_css([
			'.newsx-header-menu-secondary a' => [
				'color' => $body_text_color,
			],
			'.newsx-header-menu-secondary .sub-menu-icon .newsx-svg' => [
				'fill' => $body_text_color,
			],
			'.newsx-header-menu-secondary .newsx-nav-menu > .menu-item > a' => [
				'font-size' => '13px',
			],
			'.newsx-header-menu-secondary .sub-menu a' => [
				'font-size' => '12px',
			],
			'.newsx-header-menu-secondary .newsx-desktop-menu .sub-menu.newsx-submenu-divider .menu-item:not(:last-child)' => [
				'border-bottom' => '1px solid #eeeeee',
			],
			'.newsx-header-menu-secondary .newsx-desktop-menu.newsx-nav-menu > .menu-item > .sub-menu' => [
				'margin-top' => '11px',
			],
			'.newsx-header-menu-secondary .newsx-desktop-menu.newsx-nav-menu > .menu-item > .sub-menu:before' => [
				'height' => '11px',
			]
		]);

		// Top Section
		$parse_css .= newsx_parse_css([
			'.newsx-top-section-wrap .newsx-header-menu-secondary .newsx-nav-menu > .menu-item > a' => [
				'color' => '#ffffff',
			],
			'.newsx-top-section-wrap .newsx-header-menu-secondary .newsx-nav-menu > .menu-item > a svg' => [
				'fill' => '#ffffff',
			],
		]);
	}

	$parse_css .= newsx_get_resp_spacing_css(newsx_get_option('header_sm_item_padding'), '.newsx-header-menu-secondary .newsx-nav-menu > .menu-item > a', 'padding');
	$parse_css .= newsx_get_resp_spacing_css(newsx_get_option('header_sm_item_margin'), '.newsx-header-menu-secondary .newsx-nav-menu > .menu-item', 'margin');
	$parse_css .= newsx_get_resp_spacing_css(newsx_get_option('header_sm_margin'), '.newsx-header-menu-secondary', 'margin');
	
    /* Date & Time Options
    -------------------------------------------------- */
	$parse_css .= newsx_get_element_visibility_css(newsx_get_option('date_visibility'), '.newsx-date-and-time');

	if ( defined('NEWSX_CORE_PRO_VERSION') && newsx_core_pro_fs()->can_use_premium_code() ) {
		$parse_css .= Newsx_Core_Pro_Dynamic_CSS::get_header_date_and_time_styles();
	} else {
		$parse_css .= newsx_parse_css([
			'.newsx-date-and-time' => [
				'font-size' => '13px',
			],
		]);

		// Top Section
		$parse_css .= newsx_parse_css([
			'.newsx-top-section-wrap .newsx-date-and-time' => [
				'color' => '#ffffff',
			],
		]);
	}

	$parse_css .= newsx_get_resp_spacing_css(newsx_get_option('header_date_margin'), '.newsx-date-and-time', 'margin');
	
	/* News Ticker Options
    -------------------------------------------------- */
	if ( defined('NEWSX_CORE_PRO_VERSION') && newsx_core_pro_fs()->can_use_premium_code() ) {
		$parse_css .= Newsx_Core_Pro_Dynamic_CSS::get_header_news_ticker_styles();
	} else {
		$parse_css .= newsx_parse_css([
			'.newsx-header-news-ticker .newsx-news-ticker-title' => [
				'font-size' => '12px',
			],
			'.newsx-header-news-ticker .newsx-news-ticker-title p' => [
				'padding-top' => '1px',
			],

			'.newsx-section-wrap:not(.newsx-top-section-wrap) .newsx-news-ticker-title' => [
				'color' => '#67737e',
			],
			'.news-ticker-heading-icon svg' => [
				'width' => '14px',
				'height' => '14px',
			],
		]);

		// Top Section
		$parse_css .= newsx_parse_css([
			'.newsx-top-section-wrap .news-ticker-heading, .newsx-top-section-wrap .newsx-news-ticker-title' => [
				'color' => '#ffffff',
			],
		]);
	}

	$parse_css .= newsx_get_resp_spacing_css(newsx_get_option('header_nt_margin'), '.newsx-header-news-ticker', 'margin', 'px');
	
    /* Social Icons Options
    -------------------------------------------------- */
	$parse_css .= newsx_get_element_visibility_css(newsx_get_option('header_si_visibility'), '.newsx-header-social-icons', 'flex');

	if ( defined('NEWSX_CORE_PRO_VERSION') && newsx_core_pro_fs()->can_use_premium_code() ) {
		$parse_css .= Newsx_Core_Pro_Dynamic_CSS::get_header_social_icons_styles();
	} else {
		$parse_css .= newsx_parse_css([
			'.newsx-site-header .newsx-header-social-icons svg' => [
				'width' => '16px',
				'height' => '16px',
			],
		]);

		// Top Section
		$parse_css .= newsx_parse_css([
			'.newsx-site-header .newsx-top-section-wrap .newsx-header-social-icons svg' => [
				'width' => '14px',
				'height' => '14px',
			],
		]);

		$parse_css .= newsx_parse_css([
			'.newsx-top-section-wrap .newsx-header-social-icons svg' => [
				'fill' => '#ffffff',
			],
		]);

		$parse_css .= newsx_parse_css([
			'.newsx-top-section-wrap .newsx-header-social-icons svg:hover' => [
				'fill' => '#ffffff',
			],
		]);
	}

	$parse_css .= newsx_get_resp_spacing_css(newsx_get_option('header_si_margin'), '.newsx-header-social-icons', 'margin');

    /* Off-Canvas Options
    -------------------------------------------------- */
	if ( defined('NEWSX_CORE_PRO_VERSION') && newsx_core_pro_fs()->can_use_premium_code() ) {
		$parse_css .= Newsx_Core_Pro_Dynamic_CSS::get_header_offcanvas_styles();
	} else {
		$parse_css .= newsx_parse_css([
			'.newsx-offcanvas-widgets-area' => [
				'width' => '306px'
			],
			'.newsx-offcanvas-btn svg' => [
				'fill' => '#111111',
				'width' => '20px',
				'height' => '20px',
			],
		]);

		// Top Section
		$parse_css .= newsx_parse_css([
			'.newsx-top-section-wrap .newsx-offcanvas-btn svg' => [
				'fill' => '#ffffff',
			],
		]);
	}

	$parse_css .= newsx_get_resp_spacing_css(newsx_get_option('header_ofc_icon_margin'), '.newsx-offcanvas-btn', 'margin');
	$parse_css .= newsx_get_resp_spacing_css(newsx_get_option('header_ofc_padding'), '.newsx-offcanvas-widgets-area', 'padding');
	
    /* Search Options
    -------------------------------------------------- */
	if ( defined('NEWSX_CORE_PRO_VERSION') && newsx_core_pro_fs()->can_use_premium_code() ) {
		$parse_css .= Newsx_Core_Pro_Dynamic_CSS::get_header_search_styles();
	} else {
		$parse_css .= newsx_parse_css([
			'.newsx-header-search.active .search-form' => [
				'width' => '200px',
			],
			'.header-search-s1:not(.active) .search-field' => [
				'border' => 'none',
			],
			'.newsx-header-search .search-icon svg' => [
				'fill' => $headings_color,
			],
		]);

		// Top Section
		$parse_css .= newsx_parse_css([
			'.newsx-top-section-wrap .newsx-header-search .search-icon svg' => [
				'fill' => '#ffffff',
			],
		]);
	}

	$parse_css .= newsx_get_resp_spacing_css(newsx_get_option('header_search_margin'), '.newsx-header-search', 'margin');
	
    /* CTA Button Options
    -------------------------------------------------- */
	if ( defined('NEWSX_CORE_PRO_VERSION') && newsx_core_pro_fs()->can_use_premium_code() ) {
		$parse_css .= Newsx_Core_Pro_Dynamic_CSS::get_header_cta_button_styles();
	}

	/* Weather Options
    -------------------------------------------------- */
	if ( defined('NEWSX_CORE_PRO_VERSION') && newsx_core_pro_fs()->can_use_premium_code() ) {
		$parse_css .= Newsx_Core_Pro_Dynamic_CSS::get_header_weather_styles();
	}

    /* Random Post Options
    -------------------------------------------------- */
	if ( defined('NEWSX_CORE_PRO_VERSION') && newsx_core_pro_fs()->can_use_premium_code() ) {
		$parse_css .= Newsx_Core_Pro_Dynamic_CSS::get_header_random_post_styles();
	} else {
		$parse_css .= newsx_parse_css([
			'.newsx-random-post svg' => [
				'color' => $headings_color,
				'width' => '16px',
				'height' => '16px',
			],
			'.newsx-dark-mode .newsx-random-post svg' => [
				'color' => '#ffffff',
			],
		]);

		// Top Section
		$parse_css .= newsx_parse_css([
			'.newsx-top-section-wrap .newsx-random-post svg' => [
				'color' => '#ffffff',
			],
		]);
	}

	$parse_css .= newsx_get_resp_spacing_css(newsx_get_option('random_post_margin'), '.newsx-random-post', 'margin');
	
    /* Dark Mode Switcher Options
    -------------------------------------------------- */
	$parse_css .= newsx_get_element_visibility_css(newsx_get_option('dark_switcher_visibility'), '.newsx-dark-mode-switcher', 'flex');

	if ( defined('NEWSX_CORE_PRO_VERSION') && newsx_core_pro_fs()->can_use_premium_code() ) {
		$parse_css .= Newsx_Core_Pro_Dynamic_CSS::get_header_dark_switcher_styles();
	} else {
		$parse_css .= newsx_minify_static_css('
			.newsx-top-section-wrap .newsx-dark-mode-switcher {
				width: 30px;
				height: 10px;
			}

			.newsx-top-section-wrap .newsx-dark-mode-switcher svg {
				width: 10px;
				height: 10px;
			}

			.newsx-top-section-wrap .newsx-dark-mode-switcher .newsx-switch-to-dark,
			.newsx-top-section-wrap .newsx-dark-mode-switcher .newsx-switch-to-light {
				top: -4px;
				padding: 4px;
			}
		');
	}

	// $parse_css .= newsx_get_resp_svg_size_css($dark_switcher_icon_size, '.newsx-dark-mode-switcher svg');
	$parse_css .= newsx_get_resp_spacing_css(newsx_get_option('dark_switcher_margin'), '.newsx-dark-mode-switcher', 'margin');

	/* Header Custom HTML 1 Options
    -------------------------------------------------- */
	if ( defined('NEWSX_CORE_PRO_VERSION') && newsx_core_pro_fs()->can_use_premium_code() ) {
		$parse_css .= Newsx_Core_Pro_Dynamic_CSS::get_header_custom_html_1_styles();
	}

	/* Header Custom HTML 2 Options
    -------------------------------------------------- */
	if ( defined('NEWSX_CORE_PRO_VERSION') && newsx_core_pro_fs()->can_use_premium_code() ) {
		$parse_css .= Newsx_Core_Pro_Dynamic_CSS::get_header_custom_html_2_styles();
	}

	/* Header Widgets 1 Options
    -------------------------------------------------- */
	if ( defined('NEWSX_CORE_PRO_VERSION') && newsx_core_pro_fs()->can_use_premium_code() ) {
		$parse_css .= Newsx_Core_Pro_Dynamic_CSS::get_header_widgets_1_styles();
	}

	/* Header Widgets 2 Options
    -------------------------------------------------- */
	if ( defined('NEWSX_CORE_PRO_VERSION') && newsx_core_pro_fs()->can_use_premium_code() ) {
		$parse_css .= Newsx_Core_Pro_Dynamic_CSS::get_header_widgets_2_styles();
	}

    /* Footer Top Section Options
    -------------------------------------------------- */
	$parse_css .= newsx_parse_css([
		'.newsx-site-footer .newsx-top-section-wrap .newsx-builder-grid-row' => [
			'align-items' => newsx_get_option('section_ft_top_vertical_align'),
		],
	]);
	
	$parse_css .= newsx_get_resp_slider_control_css(newsx_get_option('section_ft_top_columns_gap'), '.newsx-site-footer .newsx-top-section-wrap .newsx-builder-grid-row', 'gap');
    $parse_css .= newsx_get_resp_slider_control_css(newsx_get_option('section_ft_top_height'), '.newsx-site-footer .newsx-top-section-wrap .newsx-builder-grid-row', 'min-height');
	$parse_css .= newsx_get_element_visibility_css( newsx_get_option('section_ft_top_visibility'), '.newsx-site-footer .newsx-top-section-wrap');

	if ( defined('NEWSX_CORE_PRO_VERSION') && newsx_core_pro_fs()->can_use_premium_code() ) {
		$parse_css .= Newsx_Core_Pro_Dynamic_CSS::get_header_footer_section_styles('footer', 'top');
	}

	$parse_css .= newsx_get_resp_spacing_css(newsx_get_option('section_ft_top_padding'), '.newsx-site-footer .newsx-top-section-wrap', 'padding');

    /* Footer Middle Section Options
    -------------------------------------------------- */
	$parse_css .= newsx_parse_css([
		'.newsx-site-footer .newsx-middle-section-wrap .newsx-builder-grid-row' => [
			'align-items' => newsx_get_option('section_ft_middle_vertical_align'),
		],
	]);
	
	$parse_css .= newsx_get_resp_slider_control_css(newsx_get_option('section_ft_middle_columns_gap'), '.newsx-site-footer .newsx-middle-section-wrap .newsx-builder-grid-row', 'gap');
    $parse_css .= newsx_get_resp_slider_control_css(newsx_get_option('section_ft_middle_height'), '.newsx-site-footer .newsx-middle-section-wrap .newsx-builder-grid-row', 'min-height');
	$parse_css .= newsx_get_element_visibility_css( newsx_get_option('section_ft_middle_visibility'), '.newsx-site-footer .newsx-middle-section-wrap');

	if ( defined('NEWSX_CORE_PRO_VERSION') && newsx_core_pro_fs()->can_use_premium_code() ) {
		$parse_css .= Newsx_Core_Pro_Dynamic_CSS::get_header_footer_section_styles('footer', 'middle');
	}

	$parse_css .= newsx_get_resp_spacing_css(newsx_get_option('section_ft_middle_padding'), '.newsx-site-footer .newsx-middle-section-wrap', 'padding');
	
    /* Footer Bottom Section Options
    -------------------------------------------------- */
	$parse_css .= newsx_parse_css([
		'.newsx-site-footer .newsx-bottom-section-wrap .newsx-builder-grid-row' => [
			'align-items' => newsx_get_option('section_ft_bottom_vertical_align'),
		],
	]);
	
	$parse_css .= newsx_get_resp_slider_control_css(newsx_get_option('section_ft_bottom_columns_gap'), '.newsx-site-footer .newsx-bottom-section-wrap .newsx-builder-grid-row', 'gap');
    $parse_css .= newsx_get_resp_slider_control_css(newsx_get_option('section_ft_bottom_height'), '.newsx-site-footer .newsx-bottom-section-wrap .newsx-builder-grid-row', 'min-height');
	$parse_css .= newsx_get_element_visibility_css( newsx_get_option('section_ft_bottom_visibility'), '.newsx-site-footer .newsx-bottom-section-wrap');

	if ( defined('NEWSX_CORE_PRO_VERSION') && newsx_core_pro_fs()->can_use_premium_code() ) {
		$parse_css .= Newsx_Core_Pro_Dynamic_CSS::get_header_footer_section_styles('footer', 'bottom');
	}

	$parse_css .= newsx_get_resp_spacing_css(newsx_get_option('section_ft_bottom_padding'), '.newsx-site-footer .newsx-bottom-section-wrap', 'padding');

    /* Footer Logo Options
    -------------------------------------------------- */
    $parse_css .= newsx_get_resp_slider_control_css(newsx_get_option('footer_logo_width'), '.newsx-footer-logo img', 'max-width');
	$parse_css .= newsx_get_responsive_align_control_css(newsx_get_option('footer_logo_align'), '.newsx-footer-logo', 'justify-content');
	$parse_css .= newsx_get_resp_spacing_css(newsx_get_option('footer_logo_margin'), '.newsx-footer-logo', 'margin');

    /* Copyright Options
    -------------------------------------------------- */
	if ( defined('NEWSX_CORE_PRO_VERSION') && newsx_core_pro_fs()->can_use_premium_code() ) {
		$parse_css .= Newsx_Core_Pro_Dynamic_CSS::get_footer_copyright_styles();
	} else {
		$parse_css .= newsx_parse_css([
			'.newsx-copyright' => [
				'font-size' => '12px',
				'text-align' => 'center'
			],
		]);
	}

	$parse_css .= newsx_get_resp_spacing_css(newsx_get_option('copyright_margin'), '.newsx-copyright', 'margin');
		
    /* Footer Menu Options 
    -------------------------------------------------- */
	$footer_menu_layout = newsx_get_option('footer_menu_layout');

	if ( 'row' === $footer_menu_layout ) {
		$parse_css .= newsx_parse_css([
			'#newsx-footer-menu' => [
				'flex-direction' => 'row',
			],
		]);
		$parse_css .= newsx_get_responsive_align_control_css(newsx_get_option('footer_menu_align'), '#newsx-footer-menu', 'justify-content');
	} else {
		$parse_css .= newsx_parse_css([
			'#newsx-footer-menu' => [
				'flex-direction' => 'column',
			],
		]);
		$parse_css .= newsx_get_responsive_align_control_css(newsx_get_option('footer_menu_align'), '#newsx-footer-menu', 'align-items');
	}
	
	$parse_css .= newsx_get_element_visibility_css( newsx_get_option('footer_menu_visibility'), '#newsx-footer-menu', 'flex');

	if ( defined('NEWSX_CORE_PRO_VERSION') && newsx_core_pro_fs()->can_use_premium_code() ) {
		$parse_css .= Newsx_Core_Pro_Dynamic_CSS::get_footer_menu_styles();
	} else {
		$parse_css .= newsx_parse_css([
			'.newsx-footer-menu a' => [
				'font-size' => '12px',
			],
		]);
	}

	$parse_css .= newsx_get_resp_spacing_css(newsx_get_option('footer_menu_item_padding'), '.newsx-footer-menu .newsx-nav-menu > .menu-item > a', 'padding');
	$parse_css .= newsx_get_resp_spacing_css(newsx_get_option('footer_menu_margin'), '.newsx-footer-menu', 'margin');
	
    /* Social Icons Options
    -------------------------------------------------- */
	$parse_css .= newsx_get_responsive_align_control_css(newsx_get_option('footer_si_align'), '.newsx-footer-social-icons', 'justify-content');
	$parse_css .= newsx_get_element_visibility_css(newsx_get_option('footer_si_visibility'), '.newsx-footer-social-icons', 'flex');

	if ( defined('NEWSX_CORE_PRO_VERSION') && newsx_core_pro_fs()->can_use_premium_code() ) {
		$parse_css .= Newsx_Core_Pro_Dynamic_CSS::get_footer_social_icons_styles();
	} else {
		$parse_css .= newsx_parse_css([
			'.newsx-site-footer .newsx-bottom-section-wrap .newsx-footer-social-icons svg' => [
				'width' => '14px',
				'height' => '14px',
			],
		]);
	}

	$parse_css .= newsx_get_resp_spacing_css(newsx_get_option('footer_si_margin'), '.newsx-footer-social-icons', 'margin');

	/* Footer Custom HTML 1 Options
    -------------------------------------------------- */
	$parse_css .= newsx_get_element_visibility_css( newsx_get_option('footer_html1_visibility'), '.newsx-footer-custom-html-1');

	if ( defined('NEWSX_CORE_PRO_VERSION') && newsx_core_pro_fs()->can_use_premium_code() ) {
		$parse_css .= Newsx_Core_Pro_Dynamic_CSS::get_footer_custom_html_1_styles();
	}

	$parse_css .= newsx_get_resp_spacing_css(newsx_get_option('footer_html1_margin'), '.newsx-footer-custom-html-1', 'margin');

	/* Footer Custom HTML 2 Options
    -------------------------------------------------- */
	$parse_css .= newsx_get_element_visibility_css( newsx_get_option('footer_html2_visibility'), '.newsx-footer-custom-html-2');

	if ( defined('NEWSX_CORE_PRO_VERSION') && newsx_core_pro_fs()->can_use_premium_code() ) {
		$parse_css .= Newsx_Core_Pro_Dynamic_CSS::get_footer_custom_html_2_styles();
	}

	$parse_css .= newsx_get_resp_spacing_css(newsx_get_option('footer_html2_margin'), '.newsx-footer-custom-html-2', 'margin');

	/* Footer Back to Top
    -------------------------------------------------- */
	if ( defined('NEWSX_CORE_PRO_VERSION') && newsx_core_pro_fs()->can_use_premium_code() ) {
		$parse_css .= Newsx_Core_Pro_Dynamic_CSS::get_footer_back_to_top_styles();
	}

	$parse_css .= newsx_get_element_visibility_css( newsx_get_option('ft_backtop_visibility'), '#newsx-back-to-top', 'flex');

	/* Blog Page Options
    -------------------------------------------------- */
	$parse_css .= newsx_get_resp_slider_control_css(newsx_get_option('bp_feed_gutter'), '.newsx-posts-feed', 'gap');

	// Add Widgets Dynamic CSS
	$parse_css .= newsx_dynamic_sidebar_dynamic_css();

	/* Blog Single Options
    -------------------------------------------------- */
	if ( defined('NEWSX_CORE_PRO_VERSION') && function_exists('newsx_core_pro_fs') && newsx_core_pro_fs()->can_use_premium_code() ) {
		if ( method_exists( 'Newsx_Core_Pro_Dynamic_CSS', 'get_blog_single_featured_caption_styles' ) ) {
			$parse_css .= Newsx_Core_Pro_Dynamic_CSS::get_blog_single_featured_caption_styles();
		}
	}

	// Post Meta
	if ( defined('NEWSX_CORE_PRO_VERSION') && newsx_core_pro_fs()->can_use_premium_code() ) {
		$parse_css .= Newsx_Core_Pro_Dynamic_CSS::get_blog_single_header_post_meta_styles();
	} else {
		$parse_css .= newsx_parse_css([
			'.newsx-post-meta-inner' => [
				'font-size' => '14px',
			],
			'.newsx-post-meta-inner svg' => [
				'width' => '14px',
				'height' => '14px',
			],
		]);
	}
	
	if ( newsx_get_option('bs_content_underline_links') ) {
		$bs_content_underline_links_selector = '.newsx-post-content a:where(:not(.wp-element-button)), .comment-text a:where(:not(.wp-element-button)), .widget_block a, .widget_rss li a';
		// .widget_text a, .wp-block-pullquote a, .wp-block-quote a, .wp-block-preformatted a, .wp-block-code a,

		$parse_css .= newsx_minify_static_css('
			'.$bs_content_underline_links_selector.' {
				-webkit-text-decoration-color: transparent;
				text-decoration-color: transparent;
				-webkit-text-decoration-line: underline;
						text-decoration-line: underline;
				text-decoration-thickness: .0.5em;
			}
		');

		$parse_css .= newsx_parse_css([
			$bs_content_underline_links_selector => [
				'text-decoration-color' => $accent_color,
				'-webkit-text-decoration-color' => $accent_color,
			],
		]);
	}

	// Post Date
	if ( 'separate' === newsx_get_option('bs_header_date_position') ) {
		$parse_css .= newsx_minify_static_css('
			.newsx-post-meta-inner {
				flex-direction: column;
				align-items: flex-start;	
			}
			.newsx-post-meta-inn-group div:last-child:after {
				display: none;
			}
		');
	}

	// Table of Contents
	if ( defined('NEWSX_CORE_PRO_VERSION') && newsx_core_pro_fs()->can_use_premium_code() ) {
		$parse_css .= Newsx_Core_Pro_Dynamic_CSS::get_blog_single_table_of_contents_styles();
	}

	// Social Sharing
	if ( defined('NEWSX_CORE_PRO_VERSION') && newsx_core_pro_fs()->can_use_premium_code() ) {
		$parse_css .= Newsx_Core_Pro_Dynamic_CSS::get_blog_single_sharing_styles();
	} else {
		$parse_css .= newsx_parse_css([
			'.newsx-post-sharing svg' => [
				'color' => '#292929',
			],
		]);
	}

	// Post Content
	if ( defined('NEWSX_CORE_PRO_VERSION') && newsx_core_pro_fs()->can_use_premium_code() ) {
		$parse_css .= Newsx_Core_Pro_Dynamic_CSS::get_blog_single_content_styles();
	}

	// Navigation
	if ( newsx_get_option('bs_nav_dividers') ) {
		$parse_css .= newsx_minify_static_css('
			.newsx-post-navigation {
				border-top-width: 1px;
				border-top-style: solid;
				border-bottom-width: 1px;
				border-bottom-style: solid;
			}
			.newsx-post-navigation > a {
				padding: 15px 0;
			}
			.newsx-post-navigation .newsx-divider {
				position: absolute;
				top: 0;
				left: 50%;
				height: 100%;
				border-right-width: 1px;
				border-right-style: solid;
			}
		');
	}

	$parse_css .= newsx_get_resp_spacing_css(newsx_get_option('bs_nav_padding'), '.newsx-post-navigation > a.prev-post, .newsx-post-navigation > a.next-post', 'padding');
	$parse_css .= newsx_get_resp_spacing_css(newsx_get_option('bs_nav_margin'), '.newsx-post-navigation', 'margin');

	$parse_css .= newsx_get_resp_slider_control_css($global_font_body['font-size'], '.newsx-post-navigation', 'font-size', 'px', 1); 

	// Author Box
	if ( newsx_get_option('bs_author_avatar_radius') ) {
		$parse_css .= newsx_minify_static_css('
			.newsx-post-author-box .author-avatar img {
				border-radius: 50%;
			}
		');
	}

	$parse_css .= newsx_get_resp_slider_control_css($global_font_body['font-size'], '.newsx-post-author-box .author-info a', 'font-size', 'px', -1);
	
	if ( newsx_get_option('bs_author_section_divider') ) {
		$parse_css .= newsx_minify_static_css('
			.newsx-post-author-box {
				border-bottom-width: 1px;
				border-bottom-style: solid;
			}
		');
	}

	$parse_css .= newsx_get_resp_spacing_css(newsx_get_option('bs_author_padding'), '.newsx-post-author-box', 'padding');
	$parse_css .= newsx_get_resp_spacing_css(newsx_get_option('bs_author_margin'), '.newsx-post-author-box', 'margin');

	// Newsletter
	if ( defined('NEWSX_CORE_PRO_VERSION') && newsx_core_pro_fs()->can_use_premium_code() ) {
		$parse_css .= Newsx_Core_Pro_Dynamic_CSS::get_blog_single_newsletter_styles();
	}

	// Related Posts
	if ( newsx_get_option('bs_related_section_divider') ) {
		$parse_css .= newsx_minify_static_css('
			.newsx-related-posts-wrap {
				border-bottom-width: 1px;
				border-bottom-style: solid;
			}
		');
	}

	$parse_css .= newsx_get_resp_spacing_css(newsx_get_option('bs_related_padding'), '.newsx-related-posts-wrap', 'padding');
	$parse_css .= newsx_get_resp_spacing_css(newsx_get_option('bs_related_margin'), '.newsx-related-posts-wrap', 'margin');

	// Progress Bar
	if ( defined('NEWSX_CORE_PRO_VERSION') && newsx_core_pro_fs()->can_use_premium_code() ) {
		$parse_css .= Newsx_Core_Pro_Dynamic_CSS::get_blog_single_advanced_prbar_styles();
	} else {
		$parse_css .= newsx_parse_css([
			'.newsx-reading-progress-bar' => [
				'height' => '4px',
			],
		]);
	}

	// Preloader
	if ( defined('NEWSX_CORE_PRO_VERSION') && newsx_core_pro_fs()->can_use_premium_code() && method_exists('Newsx_Core_Pro_Dynamic_CSS', 'get_preloader_styles') ) {
		$parse_css .= Newsx_Core_Pro_Dynamic_CSS::get_preloader_styles();
	}

    /* Responsive
    -------------------------------------------------- */
	$parse_css .= newsx_minify_static_css('
		/* Tablet */
		@media screen and ( max-width: '. newsx_get_tablet_breakpoint() .'px ) {
			/* Row */
			.newsx-row-inner,
			.newsx-blog-page-inner,
			.newsx-archive-page-inner,
			.newsx-single-inner {
				flex-direction: column;
			}

			/* Footer Rows */
			.newsx-site-footer .newsx-builder-grid-row {
				grid-template-columns: auto;
			}

			/* Menu */
			.newsx-pointer-item:after {
				display: none;
			}
			.newsx-mobile-menu .home-icon {
				display: none;
			}

			/* Sidebar */
			.newsx-sidebar {
				position: static;
				width: 100% !important;
			}
			.home .newsx-main-content section.newsx-widget {
				margin-bottom: 30px;
			}
			.home .newsx-sidebar {
				padding-bottom: 30px;
			}

			/* Front Page */
			.home .newsx-magazine-layout .newsx-grid-excerpt {
				display: none;
			}

			/* Single Post */
			.newsx-single-inner {
				margin-top: 0;
			}
			.newsx-post-meta-inn-wrap {
				flex-direction: row;
				flex-wrap: nowrap;
			}
			.single-format-standard .newsx-single-wrap.newsx-s3:not(.newsx-no-post-thumb) .newsx-single-post-header {
				padding: 25px;
				margin: 0;
			}
			.single-format-standard .newsx-single-wrap.newsx-s3:not(.newsx-no-post-thumb) .newsx-single-post-header .newsx-breadcrumbs,
			.single-format-standard .newsx-single-wrap.newsx-s3:not(.newsx-no-post-thumb) .newsx-single-post-header .newsx-static-sharing {
				display: none;
			}
			.newsx-single-post-header .newsx-post-categories {
				margin-bottom: 10px;
			}
			.newsx-post-meta .newsx-static-sharing {
				width: 100%;
				margin-left: 0;
				margin-top: 10px;
			}
			.newsx-post-meta .newsx-s0.newsx-static-sharing {
				padding: 10px 15px;
				background: #f9f9f9;
			}
			.newsx-static-sharing .newsx-post-sharing {
				justify-content: flex-start;
			}
			.newsx-static-sharing .sharing-icons {
				flex-wrap: wrap;
			}
			.newsx-table-of-contents > div {
				column-count: 1;
			}
			.newsx-table-of-contents > div:before {
				display: none;
			}

			/* Grid Layouts */
			.newsx-grid-layout-2-column,
			.newsx-grid-layout-3-column,
			.newsx-grid-layout-4-column {
				grid-template-columns: 1fr 1fr;
			}

			/* List Layout */
			.newsx-list-layout-list-9 {
				grid-template-columns: 1fr 1fr;
			}

			/* Magazine Layout */
			.newsx-magazine-layout-1-3 {
				grid-template-columns: 1fr 1fr !important;
				grid-template-rows: 1fr 1fr 1fr !important;
				grid-template-areas:
					"main main"
					"main2 main2" 
					"box1 box2";
			}
			.newsx-magazine-layout-1-4 {
				grid-template-columns: 1fr 1fr;
				grid-template-rows: repeat(4, 1fr);
				grid-template-areas:
					"main main"
					"main main"
					"box1 box2"
					"box3 box4";
			}
			.newsx-magazine-layout-1-1-2 {
				grid-template-columns: 1fr 1fr;
				grid-template-rows: repeat(3, 1fr);
				grid-template-areas:
					"main main"
					"main2 main2"
					"box1 box2";
			}
			.newsx-magazine-layout-2-1-2 {
				grid-template-columns: 1fr 1fr;
				grid-template-rows: repeat(4, 1fr);
				grid-template-areas:
					"box1 box2"
					"main main"
					"main main"
					"box3 box4";
			}
			.newsx-magazine-layout-1-1-3 {
				grid-template-columns: 1fr 1fr;
				grid-template-rows: repeat(4, 1fr);
				grid-template-areas:
					"main main"
					"main main"
					"main2 box1"
					"box2 box3";
			}
			.newsx-magazine-layout-2-h {
				grid-template-columns: 1fr;
				grid-template-rows: 1fr 1fr;
				grid-template-areas:
					"box1"
					"box2";
			}
			.newsx-magazine-layout-4-h {
				grid-template-columns: 1fr 1fr;
				grid-template-rows: 1fr 1fr;
				grid-template-areas:
					"box1 box2"
					"box3 box4";
			}
			.newsx-magazine-layout .newsx-grid-over-media {
				padding: 15px;
			}
			.newsx-magazine-layout .newsx-grid-title {
				max-width: 95%;
			}
			:where(
				.newsx-magazine-layout-1-4,
				.newsx-magazine-layout-2-1-2,
				.newsx-magazine-layout-1-1-3
			) article:not(:first-child) :where(.newsx-grid-categories, .newsx-grid-author),
			:where(
				.newsx-magazine-layout-1-3,
				.newsx-magazine-layout-1-1-2
			) article:where(:nth-child(3), :nth-child(4)) :where(.newsx-grid-categories, .newsx-grid-author),
			.newsx-magazine-layout-2-3 article:where(:nth-child(3), :nth-child(4), :nth-child(5)) :where(.newsx-grid-categories, .newsx-grid-author),
			.newsx-magazine-layout-1vh-3h article:not(:first-child) .newsx-grid-categories,
			.newsx-magazine-layout-4-h :where(.newsx-grid-categories, .newsx-grid-author) {
				display: none;
			}

			/* Slider */
			.newsx-slider-wrap .newsx-slider-over-media {
				padding: 15px;
			}
			.newsx-slider-wrap .newsx-slider-prev,
			.newsx-slider-wrap .newsx-slider-next {
				width: 30px;
				height: 30px;
				margin-top: -15px;
			}
			.newsx-slider-wrap .newsx-slider-prev:after,
			.newsx-slider-wrap .newsx-slider-next:after {
				font-size: 12px;
			}
			.newsx-slider-wrap .newsx-slider-prev {
				left: 15px;
			}
			.newsx-slider-wrap .newsx-slider-next {
				right: 15px;
			}
			.newsx-slider-wrap .newsx-slider-over-media .newsx-grid-excerpt {
				display: none;
			}
			.newsx-slider-wrap .newsx-slider-next,
			.newsx-slider-wrap .newsx-slider-prev {
				opacity: 0;
				transition: opacity 0.3s ease;
			}
			.newsx-slider-wrap:hover .newsx-slider-next,
			.newsx-slider-wrap:hover .newsx-slider-prev {
				opacity: 1;
			}

			/* News Ticker */
			.newsx-news-ticker-title {
				line-height: 1.2;
			}
			.newsx-slider-prev, .newsx-slider-next {
				display: none !important;
			}

			.newsx-news-ticker .js-marquee .news-ticker-post {
				min-width: 75px;
			}
		}

		/* Mobile */
		@media screen and ( max-width: '. newsx_get_mobile_breakpoint() .'px ) {
			/* Header */
			.newsx-builder-grid-row > .site-header-column > div {
				flex-shrink: 1 !important;
			}

			/* News Ticker */
			.newsx-news-ticker[data-ticker-type="marquee"] .news-ticker-content:after {
				width: 10px;
			}

			.newsx-news-ticker .js-marquee .news-ticker-post {
				min-width: 0;
			}

			/* Single Post */
			.newsx-table-of-contents h5 a {
				white-space: wrap;
			}
			:where(.newsx-s0, .newsx-s0-nr).newsx-post-navigation img {
				display: none;
			}
			.newsx-s1.newsx-post-navigation a:first-of-type {
				flex-direction: column;
			}
			.newsx-s1.newsx-post-navigation a:last-child {
				flex-direction: column-reverse;
				align-items: flex-end;
			}
			.newsx-s1.newsx-post-navigation img {
				width: 65%;
				height: auto;
			}
			.newsx-newsletter-title,
			.newsx-newsletter-title * {
				justify-content: center;
				text-align: center;
			}
			.newsx-related-posts {
				grid-template-columns: repeat(2, 1fr);
			}
			.newsx-related-posts.newsx-col-3 section:nth-child(3),
			.newsx-related-posts.newsx-col-9 section:nth-child(9) {
				display: none;
			}
			article.entry-comments {
				gap: 10px;
			}
			article.entry-comments .comment-avatar {
				max-width: 30px;
			}
			.comments-area .children {
				margin-left: 20px;
			}

			/* Slider */
			.newsx-slider-wrap.newsx-slider-over-media .newsx-grid-read-more {
				display: none;
			}
			.newsx-slider.newsx-s0 .newsx-grid-categories {
				display: none;
			}
			.newsx-slider-1-column .newsx-grid-title > * {
				font-size: 24px;
			}

			/* Blog Page Feed */
			.newsx-posts-feed .newsx-grid-title > * {
				font-size: 20px;
			}

			/* Category List */
			.newsx-s2.newsx-category-list {
				grid-template-columns: 1fr;
				gap: 10px;
			}
			.newsx-s2.newsx-category-list li {
				display: flex;
			}
			.newsx-s2.newsx-category-list li img {
				max-width: 100px;
			}
			.newsx-s2.newsx-category-list .category-meta {
				width: 100%;
			}

			/* Featured Posts */
			.newsx-featured-posts .newsx-post-index {
				left: 7px;
			}

			/* Grid Layouts */
			.newsx-grid-layout-2-column,
			.newsx-grid-layout-3-column,
			.newsx-grid-layout-4-column {
				grid-template-columns: 1fr;
			}

			/* List Layout */
			:where(
				.newsx-list-layout-list-1,
				.newsx-list-layout-list-2,
				.newsx-list-layout-list-3,
				.newsx-list-layout-list-4,
				.newsx-list-layout-list-5,
				.newsx-list-layout-list-6
			) .newsx-grid-item {
				flex-direction: column;	
			}
			.newsx-list-layout :where(.newsx-grid-media, .newsx-grid-below-media, img) {
				width: 100% !important;
				max-width: 100% !important;
			}

			.newsx-list-layout .newsx-grid-item {
				gap: 5px;
			}

			.newsx-list-layout-list-7,
			.newsx-list-layout-list-8,
			.newsx-list-layout-list-9 {
				grid-template-columns: 1fr;
			}
			.newsx-list-layout:where(
				.newsx-list-layout-list-7,
				.newsx-list-layout-list-8,
				.newsx-list-layout-list-9
			) article {
				flex-direction: column;
			}
			.newsx-list-layout-list-4 .newsx-grid-item:first-child,
			.newsx-list-layout-list-5 .newsx-grid-item:first-child,
			.newsx-list-layout-list-6 .newsx-grid-item:nth-child(1),
			.newsx-list-layout-list-6 .newsx-grid-item:nth-child(2) {
				grid-column: span 2;
			}
			.newsx-list-layout-list-5 .newsx-grid-item.newsx-big-post {
				min-height: 520px;
			}

			.newsx-list-layout-list-8 .newsx-grid-media,
			.newsx-list-layout-list-9 .newsx-grid-media {
				flex: auto;
			}
			
			/* Magazine Layout */
			.newsx-magazine-layout-1-2 {
				grid-template-columns: 1fr 1fr;
				grid-template-rows: 1fr 1fr;
				grid-template-areas:
					"main main"
					"box1 box2";
			}
			.newsx-magazine-layout-1vh-3h {
				grid-template-columns: 1fr;
				grid-template-rows: repeat(5, 1fr);
				grid-template-areas:
					"main"
					"main"
					"box1"
					"box2"
					"box3";
			}
			.newsx-magazine-layout-1-1-1 {
				grid-template-columns: 1fr;
				grid-template-rows: repeat(4, 1fr);
				grid-template-areas:
					"box1"
					"main"
					"main"
					"box2";
			}
			.newsx-magazine-layout-2-3 {
				grid-template-columns: 1fr 1fr;
				grid-template-rows: repeat(6, 1fr);
				grid-template-areas:
					"main box1"
					"main box1"
					"main box2"
					"main2 box2"
					"main2 box3"
					"main2 box3";
			}
			.newsx-magazine-layout-3-h {
				grid-template-columns: 1fr;
				grid-template-rows: repeat(3, 1fr);
				grid-template-areas: 
					"box1"
					"box2"
					"box3";
			}
			.newsx-magazine-layout .newsx-grid-excerpt {
				display: none;
			}

			/* Media and Text */
			.wp-block-media-text.is-stacked-on-mobile {
				gap: 10px !important;
			}

			/* Search */
			.newsx-ajax-search-results {
				right: -2.5vw;
				width: 95vw;
			}
		}
		');

	// CSS Output
	return $parse_css;
}


/* Dark Mode
-------------------------------------------------- */
function newsx_get_dark_mode_css() {
	$dark_mode_css = '';

	$dark_mode_css .= newsx_minify_static_css('
		/* Background Color */
		body.newsx-dark-mode, .site-content, .newsx-offcanvas-widgets-area, .newsx-mobile-menu-container, .search-form, .newsx-ajax-search-results, .newsx-table-of-contents h3:after, .newsx-single-post-media .image-caption,
		.newsx-site-header .newsx-section-wrap, .newsx-site-footer .newsx-section-wrap, .newsx-desktop-menu .sub-menu, .newsx-preloader-wrap {
			background-color: #222222;
		}

		/* Background Color Light */
		input, select, textarea,
		.comment-form input, .comment-form select, .comment-form textarea {
			background-color: #272727;
		}
		.tipsy-inner {
			background-color: #383838;
			color: #fff;
		}
		.tipsy-arrow {
			border-color: #383838;
		}
		.newsx-grid-filters-dropdown {
			background-color: #383838;
			color: #c4c4c4;
		}

		/* Background Color Extra Light */
		.newsx-newsletter-wrap, .newsx-weather-wrap,
		.newsx-table-of-contents, .newsx-table-of-contents > div:before,
		.newsx-s2.newsx-category-list .category-meta,
		.newsx-s0.newsx-float-sharing:not(.pos-outside) .sharing-icons, .newsx-s0.newsx-float-sharing.pos-outside .newsx-post-sharing,
		.header-search-s2.active .search-form, .newsx-ajax-search-results,
		.newsx-dark-mode-switcher {
			background-color: #333333 !important;
		}
		.newsx-offcanvas-widgets-area::-webkit-scrollbar-thumb,
		.newsx-offcanvas-widgets-area::-webkit-scrollbar-thumb:hover  {
			background-color: #333333 !important;
		}
		.header-search-s2 .search-form::after {
			border-bottom-color: #333333;
		}
		.single .newsx-newsletter-wrap {
			background-color: transparent !important;
		}
		.comment-form .form-submit .submit, .post-password-form input[type="submit"], .wpcf7-submit, .wp-block-file__button, .wp-block-loginout .button {
			background-color: #333333;
		}
		@media screen and (max-width: 768px) {
			.newsx-post-meta .newsx-s0.newsx-static-sharing {
				background: #333333;
			}
		}

		/* Border Color */
		pre, button, input, select, textarea, .newsx-mobile-menu li, .newsx-cta-button, .search-form, .search-field, .newsx-ajax-search-results, .newsx-grid-filters-dropdown, .newsx-prev, .newsx-next, .newsx-load-more, .newsx-category-list li a, .newsx-tabs li, .newsx-social-icon, .newsx-blog-pagination > *, article.entry-comments, .newsx-table-of-contents a, .newsx-post-navigation, .newsx-post-navigation .newsx-divider, .comments-pagination, .newsx-post-author-box, .newsx-newsletter-wrap, .newsx-related-posts-wrap, table, td, th, .widget_block table thead, .widget_block table th, .widget_block table td, .widget_block table tfoot, .wp-block-search__input, :where(.wp-block-search__button-inside .wp-block-search__inside-wrapper), .wp-block-tag-cloud.is-style-outline a, .widget_nav_menu li a, .wp-block-group, .wp-block-code, .wp-block-table thead, .wp-block-table tfoot, .wp-block-table td, .wp-block-table th,
		.newsx-site-header .newsx-section-wrap, .newsx-site-footer .newsx-section-wrap, .newsx-header-menu-primary .newsx-desktop-menu .sub-menu.newsx-submenu-divider .menu-item:not(:last-child), .newsx-header-menu-secondary .newsx-desktop-menu .sub-menu.newsx-submenu-divider .menu-item:not(:last-child),
		.newsx-s3.newsx-widget-title-wrap {
			border-color: #383838;
		}
		hr, .wp-block-separator {
			color: #383838;
			background-color: #383838;
		}
		.newsx-site-header .newsx-top-section-wrap {
			border-bottom-width: 1px;
			border-bottom-style: solid;
		}

		/* Border Color Light */
		.newsx-table-of-contents a,
		.header-search-s2.active .search-form .search-field,
		.newsx-site-footer .newsx-category-list.newsx-s0 li a {
			border-color: #4a4a4a;
		}
		.newsx-s3.newsx-widget-title-wrap {
			border-bottom-color: #4a4a4a;
		}

		/* Text Color */
		body, button, input, select, textarea, .newsx-social-icon, .newsx-cta-button a, .newsx-random-post a, .newsx-blog-pagination > *, .newsx-breadcrumbs a, .newsx-post-sources a, .widget_nav_menu li a, .newsx-grid-view-all a,
		.newsx-header-menu-secondary a, .newsx-header-weather,
		.newsx-single-wrap .newsx-post-content,
		.newsx-date-and-time,
		.newsx-site-footer {
			color: #c4c4c4;
		}
		.newsx-header-menu-secondary .sub-menu-icon .newsx-svg {
			fill: #c4c4c4;
		}

		/* Meta Color */
		.newsx-weather-content .weather-condition, .newsx-weather-content .weather-extra-info, .newsx-grid-date-time, .newsx-grid-author a, .newsx-grid-author a:hover, .newsx-grid-post-meta div:first-child:after, .wp-block-tag-cloud.is-style-outline a, .sharing-header, .newsx-post-meta-inner, .newsx-post-meta-inner a, .newsx-post-author-box .author-job, .newsx-related-posts .post-date, .comment-meta, .comment-meta a, .comment-respond .logged-in-as, .comment-respond .logged-in-as, .comment-respond .comment-notes, .comment-form .comment-form-cookies-consent label, .comment-form textarea::placeholder, .comment-form input::placeholder, .wpcf7-form-control::placeholder, .newsx-newsletter-form .agree-to-terms, .newsx-newsletter-policy, .newsx-archive-page-header .sub-categories span,
		.search-field::placeholder, .newsx-newsletter-form input::placeholder, .wp-block-search__input::placeholder, 
		.newsx-site-title-tagline .site-description, .newsx-ajax-search-results .search-results-content span,
		.newsx-s0.newsx-category-list li a,
		.newsx-site-footer .newsx-post-meta {
			color: #9e9e9e;
		}
		.newsx-static-sharing .sharing-header {
			color: #9e9e9e !important;
		}
		.newsx-static-sharing .sharing-header svg {
			color: #9e9e9e !important;
			fill: #9e9e9e !important;
		}

		/* SVG Icon Color */
		.newsx-svg-icon svg,
		.newsx-offcanvas-btn svg,
		.newsx-header-social-icons .newsx-social-icon svg,
		.newsx-header-search .newsx-search-icon svg,
		.newsx-random-post svg,
		.newsx-s0.newsx-static-sharing .sharing-icons .copy-share svg,
		.newsx-s0.newsx-static-sharing .sharing-icons .print-share svg,
		.newsx-s0.newsx-float-sharing .sharing-icons .copy-share svg,
		.newsx-s0.newsx-float-sharing .sharing-icons .print-share svg,
		.newsx-grid-filters-dropdown-more:hover svg,
		.newsx-widget .newsx-social-icon svg,
		.newsx-site-footer .newsx-social-icon svg {
			fill: #fff;
			color: #fff;
		}
		:not(.newsx-original-colors) .newsx-post-sharing .sharing-icons svg {
			fill: #fff;
			color: #fff;
		}
		.newsx-post-format-icon {
			border-color: #f9f9f9;
		}
		.newsx-post-format-icon svg path {
			color: #f9f9f9;
		}
		:where(.newsx-s2, .newsx-s2-sr, .newsx-s2-br) > .newsx-post-sharing .sharing-icons .copy-share {
			border-color: #999;
		}
		:where(.newsx-s2, .newsx-s2-sr, .newsx-s2-br) > .newsx-post-sharing .sharing-icons .copy-share svg {
			fill: #999;
		}
		:where(.newsx-s2, .newsx-s2-sr, .newsx-s2-br).newsx-float-sharing.newsx-original-colors:not(.newsx-s0) .sharing-icons a {
			background-color: transparent;
		}
		.newsx-header-search .newsx-ring-loader div {
			border-left-color: #fff !important;
		}

		/* Link Color */
		a, .newsx-header-menu-primary a,
		.newsx-grid-filters-dropdown-more:hover {
			color: #fff;
		}

		/* Heading Color */
		.newsx-site-title-tagline .site-title a, .newsx-site-title-tagline .site-title a:hover, h1, h1 a, h2, h2 a, h3, h3 a, h4, h4 a, h5, h5 a, h6, h6 a, .newsx-grid-title > :where(div, p, span) a,
		.newsx-table-of-contents a, .newsx-grid-read-more a, .newsx-weather-content .weather-location, .newsx-weather-content .weather-temp,
		.newsx-post-meta-inner .newsx-post-author a, .comment-author, .comment .comment-author a, .newsx-post-content + .newsx-static-sharing .sharing-header,
		.newsx-single-post-media .image-caption, .newsx-newsletter-title svg, .widget_block .wp-block-quote, .widget_block .wp-block-details:not(.has-text-color) summary,
		.newsx-mobile-menu-toggle, .newsx-ajax-search-results .search-results-content a,
		.newsx-header-news-ticker a.newsx-news-ticker-title,
		.newsx-widget .soc-brand, .newsx-widget .newsx-lt-s1 .soc-label1, .newsx-widget .soc-count,
		.newsx-widget .newsx-social-icon:hover .soc-brand, .newsx-widget .newsx-lt-s1 .newsx-social-icon:hover .soc-label1, .newsx-widget .newsx-social-icon:hover .soc-count,
		.newsx-heading-widget h3, .newsx-widget-title-text,
		.newsx-site-footer .newsx-widget :where(h1, h2, h3, h4, h5, h6) a {
			color: #fff;
		}
	');

	if ( newsx_get_option('global_island_style') ) {
		$island_style_selector = '.site-content :where(section.newsx-list-widget, section.newsx-grid-widget, section.newsx-social-icons-widget, section.newsx-featured-tabs-widget, section.newsx-featured-posts-widget, section.newsx-category-list-widget, .widget_tag_cloud, .widget_text, .widget_block .wp-block-table, .widget_search, .newsx-single-content-wrap, .newsx-default-page-wrap .primary), .primary > .newsx-posts-feed .newsx-grid-item, .primary > .newsx-posts-feed > .newsx-blog-pagination, .newsx-fp-row-extra .newsx-posts-feed .newsx-grid-item';
		
		$dark_mode_css .= newsx_minify_static_css('
			'. $island_style_selector .' {
				background-color: #333333;
			}

			.site-content .newsx-newsletter-wrap {
				background-color: #333333 !important;
			}

			pre, button, input, select, textarea, .newsx-mobile-menu li, .newsx-cta-button, .search-form, .search-field, .newsx-ajax-search-results, .newsx-grid-filters-dropdown, .newsx-prev, .newsx-next, .newsx-load-more, .newsx-category-list li a, .newsx-tabs li, .newsx-social-icon, .newsx-blog-pagination > *, article.entry-comments, .newsx-table-of-contents a, .newsx-post-navigation, .newsx-post-navigation .newsx-divider, .comments-pagination, .newsx-post-author-box, .newsx-newsletter-wrap, .newsx-related-posts-wrap, table, td, th, .widget_block table thead, .widget_block table th, .widget_block table td, .widget_block table tfoot, .wp-block-search__input, :where(.wp-block-search__button-inside .wp-block-search__inside-wrapper), .wp-block-tag-cloud.is-style-outline a, .widget_nav_menu li a, .wp-block-group, .wp-block-code, .wp-block-table thead, .wp-block-table tfoot, .wp-block-table td, .wp-block-table th,
			.newsx-site-header .newsx-section-wrap, .newsx-site-footer .newsx-section-wrap, .newsx-header-menu-primary .newsx-desktop-menu .sub-menu.newsx-submenu-divider .menu-item:not(:last-child), .newsx-header-menu-secondary .newsx-desktop-menu .sub-menu.newsx-submenu-divider .menu-item:not(:last-child),
			.newsx-s3.newsx-widget-title-wrap {
				border-color: #4a4a4a;
			}
			hr, .wp-block-separator {
				color: #4a4a4a;
				background-color: #4a4a4a;
			}
		');
	} else {
		if ( defined('NEWSX_CORE_PRO_VERSION') && newsx_core_pro_fs()->can_use_premium_code() ) {
			$dark_mode_css .= newsx_minify_static_css('
				.newsx-row-inner, .newsx-single-wrap, .newsx-blog-page-wrap, .newsx-archive-page-wrap, .newsx-default-page-wrap {
					background-color: #222222;
				}
			');
		}
	}

	return $dark_mode_css;
}


/*
** Get CSS Value
*/
if ( ! function_exists( 'newsx_get_css_value' ) ) {
	function newsx_get_css_value($value, $unit = 'px') {
		$css_value = '';

		if ( '' !== $value ) {
			$css_value = esc_attr( $value ) . $unit;
		}

		return $css_value;
	}
}

/*
** Parse Dynamic CSS
*/
if ( ! function_exists( 'newsx_parse_css' ) ) {
	function newsx_parse_css( $css_output = [], $min_media = '', $max_media = '' ) {
		$parse_css = '';

		if ( is_array( $css_output ) && count( $css_output ) > 0 ) {

			foreach ( $css_output as $selector => $properties ) {

				if ( null === $properties ) {
					break;
				}

				if ( ! count( $properties ) ) {
					continue;
				}

				$temp_parse_css   = $selector . '{';
				$properties_added = 0;

				foreach ( $properties as $property => $value ) {

					if ( '' == $value && 0 !== $value ) {
						continue;
					}

					$properties_added++;
					$temp_parse_css .= $property . ':' . $value . ';';
				}

				$temp_parse_css .= '}';

				if ( $properties_added > 0 ) {
					$parse_css .= $temp_parse_css;
				}
			}

			if ( '' != $parse_css && ( '' !== $min_media || '' !== $max_media ) ) {

				$media_css       = '@media ';
				$min_media_css   = '';
				$max_media_css   = '';
				$media_separator = '';

				if ( '' !== $min_media ) {
					$min_media_css = '(min-width:' . $min_media . 'px)';
				}
				if ( '' !== $max_media ) {
					$max_media_css = '(max-width:' . $max_media . 'px)';
				}
				if ( '' !== $min_media && '' !== $max_media ) {
					$media_separator = ' and ';
				}

				$media_css .= $min_media_css . $media_separator . $max_media_css . '{' . $parse_css . '}';

				return $media_css;
			}
		}

		return $parse_css;
	}
}

/*
** Minify Static CSS
*/
if ( ! function_exists( 'newsx_minify_static_css' ) ) {
	function newsx_minify_static_css( $css ) {
		// Remove comments
		$minified_css = preg_replace( '!/\*[^*]*\*+([^/][^*]*\*+)*/!', '', $css );
		// Remove space after colons
		$minified_css = str_replace( ': ', ':', $minified_css );
		// Remove whitespace
		$minified_css = str_replace( array("\r\n", "\r", "\n", "\t", '  ', '    ', '    '), '', $minified_css );
		
		return $minified_css;
	}
}

/*
** Get Typography CSS
*/
if ( ! function_exists( 'newsx_get_typography_css' ) ) {
	function newsx_get_typography_css( $option, $selector ) {
		$parse_css = '';

		$parse_css .= newsx_parse_css([
			$selector => [
				'font-family' => $option['font-family'],
				'font-weight' => (intval($option['font-weight']) > 100 ? $option['font-weight'] : 'normal'),
				'font-style'  => $option['font-style'],
				'text-transform' => $option['text-transform'],
				'text-decoration' => $option['text-decoration'],
				'text-align' => $option['text-align'],
				'line-height' => $option['line-height'],
				'letter-spacing' => $option['letter-spacing'] .'px',
			],
		]);

		if ( isset($option['font-size'] ) ) {
			$parse_css .= newsx_get_resp_slider_control_css($option['font-size'], $selector, 'font-size');
		}

		return $parse_css;
	}
}

/*
** Element CSS Visibility Logic
*/
if ( ! function_exists( 'newsx_get_element_visibility_css' ) ) {
	function newsx_get_element_visibility_css($option, $selector, $display = 'block') {
		$parse_css = '';

		if ( ! is_array($option) ) {
			return $parse_css;
		}

		$desktop_option = in_array('desktop', $option) ? $display : 'none';
		$tablet_option  = in_array('tablet', $option) ? $display : 'none';
		$mobile_option  = in_array('mobile', $option) ? $display : 'none';

		// Desktop
		$parse_css .= newsx_parse_css([
			$selector => [
				'display' => $desktop_option,
			],
		]);

		// Tablet
		$parse_css .= newsx_parse_css(
			[
				$selector => [
					'display' => $tablet_option,
				],
			],
			'',
			newsx_get_tablet_breakpoint()
		);

		// Mobile
		$parse_css .= newsx_parse_css(
			[
				$selector => [
					'display' => $mobile_option,
				],
			],
			'',
			newsx_get_mobile_breakpoint()
		);

		return $parse_css;
	}
}

/*
** Get Responsive Slider Control CSS
*/
if ( ! function_exists( 'newsx_get_resp_slider_control_css' ) ) {
	function newsx_get_resp_slider_control_css($option, $selector, $property, $unit = 'px', $minuspx = 0) {
		$parse_css = '';

		// if is json convert to array
		if ( is_string($option) ) {
			$option = json_decode($option, true);
		}

		// Desktop
		if ( isset($option['desktop']) ) {
			$desktop_value = !empty($option['desktop']) ? ($option['desktop'] - $minuspx) : '';
			$parse_css .= newsx_parse_css([
				$selector => [
					$property => newsx_get_css_value($desktop_value, $unit),
				],
			]);
		}

		// Tablet
		if ( isset($option['tablet']) ) {
			$tablet_value = !empty($option['tablet']) ? ($option['tablet'] - $minuspx) : '';
			$parse_css .= newsx_parse_css(
				[
					$selector => [
						$property => newsx_get_css_value($tablet_value, $unit),
					],
				],
				'',
				newsx_get_tablet_breakpoint()
			);
		}

		// Mobile
		if ( isset($option['mobile']) ) {
			$mobile_value = !empty($option['mobile']) ? ($option['mobile'] - $minuspx) : '';
			$parse_css .= newsx_parse_css(
				[
					$selector => [
						$property => newsx_get_css_value($mobile_value, $unit),
					],
				],
				'',
				newsx_get_mobile_breakpoint()
			);
		}

		return $parse_css;
	}
}

/*
** Get Responsive Slider Reduce Control CSS
*/
if ( ! function_exists( 'newsx_get_resp_slider_reduce_control_css' ) ) {
	function newsx_get_resp_slider_reduce_control_css($option, $selector, $property, $unit = 'px', $percent = 0) {
		$parse_css = '';

		// if is json convert to array
		if ( is_string($option) ) {
			$option = json_decode($option, true);
		}

		// Desktop
		if ( isset($option['desktop']) ) {
			$desktop_value = !empty($option['desktop']) ? newsx_reduce_value($option['desktop'], $percent) : '';
			$parse_css .= newsx_parse_css([
				$selector => [
					$property => newsx_get_css_value($desktop_value, $unit),
				],
			]);
		}

		// Tablet
		if ( isset($option['tablet']) ) {
			$tablet_value = !empty($option['tablet']) ? newsx_reduce_value($option['tablet'], $percent) : '';
			$parse_css .= newsx_parse_css(
				[
					$selector => [
						$property => newsx_get_css_value($tablet_value, $unit),
					],
				],
				'',
				newsx_get_tablet_breakpoint()
			);
		}

		// Mobile
		if ( isset($option['mobile']) ) {
			$mobile_value = !empty($option['mobile']) ? newsx_reduce_value($option['mobile'], $percent) : '';
			$parse_css .= newsx_parse_css(
				[
					$selector => [
						$property => newsx_get_css_value($mobile_value, $unit),
					],
				],
				'',
				newsx_get_mobile_breakpoint()
			);
		}

		return $parse_css;
	}
}

/*
** Get Responsive Spacing Control CSS
*/
if ( ! function_exists( 'newsx_get_resp_spacing_css' ) ) {
	function newsx_get_resp_spacing_css($option, $selector, $property, $unit = 'px') {
		$parse_css = '';

		// Desktop
		if ( isset($option['desktop']) ) {
			if ( $option['desktop']['isLinked'] ) {
				$parse_css .= newsx_parse_css([
					$selector => [
						$property => newsx_get_css_value($option['desktop']['top'], $unit),
					],
				]);
			} else {
				$parse_css .= newsx_parse_css([
					$selector => [
						$property .'-top' => newsx_get_css_value($option['desktop']['top'], $unit),
						$property .'-right' => newsx_get_css_value($option['desktop']['right'], $unit),
						$property .'-bottom' => newsx_get_css_value($option['desktop']['bottom'], $unit),
						$property .'-left' => newsx_get_css_value($option['desktop']['left'], $unit),
					],
				]);
			}
		}

		// Tablet
		if ( isset($option['tablet']) ) {
			if ( $option['tablet']['isLinked'] ) {
				$parse_css .= newsx_parse_css(
					[
						$selector => [
							$property => newsx_get_css_value($option['tablet']['top'], $unit),
						],
					],
					'',
					newsx_get_tablet_breakpoint()
				);
			} else {
				$parse_css .= newsx_parse_css(
					[
						$selector => [
							$property .'-top' => newsx_get_css_value($option['tablet']['top'], $unit),
							$property .'-right' => newsx_get_css_value($option['tablet']['right'], $unit),
							$property .'-bottom' => newsx_get_css_value($option['tablet']['bottom'], $unit),
							$property .'-left' => newsx_get_css_value($option['tablet']['left'], $unit),
						],
					],
					'',
					newsx_get_tablet_breakpoint()
				);
			}
		}

		// Mobile
		if ( isset($option['mobile']) ) {
			if ( $option['mobile']['isLinked'] ) {
				$parse_css .= newsx_parse_css(
					[
						$selector => [
							$property => newsx_get_css_value($option['mobile']['top'], $unit),
						],
					],
					'',
					newsx_get_tablet_breakpoint()
				);
			} else {
				$parse_css .= newsx_parse_css(
					[
						$selector => [
							$property .'-top' => newsx_get_css_value($option['mobile']['top'], $unit),
							$property .'-right' => newsx_get_css_value($option['mobile']['right'], $unit),
							$property .'-bottom' => newsx_get_css_value($option['mobile']['bottom'], $unit),
							$property .'-left' => newsx_get_css_value($option['mobile']['left'], $unit),
						],
					],
					'',
					newsx_get_mobile_breakpoint()
				);
			}
		}

		return $parse_css;
	}
}

/*
** Get Responsive SVG Size CSS
*/
if ( ! function_exists( 'newsx_get_resp_svg_size_css' ) ) {
	function newsx_get_resp_svg_size_css($option, $selector, $minuspx = 0) {
		$parse_css = '';

		// Desktop
		if ( isset($option['desktop']) ) {
			$desktop_value = !empty($option['desktop']) ? ($option['desktop'] - $minuspx) : '';
			$parse_css .= newsx_parse_css([
				$selector => [
					'width' => newsx_get_css_value($desktop_value),
					'height' => newsx_get_css_value($desktop_value),
				],
			]);
		}

		// Tablet
		if ( isset($option['tablet']) ) {
			$tablet_value = !empty($option['tablet']) ? ($option['tablet'] - $minuspx) : '';
			$parse_css .= newsx_parse_css(
				[
					$selector => [
						'width' => newsx_get_css_value($tablet_value),
						'height' => newsx_get_css_value($tablet_value),
					],
				],
				'',
				newsx_get_tablet_breakpoint()
			);
		}

		// Mobile
		if ( isset($option['mobile']) ) {
			$mobile_value = !empty($option['mobile']) ? ($option['mobile'] - $minuspx) : '';
			$parse_css .= newsx_parse_css(
				[
					$selector => [
						'width' => newsx_get_css_value($mobile_value),
						'height' => newsx_get_css_value($mobile_value),
					],
				],
				'',
				newsx_get_mobile_breakpoint()
			);
		}

		return $parse_css;
	}
}

/*
** Get Responsive Alignment
*/
if ( ! function_exists( 'newsx_get_responsive_align_control_css' ) ) {
	function newsx_get_responsive_align_control_css($option, $selector, $property) {
		$parse_css = '';

		// Desktop
		if ( isset($option['desktop']) ) {
			$parse_css .= newsx_parse_css([
				$selector => [
					$property => $option['desktop'],
				],
			]);
		}

		// Tablet
		if ( isset($option['tablet']) ) {
			$parse_css .= newsx_parse_css(
				[
					$selector => [
						$property => $option['tablet'],
					],
				],
				'',
				newsx_get_tablet_breakpoint()
			);
		}

		// Mobile
		if ( isset($option['mobile']) ) {
			$parse_css .= newsx_parse_css(
				[
					$selector => [
						$property => $option['mobile'],
					],
				],
				'',
				newsx_get_mobile_breakpoint()
			);
		}

		return $parse_css;
	}
}

/*
** Get Background CSS
*/
if ( ! function_exists( 'newsx_get_background_css' ) ) {
	function newsx_get_background_css( $data, $selector ) {
		if  ( !$data ) {
			return;
		}

		$parse_css = '';

		$selected_tab = explode('|', $data['background-tabs']);
		$selected_tab = $selected_tab[0];

		// Color
		if ( 'color' === $selected_tab ) {
			$parse_css .= newsx_parse_css([
				$selector => [
					'background-color' => esc_attr($data['background-color']),
				],
			]);

		// Gradient
		} elseif ( 'gradient' === $selected_tab ) {
			$angle = absint($data['gradient-angle']);
			$color1 = esc_attr($data['gradient-color-1']);
			$color2 = esc_attr($data['gradient-color-2']);
			$pos1 = absint($data['gradient-pos-1']);
			$pos2 = absint($data['gradient-pos-2']);

			
			$parse_css .= newsx_parse_css([
				$selector => [
					'background' => 'linear-gradient( '. $angle .'deg, '. $color1 .' '. $pos1 .'%, '. $color2 .' '. $pos2 .'% )',
				],
			]);
		
		// Image
		} else {
			$image = esc_url($data['background-image']);
			$repeat = esc_attr($data['background-repeat']);
			$position = esc_attr($data['background-position']);
			$size = esc_attr($data['background-size']);
			$image_att = esc_attr($data['background-attachment']);

			$parse_css .= newsx_parse_css([
				$selector => [
					'background-image' => 'url('. esc_url($image) .')',
					'background-repeat' => $repeat,
					'background-position' => $position,
					'background-size' => $size,
					'background-attachment' => $image_att,
				],
			]);
		}

		return $parse_css;
	}
}

/*
** Convert HEX to RGBA
*/
if ( ! function_exists( 'newsx_hex_to_rgba' ) ) {
	function newsx_hex_to_rgba($hex, $opacity = 1) {
		if  ( $hex ) {
			$hex = str_replace('#', '', $hex);
			$length = strlen($hex);
			$rgba = 'rgba(';
			$rgba .= hexdec(substr($hex, 0, 2)) . ', ' . hexdec(substr($hex, 2, 2)) . ', ' . hexdec(substr($hex, 4, 2)) . ', ' . $opacity;
			$rgba .= ')';
			return $rgba;
		}
	}
}

/*
** Calculate Percentage Reduction
*/
if ( ! function_exists( 'newsx_percentage_reduction' ) ) {
	function newsx_reduce_value($value, $percent) {
		$value = intval($value);

		// Calculate the reduced value
		$reduced_value = $value - ($value * $percent);

		// Round the result to the nearest integer
		return round($reduced_value);
	}
}
