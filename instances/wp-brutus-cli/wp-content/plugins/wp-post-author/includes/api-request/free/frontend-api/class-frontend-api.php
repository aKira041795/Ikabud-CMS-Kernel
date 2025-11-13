<?php
if(!class_exists('Awpa_Frontend_Form_Builder_Rest_Controller')){

    class Awpa_Frontend_Form_Builder_Rest_Controller{

        private $namespace;
        public function __construct() {
			$this->namespace = 'aft-wp-post-author/v1';
			
		}

        public function awpa_frontend_form_register_routes() {
            register_rest_route(
                $this->namespace,
                '/frontend/get-post-data' ,
                array(
                    array(
                        'methods'             => WP_REST_Server::READABLE,
                        'callback'            => array( $this, 'awpa_frontend_get_post' ),
                        'permission_callback' => function ($request){
                            $nonce = $request->get_header( 'X-WP-Nonce' );
                            if ( ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
                                return new WP_Error( 'rest_forbidden', 'Validation Failed', array( 'status' => 200 ) );
                            }
                    
                            return true;
                        }
                        
                    ),
                )
            );

            register_rest_route(
                $this->namespace,
                '/frontend/validate-register-user' ,
                array(
                    array(
                        'methods'             => 'POST',
                        'callback'            => array( $this, 'awpa_validate_register_user' ),
                        'permission_callback' => function ($request){
                            $nonce = $request->get_header( 'X-WP-Nonce' );
                            if ( ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
                                return new WP_Error( 'rest_forbidden', 'Validation Failed', array( 'status' => 200 ) );
                                
                              
                            }
                    
                            return true;
                        }
                        
                    ),
                )
            );

            register_rest_route(
                $this->namespace,
                '/frontend/register-user' ,
                array(
                    array(
                        'methods'             => 'POST',
                        'callback'            => array( $this, 'awpa_register_user' ),
                        'permission_callback' => function ($request){
                            $nonce = $request->get_header( 'X-WP-Nonce' );
                            if ( ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
                                return new WP_Error( 'rest_forbidden', 'Validation Failed', array( 'status' => 200 ) );
                            }
                    
                            return true;
                        }
                        
                    ),
                )
            );
        }

        public function awpa_frontend_get_post(\WP_REST_Request $request){
            $postId = (int) $request['postId'];
            global $wpdb;
            $table_name = $wpdb->prefix . "wpa_form_builder";
            $dbpost = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . $table_name . " WHERE id = %d", $postId));
            $response['post'] = $dbpost;
            $membership_plans = json_decode($dbpost->payment_data, true)['membershipPlans'];
            $response['membership_plan'] = [];
            
        
            foreach ($membership_plans as $key => $plan) {
                array_push($response['membership_plan'], $this->get_membership_plans_by_id($plan['id']));
            }
            $response['recaptcha_integration'] = awpa_post_author_integration_setting();    
            $response['wp_upload_dir'] = wp_upload_dir();
        
            return $response;
        }

        /*
        * User registration
        */
        public function awpa_validate_register_user(\WP_REST_Request $request)
        {
            $error_messages = [];
            $params = $request->get_params();
            $formInput = $params['formInput'] ? json_decode($params['formInput'], true) : [];
            $post_id = json_decode($params['formId']);
            global $wpdb;
            $table_name = $wpdb->prefix . "wpa_form_builder";
            $form = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . $table_name . " WHERE id = %d", $post_id));
            $post_content = json_decode($form->post_content, true);
            $data = [];
            $error_state = false;
            $form_image_key = ['profile_image', 'image'];
            $membership = $params['membership'];

            //advance form detail check
            foreach ($post_content as $index => $post_input) {
                if ($post_input['type'] == 'privacy_policy') {
                    break;
                }
                $data = [];
                $name = $post_input['name'];
                $id = $post_input['id'];
                foreach ($formInput as $key => $input) {
                    if ($input['id'] == $id) {
                        $data = $input;
                    }
                }
                $data = $data ? $data : ['value' => ''];
                if (in_array($name, $form_image_key)) {
                    $file_size = $_FILES[$name]['size'];
                    if ($post_input['required'] && !$_FILES[$name]) {
                        $error_state = true;
                        array_push($error_messages, ['key' => $name, 'id' => $id, 'value' => str_replace('_', ' ', ucfirst($name)) . ' is required']);
                    }
                    $image_type_array = [];
                    $file_type_valid = false;
                    foreach ($post_input['type'] as $index => $type) {
                        if (wp_check_filetype_and_ext($_FILES[$name], $_FILES[$name]['name'])['ext'] == $type['name']) {
                            $file_type_valid = true;
                            break;
                        } else {
                            $file_type_valid = false;
                        }
                    }
                    if ($_FILES[$name] && !$file_type_valid) {
                        $error_state = true;
                        array_push($error_messages, ['key' => $name, 'id' => $id, 'value' => __('Image extension not valid', 'wp-post-author')]);
                    }
                    if ($_FILES[$name] && $file_size > $post_input['size_limit'] * 1024 * 1024) {
                        $error_state = true;
                        array_push($error_messages, ['key' => $name, 'id' => $id, 'value' => sprintf( __('Image size is larger %s MB limit', 'wp-post-author'), $post_input['size_limit'])]);
                    }
                } else {
                    if ($post_input['required']) {
                        if (empty($data['value'])) {
                            $error_state = true;                    
                            array_push($error_messages, ['key' => $name, 'id' => $id, 'value' => sprintf( __('%s is required', 'wp-post-author'), str_replace('_', ' ', ucfirst($name)))]);
                        }
                    }
                    if (($name == 'secondary_email' || $name == 'email') && $data['value']) {
                        if (!is_email($data['value'])) {
                            $error_state = true;
                            array_push($error_messages, ['key' => $name, 'id' => $id, 'value' => __('Not a valid email', 'wp-post-author')]);
                        }
                        if (email_exists($data['value'])) {
                            $error_state = true;
                            array_push($error_messages, ['key' => 'email', 'id' => $id, 'value' => __('Given email is already registered', 'wp-post-author')]);
                        }
                    }

                    if ($name == 'number' || $post_input['required']) {
                        if (!is_numeric((float) $data['value'])) {
                            $error_state = true;
                            array_push($error_messages, ['key' => $name, 'id' => $id, 'value' => __('Not a valid number', 'wp-post-author')]);
                        }
                    }
                    if ($name == 'user_login') {
                        if (username_exists($data['value'])) {
                            $error_state = true;
                            array_push($error_messages, ['key' => $name, 'id' => $id, 'value' => __('Login name already exists', 'wp-post-author')]);
                        }
                    }
                    if ($name == 'web_site' && $data['value']) {
                        if (filter_var($data['value'], FILTER_VALIDATE_URL) === false) {
                            $error_state = true;
                            array_push($error_messages, ['key' => $name, 'id' => $id, 'value' => __('Not a valid website URL', 'wp-post-author')]);
                        }
                    }
                    if ($name == 'password' && $data['value'] && strlen($data['value']) < 8) {
                        $error_state = true;
                        array_push($error_messages, ['key' => $name, 'id' => $id, 'value' => __('Password minimum length: 8', 'wp-post-author')]);
                    }
                }
            }
            if ($error_state) {
                $response = new WP_REST_Response([
                    "errors" => $error_messages,
                    "message" => __('Validation Failed', 'wp-post-author'),
                ]);
                $response->set_status(200);

                return $response;
            } else {
                $response = new WP_REST_Response([
                    "errors" => 'no-error',
                    "message" => __('Validation Ok!', 'wp-post-author'),
                ]);
                $response->set_status(200);

                return $response;
            }
        }

        public function get_membership_plans_by_id($id){
            global $wpdb;
            $table_name = $wpdb->prefix . "wpa_membership_plan";
            $membership_plan = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . $table_name . " WHERE id = %d", $id));
            return $membership_plan;
        }

        public function awpa_register_user(\WP_REST_Request $request)
        {
            $registration_allowed = get_option('users_can_register',true);
            if($registration_allowed == '0' || $registration_allowed == false){
                return new WP_Error( 'rest_forbidden', esc_html__( 'You do not have permission to access this endpoint.', 'wp-post-author' ), array( 'status' => 403 ) );
            }
            $error_messages = array();
            $params = $request->get_params();
            $formInput = json_decode($params['formInput'], true);
            $post_id = (int) json_decode($params['formId']);
            global $wpdb;
        
            $table_name = $wpdb->prefix . "wpa_form_builder";
            $form = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . $table_name . " WHERE id = %d", $post_id));
            $form_settings =  json_decode($form->form_settings);
           
            $post_content = json_decode($form->post_content, true);
            $basic_input = array(
                'email', 'password', 'first_name', 'last_name', 'description', 'nickname',
                'display_name', 'user_login', 'web_site'
            );
            $keys = $this->get_post_content_keys($post_content);
            $basicFormInputData = array();
            foreach ($formInput as $key => $input) {
                if (in_array($input['key'], $basic_input)) {
                    $basicFormInputData[$input['key']] = sanitize_text_field($input['value']);
                }
            }
            $membership = $params['membership'];
        
            $user_id = wp_create_user(
                $basicFormInputData['user_login'] ?
                    sanitize_text_field($basicFormInputData['user_login']) :
                    sanitize_email($basicFormInputData['email']),
                $basicFormInputData['password'],
                sanitize_email($basicFormInputData['email'])
            );
        
            if ($user_id) {
                $user = new WP_User($user_id);
                $user->remove_role('author');
                $user->remove_role('subscriber');
                $user->remove_role('adminstrator');
                $user->remove_role('editor');
                $user->remove_role('translator');
                $user->remove_role('contributor');
                
                if (isset($form_settings->author_type)) {
                   
                    $user->add_role($form_settings->author_type);
                } else {
                    
                    $user->add_role('subscriber');
                }
               
        
                if ($membership == 'free_no_plan') {
                    $this->awpa_create_free_user_membership($user_id);
                }
                //add user meta data
                if (in_array('first_name', $keys) && array_key_exists('first_name', $basicFormInputData)) {
                    update_user_meta($user_id, 'first_name', sanitize_text_field($basicFormInputData['first_name']));
                }
                if (in_array('last_name', $keys) && array_key_exists('last_name', $basicFormInputData)) {
                    update_user_meta($user_id, 'last_name', sanitize_text_field($basicFormInputData['last_name']));
                }
                if (in_array('description', $keys) && array_key_exists('description', $basicFormInputData)) {
                    update_user_meta($user_id, 'description', sanitize_text_field($basicFormInputData['description']));
                }
                if (in_array('nickname', $keys) && array_key_exists('nickname', $basicFormInputData)) {
                    wp_update_user(['ID' => $user_id, 'nickname' => sanitize_text_field($basicFormInputData['nickname'])]);
                }
                if (in_array('display_name', $keys) && array_key_exists('display_name', $basicFormInputData)) {
                    wp_update_user(['ID' => $user_id, 'display_name' => sanitize_text_field($basicFormInputData['display_name'])]);
                }
                if (in_array('web_site', $keys) && array_key_exists('web_site', $basicFormInputData)) {
                    wp_update_user(['ID' => $user_id, 'user_url' => sanitize_url($basicFormInputData['web_site'])]);
                }
                //need to add advance filed name here in future
                $advanceInputFormData = array();
                $advance_input = array('input_field', 'secondary_email', 'country', 'textarea', 'number', 'date', 'checkbox', 'select', 'radiobox', 'multiselect');
                foreach ($formInput as $key => $input) {
                    if (in_array($input['key'], $advance_input)) {
                        array_push($advanceInputFormData, $input);
                    }
                }
                if ($advanceInputFormData) {
                    add_user_meta($user_id, 'advance_input', json_encode($advanceInputFormData));
                }
                if (in_array('profile_image', $keys)) {
                    $upload_overrides = array(
                        'test_form' => false,
                    );
                    $movefile = wp_handle_upload($_FILES['profile_image'], $upload_overrides);
                    if (isset($movefile['error'])) {
                        $error_state = true;
                        array_push($error_messages, ['key' => 'awpa-image-user', 'value' => $movefile['error']]);
                    }
                    add_user_meta($user_id, 'profile_image', $movefile['url']);
                }
        
                //send mail notification for registered user.
                $name =  $basicFormInputData['display_name'] ? $basicFormInputData['display_name'] : 'User';
                do_action('awpa_send_user_registration_mail_notification', array(
                    'name' => $name,
                    'email' => $basicFormInputData['email']
                ));
        
                return rest_ensure_response([
                    'message' => __('User created successfully', 'wp-post-author'),
                    'status' => 201,
                    'user_id' => $user_id
                ]);
            }
        }

        public function get_input_index($post_content, $id){
            foreach ($post_content as $key => $input) {
                if ($input['id'] == $id) {
                    return $key;
                }
            }
        }

        public function get_post_content_keys($post_content){
            $array = [];
            foreach ($post_content as $key => $input) {
                array_push($array, $input['name']);
            }
            return $array;
        }

        public function get_post_content_ids($post_content){
            $array = [];
            foreach ($post_content as $key => $input) {
                array_push($array, $input['id']);
            }
            return $array;
        }

        

        public function get_post_content_required($post_content){
            $array = [];
            foreach ($post_content as $key => $input) {
                array_push($array, $input['required']);
            }
            return $array;
        }

        public function awpa_create_free_user_membership($user_id){
            global $wpdb;
            $table_name = $wpdb->prefix . "wpa_subscriptions";
            $wpdb->insert($table_name, array(
                'user_id' => absint($user_id),
                'plan_name' => 'Free',
                'plan_id' => '0',
                'status' => 'active',
                'gateway' => 'Manual',
                'membership_type' => 'free',
                'quantity' => 1,
                'starts_from' => date('Y-m-d H:i:s', strtotime('now')),
                'trial_ends_at' => null,
                'ends_at' => date('Y-m-d H:i:s', strtotime('+1 years')),
                'created_at' => date('Y-m-d H:i:s', strtotime('now')),
                'updated_at' => date('Y-m-d H:i:s', strtotime('now')),
            ), array('%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s'));
        }
    }
}            