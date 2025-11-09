<?php
// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

class Newsx_Welcome_Notice {

	public function __construct() {
		add_action( 'wp_loaded', [$this, 'welcome_notice'], 20 );
		add_action( 'wp_loaded', [$this, 'hide_notices'], 15 );
		add_action( 'after_switch_theme', [$this, 'erase_ignored_notice' ] );
	}
	

	public function welcome_notice() {
		add_action( 'admin_init', [$this, 'enqueue_scripts' ] );

		if ( false === get_transient( 'newsx_admin_notice_welcome' ) ) {
			if (isset($_GET['page']) && 'newsx-options' === $_GET['page']) {
				return;
			}
			
			add_action( 'admin_notices', [$this, 'welcome_notice_markup' ] );
		}
	}

	/**
	 * Show welcome notice.
	 */
	public function welcome_notice_markup() {
		$dismiss_url = wp_nonce_url(
			remove_query_arg( array( 'activated' ), add_query_arg( 'newsx-hide-notice', 'welcome' ) ),
			'newsx_hide_notices_nonce',
			'_newsx_notice_nonce'
		);

		// Get the current user object
		$current_user = wp_get_current_user();

		// Check if the user is logged in
		if ( 0 !== $current_user->ID ) {
			// Get the username
			$username = $current_user->user_login;
		}

		if ( is_plugin_active( 'news-magazine-x-core/news-magazine-x-core.php' ) ) {
			return;
		}
		?>
		<div id="message" class="notice notice-info newsx-notice newsx-welcome-notice">
			<a class="newsx-message-close notice-dismiss" href="<?php echo esc_url( $dismiss_url ); ?>"></a>

			<div class="newsx-message__content">
				<div class="newsx-message__text">
					<div class="newsx-message__head">
						<p class="newsx-message__subheading">
							<?php
							printf(
							/* translators: 1: Username */
								esc_html__( 'Welcome %1$s!', 'news-magazine-x' ),
								$username
							);
							?>
						</p>
						<h2 class="newsx-message__heading">
							<?php
							printf(
								esc_html__( 'Build your website with News Magazine X!', 'news-magazine-x' )
							);
							?>
						</h2>
						<p class="newsx-message__description">
							<?php
							printf(
							/* translators: 1: welcome page link starting html tag, 2: welcome page link ending html tag. */
								esc_html__( 'To fully enjoy the best features of our theme, click the button below to install the Theme Core plugin. This plugin allows you to import Starter Semplates with a single click.', 'news-magazine-x' )
							);
							?>
						</p>
					</div>

					<div class="newsx-message__cta">
						<button class="newsx-btn-get-started button button-primary button-hero" href="#">
							<span><?php echo esc_html__( 'Get started with Starter Templates', 'news-magazine-x' ); ?></span>
						</button>
						<a class="newsx-btn-demo-preview button button-primary button-hero" href="https://wp-royal-themes.com/themes/item-news-magazine-x-free/?ref=newsx-free-dash-welcome-banner-demo-preview/#!/demo-preview" target="_blank">
							<span><?php echo esc_html__( 'Theme Demo Preview', 'news-magazine-x' ); ?></span>
							<span class="dashicons dashicons-external"></span>
						</a>
					</div>

					<div class="newsx-message-start-from-scratch">
						<a href="<?php echo esc_url( $dismiss_url ); ?>"><?php echo esc_html__( 'I want to build this website from scratch', 'news-magazine-x' ); ?></a>
					</div>
				</div>
				<div class="newsx-message__image">
					<img src="<?php echo esc_url(NEWSX_ADMIN_URL . '/assets/images/starter-templates.png'); ?>" alt="<?php esc_attr_e('Starter Templates', 'news-magazine-x'); ?>">
				</div>
			</div>
		</div> <!-- /.newsx-message__content -->
		<?php
	}

	/**
	 * Hide a notice if the GET variable is set.
	 */
	public function hide_notices() {
		if ( isset( $_GET['newsx-hide-notice'] ) && isset( $_GET['_newsx_notice_nonce'] ) ) {
			if ( ! wp_verify_nonce( wp_unslash( $_GET['_newsx_notice_nonce'] ), 'newsx_hide_notices_nonce' ) ) {
				wp_die( __( 'Action failed. Please refresh the page and retry.', 'news-magazine-x' ) );
			}

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( __( 'Cheatin&#8217; huh?', 'news-magazine-x' ) ); // WPCS: xss ok.
			}

			$hide_notice = $_GET['newsx-hide-notice'];

			// Hide
			if ( 'welcome' === $hide_notice ) {
				set_transient( 'newsx_admin_notice_' . sanitize_text_field( wp_unslash( $hide_notice ) ), 1, 0 );
				wp_safe_redirect( admin_url( 'admin.php?page=newsx-options' ) );
				exit;
			// Show
			} else {
				delete_transient( 'newsx_admin_notice_' . sanitize_text_field( wp_unslash( $hide_notice ) ) );
			}
		}
	}
    
    public function erase_ignored_notice() {
        delete_transient('newsx_admin_notice_welcome');
    }

	public function enqueue_scripts() {
		// Enqueue CSS
		wp_enqueue_style( 'newsx-welcome-notice', NEWSX_ADMIN_URL .'/assets/css/welcome-notice.css', [], NEWSX_THEME_VERSION );

		// Enqueue JS
		wp_enqueue_script( 'newsx-welcome-notice', NEWSX_ADMIN_URL .'/assets/js/welcome-notice.js', ['jquery', 'updates'], NEWSX_THEME_VERSION, true );

        // Localize Scripts
        wp_localize_script( 'newsx-welcome-notice', 'NewsxWelcomeNotice', [
                'ajaxurl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('newsx-activate-required-plugins'),
				'installing_text' => esc_html__( 'Installing Starter Templates', 'news-magazine-x' ),
                'core_installed' => newsx_is_plugin_installed( 'news-magazine-x-core' ) ? 'yes' : 'no'
            ]
        );
	}
}

new Newsx_Welcome_Notice();
