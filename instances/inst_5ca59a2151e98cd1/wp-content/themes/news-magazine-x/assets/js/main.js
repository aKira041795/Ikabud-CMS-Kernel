jQuery( document ).ready( function( $ ) {
    "use strict";
     
    /* Dark Mode Switcher
    -------------------------------------------------- */
    var NewsxDarkModeSwitcher = {
        init: function() {
            $('.newsx-dark-mode-switcher').on('click', function() {
                let $head = $('head'),
                    $body = $('body');

                $body.toggleClass('newsx-dark-mode');
                
                $(this).toggleClass('active');

                if ( $body.hasClass('newsx-dark-mode') ) {
                    $head.append('<style type="text/css" id="newsx-dark-mode-css">'+ NewsxMain.dark_mode +'</style>');
                    localStorage.setItem('newsxDarkMode', 'on');
                } else {
                    // Reset
                    let darkModeCss = $head.find('#newsx-main-inline-css').text();
                    let darkModeStart = darkModeCss.indexOf('body.newsx-dark-mode');
                    if (darkModeStart !== -1) {
                        darkModeCss = darkModeCss.substring(0, darkModeStart);
                        $head.find('#newsx-main-inline-css').text(darkModeCss);
                    }
                    
                    $head.find('#newsx-dark-mode-css').remove();
                    localStorage.setItem('newsxDarkMode', 'off');
                }

                // Add Blur Effect to News Tickers
                NewsxSwiperAdjustments.headerNewsTickerBlurEffect();
                NewsxSwiperAdjustments.widgetNewsTickerBlurEffect();
            });

            // Apply dark mode on page load if previously selected
            let $head = $('head'),
                $body = $('body');

            if ( !$body.hasClass('newsx-dark-mode') ) {
                if ('on' === localStorage.getItem('newsxDarkMode')) {
                    $body.addClass('newsx-dark-mode');
                    $('.newsx-dark-mode-switcher').addClass('active');
                    $head.append('<style type="text/css" id="newsx-dark-mode-css">'+ NewsxMain.dark_mode +'</style>');
                }
            } else {
                if ('off' === localStorage.getItem('newsxDarkMode')) {
                    $body.removeClass('newsx-dark-mode');
                    $('.newsx-dark-mode-switcher').removeClass('active');

                    // Remove Dark Mode CSS
                    let darkModeCss = $head.find('#newsx-main-inline-css').text();
                    let darkModeStart = darkModeCss.indexOf('body.newsx-dark-mode');
                    if (darkModeStart !== -1) {
                        darkModeCss = darkModeCss.substring(0, darkModeStart);
                        $head.find('#newsx-main-inline-css').text(darkModeCss);
                    }
                }
            }
        }
    };

    NewsxDarkModeSwitcher.init();

    /* Sticky Header
    -------------------------------------------------- */
    var NewsxStickyHeader = {
        headerHeight: 0,
        topRow : null,
        topRowOffset: 0,
        topRowHeight: 0,
        middleRow: null,
        middleRowOffset: 0,
        middleRowHeight: 0,
        bottomRow: null,
        bottomRowOffset: 0,
        bottomRowHeight: 0,

        init: function() {
            this.topRow = $('.newsx-site-header .newsx-top-section-wrap');
            this.middleRow = $('.newsx-site-header .newsx-middle-section-wrap');
            this.bottomRow = $('.newsx-site-header .newsx-bottom-section-wrap');
            this.headerHeight = $('.newsx-site-header').outerHeight();

            // Top Row
            if ( this.stickyRowEnabled('top') ) {
                this.topRowHeight = this.topRow.outerHeight();
                this.topRowOffset = this.topRow.offset().top + this.headerHeight;
            }

            // Middle Row
            if ( this.stickyRowEnabled('middle') ) {
                this.middleRowHeight = this.middleRow.outerHeight();
                this.middleRowOffset = this.middleRow.offset().top + this.headerHeight;
            }

            // Bottom Row
            if ( this.stickyRowEnabled('bottom') ) {
                this.bottomRowHeight = this.bottomRow.outerHeight();
                this.bottomRowOffset = this.bottomRow.offset().top + this.headerHeight;
            }

            // on Load
            this.stickyRows();
            this.fixStickySharing();

            // on Scroll
            $(window).on('scroll', this.stickyRows.bind(this));
        },

        stickyRows: function() {
            let windowScrollTop = $(window).scrollTop();

            let stickyCSS = {
                'position': 'fixed',
                'top': 0,
                'z-index': 99999999,
                'width': '100%'
            },
            staticCSS = {
                'position': 'relative',
                'top': 'auto',
                'z-index': 'auto',
                'width': 'auto'
            };

            // Top Row
            if ( this.stickyRowEnabled('top') ) {
                if ( windowScrollTop > this.topRowOffset ) {
                    if ( !this.isSticky('top') ) {
                        // Add placeholder before making the row sticky
                        if (!this.topRow.next('.newsx-sticky-placeholder').length) {
                            this.topRow.after($('<div>').addClass('newsx-sticky-placeholder').height(this.topRowHeight));
                        }
                        this.topRow.css(stickyCSS).stop().hide().fadeIn(300);
                    }
                } else {
                    if ( this.isSticky('top') ) {
                        this.topRow.css(staticCSS).stop().show();
                        // Remove placeholder when returning to static
                        this.topRow.next('.newsx-sticky-placeholder').remove();
                    }
                }
            }

            // Middle Row
            if ( this.stickyRowEnabled('middle') ) {
                if ( this.isSticky('top') || windowScrollTop > this.middleRowOffset ) {
                    if ( !this.isSticky('middle') ) {
                        // Add placeholder before making the row sticky
                        if (!this.middleRow.next('.newsx-sticky-placeholder').length) {
                            this.middleRow.after($('<div>').addClass('newsx-sticky-placeholder').height(this.middleRowHeight));
                        }
                        stickyCSS.top = this.stickyRowEnabled('top') ? this.topRowHeight : 0;
                        this.middleRow.css(stickyCSS).stop().hide().fadeIn(300);
                    }
                } else {
                    if ( this.isSticky('middle') ) {
                        this.middleRow.css(staticCSS).stop().show();
                        // Remove placeholder when returning to static
                        this.middleRow.next('.newsx-sticky-placeholder').remove();
                    }
                }
            }

            // Bottom Row
            if ( this.stickyRowEnabled('bottom') ) {
                if ( this.isSticky('top') || this.isSticky('middle') || windowScrollTop > this.bottomRowOffset ) {
                    if ( !this.isSticky('bottom') ) {
                        // Add placeholder before making the row sticky
                        if (!this.bottomRow.next('.newsx-sticky-placeholder').length) {
                            this.bottomRow.after($('<div>').addClass('newsx-sticky-placeholder').height(this.bottomRowHeight));
                        }
                        stickyCSS.top = this.stickyRowEnabled('top') ? this.topRowHeight : 0;
                        stickyCSS.top += this.stickyRowEnabled('middle') ? this.middleRowHeight : 0;
                        this.bottomRow.css(stickyCSS).stop().hide().fadeIn(300);
                    }
                } else {
                    if ( this.isSticky('bottom') ) {
                        this.bottomRow.css(staticCSS).stop().show();
                        // Remove placeholder when returning to static
                        this.bottomRow.next('.newsx-sticky-placeholder').remove();
                    }
                }
            }
        },
        
        stickyRowEnabled: function( row ) {
            let $row = $('.newsx-site-header .newsx-'+ row +'-section-wrap');

            if ( $row.length && 'yes' === $row.data('sticky') ) {
                return true;
            }

            return false;
        },

        isSticky: function( row ) {
            let $row = $('.newsx-site-header .newsx-'+ row +'-section-wrap');

            if ( this.stickyRowEnabled(row) && 'fixed' === $row.css('position') ) {
                return true;
            }

            return false;
        },

        fixStickySharing: function() {
            let stickyHeaderHeight = 0;

            $('.newsx-site-header .newsx-section-wrap').each(function() {
                if ( 'yes' === $(this).data('sticky') ) {
                    stickyHeaderHeight += $(this).outerHeight();
                }
            });

            $('.newsx-float-sharing.pos-inside .newsx-post-sharing').css({
                'top': (15 + stickyHeaderHeight) + 'px'
            });
        }
    }

    NewsxStickyHeader.init();

    /* AJAX Search
    -------------------------------------------------- */
    var NewsxAjaxSearch = {
        init: function() {
            if ( !(0 < $('.newsx-ajax-search-results').length) ) {
                return;
            }

            $('.newsx-header-search').each(function() {
                let $headerSearch = $(this),
                    searchTimeout = null,
                    preloaderHTML = '<div class="newsx-ring-loader"><div></div><div></div><div></div><div></div></div>',
                    storedIcon = '';

                if ( $headerSearch.hasClass('header-search-s2') ) {
                    storedIcon = $headerSearch.find('.newsx-search-submit').find('.newsx-svg-icon').clone();
                } else {
                    storedIcon = $headerSearch.find('.newsx-svg-icon').first().clone();
                }

                $headerSearch.find('input').attr('autocomplete', 'off');

                // Close results when search is closed
                $headerSearch.find('.newsx-search-icon').on('click', function() {
                    if ($headerSearch.hasClass('active')) {
                        $headerSearch.find('.newsx-ajax-search-results').hide();
                    }
                });

                $headerSearch.find('input').on('keyup', function(e) {
                    if ( e.which === 13 ) {
                        return false;
                    }
        
                    if (searchTimeout != null) {
                        clearTimeout(searchTimeout);
                    }
                    
                    let thisValue = $(this).val();

                    $headerSearch.find('.newsx-ajax-search-results').hide();
                    $headerSearch.find('.newsx-ajax-search-results ul').html('');

                    if ( thisValue.length > 2 ) {
                        if ( $headerSearch.hasClass('header-search-s2') ) {
                            $headerSearch.find('.newsx-search-submit').find('.newsx-svg-icon').remove();
                            if ( !($headerSearch.find('.newsx-ring-loader').length) ) {
                                $headerSearch.find('.newsx-search-submit').append(preloaderHTML);
                            }
                        } else {
                            $headerSearch.find('.newsx-svg-icon').first().remove();
                            $headerSearch.find('.newsx-search-icon').first().html(preloaderHTML);
                        }
                    }

                    searchTimeout = setTimeout(() => {
                        $.ajax({
                            type: 'POST',
                            url: NewsxMain.ajaxurl,
                            data: {
                                action: 'newsx_ajax_search',
                                nonce: NewsxMain.nonce,
                                newsx_keyword: thisValue
                            },
                            success: function(response) {
                                $headerSearch.find('.newsx-ajax-search-results ul').html(response);

                                if ( $headerSearch.hasClass('header-search-s2') ) {
                                    if ( $headerSearch.find('.newsx-search-submit').find('.newsx-svg-icon').length == 0 ) {
                                        $headerSearch.find('.newsx-search-submit').find('.newsx-ring-loader').remove();
                                        $headerSearch.find('.newsx-search-submit').append(storedIcon);
                                    }
                                } else {
                                    if ( $headerSearch.find('.newsx-svg-icon').length == 0 ) {
                                        $headerSearch.find('.newsx-search-icon').html(storedIcon);
                                    }
                                }

                                setTimeout(function() {
                                    if ( !response.includes('newsx-no-results') ) {
                                        $headerSearch.find('.newsx-ajax-search-results').removeClass('newsx-no-results').show();
                                    } else {
                                        $headerSearch.find('.newsx-ajax-search-results').addClass('newsx-no-results');
                                    }
                                
                                    if (thisValue.length > 2) {
                                        $headerSearch.find('.newsx-ajax-search-results').show();
                                    } else {
                                        $headerSearch.find('.newsx-ajax-search-results').hide();
                                        setTimeout(function() {
                                            $headerSearch.find('.newsx-ajax-search-results ul').html('');
                                            $headerSearch.find('.wpr-no-results').remove();
                                        }, 600);
                                    }
                                }, 100);
                            },
                            error: function(error) {
                                console.log(error);
                            }
                        });
                    }, 400);
                });
            });
        }
    }

    NewsxAjaxSearch.init();

    /* Swiper Adjustments
    -------------------------------------------------- */
    var NewsxSwiperAdjustments = {
        init: function() {
            NewsxSwiperAdjustments.headerNewsTicker();
            NewsxSwiperAdjustments.widgetNewsTicker();
            NewsxSwiperAdjustments.sliderCarousel();
            NewsxSwiperAdjustments.formatGallery();
        },

        headerNewsTicker: function() {
            let $headerTicker = $('.newsx-header-news-ticker');

            if ( $headerTicker.length > 0 ) {
                let headerTickerMargin = parseInt($headerTicker.css('margin-right'), 10) + parseInt($headerTicker.css('margin-left'), 10),
                    $tickerHeading = $headerTicker.find('.news-ticker-heading'),
                    tickerHeadingWidth = $('.news-ticker-heading').outerWidth(),
                    gridRowWidth = $tickerHeading.closest('.newsx-builder-grid-row').width();
            
                let rightColumntWidth = 0,
                    $rightColumn = $tickerHeading.closest('.newsx-builder-grid-row').find('.newsx-grid-column-right');
            
                if ( 'none' !== $rightColumn.css('display') ) {
                    $rightColumn.children(':visible').each(function() {
                        rightColumntWidth += $(this).outerWidth(true);
                    });

                    rightColumntWidth += 25; // for extra gaph
                }
            
                var headerTickerWidth = gridRowWidth - tickerHeadingWidth - rightColumntWidth - headerTickerMargin;

                let tickerArrowWidth = 0,
                    $headerTickerArrNext = $headerTicker.find('.newsx-slider-next');

                if ( 'slider' === $headerTicker.attr('data-ticker-type') && $headerTickerArrNext.length > 0 && 'none' !== $headerTickerArrNext.css('display') ) {
                    tickerArrowWidth = $headerTickerArrNext.outerWidth() * 2 + 10;
                }

                // on Load
                $headerTicker.find('.news-ticker-wrapper').css('max-width', headerTickerWidth );
                $headerTicker.find('.news-ticker-content').css('max-width', (headerTickerWidth - tickerArrowWidth ));
                $headerTicker.find('.news-ticker-wrapper').css({'height' : 'auto'});
                $headerTicker.find('.news-ticker-wrapper').animate({'opacity' : 1}, 500);
            }

            // Add Blur Effect
            NewsxSwiperAdjustments.headerNewsTickerBlurEffect();
        },

        headerNewsTickerBlurEffect: function() {
            let $headerTicker = $('.newsx-header-news-ticker');

            let tickerBg = $headerTicker.find('.news-ticker-wrapper').css('background-color'),
                sectionBg = $headerTicker.closest('.newsx-section-wrap').css('background-color'),
                blurBg = ('rgba(0, 0, 0, 0)' !== tickerBg && '' !== tickerBg) ? tickerBg : sectionBg;

            $headerTicker.find('.news-ticker-content').css('--newsx-hd-ticker-bg', blurBg);
        },

        widgetNewsTicker: function() {
            let widgetTicker = $('.newsx-news-ticker-widget');

            if ( widgetTicker.length > 0 ) {
                widgetTicker.each(function() {
                    let widgetTickerWrapperWidth = $(this).width(),
                        widgetTicker = $(this).find('.newsx-news-ticker'),
                        tickerHeadingWidth = $(this).find('.news-ticker-heading').outerWidth();


                    let tickerArrowWidth = 0,
                        $widgetTickerArrowNext = $(this).find('.newsx-slider-next');

                    if ( 'slider' === widgetTicker.attr('data-ticker-type') && $widgetTickerArrowNext.length > 0 && 'none' !== $widgetTickerArrowNext.css('display') ) {
                        tickerArrowWidth = $widgetTickerArrowNext.outerWidth() * 2 + 10;
                    }

                    let widgetTickerWidth = widgetTickerWrapperWidth - tickerHeadingWidth;

                    $(this).find('.news-ticker-wrapper').css('max-width', widgetTickerWidth);
                    $(this).find('.news-ticker-content').css('max-width', (widgetTickerWidth - tickerArrowWidth ));
                    $(this).find('.news-ticker-wrapper').css({'height' : 'auto'});
                    $(this).find('.news-ticker-wrapper').animate({'opacity' : 1}, 500);
                });
            }

            // Add Blur Effect
            NewsxSwiperAdjustments.widgetNewsTickerBlurEffect();
        },

        widgetNewsTickerBlurEffect: function() {
            let widgetTicker = $('.newsx-news-ticker-widget');

            let siteContentBg = $('.site-content').css('background-color'),
                blurBg = ('rgba(0, 0, 0, 0)' !== siteContentBg && '' !== siteContentBg) ? siteContentBg : '#fff';

            widgetTicker.find('.news-ticker-content').css('--newsx-wdg-ticker-bg', blurBg);
        },

        sliderCarousel: function() {
            let sliderCarousel = $('.newsx-slider-wrap');

            if ( sliderCarousel.length > 0 ) {
                sliderCarousel.each(function() {
                    let sliderCarouselWidth = $(this).parent().width();
                    
                    $(this).css('max-width', (sliderCarouselWidth - 10));
                    $(this).css('max-width', sliderCarouselWidth);

                    // Loading Fix
                    $(this).animate({'opacity' : 1}, 300);
                });
            }
        },

        formatGallery: function() {
            if ( ! $('body').hasClass('single-format-gallery') ) {
                return;
            }

            let $newsxSingleWrap = $('.newsx-single-wrap'),
                $singlePostMedia = $('.newsx-single-post-media'),
                singlePostMediaWidth = $singlePostMedia.outerWidth(),
                $formatGallery = $('.format-gallery-wrapper'),
                postHeaderWidth = $('.newsx-single-post-header').outerWidth(),
                formatGalleryWidth = $newsxSingleWrap.hasClass('newsx-s7') ? singlePostMediaWidth : postHeaderWidth;

            if ( $formatGallery.length > 0 ) {
                $singlePostMedia.hide();
                $formatGallery.css('max-width', '');

                $formatGallery.css('max-width', formatGalleryWidth);

                $singlePostMedia.fadeIn(500);
            }
        }
    }

    NewsxSwiperAdjustments.init();

    /* News Ticker
    -------------------------------------------------- */
    var NewsxNewsTicker = {
        init: function() {
            $('.newsx-news-ticker').each(function() {
                let $widget = $(this);
    
                // Initialize the swiper for news ticker if applicable
                if ( $widget.find('.swiper').length > 0 )  {
                    let settings = $widget.find('.news-ticker-wrapper').data('newsx-settings'),
                        layout = parseInt(settings.layout, 10);
    
                    const swiper = new Swiper($widget.find('.news-ticker-content')[0], { // show on hover in general section
                        slidesPerView: layout,
                        spaceBetween: 10,
                        loop: true,
                        navigation: {
                            nextEl: $widget.find('.newsx-slider-next')[0],
                            prevEl: $widget.find('.newsx-slider-prev')[0],
                        },
                        speed: 1000,
                        autoplay: 1 == settings.autoplay ? {
                            delay: +settings.delay,
                            pauseOnMouseEnter: true,
                        } : false,
                        breakpointsBase: 'window',
                        breakpoints: {
                            0: {
                                slidesPerView: 1
                            },
                            [NewsxMain.tablet_bp - 1]: { // 767px
                                slidesPerView: Math.min(2, layout)
                            },
                            1024: {
                                slidesPerView: Math.min(3, layout)
                            },
                            1280: {
                                slidesPerView: layout
                            }
                        },
                    });
    
                    $widget.find('.news-ticker-content').addClass('newsx-ticker-visible');

                    // Fix Text overflow
                    if ( $widget.hasClass('newsx-header-news-ticker') ) {
                        $widget.addClass('newsx-ellipsis');
                    }
                } else {
                    if ($widget.find('.newsx-ticker-marquee').find('js-marquee').length == 0) {
                        var marqueeData = $widget.find('.newsx-ticker-marquee').data('options');
                        $widget.find('.newsx-ticker-marquee').marquee(marqueeData);
                    }
                }
            });
        }
    };    

    NewsxNewsTicker.init();

    /* Slider and Carousel
    -------------------------------------------------- */
    var NewsxSliderCarousel = {
        init: function()  {
            $('.widget.newsx-slider-widget, .newsx-blog-page-wrap > .newsx-slider-wrap').each(function() {

                if ( $(this).find('.swiper').length > 0 )  {
                    let $widget = $(this),
                        settings = $widget.find('.swiper-wrapper').data('newsx-settings'),
                        gutter = typeof settings.gutter !== 'object' ? JSON.parse(settings.gutter) : settings.gutter,
                        layout = parseInt(settings.layout, 10),
                        autoplay_delay = $(this).closest('.newsx-widget').length ? settings.delay : settings.autoplay_delay;
        
                    const swiper = new Swiper($widget.find('.newsx-swiper')[0], {
                        slidesPerView: layout,
                        spaceBetween: gutter.desktop || 20,
                        loop: true,
                        navigation: {
                            nextEl: $widget.find('.newsx-slider-next')[0],
                            prevEl: $widget.find('.newsx-slider-prev')[0],
                        },
                        speed: 1000,
                        autoplay: 1 == settings.autoplay ? {
                            delay: +autoplay_delay,
                        } : false,
                        breakpointsBase: 'window',
                        breakpoints: {
                            0: {
                                slidesPerView: 1,
                                spaceBetween: gutter.mobile || 20
                            },
                            [NewsxMain.tablet_bp - 1]: { // 767px
                                slidesPerView: Math.min(2, layout),
                                spaceBetween: gutter.tablet || 20
                            },
                            1024: {
                                slidesPerView: Math.min(3, layout),
                                spaceBetween: gutter.desktop || 20
                            },
                            1580: {
                                slidesPerView: layout,
                                spaceBetween: gutter.desktop || 20
                            }
                        },
                    });
                }
            });
        }
    }

    NewsxSliderCarousel.init();

    /* Desktop Menu
    -------------------------------------------------- */
    // Add keyboard navigation for desktop menu
    $('.newsx-desktop-menu li').on('focusin focusout', function(e) {
        let $menuItem = $(this);
        let $subMenu = $menuItem.children('.sub-menu');
        
        if ($subMenu.length) {
            if (e.type === 'focusin') {
                $subMenu.addClass('active');
            } else {
                // Check if focus is still within this menu item
                setTimeout(() => {
                    if (!$menuItem.find(':focus').length) {
                        $subMenu.removeClass('active');
                    }
                }, 0);
            }
        }
    });

    /* Mobile Menu
    -------------------------------------------------- */
    $('.newsx-mobile-menu-toggle').on('click', function() {
        $(this).toggleClass('active');
        $(this).next('nav').slideToggle();
    });

    $('.newsx-mobile-menu .sub-menu-icon').on('click', function(e) {
        e.preventDefault();
        $(this).closest('li').children('.sub-menu').slideToggle();
    });

    /* Off-Canvas
    -------------------------------------------------- */
    $('.newsx-offcanvas-btn').on('click', function() {
        $('.newsx-offcanvas-wrap').addClass('active');
        $('.newsx-offcanvas-overlay').fadeIn();

        if ( $('body').hasClass('customize-partial-edit-shortcuts-shown') ) {
            $('.newsx-customize-edit[data-section="newsx_section_hd_top"]').hide();
        }
    });

    $('.newsx-offcanvas-close-btn').on('click', function() {
        $('.newsx-offcanvas-wrap').removeClass('active');
        $('.newsx-offcanvas-overlay').fadeOut();

        if ( $('body').hasClass('customize-partial-edit-shortcuts-shown') ) {
            $('.newsx-customize-edit[data-section="newsx_section_hd_top"]').show();
        }
    });

    $('.newsx-offcanvas-overlay').on('click', function() {
        $('.newsx-offcanvas-wrap').removeClass('active');
        $('.newsx-offcanvas-overlay').fadeOut();

        if ( $('body').hasClass('customize-partial-edit-shortcuts-shown') ) {
            $('.newsx-customize-edit[data-section="newsx_section_hd_top"]').show();
        }
    });

    /* Header Search
    -------------------------------------------------- */
    var $headerSearch = $('.newsx-header-search');

    $headerSearch.find('.newsx-search-icon').on('click', function() {
        if ($headerSearch.hasClass('header-search-s1') && $headerSearch.hasClass('active')) {
            $headerSearch.find('.search-form').submit();
        } else {
            $headerSearch.toggleClass('active');
            $headerSearch.find('input').val('').focus();

            if ($headerSearch.hasClass('header-search-s0')) {
                $headerSearch.find('.search-form').submit();
            }
        }
    });
    

    // Close search on click outside
    $(document).on('click', function (e) {
        if ( $(e.target).is('.newsx-search-icon') || $(e.target).closest('.newsx-header-search').length ) {
            return;
        }

        $headerSearch.removeClass('active');
        $('.newsx-ajax-search-results').hide();
    });

    // 404 Page Search
    $('.newsx-404-page-search').find('.newsx-search-icon').on('click', function() {
        $('.newsx-404-page-search').find('.search-form').submit();
    });

    /* Date and Time
    -------------------------------------------------- */
    function updateTime() {
        const now = new Date();
        let hours = now.getHours();
        const ampm = hours >= 12 ? 'PM' : 'AM';
        hours = hours % 12;
        hours = hours ? hours : 12; // Convert 0 to 12
        const minutes = String(now.getMinutes()).padStart(2, '0');
        const seconds = String(now.getSeconds()).padStart(2, '0');
        $('.newsx-site-header').find('.newsx-date-and-time span:last-child span:last-child').text(hours + ':' + minutes + ':' + seconds + ' ' + ampm);
    }
    
    // Update time immediately and then every second
    updateTime();
    setInterval(updateTime, 1000);

    /* Grid Filters
    -------------------------------------------------- */
    var NewsxGridFilters = {
        init: function() {
            $('.newsx-grid-ajax-filters').each(function() {
                let section = $(this).closest('section'),
                    filters = $(document).find('.newsx-grid-filter');

                // on Load
                NewsxGridFilters.adjustFilters( section );
                $(this).addClass('newsx-visible');

                // on Resize
                $(window).smartresize(function() {
                    NewsxGridFilters.adjustFilters( section );
                });

                // Trigger Filters Click
                filters.off('click');
                filters.on('click', NewsxGridFilters.onFilterClick);
            });
        },

        onFilterClick: function() {
            // Scope
            let $this = $(this),
                $scope = $this.closest('section');

            // Reset
            $scope.find('.newsx-grid-filter').removeClass('active');
            $scope.find('.newsx-ajax-next, .newsx-ajax-prev').attr('data-page', 0);

            // Set Active
            $this.addClass('active');

            // Load Posts
            NewsxGridAjaxPosts.currentPageNumber = NewsxGridAjaxPosts.getCurrentPageNumber($this);
            NewsxGridAjaxPosts.fetchPosts(NewsxGridAjaxPosts.currentPageNumber, $this);
        },

        adjustFilters: function( $scope ) {
            let scopeWidth = $scope.outerWidth(),

                title =  $scope.find('.newsx-widget-title-text'),
                titleWidth = title.outerWidth(),

                filters = $scope.find('.newsx-grid-ajax-filters'),
                filtersUL = filters.children('ul'),
                filtersWidth = filters.outerWidth(),
                filtersCount = filtersUL.children().length,

                moreDropdown = $scope.find('.newsx-grid-filters-dropdown'),
                moreFiltersCount = moreDropdown.children().length,

                distance = scopeWidth - titleWidth - filtersWidth;

            // Move from dropdown back to filters
            if ( distance > 100 ) {
                for ( let i = 0; i < moreFiltersCount; i++ ) {
                    let filter = moreDropdown.children().first();
                        distance = scopeWidth - titleWidth - filtersWidth;

                    if ( distance > 100 ) {
                        filtersUL.append( filter.remove() );

                        filtersWidth = filters.outerWidth();
                        moreFiltersCount = moreDropdown.children().length
                    }
                }
            }

            // Move filters to dropdown
            if ( distance <= 100 ) {
                for ( let i = 0; i < filtersCount; i++ ) {
                    let filter = filtersUL.children().last();
                        distance = scopeWidth - titleWidth - filtersWidth;

                    if ( distance <= 100 ) {
                        moreDropdown.prepend( filter.remove() );

                        filtersWidth = filters.outerWidth();
                        moreFiltersCount = moreDropdown.children().length
                    }
                }
            }
            
            // Show/Hide Dropdown
            if ( moreFiltersCount > 0 ) {
                $scope.find('.newsx-grid-filters-dropdown-wrap').removeClass('newsx-hidden');
            } else {
                $scope.find('.newsx-grid-filters-dropdown-wrap').addClass('newsx-hidden');
            }
        }
    }
 
    /* Grid Pagination
    -------------------------------------------------- */
    var NewsxGridPagination = {
        init: function() {
            // Previous Button click
            NewsxGridPagination.onPrevButtonClick();

            // Next Button click
            NewsxGridPagination.onNextButtonClick();

            // Load More Button click
            NewsxGridPagination.onLoadMoreButtonClick();
        },

        onPrevButtonClick: function() {
            $(document).find('.newsx-ajax-prev').on('click', function() {
                // Get the current page number
                NewsxGridAjaxPosts.currentPageNumber = NewsxGridAjaxPosts.getCurrentPageNumber($(this));

                if ( NewsxGridAjaxPosts.currentPageNumber > 0 ) {
                    // Load Posts
                    NewsxGridAjaxPosts.fetchPosts(NewsxGridAjaxPosts.currentPageNumber - 1, $(this));

                    // Set Data Page
                    $(this).attr('data-page', NewsxGridAjaxPosts.currentPageNumber - 1);
                    $(this).next().attr('data-page', NewsxGridAjaxPosts.currentPageNumber - 1);
                }
            });
        },

        onNextButtonClick: function() {
            $(document).find('.newsx-ajax-next').on('click', function() {
                // Get the current page number
                NewsxGridAjaxPosts.currentPageNumber = NewsxGridAjaxPosts.getCurrentPageNumber($(this));

                // Load Posts
                NewsxGridAjaxPosts.fetchPosts(NewsxGridAjaxPosts.currentPageNumber + 1, $(this));

                // Set Data Page
                $(this).attr('data-page', NewsxGridAjaxPosts.currentPageNumber + 1);
                $(this).prev().attr('data-page', NewsxGridAjaxPosts.currentPageNumber + 1);
            });
        },

        onLoadMoreButtonClick: function() {
            $(document).find('.newsx-load-more').on('click', function() {
                // Get the current page number
                NewsxGridAjaxPosts.currentPageNumber = NewsxGridAjaxPosts.getCurrentPageNumber($(this));

                // Load Posts
                NewsxGridAjaxPosts.fetchPosts(NewsxGridAjaxPosts.currentPageNumber + 1, $(this));

                // Set Data Page
                $(this).attr('data-page', NewsxGridAjaxPosts.currentPageNumber + 1);
                $(this).prev().attr('data-page', NewsxGridAjaxPosts.currentPageNumber + 1);
            });
        },
    }

    /* Load Posts with AJAX
    -------------------------------------------------- */
    var NewsxGridAjaxPosts = {
        pageCacheKey: 'newsxPageCache',
        currentPageNumber: 0,

        init: function() {
            // Reset
            this.clearCache();
            
            // Init Grid Pagination
            NewsxGridPagination.init();

            // Init Grid Filters
            NewsxGridFilters.init();
        },

        clearCache: function() {
            localStorage.removeItem(self.pageCacheKey);
        },

        fetchPosts: function( pageNumber, eventTarget ) {
            // Define Variables
            let $scope = $(eventTarget).closest('section'),
                targetGrid = $('[data-id="' + $scope.attr('id') + '"]'),
                activeFilter = $scope.find('.active').data('filter') || 'all',
                widgetInstance = targetGrid.data('newsx-settings'),
                filterTaxonomy = $scope.find('.newsx-grid-ajax-filters').data('taxonomy');

            // Change Offset according to page number
            widgetInstance._main_query_args.offset = pageNumber * widgetInstance._main_query_args.posts_per_page;

            // Set Tax Query
            if ( $(eventTarget).hasClass('newsx-grid-filter') ) {
                if ( 'all' === $scope.find('.active').data('filter') ) {
                    widgetInstance._main_query_args.tax_query = [];
                } else {
                    widgetInstance._main_query_args.tax_query = [{
                        taxonomy: filterTaxonomy,
                        field: 'slug',
                        terms: [ activeFilter ]
                    }];
                }

                pageNumber = 0;
            }

            if ( eventTarget.hasClass('newsx-load-more') ) {
                widgetInstance._loading_more = true;
            } else {
                widgetInstance._loading_more = false;
            }

            // Get Post Count
            $.ajax({
                url: NewsxMain.ajaxurl,
                type: 'POST',
                data: {
                    'action': 'get_post_count',
                    'nonce': NewsxMain.nonce,
                    '_main_query_args': JSON.stringify(widgetInstance._main_query_args)
                },
                success: function (response) {
                    if ( response.data.post_count - response.data.args.offset <= response.data.args.posts_per_page ) {
                        $scope.find('.newsx-ajax-next').addClass('newsx-disabled');
                        $scope.find('.newsx-load-more').fadeOut();
                    } else {
                        $scope.find('.newsx-ajax-next').removeClass('newsx-disabled');
                        $scope.find('.newsx-load-more').removeClass('newsx-loading');
                    }
                },
                error: function () {
                    console.error('Failed to fetch posts.');
                }
            });

            // Preloader
            let preloaderHTML = '<div class="newsx-ring-loader"><div></div><div></div><div></div><div></div></div>',
                gridHeight = targetGrid.outerHeight();

            if ( ! $(eventTarget).hasClass('newsx-load-more') ) {
                targetGrid.addClass('newsx-loading');
                targetGrid.html( preloaderHTML );
                targetGrid.css('min-height', gridHeight + 'px');
            } else {
                $scope.find('.newsx-load-more').addClass('newsx-loading');
            }
            
            // Get new content
            $.ajax({
                url: NewsxMain.ajaxurl,
                type: 'POST',
                data: {
                    action: 'load_posts_by_ajax',
                    nonce: NewsxMain.nonce,
                    newsxpage: pageNumber,
                    instance: JSON.stringify(widgetInstance)
                },
                success: function (response) {
                    // Add class to new posts
                    let newPosts = $(response);
                        newPosts.addClass('newsx-animated-post');

                    // Load More button
                    if ( $(eventTarget).hasClass('newsx-load-more') ) {
                        targetGrid.append(newPosts);
                    // Next/Previous button
                    } else {
                        targetGrid.removeClass('newsx-loading');
                        targetGrid.html(newPosts);
                        targetGrid.css('min-height', '');
                    }

                    // Animate Posts
                    targetGrid.find('.newsx-grid-item').animate({
                        opacity: 1,
                        top: 0,
                    }, 300);
                },
                error: function () {
                    console.error('Failed to fetch posts.');
                }
            });

            // Animate Posts
            if ( ! $(eventTarget).hasClass('newsx-load-more') ) {
                targetGrid.find('.newsx-grid-item').addClass('newsx-animated-post');
                targetGrid.find('.newsx-grid-item').animate({
                    opacity: 1,
                    top: 0,
                }, 300);
            }

            // Set Navigation as Disabled
            if ( pageNumber == 0 ) {
                $scope.find('.newsx-ajax-prev').addClass('newsx-disabled');
            } else {
                $scope.find('.newsx-ajax-prev').removeClass('newsx-disabled');
            }
        },

        // Get the current page number
        getCurrentPageNumber: function ( eventTarget ) {
            let activeFilter = $(eventTarget).closest('section').find('.newsx-grid-filter.active').data('filter'),
                beforeExtract = localStorage.getItem('currentPage') || (0 + activeFilter),
                attrDataPage = $(eventTarget).attr('data-page'),
                number;

            if ( attrDataPage ) {
                number = parseInt(attrDataPage);
            } else {
                let extract = beforeExtract.match(/\d+/);
                number = extract ? parseInt(extract[0], 10) : null;
            }

            return number;
        }
    }

    // Init Ajax Posts
    NewsxGridAjaxPosts.init();

    /* Video Playlist
    -------------------------------------------------- */
    var NewsxVideoPlaylist = {
        players: {},
        isAPIReady: false,
        pendingPlayers: [],

        init: function() {
            let videoPlaylists = $('.newsx-video-playlist-widget');

            if (videoPlaylists.length > 0) {
                // Load YouTube IFrame API
                const tag = document.createElement('script');
                tag.src = "//www.youtube.com/iframe_api";
                const firstScriptTag = document.getElementsByTagName('script')[0];
                firstScriptTag.parentNode.insertBefore(tag, firstScriptTag);

                // Set up global callback for YouTube API
                window.onYouTubeIframeAPIReady = function() {
                    NewsxVideoPlaylist.isAPIReady = true;
                    NewsxVideoPlaylist.pendingPlayers.forEach(function(playerData) {
                        NewsxVideoPlaylist.createPlayer(playerData.$container, playerData.videoId, playerData.$widget);
                    });
                };

                // Process video data and create playlist items
                videoPlaylists.each(function() {
                    var $widget = $(this);
                    var urls = $widget.find('.newsx-vplaylist-thumbs').data('urls');
                    NewsxVideoPlaylist.processVideos($widget, urls);
                });
            }
        },

        processVideos: function($widget, urls) {
            var $playlistUl = $widget.find('.newsx-vplaylist-thumbs ul');
            var title_tag = $widget.find('.newsx-vplaylist-current-title').prop('tagName').toLowerCase();

            // Create array of promises for video data
            const videoPromises = urls.map((url, index) => {
                return new Promise((resolve) => {
                    var videoId = NewsxVideoPlaylist.extractVideoId(url);
                    if (!videoId) {
                        resolve(null);
                        return;
                    }

                    $.get('https://www.youtube.com/oembed', {
                        url: url,
                        format: 'json'
                    })
                    .done(function(response) {
                        resolve({
                            videoId: videoId,
                            title: response.title,
                            index: index
                        });
                    })
                    .fail(function() {
                        resolve(null);
                    });
                });
            });

            // Wait for all video data to be fetched
            Promise.all(videoPromises).then(results => {
                // Filter out null results and sort by original index
                const validResults = results.filter(result => result !== null)
                                      .sort((a, b) => a.index - b.index);

                validResults.forEach((video, index) => {
                    // Create playlist item
                    var $li = $('<li>').attr('data-video', video.videoId);
                    var thumbnailUrl = 'https://i.ytimg.com/vi/'+ video.videoId +'/maxresdefault.jpg';
                    
                    $li.append($('<img>').attr({
                        'src': thumbnailUrl,
                        'alt': video.title
                    }));

                    var $info = $('<div>').addClass('newsx-vplaylist-info');
                    $info.append($('<'+ title_tag +'>').addClass('newsx-vplaylist-info-title').text(video.title));

                    $li.append($info);
                    $playlistUl.append($li);

                    // Set first video title and data
                    if (index === 0) {
                        $widget.find('.newsx-vplaylist-current-title').text(video.title);
                        $widget.find('.newsx-vplaylist-highlight').attr('data-video', video.videoId);
                        
                        // Initialize the first video
                        var $player = $widget.find('.newsx-vplaylist-main');
                        if (NewsxVideoPlaylist.isAPIReady) {
                            NewsxVideoPlaylist.createPlayer($player, video.videoId, $widget);
                        } else {
                            NewsxVideoPlaylist.pendingPlayers.push({
                                $container: $player,
                                videoId: video.videoId,
                                $widget: $widget
                            });
                        }
                    }
                });

                // Set up click handlers for playlist items
                $widget.find('.newsx-vplaylist-thumbs li').on('click', function() {
                    var videoId = $(this).data('video');
                    var playerId = $widget.find('.newsx-vplaylist-main').attr('id');
                    var videoTitle = $(this).find('.newsx-vplaylist-info-title').text();
                    
                    if (NewsxVideoPlaylist.players[playerId]) {
                        NewsxVideoPlaylist.players[playerId].loadVideoById(videoId);
                        
                        $widget.find('.newsx-vplaylist-highlight').attr('data-video', videoId);
                        $widget.find('.newsx-vplaylist-current-title').text(videoTitle);
                        
                        $widget.find('.newsx-play').hide();
                        $widget.find('.newsx-pause').show();
                    }
                });
            });
        },

        createPlayer: function($container, videoId, $widget) {
            var playerId = 'newsx-player-' + Math.random().toString(36).substr(2, 9);
            $container.attr('id', playerId);

            this.players[playerId] = new YT.Player(playerId, {
                height: '360',
                width: '640',
                videoId: videoId,
                playerVars: {
                    'autoplay': 0,
                    'controls': 1,
                    'rel': 0,
                    'showinfo': 0
                },
                events: {
                    'onReady': function() {
                        // Set up play/pause controls once player is ready
                        $widget.find('.newsx-vplaylist-controller').on('click', function() {
                            var player = NewsxVideoPlaylist.players[playerId];
                            
                            if (player.getPlayerState() === YT.PlayerState.PLAYING) {
                                player.pauseVideo();
                                $(this).find('.newsx-play').show();
                                $(this).find('.newsx-pause').hide();
                            } else {
                                player.playVideo();
                                $(this).find('.newsx-play').hide();
                                $(this).find('.newsx-pause').show();
                            }
                        });
                    },
                    'onStateChange': function(event) {
                        var $controller = $widget.find('.newsx-vplaylist-controller');
                        
                        // YT.PlayerState.PLAYING = 1
                        // YT.PlayerState.PAUSED = 2
                        // YT.PlayerState.ENDED = 0
                        if (event.data === YT.PlayerState.PLAYING) {
                            $controller.find('.newsx-play').hide();
                            $controller.find('.newsx-pause').show();
                        } else if (event.data === YT.PlayerState.PAUSED || event.data === YT.PlayerState.ENDED) {
                            $controller.find('.newsx-play').show();
                            $controller.find('.newsx-pause').hide();
                        }
                    }
                }
            });

            return playerId;
        },

        extractVideoId: function(url) {
            var pattern = /(?:youtube\.com\/(?:[^\/]+\/.+\/|(?:v|e(?:mbed)?)\/|.*[?&]v=)|youtu\.be\/)([^"&?\/\s]{11})/i;
            var match = url.match(pattern);
            return match ? match[1] : null;
        }
    };

    NewsxVideoPlaylist.init();

    /* Table of Contents
    -------------------------------------------------- */
    $('.newsx-table-of-contents a').on('click', function(event) {
        event.preventDefault();
        let stickyHeaderHeight = 0;

        $('.newsx-site-header .newsx-section-wrap').each(function() {
            if ( 'yes' === $(this).data('sticky') ) {
                stickyHeaderHeight += $(this).outerHeight();
            }
        });

        // Animate the scroll to the target section
        $('html, body').animate({
            scrollTop: $($.attr(this, 'href')).offset().top - stickyHeaderHeight - 10
        }, 500);
    });

    /* Reading Progress Bar
    -------------------------------------------------- */
    var NewsxReadingProgressBar = {
      init: function() {
        let scrollTargetPost = $('.newsx-single-content-wrap');

        $(window).scroll(function() {
          $('body').find('.newsx-single-content-wrap').each(function() {
            let postTop = $(this).offset().top,
                postBottom = postTop + $(this).outerHeight(),
                scrollTop = $(window).scrollTop(),
                scrollBottom = scrollTop + $(window).height(),
                progress = 0;

            if ( scrollTop < scrollTargetPost.offset().top ) {
                $('.newsx-reading-progress-bar').css('width', 0 + '%');
            }

            if (scrollTop > (postTop + 5) && scrollTop < (postBottom - 5)) {
                // Element is partially or fully in view

                if (postBottom > scrollBottom ) {
                    progress = ((scrollTop - postTop) / ($(this).outerHeight())) * 100;
                } else {
                    progress = ((scrollBottom - postTop) / (scrollBottom - scrollTop)) * 100;
                }

                progress = Math.round(Math.min(100, Math.max(0, progress))); // Clamp between 0 and 100
                $('.newsx-reading-progress-bar').css('width', progress + '%');

                if (progress >= 100) progress = 0; // Stop the loop once 100% progress is reached for an element
            }
          });
        });
      }
    }

    NewsxReadingProgressBar.init();

    /* Load Single Posts
    -------------------------------------------------- */
    var NewsxLoadSinglePosts = {  
        init: function() {
            let loadNext = true,
                currentPostHTML = $('.newsx-single-wrap');

            if ( currentPostHTML.length > 0 && 'yes' === currentPostHTML.data('load-posts') ) {
                let currentPostID =  $('.newsx-single-wrap').find('article').attr('id').replace('post-', ''),
                    preloaderHTML = '<div class="newsx-ring-loader"><div></div><div></div><div></div><div></div></div>';
    
                $(window).scroll(NewsxLoadSinglePosts.debounce(function() {
                    let contentBottom = currentPostHTML.offset().top + currentPostHTML.height(),
                        windowBottom = $(window).scrollTop() + $(window).height();

                    if (windowBottom > contentBottom && loadNext ) {
                        if ( $('body').find('.newsx-ring-loader').length == 0 ) {
                           currentPostHTML.after(preloaderHTML);
                        }
    
                        $.ajax({
                            url: NewsxMain.ajaxurl,
                            type: 'POST',
                            data: {
                                action: 'load_single_posts_by_ajax',
                                post_id: currentPostID,
                                nonce: NewsxMain.nonce
                            },
                            success: function(response) {
                                if (response.success) {
                                    let nextPostContent = $(response.data),
                                        nextPostID = '#' + nextPostContent.find('article').attr('id');

                                    if ( !($(nextPostID).length > 0) ) {
                                        $('body').find('.newsx-ring-loader').remove();
                                       currentPostHTML.after(nextPostContent);
                                        
                                        currentPostHTML = $(nextPostID).closest('.newsx-single-wrap');
                                        currentPostID = nextPostID.replace('#post-', '');
                                    }
                                } else  {
                                    $('body').find('.newsx-ring-loader').remove();
                                    loadNext = false;
                                }
                            },
                            error: function(response) {
                                console.log(response);
                            }
                        });
                        return false; // Break the loop after loading the next post
                    }
                }, 10));
            }
        },
        
        debounce: function(func, wait) {
            let timeout;
            return function() {
                const context = this, args = arguments;
                clearTimeout(timeout);
                timeout = setTimeout(() => func.apply(context, args), wait);
            };
        }
    }

    NewsxLoadSinglePosts.init();
 

    /* Tabs Widget
    -------------------------------------------------- */
    var NewsxTabsWidget = {
        init: function() {
            $('.newsx-tabs').each(function() {
                NewsxTabsWidget.tabLabelClick( $(this).closest('section.newsx-widget') );
            });
        },

        tabLabelClick: function( $scope ) {
            let tabLabel = $scope.find('.newsx-tabs li');

            tabLabel.on('click', function() {
                let tabIndex = $(this).data('tab-id');

                // Remove Active Class
                $scope.find('.newsx-tabs li').removeClass('active');
                $scope.find('.newsx-tab-content').removeClass('active').hide();

                // Add Active Class
                $(this).addClass('active');
                $scope.find('.newsx-tab-content.newsx-tab-' + tabIndex).addClass('active').fadeIn(400);
            });
        }
    }

    // Init Tabs
    NewsxTabsWidget.init();

    /* Post Sharing
    -------------------------------------------------- */
    var NewsxPostSharing = {
        init: function() {
            NewsxPostSharing.copyLink();
        },

        copyLink: function() {
            const copyButton = $('a.copy-share');

            copyButton.off('click').on('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                
                let target = $(this),
                    link = target.attr('href'),
                    copied = target.data('copied');

                if (link) {
                    // Create temporary textarea
                    let textarea = document.createElement('textarea');
                    textarea.value = link;
                    textarea.setAttribute('readonly', '');
                    textarea.style.position = 'absolute';
                    textarea.style.left = '-9999px';
                    document.body.appendChild(textarea);

                    // Select and copy text
                    textarea.select();
                    try {
                        document.execCommand('copy');
                        $('body').find('.tipsy-inner').html('&nbsp;&nbsp;' + copied + '&nbsp;&nbsp;');
                    } catch (err) {
                        console.log('Copy failed');
                    }

                    // Cleanup
                    document.body.removeChild(textarea);
                }
            });
        }
    }

    NewsxPostSharing.init();

    /* Baack to Top
    -------------------------------------------------- */
    let $backToTop = $('#newsx-back-to-top');

    if ( $backToTop.length ) {
        $(window).scroll(function() {
            if ($(this).scrollTop() > 100) {
                $backToTop.css('opacity', 1);
            } else {
                $backToTop.css('opacity', 0);
            }
        });
        
        $backToTop.on('click', function() {
            $('html, body').animate({ scrollTop: 0 }, 800);
        });
    }

    /* Preloader
    -------------------------------------------------- */
    if ( $('.newsx-preloader-wrap').length ) {
        setTimeout(function(){
            $('.newsx-preloader-wrap > div').fadeOut( 600 );
            $('.newsx-preloader-wrap').fadeOut( 1500 );
        }, 300);

        if ( $('body').hasClass('elementor-editor-active') ) {
            setTimeout(function(){
                $('.newsx-preloader-wrap > div').fadeOut( 600 );
                $('.newsx-preloader-wrap').fadeOut( 1500 );
            }, 300);
        }
    }
    

    /* Tooltips
    -------------------------------------------------- */
    $('a[data-tooltip], .newsx-switch-to-light, .newsx-switch-to-dark').newsxTipsy({
        title: 'data-tooltip',
        fade: true,
        opacity: 1,
        trigger: 'hover'
    });

    /* Resize Events
    -------------------------------------------------- */
    $(window).smartresize(function() {
        NewsxSwiperAdjustments.init();

        // Hide mobile menu on resize
        let $mobileMenuToggle = $('.newsx-mobile-menu-toggle');
        if ( $mobileMenuToggle.hasClass('active') ) {
            $mobileMenuToggle.removeClass('active');
            $mobileMenuToggle.next('.newsx-mobile-menu-container').hide();
        }
    });

    document.querySelectorAll('[tabindex="0"]').forEach(el => {
        el.addEventListener('keydown', (e) => {
            if (e.key === "Enter" || e.key === " ") {
                e.preventDefault(); // Prevent scrolling on Space
                el.click();
            }
        });
    });

} ); // End Ready


// Resize Function - Debounce
(function($,sr){

var debounce = function (func, threshold, execAsap) {
    var timeout;

    return function debounced () {
        var obj = this, args = arguments;
        function delayed () {
            if (!execAsap)
                func.apply(obj, args);
            timeout = null;
        };

        if (timeout)
            clearTimeout(timeout);
        else if (execAsap)
            func.apply(obj, args);

        timeout = setTimeout(delayed, threshold || 300);
    };
}
// smartresize 
jQuery.fn[sr] = function(fn){  return fn ? this.bind('resize', debounce(fn)) : this.trigger(sr); };

})(jQuery,'smartresize');