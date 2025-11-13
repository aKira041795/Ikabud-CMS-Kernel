(function ($) {
	"use strict";

	var $window = $(window);

	function debounce(func, wait, immediate) {
		var timeout;
		return function () {
			var context = this,
				args = arguments;
			var later = function () {
				timeout = null;
				if (!immediate) func.apply(context, args);
			};
			var callNow = immediate && !timeout;
			clearTimeout(timeout);
			timeout = setTimeout(later, wait);
			if (callNow) func.apply(context, args);
		};
	}

	function rtl_slick() {
		if ($("body").hasClass("rtl")) {
			return true;
		} else {
			return false;
		}
	}

	$window.on("elementor/frontend/init", function () {
		var ModuleHandler = elementorModules.frontend.handlers.Base;

		var PostMarquee = ModuleHandler.extend({
			onInit: function () {
				ModuleHandler.prototype.onInit.apply(this, arguments);
				this.wrapper = this.$element.find(".elespare-flash-wrap");
				this.run();
			},

			getDefaultSettings: function () {
				var pauseticker = false;
				if (this.getElementSettings("_pause_on_hover") == "yes") {
					pauseticker = true;
				}
				return {
					//duration in milliseconds of the marquee
					speed: this.getElementSettings("_animation_speed"),
					//gap in pixels between the tickers
					gap: 0,
					//time in milliseconds before the marquee will start animating
					delayBeforeStart: 0,
					//'left' or 'right'
					// direction: 'right',
					//true or false - should the marquee be duplicated to show an effect of continues flow
					duplicated: true,
					pauseOnHover: pauseticker,
					startVisible: true,
				};
			},
			getDefaultElements: function () {
				return {
					$container: this.findElement(this.getSettings("container")),
				};
			},
			run: function () {
				var filter_wrap = this.wrapper.find(".marquee.elespare-flash-side");

				filter_wrap.marquee(this.getDefaultSettings());
			},
		});

		var PostCarousel = ModuleHandler.extend({
			onInit: function () {
				ModuleHandler.prototype.onInit.apply(this, arguments);
				this.run();
			},
			getDefaultSettings: function () {
				return {
					autoplay: false,
					arrows: false,
					checkVisible: false,
					container: ".elespare-posts-wrap",
					dots: false,
					infinite: true,
					rows: 0,
					slidesToShow: 3,
					prevArrow: $("<div />")
						.append(this.findElement(".slick-prev").clone().show())
						.html(),
					nextArrow: $("<div />")
						.append(this.findElement(".slick-next").clone().show())
						.html(),
				};
			},
			getDefaultElements: function () {
				return {
					$container: this.findElement(this.getSettings("container")),
				};
			},
			onElementChange: debounce(function () {
				this.elements.$container.slick("unslick");
				this.run();
			}, 200),

			getSlickSettings: function () {
				var autoplaycarousel = false;
				if (this.getElementSettings("autoplay") == "yes") {
					autoplaycarousel = true;
				}

				var settings = {
					infinite: !!this.getElementSettings("loop"),
					autoplay: autoplaycarousel,
					autoplaySpeed: this.getElementSettings("autoplay_speed"),
					speed: this.getElementSettings("animation_speed"),
					centerMode: !!this.getElementSettings("center"),
					slidesToScroll: 1,
					rtl: rtl_slick(),
				};

				switch (this.getElementSettings("navigation")) {
					case "arrow":
						settings.arrows = true;
						break;
					case "dots":
						settings.dots = true;
						break;
					case "both":
						settings.arrows = true;
						settings.dots = true;
						break;
				}
				//  var blockname =  this.elements.$container.attr('data-blockname');

				settings.slidesToShow =
					parseInt(this.getElementSettings("slides_to_show")) || 1;

				settings.slidesToShow =
					parseInt(this.getElementSettings("slides_to_show")) || 1;
				settings.responsive = [
					{
						breakpoint: elementorFrontend.config.breakpoints.lg,
						settings: {
							slidesToShow:
								parseInt(this.getElementSettings("slides_to_show_tablet")) ||
								settings.slidesToShow,
						},
					},
					{
						breakpoint: elementorFrontend.config.breakpoints.md,
						settings: {
							slidesToShow:
								parseInt(this.getElementSettings("slides_to_show_mobile")) ||
								parseInt(this.getElementSettings("slides_to_show_tablet")) ||
								settings.slidesToShow,
						},
					},
				];

				return $.extend({}, this.getSettings(), settings);
			},

			run: function () {
				this.elements.$container
					.not(".slick-initialized")
					.slick(this.getSlickSettings());
			},
		});

		//Masonry
		var PostMasonry = ModuleHandler.extend({
			onInit: function () {
				ModuleHandler.prototype.onInit.apply(this, arguments);
				this.run();
			},
			getLayoutMode: function () {
				var layout = "even";
				return layout === "even" ? "masonry" : layout;
			},

			getDefaultSettings: function () {
				return {
					itemSelector: ".elespare-posts-masonry-item-wrapper",
					percentPosition: true,
					layoutMode: this.getLayoutMode(),
				};
			},

			getDefaultElements: function () {
				return {
					$container: this.findElement(".elespare-isotope"),
				};
			},

			run: function () {
				var self = this;
				self.elements.$container.isotope(self.getDefaultSettings());
			},
		});
		//-------
		var MainBannerOne = ModuleHandler.extend({
			onInit: function () {
				ModuleHandler.prototype.onInit.apply(this, arguments);
				this.run();
			},
			getDefaultSettings: function () {
				return {
					autoplay: true,
					arrows: true,
					checkVisible: false,
					container: ".elespare-carousel-wrap",
					dots: false,
					infinite: true,
					rows: 0,
					slidesToShow: 1,
					prevArrow: $("<div />")
						.append(this.findElement(".slick-prev").clone().show())
						.html(),
					nextArrow: $("<div />")
						.append(this.findElement(".slick-next").clone().show())
						.html(),
				};
			},
			getDefaultElements: function () {
				return {
					$container: this.findElement(this.getSettings("container")),
				};
			},
			onElementChange: debounce(function () {
				this.elements.$container.slick("unslick");
				this.run();
			}, 200),

			getSlickSettings: function () {
				var autoplaybanner = false;
				if (this.getElementSettings("_autoplay") == "yes") {
					autoplaybanner = true;
				}

				var bannersettings = {
					infinite: !!this.getElementSettings("_loop"),
					autoplay: autoplaybanner,
					autoplaySpeed: this.getElementSettings("_autoplay_speed"),
					speed: this.getElementSettings("_animation_speed"),
					slidesToScroll: 1,
					rtl: rtl_slick(),
				};

				bannersettings.slidesToShow =
					parseInt(this.elements.$container.attr("data-num")) || 1;
				bannersettings.responsive = [
					{
						breakpoint: elementorFrontend.config.breakpoints.lg,
						settings: {
							slidesToShow: 1,
						},
					},
					{
						breakpoint: elementorFrontend.config.breakpoints.md,
						settings: {
							slidesToShow: 1,
						},
					},
				];

				return $.extend({}, this.getSettings(), bannersettings);
			},

			run: function () {
				this.elements.$container
					.not(".slick-initialized")
					.slick(this.getSlickSettings());
			},
		});

		var MainBannerTwo = ModuleHandler.extend({
			onInit: function () {
				ModuleHandler.prototype.onInit.apply(this, arguments);
				this.run();
			},
			getDefaultSettings: function () {
				return {
					autoplay: true,
					arrows: true,
					checkVisible: false,
					container: ".elespare-carousel-wrap",
					dots: false,
					infinite: true,
					rows: 0,
					slidesToShow: 1,
					prevArrow: $("<div />")
						.append(this.findElement(".slick-prev").clone().show())
						.html(),
					nextArrow: $("<div />")
						.append(this.findElement(".slick-next").clone().show())
						.html(),
				};
			},
			getDefaultElements: function () {
				return {
					$container: this.findElement(this.getSettings("container")),
				};
			},
			onElementChange: debounce(function () {
				this.elements.$container.slick("unslick");
				this.run();
			}, 200),

			getSlickSettings: function () {
				var autoplaybanner = false;
				if (this.getElementSettings("_autoplay") == "yes") {
					autoplaybanner = true;
				}

				var bannersettings = {
					infinite: !!this.getElementSettings("_loop"),
					autoplay: autoplaybanner,
					autoplaySpeed: this.getElementSettings("_autoplay_speed"),
					speed: this.getElementSettings("_animation_speed"),
					slidesToScroll: 1,
					rtl: rtl_slick(),
				};

				bannersettings.slidesToShow =
					parseInt(this.elements.$container.attr("data-num")) || 1;
				bannersettings.responsive = [
					{
						breakpoint: elementorFrontend.config.breakpoints.lg,
						settings: {
							slidesToShow: 1,
						},
					},
					{
						breakpoint: elementorFrontend.config.breakpoints.md,
						settings: {
							slidesToShow: 1,
						},
					},
				];

				return $.extend({}, this.getSettings(), bannersettings);
			},

			run: function () {
				this.elements.$container
					.not(".slick-initialized")
					.slick(this.getSlickSettings());
			},
		});
		//-----
		//Post banner slider
		var PostBannerCarousel = ModuleHandler.extend({
			onInit: function () {
				ModuleHandler.prototype.onInit.apply(this, arguments);
				this.run();
			},
			getDefaultSettings: function () {
				return {
					autoplay: true,
					arrows: true,
					checkVisible: false,
					container: ".elespare-carousel-wrap",
					dots: false,
					infinite: true,
					rows: 0,
					slidesToShow: 1,
					prevArrow: $("<div />")
						.append(this.findElement(".slick-prev").clone().show())
						.html(),
					nextArrow: $("<div />")
						.append(this.findElement(".slick-next").clone().show())
						.html(),
				};
			},
			getDefaultElements: function () {
				return {
					$container: this.findElement(this.getSettings("container")),
				};
			},
			onElementChange: debounce(function () {
				this.elements.$container.slick("unslick");
				this.run();
			}, 200),

			getSlickSettings: function () {
				var autoplaybanner = false;
				if (this.getElementSettings("_autoplay") == "yes") {
					autoplaybanner = true;
				}

				var bannersettings = {
					infinite: !!this.getElementSettings("_loop"),
					autoplay: autoplaybanner,
					autoplaySpeed: this.getElementSettings("_autoplay_speed"),
					speed: this.getElementSettings("_animation_speed"),
					slidesToScroll: 1,
					rtl: rtl_slick(),
				};

				var parent_class =
					this.elements.$container.parents(".elementor-column");

				var admin_attr = parent_class.attr("data-col");

				if (
					admin_attr <= 33 ||
					parent_class.hasClass("elementor-col-33") ||
					parent_class.hasClass("elementor-col-20") ||
					parent_class.hasClass("elementor-col-16")
				) {
					bannersettings.slidesToShow = 1;
				} else {
					bannersettings.slidesToShow =
						parseInt(this.elements.$container.attr("data-num")) || 1;
				}

				bannersettings.responsive = [
					{
						breakpoint: elementorFrontend.config.breakpoints.lg,
						settings: {
							slidesToShow: 1,
						},
					},
					{
						breakpoint: elementorFrontend.config.breakpoints.md,
						settings: {
							slidesToShow: 1,
						},
					},
				];

				return $.extend({}, this.getSettings(), bannersettings);
			},

			run: function () {
				this.elements.$container
					.not(".slick-initialized")
					.slick(this.getSlickSettings());
			},
		});

		var PostBannerTrendings = ModuleHandler.extend({
			onInit: function () {
				ModuleHandler.prototype.onInit.apply(this, arguments);
				this.run();
			},
			getDefaultSettings: function () {
				return {
					autoplay: true,
					arrows: false,
					checkVisible: false,
					container: ".elespare-trending-wrap",
					dots: false,
					infinite: true,
					vertical: true,
					verticalSwiping: true,
					rows: 0,
					slidesToShow: 3,
				};
			},
			getDefaultElements: function () {
				return {
					$container: this.findElement(this.getSettings("container")),
				};
			},
			onElementChange: debounce(function () {
				this.elements.$container.slick("unslick");
				this.run();
			}, 200),

			getSlickSettings: function () {
				var slide_to_show = this.getElementSettings("trending_slides_to_show");

				var parent_class =
					this.elements.$container.parents(".elementor-element");
				var widget_name = parent_class
					.find(".elementor-widget-main-banner-1")
					.attr("data-widget_type");
				var widget_name_banner_2 = parent_class
					.find(".elementor-widget-main-banner-2")
					.attr("data-widget_type");

				var verticals = true;
				var admin_attr = parent_class.attr("data-col");

				if (
					widget_name == "main-banner-1.default" ||
					widget_name_banner_2 == "main-banner-2.default"
				) {
					if (admin_attr == "66" || parent_class.hasClass("elementor-col-66")) {
						verticals = false;
						slide_to_show = 2;
					} else if (
						admin_attr == "50" ||
						parent_class.hasClass("elementor-col-50")
					) {
						verticals = false;
						slide_to_show = 2;
					} else if (
						admin_attr == "33" ||
						parent_class.hasClass("elementor-col-33")
					) {
						verticals = false;
						slide_to_show = 1;
					} else if (
						admin_attr == "25" ||
						parent_class.hasClass("elementor-col-25")
					) {
						verticals = false;
						slide_to_show = 1;
					} else {
						verticals = false;
						slide_to_show = this.getElementSettings("trending_slides_to_show");
					}
				} else {
					verticals = false;
				}

				var autoplayverticale = false;
				if (this.getElementSettings("trending_autoplay") == "yes") {
					autoplayverticale = true;
				}

				var verticalsettings = {
					infinite: !!this.getElementSettings("trending_loop"),
					autoplay: autoplayverticale,
					autoplaySpeed: this.getElementSettings("trending_autoplay_speed"),
					speed: this.getElementSettings("trending_animation_speed"),
					slidesToShow: slide_to_show,
					slidesToScroll: 1,
					vertical: verticals,
					rtl: rtl_slick(),
				};

				verticalsettings.responsive = [
					{
						breakpoint: elementorFrontend.config.breakpoints.lg,
						settings: {
							slidesToShow:
								parseInt(
									this.getElementSettings("trending_slides_to_show_tablet")
								) || verticalsettings.slidesToShow,
							vertical: false,
						},
					},
					{
						breakpoint: elementorFrontend.config.breakpoints.md,
						settings: {
							slidesToShow:
								parseInt(
									this.getElementSettings("trending_slides_to_show_mobile")
								) ||
								parseInt(this.getElementSettings("slides_to_show_tablet")) ||
								verticalsettings.slidesToShow,
							vertical: false,
						},
					},
				];

				return $.extend({}, this.getSettings(), verticalsettings);
			},

			run: function () {
				this.elements.$container
					.not(".slick-initialized")
					.slick(this.getSlickSettings());
			},
		});

		var PostBannerVerticaleTrendings = ModuleHandler.extend({
			onInit: function () {
				ModuleHandler.prototype.onInit.apply(this, arguments);
				this.run();
			},
			getDefaultSettings: function () {
				return {
					autoplay: true,
					arrows: false,
					checkVisible: false,
					container: ".elespare-trending-wrap",
					dots: false,
					infinite: true,
					vertical: true,
					verticalSwiping: true,
					rows: 0,
					slidesToShow: 3,
				};
			},
			getDefaultElements: function () {
				return {
					$container: this.findElement(this.getSettings("container")),
				};
			},
			onElementChange: debounce(function () {
				this.elements.$container.slick("unslick");
				this.run();
			}, 200),

			getSlickSettings: function () {
				var slide_to_show = 3;

				var autoplayverticale = false;
				if (this.getElementSettings("trending_autoplay") == "yes") {
					autoplayverticale = true;
				}

				var parent_class = this.elements.$container.parents(
					".elementor-element.elementor-widget-post-banner"
				);
				var widget_name = parent_class.attr("data-widget_type");
				if (widget_name == "post-banner.default") {
					if (
						parent_class
							.find(".elespare-banner-one-wrap")
							.hasClass("banner-style-4") ||
						parent_class
							.find(".elespare-banner-one-wrap")
							.hasClass("banner-style-5") ||
						parent_class
							.find(".elespare-banner-one-wrap")
							.hasClass("banner-style-6")
					) {
						slide_to_show = 4;
					}
				}

				var verticalsettings = {
					infinite: !!this.getElementSettings("trending_loop"),
					autoplay: autoplayverticale,
					autoplaySpeed: this.getElementSettings("trending_autoplay_speed"),
					speed: this.getElementSettings("trending_animation_speed"),
					slidesToShow: slide_to_show,
					slidesToScroll: 1,
					vertical: true,
					verticalSwiping: true,
					// rtl: rtl_slick()
				};

				verticalsettings.responsive = [
					{
						breakpoint: elementorFrontend.config.breakpoints.lg,
						settings: {
							slidesToShow: 2,
							vertical: false,
							verticalSwiping: false,
							rtl: rtl_slick(),
						},
					},
					{
						breakpoint: elementorFrontend.config.breakpoints.md,
						settings: {
							slidesToShow: 1,
							vertical: false,
							verticalSwiping: false,
							rtl: rtl_slick(),
						},
					},
				];

				return $.extend({}, this.getSettings(), verticalsettings);
			},

			run: function () {
				this.elements.$container
					.not(".slick-initialized")
					.slick(this.getSlickSettings());
			},
		});

		//Post Trending Carousel
		var PostTrendingsCarousel = ModuleHandler.extend({
			onInit: function () {
				ModuleHandler.prototype.onInit.apply(this, arguments);
				this.run();
			},
			getDefaultSettings: function () {
				return {
					autoplay: false,
					arrows: false,
					checkVisible: false,
					container: ".elespare-trending-wrap",
					dots: false,
					infinite: true,
					vertical: false,
					verticalSwiping: false,
					rows: 0,
					slidesToShow: 3,
				};
			},
			getDefaultElements: function () {
				return {
					$container: this.findElement(this.getSettings("container")),
				};
			},
			onElementChange: debounce(function () {
				this.elements.$container.slick("unslick");
				this.run();
			}, 200),

			getSlickSettings: function () {
				var verticals = false;
				var slide_to_show = 3;
				var parent_class =
					this.elements.$container.parents(".elementor-column");
				var rtl = rtl_slick();

				var admin_attr = parent_class.attr("data-col");

				if (parent_class.hasClass("elementor-col-66")) {
					verticals = false;
					slide_to_show = 2;
					rtl = rtl_slick();
				}
				if (parent_class.hasClass("elementor-col-50")) {
					verticals = false;
					slide_to_show = 2;
					rtl = rtl_slick();
				}
				if (parent_class.hasClass("elementor-col-33")) {
					verticals = true;
					slide_to_show = 5;
					rtl = false;
				}
				if (parent_class.hasClass("elementor-col-25")) {
					verticals = true;
					slide_to_show = 2;
					rtl = false;
				}
				if (parent_class.hasClass("elementor-col-16")) {
					verticals = true;
					slide_to_show = 2;
					rtl = false;
				}

				if (admin_attr == "66") {
					verticals = false;
					slide_to_show = 2;
					rtl = rtl_slick();
				}
				if (admin_attr == "50") {
					verticals = false;
					slide_to_show = 2;
					rtl = rtl_slick();
				}

				if (admin_attr == "33") {
					verticals = true;
					slide_to_show = 5;
					rtl = false;
				}
				if (admin_attr == "25") {
					verticals = true;
					slide_to_show = 2;
					rtl = false;
				}
				if (admin_attr == "16") {
					verticals = true;
					slide_to_show = 2;
					rtl = false;
				}

				var verticalsettings = {
					infinite: !!this.getElementSettings("trending_loop"),
					autoplay: !!this.getElementSettings("trending_autoplay"),
					autoplaySpeed: this.getElementSettings("trending_autoplay_speed"),
					speed: this.getElementSettings("trending_animation_speed"),
					slidesToShow: slide_to_show,
					slidesToScroll: 1,
					vertical: verticals,
					rtl: rtl,
				};

				verticalsettings.responsive = [
					{
						breakpoint: elementorFrontend.config.breakpoints.lg,
						settings: {
							slidesToShow: 2,
							vertical: false,
							verticalSwiping: false,
							rtl: rtl_slick(),
						},
					},
					{
						breakpoint: elementorFrontend.config.breakpoints.md,
						settings: {
							slidesToShow: 5,
							vertical: true,
							verticalSwiping: true,
							rtl: false,
						},
					},
				];

				return $.extend({}, this.getSettings(), verticalsettings);
			},

			run: function () {
				this.elements.$container
					.not(".slick-initialized")
					.slick(this.getSlickSettings());
			},
		});

		//Tabs
		var PostTab = ModuleHandler.extend({
			onInit: function () {
				ModuleHandler.prototype.onInit.apply(this, arguments);
				this.wrapper = this.$element.find(".elespare-post-tab");
				this.run();
			},

			run: function () {
				var filter_wrap = this.wrapper.find(".elespare-post-tab-filter"),
					filter = filter_wrap.find("li"),
					event = this.wrapper.data("event"),
					args = this.wrapper.data("query-args");
				filter.on(
					event,
					debounce(function (e) {
						e.preventDefault();
						var $self = $(this),
							term_id = $self.data("term"),
							$wrapper = $self.closest(".elespare-post-tab"),
							content = $wrapper.find(".elespare-post-tab-content"),
							loading = content.find(".elespare-post-tab-loading"),
							tab_item = content.find(".elespare-post-tab-item-wrapper"),
							$content_exist = false;

						if (0 === loading.length) {
							filter.removeClass("active");
							tab_item.removeClass("active");
							$self.addClass("active");

							tab_item.each(function () {
								var $self = $(this),
									$content_id = $self.data("term");
								if (term_id === $content_id) {
									$self.addClass("active"), ($content_exist = true);
								}
							});

							if (false === $content_exist) {
								$.ajax({
									url: ElespareLocalize.ajax_url,
									type: "POST",
									data: {
										action: "elespare_post_tab_action",
										security: ElespareLocalize.nonce,
										post_tab_query: args,
										term_id: term_id,
									},
									beforeSend: function () {
										content.append(
											'<span class="elespare-post-tab-loading"><span class="elespare-roller"><span></span><span></span><span></span><span></span></span></span>'
										);
									},
									success: function (response) {
										content.find(".elespare-post-tab-loading").remove();
										content.append(response);
									},
									error: function (error) {},
								});
							}
						}
					}, 200)
				);
			},
		});

		var SearchForm = ModuleHandler.extend({
			onInit: function () {
				ModuleHandler.prototype.onInit.apply(this, arguments);
				this.wrapper = this.$element.find(".elespare-search-wrapper");
				this.run();
			},
			run: function () {
				var searchContainer = this.wrapper.find(".elespare-search--toggle");
				var btn = this.wrapper.find(".elespare-search-icon--toggle");
				var close = this.wrapper.find(".elespare--site-search-close");
				var dropdown_click = this.wrapper.find(
					".elespare-search-dropdown-toggle"
				);

				btn.on("click", function () {
					searchContainer.addClass("show");
					dropdown_click.toggleClass("show");
				});

				close.on("click", function () {
					searchContainer.removeClass("show");
				});

				const $menu = this.wrapper.find(".elespare-search-dropdown-toggle");

				$(document).mouseup((e) => {
					if (
						!$menu.is(e.target) && // if the target of the click isn't the container...
						$menu.has(e.target).length === 0
					) {
						// ... nor a descendant of the container
						$menu.removeClass("show");
					}
				});

				$(document).on("keydown", function (e) {
					if (e.keyCode === 27) {
						// ESC
						searchContainer.removeClass("show");
					}
				});
			},
		});

		var NavMenu = ModuleHandler.extend({
			onInit: function () {
				ModuleHandler.prototype.onInit.apply(this, arguments);
				this.wrapper = this.$element.find(".elespare-navigation-wrapper");
				this.run();
			},

			run: function () {
				var toggle = this.wrapper.find(".elespare-menu-toggle");
				var nav = this.wrapper.find(".elespare-moblie-ham-menu");
				var overlay = this.wrapper.find(".elespare-overlay");
				var close = this.wrapper.find(".elespare--close-menu-side-bar");
				var main = this.wrapper.find(".elespare-main-navigation");
				var desktop_subnav = main.find("ul >.menu-item-has-children>a");
				var sub = main.find(".sub-menu");
				if (
					this.wrapper.hasClass("vertical") ||
					this.wrapper.hasClass("horizontal")
				) {
					sub.each(function (index) {
						$(this).wrap('<div class="elespare-menu-child">');
					});
				}

				sub
					.parents("li")
					.find("> a")
					.append('<button class="elespare-dropdwon-toggle">');
				nav
					.find("ul >.menu-item-has-children>a")
					.parents("li")
					.find("> a")
					.append('<button class="elespare-dropdwon-toggle">');

				if (
					this.wrapper.hasClass("vertical") ||
					this.wrapper.hasClass("horizontal")
				) {
					var submenu_op = this.wrapper.data("opt");
					var cl_event = "click";
					if (submenu_op == "sub-hover") {
						cl_event = "hover";
					}
					desktop_subnav.on(cl_event, function (e) {
						e.preventDefault();

						var item = $(this).siblings(".elespare-menu-child");
						if (
							desktop_subnav
								.parents(".elespare-navigation-wrapper")
								.hasClass("horizontal")
						) {
							$(this)
								.closest("ul")
								.find(".elespare-menu-child")
								.not(item)
								.removeClass("active");
						}
						if (item.hasClass("active")) {
							item.removeClass("active");
							$(this).removeClass("up");
							$(this).removeClass(submenu_op);
						} else {
							item.addClass("active");
							$(this).addClass("up");
							$(this).addClass(submenu_op);
						}
					});

					if (this.wrapper.hasClass("sub-click")) {
						$(document).on("click", function (e) {
							var elebodyclick = $(".sub-click");
							if (
								!elebodyclick.is(e.target) && // if the target of the click isn't the container...
								elebodyclick.has(e.target).length === 0
							) {
								// ... nor a descendant of the container
								elebodyclick
									.parents(".elespare-main-navigation")
									.find(".elespare-menu-child")
									.removeClass("active");
							}
						});
					}
				}

				// Show Menu Mobile

				toggle.on("click", function (e) {
					e.preventDefault();
					if (nav.hasClass("show")) {
						nav.removeClass("show");
						overlay.removeClass("show");
						toggle.removeClass("elespare-nav-menu-toggle");
					} else {
						nav.addClass("show");
						overlay.addClass("show");
						toggle.addClass("elespare-nav-menu-toggle");
					}
				});

				// Close menu mobile when click overlay
				overlay.on("click", function (e) {
					nav.removeClass("show");
					overlay.removeClass("show");
				});

				// Close menu mobile when click icon close
				close.on("click", function (e) {
					nav.removeClass("show");
					overlay.removeClass("show");
				});

				// Close menu mobile when click ESC
				$(document).on("keyup", function (e) {
					if (e.keyCode === 27) {
						nav.removeClass("show");
						overlay.removeClass("show");
					}
				});

				/* MOBILE MENU */

				var btn = nav.find(
					"ul >.menu-item-has-children>a .elespare-dropdwon-toggle"
				);

				btn.on("click", function (e) {
					e.preventDefault();
					var item = $(this).parent("a").siblings("ul.sub-menu");
					var active = item.hasClass("active");
					if (active) {
						item.removeClass("active");
						$(this).removeClass("up");
						item.slideUp(300);
					} else {
						item.addClass("active");
						item.slideDown(300);
						$(this).addClass("up");
					}
				});

				// for Testing

				$(".sub-menu .menu-item-has-children").on("hover", function () {
					var width = $(this).offset().left,
						windowWidth = $(window).width(),
						range = windowWidth - width;

					if (range < 400) {
						$(this).find(".elespare-menu-child").css({ top: "100%" });
						$(this)
							.find(".sub-menu")
							.css({ left: "auto", top: "100%", right: "50%" });
					}
				});

				if (main.hasClass("primary-menu")) {
					main.removeClass("primary-menu");
				}
			},
		});

		//Tabs
		elementorFrontend.hooks.addAction(
			"frontend/element_ready/post-tabs.default",
			function ($scope) {
				elementorFrontend.elementsHandler.addHandler(PostTab, {
					$element: $scope,
				});
			}
		);
		//Masonry

		elementorFrontend.hooks.addAction(
			"frontend/element_ready/post-masonry.default",
			function ($scope) {
				elementorFrontend.elementsHandler.addHandler(PostMasonry, {
					$element: $scope,
				});
			}
		);

		//Carousel
		elementorFrontend.hooks.addAction(
			"frontend/element_ready/post-carousel.default",
			function ($scope) {
				elementorFrontend.elementsHandler.addHandler(PostCarousel, {
					$element: $scope,
				});
			}
		);

		//slider
		elementorFrontend.hooks.addAction(
			"frontend/element_ready/post-slider.default",
			function ($scope) {
				elementorFrontend.elementsHandler.addHandler(PostCarousel, {
					$element: $scope,
				});
			}
		);
		//Marquee

		elementorFrontend.hooks.addAction(
			"frontend/element_ready/post-flash.default",
			function ($scope) {
				elementorFrontend.elementsHandler.addHandler(PostMarquee, {
					$element: $scope,
				});
			}
		);

		//banner -1
		elementorFrontend.hooks.addAction(
			"frontend/element_ready/post-banner.default",
			function ($scope) {
				elementorFrontend.elementsHandler.addHandler(PostBannerCarousel, {
					$element: $scope,
				});
			}
		);
		//banner -2
		elementorFrontend.hooks.addAction(
			"frontend/element_ready/post-banner-2.default",
			function ($scope) {
				elementorFrontend.elementsHandler.addHandler(PostBannerCarousel, {
					$element: $scope,
				});
			}
		);
		//---------------------------
		elementorFrontend.hooks.addAction(
			"frontend/element_ready/main-banner-1.default",
			function ($scope) {
				elementorFrontend.elementsHandler.addHandler(MainBannerOne, {
					$element: $scope,
				});
			}
		);
		//MainBannerTrendings banner1
		elementorFrontend.hooks.addAction(
			"frontend/element_ready/main-banner-1.default",
			function ($scope) {
				elementorFrontend.elementsHandler.addHandler(PostBannerTrendings, {
					$element: $scope,
				});
			}
		);
		//MainBannerTrendings banner2

		elementorFrontend.hooks.addAction(
			"frontend/element_ready/main-banner-2.default",
			function ($scope) {
				elementorFrontend.elementsHandler.addHandler(MainBannerTwo, {
					$element: $scope,
				});
			}
		);
		//MainBannerTrendings banner1
		// elementorFrontend.hooks.addAction(
		// 	"frontend/element_ready/main-banner-2.default",
		// 	function ($scope) {
		// 		elementorFrontend.elementsHandler.addHandler(PostBannerTrendings, {
		// 			$element: $scope,
		// 		});
		// 	}
		// );

		//-------------------
		//PostBannerTrendings banner1
		elementorFrontend.hooks.addAction(
			"frontend/element_ready/post-banner.default",
			function ($scope) {
				elementorFrontend.elementsHandler.addHandler(
					PostBannerVerticaleTrendings,
					{
						$element: $scope,
					}
				);
			}
		);

		//PostBannerTrendings banner 2
		// elementorFrontend.hooks.addAction(
		// 	"frontend/element_ready/post-banner-2.default",
		// 	function ($scope) {
		// 		elementorFrontend.elementsHandler.addHandler(PostBannerTrendings, {
		// 			$element: $scope,
		// 		});
		// 	}
		// );

		//PostsTrendingsCarousel
		elementorFrontend.hooks.addAction(
			"frontend/element_ready/post-trending-carousel.default",
			function ($scope) {
				elementorFrontend.elementsHandler.addHandler(PostTrendingsCarousel, {
					$element: $scope,
				});
			}
		);
		//Serach Form
		elementorFrontend.hooks.addAction(
			"frontend/element_ready/search-from.default",
			function ($scope) {
				elementorFrontend.elementsHandler.addHandler(SearchForm, {
					$element: $scope,
				});
			}
		);

		//Horizontal Nav Menu

		elementorFrontend.hooks.addAction(
			"frontend/element_ready/elespare-nav-horziontal-menu.default",
			function ($scope) {
				elementorFrontend.elementsHandler.addHandler(NavMenu, {
					$element: $scope,
				});
			}
		);
		//Vertical Nav Menu
		elementorFrontend.hooks.addAction(
			"frontend/element_ready/elespare-nav-vertical-menu.default",
			function ($scope) {
				elementorFrontend.elementsHandler.addHandler(NavMenu, {
					$element: $scope,
				});
			}
		);

		//Expandable Nav Menu
		elementorFrontend.hooks.addAction(
			"frontend/element_ready/elespare-nav-expanded-menu.default",
			function ($scope) {
				elementorFrontend.elementsHandler.addHandler(NavMenu, {
					$element: $scope,
				});
			}
		);
	});
})(jQuery);
