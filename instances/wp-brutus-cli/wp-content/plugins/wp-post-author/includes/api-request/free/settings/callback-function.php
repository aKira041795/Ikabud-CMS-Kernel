<?php

function awpa_post_author_default_options()
{
  $default_theme_options = array(
    'awpa_global_title' => __('About The Author', 'wp-post-author'),
    'awpa_global_align' => 'left',
    'awpa_global_image_layout' => 'square',
    'awpa_author_posts_link_layout' => 'round',
    'awpa_social_icon_layout' => 'round',
    'awpa_global_show_role' => false,
    'awpa_global_show_email' => false,
    'awpa_highlight_color' => '#af0000',
    'awpa_also_visibile_in_awpa_user_form_build' => true,
    'awpa_hide_from_post_content' => false,
    'awpa_custom_css' => '',
    'awpa_login_url' => '',
    'awpa_register_url' => '',
    'awpa_also_visibile_in_' => array(
      'post' => true,
      'page' => false
    )
  );

  return apply_filters('awpa_post_author_default_options', $default_theme_options);
}

function awpa_post_author_get_options($key = '')
{
  $options = get_option('awpa_setting_options');
  $default_options = awpa_post_author_default_options();

  if (!empty($key)) {
    if (isset($options[$key])) {
      return $options[$key];
    }
    return isset($default_options[$key]) ? $default_options[$key] : false;
  } else {
    if (!is_array($options)) {
      $options = array();
    }
    return array_merge($default_options, $options);
  }
}

function awpa_post_author_delete_options($key = '')
{
  if (!empty($key)) {
    $options = awpa_post_author_get_options();
    if (isset($options[$key])) {
      unset($options[$key]);
      return update_option('awpa_setting_options', $options);
    }
  } else {
    return delete_option('awpa_setting_options');
  }
}

function awpa_post_author_set_options($settings)
{
  $setting_keys = array_keys(awpa_post_author_default_options());
  $options = array();
  foreach ($settings as $key => $value) {
    if (in_array($key, $setting_keys)) {
      switch ($key) {
        case $key == 'awpa_global_title' || $key == 'awpa_highlight_color' || $key == 'awpa_also_visibile_in_awpa_user_form_build' || $key == 'awpa_custom_css' || $key == 'awpa_hide_from_post_content':
          $fvalue = sanitize_text_field($value);
          break;
        case 'bool': //extra for reference
          $fvalue = (bool) $value;
          break;
        case $key == 'awpa_global_align' || $key ==  'awpa_global_image_layout' || $key ==  'awpa_global_show_role' || $key ==
          'awpa_global_show_email' || $key ==  'awpa_author_posts_link_layout' || $key ==  'awpa_social_icon_layout':
          $fvalue = sanitize_key($value);
          break;
        case 'awpa_also_visibile_in_': //extra for reference
          $fvalue = $value;
          break;
        default:
          $fvalue = sanitize_key($value);
          break;
      }
      $options[$key] = $fvalue;
    }
  }
  return update_option('awpa_setting_options', $options);
}

function awpa_post_author_integration_setting_default_options()
{
  $default_integration_options = array(
    'enable_recaptcha' => false,
    'recaptcha_version' => 'v2',
    'site_key' => '',
    'secret_key' => '',
  );
  return apply_filters('awpa_post_author_integration_setting_default_options', $default_integration_options);
}

function awpa_post_author_integration_setting($key = '')
{
  $options = get_option('awpa_integrations_setting_options');
  $default_options = awpa_post_author_integration_setting_default_options();

  if (!empty($key)) {
    if (isset($options[$key])) {
      return $options[$key];
    }
    return isset($default_options[$key]) ? $default_options[$key] : false;
  } else {
    if (!is_array($options)) {
      $options = array();
    }
    return array_merge($default_options, $options);
  }
}

function awpa_post_author_set_integration_settings($settings)
{
  $setting_keys = array_keys(awpa_post_author_integration_setting_default_options());
  $options = array();
  foreach ($settings as $key => $value) {
    if (in_array($key, $setting_keys)) {
      switch ($key) {
        case 'recaptcha_version' || 'secret_key' || 'site_key':
          $value = sanitize_text_field($value);
          break;
        case 'enable_recaptcha': //extra for reference
          $value = (bool) $value;
          break;
        default:
          $value = sanitize_key($value);
          break;
      }
      $options[$key] = $value;
    }
  }
  update_option('awpa_integrations_setting_options', $options);
}


