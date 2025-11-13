<?php

if (!function_exists('blockspare_popular_tags_render')) {
    function blockspare_popular_tags_render($attributes)
    {
        ob_start();
        $unq_class = mt_rand(100000, 999999);
        $blockuniqueclass = '';

        if (!empty($attributes['uniqueClass'])) {
            $blockuniqueclass = $attributes['uniqueClass'];
        } else {
            $blockuniqueclass = 'blockspare-posts-block-list-' . $unq_class;
        }

        $mainClass = $blockuniqueclass;
        if ($attributes['animation']) {
            $mainClass .= ' blockspare-block-animation ' . $attributes['animation'];
        }
        $mainClass .= ' ' . $attributes['blockHoverEffect'];

        $wrapClass = 'bs-popular-tags-wrapper ' . $attributes['tagStyle'];
        if ($attributes['primaryIconToggle'] != true) {
            $wrapClass .= ' bs-tag-without-icon-wrapper';
        }

        $directionClass = 'bs-popular-taxonomies-lists ' . $attributes['directionOption'];
        if ($attributes['tagStyle'] != 'popular-tag-style-1') {
            $directionClass .= ' ' . $attributes['borderRadius'];
        }

        $primaryIconClass = 'bs-primary-icon ' . $attributes['primaryIcon'];
        $secondaryIconClass = 'bs-secondary-icon ' . $attributes['tagsIcon'];
        $columnClass = '';
        if ($attributes['directionOption'] == 'popular-vertical' && $attributes['column'] != 'bs-column-1') {
            $columnClass .= $attributes['column'];
        } else {
            $columnClass .= 'popular-vertical';
        }

        $alignclass = blockspare_checkalignment($attributes['align']);

        $query = array(
            'taxonomy' => $attributes['filterOption'] == 'categories' ? 'category' : 'post_tag',
            'number' => $attributes['tagsNumber'],
            'hide_empty' => false,
        );

        if ($attributes['orderOption'] == 'popular') {
            $query["orderby"] = 'count';
            $query["order"] = 'DESC';
        }

        $tags = get_terms($query);

        ?>
         <div class="<?php echo esc_attr($mainClass); ?> align<?php echo esc_attr($alignclass) ?>">
         <?php echo popular_tags_style_control($blockuniqueclass, $attributes); ?>
             <section class="<?php echo esc_attr($wrapClass); ?>">
                <div class="<?php echo esc_attr($directionClass); ?>">
                    <div class="bs-popular-tags-text">
                        <?php if ($attributes['primaryIconToggle']) {?>
                            <div class="bs-primary-icon-wrapper">
                                <i class="<?php echo esc_attr($primaryIconClass); ?>"></i>
                            </div>
                        <?php }?>
                        <div class="bs-tag-title-wrapper ">
							<span class="bs-tag-title-text">
                                <?php
echo esc_html($attributes['orderOption'] . ' ' . $attributes['filterOption']);
        ?>
							</span>
						</div>
                    </div>
                    <ul class="<?php echo esc_attr($columnClass); ?>">
                        <?php
foreach ($tags as $tag) {
            if ($attributes['tagsIconToggle']) {
                echo '<li><span class="bs-secondry-wrap blockspare-hover-item blockspare-hover-text"> <i class="' . esc_attr($secondaryIconClass) . '"></i><span><a class="bs-tag-wrapper" href="' . get_tag_link($tag->term_id) . '" rel="tag"><span class="bs-tag-text">' . $tag->name . '</span></a></span></span></li>';
            } else {
                echo '<li><span class="bs-secondry-wrap"><span><a class="bs-tag-wrapper blockspare-hover-item blockspare-hover-text" href="' . get_tag_link($tag->term_id) . '" rel="tag"><span class="bs-tag-text">' . $tag->name . '</span></a></span></span></li>';
            }
        }
        ?>
                    </ul>
                </div>

              </section>
        </div>

    <?php
return ob_get_clean();
    }

    /**
     * Registers the post grid block on server
     */

    if (!function_exists('blockspare_popular_tags_init')) {
        function blockspare_popular_tags_init()
        {
            if (!function_exists('register_block_type')) {
                return;
            }

            ob_start();
            include BLOCKSPARE_PLUGIN_DIR . 'inc/other-block/popular-tags/block.json';
            $metadata = json_decode(ob_get_clean(), true);

            /* Block attributes */
            register_block_type(
                'blockspare/popular-tags',
                array(
                    'attributes' => $metadata['attributes'],
                    'render_callback' => 'blockspare_popular_tags_render',
                )
            );
        }

        add_action('init', 'blockspare_popular_tags_init');
    }

}

