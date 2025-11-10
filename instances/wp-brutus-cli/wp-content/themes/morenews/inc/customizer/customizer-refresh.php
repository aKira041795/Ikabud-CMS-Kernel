<?php
// Front-page

$wp_customize->selective_refresh->add_partial('show_top_header_section', array(
    'selector' => 'div.top-header div.date-bar-left',     
));

$wp_customize->selective_refresh->add_partial('banner_advertisement_section', array(
    'selector' => 'div.header-promotion',     
));

$wp_customize->selective_refresh->add_partial('aft_custom_link', array(
    'selector' => 'div.custom-menu-link',     
));

$wp_customize->selective_refresh->add_partial('flash_news_title', array(
    'selector' => 'div.exclusive-now',     
));

$wp_customize->selective_refresh->add_partial('frontpage_popular_tags_section_title', array(
    'selector' => 'div.aft-popular-tags > div',     
));

$wp_customize->selective_refresh->add_partial('main_banner_news_section_title', array(
    'selector' => 'div.aft-slider-part div.morenews-customizer',     
));

$wp_customize->selective_refresh->add_partial('main_latest_news_section_title', array(
    'selector' => 'div.af-main-banner-tabbed-posts',     
));

$wp_customize->selective_refresh->add_partial('main_editors_picks_section_title', array(
    'selector' => 'div.af-main-banner-thumb-posts',     
));

$wp_customize->selective_refresh->add_partial('main_trending_news_section_title', array(
    'selector' => 'div.aft-trending-part div.morenews-customizer',     
));

$wp_customize->selective_refresh->add_partial('featured_news_section_title', array(
    'selector' => 'div.af-main-banner-featured-posts.morenews-customizer',     
));

$wp_customize->selective_refresh->add_partial('show_featured_post_list_section', array(
    'selector' => 'section.aft-featured-category-section.morenews-customizer .container-wrapper',     
));

$wp_customize->selective_refresh->add_partial('frontpage_latest_posts_section_title', array(
    'selector' => 'div.af-main-banner-latest-posts.morenews-customizer .container-wrapper',     
));

$wp_customize->selective_refresh->add_partial('frontpage_content_type', array(
    'selector' => 'body.page-template-tmpl-front-page #content',     
));

$wp_customize->selective_refresh->add_partial('footer_copyright_text', array(
    'selector' => 'footer.site-footer .site-info',     
));

$wp_customize->selective_refresh->add_partial('global_scroll_to_top_position', array(
    'selector' => 'a#scroll-up',     
));

$wp_customize->selective_refresh->add_partial('select_breadcrumb_mode', array(
    'selector' => 'div.af-breadcrumbs',     
));

$wp_customize->selective_refresh->add_partial('global_single_content_mode', array(
    'selector' => 'article.af-single-article',     
));

$wp_customize->selective_refresh->add_partial('single_related_posts_title', array(
    'selector' => 'div.af-reated-posts',     
));

$wp_customize->selective_refresh->add_partial('archive_layout', array(
    'selector' => 'div.aft-archive-wrapper',     
));

$wp_customize->selective_refresh->add_partial('single_show_theme_author_bio', array(
    'selector' => 'div.author-box-content',     
));

$wp_customize->selective_refresh->add_partial('enable_site_mode_switch', array(
    'selector' => '#aft-dark-light-mode-wrap',     
));