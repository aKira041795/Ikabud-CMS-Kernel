<?php

$morenews_main_posts_title = morenews_get_option('main_banner_news_section_title');
$morenews_editors_picks_posts_title = morenews_get_option('main_editors_picks_section_title');

$morenews_title_class ='' ;
if(empty($morenews_main_posts_title)){
    $morenews_title_class .= 'no-main-slider-title';
}


if(empty($morenews_editors_picks_posts_title)){
    $morenews_title_class .= ' no-editors-picks-title';
}



$morenews_editors_pick_color_class = '';
$morenews_banner_posts_color_class = '';
$morenews_trending_posts_color_class = '';

$morenews_editor_picks_posts_filter_by = morenews_get_option('select_editors_picks_filterby');
if ($morenews_editor_picks_posts_filter_by == 'cat') {
    $morenews_editors_pick_category = morenews_get_option('select_editors_picks_news_category');
    if (absint($morenews_editors_pick_category) > 0) {
        $color_id = "category_color_" . $morenews_editors_pick_category;
        // retrieve the existing value(s) for this meta field. This returns an array
        $term_meta = get_option($color_id);
        $morenews_editors_pick_color_class = ($term_meta) ? $term_meta['color_class_term_meta'] : 'category-color-1';
    }
}

$morenews_banner_posts_filter_by = morenews_get_option('select_main_banner_carousel_filterby');
if ($morenews_banner_posts_filter_by == 'cat') {
    $morenews_banner_posts_category = morenews_get_option('select_slider_news_category');
    if (absint($morenews_banner_posts_category) > 0) {
        $color_id = "category_color_" . $morenews_banner_posts_category;
        // retrieve the existing value(s) for this meta field. This returns an array
        $term_meta = get_option($color_id);
        $morenews_banner_posts_color_class = ($term_meta) ? $term_meta['color_class_term_meta'] : 'category-color-1';
    }
}

?>
<div class="aft-main-banner-part af-container-row-5 <?php echo esc_attr($morenews_title_class); ?>">

    <div class="aft-slider-part col-2 pad">
        <div class="morenews-customizer">
            <?php if (!empty($morenews_main_posts_title)): ?>
                <?php morenews_render_section_title($morenews_main_posts_title, $morenews_banner_posts_color_class); ?>
            <?php endif; ?>
            <?php morenews_get_block('carousel', 'banner'); ?>
        </div>
    </div>

    <div class="aft-trending-part col-4 pad">
        <div class="morenews-customizer">
        <?php morenews_get_block('tabs', 'banner'); ?>
        </div>
    </div>

    <div class="aft-thumb-part col-4 pad">
        <div class="morenews-customizer">
        <?php if (!empty($morenews_editors_picks_posts_title)): ?>
            <?php morenews_render_section_title($morenews_editors_picks_posts_title, $morenews_editors_pick_color_class); ?>
        <?php endif; ?>

        <?php morenews_get_block('thumb', 'banner'); ?>
        </div>
    </div>


</div>