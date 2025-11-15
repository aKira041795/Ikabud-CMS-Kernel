jQuery(document).ready(function ($) {
  'use strict';
  var AftTemplatesKit = {
    requiredTheme: 'false',
    requiredPlugins: 'false',
    init: function () {
      //Buynow
      $(document).on('click', '.templatespare-purchase-btn', function (e) {
        //e.preventDefault();
        var slug = $(this).attr('data-theme-slug');
        var plaType = 'single';
        var image = $(this).attr('data-image');
        var name = $(this).attr('data-name');
        // AftTemplatesKit.purchase(slug, plaType, image, name)

        var demoDetails = 'https://afthemes.com/products/' + slug;
        var newtab = window.open(demoDetails, '_blank');
        newtab.focus();
      });

      $('body').on('click', '.template-spare-modal', function (e) {
        var _this = $(this);
        var themereq = $(this).attr('data-theme');
        AftTemplatesKit.verifyTheme(themereq, _this);
      });
      $('body').on('click', '.template-spare-close', function () {
        $('.ReactModalPortal')
          .find('.templatespare-popup-inner')
          .find('a')
          .removeAttr('data-theme-status');
      });

      $('body').on('click', '.templatespare-open-iframe', function () {
        var _this = $(this);
        var freePro = _this.attr('data-pro');
        var previeUrl = _this.attr('data-src');
        var parentNode = _this.parents('.templatespare-main-demo');
        var themeSlug = _this.attr('data-theme-slug');
        var image = _this.attr('data-image');
        var name = _this.attr('data-name');

        var buttonText = 'Details';
        if (freePro === 'pro') {
          buttonText = 'Purchase';
        }

        var demoDetails = 'https://afthemes.com/products/' + themeSlug;

        parentNode.append(
          "<div class='templatespare-demo-iframe'><iframe src=" +
            previeUrl +
            " ></iframe ><div class='templatespare-iframe-footer-wrapper'> <a href='' class='templatespare-close-iframe'><i class='dashicons dashicons-no-alt'></i></a><div class='theme-details'><a class='templatespare-logo-link' href='https://afthemes.com/all-themes-plan/' target='_blank'><img src='" +
            afobDash.aflogo +
            "'/></a><a class='templatespare-theme-title' href=" +
            demoDetails +
            " target='_blank'>" +
            name +
            "</a></div> <div class='responsive-view'><span class='active desktop'><i class='dashicons dashicons-desktop'></i></span><span class='tablet'><i class='dashicons dashicons-tablet'></i></span><span class='mobile'><i class='dashicons dashicons-smartphone'></i></span></div><div class='templatespare-plans'><button class='templatespare-single-plan single-plan' plan-type='single' data-slug=" +
            themeSlug +
            ' data-image=' +
            image +
            ' data-name=' +
            name +
            ' >' +
            buttonText +
            "  </button> <button class='templatespare-single-plan all-plan' plan-type='all' data-slug=" +
            themeSlug +
            ' data-image=' +
            image +
            ' data-name=' +
            name +
            ' > All Themes Plan</button ></div></div ></div > '
        );

        parentNode.find('.templatespare-demo-iframe').addClass('desktop');
      });
      $('body').on('click', '.responsive-view span', function () {
        $(this).parent('.responsive-view').find('span').removeClass('active');
        var clickedElement = $(this).attr('class');
        $(this).addClass('active');
        $(this)
          .parents('.templatespare-demo-iframe')
          .removeClass('desktop tablet mobile')
          .addClass(clickedElement);
      });
      $('body').on('click', '.templatespare-close-iframe', function (e) {
        e.preventDefault();
        var _this = $(this);

        var parentNode = _this.parents('.templatespare-main-demo');
        parentNode.find('.templatespare-demo-iframe').remove();
      });

      $('body').on('click', '.templatespare-kit-single', function (e) {
        e.preventDefault();
        var parentNode = $(this)
          .parents('.ReactModal__Content')
          .find('.templatespare-popup-inner')
          .find('.templatespare-import-kit-popup-wrap');
        parentNode.fadeIn();
        $(this).fadeOut();
        parentNode.find('.progress-bar').fadeIn();
        $(this)
          .parents('.ReactModal__Content')
          .find('.templatespare-popup-inner')
          .find('.templatespare-popup-footer')
          .fadeOut();
        $(this)
          .parents('.ReactModal__Content')
          .find('.templatespare-popup-inner')
          .find('.templatespare-popup-header')
          .find('.template-spare-close')
          .fadeOut();
        $(this)
          .parents('.ReactModal__Content')
          .find('.templatespare-popup-inner')
          .find('.templatespare-import-kit-popup')
          .find('strong')
          .addClass('templatespare-process-msg');
        AftTemplatesKit.importTemplatesKit($(this).attr('data-kit-id'));
      });

      $('body').on('click', '.templatespare-single-plan', function (e) {
        //e.preventDefault();

        var slug = $(this).attr('data-slug');
        var plaType = $(this).attr('plan-type');
        var image = $(this).attr('data-image');
        var name = $(this).attr('data-name');
        var demoDetails = '';
        if (plaType === 'all') {
          demoDetails = 'https://afthemes.com/all-themes-plan/';
        } else {
          demoDetails = 'https://afthemes.com/products/' + slug;
        }

        var newtab = window.open(demoDetails, '_blank');
        newtab.focus();
        // AftTemplatesKit.purchase(slug, plaType, image, name)
      });

      $('.templatespare-dismiss-notice').on('click', function () {
        $.ajax({
          type: 'POST',
          url: ajaxurl,
          data: {
            action: 'templatespare_notice_dismiss',
            security: afobDash.ajax_nonce,
          },
          success: function (data) {
            if (data.status == 'success') {
              $('.templatespare-notice-content-wrapper').remove();
            }
          },
        });
      });
    },

    purchase: function (slug, plaType, image, name) {
      $.ajax({
        type: 'POST',
        url: ajaxurl,
        data: {
          action: 'templatespare_get_plan_details',
          slug: slug,
          plaType: plaType,
        },
        success: function (response) {
          if (response.success === true) {
            var handler = FS.Checkout.configure({
              plugin_id: response.data.productid,
              plan_id: response.data.planid,
              public_key: response.data.publickey,
              image: image,
            });
            var license_value = 1;
            handler.open({
              name: name,
              licenses: license_value,
              // You can consume the response for after purchase logic.
              purchaseCompleted: function (response) {
                // The logic here will be executed immediately after the purchase confirmation.                                // alert(response.user.email);
              },
              success: function (response) {
                // The logic here will be executed after the customer closes the checkout, after a successful purchase.                                // alert(response.user.email);
              },
            });
          }
        },
      });
    },
    verifyTheme: function (themereq, _this) {
      $.ajax({
        type: 'POST',
        url: ajaxurl,
        data: {
          action: 'templatespare_get_theme_status',
          security: afobDash.ajax_nonce,
          re_theme: themereq,
        },

        success: function (response) {
          $('.ReactModalPortal')
            .find('.templatespare-popup-inner')
            .find('a')
            .attr('data-theme-status', response.data.status);
        },
        error: function (xhr, ajaxOptions, thrownerror) {
          // console.log(xhr)
        },
      });
    },
    importTemplatesKit: function (kitID) {
      AftTemplatesKit.importProgressBar('Loading');

      AftTemplatesKit.installRequiredPlugins(kitID, function (response) {
        if (response === 'success') {
          setTimeout(() => {
            AftTemplatesKit.importProgressBar('importing-2');
            $('.ReactModal__Content')
              .find('.templatespare-popup-inner')
              .removeClass('templatespare-import-success');
            installPlugins(kitID);
          }, 2000);
        }
      });

      function installPlugins(kitID) {
        var kit = $('.templatespare-kit-single[data-kit-id="' + kitID + '"]');
        var selectedTheme = kit.data('theme-folder');
        var childTheme = kit.data('verify-child');

        var allcontent = kit.data('checked');
        console.log(allcontent);
        $.ajax({
          type: 'POST',
          url: ajaxurl,
          data: {
            action: 'AFTMLS_import_demo_data',
            templatespare_templates_kit: kitID,
            security: afobDash.ajax_nonce,
            selectedTheme: selectedTheme,
            isChild: childTheme,
            allcontent: allcontent,
          },

          success: function (response) {
            if (
              'undefined' !== typeof response.status &&
              'newAJAX' === response.status
            ) {
              AftTemplatesKit.importProgressBar(
                'importing-' + response.ajaxCall
              );
              installPlugins(kitID);
            } else if ('undefined' !== typeof response.message) {
              AftTemplatesKit.importProgressBar('finish');
              kit
                .parents('.ReactModal__Content')
                .find('.templatespare-popup-inner')
                .find('.templatespare-popup-footer')
                .fadeIn();
              kit
                .parents('.ReactModal__Content')
                .find('.templatespare-popup-inner')
                .find('.templatespare-import-kit-popup')
                .find('strong')
                .removeClass('templatespare-process-msg');
            }
          },
          error: function (xhr, ajaxOptions, thrownerror) {
            // console.log(xhr)
          },
        });
      }
    },
    pageSettings: function (selectedTheme, kitID) {
      var kit = $('.templatespare-kit-single[data-kit-id="' + kitID + '"]');
      var selectedTheme = kit.data('theme');
      $.ajax({
        type: 'POST',
        url: ajaxurl,
        data: {
          action: 'templatespare_elementor_final_setup',
          kitID: kitID,
          selectedTheme: selectedTheme,
        },
        success: function (response) {
          if (response.success === true) {
            // console.log(response)
          }
        },
      });
    },
    installRequiredTheme: function (kitID) {
      AftTemplatesKit.importProgressBar('theme');
      var kit = $('.templatespare-kit-single[data-kit-id="' + kitID + '"]');
      var themeStatus = kit.data('theme-status');
      var selectedTheme = kit.data('theme');

      if ('req-theme-active' == themeStatus) {
        AftTemplatesKit.requiredTheme = 'true';
        return;
      } else if ('req-theme-inactive' == themeStatus) {
        $.post(ajaxurl, {
          action: 'templatespare_activate_required_theme',
          theme: selectedTheme,
          security: afobDash.ajax_nonce,
        });

        AftTemplatesKit.requiredTheme = 'true';
        return;
      }
      wp.updates.installTheme({
        slug: selectedTheme,
        success: function () {
          $.post(ajaxurl, {
            action: 'templatespare_activate_required_theme',
            theme: selectedTheme,
            security: afobDash.ajax_nonce,
          });

          AftTemplatesKit.requiredTheme = 'true';
        },
      });
    },
    installRequiredPlugins: function (kid, callback) {
      AftTemplatesKit.installRequiredTheme(kid);
      AftTemplatesKit.importProgressBar('plugins');
      var kit = $('.templatespare-kit-single[data-kit-id="' + kid + '"]');
      var plugisList = kit.data('builder');

      if (plugisList !== 'no') {
        var builder = kit.data('builder');

        var plugins = [];

        plugins.push(...builder.split(','));

        AftTemplatesKit.installRequiredPluginsViaAjax(
          plugins,
          function (response) {
            // Use the response here
            callback(response);
          }
        );
      } else {
        callback('success');
      }
    },

    installRequiredPluginsViaAjax: function (slug, callback) {
      if (slug) {
        $.ajax({
          type: 'POST',
          url: ajaxurl,
          data: {
            action: 'templatespare_install_require_plugins',
            plugins: slug,
            security: afobDash.ajax_nonce,
          },
          success: function (res) {
            callback(res);
          },
        });
      }
    },

    importProgressBar: function (step) {
      if ('plugins' === step) {
        $('.templatespare-import-kit-popup .progress-wrap strong').html(
          ' <span class="dot-flashing"></span> <span>Installing/Activating Theme and Plugins</span>'
        );
      } else if ('importing-2' === step) {
        $('.templatespare-import-kit-popup .progress-bar').animate(
          { width: '75%' },
          500
        );
        $('.templatespare-import-kit-popup .progress-wrap strong').html(
          ' <span class="dot-flashing"></span> <span>Setting up your theme... Hang tight!</span>'
        );
      } else if ('importing-3' === step) {
        $('.templatespare-import-kit-popup .progress-bar').animate(
          { width: '85%' },
          500
        );
        $('.templatespare-import-kit-popup .progress-wrap strong').html(
          '<span class="dot-flashing"></span> <span>Importing Widgets</span>'
        );
      } else if ('importing-4' === step) {
        $('.templatespare-import-kit-popup .progress-bar').animate(
          { width: '90%' },
          500
        );
        $('.templatespare-import-kit-popup .progress-wrap strong').html(
          ' <span class="dot-flashing"></span> <span>Importing Frontpage Settings</span>'
        );
      } else if ('importing-5' === step) {
        $('.templatespare-import-kit-popup .progress-bar').animate(
          { width: '99%' },
          500
        );
        $('.templatespare-import-kit-popup .progress-wrap strong').html(
          '<span class="dot-flashing"></span> <span>Importing Customizer Settings</span>'
        );
      } else if ('finish' === step) {
        var href = window.location.href,
          index = href.indexOf('/wp-admin'),
          homeUrl = href.substring(0, index);

        $('.templatespare-import-kit-popup .progress-bar').animate(
          { width: '100%' },
          500
        );
        $('.templatespare-import-kit-popup .content').children('p').remove();
        $('.templatespare-import-kit-popup .progress-wrap strong').html(
          'All done! Check out your new site.<a href="' +
            homeUrl +
            '" target="_blank">Visit Site</a>'
        );
        $('.templatespare-import-kit-popup header h3').text(
          'Import was Successfull!'
        );
        // $('.templatespare-import-kit-popup-wrap .templatespare-popup-footer').show();
        $(
          '.templatespare-popup-inner .templatespare-popup-header .template-spare-close'
        ).show();
        $('.templatespare-popup-inner').addClass(
          'templatespare-import-success'
        );
      }
    },
  };

  AftTemplatesKit.init();
});
