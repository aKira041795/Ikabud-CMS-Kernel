//--------------------------------------------------------------------//
// Global helper functions
//--------------------------------------------------------------------//

/**
 * Matches polyfill.
 *
 * @since 1.0.0
 */
if ( ! Element.prototype.matches ) {
	Element.prototype.matches = Element.prototype.msMatchesSelector || Element.prototype.webkitMatchesSelector;
}

/**
 * Closest polyfill.
 *
 * @since 1.0.0
 */
if ( ! Element.prototype.closest ) {
	Element.prototype.closest = function( s ) {
		var el = this;

		do {
			if ( el.matches( s ) ) {
				return el;
			}
			el = el.parentElement || el.parentNode;
		} while ( null !== el && 1 === el.nodeType );

		return null;
	};
}

/**
 * Foreach polyfill.
 *
 * @since 1.0.0
 */
if ( window.NodeList && ! NodeList.prototype.forEach ) {
	NodeList.prototype.forEach = Array.prototype.forEach;
}

/**
 * Element.prototype.classList for IE8/9, Safari.
 *
 * @since 1.10
 */
( function() {

	// Helpers.
	var trim = function( s ) {
		return s.replace( /^\s+|\s+$/g, '' );
	},
	regExp = function( name ) {
		return new RegExp( '(^|\\s+)' + name + '(\\s+|$)' );
	},
	forEach = function( list, fn, scope ) {
		for ( var i = 0; i < list.length; i++ ) {
			fn.call( scope, list[i]);
		}
	};

	// Class list object with basic methods.
	function ClassList( element ) {
		this.element = element;
	}

	ClassList.prototype = {
		add: function() {
			forEach(
				arguments,
				function( name ) {
					if ( ! this.contains( name ) ) {
						this.element.className = trim( this.element.className + ' ' + name );
					}
				},
				this
			);
		},
		remove: function() {
			forEach(
				arguments,
				function( name ) {
					this.element.className = trim( this.element.className.replace( regExp( name ), ' ' ) );
				},
				this
			);
		},
		toggle: function( name ) {
			return this.contains( name ) ? ( this.remove( name ), false ) : ( this.add( name ), true );
		},
		contains: function( name ) {
			return regExp( name ).test( this.element.className );
		},
		item: function( i ) {
			return this.element.className.split( /\s+/ )[i] || null;
		},

		// bonus
		replace: function( oldName, newName ) {
			this.remove( oldName ), this.add( newName );
		}
	};

	// IE8/9, Safari
	// Remove this if statements to override native classList.
	if ( ! ( 'classList' in Element.prototype ) ) {

		// Use this if statement to override native classList that does not have for example replace() method.
		// See browser compatibility: https://developer.mozilla.org/en-US/docs/Web/API/Element/classList#Browser_compatibility.
		// if (!('classList' in Element.prototype) ||
		//     !('classList' in Element.prototype && Element.prototype.classList.replace)) {
		Object.defineProperty( Element.prototype, 'classList', {
			get: function() {
				return new ClassList( this );
			}
		});
	}

	// For others replace() support.
	if ( window.DOMTokenList && ! DOMTokenList.prototype.replace ) {
		DOMTokenList.prototype.replace = ClassList.prototype.replace;
	}
}() );

/**
 * Index polyfill.
 *
 * @since 1.0.0
 */
var bloghashGetIndex = function( el ) {
	var i = 0;

	while ( ( el = el.previousElementSibling ) ) {
		i++;
	}

	return i;
};

/**
 * Slide Up animation.
 *
 * @since 1.0.0
 *
 * @param  {[type]} target   Element to slide.
 * @param  {Number} duration Animation duration.
 */
var bloghashSlideUp = ( target, duration = 500 ) => {
	target.style.transitionProperty = 'height, margin, padding';
	target.style.transitionDuration = duration + 'ms';
	target.style.boxSizing = 'border-box';
	target.style.height = target.offsetHeight + 'px';
	target.offsetHeight;
	target.style.overflow = 'hidden';
	target.style.height = 0;
	target.style.paddingTop = 0;
	target.style.paddingBottom = 0;
	target.style.marginTop = 0;
	target.style.marginBottom = 0;
	window.setTimeout( () => {
		target.style.display = null;
		target.style.removeProperty( 'height' );
		target.style.removeProperty( 'padding-top' );
		target.style.removeProperty( 'padding-bottom' );
		target.style.removeProperty( 'margin-top' );
		target.style.removeProperty( 'margin-bottom' );
		target.style.removeProperty( 'overflow' );
		target.style.removeProperty( 'transition-duration' );
		target.style.removeProperty( 'transition-property' );
	}, duration );
};

/**
 * Slide Down animation.
 *
 * @since 1.0.0
 *
 * @param  {[type]} target   Element to slide.
 * @param  {Number} duration Animation duration.
 */
var bloghashSlideDown = ( target, duration = 500 ) => {
	target.style.removeProperty( 'display' );
	let display = window.getComputedStyle( target ).display;

	if ( 'none' === display ) {
		display = 'block';
	}

	target.style.display = display;
	let height = target.offsetHeight;
	target.style.overflow = 'hidden';
	target.style.height = 0;
	target.style.paddingTop = 0;
	target.style.paddingBottom = 0;
	target.style.marginTop = 0;
	target.style.marginBottom = 0;
	target.offsetHeight;
	target.style.boxSizing = 'border-box';
	target.style.transitionProperty = 'height, margin, padding';
	target.style.transitionDuration = duration + 'ms';
	target.style.height = height + 'px';
	target.style.removeProperty( 'padding-top' );
	target.style.removeProperty( 'padding-bottom' );
	target.style.removeProperty( 'margin-top' );
	target.style.removeProperty( 'margin-bottom' );
	window.setTimeout( () => {
		target.style.removeProperty( 'height' );
		target.style.removeProperty( 'overflow' );
		target.style.removeProperty( 'transition-duration' );
		target.style.removeProperty( 'transition-property' );
	}, duration );
};

/**
 * MoveTo - A lightweight scroll animation javascript library without any dependency.
 * Version 1.8.3 (21-07-2019 00:32)
 * Licensed under MIT
 * Copyright 2019 Hasan Aydoğdu <hsnaydd@gmail.com>
 */
