<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Provide a admin area view for the plugin
 *
 * This file is used to markup the admin-facing aspects of the plugin.
 *
 * @link       https://https://wp-royal-themes.com/
 * @since      1.0.0
 *
 * @package    Newsx_Core
 * @subpackage Newsx_Core/admin/partials
 */
?>

<header class="newsx-starter-templates-header">
    <h1><?php echo esc_html__( 'News Magazine X Starter Templates', 'news-magazine-x-core' ); ?></h1>
    <p><?php echo esc_html__( 'Choose a template to import and setup your website in seconds.', 'news-magazine-x-core' ); ?></p>
    <a href="https://www.youtube.com/watch?v=zCfzzUuX8HE" target="_blank" class="button button-primary newsx-video-tutorial-link">
        <?php echo esc_html__( 'Video Tutorial', 'news-magazine-x-core' ); ?>
        <span class="dashicons dashicons-video-alt3"></span>
    </a>
</header>

<div class="newsx-starter-templates-wrap">
    <div class="newsx-starter-templates">
        <?php

        foreach ( newsx_get_templates_data() as $template ) {
            newsx_starter_template_markup( $template );
        }
        
        ?>

        <!-- coming Soon -->
        <div class="newsx-template newsx-template-coming-soon">
            <h3><?php echo esc_html__( 'coming Soon', 'news-magazine-x-core' ); ?></h3>
            <p><?php echo esc_html__( 'More templates are on the way!', 'news-magazine-x-core' ); ?></p>
            <p><?php echo esc_html__( 'Stay tuned for updates.', 'news-magazine-x-core' ); ?></p>
        </div>
    </div>
</div>

<div class="newsx-import-popup-wrap first">
    <div class="newsx-import-popup">
        <header class="newsx-flex">
            <h3><?php echo esc_html__('Setup Starter Template', 'news-magazine-x-core'); ?></h3>
            <span class="dashicons dashicons-no-alt close-btn"></span>
        </header>

        <div class="content">
            <p><?php echo esc_html__( 'For the best results, it is recommended to temporarily deactivate All Active 3rd Party plugins except News Magazine X Core (Pro). Site Header, Footer, Pages, Media Files, Menus and some required plugins will be set up and installed on your website.', 'news-magazine-x-core' ); ?></p>
            <h4><?php echo esc_html__( 'Choose what you want to import:', 'news-magazine-x-core' ); ?></h4>

            <ul>
                <li>
                    <input type="checkbox" id="import-customizer" name="import-customizer" value="1" checked>
                    <label for="import-customizer"><?php 
                    // translators: %1$s is a placeholder for the span HTML tag containing additional details
                    echo sprintf( esc_html__( 'Customizer Settings %1$s', 'news-magazine-x-core' ), '<span>(Website Design, Colors, Fonts, Widgets, etc.)</span>' ); ?></label>
                </li>
                <li>
                    <input type="checkbox" id="import-content" name="import-content" value="1" checked>
                    <label for="import-content"><?php 
                    // translators: %1$s is a placeholder for the span HTML tag containing additional details
                    echo sprintf( esc_html__( 'Site Content %1$s', 'news-magazine-x-core' ), '<span>(Posts, Pages, Menus, Media, etc.)</span>' ); ?></label>
                </li>
            </ul>
        </div>

        <footer class="newsx-flex">
            <button class="button button-primary newsx-start-import"><?php echo esc_html__( 'Start Import', 'news-magazine-x-core' ); ?><span class="dashicons dashicons-arrow-right-alt"></span></button>
        </footer>
    </div>
</div>

<div class="newsx-import-popup-wrap second">
    <div class="newsx-import-popup">
        <header class="newsx-flex">
            <h3><?php echo esc_html__('Starter Template is being imported...', 'news-magazine-x-core'); ?></h3>
            <span class="dashicons dashicons-no-alt close-btn"></span>
        </header>

        <div class="content">
            <p><?php echo esc_html__('The import process may take a few seconds, depending on the size of the Template and the speed of your connection.', 'news-magazine-x-core'); ?></p>
            <p>
                <?php 
                // translators: %1$s and %2$s are placeholders for the text "DO NOT CLOSE" and "this browser window" respectively.
                echo sprintf( esc_html__('Please %1$sDO NOT CLOSE%2$s this browser window until the import is complete.', 'news-magazine-x-core'), '<strong>', '</strong>' ); 
                echo '<br>';
                echo sprintf( esc_html__('You can close this window after the import is finished.', 'news-magazine-x-core'), '<strong>', '</strong>' );
                ?>
            </p>

            <div class="progress-wrap">
                <div class="progress-bar"></div>
                <strong>
                    <span class="steps"></span>
                    <span class="dot-flashing"></span>
                </strong>
            </div>
        </div>
    </div>
</div>

<?php

