<?php
global $wp_filesystem;
if (empty($wp_filesystem)) {
  require_once ABSPATH . 'wp-admin/includes/file.php';
  WP_Filesystem();
}


$year = gmdate('Y');
$month = gmdate('m');
$uploadDir = wp_upload_dir();
$backupDir = $uploadDir['basedir'] . '/templatespare-backup/';

// Pagination setup
$per_page = 10;
$paged = isset($_GET['paged']) ? absint($_GET['paged']) : 1;

$folders = templatespare_get_folders($backupDir);

// Sort folders by parsed timestamp in descending order
usort($folders, function ($a, $b) {
  return strcmp(templatespare_parse_timestamp($b), templatespare_parse_timestamp($a));
});

// Paginate folders
$total_items = count($folders);

$folders = array_slice($folders, ($paged - 1) * $per_page, $per_page);

function templatespare_get_folders($dir)
{
  $folders = glob($dir . '*');
  return array_map('basename', $folders);
}

function templatespare_parse_timestamp($folder)
{
  $parts = explode('__', $folder);
  if (count($parts) === 2) {
    $date = str_replace('-', '', $parts[0]);
    $time = str_replace('-', '', $parts[1]);
    return $date . $time;
  }
  return '';
}

function convert_folder_name_to_datetime($folder_name)
{
  $parts = explode('__', $folder_name);
  if (count($parts) === 2) {
    $date = $parts[0];
    $time = $parts[1];
    $datetime = DateTime::createFromFormat('Y-m-d H-i-s', $date . ' ' . $time);
    if ($datetime) {
      return $datetime->format('F j, Y, g:i');
    }
  }
}

if (isset($_POST['templastespare_sitebackup'])) {


  // Logic for creating a backup (customize based on your requirements)
  $backup_name =  gmdate('Y-m-d__H-i-s');
  $backup_path = trailingslashit($backupDir) . $backup_name;

  // Add code to copy themes/plugins/files into the backup folder here.
  echo '<div class="updated"><p>' . esc_html__('Backup created successfully!', 'templatespare') . '</p></div>';
}

if (!class_exists('ZipArchive')) {
  echo '<div class="error">
      <p>' . esc_html__('The following extensions are required for Downloadzip to function correctly:', 'templatespare') . '</p>
      <p>' . sprintf(
    '<a href="https://www.php.net/manual/en/book.zip.php">%s</a>',
    esc_html__('extension-zip', 'templatespare')
  ) . '</p>
      <p>' . esc_html__('Please contact your hosting company for support.', 'templatespare') . '</p>
  </div>';
}

?>