var bloghashScrollTo = ( function() {

	/**
	 * Defaults
	 * @type {object}
	 */
	var defaults = {
		tolerance: 0,
		duration: 800,
		easing: 'easeOutQuart',
		container: window,
		callback: function callback() { }
	};

	/**
	 * easeOutQuart Easing Function
	 * @param  {number} t - current time
	 * @param  {number} b - start value
	 * @param  {number} c - change in value
	 * @param  {number} d - duration
	 * @return {number} - calculated value
	 */

	function easeOutQuart( t, b, c, d ) {
		t /= d;
		t--;
		return -c * ( t * t * t * t - 1 ) + b;
	}

	/**
	 * Merge two object
	 *
	 * @param  {object} obj1
	 * @param  {object} obj2
	 * @return {object} merged object
	 */
	function mergeObject( obj1, obj2 ) {
		var obj3 = {};
		Object.keys( obj1 ).forEach( function( propertyName ) {
			obj3[propertyName] = obj1[propertyName];
		});
		Object.keys( obj2 ).forEach( function( propertyName ) {
			obj3[propertyName] = obj2[propertyName];
		});
		return obj3;
	}

	/**
	 * Converts camel case to kebab case
	 * @param  {string} val the value to be converted
	 * @return {string} the converted value
	 */
	function kebabCase( val ) {
		return val.replace( /([A-Z])/g, function( $1 ) {
			return '-' + $1.toLowerCase();
		});
	}

	/**
	 * Count a number of item scrolled top
	 * @param  {Window|HTMLElement} container
	 * @return {number}
	 */
	function countScrollTop( container ) {
		if ( container instanceof HTMLElement ) {
			return container.scrollTop;
		}

		return container.pageYOffset;
	}

	/**
	 * bloghashScrollTo Constructor
	 * @param {object} options Options
	 * @param {object} easeFunctions Custom ease functions
	 */
	function bloghashScrollTo() {
		var options = 0 < arguments.length && arguments[0] !== undefined ? arguments[0] : {};
		var easeFunctions = 1 < arguments.length && arguments[1] !== undefined ? arguments[1] : {};
		this.options = mergeObject( defaults, options );
		this.easeFunctions = mergeObject(
			{
				easeOutQuart: easeOutQuart
			},
			easeFunctions
		);
	}

	/**
	 * Register a dom element as trigger
	 * @param  {HTMLElement} dom Dom trigger element
	 * @param  {function} callback Callback function
	 * @return {function|void} unregister function
	 */
	bloghashScrollTo.prototype.registerTrigger = function( dom, callback ) {
		var _this = this;

		if ( ! dom ) {
			return;
		}

		var href = dom.getAttribute( 'href' ) || dom.getAttribute( 'data-target' ); // The element to be scrolled

		var target = href && '#' !== href ? document.getElementById( href.substring( 1 ) ) : document.body;
		var options = mergeObject( this.options, _getOptionsFromTriggerDom( dom, this.options ) );

		if ( 'function' === typeof callback ) {
			options.callback = callback;
		}

		var listener = function listener( e ) {
			e.preventDefault();

			_this.move( target, options );
		};

		dom.addEventListener( 'click', listener, false );
		return function() {
			return dom.removeEventListener( 'click', listener, false );
		};
	};

	/**
	 * Move
	 * Scrolls to given element by using easeOutQuart function
	 * @param  {HTMLElement|number} target Target element to be scrolled or target position
	 * @param  {object} options Custom options
	 */
	bloghashScrollTo.prototype.move = function( target ) {
		var _this2 = this;

		var options = 1 < arguments.length && arguments[1] !== undefined ? arguments[1] : {};

		if ( 0 !== target && ! target ) {
			return;
		}

		options = mergeObject( this.options, options );
		var distance = 'number' === typeof target ? target : target.getBoundingClientRect().top;
		var from = countScrollTop( options.container );
		var startTime = null;
		var lastYOffset;
		distance -= options.tolerance; // rAF loop

		var loop = function loop( currentTime ) {
			var currentYOffset = countScrollTop( _this2.options.container );

			if ( ! startTime ) {

				// To starts time from 1, we subtracted 1 from current time
				// If time starts from 1 The first loop will not do anything,
				// because easing value will be zero
				startTime = currentTime - 1;
			}

			var timeElapsed = currentTime - startTime;

			if ( lastYOffset ) {
				if ( ( 0 < distance && lastYOffset > currentYOffset ) || ( 0 > distance && lastYOffset < currentYOffset ) ) {
					return options.callback( target );
				}
			}

			lastYOffset = currentYOffset;

			var val = _this2.easeFunctions[options.easing]( timeElapsed, from, distance, options.duration );

			options.container.scroll( 0, val );

			if ( timeElapsed < options.duration ) {
				window.requestAnimationFrame( loop );
			} else {
				options.container.scroll( 0, distance + from );
				options.callback( target );
			}
		};

		window.requestAnimationFrame( loop );
	};

	/**
	 * Adds custom ease function
	 * @param {string}   name Ease function name
	 * @param {function} fn   Ease function
	 */
	bloghashScrollTo.prototype.addEaseFunction = function( name, fn ) {
		this.easeFunctions[name] = fn;
	};

	/**
	 * Returns options which created from trigger dom element
	 * @param  {HTMLElement} dom Trigger dom element
	 * @param  {object} options The instance's options
	 * @return {object} The options which created from trigger dom element
	 */
	function _getOptionsFromTriggerDom( dom, options ) {
		var domOptions = {};
		Object.keys( options ).forEach( function( key ) {
			var value = dom.getAttribute( 'data-mt-'.concat( kebabCase( key ) ) );

			if ( value ) {
				domOptions[key] = isNaN( value ) ? value : parseInt( value, 10 );
			}
		});
		return domOptions;
	}

	return bloghashScrollTo;
}() );

/**
 * Get all of an element's parent elements up the DOM tree
 *
 * @since 1.0.0
 *
 * @param  {Node}   elem     The element.
 * @param  {String} selector Selector to match against [optional].
 * @return {Array}           The parent elements.
 */
