<?php 
include_once 'callback-function.php';
if(!class_exists('Awpa_Settings_Rest_Controller')){


    class Awpa_Settings_Rest_Controller{
        private $namespace;
        public function __construct() {
			$this->namespace = 'aft-wp-post-author/v1';
			
		}

        public function awpa_settings_register_routes() {
            register_rest_route(
                $this->namespace,
                '/get-settings' ,
                array(
                    array(
                        'methods'             => WP_REST_Server::READABLE,
                        'callback'            => array( $this, 'awpa_get_settings_api' ),
                        'permission_callback' => array( $this, 'awpa_permission_check' ),
                        
                    ),
                )
            );

            register_rest_route(
                $this->namespace,
                '/set-settings' ,
                array(
                    array(
                        'methods'             => WP_REST_Server::EDITABLE,
                        'callback'            => array( $this, 'awpa_set_settings_api' ),
                        'permission_callback' => array( $this, 'awpa_permission_check' ),
                        
                    ),
                )
            );

            register_rest_route(
                $this->namespace,
                '/get_integration_settings' ,
                array(
                    array(
                        'methods'             => WP_REST_Server::READABLE,
                        'callback'            => array( $this, 'awpa_get_recaptcha_integration_settings' ),
                        'permission_callback' => array( $this, 'awpa_permission_check' ),
                        
                    ),
                )
            );

            register_rest_route(
                $this->namespace,
                '/set_integration_settings' ,
                array(
                    array(
                        'methods'             => WP_REST_Server::EDITABLE,
                        'callback'            => array( $this, 'awpa_set_recaptcha_integration_settings' ),
                        'permission_callback' => array( $this, 'awpa_permission_check' ),
                        
                    ),
                )
            );

            register_rest_route(
                $this->namespace,
                '/author-metabox' ,
                array(
                    array(
                        'methods'             => WP_REST_Server::READABLE,
                        'callback'            => array( $this, 'awpa_get_author_metabox' ),
                        'permission_callback' => array( $this, 'awpa_permission_check' ),
                        
                    ),
                )
            );

            register_rest_route(
                $this->namespace,
                '/author-metabox' ,
                array(
                    array(
                        'methods'             => WP_REST_Server::EDITABLE,
                        'callback'            => array( $this, 'awpa_set_author_metabox' ),
                        'permission_callback' => array( $this, 'awpa_permission_check' ),
                        
                    ),
                )
            );

            register_rest_route(
                $this->namespace,
                '/mail-settings' ,
                array(
                    array(
                        'methods'             => WP_REST_Server::READABLE,
                        'callback'            => array( $this, 'awpa_get_mail_settings' ),
                        'permission_callback' => array( $this, 'awpa_permission_check' ),
                        
                    ),
                )
            );


            register_rest_route(
                $this->namespace,
                '/mail-settings' ,
                array(
                    array(
                        'methods'             => WP_REST_Server::EDITABLE,
                        'callback'            => array( $this, 'awpa_set_mail_settings' ),
                        'permission_callback' => array( $this, 'awpa_permission_check' ),
                        
                    ),
                )
            );
        }  
        
        public function awpa_permission_check( $request ) {
			return current_user_can( 'manage_options' );
		}

        /**
         * WPA Post Author Settings
         */
        public function awpa_get_settings_api(\WP_REST_Request $request){
            $args = array(
                'public'   => true
                
            );
            return new WP_REST_Response([
                'options' =>  awpa_post_author_get_options(),        
                'post_types' => array_diff( get_post_types($args), [ 'attachment', 'awpa_user_form_build', 'elespare_builder', 'elementor_library', 'e-landing-page' ] )
            ], 200);
        }

        public function awpa_set_settings_api(\WP_REST_Request $request){
            $params = $request->get_params();
            if (isset($params['settings'])) {
                awpa_post_author_delete_options();
                awpa_post_author_set_options($params['settings']);
                return new WP_REST_Response([
                    'status' => 200,
                ]);
            }
        }

        /**
         * WPA POST Author Google Recaptcha Integration Settings
         */
        public function awpa_get_recaptcha_integration_settings(\WP_REST_Request $request){
            return rest_ensure_response(awpa_post_author_integration_setting());
        }

        public function awpa_set_recaptcha_integration_settings(\WP_REST_Request $request){
            $params = $request->get_params();
            // awpa_post_author_delete_options();
            awpa_post_author_set_integration_settings($params['integration_settings']);
            return new WP_REST_Response([
                'status' => 200,
            ]);
        }

        public function awpa_get_author_metabox(){
            return rest_ensure_response(awpa_get_author_metabox_setting());
        }

        public function awpa_set_author_metabox(\WP_REST_Request $request){
            $params = $request->get_params();
            awpa_set_author_metabox_setting($params['awpa_author_metabox_integration']);
            return new WP_REST_Response([
                'status' => 200,
            ]);
        }

        /** 
         * WPA POST Author mail Settings, currently WP Mail
         */
        public function awpa_get_mail_settings(){
            return rest_ensure_response(awpa_mail_setting());
        }

        public function awpa_set_mail_settings(\WP_REST_Request $request){
            $params = $request->get_params();
            awpa_mail_settings($params['aft_wpa_mail_settings']);
            return new WP_REST_Response([
                'status' => 200,
            ]);
        }


    }
}    