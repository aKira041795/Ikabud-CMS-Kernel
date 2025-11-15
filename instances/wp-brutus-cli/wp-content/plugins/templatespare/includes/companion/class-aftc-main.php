<?php

/**
 * Main Templatespare plugin class/file.
 *
 * @package templatespare
 */


// Include files.
require AFTMLS_PLUGIN_DIR . 'includes/companion/class-aftc-helpers.php';
require AFTMLS_PLUGIN_DIR . 'includes/companion/class-aftc-importer.php';
require AFTMLS_PLUGIN_DIR . 'includes/companion/class-aftc-widget-importer.php';
require AFTMLS_PLUGIN_DIR . 'includes/companion/class-aftc-customizer-importer.php';
require AFTMLS_PLUGIN_DIR . 'includes/companion/class-aftc-logger.php';
require AFTMLS_PLUGIN_DIR . 'includes/companion/demo-importer.php';



/**
 * Templatespare class, so we don't have to worry about namespaces.
 */
class AFTMLS_Companion
{

  /**
   * @var $instance the reference to *Singleton* instance of this class
   */
  private static $instance;

  const API = 'https://raw.githubusercontent.com/afthemes/templatespare-demo-data/master/';

  /**
   * Private variables used throughout the plugin.
   */
  private $importer, $plugin_page, $import_files, $logger, $log_file_path, $selected_index, $selected_import_files, $microtime, $frontend_error_messages, $ajax_call_number, $selected_theme, $ischild;

  private $allcontentimport;
  private $homepage_type;
  private $plugin_page_setup = array();

  /**
   * Returns the *Singleton* instance of this class.
   *
   * @return AF_Companion the *Singleton* instance.
   */
  public static function getInstance()
  {
    if (null === static::$instance) {
      static::$instance = new static();
    }

    return static::$instance;
  }


  /**
   * Class construct function, to initiate the plugin.
   * Protected constructor to prevent creating a new instance of the
   * *Singleton* via the `new` operator from outside of this class.
   */
  protected function __construct()
  {




    // Actions.
    add_action('wp_ajax_AFTMLS_import_demo_data', array($this, 'import_demo_data_ajax_callback'));
    add_action('after_setup_theme', array($this, 'setup_plugin_with_filter_data'));
    //
    add_action('plugins_loaded', array($this, 'load_textdomain'));
  }


  /**
   * Private clone method to prevent cloning of the instance of the *Singleton* instance.
   *
   * @return void
   */
  private function __clone() {}


  /**
   * Private unserialize method to prevent unserializing of the *Singleton* instance.
   *
   * @return void
   */
  public function __wakeup() {}



