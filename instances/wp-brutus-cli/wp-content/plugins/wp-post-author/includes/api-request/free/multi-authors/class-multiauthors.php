<?php
if (!class_exists('Awpa_Multiauthor_Rest_Controller')) {
  class Awpa_Multiauthor_Rest_Controller
  {


    private $namespace;
    public function __construct()
    {
      $this->namespace = 'aft-wp-post-author/v1';
    }

    public function awpa_multiahuthors_register_routes()
    {
      register_rest_route(
        $this->namespace,
        '/list-guest-authors',
        array(
          array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array($this, 'awpa_list_guest_authors'),
            'permission_callback' => array($this, 'awpa_permission_check'),

          ),
        )
      );

      register_rest_route(
        $this->namespace,
        '/new-guest-author',
        array(
          array(
            'methods'             => WP_REST_Server::EDITABLE,
            'callback'            => array($this, 'awpa_add_new_guest_author'),
            'permission_callback' => array($this, 'awpa_permission_check'),

          ),
        )
      );

      register_rest_route(
        $this->namespace,
        '/status-change-membership-plan',
        array(
          array(
            'methods'             => WP_REST_Server::EDITABLE,
            'callback'            => array($this, 'awpa_new_guest_link_to_user'),
            'permission_callback' => array($this, 'awpa_permission_check'),

          ),
        )
      );

      register_rest_route(
        $this->namespace,
        '/delete-guest-author',
        array(
          array(
            'methods'             => WP_REST_Server::EDITABLE,
            'callback'            => array($this, 'awpa_delete_guest_author'),
            'permission_callback' => array($this, 'awpa_permission_check'),

          ),
        )
      );

      register_rest_route(
        $this->namespace,
        '/get-users',
        array(
          array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array($this, 'awpa_get_user'),
            'permission_callback' => array($this, 'awpa_permission_check'),

          ),
        )
      );
    }


    public function awpa_permission_check($request)
    {
      return current_user_can('manage_options');
    }

    public function awpa_list_guest_authors(\WP_REST_Request $request)
    {
      $authors_per_page = sanitize_text_field($request['per_page']);
      $paged = sanitize_text_field($request['page']);
      $orderby = sanitize_text_field($request['order_by']);
      $order = sanitize_text_field($request['order']);
      $search_term = sanitize_text_field($request['search']);
      $page = isset($paged) ? abs((int) $paged) : 1;
      $offset = (int) ($page * $authors_per_page) - $authors_per_page;
      global $wpdb;
      $table_name = $wpdb->prefix . "wpa_guest_authors";
      if ($search_term) {
        $total_query = "SELECT COUNT(*) FROM $table_name 
                    WHERE 1=1 AND (user_email LIKE '%$search_term%' OR
                    display_name LIKE '%$search_term%' OR
                    user_nicename LIKE '%$search_term%' OR
                    first_name LIKE '%$search_term%' OR
                    last_name LIKE '%$search_term%' OR
                    user_meta LIKE '%$search_term%' OR
                    website LIKE '%$search_term%');";
        $query = "SELECT * FROM $table_name 
                    WHERE 1=1 AND (user_email LIKE '%$search_term%' OR
                    display_name LIKE '%$search_term%' OR
                    user_nicename LIKE '%$search_term%' OR
                    first_name LIKE '%$search_term%' OR
                    last_name LIKE '%$search_term%' OR
                    user_meta LIKE '%$search_term%' OR
                    website LIKE '%$search_term%')
                    ORDER BY $orderby $order LIMIT $offset, $authors_per_page;";
      } else {
        $total_query = "SELECT COUNT(*) FROM $table_name";
        $query = "SELECT * FROM $table_name ORDER BY $orderby $order LIMIT $offset, $authors_per_page;";
      }
      $response['guest_authors_count'] = $wpdb->get_var($total_query);
      $guest_authors = $wpdb->get_results($query, OBJECT);
      foreach ($guest_authors as $key => $guest_author) {
        $guest_authors[$key]->human_readable = human_time_diff(strtotime($guest_author->user_registered));
        if ($guest_author->linked_user_id) {
          $user = get_user_by('ID', $guest_author->linked_user_id);
          $guest_authors[$key]->linked_user = $user ? $user->display_name : __('Guest', 'wp-post-author');
        }
      }
      $response['guest_authors'] = $guest_authors;
      $response['wp_upload_dir'] = wp_upload_dir();

      return $response;
    }

    public function awpa_add_new_guest_author(\WP_REST_Request $request)
    {
      $params = $request->get_params();
      $guest_author = json_decode($params['guest_author'], true);

      $view = $request['view'];
      $wpaMultiAuthor = new WPAMultiAuthors();
      $guest = $wpaMultiAuthor->awpa_ma_get_guest_by_email($guest_author['user_email']);


      if ($view == 'new') {
        $require_input = array(
          'user_email',
          'display_name',
          'first_name',
          'last_name',
          'is_active',
          'linked_user_id',
          'convert_guest_to_author'
        );

        $error = false;
        $error_message = array();

        foreach ($require_input as $input) {
          if (!isset($guest_author[$input]) || $guest_author[$input] === "") {
            $error = true;

            $string = str_replace('_', ' ', $input);
            $string = strtolower($string);
            $string = ucfirst($string);
            $error_message[] = array(
              'key' => esc_attr($input),
              'value' => sprintf(esc_html__('%s is required', 'wp-post-author'), esc_html($string))
            );
          }

          if ($input == 'user_email') {
            $sanitized_email = sanitize_email($guest_author['user_email']);
            if (!is_email($sanitized_email)) {
              $error = true;
              $error_message[] = array(
                'key' => 'user_email',
                'value' => esc_html__('Not a valid email', 'wp-post-author')
              );
            }
          }
        }

        $sanitized_email = sanitize_email($guest_author['user_email']);
        $user_email_exists = email_exists($sanitized_email);
        if ($user_email_exists) {
          $error = true;
          $error_message[] = array(
            'key' => 'user_email',
            'value' => esc_html__('Email registered, please use a different email', 'wp-post-author'),
          );
        }

        $guest_email_exists = $wpaMultiAuthor->awpa_ma_get_guest_by_email($sanitized_email);
        if ($guest_email_exists) {
          $error = true;
          $error_message[] = array(
            'key' => 'user_email',
            'value' => esc_html__('Guest email registered, please use a different email', 'wp-post-author'),
          );
        }

        foreach ($guest_author['user_meta'] as $key => $value) {
          if (!empty($value) || $value === '0') {
            if (!filter_var($value, FILTER_VALIDATE_URL)) {
              $error = true;
              $error_message[] = array(
                'key' => esc_attr($key),
                'value' => esc_html__('Not a valid URL', 'wp-post-author')
              );
            }
          }
        }

        if ($error) {
          return array(
            'message' => esc_html__('Input missing', 'wp-post-author'),
            'data' => $error_message,
            'status' => 424
          );
        }

        $user_type = sanitize_text_field($guest_author['user_type']);
        $id = isset($guest_author['id']) ? intval($guest_author['id']) : 0;
        global $wpdb;
        $table_name = esc_sql($wpdb->prefix . "wpa_guest_authors");
        $guest = $wpaMultiAuthor->get_guest_by_id($id);
        $image_name = '';

        $linked_user_id = isset($guest_author['linked_user_id']) ? intval($guest_author['linked_user_id']) : false;
        $is_guest_author_linked = $wpaMultiAuthor->awpa_is_guest_linked_with_author($linked_user_id);
        if ($is_guest_author_linked) {
          return array(
            'message' => esc_html__('Author linked with other guest!', 'wp-post-author'),
            'data' => array(),
            'status' => 424
          );
        }

        if ($guest_author['convert_guest_to_author'] === false) {
          $email_exists = $wpaMultiAuthor->awpa_ma_get_guest_by_email($sanitized_email);
          if (!$email_exists && $user_type == 'guest') {
            $nicename = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', sanitize_text_field($guest_author['display_name']))));
            $author_exists = $wpaMultiAuthor->awap_ma_get_guest_by_nicename($nicename);
            $nicename = $author_exists ? $nicename . "1" : $nicename;

            if (isset($_FILES['image']) && $_FILES['image']['size'] != 0) {
              $file_name = sanitize_file_name($_FILES['image']['name']);
              $path_parts = pathinfo($file_name);
              $image_name = strtotime('now') . "." . $path_parts['extension'];
              $_FILES['image']['name'] = sanitize_file_name($image_name);
            }

            $this->awpa_author_register_user($guest_author, $image_name, $nicename);

            return array(
              'message' => esc_html__('New guest created', 'wp-post-author'),
              'status' => 200
            );
          } elseif (!$email_exists && $user_type == 'user') {
            $nicename = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', sanitize_text_field($guest_author['display_name']))));
            $author_exists = $wpaMultiAuthor->awap_ma_get_guest_by_nicename($nicename);
            $nicename = $author_exists ? $nicename . "1" : $nicename;
            $user_id = wp_create_user(
              sanitize_text_field($nicename),
              wp_generate_password(8),
              sanitize_email($sanitized_email)
            );

            if ($user_id) {
              $user = new WP_User($user_id);
              $user_role = isset($guest_author['user_role']) ? sanitize_text_field($guest_author['user_role']) : 'author';
              $user->set_role($user_role);
            }

            return array(
              'message' => esc_html__('New user created', 'wp-post-author'),
              'status' => 200
            );
          } else {
            return array(
              'message' => esc_html__('User guest email already registered, please use a different email!', 'wp-post-author'),
              'data' => array(),
              'status' => 424
            );
          }
        }
      }


      if ('edit' == $view) {
        $require_input = array(
          'display_name',
          'first_name',
          'last_name',
          'is_active',
          'linked_user_id',
          'convert_guest_to_author'
        );

        global $wpdb;
        $wpaMultiAuthor = new WPAMultiAuthors();
        $table_name = $wpdb->prefix . "wpa_guest_authors";

        // Sanitize guest_author ID
        $guest_id = intval($guest_author['id']);
        $guest = $wpaMultiAuthor->get_guest_by_id($guest_id);
        $error = false;
        $error_message = array();

        foreach ($require_input as $input) {
          if (!array_key_exists($input, $guest_author) || $guest_author[$input] === "") {
            $error = true;
            $string = ucfirst(strtolower(str_replace('_', ' ', $input)));
            $error_message[] = array(
              'key' => $input,
              'value' => sprintf(__('%s is required', 'wp-post-author'), esc_html($string))
            );
          }
          if ($input == 'user_email') {
            if (!is_email($guest_author['user_email'])) {
              $error = true;
              $error_message[] = array(
                'key' => $input,
                'value' => __('Not valid email', 'wp-post-author')
              );
            }
          }
        }

        foreach ($guest_author['user_meta'] as $key => $value) {
          if ($value || $value == '0') {
            if (!filter_var($value, FILTER_VALIDATE_URL)) {
              $error = true;
              $error_message[] = array(
                'key' => $key,
                'value' => __('Not valid URL', 'wp-post-author')
              );
            }
          }
        }

        if ($error) {
          return array(
            'message' => __('Input missing', 'wp-post-author'),
            'data' => $error_message,
            'status' => 424
          );
        }

        if (isset($guest_author['unlink']) && $guest_author['unlink'] === true) {
          $result = $wpdb->update(
            $table_name,
            array(
              'is_linked' => false,
              'linked_user_id' => null
            ),
            array('id' => $guest_id),
            array('%d', '%d'),
            array('%d')
          );
          if (isset($guest_author['linked_user_id'])) {
            delete_user_meta(intval($guest_author['linked_user_id']), 'wpma_linked_guest');
          }
        }

        $new_data = array(
          'display_name' => isset($guest_author['display_name']) ? sanitize_text_field($guest_author['display_name']) : sanitize_text_field($guest->display_name),
          'first_name' => isset($guest_author['first_name']) ? sanitize_text_field($guest_author['first_name']) : sanitize_text_field($guest->first_name),
          'last_name' => isset($guest_author['last_name']) ? sanitize_text_field($guest_author['last_name']) : sanitize_text_field($guest->last_name),
          'description' => isset($guest_author['description']) ? sanitize_textarea_field($guest_author['description']) : sanitize_textarea_field($guest->description),
          'is_active' => isset($guest_author['is_active']) ? intval($guest_author['is_active']) : intval($guest->is_active),
        );

        $result = $wpdb->update(
          $table_name,
          $new_data,
          array('id' => $guest_id),
          array('%s', '%s', '%s', '%s', '%d'),
          array('%d')
        );

        if (isset($guest_author['convert_guest']) && $guest_author['convert_guest'] == 'guest_to_user') {
          $user_email = sanitize_email($guest_author['user_email']);
          if (email_exists($user_email)) {
            return array(
              'message' => __('Current email address registered on User\'s, cannot be used. Try to create author manually and link it after then!', 'wp-post-author'),
              'description' => '',
              'data' => array(),
              'status' => 424
            );
          }

          $user_id = wp_create_user(
            sanitize_user($guest->user_nicename),
            wp_generate_password(8),
            $user_email
          );

          if ($user_id) {
            $user = new WP_User($user_id);
            $user_role = isset($guest_author['user_role']) ? sanitize_key($guest_author['user_role']) : 'author';
            $user->set_role($user_role);

            $result = $wpdb->update(
              $table_name,
              array(
                'is_linked' => true,
                'linked_user_id' => $user_id,
                'is_active' => false
              ),
              array('id' => $guest_id),
              array('%d', '%d', '%d'),
              array('%d')
            );

            $user_keys = ['display_name', 'user_nicename'];
            $meta_keys = ['description', 'first_name', 'last_name'];

            foreach ($user_keys as $user_key) {
              if (isset($guest->$user_key)) {
                wp_update_user(array('ID' => $user_id, $user_key => sanitize_text_field($guest->$user_key)));
              }
            }

            foreach ($meta_keys as $meta_key) {
              if (isset($guest->$meta_key)) {
                update_user_meta($user_id, $meta_key, sanitize_text_field($guest->$meta_key));
              }
            }

            $this->change_post_meta_value($guest->id, $user_id);
          }

          if (isset($guest_author['linked_user_id'])) {
            update_user_meta(intval($guest_author['linked_user_id']), 'wpma_linked_guest', $guest_id);
          }

          foreach ($guest_author['user_meta'] as $key => $value) {
            if ($value || $value == '0') {
              if ($key == 'website') {
                wp_update_user(array('ID' => $user_id, 'user_url' => sanitize_url($value)));
              } else {
                update_user_meta($user_id, 'awpa_contact_' . sanitize_key($key), sanitize_text_field($value));
              }
            }
          }
        }

        if (
          isset($guest_author['linked_user_id']) && $guest_author['linked_user_id'] != null &&
          isset($guest_author['convert_guest']) && $guest_author['convert_guest'] == 'link_with_user' &&
          isset($guest_author['unlink']) && $guest_author['unlink'] == false
        ) {
          $is_guest_author_linked = $wpaMultiAuthor->awpa_is_guest_linked_with_author($guest_author['linked_user_id']);
          if ($is_guest_author_linked && $guest_author['link_user']) {
            return array(
              'message' => __('Author linked with other guest!', 'wp-post-author'),
              'data' => array(),
              'status' => 424
            );
          }

          $result = $wpdb->update(
            $table_name,
            array(
              'is_linked' => true,
              'linked_user_id' => intval($guest_author['linked_user_id'])
            ),
            array('id' => $guest_id),
            array('%d', '%d'),
            array('%d')
          );

          update_user_meta(intval($guest_author['linked_user_id']), 'wpma_linked_guest', $guest_id);
        }

        if (isset($guest_author['user_meta'])) {
          $user_meta_data['user_meta'] = json_encode($guest_author['user_meta']);
          $result = $wpdb->update(
            $table_name,
            $user_meta_data,
            array('id' => $guest_id),
            array('%s'),
            array('%d')
          );
        }

        return array(
          'message' => $result ? __('Guest updated', 'wp-post-author') : __('Error occurred', 'wp-post-author'),
          'status' => 200
        );
      }
    }

    public function awpa_new_guest_link_to_user(\WP_REST_Request $request)
    {
      $params = $request->get_params();
      $plan_id = absint(array_key_exists('plan_id', $params) ? $params['plan_id'] : 0);
      $status = sanitize_text_field($params['status']);
      global $wpdb;
      $table_name = $wpdb->prefix . "wpa_membership_plan";
      $dbpost = $wpdb->query($wpdb->prepare("UPDATE " . $table_name . " SET status = %d WHERE id = %d", $status, $plan_id));
      return $dbpost;
    }

    public function awpa_delete_guest_author(\WP_REST_Request $request)
    {
      $params = $request->get_params();
      $guest_id = absint(array_key_exists('guest_id', $params) ? $params['guest_id'] : 0);
      global $wpdb;
      $guest_authors = $wpdb->prefix . "wpa_guest_authors";
      $postmeta = $wpdb->prefix . "postmeta";

      $dbpost = $wpdb->query($wpdb->prepare("DELETE FROM " . $guest_authors . " WHERE id = %d", $guest_id));
      $wpdb->query($wpdb->prepare("DELETE FROM " . $postmeta . " WHERE  meta_value = %s", 'guest-' . $guest_id));

      return $dbpost;
    }

    public function awpa_get_user()
    {
      $args = array(
        // 'role__in' => array('author', 'contributor', 'editor', 'subscriber'),
        'role__in' => array('author', 'editor'),
        'fields' => array('ID', 'display_name', 'user_nicename', 'user_login'),
      );
      $response['users'] = get_users($args);

      return $response;
    }

    public function awpa_guest_avatar_upload_dir($dir)
    {
      $awpadir = '/wpa-post-author/guest-avatar';
      $dir['path'] = $dir['basedir'] . $awpadir;
      $dir['url'] = $dir['baseurl'] . $awpadir;
      return $dir;
    }

    public function change_post_meta_value($guest_id, $user_id)
    {
      global $wpdb;
      $table = $wpdb->prefix . "postmeta";
      $meta_value = 'guest-' . $guest_id;
      $query = "SELECT * FROM $table WHERE meta_key = 'wpma_author' AND meta_value = '$meta_value'";
      $results = $wpdb->get_results($query, OBJECT);
      //error_log(json_encode($results));
      foreach ($results as $key => $post_meta) {
        $wpdb->update(
          $table,
          array(
            'meta_value' => $user_id
          ),
          array(
            'meta_id' => $post_meta->meta_id,
          )
        );
      }
    }
    public function awpa_author_register_user($guest_author, $image_name, $nicename, $user_id = null)
    {
      global $wpdb;

      $table_name = esc_sql($wpdb->prefix . "wpa_guest_authors");

      if (!empty($user_id)) {
        $guest_author['linked_user_id'] = intval($user_id);
      }
      $linked_user_id = isset($guest_author['linked_user_id']) ? $guest_author['linked_user_id'] : null;

      // Validate and sanitize linked_user_id
      $linked_user_id = filter_var($linked_user_id, FILTER_VALIDATE_INT);
      if ($linked_user_id === false) {
        // Handle invalid ID
        $linked_user_id = null;
      }

     

      $new_data = array(
        'user_email'      => sanitize_email($guest_author['user_email']),
        'display_name'    => sanitize_text_field($guest_author['display_name']),
        'user_nicename'   => sanitize_text_field($nicename),
        'first_name'      => sanitize_text_field($guest_author['first_name']),
        'last_name'       => sanitize_text_field($guest_author['last_name']),
        'description'     => !empty($guest_author['description']) ? sanitize_text_field($guest_author['description']) : '',
        'user_registered' => gmdate('Y-m-d H:i:s', time()),
        'website'         => '', // You can update this field if needed
        'is_active'       => !empty($guest_author['is_active']) ? 1 : 0, // Boolean as integer (1 or 0)
        'user_meta'       => !empty($guest_author['user_meta']) ? sanitize_text_field(json_encode($guest_author['user_meta'])) : '',
        'is_linked'       =>   $linked_user_id !== null ? 1 : 0,
        'avatar_name'     => !empty($image_name) ? sanitize_text_field($image_name) : null,
        'linked_user_id'  =>  $linked_user_id
      );

      $format = array('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%d');
      $result = $wpdb->insert($table_name, $new_data, $format);

      if ($result && !empty($guest_author['user_email'])) {
        $name = !empty($guest_author['display_name']) ? sanitize_text_field($guest_author['display_name']) : __('Guest User', 'wp-post-author');

        // Send registration mail notification
        do_action('awpa_send_user_registration_mail_notification', array(
          'name'  => $name,
          'email' => sanitize_email($guest_author['user_email'])
        ));
      }
    }
  }
}