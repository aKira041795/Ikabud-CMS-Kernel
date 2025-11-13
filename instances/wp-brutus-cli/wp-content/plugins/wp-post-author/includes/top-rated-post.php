<?php


function awpa_rating_variation($post_id= 1,$show_avg=true,$show_star_type=true ,$show_votes=true,$star_size='x-small'){
    
   
    $rating_settings = get_option('awpa_pro_rating_settings');
   
    if($rating_settings ){
        $post_rating_type = explode("_",$rating_settings['rating_review']);
        if($post_rating_type){
        $star_type = (int)$post_rating_type[0];
        }else{
            $star_type = '';
        }

        $rating_color_back = $rating_settings['rating_color_back'];
        $rating_color_front=$rating_settings['rating_color_front'];
        $show_star_rating = false;
        if(isset($rating_settings['show_star_rating'])){
            $show_star_rating=$rating_settings['show_star_rating'];
        }

        
        $search_meta_key = 'awpa_pro_post_' . $rating_settings['rating_review'] . '_rating_review';
        $post_meta = get_post_meta($post_id, $search_meta_key, false);
        
        if($post_meta){
            $data = json_encode($post_meta,true); 
            ob_start();
            ?>      
    
         <div class='awpa-single-post-star-variation'  attributes='<?php echo esc_attr($data); ?>' show_star_rating='<?php echo esc_attr($show_star_rating); ?>' rating_color_back='<?php echo esc_attr($rating_color_back); ?>' rating_color_front='<?php echo esc_attr($rating_color_front); ?>' rating_type='<?php echo esc_attr($star_type); ?>' show_avg='<?php echo esc_attr($show_avg); ?>', show_star_type='<?php echo esc_attr($show_star_type); ?>' show_votes='<?php echo esc_attr($show_votes); ?>' star_size='<?php echo esc_attr($star_size); ?>'></div>
        <?php 
        $output = ob_get_contents();
        ob_end_clean();
        return $output;    
        }
    }
     
 
 }

 function awpa_with_rating_title_update($title, $id = null) {
    $rating_settings = get_option('awpa_pro_rating_settings');

    if (!is_admin() && !is_null($id)) {
        $post = get_post($id);
        if ($post instanceof WP_Post && ($post->post_type == 'post' || $post->post_type == 'page')) {
            $rating_setting_mata = get_post_meta($id,'awpa_rating_review_enable',true);
             $enable_rating = false;
            if($rating_settings){
                if($rating_settings['enable_pro_rating'] == 1  && $rating_settings['show_star_rating']==1){
                        if( $rating_setting_mata =='true' ||  $rating_setting_mata =='' ){
                        $enable_rating = true;
                        }
                    }
            }
            $show_avg = false;
            $show_star_type = false;
            $show_votes = false;
            $new_title = awpa_rating_variation($id, $show_avg, $show_star_type, $show_votes);
            if (!empty($new_title)) {
                if($enable_rating){
                    if (isset($rating_settings['rating_display_on']) && $rating_settings['rating_display_on'] == 'top' ) {
                        $title = $new_title . $title;
                    } else{
                        $title = $title . $new_title;
                    }
                }

                return $title;
            }
        } 
    }

    

    return $title;
}

add_filter('the_title', 'awpa_with_rating_title_update', 10, 2);


function awpa_with_rating_remove_title_filter_nav_menu( $nav_menu, $args ) {
    // we are working with menu, so remove the title filter
    if (!is_admin()) {
    if(!empty($nav_menu)){
        remove_filter( 'the_title', 'awpa_with_rating_title_update', 10, 2 );
        return $nav_menu;
    }
    }
}
// this filter fires just before the nav menu item creation process
add_filter( 'pre_wp_nav_menu', 'awpa_with_rating_remove_title_filter_nav_menu', 10, 2 );

function awpa_with_rating_add_title_filter_non_menu( $items, $args ) {
    // we are done working with menu, so add the title filter back
    if (!is_admin()) {
    if(!empty($items)){
        add_filter( 'the_title', 'awpa_with_rating_title_update', 10, 2 );
        return $items;
    }
    }
}
// this filter fires after nav menu item creation is done
add_filter( 'wp_nav_menu_items', 'awpa_with_rating_add_title_filter_non_menu', 10, 2 );