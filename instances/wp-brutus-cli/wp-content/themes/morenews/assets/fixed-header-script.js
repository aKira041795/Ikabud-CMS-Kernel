jQuery(document).ready(function ($) {
    var header = document.getElementById("main-navigation-bar");
    var masthead = document.getElementById("masthead");

    if (!header || !masthead) return;

    var stickyHeight = header.offsetHeight;
    var sticky = header.offsetTop + stickyHeight;
    var isStickyActive = false;

    function applyStickyHeader() {
        if (window.pageYOffset > sticky) {
            if (!isStickyActive) {
                header.classList.add("aft-sticky-navigation");
                masthead.style.paddingBottom = stickyHeight + "px";
                isStickyActive = true;
            }
        } else {
            if (isStickyActive) {
                header.classList.remove("aft-sticky-navigation");
                masthead.style.paddingBottom = "0px";
                isStickyActive = false;
            }
        }
    }

    $(window).on('scroll', applyStickyHeader);
    applyStickyHeader(); // Run on load
});