if (!function_exists('popular_tags_style_control')) {
    function popular_tags_style_control($blockuniqueclass, $attributes)
    {
        $block_content = '';
        $block_content .= '<style type="text/css">';

        // popular tags/cats title color
        if (isset($attributes['popularTagColor'])) {
            $block_content .= ' .' . $blockuniqueclass . ' .bs-popular-tags-wrapper .bs-tag-title-wrapper .bs-tag-title-text{
                color:' . $attributes['popularTagColor'] . ';
            }';
        }

        // popular tags/cats title hover color
        // if(isset($attributes['popularTagHoverColor'])){
        //     $block_content .= ' .' . $blockuniqueclass . ' .bs-popular-tags-wrapper .bs-tag-title-wrapper .bs-tag-title-text:hover{
        //         color:' . $attributes['popularTagHoverColor'] . ';
        //     }';
        // }

        //tags/cats title color
        if (isset($attributes['tagsColor'])) {
            $block_content .= ' .' . $blockuniqueclass . ' .bs-popular-tags-wrapper .bs-tag-wrapper .bs-tag-text{
                color:' . $attributes['tagsColor'] . ';
            }';
        }

        // tags/cats title hover color
        if (isset($attributes['tagsHoverColor'])) {
            $block_content .= ' .' . $blockuniqueclass . ' .bs-popular-tags-wrapper .bs-tag-wrapper .bs-tag-text:hover{
                color:' . $attributes['tagsHoverColor'] . ';
            }';
        }

        // primary icon color
        if (isset($attributes['primaryIconColor'])) {
            $block_content .= ' .' . $blockuniqueclass . ' .bs-popular-tags-wrapper .bs-primary-icon{
                 color:' . $attributes['primaryIconColor'] . ';
             }';
        }

        // secondary icon color
        if (isset($attributes['secondaryIconColor'])) {
            $block_content .= ' .' . $blockuniqueclass . ' .bs-popular-tags-wrapper .bs-secondary-icon{
                color:' . $attributes['secondaryIconColor'] . ';
            }';
        }

        // secondary background color
        if ($attributes['tagStyle'] != "popular-tag-style-1") {
            if (isset($attributes['secondaryColor'])) {
                $block_content .= ' .' . $blockuniqueclass . ' .bs-popular-tags-wrapper .bs-popular-taxonomies-lists .bs-popular-tags-text{
                    background-color:' . $attributes['secondaryColor'] . ';
                }';
            }
        }

        //Block Gaps
        $block_content .= ' .' . $blockuniqueclass . ' .bs-popular-tags-wrapper{
            margin-top:' . $attributes['marginTop'] . 'px;
            margin-right:' . $attributes['marginRight'] . 'px;
            margin-bottom:' . $attributes['marginBottom'] . 'px;
            margin-left:' . $attributes['marginLeft'] . 'px;
            padding-top:' . $attributes['paddingTop'] . 'px;
            padding-right:' . $attributes['paddingRight'] . 'px;
            padding-bottom:' . $attributes['paddingBottom'] . 'px;
            padding-left:' . $attributes['paddingLeft'] . 'px;
        }';

        //Font Settings

        $block_content .= ' .' . $blockuniqueclass . ' .bs-popular-tags-wrapper .bs-popular-tags-text{
            font-size: ' . $attributes['popularTagsFontSize'] . $attributes['popularTagsFontSizeType'] . ';
            ' . bscheckFontfamily($attributes['popularTagsFontFamily']) . ';
            ' . bscheckFontfamilyWeight($attributes['popularTagsFontWeight']) . ';
        }';

        $block_content .= ' .' . $blockuniqueclass . ' .bs-popular-tags-wrapper .bs-secondry-wrap{
            font-size: ' . $attributes['tagsFontSize'] . $attributes['tagsFontSizeType'] . ';
            ' . bscheckFontfamily($attributes['tagsFontFamily']) . ';
            ' . bscheckFontfamilyWeight($attributes['tagsFontWeight']) . ';
        }';

        $block_content .= '@media (max-width: 1025px) { ';
        $block_content .= ' .' . $blockuniqueclass . ' .bs-popular-tags-wrapper .bs-popular-tags-text{
                font-size: ' . $attributes['popularTagsFontSizeTablet'] . $attributes['popularTagsFontSizeType'] . ';
            }';

        $block_content .= ' .' . $blockuniqueclass . ' .bs-popular-tags-wrapper .bs-secondry-wrap{
                font-size: ' . $attributes['tagsFontSizeTablet'] . $attributes['tagsFontSizeType'] . ';
            }';
        $block_content .= '}';

        $block_content .= '@media (max-width: 767px) { ';
        $block_content .= ' .' . $blockuniqueclass . ' .bs-popular-tags-wrapper .bs-popular-tags-text{
                font-size: ' . $attributes['popularTagsFontSizeMobile'] . $attributes['popularTagsFontSizeType'] . ';
            }';

        $block_content .= ' .' . $blockuniqueclass . ' .bs-popular-tags-wrapper .bs-secondry-wrap{
                font-size: ' . $attributes['tagsFontSizeMobile'] . $attributes['tagsFontSizeType'] . ';
            }';
        $block_content .= '}';

        $block_content .= '</style>';
        return $block_content;
    }
}