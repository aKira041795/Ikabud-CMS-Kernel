<?php

if (!defined('ABSPATH')) {
  exit; // Exit if accessed directly
}

class TemplatesapreBackupSite
{
  private static $instance = null;

  private function __construct()
  {
    // Add hooks
    add_action('admin_init', [$this, 'templatespare_backup_load_files']);
    add_action('admin_enqueue_scripts', [$this, 'templatespare_add_backup_menus_sctipt']);
    add_action('wp_ajax_templatespare_exportFiles', [$this, 'templatespare_exportFiles']); // Handle AJAX request
    add_action('wp_ajax_get_folder_details', [$this, 'get_folder_details']);
  }

  public static function get_instance()
  {
    if (self::$instance === null) {
      self::$instance = new self();
    }

    return self::$instance;
  }

  public function templatespare_add_backup_menus_sctipt()
  {

    wp_enqueue_style(
      'templatespare_export_style',
      AFTMLS_PLUGIN_URL . 'assets/css/export.css',
      [],
      '1.0',
      'all'
    );

    wp_enqueue_script(
      'templatespare_backup_script',
      AFTMLS_PLUGIN_URL . 'dist/migrate_script.build.js',
      ['aftmls-backend-script'],
      '1.0',
      true
    );
    //
    wp_register_script(
      'templatespare_fileimport_script',
      AFTMLS_PLUGIN_URL . 'dist/fileUpload_script.build.js',
      ['aftmls-backend-script', 'jquery'],
      '1.0',
      true
    );


    wp_localize_script('templatespare_backup_script', 'templatespareBackup', [
      'ajax_url' => admin_url('admin-ajax.php'),
      'nonce'    => wp_create_nonce('templastespare_sitebackup_nonce')
    ]);
    wp_localize_script('templatespare_fileimport_script', 'templatespareImport', [
      'ajax_url' => admin_url('admin-ajax.php'),
      'nonce'    => wp_create_nonce('templastespare_sitebackup_nonce'),
      'fileuploadsize' => $this->templatespare_file_upload_max_size()
    ]);
  }

  public function templatespare_file_upload_max_size()
  {
    static $max_size = -1;

    if ($max_size < 0) {
      $post_max_size = $this->templatespare_parse_size(ini_get('post_max_size'));
      if ($post_max_size > 0) {
        $max_size = $post_max_size;
      }

      $upload_max = $this->templatespare_parse_size(ini_get('upload_max_filesize'));
      if ($upload_max > 0 && $upload_max < $max_size) {
        $max_size = $upload_max;
      }
    }
    return $max_size;
  }

  public function templatespare_parse_size($size)
  {
    $unit = strtolower(substr($size, -1));
    $size = (int) $size;
    switch ($unit) {
      case 'g':
        $size *= 1024;
      case 'm':
        $size *= 1024;
      case 'k':
        $size *= 1024;
    }
    return $size;
  }

  public function templatespare_backup_load_files()
  {
    // Restrict access to administrators only
    if (!current_user_can('manage_options')) {
      return;
    }
    require_once AFTMLS_PLUGIN_DIR . 'includes/site-backup/class-download-backup-zip.php';
    $downloadZip = new TemplatesapreDownloadZip();
    $downloadZip->templatespareDownloadAsZip();

    require_once AFTMLS_PLUGIN_DIR . 'includes/site-backup/class-backup-delete.php';
    $deleteBackup = new TemplatesapredeleteBackup();
    $deleteBackup->templatespare_delete_backup_folder();

    require_once AFTMLS_PLUGIN_DIR . 'includes/site-backup/class-import-zip.php';
    require_once AFTMLS_PLUGIN_DIR . 'includes/site-backup/export.php';

    Templatespareimportzip::get_instance();
  }

