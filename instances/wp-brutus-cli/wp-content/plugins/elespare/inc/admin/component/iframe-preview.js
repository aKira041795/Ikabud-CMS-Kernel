/* global elementor, elementorCommon */
/* eslint-disable */
"undefined" != typeof jQuery && function ($) {
  $(function () {

    $('body').on('click', '.elespare-open-iframe', function () {
      var _this = $(this);


      var previeUrl = _this.attr('data-src');
      var parentNode = _this.parents('.ele-templates-demo-lists')

      var name = _this.attr('data-name');

      parentNode.append(`
            <div class="elespare-demo-iframe desktop">
              <iframe src="${previeUrl}"></iframe>
              <div class="elespare-iframe-footer-wrapper">
              
                <div class="theme-details">
                  <a href="">
                    <img src="${ELELibrary.logo}" alt=""?>
                  </a>
                  <a class="elespare-theme-title" href="https://elespare.com" target="_blank">${name}</a>
                </div>
                <div class="responsive-view">
                  <span class="active desktop"><i class="dashicons dashicons-desktop"></i></span>
                  <span class="tablet"><i class="dashicons dashicons-tablet"></i></span>
                  <span class="mobile"><i class="dashicons dashicons-smartphone"></i></span>
                </div>
                <div class="elespare-upgrade">
                <a href="https://elespare.com/pricing/" target="_blank" class="ele-upgrade">Upgrade</a>
                  <a class="elespare-close-iframe"><i class="dashicons dashicons-no-alt"></i></a>
                  </div>
              </div>
            </div>
          `);

      parentNode.find('.elespare-demo-iframe').addClass('desktop')

    })
    $('body').on('click', '.responsive-view span', function () {
      $(this).parent('.responsive-view').find('span').removeClass('active');
      var clickedElement = $(this).attr('class')
      $(this).addClass('active')
      $(this).parents('.elespare-demo-iframe').removeClass('desktop tablet mobile').addClass(clickedElement);



    })
    $('body').on('click', '.elespare-close-iframe', function (e) {
      e.preventDefault()
      var _this = $(this);

      var parentNode = _this.parents('.ele-templates-demo-lists')
      parentNode.find('.elespare-demo-iframe').remove()


    })
  })

}(jQuery);