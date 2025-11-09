( function( $, api ) {

    /*
    ** Customizer Partials
    */
    wp.customize.bind( 'preview-ready', function() {
        var defaultTarget = window.parent === window ? null : window.parent;
        $( document ).on( 'click', '.item-customizer-focus', function(e) {
                e.preventDefault();
                e.stopPropagation();

                var section_id = $( this ).closest( '.customize-partial-edit-shortcut' ).attr( 'data-section' ) || '';

                if ( section_id ) {
                    if ( defaultTarget.wp.customize.section( section_id ) ) {
                        defaultTarget.wp.customize.section( section_id ).focus();
                    }
                }
            }
        );

        // Setup Menu Link Click
        $( document ).on( 'click', '.newsx-setup-menu-link', function(e) {
            e.preventDefault();
            e.stopPropagation();
            if ( defaultTarget.wp.customize.panel( 'nav_menus' ) ) {
                defaultTarget.wp.customize.panel( 'nav_menus' ).focus();
            }
        });
    });

    /*
    ** Global Colors
    */
    newsxLivePreview('global_color_accent', function(newval) {

        let css = '\
            .newsx-social-icon:hover, .newsx-cta-button:hover a, .newsx-random-post:hover a, .newsx-tabs li.active, #newsx-back-to-top.newsx-trans-bg svg {\
                color: '+ newval +';\
            }\
            .search-submit, .newsx-weather-header, .newsx-vplaylist-controller, .newsx-newsletter-form input[type="submit"], .newsx-s1.newsx-category-list .category-count, .newsx-post-index, .newsx-blog-pagination .current, .newsx-reading-progress-bar, .newsx-post-sources .source-tag a, #newsx-back-to-top:not(.newsx-trans-bg), .wp-block-search__button {\
                background-color: '+ newval +';\
            }\
            .newsx-social-icon:hover svg, .newsx-cta-button:hover svg, .newsx-random-post:hover svg, .newsx-switch-to-dark:hover svg, #newsx-back-to-top.newsx-trans-bg svg {\
                fill: '+ newval +';\
            }\
            .sub-menu, #newsx-back-to-top.newsx-trans-bg, blockquote {\
                border-color: '+ newval +';\
            }\
            .newsx-post-sources .source-tag a:hover {\
                box-shadow: 0 0 5px 3px '+ newsxHexToRgba(newval, 0.2) +';\
            }\
            .newsx-underline-hover:hover {\
                text-decoration-color: '+ newval +';\
                -webkit-text-decoration-color: '+ newval +';\
            }\
            .newsx-tabs li.active {\
                border-bottom-color: '+ newval +' !important;\
            }\
        ';

        
        // Get widget accent color
        let widgetAccentColor = newsxGetOptionValue('global_widget_accent_color'),
            widgetCSS = '';

        if ( '' === widgetAccentColor ) {
            widgetCSS = '\
                .newsx-grid-filter:hover, .newsx-grid-filter.active {\
                    color: '+ newval +';\
                }\
                .newsx-s0.newsx-widget-title-wrap .newsx-widget-title-text {\
                    background-color: '+ newval +';\
                }\
                .newsx-widget-title-wrap, .newsx-widget-title-text, .newsx-widget-title-text:after, .newsx-widget .newsx-ring-loader div {\
                    border-color: '+ newval +';\
                }\
            ';
        }

        newsxPreviewStyle('global_color_accent', css + widgetCSS);
    });

    newsxLivePreview('global_widget_accent_color', function(newval) {
        let css = '\
            .newsx-grid-filter:hover, .newsx-grid-filter.active {\
                color: '+ newval +';\
            }\
            .newsx-s0.newsx-widget-title-wrap .newsx-widget-title-text {\
                background-color: '+ newval +';\
            }\
            .newsx-widget-title-wrap, .newsx-widget-title-text, .newsx-widget-title-text:after, .newsx-widget .newsx-ring-loader div {\
                border-color: '+ newval +';\
            }\
        ';

        newsxPreviewStyle('global_widget_accent_color', css);
    });

    newsxMultiColorLivePreview('global_color_links:normal', function(newval) {
        let css = '\
            a:not(.menu-item a) {\
                color: '+ newval +';\
            }\
        ';

        newsxPreviewStyle('global_color_links_hover', css);
    });

    newsxMultiColorLivePreview('global_color_links:hover', function(newval) {
        let css = '\
            a:hover {\
                color: '+ newval +';\
            }\
        ';

        newsxPreviewStyle('global_color_links_hover', css);
    });

    /*
    ** Helper functions for customizer preview
    */
    function newsxLivePreview( option, func ) {
        wp.customize('newsx_options['+ option +']', function(value) {
            value.bind(function(newval) {
                func(newval);
            });
        });
    }

    function newsxMultiColorLivePreview(option, func) {
        let options = option.split(':');
        wp.customize('newsx_options['+ options[0] +']['+ options[1] +']', function(value) {
            value.bind(function(newval) {
                func(newval);
            });
        });
    }

	// Style Tag
	function newsxPreviewStyle( id, css ) {
		if ( $( '#'+ id ).length === 0 ) {
			$('head').append('<style id="'+ id +'"></style>')
		}

		$( '#'+ id ).text( css );
	}

    function newsxHexToRgba(hex, opacity = 1) {
        if (!hex) return '';
        
        hex = hex.replace('#', '');
        return `rgba(${parseInt(hex.slice(0,2), 16)}, ${parseInt(hex.slice(2,4), 16)}, ${parseInt(hex.slice(4,6), 16)}, ${opacity})`;
    }

    function newsxGetCssValue(value, unit = 'px') {
        if (!value && '0' !== value) return '';
        return value + unit;
    }

    function newsxReduceValue(value, percent) {
        value = parseInt(value);
        const reducedValue = value - (value * percent);
        return Math.round(reducedValue);
    }

    function newsxGetTypographyCss(option, selector) {
        let css = {};
        
        css[selector] = {
            'font-family': option['font-family'],
            'font-weight': parseInt(option['font-weight']) > 100 ? option['font-weight'] : 'normal',
            'font-style': option['font-style'],
            'text-transform': option['text-transform'],
            'text-decoration': option['text-decoration'],
            'text-align': option['text-align'],
            'line-height': option['line-height'],
            'letter-spacing': option['letter-spacing'] + 'px'
        };

        if (option['font-size']) {
            css = {...css, ...newsxGetRespSliderControlCss(option['font-size'], selector, 'font-size')};
        }

        return css;
    }

    function newsxGetElementVisibilityCss(option, selector, display = 'block') {
        if (!Array.isArray(option)) return {};

        const desktopOption = option.includes('desktop') ? display : 'none';
        const tabletOption = option.includes('tablet') ? display : 'none';
        const mobileOption = option.includes('mobile') ? display : 'none';

        let css = {
            [selector]: {
                'display': desktopOption
            }
        };

        // Add media queries as needed
        // You'll need to implement newsxGetTabletBreakpoint() and newsxGetMobileBreakpoint()
        // or use hardcoded values based on your theme's breakpoints

        return css;
    }

    function newsxGetRespSliderControlCss(option, selector, property, unit = 'px', minuspx = 0) {
        if (typeof option === 'string') {
            option = JSON.parse(option);
        }

        let css = {};

        if (option.desktop) {
            const desktopValue = option.desktop ? (option.desktop - minuspx) : '';
            css[selector] = {
                [property]: newsxGetCssValue(desktopValue, unit)
            };
        }

        // Add media queries for tablet and mobile as needed

        return css;
    }

    function newsxGetBackgroundCss(data, selector) {
        if (!data) return {};

        let css = {};
        const selectedTab = data['background-tabs'].split('|')[0];

        if (selectedTab === 'color') {
            css[selector] = {
                'background-color': data['background-color']
            };
        } else if (selectedTab === 'gradient') {
            const angle = parseInt(data['gradient-angle']);
            const color1 = data['gradient-color-1'];
            const color2 = data['gradient-color-2'];
            const pos1 = parseInt(data['gradient-pos-1']);
            const pos2 = parseInt(data['gradient-pos-2']);

            css[selector] = {
                'background': `linear-gradient(${angle}deg, ${color1} ${pos1}%, ${color2} ${pos2}%)`
            };
        } else {
            css[selector] = {
                'background-image': `url(${data['background-image']})`,
                'background-repeat': data['background-repeat'],
                'background-position': data['background-position'],
                'background-size': data['background-size'],
                'background-attachment': data['background-attachment']
            };
        }

        return css;
    }

    function newsxGetOptionValue(option) {
        const setting = wp.customize('newsx_options[' + option + ']');
        return setting ? setting.get() : null;
    }

} )( jQuery, wp );