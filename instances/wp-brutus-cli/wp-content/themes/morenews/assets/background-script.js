(function ($) {
    "use strict";
    var AFTHRAMPES_JS = window.AFTHRAMPES_JS || {};

    // Function to preload and apply background images
    AFTHRAMPES_JS.DataBackground = function () {
        $(".data-bg").each(function () {
            var $this = $(this);
            var background = $this.data("background");

            if (background) {
                // Preload the background image first
                var img = new Image();
                img.src = background;
                
                // When the image is loaded, apply it as the background
                img.onload = function () {
                    $this.css("background-image", "url(" + background + ")");
                    $this.addClass("data-bg-loaded");
                };
            }
        });
    };

    // Preloader function to hide preloader after page load
    AFTHRAMPES_JS.Preloader = function () {
        $(window).on('load', function () {
            $('#af-preloader').fadeOut('slow', function () {
                $('#loader-wrapper').fadeOut();
            });
        });
    };

    // Document ready
    $(document).ready(function () {
        AFTHRAMPES_JS.DataBackground();
        AFTHRAMPES_JS.Preloader();
    });

})(jQuery);
