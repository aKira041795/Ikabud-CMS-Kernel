<?php

/**
 *
 */




add_action('wp_ajax_templatespare_get_theme_status', 'templatespare_get_theme_status');
add_action('wp_ajax_templatespare_activate_required_theme', 'templatespare_activate_required_theme');

/**
 ** Install/Activate Required Theme
 */
function templatespare_activate_required_theme()
{

  if (!current_user_can('manage_options')) {
    return;
  }
  check_ajax_referer('aftc-ajax-verification', 'security');
  if (isset($_POST['theme'])) {
    $theme = sanitize_text_field($_POST['theme']);
    $res_theme = strtolower($theme);
    $theme_slug = $res_theme;
    switch_theme($theme_slug);
    set_transient('templatespare-kit_activation_notice', true);
  }
  echo "success";
  wp_die();
}

function templatespare_get_theme_status()
{
  if (!current_user_can('manage_options')) {
    return;
  }
  check_ajax_referer('aftc-ajax-verification', 'security');

  if (isset($_POST['re_theme'])) {

    $themename = sanitize_text_field($_POST['re_theme']);

    $theme = wp_get_theme();
    $current_active_theme = str_replace(' ', '-', strtolower($theme->name));

    //check if pro theme is activated 


    if (templatespare_is_current_theme_valid_pro($current_active_theme, $themename)) {
      return wp_send_json_success(array(
        'status' => 'req-theme-active',
      ), 200);
    }
    // Theme installed and activate.
    if (strtolower($themename) == $current_active_theme) {

      return wp_send_json_success(array(
        'status' => 'req-theme-active',
      ), 200);
    }

    // Theme installed but not activate.
    foreach ((array) wp_get_themes() as $theme_dir => $themes) {
      $current_inactive_theme = str_replace(' ', '-', $themes->name);
      if (strtolower($themename) == strtolower($current_inactive_theme)) {

        return wp_send_json_success(array(
          'status' => 'req-theme-inactive',
        ), 200);
      }
    }

    return wp_send_json_success(array(
      'status' => 'req-theme-not-installed',
    ), 200);
  }
}

function templatespare_available_themes()
{


  return $themes = array(
    'CoverNews',
    'ChromeNews',
    'MoreNews',
    'Newsphere',
    'EnterNews',
    'DarkNews',
    'Newsium',
    'Newsever',
    'Elegant Magazine',
    'Magazine 7',
    'BroadNews',
    'Magnitude',
    'Shopical',
    'Storeship',
    'StoreCommerce',
    'Kreeti',
  );
}

function templatespare_available_pro_themes()
{

  return $themes = array(

    'CoverNews Pro',
    'BroadNews Pro',
    'ChromeNews Pro',
    'MoreNews Pro',
    'EnterNews Pro',
    'DarkNews Pro',
    'Storeship Pro',
    'Newsium Pro',
    'Newsever Pro',
    'Shopical Pro',
    'Newsphere Pro',
    'Elegant Magazine Pro',
    'Magazine 7 Plus',
    'Storecommerce Pro',
    'Magnitude Pro',
    'Kreeti',
    'ReviewNews Pro',

  );
}

if (apply_filters('templatesapre_clear_data_before_demo_import', true)) {
  add_action('templatespare_ajax_before_demo_import', 'templatespare_reset_widgets', 10);
  add_action('templatespare_ajax_before_demo_import', 'templatespare_delete_nav_menus', 20);
  add_action('templatespare_ajax_before_demo_import', 'templatespare_remove_theme_mods', 30);
}

function templatespare_reset_widgets()
{
  $sidebars_widgets = wp_get_sidebars_widgets();

  // Reset active widgets.
  foreach ($sidebars_widgets as $key => $widgets) {
    $sidebars_widgets[$key] = array();
  }

  wp_set_sidebars_widgets($sidebars_widgets);
}

function templatespare_delete_nav_menus()
{
  $nav_menus = wp_get_nav_menus();

  // Delete navigation menus.
  if (!empty($nav_menus)) {
    foreach ($nav_menus as $nav_menu) {
      wp_delete_nav_menu($nav_menu->slug);
    }
  }
}

function templatespare_remove_theme_mods()
{
  remove_theme_mods();
}

add_action('wp_ajax_nopriv_templatespare_install_require_plugins', 'templatespare_install_require_plugins');
add_action('wp_ajax_templatespare_install_require_plugins', 'templatespare_install_require_plugins');

