<?php

if (!defined('ABSPATH')) exit;


add_action('wp_ajax_templatespare_plugin_installer_activation', 'templatespare_plugin_installer_activation'); // Activate plugin

if (!function_exists('templatespare_plugin_installer_activation')) {

  function templatespare_plugin_installer_activation()
  {
    if (! current_user_can('install_plugins'))
      wp_die(__('Sorry, you are not allowed to install plugins on this site.', 'templatespare'));


    $nonce = $_POST["nonce"];

    // Check our nonce, if they don't match then bounce!
    if (! wp_verify_nonce($nonce, 'aftc-ajax-verification'))
      wp_die(__('Error - unable to verify nonce, please try again.', 'templatespare'));

    $install = isset($_POST['install']) ? $_POST['install'] : '';
    $activate = isset($_POST['activate']) ? $_POST['activate'] : '';
    $page =  isset($_POST['page']) ? $_POST['page'] : '';
    if (is_array($install)) {
      $sanitinzed_plugins = array_map('sanitize_key', $install);
    } else {
      $sanitinzed_plugins = sanitize_key($install);
    }
    $is_active = 'false';
    if (is_array($activate)) {
      $is_active = 'true';
      $sanitinzed_activate = array_map('sanitize_key', $activate);
    } else {
      $is_active = 'true';
      $sanitinzed_activate = array(sanitize_key($activate));
    }



    // Include required libs for installation
    require_once(ABSPATH . 'wp-admin/includes/plugin-install.php');
    require_once(ABSPATH . 'wp-admin/includes/class-wp-upgrader.php');
    require_once(ABSPATH . 'wp-admin/includes/class-wp-ajax-upgrader-skin.php');
    require_once(ABSPATH . 'wp-admin/includes/class-plugin-upgrader.php');
    $data = [];
    // Get Plugin Info
    if (!empty($sanitinzed_plugins)) {
      foreach ($sanitinzed_plugins as $pl) {
        $api = plugins_api(
          'plugin_information',
          array(
            'slug' => $pl,
            'fields' => array(
              'short_description' => false,
              'sections' => false,
              'requires' => false,
              'rating' => false,
              'ratings' => false,
              'downloaded' => false,
              'last_updated' => false,
              'added' => false,
              'tags' => false,
              'compatibility' => false,
              'homepage' => false,
              'donate_link' => false,
            ),
          )
        );

        $skin     = new WP_Ajax_Upgrader_Skin();
        $upgrader = new Plugin_Upgrader($skin);

        $upgrader->install($api->download_link);


        if ($api->name) {
          $status = 'success';
          $msg = $api->name . ' successfully installed.';
        } else {
          $status = 'failed';
          $msg = 'There was an error installing ' . $api->name . '.';
        }
        array_push($sanitinzed_activate, $pl);
      }
      $json = array(
        'status' => 'success',
        'url' => site_url() . '/wp-admin/admin.php?page=' . $page
      );
    }

    $list_of_plugins = array_merge($sanitinzed_activate, $sanitinzed_activate);
    $list_of_plugins  = array_filter($sanitinzed_activate, 'strlen');

    if (!empty($list_of_plugins)) {
      $data =  templatespare_activate_plugin_list($sanitinzed_activate);
    }

    if ($data == 'success') {

      $json = array(
        'status' => 'success',
        'url' => site_url() . '/wp-admin/admin.php?page=' . $page
      );
      return wp_send_json($json);
    }
  }
}
function templatespare_activate_plugin_list($sanitinzed_activate)
{
  $count = 0;
  $total = count($sanitinzed_activate);
  foreach ($sanitinzed_activate as $plugin) {
    $count++;

    if ($plugin == 'elespare-pro') {

      activate_plugin('elespare-pro/elespare.php', '', false, true);
    }
    if ($plugin == 'blockspare-pro') {

      activate_plugin('blockspare-pro/blockspare.php', '', false, true);
    } else if (!is_plugin_active($plugin . '/' . $plugin . '.php')) {
      activate_plugin($plugin . '/' . $plugin . '.php', '', false, true);
    }
  }

  return "success";
}
if (!function_exists('templatespare_get_plugin_file')) {
  function templatespare_get_plugin_file($plugin_slug)
  {
    require_once(ABSPATH . '/wp-admin/includes/plugin.php'); // Load plugin lib
    $plugins = get_plugins();

    foreach ($plugins as $plugin_file => $plugin_info) {

      // Get the basename of the plugin e.g. [askismet]/askismet.php
      $slug = dirname(plugin_basename($plugin_file));

      if ($slug) {
        if ($slug == $plugin_slug) {
          return $plugin_file; // If $slug = $plugin_name
        }
      }
    }
    return null;
  }
}
