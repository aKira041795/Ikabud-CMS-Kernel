<?php
// Exit if accessed directly.
if (!defined('ABSPATH')) {
  exit;
}

if (!class_exists('AFTMLS_Templates_Importer')) {

  class AFTMLS_Templates_Importer
  {

    protected static $instance = null;

    /**
     * Initialize the plugin.
     */

    private $plugin_page_setup = array();
    private $plugin_page;
    public static function get_instance()
    {
      // If the single instance hasn't been set, set it now.
      if (null == self::$instance) {
        self::$instance = new self;
      }

      return self::$instance;
    }

    public function __construct()
    {
      $test = get_option('templatespare_wizard_category_value', false);

      add_action('admin_menu', array($this, 'templatespare_register_menu_info_page'));
      add_action('admin_enqueue_scripts', array($this, 'templatespare_dashboard_assets'));
      add_action('init', array($this, 'templatespare_load_files'));
      add_action('rest_api_init', array($this, 'templatespare_register_plugins_routes'));
      add_action('admin_init', array($this, 'templatespare_display_wizard'));
      add_filter('plugin_row_meta', [$this, 'plugin_row_meta'], 10, 2);

      add_filter('plugin_action_links_' . AFTMLS_PLUGIN_BASE, [$this, 'plugin_action_links']);
    }

    public function plugin_action_links($links)
    {
      $settings_link = sprintf('<a href="%1$s">%2$s</a>', admin_url('admin.php?page=templatespare-main-dashboard'), esc_html__('Starter Sites', 'templatespare'));

      array_unshift($links, $settings_link);

      $links['aftpro'] = sprintf('<a href="%1$s" target="_blank" class="templatespare-pro-link">%2$s</a>', 'https://afthemes.com/all-themes-plan/', esc_html__('Access All Sites', 'templatespare'));

      return $links;
    }
    public function plugin_row_meta($plugin_meta, $plugin_file)
    {
      if (AFTMLS_PLUGIN_BASE === $plugin_file) {
        $row_meta = [
          'starter' => '<a href="https://www.templatespare.com/" aria-label="' . esc_attr(esc_html__('View Starter Websites', 'templatespare')) . '" target="_blank">' . esc_html__('Demos', 'templatespare') . '</a>',
          'docs' => '<a href="https://docs.afthemes.com/" aria-label="' . esc_attr(esc_html__('View Documentation', 'templatespare')) . '" target="_blank">' . esc_html__('Docs', 'templatespare') . '</a>',
          'ideo' => '<a href="https://www.youtube.com/@wpafthemes/" aria-label="' . esc_attr(esc_html__('View Videos Tutorials', 'templatespare')) . '" target="_blank">' . esc_html__('Videos', 'templatespare') . '</a>',
        ];

        $plugin_meta = array_merge($plugin_meta, $row_meta);
      }

      return $plugin_meta;
    }

    public function templatespare_register_menu_info_page()
    {

      $this->plugin_page_setup = apply_filters('templatespare/plugin_page_setup', array(
        'parent_slug' => 'admin.php',
        'page_title' => esc_html__('TemplateSpare', 'templatespare'),
        'menu_title' => esc_html__('TemplateSpare', 'templatespare'),
        'capability' => 'import',
        'menu_slug' => 'templatespare-main-dashboard',
      ));

      $svg_logo = '<svg id="Layer_1" data-name="Layer 1" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" viewBox="0 0 739.27 746.51"><defs><linearGradient id="linear-gradient" x1="581.22" y1="477.99" x2="158.47" y2="55.24" gradientUnits="userSpaceOnUse"><stop offset="0" stop-color="#154DE9"/><stop offset="0.17" stop-color="#1551EA"/><stop offset="0.34" stop-color="#135DEB"/><stop offset="0.52" stop-color="#1271EF"/><stop offset="0.69" stop-color="#0F8DF3"/><stop offset="0.86" stop-color="#0BB1F8"/><stop offset="1" stop-color="#08D5FE"/></linearGradient><linearGradient id="linear-gradient-2" x1="488.86" y1="491.66" x2="250.83" y2="253.63" xlink:href="#linear-gradient"/><linearGradient id="linear-gradient-3" x1="542.34" y1="652.09" x2="197.57" y2="307.32" xlink:href="#linear-gradient"/><linearGradient id="linear-gradient-4" x1="315.32" y1="552.41" x2="683.63" y2="552.41" gradientUnits="userSpaceOnUse"><stop offset="0" stop-color="#fff"/><stop offset="1" stop-color="#1143CE"/></linearGradient><linearGradient id="linear-gradient-5" x1="491.29" y1="581.15" x2="690.57" y2="381.88" gradientTransform="matrix(-1, 0, 0, 1, 1052.45, 51.68)" gradientUnits="userSpaceOnUse"><stop offset="0"/><stop offset="1" stop-color="#21A1F5" stop-opacity="0"/></linearGradient><linearGradient id="linear-gradient-6" x1="618.14" y1="831.27" x2="380.07" y2="593.2" gradientTransform="translate(-129.37 -125.55)" xlink:href="#linear-gradient"/><linearGradient id="linear-gradient-7" x1="683.63" y1="712.5" x2="500.37" y2="712.5" gradientUnits="userSpaceOnUse"><stop offset="0" stop-color="#2383EE"/><stop offset="1" stop-color="#2381ED"/></linearGradient><linearGradient id="linear-gradient-8" x1="315.32" y1="712.41" x2="499.62" y2="712.41" gradientUnits="userSpaceOnUse"><stop offset="0" stop-color="#fff"/><stop offset="1" stop-color="#1760EC"/></linearGradient><linearGradient id="linear-gradient-9" x1="315.29" y1="392.25" x2="498.62" y2="392.25" gradientUnits="userSpaceOnUse"><stop offset="0" stop-color="#fff"/><stop offset="1" stop-color="#22AEF5"/></linearGradient><linearGradient id="linear-gradient-10" x1="307.5" y1="185.4" x2="506.77" y2="-13.88" gradientTransform="matrix(1, 0, 0, -1, -129.37, 724.67)" gradientUnits="userSpaceOnUse"><stop offset="0" stop-color="#fff"/><stop offset="0.08" stop-color="#D1EBFD" stop-opacity="0.79"/><stop offset="0.17" stop-color="#A8DAFB" stop-opacity="0.61"/><stop offset="0.26" stop-color="#84CBF9" stop-opacity="0.44"/><stop offset="0.35" stop-color="#65BEF8" stop-opacity="0.31"/><stop offset="0.45" stop-color="#4CB3F7" stop-opacity="0.19"/><stop offset="0.56" stop-color="#39ABF6" stop-opacity="0.11"/><stop offset="0.68" stop-color="#2BA5F5" stop-opacity="0.05"/><stop offset="0.81" stop-color="#23A2F5" stop-opacity="0.01"/><stop offset="1" stop-color="#21A1F5" stop-opacity="0"/></linearGradient><linearGradient id="linear-gradient-11" x1="326.69" y1="418.3" x2="670.19" y2="74.8" xlink:href="#linear-gradient-10"/><linearGradient id="linear-gradient-12" x1="69" y1="94.5" x2="300.13" y2="227.94" gradientTransform="matrix(1, 0, 0, 1, 0, 0)" xlink:href="#linear-gradient-10"/><linearGradient id="linear-gradient-13" x1="131.42" y1="233.25" x2="499.5" y2="233.25" gradientTransform="matrix(1, 0, 0, 1, -130.5, -125.1)" gradientUnits="userSpaceOnUse"><stop offset="0" stop-color="#fff"/><stop offset="1"/></linearGradient><linearGradient id="linear-gradient-14" x1="131.29" y1="232.75" x2="498.63" y2="232.75" gradientUnits="userSpaceOnUse"><stop offset="0" stop-color="#fff"/><stop offset="1" stop-color="#22ABF5"/></linearGradient></defs><polygon points="369.84 0 0.41 213.29 0.41 319.93 369.84 106.64 739.27 319.93 739.27 213.29 369.84 0" fill="url(#linear-gradient)"/><polygon points="369.84 212.68 185.13 319.32 185.13 425.97 369.84 319.32 554.56 425.97 554.56 319.32 369.84 212.68" fill="url(#linear-gradient-2)"/><polygon points="185.13 319.76 554.56 533.22 554.56 639.87 185.13 425.97 185.13 319.76" fill="url(#linear-gradient-3)"/><path d="M315.06,446l9.9,5.76,26.73,15.55,39.74,23.13,48.2,28.05,53.1,30.9,53.28,31,49.83,29,42.1,24.49,30.19,17.57c4.79,2.79,9.56,5.64,14.39,8.37l.6.36a.75.75,0,0,0,.76-1.3l-9.9-5.76-26.73-15.55-39.74-23.13-48.2-28-53.1-30.9-53.28-31-49.82-29L361,471l-30.2-17.57c-4.79-2.79-9.56-5.63-14.38-8.37l-.61-.36a.75.75,0,0,0-.76,1.3Z" transform="translate(-129.37 -125.55)" fill="url(#linear-gradient-4)"/><polygon points="554.2 639.79 553.79 532.79 368.89 426.55 368.88 533.22 554.2 639.79" fill="url(#linear-gradient-5)"/><polygon points="554.56 533.72 369.84 639.87 185.13 533.22 185.13 639.87 369.84 746.51 554.56 639.93 554.56 533.72" fill="url(#linear-gradient-6)"/><path d="M500.88,766.15l4.9-2.84,13.33-7.73,19.73-11.42,23.95-13.87L589.13,715l26.59-15.41,24.7-14.3,21-12.14,15.07-8.73c2.37-1.37,4.78-2.7,7.13-4.13l.3-.17a.75.75,0,0,0-.76-1.3l-4.9,2.84-13.33,7.73-19.73,11.42-23.95,13.87L594.87,710l-26.59,15.41-24.7,14.3-21,12.14-15.07,8.73c-2.37,1.37-4.78,2.7-7.13,4.13l-.3.17a.75.75,0,0,0,.76,1.3Z" transform="translate(-129.37 -125.55)" fill="url(#linear-gradient-7)"/><path d="M315.06,660l4.93,2.84,13.39,7.73L353.19,682l24.18,13.94,26.47,15.28,26.73,15.41,24.82,14.32,21.15,12.2,15.08,8.7c2.4,1.38,4.77,2.82,7.19,4.15l.31.18a.75.75,0,0,0,.76-1.3L495,762l-13.4-7.72-19.81-11.43-24.18-14L411.1,713.64l-26.73-15.42L359.55,683.9,338.4,671.7,323.32,663c-2.39-1.38-4.77-2.81-7.19-4.15l-.31-.18a.75.75,0,0,0-.76,1.3Z" transform="translate(-129.37 -125.55)" fill="url(#linear-gradient-8)"/><path d="M315.79,445.65l4.91-2.83,13.3-7.66,19.73-11.37,24-13.82,26.38-15.2,26.62-15.34,24.72-14.25,20.91-12,15.09-8.7c2.38-1.37,4.8-2.69,7.15-4.12l.3-.17a.75.75,0,0,0-.76-1.3l-4.91,2.83-13.29,7.66-19.74,11.37-24,13.82-26.37,15.2L383.2,405.07l-24.72,14.25-20.9,12-15.1,8.7c-2.38,1.37-4.79,2.7-7.14,4.12l-.3.17a.75.75,0,0,0,.75,1.3Z" transform="translate(-129.37 -125.55)" fill="url(#linear-gradient-9)"/><polygon points="185.09 532.31 185.5 639.31 370.4 745.55 370.41 638.89 185.09 532.31" fill="url(#linear-gradient-10)"/><polygon points="184.71 318.97 185.13 425.97 553.21 637.47 554.21 531.47 184.71 318.97" fill="url(#linear-gradient-11)"/><polygon points="0 320.29 0.41 213.29 368.5 1.79 369.5 107.79 0 320.29" fill="url(#linear-gradient-12)"/><line x1="2.05" y1="213.45" x2="369.13" y2="0.95" fill="url(#linear-gradient-13)"/><path d="M131.79,339.65l9.9-5.73,26.71-15.46,39.45-22.84L256,267.77l53-30.7,53.19-30.79,49.61-28.72,41.93-24.27L484,135.74c4.75-2.74,9.53-5.44,14.24-8.24l.61-.35a.75.75,0,0,0-.76-1.3l-9.89,5.73L461.52,147l-39.46,22.84L374,197.74l-53,30.69-53.2,30.79-49.6,28.72L176.2,312.21l-30.32,17.55c-4.74,2.75-9.52,5.44-14.24,8.24l-.6.35a.75.75,0,0,0,.75,1.3Z" transform="translate(-129.37 -125.55)" fill="url(#linear-gradient-14)"/></svg>';

      //$svg = file_get_contents( AFTMLS_PLUGIN_URL .'assets/images/logo.svg' );

      // Main Plugin Dashboard Page
      $this->plugin_page = add_menu_page(
        $this->plugin_page_setup['page_title'],
        $this->plugin_page_setup['menu_title'],
        $this->plugin_page_setup['capability'],
        $this->plugin_page_setup['menu_slug'],
        apply_filters('templatespare/plugin_page_display_callback_function', [$this, 'templatespare_render_page']),
        'data:image/svg+xml;base64,' . base64_encode($svg_logo),
        60
      );

      // Submenu: Starter Sites
      add_submenu_page(
        'templatespare-main-dashboard',
        esc_html__('Import Sites', 'templatespare'),
        esc_html__('Import Sites', 'templatespare'),
        'import',
        'templatespare-main-dashboard'
      );

      // Submenu: Get Started (Wizard)
      add_submenu_page(
        'templatespare-main-dashboard',
        esc_html__('Get Started', 'templatespare'),
        esc_html__('Get Started', 'templatespare'),
        'manage_options',
        'wizard-page',
        [$this, 'templatespare_display_wizard']
      );

      // Conditional Submenu: Elementor Kits (Visible only if Elementor and Elespare plugins are active)

      add_submenu_page(
        'templatespare-main-dashboard',
        esc_html__('Elementor Kits', 'templatespare'),
        esc_html__('Elementor Kits', 'templatespare'),
        'manage_options',
        'elespare-dashboard',
        [$this, 'elespare_demo_import_callback']
      );

      // Conditional Submenu: Block Patterns (Visible only if BlockSpare plugin is active)

      add_submenu_page(
        'templatespare-main-dashboard',
        esc_html__('Block Patterns', 'templatespare'),
        esc_html__('Block Patterns', 'templatespare'),
        'manage_options',
        'blockspare-dashboard',
        [$this, 'blockspare_demo_import_callback']
      );


      


      // Submenu: Export Site
      add_submenu_page(
        'templatespare-main-dashboard',
        esc_html__('Export Site', 'templatespare'),
        esc_html__('Export Site', 'templatespare'),
        'manage_options',
        'templatespare-site-backup',
        [$this, 'temmplatespareRenderBackupPage']
      );

      // Submenu: Import Site
      add_submenu_page(
        'templatespare-main-dashboard',
        esc_html__('Import Site', 'templatespare'),
        esc_html__('Import Site', 'templatespare'),
        'manage_options',
        'templatespare-site-import',
        [$this, 'temmplatespareRenderImportPage']
      );


      // External Link: Make Website
      add_submenu_page(
        'templatespare-main-dashboard',
        esc_html__('Hosting', 'templatespare'),
        esc_html__('Hosting', 'templatespare'),
        'manage_options',
        esc_url('https://afthemes.com/hosting/')
      );

      // External Link: All Themes
      add_submenu_page(
        'templatespare-main-dashboard',
        esc_html__('All Themes ', 'templatespare'),
        esc_html__('All Themes ', 'templatespare'),
        'manage_options',
        esc_url('https://afthemes.com/all-themes-plan/')
      );
      


      register_importer($this->plugin_page_setup['menu_slug'], $this->plugin_page_setup['page_title'], $this->plugin_page_setup['menu_title'], apply_filters('templatespare/plugin_page_display_callback_function', array($this, 'templatespare_render_page')));
    }

    public function templatespare_render_page()
    { ?>
      <div id="templatespare-template-collcetion-dashboard"></div>
      <?php }

    public function templatespare_display_wizard()
    {

      $screen = isset($_GET['page']) ? $_GET['page'] : '';

      if ($screen == 'wizard-page') {

        $step = (int) get_option('templatespare_wizard_next_step', 0);
        if (!is_user_logged_in() || !current_user_can('administrator') || $step == 4) {
          return;
        }
        include_once AFTMLS_BASE_DIR . '/includes/wizard/templates/wizard_template.php';
        exit;
      }
    }

    public function blockspare_demo_import_callback()
    {
      wp_enqueue_script('aftmls-plugin-installl-activation-script');
      $templatespare_blockspare_installed = templatespare_get_plugin_file('blockspare-pro');
      $install = [];
      $activate = [];
      $templatespare_blockspare_verison = '';
      $templatespare_check_blockspare = $this->templatespare_check_blockspare_free_pro_activated();
      $templatespare_blockspare_status = 'free';
      if (!empty($templatespare_blockspare_installed) && $templatespare_check_blockspare == 'pro') {
        $templatespare_blockspare_status = 'pro';
        $templatespare_blockspare_old_version = '4.1.3';
        $templatespare_blockspare_pro_info = get_plugin_data(WP_PLUGIN_DIR . '/' . $templatespare_blockspare_installed);
        $templatespare_blockspare_verison = $templatespare_blockspare_pro_info['Version'];
        $templatespare_blockspare_active = is_plugin_active($templatespare_blockspare_installed);

        if ($templatespare_blockspare_active == false && $templatespare_blockspare_installed != null) {
          $activate[] = 'blockspare-pro';
        }
      } else {

        $templatespare_blockspare_installed = templatespare_get_plugin_file('blockspare');
        $templatespare_blockspare_old_version = '3.1.0';

        if (!empty($templatespare_blockspare_installed)) {
          $templatespare_blockspare_info = get_plugin_data(WP_PLUGIN_DIR . '/' . $templatespare_blockspare_installed);
          $templatespare_blockspare_verison = $templatespare_blockspare_info['Version'];
          $templatespare_blockspare_active = is_plugin_active('blockspare/blockspare.php');

          if ($templatespare_blockspare_active == false && $templatespare_blockspare_installed != null) {
            $activate = ['blockspare'];
          }
        } else {
          if ($templatespare_blockspare_installed == null) {
            $install = ['blockspare'];
          }
        }

        $plugin_update = 'false';
        if (!empty($templatespare_blockspare_verison) && $templatespare_blockspare_verison < $templatespare_blockspare_old_version) {
          $plugin_update = 'true';
        }
        if (($templatespare_blockspare_installed && $templatespare_blockspare_active) && $plugin_update == 'false') {
      ?>
          <div id="bs-dashboard"></div>
        <?php } else {
          if (!empty($templatespare_blockspare_verison) && $templatespare_blockspare_active && $templatespare_blockspare_verison < $templatespare_blockspare_old_version) {
            $class = admin_url('plugins.php');

            $message = sprintf(
              __('Blockspare plugin version should be more than %s.', 'templatespare'),
              $templatespare_blockspare_old_version
            );
          } else {
            $class = 'false';
            $message = __('One-click Demo Import, Block Editor Ready, No Code Required! Built with Blockspare.', 'templatespare');
          }
        }
        ?>
        <div id="templatespare-plugins-install-activate" data-class="<?php echo $class; ?>" current-theme='blockspare'
          install=<?php echo json_encode($install); ?> activate=<?php echo json_encode($activate); ?> data-plugin-page="blockspare-dashboard"
          message='<?php echo $message; ?>' isPro='<?php echo esc_attr($templatespare_blockspare_status); ?>'></div>
<?php }
    }

    public function elespare_demo_import_callback()
    {
      wp_enqueue_script('aftmls-plugin-installl-activation-script');
      $install = [];
      $activate = [];
      $templatespare_elespare_installed = templatespare_get_plugin_file('elespare-pro');
      $templatespare_elementor_pro_installed = templatespare_get_plugin_file('elementor-pro');
      $templatespare_elementor_installed = templatespare_get_plugin_file('elementor');

      if ($templatespare_elementor_pro_installed) {
        $activate[] = 'elementor-pro';
      }
      if ($templatespare_elementor_installed) {
        $activate[] = 'elementor';
      } else {
        $install[] = 'elementor';
      }

      $templatespare_elespare_verison = '';
      $templatespare_check_elespare = $this->templatespare_check_elespare_free_pro_activated();
      $templatespare_elespare_status = 'free';
      if (!empty($templatespare_elespare_installed) && $templatespare_check_elespare == 'pro') {
        $templatespare_elespare_status = 'pro';
        $templatespare_elespare_old_version = '2.5.0';
        $templatespare_elespare_pro_info = get_plugin_data(WP_PLUGIN_DIR . '/' . $templatespare_elespare_installed);
        $templatespare_elespare_verison = $templatespare_elespare_pro_info['Version'];
        $templatespare_elespare_active = is_plugin_active($templatespare_elespare_installed);

        if ($templatespare_elespare_active == false && $templatespare_elespare_installed != null) {
          $activate[] = 'elespare-pro';
        }
      } else {

        $templatespare_elespare_installed = templatespare_get_plugin_file('elespare');
        $templatespare_elespare_old_version = '3.1.0';

        if (!empty($templatespare_elespare_installed)) {
          $templatespare_elespare_info = get_plugin_data(WP_PLUGIN_DIR . '/' . $templatespare_elespare_installed);
          $templatespare_elespare_verison = $templatespare_elespare_info['Version'];
          $templatespare_elespare_active = is_plugin_active('elespare/elespare.php');

          if ($templatespare_elespare_active == false && $templatespare_elespare_installed != null) {
            $activate[] = 'elespare';
          }
        } else {
          if ($templatespare_elespare_installed == null) {
            $install[] = 'elespare';
          }
        }
      }

      $plugin_update = 'false';
      if (!empty($templatespare_elespare_verison) && $templatespare_elespare_verison < $templatespare_elespare_old_version) {
        $plugin_update = 'true';
      }

      if (($templatespare_elespare_installed && $templatespare_elespare_active) && $plugin_update == 'false' && is_plugin_active($templatespare_elementor_installed)) {
        echo '<div id="elespare-demo-list"></div>';
      } else {

        wp_enqueue_style('templatespare');
        $message = (!empty($templatespare_elespare_verison) && $templatespare_elespare_active && $templatespare_elespare_verison < $templatespare_elespare_old_version)
          ? sprintf(__('Elespare plugin version should be more than %s.', 'templatespare'), $templatespare_elespare_old_version)
          : __('One-click Import, Header/Footer Builder, Multilingual Support! Powered by Elespare.', 'templatespare');
        $class = (!empty($templatespare_elespare_verison) && $templatespare_elespare_active && $templatespare_elespare_verison < $templatespare_elespare_old_version)
          ? admin_url('plugins.php')
          : 'false';
        echo '<div id="templatespare-plugins-install-activate" data-class="' . esc_attr($class) . '" current-theme="elespare" install="' . esc_attr(json_encode($install)) . '" activate="' . esc_attr(json_encode($activate)) . '" data-plugin-page="elespare-dashboard" message="' . esc_attr($message) . '" isPro="' . esc_attr($templatespare_elespare_status) . '"></div>';
      }

      // <!-- <div id="elespare-demo-list"></div> -->
    }

    public function templatespare_dashboard_assets($hook)
    {

      // Get the last modified time of the file.
      $aftmls_file_modified_time = filemtime(AFTMLS_PLUGIN_DIR . 'dist/block.build.js');

      // Append the modified time as a timestamp to the version.
      $aftmls_version_with_timestamp = '2.0.' . $aftmls_file_modified_time;

      wp_enqueue_script(
        'aftmls-dashboard-script', // Handle.
        AFTMLS_PLUGIN_URL . 'dist/block.build.js',
        array(
          'wp-blocks',
          'wp-i18n',
          'wp-element',
          'wp-components',
          'wp-editor',

        ), // Dependencies, defined above.
        $aftmls_version_with_timestamp, // version.
        true
        // Enqueue the script in the footer.
      );

      wp_enqueue_script(
        'aftmls-backend-script', // Handle.
        AFTMLS_PLUGIN_URL . 'dist/admin_script.build.js',
        array(
          'aftmls-dashboard-script',
          'jquery',
          'updates',
        ), // Dependencies, defined above.
        '1.0', // version.
        true
      );

      wp_enqueue_script(
        'aftmls-blockspare-script', // Handle.
        AFTMLS_PLUGIN_URL . 'dist/dashboard_script.build.js',
        array(), // Dependencies, defined above.
        '1.0', // version.
        true
      );

      wp_register_script(
        'aftmls-plugin-installl-activation-script', // Handle.
        AFTMLS_PLUGIN_URL . 'dist/plugin_activate_script.build.js',
        array('jquery'), // Dependencies, defined above.
        '1.0', // version.
        true
      );

      wp_localize_script(
        'aftmls-plugin-installl-activation-script',
        'afplugin',
        array(
          'ajax_nonce' => wp_create_nonce('aftc-ajax-verification'),
          'apiUrl' => site_url() . '/index.php?rest_route=/',
        )
      );

      $is_elementor_active = file_exists(WP_PLUGIN_DIR . '/' . 'elementor/elementor.php') ? 'true' : 'false';
      $is_elespare_active = file_exists(WP_PLUGIN_DIR . '/' . 'elespare/elespare.php') ? 'true' : 'false';
      $is_woocommerce_active = file_exists(WP_PLUGIN_DIR . '/' . 'woocommerce/woocommerce.php') ? 'true' : 'false';
      $is_blockspare_active = file_exists(WP_PLUGIN_DIR . '/' . 'blockspare/blockspare.php') ? 'true' : 'false';
      $theme = wp_get_theme();
      $listConfig = templatespare_get_filtered_data();
      $is_pro = templatespare_cheeck_pro_themes();

      $installed_themes = $this->templatespare_get_all_install_themes();
      $templatesapre_active_theme = wp_get_theme();
      $theme_index = strtolower($active_theme = get_stylesheet());
      $defined_theme = '';

      /**
       * Check whether the get_current_screen function exists
       * because it is loaded only after 'admin_init' hook.
       */

      $current_screen = get_current_screen();

      if ($current_screen->base == 'toplevel_page_templatespare-main-dashboard') {
        $defined_theme = $theme->name;
        $theme_index = ($hook == 'toplevel_page_templatespare-main-dashboard') ? 'all' : $theme_index;
      } else {
        if (defined('AFT_THEME_NAME') && AFT_THEME_NAME == $theme_index) {

          $theme_index = AFT_THEME_NAME;
          $defined_theme = $theme->name;
        } else
                if (str_contains($templatesapre_active_theme['Author'], "AF themes")) {

          $theme_index = $theme_index;
          $defined_theme = $theme->name;
        } else {

          $defined_theme = $theme->name;
          $theme_index = 'all';
        }
      }
      $selected_cats = get_option('templatespare_wizard_category_value', true);

      if (!isset($selected_cat) && empty($selected_cat)) {
        $selected_cats = 'all-cat';
      } else {
        $selected_cats =  $selected_cat[1];
      }
      $slug  = '';
      if (is_admin() && isset($_GET['page'])) {
        $slug = sanitize_text_field($_GET['page']);
      }
      wp_localize_script(
        'aftmls-dashboard-script',
        'afobDash',
        array(
          'ajax_nonce' => wp_create_nonce('aftc-ajax-verification'),
          'apiUrl' => site_url() . '/index.php?rest_route=/',
          'srcUrl' => AFTMLS_PLUGIN_URL . 'assets/images',
          'afthemes_lists' => json_encode($installed_themes),
          'widgetsrcUrl' => AFTMLS_PLUGIN_URL . 'includes/wizard/cat-images',
          'active_theme' => $theme_index,
          'elementor' => $is_elementor_active,
          'elespare' => $is_elespare_active,
          'blockspare' => $is_blockspare_active,
          'woocommerce' => $is_woocommerce_active,
          'configList' => json_encode($listConfig),
          'themes' => $theme->name,
          'allThems' => wp_get_themes(),
          'isPro' => $is_pro,
          'logo' => AFTMLS_PLUGIN_URL . 'assets/images/logo.svg',
          'aflogo' => AFTMLS_PLUGIN_URL . 'assets/images/afthemes.png',
          'cscreen' => $hook,
          'currentTheme' => $defined_theme,
          'selected_cat' => $selected_cats,
          'templatespare_dashbord_href' => admin_url('admin.php?page=templatespare-main-dashboard', 'admin'),
          'templatespare_wizard_href' => admin_url('admin.php?page=wizard-page', 'admin'),
          'all_categories' => get_all_categories(),
          'templatesapre_url' => admin_url('admin.php?', 'admin'),
          'block_patterns' => AFTMLS_PLUGIN_URL . '/assets/images/block-patterns.jpg',
          'template_kits' => AFTMLS_PLUGIN_URL . '/assets/images/template-kits.jpg',
          'current_page' =>  $slug

        )
      );

      if (is_admin() && $current_screen->base != 'toplevel_page_wizard-page') :

        wp_enqueue_style(
          'aftmls-block-edit-style',
          AFTMLS_PLUGIN_URL . 'dist/blocks.editor.build.css',
          array('wp-edit-blocks')
        );
      endif;
    }

    public function templatespare_load_files()
    {
      include_once AFTMLS_PLUGIN_DIR . 'includes/layouts/layout-endpoints.php';
      include_once AFTMLS_PLUGIN_DIR . 'includes/layouts/class-plugin-notice.php';
      require_once AFTMLS_PLUGIN_DIR . 'includes/companion/elementor-meta-handler.php';
      require_once AFTMLS_PLUGIN_DIR . 'includes/layouts/default.php';
      require_once AFTMLS_PLUGIN_DIR . 'includes/site-backup/class-backup-site.php';
      require_once AFTMLS_PLUGIN_DIR . 'includes/dashboard/activate_install_plugins.php';
    }

    public function templatespare_register_plugins_routes()
    {
      $templatespare_rest = new AFTMLS_RestApi_Reques_Controller();
      $templatespare_rest->templatespare_register_routes();
    }

    public function templatespare_get_all_install_themes()
    {
      $allthemes = array();
      $themes = templatespare_available_themes();
      if (!empty($themes)) {
        foreach ($themes as $res) {
          $theme_slug = str_replace('', '', $res);
          $empty_array = array(
            'theme_name' => $res,
            'theme_slug' => strtolower($theme_slug),
          );
          array_push($allthemes, $empty_array);
        }
      }

      return $allthemes;
    }
    public function temmplatespareRenderBackupPage()
    {
      require_once AFTMLS_PLUGIN_DIR . 'includes/site-backup/backuplisting.php';
    }

    public function temmplatespareRenderImportPage()
    {
      require_once AFTMLS_PLUGIN_DIR . 'includes/site-backup/import-site.php';
    }



    public  function templatespare_check_blockspare_free_pro_activated()
    {
      $templatespare_blockspare_pro_installed = templatespare_get_plugin_file('blockspare-pro');
      $templatespare_blockspare_free_installed = templatespare_get_plugin_file('blockspare');

      if (!empty($templatespare_blockspare_free_installed) && is_plugin_active($templatespare_blockspare_free_installed)) {
        $flag = 'free';
      } elseif (!empty($templatespare_blockspare_pro_installed) && !is_plugin_active($templatespare_blockspare_pro_installed)) {
        $flag = 'pro';
      } elseif (!empty($templatespare_blockspare_pro_installed) && is_plugin_active($templatespare_blockspare_pro_installed)) {
        $flag = 'pro';
      } else {
        $flag = 'free';
      }
      return $flag;
    }
    public function templatespare_check_elespare_free_pro_activated()
    {
      $templatespare_elespare_pro_installed = templatespare_get_plugin_file('elespare-pro');
      $templatespare_elespare_free_installed = templatespare_get_plugin_file('elespare');
      if (!empty($templatespare_elespare_free_installed) && is_plugin_active($templatespare_elespare_free_installed)) {
        $flag = 'free';
      } elseif (!empty($templatespare_elespare_pro_installed) && !is_plugin_active($templatespare_elespare_pro_installed)) {
        $flag = 'pro';
      } elseif (!empty($templatespare_elespare_pro_installed) && is_plugin_active($templatespare_elespare_pro_installed)) {
        $flag = 'pro';
      } else {
        $flag = 'free';
      }
      return $flag;
    }
  }

  new AFTMLS_Templates_Importer();
}