  public function templatespare_exportFiles()
  {
    // Check user permissions
    if (!current_user_can('manage_options')) {
      return;
    }

    check_ajax_referer('templastespare_sitebackup_nonce', 'nonce');



    require_once ABSPATH . 'wp-admin/includes/file.php';
    WP_Filesystem();
    global $wp_filesystem;

    // Create backup directory
    $uploadDir = wp_upload_dir();
    $backupDir = $uploadDir['basedir'] . '/templatespare-backup/';
    if (!$wp_filesystem->exists($backupDir)) {
      $wp_filesystem->mkdir($backupDir);
    }

    // Create timestamped directory
    $yearDir = $backupDir . date('Y-m-d__H-i-s') . '/';
    if (!$wp_filesystem->exists($yearDir)) {
      $wp_filesystem->mkdir($yearDir);
    }

    // Create demo content directory
    $demoContentDir = $yearDir . 'demo-content/';
    if (!$wp_filesystem->exists($demoContentDir)) {
      $wp_filesystem->mkdir($demoContentDir);
    }
    templatespare_export_xml($demoContentDir);
    templatespare_export_wie($demoContentDir);
    templatespare_exportCustomizerData($demoContentDir);


    // Copy theme and plugin data
    $this->templatespare_copy_themes($yearDir);
    $this->templatespare_copy_plugins($yearDir);

    // Save configuration
    $config = $this->templatespare_get_atcive_plugin_theme_status();
    file_put_contents($yearDir . 'config.json', $config);

    // Send JSON response
    $notice_message = '<div class="updated"><p>' . esc_html__('Backup created successfully!', 'templatespare') . '</p></div>';
    wp_send_json_success(['success' => 'success', 'msg' => $notice_message]);

    exit;
  }

  public function get_folder_details()
  {


    global $wp_filesystem;
    $backupDir = wp_upload_dir()['basedir'] . '/templatespare-backup/';

    // Validate nonce
    if (!isset($_POST['nonce']) || !check_ajax_referer('templastespare_sitebackup_nonce', 'nonce', false)) {
      wp_send_json_error(['message' => 'Invalid nonce.'], 400);
      exit;
    }



    // Sanitize folder input
    $folder = isset($_POST['folder']) ? sanitize_text_field($_POST['folder']) : '';
    if (empty($folder)) {
      wp_send_json_error(['message' => 'Folder parameter is missing.'], 400);
      exit;
    }


    $folderPath = trailingslashit($backupDir) . $folder;

    if (!is_dir($folderPath)) {
      wp_send_json_error(['message' => 'Folder not found.'], 404);
      exit;
    }


    // Initialize WP Filesystem
    require_once ABSPATH . 'wp-admin/includes/file.php';
    if (!WP_Filesystem()) {
      wp_send_json_error(['message' => 'Failed to initialize WordPress Filesystem.'], 500);
      exit;
    }
    if ($wp_filesystem->is_dir($folderPath)) {

      $size = $this->get_folder_size($folderPath, $wp_filesystem);
      $formattedSize = $this->templatespare_get_format_size($size);
      wp_send_json_success(['size' => $formattedSize]);
    } else {
      wp_send_json_error(['message' => 'Folder not found.'], 404);
    }

    exit;
  }
  private function get_folder_size($folder, $wp_filesystem)
  {
    $size = 0;
    if (!$wp_filesystem->is_dir($folder)) {
      return 0;
    }
    $files = $wp_filesystem->dirlist($folder);
    if ($files) {
      foreach ($files as $file) {
        $file_path = trailingslashit($folder) . $file['name'];
        if ($file['type'] === 'f') {
          $size += $wp_filesystem->size($file_path);
        } elseif ($file['type'] === 'd') {
          $size += self::get_folder_size($file_path, $wp_filesystem);
        }
      }
    }
    return $size;
  }

  private function templatespare_get_format_size($size)
  {
    if ($size < 1024) {
      return $size . ' bytes';
    } elseif ($size < 1048576) {
      return round($size / 1024, 2) . ' KB';
    } elseif ($size < 1073741824) {
      return round($size / 1048576, 2) . ' MB';
    } else {
      return round($size / 1073741824, 2) . ' GB';
    }
  }

  private function templatespare_copy_themes($destinationDir)
  {
    $themeRoot = get_theme_root(); // Root directory of themes
    $destinationThemesDir = $destinationDir . 'theme/'; // Destination directory for themes

    // Get the active theme details
    $currentTheme = wp_get_theme();

    // Check if the active theme is a child theme
    if ($currentTheme->parent_theme) {
      // If it's a child theme, copy both the child and parent themes
      $childTheme = $currentTheme;
      $parentTheme = wp_get_theme($childTheme->parent_theme);

      // Copy the child theme
      $this->copy_single_theme($themeRoot, $destinationThemesDir, $currentTheme);

      // Copy the parent theme
      $this->copy_single_theme($themeRoot, $destinationThemesDir, $parentTheme);
    } else {

      // If it's not a child theme, copy only the active theme
      $this->copy_single_theme($themeRoot, $destinationThemesDir, $currentTheme);
    }
  }

