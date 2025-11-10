<?php
if (!defined('ABSPATH')) {
  exit; // Exit if accessed directly
}

class TemplatesapreDownloadZip
{
  private static $instance = null;

  public function __construct()
  {
    // Here you can check the user's roles or capabilities if needed
    if (!current_user_can('export')) { // Replace 'download_files' with a suitable capability if needed
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
  public function templatespareDownloadAsZip()
  {

    if (!isset($_GET['download_folder']) || empty($_GET['download_folder'])) {
      return;
    }
    if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'download_folder_' . $_GET['download_folder'])) {
      return;
    }

    $folder = isset($_GET['download_folder']) ? sanitize_text_field($_GET['download_folder']) : '';

    if (empty($folder)) {
      return; // Return early if the folder is empty
    }
    $uploadDir = wp_upload_dir();
    $backupDir = $uploadDir['basedir'] . '/templatespare-backup/';

    $folder_name = basename($folder); // Sanitize folder name to prevent directory traversal
    $dir = $backupDir . $folder_name;

    // Check if the directory exists
    if (!is_dir($dir)) {
      wp_die('Directory does not exist.');
    }

    $zipFileName = 'backup_' . $folder_name . '.zip';
    $zipFilePath = $backupDir . $zipFileName;

    $zip = new ZipArchive();
    if ($zip->open($zipFilePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
      wp_die('Cannot open zip file.');
    }

    //$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
      if ($file->isFile()) {
        $filePath = $file->getRealPath();
        $relativePath = $folder_name . '/' . str_replace(DIRECTORY_SEPARATOR, '/', substr($filePath, strlen($dir) + 1));
        $zip->addFile($filePath, $relativePath);
      }
    }

    $zip->close();

    // Set headers and download the file
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $zipFileName . '"');
    header('Content-Length: ' . filesize($zipFilePath));

    // Read and output the file content
    readfile($zipFilePath);

    // Optionally delete the zip file after download
    unlink($zipFilePath);

    exit;
  }
}
TemplatesapreDownloadZip::get_instance();
