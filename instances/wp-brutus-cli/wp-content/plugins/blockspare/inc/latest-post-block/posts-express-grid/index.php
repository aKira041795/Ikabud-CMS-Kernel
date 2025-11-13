<?php

if (!function_exists('blockspare_render_block_core_latest_posts_express_grid')) {
    function blockspare_render_block_core_latest_posts_express_grid($attributes)
    {

        if ($attributes['enablePagination']) {
            wp_enqueue_script('blockspare-pagination-js');
        }
        ob_start();
        $unq_class = mt_rand(100000, 999999);
        $blockuniqueclass = '';

        if (!empty($attributes['uniqueClass'])) {
            $blockuniqueclass = $attributes['uniqueClass'];
        } else {
            $blockuniqueclass = 'blockspare-posts-block-list-' . $unq_class;
        }

        $design = 'blockspare-posts-block-latestpost-express-grid ';

        $block_class = 'blockspare-posts-block-is-express' . ' has-gutter-' . $attributes['gutterSpace'];
        if ($attributes['enableEqualHeight']) {
            $block_class .= ' bs-has-equal-height';
        }

        if ($attributes['displaySpotLightExceprt']) {
            $block_class .= ' has-spotlight-excerpt ';
        }
        $layoutClass = false;

        if ($attributes['express'] != 'blockspare-posts-block-express-grid-layout-4' && $attributes['express'] != 'blockspare-posts-block-express-grid-layout-5' && $attributes['express'] != 'blockspare-posts-block-express-grid-layout-6') {
            $layoutClass = true;
        }

        $design .= $attributes['express'];
        $blockName = 'express';
        $alignclass = blockspare_checkalignment($attributes['align']);

        /* Build the block classes */
        $class = "wp-block-blockspare-posts-block-blockspare-posts-block-latest-posts align" . $alignclass . " " . $attributes['blockHoverEffect'] . " " . $attributes['imageHoverEffect'];

        if (isset($attributes['className'])) {
            $class .= ' ' . $attributes['className'];
        }

        if ($attributes['animation']) {
            $class .= ' blockspare-block-animation';
        }
        $class .= ' ' . $blockuniqueclass;
        ?>

        <div class="<?php echo esc_attr($class); ?>" blockspare-animation="<?php echo esc_attr($attributes['animation']); ?>">
        <?php
echo blockspare_latest_posts_style_control($blockuniqueclass, $attributes);
        blockspare_epxpress_query_loop_and_wrapper($attributes, $block_class, $design, $blockName, $layoutClass);

        ?>
        </div>
        <?php
return ob_get_clean();

    }
}

/**
 * Registers the post grid block on server
 */

if (!function_exists('blockspare_register_block_core_latest_posts_express_grid')) {
    function blockspare_register_block_core_latest_posts_express_grid()
    {

        if (!function_exists('register_block_type')) {
            return;
        }

        ob_start();
        include BLOCKSPARE_PLUGIN_DIR . 'inc/latest-post-block/posts-express-grid/block.json';
        $metadata = json_decode(ob_get_clean(), true);

        /* Block attributes */
        register_block_type(
            'blockspare/latest-posts-express-grid',
            array(
                'attributes' => $metadata['attributes'],
                'render_callback' => 'blockspare_render_block_core_latest_posts_express_grid',
            )
        );
    }

    add_action('init', 'blockspare_register_block_core_latest_posts_express_grid');
}

