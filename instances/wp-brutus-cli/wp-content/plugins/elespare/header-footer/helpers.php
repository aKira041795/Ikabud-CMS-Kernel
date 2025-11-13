<?php 
function elespare_pt_support() {
        $post_types       = get_post_types();
        $post_types_unset = array(
            'attachment'          => 'attachment',
            'revision'            => 'revision',
            'nav_menu_item'       => 'nav_menu_item',
            'custom_css'          => 'custom_css',
            'customize_changeset' => 'customize_changeset',
            'oembed_cache'        => 'oembed_cache',
            'user_request'        => 'user_request',
            'wp_block'            => 'wp_block',
            'elementor_library'   => 'elementor_library',
            'elespare_builder'    => 'elespare_builder',
            'elementor-hf'        => 'elementor-hf',
            'elementor_font'      => 'elementor_font',
            'elementor_icons'     => 'elementor_icons',
            'wpforms'             => 'wpforms',
            'wpforms_log'         => 'wpforms_log',
            'acf-field-group'     => 'acf-field-group',
            'acf-field'           => 'acf-field',
            'booked_appointments' => 'booked_appointments',
            'wpcf7_contact_form'  => 'wpcf7_contact_form',
            'scheduled-action'    => 'scheduled-action',
            'shop_order'          => 'shop_order',
            'shop_order_refund'   => 'shop_order_refund',
            'shop_coupon'         => 'shop_coupon',
        );
        $diff             = array_diff( $post_types, $post_types_unset );
        $default          = array(
            ''          =>'Select',
            'all'       => 'All',
            'blog'      => 'Blog Page',
            'archive'   => 'Archive Page',
            'search'    => 'Search Page',
            'not_found' => '404 Page',
        );
        $options          = array_merge( $default, $diff );
    
        return $options;
    }


    function elespare_type_builder() {
        
        $type = array(
            'header'   => __( 'Header', 'elespare' ),
            'footer'   => __( 'Footer', 'elespare' ),
        );
    
        return $type;
    }


    function elspare_header_footer_content() {
        $id   = get_the_ID(); 
        $type = get_post_meta( $id, 'ele_hf_type' );
        if ( empty( $type ) ) {
            $type[0] = 'header';
        }
        if ( 'header' === $type[0] ) {
            $path = ELESPARE_DIR_PATH . 'header-footer/templates/content/content-header.php';
        } 
        if ( 'footer' === $type[0] ) {
            $path = ELESPARE_DIR_PATH . 'header-footer/templates/content/content-footer.php';
        } 
        load_template( $path );
    }


    function elespare_nav_description( $item_output, $item, $depth, $args ) {
        if ( !empty( $item->description ) ) {
            $item_output = str_replace( $args->link_after . '</a>', '<span class="elespare-menu-item-description">' . $item->description . '</span>' . $args->link_after . '</a>', $item_output );
        }
     
        return $item_output;
    }
    