var bloghashGetParents = ( elem, selector ) => {

	// Element.matches() polyfill.
	if ( ! Element.prototype.matches ) {
		Element.prototype.matches =
			Element.prototype.matchesSelector ||
			Element.prototype.mozMatchesSelector ||
			Element.prototype.msMatchesSelector ||
			Element.prototype.oMatchesSelector ||
			Element.prototype.webkitMatchesSelector ||
			function( s ) {
				var matches = ( this.document || this.ownerDocument ).querySelectorAll( s ),
					i = matches.length;
				while ( 0 <= --i && matches.item( i ) !== this ) { }
				return -1 < i;
			};
	}

	// Setup parents array.
	var parents = [];

	// Get matching parent elements.
	for ( ; elem && elem !== document; elem = elem.parentNode ) {

		// Add matching parents to array.
		if ( selector ) {
			if ( elem.matches( selector ) ) {
				parents.push( elem );
			}
		} else {
			parents.push( elem );
		}
	}
	return parents;
};

// CustomEvent() constructor functionality in Internet Explorer 9 and higher.
( function() {
	if ( 'function' === typeof window.CustomEvent ) {
		return false;
	}

	function CustomEvent( event, params ) {
		params = params || { bubbles: false, cancelable: false, detail: undefined };
		var evt = document.createEvent( 'CustomEvent' );
		evt.initCustomEvent( event, params.bubbles, params.cancelable, params.detail );
		return evt;
	}

	CustomEvent.prototype = window.Event.prototype;
	window.CustomEvent = CustomEvent;
}() );

/**
 * Trigger custom JS Event.
 *
 * @since 1.0.0
 *
 * @link https://developer.mozilla.org/en-US/docs/Web/API/CustomEvent
 * @param {Node} el Dom Node element on which the event is to be triggered.
 * @param {Node} typeArg A DOMString representing the name of the event.
 * @param {String} A CustomEventInit dictionary, having the following fields:
 *			"detail", optional and defaulting to null, of type any, that is an event-dependent value associated with the event.
 */
var bloghashTriggerEvent = function( el, typeArg ) {
	var customEventInit = 2 < arguments.length && arguments[2] !== undefined ? arguments[2] : {};

	var event = new CustomEvent( typeArg, customEventInit );
	el.dispatchEvent( event );
};

