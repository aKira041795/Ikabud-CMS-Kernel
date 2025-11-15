<?php
    $morenews_featured_categories = array();
    
    for ($morenews_i = 1; $morenews_i <= 3; $morenews_i++) {
        $morenews_category = morenews_get_option('featured_post_list_category_section_' . $morenews_i);
        $morenews_featured_categories['feature_' . $morenews_i][] = $morenews_category;
        $morenews_featured_categories['feature_' . $morenews_i][] = morenews_get_option('featured_post_list_section_title_' . $morenews_i);
        
        $color_class = '';
        if(absint($morenews_category) > 0){
            $color_id = "category_color_" . $morenews_category;
            // retrieve the existing value(s) for this meta field. This returns an array
            $term_meta = get_option($color_id);
            $color_class = ($term_meta) ? $term_meta['color_class_term_meta'] : 'category-color-1';
        }
        $morenews_featured_categories['feature_' . $morenews_i][] = $color_class;
    }
    
    if (isset($morenews_featured_categories)):
        $morenews_count = 1;
        foreach ($morenews_featured_categories as $morenews_fc): ?>
            <div class="featured-category-item pad float-l morenews-customizer">

                    <?php if (!empty($morenews_fc[1])): ?>
                        <?php morenews_render_section_title($morenews_fc[1], $morenews_fc[2]); ?>
                    <?php endif; ?>

                
                <?php $morenews_all_posts_vertical = morenews_get_posts(4, $morenews_fc[0]); ?>
                <div class="full-wid-resp af-widget-body">
                    <?php
                        if ($morenews_all_posts_vertical->have_posts()) :
                            while ($morenews_all_posts_vertical->have_posts()) : $morenews_all_posts_vertical->the_post();
                                global $post;

                                ?>
                                <?php do_action('morenews_action_loop_list', $post->ID, 'thumbnail', 0, true, true, false); ?>
                            <?php
                            endwhile;
                        endif;
                        wp_reset_postdata();
                    ?>
                </div>
            </div><!--featured-category-item-->
            <?php
            $morenews_count++;
        endforeach;
    endif;
