<?php
// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;


// Exit if accessed directly.
defined('ABSPATH') || exit;

class AWPA_Notice
{
    public $name;
    public $type;
    public $dismiss_url;
    public $temporary_dismiss_url;
    public $pricing_url;
    public $current_user_id;

    /**
     * The constructor.
     *
     * @param string $name Notice Name.
     * @param string $type Notice type.
     * @param string $dismiss_url Notice permanent dismiss URL.
     * @param string $temporary_dismiss_url Notice temporary dismiss URL.
     *
     * @since 1.4.7
     *
     */
    public function __construct($name, $type, $dismiss_url, $temporary_dismiss_url)
    {
        $this->name = $name;
        $this->type = $type;
        $this->dismiss_url = $dismiss_url;
        $this->temporary_dismiss_url = $temporary_dismiss_url;
        $this->pricing_url = 'https://afthemes.com/plugins/wp-post-author/';
        $this->current_user_id = get_current_user_id();

        // Notice markup.
        add_action('admin_notices', array($this, 'notice'));

        $this->dismiss_notice();
        $this->dismiss_notice_temporary();
    }

    public function notice()
    {
        if (!$this->is_dismiss_notice()) {
            $this->notice_markup();
        }
    }

    private function is_dismiss_notice()
    {
        return apply_filters('awpa_' . $this->name . '_notice_dismiss', true);
    }

    public function notice_markup()
    {
        echo '';
    }

    /**
     * Hide a notice if the GET variable is set.
     */
    public function dismiss_notice()
    {
        if (isset($_GET['awpa_notice_dismiss']) && isset($_GET['_awpa_upgrade_notice_dismiss_nonce'])) { // WPCS: input var ok.
            if (!wp_verify_nonce(wp_unslash($_GET['_awpa_upgrade_notice_dismiss_nonce']), 'awpa_upgrade_notice_dismiss_nonce')) { // phpcs:ignore WordPress.VIP.ValidatedSanitizedInput.InputNotSanitized
                wp_die(__('Action failed. Please refresh the page and retry.', 'wp-post-author')); // WPCS: xss ok.
            }

            if (!current_user_can('publish_posts')) {
                wp_die(__('Cheatin&#8217; huh?', 'wp-post-author')); // WPCS: xss ok.
            }

            $dismiss_notice = sanitize_text_field(wp_unslash($_GET['awpa_notice_dismiss']));

            // Hide.
            if ($dismiss_notice === $_GET['awpa_notice_dismiss']) {
                add_user_meta(get_current_user_id(), 'awpa_' . $dismiss_notice . '_notice_dismiss', 'yes', true);
            }
        }
    }

    public function dismiss_notice_temporary()
    {
        if (isset($_GET['awpa_notice_dismiss_temporary']) && isset($_GET['_awpa_upgrade_notice_dismiss_temporary_nonce'])) { // WPCS: input var ok.
            if (!wp_verify_nonce(wp_unslash($_GET['_awpa_upgrade_notice_dismiss_temporary_nonce']), 'awpa_upgrade_notice_dismiss_temporary_nonce')) { // phpcs:ignore WordPress.VIP.ValidatedSanitizedInput.InputNotSanitized
                wp_die(__('Action failed. Please refresh the page and retry.', 'wp-post-author')); // WPCS: xss ok.
            }

            if (!current_user_can('publish_posts')) {
                wp_die(__('Cheatin&#8217; huh?', 'wp-post-author')); // WPCS: xss ok.
            }

            $dismiss_notice = sanitize_text_field(wp_unslash($_GET['awpa_notice_dismiss_temporary']));

            // Hide.
            if ($dismiss_notice === $_GET['awpa_notice_dismiss_temporary']) {
                add_user_meta(get_current_user_id(), 'awpa_' . $dismiss_notice . '_notice_dismiss_temporary', 'yes', true);
            }
        }
    }
}


class AWPA_Upgrade_Notice extends AWPA_Notice {

