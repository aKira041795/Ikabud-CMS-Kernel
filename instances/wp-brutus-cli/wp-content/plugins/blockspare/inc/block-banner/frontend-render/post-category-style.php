<?php 
//categories
if(!function_exists('blockspare_posts_category_style')){
    function blockspare_posts_category_style(
        $block_content, 
        $attributes,
        $blockuniqueclass,
        $color,
        $backgroundColor,
        $borderColor,
        $secondColor,
        $secondBackgroundColor,
        $secondBorderColor,
        $thirdColor,
        $thirdBackgroundColor,
        $thirdBorderColor
    ){
        // border-radius
        if ($attributes['categoryLayoutOption'] == 'border' || $attributes['categoryLayoutOption'] == 'solid') {
            $block_content .= ' .' . $blockuniqueclass . ' .blockspare-posts-block-post-category a{
                border-radius:' . $attributes['categoryBorderRadius'] . "px" . ';
            }';
        }

        // border
        if ($attributes['categoryLayoutOption'] == 'border') {
            $block_content .= ' .' . $blockuniqueclass . ' .blockspare-posts-block-post-category a{
                border-style: solid;
                border-width:' . $attributes['categoryBorderWidth'] . "px" .';
            }';
        }

        // underline
        if ($attributes['categoryLayoutOption'] == 'underline') {
            $block_content .= ' .' . $blockuniqueclass . ' .blockspare-posts-block-post-category a{
                border-bottom-style: solid;
                border-bottom-width:' . $attributes['categoryBorderWidth'] . "px" .';
                padding-left: 0;
                padding-right: 0;
            }';
        }
        
        // colors
        if ($attributes['colorType'] == 'bs-single-color') {
            $block_content .= ' .' . $blockuniqueclass . ' .blockspare-posts-block-post-category a{
                color:' . $color . "!important" . ';
            }';

            if ($attributes['categoryLayoutOption'] == 'solid') {
                $block_content .= ' .' . $blockuniqueclass . ' .blockspare-posts-block-post-category a{
                    background-color:' . $backgroundColor . "!important" . ';
                }';      
            }

            if ($attributes['categoryLayoutOption'] == 'border' || $attributes['categoryLayoutOption'] == 'underline') {
                $block_content .= ' .' . $blockuniqueclass . ' .blockspare-posts-block-post-category a{
                    border-color:' . $borderColor . ';
                }';
            }

        } else {
            $block_content .= ' .' . $blockuniqueclass . ' .blockspare-posts-block-post-category a:nth-child(3n + 1){
                color:' . $color . "!important" . ';
            }';
            $block_content .= ' .' . $blockuniqueclass . ' .blockspare-posts-block-post-category a:nth-child(3n + 2){
                color:' . $secondColor . "!important" . ';
            }';
            $block_content .= ' .' . $blockuniqueclass . ' .blockspare-posts-block-post-category a:nth-child(3n){
                color:' . $thirdColor . "!important" . ';
            }';

            if ($attributes['categoryLayoutOption'] == 'solid') {
                $block_content .= ' .' . $blockuniqueclass . ' .blockspare-posts-block-post-category a:nth-child(3n + 1){
                    background-color:' . $backgroundColor . "!important" . ';
                }';
                $block_content .= ' .' . $blockuniqueclass . ' .blockspare-posts-block-post-category a:nth-child(3n + 2){
                    background-color:' . $secondBackgroundColor . "!important" . ';
                }';
                $block_content .= ' .' . $blockuniqueclass . ' .blockspare-posts-block-post-category a:nth-child(3n){
                    background-color:' .  $thirdBackgroundColor . "!important" . ';
                }';     
            }

            if ($attributes['categoryLayoutOption'] == 'border' || $attributes['categoryLayoutOption'] == 'underline') {
                $block_content .= ' .' . $blockuniqueclass . ' .blockspare-posts-block-post-category a:nth-child(3n + 1){
                    border-color:' . $borderColor . ';
                }';
                $block_content .= ' .' . $blockuniqueclass . ' .blockspare-posts-block-post-category a:nth-child(3n + 2){
                    border-color:' . $secondBorderColor . ';
                }';
                $block_content .= ' .' . $blockuniqueclass . ' .blockspare-posts-block-post-category a:nth-child(3n){
                    border-color:' . $thirdBorderColor . ';
                }';
            }
        }

        $block_content .= ' .' . $blockuniqueclass . ' .blockspare-posts-block-post-category{
            margin-top:' . $attributes['categoryMarginTop'] . 'px' . ';
            margin-bottom:' . $attributes['categoryMarginBottom'] . 'px' . ';
            margin-left:' . $attributes['categoryMarginLeft'] . 'px' . ';
            margin-right:' . $attributes['categoryMarginRight'] . 'px' . ';
        }';

        $block_content .= ' .' . $blockuniqueclass . ' .blockspare-posts-block-post-category a {
            font-size:' . $attributes['postCategoryFontSize'] . $attributes['postCategoryFontSizeType'] . ';
            '.bscheckFontfamily($attributes['postCategoryFontFamily']).';
            '.bscheckFontfamilyWeight($attributes['postCategoryFontWeight']).';
        }';

        $block_content .= '@media (max-width: 1025px) { ';
            $block_content .= ' .' .  $blockuniqueclass .' .blockspare-posts-block-post-category a {
                font-size:' . $attributes['postCategoryFontSizeTablet'].$attributes['postCategoryFontSizeType'].'
            }';
        $block_content .= '}';

        $block_content .= '@media (max-width: 767px) { ';
            $block_content .= ' .' .  $blockuniqueclass .' .blockspare-posts-block-post-category a {
                font-size:' . $attributes['postCategoryFontSizeMobile'].$attributes['postCategoryFontSizeType'].'
            }';
        $block_content .= '}';

        echo $block_content;
    }
}