// Main
( function() {

	//--------------------------------------------------------------------//
	// Variable caching
	//--------------------------------------------------------------------//

	var bloghashScrollButton = document.querySelector( '#bloghash-scroll-top' );
	var pageWrapper = document.getElementById( 'page' );

	//--------------------------------------------------------------------//
	// Local helper functions
	//--------------------------------------------------------------------//

	/**
 	*
	* @param {*} button
	*/
	function initLoadingTargetButton(button) {
		let entry = document.createElement('span');
		entry.classList.add('wait');

		let wrapButtonText = document.createElement('span');
		wrapButtonText.innerHTML = button.innerHTML;
		wrapButtonText.style.display = "none";

		button.innerHTML = '';
		button.append(entry);
		button.append(wrapButtonText);
		button.disabled = true;
	}
	/**
	 *
	 * @param {*} button
	 */
	function endLoadingTargetButton(button) {
		button.children[0].remove();
		const text = button.children[0].innerHTML;
		button.children[0].remove();
		button.innerHTML = text;
		button.disabled = false;
	}

	/**
	 * Submenu overflow helper
	 *
	 * @since 1.0.0
	 */
	var bloghashSmartSubmenus = () => {
		if ( document.body.classList.contains( 'bloghash-is-mobile' ) ) {
			return;
		}

		var el, elPosRight, elPosLeft, winRight;

		winRight = window.innerWidth;

		document.querySelectorAll( '.sub-menu' ).forEach( ( item ) => {

			// Set item to be visible so we can grab offsets
			item.style.visibility = 'visible';

			// Left offset
			const rect = item.getBoundingClientRect();
			elPosLeft = rect.left + window.pageXOffset;

			// Right offset
			elPosRight = elPosLeft + rect.width;

			// Remove styles
			item.removeAttribute( 'style' );

			// Decide where to open
			if ( elPosRight > winRight ) {
				item.closest( 'li' ).classList.add( 'opens-left' );
			} else if ( 0 > elPosLeft ) {
				item.closest( 'li' ).classList.add( 'opens-right' );
			}
		});
	};

	/**
	 * Debounce functions for better performance
	 * (c) 2018 Chris Ferdinandi, MIT License, https://gomakethings.com
	 *
	 * @since 1.0.0
	 *
	 * @param  {Function} fn The function to debounce
	 */
	var bloghashDebounce = ( fn ) => {

		// Setup a timer
		var timeout;

		// Return a function to run debounced
		return function() {

			// Setup the arguments
			var context = this;
			var args = arguments;

			// If there's a timer, cancel it
			if ( timeout ) {
				window.cancelAnimationFrame( timeout );
			}

			// Setup the new requestAnimationFrame()
			timeout = window.requestAnimationFrame( function() {
				fn.apply( this, args );
			});
		};
	};

	/**
	 * Handles Scroll to Top button click
	 *
	 * @since 1.0.0
	 */
	var bloghashScrollTopButton = () => {
		if ( null === bloghashScrollButton ) {
			return;
		}

		if ( 450 < window.pageYOffset || 450 < document.documentElement.scrollTop ) {
			bloghashScrollButton.classList.add( 'bloghash-visible' );
		} else {
			bloghashScrollButton.classList.remove( 'bloghash-visible' );
		}
	};

	/**
	 * Handles Sticky Header functionality.
	 *
	 * @since 1.0.0
	 */
	var bloghashStickyHeader = () => {

		// Check if sticky is enabled.
		if ( ! bloghash_vars['sticky-header'].enabled ) {
			return;
		}

		var header = document.getElementById( 'bloghash-header' );
		var headerInner = document.getElementById( 'bloghash-header-inner' );
		var wpadminbar = document.getElementById( 'wpadminbar' );

		// Check for header layout 3 & 4.
		if ( document.body.classList.contains( 'bloghash-header-layout-3' ) || document.body.classList.contains( 'bloghash-header-layout-4' ) || document.body.classList.contains( 'bloghash-header-layout-6' ) ) {
			header = document.querySelector( '#bloghash-header .bloghash-nav-container' );
			headerInner = document.querySelector( '#bloghash-header .bloghash-nav-container .bloghash-container' );
		}

		// Mobile nav active.
		if ( window.outerWidth <= bloghash_vars['responsive-breakpoint']) {
			var header = document.getElementById( 'bloghash-header' );
			var headerInner = document.getElementById( 'bloghash-header-inner' );
		}

		// Check if elements exist.
		if ( null === header || null === headerInner ) {
			return;
		}

		// Calculate the initial sticky position.
		var stickyPosition = header.getBoundingClientRect().bottom;
		var sticky = 0 >= stickyPosition - tolerance;
		var tolerance;
		var stickyPlaceholder;

		// Check if there is a top bar.
		if ( null === wpadminbar ) {
			tolerance = 0;
		} else if ( 600 >= window.outerWidth ) {
			tolerance = 0;
		} else {
			tolerance = wpadminbar.getBoundingClientRect().height;
		}

		var checkPosition = function() {

			if ( null === wpadminbar ) {
				tolerance = 0;
			} else if ( 600 >= window.outerWidth ) {
				tolerance = 0;
			} else {
				tolerance = wpadminbar.getBoundingClientRect().height;
			}


			stickyPosition = header.getBoundingClientRect().bottom;
			sticky = 0 >= stickyPosition - tolerance;

			maybeStickHeader();
		};

		var maybeStickHeader = function() {
			let hideOn = bloghash_vars['sticky-header']['hide_on'];

			// Desktop.
			if ( hideOn.includes( 'desktop' ) && 992 <= window.innerWidth ) {
				sticky = false;
			}

			// Tablet.
			if ( hideOn.includes( 'tablet' ) && 481 <= window.innerWidth && 992 > window.innerWidth ) {
				sticky = false;
			}

			// Mobile.
			if ( hideOn.includes( 'mobile' ) && 481 > window.innerWidth ) {
				sticky = false;
			}

			if ( sticky ) {
				if ( ! document.body.classList.contains( 'bloghash-sticky-header' ) ) {
					stickyPlaceholder = document.createElement( 'div' );
					stickyPlaceholder.setAttribute( 'id', 'bloghash-sticky-placeholder' );

					stickyPlaceholder.style.height = headerInner.getBoundingClientRect().height + 'px';
					header.appendChild( stickyPlaceholder );

					document.body.classList.add( 'bloghash-sticky-header' );

					// Add sticky header offset variable.
					document.body.style.setProperty( '--bloghash-sticky-h-offset', header.offsetHeight + 20 + 'px' );
				}
			} else {
				if ( document.body.classList.contains( 'bloghash-sticky-header' ) ) {
					document.body.classList.remove( 'bloghash-sticky-header' );
					document.getElementById( 'bloghash-sticky-placeholder' ).remove();
				}

				// Remove sticky header offset variable.
				document.body.style.removeProperty( '--bloghash-sticky-h-offset' );
			}
		};

		// Debounce scroll.
		if ( 'true' !== header.getAttribute( 'data-scroll-listener' ) ) {
			window.addEventListener( 'scroll', function() {
				bloghashDebounce( checkPosition() );
			});

			header.setAttribute( 'data-scroll-listener', 'true' );
		}

		// Debounce resize.
		if ( 'true' !== header.getAttribute( 'data-resize-listener' ) ) {
			window.addEventListener( 'resize', function() {
				bloghashDebounce( checkPosition() );
			});

			header.setAttribute( 'data-resize-listener', 'true' );
		}

		// Trigger scroll.
		bloghashTriggerEvent( window, 'scroll' );
	};

	/**
	 * Handles smooth scrolling of elements that have 'bloghash-smooth-scroll' class.
	 *
	 * @since 1.0.0
	 */
	var bloghashSmoothScroll = () => {
		const scrollTo = new bloghashScrollTo({
			tolerance: null === document.getElementById( 'wpadminbar' ) ? 0 : document.getElementById( 'wpadminbar' ).getBoundingClientRect().height
		});

		const scrollTriggers = document.getElementsByClassName( 'bloghash-smooth-scroll' );

		for ( var i = 0; i < scrollTriggers.length; i++ ) {
			scrollTo.registerTrigger( scrollTriggers[i]);
		}
	};

	/**
	 * Menu accessibility.
	 *
	 * @since 1.0.0
	 */
	var bloghashMenuAccessibility = () => {
		if ( ! document.body.classList.contains( 'bloghash-menu-accessibility' ) ) {
			return;
		}

		document.querySelectorAll( '.bloghash-nav' ).forEach( ( menu ) => {

			// aria-haspopup
			menu.querySelectorAll( 'ul' ).forEach( ( subMenu ) => {
				subMenu.parentNode.setAttribute( 'aria-haspopup', 'true' );
			});

			// Dropdown visibility on focus
			menu.querySelectorAll( 'a' ).forEach( ( link ) => {
				link.addEventListener( 'focus', bloghashMenuFocus, true );
				link.addEventListener( 'blur', bloghashMenuFocus, true );
			});
		});
	};

	/**
	 * Helper function that toggles .hovered on focused/blurred menu items.
	 *
	 * @since 1.0.0
	 */
	function bloghashMenuFocus() {
		var self = this;

		// Move up until we find .bloghash-nav
		while ( ! self.classList.contains( 'bloghash-nav' ) ) {
			if ( 'li' === self.tagName.toLowerCase() ) {
				if ( ! self.classList.contains( 'hovered' ) ) {
					self.classList.add( 'hovered' );
				} else {
					self.classList.remove( 'hovered' );
				}
			}

			self = self.parentElement;
		}
	}

	/**
	 * Helps with accessibility for keyboard only users.
	 *
	 * @since 1.0.0
	 */
	var bloghashKeyboardFocus = () => {
		document.body.addEventListener( 'keydown', function( e ) {
			document.body.classList.add( 'using-keyboard' );
		});

		document.body.addEventListener( 'mousedown', function( e ) {
			document.body.classList.remove( 'using-keyboard' );
		});
	};

	/**
	 * Calculates screen width without scrollbars.
	 *
	 * @since 1.0.0
	 */
	var bloghashCalcScreenWidth = () => {
		document.body.style.setProperty( '--bloghash-screen-width', document.body.clientWidth + 'px' );
	};

	/**
	 * Adds visibility delay on navigation submenus.
	 *
	 * @since 1.0.0
	 */
	var bloghashDropdownDelay = () => {
		var hoverTimer = null;

		document.querySelectorAll( '.bloghash-nav .menu-item-has-children' ).forEach( ( item ) => {
			item.addEventListener( 'mouseenter', function() {
				document.querySelectorAll( '.menu-item-has-children' ).forEach( ( subitem ) => {
					subitem.classList.remove( 'hovered' );
				});
			});
		});

		document.querySelectorAll( '.bloghash-nav .menu-item-has-children' ).forEach( ( item ) => {
			item.addEventListener( 'mouseleave', function() {
				item.classList.add( 'hovered' );

				if ( null !== hoverTimer ) {
					clearTimeout( hoverTimer );
					hoverTimer = null;
				}

				hoverTimer = setTimeout( () => {
					item.classList.remove( 'hovered' );

					item.querySelectorAll( '.menu-item-has-children' ).forEach( ( childItem ) => {
						childItem.classList.remove( 'hovered' );
					});
				}, 700 );
			});
		});
	};

	/**
	 * Adds visibility delay for cart widget dropdown.
	 *
	 * @since 1.0.0
	 */
	var bloghashCartDropdownDelay = () => {
		var hoverTimer = null;

		document.querySelectorAll( '.bloghash-header-widget__cart .bloghash-widget-wrapper' ).forEach( ( item ) => {
			item.addEventListener( 'mouseenter', function() {
				item.classList.remove( 'dropdown-visible' );
			});
		});

		document.querySelectorAll( '.bloghash-header-widget__cart .bloghash-widget-wrapper' ).forEach( ( item ) => {
			item.addEventListener( 'mouseleave', function() {
				item.classList.add( 'dropdown-visible' );

				if ( null !== hoverTimer ) {
					clearTimeout( hoverTimer );
					hoverTimer = null;
				}

				hoverTimer = setTimeout( () => {
					item.classList.remove( 'dropdown-visible' );
				}, 700 );
			});
		});
	};

	/**
	 * Handles header search widget functionality.
	 *
	 * @since 1.0.0
	 */
	var bloghashHeaderSearch = () => {
		var searchButton = document.querySelectorAll( '.bloghash-search' );

		if ( 0 === searchButton.length ) {
			return;
		}

		searchButton.forEach( ( item ) => {
			item.addEventListener( 'click', ( e ) => {
				e.preventDefault();

				if ( item.classList.contains( 'bloghash-active' ) ) {
					close_search( item );
				} else {
					show_search( item );
				}
			});
		});

		document.querySelectorAll( '.bloghash-search-close' ).forEach( item => item.addEventListener( 'click', function( e ) {
			e.preventDefault();
			if ( ! item.classList.contains( 'bloghash-active' ) ) {
				close_search( document.querySelector( '.bloghash-search' ) );
				document.querySelector( '.bloghash-search' ).focus();
			}
		}) );

		// Show search.
		var show_search = function( item ) {

			var bloghashsearch = document.querySelectorAll( '.bloghash-search-form' );
			bloghashsearch.forEach( function( bloghashsearch ) {
				if ( ! bloghashsearch ) {
					return false;
				}
				document.addEventListener( 'keydown', function( e ) {
					var firstEl, selectors, elements, lastEl, tabKey, shiftKey;
					selectors = 'input, a, button';
					elements = bloghashsearch.querySelectorAll( selectors );
					lastEl = elements[elements.length - 1];
					firstEl = elements[0];
					tabKey = 9 === e.keyCode;
					shiftKey = e.shiftKey;
					if ( ! shiftKey && tabKey && lastEl === document.activeElement ) {
						e.preventDefault();
						firstEl.focus();
					}
					if ( shiftKey && tabKey && firstEl === document.activeElement ) {
						e.preventDefault();
						lastEl.focus();
					}
				});
			});

			// Make search visible
			document.body.classList.add( 'bloghash-search-visible' );

			setTimeout( function() {

				// Highlight the search icon
				item.classList.add( 'bloghash-active' );

				// Focus the input
				if ( null !== item.nextElementSibling && null !== item.nextElementSibling.querySelector( 'input' ) ) {
					item.nextElementSibling.querySelector( 'input' ).focus();
					item.nextElementSibling.querySelector( 'input' ).select();
				}
			}, 100 );

			// Attach the ESC listener
			document.addEventListener( 'keydown', esc_close_search );

			// Attach the outside click listener
			pageWrapper.addEventListener( 'click', outside_close_search );
		};

		// Close search
		var close_search = function( item ) {

			// Animate out
			document.body.classList.remove( 'bloghash-search-visible' );

			// Unhighlight the search icon
			item.classList.remove( 'bloghash-active' );

			// Unhook the ESC listener
			document.removeEventListener( 'keydown', esc_close_search );

			// Unhook the click listener
			pageWrapper.removeEventListener( 'click', outside_close_search );
		};

		// Esc support to close search
		var esc_close_search = function( e ) {
			if ( 27 == e.keyCode ) {
				document.querySelectorAll( '.bloghash-search' ).forEach( ( item ) => {
					close_search( item );
				});
			}
		};

		// Close search when clicked anywhere outside the search box
		var outside_close_search = function( e ) {
			if ( null === e.target.closest( '.bloghash-search-container' ) && null === e.target.closest( '.bloghash-search' ) ) {
				document.querySelectorAll( '.bloghash-search' ).forEach( ( item ) => {
					close_search( item );
				});
			}
		};
	};

	/**
	 * Handles mobile menu functionality.
	 *
	 * @since 1.0.0
	 */
	var bloghashMobileMenu = () => {
		var page = pageWrapper,
			nav = document.querySelector( '#bloghash-header-inner .bloghash-nav' ),
			current;

		document.querySelectorAll( '.bloghash-mobile-nav > button' ).forEach( ( item ) => {
			item.addEventListener(
				'click',
				function( e ) {
					e.preventDefault();

					if ( document.body.parentNode.classList.contains( 'is-mobile-menu-active' ) ) {
						close_menu();
					} else {
						show_menu();
					}
				},
				false
			);
		});

		// Helper functions.
		var show_menu = function( e ) {

			var bloghashnav = document.querySelectorAll( '.site-header' );
			if ( document.body.classList.contains( 'bloghash-is-mobile' ) ) {
				bloghashnav.forEach( function( bloghashnav ) {
					if ( ! bloghashnav ) {
						return false;
					}
					document.addEventListener( 'keydown', function( e ) {
						var firstEl, selectors, elements, lastEl, tabKey, shiftKey, closeIcon;
						selectors = '.bloghash-nav a, .bloghash-nav button';
						elements = bloghashnav.querySelectorAll( selectors );
						lastEl = elements[elements.length - 1];
						firstEl = elements[0];
						tabKey = 9 === e.keyCode;
						shiftKey = e.shiftKey;
						closeIcon = document.querySelector( '.bloghash-hamburger-bloghash-primary-nav' );
						if ( ! shiftKey && tabKey && lastEl === document.activeElement ) {
							e.preventDefault();
							closeIcon.focus();
						}
						if ( shiftKey && tabKey && firstEl === document.activeElement ) {
							e.preventDefault();
							closeIcon.focus();
						}

						// If there are no elements in the menu, don't move the focus
						if ( tabKey && firstEl === lastEl ) {
							e.preventDefault();
						}
					});
				});
			}

			// Add the active class.
			document.body.parentNode.classList.add( 'is-mobile-menu-active' );

			// Hook the ESC listener
			document.addEventListener( 'keyup', esc_close_menu );

			// Hook the click listener
			if ( null !== page ) {
				page.addEventListener( 'click', outside_close_menu );
			}

			// Hook the click listener for submenu toggle.
			document.querySelectorAll( '#bloghash-header .bloghash-nav' ).forEach( ( item ) => {
				item.addEventListener( 'click', submenu_toggle );
			});

			// Slide down the menu.
			bloghashSlideDown( nav, 350 );
		};

		var close_menu = function( e ) {

			// Remove the active class.
			document.body.parentNode.classList.remove( 'is-mobile-menu-active' );

			// Unhook the ESC listener
			document.removeEventListener( 'keyup', esc_close_menu );

			// Unhook the click listener
			if ( null !== page ) {
				page.removeEventListener( 'click', outside_close_menu );
			}

			// Close submenus
			document.querySelectorAll( '#bloghash-header .bloghash-nav > ul > .bloghash-open' ).forEach( ( item ) => {
				submenu_display_toggle( item );
			});

			nav.querySelectorAll( '.hovered' ).forEach( ( li ) => {
				li.classList.remove( 'hovered' );
			});

			if ( document.body.classList.contains( 'bloghash-is-mobile' ) ) {

				// Unhook the click listener for submenu toggle
				document.querySelectorAll( '#bloghash-header .bloghash-nav' ).forEach( ( item ) => {
					item.removeEventListener( 'click', submenu_toggle );
				});

				// Slide up the menu
				bloghashSlideUp( nav, 250 );
			} else {
				nav.style.display = null;
			}
		};

		var outside_close_menu = function( e ) {
			if ( null === e.target.closest( '.bloghash-hamburger' ) && null === e.target.closest( '.site-navigation' ) ) {
				close_menu();
			}
		};

		var esc_close_menu = function( e ) {
			if ( 27 == e.keyCode ) {
				close_menu();
			}
		};

		var submenu_toggle = function( e ) {
			if ( e.target.parentElement.querySelectorAll( '.sub-menu' ).length ) {
				e.preventDefault();
				if ( 'button' === e.target.type ) {
					submenu_display_toggle( e.target.parentElement );
				}
			}
		};

		// Show or hide the sub menu.
		var submenu_display_toggle = ( current ) => {
			if ( current.classList.contains( 'bloghash-open' ) ) {
				current.classList.remove( 'bloghash-open' );
				current.querySelectorAll( '.sub-menu' ).forEach( ( submenu ) => {

					//submenu.style.display = null;
					bloghashSlideUp( submenu, 350 );
				});

				// Close all submenus automatically.
				current.querySelectorAll( 'li' ).forEach( ( item ) => {
					item.classList.remove( 'bloghash-open' );
					item.querySelectorAll( '.sub-menu' ).forEach( ( submenu ) => {

						//submenu.style.display = null;
						bloghashSlideUp( submenu, 350 );
					});
				});
			} else {
				current.querySelectorAll( '.sub-menu' ).forEach( ( submenu ) => {

					// Target first level elements only.
					if ( current === submenu.parentElement ) {

						//submenu.style.display = 'block';
						bloghashSlideDown( submenu, 350 );
					}
				});

				current.classList.add( 'bloghash-open' );
			}
		};

		// Create custom event for closing mobile menu.
		document.addEventListener( 'bloghash-close-mobile-menu', close_menu );
	};

	/**
	 * Bloghash preloader.
	 *
	 * @since 1.0.0
	 */
	var bloghashPreloader = ( timeout = 0 ) => {
		var preloader = document.getElementById( 'bloghash-preloader' );

		if ( null === preloader ) {
			return;
		}

		var delay = 250;

		var hide_preloader = () => {
			if ( document.body.classList.contains( 'bloghash-loaded' ) ) {
				return;
			}

			// Start fade out animation.
			document.body.classList.add( 'bloghash-loading' );

			setTimeout( function() {

				// Fade out animation completed - set display none
				document.body.classList.replace( 'bloghash-loading', 'bloghash-loaded' );

				// Dispatch event when preloader is done
				bloghashTriggerEvent( document.body, 'bloghash-preloader-done' );
			}, delay );
		};

		// Set timeout or hide immediately
		if ( 0 < timeout ) {
			setTimeout( function() {
				hide_preloader();
			}, timeout );
		} else {
			hide_preloader();
		}

		return false;
	};

	/**
	 * Handles comments toggle functionality.
	 *
	 * @since 1.0.0
	 */
	var bloghashToggleComments = () => {
		if ( ! document.body.classList.contains( 'bloghash-has-comments-toggle' ) ) {
			return;
		}

		if ( null == document.getElementById( 'bloghash-comments-toggle' ) ) {
			return;
		}

		var toggleComments = ( e ) => {
			if ( 'undefined' !== typeof e ) {
				e.preventDefault();
			}

			if ( document.body.classList.contains( 'comments-visible' ) ) {
				document.body.classList.remove( 'comments-visible' );
				document.getElementById( 'bloghash-comments-toggle' ).querySelector( 'span' ).innerHTML = bloghash_vars.strings.comments_toggle_show;
			} else {
				document.body.classList.add( 'comments-visible' );
				document.getElementById( 'bloghash-comments-toggle' ).querySelector( 'span' ).innerHTML = bloghash_vars.strings.comments_toggle_hide;
			}
		};

		if ( null !== document.getElementById( 'bloghash-comments-toggle' ) && ( -1 !== location.href.indexOf( '#comment' ) || -1 !== location.href.indexOf( 'respond' ) ) ) {
			toggleComments();
		}

		document.getElementById( 'bloghash-comments-toggle' ).addEventListener( 'click', toggleComments );
	};

	/**
	 * Handles toggling and smooth scrolling when clicked on "Comments" link
	 *
	 * @since 1.0.0
	 */
	var bloghashCommentsClick = () => {
		var commentsLink = document.querySelector( '.single .comments-link' );

		if ( null === commentsLink ) {
			return;
		}

		commentsLink.addEventListener( 'click', function( e ) {

			// Show comments if hidden under a toggle
			if ( document.body.classList.contains( 'bloghash-has-comments-toggle' ) && ! document.body.classList.contains( 'comments-visible' ) ) {
				document.getElementById( 'bloghash-comments-toggle' ).click();
			}
		});
	};

	/**
	 * Removes inline styles on menus on resize.
	 *
	 * @since 1.0.0
	 */
	var bloghashCheckMobileMenu = () => {

		// Update body class if mobile breakpoint is reached.
		if ( window.innerWidth <= bloghash_vars['responsive-breakpoint']) {
			document.body.classList.add( 'bloghash-is-mobile' );
		} else {
			if ( document.body.classList.contains( 'bloghash-is-mobile' ) ) {
				document.body.classList.remove( 'bloghash-is-mobile' );
				bloghashTriggerEvent( document, 'bloghash-close-mobile-menu' );
			}
		}
	};

	/**
	 * Set Slider Init function
	 *
	 * @since 1.0.0
	 */
	var bloghashSliderInit = (sliderElement) => {
		if (!sliderElement) return; // Check if the slider element exists
		
		let swiperOptions = JSON.parse(sliderElement.dataset.swiperOptions);
		
		if (sliderElement.classList.contains('swiper-top')) {
			const bottomSwiperElement = document.querySelector('.swiper-bottom');
			if (bottomSwiperElement) { // Ensure the bottom swiper element exists
				const bottomSwiperOptions = JSON.parse(bottomSwiperElement.dataset.swiperOptions);
				const bottomSwiper = new Swiper(bottomSwiperElement, bottomSwiperOptions);
				swiperOptions.thumbs = {swiper: bottomSwiper};
			}
		}
		
		const mySwiper = new Swiper(sliderElement, swiperOptions);
	}

	/**
	 * Time and Date Update
	 *
	 * @since 1.0.0
	 */
	var bloghashUpdateTimeAndDate = () => {
		if (document.getElementById("bloghash-time")) {
			document.getElementById("bloghash-time").textContent = new Date().toLocaleTimeString();
		}
		if (document.getElementById("bloghash-date")) {
			const options = { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' };
			document.getElementById("bloghash-date").textContent = new Date().toLocaleString(document.documentElement.lang, options);
		}
	}

	/**
	 * Handle Quick Like
	 *
	 * @since 1.0.0
	 */
	var bloghashHandleQuickLike = (element) => {
		if ( ( element.classList.contains( 'liked' ) ) ) {
			element.classList.remove('liked', 'heartBeat');
			element.lastElementChild.textContent = parseInt(element.lastElementChild.textContent) - 1;
		} else {
			element.classList.add('liked', 'heartBeat');
			element.lastElementChild.textContent = parseInt(element.lastElementChild.textContent) + 1;
		}
	}

	/**
	 * Ticker News with vanilla.marquee
	 *
	 * @since 1.0.0
	 */
	var bloghashTicker = () => {
		const hdir = document.body.classList.contains("rtl") ? "right" : "left";
		const htc = document.querySelector(".bloghash-ticker.one-ticker");
		if (htc) {
			const htcM = new Marquee(htc.querySelector(".ticker-slider-wrap"), {
				speed: 50,
				duration: 14000,
				gap: 0,
				delayBeforeStart: 0,
				direction: hdir,
				duplicated: true,
				startVisible: true,
				pauseOnHover: true,
			});
			htc.addEventListener("click", (e) => {
				const pausePlayIcon = e.target.closest(".ticker-slider-pause")?.querySelector("i");
				if (pausePlayIcon) {
					pausePlayIcon.classList.toggle("fa-pause");
					pausePlayIcon.classList.toggle("fa-play");
					htcM.toggle();
				}
			});
		}
	}


	//--------------------------------------------------------------------//
	// Events
	//--------------------------------------------------------------------//

	// DOM ready
	document.addEventListener( 'DOMContentLoaded', function() {
		bloghashPreloader( 5000 );
		bloghashMenuAccessibility();
		bloghashKeyboardFocus();
		bloghashScrollTopButton();
		bloghashSmoothScroll();
		bloghashDropdownDelay();
		bloghashToggleComments();
		bloghashHeaderSearch();
		bloghashMobileMenu();
		bloghashCheckMobileMenu();
		bloghashSmartSubmenus();
		bloghashCommentsClick();
		bloghashCartDropdownDelay();
		bloghashStickyHeader();
		bloghashCalcScreenWidth();

		// Initialize all sliders, including those that are not thumbs
		document.querySelectorAll('.bloghash-swiper:not(.swiper-bottom)').forEach(bloghashSliderInit);
		// Then, initialize thumb (bottom) sliders to ensure they can be linked
		document.querySelectorAll('.bloghash-swiper.swiper-bottom').forEach(bloghashSliderInit);

		setInterval(bloghashUpdateTimeAndDate, 1000);
		bloghashUpdateTimeAndDate(); // Initial call to display immediately

		bloghashTicker();
	});

	// Window load
	window.addEventListener( 'load', function() {
		bloghashPreloader();
	});

	// Scroll
	window.addEventListener( 'scroll', function() {
		bloghashDebounce( bloghashScrollTopButton() );
	});

	// Resize
	window.addEventListener( 'resize', function() {
		bloghashDebounce( bloghashSmartSubmenus() );
		bloghashDebounce( bloghashCheckMobileMenu() );
		bloghashDebounce( bloghashCalcScreenWidth() );
	});

	// Bloghash ready
	bloghashTriggerEvent( document.body, 'bloghash-ready' );

	//--------------------------------------------------------------------//
	// Global
	//--------------------------------------------------------------------//

	window.bloghash = window.bloghash || {};

	// Make these function global.
	window.bloghash.preloader = bloghashPreloader;
	window.bloghash.stickyHeader = bloghashStickyHeader;

	window.App = {};
	App.config = {
		headroom: {
			enabled: true,
			options: {
				classes: {
					initial: 'headroom',
					pinned: 'is-pinned',
					unpinned: 'is-unpinned',
					top: 'is-top',
					notTop: 'is-not-top',
					bottom: 'is-bottom',
					notBottom: 'is-not-bottom',
					frozen: 'is-frozen'
				}
			}
		},
		ajax: {
			enabled: true
		},
		cursorFollower: {
			enabled: true,
			disableBreakpoint: '992'
		}
	};
	App.html = document.querySelector( 'html' );
	App.body = document.querySelector( 'body' );
	/****** // Cursor // ******/
	window.onload = () => {
		if (App.config.cursorFollower?.enabled) Cursor.init();
	};
	const Cursor = (() => {
		const cursor = document.querySelector('.bloghash-js-cursor');
		let follower, label, icon, clientX = -100,
			clientY = -100,
			cursorWidth, cursorHeight, cursorTriggers, state = false;
	
		const setVariables = () => {
			if (!cursor) return;
			[follower, label, icon] = ['.bloghash-js-follower', '.bloghash-js-label', '.bloghash-js-icon'].map(sel => cursor.querySelector(sel));
			[cursorWidth, cursorHeight] = [cursor.offsetWidth / 2, cursor.offsetHeight / 2];
		};
	
		const addEventListeners = () => {
			document.addEventListener('mousedown', () => cursor.classList.add('is-mouse-down'));
			document.addEventListener('mouseup', () => cursor.classList.remove('is-mouse-down'));
			document.addEventListener('mousemove', ({
				clientX: x,
				clientY: y
			}) => [clientX, clientY] = [x, y]);
		};
	
		const render = () => {
			cursor.style.transform = `translate(${clientX - cursorWidth}px, ${clientY - cursorHeight}px)`;
			requestAnimationFrame(render);
		};
	
		const enterHandler = ({
			target
		}) => {
			const updateCursor = (cls, sel, attr) => {
				if (target.getAttribute(attr)) {
					App.body.classList.add(cls);
					cursor.classList.add(sel);
					return target.getAttribute(attr);
				}
			};
	
			cursor.classList.add('is-active');
			label.innerHTML = updateCursor('is-cursor-active', 'has-label', 'data-cursor-label') || '';
			icon.innerHTML = updateCursor('is-cursor-active', 'has-icon', 'data-cursor-icon') || '';
		};
	
		const leaveHandler = () => {
			['is-cursor-active', 'is-active', 'has-label', 'has-icon'].forEach(cls => cursor.classList.remove(cls));
			[label.innerHTML, icon.innerHTML] = ['', ''];
		};
	
		const updateCursorTriggers = () => {
			cursorTriggers?.forEach(el => el.removeEventListener('mouseenter', enterHandler));
			cursorTriggers = document.querySelectorAll('button, a, input, [data-cursor], [data-cursor-label], [data-cursor-icon], textarea');
			cursorTriggers.forEach(el => {
				el.addEventListener('mouseenter', enterHandler);
				el.addEventListener('mouseleave', leaveHandler);
			});
		};
	
		const breakpointCheck = () => {
			const updateState = () => {
				let width = window.innerWidth || screen.width;
				state = width >= App.config.cursorFollower?.disableBreakpoint;
				cursor.classList.toggle('is-enabled', state);
				state ? updateCursorTriggers() : cursorTriggers.forEach(el => el.removeEventListener('mouseenter', enterHandler));
			};
	
			updateState();
			window.addEventListener('resize', updateState);
		};
	
		return {
			init: () => {
				if (!cursor) return;
				setVariables();
				state = true;
				cursor.classList.add('is-enabled');
				addEventListeners();
				requestAnimationFrame(render);
				updateCursorTriggers();
				breakpointCheck();
			},
			update: updateCursorTriggers,
			clear: () => cursorTriggers?.forEach(el => el.removeEventListener('mouseenter', enterHandler)),
			hide: () => cursor?.classList.add('is-hidden'),
			show: () => cursor?.classList.remove('is-hidden')
		};
	})();
	
	/****** // Start --> Dark / Light Mode Setup // ******/
	document.addEventListener('DOMContentLoaded', function() {
		const toggleButton = document.querySelector('.bloghash-darkmode');
		if (toggleButton) {
			// Initialize dark mode based on customizer setting or local storage
			let darkModeEnabled = bloghash_vars.dark_mode || localStorage.getItem('darkmode') === 'dark';
			updateDarkMode(darkModeEnabled);
		
			// Toggle button click handler
			toggleButton.addEventListener('click', function() {
				darkModeEnabled = !darkModeEnabled; // Toggle dark mode state
				updateDarkMode(darkModeEnabled); // Update UI and store preference
			});
		
			function updateDarkMode(enabled) {
				if (enabled) {
					document.documentElement.setAttribute('data-darkmode', 'dark');
					localStorage.setItem('darkmode', 'dark');
					toggleButton.classList.add('active');
				} else {
					document.documentElement.setAttribute('data-darkmode', 'light');
					localStorage.setItem('darkmode', 'light');
					toggleButton.classList.remove('active');
				}
			}
		}
	});

}() );