  /**
   * Main AJAX callback function for:
   * 1. prepare import files (uploaded or predefined via filters)
   * 2. import content
   * 3. before widgets import setup (optional)
   * 4. import widgets (optional)
   * 5. import customizer options (optional)
   * 6. after import setup (optional)
   */
  public function import_demo_data_ajax_callback()
  {







    // Try to update PHP memory limit (so that it does not run out of it).
    ini_set('memory_limit', apply_filters('templatespare/import_memory_limit', '350M'));

    // Verify if the AJAX call is valid (checks nonce and current_user_can).
    AFTMLS_Helpers::verify_ajax_call();

    // Is this a new AJAX call to continue the previous import?
    $use_existing_importer_data = $this->get_importer_data();

    $this->homepage_type = sanitize_text_field($_POST['homepageType']);

    $this->allcontentimport = '';
    if (isset($_POST['allcontent'])) {
      // Sanitize and convert to strict boolean
      $raw_allcontent = sanitize_text_field($_POST['allcontent']);
      $this->allcontentimport = $raw_allcontent;
    } else {
      $this->allcontentimport = 'true';
    }

    if (!$use_existing_importer_data) {



      // Set the AJAX call number.
      $this->ajax_call_number = empty($this->ajax_call_number) ? 0 : $this->ajax_call_number;

      if ($this->ajax_call_number == 0) {
        do_action('templatespare_ajax_before_demo_import');
      }
      // Error messages displayed on front page.
      $this->frontend_error_messages = '';

      // Create a date and time string to use for demo and log file names.
      $demo_import_start_time = date(apply_filters('templatespare/date_format_for_file_names', 'Y-m-d__H-i-s'));

      // Define log file path.
      $this->log_file_path = AFTMLS_Helpers::get_log_path($demo_import_start_time);

      // Get selected file index or set it to 0.
      $templatespare_templates_kit = sanitize_text_field($_POST['templatespare_templates_kit']);

      $manual = '';

      if (isset($_POST['impotype'])) {
        $manual = sanitize_text_field($_POST['impotype']);
      }
      if ($manual == '') {
        $selectedTheme = sanitize_text_field($_POST['selectedTheme']);
        $isChild = sanitize_text_field($_POST['isChild']);
        $this->selected_index = empty($templatespare_templates_kit) ? 0 : $templatespare_templates_kit;
        $this->selected_theme = empty(strtolower($selectedTheme)) ? 0 : strtolower($selectedTheme);
        $this->ischild = empty(strtolower($isChild)) ? 0 : strtolower($isChild);

        $demo_file_url = self::API;
        $last_path =  $this->selected_theme;


        $xml = $demo_file_url . $this->selected_theme . '/' . $this->selected_index . '/' . $last_path . '.xml';
        $dat = $demo_file_url . $this->selected_theme . '/' . $this->selected_index . '/' . $last_path . '.dat';
        $wie = $demo_file_url . $this->selected_theme . '/' . $this->selected_index . '/' . $last_path . '.wie';



        $data[$this->selected_index] = array(
          'import_file_name' => $this->selected_theme,
          'categories' => '',
          'import_file_url' => $xml,
          'import_widget_file_url' => $wie,
          'import_customizer_file_url' => $dat,

        );



        $this->import_files = AFTMLS_Helpers::validate_import_file_info($data);
      } else {


        $this->selected_index = 0;

        $DemoName = sanitize_text_field($_POST['foldername']);

        // Check for JSON decode errors
        if (json_last_error() === JSON_ERROR_NONE) {


          $demourl = wp_get_upload_dir();
          $data[$this->selected_index] = array(
            'import_file_name' =>  'demo-content',
            'categories' => '',
            'import_file_url' =>  $demourl['baseurl'] . '/' . $DemoName . '/demo-content/demo-content.xml',
            'import_widget_file_url' => $demourl['baseurl'] . '/' . $DemoName . '/demo-content/demo-content.wie',
            'import_customizer_file_url' => $demourl['baseurl'] . '/' . $DemoName . '/demo-content/demo-content.dat'

          );
        }





        $this->import_files = AFTMLS_Helpers::validate_import_file_info($data);
      }




      /**
       * 1. Prepare import files.
       * Manually uploaded import files or predefined import files via filter: templatespare/import_files
       */
      if (!empty($this->import_files[$this->selected_index])) { // Use predefined import files from wp filter: templatespare/import_files.



        // Download the import files (content and widgets files) and save it to variable for later use.
        $this->selected_import_files = AFTMLS_Helpers::download_import_files(
          $this->import_files[$this->selected_index],
          $demo_import_start_time
        );






        // Check Errors.
        if (is_wp_error($this->selected_import_files)) {

          // Write error to log file and send an AJAX response with the error.
          AFTMLS_Helpers::log_error_and_send_ajax_response(
            $this->selected_import_files->get_error_message(),
            $this->log_file_path,
            esc_html__('Downloaded files', 'templatespare')
          );
        }

        // Add this message to log file.

        $log_added = AFTMLS_Helpers::append_to_file(
          sprintf(
            __('The import files for: %s were successfully downloaded!', 'templatespare'),
            $this->import_files[$this->selected_index]['import_file_name']
          ) . AFTMLS_Helpers::import_file_info($this->selected_import_files),
          $this->log_file_path,
          esc_html__('Downloaded files', 'templatespare')
        );
      } else {

        // Send JSON Error response to the AJAX call.
        wp_send_json(esc_html__('No import files specified!', 'templatespare'));
      }
    }


    /**
     * 2. Import content.
     * Returns any errors greater then the "error" logger level, that will be displayed on front page.
     */



    if ($this->allcontentimport  !== 'false') {
      $this->frontend_error_messages .= $this->import_content($this->selected_import_files['content'], $this->allcontentimport);
    }




    /**
     * 3. Before widgets import setup.
     */
    $action = 'templatespare/before_widgets_import';

    if ((false !== has_action($action)) && empty($this->frontend_error_messages)) {

      // Run the before_widgets_import action to setup other settings.
      $this->do_import_action($action, $this->import_files[$this->selected_index]);

      if (apply_filters('templatespare/enable_custom_menu_widget_ids_fix', true)) {
        add_action('templatespare/widget_settings_array', array($this, 'templatespare_fix_custom_menu_widget_ids'));
      }
    }

    /**
     * 4. Import widgets.
     */
    if (!empty($this->selected_import_files['widgets']) && empty($this->frontend_error_messages)) {
      $this->import_widgets($this->selected_import_files['widgets']);
    }


    /**
     * 5. Import customize options.
     */
    if (!empty($this->selected_import_files['customizer']) && empty($this->frontend_error_messages)) {
      $this->import_customizer($this->selected_import_files['customizer'], $this->allcontentimport);
    }



    /**
     * 6. After import setup.
     */



    if ($this->allcontentimport === 'true') {
      $action = 'templatespare/after_import';

      if ((false !== has_action($action)) && empty($this->frontend_error_messages)) {

        // Run the after_import action to setup other settings.
        $this->do_import_action($action, $this->import_files[$this->selected_index], $this->homepage_type);
      }
    }
    if ($this->allcontentimport === 'false') {
      $action = 'templatespare/after_import_is_not_content';

      if ((false !== has_action($action)) && empty($this->frontend_error_messages)) {

        // Run the after_import action to setup other settings.
        $this->do_import_action($action,  $this->import_files[$this->selected_index], $this->homepage_type);
      }
    }






    // Display final messages (success or error messages).
    if (empty($this->frontend_error_messages)) {
      update_option('templatespare_wizard_next_step', 0);
      update_option('templatespare_wizard_category_value', null);
      $response['message'] = sprintf(
        __('%1$sSetup complete! Enjoy your new design.%2$sThe demo import has finished. Please check your page and make sure that everything has imported correctly. For more other beautiful WordPress products please visit %3$sAF themes%4$s. %5$s', 'templatespare'),
        '<div class="updated"><p>',
        '<br>',
        '<strong><a href="https://afthemes.com/" target="_blank">',
        '</a></strong>',
        '</p></div>'
      );
    } else {
      $response['message'] = $this->frontend_error_messages . '<br>';
      $response['message'] .= sprintf(
        __('%1$sThe demo import has finished, but there were some import errors.%2$sMore details about the errors can be found in this %3$s%5$slog file%6$s%4$s%7$s', 'templatespare'),
        '<div class="error"><p>',
        '<br>',
        '<strong>',
        '</strong>',
        '<a href="' . AFTMLS_Helpers::get_log_url($this->log_file_path) . '" target="_blank">',
        '</a>',
        '</p></div>'
      );
    }


    wp_send_json($response);
  }