<div class="wrap">
  <h1 class="wp-heading-inline"><?php esc_html_e('Site Export Manager', 'templatespare'); ?></h1>
  <p><?php esc_html_e('Export your entire site, including themes, plugins, and settings, for easy migration or recovery.
View and manage your recent exports below.', 'templatespare'); ?> <?php esc_html_e("Once you generate your export file, you can use it in the ", 'templatespare'); ?>
<a href="<?php echo esc_url(admin_url('admin.php?page=templatespare-site-import')); ?>"><?php esc_html_e("Import Dashboard", 'templatespare'); ?></a> 
<?php esc_html_e(" to restore or migrate your site.", 'templatespare'); ?></p>
  <h2><?php esc_html_e('Recent Exports', 'templatespare'); ?></h2>
  <hr class="wp-header-end">

  <!-- Table for listing backups -->

  <table class="wp-list-table widefat fixed striped table-view-list pages">
    <thead>
      <tr>
        <th scope="col" id="created" class="manage-column column-id"><?php esc_html_e('Created', 'templatespare') ?></th>
        <th scope="col" id="size" class="manage-column column-size"><?php esc_html_e('Size', 'templatespare') ?></th>
        <th scope="col" id="backup-name" class="manage-column column-backup-name"><?php esc_html_e('Export Name', 'templatespare') ?></th>
        <th scope="col" id="actions" class="manage-column column-actions"><?php esc_html_e('Actions', 'templatespare') ?></th>
      </tr>
    </thead>
    <tbody id="the-list">
      <?php if (!empty($folders)) {
        foreach ($folders as $res) {
          $link = add_query_arg([
            'page' => 'templatespare-site-backup',
            'download_folder' => $res,
          ], admin_url('admin.php'));
          $link = wp_nonce_url($link, 'download_folder_' . $res, '_wpnonce');

          $delete_url = add_query_arg([
            'delete_folder' => $res,
            'page' => 'templatespare-site-backup',
          ], admin_url('admin.php'));
          $delete_url = wp_nonce_url($delete_url, 'delete_folder_' . $res, '_wpnonce');

          $formattedDatetime = convert_folder_name_to_datetime($res);
      ?>
          <tr>
            <td class="column-backup-name column-backup-date"><?php echo esc_html($formattedDatetime); ?></td>
            <td class="column-id load-folder-size" data-folder="<?php echo esc_attr($res); ?>">Loading...</td>
            <td class="column-backup-name"><?php echo esc_html($res); ?></td>
            <td class="column-actions">
              <?php if (class_exists('ZipArchive')) { ?>
                <a href="<?php echo esc_url($link); ?>" class="button button-primary ">
                  <i class="dashicons dashicons-download"></i>
                  <span><?php esc_html_e('Download', 'templatespare'); ?></span>
                </a>
              <?php } ?>
              <a href="<?php echo esc_url($delete_url); ?>" class="button button-danger" onclick="return confirm('<?php esc_html_e('Are you sure you want to delete this folder?', 'templatespare'); ?>')">
                <i class="dashicons dashicons-trash"></i>
                <?php esc_html_e('Delete', 'templatespare'); ?>
              </a>
            </td>
          </tr>
      <?php }
      } ?>
    </tbody>
  </table>

  

  <!-- Form for creating backup -->
  <div class="tablenav bottom">

    <!-- Form for creating backup -->
    <div class=" alignleft">
      <form method="post">
        <!-- <h3><?php //esc_html_e('Select Backup Type:', 'templatespare'); 
                  ?></h3>
        <fieldset>
          <label>
            <input type="radio" name="backup_type" value="all" checked>
            <?php //esc_html_e('All Content', 'templatespare'); 
            ?>
          </label><br>
          <label>
            <input type="radio" name="backup_type" value="xml">
            <?php //esc_html_e('XML', 'templatespare'); 
            ?>
          </label><br>
          <label>
            <input type="radio" name="backup_type" value="widgets">
            <?php //esc_html_e('Widgets', 'templatespare'); 
            ?>
          </label><br>
          <label>
            <input type="radio" name="backup_type" value="customizer">
            <?php //esc_html_e('Customizer', 'templatespare'); 
            ?>
          </label>
        </fieldset> -->
        <button type="submit" name="templastespare_sitebackup" class="button button-primary templastespare_sitebackup"
          value="Backup Now">
          <span class="dashicons dashicons-plus-alt"></span>
          <?php echo esc_html('Export your site ', 'templatespare'); ?>
        </button>
      </form>
    </div>
    <?php
    $total_pages = ceil($total_items / $per_page);

    if ($total_pages > 1) {
      $current_page = max(1, $paged);

      // Generate the pagination links using WordPress paginate_links
      $pagination = paginate_links([
        'base'         => add_query_arg('paged', '%#%'),
        'format'       => '?paged=%#%',
        'total'        => $total_pages,
        'current'      => $current_page,
        'show_all'     => false,
        'end_size'     => 3,
        'mid_size'     => 2,
        'prev_text'    => __('« Prev', 'templatespare'),
        'next_text'    => __('Next »', 'templatespare'),
        'type'         => 'grid',
      ]);

      if ($pagination) { ?>
        <div class="tablenav-pages">
          <span class="displaying-num"><?php echo esc_html($total_pages); ?> items</span>
          <span class="pagination-links">
            <?php echo wp_kses_post($pagination); ?>
          </span>
        </div>
    <?php  }
    }
    ?>
  </div>

  

</div>
<?php
