<!doctype html>
<html>

<head>
  <!-- Defining responsive ambient. -->
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?php esc_html_e('Welcome to Your WordPress Journey 🎉', 'templatespare'); ?></title>
  <style>
    .notice {
      display: none !important;
    }
  </style>

  <script type="text/javascript">
    var ajaxurl = '<?php echo esc_js(admin_url('admin-ajax.php', 'relative')); ?>'
  </script>

</head>

<body class="bg-white">
  <?php

  $data = new AFTMLS_Templates_Importer();

  wp_enqueue_style(
    'templatespare-central-style',
    AFTMLS_PLUGIN_URL . 'assets/css/wizard.css',
    array(),
    '1.0',
    'all'
  );

  // Get the last modified time of the file.
  $aftmls_file_modified_time = filemtime(AFTMLS_PLUGIN_DIR . 'dist/block.build.js');

  // Append the modified time as a timestamp to the version.
  $aftmls_version_with_timestamp = '2.0.' . $aftmls_file_modified_time;

  wp_enqueue_script(
    'aftmls-dashboard-script', // Handle.
    AFTMLS_PLUGIN_URL . 'dist/block.build.js',
    array(
      'wp-blocks',
      'wp-i18n',
      'wp-element',
      'wp-components',
      'wp-editor',

    ), // Dependencies, defined above.
    $aftmls_version_with_timestamp, // version.
    true
    // Enqueue the script in the footer.
  );

  wp_enqueue_script(
    'aftmls-backend-script', // Handle.
    AFTMLS_PLUGIN_URL . 'dist/admin_script.build.js',
    array(
      'aftmls-dashboard-script',
      'jquery',
      'updates',
    ), // Dependencies, defined above.
    '1.0', // version.
    true
  );

  if (is_admin()):
    wp_enqueue_style(
      'aftmls-block-edit-style',
      AFTMLS_PLUGIN_URL . 'dist/blocks.editor.build.css',
      array('wp-edit-blocks')
    );
  endif;

  $is_elementor_active = file_exists(WP_PLUGIN_DIR . '/' . 'elementor/elementor.php') ? 'true' : 'false';
  $is_elespare_active = file_exists(WP_PLUGIN_DIR . '/' . 'elespare/elespare.php') ? 'true' : 'false';
  $is_woocommerce_active = file_exists(WP_PLUGIN_DIR . '/' . 'woocommerce/woocommerce.php') ? 'true' : 'false';
  $is_blockspare_active = file_exists(WP_PLUGIN_DIR . '/' . 'blockspare/blockspare.php') ? 'true' : 'false';
  $theme = wp_get_theme();
  $listConfig = templatespare_get_filtered_data();
  $is_pro = templatespare_cheeck_pro_themes();

  $installed_themes = $data->templatespare_get_all_install_themes();


  $templatesapre_active_theme = wp_get_theme();
  $theme_index = strtolower($active_theme = get_stylesheet());
  $defined_theme = '';

  /**
   * Check whether the get_current_screen function exists
   * because it is loaded only after 'admin_init' hook.
   */

  $current_screen = get_current_screen();



  $selected_cats = get_option('templatespare_wizard_category_value', true);

  if (!isset($selected_cat) && empty($selected_cat)) {
    $selected_cats = 'all-cat';
  } else {
    $selected_cats =  $selected_cat[1];
  }


  $theme_index = 'all';

  wp_localize_script(
    'aftmls-dashboard-script',
    'afobDash',
    array(
      'ajax_nonce' => wp_create_nonce('aftc-ajax-verification'),
      'apiUrl' => site_url() . '/index.php?rest_route=/',
      'srcUrl' => AFTMLS_PLUGIN_URL . 'assets/images',
      'widgetsrcUrl' => AFTMLS_PLUGIN_URL . 'includes/wizard/cat-images',
      'afthemes_lists' => json_encode($installed_themes),
      'active_theme' => $theme_index,
      'elementor' => $is_elementor_active,
      'elespare' => $is_elespare_active,
      'blockspare' => $is_blockspare_active,
      'woocommerce' => $is_woocommerce_active,
      'configList' => json_encode($listConfig),
      'themes' => $theme->name,
      'allThems' => wp_get_themes(),
      'isPro' => $is_pro,
      'logo' => AFTMLS_PLUGIN_URL . 'assets/images/logo.svg',
      'aflogo' => AFTMLS_PLUGIN_URL . 'assets/images/afthemes.png',
      'cscreen' => '$hook',
      'currentTheme' => $defined_theme ? $defined_theme : $theme->name,
      'selected_cat' => $selected_cats,
      'templatespare_dashbord_href' => admin_url('admin.php?page=templatespare-main-dashboard', 'admin'),
      'templatespare_wizard_href' => admin_url('admin.php?page=wizard-page', 'admin'),
      'all_categories' => get_all_categories()

    )
  );
  // Enqueue the script.
  wp_enqueue_script(
    'templatespare-central-script',
    AFTMLS_PLUGIN_URL . '/dist/wizard_dashboard.build.js',
    array('wp-api-fetch', 'wp-element', 'aftmls-dashboard-script'), // Dependencies.
    '1.0',
    false
  );
  //blocks.editor.build.css
  $step = (int) get_option('templatespare_wizard_next_step', 0);
  wp_localize_script(
    'templatespare-central-script',
    'afcobDash',
    array(
      'apiUrl' => site_url() . '/index.php?rest_route=/',
      'saveStep' => ($step) ? $step : 0,
      'templatespare_dashbord_href' => admin_url('admin.php?page=templatespare-main-dashboard', 'admin'),

    )
  );

  wp_print_scripts();
  //wp_enqueue_emoji_styles();
  // wp_print_styles();
  wp_print_styles(['templatespare-central-style', 'aftmls-block-edit-style', 'dashicons']);


  ?>
  <div id="templatespare-starter-container"></div>

</body>