function newsx_get_templates_data() {
    return [
        [
            'type' => 'free',
            'slug' => 'main',
            'name' => esc_html__( 'Main', 'news-magazine-x-core' ),
            'site_identity' => [
                'title' => 'News',
                'tagline' => 'Magazine WP Theme',
            ],
            'plugins' => [
                'contact-form-7' => true,
                'mailchimp-for-wp' => true,
            ],
            'demo_url' => 'https://news-magazine-x-free.wp-royal-themes.com/demo/?ref=newsx-free-dash-starter-template-main',
        ],
        [
            'type' => 'free',
            'slug' => 'island',
            'name' => esc_html__( 'Island', 'news-magazine-x-core' ),
            'site_identity' => [
                'title' => 'News Magazine',
                'tagline' => 'Get The Most Freshy News Every Day',
            ],
            'plugins' => [
                'contact-form-7' => true,
                'mailchimp-for-wp' => true,
            ],
            'demo_url' => 'https://news-magazine-x-free.wp-royal-themes.com/island/?ref=newsx-free-dash-starter-template-island',
        ],
        [
            'type' => 'pro',
            'slug' => 'main-pro',
            'name' => esc_html__( 'Main', 'news-magazine-x-core' ),
            'site_identity' => [
                'title' => 'News',
                'tagline' => 'Magazine WP Theme',
            ],
            'plugins' => [
                'contact-form-7' => true,
                'mailchimp-for-wp' => true,
            ],
            'demo_url' => 'https://news-magazine-x-pro.wp-royal-themes.com/demo/?ref=newsx-free-dash-starter-template-main-pro',
        ],
        [
            'type' => 'pro',
            'slug' => 'dark-pro',
            'name' => esc_html__( 'Dark', 'news-magazine-x-core' ),
            'site_identity' => [
                'title' => 'Dark',
                'tagline' => 'Magazine WP Theme',
            ],
            'plugins' => [
                'contact-form-7' => true,
                'mailchimp-for-wp' => true,
            ],
            'demo_url' => 'https://news-magazine-x-pro.wp-royal-themes.com/dark/?ref=newsx-free-dash-starter-template-dark-pro',
        ],
        [
            'type' => 'pro',
            'slug' => 'times-pro',
            'name' => esc_html__( 'Times', 'news-magazine-x-core' ),
            'site_identity' => [
                'title' => 'Times',
                'tagline' => 'Magazine WP Theme',
            ],
            'plugins' => [
                'contact-form-7' => true,
                'mailchimp-for-wp' => true,
            ],
            'demo_url' => 'https://news-magazine-x-pro.wp-royal-themes.com/times/?ref=newsx-free-dash-starter-template-times-pro',
        ],
        [
            'type' => 'free',
            'slug' => 'demo-v2',
            'name' => esc_html__( 'Demo v2', 'news-magazine-x-core' ),
            'site_identity' => [
                'title' => 'News Magazine',
                'tagline' => 'Get The Most Freshy News Every Day',
            ],
            'plugins' => [
                'contact-form-7' => true,
                'mailchimp-for-wp' => true,
            ],
            'demo_url' => 'https://news-magazine-x-free.wp-royal-themes.com/demo-v2/?ref=newsx-free-dash-starter-template-demo-v2',
        ],
    ];
}

function newsx_starter_template_markup( $args ) {
    $btn_class = '';
    $plugins = isset($args['plugins']) ? $args['plugins'] : [];
    $template_data = [
        'type' => $args['type'],
        'slug' => $args['slug'],
        'plugins' => $plugins,
        'site_identity' => $args['site_identity'],
    ];
    
    echo '<div class="newsx-template" data-template-data="'. esc_attr(json_encode($template_data)) .'">';
        echo '<div class="newsx-template-image">';
            $img_src = NEWSX_CORE_URL . 'admin/import/data/'. $args['slug'] .'/demo.jpg';
            echo '<img src="'. esc_url($img_src) .'" alt="'. esc_attr($args['name']) .'">';
            echo '<a class="newsx-template-link" href="'. $args['demo_url'] .'" target="_blank"></a>';
        echo '</div>';

        echo '<div class="newsx-template-footer newsx-flex">';

        if ( $args['type'] === 'free' || ( $args['type'] === 'pro' && defined('NEWSX_CORE_PRO_VERSION') && newsx_core_pro_fs()->can_use_premium_code() ) ) {
            echo '<h3>';
                echo esc_html($args['name']);

                // Free Badge
                if ( !defined('NEWSX_CORE_PRO_VERSION') || !newsx_core_pro_fs()->can_use_premium_code() ) {
                    echo '<span class="newsx-free-badge">'. esc_html__( 'Free', 'news-magazine-x-core' ) .'</span>';
                }
            echo '</h3>';
            // echo '<p>'. esc_html__( 'Click the button below to import the demo content.', 'news-magazine-x-core' ) .'</p>';
            
            echo '<a href="'. esc_url( $args['demo_url'] ) .'" target="_blank" class="newsx-preview-button">';
                echo esc_html__( 'Preview', 'news-magazine-x-core' );
                echo '<span class="dashicons dashicons-external"></span>';
            echo '</a>';

            echo '<button class="button button-primary newsx-import-button '. esc_attr( $btn_class ) .'">';
                echo esc_html__( 'Import Template', 'news-magazine-x-core' );
            echo '</button>';
        } else {
            echo '<h3>';
                echo esc_html($args['name']);

                    // Pro Badge
                echo '<span class="newsx-pro-badge">'. esc_html__( 'Pro', 'news-magazine-x-core' ) .'</span>';
            echo '</h3>';
            // echo '<p>'. esc_html__( 'Click the button below to import the demo content.', 'news-magazine-x-core' ) .'</p>';

            echo '<a href="'. esc_url( $args['demo_url'] ) .'" target="_blank" class="newsx-preview-button">';
                echo esc_html__( 'Preview', 'news-magazine-x-core' );
                echo '<span class="dashicons dashicons-external"></span>';
            echo '</a>';
            
            echo '<a class="button button-primary newsx-upgrade-button" href="https://wp-royal-themes.com/themes/item-news-magazine-x-pro/?ref=newsx-free-dash-starter-template-'. $args['slug'] .'#features" target="_blank">';
                echo esc_html__( 'Get Pro', 'news-magazine-x-core' );
            echo '</a>';
        }

        echo '</div>';


    echo '</div>';
}
