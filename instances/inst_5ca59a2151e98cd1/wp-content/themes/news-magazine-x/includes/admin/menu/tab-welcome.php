<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

?>

<!-- Welcome -->
<div class="newsx-welcome-hero-section">
    <h2>
        <?php 
            /* translators: %1$s: Hello text, %2$s: User display name */
            printf( '%1$s %2$s,', 
                esc_html__('Hello', 'news-magazine-x'),
                esc_html($current_user->display_name)
            ); 
        ?>
    </h2>
    
    <div class="newsx-theme-intro">
        <h1>
            <?php 
                if ( defined('NEWSX_CORE_PRO_VERSION') && newsx_core_pro_fs()->can_use_premium_code() ) {
                    printf( '%1$s %2$s <span class="newsx-badge-pro">%3$s</span>',
                        esc_html__('Welcome to', 'news-magazine-x'),
                        esc_html($theme->get('Name')), 
                        esc_html__('PRO', 'news-magazine-x')
                    );
                } else {
                    printf( '%1$s %2$s <span class="newsx-badge-free">%3$s</span>',
                        esc_html__('Welcome to', 'news-magazine-x'),
                        esc_html($theme->get('Name')),
                        esc_html__('FREE', 'news-magazine-x')
                    );
                }
            ?>
        </h1>
        
        <p class="newsx-theme-description">
            <?php echo esc_html__('Experience the ultimate magazine-themed website building journey! Enjoy exploring all the settings and features to get started effortlessly.', 'news-magazine-x'); ?>
        </p>

        <div class="newsx-action-buttons">
            <a href="https://wp-royal-themes.com/themes/item-news-magazine-x-free/?ref=newsx-free-dash-welcome-demo-preview/#!/demo-preview" class="button button-primary newsx-demo-preview-link" target="_blank">
                <?php esc_html_e('Theme Demo Preview', 'news-magazine-x'); ?>
                <span class="dashicons dashicons-external"></span>
            </a>
            
            <a href="<?php echo esc_url(admin_url('customize.php')); ?>" class="newsx-start-customizing-link">
                <?php esc_html_e('Start Customizing', 'news-magazine-x'); ?>
            </a>
        </div>
    </div>

    <!-- <div class="newsx-video-container">
        Welcome Video Here
    </div> -->
</div>

<!-- Quick Settings -->
<div class="newsx-quick-settings">
    <div class="newsx-section-header newsx-flex newsx-items-center newsx-justify-between">
        <h2><?php esc_html_e('Quick Settings', 'news-magazine-x'); ?></h2>
        <a href="<?php echo esc_url(admin_url('customize.php')); ?>" class="newsx-customizer-link"><?php esc_html_e('Go to Customizer', 'news-magazine-x'); ?></a>
    </div>

    <div class="newsx-settings-grid">
        <!-- Row 1 -->

        <div class="newsx-settings-card">
            <h3><?php esc_html_e('Front Page', 'news-magazine-x'); ?></h3>
            <a href="<?php echo esc_url(admin_url('customize.php?autofocus[section]=newsx_section_front_page')); ?>" class="newsx-customize-link"><?php esc_html_e('Customize', 'news-magazine-x'); ?></a>
            
            <a href="https://youtu.be/OrtzJs-wzlw?t=31" class="newsx-tutorial-link" target="_blank">
                <span class="dashicons dashicons-video-alt3"></span>
                <?php esc_html_e('Tutorial', 'news-magazine-x'); ?>
            </a>
        </div>

        <div class="newsx-settings-card">
            <h3><?php esc_html_e('Header Builder', 'news-magazine-x'); ?></h3>
            <a href="<?php echo esc_url(admin_url('customize.php?autofocus[panel]=newsx_panel_header')); ?>" class="newsx-customize-link"><?php esc_html_e('Customize', 'news-magazine-x'); ?></a>
        
            <a href="https://youtu.be/OrtzJs-wzlw?t=213" class="newsx-tutorial-link" target="_blank">
                <span class="dashicons dashicons-video-alt3"></span>
                <?php esc_html_e('Tutorial', 'news-magazine-x'); ?>
            </a>
        </div>

        <div class="newsx-settings-card">
            <h3><?php esc_html_e('Footer Builder', 'news-magazine-x'); ?></h3>
            <a href="<?php echo esc_url(admin_url('customize.php?autofocus[panel]=newsx_panel_footer')); ?>" class="newsx-customize-link"><?php esc_html_e('Customize', 'news-magazine-x'); ?></a>
        
            <a href="https://youtu.be/OrtzJs-wzlw?t=273" class="newsx-tutorial-link" target="_blank">
                <span class="dashicons dashicons-video-alt3"></span>
                <?php esc_html_e('Tutorial', 'news-magazine-x'); ?>
            </a>
        </div>

        <!-- Row 2 -->
        <div class="newsx-settings-card">
            <h3><?php esc_html_e('Colors', 'news-magazine-x'); ?></h3>
            <a href="<?php echo esc_url(admin_url('customize.php?autofocus[section]=newsx_section_global_colors')); ?>" class="newsx-customize-link"><?php esc_html_e('Customize', 'news-magazine-x'); ?></a>
        </div>

        <div class="newsx-settings-card">
            <h3><?php esc_html_e('Typography', 'news-magazine-x'); ?></h3>
            <a href="<?php echo esc_url(admin_url('customize.php?autofocus[section]=newsx_section_global_typography')); ?>" class="newsx-customize-link"><?php esc_html_e('Customize', 'news-magazine-x'); ?></a>
        </div>

        <div class="newsx-settings-card">
            <h3><?php esc_html_e('Layout', 'news-magazine-x'); ?></h3>
            <a href="<?php echo esc_url(admin_url('customize.php?autofocus[section]=newsx_section_global_layout')); ?>" class="newsx-customize-link"><?php esc_html_e('Customize', 'news-magazine-x'); ?></a>
        </div>
        <div class="newsx-settings-card">
            <h3><?php esc_html_e('Site Identity', 'news-magazine-x'); ?></h3>
            <a href="<?php echo esc_url(admin_url('customize.php?autofocus[section]=title_tagline')); ?>" class="newsx-customize-link"><?php esc_html_e('Customize', 'news-magazine-x'); ?></a>
        </div>

        <!-- Row 3 -->
        <div class="newsx-settings-card">
            <h3><?php esc_html_e('Blog Options', 'news-magazine-x'); ?></h3>
            <a href="<?php echo esc_url(admin_url('customize.php?autofocus[panel]=newsx_panel_blog_page')); ?>" class="newsx-customize-link"><?php esc_html_e('Customize', 'news-magazine-x'); ?></a>
        </div>

        <div class="newsx-settings-card">
            <h3><?php esc_html_e('Menus', 'news-magazine-x'); ?></h3>
            <a href="<?php echo esc_url(admin_url('customize.php?autofocus[panel]=nav_menus')); ?>" class="newsx-customize-link"><?php esc_html_e('Customize', 'news-magazine-x'); ?></a>
        </div>
    </div>
