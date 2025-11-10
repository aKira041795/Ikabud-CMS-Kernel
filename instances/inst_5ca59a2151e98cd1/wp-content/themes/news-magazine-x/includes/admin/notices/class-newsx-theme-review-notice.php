<?php
// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Class to display the theme review notice after certain period.
 *
 * Class Newsx_Theme_Review_Notice
 */
class Newsx_Theme_Review_Notice {

	/**
	 * Constructor function to include the required functionality for the class.
	 *
	 * Newsx_Theme_Review_Notice constructor.
	 */
	public function __construct() {
		add_action( 'after_setup_theme', array( $this, 'review_notice' ) );
		add_action( 'admin_notices', array( $this, 'review_notice_markup' ), 0 );
		add_action( 'admin_init', array( $this, 'ignore_theme_review_notice' ), 0 );
		add_action( 'admin_init', array( $this, 'ignore_theme_review_notice_partially' ), 0 );
		add_action( 'switch_theme', array( $this, 'review_notice_data_remove' ) );

        if ( $this->is_time_to_show_review_notice() ) {
            add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
        }
	}

	/**
	 * Set the required option value as needed for theme review notice.
	 */
	public function review_notice() {
		// Set the installed time in `newsx_theme_installed_time` option table.
		if ( ! get_option( 'newsx_theme_installed_time' ) ) {
			update_option( 'newsx_theme_installed_time', time() );
		}
	}

	/**
	 * Show HTML markup if conditions meet.
	 */
	public function review_notice_markup() {
		$dismiss_url = wp_nonce_url(
			add_query_arg( 'nag_newsx_ignore_theme_review_notice', 0 ),
			'nag_newsx_ignore_theme_review_notice_nonce',
			'_newsx_ignore_theme_review_notice_nonce'
		);
		$temporary_dismiss_url = wp_nonce_url(
			add_query_arg( 'nag_newsx_ignore_theme_review_notice_partially', 0 ),
			'nag_newsx_ignore_theme_review_notice_partially_nonce',
			'_newsx_ignore_theme_review_notice_nonce'
		);

		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}

		/**
		 * Return from notice display if:
		 *
		 * 1. The theme installed is less than 10 days ago.
		 * 2. If the user has ignored the message partially for 10 days.
		 * 3. Dismiss always if clicked on 'I Already Did' button.
		 */
        if ( ! $this->is_time_to_show_review_notice() ) {
            return;
        }
		?>
		<div class="notice notice-info newsx-notice newsx-theme-review-notice" style="position:relative;">
		<div class="newsx-message__content">
			<div class="newsx-message-review__text">
			<h3>
                <?php
                /* translators: %s: Theme name */
                printf(
                    esc_html__( 'Thank you for using %s Theme!', 'news-magazine-x' ), wp_get_theme( get_template() )
                );
                ?>
            </h3>
            <p>
                <?php
                    printf(
                        /* translators: %1$s: Opening of strong tag, %2$s: Theme's Name, %3$s: Closing of strong tag  */
                        esc_html__( 'Thank you for choosing the %1$s %2$s %3$s theme! We hope you\'re enjoying it so far. Your feedback means the world to us so please take a moment to leave a nice review. It helps us grow and inspires us to do even better!', 'news-magazine-x' ),
                        '<strong>',
                        esc_html( wp_get_theme( get_template() ) ),
                        '</strong>'
                    );
                ?>
            </p>

			<div class="newsx-review-buttons">
				<a href="https://wordpress.org/support/theme/news-magazine-x/reviews/" class="btn button-primary" target="_blank">
					<span><?php esc_html_e( 'OK, you deserve it!', 'news-magazine-x' ); ?></span>
				</a>

				<a href="<?php echo esc_url( $dismiss_url ); ?>" class="btn button-secondary">
					<span class="dashicons dashicons-smiley"></span>
					<span><?php esc_html_e( 'I already did!', 'news-magazine-x' ); ?></span>
				</a>

				<a href="<?php echo esc_url( $temporary_dismiss_url ); ?>" class="btn button-secondary">
					<span class="dashicons dashicons-clock"></span>
					<span><?php esc_html_e( 'Maybe later', 'news-magazine-x' ); ?></span>
				</a>

				<a href="<?php echo esc_url( 'https://wp-royal-themes.com/contact/?ref=newsx-free-dash-review-banner-contact/#!/cform' ); ?>" class="btn button-secondary" target="_blank">
					<span class="dashicons dashicons-sos"></span>
					<span><?php esc_html_e( 'I need support', 'news-magazine-x' ); ?></span>
				</a>
                
