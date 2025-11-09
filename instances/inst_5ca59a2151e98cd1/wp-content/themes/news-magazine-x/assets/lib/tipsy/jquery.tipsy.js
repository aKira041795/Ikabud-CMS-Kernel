/*!
 * jQuery.tipsy
 * Copyright (c) 2014 CreativeDream
 * Website: http://creativedream.net/plugins/
 * Version: 1.0 (18-11-2014)
 * Requires: jQuery v1.7.1 or later
 */
(function($) {
    function applyCallback(callback, context) {
        return typeof callback === 'function' ? callback.call(context) : callback;
    }

    function Tipsy(element, options) {
        this.$element = $(element);
        this.options = options;
        this.enabled = true;
    }

    Tipsy.prototype = {
        show: function() {
            var title = this.getTitle();
            if (title && this.enabled) {
                var tooltip = this.tip();
                tooltip.find('.tipsy-inner')[this.options.html ? 'html' : 'text'](title);
                tooltip[0].className = 'tipsy'; // Reset the class in case of dynamic updates
                tooltip.remove().css({ top: 0, left: 0, visibility: 'hidden', display: 'block' }).prependTo(document.body);

                var pos = $.extend({}, this.$element.offset(), {
                    width: this.$element[0].offsetWidth,
                    height: this.$element[0].offsetHeight
                });

                var actualWidth = tooltip[0].offsetWidth;
                var actualHeight = tooltip[0].offsetHeight;
                var gravity = this.getGravity();

                var tp;
                switch (gravity.charAt(0)) {
                    case 'n':
                        tp = { top: pos.top + pos.height + this.options.offset, left: pos.left + pos.width / 2 - actualWidth / 2 };
                        break;
                    case 's':
                        tp = { top: pos.top - actualHeight - this.options.offset, left: pos.left + pos.width / 2 - actualWidth / 2 };
                        break;
                    case 'e':
                        tp = { top: pos.top + pos.height / 2 - actualHeight / 2, left: pos.left - actualWidth - this.options.offset };
                        break;
                    case 'w':
                        tp = { top: pos.top + pos.height / 2 - actualHeight / 2, left: pos.left + pos.width + this.options.offset };
                        break;
                }

                if (gravity.length == 2) {
                    tp.left = gravity.charAt(1) == 'w' ? pos.left + pos.width / 2 - 15 : pos.left + pos.width / 2 - actualWidth + 15;
                }

                tooltip.css(tp).addClass('tipsy-' + gravity);
                tooltip.find('.tipsy-arrow')[0].className = 'tipsy-arrow tipsy-arrow-' + gravity.charAt(0);

                if (this.options.className) {
                    tooltip.addClass(applyCallback(this.options.className, this.$element[0]));
                }

                if (this.options.fade) {
                    tooltip.stop().css({ opacity: 0, display: 'block', visibility: 'visible' }).animate({ opacity: this.options.opacity });
                } else {
                    tooltip.css({ visibility: 'visible', opacity: this.options.opacity });
                }
            }
        },

        hide: function() {
            if (this.options.fade) {
                this.tip().stop().fadeOut(function() { $(this).remove(); });
            } else {
                this.tip().remove();
            }
        },

        fixTitle: function() {
            var $element = this.$element;
            if ($element.attr('title') || typeof($element.attr('original-title')) !== 'string') {
                $element.attr('original-title', $element.attr('title') || '').removeAttr('title');
            }
        },

        getTitle: function() {
            var title, $element = this.$element, options = this.options;
            title = typeof options.title === 'string' ? $element.attr(options.title === 'title' ? 'original-title' : options.title) : options.title.call($element[0]);
            title = (title + '').replace(/(^\s*|\s*$)/, "");
            return title || options.fallback;
        },

        getGravity: function() {
            var gravity = this.$element.data('gravity') || applyCallback(this.options.gravity, this.$element[0]);
            return gravity;
        },

        tip: function() {
            if (!this.$tip) {
                this.$tip = $('<div class="tipsy"></div>').html('<div class="tipsy-arrow"></div><div class="tipsy-inner"></div>');
                this.$tip.data('tipsy-pointee', this.$element[0]);
            }
            return this.$tip;
        },

        validate: function() {
            if (!this.$element[0].parentNode) {
                this.hide();
                this.$element = null;
                this.options = null;
            }
        },

        enable: function() { this.enabled = true; },
        disable: function() { this.enabled = false; },
        toggleEnabled: function() { this.enabled = !this.enabled; }
    };

    $.fn.newsxTipsy = function(options) {
        if (options === true) {
            return this.data('tipsy');
        }
        if (typeof options === 'string') {
            var tipsy = this.data('tipsy');
            if (tipsy) tipsy[options]();
            return this;
        }

        options = $.extend({}, $.fn.newsxTipsy.defaults, options);

        function getTipsy(element) {
            var tipsy = $.data(element, 'tipsy');
            if (!tipsy) {
                tipsy = new Tipsy(element, $.fn.newsxTipsy.elementOptions(element, options));
                $.data(element, 'tipsy', tipsy);
            }
            return tipsy;
        }

        if (options.live || this.each(function() { getTipsy(this); })) {
            var eventIn = options.trigger === 'hover' ? 'mouseenter' : 'focus';
            var eventOut = options.trigger === 'hover' ? 'mouseleave' : 'blur';

            this.on(eventIn, function() {
                var tipsy = getTipsy(this);
                tipsy.hoverState = 'in';
                if (options.delayIn === 0) {
                    tipsy.show();
                } else {
                    setTimeout(function() { if (tipsy.hoverState === 'in') tipsy.show(); }, options.delayIn);
                }
            }).on(eventOut, function() {
                var tipsy = getTipsy(this);
                tipsy.hoverState = 'out';
                if (options.delayOut === 0) {
                    tipsy.hide();
                } else {
                    setTimeout(function() { if (tipsy.hoverState === 'out') tipsy.hide(); }, options.delayOut);
                }
            });
        }

        return this;
    };

    $.fn.newsxTipsy.defaults = {
        className: null,
        delayIn: 0,
        delayOut: 0,
        fade: false,
        fallback: '',
        gravity: 'n',
        html: false,
        live: false,
        offset: 0,
        opacity: 0.8,
        title: 'title',
        trigger: 'hover'
    };

    // Auto-revalidate tipsies in DOM
    $.fn.newsxTipsy.revalidate = function() {
        $('.tipsy').each(function() {
            var pointee = $.data(this, 'tipsy-pointee');
            if (!pointee || !isElementInDOM(pointee)) {
                $(this).remove();
            }
        });
    };

    function isElementInDOM(element) {
        while (element = element.parentNode) {
            if (element == document) {
                return true;
            }
        }
        return false;
    }

    // Helper functions for determining the gravity
    $.fn.newsxTipsy.elementOptions = function(element, options) {
        return $.metadata ? $.extend({}, options, $(element).metadata()) : options;
    };

    $.fn.newsxTipsy.autoNS = function() {
        return $(this).offset().top > ($(document).scrollTop() + $(window).height() / 2) ? 's' : 'n';
    };

    $.fn.newsxTipsy.autoWE = function() {
        return $(this).offset().left > ($(document).scrollLeft() + $(window).width() / 2) ? 'e' : 'w';
    };

    $.fn.newsxTipsy.autoBounds = function(margin, prefer) {
        return function() {
            var dir = { ns: prefer[0], ew: (prefer.length > 1 ? prefer[1] : false) },
                scroll = { top: $(document).scrollTop() + margin, left: $(document).scrollLeft() + margin },
                bounds = { w: $(window).width() + scroll.left, h: $(window).height() + scroll.top },
                $this = $(this);

            if ($this.offset().top < scroll.top) { dir.ns = 'n'; }
            if ($this.offset().left < scroll.left) { dir.ew = 'w'; }
            if (bounds.w < $this.offset().left + $this.width()) { dir.ew = 'e'; }
            if (bounds.h < $this.offset().top + $this.height()) { dir.ns = 's'; }

            return dir.ns + (dir.ew ? dir.ew : '');
        };
    };
})(jQuery);
