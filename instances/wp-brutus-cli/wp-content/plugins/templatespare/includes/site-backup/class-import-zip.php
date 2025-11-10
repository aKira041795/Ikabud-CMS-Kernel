<?php

class Templatespareimportzip
{
  private static $instance = null;

  public static function get_instance()
  {
    if (self::$instance == null) {
      self::$instance = new self();
    }
    return self::$instance;
  }

  public function __construct()
  {

    if (!current_user_can('import')) { // Replace 'download_files' with a suitable capability if needed
      return;
    }

    add_action('wp_ajax_templatespare_import_zip_Files', [$this, 'templatespare_import_zip_Files']); // Handle AJAX request
    add_action('wp_ajax_templatespare_after_import', [$this, 'templatespare_after_import']); // Handle AJAX request
  }






  public function templatespare_import_zip_Files()
  {



    check_ajax_referer('templastespare_sitebackup_nonce', 'nonce');




    if (isset($_FILES['templatespare_import_file'])) {

      if (!isset($_FILES['templatespare_import_file']) || $_FILES['templatespare_import_file']['error'] !== UPLOAD_ERR_OK) {
        wp_send_json_error('Error uploading file.');
        return;
      }

      $uploaded_file = $_FILES['templatespare_import_file'];

      // $file_mime_type = mime_content_type($uploaded_file['tmp_name']);
      // $allowed_mime_types = ['application/zip'];
      // if (!in_array($file_mime_type, $allowed_mime_types)) {
      //   wp_send_json_error('Invalid file type. Only ZIP  files are allowed.');
      //   return;
      // }

      // Load WordPress Filesystem API
      if (!function_exists('WP_Filesystem')) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
      }

      WP_Filesystem();

      $fileName = pathinfo($uploaded_file['name']);
      $fileNameWithoutext = preg_replace('/\s\(\d+\)$/', '', $fileName['filename']);
      $destination = wp_upload_dir();
      $destination_path = $destination['basedir'];
      $zip_file_path = $destination_path . '/' . basename($uploaded_file['name']);

      if (move_uploaded_file($uploaded_file['tmp_name'], $zip_file_path)) {
        $unzipfile = unzip_file($zip_file_path, $destination_path);

        if ($unzipfile) {
          $final_filename = str_replace('backup_', '', $fileNameWithoutext);

          $copy_status = true;
          $unzipped_folder_path = $destination_path . '/' . $final_filename;

          // Check for demo-content folder and files
          $demo_content_path = $unzipped_folder_path . '/demo-content';
          $xml_file = $demo_content_path . '/demo-content.xml';
          $wie_file = $demo_content_path . '/demo-content.wie';
          $dat_file = $demo_content_path . '/demo-content.dat';



          if (!file_exists($demo_content_path) || !is_dir($demo_content_path)) {
            $folderexit = $this->isFolderExists('folder', $zip_file_path);

            $response = ['msg' => $folderexit];
            wp_send_json_error($response);
            return;
          }

          if (!file_exists($xml_file) || !file_exists($wie_file) || !file_exists($dat_file)) {
            $fileexist = $this->isFolderExists('files', $zip_file_path);
            $response = ['msg' => $fileexist];
            wp_send_json_error($response);

            return;
          }
          $themes_source = $destination_path . '/' . $final_filename . '/theme';
          $plugins_source = $destination_path . '/' . $final_filename . '/plugins';
          $themes_destination = get_theme_root();
          $plugins_destination = WP_PLUGIN_DIR;


          //Theme and plugins
          // Check if the theme directory already exists in the destination
          if (is_dir($themes_source) && !$this->copy_directory($themes_source, $themes_destination)) {
            $copy_status = false;
            $response = ['msg' => 'Error copying theme folder.'];
            wp_send_json_error($response);
            return;
          }

          // Check if the plugin directory already exists in the destination
          if (is_dir($plugins_source) && !$this->copy_directory($plugins_source, $plugins_destination)) {
            $copy_status = false;
            $response = ['msg' => 'Error copying plugin folder.'];
            wp_send_json_error($response);
            return;
          }
          // Set permissions for the unzipped folder
          chmod($unzipped_folder_path, FS_CHMOD_DIR);


          if ($copy_status) {
            $configFilePath = $destination_path . '/' . $final_filename . '/config.json';
            $jsonContent = file_get_contents($configFilePath);
            $configData = json_decode($jsonContent, true);
            $configInfoData = '';
            if (file_exists($configFilePath)) {
              $configInfoData =  $this->templatesapre_require_data($configFilePath);
            }

            //Paste theme and plugins

            if (json_last_error() === JSON_ERROR_NONE) {
              $activeTheme = $configData['theme'];
              $activePlugins = $configData['plugins'];

              if ($activeTheme) {
                switch_theme($activeTheme['slug']);
              }

              foreach ($activePlugins as $plugin) {
                if (isset($plugin['slug'])) {
                  $pluginSlug = $plugin['slug'];
                  $pluginPath = $pluginSlug . '/' . $pluginSlug . '.php';
                  if (!is_plugin_active($pluginPath)) {
                    activate_plugin($pluginPath, '', false, true);
                  }
                }
              }
            }

            $demourl = wp_get_upload_dir();
            $xml = $demourl['baseurl'] . '/' . $final_filename . '/demo-content/demo-content.xml';
            $wie = $demourl['baseurl'] . '/' . $final_filename . '/demo-content/demo-content.wie';
            $dat = $demourl['baseurl'] . '/' . $final_filename . '/demo-content/demo-content.dat';
            $fileName = $final_filename;

            $democontent = array('xml' => $xml, 'wie' => $wie, 'dat' => $dat, 'file_name' => $fileName);
            $jsonFilePath = $destination_path . '/' . $final_filename . '/demo-content.json';

            if (!file_exists(dirname($jsonFilePath))) {
              mkdir(dirname($jsonFilePath), 0755, true);
            }

            file_put_contents($jsonFilePath, json_encode($democontent, JSON_PRETTY_PRINT));

            if (file_exists($zip_file_path)) {
              unlink($zip_file_path);
            }

            $response = ['success' => true, 'filepath' => $jsonFilePath, 'method' => 'manual', 'folderName' => $final_filename, 'Msg' => $configInfoData];
            wp_send_json_success($response);
          } else {
            wp_send_json_error('Error copying files.');
          }
        } else {
          wp_send_json_error('Error unzipping the file.');
        }
      } else {
        wp_send_json_error('Error moving uploaded file.');
      }
    } else {
      wp_send_json_error('No file uploaded.');
    }
    die();
  }

  public function copy_directory($source, $destination)
  {
    global $wp_filesystem;

    if (!$wp_filesystem->is_dir($source)) {
      return false; // Source folder doesn't exist
    }



    // Make sure the destination directory exists, create if not
    if (!$wp_filesystem->is_dir($destination)) {
      $wp_filesystem->mkdir($destination);
    }

    // Copy files and folders recursively
    $dir = opendir($source);
    while (false !== ($file = readdir($dir))) {
      if ($file !== '.' && $file !== '..') {
        $src_file = $source . '/' . $file;
        $dst_file = $destination . '/' . $file;

        if (is_dir($src_file)) {
          // Recursively copy directories
          if ($wp_filesystem->is_dir($dst_file)) {

            continue; // Skip copying this directory
          }
          self::copy_directory($src_file, $dst_file);
        } else {
          // Copy files
          $wp_filesystem->copy($src_file, $dst_file, true, FS_CHMOD_FILE);
        }
      }
    }
    closedir($dir);
    return true;
  }
  public function isFolderExists($type = '', $zip_file_path = '')
  {
    $message = '';

    if ($type === 'folder') {
      $message = __('The demo-content folder does not exist.', 'templatespare');
    } elseif ($type === 'files') {
      $message = __('One or more required files (demo-content.xml, demo-content.wie, demo-content.dat) are missing.', 'templatespare');
    }
    if (isset($zip_file_path)) {
      unlink($zip_file_path);
    }

    return sprintf('<div class="error"><h2>%s</h2></div>', esc_html($message));
  }


  public function templatesapre_require_data($configFilePath)
  {

    $jsonContent = file_get_contents($configFilePath);
    $configData = json_decode($jsonContent, true);
    $activePlugins = $configData['plugins'];
    $themes_url = admin_url('themes.php');
    $plugins_url = admin_url('plugins.php');
    ob_start(); ?>
    <h2><?php esc_html_e('To achieve the same demo as the one you exported, please install and activate the following themes and plugins .', 'templatespare') ?></h2>
    <div class="templatespare-theme-plugin-list">


    </div>

<?php
    return ob_get_clean();
  }

  public function templatespare_after_import()
  {
    // Verify nonce for security
    check_ajax_referer('templastespare_sitebackup_nonce', 'nonce');

    // Ensure the 'foldername' parameter is set in the POST request
    if (isset($_POST['foldername'])) {
      $foldername = sanitize_text_field($_POST['foldername']);
      $destination = wp_upload_dir();
      $destination_path = $destination['basedir'];
      $config_json_path = $destination_path . '/' . $foldername . '/config.json';

      // Check if the config.json file exists
      if (!file_exists($config_json_path)) {
        return new WP_Error('file_not_found', 'Config file not found.');
      }

      // Decode the JSON data from config file
      $config_data = json_decode(file_get_contents($config_json_path), true);

      // Handle homepage setting if 'homepage' title exists in the config
      if (isset($config_data['homepage']['title'])) {
        $this->set_homepage_from_config($config_data['homepage']['title']);
      }

      // Handle active navigation menus if 'active_nav_menu' exists in the config
      if (isset($config_data['active_nav_menu']) && is_array($config_data['active_nav_menu'])) {
        $this->set_active_nav_menus($config_data['active_nav_menu']);
      }

      // Optionally, flush rewrite rules if needed
      flush_rewrite_rules();
    }

    exit;
  }

  /**
   * Set the homepage to the most recent page with the given title.
   *
   * @param string $homepage_title The title of the homepage to set.
   */
  private function set_homepage_from_config($homepage_title)
  {
    // Search for pages with the specified title
    $query_args = [
      'post_type'   => 'page',
      'post_status' => 'publish',
      'title'       => $homepage_title,
    ];

    $query = new WP_Query($query_args);

    if ($query->have_posts()) {
      $new_homepage_id = $this->get_most_recent_page($query->posts);
      if ($new_homepage_id) {
        // Update the homepage
        update_option('page_on_front', $new_homepage_id);
        update_option('show_on_front', 'page');

        return true; // Success
      }
    }

    return new WP_Error('page_not_found', "No page with the title '$homepage_title' was found.");
  }

  /**
   * Get the most recently created page from a list of pages.
   *
   * @param array $pages The list of pages to check.
   * @return int|null The ID of the most recent page, or null if not found.
   */
  private function get_most_recent_page($pages)
  {
    $new_homepage_id = null;
    foreach ($pages as $page) {
      if (!$new_homepage_id || strtotime($page->post_date) > strtotime(get_post($new_homepage_id)->post_date)) {
        $new_homepage_id = $page->ID;
      }
    }
    return $new_homepage_id;
  }

  /**
   * Set the active navigation menus from the config data.
   *
   * @param array $active_nav_menus The active navigation menus from the config.
   */
  private function set_active_nav_menus($active_nav_menus)
  {
    // $active_nav_menus = isset($config_data['active_nav_menu']) ? $config_data['active_nav_menu'] : [];

    // Retrieve the registered menu locations
    $registered_menus = get_registered_nav_menus();

    // Retrieve all available menus
    $nav_menus = get_terms('nav_menu', array('hide_empty' => true));

    if (empty($registered_menus) || empty($nav_menus)) {
      error_log('No registered menu locations or navigation menus found.');
      return;
    }

    $new_menu_locations = [];
    if (!empty($active_nav_menus)) {

      // Loop through registered menus and assign based on active_nav_menu
      foreach ($registered_menus as $location_slug => $description) {

        foreach ($nav_menus as $menu) {
          if ($location_slug === 'aft-primary-nav' && in_array('aft-primary-nav', $active_nav_menus) && $menu->slug === 'menu') {
            $new_menu_locations[$location_slug] = $menu->term_id;
          }
          if ($location_slug === 'aft-social-nav' && in_array('aft-social-nav', $active_nav_menus) && $menu->slug === 'social') {
            $new_menu_locations[$location_slug] = $menu->term_id;
          }
          if ($location_slug === 'aft-footer-nav' && in_array('aft-footer-nav', $active_nav_menus) && $menu->slug === 'footer') {
            $new_menu_locations[$location_slug] = $menu->term_id;
          }
        }
      }

      // Update the theme's menu locations
      if (!empty($new_menu_locations)) {
        set_theme_mod('nav_menu_locations', $new_menu_locations);
      }
    }
    return true;
  }
}
