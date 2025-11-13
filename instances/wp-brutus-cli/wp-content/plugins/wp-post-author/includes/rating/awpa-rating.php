<?php
defined('ABSPATH') or die('No script kiddies please!'); // prevent direct access

class AWPARating
{
  public $rating_enable_post_type = array();
  public $rating = array();
  public $enable_per_post = false;
  public $awpa_post_rating_title;

  public function __construct()
  {

    $rating = get_option('awpa_pro_rating_settings');
    $this->rating = $rating;
    if (!empty($rating['post_types'])) {
      foreach ($rating['post_types'] as $key => $post_type) {
        if ($post_type['value']) {
          array_push($this->rating_enable_post_type, $post_type['name']);
        }
      }
    }
    if ($rating && $rating['enable_pro_rating']) {
      foreach ($rating['post_types'] as $key => $post_type) {
        if (filter_var($post_type['value'], FILTER_VALIDATE_BOOLEAN)) {
          add_action('add_meta_boxes', array($this, 'awpa_add_rating_metabox'), 10, 1);
          add_action('admin_enqueue_scripts', array($this, 'awpa_rating_review_metabox_register_scripts'));
          add_action('save_post', array($this, 'awpa_pro_rating_review_save_metabox'), 10, 1);
          add_filter('the_content', array($this, 'awpa_pro_rating_review_on_post_content'), 10, 1);
          // add_filter('the_content', array($this, 'awpa_pro_rating_review_on_post_content_by_author'), 10, 1);
        }
      }
    }
    add_shortcode('awpa-rating-review', array($this, 'awpa_rating_review'));

    //to add on frontend post
    add_action('wp_enqueue_scripts', array($this, 'awpa_pro_rating_review_enqueue_style'));
  }




  public function awpa_add_rating_metabox()
  {
    add_meta_box(
      'awpa-rating',
      __('Post Rating Review', 'wp-post-author'),
      array($this, 'awpa_add_rating_metaboxes'),
      $this->rating_enable_post_type,
      'side',
      'high'
    );
  }
  public function awpa_add_rating_metaboxes($post)
  {

    $rating = json_encode($this->rating);
    $rating_checkbox_value = get_post_meta($post->ID, 'awpa_rating_review_enable', true);
    $checked = ($rating_checkbox_value == 'true' || $rating_checkbox_value == '') ? 'checked' : '';
    $rating_checkbox_title = get_post_meta($post->ID, 'awpa_post_rating_title', true);

    $rating_checkbox_title = isset($this->rating['rating_heading']) ? $this->rating['rating_heading'] : __('Love it or Not? Let us know!', 'wp-post-author');



?>
<div class='awpa-checkbox-field'>
  <label for="awpa_rating_review_enable">
    <?php esc_html_e('Enable Rating Review', 'wp-post-author') ?>
    <input type="checkbox" name="awpa_rating_review_enable" id="custom_checkbox" value="true" <?php echo $checked; ?> />
  </label>
</div>
<div class='awpa-field'>
  <label for="awpa_post_rating_title">
    <!-- <?php //echo  esc_html('Title:','wp-post-author')
              ?> -->
    <!-- <input type="text" name="awpa_post_rating_title" id="custom_input" value="<?php //echo esc_attr($rating_checkbox_title); 
                                                                                        ?>" /> -->
    <!-- <?php //echo esc_attr($rating_checkbox_title); 
              ?> -->
  </label>
  <!-- Add a nonce field for security -->
  <?php wp_nonce_field('rating_metabox_nonce', 'rating_metabox_nonce'); ?>
</div>
<div id="awpa-rating-feature" rating="<?php echo esc_attr($rating); ?>"></div>
<!-- <input type="hidden" id="awpa_rating_review_enable" name="awpa_rating_review_enable" /> -->
<input type="hidden" id="awpa_post_rating_type" name="awpa_post_rating_type" />
<!-- <input type="hidden" id="awpa_post_rating_title" name="awpa_post_rating_title" /> -->
<?php
  }
  public function awpa_rating_review_metabox_register_scripts()
  {
    $post_type = get_post_type();
    if ($post_type == 'post') {
      wp_enqueue_script('awpa-rating-review', AWPA_PLUGIN_URL . 'assets/dist/awpa_rating_review.build.js', array(), AWPA_VERSION, true);
      $this->localize_script_dynamic('awpa_rating_review');
    }
  }
  public function awpa_pro_rating_review_enqueue_style()
  {
    $post_type = get_post_type();
    if (in_array($post_type,  $this->rating_enable_post_type)) {
      wp_enqueue_script(
        'awpa-pro-rating-review-frontend',
        AWPA_PLUGIN_URL . 'assets/dist/awpa_rating_review_frontend.build.js',
        array('wp-i18n', 'wp-blocks', 'wp-api-fetch', 'jquery'),
        AWPA_VERSION,
        true
      );

      $this->localize_script_dynamic('awpa_pro_rating_review_frontend');
    }

    wp_enqueue_script(
      'awpa-single-rating-variation-frontend',
      AWPA_PLUGIN_URL . 'assets/dist/single_post_star.build.js',
      array(),
      AWPA_VERSION,
      true
    );

    wp_enqueue_script(
      'awpa-author-rating-variation-frontend',
      AWPA_PLUGIN_URL . 'assets/dist/author_post_star.build.js',
      array(),
      AWPA_VERSION,
      true
    );
  }
  public function localize_script_dynamic($value)
  {
    global $post;
    $post_id = $post ? $post->ID : 0;
    $rating = get_option('awpa_pro_rating_settings');
    $rating_review = '';
    if (isset($rating['rating_review'])) {
      $rating_review = $rating['rating_review'];
    }
    $awpa_post_global_type = get_post_meta($post_id, 'awpa_post_global_type', true);
    $awpa_post_rating_type = get_post_meta($post_id, 'awpa_post_rating_type', true);
    $query_string = $awpa_post_rating_type &&  $awpa_post_rating_type != 'global_type' ?  $awpa_post_rating_type : $rating_review;
    $review_type = "awpa_pro_post_" . $query_string . "_rating_review";
    $this->enable_per_post = get_post_meta($post_id, 'awpa_rating_review_enable', true);
    $this->awpa_post_rating_title = get_post_meta($post_id, 'awpa_post_rating_title', true);
    $rating_review_meta = get_post_meta($post_id, $review_type, true);
    $options = get_option('awpa_setting_options');
    $login_url = '';
    if (isset($options['awpa_login_url'])) {
      if (!empty($options['awpa_login_url'])) {
        $login_url = get_page_link($options['awpa_login_url']);
      }
    }
   
   
    $register_url = '';
    if (isset($options['awpa_register_url'])) {
      if (!empty($options['awpa_register_url'])) {
        $register_url = get_page_link($options['awpa_register_url']);
      }
    }

    wp_localize_script(
      str_replace('_', '-', $value),
      $value,
      array(
        'rating' => $rating,
        'post_id' => $post_id,
        'has_rating_review' => $rating_review_meta ? true : false,
        'rating_review_meta' => json_encode($rating_review_meta),
        'rating_review_enable' => ($this->enable_per_post == '' || $this->enable_per_post == 'true') ? 'true' : 'false',
        'rating_title' => $this->awpa_post_rating_title,
        'logged_in' => is_user_logged_in(),
        'is_admin' => is_admin(),
        'enable_per_post' => ($this->enable_per_post == '' || $this->enable_per_post == 'true') ? 'true' : 'false',
        'awpa_post_rating_type' => $awpa_post_rating_type,
        'post_has_global_type' => $awpa_post_global_type == 'global_type' ? 'true' : 'false',
        'current_user_id' => get_current_user_id(),
        'awpa_login_url' => $login_url,
        'awpa_register_url' => $register_url,
      )
    );
  }

