<?php
defined('ABSPATH') || exit;

class ElespaeTemplate
{
  private static $instance;

  /**
   * Post ID
   *
   * @var int
   */
  public $post_id;

  /**
   * Post type
   *
   * @var String
   */
  public $post_type;

  /**
   *  Initiator
   */
  public static function instance()
  {
    if (! isset(self::$instance)) {
      self::$instance = new self();
    }
    return self::$instance;
  }
  public function __construct()
  {
    add_action('wp', array($this, 'hooks'));
    add_action('wp_head', array($this, 'wp_head'));
    add_action('wp_enqueue_scripts', [$this, 'elespare_enqueue_scripts']);
    add_filter('single_template', array($this, 'single_template'));
    add_action('elespare_hf_get_header', array($this, 'elespare_header_template'), 10);
    add_action('elespare_hf_get_header_wrapper', array($this, 'elespare_hf_get_header_wrapper_html'), 10);
    add_action('elespare_hf_get_footer_wrapper', array($this, 'elespare_hf_get_footer_wrapper_html'), 10);
    add_action('elespare_hf_get_footer', array($this, 'elespare_footer_template'), 10);
  }

  public function hooks()
  {


    add_action('get_header', array($this, 'render_header'));
    add_action('get_footer', array($this, 'render_footer'));
  }

  public function wp_head()
  {
    wp_reset_postdata();
  }

  public function elespare_enqueue_scripts()
  {
    if (class_exists('\Elementor\Plugin')) {
      $elementor = \Elementor\Plugin::instance();
      if (method_exists($elementor->frontend, 'enqueue_styles')) {
        $elementor->frontend->enqueue_styles();
      }
    }

    if (class_exists('\ElementorPro\Plugin')) {
      $elementor_pro = \ElementorPro\Plugin::instance();
      if (method_exists($elementor_pro, 'enqueue_styles')) {
        $elementor_pro->enqueue_styles();
      }
    }


    $header_id = $this->template_header_id();
    if ($header_id) {
      if (class_exists('\Elementor\Core\Files\CSS\Post')) {
        $css_file = new \Elementor\Core\Files\CSS\Post($header_id);
      } elseif (class_exists('\Elementor\Post_CSS_File')) {
        $css_file = new \Elementor\Post_CSS_File($header_id);
      }

      $css_file->enqueue();
    }

    $footer_id = $this->template_footer_id();


    if ($footer_id) {
      if (class_exists('\Elementor\Core\Files\CSS\Post')) {
        $css_file = new \Elementor\Core\Files\CSS\Post($footer_id);
      } elseif (class_exists('\Elementor\Post_CSS_File')) {
        $css_file = new \Elementor\Post_CSS_File($footer_id());
      }

      $css_file->enqueue();
    }
  }
  public function single_template($single_template)
  {

    if ('elespare_builder' == get_post_type()) { // phpcs:ignore
      $single_template = ELESPARE_DIR_PATH . 'header-footer/templates/hf.php';
    }

    return $single_template;
  }


  public function render_header()
  {
    $header_id = $this->template_header_id();

    if ($header_id) {

      require ELESPARE_DIR_PATH . 'header-footer/templates/default/header.php';
      $templates   = array();
      $templates[] = 'header.php';
      // Avoid running wp_head hooks again.
      remove_all_actions('wp_head');
      ob_start();
      locate_template($templates, true);
      ob_get_clean();
    }
  }

  /**
   * Get Footer Site.
   *
   * @return Footer Site.
   */
  public function render_footer()
  {

    $footer_id = $this->template_footer_id();


    if ($footer_id) {

      require ELESPARE_DIR_PATH . 'header-footer/templates/default/footer.php';
      $templates   = array();
      $templates[] = 'footer.php';
      // Avoid running wp_footer hooks again.
      remove_all_actions('wp_footer');
      ob_start();
      locate_template($templates, true);
      ob_get_clean();
    }
  }


