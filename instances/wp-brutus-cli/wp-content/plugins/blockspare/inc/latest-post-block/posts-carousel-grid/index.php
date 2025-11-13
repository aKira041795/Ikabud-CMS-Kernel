<?php

if (!function_exists('blockspare_carousel_render_block_core_latest_posts_list')) {
    function blockspare_carousel_render_block_core_latest_posts_list($attributes)
    {

        ob_start();
        $unq_class = mt_rand(100000, 999999);
        $blockuniqueclass = '';

        if (!empty($attributes['uniqueClass'])) {
            $blockuniqueclass = $attributes['uniqueClass'];
        } else {
            $blockuniqueclass = 'blockspare-posts-block-list-' . $unq_class;
        }

        if (isset($attributes['categories']) && !empty($attributes['categories']) && is_array($attributes['categories'])) {
            $categories = array();
            $i = 1;
            foreach ($attributes['categories'] as $key => $value) {
                $categories[] = $value['value'];
            }
        } else {
            $categories = array();
        }

        if (isset($attributes['tags']) && !empty($attributes['tags']) && is_array($attributes['tags'])) {
            $tags = array();
            $i = 1;
            foreach ($attributes['tags'] as $key => $value) {
                $tags[] = $value['value'];
            }
        } else {
            $tags = array();
        }

        /* Setup the query */

        $query_args = array(
            'posts_per_page' => $attributes['postsToShow'],
            'post_status' => 'publish',
            'order' => $attributes['order'],
            'orderby' => $attributes['orderBy'],
            'offset' => $attributes['offset'],
            'post_type' => $attributes['postType'],
            'ignore_sticky_posts' => 1,
        );

        if ($attributes['taxType'] == 'category') {
            $query_args['category__in'] = $categories;

        }
        if ($attributes['taxType'] == 'post_tag') {
            $query_args['tag__in'] = $tags;

        }

        if ($attributes['taxType'] != 'category' && $attributes['taxType'] != 'post_tag') {

            $tax_type = $attributes['taxType'];
            if ($tax_type) {
                $query_args['tax_query'][] = array(
                    'taxonomy' => (isset($tax_type)) ? $tax_type : 'category',
                    'field' => 'id',
                    'terms' => $categories,
                    'operator' => 'IN',
                );
            }
        }

        $grid_query = new WP_Query($query_args);

        $next = $attributes['carouselNextIcon'];
        $prevstr = str_replace("-right", "-left", $attributes['carouselNextIcon']);

        $centermode = false;

        $responsivelayoutTab = 1;
        $responsivelayoutMobile = 1;

        if ($attributes['numberofSlide'] > 2) {
            $responsivelayoutTab = 2;
            $responsivelayoutMobile = 1;
        } else {
            $responsivelayoutTab = $attributes['numberofSlide'];
        }

        $carousel_args = array(

            'dots' => $attributes['showDots'],
            'loop' => true,
            'autoplay' => $attributes['enableAutoPlay'],
            'speed' => $attributes['carouselSpeed'],
            'arrows' => $attributes['showsliderNextPrev'],
            'slidesToShow' => ($attributes['postListingOption'] === 'latest-posts-block-carousel-grid') ? $attributes['numberofSlide'] : 1,
            'centerMode' => $centermode,
            'responsive' => array(
                array(
                    'breakpoint' => 769,
                    'settings' => array(
                        'slidesToShow' => $responsivelayoutTab,
                        'slidesToScroll' => 1,
                        'infinite' => true,
                        'centerMode' => false,
                    ),
                ),
                // array(
                //     'breakpoint' => 600,
                //     'settings' => array(
                //         'slidesToShow' => $responsivelayoutTab,
                //         'slidesToScroll' => 1,
                //         'centerMode'=>false,
                //     ),
                // ),
                array(
                    'breakpoint' => 481,
                    'settings' => array(
                        'slidesToShow' => $responsivelayoutMobile,
                        'slidesToScroll' => 1,
                        'centerMode' => false,
                    ),
                ),
            ),
        );
        $carousel_args_encoded = wp_json_encode($carousel_args);

        /* Start the loop */
        if ($grid_query->have_posts()) {
            $alignclass = blockspare_checkalignment($attributes['align']);
            /* Build the block classes */
            $class = "wp-block-blockspare-posts-block-blockspare-posts-block-latest-posts align" . $alignclass . ' ' . $attributes['blockHoverEffect'] . ' ' . $attributes['imageHoverEffect'] . ' ' . $blockuniqueclass;

            if (isset($attributes['className'])) {
                $class .= ' ' . $attributes['className'];
            }
            if ($attributes['enableBoxShadow']) {
                $class .= ' bs-has-shadow';
            }

            $list_layout_class = $attributes['grid'];

            $hoverNavClass = '';
            if ($attributes['enableNavInHover']) {
                $hoverNavClass = 'nav-on-hover';
            }
            //$list_layout_class = $attributes['design'];
            $listgridClass = 'blockspare-posts-block-is-carousel' . " " . $attributes['navigationSize'] . ' ' . $attributes['navigationShape'] . ' ' . $hoverNavClass . ' blockspare-slides-' . $attributes['numberofSlide'];

            $animation_class = '';
            if ($attributes['animation']) {
                $animation_class = 'blockspare-block-animation';
            }

            /* Layout orientation class */
            $grid_class = 'blockspare-posts-block-latest-post-carousel-wrap  blockspare-posts-block-latest-post-wrap' . ' ' . $listgridClass . ' ' . $list_layout_class . ' has-gutter-space-' . $attributes['gutterSpace'] . " " . $attributes['postListingOption'] . ' ' . $animation_class;

            $category_class = 'blockspare-posts-block-post-category';

            if ($attributes['categoryLayoutOption'] == 'none') {
                $category_class .= ' has-no-category-style';
            }

            ?>

<div class="<?php echo esc_attr($class); ?>">
<?php echo latest_posts_style_control_carousel($blockuniqueclass, $attributes); ?>
    <section class="blockspare-posts-block-post-wrap">
        <div class="<?php echo esc_attr($grid_class); ?>"  blockspare-animation="<?php echo esc_attr($attributes['animation']) ?>">
            <div class="latest-post-carousel" data-slick="<?php echo esc_attr($carousel_args_encoded); ?>"
                data-next="<?php echo esc_attr($next); ?>" data-prev="<?php echo esc_attr($prevstr); ?>">
                <?php while ($grid_query->have_posts()) {
                $grid_query->the_post();

                /* Setup the post ID */
                $post_id = get_the_ID();

                /* Setup the featured image ID */
                $post_thumb_id = get_post_thumbnail_id($post_id);

                $has_img_class = '';

                if (!$post_thumb_id) {
                    $has_img_class = "post-has-no-image";
                }

                $contentOrderClass = '';
                if ($attributes['contentOrder'] == 'content-order-1') {
                    $contentOrderClass .= 'contentorderone';
                }
                if ($attributes['contentOrder'] == 'content-order-2') {
                    $contentOrderClass .= 'contentordertwo';
                }

                /* Setup the post classes */
                $post_classes = 'blockspare-posts-block-post-single blockspare-hover-item ' . $contentOrderClass;
                if ($attributes['enableEqualHeight']) {
                    $post_classes .= ' bs-has-equal-height';
                }

                $layoutclass = false;

                if ($attributes['grid'] != 'blockspare-posts-block-grid-layout-3' && $attributes['grid'] != 'blockspare-posts-block-grid-layout-4') {
                    $layoutclass = true;
                }

                if ($layoutclass) {
                    $post_classes .= ' has-background';
                } else {
                    $post_classes .= ' blockspare-hover-child';
                }

                /* Add sticky class */
                if (is_sticky($post_id)) {
                    $post_classes .= ' sticky';
                } else {
                    $post_classes .= null;
                }
                ?>
                            <div>
                                <div id="<?php echo esc_attr($post_id); ?>" class="<?php echo esc_attr($post_classes) . ' ' . $has_img_class; ?>">
                                        <?php blockspare_post_image($attributes, $post_id, $category_class);?>
                                        <?php blockspare_post_content($attributes, $post_id, $category_class);?>
                                </div>
                            </div>


                <?php
}?>
            </div>
        </div>
    </section>
</div>
<?php wp_reset_postdata();

            return ob_get_clean();
        }
    }
}