  private function copy_single_theme($themeRoot, $destinationThemesDir, $theme)
  {
    $themeDir = $themeRoot . '/' . $theme->get_stylesheet(); // Theme directory path
    $destinationThemeDir = $destinationThemesDir . strtolower(basename($themeDir))  . '/'; // Unique directory based on name and stylesheet


    // Ensure the destination directory exists
    if (!is_dir($destinationThemeDir)) {
      mkdir($destinationThemeDir, 0755, true);
    }

    // Copy the theme directory
    $this->templatespare_copy_directory($themeDir, $destinationThemeDir);
  }




  private function templatespare_copy_plugins($destinationDir)
  {
    $pluginsDir = WP_PLUGIN_DIR;
    $destinationPluginsDir = $destinationDir . 'plugins/';

    // Get active plugins
    $activePlugins = get_option('active_plugins');

    if (!is_dir($destinationPluginsDir)) {
      mkdir($destinationPluginsDir, 0755, true);
    }

    foreach ($activePlugins as $pluginFile) {
      $pluginPath = dirname($pluginFile); // Get the folder name of the plugin
      $sourcePluginDir = $pluginsDir . '/' . $pluginPath;
      $destinationPluginDir = $destinationPluginsDir . $pluginPath;

      if ($pluginPath === 'templatespare') {
        continue;
      }

      if ($pluginPath === 'sg-security') {
        continue;
      }

      if ($pluginPath === 'siteground-email-marketing') {
        continue;
      }

      if ($pluginPath === 'siteground-migrator') {
        continue;
      }
      if ($pluginPath === 'sg-cachepress') {
        continue;
      }


      // Check if the plugin directory exists
      if (is_dir($sourcePluginDir)) {
        $this->templatespare_copy_directory($sourcePluginDir, $destinationPluginDir);
      }
    }
  }

  private function templatespare_copy_directory($source, $destination)
  {
    if (!is_dir($source)) {
      return false;
    }

    $dir = opendir($source);
    if (!is_dir($destination)) {
      mkdir($destination, 0755, true);
    }

    $exclude = ['node_modules', '.git', '.idea', '.DS_Store', 'Thumbs.db', 'templatespare', 'sg-security', 'siteground-email-marketing', 'siteground-migrator', 'sg-cachepress'];

    if ($source === WP_PLUGIN_DIR) {
      $exclude[] = 'index.php';
    }

    while (($file = readdir($dir)) !== false) {
      if ($file === '.' || $file === '..' || in_array($file, $exclude)) {
        continue;
      }

      $sourcePath = $source . '/' . $file;
      $destPath = $destination . '/' . $file;

      if (is_dir($sourcePath)) {
        $this->templatespare_copy_directory($sourcePath, $destPath);
      } else {
        copy($sourcePath, $destPath);
      }
    }

    closedir($dir);
  }

  private function templatespare_get_atcive_plugin_theme_status()
  {
    $active_theme = wp_get_theme();
    $active_plugins = get_option('active_plugins');

    $data = [
      'theme'   => [
        'slug'    => $active_theme->get_stylesheet(),
        'name'    => $active_theme->get('Name'),
        'version' => $active_theme->get('Version'),
        'author'  => $active_theme->get('Author'),
      ],
      'plugins' => [],
    ];

    foreach ($active_plugins as $plugin) {
      $plugin_data = get_plugin_data(WP_PLUGIN_DIR . '/' . $plugin);

      $data['plugins'][] = [
        'slug'    => dirname($plugin),
        'name'    => $plugin_data['Name'],
        'version' => $plugin_data['Version'],
        'author'  => $plugin_data['Author'],
      ];
    }

    $homepage_id = get_option('page_on_front');
    $homepage_title = get_the_title($homepage_id);
    // Get the slug of the current homepage
    $homepage_slug = get_post_field('post_name', $homepage_id);
    $data['homepage'] = [
      'id' => $homepage_id,
      'slug' => $homepage_slug,
      'title' => $homepage_title,

    ];

    $menu_locations = get_nav_menu_locations();

    if ($menu_locations) {
      $data['active_nav_menu'] = [];

      foreach ($menu_locations as $key => $val) {
        // Add items to the array in the desired format
        $data['active_nav_menu'][] =  $key;
      }
    }



    return json_encode($data);
  }
}

// Initialize the Backup class
TemplatesapreBackupSite::get_instance();