if (!function_exists('blockspare_latest_posts_style_control')) {

    function blockspare_latest_posts_style_control($blockuniqueclass, $attributes)
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

        $layoutstyle = explode('-', $attributes['express']);

        if ($layoutstyle[6] > 3) {
            $block_content .= ' .' . $blockuniqueclass . '  .blockspare-posts-block-post-single .blockspare-posts-block-post-img {
                border-radius:' . $attributes['borderRadius'] . 'px' . ';
            }';
            if ($attributes['enableBoxShadow']) {
                $block_content .= ' .' . $blockuniqueclass . '  .blockspare-posts-block-post-single .blockspare-posts-block-post-img {
                    box-shadow: ' . $attributes['xOffset'] . 'px ' . $attributes['yOffset'] . 'px ' . $attributes['blur'] . 'px ' . $attributes['spread'] . 'px ' . $attributes['shadowColor'] . ';
                }';
            }
        } else {
            $block_content .= ' .' . $blockuniqueclass . '  .blockspare-posts-block-post-single{
                border-radius:' . $attributes['borderRadius'] . 'px' . ';
                background-color:' . $attributes['backGroundColor'] . '
            }';

            if ($attributes['enableBoxShadow']) {
                $block_content .= ' .' . $blockuniqueclass . '  .blockspare-posts-block-post-single{
                    box-shadow: ' . $attributes['xOffset'] . 'px ' . $attributes['yOffset'] . 'px ' . $attributes['blur'] . 'px ' . $attributes['spread'] . 'px ' . $attributes['shadowColor'] . ';
                }';
            }

            $block_content .= ' .' . $blockuniqueclass . ' .blockspare-posts-block-post-content{
                padding-top:' . $attributes['contentPaddingTop'] . 'px;
                padding-right:' . $attributes['contentPaddingRight'] . 'px;
                padding-bottom:' . $attributes['contentPaddingBottom'] . 'px;
                padding-left:' . $attributes['contentPaddingLeft'] . 'px;
            }';
        }

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

        //Font Settings
        $block_content .= ' .' . $blockuniqueclass . ' .blockspare-posts-block-post-grid-title a span{
            font-size: ' . $attributes['postTitleFontSize'] . $attributes['titleFontSizeType'] . ';
            ' . bscheckFontfamily($attributes['titleFontFamily']) . ';
            ' . bscheckFontfamilyWeight($attributes['titleFontWeight']) . ';
            line-height: ' . $attributes['postTitleLineHeight'] . ';
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

        if ($attributes['express'] != 'blockspare-posts-block-express-grid-layout-3' && $attributes['express'] != 'blockspare-posts-block-express-grid-layout-6') {
            $block_content .= ' .' . $blockuniqueclass . ' .blockspare-posts-block-post-single:nth-child(1) .blockspare-posts-block-post-grid-title a span{
                font-size: ' . $attributes['spostTitleFontSize'] . $attributes['spostTitleFontSizeType'] . ';
                ' . bscheckFontfamily($attributes['spostTitleFontFamily']) . ';
                ' . bscheckFontfamilyWeight($attributes['spostTitleFontWeight']) . ';
            }';

            $block_content .= '@media (max-width: 1025px) { ';

            $block_content .= ' .' . $blockuniqueclass . ' .blockspare-posts-block-post-single:nth-child(1) .blockspare-posts-block-post-grid-title a span{
                font-size: ' . $attributes['spostTitleFontSizeTablet'] . $attributes['spostTitleFontSizeType'] . ';
            }';

            $block_content .= '}';
            $block_content .= '@media (max-width: 767px) { ';
            $block_content .= ' .' . $blockuniqueclass . ' .blockspare-posts-block-post-single:nth-child(1) .blockspare-posts-block-post-grid-title a span{
                font-size: ' . $attributes['spostTitleFontSizeMobile'] . $attributes['spostTitleFontSizeType'] . ';
            }';

            $block_content .= '}';
        } else {
            $block_content .= ' .' . $blockuniqueclass . ' .blockspare-posts-block-post-single:nth-child(1) .blockspare-posts-block-post-grid-title a span{
                font-size: ' . $attributes['spostTitleFontSize'] . $attributes['spostTitleFontSizeType'] . ';
                ' . bscheckFontfamily($attributes['spostTitleFontFamily']) . ';
                ' . bscheckFontfamilyWeight($attributes['spostTitleFontWeight']) . ';
            }';

            $block_content .= ' .' . $blockuniqueclass . ' .blockspare-posts-block-post-single:nth-child(2) .blockspare-posts-block-post-grid-title a span{
                font-size: ' . $attributes['spostTitleFontSize'] . $attributes['spostTitleFontSizeType'] . ';
                ' . bscheckFontfamily($attributes['spostTitleFontFamily']) . ';
                ' . bscheckFontfamilyWeight($attributes['spostTitleFontWeight']) . ';
            }';

            $block_content .= '@media (max-width: 1025px) { ';

            $block_content .= ' .' . $blockuniqueclass . ' .blockspare-posts-block-post-single:nth-child(1) .blockspare-posts-block-post-grid-title a span{
                font-size: ' . $attributes['spostTitleFontSizeTablet'] . $attributes['spostTitleFontSizeType'] . ';
            }';

            $block_content .= ' .' . $blockuniqueclass . ' .blockspare-posts-block-post-single:nth-child(2) .blockspare-posts-block-post-grid-title a span{
                font-size: ' . $attributes['spostTitleFontSizeTablet'] . $attributes['spostTitleFontSizeType'] . ';
            }';

            $block_content .= '}';

            $block_content .= '@media (max-width: 767px) { ';

            $block_content .= ' .' . $blockuniqueclass . ' .blockspare-posts-block-post-single:nth-child(1) .blockspare-posts-block-post-grid-title a span{
                font-size: ' . $attributes['spostTitleFontSizeMobile'] . $attributes['spostTitleFontSizeType'] . ';
            }';
            $block_content .= ' .' . $blockuniqueclass . ' .blockspare-posts-block-post-single:nth-child(2) .blockspare-posts-block-post-grid-title a span{
                font-size: ' . $attributes['spostTitleFontSizeMobile'] . $attributes['spostTitleFontSizeType'] . ';
            }';

            $block_content .= '}';

        }

        //Pagination
        if ($attributes['enablePagination']) {
            if ($attributes['loadMoreStyle'] == 'bs-loadmore-solid') {
                $block_content .= ' .' . $blockuniqueclass . '  .bs_blockspare_loadmore a.blockspare-readmore .load-btn{
                        color:' . $attributes['loadMoreSolidTextColor'] . ';
                        background-color:' . $attributes['loadMoreTextBgColor'] . ';
                    }';
                $block_content .= ' .' . $blockuniqueclass . '  .bs_blockspare_loadmore a.blockspare-readmore .load-btn:hover{
                        color:' . $attributes['loadMoreSolidTextHoverColor'] . ';
                        background-color:' . $attributes['loadMoreTextBgHoverColor'] . ';
                    }';
            } else {
                $block_content .= ' .' . $blockuniqueclass . '  .bs_blockspare_loadmore a.blockspare-readmore .load-btn{
                        color:' . $attributes['loadMoreTextColor'] . ';
                    }';
                $block_content .= ' .' . $blockuniqueclass . '  .bs_blockspare_loadmore a.blockspare-readmore .load-btn:hover{
                        color:' . $attributes['loadMoreTextHoverColor'] . ';
                    }';
            }

            $block_content .= ' .' . $blockuniqueclass . '  .bs_blockspare_loadmore{
                    text-align:' . $attributes['loadMoreAlignment'] . ';
                }';
            $block_content .= ' .' . $blockuniqueclass . ' .bs_blockspare_loadmore a.blockspare-readmore .ajax-loader-enabled{
                    border-top-color:' . $attributes['loadMoreColor'] . ';
                }';
            $block_content .= '}';
        }

        $block_content .= '</style>';
        return $block_content;
    }
}