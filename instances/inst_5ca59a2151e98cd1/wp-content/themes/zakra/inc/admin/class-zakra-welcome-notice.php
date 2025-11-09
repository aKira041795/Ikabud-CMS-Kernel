<?php
// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

class Zakra_Welcome_Notice {

	public function __construct() {
		//      add_action( 'wp_loaded', array( $this, 'major_update_notice' ) );
		add_action( 'wp_loaded', array( $this, 'welcome_notice' ), 20 );
		add_action( 'wp_loaded', array( $this, 'hide_notices' ), 15 );
		add_action( 'wp_ajax_import_button', array( $this, 'welcome_notice_import_handler' ) );
	}

	public function welcome_notice() {
		if ( ! get_option( 'zakra_admin_notice_welcome' ) ) {
			add_action( 'admin_notices', array( $this, 'welcome_notice_markup' ) ); // Show notice.
		}
	}


	public function major_update_notice() {
		if ( ! get_option( 'zakra_admin_notice_major_update' ) ) {
			add_action( 'admin_notices', array( $this, 'major_update_notice_markup' ) ); // Show notice.
		}
	}

	/**
	 * echo `Get started` CTA.
	 *
	 * @return string
	 */
	public function import_button_html() {
		$html = '<a class="btn-get-started button button-primary button-hero" href="#" data-name="' . esc_attr( 'themegrill-demo-importer' ) . '" data-slug="' . esc_attr( 'themegrill-demo-importer' ) . '" aria-label="' . esc_attr__( 'Get started', 'zakra' ) . '">' . esc_html__( 'Get started', 'zakra' ) . '</a>';

		return $html;
	}

	public function update_button_html() {
		$html = '<a class="btn-major-update button button-primary button-hero" href="https://zakratheme.com/blog/zakra-3-0/" target=”_blank” aria-label="' . esc_attr__( 'Learn More', 'zakra' ) . '">' . esc_html__( 'Learn about Zakra 3.0', 'zakra' ) . '</a>';

		return $html;
	}

	public function support_button_html() {
		$html = '<a class="btn-contact-support button button-secondary button-hero" href="https://zakratheme.com/support/" target=”_blank” aria-label="' . esc_attr__( 'Contact Support', 'zakra' ) . '">' . esc_html__( 'Contact Support', 'zakra' ) . '</a>';

		return $html;
	}

	public function faq_button_html() {
		$html = '<a class="btn-contact-support button button-secondary button-hero" href="https://docs.zakratheme.com/en/article/troubleshooting-common-issues-after-zakra-v30-pro-v20-updates-1w10vgm/" target=”_blank” aria-label="' . esc_attr__( 'FAQ', 'zakra' ) . '">' . esc_html__( 'FAQ', 'zakra' ) . '</a>';

		return $html;
	}

	/**
	 * Show release notice.
	 */

	public function major_update_notice_markup() {
		$dismiss_url = wp_nonce_url(
			remove_query_arg( array( 'activated' ), add_query_arg( 'zakra-hide-notice', 'major_update' ) ),
			'zakra_hide_notices_nonce',
			'_zakra_notice_nonce'
		);

		// Get the current user object
		$current_user = wp_get_current_user();

		$username = $current_user->user_login;

		if ( is_plugin_active( 'themegrill-demo-importer/themegrill-demo-importer.php' ) ) {
			return;
		}

		?>
		<div id="message" class="notice notice-success zakra-notice">
			<a class="zakra-message-close notice-dismiss" href="<?php echo esc_url( $dismiss_url ); ?>"></a>

			<div class="zakra-message__content">
				<div class="zakra-message__text">
					<div class="zakra-message__head">
						<p class="zakra-message__subheading">
							<?php
							sprintf(
								/* translators: %s: welcome page link starting username */
								esc_html__( 'Welcome %s!', 'zakra' ),
								$username
							);
							?>
						</p>
						<h2 class="zakra-message__heading">
							<?php
							printf(
								esc_html__( 'Start Building with Zakra!', 'zakra' )
							);
							?>
						</h2>
						<p class="zakra-message__description">
							<?php
							printf(
							/* translators: 1: welcome page link starting html tag, 2: welcome page link ending html tag. */
								esc_html__( 'Welcome! Thank you for choosing Zakra! Let’s get you started right away with our visually appealing and attractive demos.', 'zakra' )
							);
							?>
						</p>
					</div>
					<div class="zakra-message__cta">
						<?php echo $this->import_button_html(); ?>
						<span class="plugin-install-notice"><?php esc_html_e( 'Clicking this button will install and activate the ThemeGrill Demo Importer plugin allowing you to import the theme’s demos.', 'zakra' ); ?></span>
					</div>
				</div>
				<div class="zakra-message__image">
					<img src="<?php echo esc_url( get_template_directory_uri() ); ?>/inc/admin/images/zakra-welcome-banner.png" alt="Zakra Templates">
				</div>
			</div>
		</div> <!-- /.zakra-message__content -->

		<?php
	}

