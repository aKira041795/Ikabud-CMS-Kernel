<?php
// Exit if accessed directly.
defined('ABSPATH') || exit;

class Elespare_Notice {
    public $name;
    public $type;
    public $dismiss_url;
    public $temporary_dismiss_url;
    public $pricing_url;
    public $current_user_id;

    public function __construct($name, $type, $dismiss_url, $temporary_dismiss_url) {
        $this->name = $name;
        $this->type = $type;
        $this->dismiss_url = $dismiss_url;
        $this->temporary_dismiss_url = $temporary_dismiss_url;
        $this->pricing_url = 'https://elespare.com/pricing/';
        $this->current_user_id = get_current_user_id();

        // Conditionally show notice on relevant admin pages.
        add_action('admin_notices', array($this, 'maybe_display_notice'));
        add_action('admin_head', array($this, 'enqueue_styles'));

        $this->dismiss_notice();
        $this->dismiss_notice_temporary();
    }

    public function maybe_display_notice() {
        $screen = get_current_screen();
        $allowed_screens = ['dashboard', 'plugins', 'toplevel_page_elespare_dashboard', 'elespare_page_elespare_explore_more'];
        if (in_array($screen->id, $allowed_screens) && !$this->is_dismiss_notice()) {
            $this->notice_markup();
        }
    }

    private function is_dismiss_notice() {
        // Meta keys have been renamed to ensure the notice reappears for users.
        $permanent_dismissed = get_user_meta($this->current_user_id, 'elespare_' . $this->name . '_notice_dismiss_v2', true);
        $temporary_dismissed = get_user_meta($this->current_user_id, 'elespare_' . $this->name . '_notice_dismiss_temporary_v2', true);

        // Reset temporary dismiss after 7 days.
        if ($temporary_dismissed && (time() - intval($temporary_dismissed)) > 7 * DAY_IN_SECONDS) {
            delete_user_meta($this->current_user_id, 'elespare_' . $this->name . '_notice_dismiss_temporary_v2');
            $temporary_dismissed = false;
        }

        return apply_filters('elespare_' . $this->name . '_notice_dismiss', $permanent_dismissed || $temporary_dismissed);
    }

    public function dismiss_notice() {
        if (isset($_GET['elespare_notice_dismiss']) && isset($_GET['_elespare_upgrade_notice_dismiss_nonce'])) {
            if (!wp_verify_nonce(wp_unslash($_GET['_elespare_upgrade_notice_dismiss_nonce']), 'elespare_upgrade_notice_dismiss_nonce')) {
                wp_die(esc_html__('Action failed. Please refresh the page and retry.', 'elespare'));
            }

            if (!current_user_can('publish_posts')) {
                wp_die(esc_html__('Cheatin&#8217; huh?', 'elespare'));
            }

            $dismiss_notice = sanitize_text_field(wp_unslash($_GET['elespare_notice_dismiss']));
            if ($dismiss_notice === $_GET['elespare_notice_dismiss']) {
                // Renamed the meta key for permanent dismissal.
                update_user_meta($this->current_user_id, 'elespare_' . $dismiss_notice . '_notice_dismiss_v2', 'yes');
            }
        }
    }

    public function dismiss_notice_temporary() {
        if (isset($_GET['elespare_notice_dismiss_temporary']) && isset($_GET['_elespare_upgrade_notice_dismiss_temporary_nonce'])) {
            if (!wp_verify_nonce(wp_unslash($_GET['_elespare_upgrade_notice_dismiss_temporary_nonce']), 'elespare_upgrade_notice_dismiss_temporary_nonce')) {
                wp_die(esc_html__('Action failed. Please refresh the page and retry.', 'elespare'));
            }

            if (!current_user_can('publish_posts')) {
                wp_die(esc_html__('Cheatin&#8217; huh?', 'elespare'));
            }

            $dismiss_notice = sanitize_text_field(wp_unslash($_GET['elespare_notice_dismiss_temporary']));
            if ($dismiss_notice === $_GET['elespare_notice_dismiss_temporary']) {
                // Renamed the meta key for temporary dismissal and store the time of dismissal.
                update_user_meta($this->current_user_id, 'elespare_' . $dismiss_notice . '_notice_dismiss_temporary_v2', time());
            }
        }
    }