			    <a href="<?php echo esc_url( $dismiss_url ); ?>" class="btn button-secondary">
                    <span class="dashicons dashicons-thumbs-down"></span>
                    <span><?php esc_html_e( 'NO, not good enough!', 'news-magazine-x' ); ?></span>
                </a>
			</div> <!-- /.links -->
			</div>
		</div>

			<a class="notice-dismiss" href="<?php echo esc_url( $dismiss_url ); ?>"></a>
		</div> <!-- /.theme-review-notice -->
		<?php
	}

	/**
	 * `I already did` button or `dismiss` button: remove the review notice permanently.
	 */
	public function ignore_theme_review_notice() {

		// If user clicks to ignore the notice, add that to their user meta.
		if ( isset( $_GET['nag_newsx_ignore_theme_review_notice'] ) && isset( $_GET['_newsx_ignore_theme_review_notice_nonce'] ) ) {

			if ( ! wp_verify_nonce( wp_unslash( $_GET['_newsx_ignore_theme_review_notice_nonce'] ), 'nag_newsx_ignore_theme_review_notice_nonce' ) ) {
				wp_die( esc_html__( 'Action failed. Please refresh the page and retry.', 'news-magazine-x' ) );
			}

			if ( '0' === $_GET['nag_newsx_ignore_theme_review_notice'] ) {
				add_user_meta( get_current_user_id(), 'newsx_ignore_theme_review_notice', 'true', true );
			}
		}
	}

	/**
	 * `Maybe later` button: remove the review notice partially.
	 */
	public function ignore_theme_review_notice_partially() {

		// If user clicks to ignore the notice, add that to their user meta.
		if ( isset( $_GET['nag_newsx_ignore_theme_review_notice_partially'] ) && isset( $_GET['_newsx_ignore_theme_review_notice_nonce'] ) ) {

			if ( ! wp_verify_nonce( wp_unslash( $_GET['_newsx_ignore_theme_review_notice_nonce'] ), 'nag_newsx_ignore_theme_review_notice_partially_nonce' ) ) {
				wp_die( esc_html__( 'Action failed. Please refresh the page and retry.', 'news-magazine-x' ) );
			}

			if ( '0' === $_GET['nag_newsx_ignore_theme_review_notice_partially'] ) {
				update_user_meta( get_current_user_id(), 'nag_newsx_ignore_theme_review_notice_partially', time() );
			}
		}
	}

	/**
	 * Remove the data set after the theme has been switched to other theme.
	 */
	public function review_notice_data_remove() {
		$get_all_users        = get_users();
		$theme_installed_time = get_option( 'newsx_theme_installed_time' );

		// Delete options data.
		if ( $theme_installed_time ) {
			delete_option( 'newsx_theme_installed_time' );
		}

		// Delete user meta data for theme review notice.
		foreach ( $get_all_users as $user ) {
			$ignored_notice           = get_user_meta( $user->ID, 'newsx_ignore_theme_review_notice', true );
			$ignored_notice_partially = get_user_meta( $user->ID, 'nag_newsx_ignore_theme_review_notice_partially', true );

			// Delete permanent notice remove data.
			if ( $ignored_notice ) {
				delete_user_meta( $user->ID, 'newsx_ignore_theme_review_notice' );
			}

			// Delete partial notice remove data.
			if ( $ignored_notice_partially ) {
				delete_user_meta( $user->ID, 'nag_newsx_ignore_theme_review_notice_partially' );
			}
		}
	}

    public function enqueue_scripts() {
        wp_enqueue_style( 'newsx-review-notice-style', NEWSX_ADMIN_URL . '/assets/css/review-notice.css', array(), NEWSX_THEME_VERSION );
    }

    public function is_time_to_show_review_notice() {
        // return true; // Only for testing
        
        $user_id = get_current_user_id();
        $ignored_notice = get_user_meta( $user_id, 'newsx_ignore_theme_review_notice', true );
        $ignored_notice_partially = get_user_meta( $user_id, 'nag_newsx_ignore_theme_review_notice_partially', true );

        if ( ( get_option( 'newsx_theme_installed_time' ) > strtotime( '-10 day' ) ) || 
             ( $ignored_notice_partially > strtotime( '-10 day' ) ) || 
             ( '' !== $ignored_notice ) ) {
            return false;
        }

        return true;
    }
}

new newsx_Theme_Review_Notice();