	/**
	 * Show welcome notice.
	 */
	public function welcome_notice_markup() {

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$dismiss_url = wp_nonce_url(
			remove_query_arg( array( 'activated' ), add_query_arg( 'zakra-hide-notice', 'welcome' ) ),
			'zakra_hide_notices_nonce',
			'_zakra_notice_nonce'
		);
		// Get the current user object
		$current_user = wp_get_current_user();

		$username = $current_user->user_login;

		if ( is_plugin_active( 'themegrill-demo-importer/themegrill-demo-importer.php' ) ) {
			return;
		}
		?>
		<div id="message" class="notice notice-success zakra-notice">
			<a class="zakra-message-close notice-dismiss" href="<?php echo esc_url( $dismiss_url ); ?>"></a>

			<div class="zakra-message__content">
				<div class="zakra-message__text">
					<div class="zakra-message__head">
						<p class="zakra-message__subheading">
							<?php
							sprintf(
								/* translators: %s: welcome page link starting username */
								esc_html__( 'Welcome %s!', 'zakra' ),
								$username
							);
							?>
						</p>
						<h2 class="zakra-message__heading">
							<?php
							printf(
								esc_html__( 'Start Building with Zakra!', 'zakra' )
							);
							?>
						</h2>
						<p class="zakra-message__description">
							<?php
							printf(
							/* translators: 1: welcome page link starting html tag, 2: welcome page link ending html tag. */
								esc_html__( 'Welcome! Thank you for choosing Zakra! Let’s get you started right away with our visually appealing and attractive demos.', 'zakra' )
							);
							?>
						</p>
					</div>
					<div class="zakra-message__cta">
						<?php echo $this->import_button_html(); ?>
						<span class="plugin-install-notice"><?php esc_html_e( 'Clicking this button will install and activate the ThemeGrill Demo Importer and BlockArt Blocks plugins allowing you to import the theme demos.', 'zakra' ); ?></span>
					</div>
				</div>
				<div class="zakra-message__image">
					<img src="<?php echo esc_url( get_template_directory_uri() ); ?>/inc/admin/images/zakra-welcome-banner.png" alt="Zakra Templates">
				</div>
			</div>
		</div> <!-- /.zakra-message__content -->
		<?php
	}

	/**
	 * Hide a notice if the GET variable is set.
	 */
	public function hide_notices() {
		if ( isset( $_GET['zakra-hide-notice'] ) && isset( $_GET['_zakra_notice_nonce'] ) ) { // WPCS: input var ok.
			if ( ! wp_verify_nonce( wp_unslash( $_GET['_zakra_notice_nonce'] ), 'zakra_hide_notices_nonce' ) ) { // phpcs:ignore WordPress.VIP.ValidatedSanitizedInput.InputNotSanitized
				wp_die( __( 'Action failed. Please refresh the page and retry.', 'zakra' ) ); // WPCS: xss ok.
			}

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( __( 'Cheatin&#8217; huh?', 'zakra' ) ); // WPCS: xss ok.
			}

			$hide_notice = sanitize_text_field( wp_unslash( $_GET['zakra-hide-notice'] ) );

			// Hide.
			if ( 'welcome' === $_GET['zakra-hide-notice'] ) {
				update_option( 'zakra_admin_notice_' . $hide_notice, 1 );
			} elseif ( 'major_update' === $_GET['zakra-hide-notice'] ) {
				update_option( 'zakra_admin_notice_major_update', 1 );
			} else { // Show.
				delete_option( 'zakra_admin_notice_' . $hide_notice );
			}
		}
	}

	/**
	 * Handle the AJAX process while import or get started button clicked.
	 */
	public function welcome_notice_import_handler() {
		check_ajax_referer( 'zakra_demo_import_nonce', 'security' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array(
					'errorCode'    => 'permission_denied',
					'errorMessage' => __( 'You do not have permission to perform this action.', 'zakra' ),
				)
			);
			exit;
		}

		$plugins = array(
			'themegrill-demo-importer' => 'themegrill-demo-importer/themegrill-demo-importer.php',
			'blockart-blocks'          => 'blockart-blocks/blockart.php',
		);

		foreach ( $plugins as $slug => $basename ) {
			$state = $this->install_plugin( $slug, $basename );
		}

		if ( is_wp_error( $state ) ) {
			$response['errorCode']    = $state->get_error_code();
			$response['errorMessage'] = $state->get_error_message();
		} else {
			$response['redirect'] = zakra_is_zakra_pro_active() && zakra_plugin_version_compare( 'zakra-pro/zakra-pro.php', '3.1.0', '>=' ) ? admin_url( 'admin.php?page=zakra' ) : admin_url( 'themes.php?page=zakra' );
		}

		wp_send_json( $response );

		exit();
	}

	public function install_plugin( $slug, $basename ) {

		$state                  = '';
		$installed_plugin_slugs = array_keys( get_plugins() );

		if ( in_array( $basename, $installed_plugin_slugs, true ) ) {
			$state = is_plugin_active( $basename ) ? 'activated' : 'installed';
		}

		if ( 'activated' === $state ) {
			return true;
		}

		if ( 'installed' === $state && current_user_can( 'activate_plugin' ) ) {
				$result = activate_plugin( $basename );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			return true;
		}

		wp_enqueue_style( 'plugin-install' );
		wp_enqueue_script( 'plugin-install' );

		/**
		 * Install Plugin.
		 */
		include_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		include_once ABSPATH . 'wp-admin/includes/plugin-install.php';

		$api = plugins_api(
			'plugin_information',
			array(
				'slug'   => sanitize_key( wp_unslash( $slug ) ),
				'fields' => array(
					'sections' => false,
				),
			)
		);

		$skin     = new WP_Ajax_Upgrader_Skin();
		$upgrader = new Plugin_Upgrader( $skin );
		$result   = $upgrader->install( $api->download_link );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( ! $result ) {
			return new WP_Error( 'install_failed', __( 'Plugin installation failed.', 'zakra' ) );
		}

		// Activate plugin.
		if ( current_user_can( 'activate_plugin' ) ) {
			$result = activate_plugin( $basename );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			return true;
		}

		return true;
	}
}

new Zakra_Welcome_Notice();