</div> <!-- End of Quick Settings -->

</div> <!-- End of Content Main -->


<!-- Sidebar widgets -->
<div class="newsx-page-content-sidebar">
    
<div class="newsx-sidebar-widget newsx-sidebar-widget-video-tutorial">
    <h3><?php esc_html_e('Getting Started Video', 'news-magazine-x'); ?></h3>
    <p>
        <a href="https://youtu.be/OrtzJs-wzlw" target="_blank">
            <img src="<?php echo esc_url(NEWSX_ADMIN_URL . '/assets/images/video-tutorial.jpg'); ?>" alt="<?php esc_attr_e('Video Tutorial', 'news-magazine-x'); ?>">
        </a>
    </p>
    <p><a href="https://youtu.be/OrtzJs-wzlw" target="_blank"><?php esc_html_e('Watch Video Tutorial →', 'news-magazine-x'); ?></a></p>
</div>

<div class="newsx-sidebar-widget newsx-sidebar-widget-starter-templates">
    <h3><?php esc_html_e('Starter Templates', 'news-magazine-x'); ?></h3>
    <p>
        <a href="<?php echo esc_url(admin_url('admin.php?page=newsx-options&tab=starter-templates')); ?>">
            <img src="<?php echo esc_url(NEWSX_ADMIN_URL . '/assets/images/starter-templates.png'); ?>" alt="<?php esc_attr_e('Starter Templates', 'news-magazine-x'); ?>">
        </a>
    </p>
    <p><?php esc_html_e('With just one click, select the demo that suits your needs, import it, and customize it to match your personal style!', 'news-magazine-x'); ?></p>
    <p><a href="<?php echo esc_url(admin_url('admin.php?page=newsx-options&tab=starter-templates')); ?>"><?php esc_html_e('View Starter Templates →', 'news-magazine-x'); ?></a></p>
</div>

<div class="newsx-sidebar-widget">
    <h3><?php esc_html_e('Review', 'news-magazine-x'); ?></h3>
    <div class="newsx-star-rating-wrap newsx-flex newsx-items-center">
        <div class="newsx-star-rating">
            <span class="dashicons dashicons-star-filled"></span>
            <span class="dashicons dashicons-star-filled"></span>
            <span class="dashicons dashicons-star-filled"></span>
            <span class="dashicons dashicons-star-filled"></span>
            <span class="dashicons dashicons-star-filled"></span>
        </div>
        <span class="newsx-star-rating-text"><?php esc_html_e('5 Stars', 'news-magazine-x'); ?></span>
    </div>
    <p><?php esc_html_e('We\'d love your feedback! Please take a moment to review the theme and share your thoughts.', 'news-magazine-x'); ?></p>
    <p><a href="https://wordpress.org/support/theme/news-magazine-x/reviews/" target="_blank"><?php esc_html_e('Leave a Review →', 'news-magazine-x'); ?></a></p>
</div>

<div class="newsx-sidebar-widget">
    <h3><?php esc_html_e('Support', 'news-magazine-x'); ?></h3>
    <p><?php esc_html_e('If you have any questions or need help, please feel free to contact us.', 'news-magazine-x'); ?></p>
    <p><a href="https://wp-royal-themes.com/contact/?ref=newsx-free-dash-welcome-contact/#!/cform" target="_blank"><?php esc_html_e('Contact Support →', 'news-magazine-x'); ?></a></p>
</div>
</div>