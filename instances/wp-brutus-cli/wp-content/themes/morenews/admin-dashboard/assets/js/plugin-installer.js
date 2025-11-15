var n = window.AFTHRAMPES_JS || {};

jQuery(document).ready(function ($) {
  'use strict';

  var is_loading = false;

  function handleAjaxError(aft) {
    aft.removeClass('installing');
    is_loading = false;
  }

  n.install_plugin = function (aft, plugin) {
    is_loading = true;
    aft.addClass('installing');

    $.ajax({
      type: 'POST',
      url: aft_installer_localize.ajax_url,
      data: {
        action: 'morenews_plugin_installer',
        plugin: plugin,
        nonce: aft_installer_localize.admin_nonce,
        dataType: 'json',
      },
      success: function (data) {
        if (data && data.status === 'success') {
          aft
            .attr('class', 'activate button button-primary')
            .html(aft_installer_localize.activate_btn);
        } else {
          aft.removeClass('installing');
        }
        is_loading = false;
      },
      error: function () {
        handleAjaxError(aft);
      },
    });
  };

  n.activate_plugin = function (aft, plugin) {
    aft.addClass('installing');

    $.ajax({
      type: 'POST',
      url: aft_installer_localize.ajax_url,
      data: {
        action: 'morenews_plugin_activation',
        plugin: plugin,
        nonce: aft_installer_localize.admin_nonce,
        dataType: 'json',
      },
      success: function (data) {
        if (data && data.status === 'success') {
          aft
            .attr('class', 'installed button disabled')
            .html(aft_installer_localize.installed_btn);

          if (data.plugin === 'templatespare') {
            aft
              .attr('href', data.redirectUrl)
              .removeClass('disabled installed')
              .attr('class', 'button-primary templatespare primary')
              .html('Get Starter Sites');
          } else {
            aft.removeClass('installing');
          }
        }
        is_loading = false;
      },
      error: function () {
        handleAjaxError(aft);
      },
    });
  };

  $(document).on('click', '.aft-plugin-installer a.button', function (e) {
    e.preventDefault();

    var aft = $(this),
      plugin = aft.data('slug');

    if (!aft.hasClass('disabled') && !is_loading) {
      if (aft.hasClass('install')) {
        n.install_plugin(aft, plugin);
      } else if (aft.hasClass('activate')) {
        n.activate_plugin(aft, plugin);
      }
    }
  });

  $('.aft-dismiss-notice').on('click', function () {
    $.ajax({
      type: 'POST',
      url: aft_installer_localize.ajax_url,
      data: {
        action: 'aft_notice_dismiss',
        nonce: aft_installer_localize.admin_nonce,
      },
      success: function (data) {
        if (data.status === 'success') {
          $('.aft-notice-content-wrapper').remove();
        }
      },
    });
  });

  $(document).on(
    'click',
    '.aft-bulk-active-plugin-installer a.button',
    function (e) {
      e.preventDefault();
      e.stopPropagation(); // Prevent the event from bubbling up

      var aft = $(this),
        install = aft.data('install'),
        activate = aft.data('activate'),
        page = aft.data('page');

      aft.addClass('installing');

      $.ajax({
        type: 'POST',
        url: ajaxurl,
        data: {
          action: 'morenews_plugin_installer_activation',
          install: install,
          activate: activate,
          page: page,
          nonce: aft_installer_localize.admin_nonce,
          dataType: 'json',
        },
        success: function (response) {
          if (response.status === 'success') {
            window.location.href = response.url;
          }
        },
      });
    }
  );
});
