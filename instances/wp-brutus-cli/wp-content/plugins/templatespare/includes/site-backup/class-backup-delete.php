<?php
if (!defined('ABSPATH')) {
  exit; // Exit if accessed directly
}

class TemplatesapredeleteBackup
{
  private static $instance = null;

  public function __construct()
  {
    // Here you can check the user's roles or capabilities if needed
    if (!current_user_can('delete_site')) { // Replace 'download_files' with a suitable capability if needed
      return;
    }
  }

  public static function get_instance()
  {
    if (self::$instance === null) {
      self::$instance = new self();
    }

    return self::$instance;
  }

  public function templatespare_delete_backup_folder()
  {
    if (!isset($_GET['delete_folder']) || empty($_GET['delete_folder'])) {
      return;
    }
    if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'delete_folder_' . $_GET['delete_folder'])) {
      return;
    }




    $folder_name = sanitize_text_field($_GET['delete_folder']); // Sanitize input
    $uploadDir = wp_upload_dir();
    $backupDir = $uploadDir['basedir'] . '/templatespare-backup/';
    $dir = $backupDir . $folder_name;

    if (is_dir($dir)) {
      // Recursively delete the directory
      if ($this->templatespare_delete_directory($dir)) {

        wp_redirect(admin_url('admin.php?page=templatespare-site-backup&message=deleted')); // Redirect to prevent resubmission
      }
    } else {
      echo '<div class="error notice"><p>Folder not found.</p></div>';
    }
  }

  public function templatespare_delete_directory($dir)
  {
    require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
    require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';

    if (!file_exists($dir)) {
      return true;
    }

    // finally, you can call the 'delete' function on the selected class,
    // which is now stored in the global '$wp_filesystem'
    $fileSystemDirect = new WP_Filesystem_Direct(false);
    $fileSystemDirect->rmdir($dir, true);
    return true;
    // return rmdir($dir); // Remove the directory itself
  }
}
TemplatesapredeleteBackup::get_instance();