function templatespare_install_require_plugins()
{
  check_ajax_referer('aftc-ajax-verification', 'security');
  $plugins = $_POST['plugins'];

  include_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
  include_once ABSPATH . 'wp-admin/includes/plugin-install.php';

  if (is_array($plugins)) {
    $sanitinzed_plugins = array_map('sanitize_key', $plugins);
  } else {
    $sanitinzed_plugins = sanitize_key($plugins);
  }

  $plugin_path = array();

  foreach ($sanitinzed_plugins as $plugin):
    if (file_exists(WP_PLUGIN_DIR . '/' . $plugin . '/' . $plugin . '.php')) {
      $plugin_data = get_plugin_data(WP_PLUGIN_DIR . '/' . $plugin);

      $status['plugin'] = $plugin;
      $status['pluginName'] = $plugin_data['Name'];

      if (current_user_can('activate_plugin', $plugin) && is_plugin_inactive($plugin)) {

        $plugin_path[] = $plugin . '/' . $plugin . '.php';
      }
    } else {

      if (empty($plugin)) {
        wp_send_json_error(
          array(
            'slug' => '',
            'errorCode' => 'no_plugin_specified',
            'errorMessage' => __('No plugin specified.', 'templatespare'),
          )
        );
      }

      $slug = sanitize_key(wp_unslash($plugin));
      $plugin = plugin_basename(sanitize_text_field(wp_unslash($plugin)));
      $status = array(
        'install' => 'plugin',
        'slug' => sanitize_key(wp_unslash($plugin)),
      );

      if (!current_user_can('install_plugins')) {
        $status['errorMessage'] = __('Sorry, you are not allowed to install plugins on this site.', 'templatespare');
        wp_send_json_error($status);
      }

      // Looks like a plugin is installed, but not active.

      $api = plugins_api(
        'plugin_information',
        array(
          'slug' => sanitize_key($plugin),
          'fields' => array(
            'sections' => false,
          ),
        )
      );

      if (is_wp_error($api)) {
        $status['errorMessage'] = $api->get_error_message();
        wp_send_json_error($status);
      }

      $status['pluginName'] = $api->name;

      $skin = new WP_Ajax_Upgrader_Skin();
      $skin->api = $api;  // Set the API information in the skin
      $upgrader = new Plugin_Upgrader($skin);
      $result = $upgrader->install($api->download_link);

      if (defined('WP_DEBUG') && WP_DEBUG) {
        $status['debug'] = $skin->get_upgrade_messages();
      }

      if (is_wp_error($result)) {
        $status['errorCode'] = $result->get_error_code();
        $status['errorMessage'] = $result->get_error_message();
        wp_send_json_error($status);
      } elseif (is_wp_error($skin->result)) {
        $status['errorCode'] = $skin->result->get_error_code();
        $status['errorMessage'] = $skin->result->get_error_message();
        wp_send_json_error($status);
      } elseif ($skin->get_errors()->get_error_code()) {
        $status['errorMessage'] = $skin->get_error_messages();
        wp_send_json_error($status);
      } elseif (is_null($result)) {
        global $wp_filesystem;

        $status['errorCode'] = 'unable_to_connect_to_filesystem';
        $status['errorMessage'] = __('Unable to connect to the filesystem. Please confirm your credentials.', 'templatespare');

        // Pass through the error from WP_Filesystem if one was raised.
        if ($wp_filesystem instanceof WP_Filesystem_Base && is_wp_error($wp_filesystem->errors) && $wp_filesystem->errors->get_error_code()) {
          $status['errorMessage'] = esc_html($wp_filesystem->errors->get_error_message());
        }

        wp_send_json_error($status);
      }

      $install_status = install_plugin_install_status($api);
      $plugin_path[] = $install_status['file'];
    }
  endforeach;
  if (!empty($plugin_path)) {
    $activatePlugins = activate_plugins($plugin_path, '', false, true);
    if ($activatePlugins) {
      _e('success', 'templatespare');
    }
  }
  wp_die();
}

function templatespare_is_current_theme_valid_pro($current_slug, $target_slug)
{
  $theme = wp_get_theme();
  $theme_name = $theme->get('Name');

  // Step 1: Check if it's a Pro or Plus theme
  if (!preg_match('/\s+(Pro|Plus)$/i', $theme_name)) {
    return false;
  }

  // Step 2: Remove ' Pro' or ' Plus' from end
  $base_name = preg_replace('/\s+(Pro|Plus)$/i', '', $theme_name);

  // Step 3: Slugify (e.g., 'CoverNews' -> 'covernews')
  $base_slug = str_replace(' ', '-', strtolower($base_name));

  // Step 4: Compare with provided $themename
  return $base_slug === $target_slug;
}
