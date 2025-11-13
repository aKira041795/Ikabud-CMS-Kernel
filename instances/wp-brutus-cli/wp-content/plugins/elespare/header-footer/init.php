<?php 

defined( 'ABSPATH' ) || exit;

/**
 * Main Elespare Header Footer Builder
 *
 * @class Elespare_Header_Footer_Builder
 *
 * Written by ptp
 *
 */

 if(!class_exists('Elespare_Header_Footer_Builder')){
     class Elespare_Header_Footer_Builder{
        private static $instance;

		public static function instance() {
			if ( ! isset( self::$instance ) ) {
				self::$instance = new self();
			}
			return self::$instance;
		}


        /**
		 * Elespare Header Footer Builder Constructor.
		 */
		public function __construct() {
			$this->includes();
			$this->hooks();
			$this->cpt();
		}

		public function includes(){
			include_once ELESPARE_DIR_PATH .'header-footer/inc/admin/class-admin-dashboard.php';
            include_once ELESPARE_DIR_PATH .'header-footer/helpers.php';
            include_once ELESPARE_DIR_PATH .'header-footer/inc/admin/class-admin.php';
            include_once ELESPARE_DIR_PATH .'header-footer/inc/admin/class-metabox.php';
            include_once ELESPARE_DIR_PATH .'header-footer/class-template.php';
            include_once ELESPARE_DIR_PATH .'src/hooks/class-hooks.php';
            //include_once ELESPARE_DIR_PATH .'header-footer/inc/admin/widgets-list.php';
            include_once ELESPARE_DIR_PATH .'header-footer/inc/admin/class-dashboard.php';
        }

		public function hooks(){
			add_action( 'init', array( $this, 'elespare_post_types' ) );
		}


		public function cpt() {
			add_post_type_support( 'elespare_builder', 'elementor' );
		}

		public function elespare_post_types(){
			$labels = array(
				'name'                  => _x( 'Elespare Header Footer', 'Post Type General Name', 'elespare-pro' ),
				'singular_name'         => _x( 'Elespare item', 'Post Type Singular Name', 'elespare-pro' ),
				'menu_name'             => esc_html__( 'Elespare Header Footer', 'elespare-pro' ),
				'name_admin_bar'        => esc_html__( 'Elespare item', 'elespare-pro' ),
				'archives'              => esc_html__( 'Item Archives', 'elespare-pro' ),
				'attributes'            => esc_html__( 'Item Attributes', 'elespare-pro' ),
				'parent_item_colon'     => esc_html__( 'Parent Item:', 'elespare-pro' ),
				'all_items'             => esc_html__( 'All Items', 'elespare-pro' ),
				'add_new_item'          => esc_html__( 'Add New Item', 'elespare-pro' ),
				'add_new'               => esc_html__( 'Add New', 'elespare-pro' ),
				'new_item'              => esc_html__( 'New Item', 'elespare-pro' ),
				'edit_item'             => esc_html__( 'Edit Item', 'elespare-pro' ),
				'update_item'           => esc_html__( 'Update Item', 'elespare-pro' ),
				'view_item'             => esc_html__( 'View Item', 'elespare-pro' ),
				'view_items'            => esc_html__( 'View Items', 'elespare-pro' ),
				'search_items'          => esc_html__( 'Search Item', 'elespare-pro' ),
				'not_found'             => esc_html__( 'Not found', 'elespare-pro' ),
				'not_found_in_trash'    => esc_html__( 'Not found in Trash', 'elespare-pro' ),
				'featured_image'        => esc_html__( 'Featured Image', 'elespare-pro' ),
				'set_featured_image'    => esc_html__( 'Set featured image', 'elespare-pro' ),
				'remove_featured_image' => esc_html__( 'Remove featured image', 'elespare-pro' ),
				'use_featured_image'    => esc_html__( 'Use as featured image', 'elespare-pro' ),
				'insert_into_item'      => esc_html__( 'Insert into item', 'elespare-pro' ),
				'uploaded_to_this_item' => esc_html__( 'Uploaded to this item', 'elespare-pro' ),
				'items_list'            => esc_html__( 'Items list', 'elespare-pro' ),
				'items_list_navigation' => esc_html__( 'Items list navigation', 'elespare-pro' ),
				'filter_items_list'     => esc_html__( 'Filter items list', 'elespare-pro' ),
			);
			$rewrite = array(
				'slug'                  => 'elespare-builder',
			);
			$args = array(
				'label'                 => esc_html__( 'Elespare Header Footer', 'elespare-pro' ),
				'description'           => esc_html__( 'elespare_content', 'elespare-pro' ),
				'labels'                => $labels,
				'supports'              => array( 'title', 'editor', 'elementor', 'permalink' ),
				'hierarchical'          => true,
				'public'                => true,
				'show_ui'               => true,
				'show_in_menu'          => false,
				'has_archive' 			=> false,
				'rewrite'               => $rewrite,
				'menu_icon'    => 'dashicons-editor-kitchensink',
			);
			register_post_type( 'elespare_builder', $args );
		}


     }

     Elespare_Header_Footer_Builder::instance();
 }
 