<?php

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}


/**
 * Customizer
 *
 * @class   morenews
 */

if (!function_exists('morenews_custom_style')) {

    function morenews_custom_style()
    {

        global $morenews_google_fonts;
        $morenews_background_color = get_background_color();
        $light_background_color = '#' . $morenews_background_color;
        $dark_background_color = morenews_get_option('dark_background_color');
        $secondary_color = morenews_get_option('secondary_color');

        
        $global_font_family_type = morenews_get_option('global_font_family_type');

        $universal_fallbacks = "'Noto Sans', 'Noto Sans CJK SC', 'Noto Sans JP', 'Noto Sans KR',
                        system-ui, -apple-system, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif,
                        'Apple Color Emoji', 'Segoe UI Emoji', 'Segoe UI Symbol'";        
        $system_stack = 'system-ui, -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif,
                 "Apple Color Emoji", "Segoe UI Emoji", "Segoe UI Symbol"';

        if ($global_font_family_type === 'google') {

            $site_title_key = morenews_get_option('site_title_font');
            $primary_key    = morenews_get_option('primary_font');
            $secondary_key  = morenews_get_option('secondary_font');

            $site_title_font = !empty($morenews_google_fonts[$site_title_key])
                ? "'" . esc_attr($morenews_google_fonts[$site_title_key]) . "', " . $universal_fallbacks
                : $universal_fallbacks;

            $primary_font = !empty($morenews_google_fonts[$primary_key])
                ? "'" . esc_attr($morenews_google_fonts[$primary_key]) . "', " . $universal_fallbacks
                : $universal_fallbacks;

            $secondary_font = !empty($morenews_google_fonts[$secondary_key])
                ? "'" . esc_attr($morenews_google_fonts[$secondary_key]) . "', " . $universal_fallbacks
                : $universal_fallbacks;
        } else {

            $site_title_font = $system_stack;
            $primary_font    = $system_stack;
            $secondary_font  = $system_stack;
        }

        ob_start();
?>

<?php if (!empty($dark_background_color)) : ?>
            body.aft-dark-mode #sidr,
            body.aft-dark-mode,
            body.aft-dark-mode.custom-background,
            body.aft-dark-mode #af-preloader {
            background-color: <?php morenews_esc_custom_style($dark_background_color) ?>;
            }
        <?php endif; ?>

        <?php if (!empty($light_background_color)) : ?>
            body.aft-default-mode #sidr,
            body.aft-default-mode #af-preloader,
            body.aft-default-mode {
            background-color: <?php morenews_esc_custom_style($light_background_color) ?>;
            }

        <?php endif; ?>

        <?php if (!empty($secondary_color)) : ?>
            .frm_style_formidable-style.with_frm_style .frm_compact .frm_dropzone.dz-clickable .dz-message,
            .frm_style_formidable-style.with_frm_style input[type=submit],
            .frm_style_formidable-style.with_frm_style .frm_submit input[type=button],
            .frm_style_formidable-style.with_frm_style .frm_submit button,
            .frm_form_submit_style,
            .frm_style_formidable-style.with_frm_style .frm-edit-page-btn,

            .woocommerce #respond input#submit.disabled,
            .woocommerce #respond input#submit:disabled,
            .woocommerce #respond input#submit:disabled[disabled],
            .woocommerce a.button.disabled,
            .woocommerce a.button:disabled,
            .woocommerce a.button:disabled[disabled],
            .woocommerce button.button.disabled,
            .woocommerce button.button:disabled,
            .woocommerce button.button:disabled[disabled],
            .woocommerce input.button.disabled,
            .woocommerce input.button:disabled,
            .woocommerce input.button:disabled[disabled],
            .woocommerce #respond input#submit,
            .woocommerce a.button,
            .woocommerce button.button,
            .woocommerce input.button,
            .woocommerce #respond input#submit.alt,
            .woocommerce a.button.alt,
            .woocommerce button.button.alt,
            .woocommerce input.button.alt,
            .woocommerce-account .addresses .title .edit,
            :root .wc-block-featured-product__link :where(.wp-element-button, .wp-block-button__link),
            :root .wc-block-featured-category__link :where(.wp-element-button, .wp-block-button__link),
            hustle-button,
            button.wc-block-mini-cart__button,
            .wc-block-checkout .wp-block-button__link,
            .wp-block-button.wc-block-components-product-button .wp-block-button__link,
            .wc-block-grid__product-add-to-cart.wp-block-button .wp-block-button__link,
            body .wc-block-components-button,
            .wc-block-grid .wp-block-button__link,
            .woocommerce-notices-wrapper .button,
            body .woocommerce-notices-wrapper .button:hover,
            body.woocommerce .single_add_to_cart_button.button:hover,
            body.woocommerce a.button.add_to_cart_button:hover,

            .widget-title-fill-and-border .morenews-widget .wp-block-search__label,
            .widget-title-fill-and-border .morenews-widget .wp-block-group .wp-block-heading,
            .widget-title-fill-and-no-border .morenews-widget .wp-block-search__label,
            .widget-title-fill-and-no-border .morenews-widget .wp-block-group .wp-block-heading,

            .widget-title-fill-and-border .wp_post_author_widget .widget-title .header-after,
            .widget-title-fill-and-border .widget-title .heading-line,
            .widget-title-fill-and-border .aft-posts-tabs-panel .nav-tabs>li>a.active,
            .widget-title-fill-and-border .aft-main-banner-wrapper .widget-title .heading-line ,
            .widget-title-fill-and-no-border .wp_post_author_widget .widget-title .header-after,
            .widget-title-fill-and-no-border .widget-title .heading-line,
            .widget-title-fill-and-no-border .aft-posts-tabs-panel .nav-tabs>li>a.active,
            .widget-title-fill-and-no-border .aft-main-banner-wrapper .widget-title .heading-line,
            a.sidr-class-sidr-button-close,
            body.widget-title-border-bottom .header-after1 .heading-line-before,
            body.widget-title-border-bottom .widget-title .heading-line-before,

            .widget-title-border-center .morenews-widget .wp-block-search__label::after,
            .widget-title-border-center .morenews-widget .wp-block-group .wp-block-heading::after,
            .widget-title-border-center .wp_post_author_widget .widget-title .heading-line-before,
            .widget-title-border-center .aft-posts-tabs-panel .nav-tabs>li>a.active::after,
            .widget-title-border-center .wp_post_author_widget .widget-title .header-after::after,
            .widget-title-border-center .widget-title .heading-line-after,

            .widget-title-border-bottom .morenews-widget .wp-block-search__label::after,
            .widget-title-border-bottom .morenews-widget .wp-block-group .wp-block-heading::after,
            .widget-title-border-bottom .heading-line::before,
            .widget-title-border-bottom .wp-post-author-wrap .header-after::before,
            .widget-title-border-bottom .aft-posts-tabs-panel .nav-tabs>li>a.active span::after,

            .aft-dark-mode .is-style-fill a.wp-block-button__link:not(.has-background),
            .aft-default-mode .is-style-fill a.wp-block-button__link:not(.has-background),
            #reading-progress-bar,
            a.comment-reply-link,
            body.aft-default-mode .reply a,
            body.aft-dark-mode .reply a,
            .aft-popular-taxonomies-lists span::before ,
            #loader-wrapper div,
            span.heading-line::before,
            .wp-post-author-wrap .header-after::before,
            body.aft-dark-mode input[type="button"],
            body.aft-dark-mode input[type="reset"],
            body.aft-dark-mode input[type="submit"],
            body.aft-dark-mode .inner-suscribe input[type=submit],
            body.aft-default-mode input[type="button"],
            body.aft-default-mode input[type="reset"],
            body.aft-default-mode input[type="submit"],
            body.aft-default-mode .inner-suscribe input[type=submit],
            .woocommerce-product-search button[type="submit"],
            input.search-submit,
            .wp-block-search__button,
            .af-youtube-slider .af-video-wrap .af-bg-play i,
            .af-youtube-video-list .entry-header-yt-video-wrapper .af-yt-video-play i,
            .af-post-format i,
            body .btn-style1 a:visited,
            body .btn-style1 a,
            body span.post-page-numbers.current,
            body .morenews-pagination .nav-links .page-numbers.current,
            body #scroll-up,
            button,
            a.author-button.primary,
            .morenews-reaction-button .reaction-percentage,
            h3.morenews-subtitle:after,
            body article.sticky .read-single:before,
            .aft-readmore-wrapper a.aft-readmore:hover,
            body.aft-dark-mode .aft-readmore-wrapper a.aft-readmore:hover,
            footer.site-footer .aft-readmore-wrapper a.aft-readmore:hover,
            .aft-readmore-wrapper a.aft-readmore:hover,
            body .trending-posts-vertical .trending-no{
            background-color: <?php morenews_esc_custom_style($secondary_color) ?>;
            }

            div.wpforms-container-full button[type=submit]:hover,
            div.wpforms-container-full button[type=submit]:not(:hover):not(:active){
            background-color: <?php morenews_esc_custom_style($secondary_color) ?> !important;
            }

            .grid-design-texts-over-image .aft-readmore-wrapper a.aft-readmore:hover,
            .aft-readmore-wrapper a.aft-readmore:hover,
            body.aft-dark-mode .aft-readmore-wrapper a.aft-readmore:hover,
            body.aft-default-mode .aft-readmore-wrapper a.aft-readmore:hover,

            a.author-button.primary,
            .morenews-post-badge,
            body.single .entry-header .aft-post-excerpt-and-meta .post-excerpt,
            body.aft-dark-mode.single span.tags-links a:hover,
            .morenews-pagination .nav-links .page-numbers.current,
            .aft-readmore-wrapper a.aft-readmore:hover,
            p.awpa-more-posts a:hover,
            .wp-post-author-meta .wp-post-author-meta-more-posts a.awpa-more-posts:hover{
            border-color: <?php morenews_esc_custom_style($secondary_color) ?>;
            }
            body:not(.rtl) .aft-popular-taxonomies-lists span::after {
            border-left-color: <?php morenews_esc_custom_style($secondary_color) ?>;
            }
            body.rtl .aft-popular-taxonomies-lists span::after {
            border-right-color: <?php morenews_esc_custom_style($secondary_color) ?>;
            }
            .widget-title-fill-and-no-border .morenews-widget .wp-block-search__label::after,
            .widget-title-fill-and-no-border .morenews-widget .wp-block-group .wp-block-heading::after,
            .widget-title-fill-and-no-border .aft-posts-tabs-panel .nav-tabs>li a.active::after,
            .widget-title-fill-and-no-border .morenews-widget .widget-title::before,
            .widget-title-fill-and-no-border .morenews-customizer .widget-title::before{
            border-top-color: <?php morenews_esc_custom_style($secondary_color) ?>;

            }
            .woocommerce div.product .woocommerce-tabs ul.tabs li.active,
            #scroll-up::after,
            .aft-dark-mode #loader,
            .aft-default-mode #loader {
            border-bottom-color: <?php morenews_esc_custom_style($secondary_color) ?>;
            }
            footer.site-footer .wp-calendar-nav a:hover,
            footer.site-footer .wp-block-latest-comments__comment-meta a:hover,
            .aft-dark-mode .tagcloud a:hover,
            .aft-dark-mode .widget ul.menu >li a:hover,
            .aft-dark-mode .widget > ul > li a:hover,
            .banner-exclusive-posts-wrapper a:hover,
            .list-style .read-title h3 a:hover,
            .grid-design-default .read-title h3 a:hover,
            body.aft-dark-mode .banner-exclusive-posts-wrapper a:hover,
            body.aft-dark-mode .banner-exclusive-posts-wrapper a:visited:hover,
            body.aft-default-mode .banner-exclusive-posts-wrapper a:hover,
            body.aft-default-mode .banner-exclusive-posts-wrapper a:visited:hover,
            body.wp-post-author-meta .awpa-display-name a:hover,
            .widget_text a ,
            .post-description a:not(.aft-readmore), .post-description a:not(.aft-readmore):visited,

            .wp_post_author_widget .wp-post-author-meta .awpa-display-name a:hover,
            .wp-post-author-meta .wp-post-author-meta-more-posts a.awpa-more-posts:hover,
            body.aft-default-mode .af-breadcrumbs a:hover,
            body.aft-dark-mode .af-breadcrumbs a:hover,
            body .wp-block-latest-comments li.wp-block-latest-comments__comment a:hover,

            body .site-footer .color-pad .read-title h3 a:hover,

            body.aft-dark-mode #secondary .morenews-widget ul[class*="wp-block-"] a:hover,
            body.aft-dark-mode #secondary .morenews-widget ol[class*="wp-block-"] a:hover,
            body.aft-dark-mode a.post-edit-link:hover,
            body.aft-default-mode #secondary .morenews-widget ul[class*="wp-block-"] a:hover,
            body.aft-default-mode #secondary .morenews-widget ol[class*="wp-block-"] a:hover,
            body.aft-default-mode a.post-edit-link:hover,
            body.aft-default-mode #secondary .widget > ul > li a:hover,

            body.aft-default-mode footer.comment-meta a:hover,
            body.aft-dark-mode footer.comment-meta a:hover,
            body.aft-default-mode .comment-form a:hover,
            body.aft-dark-mode .comment-form a:hover,
            body.aft-dark-mode .entry-content > .wp-block-tag-cloud a:not(.has-text-color):hover,
            body.aft-default-mode .entry-content > .wp-block-tag-cloud a:not(.has-text-color):hover,
            body.aft-dark-mode .entry-content .wp-block-archives-list.wp-block-archives a:not(.has-text-color):hover,
            body.aft-default-mode .entry-content .wp-block-archives-list.wp-block-archives a:not(.has-text-color):hover,
            body.aft-dark-mode .entry-content .wp-block-latest-posts a:not(.has-text-color):hover,
            body.aft-dark-mode .entry-content .wp-block-categories-list.wp-block-categories a:not(.has-text-color):hover,
            body.aft-default-mode .entry-content .wp-block-latest-posts a:not(.has-text-color):hover,
            body.aft-default-mode .entry-content .wp-block-categories-list.wp-block-categories a:not(.has-text-color):hover,

            .aft-default-mode .wp-block-archives-list.wp-block-archives a:not(.has-text-color):hover,
            .aft-default-mode .wp-block-latest-posts a:not(.has-text-color):hover,
            .aft-default-mode .wp-block-categories-list.wp-block-categories a:not(.has-text-color):hover,
            .aft-default-mode .wp-block-latest-comments li.wp-block-latest-comments__comment a:hover,
            .aft-dark-mode .wp-block-archives-list.wp-block-archives a:not(.has-text-color):hover,
            .aft-dark-mode .wp-block-latest-posts a:not(.has-text-color):hover,
            .aft-dark-mode .wp-block-categories-list.wp-block-categories a:not(.has-text-color):hover,
            .aft-dark-mode .wp-block-latest-comments li.wp-block-latest-comments__comment a:hover,

            body.aft-dark-mode .morenews-pagination .nav-links a.page-numbers:hover,
            body.aft-default-mode .morenews-pagination .nav-links a.page-numbers:hover,
            body.aft-default-mode .aft-popular-taxonomies-lists ul li a:hover ,
            body.aft-dark-mode .aft-popular-taxonomies-lists ul li a:hover,
            body.aft-dark-mode .wp-calendar-nav a,
            body .entry-content > .wp-block-heading:not(.has-link-color):not(.wp-block-post-title) a,
            body .entry-content > ul a,
            body .entry-content > ol a,
            body .entry-content > p:not(.has-link-color) a ,
            body.aft-default-mode p.logged-in-as a,
            body.aft-dark-mode p.logged-in-as a,
            body.aft-dark-mode .woocommerce-loop-product__title:hover,
            body.aft-default-mode .woocommerce-loop-product__title:hover,
            a:hover,
            p a,
            .stars a:active,
            .stars a:focus,
            .morenews-widget.widget_text a,
            a.author-website:hover,
            .author-box-content a.author-posts-link:hover,
            body .morenews-sponsor a,
            body .morenews-source a,
            body.aft-default-mode .wp-block-latest-comments li.wp-block-latest-comments__comment a:hover,
            body.aft-dark-mode .wp-block-latest-comments li.wp-block-latest-comments__comment a:hover,
            .entry-content .wp-block-latest-comments a:not(.has-text-color):hover,
            .wc-block-grid__product .wc-block-grid__product-link:focus,

            body.aft-default-mode .entry-content .wp-block-heading:not(.has-link-color):not(.wp-block-post-title) a,
            body.aft-dark-mode .entry-content .wp-block-heading:not(.has-link-color):not(.wp-block-post-title) a,

            body.aft-default-mode .comment-content a,
            body.aft-dark-mode .comment-content a,
            body.aft-default-mode .post-excerpt a,
            body.aft-dark-mode .post-excerpt a,
            body.aft-default-mode .wp-block-tag-cloud a:hover,
            body.aft-default-mode .tagcloud a:hover,
            body.aft-default-mode.single span.tags-links a:hover,
            body.aft-default-mode p.awpa-more-posts a:hover,
            body.aft-default-mode p.awpa-website a:hover ,
            body.aft-default-mode .wp-post-author-meta h4 a:hover,
            body.aft-default-mode .widget ul.menu >li a:hover,
            body.aft-default-mode .widget > ul > li a:hover,
            body.aft-default-mode .nav-links a:hover,
            body.aft-default-mode ul.trail-items li a:hover,
            body.aft-dark-mode .wp-block-tag-cloud a:hover,
            body.aft-dark-mode .tagcloud a:hover,
            body.aft-dark-mode.single span.tags-links a:hover,
            body.aft-dark-mode p.awpa-more-posts a:hover,
            body.aft-dark-mode p.awpa-website a:hover ,
            body.aft-dark-mode .widget ul.menu >li a:hover,
            body.aft-dark-mode .nav-links a:hover,
            body.aft-dark-mode ul.trail-items li a:hover{
            color:<?php morenews_esc_custom_style($secondary_color) ?>;
            }

            @media only screen and (min-width: 992px){
            body.aft-default-mode .morenews-header .main-navigation .menu-desktop > ul > li:hover > a:before,
            body.aft-default-mode .morenews-header .main-navigation .menu-desktop > ul > li.current-menu-item > a:before {
            background-color: <?php morenews_esc_custom_style($secondary_color) ?>;
            }
            }
        <?php endif; ?>

        <?php if (!empty($secondary_color)) : ?>
            .woocommerce-product-search button[type="submit"], input.search-submit{
            background-color: <?php morenews_esc_custom_style($secondary_color) ?>;
            }
            .aft-dark-mode .entry-content a:hover, .aft-dark-mode .entry-content a:focus, .aft-dark-mode .entry-content a:active,
            .wp-calendar-nav a,
            #wp-calendar tbody td a,
            body.aft-dark-mode #wp-calendar tbody td#today,
            body.aft-default-mode #wp-calendar tbody td#today,
            body.aft-default-mode .entry-content .wp-block-heading:not(.has-link-color):not(.wp-block-post-title) a,
            body.aft-dark-mode .entry-content .wp-block-heading:not(.has-link-color):not(.wp-block-post-title) a,
            body .entry-content > ul a, body .entry-content > ul a:visited,
            body .entry-content > ol a, body .entry-content > ol a:visited,
            body .entry-content > p:not(.has-link-color) a, body .entry-content > p:not(.has-link-color) a:visited
            {
            color: <?php morenews_esc_custom_style($secondary_color) ?>;
            }
            .woocommerce-product-search button[type="submit"], input.search-submit,
            body.single span.tags-links a:hover,
            body .entry-content .wp-block-heading:not(.has-link-color):not(.wp-block-post-title) a,
            body .entry-content > ul a, body .entry-content > ul a:visited,
            body .entry-content > ol a, body .entry-content > ol a:visited,
            body .entry-content > p:not(.has-link-color) a, body .entry-content > p:not(.has-link-color) a:visited{
            border-color: <?php morenews_esc_custom_style($secondary_color) ?>;
            }

            @media only screen and (min-width: 993px){
            .main-navigation .menu-desktop > li.current-menu-item::after,
            .main-navigation .menu-desktop > ul > li.current-menu-item::after,
            .main-navigation .menu-desktop > li::after, .main-navigation .menu-desktop > ul > li::after{
            background-color: <?php morenews_esc_custom_style($secondary_color) ?>;
            }
            }
        <?php endif; ?>


        <?php if (!empty($site_title_font)) : ?>
            .site-branding .site-title {
            font-family: <?php morenews_esc_custom_style($site_title_font) ?>;
            }
        <?php endif; ?>

        <?php if (!empty($primary_font)) : ?>
            body,
            button,
            input,
            select,
            optgroup,
            .cat-links li a,
            .min-read,
            .af-social-contacts .social-widget-menu .screen-reader-text,
            textarea {
            font-family: <?php morenews_esc_custom_style($primary_font) ?>;
            }
        <?php endif; ?>

        <?php if (!empty($secondary_font)) : ?>
            .wp-block-tag-cloud a, .tagcloud a,
            body span.hustle-title,
            .wp-block-blockspare-blockspare-tabs .bs-tabs-title-list li a.bs-tab-title,
            .navigation.post-navigation .nav-links a,
            div.custom-menu-link > a,
            .exclusive-posts .exclusive-now span,
            .aft-popular-taxonomies-lists span,
            .exclusive-posts a,
            .aft-posts-tabs-panel .nav-tabs>li>a,
            .widget-title-border-bottom .aft-posts-tabs-panel .nav-tabs>li>a,
            .nav-tabs>li,
            .widget ul ul li,
            .widget ul.menu >li ,
            .widget > ul > li,
            .wp-block-search__label,
            .wp-block-latest-posts.wp-block-latest-posts__list li,
            .wp-block-latest-comments li.wp-block-latest-comments__comment,
            .wp-block-group ul li a,
            .main-navigation ul li a,
            h1, h2, h3, h4, h5, h6 {
            font-family: <?php morenews_esc_custom_style($secondary_font) ?>;
            }
        <?php endif; ?>

        .container-wrapper .elementor {
        max-width: 100%;
        }
        .full-width-content .elementor-section-stretched,
        .align-content-left .elementor-section-stretched,
        .align-content-right .elementor-section-stretched {
        max-width: 100%;
        left: 0 !important;
        }
<?php
        $css = ob_get_clean();

        // Minify CSS: remove comments, newlines, extra spaces
        $css = preg_replace('!/\*.*?\*/!s', '', $css);        // Remove comments
        $css = preg_replace('/\s+/', ' ', $css);             // Collapse whitespace
        $css = str_replace([' {', '{ ', '; ', ': ', ', '], ['{', '{', ';', ':', ','], $css);
        $css = trim($css);

        return $css;
        
        // return ob_get_clean();
    }
}

if (!function_exists('morenews_esc_custom_style(')) {

    function morenews_esc_custom_style($props)
    {
        echo wp_kses($props, array("\'", '\"'));
    }
}
