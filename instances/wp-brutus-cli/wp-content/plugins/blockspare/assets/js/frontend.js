(function($) {
	"use strict";
	var n = window.AFTHRAMPES_JS || {};

	function blockspare_rtl_slick() {
		if ($("body").hasClass("rtl")) {
			return true;
		} else {
			return false;
		}
	}

	function escapeHtmlAttr(str) {
		return String(str)
			.replace(/&/g, "&amp;")
			.replace(/"/g, "&quot;")
			.replace(/'/g, "&#39;")
			.replace(/</g, "&lt;")
			.replace(/>/g, "&gt;");
	}
	//Skill Bar
	(n.SkillBar = function() {
		if ($(".blockspare_progress-bar-container").length > 0) {
			$(".blockspare_progress-bar-container").waypoint(
				function() {
					$(this.element)
						.find(".blockspare-skillbar-item")
						.each(function() {
							var data_percent = $(this).attr("data-percent");
							$(this)
								.find(".blockspare-skillbar-bar")
								.animate(
									{
										width: data_percent + "%"
									},
									20 * data_percent
								);
						});
				},
				{
					offset: "75%",
					triggerOnce: !0
				}
			);
		}
	}),
		//Search

		(n.Search = function() {
			$(".bs-search-icon--toggle").each(function() {
				$(this).on("click", function() {
					var parentClass = $(this).parent();
					if (parentClass.hasClass("bs-search-dropdown-toggle")) {
						parentClass.toggleClass("show");
					}
					if (parentClass.hasClass("bs-site-search-toggle")) {
						parentClass
							.parent(".bs-search-wrapper")
							.find(".bs-search--toggle")
							.addClass("show");
					}
				});
			});

			$(".bs--site-search-close").each(function() {
				$(this).on("click", function() {
					var parentClass = $(this).parent();
					if (parentClass.hasClass("bs-search--toggle")) {
						parentClass
							.parent(".bs-search-wrapper")
							.find(".bs-search--toggle")
							.removeClass("show");
					}
				});
			});
		}),
		(n.searchReveal = function() {
			$(".blocksapre-search-icon").on("click", function(event) {
				event.preventDefault();
				$(".blockspare-search-box").toggleClass("reveal-search");
			});
		}),
		//CountUP
		(n.CountUp = function() {
			if ($(".blockspare-section-counter-bar").length > 0) {
				$(".blockspare-counter").counterUp({
					delay: 10,
					time: 1600
				});
			}
		}),
		(n.Tabs = function() {
			$(".blockspare-tab-title").on("click", function(event) {
				var $blockspareTab = $(this).parent();
				var blockspareIndex = $blockspareTab.index();
				if ($blockspareTab.hasClass("blockspare-active")) {
					return;
				}

				$blockspareTab
					.closest(".blockspare-tab-nav")
					.find(".blockspare-active")
					.removeClass("blockspare-active");
				$blockspareTab.addClass("blockspare-active");
				$blockspareTab
					.closest(".blockspare-block-tab")
					.find(".blockspare-tab-content.blockspare-active")
					.removeClass("blockspare-active");
				$blockspareTab
					.closest(".blockspare-block-tab")
					.find(".blockspare-tab-content")
					.eq(blockspareIndex)
					.addClass("blockspare-active");

				if ($blockspareTab.hasClass("blockspare-active")) {
					var tab_bg = $blockspareTab
						.find(".blockspare-tab-title ")
						.attr("tab-bg");

					var text = $blockspareTab
						.find(".blockspare-tab-title ")
						.attr("tab-text");

					$(this)
						.parents(".blockspare-block-tab")
						.find(".blockspare-tab-title")
						.css("background-color", tab_bg);
					$(this)
						.parents(".blockspare-block-tab")
						.find(".blockspare-tab-title")
						.css("color", text);

					var tab_abg = $blockspareTab
						.find(".blockspare-tab-title ")
						.attr("atab-bg");
					var atext = $blockspareTab
						.find(".blockspare-tab-title ")
						.attr("atab-text");
					$blockspareTab
						.find(".blockspare-tab-title ")
						.css("background-color", tab_abg);
					$blockspareTab
						.find(".blockspare-tab-title ")
						.css("color", atext);
				}
			});
		}),
		(n.Accordion = function() {
			$(
				".blockspare-block-accordion:not(.blockspare-accordion-ready)"
			).each(function() {
				const $accordion = $(this);
				const itemToggle = $accordion.attr("data-item-toggle");
				const bgcolor = $accordion
					.find(".blockspare-accordion-body")
					.attr("data-bg");
				//$accordion.find('.blockspare-accordion-body').css('background-color', bgcolor);
				$accordion.addClass("blockspare-accordion-ready");
				$accordion.on(
					"click",
					".blockspare-accordion-item .blockspare-accordion-panel",
					function(e) {
						e.preventDefault();

						const $selectedItem = $(this).parent(
							".blockspare-accordion-item"
						);
						const $selectedItemContent = $selectedItem.find(
							".blockspare-accordion-body"
						);
						const isActive = $selectedItem.hasClass(
							"blockspare-accordion-active"
						);

						var _pnl = $accordion
							.find(".blockspare-type-fill")
							.attr("data-pan");
						var text_pnl = $accordion
							.find(".blockspare-type-fill")
							.attr("data-txt-color");

						$accordion
							.find(".blockspare-accordion-panel")
							.css("background-color", _pnl);
						$accordion
							.find(".blockspare-accordion-panel-handler")
							.css("color", text_pnl);

						if (isActive) {
							$selectedItemContent
								.css("display", "block")
								.slideUp(150);
							$selectedItem.removeClass(
								"blockspare-accordion-active"
							);
						} else {
							var act_pnl_text = $accordion
								.find(".blockspare-type-fill")
								.attr("data-act-color");
							var act_pnl = $accordion
								.find(".blockspare-type-fill")
								.attr("data-active");
							$selectedItem
								.find(".blockspare-accordion-panel")
								.css({ "background-color": act_pnl });
							$selectedItem
								.find(".blockspare-accordion-panel-handler")
								.css({ color: act_pnl_text });
							$selectedItemContent
								.css("display", "none")
								.slideDown(150);
							$selectedItem.addClass(
								"blockspare-accordion-active"
							);
						}

						if (itemToggle == "true") {
							const $collapseItems = $accordion
								.find(".blockspare-accordion-active")
								.not($selectedItem);
							if ($collapseItems.length) {
								$collapseItems
									.find(".blockspare-accordion-body")
									.css("display", "block")
									.slideUp(150);
								$collapseItems.removeClass(
									"blockspare-accordion-active"
								);
							}
						}
					}
				);
			});
		});
	n.ImageCarousel = function() {
		var next = $(".blockspare-carousel-items > div").attr("data-next");
		var prev = $(".blockspare-carousel-items > div").attr("data-prev");
		var imageCarousel = $(".blockspare-carousel-items");
		const safeNext = escapeHtmlAttr(next);
		const safePrev = escapeHtmlAttr(prev);
		if (imageCarousel.length > 0) {
			$(".blockspare-carousel-items > div").slick({
				rtl: blockspare_rtl_slick(),
				nextArrow: `<span class="slide-next ${safeNext}"></span>`,
				prevArrow: `<span class="slide-prev ${safePrev}"></span>`,
				responsive: [
					{
						breakpoint: 768,
						settings: {
							slidesToShow: 2,
							slidesToScroll: 2
						}
					},
					{
						breakpoint: 480,
						settings: {
							slidesToShow: 1,
							slidesToScroll: 1
						}
					}
				]
			});
		}
	};

	n.Masonry = function() {
		var container = $(".blockspare-masonry-wrapper ul");
		if (container.length > 0) {
			container.imagesLoaded(function() {
				container.masonry({
					itemSelector: ".blockspare-gallery-item",
					transitionDuration: "0.2s",
					percentPosition: true
				});
			});
		}
	};
	n.postCarousel = function() {
		$(".latest-post-carousel").each(function() {
			var next = $(this).attr("data-next");
			var prev = $(this).attr("data-prev");
			$(this)
				.not(".slick-initialized")
				.slick({
					rtl: blockspare_rtl_slick(),
					nextArrow: '<span class="slide-next ' + next + '"></span>',
					prevArrow: '<span class="slide-prev ' + prev + ' "></span>'
				});
		});
	};

	n.trendingPostCarousel = function() {
		$(".latest-post-trending-carousel").each(function() {
			var next = $(this).attr("data-next");
			var prev = $(this).attr("data-prev");
			$(this)
				.not(".slick-initialized")
				.slick({
					rtl: blockspare_rtl_slick(),
					nextArrow: '<span class="slide-next ' + next + '"></span>',
					prevArrow: '<span class="slide-prev ' + prev + ' "></span>'
				});
		});
	};

	n.banneroneSlider = function() {
		$(".blockspare-banner-slider").each(function() {
			var next = $(this).attr("data-next");
			var prev = $(this).attr("data-prev");
			$(this)
				.not(".slick-initialized")
				.slick({
					rtl: blockspare_rtl_slick(),
					nextArrow: '<span class="slide-next ' + next + '"></span>',
					prevArrow: '<span class="slide-prev ' + prev + ' "></span>'
				});
		});
	};
	n.bannerobeTrending = function() {
		$(".banner-trending-carousel").each(function() {
			var next = $(this).attr("data-next");
			var prev = $(this).attr("data-prev");
			$(this)
				.not(".slick-initialized")
				.slick({
					rtl: blockspare_rtl_slick(),
					nextArrow: '<span class="slide-next ' + next + '"></span>',
					prevArrow: '<span class="slide-prev ' + prev + ' "></span>',
					responsive: [
						{
							breakpoint: 1024,
							settings: {
								slidesToShow: 2,
								slidesToScroll: 1
							}
						},
						{
							breakpoint: 768,
							settings: {
								slidesToShow: 1,
								slidesToScroll: 1
							}
						}
					]
				});
		});
	};

	n.bannerobeTrendingVertical = function() {
		$(".banner-trending-vertical-carousel").each(function() {
			var next = $(this).attr("data-next");
			var prev = $(this).attr("data-prev");
			$(this)
				.not(".slick-initialized")
				.slick({
					nextArrow: '<span class="slide-next ' + next + '"></span>',
					prevArrow: '<span class="slide-prev ' + prev + ' "></span>',
					responsive: [
						{
							breakpoint: 768,
							settings: {
								slidesToShow: 3,
								slidesToScroll: 1
							}
						}
					]
				});
		});
	};

	(n.CurrentTimeRunner = function() {
		document
			.querySelectorAll(".bs-date-time-widget")
			.forEach(function(element) {
				updateLocaleTimeString(element);
			});

		function updateLocaleTimeString(element) {
			if (element) {
				var aftSetInterval = setInterval(function() {
					aftToLocaleTimeString();
				}, 100);
				function aftToLocaleTimeString() {
					var aftDate = new Date();
					var topbarTimeElement = element.querySelector(
						".bs-time-text"
					);
					var aftTimeFormat = topbarTimeElement.getAttribute(
						"bs-format"
					);
					topbarTimeElement.innerHTML = aftDate.toLocaleTimeString(
						"en-US",
						{ timeFormat: aftTimeFormat }
					);
				}
			}
		}
	}),
		$(document).ready(function() {
			var logoWrapper = $(".logo-wrapper");
			if (logoWrapper.length > 0) {
				$(".logo-wrapper").slick();
			}
			var sliderWrapper = $(".slider-wrapper");
			if (sliderWrapper.length > 0) {
				$(".slider-wrapper").slick();
			}
			var instagramLayout = $(".instagram-layout-carousel");
			if (instagramLayout.length > 0) {
				$(".instagram-layout-carousel").slick();
			}
			n.ImageCarousel();
			n.SkillBar();
			n.CountUp();
			n.Tabs();
			n.Masonry();
			n.Accordion();
			n.postCarousel();
			n.trendingPostCarousel();
			n.banneroneSlider();
			n.bannerobeTrending();
			n.Search();
			n.searchReveal();
			n.bannerobeTrendingVertical();
			n.CurrentTimeRunner();
		});
})(jQuery);

