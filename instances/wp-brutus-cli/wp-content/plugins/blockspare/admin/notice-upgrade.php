<?php
// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

class Blockspare_Notice
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
        $this->pricing_url = 'https://www.blockspare.com/pricing/';
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
        return apply_filters('blockspare_' . $this->name . '_notice_dismiss', true);
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
        if (isset($_GET['blockspare_notice_dismiss']) && isset($_GET['_blockspare_upgrade_notice_dismiss_nonce'])) { // WPCS: input var ok.
            if (!wp_verify_nonce(wp_unslash($_GET['_blockspare_upgrade_notice_dismiss_nonce']), 'blockspare_upgrade_notice_dismiss_nonce')) { // phpcs:ignore WordPress.VIP.ValidatedSanitizedInput.InputNotSanitized
                wp_die(__('Action failed. Please refresh the page and retry.', 'blockspare')); // WPCS: xss ok.
            }

            if (!current_user_can('publish_posts')) {
                wp_die(__('Cheatin&#8217; huh?', 'blockspare')); // WPCS: xss ok.
            }

            $dismiss_notice = sanitize_text_field(wp_unslash($_GET['blockspare_notice_dismiss']));

            // Hide.
            if ($dismiss_notice === $_GET['blockspare_notice_dismiss']) {
                add_user_meta(get_current_user_id(), 'blockspare_' . $dismiss_notice . '_notice_dismiss', 'yes', true);
            }
        }
    }

    public function dismiss_notice_temporary()
    {
        if (isset($_GET['blockspare_notice_dismiss_temporary']) && isset($_GET['_blockspare_upgrade_notice_dismiss_temporary_nonce'])) { // WPCS: input var ok.
            if (!wp_verify_nonce(wp_unslash($_GET['_blockspare_upgrade_notice_dismiss_temporary_nonce']), 'blockspare_upgrade_notice_dismiss_temporary_nonce')) { // phpcs:ignore WordPress.VIP.ValidatedSanitizedInput.InputNotSanitized
                wp_die(__('Action failed. Please refresh the page and retry.', 'blockspare')); // WPCS: xss ok.
            }

            if (!current_user_can('publish_posts')) {
                wp_die(__('Cheatin&#8217; huh?', 'blockspare')); // WPCS: xss ok.
            }

            $dismiss_notice = sanitize_text_field(wp_unslash($_GET['blockspare_notice_dismiss_temporary']));

            // Hide.
            if ($dismiss_notice === $_GET['blockspare_notice_dismiss_temporary']) {
                add_user_meta(get_current_user_id(), 'blockspare_' . $dismiss_notice . '_notice_dismiss_temporary', 'yes', true);
            }
        }
    }
}


class Blockspare_Upgrade_Notice extends Blockspare_Notice {

    public function __construct() {
        if ( ! current_user_can( 'publish_posts' ) ) {
            return;
        }

        $dismiss_url = wp_nonce_url(
            add_query_arg( 'blockspare_notice_dismiss', 'upgrade', admin_url() ),
            'blockspare_upgrade_notice_dismiss_nonce',
            '_blockspare_upgrade_notice_dismiss_nonce'
        );

        $temporary_dismiss_url = wp_nonce_url(
            add_query_arg( 'blockspare_notice_dismiss_temporary', 'upgrade', admin_url() ),
            'blockspare_upgrade_notice_dismiss_temporary_nonce',
            '_blockspare_upgrade_notice_dismiss_temporary_nonce'
        );

        parent::__construct( 'upgrade', 'info', $dismiss_url, $temporary_dismiss_url );

        $this->set_notice_time();

        $this->set_temporary_dismiss_notice_time();

        $this->set_dismiss_notice();
    }

    private function set_notice_time() {
        if ( ! get_option( 'blockspare_upgrade_notice_start_time' ) ) {
            update_option( 'blockspare_upgrade_notice_start_time', time() );
        }
    }