  /**
   * Import content from an WP XML file.
   *
   * @param string $import_file_path path to the import file.
   */
  private function import_content($import_file_path)
  {



    $this->microtime = microtime(true);

    // This should be replaced with multiple AJAX calls (import in smaller chunks)
    // so that it would not come to the Internal Error, because of the PHP script timeout.
    // Also this function has no effect when PHP is running in safe mode
    // https://php.net/manual/en/function.set-time-limit.php.
    // Increase PHP max execution time.
    set_time_limit(apply_filters('templatespare/set_time_limit_for_demo_data_import', 300));

    // Disable import of authors.
    add_filter('wxr_importer.pre_process.user', '__return_false');

    // Check, if we need to send another AJAX request and set the importing author to the current user.
    add_filter('wxr_importer.pre_process.post', array($this, 'new_ajax_request_maybe'));

    // Disables generation of multiple image sizes (thumbnails) in the content import step.
    if (!apply_filters('templatespare/regenerate_thumbnails_in_content_import', true)) {
      add_filter(
        'intermediate_image_sizes_advanced',
        function () {
          return null;
        }
      );
    }



    // Import content.
    if (!empty($import_file_path)) {

      ob_start();
      $this->importer->import($import_file_path);
      $message = ob_get_clean();


      // Add this message to log file.
      $log_added = AFTMLS_Helpers::append_to_file(
        $message . PHP_EOL . esc_html__('Max execution time after content import = ', 'templatespare') . ini_get('max_execution_time'),
        $this->log_file_path,
        esc_html__('Importing content', 'templatespare')
      );
    }

    // Delete content importer data for current import from DB.
    delete_transient('AFTMLS_importer_data');

    // Return any error messages for the front page output (errors, critical, alert and emergency level messages only).
    return $this->logger->error_output;
  }


