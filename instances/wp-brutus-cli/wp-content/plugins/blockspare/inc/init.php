<?php

//Exit if directly acess
defined('ABSPATH') or die('No script kiddies please!');

if (!class_exists('BlockspareInit')) {
  class BlockspareInit
  {
    /**
     * Member Variable
     *
     * @var instance
     */
    private static $instance;

    /**
     *  Initiator
     */
    public static function get_instance()
    {
      if (!isset(self::$instance)) {
        self::$instance = new self();
      }
      return self::$instance;
    }
    public function __construct()
    {
      add_action('init', array($this, 'blockspare_blocks_block_assets'));
      add_action('enqueue_block_editor_assets', array($this, 'blockspare_create_block'));
      if (version_compare(get_bloginfo('version'), '5.8', '<')) {
        add_action('wp_enqueue_scripts', array($this, 'blockspare_load_scripts'));
      } else {
        add_action('enqueue_block_assets', array($this, 'blockspare_load_scripts'), 1);
      }
      add_action('plugins_loaded', array($this, 'blockspare_blocks_loader'));
      add_action('rest_api_init', array($this, 'blockspare_blocks_register_api_endpoints'));
    }

    public function blockspare_blocks_block_assets()
    {
      wp_register_style(
        'fontawesome',
        plugins_url('assets/fontawesome/css/all.css', dirname(__FILE__)),
        array()

      );
      // Load the compiled styles.
      wp_enqueue_style(
        'blockspare-frontend-block-style-css',
        plugins_url('dist/style-blocks.css', dirname(__FILE__)),
        array()
      );

      wp_register_style('slick', BLOCKSPARE_PLUGIN_URL . 'assets/slick/css/slick.css', array(), '', 'all');
      if (is_admin()) {
        wp_enqueue_style('slick');
      }
      include BLOCKSPARE_PLUGIN_DIR . '/admin/notice-setup.php';
      include BLOCKSPARE_PLUGIN_DIR . '/admin/notice-upgrade.php';
    }

    public function blockspare_load_scripts()
    {

      wp_register_style(
        'fontawesome',
        plugins_url('assets/fontawesome/css/all.css', dirname(__FILE__)),
        array()

      );
      // Load the compiled styles.
      wp_enqueue_style(
        'blockspare-frontend-block-style-css',
        plugins_url('dist/style-blocks.css', dirname(__FILE__)),
        array()
      );

      wp_register_style('slick', BLOCKSPARE_PLUGIN_URL . 'assets/slick/css/slick.css', array(), '', 'all');
      if (is_admin()) {
        wp_enqueue_style('slick');
      }

      wp_enqueue_style('fontawesome');
      $min = (SCRIPT_DEBUG == true) ? '' : '.min';
      wp_register_script('slick', BLOCKSPARE_PLUGIN_URL . 'assets/slick/js/slick.js', array('jquery'), '', true);
      if (has_blocks() || has_block('blockspare/blockspare-carousel') || has_block('blockspare/blockspare-slider') || has_block('blockspare/latest-posts-block-carousel-grid') || has_block('blockspare/blockspare-posts-block-slider')) {
        wp_enqueue_script('slick');
        wp_enqueue_style('slick');
      }

      wp_register_script('countup', BLOCKSPARE_PLUGIN_URL . 'assets/js/countup/jquery.counterup.min.js', array('waypoint'), true);
      wp_enqueue_script('waypoint', BLOCKSPARE_PLUGIN_URL . 'assets/js/countup/waypoints.min.js', array('jquery'), '', true);

      if (has_blocks() || has_block('blockspare/blockspare-masonry')) {
        wp_enqueue_script('jquery-masonry');
      }
      wp_enqueue_script('blockspare-animation', BLOCKSPARE_PLUGIN_URL . 'dist/block_animation.js', array(), '', true);
      wp_enqueue_script('blockspare-script', BLOCKSPARE_PLUGIN_URL . 'dist/block_frontend.js', array('jquery', 'waypoint', 'countup'), '', true);
      if (has_blocks() || has_block('blockspare/blockspare-tabs')) {

        wp_enqueue_script('blockspare-tabs', BLOCKSPARE_PLUGIN_URL . 'dist/block_tabs.js', array('jquery'), '', true);
      }
      wp_register_script('blockspare-pagination-js', BLOCKSPARE_PLUGIN_URL . 'dist/block_pagination.js', array(), '', true);
      wp_localize_script('blockspare-pagination-js', 'blocsparePagination', array(
        'ajaxurl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('blockspare-load-more-nonce'),
      ));
    }

    public function blockspare_create_block()
    {

      $script_dep_path = BLOCKSPARE_BASE_DIR . 'dist/blocks.asset.php';
      $script_info = file_exists($script_dep_path) ? include $script_dep_path : array(
        'dependencies' => array(),
        'version' => BLOCKSPARE_VERSION,
      );
      if (version_compare(get_bloginfo('version'), '5.8', '<')) {
        $script_dep = array_merge($script_info['dependencies'], array(
          'wp-blocks',
          'wp-i18n',
          'wp-element',
          'wp-components',
          'wp-editor',
          'wp-api-fetch',
        ));
      } else {
        $script_dep = $script_info['dependencies'];
      }

      wp_enqueue_script(
        'blockspare-blocks-block-js', // Handle.
        BLOCKSPARE_PLUGIN_URL . 'dist/blocks.js',
        $script_dep, // Dependencies, defined above.
        $script_info['version'], // AFT_PRICING_TABLE_VERSION.
        true
        // Enqueue the script in the footer.
      );

      if (is_admin()):
        wp_enqueue_style('fontawesome');
        wp_enqueue_style(
          'blockspare-block-edit-style',
          BLOCKSPARE_PLUGIN_URL . 'dist/blocks.css',
          array('wp-edit-blocks')
        );
        wp_enqueue_script('blockspare-design-btn', BLOCKSPARE_PLUGIN_URL . 'dist/block_design_btn.js', array('blockspare-blocks-block-js'), $script_info['version'], true);
        wp_enqueue_script('blockspare-design', BLOCKSPARE_PLUGIN_URL . 'dist/block_design.js', array('blockspare-blocks-block-js'), $script_info['version'], true);
        wp_enqueue_style('blockspare-design', BLOCKSPARE_PLUGIN_URL . 'dist/block_design.css', array('blockspare-block-edit-style'));
      endif;

      $blockspare_priview_img_url = BLOCKSPARE_PLUGIN_URL . 'assets/blockspare-placeholder-img.jpg';
      $blockspare_food_img_url = BLOCKSPARE_PLUGIN_URL . 'assets/blockspare-placeholder-img-square.jpg';
      $blockspare_dashboard_logo = BLOCKSPARE_PLUGIN_URL . 'assets/images/blockspare-logo.png';
      $taxonomies = get_categories();
      $txnm = array();

      foreach ($taxonomies as $type) {

        $txnm[] = array(
          'label' => $type->name,
          'value' => $type->term_id,
        );
      }

      $date = wp_kses_post(date_i18n(get_option('date_format')));
      $open_design = false;
      if (isset($_GET['blockspare_show_intro'])) {
        if ($_GET['blockspare_show_intro'] == 'true') {
          $open_design = true;
        }
      }
      $date_format = date_i18n(get_option('date_format'), current_time('timestamp'));

      $blockData = '';
      if (isset($_GET['blockspare_create_block'])) {
        $blockName = $_GET['blockspare_create_block'];
        $blocks_lists = array();

        $filterdata = apply_filters('blockspare_template_library', $blocks_lists);

        foreach ($filterdata as $block) {
          if (isset($block['key']) && $block['key'] === $blockName) {
            // Found the block with the desired key, retrieve the content
            $blockData = $block['content'];
            break; // Stop looping once the desired block is found
          }
        }
      }

      global $pagenow;
      $loadscripton = true;
      if ($pagenow !== 'widgets.php' && !is_customize_preview()) {
        $loadscripton = false;
      }
      //$blockspare_current_wp_version = '6.5';
      $blockspare_current_wp_version = get_bloginfo('version');

      wp_localize_script(
        'blockspare-blocks-block-js',
        'blockspare_globals',
        array(
          'srcUrl' => untrailingslashit(plugins_url('/', BLOCKSPARE_BASE_DIR . '/dist/')),
          'rest_url' => esc_url(rest_url()),
          'img' => $blockspare_priview_img_url,
          'homeUrl' => home_url(),
          'blogUrl' => get_site_url(),
          'menu_img_url' => $blockspare_food_img_url,
          'logo' => $blockspare_dashboard_logo,
          'postTypes' => $this->aft_get_post_types(),
          'taxonomies' => $this->aft_get_taxonomies(),
          'postQueryEndpoint' => 'aft/v1/post-query',
          'config' => '',
          'configuration' => '',
          'settings' => '',
          'bs_date' => $date,
          "imagePath" => "https://raw.githubusercontent.com/afthemes/blockspare-demo-data/master/blocks/",
          "open_design" => $open_design,
          'date_format ' => $date_format,
          'blockData' => $blockData,
          'template_link' => admin_url('edit.php?post_type=bs_templates'),
          'newTemlateUrl' => admin_url('post-new.php?post_type=bs_templates&blockspare_create_block&blockspare_show_intro=true'),
          'loadscripton' => $loadscripton,
          'wpversion' => (version_compare($blockspare_current_wp_version, '6.6') >= 0) ? 'true' : 'false',
        )
      );
    }

    public function aft_get_post_types()
    {
      $args = array(
        'public' => true,
        'show_in_rest' => true,
      );
      $post_types = get_post_types($args, 'objects');
      $output = array();
      foreach ($post_types as $post_type) {

        if ('attachment' == $post_type->name) {
          continue;
        }
        $output[] = array(
          'value' => $post_type->name,
          'label' => $post_type->label,
        );
      }

      return apply_filters('aft_blocks_post_types', $output);
    }

    public function aft_get_taxonomies()
    {
      $post_types = $this->aft_get_post_types();
      $output = array();
      foreach ($post_types as $key => $post_type) {
        $taxonomies = get_object_taxonomies($post_type['value'], 'objects');
        $taxs = array();
        foreach ($taxonomies as $term_slug => $term) {
          if (!$term->public || !$term->show_ui) {
            continue;
          }
          $taxs[$term_slug] = $term;
          $terms = get_terms($term_slug);
          $term_items = array();
          if (!empty($terms)) {
            foreach ($terms as $term_key => $term_item) {
              $term_items[] = array(
                'value' => $term_item->term_id,
                'label' => $term_item->name,
              );
            }
            $output[$post_type['value']]['terms'][$term_slug] = $term_items;
          }
        }
        $output[$post_type['value']]['taxonomy'] = $taxs;
      }

      return apply_filters('aft_blocks_taxonomies', $output);
    }

    public function blockspare_blocks_loader()
    {

      if (PHP_VERSION_ID >= 50600) {
        require_once BLOCKSPARE_PLUGIN_DIR . 'inc/layout/class-image-importer.php';
        require_once BLOCKSPARE_PLUGIN_DIR . 'inc/layout/functions.php';
        require_once BLOCKSPARE_PLUGIN_DIR . 'inc/layout/registry.php';
        require_once BLOCKSPARE_PLUGIN_DIR . 'inc/layout/components.php';

        /**
         * REST API Endpoints for Layouts.
         */
        require_once BLOCKSPARE_PLUGIN_DIR . 'inc/layout/endpoints.php';
        require_once BLOCKSPARE_PLUGIN_DIR . 'inc/layout/save-templates.php';

        /**
         * Import Pattern
         */

        // require_once BLOCKSPARE_PLUGIN_DIR . 'inc/pattern/pattern.php';
      }

      //Load Gutenberg Block php Files
      include BLOCKSPARE_PLUGIN_DIR . 'inc/other-block/date-time/index.php';
      include BLOCKSPARE_PLUGIN_DIR . '/inc/other-block/social-sharing/index.php';
      include BLOCKSPARE_PLUGIN_DIR . '/inc/latest-post-block/posts-grid/index.php';
      include BLOCKSPARE_PLUGIN_DIR . '/inc/latest-post-block/posts-list/index.php';

      include BLOCKSPARE_PLUGIN_DIR . '/inc/latest-post-block/posts-express-grid/index.php';
      include BLOCKSPARE_PLUGIN_DIR . '/inc/latest-post-block/posts-express-grid/post-content.php';

      include BLOCKSPARE_PLUGIN_DIR . '/inc/latest-post-block/posts-carousel-grid/index.php';
      include BLOCKSPARE_PLUGIN_DIR . '/inc/latest-post-block/posts-slider/index.php';

      include BLOCKSPARE_PLUGIN_DIR . '/inc/post-banner/banner-1/index.php';
      include BLOCKSPARE_PLUGIN_DIR . '/inc/post-banner/banner-2/index.php';
      include BLOCKSPARE_PLUGIN_DIR . '/inc/post-banner/banner-9/index.php';
      include BLOCKSPARE_PLUGIN_DIR . 'inc/block-posts-config/ajax-pagination.php';
      include BLOCKSPARE_PLUGIN_DIR . 'inc/block-posts-config/class-post-rest-api.php';
      include BLOCKSPARE_PLUGIN_DIR . 'inc/block-posts-config/class-multiauthor-backend.php';
      include BLOCKSPARE_PLUGIN_DIR . 'inc/block-posts-config/class-multiauthor-frontend.php';
      include BLOCKSPARE_PLUGIN_DIR . 'inc/block-posts-config/tax-category.php';
      include BLOCKSPARE_PLUGIN_DIR . 'inc/block-posts-config/class-bs-post.php';
      include BLOCKSPARE_PLUGIN_DIR . 'inc/block-banner/frontend-render/post-banner-config.php';
      include BLOCKSPARE_PLUGIN_DIR . 'inc/block-banner/frontend-render/post-banner-style.php';
      include BLOCKSPARE_PLUGIN_DIR . 'inc/other-block/search/index.php';
      include BLOCKSPARE_PLUGIN_DIR . 'inc/latest-post-block/posts-flash/index.php';
      include BLOCKSPARE_PLUGIN_DIR . 'inc/other-block/popular-tags/index.php';
      include BLOCKSPARE_PLUGIN_DIR . 'inc/page-templates/module.php';
      include BLOCKSPARE_PLUGIN_DIR . 'inc/block-banner/frontend-render/post-category-style.php';
    }

    public function blockspare_blocks_register_api_endpoints()
    {

      $posts_controller = new Aft_Post_Rest_Controller();
      $posts_controller->register_routes();
    }
  }
  BlockspareInit::get_instance();
}