    private function set_temporary_dismiss_notice_time() {
        if ( isset( $_GET['blockspare_notice_dismiss_temporary'] ) && 'upgrade' === $_GET['blockspare_notice_dismiss_temporary'] ) {
            update_user_meta( $this->current_user_id, 'blockspare_upgrade_notice_dismiss_temporary_start_time', time() );
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
        if ( get_option( 'blockspare_upgrade_notice_start_time' ) > strtotime( '-2 days' )
            || get_user_meta( get_current_user_id(), 'blockspare_upgrade_notice_dismiss', true )
            || get_user_meta( get_current_user_id(), 'blockspare_upgrade_notice_dismiss_temporary_start_time', true ) > strtotime( '-2 days' )
        ) {
            add_filter( 'blockspare_upgrade_notice_dismiss', '__return_true' );
        } else {
            add_filter( 'blockspare_upgrade_notice_dismiss', '__return_false' );
        }
    }

    public function notice_markup() {
        ?>
        <div class="notice notice-success blockspare-notice" >
            <div class="blockspare-notice__logo">
                <svg fill="url(#blockspare-gradient)" width="24" height="24" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 40 40" class="blockspare-svg blockspare-svg-blockspare" aria-hidden="true" focusable="false"><g class="blockspare-logo"><g><path class="st0" d="M1.6,9.2v21.4l18.3-9.2L20.2,0L1.6,9.2z M16.9,19.8L4.9,26V10.9l12-6.1V19.8z"></path><polygon id="XMLID_3_" class="st1" points="19.9,21.4 16.9,23 26,27.7 13.8,33.8 4.9,29 1.6,30.7 13.9,36.9 32.4,27.7      "></polygon></g><g><polygon id="XMLID_2_" class="st0" points="23,1.5 23,19.8 32.4,24.6 32.4,9.4 35.3,10.9 35.3,29 38.4,30.7 38.4,9.2 29.4,4.8  29.2,19.7 26,18.4 26,3.1        "></polygon><polygon id="XMLID_1_" class="st1" points="17,38.4 19.9,40 38.4,30.7 35.3,29        "></polygon></g></g></svg>
            </div>
            <div class="blockspare-notice__content">
                <?php
                $current_user = wp_get_current_user();

                printf(
                /* Translators: %1$s current user display name., %2$s this plugin name., %3$s discount coupon code., %4$s discount percentage. */
                   esc_html__(
                        '%1$s %7$s We would appreciate it if you can %4$s rate us on WordPress.org%5$s! By spreading the love, we will continue to create thrilling new features for free in the future. Enjoy! %8$s',
                        'blockspare'
                    ),
                    '<h2 class="blockspare-notice-title">Hello ' . esc_html( $current_user->display_name ) . ', you are awesome for using Blockspare Pro!</h2>',
                    '<p><strong>Blockspare</strong>',
                    '<strong><a target="_blank" href="https://www.blockspare.com/pricing/">Blockspare Pro</a></strong>',
                    '<strong><a href="https://wordpress.org/support/plugin/blockspare/reviews/?filter=5#new-post" target="_blank">',
                    '</a></strong>',
                    '<br>',
                    '<p class="blockspare-notice-description">',
                    '</p>',
                );

                ?>
                <div class="links">
                    <a href="https://wordpress.org/support/plugin/blockspare/reviews/?filter=5#new-post" class="button button-primary" target="_blank">
                        <span><?php esc_html_e( 'Sure thing', 'blockspare' ); ?></span>
                    </a>   
                    <a href="<?php echo esc_url( $this->pricing_url ); ?>" class="button button-secondary" target="_blank">
                        <span><?php esc_html_e( 'Unlock All Features', 'blockspare' ); ?></span>
                    </a>           
    
                    <a href="<?php echo esc_url( $this->temporary_dismiss_url ); ?>" class="button button-secondary plain">
                        <span><?php esc_html_e( 'Maybe later', 'blockspare' ); ?></span>
                    </a>
    
                    <a href="https://afthemes.com/supports/" class="button button-secondary plain" target="_blank">
                        <span><?php esc_html_e( 'Need help?', 'blockspare' ); ?></span>
                    </a>                           
                </div>
            </div>
            <div class="bs-notice-image">
                <img src='<?php echo BLOCKSPARE_PLUGIN_URL ."admin/assets/images/review.webp";?>'/>
            </div>
            <a class="blockspare-notice-dismiss notice-dismiss" href="<?php echo esc_url( $this->dismiss_url ); ?>"></a>
        </div> <!-- /blockspare-notice -->
        <?php
    }
}

new Blockspare_Upgrade_Notice();
