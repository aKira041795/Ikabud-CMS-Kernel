(function ($) {
	function zakraColorPalette(v, to) {
		let styles = '';
		Object.entries(to.colors).forEach(([k, v]) => {
			styles += `--${k}:${v};`;
		});
		v = `:root {${styles}}`;
		return v;
	}

	function zakraGenerateCommonCSS(selector, property, value) {
		return `${selector} {${property}:${value};}`;
	}

	function zakraGenerateSlidebarWidthCSS(
		selector,
		secondarySelector,
		property,
		value,
	) {
		let sidebarCss = value.size;
		let primaryCss = 100 - value.size;
		let css = '';
		css = `${selector} {${property}: ${sidebarCss}${value.unit};}`;
		css += `${secondarySelector} {${property}: ${primaryCss}${value.unit};}`;

		return css;
	}

	function zakraGenerateSliderCSS(selector, property, value) {
		return `${selector} {${property}: ${value.size}${value.unit};}`;
	}

	function zakraGenerateBackgroundCSS(selector, value) {
		let backgroundColor = value['background-color'],
			backgroundImage = value['background-image'],
			backgroundAttachment = value['background-attachment'],
			backgroundPosition = value['background-position'],
			backgroundSize = value['background-size'],
			backgroundRepeat = value['background-repeat'];

		return `${selector}{background-color: ${backgroundColor};background-image: url( ${backgroundImage} );background-attachment: ${backgroundAttachment};background-position: ${backgroundPosition};background-size: ${backgroundSize};background-repeat: ${backgroundRepeat};}`;
	}

	/**
	 * @param {string} str
	 * @returns {boolean}
	 */
	function zakraIsNumeric(str) {
		var matches;

		if ('string' !== typeof str) {
			return false;
		}

		matches = str.match(/\d+/g);

		return null !== matches;
	}

	function zakraGenerateTypographyCSS(controlId, selector, typography) {
		let css = '';
		var link = '',
			fontFamily = '',
			fontWeight = '',
			fontStyle = '',
			fontTransform = '',
			desktopFontSize = '',
			tabletFontSize = '',
			mobileFontSize = '',
			desktopLineHeight = '',
			tabletLineHeight = '',
			mobileLineHeight = '',
			desktopLetterSpacing = '',
			tabletLetterSpacing = '',
			mobileLetterSpacing = '';

		if ('object' == typeof typography) {
			if (undefined !== typography['font-size']) {
				if (
					undefined !== typography['font-size']['desktop']['size'] &&
					'' !== typography['font-size']['desktop']['size']
				) {
					desktopFontSize =
						typography['font-size']['desktop']['size'] +
						typography['font-size']['desktop']['unit'];
				}

				if (
					undefined !== typography['font-size']['tablet']['size'] &&
					'' !== typography['font-size']['tablet']['size']
				) {
					tabletFontSize =
						typography['font-size']['tablet']['size'] +
						typography['font-size']['tablet']['unit'];
				}

				if (
					undefined !== typography['font-size']['mobile']['size'] &&
					'' !== typography['font-size']['mobile']['size']
				) {
					mobileFontSize =
						typography['font-size']['mobile']['size'] +
						typography['font-size']['mobile']['unit'];
				}
			}

			if (undefined !== typography['line-height']) {
				if (
					undefined !== typography['line-height']['desktop']['size'] &&
					'' !== typography['line-height']['desktop']['size']
				) {
					const desktopLineHeightUnit =
						'-' !== typography['line-height']['desktop']['unit']
							? typography['line-height']['desktop']['unit']
							: '';
					desktopLineHeight =
						typography['line-height']['desktop']['size'] +
						desktopLineHeightUnit;
				}

				if (
					undefined !== typography['line-height']['tablet']['size'] &&
					'' !== typography['line-height']['tablet']['size']
				) {
					const tabletLineHeightUnit =
						'-' !== typography['line-height']['tablet']['unit']
							? typography['line-height']['tablet']['unit']
							: '';
					tabletLineHeight =
						typography['line-height']['tablet']['size'] + tabletLineHeightUnit;
				}

				if (
					undefined !== typography['line-height']['mobile']['size'] &&
					'' !== typography['line-height']['mobile']['size']
				) {
					const mobileLineHeightUnit =
						'-' !== typography['line-height']['mobile']['unit']
							? typography['line-height']['mobile']['unit']
							: '';
					mobileLineHeight =
						typography['line-height']['mobile']['size'] + mobileLineHeightUnit;
				}
			}

			if (undefined !== typography['letter-spacing']) {
				if (
					undefined !== typography['letter-spacing']['desktop']['size'] &&
					'' !== typography['letter-spacing']['desktop']['size']
				) {
					const desktopLetterSpacingUnit =
						'-' !== typography['letter-spacing']['desktop']['unit']
							? typography['letter-spacing']['desktop']['unit']
							: '';
					desktopLetterSpacing =
						typography['letter-spacing']['desktop']['size'] +
						desktopLetterSpacingUnit;
				}

				if (
					undefined !== typography['letter-spacing']['tablet']['size'] &&
					'' !== typography['letter-spacing']['tablet']['size']
				) {
					const tabletLetterSpacingUnit =
						'-' !== typography['letter-spacing']['tablet']['unit']
							? typography['letter-spacing']['tablet']['unit']
							: '';
					tabletLetterSpacing =
						typography['letter-spacing']['tablet']['size'] +
						tabletLetterSpacingUnit;
				}

				if (
					undefined !== typography['letter-spacing']['mobile']['size'] &&
					'' !== typography['letter-spacing']['mobile']['size']
				) {
					const mobileLetterSpacingUnit =
						'-' !== typography['letter-spacing']['mobile']['unit']
							? typography['letter-spacing']['mobile']['unit']
							: '';
					mobileLetterSpacing =
						typography['letter-spacing']['mobile']['size'] +
						mobileLetterSpacingUnit;
				}
			}

			if (
				undefined !== typography['font-family'] &&
				'' !== typography['font-family']
			) {
				fontFamily = typography['font-family'].split(',')[0];
				fontFamily = fontFamily.replace(/'/g, '');

				console.log(fontFamily.includes('default'));

				if (
					fontFamily.includes('default') ||
					fontFamily.includes('Default') ||
					fontFamily.includes('-apple-system')
				) {
					fontFamily =
						'-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", Helvetica, Arial, sans-serif';
				} else if (fontFamily.includes('Monaco')) {
					fontFamily =
						'Monaco,"Lucida Sans Typewriter","Lucida Typewriter","Courier New",Courier,monospace';
				} else {
					link = `<link id="${controlId}" href="https://fonts.googleapis.com/css?family=${fontFamily}" rel="stylesheet">`;
				}
			}

			if (
				undefined !== typography['font-weight'] &&
				'' !== typography['font-weight']
			) {
				if (zakraIsNumeric(typography['font-weight'])) {
					fontWeight = parseInt(typography['font-weight']);
				} else {
					fontWeight =
						'regular' != typography['font-weight']
							? typography['font-weight']
							: 'normal';
				}
			}

			if (
				undefined !== typography['font-style'] &&
				'' !== typography['font-style']
			) {
				fontStyle = typography['font-style'];
			}

			if (
				undefined !== typography['text-transform'] &&
				'' !== typography['text-transform']
			) {
				fontTransform = typography['text-transform'];
			}

			jQuery('link#' + controlId).remove();

			css = `${selector} {
						font-family: ${fontFamily};
						font-weight: ${fontWeight};
						font-style: ${fontStyle};
						text-transform: ${fontTransform};
						font-size: ${desktopFontSize};
						line-height: ${desktopLineHeight};
						letter-spacing: ${desktopLetterSpacing};
					}`;

			css += `@media (max-width: 768px) {
						${selector} {
							font-size: ${tabletFontSize};
							line-height: ${tabletLineHeight};
							letter-spacing: ${tabletLetterSpacing};
						}
					}`;

			css += `@media (max-width: 600px) {
						${selector}{
							font-size: ${mobileFontSize};
							line-height:${mobileLineHeight};
							letter-spacing: ${mobileLetterSpacing};
						}
					}`;

			jQuery('head').append(link);

			return css;
		}

		return css;
	}

	function zakraGenerateDimensionCSS(selector, property, value) {
		let topCSS = value.top ? value.top : 0,
			rightCSS = value.right ? value.right : 0,
			bottomCSS = value.bottom ? value.bottom : 0,
			leftCSS = value.left ? value.left : 0,
			unit = value.unit ? value.unit : 'px';

		return `${selector}{ ${property} : ${topCSS + unit + ' ' + rightCSS + unit + ' ' + bottomCSS + unit + ' ' + leftCSS + unit}}`;
	}

	function zakraElementLayoutCSS(selector, property, value) {
		return `${selector} {${property}: ${value};}`;
	}

	function zakraAlignmentCSS(selector, property, value) {
		return `${selector} {${property}: ${value};}`;
	}

	wp.hooks.addFilter(
		'customind.dynamic.css',
		'customind',
		function (css, value, id) {
			switch (id) {
				case 'zakra_color_palette':
					css = zakraColorPalette(css, value);
					break;
				case 'zakra_base_color':
					css = zakraGenerateCommonCSS(
						'body, .woocommerce-ordering select, .woocommerce ul.products li.product .price, .woocommerce .star-rating span, ul li.product .price, .wc-block-components-formatted-money-amount, .wc-block-grid__products .wc-block-grid__product .wc-block-grid__product-price',
						'color',
						value,
					);
					break;

				case 'zakra_border_color':
					css = zakraGenerateCommonCSS(
						'.zak-header, .zak-post, .zak-secondary, .zak-footer-bar, .zak-primary-nav .sub-menu, .zak-primary-nav .sub-menu li, .posts-navigation, #comments, .post-navigation, blockquote, .wp-block-quote, .zak-posts .zak-post, .zak-content-area--boxed .widget',
						'border-color',
						value,
					);
					break;

				case 'zakra_link_color':
					css = zakraGenerateCommonCSS('.entry-content a', 'color', value);
					break;

				case 'zakra_link_hover_color':
					css = zakraGenerateCommonCSS(
						`.zak-entry-footer a:hover,
				.entry-button:hover,
				.zak-entry-footer a:hover,
				.entry-content a:hover,
				.pagebuilder-content a:hover, .pagebuilder-content a:hover`,
						'color',
						value,
					);
					css += zakraGenerateCommonCSS(
						`.entry-button:hover .zak-icon`,
						'fill',
						value,
					);
					break;

				case 'zakra_container_width':
					css = zakraGenerateSliderCSS(
						'.zak-container, .zak-container--boxed .zak-site',
						'max-width',
						value,
					);
					break;

				case 'zakra_inside_container_background':
					css = zakraGenerateBackgroundCSS('.zak-content', value);
					break;

				case 'zakra_outside_container_background':
					css = zakraGenerateBackgroundCSS('body', value);
					break;

				case 'zakra_content_area_padding':
					css = zakraGenerateSliderCSS(
						'.zak-primary, .zak-secondary',
						'padding-top',
						value,
					);
					css += zakraGenerateSliderCSS(
						'.zak-primary, .zak-secondary',
						'padding-bottom',
						value,
					);
					break;

				case 'zakra_sidebar_width':
					css = zakraGenerateSlidebarWidthCSS(
						'.zak-secondary',
						'.zak-primary',
						'width',
						value,
					);
					break;

				case 'zakra_root_font_size':
					css = zakraGenerateSliderCSS(':root', '--zak-root-font-size', value);
					break;

				case 'zakra_body_typography':
					css = zakraGenerateTypographyCSS(id, 'body', value);
					break;

				case 'zakra_heading_typography':
					css = zakraGenerateTypographyCSS(id, 'h1, h2, h3, h4, h5, h6', value);
					break;

				case 'zakra_h1_typography':
					css = zakraGenerateTypographyCSS(id, 'h1', value);
					break;

				case 'zakra_h2_typography':
					css = zakraGenerateTypographyCSS(id, 'h2', value);
					break;

				case 'zakra_h3_typography':
					css = zakraGenerateTypographyCSS(id, 'h3', value);
					break;

				case 'zakra_h4_typography':
					css = zakraGenerateTypographyCSS(id, 'h4', value);
					break;

				case 'zakra_h5_typography':
					css = zakraGenerateTypographyCSS(id, 'h5', value);
					break;

				case 'zakra_h6_typography':
					css = zakraGenerateTypographyCSS(id, 'h6', value);
					break;

				case 'zakra_button_color':
					css = zakraGenerateCommonCSS(
						`button, input[type="button"], input[type="reset"], input[type="submit"], #infinite-handle span, .wp-block-button .wp-block-button__link`,
						'color',
						value,
					);
					break;

				case 'zakra_button_typography':
					css = zakraGenerateTypographyCSS(
						id,
						`button, input[type="button"], input[type="reset"], input[type="submit"], .wp-block-button .wp-block-button__link`,
						value,
					);
					break;

				case 'zakra_button_hover_color':
					css = zakraGenerateCommonCSS(
						`button:hover, input[type="button"]:hover, input[type="reset"]:hover, input[type="submit"]:hover, #infinite-handle span:hover, .wp-block-button .wp-block-button__link:hover`,
						'color',
						value,
					);
					break;

				case 'zakra_button_background_color':
					css = zakraGenerateCommonCSS(
						`button, input[type="button"], input[type="reset"], input[type="submit"], #infinite-handle span, .wp-block-button .wp-block-button__link`,
						'background-color',
						value,
					);
					break;

				case 'zakra_button_background_hover_color':
					css = zakraGenerateCommonCSS(
						`button:hover, input[type="button"]:hover, input[type="reset"]:hover, input[type="submit"]:hover, #infinite-handle span:hover, .wp-block-button .wp-block-button__link:hover`,
						'background-color',
						value,
					);
					break;

				case 'zakra_button_padding':
					css = zakraGenerateDimensionCSS(
						`button, input[type="button"], input[type="reset"], input[type="submit"], #infinite-handle span, .wp-block-button .wp-block-button__link`,
						'padding',
						value,
					);
					break;

				case 'zakra_button_border_radius':
					css = zakraGenerateSliderCSS(
						`button, input[type="button"], input[type="reset"], input[type="submit"], #infinite-handle span, .wp-block-button .wp-block-button__link`,
						'border-radius',
						value,
					);
					break;

				case 'zakra_top_bar_color':
					css = zakraGenerateCommonCSS(
						'.zak-header .zak-top-bar',
						'color',
						value,
					);
					break;

				case 'zakra_top_bar_background':
					css = zakraGenerateBackgroundCSS('.zak-header .zak-top-bar', value);
					break;

				case 'zakra_main_header_background_color':
					css = zakraGenerateBackgroundCSS(
						'.zak-header .zak-main-header',
						value,
					);
					break;

				case 'zakra_primary_menu_border_bottom_width':
					css = zakraGenerateSliderCSS(
						'.zak-header .main-navigation',
						'border-bottom-width',
						value,
					);
					break;

				case 'zakra_primary_menu_border_bottom_color':
					css = zakraGenerateCommonCSS(
						'.zak-header .main-navigation',
						'border-bottom-color',
						value,
					);
					break;

				case 'zakra_main_menu_color':
					css = zakraGenerateCommonCSS(
						'.zak-primary-nav ul li > a, .zak-main-nav.zak-primary-nav ul.zak-primary-menu > li > a, .zak-primary-nav.zak-menu-item--layout-2 > ul > li > a',
						'color',
						value,
					);
					css += zakraGenerateCommonCSS(
						'.zak-primary-nav ul li > a .zak-icon, zak-main-nav.zak-primary-nav ul.zak-primary-menu li .zak-icon, .zak-primary-nav.zak-menu-item--layout-2 > ul > li > .zak-icon',
						'fill',
						value,
					);
					break;

				case 'zakra_main_menu_hover_color':
					css = zakraGenerateCommonCSS(
						`.zak-primary-nav ul li:hover > a, .zak-primary-nav.zak-menu-item--layout-2 > ul > li:hover > a, .zak-primary-nav ul li:hover > a, .zak-main-nav.zak-primary-nav ul.zak-primary-menu li:hover > a`,
						'color',
						value,
					);
					css += zakraGenerateCommonCSS(
						`.zak-primary-nav ul li:hover > .zak-icon, .zak-primary-nav.zak-menu-item--layout-2 > ul > li:hover > .zak-icon`,
						'fill',
						value,
					);
					break;

				case 'zakra_main_menu_active_color':
					css = zakraGenerateCommonCSS(
						`.zak-primary-nav ul li:active > a, .zak-primary-nav ul > li:not(.zak-header-button).current_page_item > a, .zak-primary-nav ul > li:not(.zak-header-button).current_page_ancestor > a, .zak-primary-nav ul > li:not(.zak-header-button).current-menu-item > a, .zak-primary-nav ul > li:not(.zak-header-button).current-menu-ancestor > a`,
						'color',
						value,
					);
					css += zakraGenerateCommonCSS(
						`.zak-primary-nav.zak-layout-1-style-2 ul > li:not(.zak-header-button).current_page_item > a::before, .zak-primary-nav.zak-layout-1-style-2 ul > li:not(.zak-header-button).current_page_ancestor > a::before, .zak-primary-nav.zak-layout-1-style-2 ul > li:not(.zak-header-button).current-menu-item > a::before, .zak-primary-nav.zak-layout-1-style-2 ul > li:not(.zak-header-button).current-menu-ancestor > a::before, .zak-primary-nav.zak-layout-1-style-3 ul > li:not(.zak-header-button).current_page_item > a::before, .zak-primary-nav.zak-layout-1-style-3 ul > li:not(.zak-header-button).current_page_ancestor > a::before, .zak-primary-nav.zak-layout-1-style-3 ul > li:not(.zak-header-button).current-menu-item > a::before, .zak-primary-nav.zak-layout-1-style-3 ul > li:not(.zak-header-button).current-menu-ancestor > a::before, .zak-primary-nav.zak-layout-1-style-4 ul > li:not(.zak-header-button).current_page_item > a::before, .zak-primary-nav.zak-layout-1-style-4 ul > li:not(.zak-header-button).current_page_ancestor > a::before, .zak-primary-nav.zak-layout-1-style-4 ul > li:not(.zak-header-button).current-menu-item > a::before, .zak-primary-nav.zak-layout-1-style-4 ul > li:not(.zak-header-button).current-menu-ancestor > a::before`,
						'background-color',
						value,
					);
					css += zakraGenerateCommonCSS(
						`.zak-primary-nav ul li:hover > .zak-icon, .zak-primary-nav.zak-menu-item--layout-2 > ul > li span`,
						'fill',
						value,
					);
					break;

				case 'zakra_main_menu_typography':
					css = zakraGenerateTypographyCSS(
						id,
						'.zak-primary-nav ul li a',
						value,
					);
					break;

				case 'zakra_sub_menu_typography':
					css = zakraGenerateTypographyCSS(
						id,
						'.zak-primary-nav ul li ul li a',
						value,
					);
					break;

				case 'zakra_mobile_menu_typography':
					css = zakraGenerateTypographyCSS(id, '.zak-mobile-menu a', value);
					break;

				case 'zakra_page_header_background':
					css = zakraGenerateBackgroundCSS(
						'.zak-page-header, .zak-container--separate .zak-page-header',
						value,
					);
					break;

				case 'zakra_page_header_padding':
					css = zakraGenerateDimensionCSS(
						'.has-page-header .zak-page-header',
						'padding',
						value,
					);
					break;

				case 'zakra_post_page_title_color':
					css = zakraGenerateCommonCSS(
						'.zak-page-header .zak-page-title, .zakra-single-article .zak-entry-header .entry-title',
						'color',
						value,
					);
					break;

				case 'zakra_post_page_title_typography':
					css = zakraGenerateTypographyCSS(
						id,
						'.zak-page-header .zak-page-title, .zakra-single-article .zak-entry-header .entry-title',
						value,
					);
					break;

				case 'zakra_breadcrumb_typography':
					css = zakraGenerateTypographyCSS(
						id,
						'.zak-page-header .breadcrumb-trail ul li',
						value,
					);
					break;

				case 'zakra_breadcrumbs_text_color':
					css = zakraGenerateCommonCSS(
						'.zak-page-header .breadcrumb-trail ul li',
						'color',
						value,
					);
					break;

				case 'zakra_breadcrumb_separator_color':
					css = zakraGenerateCommonCSS(
						'.zak-page-header .breadcrumb-trail ul li::after',
						'color',
						value,
					);
					break;

				case 'zakra_breadcrumbs_link_color':
					css = zakraGenerateCommonCSS(
						'.zak-page-header .breadcrumb-trail ul li a',
						'color',
						value,
					);
					break;

				case 'zakra_breadcrumbs_link_hover_color':
					css = zakraGenerateCommonCSS(
						'.zak-page-header .breadcrumb-trail ul li a:hover',
						'color',
						value,
					);
					break;

				case 'zakra_blog_post_title_typography':
					css = zakraGenerateTypographyCSS(
						id,
						'.entry-title:not(.zak-page-title)',
						value,
					);
					break;

				case 'zakra_widget_title_typography':
					css = zakraGenerateTypographyCSS(
						id,
						'.zak-secondary .widget .widget-title, .zak-secondary .widget .wp-block-heading',
						value,
					);
					break;

				case 'zakra_widget_content_typography':
					css = zakraGenerateTypographyCSS(
						id,
						'.zak-secondary .widget, .zak-secondary .widget li a',
						value,
					);
					break;

				case 'zakra_footer_column_background':
					css = zakraGenerateBackgroundCSS('.zak-footer-cols', value);
					break;

				case 'zakra_footer_column_border_top_width':
					css = zakraGenerateSliderCSS(
						'.zak-footer-cols',
						'border-top-width',
						value,
					);
					break;

				case 'zakra_footer_column_border_top_color':
					css = zakraGenerateCommonCSS(
						'.zak-footer-cols',
						'border-top-color',
						value,
					);
					break;

				case 'zakra_footer_column_widget_text_color':
					css = zakraGenerateCommonCSS(
						'.zak-footer .zak-footer-cols, .zak-footer .zak-footer-cols p',
						'color',
						value,
					);
					break;

				case 'zakra_footer_column_widget_link_color':
					css = zakraGenerateCommonCSS(
						'.zak-footer .zak-footer-cols a, .zak-footer-col .widget ul a',
						'color',
						value,
					);
					break;

				case 'zakra_footer_column_widget_link_hover_color':
					css = zakraGenerateCommonCSS(
						'.zak-footer .zak-footer-cols a:hover, .zak-footer-col .widget ul a:hover, .zak-footer .zak-footer-cols a:focus',
						'color',
						value,
					);
					break;

				case 'zakra_footer_widgets_title_color':
					css = zakraGenerateCommonCSS(
						'.zak-footer .zak-footer-cols .widget-title, .zak-footer-cols h1, .zak-footer-cols h2, .zak-footer-cols h3, .zak-footer-cols h4, .zak-footer-cols h5, .zak-footer-cols h6',
						'color',
						value,
					);
					break;

				case 'zakra_footer_widgets_item_border_bottom_width':
					css = zakraGenerateSliderCSS(
						'.zak-footer-cols ul li, .zak-footer-builder .zak-footer-main-row ul li',
						'border-bottom-width',
						value,
					);
					break;

				case 'zakra_footer_widgets_item_border_bottom_color':
					css = zakraGenerateCommonCSS(
						'.zak-footer-cols ul li, .zak-footer-builder .zak-footer-main-row ul li',
						'border-bottom-color',
						value,
					);
					break;

				case 'zakra_footer_bar_background':
					css = zakraGenerateBackgroundCSS('.zak-footer-bar', value);
					break;

				case 'zakra_footer_bar_border_top_width':
					css = zakraGenerateSliderCSS(
						'.zak-footer-bar',
						'border-top-width',
						value,
					);
					break;

				case 'zakra_footer_bar_border_top_color':
					css = zakraGenerateCommonCSS(
						'.zak-footer-bar',
						'border-top-color',
						value,
					);
					break;

				case 'zakra_footer_bar_text_color':
					css = zakraGenerateCommonCSS('.zak-footer-bar', 'color', value);
					break;

				case 'zakra_footer_bar_link_color':
					css = zakraGenerateCommonCSS('.zak-footer-bar a', 'color', value);
					break;

				case 'zakra_footer_bar_link_hover_color':
					css = zakraGenerateCommonCSS(
						'.zak-footer-bar a:hover, .zak-footer-bar a:focus',
						'color',
						value,
					);
					break;

				case 'zakra_scroll_to_top_background':
					css = zakraGenerateCommonCSS(
						'.zak-scroll-to-top',
						'background-color',
						value,
					);
					break;

				case 'zakra_scroll_to_top_hover_background':
					css = zakraGenerateCommonCSS(
						'.zak-scroll-to-top:hover',
						'background-color',
						value,
					);
					break;

				case 'zakra_scroll_to_top_icon_color':
					css = zakraGenerateCommonCSS('.zak-scroll-to-top', 'color', value);
					css += zakraGenerateCommonCSS(
						'.zak-scroll-to-top .zak-icon',
						'fill',
						value,
					);
					break;

				case 'zakra_scroll_to_top_icon_hover_color':
					css = zakraGenerateCommonCSS(
						'.zak-scroll-to-top:hover',
						'color',
						value,
					);
					css += zakraGenerateCommonCSS(
						'.zak-scroll-to-top:hover .zak-icon',
						'fill',
						value,
					);
					break;

				// Builder options.
				case 'zakra_header_top_area_height':
					css = zakraGenerateSliderCSS(
						'.zak-header-builder .zak-top-row',
						'height',
						value,
					);
					break;

				case 'zakra_header_top_area_container':
					css = zakraGenerateSliderCSS(
						'.zak-header-builder .zak-header-top-row .zak-container',
						'max-width',
						value,
					);
					break;

				case 'zakra_header_top_area_background':
					css = zakraGenerateBackgroundCSS(
						'.zak-header-builder .zak-header-top-row',
						value,
					);
					break;

				case 'zakra_header_top_area_padding':
					css = zakraGenerateDimensionCSS(
						'.zak-header-builder .zak-header-top-row',
						'padding',
						value,
					);
					break;

				case 'zakra_header_top_area_border_width':
					css = zakraGenerateDimensionCSS(
						'.zak-header-builder .zak-header-top-row',
						'border-width',
						value,
					);
					css += zakraGenerateCommonCSS(
						'.zak-header-builder .zak-header-top-row',
						'border-style',
						'solid',
					);
					break;

				case 'zakra_header_top_area_border_color':
					css = zakraGenerateCommonCSS(
						'.zak-header-builder .zak-header-top-row',
						'border-color',
						value,
					);
					break;

				case 'zakra_header_top_area_color':
					css = zakraGenerateCommonCSS(
						'.zak-header-builder .zak-header-top-row',
						'color',
						value,
					);
					break;

				case 'zakra_header_main_area_height':
					css = zakraGenerateSliderCSS(
						'.zak-header-builder .zak-main-row',
						'height',
						value,
					);
					break;

				case 'zakra_header_main_area_container':
					css = zakraGenerateSliderCSS(
						'.zak-header-builder .zak-header-main-row .zak-container',
						'max-width',
						value,
					);
					break;

				case 'zakra_header_main_area_background':
					css = zakraGenerateBackgroundCSS(
						'.zak-header-builder .zak-header-main-row',
						value,
					);
					break;

				case 'zakra_header_main_area_padding':
					css = zakraGenerateDimensionCSS(
						'.zak-header-builder .zak-header-main-row',
						'padding',
						value,
					);
					break;

				case 'zakra_header_main_area_margin':
					css = zakraGenerateDimensionCSS(
						'.zak-header-builder .zak-header-main-row',
						'margin',
						value,
					);
					break;

				case 'zakra_header_main_area_border_width':
					css = zakraGenerateDimensionCSS(
						'.zak-header-builder .zak-header-main-row',
						'border-width',
						value,
					);
					css += zakraGenerateCommonCSS(
						'.zak-header-builder .zak-header-main-row',
						'border-style',
						'solid',
					);
					break;

				case 'zakra_header_main_area_border_color':
					css = zakraGenerateCommonCSS(
						'.zak-header-builder .zak-header-main-row',
						'border-color',
						value,
					);
					break;

				case 'zakra_header_main_area_color':
					css = zakraGenerateCommonCSS(
						'.zak-header-builder .zak-header-main-row',
						'color',
						value,
					);
					break;

				case 'zakra_header_bottom_area_height':
					css = zakraGenerateSliderCSS(
						'.zak-header-builder .zak-bottom-row',
						'height',
						value,
					);
					break;

				case 'zakra_header_bottom_area_container':
					css = zakraGenerateSliderCSS(
						'.zak-header-builder .zak-header-bottom-row .zak-container',
						'max-width',
						value,
					);
					break;

				case 'zakra_header_bottom_area_background':
					css = zakraGenerateBackgroundCSS(
						'.zak-header-builder .zak-header-bottom-row',
						value,
					);
					break;

				case 'zakra_header_bottom_area_padding':
					css = zakraGenerateDimensionCSS(
						'.zak-header-builder .zak-header-bottom-row',
						'padding',
						value,
					);
					break;

				case 'zakra_header_bottom_area_margin':
					css = zakraGenerateDimensionCSS(
						'.zak-header-builder .zak-header-bottom-row',
						'margin',
						value,
					);
					break;

				case 'zakra_header_bottom_area_border_width':
					css = zakraGenerateDimensionCSS(
						'.zak-header-builder .zak-header-bottom-row',
						'border-width',
						value,
					);
					css += zakraGenerateCommonCSS(
						'.zak-header-builder .zak-header-bottom-row',
						'border-style',
						'solid',
					);
					break;

				case 'zakra_header_bottom_area_border_color':
					css = zakraGenerateCommonCSS(
						'.zak-header-builder .zak-header-bottom-row',
						'border-color',
						value,
					);
					break;

				case 'zakra_header_bottom_area_color':
					css = zakraGenerateCommonCSS(
						'.zak-header-builder .zak-header-bottom-row',
						'color',
						value,
					);
					break;

				case 'zakra_header_menu_border_bottom_width':
					css = zakraGenerateSliderCSS(
						'.zak-header-builder .zak-main-nav',
						'border-bottom-width',
						value,
					);
					break;

				case 'zakra_header_secondary_menu_border_bottom_width':
					css = zakraGenerateSliderCSS(
						'.zak-header-builder .zak-secondary-nav',
						'border-bottom-width',
						value,
					);
					break;

				case 'zakra_header_tertiary_menu_border_bottom_width':
					css = zakraGenerateSliderCSS(
						'.zak-header-builder .zak-tertiary-nav',
						'border-bottom-width',
						value,
					);
					break;

				case 'zakra_header_menu_border_bottom_color':
					css = zakraGenerateCommonCSS(
						'.zak-header-builder .zak-main-nav',
						'border-color',
						value,
					);
					break;

				case 'zakra_header_secondary_menu_border_bottom_color':
					css = zakraGenerateCommonCSS(
						'.zak-header-builder .zak-secondary-nav',
						'border-color',
						value,
					);
					break;

				case 'zakra_header_tertiary_menu_border_bottom_color':
					css = zakraGenerateCommonCSS(
						'.zak-header-builder .zak-tertiary-nav',
						'border-color',
						value,
					);
					break;

				case 'zakra_header_main_menu_color':
					css = zakraGenerateCommonCSS(
						'.zak-header-builder .zak-primary-nav ul li > a, .zak-header-builder .zak-main-nav.zak-primary-nav ul.zak-primary-menu > li > a, .zak-header-builder .zak-primary-nav.zak-menu-item--layout-2 > ul > li > a',
						'color',
						value,
					);
					css += zakraGenerateCommonCSS(
						'.zak-header-builder .zak-primary-nav ul li > a .zak-icon, .zak-header-builder .zak-main-nav.zak-primary-nav ul.zak-primary-menu li .zak-icon, .zak-header-builder .zak-primary-nav.zak-menu-item--layout-2 > ul > li > .zak-icon',
						'fill',
						value,
					);
					break;

				case 'zakra_header_secondary_menu_color':
					css = zakraGenerateCommonCSS(
						'.zak-header-builder .zak-secondary-nav ul li > a, .zak-header-builder .zak-main-nav.zak-secondary-nav ul.zak-secondary-menu > li > a, .zak-header-builder .zak-secondary-nav.zak-menu-item--layout-2 > ul > li > a',
						'color',
						value,
					);
					css += zakraGenerateCommonCSS(
						'.zak-header-builder .zak-secondary-nav ul li > a .zak-icon, .zak-header-builder .zak-main-nav.zak-secondary-nav ul.zak-secondary-menu li .zak-icon, .zak-header-builder .zak-secondary-nav.zak-menu-item--layout-2 > ul > li > .zak-icon',
						'fill',
						value,
					);
					break;

				case 'zakra_header_tertiary_menu_color':
					css = zakraGenerateCommonCSS(
						'.zak-header-builder .zak-tertiary-nav ul li > a, .zak-header-builder .zak-main-nav.zak-tertiary-nav ul.zak-tertiary-menu > li > a, .zak-header-builder .zak-tertiary-nav.zak-menu-item--layout-2 > ul > li > a',
						'color',
						value,
					);
					css += zakraGenerateCommonCSS(
						'.zak-header-builder .zak-tertiary-nav ul li > a .zak-icon, .zak-header-builder .zak-main-nav.zak-tertiary-nav ul.zak-tertiary-menu li .zak-icon, .zak-header-builder .zak-tertiary-nav.zak-menu-item--layout-2 > ul > li > .zak-icon',
						'fill',
						value,
					);
					break;

				case 'zakra_header_quaternary_menu_color':
					css = zakraGenerateCommonCSS(
						'.zak-header-builder .zak-quaternary-nav ul li > a, .zak-header-builder .zak-main-nav.zak-quaternary-nav ul.zak-quaternary-menu > li > a, .zak-header-builder .zak-quaternary-nav.zak-menu-item--layout-2 > ul > li > a',
						'color',
						value,
					);
					css += zakraGenerateCommonCSS(
						'.zak-header-builder .zak-quaternary-nav ul li > a .zak-icon, .zak-header-builder .zak-main-nav.zak-quaternary-nav ul.zak-quaternary-menu li .zak-icon, .zak-header-builder .zak-quaternary-nav.zak-menu-item--layout-2 > ul > li > .zak-icon',
						'fill',
						value,
					);
					break;

				case 'zakra_header_main_menu_hover_color':
					css = zakraGenerateCommonCSS(
						'.zak-header-builder .zak-primary-nav ul li:hover > a, .zak-header-builder .zak-primary-nav.zak-menu-item--layout-2 > ul > li:hover > a, .zak-header-builder .zak-primary-nav ul li:hover > a, .zak-header-builder .zak-main-nav.zak-primary-nav ul.zak-primary-menu li:hover > a',
						'color',
						value,
					);
					css += zakraGenerateCommonCSS(
						'.zak-header-builder .zak-primary-nav ul li:hover > .zak-icon, .zak-header-builder .zak-primary-nav.zak-menu-item--layout-2 > ul > li:hover > .zak-icon',
						'fill',
						value,
					);
					break;

				case 'zakra_header_secondary_menu_hover_color':
					css = zakraGenerateCommonCSS(
						'.zak-header-builder .zak-secondary-nav ul li:hover > a, .zak-header-builder .zak-secondary-nav.zak-menu-item--layout-2 > ul > li:hover > a, .zak-header-builder .zak-secondary-nav ul li:hover > a, .zak-header-builder .zak-main-nav.zak-secondary-nav ul.zak-secondary-menu li:hover > a',
						'color',
						value,
					);
					css += zakraGenerateCommonCSS(
						'.zak-header-builder .zak-secondary-nav ul li:hover > .zak-icon, .zak-header-builder .zak-secondary-nav.zak-menu-item--layout-2 > ul > li:hover > .zak-icon',
						'fill',
						value,
					);
					break;

				case 'zakra_header_tertiary_menu_hover_color':
					css = zakraGenerateCommonCSS(
						'.zak-header-builder .zak-tertiary-nav ul li:hover > a, .zak-header-builder .zak-tertiary-nav.zak-menu-item--layout-2 > ul > li:hover > a, .zak-header-builder .zak-tertiary-nav ul li:hover > a, .zak-header-builder .zak-main-nav.zak-tertiary-nav ul.zak-tertiary-menu li:hover > a',
						'color',
						value,
					);
					css += zakraGenerateCommonCSS(
						'.zak-header-builder .zak-tertiary-nav ul li:hover > .zak-icon, .zak-header-builder .zak-tertiary-nav.zak-menu-item--layout-2 > ul > li:hover > .zak-icon',
						'fill',
						value,
					);
					break;

				case 'zakra_header_quaternary_menu_hover_color':
					css = zakraGenerateCommonCSS(
						'.zak-header-builder .zak-quaternary-nav ul li:hover > a, .zak-header-builder .zak-quaternary-nav.zak-menu-item--layout-2 > ul > li:hover > a, .zak-header-builder .zak-quaternary-nav ul li:hover > a, .zak-header-builder .zak-main-nav.zak-quaternary-nav ul.zak-quaternary-menu li:hover > a',
						'color',
						value,
					);
					css += zakraGenerateCommonCSS(
						'.zak-header-builder .zak-quaternary-nav ul li:hover > .zak-icon, .zak-header-builder .zak-quaternary-nav.zak-menu-item--layout-2 > ul > li:hover > .zak-icon',
						'fill',
						value,
						value,
					);
					break;

				case 'zakra_header_main_menu_active_color':
					css = zakraGenerateCommonCSS(
						'.zak-header-builder .zak-primary-nav ul li:active > a, .zak-header-builder .zak-primary-nav ul > li:not(.zak-header-button).current_page_item > a, .zak-header-builder .zak-primary-nav ul > li:not(.zak-header-button).current_page_ancestor > a, .zak-header-builder .zak-primary-nav ul > li:not(.zak-header-button).current-menu-item > a, .zak-header-builder .zak-primary-nav ul > li:not(.zak-header-button).current-menu-ancestor > a',
						'color',
						value,
					);
					css += zakraGenerateCommonCSS(
						'.zak-header-builder .zak-primary-nav ul li.current-menu-item > a .zak-icon, .zak-header-builder .zak-main-nav.zak-primary-nav ul.zak-primary-menu li.current-menu-item .zak-icon',
						'fill',
						value,
					);
					break;

				case 'zakra_header_secondary_menu_active_color':
					css = zakraGenerateCommonCSS(
						'.zak-header-builder .zak-secondary-nav ul li:active > a, .zak-header-builder .zak-secondary-nav ul > li:not(.zak-header-button).current_page_item > a, .zak-header-builder .zak-secondary-nav ul > li:not(.zak-header-button).current_page_ancestor > a, .zak-header-builder .zak-secondary-nav ul > li:not(.zak-header-button).current-menu-item > a, .zak-header-builder .zak-secondary-nav ul > li:not(.zak-header-button).current-menu-ancestor > a',
						'color',
						value,
					);
					css += zakraGenerateCommonCSS(
						'.zak-header-builder .zak-secondary-nav ul li.current-menu-item > a .zak-icon, .zak-header-builder .zak-main-nav.zak-secondary-nav ul.zak-secondary-menu li.current-menu-item .zak-icon',
						'fill',
						value,
					);
					break;

				case 'zakra_header_tertiary_menu_active_color':
					css = zakraGenerateCommonCSS(
						'.zak-header-builder .zak-tertiary-nav ul li:active > a, .zak-header-builder .zak-tertiary-nav ul > li:not(.zak-header-button).current_page_item > a, .zak-header-builder .zak-tertiary-nav ul > li:not(.zak-header-button).current_page_ancestor > a, .zak-header-builder .zak-tertiary-nav ul > li:not(.zak-header-button).current-menu-item > a, .zak-header-builder .zak-tertiary-nav ul > li:not(.zak-header-button).current-menu-ancestor > a',
						'color',
						value,
					);
					css += zakraGenerateCommonCSS(
						'.zak-header-builder .zak-tertiary-nav ul li.current-menu-item > a .zak-icon, .zak-header-builder .zak-main-nav.zak-tertiary-nav ul.zak-tertiary-menu li.current-menu-item .zak-icon',
						'fill',
						value,
					);
					break;

				case 'zakra_header_quaternary_menu_active_color':
					css = zakraGenerateCommonCSS(
						'.zak-header-builder .zak-quaternary-nav ul li:active > a, .zak-header-builder .zak-quaternary-nav ul > li:not(.zak-header-button).current_page_item > a, .zak-header-builder .zak-quaternary-nav ul > li:not(.zak-header-button).current_page_ancestor > a, .zak-header-builder .zak-quaternary-nav ul > li:not(.zak-header-button).current-menu-item > a, .zak-header-builder .zak-quaternary-nav ul > li:not(.zak-header-button).current-menu-ancestor > a',
						'color',
						value,
					);
					css += zakraGenerateCommonCSS(
						'.zak-header-builder .zak-quaternary-nav ul li.current-menu-item > a .zak-icon, .zak-header-builder .zak-main-nav.zak-quaternary-nav ul.zak-quaternary-menu li.current-menu-item .zak-icon',
						'fill',
						value,
					);
					break;

				case 'zakra_header_main_menu_typography':
					css = zakraGenerateTypographyCSS(
						id,
						'.zak-header-builder .zak-primary-nav ul li a',
						value,
					);
					break;

				case 'zakra_header_secondary_menu_typography':
					css = zakraGenerateTypographyCSS(
						id,
						'.zak-header-builder .zak-secondary-nav ul li a',
						value,
					);
					break;

				case 'zakra_header_tertiary_menu_typography':
					css = zakraGenerateTypographyCSS(
						id,
						'.zak-header-builder .zak-tertiary-nav ul li a',
						value,
					);
					break;

				case 'zakra_header_quaternary_menu_typography':
					css = zakraGenerateTypographyCSS(
						id,
						'.zak-header-builder .zak-quaternary-nav ul li a',
						value,
					);
					break;

				case 'zakra_header_sub_menu_typography':
					css = zakraGenerateTypographyCSS(
						id,
						'.zak-header-builder .zak-primary-nav ul li ul li a',
						value,
					);
					break;

				case 'zakra_header_secondary_sub_menu_typography':
					css = zakraGenerateTypographyCSS(
						id,
						'.zak-header-builder .zak-secondary-nav ul li ul li a',
						value,
					);
					break;

				case 'zakra_header_search_icon_color':
					css = zakraGenerateCommonCSS(
						'.zak-header-builder .zak-header-search .zak-icon',
						'fill',
						value,
					);
					break;

				case 'zakra_header_search_background':
					css = zakraGenerateBackgroundCSS(
						'.zak-header-builder .zak-main-header.zak-header-search--opened',
						value,
					);
					break;

				case 'zakra_header_search_text_color':
					css = zakraGenerateCommonCSS(
						'.zak-header-builder .zak-header-search .zak-search-field, .zak-header-builder .zak-header-search .zak-search-field:focus',
						'color',
						value,
					);
					css += zakraGenerateCommonCSS(
						'.zak-header-builder .zak-header-search .zak-icon--close::after , .zak-header-builder .zak-header-search .zak-icon--close::before',
						'background',
						value,
					);
					css += zakraGenerateCommonCSS(
						'.zak-header-builder .zak-header-search .zak-icon--search .zak-icon',
						'fill',
						value,
					);
					break;

				case 'zakra_header_button_color':
					css = zakraGenerateCommonCSS(
						'.zak-header-builder .zak-header-buttons .zak-header-button .zak-button',
						'color',
						value,
					);
					break;

				case 'zakra_header_button_hover_color':
					css = zakraGenerateCommonCSS(
						'.zak-header-builder .zak-header-buttons .zak-header-button .zak-button:hover',
						'color',
						value,
					);
					break;

				case 'zakra_header_button_background_color':
					css = zakraGenerateCommonCSS(
						'.zak-header-builder .zak-header-buttons .zak-header-button .zak-button',
						'background-color',
						value,
					);
					break;

				case 'zakra_header_button_background_hover_color':
					css = zakraGenerateCommonCSS(
						'.zak-header-builder .zak-header-buttons .zak-header-button .zak-button:hover',
						'background-color',
						value,
					);
					break;

				case 'zakra_header_button_padding':
					css = zakraGenerateDimensionCSS(
						'.zak-header-builder .zak-header-buttons .zak-header-button .zak-button',
						'padding',
						value,
					);
					break;

				case 'zakra_header_button_typography':
					css = zakraGenerateTypographyCSS(
						id,
						`.zak-header-builder .zak-header-buttons .zak-header-button .zak-button`,
						value,
					);
					break;

				case 'zakra_header_button_border_radius':
					css = zakraGenerateSliderCSS(
						'.zak-header-builder .zak-header-buttons .zak-header-button .zak-button',
						'border-radius',
						value,
					);
					break;

				case 'zakra_header_button_border_width':
					css = zakraGenerateDimensionCSS(
						'.zak-header-builder .zak-header-buttons .zak-header-button .zak-button',
						'border-width',
						value,
					);
					break;

				case 'zakra_header_button_border_color':
					css = zakraGenerateCommonCSS(
						'.zak-header-builder .zak-header-buttons .zak-header-button .zak-button',
						'border-color',
						value,
					);
					break;

				case 'zakra_header_html_1_text_color':
					css = zakraGenerateCommonCSS(
						'.zak-header-builder .zak-html-1 *',
						'color',
						value,
					);
					break;

				case 'zakra_header_html_1_link_color':
					css = zakraGenerateCommonCSS(
						'.zak-header-builder .zak-html-1 a',
						'color',
						value,
					);
					break;

				case 'zakra_header_html_1_link_hover_color':
					css = zakraGenerateCommonCSS(
						'.zak-header-builder .zak-html-1 a:hover',
						'color',
						value,
					);
					break;

				case 'zakra_header_html_1_font_size':
					css = zakraGenerateSliderCSS(
						'.zak-header-builder .zak-html-1 *',
						'font-size',
						value,
					);
					break;

				case 'zakra_header_html_1_margin':
					css = zakraGenerateDimensionCSS(
						'.zak-header-builder .zak-html-1',
						'margin',
						value,
					);
					break;

				case 'zakra_header_html_2_text_color':
					css = zakraGenerateCommonCSS(
						'.zak-header-builder .zak-html-2 *',
						'color',
						value,
					);
					break;

				case 'zakra_header_html_2_link_color':
					css = zakraGenerateCommonCSS(
						'.zak-header-builder .zak-html-2 a',
						'color',
						value,
					);
					break;

				case 'zakra_header_html_2_link_hover_color':
					css = zakraGenerateCommonCSS(
						'.zak-header-builder .zak-html-2 a:hover',
						'color',
						value,
					);
					break;

				case 'zakra_header_html_2_font_size':
					css = zakraGenerateSliderCSS(
						'.zak-header-builder .zak-html-2 p',
						'font-size',
						value,
					);
					break;

				case 'zakra_header_html_2_margin':
					css = zakraGenerateDimensionCSS(
						'.zak-header-builder .zak-html-2',
						'margin',
						value,
					);
					break;

				case 'zakra_footer_top_area_height':
					css = zakraGenerateSliderCSS(
						'.zak-footer-builder .zak-top-row',
						'height',
						value,
					);
					break;

				case 'zakra_footer_top_area_container':
					css = zakraGenerateSliderCSS(
						'.zak-footer-builder .zak-footer-top-row .zak-container',
						'max-width',
						value,
					);
					break;

				case 'zakra_footer_top_area_background':
					css = zakraGenerateBackgroundCSS(
						'.zak-footer-builder .zak-footer-top-row',
						value,
					);
					break;

				case 'zakra_footer_top_area_padding':
					css = zakraGenerateDimensionCSS(
						'.zak-footer-builder .zak-footer-top-row',
						'padding',
						value,
					);
					break;

				case 'zakra_footer_top_area_margin':
					css = zakraGenerateDimensionCSS(
						'.zak-footer-builder .zak-footer-top-row',
						'margin',
						value,
					);
					break;

				case 'zakra_footer_top_area_border_width':
					css = zakraGenerateDimensionCSS(
						'.zak-footer-builder .zak-footer-top-row',
						'border-width',
						value,
					);
					css += zakraGenerateCommonCSS(
						'.zak-footer-builder .zak-footer-top-row',
						'border-style',
						'solid',
					);
					break;

				case 'zakra_footer_top_area_border_color':
					css = zakraGenerateCommonCSS(
						'.zak-footer-builder .zak-footer-top-row',
						'border-color',
						value,
					);
					break;

				case 'zakra_footer_main_area_height':
					css = zakraGenerateSliderCSS(
						'.zak-footer-builder .zak-main-row',
						'height',
						value,
					);
					break;

				case 'zakra_footer_main_area_container':
					css = zakraGenerateSliderCSS(
						'.zak-footer-builder .zak-footer-main-row .zak-container',
						'max-width',
						value,
					);
					break;

				case 'zakra_footer_main_area_background':
					css = zakraGenerateBackgroundCSS(
						'.zak-footer-builder .zak-footer-main-row',
						value,
					);
					break;

				case 'zakra_footer_main_area_padding':
					css = zakraGenerateDimensionCSS(
						'.zak-footer-builder .zak-footer-main-row',
						'padding',
						value,
					);
					break;

				case 'zakra_footer_main_area_margin':
					css = zakraGenerateDimensionCSS(
						'.zak-footer-builder .zak-footer-main-row',
						'margin',
						value,
					);
					break;

				case 'zakra_footer_main_area_border_width':
					css = zakraGenerateDimensionCSS(
						'.zak-footer-builder .zak-footer-main-row',
						'border-width',
						value,
					);
					css += zakraGenerateCommonCSS(
						'.zak-footer-builder .zak-footer-main-row',
						'border-style',
						'solid',
					);
					break;

				case 'zakra_footer_main_area_border_color':
					css = zakraGenerateCommonCSS(
						'.zak-footer-builder .zak-footer-main-row',
						'border-color',
						value,
					);
					break;

				case 'zakra_footer_bottom_area_height':
					css = zakraGenerateSliderCSS(
						'.zak-footer-builder .zak-bottom-row',
						'height',
						value,
					);
					break;

				case 'zakra_footer_bottom_area_container':
					css = zakraGenerateSliderCSS(
						'.zak-footer-builder .zak-footer-bottom-row .zak-container',
						'max-width',
						value,
					);
					break;

				case 'zakra_footer_bottom_area_background':
					css = zakraGenerateBackgroundCSS(
						'.zak-footer-builder .zak-footer-bottom-row',
						value,
					);
					break;

				case 'zakra_footer_bottom_area_padding':
					css = zakraGenerateDimensionCSS(
						'.zak-footer-builder .zak-footer-bottom-row',
						'padding',
						value,
					);
					break;

				case 'zakra_footer_bottom_area_margin':
					css = zakraGenerateDimensionCSS(
						'.zak-footer-builder .zak-footer-bottom-row',
						'margin',
						value,
					);
					break;

				case 'zakra_footer_bottom_area_border_width':
					css = zakraGenerateDimensionCSS(
						'.zak-footer-builder .zak-footer-bottom-row',
						'border-width',
						value,
					);
					css += zakraGenerateCommonCSS(
						'.zak-footer-builder .zak-footer-bottom-row',
						'border-style',
						'solid',
					);
					break;

				case 'zakra_footer_bottom_area_border_color':
					css = zakraGenerateCommonCSS(
						'.zak-footer-builder .zak-footer-bottom-row',
						'border-color',
						value,
					);
					break;

				case 'zakra_site_logo_height':
					css = zakraGenerateSliderCSS(
						'.site-branding .custom-logo-link img',
						'max-width',
						value,
					);
					break;

				case 'zakra_site_identity_color':
					css = zakraGenerateCommonCSS('.site-title', 'color', value);
					break;

				case 'zakra_site_title_typography':
					css = zakraGenerateTypographyCSS(
						id,
						'.site-branding .site-title',
						value,
					);
					break;

				case 'zakra_site_tagline_color':
					css = zakraGenerateCommonCSS('.site-description', 'color', value);
					break;

				case 'zakra_site_tagline_typography':
					css = zakraGenerateTypographyCSS(
						id,
						'.site-branding .site-description',
						value,
					);
					break;

				case 'zakra_widget_1_title_color':
					css = zakraGenerateCommonCSS(
						'.zak-header-builder .widget.widget-top-bar-col-1-sidebar .widget-title',
						'color',
						value,
					);
					break;

				case 'zakra_widget_1_title_typography':
					css = zakraGenerateTypographyCSS(
						id,
						'.zak-header-builder .widget.widget-top-bar-col-1-sidebar .widget-title',
						value,
					);
					break;

				case 'zakra_widget_1_content_color':
					css = zakraGenerateCommonCSS(
						'.zak-header-builder .widget.widget-top-bar-col-1-sidebar',
						'color',
						value,
					);
					break;

				case 'zakra_widget_1_link_color':
					css = zakraGenerateCommonCSS(
						'.zak-header-builder .widget.widget-top-bar-col-1-sidebar a',
						'color',
						value,
					);
					break;

				case 'zakra_widget_1_content_typography':
					css = zakraGenerateTypographyCSS(
						id,
						'.zak-header-builder .widget.widget-top-bar-col-1-sidebar',
						value,
					);
					break;

				case 'zakra_widget_2_title_color':
					css = zakraGenerateCommonCSS(
						'.zak-header-builder .widget.widget-top-bar-col-2-sidebar .widget-title',
						'color',
						value,
					);
					break;

				case 'zakra_widget_2_title_typography':
					css = zakraGenerateTypographyCSS(
						id,
						'.zak-header-builder .widget.widget-top-bar-col-2-sidebar .widget-title',
						value,
					);
					break;

				case 'zakra_widget_2_content_color':
					css = zakraGenerateCommonCSS(
						'.zak-header-builder .widget.widget-top-bar-col-2-sidebar',
						'color',
						value,
					);
					break;

				case 'zakra_widget_2_link_color':
					css = zakraGenerateCommonCSS(
						'.zak-header-builder .widget.widget-top-bar-col-2-sidebar a',
						'color',
						value,
					);
					break;

				case 'zakra_widget_2_content_typography':
					css = zakraGenerateTypographyCSS(
						id,
						'.zak-header-builder .widget.widget-top-bar-col-2-sidebar',
						value,
					);
					break;

				case 'zakra_header_mobile_menu_item_color':
					css = zakraGenerateCommonCSS('.zak-mobile-nav a', 'color', value);
					css += zakraGenerateCommonCSS(
						'.zak-mobile-nav li.page_item_has_children .zak-submenu-toggle .zak-icon, .zak-mobile-nav li.menu-item-has-children .zak-submenu-toggle .zak-icon',
						'fill',
						value,
					);
					break;

				case 'zakra_header_mobile_menu_item_hover_color':
					css = zakraGenerateCommonCSS(
						'.zak-mobile-nav li:hover > a',
						'color',
						value,
					);
					break;

				case 'zakra_header_mobile_menu_item_active_color':
					css = zakraGenerateCommonCSS(
						'.zak-mobile-nav .current_page_item a, .zak-mobile-nav > .menu ul li.current-menu-item > a',
						'color',
						value,
					);
					break;

				case 'zakra_header_mobile_menu_background':
					css = zakraGenerateCommonCSS(
						'.zak-mobile-nav, .zak-search-form .zak-search-field',
						'background-color',
						value,
					);
					break;

				case 'zakra_header_mobile_menu_item_border_bottom':
					css = zakraGenerateSliderCSS(
						'.zak-mobile-nav li:not(:last-child)',
						'border-bottom-width',
						value,
					);
					break;

				case 'zakra_header_mobile_menu_item_border_bottom_style':
					css = zakraGenerateCommonCSS(
						'.zak-mobile-nav li',
						'border-bottom-style',
						value,
					);
					break;

				case 'zakra_header_mobile_menu_item_border_bottom_color':
					css = zakraGenerateCommonCSS(
						'.zak-mobile-nav li',
						'border-color',
						value,
					);
					break;

				case 'zakra_header_mobile_menu_typography':
					css = zakraGenerateTypographyCSS(id, '.zak-mobile-menu a', value);
					break;

				case 'zakra_footer_html_1_text_color':
					css = zakraGenerateCommonCSS(
						'.zak-footer-builder .zak-html-1 *',
						'color',
						value,
					);
					break;

				case 'zakra_footer_html_1_link_color':
					css = zakraGenerateCommonCSS(
						'.zak-footer-builder .zak-html-1 a',
						'color',
						value,
					);
					break;

				case 'zakra_footer_html_1_link_hover_color':
					css = zakraGenerateCommonCSS(
						'.zak-footer-builder .zak-html-1 a:hover',
						'color',
						value,
					);
					break;

				case 'zakra_footer_html_1_font_size':
					css = zakraGenerateSliderCSS(
						'.zak-footer-builder .zak-html-1 *',
						'font-size',
						value,
					);
					break;

				case 'zakra_footer_html_1_margin':
					css = zakraGenerateDimensionCSS(
						'.zak-footer-builder .zak-html-1',
						'margin',
						value,
					);
					break;

				case 'zakra_footer_html_2_text_color':
					css = zakraGenerateCommonCSS(
						'.zak-footer-builder .zak-html-2 *',
						'color',
						value,
					);
					break;

				case 'zakra_footer_html_2_link_color':
					css = zakraGenerateCommonCSS(
						'.zak-footer-builder .zak-html-2 a',
						'color',
						value,
					);
					break;

				case 'zakra_footer_html_2_link_hover_color':
					css = zakraGenerateCommonCSS(
						'.zak-footer-builder .zak-html-2 a:hover',
						'color',
						value,
					);
					break;

				case 'zakra_footer_html_2_font_size':
					css = zakraGenerateSliderCSS(
						'.zak-footer-builder .zak-html-2 *',
						'font-size',
						value,
					);
					break;

				case 'zakra_footer_html_2_margin':
					css = zakraGenerateDimensionCSS(
						'.zak-footer-builder .zak-html-2',
						'margin',
						value,
					);
					break;

				case 'zakra_footer_widget_1_title_color':
					css = zakraGenerateCommonCSS(
						'.zak-footer-builder .widget.widget-footer-sidebar-1 .widget-title',
						'color',
						value,
					);
					break;

				case 'zakra_footer_widget_1_title_typography':
					css = zakraGenerateTypographyCSS(
						id,
						'.zak-footer-builder .widget.widget-footer-sidebar-1 .widget-title',
						value,
					);
					break;

				case 'zakra_footer_widget_1_content_color':
					css = zakraGenerateCommonCSS(
						'.zak-footer-builder .widget.widget-footer-sidebar-1',
						'color',
						value,
					);
					break;

				case 'zakra_footer_widget_1_link_color':
					css = zakraGenerateCommonCSS(
						'.zak-footer-builder .widget.widget-footer-sidebar-1 a',
						'color',
						value,
					);
					break;

				case 'zakra_footer_widget_1_content_typography':
					css = zakraGenerateTypographyCSS(
						id,
						'.zak-footer-builder .widget.widget-footer-sidebar-1',
						value,
					);
					break;
				//
				case 'zakra_footer_widget_2_title_color':
					css = zakraGenerateCommonCSS(
						'.zak-footer-builder .widget.widget-footer-sidebar-2 .widget-title',
						'color',
						value,
					);
					break;

				case 'zakra_footer_widget_2_title_typography':
					css = zakraGenerateTypographyCSS(
						id,
						'.zak-footer-builder .widget.widget-footer-sidebar-2 .widget-title',
						value,
					);
					break;

				case 'zakra_footer_widget_2_content_color':
					css = zakraGenerateCommonCSS(
						'.zak-footer-builder .widget.widget-footer-sidebar-2',
						'color',
						value,
					);
					break;

				case 'zakra_footer_widget_2_link_color':
					css = zakraGenerateCommonCSS(
						'.zak-footer-builder .widget.widget-footer-sidebar-2 a',
						'color',
						value,
					);
					break;

				case 'zakra_footer_widget_2_content_typography':
					css = zakraGenerateTypographyCSS(
						id,
						'.zak-footer-builder .widget.widget-footer-sidebar-2',
						value,
					);
					break;

				case 'zakra_footer_widget_3_title_color':
					css = zakraGenerateCommonCSS(
						'.zak-footer-builder .widget.widget-footer-sidebar-3 .widget-title',
						'color',
						value,
					);
					break;

				case 'zakra_footer_widget_3_title_typography':
					css = zakraGenerateTypographyCSS(
						id,
						'.zak-footer-builder .widget.widget-footer-sidebar-3 .widget-title',
						value,
					);
					break;

				case 'zakra_footer_widget_3_content_color':
					css = zakraGenerateCommonCSS(
						'.zak-footer-builder .widget.widget-footer-sidebar-3',
						'color',
						value,
					);
					break;

				case 'zakra_footer_widget_3_link_color':
					css = zakraGenerateCommonCSS(
						'.zak-footer-builder .widget.widget-footer-sidebar-3 a',
						'color',
						value,
					);
					break;

				case 'zakra_footer_widget_3_content_typography':
					css = zakraGenerateTypographyCSS(
						id,
						'.zak-footer-builder .widget.widget-footer-sidebar-3',
						value,
					);
					break;

				case 'zakra_footer_widget_4_title_color':
					css = zakraGenerateCommonCSS(
						'.zak-footer-builder .widget.widget-footer-sidebar-4 .widget-title',
						'color',
						value,
					);
					break;

				case 'zakra_footer_widget_4_title_typography':
					css = zakraGenerateTypographyCSS(
						id,
						'.zak-footer-builder .widget.widget-footer-sidebar-4 .widget-title',
						value,
					);
					break;

				case 'zakra_footer_widget_4_content_color':
					css = zakraGenerateCommonCSS(
						'.zak-footer-builder .widget.widget-footer-sidebar-4',
						'color',
						value,
					);
					break;

				case 'zakra_footer_widget_4_link_color':
					css = zakraGenerateCommonCSS(
						'.zak-footer-builder .widget.widget-footer-sidebar-4 a',
						'color',
						value,
					);
					break;

				case 'zakra_footer_widget_4_content_typography':
					css = zakraGenerateTypographyCSS(
						id,
						'.zak-footer-builder .widget.widget-footer-sidebar-4',
						value,
					);
					break;

				case 'zakra_footer_menu_color':
					css = zakraGenerateCommonCSS(
						'.zak-footer-builder .zak-footer-nav ul li a',
						'color',
						value,
					);
					break;

				case 'zakra_footer_menu_hover_color':
					css = zakraGenerateCommonCSS(
						'.zak-footer-builder .zak-footer-nav ul li a:hover',
						'color',
						value,
					);
					break;

				case 'zakra_footer_menu_typography':
					css = zakraGenerateTypographyCSS(
						id,
						'.zak-footer-builder .zak-footer-nav ul li a',
						value,
					);
					break;

				case 'zakra_footer_menu_2_color':
					css = zakraGenerateCommonCSS(
						'.zak-footer-builder .zak-footer-nav-2 ul li a',
						'color',
						value,
					);
					break;

				case 'zakra_footer_menu_2_hover_color':
					css = zakraGenerateCommonCSS(
						'.zak-footer-builder .zak-footer-nav-2 ul li a:hover',
						'color',
						value,
					);
					break;

				case 'zakra_footer_menu_2_typography':
					css = zakraGenerateTypographyCSS(
						id,
						'.zak-footer-builder .zak-footer-nav-2 ul li a',
						value,
					);
					break;

				case 'zakra_footer_copyright_text_color':
					css = zakraGenerateCommonCSS(
						'.zak-footer-builder .zak-copyright',
						'color',
						value,
					);
					break;

				case 'zakra_footer_copyright_link_color':
					css = zakraGenerateCommonCSS(
						'.zak-footer-builder .zak-copyright a',
						'color',
						value,
					);
					break;

				case 'zakra_footer_copyright_link_hover_color':
					css = zakraGenerateCommonCSS(
						'.zak-footer-builder .zak-copyright a:hover',
						'color',
						value,
					);
					break;

				case 'zakra_footer_copyright_typography':
					css = zakraGenerateTypographyCSS(
						id,
						'.zak-footer-builder .zak-copyright',
						value,
					);
					break;

				case 'zakra_footer_copyright_margin':
					css = zakraGenerateDimensionCSS(
						'.zak-footer-builder .zak-copyright',
						'margin',
						value,
					);
					break;

				case 'zakra_header_site_logo_height':
					css = zakraGenerateSliderCSS(
						'.zak-header-builder .site-branding .custom-logo-link img',
						'width',
						value,
					);
					break;

				case 'zakra_header_site_identity_color':
					css = zakraGenerateCommonCSS(
						'.zak-header-builder .site-title',
						'color',
						value,
					);
					break;

				case 'zakra_header_site_title_typography':
					css = zakraGenerateTypographyCSS(
						id,
						'.zak-header-builder .site-title',
						value,
					);
					break;

				case 'zakra_header_site_tagline_color':
					css = zakraGenerateCommonCSS(
						'.zak-header-builder .site-description',
						'color',
						value,
					);
					break;

				case 'zakra_header_site_tagline_typography':
					css = zakraGenerateTypographyCSS(
						id,
						'.zak-header-builder .site-description',
						value,
					);
					break;

				case 'zakra_footer_bottom_area_color':
					css = zakraGenerateCommonCSS(
						'.zak-footer-builder .zak-footer-bottom-row',
						'color',
						value,
					);
					break;

				case 'zakra_footer_top_area_color':
					css = zakraGenerateCommonCSS(
						'.zak-footer-builder .zak-footer-top-row',
						'color',
						value,
					);
					break;

				case 'zakra_footer_main_area_color':
					css = zakraGenerateCommonCSS(
						'.zak-footer-builder .zak-footer-main-row',
						'color',
						value,
					);
					break;

				case 'zakra_footer_main_area_link_color':
					css = zakraGenerateCommonCSS(
						'.zak-footer-builder .zak-footer-main-row a, .zak-footer-builder .zak-footer-main-row ul li a, .zak-footer-builder .zak-footer-main-row .widget ul li a',
						'color',
						value,
					);
					break;

				case 'zakra_footer_main_area_link_hover_color':
					css = zakraGenerateCommonCSS(
						'.zak-footer-builder .zak-footer-main-row a, .zak-footer-builder .zak-footer-main-row ul li a:hover, .zak-footer-builder .zak-footer-main-row .widget ul li a:hover',
						'color',
						value,
					);
					break;

				case 'zakra_footer_main_area_widget_title_color':
					css = zakraGenerateCommonCSS(
						'.zak-footer-builder .zak-footer-main-row .widget-title, .zak-footer-builder .zak-footer-main-row h1, .zak-footer-builder .zak-footer-main-row h2, .zak-footer-builder .zak-footer-main-row h3, .zak-footer-builder .zak-footer-main-row h4, .zak-footer-builder .zak-footer-main-row h5, .zak-footer-builder .zak-footer-main-row h6',
						'color',
						value,
					);
					break;

				case 'zakra_header_builder_background':
					css = zakraGenerateBackgroundCSS('.zak-header-builder', value);
					break;

				case 'zakra_header_builder_border_width':
					css = zakraGenerateDimensionCSS(
						'.zak-header-builder',
						'border-width',
						value,
					);
					css += zakraGenerateCommonCSS(
						'.zak-header-builder',
						'border-style',
						'solid',
					);
					break;

				case 'zakra_header_builder_border_color':
					css = zakraGenerateCommonCSS(
						'.zak-header-builder',
						'border-color',
						value,
					);
					break;
				//
				case 'zakra_footer_widget_5_title_color':
					css = zakraGenerateCommonCSS(
						'.zak-footer-builder .widget.widget-footer-bar-col-1-sidebar .widget-title',
						'color',
						value,
					);
					break;

				case 'zakra_footer_widget_5_title_typography':
					css = zakraGenerateTypographyCSS(
						id,
						'.zak-footer-builder .widget.widget-footer-bar-col-1-sidebar .widget-title',
						value,
					);
					break;

				case 'zakra_footer_widget_5_content_color':
					css = zakraGenerateCommonCSS(
						'.zak-footer-builder .widget.widget-footer-bar-col-1-sidebar',
						'color',
						value,
					);
					break;

				case 'zakra_footer_widget_5_link_color':
					css = zakraGenerateCommonCSS(
						'.zak-footer-builder .widget.widget-footer-bar-col-1-sidebar a',
						'color',
						value,
					);
					break;

				case 'zakra_footer_widget_5_content_typography':
					css = zakraGenerateTypographyCSS(
						id,
						'.zak-footer-builder .widget.widget-footer-bar-col-1-sidebar',
						value,
					);
					break;

				case 'zakra_footer_widget_6_title_color':
					css = zakraGenerateCommonCSS(
						'.zak-footer-builder .widget.widget-footer-bar-col-2-sidebar .widget-title',
						'color',
						value,
					);
					break;

				case 'zakra_footer_widget_6_title_typography':
					css = zakraGenerateTypographyCSS(
						id,
						'.zak-footer-builder .widget.widget-footer-bar-col-2-sidebar .widget-title',
						value,
					);
					break;

				case 'zakra_footer_widget_6_content_color':
					css = zakraGenerateCommonCSS(
						'.zak-footer-builder .widget.widget-footer-bar-col-2-sidebar',
						'color',
						value,
					);
					break;

				case 'zakra_footer_widget_6_link_color':
					css = zakraGenerateCommonCSS(
						'.zak-footer-builder .widget.widget-footer-bar-col-2-sidebar a',
						'color',
						value,
					);
					break;

				case 'zakra_footer_widget_6_content_typography':
					css = zakraGenerateTypographyCSS(
						id,
						'.zak-footer-builder .widget.widget-footer-bar-col-2-sidebar',
						value,
					);
					break;

				case 'zakra_header_mobile_menu_typography':
					css = zakraGenerateTypographyCSS(
						id,
						'.zak-header-builder .zak-mobile-menu a',
						value,
					);
					break;

				case 'zakra_footer_bottom_inner_element_layout':
					css = zakraElementLayoutCSS(
						'.zak-footer-builder .zak-footer-bottom-row .zak-footer-col',
						'flex-direction',
						value,
					);
					break;

				case 'zakra_footer_main_inner_element_layout':
					css = zakraElementLayoutCSS(
						'.zak-footer-builder .zak-footer-main-row .zak-footer-col',
						'flex-direction',
						value,
					);
					break;

				case 'zakra_footer_top_inner_element_layout':
					css = zakraElementLayoutCSS(
						'.zak-footer-builder .zak-footer-top-row .zak-footer-col',
						'flex-direction',
						value,
					);
					break;

				case 'zakra_copyright_alignment':
					css = zakraAlignmentCSS(
						'.zak-footer-builder .zak-copyright',
						'text-align',
						value,
					);
					break;

				case 'zakra_html_1_alignment':
					css = zakraAlignmentCSS(
						'.zak-footer-builder .zak-html-1',
						'text-align',
						value,
					);
					break;

				case 'zakra_html_2_alignment':
					css = zakraAlignmentCSS(
						'.zak-footer-builder .zak-html-2',
						'text-align',
						value,
					);
					break;

				case 'zakra_socials_alignment':
					css = zakraAlignmentCSS(
						'.zak-footer-builder .footer-social-icons',
						'text-align',
						value,
					);
					break;

				case 'zakra_footer_widget_1_alignment':
					css = zakraAlignmentCSS(
						'.zak-footer-builder .widget-footer-sidebar-1',
						'text-align',
						value,
					);
					break;

				case 'zakra_footer_widget_2_alignment':
					css = zakraAlignmentCSS(
						'.zak-footer-builder .widget-footer-sidebar-2',
						'text-align',
						value,
					);
					break;

				case 'zakra_footer_widget_3_alignment':
					css = zakraAlignmentCSS(
						'.zak-footer-builder .widget-footer-sidebar-3',
						'text-align',
						value,
					);
					break;

				case 'zakra_footer_widget_4_alignment':
					css = zakraAlignmentCSS(
						'.zak-footer-builder .widget-footer-sidebar-4',
						'text-align',
						value,
					);
					break;

				case 'zakra_footer_widget_5_alignment':
					css = zakraAlignmentCSS(
						'.zak-footer-builder .widget-footer-bar-col-1-sidebar',
						'text-align',
						value,
					);
					break;

				case 'zakra_footer_widget_6_alignment':
					css = zakraAlignmentCSS(
						'.zak-footer-builder .widget-footer-bar-col-2-sidebar',
						'text-align',
						value,
					);
					break;

				case 'zakra_blog_post_title_color':
					css = zakraGenerateCommonCSS('.entry-title a', 'color', value);
					break;

				case 'zakra_blog_post_title_hover_color':
					css = zakraGenerateCommonCSS('.entry-title a:hover', 'color', value);
					break;
			}
			return css;
		},
	);

	// wp.hooks.addAction(
	//   "customind.change.zakra_color_palette",
	//   "customind",
	//   (...args) => {
	//     console.log(args);
	//   },
	// );

	wp.hooks.addAction(
		'customind.change.zakra_container_layout',
		'customind',
		(value) => {
			if ('wide' === value) {
				$('body')
					.removeClass('zak-container--boxed')
					.addClass('zak-container--wide');
			} else if ('boxed' === value) {
				$('body')
					.removeClass('zak-container--wide')
					.addClass('zak-container--boxed');
			}
		},
	);

	wp.hooks.addAction(
		'customind.change.zakra_content_area_layout',
		'customind',
		(value) => {
			if ('bordered' === value) {
				$('body')
					.removeClass('zak-content-area--boxed')
					.addClass('zak-content-area--bordered');
			} else if ('boxed' === value) {
				$('body')
					.removeClass('zak-content-area--bordered')
					.addClass('zak-content-area--boxed');
			}
		},
	);

	wp.hooks.addAction(
		'customind.change.zakra_page_header_layout',
		'customind',
		(value) => {
			if ('style-1' === value) {
				$('.zak-page-header')
					.removeClass('zak-style-2')
					.removeClass('zak-style-3')
					.removeClass('zak-style-4')
					.removeClass('zak-style-5')
					.addClass('zak-style-1');
			} else if ('style-2' === value) {
				$('.zak-page-header')
					.removeClass('zak-style-1')
					.removeClass('zak-style-3')
					.removeClass('zak-style-4')
					.removeClass('zak-style-5')
					.addClass('zak-style-2');
			} else if ('style-3' === value) {
				$('.zak-page-header')
					.removeClass('zak-style-1')
					.removeClass('zak-style-2')
					.removeClass('zak-style-4')
					.removeClass('zak-style-5')
					.addClass('zak-style-3');
			} else if ('style-4' === value) {
				$('.zak-page-header')
					.removeClass('zak-style-1')
					.removeClass('zak-style-2')
					.removeClass('zak-style-3')
					.removeClass('zak-style-5')
					.addClass('zak-style-4');
			} else if ('style-5' === value) {
				$('.zak-page-header')
					.removeClass('zak-style-1')
					.removeClass('zak-style-2')
					.removeClass('zak-style-3')
					.removeClass('zak-style-4')
					.addClass('zak-style-5');
			}
		},
	);

	wp.hooks.addAction(
		'customind.change.zakra_footer_bar_style',
		'customind',
		(value) => {
			if ('style-1' === value) {
				$('.zak-footer-bar').removeClass('zak-style-2').addClass('zak-style-1');
			} else if ('style-2' === value) {
				$('.zak-footer-bar').removeClass('zak-style-1').addClass('zak-style-2');
			}
		},
	);

	wp.hooks.addAction(
		'customind.change.zakra_main_header_layout_1_style',
		'customind',
		(style) => {
			if ('style-1' === style) {
				$('#zak-masthead')
					.removeClass('zak-layout-1-style-2')
					.removeClass('zak-layout-1-style-3')
					.removeClass('zak-layout-1-style-4')
					.addClass('zak-layout-1-style-1');
				removeAndAppendHeaderAction();
			} else if ('style-2' === style) {
				$('#zak-masthead')
					.removeClass('zak-layout-1-style-3')
					.removeClass('zak-layout-1-style-1')
					.removeClass('zak-layout-1-style-4')
					.removeClass('zak-menu--center')
					.removeClass('zak-menu--right')
					.removeClass('zak-menu--left')
					.addClass('zak-layout-1-style-2');
				removeAndAppendHeaderAction();
			} else if ('style-3' === style) {
				$('#zak-masthead')
					.removeClass('zak-layout-1-style-1')
					.removeClass('zak-layout-1-style-2')
					.removeClass('zak-layout-1-style-4')
					.removeClass('zak-menu--center')
					.removeClass('zak-menu--right')
					.removeClass('zak-menu--left')
					.addClass('zak-layout-1-style-3');
				removeAndAppendHeaderAction();
			} else if ('style-4' === style) {
				$('#zak-masthead')
					.removeClass('zak-layout-1-style-1')
					.removeClass('zak-layout-1-style-3')
					.removeClass('zak-layout-1-style-2')
					.removeClass('zak-menu--center')
					.removeClass('zak-menu--right')
					.removeClass('zak-menu--left')
					.addClass('zak-layout-1-style-4');
				removeAndAppendHeaderAction();
			}
		},
	);
})(jQuery);