/**
 * Registers the post grid block on server
 */
if (!function_exists('blockspare_carousel_register_block_core_latest_posts_list')) {
    function blockspare_carousel_register_block_core_latest_posts_list()
    {

        if (!function_exists('register_block_type')) {
            return;
        }

        ob_start();
        include BLOCKSPARE_PLUGIN_DIR . 'inc/latest-post-block/posts-carousel-grid/block.json';

        $metadata = json_decode(ob_get_clean(), true);

        /* Block attributes */
        register_block_type(
            'blockspare/latest-posts-block-carousel-grid',
            array(
                'attributes' => $metadata['attributes'],
                'render_callback' => 'blockspare_carousel_render_block_core_latest_posts_list',
            )
        );
    }

    add_action('init', 'blockspare_carousel_register_block_core_latest_posts_list');
}

if (!function_exists('latest_posts_style_control_carousel')) {
    function latest_posts_style_control_carousel($blockuniqueclass, $attributes)
    {

        $block_content = '';
        $block_content .= '<style type="text/css">';
        $block_content .= ' .' . $blockuniqueclass . ' .blockspare-posts-block-post-wrap{
            margin-top:' . $attributes['marginTop'] . 'px;
            margin-bottom:' . $attributes['marginBottom'] . 'px;
            margin-left:' . $attributes['marginLeft'] . 'px;
            margin-right:' . $attributes['marginRight'] . 'px;
        }';

        $block_content .= ' .' . $blockuniqueclass . ' .blockspare-posts-block-post-content{
            padding-top:' . $attributes['contentPaddingTop'] . 'px;
            padding-right:' . $attributes['contentPaddingRight'] . 'px;
            padding-bottom:' . $attributes['contentPaddingBottom'] . 'px;
            padding-left:' . $attributes['contentPaddingLeft'] . 'px;
        }';

        blockspare_posts_category_style(
            $block_content,
            $attributes,
            $blockuniqueclass,
            $attributes['categoryTextColor'],
            $attributes['categoryBackgroundColor'],
            $attributes['categoryBorderColor'],
            $attributes['secondCategoryTextColor'],
            $attributes['secondCategoryBackgroundColor'],
            $attributes['secondCategoryBorderColor'],
            $attributes['thirdCategoryTextColor'],
            $attributes['thirdCategoryBackgroundColor'],
            $attributes['thirdCategoryBorderColor'],
        );

        $block_content .= ' .' . $blockuniqueclass . ' .blockspare-posts-block-post-grid-byline{
            margin-top:' . $attributes['metaMarginTop'] . 'px' . ';
            margin-bottom:' . $attributes['metaMarginBottom'] . 'px' . ';
            margin-left:' . $attributes['metaMarginLeft'] . 'px' . ';
            margin-right:' . $attributes['metaMarginRight'] . 'px' . ';
        }';

        $block_content .= ' .' . $blockuniqueclass . ' .blockspare-posts-block-post-grid-more-link{
            margin-top:' . $attributes['moreLinkMarginTop'] . 'px' . ';
            margin-bottom:' . $attributes['moreLinkMarginBottom'] . 'px' . ';
            margin-left:' . $attributes['moreLinkMarginLeft'] . 'px' . ';
            margin-right:' . $attributes['moreLinkMarginRight'] . 'px' . ';
        }';

        $block_content .= ' .' . $blockuniqueclass . ' .blockspare-posts-block-is-carousel span:before{
            color:' . $attributes['navigationColor'] . ';
        }';
        $block_content .= ' .' . $blockuniqueclass . ' .blockspare-posts-block-latest-post-carousel-wrap ul li button{
            color:' . $attributes['navigationColor'] . ';
        }';

        $block_content .= ' .' . $blockuniqueclass . ' .blockspare-posts-block-is-carousel .slick-slider .slick-dots > li button{
            background-color:' . $attributes['navigationColor'] . ';
        }';

        if ($attributes['navigationShape'] == 'lpc-navigation-1' || $attributes['navigationShape'] == 'lpc-navigation-2') {
            $block_content .= ' .' . $blockuniqueclass . ' .blockspare-posts-block-is-carousel .slick-slider .slick-arrow:after{
                background-color:' . $attributes['navigationShapeColor'] . '
            }';
        } elseif ($attributes['navigationShape'] == 'lpc-navigation-3' || $attributes['navigationShape'] == 'lpc-navigation-4') {
            $block_content .= ' .' . $blockuniqueclass . ' .blockspare-posts-block-is-carousel .slick-slider .slick-arrow{
                border-color:' . $attributes['navigationShapeColor'] . '
            }';
        } else {
            $block_content .= ' .' . $blockuniqueclass . ' .blockspare-posts-block-is-carousel .slick-slider .slick-arrow{
                border-color:transparent ;
                background-color:transparent
            }';
        }

        //Title Hover

        if ($attributes['titleOnHover'] == 'lpc-title-hover') {
            $block_content .= ' .' . $blockuniqueclass . ' .blockspare-posts-block-post-content .blockspare-posts-block-title-link:hover span{
                color: ' . $attributes['titleOnHoverColor'] . ';
                 }';
        }

        if ($attributes['titleOnHover'] == 'lpc-title-border') {
            $block_content .= ' .' . $blockuniqueclass . ' .lpc-title-border .blockspare-posts-block-post-grid-title .blockspare-posts-block-title-link span:hover{
                box-shadow: inset 0 -2px 0 0 ' . $attributes['titleOnHoverColor'] . ';
            }';
        }

        //Content Bakcground

        $block_content .= ' .' . $blockuniqueclass . ' .blockspare-posts-block-post-grid-title a span{
            color: ' . $attributes['postTitleColor'] . ';
        }';

        $block_content .= ' .' . $blockuniqueclass . ' .blockspare-posts-block-post-grid-author a span{
            color:' . $attributes['linkColor'] . ';
        }';

        $block_content .= ' .' . $blockuniqueclass . ' .blockspare-posts-block-post-grid-more-link span{
            color:' . $attributes['linkColor'] . ';
        }';

        $block_content .= ' .' . $blockuniqueclass . ' .blockspare-posts-block-post-grid-date{
            color:' . $attributes['generalColor'] . ';
        }';

        $block_content .= ' .' . $blockuniqueclass . ' .blockspare-posts-block-post-grid-excerpt-content{
            color:' . $attributes['generalColor'] . ';
        }';
        $block_content .= ' .' . $blockuniqueclass . ' .comment_count{
            color:' . $attributes['generalColor'] . ';
        }';

        //end Content Background

        $block_content .= ' .' . $blockuniqueclass . ' .blockspare-posts-block-post-grid-title{
            margin-top:' . $attributes['titleMarginTop'] . 'px' . ';
            margin-bottom:' . $attributes['titleMarginBottom'] . 'px' . ';
            margin-left:' . $attributes['titleMarginLeft'] . 'px' . ';
            margin-right:' . $attributes['titleMarginRight'] . 'px' . ';
        }';

        $block_content .= ' .' . $blockuniqueclass . ' .blockspare-posts-block-post-grid-excerpt-content{
            margin-top:' . $attributes['exceprtMarginTop'] . 'px' . ';
            margin-bottom:' . $attributes['exceprtMarginBottom'] . 'px' . ';
            margin-left:' . $attributes['exceprtMarginLeft'] . 'px' . ';
            margin-right:' . $attributes['exceprtMarginRight'] . 'px' . ';
        }';

        $boxshadow = false;

        if (!empty($attributes['grid']) && isset($attributes['grid'])) {
            if ($attributes['grid'] == 'blockspare-posts-block-grid-layout-1' || $attributes['grid'] == 'blockspare-posts-block-grid-layout-2' || $attributes['grid'] == 'blockspare-posts-block-grid-layout-5' || $attributes['grid'] == 'blockspare-posts-block-grid-layout-6' || $attributes['grid'] == 'blockspare-posts-block-grid-layout-9' || $attributes['grid'] == 'blockspare-posts-block-grid-layout-10' || $attributes['grid'] == 'blockspare-posts-block-grid-layout-13' || $attributes['grid'] == 'blockspare-posts-block-grid-layout-14') {
                $boxshadow = true;
            }
        }

        if ($boxshadow) {
            if ($attributes['enableBoxShadow']) {
                $block_content .= ' .' . $blockuniqueclass . ' .blockspare-posts-block-post-single{
                    box-shadow: ' . $attributes['xOffset'] . 'px ' . $attributes['yOffset'] . 'px ' . $attributes['blur'] . 'px ' . $attributes['spread'] . 'px ' . $attributes['shadowColor'] . ';
                    border-radius:' . $attributes['borderRadius'] . 'px' . ';
                }';
            } else {
                $block_content .= ' .' . $blockuniqueclass . ' .blockspare-posts-block-post-single{
                    border-radius:' . $attributes['borderRadius'] . 'px' . ';
                }';
            }
        } else {
            if ($attributes['enableBoxShadow']) {
                $block_content .= ' .' . $blockuniqueclass . ' .blockspare-posts-block-post-wrap .blockspare-posts-block-post-single .blockspare-posts-block-post-img {
                    box-shadow: ' . $attributes['xOffset'] . 'px ' . $attributes['yOffset'] . 'px ' . $attributes['blur'] . 'px ' . $attributes['spread'] . 'px ' . $attributes['shadowColor'] . ';
                    border-radius:' . $attributes['borderRadius'] . 'px' . ';
                }';
            } else {
                $block_content .= ' .' . $blockuniqueclass . ' .blockspare-posts-block-post-wrap .blockspare-posts-block-post-single .blockspare-posts-block-post-img {
                    border-radius:' . $attributes['borderRadius'] . 'px' . ';
                }';
            }
        }

        if ($attributes['grid'] != 'blockspare-posts-block-grid-layout-3' && $attributes['grid'] != 'blockspare-posts-block-grid-layout-4' && $attributes['grid'] != 'blockspare-posts-block-grid-layout-7' && $attributes['grid'] != 'blockspare-posts-block-grid-layout-8' && $attributes['grid'] != 'blockspare-posts-block-grid-layout-11' && $attributes['grid'] != 'blockspare-posts-block-grid-layout-12') {
            $block_content .= ' .' . $blockuniqueclass . ' .blockspare-posts-block-post-single{
                background-color:' . $attributes['backGroundColor'] . ';
            }';
        }

        //Font Settings

        $block_content .= ' .' . $blockuniqueclass . ' .blockspare-posts-block-post-grid-title a span{
            font-size: ' . $attributes['postTitleFontSize'] . $attributes['titleFontSizeType'] . ';
            ' . bscheckFontfamily($attributes['titleFontFamily']) . ';
            ' . bscheckFontfamilyWeight($attributes['titleFontWeight']) . ';
            line-height:' . $attributes['lineHeight'] . ';
        }';

        $block_content .= ' .' . $blockuniqueclass . ' .blockspare-posts-block-post-content .blockspare-posts-block-post-grid-excerpt .blockspare-posts-block-post-grid-excerpt-content{
            font-size:' . $attributes['descriptionFontSize'] . $attributes['descriptionFontSizeType'] . ';
            ' . bscheckFontfamily($attributes['descriptionFontFamily']) . ';
            ' . bscheckFontfamilyWeight($attributes['descriptionFontWeight']) . ';
        }';

        $block_content .= ' .' . $blockuniqueclass . ' .blockspare-posts-block-post-grid-author a span {
            font-size:' . $attributes['postMetaFontSize'] . $attributes['postMetaFontSizeType'] . ';
            ' . bscheckFontfamily($attributes['postMetaFontFamily']) . ';
            ' . bscheckFontfamilyWeight($attributes['postMetaFontWeight']) . ';
        }';

        $block_content .= ' .' . $blockuniqueclass . ' .blockspare-posts-block-post-grid-date {
            font-size:' . $attributes['postMetaFontSize'] . $attributes['postMetaFontSizeType'] . ';
            ' . bscheckFontfamily($attributes['postMetaFontFamily']) . ';
            ' . bscheckFontfamilyWeight($attributes['postMetaFontWeight']) . ';
        }';

        $block_content .= ' .' . $blockuniqueclass . ' .comment_count {
            font-size:' . $attributes['postMetaFontSize'] . $attributes['postMetaFontSizeType'] . ';
            ' . bscheckFontfamily($attributes['postMetaFontFamily']) . ';
            ' . bscheckFontfamilyWeight($attributes['postMetaFontWeight']) . ';
        }';

        $block_content .= '@media (max-width: 1025px) { ';
        $block_content .= ' .' . $blockuniqueclass . ' .blockspare-posts-block-post-grid-title a span{
            font-size: ' . $attributes['titleFontSizeTablet'] . $attributes['titleFontSizeType'] . ';
        }';
        $block_content .= ' .' . $blockuniqueclass . ' .blockspare-posts-block-post-content .blockspare-posts-block-post-grid-excerpt .blockspare-posts-block-post-grid-excerpt-content{
            font-size:' . $attributes['descriptionFontSizeTablet'] . $attributes['descriptionFontSizeType'] . '
        }';
        $block_content .= ' .' . $blockuniqueclass . ' .blockspare-posts-block-post-grid-author a span {
            font-size:' . $attributes['postMetaFontSizeTablet'] . $attributes['postMetaFontSizeType'] . '
        }';
        $block_content .= ' .' . $blockuniqueclass . ' .blockspare-posts-block-post-grid-date {
            font-size:' . $attributes['postMetaFontSizeTablet'] . $attributes['postMetaFontSizeType'] . '
        }';
        $block_content .= ' .' . $blockuniqueclass . ' .comment_count {
            font-size:' . $attributes['postMetaFontSizeTablet'] . $attributes['postMetaFontSizeType'] . '
        }';
        $block_content .= '}';

        $block_content .= '@media (max-width: 767px) { ';
        $block_content .= ' .' . $blockuniqueclass . ' .blockspare-posts-block-post-grid-title a span{
            font-size: ' . $attributes['titleFontSizeMobile'] . $attributes['titleFontSizeType'] . ';
        }';
        $block_content .= ' .' . $blockuniqueclass . ' .blockspare-posts-block-post-content .blockspare-posts-block-post-grid-excerpt .blockspare-posts-block-post-grid-excerpt-content{
            font-size:' . $attributes['descriptionFontSizeMobile'] . $attributes['descriptionFontSizeType'] . '
        }';
        $block_content .= ' .' . $blockuniqueclass . ' .blockspare-posts-block-post-grid-author a span {
            font-size:' . $attributes['postMetaFontSizeMobile'] . $attributes['postMetaFontSizeType'] . '
        }';
        $block_content .= ' .' . $blockuniqueclass . ' .blockspare-posts-block-post-grid-date {
            font-size:' . $attributes['postMetaFontSizeMobile'] . $attributes['postMetaFontSizeType'] . '
        }';
        $block_content .= ' .' . $blockuniqueclass . ' .comment_count {
            font-size:' . $attributes['postMetaFontSizeMobile'] . $attributes['postMetaFontSizeType'] . '
        }';
        $block_content .= '}';

        $block_content .= '</style>';
        return $block_content;
    }
}
