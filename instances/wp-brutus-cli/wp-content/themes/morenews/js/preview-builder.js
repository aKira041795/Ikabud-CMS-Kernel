(function ($) {
  let activeBuilder = null;
  wp.customize.bind('preview-ready', function () {
    console.log('Customizer preview is ready');

    wp.customize.preview.bind('hfb_active_builder', function (builderType) {
      console.log('Active builder from controls:', builderType);
      activeBuilder = builderType; // Update global activeBuilder
    });

    // You can also do other preview binds here...
  });
  //hfb_{$context}_navigation_menu_id

  wp.customize('hfb_header_html_custom_html', function (value) {
    value.bind(function (newVal) {
      $('.athfb-element-header_html').html(newVal);
    });
  });

  wp.customize('hfb_header_social_icons_facebook', function (value) {
    value.bind(function (newVal) {
      $('.athfb-social-icons a.athfb-facebook').attr('href', newVal);
    });
  });

  wp.customize('hfb_header_social_icons_twitter', function (value) {
    value.bind(function (newVal) {
      $('.athfb-social-icons a.athfb-twitter').attr('href', newVal);
    });
  });
  wp.customize('hfb_button_text', function (value) {
    value.bind(function (newVal) {
      $('.athfb-element-button .athfb-button').html(newVal);
    });
  });

  // Button URL Live Preview
  wp.customize('hfb_button_url', function (value) {
    value.bind(function (newVal) {
      $('.athfb-element-button .athfb-button').attr('href', newVal);
    });
  });
})(jQuery);