    public function __construct() {
        if ( ! current_user_can( 'publish_posts' ) ) {
            return;
        }

        $dismiss_url = wp_nonce_url(
            add_query_arg( 'awpa_notice_dismiss', 'upgrade', admin_url() ),
            'awpa_upgrade_notice_dismiss_nonce',
            '_awpa_upgrade_notice_dismiss_nonce'
        );

        $temporary_dismiss_url = wp_nonce_url(
            add_query_arg( 'awpa_notice_dismiss_temporary', 'upgrade', admin_url() ),
            'awpa_upgrade_notice_dismiss_temporary_nonce',
            '_awpa_upgrade_notice_dismiss_temporary_nonce'
        );

        parent::__construct( 'upgrade', 'info', $dismiss_url, $temporary_dismiss_url );

        $this->set_notice_time();

        $this->set_temporary_dismiss_notice_time();

        $this->set_dismiss_notice();
    }

    private function set_notice_time() {
        if ( ! get_option( 'awpa_upgrade_notice_start_time' ) ) {
            update_option( 'awpa_upgrade_notice_start_time', time() );
        }
    }

    private function set_temporary_dismiss_notice_time() {
        if ( isset( $_GET['awpa_notice_dismiss_temporary'] ) && 'upgrade' === $_GET['awpa_notice_dismiss_temporary'] ) {
            update_user_meta( $this->current_user_id, 'awpa_upgrade_notice_dismiss_temporary_start_time', time() );
        }
    }

    public function set_dismiss_notice() {

        /**
         * Do not show notice if:
         *
         * 1. It has not been 5 days since the plugin is activated.
         * 2. If the user has ignored the message partially for 2 days.
         * 3. Dismiss always if clicked on 'Dismiss' button.
         */
        if ( get_option( 'awpa_upgrade_notice_start_time' ) > strtotime( '-2 days' )
            || get_user_meta( get_current_user_id(), 'awpa_upgrade_notice_dismiss', true )
            || get_user_meta( get_current_user_id(), 'awpa_upgrade_notice_dismiss_temporary_start_time', true ) > strtotime( '-2 days' )
        ) {
            add_filter( 'awpa_upgrade_notice_dismiss', '__return_true' );
        } else {
            add_filter( 'awpa_upgrade_notice_dismiss', '__return_false' );
        }
    }

