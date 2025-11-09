<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

$current_user = wp_get_current_user();
$theme = wp_get_theme();

// Define allowed tabs for security
$allowed_tabs = [
	'welcome',
	'starter-templates',
	'support',
	'free-vs-pro'
];

// Get the current tab with proper validation
$current_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'welcome';

// Validate that the tab is in the allowed list
if ( ! in_array($current_tab, $allowed_tabs, true) ) {
	$current_tab = 'welcome';
}

?>

<div class="newsx-menu-page-wrap">
    <!-- Header -->
    <div class="newsx-page-header-wrap">
        <nav class="newsx-page-header newsx-flex">
            <div class="newsx-flex">
                <div class="newsx-logo newsx-flex newsx-items-center">
                    <img src="<?php echo esc_url(NEWSX_ADMIN_URL . '/assets/images/logo.png'); ?>" alt="<?php esc_attr_e('News Magazine X', 'news-magazine-x'); ?>">
                </div>
                <div class="newsx-menu newsx-flex">
                    <a href="<?php echo esc_url(admin_url('admin.php?page=newsx-options&tab=welcome')); ?>" class="newsx-inline-flex newsx-items-center <?php echo ('welcome' !== $current_tab) ? '' : 'active'; ?>"><?php esc_html_e('Welcome', 'news-magazine-x'); ?></a>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=newsx-options&tab=starter-templates')); ?>" class="newsx-inline-flex newsx-items-center <?php echo ('starter-templates' !== $current_tab) ? '' : 'active'; ?>"><?php esc_html_e('Starter Templates', 'news-magazine-x'); ?> <span class="dashicons dashicons-star-filled"></span></a>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=newsx-options&tab=support')); ?>" class="newsx-inline-flex newsx-items-center <?php echo ('support' !== $current_tab) ? '' : 'active'; ?>"><?php esc_html_e('Support', 'news-magazine-x'); ?></a>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=newsx-options&tab=free-vs-pro')); ?>" class="newsx-inline-flex newsx-items-center <?php echo ('free-vs-pro' !== $current_tab) ? '' : 'active'; ?>"><?php esc_html_e('Free vs Pro', 'news-magazine-x'); ?></a>
                </div>
            </div>

            <div class="newsx-header-info newsx-flex">
                <!-- <div class="newsx-theme-version newsx-flex newsx-items-center"><?php esc_html_e('V', 'news-magazine-x'); ?> <?php echo esc_html(NEWSX_THEME_VERSION); ?></div> -->

                <div class="newsx-upgrade-pro newsx-flex newsx-items-center">
                    <a href="https://wp-royal-themes.com/themes/item-news-magazine-x-pro/?ref=newsx-free-dash-header-unlock-pro#features" target="_blank">
                        <svg width="16" height="12" viewBox="0 0 16 12" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M3.3335 11.3337H12.6668M1.3335 0.666992L3.3335 8.66699H12.6668L14.6668 0.666992L10.6668 5.33366L8.00016 0.666992L5.3335 5.33366L1.3335 0.666992Z" stroke="#046BD2" stroke-linecap="round" stroke-linejoin="round" class="svg-path"></path></svg>
                        <span><?php esc_html_e('Unlock Pro Features', 'news-magazine-x'); ?></span>
                    </a>
                </div>

                <div class="newsx-docs-link newsx-flex newsx-items-center">
                    <a href="https://wp-royal-themes.com/new-themes/news-magazine-x/docs/?ref=newsx-free-dash-header-docs" target="_blank" title="<?php esc_attr_e('Theme Documentation', 'news-magazine-x'); ?>">
                        <svg width="30" height="30" viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><rect x="0.5" y="0.5" width="31" height="31" rx="15.5" fill="white"></rect><path d="M16 11.0347C14.6731 9.84713 12.9209 9.125 11 9.125C10.1234 9.125 9.28195 9.27539 8.5 9.55176V21.4268C9.28195 21.1504 10.1234 21 11 21C12.9209 21 14.6731 21.7221 16 22.9097M16 11.0347C17.3269 9.84713 19.0791 9.125 21 9.125C21.8766 9.125 22.7181 9.27539 23.5 9.55176V21.4268C22.7181 21.1504 21.8766 21 21 21C19.0791 21 17.3269 21.7221 16 22.9097M16 11.0347V22.9097" stroke="#475569" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"></path></svg>
                        <span><?php esc_html_e('Documentation', 'news-magazine-x'); ?></span>
                    </a>
                </div>
            </div>
        </nav>
    </div>

    <!-- Content -->
    <div class="newsx-page-content">
        
        <div class="newsx-page-content-main">

        <?php include NEWSX_INCLUDES_DIR . '/admin/menu/tab-' . $current_tab . '.php'; ?>

    </div> <!-- End of Content -->

</div>