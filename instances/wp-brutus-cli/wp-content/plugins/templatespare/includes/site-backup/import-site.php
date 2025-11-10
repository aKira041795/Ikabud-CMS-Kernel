<?php
wp_enqueue_script('templatespare_fileimport_script');
?>
<div class="wrap">

  <h1><?php echo esc_html('Site Import Manager', 'templatespare') ?></h1>
  <div id="templatespare-config"></div>
  <div id="templatespare-error"></div>
  <p><?php esc_html_e("Easily import and restore your site from a TemplateSpare export. Simply upload your file to replicate your site's themes, plugins, and settings on a new environment.", 'templatespare'); ?></p>

  <h2><?php esc_html_e('Import Your Site', 'templatespare'); ?></h2>

  <div class="templatespare-demo-drop">
    <form method="post" enctype="multipart/form-data" id="templatespare-upload-file">
      <div class="templatespare-drop-zone">
        <p class="templatespare-drop-zone-text"><?php echo esc_html('Drag and drop your export file here, or click to select one from your computer.', 'templatespare') ?></p>
        <input type="file" name="templatespare_import_file" class="templatespare_import_file" required />
        <p class="file-name" style="display: none;"></p>
      </div>
      <!-- <div class="templatespare-check-wrap">
        <input type="checkbox" name="templatespare_import_all_content" value="all" id="templatespare_import_all_content" checked="checked">
        <label for="templatespare_import_all_content"><?php //echo esc_html("Import Demo Content (Posts, Pages, Menus)", "temmplatespare"); 
                                                      ?></label>
        <p class="templatespare-drop-zone-text"><?php //echo esc_html('Adds sample posts, pages, and menus. Your current content won\'t be changed. Design and widgets will be added either way.', 'templatespare') 
                                                ?></p>
      </div> -->

      <button type="submit" name="nbm_migrate" id="templatespare_importBtn" class="button button-primary templatespare_import_zip">
        <span class="dashicons dashicons-database-import"></span>
        <?php echo esc_html('Import Demo', 'templatespare') ?>
      </button>
    </form>

    <div class="importing" style="display: none;">
      <div class="templatespare-initial-msg">
        <h2><?php echo esc_html('Site is importing. Please wait.', 'templatespare') ?></h2>
        <p><?php echo esc_html('It takes a few seconds', 'templatespare') ?></p>
      </div>
      <img class="templatespare-loader-img"
        src="<?php echo esc_url(AFTMLS_PLUGIN_URL . 'assets/images/importing.svg'); ?>"
        alt="<?php echo esc_attr__('Importing...', 'templatespare'); ?>">
      <div class="templatespare-success-img" style="display: none;">
        <p><?php echo esc_html__('Site Imported is successful', 'templatespare'); ?></p>
        <img src="<?php echo esc_url(AFTMLS_PLUGIN_URL . 'assets/images/success.svg'); ?>" alt="<?php echo esc_attr__('success...', 'templatespare'); ?>">
      </div>
    </div>
    <div class="templatespare-import-kit-popup-wrap" style="display: none;">
      <div class="overlay"></div>
      <div class="templatespare-import-kit-popup">
        <div class="content">
          <div class="progress-wrap">
            <div class="progress-bar-container">
              <div class="progress-bar" style="display:none;">
                <strong></strong>
              </div>
            </div>
            <strong></strong>
          </div>
        </div>
      </div>
    </div>
  </div>

  <h3><?php esc_html_e('How it works', 'templatespare'); ?></h3>
  <p><?php esc_html_e("For a smooth import, ensure that you are uploading a TemplateSpare export file. It should include your themes, plugins, demo data, and configuration settings.", 'templatespare'); ?></p>

  <p><?php esc_html_e("Need an export file? Visit the ", 'templatespare'); ?>
    <a href="<?php echo esc_url(admin_url('admin.php?page=templatespare-site-backup')); ?>"><?php esc_html_e("Export Dashboard", 'templatespare'); ?></a>
    <?php esc_html_e("to generate one.", 'templatespare'); ?>
  </p>

</div>