    public function notice_markup() {
        ?>
        <div class="notice notice-success wp-post-author-notice" >
            <div class="wp-post-author-notice__logo">
                <svg xmlns="http://www.w3.org/2000/svg" data-name="Layer 1" viewBox="0 0 382.31 446.56"><defs><linearGradient id="a" x1="118.66" y1="270.6" x2="393.33" y2="112.03" gradientUnits="userSpaceOnUse"><stop offset="0" stop-color="#2db8b7"/><stop offset="1" stop-color="#3062af"/></linearGradient></defs><path d="M114.75 425.01a2.47 2.47 0 0 0-2-1.69l-9.48-1.38-4.24-8.58a2.58 2.58 0 0 0-4.45 0l-4.24 8.58-9.47 1.34a2.48 2.48 0 0 0-1.37 4.23l6.86 6.67-1.62 9.43a2.48 2.48 0 0 0 3.6 2.62l8.46-4.46 8.47 4.46a2.49 2.49 0 0 0 1.16.29 2.56 2.56 0 0 0 1.46-.47 2.51 2.51 0 0 0 1-2.44l-1.62-9.43 6.86-6.66a2.49 2.49 0 0 0 .63-2.55Zm47.65 0a2.47 2.47 0 0 0-2-1.69l-9.48-1.38-4.25-8.58a2.58 2.58 0 0 0-4.45 0l-4.24 8.58-9.46 1.34a2.48 2.48 0 0 0-1.37 4.23l6.86 6.67-1.62 9.43a2.49 2.49 0 0 0 3.61 2.62l8.45-4.46 8.47 4.46a2.49 2.49 0 0 0 2.62-.18 2.49 2.49 0 0 0 1-2.44l-1.62-9.43 6.86-6.66a2.49 2.49 0 0 0 .63-2.55Zm46.07 0a2.47 2.47 0 0 0-2-1.69l-9.48-1.38-4.24-8.58a2.58 2.58 0 0 0-4.45 0l-4.24 8.58-9.47 1.34a2.49 2.49 0 0 0-2 1.69 2.45 2.45 0 0 0 .63 2.54l6.86 6.67-1.62 9.43a2.48 2.48 0 0 0 3.6 2.62l8.45-4.46 8.48 4.46a2.48 2.48 0 0 0 1.15.29 2.57 2.57 0 0 0 1.47-.47 2.51 2.51 0 0 0 1-2.44l-1.62-9.43 6.86-6.66a2.49 2.49 0 0 0 .63-2.55Zm49.24 0a2.47 2.47 0 0 0-2-1.69l-9.48-1.38-4.24-8.59a2.59 2.59 0 0 0-4.45 0l-4.24 8.59-9.47 1.34a2.48 2.48 0 0 0-1.37 4.23l6.85 6.67-1.61 9.43a2.48 2.48 0 0 0 3.6 2.62l8.45-4.46 8.48 4.46a2.47 2.47 0 0 0 1.15.28 2.48 2.48 0 0 0 2.46-2.9l-1.62-9.43 6.86-6.67a2.47 2.47 0 0 0 .63-2.54Zm45.72 0a2.49 2.49 0 0 0-2-1.69l-9.49-1.38-4.24-8.58a2.58 2.58 0 0 0-4.45 0l-4.24 8.58-9.46 1.34a2.49 2.49 0 0 0-1.37 4.24l6.86 6.66-1.62 9.44a2.48 2.48 0 0 0 3.61 2.61l8.45-4.45 8.47 4.5a2.49 2.49 0 0 0 2.62-.18 2.48 2.48 0 0 0 1-2.43l-1.62-9.44 6.86-6.66a2.49 2.49 0 0 0 .63-2.55Z" fill="#ffb900"/><path d="m7.15 382.41-7.17-30.06h6.21L10.72 373l5.5-20.65h7.22l5.27 21 4.61-21h6.11l-7.28 30.06h-6.44l-6-22.47-6 22.47Zm35.38 0v-30.06h9.74a35.43 35.43 0 0 1 7.22.45 7.92 7.92 0 0 1 4.33 2.94 9.36 9.36 0 0 1 1.74 5.86 9.78 9.78 0 0 1-1 4.65 8.3 8.3 0 0 1-2.56 3 8.67 8.67 0 0 1-3.15 1.42 34.32 34.32 0 0 1-6.29.43h-4v11.34Zm6.07-25v8.53h3.32a15.85 15.85 0 0 0 4.8-.47 4 4 0 0 0 2.59-3.82 3.91 3.91 0 0 0-1-2.71 4.19 4.19 0 0 0-2.44-1.33 28.92 28.92 0 0 0-4.37-.2Zm32.85 25v-30.06h9.74a35.36 35.36 0 0 1 7.22.45 7.85 7.85 0 0 1 4.33 2.94 9.36 9.36 0 0 1 1.74 5.86 9.78 9.78 0 0 1-1 4.65 8.28 8.28 0 0 1-2.55 3 8.82 8.82 0 0 1-3.15 1.42 34.44 34.44 0 0 1-6.3.43h-4v11.34Zm6.07-25v8.53h3.33a15.9 15.9 0 0 0 4.8-.47 4 4 0 0 0 2.58-3.82 3.91 3.91 0 0 0-1-2.71 4.24 4.24 0 0 0-2.45-1.33 28.84 28.84 0 0 0-4.36-.2Zm20.72 10.13a19 19 0 0 1 1.37-7.71 14.1 14.1 0 0 1 2.8-4.13 11.64 11.64 0 0 1 3.89-2.7 16.36 16.36 0 0 1 6.48-1.19q6.65 0 10.63 4.12t4 11.46q0 7.29-4 11.39t-10.58 4.12q-6.71 0-10.67-4.09t-3.9-11.24Zm6.25-.21q0 5.12 2.36 7.74a8.1 8.1 0 0 0 11.95 0q2.35-2.56 2.35-7.79t-2.26-7.71a8.41 8.41 0 0 0-12.07 0c-1.54 1.71-2.31 4.33-2.31 7.79Zm26.11 5.27 5.9-.57a7.23 7.23 0 0 0 2.17 4.37 6.52 6.52 0 0 0 4.4 1.39 6.75 6.75 0 0 0 4.42-1.24 3.68 3.68 0 0 0 1.48-2.9 2.79 2.79 0 0 0-.62-1.82 5 5 0 0 0-2.19-1.3c-.71-.25-2.33-.68-4.86-1.31q-4.87-1.22-6.85-3a7.83 7.83 0 0 1-2.76-6.05 7.69 7.69 0 0 1 1.3-4.29 8.22 8.22 0 0 1 3.75-3 15.18 15.18 0 0 1 5.92-1c3.77 0 6.61.82 8.52 2.48a8.71 8.71 0 0 1 3 6.62l-6.07.27a5.09 5.09 0 0 0-1.67-3.33 6.09 6.09 0 0 0-3.84-1 6.91 6.91 0 0 0-4.15 1.09 2.19 2.19 0 0 0-1 1.86 2.31 2.31 0 0 0 .9 1.83q1.16 1 5.58 2a29.82 29.82 0 0 1 6.55 2.16 8.47 8.47 0 0 1 3.32 3.06 8.94 8.94 0 0 1 1.2 4.79 8.84 8.84 0 0 1-1.43 4.84 8.62 8.62 0 0 1-4.06 3.35 17 17 0 0 1-6.54 1.1c-3.81 0-6.72-.88-8.76-2.64a11.39 11.39 0 0 1-3.59-7.73Zm36.32 9.78v-25H168v-5.09h23.89v5.09h-8.9v25Zm56.11 0h-6.61l-2.62-6.83h-12l-2.48 6.83h-6.44l11.71-30.06h6.42Zm-11.18-11.89-4.14-11.16-4.06 11.16Zm14.36-18.17h6.07v16.28a34.2 34.2 0 0 0 .22 5 4.84 4.84 0 0 0 1.86 3 6.43 6.43 0 0 0 4 1.12 6.06 6.06 0 0 0 3.89-1.06 4.11 4.11 0 0 0 1.58-2.59 33.49 33.49 0 0 0 .27-5.11v-16.61h6.07v15.79a40.46 40.46 0 0 1-.49 7.65 8.47 8.47 0 0 1-1.82 3.77 9 9 0 0 1-3.53 2.45 15.39 15.39 0 0 1-5.79.92 16.68 16.68 0 0 1-6.53-1 9.32 9.32 0 0 1-3.52-2.58 8.26 8.26 0 0 1-1.7-3.33 36.42 36.42 0 0 1-.59-7.63Zm37.14 30.06v-25h-8.9v-5.09h23.89v5.09h-8.9v25Zm18.9 0v-30.03h6.07v11.83h11.9v-11.83h6.07v30.06h-6.07v-13.13h-11.9v13.14Zm29.08-14.84a19 19 0 0 1 1.38-7.71 13.91 13.91 0 0 1 2.8-4.13 11.7 11.7 0 0 1 3.88-2.7 16.43 16.43 0 0 1 6.48-1.19q6.64 0 10.64 4.12t4 11.46q0 7.29-4 11.39t-10.58 4.12q-6.7 0-10.67-4.09t-3.91-11.24Zm6.26-.21q0 5.12 2.36 7.74a7.68 7.68 0 0 0 6 2.64 7.58 7.58 0 0 0 5.95-2.62q2.33-2.61 2.33-7.84c0-3.45-.75-6-2.27-7.71a8.39 8.39 0 0 0-12.06 0c-1.52 1.74-2.29 4.36-2.29 7.82Zm27.66 15.05v-30.03h12.8a21.83 21.83 0 0 1 7 .81 6.88 6.88 0 0 1 3.5 2.88 8.62 8.62 0 0 1 1.31 4.74 8 8 0 0 1-2 5.59 9.55 9.55 0 0 1-5.94 2.78 14.06 14.06 0 0 1 3.25 2.52 34.27 34.27 0 0 1 3.45 4.88l3.67 5.86h-7.26l-4.38-6.54a43.26 43.26 0 0 0-3.2-4.42 4.76 4.76 0 0 0-1.83-1.25 10.14 10.14 0 0 0-3.05-.34h-1.24v12.55Zm6.07-17.35h4.5a23.69 23.69 0 0 0 5.45-.36 3.23 3.23 0 0 0 1.7-1.28 3.9 3.9 0 0 0 .62-2.25 3.6 3.6 0 0 0-.81-2.45 3.7 3.7 0 0 0-2.29-1.18c-.49-.07-2-.1-4.43-.1h-4.74Z"/><path d="M414.61 191.34c0-87.46-71.15-158.62-158.61-158.62S97.39 103.88 97.39 191.34a158.2 158.2 0 0 0 51.48 116.84l-.15.13 5.14 4.34c.34.28.7.51 1 .79 2.73 2.27 5.56 4.42 8.45 6.5q1.4 1 2.82 2 4.62 3.18 9.47 6c.7.42 1.41.82 2.12 1.22q5.31 3 10.84 5.66l.82.37a157.61 157.61 0 0 0 38.36 12.14l1.07.19c4.17.72 8.39 1.3 12.67 1.68l1.56.12c4.26.36 8.56.58 12.92.58s8.58-.22 12.82-.57l1.61-.12q6.3-.57 12.56-1.65l1.08-.2a157.39 157.39 0 0 0 37.82-11.85c.43-.2.88-.39 1.32-.6 4.42-2.09 8.76-4.37 13-6.86q4.67-2.73 9.12-5.77c1.07-.72 2.11-1.49 3.17-2.25 2.53-1.82 5-3.7 7.43-5.67.54-.43 1.12-.81 1.64-1.25l5.28-4.41-.16-.13a158.2 158.2 0 0 0 51.96-117.23Zm-305.69 0c0-81.1 66-147.08 147.08-147.08s147.08 66 147.08 147.08a146.72 146.72 0 0 1-49.54 110 43.4 43.4 0 0 0-5.15-3.1l-48.84-24.41a12.8 12.8 0 0 1-7.1-11.5v-17.11c1.13-1.39 2.32-3 3.56-4.71A117.11 117.11 0 0 0 311.09 211a20.93 20.93 0 0 0 12-19v-20.45a21 21 0 0 0-5.09-13.67V131c.3-3 1.36-19.88-10.86-33.82C296.51 85 279.31 78.86 256 78.86S215.49 85 204.86 97.14C192.64 111.07 193.7 128 194 131v26.92a21 21 0 0 0-5.12 13.66V192a21 21 0 0 0 7.73 16.27 108.46 108.46 0 0 0 17.84 36.85v16.68a12.85 12.85 0 0 1-6.7 11.29L162.14 298a41.76 41.76 0 0 0-4.34 2.75 146.76 146.76 0 0 1-48.88-109.41Z" transform="translate(-64.85 -32.72)" fill="url(#a)"/></svg>
            </div>
            <div class="wp-post-author-notice__content">
                <span class="wp-post-author-icon-display"></span>
                
                    <?php
                    $current_user = wp_get_current_user();
    
                    printf(
                    /* Translators: %1$s current user display name., %2$s this plugin name., %3$s discount coupon code., %4$s discount percentage. */
                       esc_html__(
                            '%1$s %5$s Sharing some love with a %3$s5-star rating on WordPress.org%4$s would be incredible! Your support motivates us to keep bringing you amazing features. %7$sAnd for an enhanced experience with premium features, controls, and versatile blocks, think about upgrading to %8$s The possibilities are endless! %6$s',
                            'wp-post-author'
                        ),
                        '<h2>Using WP Post Author? You, ' . esc_html( $current_user->display_name ) . ', are absolutely awesome!</h2>',
                        '<p class="notice-text"><strong>WP Post Author.</strong>',                        
                        '<strong><a href="https://wordpress.org/support/plugin/wp-post-author/reviews/?filter=5#new-post" target="_blank">',
                        '</a></strong>',                        
                        '<p>',
                        '</p>',
                        '<br>',
                        '<strong><a target="_blank" href="https://afthemes.com/plugins/wp-post-author/">WP Post Author Pro</a></strong>',
                    );
                    ?>
                </p>
    
                <div class="links">

                    <a href="<?php echo esc_url( $this->pricing_url ); ?>" class="button button-primary" target="_blank">
                        <span><?php esc_html_e( 'Upgrade to Pro', 'wp-post-author' ); ?></span>
                    </a>

                     <a href="https://wordpress.org/support/plugin/wp-post-author/reviews/?filter=5#new-post" class="button button-secondary" target="_blank">
                        <span><?php esc_html_e( 'Sure thing', 'wp-post-author' ); ?></span>
                    </a>
    
                                 
    
                    <a href="<?php echo esc_url( $this->temporary_dismiss_url ); ?>" class="button button-secondary plain">
    
                        <span><?php esc_html_e( 'Maybe later', 'wp-post-author' ); ?></span>
                    </a>
    
                    <a href="https://afthemes.com/supports/" class="button button-secondary plain" target="_blank">
    
                        <span><?php esc_html_e( 'Need help?', 'wp-post-author' ); ?></span>
                    </a>
                </div>
            </div>
            <a class="wp-post-author-notice-dismiss notice-dismiss" href="<?php echo esc_url( $this->dismiss_url ); ?>"></a>
        </div> <!-- /wp-post-author-notice -->
        <?php
    }
}

new AWPA_Upgrade_Notice();
