<?php 

defined( 'ABSPATH' ) || exit;

if(!class_exists('Elespare_Dashboard')){
    class Elespare_Dashboard{
        private static $instance;

		public static function instance() {
			if ( ! isset( self::$instance ) ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

        public function __construct(){
            
            add_action( 'admin_menu', array($this,'elespare_register_menu'));
            add_action( 'admin_enqueue_scripts', array( $this, 'load_admin_style' ) );
            include_once ELESPARE_DIR_PATH .'inc/admin/widgets-list.php';
            include_once ELESPARE_DIR_PATH .'inc/admin/class-dashboard.php';            
            include_once ELESPARE_DIR_PATH .'inc/admin/notice-upgrade.php';
            include_once ELESPARE_DIR_PATH .'inc/admin/create-page.php';
            
        }

        public function load_admin_style(){


            wp_enqueue_style(
                'elespare-hf-admin',
                ELESPARE_DIR_URL . 'inc/admin/css/admin.css',
                array(),
                ELESPARE_VERSION
            );

        }

        public function elespare_register_menu(){


            $elespare_icon = <<< SVG
<svg version="1.1" id="Layer_1" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" x="0px" y="0px"
	 viewBox="0 0 200 200" style="enable-background:new 0 0 200 200;" xml:space="preserve">
<path d="M0,0v200h200V0H0z M33.8,50.9c19.5,0,38.8,0,58.7,0c0,6.7,0,12.8,0,19.2c-19.6,0-39,0-58.7,0C33.8,63.7,33.8,57.5,33.8,50.9
	z M33.7,90.6c19.7,0,39,0,58.7,0c0,6.5,0,12.7,0,19.2c-19.7,0-39,0-58.7,0C33.7,103.4,33.7,97.3,33.7,90.6z M166.3,70.1
	c-13.3,0-26.4,0-39.7,0c0,6.8,0,13.6,0,20.5c13.2,0,26.3,0,39.6,0c0,5,0,9.9,0,14.8c0,1.5,0,2.9,0,4.4c0,6.8,0,13.6,0,20.5
	c0,2.2,0,4.4,0,6.6c0,4,0,8,0,12.3c-24.5,0-48.9,0-73.8,0c-2.8,0-5.7,0-8.5,0c-16.6,0-33.3,0-50.2,0c0-6.2,0-12.3,0-18.9
	c16.6,0,33.3,0,50.2,0c2.8,0,5.7,0,8.5,0c18.1,0,36.3,0,54.7,0c0-6.8,0-13.6,0-20.5c-13.2,0-26.3,0-39.6,0c0-4.5,0-8.9,0-13.5
	c0-1.9,0-3.8,0-5.7c0-6.8,0-13.6,0-20.5c0-1.8,0-3.5,0-5.3c0-4.6,0-9.1,0-13.9c19.5,0,38.8,0,58.7,0
	C166.3,57.6,166.3,63.7,166.3,70.1z"/>
</svg>
SVG;

            add_menu_page(
            __('Elespare', 'elespare'), // Page Title.
            __('Elespare', 'elespare'), // Menu Title.
            'edit_posts', // Capability.
            'elespare_dashboard', // Menu slug.
            array($this,'elespare_render_starter_page' ),
            'data:image/svg+xml;base64,' . base64_encode($elespare_icon) ,
            50
            );
            

            // Check if Elementor installed and activated.
            if ( did_action( 'elementor/loaded' ) ) {
            add_submenu_page(
                'elespare_dashboard', // Parent slug.
                __( 'Design Library', 'elespare' ), // Page title.
                __( 'Design Library', 'elespare' ), // Menu title.
                'manage_options', // Capability.
                'elespare_dashboard', // Menu slug.
                array( $this, 'elespare_render_starter_page' ) // Callback function.

            );

                add_submenu_page(
                    'elespare_dashboard', // Parent slug.
                    __('Header/Footer Builder', 'elespare'), // Page title.
                    __('Header/Footer Builder', 'elespare'), // Menu title.
                    'manage_options', // Capability.
                    'elespare_header_footer', // Menu slug.
                    array($this, 'elespare_header_footer_dashboard') // Callback function.

                );
            }

            add_submenu_page(
                'elespare_dashboard', // Parent slug.
                __( 'Explore More', 'elespare' ), // Page title.
                __( 'Explore More', 'elespare' ), // Menu title.
                'manage_options', // Capability.
                'elespare_explore_more', // Menu slug.
                array( $this, 'elespare_render_menu_page' ) // Callback function.

            );

            if(ELESPARE_SHOW_PRO_NOTICES){
                add_submenu_page(
                    'elespare_dashboard', // Parent slug.
                    __( 'Upgrade', 'elespare' ), // Page title.
                    __( "<span class='dashicons dashicons-star-filled' style='font-size: 17px'></span> Upgrade Pro ", 'elespare' ), // Menu title.
                    'manage_options', // Capability.
                    esc_url('https://elespare.com/') // Callback function.

                );
            }

            add_action('admin_head', array($this,'elespare_correct_current_menu'), 50);
        }

        public function elespare_render_menu_page(){
            Elespare_General_Dashboard::elespare_render_menu_page();
        }

        public function elespare_render_starter_page(){?>
         <div id="elespare-demo-list"></div>
        
        <?php }

        public function elespare_header_footer_dashboard(){
            $url = admin_url().'edit.php?post_type=elespare_builder';
            ?>
            <script>location.href='<?php echo esc_url($url);?>';</script>
            <?php
        }
        /**
         * Add/Remove appropriate CSS classes to Menu so Submenu displays open and the Menu link is styled appropriately.
         */
        public function elespare_correct_current_menu(){
            $screen = get_current_screen();

            if ( $screen->id == 'elespare_builder' || $screen->id == 'edit-elespare_builder' ) {
            ?>
            <script type="text/javascript">
            jQuery(document).ready(function($) {
                $('#toplevel_page_elespare_dashboard').addClass('wp-has-current-submenu wp-menu-open menu-top menu-top-first').removeClass('wp-not-current-submenu');
                $('#toplevel_page_elespare_dashboard > a').addClass('wp-has-current-submenu').removeClass('wp-not-current-submenu');
            });
            </script>
            <?php
            }
        
            if ( $screen->id == 'elespare_builder' ) {
            ?>
            <script type="text/javascript">
            jQuery(document).ready(function($) {
                $('a[href$="elespare_header_footer"]').parent().addClass('current');
                $('a[href$="elespare_header_footer"]').addClass('current');
            });
            </script>
            <?php
            }
        
            if ( $screen->id == 'edit-elespare_builder' ) {
            ?>
            <script type="text/javascript">
            jQuery(document).ready(function($) {
                $('a[href$="elespare_header_footer"]').parent().addClass('current');
                $('a[href$="elespare_header_footer"]').addClass('current');
            });
            </script>
            <?php
            }
        }
    }
    Elespare_Dashboard::instance();
}


