<?php
add_action( 'rest_api_init', 'blockspare_save_templates_rest_controller');
function blockspare_save_templates_rest_controller() {
    register_rest_route('blockspare-save-templates/v1', '/save_templates', array(
        'methods'=>\WP_REST_Server::EDITABLE,
        'callback'            => 'blockspare_save_templates_callback',
        'permission_callback' => function ($request) {
            $nonce = $request->get_header( 'X-WP-Nonce' );
            if ( ! wp_verify_nonce( $nonce, 'wp_rest' ) && !current_user_can( 'edit_posts' ) ) {
                return new WP_Error( 'rest_forbidden', 'Validation Failed', array( 'status' => 200 ) );
            }
    
            return true;
        },
    ));
}

if(!function_exists('blockspare_custom_post_type')){
    function blockspare_save_templates_callback(\WP_REST_Request $request) {
        $params = $request->get_params();
        $post_content = $params['data'];
        $post_title = $params['title'];
        $category = $params['category'];
        
        $new_post = array(
            'post_content'   => $post_content,
            'post_title'     => $post_title,
            'post_type'      => 'bs_templates',
            'post_status'    => 'publish', // You can set to 'draft' if you don't want to publish immediately
        );
        
        // Insert the post into the database
        $post_id = wp_insert_post($new_post);
        
        // Check if the post was successfully inserted
        if ($post_id) {
            //update_post_meta($post_id, 'bs_template_category',$category);
            $notice='success';
        } else {
            $notice='success';
        }
        return new WP_REST_Response($notice, 200);
    }


    // Register Custom Post Type
    function blockspare_custom_post_type() {

        $labels = array(
            'name'                  => _x( 'My Patterns', 'Post Type General Name', 'blockspare' ),
            'singular_name'         => _x( 'My Patterns', 'Post Type Singular Name', 'blockspare' ),
            'menu_name'             => __( 'My Patterns', 'blockspare' ),
            'parent_item_colon'     => __( 'My Parent Item:', 'blockspare' ),

        );
        $args = array(
            'label'                 => __( 'My Patterns', 'blockspare' ),
            'description'           => __( 'Tempaltes created by users', 'blockspare' ),
            'labels'                => $labels,
            'supports'              => array( 'title', 'editor', 'thumbnail','blockeditor' ),
            'hierarchical'          => false,
            'public'                => true,
            'show_ui'               => true,
            'show_in_menu'          => false,
            'show_in_rest'          =>true,
            'show_in_admin_bar'     => false,
            'show_in_nav_menus'     => false,
            'can_export'            => false,
            'has_archive'           => false, // Set to false to exclude from archives
            'exclude_from_search'   => false,
            
        );
        register_post_type( 'bs_templates', $args );

    }
    add_action( 'init', 'blockspare_custom_post_type',1 );
}