<?php
if (!function_exists('templatespare_export_xml')) {

  function templatespare_export_xml($backupDir)
  {
    if (!current_user_can('manage_options')) {
      return null;
    }

    global $wp_filesystem;

    // Initialize WP_Filesystem
    if (!function_exists('WP_Filesystem')) {
      require_once ABSPATH . 'wp-admin/includes/file.php';
    }
    WP_Filesystem();
    ob_start();

    require_once ABSPATH . 'wp-admin/includes/export.php';
    $args = apply_filters('export_args', $args = array());
    export_wp($args); // This will output the XML content and trigger header functions

    $xmlData = ob_get_clean(); // Get the content and clean the buffer

    // You can use var_dump for debugging, but remember it will produce output
    // Consider logging it instead:
    // error_log(print_r($xmlData, true));
    $xmlFileName = 'demo-content' . '.xml';
    $xmlFile = $backupDir . $xmlFileName;
    // Save the XML data to a file
    $wp_filesystem->put_contents($xmlFile, $xmlData, FS_CHMOD_FILE);

    return $xmlFileName;
  }
}

if (!function_exists('templatespare_export_wie')) {

  function templatespare_export_wie($backupDir)
  {
    if (!current_user_can('manage_options')) {
      return null;
    }

    $site_url = site_url('', 'http');
    $site_url = trim($site_url, '/\\'); // Remove trailing slash
    $filename = str_replace('http://', '', $site_url); // Remove http://
    $filename = str_replace(array('/', '\\'), '-', $filename); // Replace slashes with -
    $wieFileName = 'demo-content.wie';
    $filename = apply_filters('wie_export_filename', $filename);

    // Generate export file contents (Assuming this returns an array or object)
    $templatespare_file_contents = templatespare_generate_export_data();
    if (empty($templatespare_file_contents)) {
      $templatespare_file_contents = "{}";
    }

    // JSON encoding with error handling
    $json_data = $templatespare_file_contents;
    if (json_last_error() !== JSON_ERROR_NONE) {
      die(esc_html__('Error encoding JSON: ', 'templatespare') . esc_html(json_last_error_msg()));
    }

    // Save the .wie file in the backup directory
    $wieFile = $backupDir . DIRECTORY_SEPARATOR . $wieFileName;
    if (file_put_contents($wieFile, $json_data) === false) {
      die(esc_html__('Failed to write to file: ', 'templatespare') . esc_html($wieFile));
    }

    // Send file to browser for download
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename=' . $filename);
    header('Expires: 0');
    header('Cache-Control: must-revalidate');
    header('Pragma: public');
    header('Content-Length: ' . strlen($json_data));

    // Output the file content
    @ob_end_clean();
    flush();
    echo $json_data;

    return $wieFileName;
  }
}

if (!function_exists('templatespare_exportCustomizerData')) {

  function templatespare_exportCustomizerData($backupDir)
  {
    if (!current_user_can('manage_options')) {
      return null;
    }

    global $wp_filesystem;

    // Initialize WP_Filesystem
    if (!function_exists('WP_Filesystem')) {
      require_once ABSPATH . 'wp-admin/includes/file.php';
    }
    WP_Filesystem();
    ob_start(); // Start output buffering
    $theme    = get_stylesheet();
    $template  = get_template();
    $charset  = get_option('blog_charset');
    $mods    = get_theme_mods();

    // Include menu locations
    $menu_locations = get_theme_mod('nav_menu_locations', []);

    $customizerSettings = [
      'template'  => $template,
      'mods'      => $mods ? $mods : [],
      'options'   => [
        'site_icon'                 => get_option('site_icon'),
        'nav_menus_created_posts'   => get_option('nav_menus_created_posts', []),
      ],
      'menu_locations' => $menu_locations, // Include menu locations
      'wp_css'    => get_option('wp_css', ''),
    ];

    if (function_exists('wp_get_custom_css_post')) {
      $customizerSettings['wp_css'] = wp_get_custom_css();
    }

    header('Content-disposition: attachment; filename=demo-content.dat');
    header('Content-Type: application/octet-stream; charset=' . $charset);

    $serializedData = serialize($customizerSettings);
    $customizerfileName = 'demo-content.dat';
    $customizerfile = $backupDir . $customizerfileName;

    $wp_filesystem->put_contents($customizerfile, $serializedData, FS_CHMOD_FILE);
    ob_end_clean(); // Clean output buffer
    return $customizerfileName;
  }
}


//Widgets

function templatespare_available_widgets()
{
  global $wp_registered_widget_controls;

  $widget_controls = $wp_registered_widget_controls;

  $available_widgets = array();

  foreach ($widget_controls as $widget) {

    if (! empty($widget['id_base']) && ! isset($available_widgets[$widget['id_base']])) { // no dupes

      $available_widgets[$widget['id_base']]['id_base'] = $widget['id_base'];
      $available_widgets[$widget['id_base']]['name'] = $widget['name'];
    }
  }

  return apply_filters('templatespare_available_widgets', $available_widgets);
}



function templatespare_generate_export_data()
{
  // Get all available widgets supported by the site
  $available_widgets = templatespare_available_widgets();
  // Get all widget instances for each widget
  $widget_instances = array();
  foreach ($available_widgets as $widget_data) {
    // Get all instances for this ID base
    $instances = get_option('widget_' . $widget_data['id_base']);

    if (!empty($instances)) {
      // Loop instances
      foreach ($instances as $instance_id => $instance_data) {
        // Key is ID (not _multiwidget)
        if (is_numeric($instance_id)) {
          $unique_instance_id = $widget_data['id_base'] . '-' . $instance_id;
          $widget_instances[$unique_instance_id] = $instance_data;
        }
      }
    }
  }

  // Gather sidebars with their widget instances
  $sidebars_widgets = get_option('sidebars_widgets'); // get sidebars and their unique widgets IDs
  $sidebars_widget_instances = array();

  foreach ($sidebars_widgets as $sidebar_id => $widget_ids) {
    // Skip inactive widgets
    if ('wp_inactive_widgets' == $sidebar_id) {
      continue;
    }

    // Skip if no data or not an array
    if (!is_array($widget_ids) || empty($widget_ids)) {
      continue;
    }

    // Loop widget IDs for this sidebar
    foreach ($widget_ids as $widget_id) {
      // Is there an instance for this widget ID?
      if (isset($widget_instances[$widget_id])) {
        $sidebars_widget_instances[$sidebar_id][$widget_id] = $widget_instances[$widget_id];
      }
    }
  }

  if (empty($sidebars_widget_instances)) {

    return;
  }

  // Return the filtered, structured data (not encoded yet)
  $data = apply_filters('wie_unencoded_export_data', $sidebars_widget_instances);

  // Encode the data for file contents
  $encoded_data = wp_json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
  return apply_filters('wie_generate_export_data', $encoded_data);
}