    public function enqueue_styles() {
        echo '<style>.elespare-notice {border-left-color: #0073aa;}</style>';
    }

    public function notice_markup() {
        ?>
        <div class="notice notice-success elespare-notice">
            <a class="elespare-notice-dismiss notice-dismiss" href="<?php echo esc_url($this->dismiss_url); ?>"></a>
            <span class="elespare-icon-display"></span>
            <?php
            $current_user = wp_get_current_user();
            printf(
                esc_html__(
                    '%1$s %7$s We would appreciate it if you can %4$sgive us a review on WordPress.org%5$s! By spreading the love, we will continue to create thrilling new features for free in the future! %6$sYou may always upgrade to %3$s to gain access to more premium features. Enjoy! %8$s',
                    'elespare'
                ),
                '<h2>Hello ' . esc_html($current_user->display_name) . ', you are awesome for using Elespare!</h2>',
                '<p class="notice-text"><strong>Elespare</strong>',
                '<strong><a target="_blank" href="https://elespare.com/pricing/">Elespare Pro</a></strong>',
                '<strong><a href="https://wordpress.org/support/plugin/elespare/reviews/?filter=5#new-post" target="_blank">',
                '</a></strong>',
                '<br>',
                '<p>',
                '</p>'
            );
            ?>
            <div class="links">
                <a href="https://wordpress.org/support/plugin/elespare/reviews/?filter=5#new-post" class="button button-primary" target="_blank">
                    <span><?php esc_html_e('Sure thing', 'elespare'); ?></span>
                </a>
                <a href="<?php echo esc_url($this->pricing_url); ?>" class="button button-secondary" target="_blank">
                    <span><?php esc_html_e('Upgrade', 'elespare'); ?></span>
                </a>                
                <a href="<?php echo esc_url($this->temporary_dismiss_url); ?>" class="button button-secondary plain">
                    <span><?php esc_html_e('Remind Me later', 'elespare'); ?></span>
                </a>
                <a href="https://afthemes.com/supports/" class="button button-secondary plain" target="_blank">
                    <span><?php esc_html_e('Need help?', 'elespare'); ?></span>
                </a>
            </div>
        </div>
        <?php
    }
}

class Elespare_Upgrade_Notice extends Elespare_Notice {

    public function __construct() {
        if (!current_user_can('edit_posts')) {
            return;
        }

        $dismiss_url = wp_nonce_url(
            add_query_arg('elespare_notice_dismiss', 'upgrade', admin_url()),
            'elespare_upgrade_notice_dismiss_nonce',
            '_elespare_upgrade_notice_dismiss_nonce'
        );

        $temporary_dismiss_url = wp_nonce_url(
            add_query_arg('elespare_notice_dismiss_temporary', 'upgrade', admin_url()),
            'elespare_upgrade_notice_dismiss_temporary_nonce',
            '_elespare_upgrade_notice_dismiss_temporary_nonce'
        );

        parent::__construct('upgrade', 'info', $dismiss_url, $temporary_dismiss_url);

        $this->set_notice_time();
        $this->set_temporary_dismiss_notice_time();
        $this->set_dismiss_notice();
    }

    private function set_notice_time() {
        if (!get_option('elespare_upgrade_notice_start_time')) {
            update_option('elespare_upgrade_notice_start_time', time());
        }
    }

    private function set_temporary_dismiss_notice_time() {
        if (isset($_GET['elespare_notice_dismiss_temporary']) && 'upgrade' === $_GET['elespare_notice_dismiss_temporary']) {
            update_user_meta($this->current_user_id, 'elespare_upgrade_notice_dismiss_temporary_start_time_v2', time());
        }
    }

    public function set_dismiss_notice() {
        if (get_option('elespare_upgrade_notice_start_time') > strtotime('-2 day')
            || get_user_meta($this->current_user_id, 'elespare_upgrade_notice_dismiss_v2', true)
            || get_user_meta($this->current_user_id, 'elespare_upgrade_notice_dismiss_temporary_start_time_v2', true) > strtotime('-7 day')
        ) {
            // Hide notice based on timing or dismissal.
        }
    }
}

new Elespare_Upgrade_Notice();