/*
 * Social Login Integration Setting
 */
function awpa_social_login_integration_setting_default_options()
{
  $default_integration_options = array(
    'enable_facebook_login' => false,
    'app_id' => '',
    'app_secret' => '',
    'enable_google_login' => false,
    'client_id' => '',
  );
  return apply_filters('awpa_social_login_integration_setting_default_options', $default_integration_options);
}
function awpa_social_login_integration_setting($key = '')
{
  $options = get_option('aft_wpa_social_login_integrations_setting_options');
  $default_options = awpa_social_login_integration_setting_default_options();

  if (!empty($key)) {
    if (isset($options[$key])) {
      return $options[$key];
    }
    return isset($default_options[$key]) ? $default_options[$key] : false;
  } else {
    if (!is_array($options)) {
      $options = array();
    }
    return array_merge($default_options, $options);
  }
}

function awpa_set_social_login_integration_settings($settings)
{
  $setting_keys = array_keys(awpa_social_login_integration_setting_default_options());
  $options = array();
  foreach ($settings as $key => $value) {
    if (in_array($key, $setting_keys)) {
      switch ($key) {
        case 'app_id' || 'app_secret' || 'client_id':
          $value = sanitize_text_field($value);
          break;
        case 'enable_facebook_login' || 'enable_google_login': //extra for reference
          $value = (bool) $value;
          break;
        default:
          $value = sanitize_key($value);
          break;
      }
      $options[$key] = $value;
    }
  }
  update_option('aft_wpa_social_login_integrations_setting_options', $options);
}


/*
 * Mailchimp Integration Setting
 */

function awpa_mail_settings_default_options()
{
  $default_options = array(
    'awpa_mail_setting' => 'default',
    'email' => '',
    'password' => '',
    'server_name' => '',
    'authentication' => 'ssl',
    'port_number' => '',
    'from_name' => '',
  );
  return apply_filters('awpa_mail_settings_default_options', $default_options);
}
function awpa_mail_setting($key = '')
{
  $options = get_option('aft_wpa_mail_settings');
  $default_options = awpa_mail_settings_default_options();

  if (!empty($key)) {
    if (isset($options[$key])) {
      return $options[$key];
    }
    return isset($default_options[$key]) ? $default_options[$key] : false;
  } else {
    if (!is_array($options)) {
      $options = array();
    }
    return array_merge($default_options, $options);
  }
}

function awpa_mail_settings($settings)
{
  $setting_keys = array_keys(awpa_mail_settings_default_options());
  $options = array();
  foreach ($settings as $key => $value) {
    if (in_array($key, $setting_keys)) {
      switch ($key) {
        case 'email' || 'password' || 'server_name' || 'authentication' || 'port_number' || 'awpa_mail_setting' || 'from_name':
          $value = sanitize_text_field($value);
          break;
        default:
          $value = sanitize_key($value);
          break;
      }
      $options[$key] = $value;
    }
  }
  update_option('aft_wpa_mail_settings', $options);
}


function awpa_author_metabox_default_options()
{
  $default_options = array(
    'enable_author_metabox' => false
  );
  return apply_filters('awpa_author_metabox_default_options', $default_options);
}
function awpa_get_author_metabox_setting($key = '')
{
  $options = get_option('awpa_author_metabox_integration');
  $default_options = awpa_author_metabox_default_options();

  if (!empty($key)) {
    if (isset($options[$key])) {
      return $options[$key];
    }
    return isset($default_options[$key]) ? $default_options[$key] : false;
  } else {
    if (!is_array($options)) {
      $options = array();
    }
    return array_merge($default_options, $options);
  }
}

function awpa_set_author_metabox_setting($settings)
{
  $setting_keys = array_keys(awpa_author_metabox_default_options());
  $options = array();
  foreach ($settings as $key => $value) {
    if (in_array($key, $setting_keys)) {
      switch ($key) {
        case 'enable_author_metabox':
          $value = (bool)$value;
          break;
        default:
          $value = sanitize_key($value);
          break;
      }
      $options[$key] = $value;
    }
  }
  update_option('awpa_author_metabox_integration', $options);
}