  /**
   * Import widgets from WIE or JSON file.
   *
   * @param string $widget_import_file_path path to the widget import file.
   */
  private function import_widgets($widget_import_file_path)
  {

    // Widget import results.
    $results = array();

    // Create an instance of the Widget Importer.
    $widget_importer = new AFTMLS_Widget_Importer();

    // Import widgets.
    if (!empty($widget_import_file_path)) {

      // Import widgets and return result.
      $results = $widget_importer->import_widgets($widget_import_file_path);
    }

    // Check for errors.
    if (is_wp_error($results)) {

      // Write error to log file and send an AJAX response with the error.
      AFTMLS_Helpers::log_error_and_send_ajax_response(
        $results->get_error_message(),
        $this->log_file_path,
        esc_html__('Importing widgets', 'templatespare')
      );
    }

    ob_start();
    $widget_importer->format_results_for_log($results);
    $message = ob_get_clean();

    // Add this message to log file.
    $log_added = AFTMLS_Helpers::append_to_file(
      $message,
      $this->log_file_path,
      esc_html__('Importing widgets', 'templatespare')
    );
  }


  /**
   * Import customizer from a DAT file, generated by the Customizer Export/Import plugin.
   *
   * @param string $customizer_import_file_path path to the customizer import file.
   */
  private function import_customizer($customizer_import_file_path, $allcontentimport)
  {


    // Try to import the customizer settings.
    $results = AFTMLS_Customizer_Importer::import_customizer_options($customizer_import_file_path, $allcontentimport);

    // Check for errors.
    if (is_wp_error($results)) {

      // Write error to log file and send an AJAX response with the error.
      AFTMLS_Helpers::log_error_and_send_ajax_response(
        $results->get_error_message(),
        $this->log_file_path,
        esc_html__('Importing customizer settings', 'templatespare')
      );
    }

    // Add this message to log file.
    $log_added = AFTMLS_Helpers::append_to_file(
      esc_html__('Customizer settings import finished!', 'templatespare'),
      $this->log_file_path,
      esc_html__('Importing customizer settings', 'templatespare')
    );
  }


  /**
   * Setup other things in the passed wp action.
   *
   * @param string $action the action name to be executed.
   * @param array $selected_import with information about the selected import.
   */
  private function do_import_action($action, $selected_import, $homepagetype)
  {

    ob_start();
    do_action($action, $selected_import, $homepagetype);
    $message = ob_get_clean();

    // Add this message to log file.
    $log_added = AFTMLS_Helpers::append_to_file(
      $message,
      $this->log_file_path,
      $action
    );
  }


