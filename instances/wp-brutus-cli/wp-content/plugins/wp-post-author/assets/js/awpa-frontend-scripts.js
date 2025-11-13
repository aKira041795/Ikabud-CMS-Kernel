(function (e) {
    "use strict";
    var n = window.AFTHRAMPES_JS || {};


    n.DataBackground = function () {
        var pageSection = e(".awpa-data-bg");
        pageSection.each(function (indx) {
            if (e(this).attr("data-background")) {
                e(this).css("background-image", "url(" + e(this).data("background") + ")");
            }
        });

        e('.awpa-bg-image').each(function () {
            var src = e(this).children('img').attr('src');
            e(this).css('background-image', 'url(' + src + ')').children('img').hide();
        });
    },
        n.authorTabs = function () {

            e(".awpa-tab").each(function () {

                e(this).on('click', function () {

                    var content = e(this).parents('.wp-post-author-shortcode')
                    content.find('.awpa-tab-content').removeClass('active')

                    content.find('.active').removeClass('active')
                    const targetTab = e(this).attr("data-tab");
                    e("#" + targetTab).addClass("active");
                    e(this).addClass('active');

                })

            })

        }


    e(document).ready(function () {
        n.DataBackground();
        n.authorTabs();
        e('#comments .comments-title').filter(function () {

            var originaltext = e(this).text().split(' ');
            var Container = e('#primary').parents('body').find('h1.entry-title');
            var filterhtml = Container.replaceAll('h1', '')
            var newtitle = filterhtml[0].innerText.replaceAll('★', '')
            e(this).text(originaltext[0] + " " + originaltext[1] + " " + originaltext[2] + ' "' + newtitle + '"');


        })
    })


})(jQuery);