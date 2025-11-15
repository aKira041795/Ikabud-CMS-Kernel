<?php

add_action('rest_api_init', 'morenews_register_plugins_routes');
function morenews_register_plugins_routes()
{
  register_rest_route('aft-useful-plugins/v1', '/get-useful-plugins', array(
    'methods' => 'GET',
    'callback' => 'morenews_get_all_useful_plugins',
    'permission_callback' => function () {

      return current_user_can('manage_options');
    }
  ));
}

function morenews_get_all_useful_plugins(\WP_REST_Request $request)
{
  $params = $request->get_params();

  $plugin_array =  json_decode($request['plug'], TRUE);

  require_once(ABSPATH . 'wp-admin/includes/plugin-install.php');


  $data = array();
  foreach ($plugin_array as $plugin) {

    $button_classes = 'install button';
    $button_text = __('Install Now', 'morenews');

    $plugin_description  = $plugin['description'];



    $api = plugins_api(
      'plugin_information',
      array(
        'slug' => sanitize_file_name($plugin['slug']),
        'fields' => array(
          'short_description' => true,
          'sections' => false,
          'requires' => false,
          'downloaded' => true,
          'last_updated' => false,
          'added' => false,
          'tags' => false,
          'compatibility' => false,
          'homepage' => false,
          'donate_link' => false,
          'icons' => true,
          'banners' => true,
        ),
      )
    );


    if (!is_wp_error($api)) { // confirm error free

      $main_plugin_file = morenews_get_plugin_file($plugin['slug']); // Get main plugin file
      if ($plugin['slug'] == 'templatespare' && !empty($plugin['title'])) {
        $title = $plugin['title'];
      } else {
        $title = $api->name ?? '';
      }

      if (morenews_check_file_extension($main_plugin_file)) { // check file extension
        if (is_plugin_active($main_plugin_file)) {
          // plugin activation, confirmed!
          $button_classes = 'button disabled';
          $button_text = __('Activated', 'morenews');
        } else {
          // It's installed, let's activate it
          $button_classes = 'activate button button-primary';
          $button_text = __('Activate', 'morenews');
        }
      }
      $data['plugins'][] = morenews_render_plugin_lists_template($plugin, $api, $button_text, $button_classes, $plugin_description, $title);
    }
  }

  return $data;
}


function morenews_render_plugin_lists_template($plugin, $api, $button_text, $button_classes, $plugin_description, $title)
{

  ob_start();
?>
  <div class="aft-plugin-installer">
    <div class="plugin">
      <div class="plugin-headear">
        <img src="<?php echo $api->icons['1x']; ?>" alt="">
        <h2><?php echo $title; ?></h2>
      </div>
      <div class="plugin-info">
        <p><?php echo $plugin_description; ?></p>

        <p class="plugin-author"><?php _e('By', 'morenews'); ?> <?php echo $api->author; ?></p>
      </div>
      <ul class="activation-row">
        <li>
          <?php if ($api->slug == 'templatespare' && $button_text == 'Activated') { ?>
            <a class="button-primary" href="<?php echo site_url() . '/wp-admin/admin.php?page=morenews' ?>"><?php echo _e('Get Starter Sites', 'morenews') ?></a>

          <?php  } else if ($api->slug == 'templatespare' && $button_text == 'Install Now') { ?>

            <a class="<?php echo $button_classes; ?>"
              data-slug="<?php echo $api->slug; ?>"
              data-name="<?php echo $api->name; ?>"
              href="<?php echo get_admin_url(); ?>update.php?action=install-plugin&amp;plugin=<?php echo $api->slug; ?>&amp;_wpnonce=<?php echo wp_create_nonce('install-plugin_' . $api->slug) ?>">
              <?php echo $button_text; ?>
            </a>
          <?php } else { ?>

            <a class="<?php echo $button_classes; ?>"
              data-slug="<?php echo $api->slug; ?>"
              data-name="<?php echo $api->name; ?>"
              href="<?php echo get_admin_url(); ?>update.php?action=activate-plugin&amp;plugin=<?php echo $api->slug; ?>&amp;_wpnonce=<?php echo wp_create_nonce('install-plugin_' . $api->slug) ?>">
              <?php echo $button_text; ?>
            </a>
          <?php } ?>
        </li>
        <li>
          <a href="https://wordpress.org/plugins/<?php echo $api->slug; ?>/" target="_blank">
            <?php _e('More Details', 'morenews'); ?>
          </a>
        </li>
      </ul>
    </div>
  </div>


<?php
  return ob_get_clean();
}

function morenews_get_plugin_file($plugin_slug)
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


function morenews_check_file_extension($filename)
{
  
  if (empty($filename) || !is_string($filename)) {
    return false;
}
  
  if (substr(strrchr($filename, '.'), 1) === 'php') {
    // has .php exension
    return true;
  } else {
    // ./wp-content/plugins
    return false;
  }
}
