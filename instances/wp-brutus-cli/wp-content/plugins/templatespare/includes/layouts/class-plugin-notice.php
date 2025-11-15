<?php 

class AFTMLS_Admin_Notice {
    private $dismiss_notice_key = 'templatespare_notice_dismissed';
    private $pliugin_name;
	private $screenshot;

    public function __construct() {
        $this->screenshot =  AFTMLS_PLUGIN_URL."assets/images/search-background.png";

        if ( get_option( $this->dismiss_notice_key ) !== 'yes' ) {
			add_action( 'admin_notices', [ $this, 'templatespare_admin_notice' ], 0 );
			add_action( 'wp_ajax_templatespare_notice_dismiss', [ $this, 'templatespare_notice_dismiss' ] );
		}
    }

    public function templatespare_admin_notice(){


        $current_screen = get_current_screen();      
        
        if ( $current_screen->id !=='dashboard' && $current_screen->id !== 'themes'   && $current_screen->id!== 'plugins'  && $current_screen->id!== 'plugins') {
            
			return;
		}

        if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
			return;
		}

		if ( is_network_admin() ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

        global $current_user;
		$user_id          = $current_user->ID;
		$dismissed_notice = get_user_meta( $user_id, $this->dismiss_notice_key, true );


		if ( $dismissed_notice === 'dismissed' ) {
			update_option( $this->dismiss_notice_key, 'yes' );
		}

		if ( get_option( $this->dismiss_notice_key, 'no' ) === 'yes' ) {
			return;
		}
        echo '<div class="templatespare-notice-content-wrapper updated notice">';
		echo '<button type="button" class="notice-dismiss templatespare-dismiss-notice"><span class="screen-reader-text">Dismiss this notice.</span></button>';
        $this->templatespare_dashboard_notice_content();
        echo '</div>';
    }

    public function  templatespare_dashboard_notice_content(){
        $af_companion_title = __( 'Explore All Sites ✨', 'templatespare' );
			$af_companion_url = site_url( ).'/wp-admin/admin.php?page=templatespare-main-dashboard';
            $main_template = '<div class="templatespare-notice-wrapper">%1$s
                <div class="templatespare-notice-msg-wrapper">%2$s %3$s %4$s  </div>
                </div>';
                $notice_header = sprintf(
                    '<h2>%1$s</h2><p class="about-description">%2$s</p></hr>',
                    esc_html__( 'Howdy!', 'templatespare' ),
                    esc_html__( 'Welcome to Templatespare. We\'ve assembled some links to get you started with better experience.', 'templatespare' )
                        
                    
                );
                $notice_picture    = sprintf(
                    '<div class="templatespare-notice-col-1"><figure>
                            <img src="%1$s"/>
                        </figure></div>',
                    esc_url( $this->screenshot )
                );

                $demo_link = "https://templatespare.com/";
                $all_themes_link = "https://afthemes.com/all-themes-plan/";
                $notice_starter_msg =sprintf(
                    '<div class="templatespare-notice-col-2">
                        <div class="templatespare-general-info">
                            <h3><span class="dashicons dashicons-images-alt2">
                            </span>%1$s</h3>
                            <p>%2$s</p>
                        </div>
                        <div class="templatespare-general-info-link">
                            <div>
                                <a href="%3$s" class="button button-primary">%4$s</a>
                                
                                
                            </div>
                            <div>
                                <a href="%5$s" target="_blank"><span aria-hidden="true" class="dashicons dashicons-external"></span>%6$s</a>
                                <a href="%7$s" target="_blank"><span aria-hidden="true" class="dashicons dashicons-external"></span>%8$s</a>
                            </div>
                        </div>
                        </div>',
                    __( 'Create Your Dream Website with Easy Starter Sites! 🚀', 'templatespare' ),
                    esc_html__( 'Explore a world of creativity with 300+ ready-to-use website templates! From chic blogs to dynamic news platforms, engaging magazines, and professional agency websites - find your perfect online space! 🌟', 'templatespare' ),
                    
                    $af_companion_url,
                    $af_companion_title,
                    esc_url($demo_link),
                    esc_html__('Demos','templatespare'),
                    esc_url($all_themes_link),
                    esc_html__('Access All Sites','templatespare')
                    
                    
                );
                $notice_external_msg =sprintf(
                    '<div class="templatespare-notice-col-3">
                    <div class="templatespare-documentation">
                        <h3><span class="dashicons dashicons-format-aside"></span>%1$s</h3>
                        <p>%8$s</p>
                    </div>
                    <div class="templatespare-documentation-links">
                        
                        <div>
                           
                            <a href="https://wordpress.org/support/plugin/templatespare/reviews/?filter=5#new-post" target="_blank" class="button">%7$s</a>
                        </div>
                        <div>
                            <a href="https://docs.afthemes.com/" target="_blank"><span aria-hidden="true" class="dashicons dashicons-external"></span>%3$s</a>
                            <a href="https://www.youtube.com/@wpafthemes" target="_blank"><span aria-hidden="true" class="dashicons dashicons-external"></span>%4$s</a>
                            <a href="https://afthemes.com/blog/" target="_blank"><span aria-hidden="true" class="dashicons dashicons-external"></span>%5$s</a>
                            <a href="https://afthemes.com/supports/" target="_blank"><span aria-hidden="true" class="dashicons dashicons-external"></span>%6$s</a>
                        </div>
                    </div>
                    </div>',
                    __( 'Big thanks for choosing Templatespare 🙌', 'templatespare' ),
                    esc_html__( 'Please check our full documentation for detailed information on how to setup and customize the theme.', 'templatespare' ),
                    esc_html__( 'Docs', 'templatespare' ),
                    esc_html__( 'Videos', 'templatespare' ),
                    esc_html__( 'Blog', 'templatespare' ),
                    esc_html__( 'Support', 'templatespare' ),
                    esc_html__( 'Share the Love ⭐️', 'templatespare' ),
                    esc_html__( 'Love our one-click importer? Please give us rating on WordPress.org. Your support keeps us motivated to add more free features. Thanks! 🌟', 'templatespare' ),
        
                );
                echo sprintf($main_template,
                $notice_header,                
                $notice_starter_msg,
                $notice_picture,
                $notice_external_msg
	            );
    }

    public function templatespare_notice_dismiss() {
       
        check_ajax_referer('aftc-ajax-verification', 'security');
        
		update_option( $this->dismiss_notice_key, 'yes' );
		$json = array(
			'status' => 'success'
			
		);
		wp_send_json($json);
		wp_die();
	}

}
new AFTMLS_Admin_Notice();
