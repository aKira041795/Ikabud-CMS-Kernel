<?php
if (!class_exists('Awpa_Formbuilder_Rest_Controller')) {

  class Awpa_Formbuilder_Rest_Controller
  {
    /**
     * Type property name.
     */
    const PROP_TYPE = 'type';
    private $namespace;
    public function __construct()
    {
      $this->namespace = 'aft-wp-post-author/v1';
    }

    public function awpa_formbuilder_register_routes()
    {
      register_rest_route(
        $this->namespace,
        '/get_post_data',
        array(
          array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array($this, 'awpa_get_post'),
            'permission_callback' => array($this, 'awpa_permission_check'),

          ),
        )
      );

      register_rest_route(
        $this->namespace,
        '/set_post_data',
        array(
          array(
            'methods'             => WP_REST_Server::EDITABLE,
            'callback'            => array($this, 'awpa_save_post'),
            'permission_callback' => array($this, 'awpa_permission_check'),

          ),
        )
      );

      register_rest_route(
        $this->namespace,
        '/get_post_single_data',
        array(
          array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array($this, 'awpa_get_single_post'),
            'permission_callback' => array($this, 'awpa_permission_check'),

          ),
        )
      );

      register_rest_route(
        $this->namespace,
        '/wpaft_get_user_post',
        array(
          array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array($this, 'awpa_get_user_post'),
            'permission_callback' => array($this, 'awpa_permission_check'),

          ),
        )
      );

      register_rest_route(
        $this->namespace,
        '/get_form_listing',
        array(
          array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array($this, 'awpa_get_form_listing'),
            'permission_callback' => array($this, 'awpa_permission_check'),
            'args'                => array(

              'order_by' => array(
                'validate_callback' => function ($param) {
                  return in_array($param, ['post_date', 'post_title'], true); // Whitelist allowed columns
                },
                'default'           => 'post_date',
                'description'       => 'Column to order by.',
                'sanitize_callback' => 'sanitize_key',
              ),
              'order' => array(
                'validate_callback' => function ($param) {
                  return in_array(strtoupper($param), ['ASC', 'DESC'], true);
                },
                'default'           => 'ASC',
                'description'       => 'Order direction (ASC or DESC).',
              ),
              'search' => array(
                'sanitize_callback' => 'sanitize_text_field',
                'default'           => '',
                'description'       => 'Search term to filter posts.',
              ),
            ),

          ),
        )
      );

      register_rest_route(
        $this->namespace,
        '/awpa-form-trash',
        array(
          array(
            'methods'             => WP_REST_Server::EDITABLE,
            'callback'            => array($this, 'awpa_form_trash'),
            'permission_callback' => array($this, 'awpa_permission_check'),

          ),
        )
      );

      register_rest_route(
        $this->namespace,
        '/awpa-form-data',
        array(
          array(
            'methods'             => WP_REST_Server::EDITABLE,
            'callback'            => array($this, 'awpa_get_single_form'),
            'permission_callback' => array($this, 'awpa_permission_check'),

          ),
        )
      );
    }


    public function awpa_permission_check($request)
    {
      return current_user_can('manage_options');
    }

    public function awpa_get_post(\WP_REST_Request $request)
    {
      $postId = (int) $request['postId'];
      global $wpdb;
      $table_name = $wpdb->prefix . "wpa_form_builder";
      $dbpost = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . $table_name . " WHERE id = %d", $postId));
      $response['post'] = $dbpost;

      return $response;
    }

    public function awpa_save_post(\WP_REST_Request $request)
    {
      $params = $request->get_params();
      $items = $params['items'];
      $postId = $params['postId'];
      $formName = $params['formName'];
      $paymentSettings = $params['paymentSettings'];
      $formSettings = $params['formSettings'];
      global $wpdb;
      $table_name = $wpdb->prefix . "wpa_form_builder";
      $dbpost = $wpdb->query($wpdb->prepare("SELECT * FROM " . $table_name . " WHERE id = %d", $postId));
      if (!(bool) $dbpost) {
        $new_data = array(
          'post_author' => absint(get_current_user_id()),
          'post_title' => sanitize_text_field($formName),
          'post_content' => sanitize_text_field(wp_json_encode($items)),
          'post_status' => 'publish',
          'post_date' => $params['post_date'] ? $params['post_date'] : gmdate('Y-m-d H:i:s', time()),
          'post_date_gmt' => gmdate('Y-m-d H:i:s', time()),
          'post_modified' => $params['post_date'] ? $params['post_date'] : gmdate('Y-m-d H:i:s', time()),
          'post_modified_gmt' => gmdate('Y-m-d H:i:s', time()),
          'payment_data' => sanitize_text_field(wp_json_encode($paymentSettings)),
          'form_settings' => sanitize_text_field(wp_json_encode($formSettings)),
          'social_login_setting' => null,
          'other_settings' => NULL
        );
        $result = $wpdb->insert($table_name, $new_data, array('%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s'));
      } else {

        $new_data = array(
          'id' => absint($postId),
          'post_title' => sanitize_text_field($formName),
          'post_content' => sanitize_text_field(wp_json_encode($items)),
          'payment_data' => sanitize_text_field(wp_json_encode($paymentSettings)),
          'form_settings' => sanitize_text_field(wp_json_encode($formSettings)),
          'social_login_setting' => null
        );
        $result = $wpdb->update($table_name, $new_data, array('id' => $postId), array('%d', '%s', '%s', '%s', '%s', '%s'), array('%d'));
      }

      return rest_ensure_response($result);
    }

    public function awpa_get_single_post(\WP_REST_Request $request)
    {
      $postId = (int) $request['postId'];
      global $wpdb;
      $table_name = $wpdb->prefix . "wpa_form_builder";
      $dbpost = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . $table_name . " WHERE id = %d", $postId));
      $response['post'] = $dbpost;
      $response['roles'] = wp_roles();
      return $response;
    }

    public function awpa_get_user_post(\WP_REST_Request $request)
    {
      $args = [
        'post_type' => 'awpa_user_form_build',
      ];
      $results = new WP_Query($args);
      return rest_ensure_response($results);
    }

    public function awpa_get_form_listing(\WP_REST_Request $request)
    {
      global $wpdb;

      // Sanitize and validate input
      $posts_per_page = isset($request['per_page']) ? absint($request['per_page']) : 10;
      $paged = isset($request['page']) ? absint($request['page']) : 1;
      $orderby = isset($request['order_by']) ? sanitize_key($request['order_by']) : 'post_date';
      $order = isset($request['order']) && in_array(strtoupper($request['order']), ['ASC', 'DESC']) ? strtoupper($request['order']) : 'ASC';
      $search_term = isset($request['search']) ? sanitize_text_field($request['search']) : '';

      $offset = ($paged - 1) * $posts_per_page;

      $builder_table = $wpdb->prefix . "wpa_form_builder";
      $users_table = $wpdb->prefix . "users";

      // Initialize query variables
      $total_query = '';
      $query = '';

      if (!empty($search_term)) {
        // Use prepared statements for queries with user input
        $search_like = '%' . $wpdb->esc_like($search_term) . '%';
        $total_query = $wpdb->prepare(
          "SELECT COUNT(*) 
                  FROM $builder_table 
                  LEFT JOIN $users_table ON $builder_table.post_author = $users_table.ID 
                  WHERE post_title LIKE %s OR display_name LIKE %s",
          $search_like,
          $search_like
        );

        $query = $wpdb->prepare(
          "SELECT * 
                  FROM $builder_table 
                  LEFT JOIN $users_table ON $builder_table.post_author = $users_table.ID 
                  WHERE post_title LIKE %s OR display_name LIKE %s
                  ORDER BY $orderby $order 
                  LIMIT %d, %d",
          $search_like,
          $search_like,
          $offset,
          $posts_per_page
        );
      } else {
        $total_query = "SELECT COUNT(*) FROM $builder_table";
        $query = $wpdb->prepare(
          "SELECT * 
                  FROM $builder_table 
                  ORDER BY $orderby $order 
                  LIMIT %d, %d",
          $offset,
          $posts_per_page
        );
      }

      // Execute queries
      $response['posts_count'] = $wpdb->get_var($total_query);
      $posts = $wpdb->get_results($query, OBJECT);

      // Add additional data to posts
      foreach ($posts as $key => $post) {
        $posts[$key]->author = get_the_author_meta('display_name', $post->post_author);
        $posts[$key]->human_readable = human_time_diff(strtotime($post->post_date));
      }

      // Include roles
      $response['posts'] = $posts;
      $response['roles'] = wp_roles()->role_names;

      return $response;
    }


    public function awpa_form_trash(\WP_REST_REQUEST $request)
    {
      $params = $request->get_params();
      $form_id = (int) $params['form_id'];
      global $wpdb;
      $table_name = $wpdb->prefix . "wpa_form_builder";
      if ($form_id == 1) {
        return array(
          'message' => __('Default form cannot be deleted!', 'wp-post-author'),
          'status' => 500
        );
      }
      $result = $wpdb->delete($table_name, array('id' => $form_id));

      return array(
        'message' => $result == 1 ? __('Form Deleted!', 'wp-post-author') : __('Error occured!', 'wp-post-author'),
        'status' => $result == 1 ? 200 : 500
      );
    }
  }
}
