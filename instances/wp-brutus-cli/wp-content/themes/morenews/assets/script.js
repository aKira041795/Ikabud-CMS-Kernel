(function (e) {
  'use strict';
  var n = window.AFTHRAMPES_JS || {};
  (n.mobileMenu = {
    init: function () {
      this.toggleMenu(), this.menuMobile(), this.menuArrow();

      if (e('.aft-mobile-navigation').length) {
        var navElement = document.querySelector('.aft-mobile-navigation');
        if (navElement) {
          n.trapFocus(navElement);
        }
      }
    },

    toggleMenu: function () {
      e('#masthead').on('click', '.toggle-menu', function (event) {
        event.preventDefault();
        var ethis = e('.main-navigation .menu .menu-mobile');
        if (ethis.css('display') == 'block') {
          ethis.slideUp('300');
        } else {
          ethis.slideDown('300');
        }
        e('.ham').toggleClass('exit');
      });
      e('#masthead .main-navigation ').on(
        'click',
        '.menu-mobile a button',
        function (event) {
          event.preventDefault();
          var ethis = e(this),
            eparent = ethis.closest('li');
          if (eparent.find('> .children').length) {
            var esub_menu = eparent.find('> .children');
          } else {
            var esub_menu = eparent.find('> .sub-menu');
          }
          if (esub_menu.css('display') == 'none') {
            esub_menu.slideDown('300');
            ethis.addClass('active');
          } else {
            esub_menu.slideUp('300');
            ethis.removeClass('active');
          }
          return false;
        }
      );
    },
    menuMobile: function () {
      if (e('.main-navigation .menu > ul').length) {
        var ethis = e('.main-navigation .menu > ul'),
          eparent = ethis.closest('.main-navigation'),
          pointbreak = eparent.data('epointbreak'),
          window_width = window.innerWidth;
        if (typeof pointbreak == 'undefined') {
          pointbreak = 991;
        }
        if (pointbreak >= window_width) {
          ethis.addClass('menu-mobile').removeClass('menu-desktop');
          e('.main-navigation .toggle-menu').css('display', 'block');
          e('.main-navigation').addClass('aft-mobile-navigation');
        } else {
          ethis
            .addClass('menu-desktop')
            .removeClass('menu-mobile')
            .css('display', '');
          e('.main-navigation .toggle-menu').css('display', '');
          e('.main-navigation').removeClass('aft-mobile-navigation');
        }

        if (e('.aft-mobile-navigation').length) {
          var navElement = document.querySelector('.aft-mobile-navigation');
          if (navElement) {
            n.trapFocus(navElement);
          }
        }
      }
    },
    menuArrow: function () {
      if (e('#masthead .main-navigation div.menu > ul').length) {
        e('#masthead .main-navigation div.menu > ul .sub-menu')
          .parent('li')
          .find('> a')
          .append('<button class="fa fa-angle-down">');
        e('#masthead .main-navigation div.menu > ul .children')
          .parent('li')
          .find('> a')
          .append('<button class="fa fa-angle-down">');
      }
    },
  }),
    (n.trapFocus = function (element) {
      var focusableEls = element.querySelectorAll(
          'a[href]:not([disabled]), button:not([disabled]), textarea:not([disabled]), input:not([disabled]), select:not([disabled])'
        ),
        firstFocusableEl = focusableEls[0],
        lastFocusableEl = focusableEls[focusableEls.length - 1],
        KEYCODE_TAB = 9;

      element.addEventListener('keydown', function (e) {
        var isTabPressed = e.key === 'Tab' || e.keyCode === KEYCODE_TAB;

        if (!isTabPressed) {
          return;
        }

        if (e.shiftKey) {
          /* shift + tab */ if (document.activeElement === firstFocusableEl) {
            lastFocusableEl.focus();
            e.preventDefault();
          }
        } /* tab */ else {
          if (document.activeElement === lastFocusableEl) {
            firstFocusableEl.focus();
            e.preventDefault();
          }
        }
      });
    }),
    (n.Search = function () {
      e('.af-search-click').on('click', function () {
        e('#af-search-wrap').toggleClass('af-search-toggle');
      });
    }),
    (n.Offcanvas = function () {
      e('#sidr').addClass('aft-mobile-off-canvas');
      var offCanvasElement = document.querySelector('.aft-mobile-off-canvas');
      if (offCanvasElement) {
        n.trapFocus(offCanvasElement);
      }

      e('.offcanvas-nav').sidr({
        speed: 300,
        side: 'left',
        displace: false,
      });
      e('.sidr-class-sidr-button-close').on('click', function () {
        e.sidr('close', 'sidr');
      });
    }),
    // SHOW/HIDE SCROLL UP //
    (n.show_hide_scroll_top = function () {
      if (e(window).scrollTop() > e(window).height() / 2) {
        e('#scroll-up').fadeIn(300);
      } else {
        e('#scroll-up').fadeOut(300);
      }
    }),
    (n.scroll_up = function () {
      e('#scroll-up').on('click', function () {
        e('html, body').animate(
          {
            scrollTop: 0,
          },
          800
        );
        return false;
      });
    }),
    (n.MagnificPopup = function () {
      e('div.zoom-gallery').magnificPopup({
        delegate: 'a.insta-hover',
        type: 'image',
        closeOnContentClick: false,
        closeBtnInside: false,
        mainClass: 'mfp-with-zoom mfp-img-mobile',
        image: {
          verticalFit: true,
          titleSrc: function (item) {
            return item.el.attr('title');
          },
        },
        gallery: {
          enabled: true,
        },
        zoom: {
          enabled: true,
          duration: 300,
          opener: function (element) {
            return element.find('img');
          },
        },
      });
      e('.gallery').each(function () {
        e(this).magnificPopup({
          delegate: 'a',
          type: 'image',
          gallery: {
            enabled: true,
          },
          zoom: {
            enabled: true,
            duration: 300,
            opener: function (element) {
              return element.find('img');
            },
          },
        });
      });

      e('.wp-block-gallery').each(function () {
        e(this).magnificPopup({
          delegate: 'a',
          type: 'image',
          gallery: {
            enabled: true,
          },
          zoom: {
            enabled: true,
            duration: 300,
            opener: function (element) {
              return element.find('img');
            },
          },
        });
      });
    }),
    (n.searchReveal = function () {
      jQuery('.search-overlay .search-icon').on('click', function (event) {
        event.preventDefault();
        jQuery(this).parent().toggleClass('reveal-search');

        if (jQuery('.reveal-search').length) {
          var searchElement = document.querySelector('.reveal-search');
          if (searchElement) {
            n.trapFocus(searchElement);
          }
        }
      });

      jQuery('body').on('click', function (e) {
        if (jQuery('.search-overlay').hasClass('reveal-search')) {
          var container = jQuery('.search-overlay');
          if (!container.is(e.target) && container.has(e.target).length === 0) {
            container.removeClass('reveal-search');
          }
        }
      });
    }),
    (n.jQueryMarquee = function () {
      e('.marquee.aft-flash-slide').marquee({
        //duration in milliseconds of the marquee
        speed: 80000,
        //gap in pixels between the tickers
        gap: 0,
        //time in milliseconds before the marquee will start animating
        delayBeforeStart: 0,
        //'left' or 'right'
        // direction: 'right',
        //true or false - should the marquee be duplicated to show an effect of continues flow
        duplicated: true,
        pauseOnHover: true,
        startVisible: true,
      });
    }),
    (n.SliderAsNavFor = function () {
      if (e('.banner-single-slider-1-wrap').hasClass('no-thumbnails')) {
        return null;
      } else {
        return '.af-banner-slider-thumbnail';
      }
    }),
    (n.RtlCheck = function () {
      if (e('body').hasClass('rtl')) {
        return true;
      } else {
        return false;
      }
    }),
    (n.SlickSliderControls = function (widgetClass, controlWrap) {
      var widgetID = e(widgetClass).parents('.morenews-widget').attr('id');
      console.log(widgetID);
      return e(widgetID).find(controlWrap);
    }),
    (n.checkThumbOption = function () {
      if (e('.hasthumbslide').hasClass('side')) {
        return '.af-post-slider-thumbnail';
      } else {
        return false;
      }
    }),
    //slick slider
    (n.SlickBannerCarousel = function () {
      e('.af-banner-carousel-1')
        .not('.slick-initialized')
        .slick({
          autoplay: true,
          autoplaySpeed: 10000,
          infinite: true,
          nextArrow:
            '<button type="button" class="slide-icon slide-next icon-right fas fa-angle-right" aria-label="Next slide" tabindex="0"></button>',
          prevArrow:
            '<button type="button" class="slide-icon slide-prev icon-left fas fa-angle-left" aria-label="Previous slide" tabindex="0"></button>',
          appendArrows: e('.af-main-navcontrols'),
          rtl: n.RtlCheck(),
        });
    }),
    //Slick Carousel
    (n.SlickTrendingVerticalCarousel = function () {
      e('.aft-4-trending-posts .banner-vertical-slider')
        .not('.slick-initialized')
        .slick({
          slidesToShow: 4,
          slidesToScroll: 1,
          autoplay: true,
          infinite: true,
          loop: true,
          vertical: true,
          verticalSwiping: true,
          dots: false,
          nextArrow:
            '<button type="button" class="slide-icon slide-next icon-up fas fa-angle-up" aria-label="Next slide" tabindex="0"></button>',
          prevArrow:
            '<button type="button" class="slide-icon slide-prev icon-down fas fa-angle-down" aria-label="Previous slide" tabindex="0"></button>',
          appendArrows: e('.af-trending-navcontrols'),
          responsive: [
            {
              breakpoint: 1025,
              settings: {
                slidesToShow: 2,
                slidesToScroll: 1,
                vertical: false,
                verticalSwiping: false,
                draggable: false,
                swipeToSlide: false,
                touchMove: false,
                swipe: false,
                rtl: n.RtlCheck(),
              },
            },
            {
              breakpoint: 600,
              settings: {
                draggable: false,
                swipeToSlide: false,
                touchMove: false,
                swipe: false,
              },
            },
          ],
        });
    }),
    (n.SlickWidgetPostSlider = function () {
      e('.af-widget-post-slider').each(function () {
        e(this)
          .not('.slick-initialized')
          .slick({
            slidesToShow: 1,
            slidesToScroll: 1,
            autoplay: true,
            autoplaySpeed: 10000,
            infinite: true,
            nextArrow:
              '<button type="button" class="slide-icon slide-next icon-right fas fa-angle-right" aria-label="Next slide" tabindex="0"></button>',
            prevArrow:
              '<button type="button" class="slide-icon slide-prev icon-left fas fa-angle-left" aria-label="Previous slide" tabindex="0"></button>',
            appendArrows: e(this)
              .parents('.morenews-widget')
              .find('.af-widget-post-slider-navcontrols'),
            rtl: n.RtlCheck(),
          });
      });
    }),
    (n.SlickWidgetTrendingVerticalCarousel = function () {
      e('.body.full-width-content #primary .af-trending-widget-carousel').each(
        function () {
          e(this)
            .not('.slick-initialized')
            .slick({
              slidesToShow: 3,
              slidesToScroll: 1,
              autoplay: true,
              infinite: true,
              loop: true,
              dots: false,
              nextArrow:
                '<button type="button" class="slide-icon slide-next icon-right fas fa-angle-right" aria-label="Next slide" tabindex="0"></button>',
              prevArrow:
                '<button type="button" class="slide-icon slide-prev icon-left fas fa-angle-left" aria-label="Previous slide" tabindex="0"></button>',
              appendArrows: e(this)
                .parents('.morenews-widget')
                .find('.af-widget-trending-carousel-navcontrols'),
              rtl: n.RtlCheck(),
              responsive: [
                {
                  breakpoint: 1025,
                  settings: {
                    slidesToShow: 2,
                    draggable: false,
                    swipeToSlide: false,
                    touchMove: false,
                    swipe: false,
                  },
                },
                {
                  breakpoint: 600,
                  settings: {
                    slidesToShow: 1,
                    draggable: false,
                    swipeToSlide: false,
                    touchMove: false,
                    swipe: false,
                  },
                },
              ],
            });
        }
      );

      e('#primary .af-trending-widget-carousel').each(function () {
        e(this)
          .not('.slick-initialized')
          .slick({
            slidesToShow: 2,
            slidesToScroll: 1,
            autoplay: true,
            infinite: true,
            loop: true,
            dots: false,
            nextArrow:
              '<button type="button" class="slide-icon slide-next icon-right fas fa-angle-right" aria-label="Next slide" tabindex="0"></button>',
            prevArrow:
              '<button type="button" class="slide-icon slide-prev icon-left fas fa-angle-left" aria-label="Previous slide" tabindex="0"></button>',
            appendArrows: e(this)
              .parents('.morenews-widget')
              .find('.af-widget-trending-carousel-navcontrols'),
            rtl: n.RtlCheck(),
            responsive: [
              {
                breakpoint: 600,
                settings: {
                  slidesToShow: 1,
                  draggable: false,
                  swipeToSlide: false,
                  touchMove: false,
                  swipe: false,
                },
              },
            ],
          });
      });

      e('.af-trending-widget-carousel').each(function () {
        e(this)
          .not('.slick-initialized')
          .slick({
            slidesToShow: 4,
            slidesToScroll: 1,
            autoplay: true,
            infinite: true,
            loop: true,
            vertical: true,
            verticalSwiping: true,
            dots: false,
            nextArrow:
              '<button type="button" class="slide-icon slide-next icon-up fas fa-angle-up" aria-label="Next slide" tabindex="0"></button>',
            prevArrow:
              '<button type="button" class="slide-icon slide-prev icon-down fas fa-angle-down" aria-label="Previous slide" tabindex="0"></button>',
            appendArrows: e(this)
              .parents('.morenews-widget')
              .find('.af-widget-trending-carousel-navcontrols'),
            responsive: [
              {
                breakpoint: 1025,
                settings: {
                  slidesToShow: 2,
                  slidesToScroll: 1,
                  vertical: false,
                  verticalSwiping: false,
                  draggable: false,
                  swipeToSlide: false,
                  touchMove: false,
                  swipe: false,
                  rtl: n.RtlCheck(),
                },
              },
              {
                breakpoint: 600,
                settings: {
                  draggable: false,
                  swipeToSlide: false,
                  touchMove: false,
                  swipe: false,
                },
              },
            ],
          });
      });
    }),
    e(document).ready(function () {
      n.mobileMenu.init(),
        n.jQueryMarquee(),
        n.MagnificPopup(),
        n.Offcanvas(),
        n.scroll_up(),
        n.SlickBannerCarousel(),
        n.SlickWidgetPostSlider(),
        n.SlickTrendingVerticalCarousel(),
        n.SlickWidgetTrendingVerticalCarousel();
    }),
    e(window).on('scroll', function () {
      n.show_hide_scroll_top();
    }),
    e(window).on('load', function () {
      // n.DataBackground(),
      n.searchReveal(),
        // n.Preloader(),
        n.Search();
    }),
    e(window).on('resize', function () {
      n.mobileMenu.menuMobile();
    });
})(jQuery);