  /**
   * Check if we need to create a new AJAX request, so that server does not timeout.
   *
   * @param array $data current post data.
   * @return array
   */
  public function new_ajax_request_maybe($data)
  {
    $time = microtime(true) - $this->microtime;




    // We should make a new ajax call, if the time is right.
    if ($time > apply_filters('templatespare/time_for_one_ajax_call', 25)) {

      $this->ajax_call_number++;
      $this->set_importer_data();


      $response = array(
        'status' => 'newAJAX',
        'message' => 'Time for new AJAX request!: ' . $time,
        'ajaxCall' => $this->ajax_call_number,
      );

      // Add any output to the log file and clear the buffers.
      $message = ob_get_clean();

      // Add message to log file.
      $log_added = AFTMLS_Helpers::append_to_file(
        __('Completed AJAX call number: ', 'templatespare') . $this->ajax_call_number . PHP_EOL . $message,
        $this->log_file_path,
        ''
      );

      wp_send_json($response);
    }

    // Set importing author to the current user.
    // Fixes the [WARNING] Could not find the author for ... log warning messages.
    $current_user_obj = wp_get_current_user();
    $data['post_author'] = $current_user_obj->user_login;


    return $data;
  }

  /**
   * Set current state of the content importer, so we can continue the import with new AJAX request.
   */
  private function set_importer_data()
  {
    $data = array(
      'frontend_error_messages' => $this->frontend_error_messages,
      'ajax_call_number' => $this->ajax_call_number,
      'log_file_path' => $this->log_file_path,
      'selected_index' => $this->selected_index,
      'selected_import_files' => $this->selected_import_files,
    );

    $data = array_merge($data, $this->importer->get_importer_data());

    set_transient('AFTMLS_importer_data', $data, 0.5 * HOUR_IN_SECONDS);
  }

  /**
   * Get content importer data, so we can continue the import with this new AJAX request.
   */
  private function get_importer_data()
  {
    if ($data = get_transient('AFTMLS_importer_data')) {
      $this->frontend_error_messages = empty($data['frontend_error_messages']) ? '' : $data['frontend_error_messages'];
      $this->ajax_call_number = empty($data['ajax_call_number']) ? 1 : $data['ajax_call_number'];
      $this->log_file_path = empty($data['log_file_path']) ? '' : $data['log_file_path'];
      $this->selected_index = empty($data['selected_index']) ? 0 : $data['selected_index'];
      $this->selected_import_files = empty($data['selected_import_files']) ? array() : $data['selected_import_files'];
      $this->importer->set_importer_data($data);

      return true;
    }
    return false;
  }

  /**
   * Load the plugin textdomain, so that translations can be made.
   */
  public function load_textdomain()
  {
    load_plugin_textdomain('templatespare', false, plugin_basename(dirname(__FILE__)) . '/languages');
  }


  /**
   * Get data from filters, after the theme has loaded and instantiate the importer.
   */
  public function setup_plugin_with_filter_data()
  {





    // Importer options array.
    $importer_options = apply_filters('templatespare/importer_options', array(
      'fetch_attachments' => true,
    ));

    // Logger options for the logger used in the importer.
    $logger_options = apply_filters('templatespare/logger_options', array(
      'logger_min_level' => 'warning',
    ));

    // Configure logger instance and set it to the importer.
    $this->logger = new AFTMLS_Logger();
    $this->logger->min_level = $logger_options['logger_min_level'];

    // Create importer instance with proper parameters.
    $this->importer = new AFTMLS_Importer($importer_options, $this->logger);
  }

  public function templatespare_fix_custom_menu_widget_ids($widget)
  {
    // Skip (no changes needed), if this is not a custom menu widget.
    if (! array_key_exists('nav_menu', $widget) || empty($widget['nav_menu']) || ! is_int($widget['nav_menu'])) {
      return $widget;
    }

    // Get import data, with new menu IDs.

    $content_import_data = $this->get_importer_data();
    $term_ids            = $content_import_data['mapping']['term_id'];

    // Set the new menu ID for the widget.
    $widget['nav_menu'] = $term_ids[$widget['nav_menu']];

    return $widget;
  }
}
