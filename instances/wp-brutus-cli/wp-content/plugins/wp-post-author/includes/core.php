<?php

if (!defined('ABSPATH')) {
  exit; // Exit if accessed directly
}


/**
 * WP Post Author
 *
 * Allows user to get WP Post Author.
 *
 * @class   WP_Post_Author_Core
 */


class WP_Post_Author_Core
{
  /**
   * Init and hook in the integration.
   *
   * @return void
   */
  public $id;
  public $method_title;
  public $method_description;

  public function __construct()
  {
    $this->id                 = 'WP_Post_Author_Core';
    $this->method_title       = __('WP Post Author Core', 'wp-post-author');
    $this->method_description = __('WP Post Author Core', 'wp-post-author');

    include_once 'awpa-backend.php';
    include_once 'awpa-functions.php';
    include_once 'awpa-shortcodes.php';
    include_once 'awpa-frontend.php';

    add_action('rest_api_init', array($this, 'awpa_post_author_api_endpoints'));
    add_action('wp_ajax_awpa_pro_api_post_rating_review', [$this, 'awpa_pro_api_post_rating_review']);
    add_action('wp_ajax_awpa_pro_api_post_rating_review_user_list', [$this, 'awpa_pro_api_post_rating_review_user_list']);
    add_action('wp_ajax_nopriv_awpa_pro_api_post_rating_review_user_list', [$this, 'awpa_pro_api_post_rating_review_user_list']);
  }

  public function awpa_post_author_api_endpoints()
  {
    $awpa_settings = new Awpa_Settings_Rest_Controller();
    $awpa_settings->awpa_settings_register_routes();

    $awpa_membership = new Awpa_Registered_Users_Rest_Controller();
    $awpa_membership->awpa_registered_user_register_routes();

    $awpa_form_builder = new Awpa_Formbuilder_Rest_Controller();
    $awpa_form_builder->awpa_formbuilder_register_routes();

    $awpa_frontend_api = new Awpa_Frontend_Form_Builder_Rest_Controller();
    $awpa_frontend_api->awpa_frontend_form_register_routes();

    $awpa_multiauthors = new Awpa_Multiauthor_Rest_Controller();
    $awpa_multiauthors->awpa_multiahuthors_register_routes();

    $awpa_ratings =  new Awpa_Ratings_Rest_Controller();
    $awpa_ratings->awpa_ratings_register_routes();
  }
  public function awpa_pro_api_post_rating_review()
  {

    $awpa_ratings =  new Awpa_Ratings_Rest_Controller();
    $awpa_ratings->awpa_pro_api_post_rating_review();
  }

  public function awpa_pro_api_post_rating_review_user_list()
  {
    $post_id = $_GET['post_id'];
   $awpa_rating_data= get_post_meta($post_id, 'awpa_pro_post_5_star_rating_review', true);
    if (!empty($awpa_rating_data) && is_array($awpa_rating_data)) {

      $rating_with_user = array();
      // Access ratings array only
      $awpa_post_ratings = isset($awpa_rating_data['ratings']) ? $awpa_rating_data['ratings'] : null;

      if ($awpa_post_ratings) {
        foreach ($awpa_post_ratings as $key => $value) {
          $user_id = str_replace('id_', '', $key);
          //$rating_with_user[(int)$user_id] = (int)$value;

          $user_info = get_userdata((int)$user_id);

          $user_gravatar = get_avatar($user_id, 64);

          $rating_with_user[] = [
            'userName' => $user_info->display_name,
            'gravatar' => $user_gravatar,
            'rating' => (int)$value,
            'authorUrl' => esc_url(get_author_posts_url($user_id))
          ];
        }
      }
    }
    if (!empty($rating_with_user)) {

      wp_send_json_success($rating_with_user);
    } else {
      wp_send_json_success([]);
    }

    wp_die();
  }
}

$awpa_frontend = new WP_Post_Author_Core();