  public function awpa_pro_rating_review_save_metabox($post_id)
  {
    if (!current_user_can('edit_post', $post_id)) {
      return;
    }

    // Verify the nonce field for security

    if (!isset($_POST['rating_metabox_nonce']) || !wp_verify_nonce($_POST['rating_metabox_nonce'], 'rating_metabox_nonce')) {
      return;
    }

    $rating_check_box = isset($_POST['awpa_rating_review_enable']) ? 'true' : 'false';

    //$title =  sanitize_text_field($_POST['awpa_post_rating_title']);
    //$title = $rating_checkbox_title;
    update_post_meta($post_id, 'awpa_rating_review_enable',  $rating_check_box);
    //update_post_meta($post_id, 'awpa_post_rating_title',$title);
    $awpa_post_rating_type = isset($_POST['awpa_post_rating_type']) ? $_POST['awpa_post_rating_type'] : 0;

    if ($awpa_post_rating_type) {
      update_post_meta($post_id, 'awpa_post_rating_type', $awpa_post_rating_type);
    }
  }
  public function awpa_rating_review($attributes)
  {
    return "<div class='awpa-rating-review' id='awpa-rating-review-render'></div>";
  }

  public function awpa_pro_rating_review_on_post_content($content)
  {
    global $post;
    $rating = get_option('awpa_pro_rating_settings');
    $rating_review = '';
    if (isset($rating['rating_review'])) {
      $rating_review = $rating['rating_review'];
    }
    $awpa_post_global_type = get_post_meta($post->ID, 'awpa_post_global_type', true);
    $awpa_post_rating_type = get_post_meta($post->ID, 'awpa_post_rating_type', true);
    $query_string = $awpa_post_rating_type &&  $awpa_post_rating_type != 'global_type' ?  $awpa_post_rating_type : $rating_review;
    $star_type = '';
    if ($query_string == '5_star') {
      $star_type = 5;
    } elseif ($query_string == '3_star') {
      $star_type = 3;
    } elseif ($query_string == '10_star') {
      $star_type = 10;
    }
    $review_type = "awpa_pro_post_" . $query_string . "_rating_review";
    if (is_single() && in_the_loop() && is_main_query()) {
      $rating_settings = get_option('awpa_pro_rating_settings');
      if ($rating_settings['bottom_post_content']) {
        $custom_content = do_shortcode('[awpa-rating-review]');
        $content .= $custom_content;
      }
    }
    return $content;
  }
}

$awpaRating = new AWPARating();