  public function elespare_header_template()
  {
    global $post;

    if (! empty($post)) {
      $id   = $post->ID;
      $post_type = get_post_type($post->ID);
    }

    $path      = ELESPARE_DIR_PATH . 'header-footer/templates/content/content-header.php';
    $page_type = $this->page_type();

    if (! empty($page_type)) {

      $header = $this->display_template($page_type);
      if (! $header) {
        $header = $this->display_all();
      }
      $this->render($header, $path);
    }


    if (is_singular()) {

      $header = $this->current_single($id, $post_type);

      if (! $header) {
        $header = $this->all_single($id, $post_type);
        if (! $header) {
          $header = $this->display_all();
        }
      }
      $this->render($header, $path);
    }
  }

  public function elespare_hf_get_header_wrapper_html()
  {

    if (defined('ESHF_COMPATIBILITY_TMPL')) {
      require_once ELESPARE_DIR_PATH . 'header-footer/templates/compat/' . ESHF_COMPATIBILITY_TMPL . '.php';
    }
  }

  public function elespare_hf_get_footer_wrapper_html()
  {
    if (defined('ESHF_COMPATIBILITY_TMPL')): ?>

      </div>
      </div>
<?php
    endif;
  }

  public function elespare_footer_template()
  {
    $id        = $this->post_id;
    $post_type = $this->post_type;
    $path      = ELESPARE_DIR_PATH . 'header-footer/templates/content/content-footer.php';
    $page_type = $this->page_type();
    if (! empty($page_type)) {

      $footer = $this->display_template($page_type, 'footer');
      if (! $footer) {
        $footer = $this->display_all('footer');
      }
      $this->render($footer, $path);
    }

    if (is_singular()) {
      $footer = $this->current_single($id, $post_type, 'footer');

      if (! $footer) {
        $footer = $this->all_single($id, $post_type, 'footer');
        if (! $footer) {
          $footer = $this->display_all('footer');
        }
      }

      $this->render($footer, $path);
    }
  }



  public function display_all($type = 'header')
  {
    $args = array(
      'post_type'           => 'elespare_builder',
      'orderby'             => 'id',
      'order'               => 'DESC',
      'posts_per_page'      => 1,
      'ignore_sticky_posts' => 1,
      'meta_query'          => array(
        array(
          'key'     => 'ele_hf_type',
          'compare' => 'LIKE',
          'value'   => $type,
        ),
        array(
          'key'     => 'ele_hf_display',
          'compare' => 'LIKE',
          'value'   => 'all',
        ),
      ),
    );

    $header = new \WP_Query($args);

    $this->check_ex_post($header);

    if ($header->have_posts()) {
      return $header;
    } else {

      return false;
    }
  }

  /**
   * Template( header or Footer ) Width Conditon For Archive, Blog Page, Search Page.
   * @param (string) $page_type | Type of page
   * @param (string) $type | Type of elespare_builder custom post type
   * @return Mixed (object) $template or (Boolean) false.
   */
  public function display_template($page_type, $type = 'header')
  {


    if (empty($page_type)) {
      return false;
    }
    $args   = array(
      'post_type'           => 'elespare_builder',
      'orderby'             => 'id',
      'order'               => 'DESC',
      'posts_per_page'      => 1,
      'ignore_sticky_posts' => 1,
      'meta_query'          => array(
        array(
          'key'     => 'ele_hf_type',
          'compare' => 'LIKE',
          'value'   => $type,
        ),
        array(
          'key'     => 'ele_hf_display',
          'compare' => 'LIKE',
          'value'   => $page_type,
        ),
      ),
    );
    $header = new \WP_Query($args);

    if ($header->have_posts()) {

      return $header;
    } else {
      return false;
    }
  }

