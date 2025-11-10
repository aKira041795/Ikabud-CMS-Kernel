<?php
/**
 * MoreNews Theme Review Notice Class.
 *
 * @author  AF themes
 * @package MoreNews
 * @since   2.1.2
 */

// Exit if directly accessed.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class to display the theme review notice for this theme after certain period and re-show after updates.
 *
 * Class MoreNews_Theme_Review_Notice
 */
class MoreNews_Theme_Review_Notice {

    /**
     * Constructor function to include the required functionality for the class.
     *
     * MoreNews_Theme_Review_Notice constructor.
     */
    public function __construct() {
        add_action( 'after_setup_theme', array( $this, 'morenews_theme_rating_notice' ) );
        add_action( 'switch_theme', array( $this, 'morenews_theme_rating_notice_data_remove' ) );
    }

    /**
     * Set the required option value as needed for theme review notice.
     */
    public function morenews_theme_rating_notice() {
        // Set or update the installed time in `morenews_theme_installed_time_v3` option table.
        $option = get_option( 'morenews_theme_installed_time_v3' );
        if ( ! $option ) {
            update_option( 'morenews_theme_installed_time_v3', time() );
        }

        add_action( 'admin_notices', array( $this, 'morenews_theme_review_notice' ), 0 );
        add_action( 'admin_init', array( $this, 'morenews_ignore_theme_review_notice' ), 0 );
        add_action( 'admin_init', array( $this, 'morenews_ignore_theme_review_notice_partially' ), 0 );
    }

    /**
     * Display the theme review notice.
     */
    public function morenews_theme_review_notice() {
        global $current_user;
        $user_id = $current_user->ID;
        $ignored_notice = get_user_meta( $user_id, 'morenews_ignore_theme_review_notice_v3', true );
        $ignored_notice_partially = get_user_meta( $user_id, 'nag_morenews_ignore_theme_review_notice_partially_v3', true );

        // Return from notice display if conditions are met.
        if ( ( get_option( 'morenews_theme_installed_time_v3' ) > strtotime( '-5 days' ) ) || ( $ignored_notice_partially > strtotime( '-2 days' ) ) || ( $ignored_notice ) ) {
            return;
        }

        // Get the active theme info.
        $current_theme = wp_get_theme();
        $theme_name = $current_theme->get( 'Name' );
        $theme_slug = $current_theme->get_stylesheet();
        $review_link = 'https://wordpress.org/support/theme/' . strtolower( $theme_slug ) . '/reviews/?filter=5#new-post';

        ?>

        <div class="notice updated theme-review-notice" style="position:relative;">
            <p>
                <?php
                printf(
                    /* Translators: %1$s current user display name. */
                    esc_html__(
                        'Howdy, %1$s! We\'ve noticed that you\'ve been using %2$s for some time now, we hope you are loving it! We would appreciate it if you can give us %3$sreview and rating%4$s on WordPress.org! We\'ll continue to develop exciting new features for free in the future by sharing the love!', 'morenews'
                    ),
                    '<strong>' . esc_html( $current_user->display_name ) . '</strong>',
                    esc_html( $theme_name ),
                    '<a href="' . esc_url( $review_link ) . '" target="_blank">',
                    '</a>'
                );
                ?>
            </p>

            <div class="links">
                <a href="<?php echo esc_url( $review_link ); ?>" class="btn button-primary" target="_blank">
                    <span class="dashicons dashicons-thumbs-up"></span>
                    <span><?php esc_html_e( 'Sure thing', 'morenews' ); ?></span>
                </a>

                <a href="?nag_morenews_ignore_theme_review_notice_partially_v3=0" class="btn button-secondary">
                    <span class="dashicons dashicons-calendar"></span>
                    <span><?php esc_html_e( 'Remind me later', 'morenews' ); ?></span>
                </a>

                <a href="?nag_morenews_ignore_theme_review_notice_v3=0" class="btn button-secondary">
                    <span class="dashicons dashicons-smiley"></span>
                    <span><?php esc_html_e( 'I\'ve already done.', 'morenews' ); ?></span>
                </a>

                <a href="<?php echo esc_url( 'https://afthemes.com/supports/' ); ?>" class="btn button-secondary" target="_blank">
                    <span class="dashicons dashicons-edit"></span>
                    <span><?php esc_html_e( 'Got any support queries?', 'morenews' ); ?></span>
                </a>
            </div>

            <a class="notice-dismiss" style="text-decoration:none;" href="?nag_morenews_ignore_theme_review_notice_v3=0"></a>
        </div>

        <?php
    }

    /**
     * Function to remove the theme review notice permanently as requested by the user.
     */
    public function morenews_ignore_theme_review_notice() {
        global $current_user;
        $user_id = $current_user->ID;

        /* If user clicks to ignore the notice, add that to their user meta */
        if ( isset( $_GET['nag_morenews_ignore_theme_review_notice_v3'] ) && '0' == $_GET['nag_morenews_ignore_theme_review_notice_v3'] ) {
            add_user_meta( $user_id, 'morenews_ignore_theme_review_notice_v3', 'true', true );
        }
    }

    /**
     * Function to remove the theme review notice partially as requested by the user.
     */
    public function morenews_ignore_theme_review_notice_partially() {
        global $current_user;
        $user_id = $current_user->ID;

        /* If user clicks to ignore the notice temporarily, update the timestamp */
        if ( isset( $_GET['nag_morenews_ignore_theme_review_notice_partially_v3'] ) && '0' == $_GET['nag_morenews_ignore_theme_review_notice_partially_v3'] ) {
            update_user_meta( $user_id, 'nag_morenews_ignore_theme_review_notice_partially_v3', time() );
        }
    }

    /**
     * Remove the data set after the theme has been switched to other theme.
     */
    public function morenews_theme_rating_notice_data_remove() {
        $get_all_users = get_users();
        $theme_installed_time = get_option( 'morenews_theme_installed_time_v3' );

        // Delete options data.
        if ( $theme_installed_time ) {
            delete_option( 'morenews_theme_installed_time_v3' );
        }

        // Delete user meta data for theme review notice.
        foreach ( $get_all_users as $user ) {
            $ignored_notice = get_user_meta( $user->ID, 'morenews_ignore_theme_review_notice_v3', true );
            $ignored_notice_partially = get_user_meta( $user->ID, 'nag_morenews_ignore_theme_review_notice_partially_v3', true );

            // Delete permanent notice remove data.
            if ( $ignored_notice ) {
                delete_user_meta( $user->ID, 'morenews_ignore_theme_review_notice_v3' );
            }

            // Delete partial notice remove data.
            if ( $ignored_notice_partially ) {
                delete_user_meta( $user->ID, 'nag_morenews_ignore_theme_review_notice_partially_v3' );
            }
        }
    }

}

new MoreNews_Theme_Review_Notice();

