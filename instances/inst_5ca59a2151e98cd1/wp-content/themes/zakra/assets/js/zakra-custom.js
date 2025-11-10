/**
 * Zakra Pro frontend JS file.
 *
 * @package Zakra_Pro
 * @since   1.4.7
 */

var ZakraFrontend = {
	toggleMenu: function (
		handleEl,
		targetEl,
		overlayWrapper,
		closeButton,
		toggleButton,
	) {
		handleEl.addEventListener('click', function () {
			this.classList.toggle('zak-mobile-toggle--opened');
			targetEl.classList.toggle('zak-mobile-nav--opened');

			if (overlayWrapper) {
				overlayWrapper.classList.toggle('overlay-show');
			}

			// Mobile menu close button.
			if (!targetEl.getElementsByClassName('zak-mobile-nav-close').length) {
				// Insert the close icon as first child of menu.
				targetEl.insertBefore(closeButton, targetEl.childNodes[0]);
			}
		});

		// Close menu when clicked outside.
		if (overlayWrapper) {
			overlayWrapper.addEventListener('click', function () {
				this.classList.toggle('overlay-show');
				toggleButton.classList.toggle('zak-mobile-toggle--opened');
				targetEl.classList.toggle('zak-mobile-nav--opened');
			});
		}
	},
};

window.zakraFrontend = ZakraFrontend;

/**
 * Only run scrips when the page is fully loaded.
 */
