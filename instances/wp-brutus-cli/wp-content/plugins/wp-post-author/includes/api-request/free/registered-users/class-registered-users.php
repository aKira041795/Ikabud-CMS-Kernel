<?php
if(!class_exists('Awpa_Registered_Users_Rest_Controller')){
    class Awpa_Registered_Users_Rest_Controller{

        private $namespace;
        public function __construct() {
			$this->namespace = 'aft-wp-post-author/v1';
		
		}

        public function awpa_registered_user_register_routes() {
            register_rest_route(
                $this->namespace,
                '/membership-listing' ,
                array(
                    array(
                        'methods'             => WP_REST_Server::READABLE,
                        'callback'            => array( $this, 'awpa_registered_users_listing' ),
                        'permission_callback' => array( $this, 'awpa_permission_check' ),
                        
                    ),
                )
            );
        } 

        public function awpa_permission_check($request){
                return current_user_can( 'manage_options' );
        }

        public function awpa_registered_users_listing(\WP_REST_Request $request)
        {
            $posts_per_page = sanitize_text_field($request['per_page']);
            $paged = sanitize_text_field($request['page']);
            $orderby = sanitize_text_field($request['order_by']);
            $order = sanitize_text_field($request['order']);
            $search_term = sanitize_text_field($request['search']);
            $page = isset($paged) ? abs((int) $paged) : 1;
            $offset = ($page * $posts_per_page) - $posts_per_page;
            global $wpdb;
            $subscription_table = $wpdb->prefix . "wpa_subscriptions";
            $users = $wpdb->prefix . "users";
        
            if ($search_term) {
                $total_query = "SELECT COUNT(*) FROM $subscription_table 
                    INNER JOIN $users ON $users.ID=$subscription_table.user_id 
                    WHERE 1=1 AND (user_email LIKE '%$search_term%' OR
                    display_name LIKE '%$search_term%' OR
                    user_nicename LIKE '%$search_term%' OR
                    membership_type LIKE '%$search_term%');";
        
                $query = "SELECT * FROM $subscription_table
                    INNER JOIN $users ON $users.ID=$subscription_table.user_id 
                    WHERE 1=1 AND (user_email LIKE '%$search_term%' OR
                    display_name LIKE '%$search_term%' OR
                    user_nicename LIKE '%$search_term%' OR
                    membership_type LIKE '%$search_term%')
                    ORDER BY $orderby $order LIMIT $offset, $posts_per_page;";
            } else {
                $total_query = "SELECT COUNT(*) FROM $subscription_table  
                    INNER JOIN $users ON $users.ID = $subscription_table.user_id";
        
                $query = "SELECT * FROM $subscription_table
                    INNER JOIN $users ON $users.ID=$subscription_table.user_id
                    ORDER BY $orderby $order LIMIT $offset, $posts_per_page;";
            }
        
            $response['posts_count'] = $wpdb->get_var($total_query);
            $posts = $wpdb->get_results($query, OBJECT);
            foreach ($posts as $key => $post) {
                $posts[$key]->author = ucfirst(get_the_author_meta('display_name', $post->user_id));
                $posts[$key]->role = get_userdata($post->user_id)->roles;
                $posts[$key]->nickname = get_user_meta($post->ID, 'nickname');
                unset($posts[$key]->user_pass);
            }
            $response['posts'] = $posts;
            $response['membership_addon_active'] = class_exists('WP_Post_Author_Membership_Plans_Addon');
        
            return $response;
        }
    }
}        