  /**
   * Template( header or Footer ) Width Conditon For Archive, Blog Page, Search Page.
   * @param (int) $id | ID Single Post
   * @param (string) $post_type | Post Type Single Post
   * @param (string) $type | Type of elespare_builder custom post type
   * @return Mixed (object) $template or (Boolean) false.
   */
  public function current_single($id, $post_type, $type = 'header')
  {
    // if ( ! is_singular()  ) {
    // 	return false;
    // }


    $args = array(
      'post_type'           => 'elespare_builder',
      'orderby'             => 'id',
      'order'               => 'DESC',
      'posts_per_page'      => -1,
      'ignore_sticky_posts' => 1,
      'meta_query'          => array(
        array(
          'key'     => 'ele_hf_type',
          'compare' => 'LIKE',
          'value'   => $type,
        ),
        array(
          'key'     => 'ele_hf_post_type',
          'compare' => 'LIKE',
          'value'   => $post_type,
        ),
      ),
    );



    $header = new \WP_Query($args);


    if ($header->have_posts()) {

      $list_header = $header->posts;
      $current     = array();



      foreach ($list_header as $key => $post) {

        $list_id = get_post_meta($post->ID, 'ele_hf_post', true);
        if (! empty($list_id) || 'all' != $list_id) { // phpcs:ignore
          $post_id = explode(',', $list_id);
          if (in_array($id, $post_id)) { // phpcs:ignore
            $current[0] = $post;
          }
        }
      }
      wp_reset_postdata();

      if (empty($current)) {

        return false;
      } else {
        $header->posts      = $current;
        $header->post_count = 1;

        return $header;
      }
    } else {

      return false;
    }
  }

  /**
   * Template( header or Footer ) Width Conditon For Archive, Blog Page, Search Page.
   * @param (int) $post_id | ID Single Post
   * @param (string) $post_type | Post Type Single Post
   * @param (string) $type | Type of elespare_builder custom post type
   * @return Mixed (object) $template or (Boolean) false.
   */
  public function all_single($post_id, $post_type, $type = 'header')
  {
    if (! is_singular()) {
      return false;
    }



    $args   = array(
      'post_type'           => 'elespare_builder',
      'orderby'             => 'id',
      'order'               => 'DESC',
      'posts_per_page'      => 1,
      'ignore_sticky_posts' => 1,
      'meta_query'          => array(
        array(
          'key'     => 'ele_hf_type',
          'compare' => 'LIKE',
          'value'   => $type,
        ),
        array(
          'key'     => 'ele_hf_post_type',
          'compare' => 'LIKE',
          'value'   => $post_type,
        ),
        array(
          'key'     => 'ele_hf_post',
          'compare' => 'LIKE',
          'value'   => 'all',
        ),
      ),
    );
    $header = new \WP_Query($args);

    if ($header->have_posts()) {

      while ($header->have_posts()) {
        $header->the_post();
        $id           = get_the_ID();
        $ex_post      = get_post_meta($id, 'ele_hf_ex_post', true);
        $no_display   = get_post_meta($id, 'ele_hf_no_display', true);
        $ex_post_type = get_post_meta($id, 'ele_hf_ex_post_type', true);
        $list_ex_post = array();
        $post_type    = get_post_type($post_id);
        $list_ex_post = explode(',', $ex_post);
        if ('all' === $ex_post && $post_type === $ex_post_type) {

          return false;
        }
        if (in_array($post_id, $list_ex_post)) { //phpcs:ignore

          return false;
        }
      }
      wp_reset_postdata();

      return $header;
    } else {

      return false;
    }
  }

  // Return Page Type.
  public function page_type()
  {
    $page_type = '';
    if (is_home()) {
      $page_type = 'blog';
    } elseif (is_archive()) {
      $page_type = 'archive';
    } elseif (is_search()) {
      $page_type = 'search';
    } elseif (is_404()) {
      $page_type = 'not_found';
    }

    return $page_type;
  }


  public function render($header, $path)
  {
    if ($header) {
      while ($header->have_posts()) {
        $header->the_post();
        load_template($path);
      }
      wp_reset_postdata();
    }
  }



  /**
   * Check condition exclude.
   *
   * @return (boolean) false | return false if exclude
   */
  public function check_ex_post($header)
  {
    $post_id = $this->post_id;
    if ($header->have_posts()) {
      while ($header->have_posts()) {
        $header->the_post();
        $id           = get_the_ID();
        $ex_post      = get_post_meta($id, 'ele_hf_ex_post', true);
        $no_display   = get_post_meta($id, 'ele_hf_no_display', true);
        $ex_post_type = get_post_meta($id, 'ele_hf_ex_post_type', true);
        $list_ex_post = array();
        $post_type    = get_post_type($post_id);

        if ('blog' === $no_display && is_home()) {

          return false;
        }
        if ('archive' === $no_display && is_archive()) {

          return false;
        }

        if ('search' === $no_display && is_search()) {
          return false;
        }

        if ('not_found' === $no_display && is_404()) {
          return false;
        }

        if (! empty($ex_post) && 'blog' !== $no_display && 'archive' !== $no_display && 'search' !== $no_display && 'not_found' !== $no_display) {
          $list_ex_post = explode(',', $ex_post);
          if ('all' === $ex_post && is_single() && $post_type === $ex_post_type) {

            return false;
          }
          if (in_array($post_id, $list_ex_post)) { //phpcs:ignore
            return false;
          }
        }
      }
      wp_reset_postdata();
    }
  }

