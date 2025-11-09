<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

?>

<div class="newsx-page-content-inner">
    <div class="newsx-support-grid">
        <div class="newsx-support-card">
            <h3><?php esc_html_e('Documentation', 'news-magazine-x'); ?></h3>
            <p><?php esc_html_e('Need help setting up News Magazine X? Our documentation covers it all.', 'news-magazine-x'); ?></p>
            <div class="newsx-support-actions">
                <a href="https://wp-royal-themes.com/new-themes/news-magazine-x/docs/?ref=newsx-free-dash-support-docs" class="button button-primary" target="_blank">
					<?php esc_html_e('Read Documentation', 'news-magazine-x'); ?>
				</a>
            </div>
        </div>

        <div class="newsx-support-card">
            <h3><?php esc_html_e('Support Forum', 'news-magazine-x'); ?></h3>
            <p><?php esc_html_e('Have a question or need help? Our support team is here to assist you.', 'news-magazine-x'); ?></p>
            <div class="newsx-support-actions">
                <a href="https://wordpress.org/support/theme/news-magazine-x/" class="button button-primary" target="_blank">
					<?php esc_html_e('Get Support', 'news-magazine-x'); ?>
				</a>
            </div>
        </div>

        <div class="newsx-support-card">
            <h3><?php esc_html_e('Video Tutorials', 'news-magazine-x'); ?></h3>
            <p><?php esc_html_e('Watch our video tutorials to learn how to use News Magazine X theme.', 'news-magazine-x'); ?></p>
            <div class="newsx-support-actions">
                <a href="https://www.youtube.com/watch?v=OrtzJs-wzlw&list=PLjFiZESrp9558M7Rghnk5s4sMq6m3RyOb" class="button button-primary" target="_blank">
					<?php esc_html_e('Watch Tutorials', 'news-magazine-x'); ?>
				</a>
            </div>
        </div>

        <div class="newsx-support-card">
            <h3><?php esc_html_e('Email Support', 'news-magazine-x'); ?></h3>
            <p><?php esc_html_e('You can also contact us via email for any support related queries.', 'news-magazine-x'); ?></p>
            <div class="newsx-support-actions">
                <a href="https://wp-royal-themes.com/contact/?ref=newsx-free-dash-support-contact/#!/cform" class="button button-primary" target="_blank">
					<?php esc_html_e('Email Support', 'news-magazine-x'); ?>
				</a>
            </div>
        </div>
    </div>
</div>

<style>
.newsx-support-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 30px;
    max-width: 1280px;
    margin: 0 auto;
    padding: 0 30px;
}

.newsx-support-card {
    background: #fff;
    padding: 30px;
    border-radius: 3px;
    box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
}

.newsx-support-card h3 {
    margin: 0 0 15px;
    font-size: 18px;
}

.newsx-support-card p {
    margin: 0 0 20px;
    color: #646970;
}

.newsx-support-actions {
    margin-top: auto;
}

@media screen and (max-width: 782px) {
    .newsx-support-grid {
        grid-template-columns: 1fr;
    }
}
</style>