document.addEventListener('DOMContentLoaded', function () {
	function mobileNavigation() {
		var menu = document.getElementById('zak-mobile-nav'),
			secondaryMenu = document.getElementById('secondary-menu'),
			toggleButton = document.querySelector('.zak-menu-toggle'),
			overlayWrapper = document.querySelector('.zak-overlay-wrapper'),
			adminBar = document.getElementById('wpadminbar'),
			adminBarHeight,
			listItems,
			secondaryMenuListItems,
			secondaryMenuListItem,
			listItem,
			subMenuToggle,
			closeButton,
			i,
			focusableSelectors,
			focusableEl,
			firstEl,
			lastEl;

		// Create close icon element.
		closeButton = document.getElementById('zak-mobile-nav-close');

		document
			.querySelector('.zak-mobile-nav')
			.addEventListener('click', function (e) {
				if (e.target.tagName === 'A') {
					toggleButton.click();
				}
			});

		if (menu) {
			listItems = menu.querySelectorAll(
				'li.page_item_has_children, li.menu-item-has-children',
			);

			if (document.body.contains(adminBar)) {
				adminBarHeight = adminBar.getBoundingClientRect().height;
			}
		}

		if (secondaryMenu) {
			secondaryMenuListItems = secondaryMenu.querySelectorAll(
				'li.page_item_has_children, li.menu-item-has-children',
			);

			if (document.body.contains(adminBar)) {
				adminBarHeight = adminBar.getBoundingClientRect().height;
			}
		}

		// Toggle mobile menu.
		if (toggleButton && menu) {
			closeButton.addEventListener('click', function () {
				toggleButton.click();
			});

			zakraFrontend.toggleMenu(
				toggleButton,
				menu,
				overlayWrapper,
				closeButton,
				toggleButton,
			);

			/**
			 * Open mobile menu on clicking toggle button.
			 */
			toggleButton.addEventListener('click', function () {
				focusableSelectors = 'a, button, input[type="search"]';
				focusableEl = menu.querySelectorAll(focusableSelectors);
				focusableEl = Array.prototype.slice.call(focusableEl);

				firstEl = focusableEl[0];
				lastEl = focusableEl[focusableEl.length - 1];

				// Set focus to first element.
				setTimeout(function () {
					firstEl.focus();
				}, 100);

				// Loop focus while using tab and shift+tab key.
				menu.addEventListener('keydown', function (e) {
					if ('Tab' === e.key) {
						if (e.shiftKey) {
							if (document.activeElement === firstEl) {
								e.preventDefault();
								lastEl.focus();
							}
						} else {
							if (document.activeElement === lastEl) {
								e.preventDefault();
								firstEl.focus();
							}
						}
					}
				});
			});
		}

		/* Sub Menu toggle */
		if (listItems) {
			let submenuCount = listItems.length;

			for (i = 0; i < submenuCount; i++) {
				listItem = listItems[i];

				subMenuToggle = listItem.querySelector('.zak-submenu-toggle');

				if (null !== subMenuToggle) {
					subMenuToggle.addEventListener('click', function (e) {
						e.preventDefault();

						this.parentNode.classList.toggle('submenu--show');
					});
				}

				if (null !== listItem.querySelector('a')) {
					var link = listItem.querySelector('a').getAttribute('href');
					var listItem_link = listItem.querySelector('a');

					if (!link || '#' === link) {
						listItem_link.addEventListener('click', function (e) {
							e.preventDefault();
							menu.classList.toggle('zak-mobile-nav--opened');
							listItem.classList.toggle('submenu--show');
						});
					}
				}
			}
		}

		/* Secondary Sub Menu toggle */
		if (secondaryMenuListItems) {
			let submenuCount = secondaryMenuListItems.length;

			for (i = 0; i < submenuCount; i++) {
				secondaryMenuListItem = secondaryMenuListItems[i];

				subMenuToggle = secondaryMenuListItem.querySelector(
					'.zak-submenu-toggle',
				);

				if (null !== subMenuToggle) {
					subMenuToggle.addEventListener('click', function (e) {
						e.preventDefault();

						this.parentNode.classList.toggle('zak-submenu--show');
					});
				}

				if (null !== secondaryMenuListItem.querySelector('a')) {
					var link = secondaryMenuListItem
						.querySelector('a')
						.getAttribute('href');
					var listItem_link = secondaryMenuListItem.querySelector('a');

					if (!link || '#' === link) {
						listItem_link.addEventListener('click', function (e) {
							// menu.classList.toggle( 'zak-mobile-nav--opened' );
							this.parentNode.classList.toggle('submenu--show');
						});
					}
				}
			}
		}
	}

	/**
	 * Scroll to top button.
	 */
	function scrollToTop() {
		var scrollToTopButton = document.getElementById('zak-scroll-to-top');

		/* Only proceed when the button is present. */
		if (scrollToTopButton) {
			/* On scroll and show and hide button. */
			window.addEventListener('scroll', function () {
				if (500 < window.scrollY) {
					scrollToTopButton.classList.add('zak-scroll-to-top--show');
				} else if (500 > window.scrollY) {
					scrollToTopButton.classList.remove('zak-scroll-to-top--show');
				}
			});

			/* Scroll to top when clicked on button. */
			scrollToTopButton.addEventListener('click', function (e) {
				e.preventDefault();

				/* Only scroll to top if we are not in top */
				if (0 !== window.scrollY) {
					window.scrollTo({
						top: 0,
						behavior: 'smooth',
					});
				}
			});
		}
	}

	try {
		mobileNavigation();
	} catch (e) {
		console.log(e.message);
	}

	scrollToTop();

	/**
	 * Search form.
	 */
	(function () {
		let searchIcon,
			searchBox,
			getClosest,
			closeIcon,
			contentBackDrop,
			footerBackDrop;

		searchIcon = document.querySelector('.zak-header-search__toggle');
		searchBox = document.querySelector('.zak-main-header');
		contentBackDrop = document.querySelector('.zak-content');
		footerBackDrop = document.querySelector('.zak-footer');
		closeIcon = document.querySelector('.zak-header-search .zak-icon--close');

		getClosest = function (elem, selector) {
			// Element.matches() polyfill
			if (!Element.prototype.matches) {
				Element.prototype.matches =
					Element.prototype.matchesSelector ||
					Element.prototype.mozMatchesSelector ||
					Element.prototype.msMatchesSelector ||
					Element.prototype.oMatchesSelector ||
					Element.prototype.webkitMatchesSelector ||
					function (s) {
						var matches = (
								this.document || this.ownerDocument
							).querySelectorAll(s),
							i = matches.length;

						while (0 <= --i && matches.item(i) !== this) {
							// TODO: Check and remove this empty loop
						}

						return -1 < i;
					};
			}

			// Get the closest matching element.
			for (; elem && elem !== document; elem = elem.parentNode) {
				if (elem.matches(selector)) {
					return elem;
				}
			}

			return null;
		};

		/**
		 * Show hide search form.
		 */
		function showHideSearchForm(action) {
			// Hide search form.
			if ('hide' === action) {
				searchBox.classList.remove('zak-header-search--opened');
				contentBackDrop.classList.remove('backdrop');
				footerBackDrop.classList.remove('backdrop');

				return;
			}

			// Toggle search form.
			searchBox.classList.toggle('zak-header-search--opened');
			contentBackDrop.classList.toggle('backdrop');
			footerBackDrop.classList.toggle('backdrop');

			// Autofocus.
			if (searchBox.classList.contains('zak-header-search--opened')) {
				setTimeout(function () {
					console.log();
					searchBox.getElementsByTagName('input')[0].focus();
				}, 300);

				document
					.querySelector('.zak-search-container')
					.addEventListener('keydown', function (e) {
						let element = document
								.querySelector('.zak-search-container')
								.querySelectorAll('input[type="search"],button'),
							firstElement = element[0],
							lastElement = element[element.length - 1],
							tab = e.keyCode === 9 || e.key === 'Tab';

						if (!tab) {
							return;
						}

						if (e.shiftKey) {
							if (document.activeElement === firstElement) {
								e.preventDefault();
								lastElement.focus();
							}
						} else if (document.activeElement === lastElement) {
							e.preventDefault();
							firstElement.focus();
						}
					});
			}
		}

		// If icon exists.
		if (null !== searchIcon) {
			// On search icon click.
			searchIcon.addEventListener('click', function (ev) {
				ev.preventDefault();

				showHideSearchForm();
			});

			// On close icon click.
			closeIcon.addEventListener('click', function (e) {
				searchBox.classList.remove('zak-header-search--opened');
				contentBackDrop.classList.remove('backdrop');
				footerBackDrop.classList.remove('backdrop');

				return;
			});

			// on click outside form.
			document.addEventListener('click', function (ev) {
				var closest = typeof ev.target.closest;

				if (
					ev.target.closest('.zak-main-header') ||
					ev.target.closest('.zak-icon-search')
				) {
					return;
				}

				showHideSearchForm('hide');
			});

			// on esc key.
			document.addEventListener('keyup', function (e) {
				if (
					searchBox.classList.contains('zak-header-search--opened') &&
					'Escape' === e.key
				) {
					showHideSearchForm('hide');
				}
			});
		}
	})();

	/**
	 * Transparent Header.
	 */
	var body = document.getElementsByTagName('body')[0],
		headerTop = body.getElementsByClassName('.zak-top-bar')[0];

	function transparentHeader(body, headerTop) {
		var headerTopHt = headerTop.offsetHeight,
			main = document.getElementById('main'),
			footer = document.getElementById('zak-footer');

		main.style.position = 'relative';
		main.style.top = headerTopHt + 'px';
		footer.style.position = 'relative';
		footer.style.top = headerTopHt + 'px';
	}

	if (
		body.classList.contains('has-transparent-header') &&
		'undefined' != typeof headerTop &&
		headerTop.classList.contains('.zak-top-bar')
	) {
		transparentHeader(body, headerTop);
	}
});