  /**
   * Get Header Template ID.
   *
   * @return Mixed (int) $id or (Boolean) false | ID Header Teamplate or false if not found
   */
  public function template_header_id()
  {
    global $post;
    $shopId = get_option('woocommerce_shop_page_id');
    if (! empty($post)) {
      $this->post_id   = $post->ID;
      $this->post_type = get_post_type($post->ID);
    }

    if (class_exists('Woocommerce') && is_shop()) {
      $this->post_id   = $shopId;
      $this->post_type = get_post_type($shopId);
    }

    $post_id              = $this->post_id;
    $maintenance_mode     = get_option('elementor_maintenance_mode_mode');
    $maintenance_template = get_option('elementor_maintenance_mode_template_id');
    if ('coming_soon' == $maintenance_mode && $maintenance_template == $post_id) {
      return false;
    }
    $page_type            = $this->page_type();
    $post_type            = $this->post_type;
    $id                   = '';


    if ($this->display_all() || $this->display_template($page_type) || $this->all_single($post_id, $post_type) || $this->current_single($post_id, $post_type)) {

      if ($this->display_all()) {
        $header = $this->display_all();
      }

      if ($this->display_template($page_type)) {
        $header = $this->display_template($page_type);
      }
      if ($this->all_single($post_id, $post_type)) {
        $header = $this->all_single($post_id, $post_type);
      }
      if ($this->current_single($post_id, $post_type)) {
        $header = $this->current_single($post_id, $post_type);
      }

      while ($header->have_posts()) {
        $header->the_post();
        $id = get_the_ID();
      }
      wp_reset_postdata();

      return $id;
    } else {
      return false;
    }
  }

  /**
   * Get Footer Template ID.
   *
   * @return Mixed (int) $id or (Boolean) false | ID Footer Teamplate or False if not found
   */
  public function template_footer_id()
  {
    global $post;
    $shopId = get_option('woocommerce_shop_page_id');
    if (! empty($post)) {
      $this->post_id   = $post->ID;
      $this->post_type = get_post_type($post->ID);
    }

    if (class_exists('Woocommerce') && is_shop()) {
      $this->post_id   = $shopId;
      $this->post_type = get_post_type($shopId);
    }
    if (! empty($post)) {
      $post_id = $post->ID;
      $post_type        = get_post_type($post->ID);
    }
    $post_id              = $this->post_id;
    $maintenance_mode     = get_option('elementor_maintenance_mode_mode');
    $maintenance_template = get_option('elementor_maintenance_mode_template_id');
    if ('coming_soon' == $maintenance_mode && $maintenance_template == $post_id) {
      return false;
    }
    $page_type            = $this->page_type();
    $post_type            = $this->post_type;
    $id                   = '';
    if ($this->display_all('footer') || $this->display_template($page_type, 'footer') || $this->all_single($post_id, $post_type, 'footer') || $this->current_single($post_id, $post_type, 'footer')) {
      if ($this->display_all('footer')) {
        $header = $this->display_all('footer');
      }
      if ($this->display_template($page_type, 'footer')) {
        $header = $this->display_template($page_type, 'footer');
      }
      if ($this->all_single($post_id, $post_type, 'footer')) {
        $header = $this->all_single($post_id, $post_type, 'footer');
      }
      if ($this->current_single($post_id, $post_type, 'footer')) {
        $header = $this->current_single($post_id, $post_type, 'footer');
      }

      while ($header->have_posts()) {
        $header->the_post();
        $id = get_the_ID();
      }
      wp_reset_postdata();

      return $id;
    } else {

      return false;
    }
  }
}
ElespaeTemplate::instance();
