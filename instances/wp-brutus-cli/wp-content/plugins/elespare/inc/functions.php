<?php
/**
 * Post title
 */
if(!function_exists('elespare_get_posts_title')){
 function elespare_get_posts_title($show_title='',$post_title='',$permalink=''){
    
     if ( 'yes' !== $show_title ) {
        return;
    }
    ?>

    <h4 class="elespare-post-title">
        <a href="<?php echo esc_url($permalink); ?>"> <span><?php echo $post_title; ?></span></a>
    </h4>
    <?php
 }
}
 /**
  * Post thumbnail
  */

  if(!function_exists('elespare_posts_grid_render_thumbnail')){
  function elespare_posts_grid_render_thumbnail($post_thumbnail_size =''){
    if ( has_post_thumbnail() ) :  ?>
    <a href="<?php the_permalink(); ?>">
            <?php the_post_thumbnail($post_thumbnail_size); ?>
        </a>
    <?php
     endif;
  }
}

  /**
   * Post excerpt and readmore
   */
  if(!function_exists('elespare_posts_tabs_render_excerpt_readmore')){
   function elespare_posts_tabs_render_excerpt_readmore($id='',$show_excerpt='',$excerpt_length='',$show_read_more='',$read_more_text=''){
       
        if ( 'yes' !== $show_excerpt ) {
        return;
    }
    ?>
    <div class="elespare-exceprt">
           <?php
            $elespare_excerpt = elespare_get_the_excerpt($id,$excerpt_length);
            if($elespare_excerpt){
                echo wp_kses_post($elespare_excerpt);
            }
             ?>
            <?php elespare_posts_render_readmore($show_read_more,$read_more_text);?>
    </div>
    <?php
    }
  }

function elespare_posts_render_readmore($show_read_more='',$read_more_text=''){

    if ( 'yes' !== $show_read_more ) {
        return;
    }

    ?>
    <a class="read-more-btn" href="<?php the_permalink(); ?>"><?php echo esc_html( $read_more_text ); ?></a>
    <?php
}
/**
 * Category common funciton to show in post item
 */

 if(!function_exists('elespare_posts_show_category')){
 function elespare_posts_show_category($show_category='',$cs=''){
    if($show_category !== 'yes'){
        return;
    }
    if($cs=='list-style-1' || $cs=='grid-style-5' || $cs=='grid-style-6'|| $cs=='grid-style-12' ||$cs=='tab-style-5'  ||$cs=='tab-style-6'|| $cs=='tab-style-12' ||$cs=='masonry-style-5' ||$cs=='masonry-style-6'||$cs=='carousel-style-4' || $cs=='carousel-style-11' || $cs=='carousel-style-12'||$cs=='masonry-style-12'){
        $cs ='none';
    }elseif($cs=='list-style-2'||$cs=='grid-style-9' ||$cs=='grid-style-10' || $cs=='carousel-style-8' || $cs=='carousel-style-9' ||$cs=='tab-style-9' ||$cs=='tab-style-10' ||$cs=='masonry-style-9' ||$cs=='masonry-style-10' || $cs=='carousel-style-5'){
        $cs ='border';
    }  else{
        $cs ='solid'; 
    }
    echo wp_kses_post( elespare_posts_grid_get_categories_list($cs));
    }
 }

 /**
 * Category common express and listfunciton to show in post item
 */

if(!function_exists('elespare_posts_express_list_show_category')){
    function elespare_posts_express_list_show_category($show_category='',$cs='', $count='',$widget_name=''){
       if($show_category !== 'yes'){
           return;
       }
       if($widget_name =='post-list'){
                    if($cs=='list-style-1' ||$cs=='list-style-3' ){
                        $cs ='none';
                    }elseif($cs=='list-style-2'||$cs=='list-style-4' ||$cs=='list-style-9'){
                        $cs ='border';
                    }else if($cs=='list-style-5' || $cs=='list-style-7' ) {
                        if( $count == 1 ){
                            $cs ='solid';
                        }else{
                            $cs ='none';  
                        } 
                    }else if($cs=='list-style-6' ||$cs=='list-style-8' ||$cs=='list-style-11' ) {
                            $cs ='border';
                        
                        }else{
                        $cs ='solid'; 
                    }
        }
        if($widget_name =='post-express-grid'){
            if($cs == 'express-style-1') {
                    if($count ==  1){
                        $cs = 'solid';
                    }else{
                        $cs = 'none';
                    }
            }else if( 'express-style-4'){
                        $cs = 'none';
             }else if($cs == 'express-style-2' || $cs == 'express-style-3'){
                         $cs = 'border';
             }
            else{
                         $cs = 'solid';
            }
        }


        if($widget_name =='post-express-list'){
            if( $cs == 'express-style-3' ||$cs == 'express-style-6') {
                    if($count ==  1){
                        $cs = 'solid';
                    }else{
                        $cs = 'none';
                    }
            }else if($cs == 'express-style-4' ) {
                 if($count <=  2){
                        $cs = 'solid';
                    }else{
                        $cs = 'none';
                    }
              } else if($cs == 'express-style-1' || $cs == 'express-style-2' ){
                        $cs = 'none';
             }
            else{
                         $cs = 'solid';
            }
        }
       echo wp_kses_post( elespare_posts_grid_get_categories_list($cs));
       }
    }
 

/**
 *
 * Query
 */
if(!function_exists('elespare_get_all_posts')){
    function elespare_get_all_posts($settings, $block_name = '' ){

        $posts_per_page = ( ! empty( $settings['posts_per_page'] ) ?  $settings['posts_per_page'] : 4 );
        $taxonomy =  $settings['_filter_by'];

        if($block_name == 'post-tabs') {

            if($taxonomy == 'post_tag') {
                $terms_ids = $settings['tag_term_ids' ];
            } else {
                $terms_ids = $settings['term_ids' ];
            }

            $query_args = array(
                'posts_per_page' => absint( $posts_per_page ),
            );



        } else {

            $query_args = array(
                'posts_per_page' => absint( $posts_per_page ),
            );

            if($taxonomy =='category'){
                $query_args['cat'] = absint($settings['posts_category']? $settings['posts_category'] : '0');
            }else{
                $query_args['tag_id'] = absint($settings['tag_term_ids']? $settings['tag_term_ids'] : '0');
            }

        }


        // Order by.
        if ( ! empty( $settings['orderby'] ) ) {
            $query_args['orderby'] = $settings['orderby'];
        }

        // Order .
        if ( ! empty( $settings['order'] ) ) {
            $query_args['order'] = $settings['order'];
        }
        
        $query_args['ignore_sticky_posts'] = 1;
        $query_data = new WP_Query($query_args);


        return $query_data;

    }
}
if(!function_exists('elespare_has_post_format')){
    function elespare_has_post_format($post_id,$format_style='')    {
        $post_format = get_post_format($post_id);
        $post_format_class = 'has-no-post-format';
        if($post_format){
            $post_format_class='';
        }
        return $post_format_class;
    }
}
    
/**
 * @param PostId
 * @param
 *
 */
if(!function_exists('elespare_get_user_profile_avatar')){
    function elespare_get_user_profile_avatar($postid = ''){
        $userid = get_post_field( 'post_author', $postid );
        $avatar_url = get_avatar_url( $userid,array('size'=>'50') );

        return $avatar_url;

    }
}
/**
 * Returns no image url.
 *
 * @since  DarkNews 1.0.0
 */
if (!function_exists('elespare_post_format')):
    function elespare_post_format($post_id,$format_style='')
    {
        $post_format = get_post_format($post_id);

        if($post_format) {?>
            <div class="elespare-post-format <?php echo esc_attr($format_style)?>">
                <?php
            switch ($post_format) {
                case "image":
                    $post_format = "<i class='demo-icon elespare-icons-camera'></i>";
                    break;
                case "video":
                    $post_format = "<i class='demo-icon elespare-icons-videocam'></i>";
                    break;
                case "gallery":
                    $post_format = "<i class='demo-icon elespare-icons-camera'></i>";
                    break;
                default:
                    $post_format = "";
            }
            echo wp_kses_post($post_format);?>
            </div>
            <?php
        }
    }
endif;
if(!function_exists('elespare_get_all_posts_in_banner')){
    function  elespare_get_all_posts_in_banner($settings,$sections){

        
        if($sections == 'banner-1'){

            $posts_per_page= 2;
            $taxonomy =  $settings['_filter_by'];
            $query_args = array(
                'posts_per_page' 		=> absint( $posts_per_page ),
            );

            if($taxonomy =='category'){
                $query_args['cat'] = absint($settings['posts_category']? $settings['posts_category'] : '0');
            }else{
                $query_args['tag_id'] = absint($settings['tag_term_ids']? $settings['tag_term_ids'] : '0');
            }

           
        }

        if($sections == 'banner-2'){
            
            if ($settings['layout_posts_style'] == 'banner-style-3' || $settings['layout_posts_style'] == 'banner-style-4'){
                $posts_per_page= 3;
            }elseif ($settings['layout_posts_style'] == 'banner-style-5' ||$settings['layout_posts_style'] == 'banner-style-6' ||$settings['layout_posts_style'] == 'banner-style-7'||$settings['layout_posts_style'] == 'banner-style-8'){
                $posts_per_page= 2;
            }else{
                $posts_per_page= 4;
            }
            $taxonomy =  $settings['_filter_by'];
            $query_args = array(
                'posts_per_page' 		=> absint( $posts_per_page ),
            );

            if($taxonomy =='category'){
                $query_args['cat'] = absint($settings['posts_category']? $settings['posts_category'] : '0');
            }else{
                $query_args['tag_id'] = absint($settings['tag_term_ids']? $settings['tag_term_ids'] : '0');
            }

           
            
        }
        if($sections == 'carousel'){
            $posts_per_page =  5 ;
            $taxonomy =  $settings['_filter_by_carousel'];
            $query_args = array(
                'posts_per_page' 		=> absint( $posts_per_page ),
            );

            if($taxonomy =='category'){
                $query_args['cat'] = absint($settings['posts_category_carousel']? $settings['posts_category_carousel'] : '0');
            }else{
                $query_args['tag_id'] = absint($settings['tag_term_ids_carousel']? $settings['tag_term_ids_carousel'] : '0');
            }
        }

        if($sections == 'trending'){
            $posts_per_page = 5;
            $taxonomy =  $settings['_filter_by_trending'];
            $query_args = array(
                'posts_per_page' 		=> absint( $posts_per_page ),
                'ignore_sticky_posts' => 1
            );

            if($taxonomy =='category'){
                $query_args['cat'] = absint($settings['posts_category_trending']? $settings['posts_category_trending'] : '0');
            }else{
                $query_args['tag_id'] = absint($settings['tag_term_ids_trending']? $settings['tag_term_ids_trending'] : '0');
            }

        }


        $query_args['ignore_sticky_posts'] = 1;
        $query_data = new WP_Query($query_args);

        return $query_data;
    }
}
if(!function_exists('elespare_get_all_posts_in_banner_one_grid')){
    function elespare_get_all_posts_in_banner_one_grid($settings,$banner_grid_left){

         $taxonomy =  $settings['_filter_by'];
           
            if($banner_grid_left == 'lower'){
                $posts_per_page= ( ! empty( $settings['posts_per_page_grid'] ) ?  $settings['posts_per_page_grid'] : 2 );;
        
                
                $query_args = array(
                    'posts_per_page'=> absint( $posts_per_page ),
                     'offset'   => 2
                );

                
            }else{
                $posts_per_page = 2;
                $query_args = array(
                
                    'posts_per_page' 		=> absint( $posts_per_page ),
                    
                );
            }

            if($taxonomy =='category'){
                $query_args['cat'] = absint($settings['posts_category']? $settings['posts_category'] : '0');
            }else{
                $query_args['tag_id'] = absint($settings['tag_term_ids']? $settings['tag_term_ids'] : '0');
            }

            // // Order by.
            // if ( ! empty( $settings['orderby'] ) ) {
            //     $query_args['orderby'] = $settings['orderby'];
            // }

            // // Order .
            // if ( ! empty( $settings['order'] ) ) {
            //     $query_args['order'] = $settings['order'];
            // }


            $query_args['orderby'] = 'date';
            $query_args['order'] = 'DESC';
            $query_args['ignore_sticky_posts'] = 1;
            $query_data = new WP_Query($query_args);

        return $query_data;


    }
}
if(!function_exists('elespare_get_all_taxonomy_list')){
    function elespare_get_all_taxonomy_list($settings){
        $taxonomy =  $settings['_filter_by'];

        if($taxonomy == 'post_tag'){
            $terms_ids = $settings['tag_term_ids' ];
        }else{
            $terms_ids = $settings['term_ids' ];
        }
        $terms_args = [
            'taxonomy'   => $taxonomy,
            'hide_empty' => true,
            'include'    => $terms_ids,
            'orderby'    => 'include',
        ];

        $filter_list = get_terms( $terms_args );
        return $filter_list;
    }
}

if(!function_exists('elespare_get_all_taxonomy_ids')){
    function elespare_get_all_taxonomy_ids($settings){
        $taxonomy =  $settings['_filter_by'];

        if($taxonomy == 'post_tag'){
            $terms_ids = $settings['tag_term_ids' ];
        }else{
            $terms_ids = $settings['term_ids' ];
        }

        return $terms_ids;
    }
}    
/**
 * Section Title dropdwom
 *
 */

 if(!function_exists('elespare_section_title_dropdown')){
    function elespare_section_title_dropdown(){
        return array(
            'title-style-1' => esc_html__( 'Title Style 1', 'elespare' ),
            'title-style-2' => esc_html__( 'Title Style 2', 'elespare' ),
            'title-style-3' => esc_html__( 'Title Style 3', 'elespare' ),
            'title-none' =>esc_html__( 'None', 'elespare' )
        );
    }
 }
/**
 * Order by list
 */

if(!function_exists('elespare_orderby_list')){
    function elespare_orderby_list(){
        $order_by =  array(
            'post_date' => esc_html__( 'Date', 'elespare' ),
            'post_title' => esc_html__( 'Title', 'elespare' ),
            'comment_count'=> esc_html__( 'Comments', 'elespare' ),
            'rand' => esc_html__( 'Random', 'elespare' ),

        );
        return $order_by;
    }
}
/**
 * Title render
 */
if(!function_exists('elespare_kses_basic')){
    function elespare_kses_basic( $string = '' ) {
        return wp_kses( $string, elespare_get_allowed_html_tags( 'basic' ) );
    }
}

/**
 * Default calor
 */

 if(!function_exists('elespare_default_color')){
     function elespare_default_color($color_type=''){
        

        if($color_type=='section-dash-color-2'){
            return '#efefef';
        }
        if($color_type=='section-dash-color-1'){
            return '#bb1919';
        }
        if($color_type =='section-title'){
            return '#000';
        }

        if($color_type=='post-title-normal'){
            return '#222';
        }

        if($color_type=='post-title-spotlight'){
            return '#fff';
        }
        if($color_type=='category'){
            return '#bb1919';
        }

        if($color_type=='postformat'){
            return '#ffffff';
        }
        if($color_type=='postformat-bg'){
            return '#BB1919';
        }
        if($color_type=='content-bg'){
            return '#ffffff';
        }

        if($color_type == 'post-meta'){
            return '#767676';
        }

        if($color_type=='post-excerpt'){
            return '#616161';
        }
        if($color_type=='navigation-arrow'){
            return '#222';
        }
        if($color_type=='navigation-background'){
            return '#fff';
        }
        if($color_type == 'navigation-dots'){
            return '#222';
        }

        if($color_type == 'post-title-dark'){
            return '#ECECEC';
        }
        if($color_type == 'post-title-dark-bg'){
            return '#1E1F24';
        }
       
    
    }
 }

/**
 * Get a list of all the allowed html tags.
 *
 * @param string $level Allowed levels are basic and intermediate
 * @return array
 */
if(!function_exists('elespare_get_allowed_html_tags')){
    function elespare_get_allowed_html_tags( $level = 'basic' ) {
        $allowed_html = [
            'b' => [
                'class' => [],
                'id' => [],
                'style' => []
            ],
            'i' => [
                'class' => [],
                'id' => [],
                'style' => []
            ],
            'u' => [
                'class' => [],
                'id' => [],
                'style' => []
            ],
            's' => [
                'class' => [],
                'id' => [],
                'style' => []
            ],
            'br' => [
                'class' => [],
                'id' => [],
                'style' => []
            ],
            'em' => [
                'class' => [],
                'id' => [],
                'style' => []
            ],
            'del' => [
                'class' => [],
                'id' => [],
                'style' => []
            ],
            'ins' => [
                'class' => [],
                'id' => [],
                'style' => []
            ],
            'sub' => [
                'class' => [],
                'id' => [],
                'style' => []
            ],
            'sup' => [
                'class' => [],
                'id' => [],
                'style' => []
            ],
            'code' => [
                'class' => [],
                'id' => [],
                'style' => []
            ],
            'mark' => [
                'class' => [],
                'id' => [],
                'style' => []
            ],
            'small' => [
                'class' => [],
                'id' => [],
                'style' => []
            ],
            'strike' => [
                'class' => [],
                'id' => [],
                'style' => []
            ],
            'abbr' => [
                'title' => [],
                'class' => [],
                'id' => [],
                'style' => []
            ],
            'span' => [
                'class' => [],
                'id' => [],
                'style' => []
            ],
            'strong' => [
                'class' => [],
                'id' => [],
                'style' => []
            ],
        ];

        if ( $level === 'intermediate' ) {
            $tags = [
                'a' => [
                    'href' => [],
                    'title' => [],
                    'class' => [],
                    'id' => [],
                    'style' => []
                ],
                'q' => [
                    'cite' => [],
                    'class' => [],
                    'id' => [],
                    'style' => []
                ],
                'img' => [
                    'src' => [],
                    'alt' => [],
                    'height' => [],
                    'width' => [],
                    'class' => [],
                    'id' => [],
                    'style' => []
                ],
                'dfn' => [
                    'title' => [],
                    'class' => [],
                    'id' => [],
                    'style' => []
                ],
                'time' => [
                    'datetime' => [],
                    'class' => [],
                    'id' => [],
                    'style' => []
                ],
                'cite' => [
                    'title' => [],
                    'class' => [],
                    'id' => [],
                    'style' => []
                ],
                'acronym' => [
                    'title' => [],
                    'class' => [],
                    'id' => [],
                    'style' => []
                ],
                'hr' => [
                    'class' => [],
                    'id' => [],
                    'style' => []
                ],
            ];

            $allowed_html = array_merge( $allowed_html, $tags );
        }

        return $allowed_html;
    }
}    
/**
 * Get all categories
 */
if(!function_exists('elespare_posts_grid_get_categories_list')){
    function elespare_posts_grid_get_categories_list( $category_style = ''){

        // Hide category and tag text for pages.
        if ('post' === get_post_type()) {

            global $post;


            $post_categories = get_the_category($post->ID);
            $classes = "elespare-cat-links ".$category_style;
            if ($post_categories) {
                $output = "<ul class='$classes'>";
                foreach ($post_categories as $post_category) {
                    $t_id = $post_category->term_id;
                    $color_id = "category_color_" . $t_id;

                    // retrieve the existing value(s) for this meta field. This returns an array
                    $term_meta = get_option($color_id);

                    $output .= '<li class="elespare-meta-category">
                                <a class="elespare-categories'. '" href="' . esc_url(get_category_link($post_category)) . '">
                                    ' . esc_html($post_category->name) . '
                                </a>
                            </li>';
                }
                $output .= '</ul>';
                return $output;

            }
        }
    }
}
if(!function_exists('elespare_get_categories_list_dropdown')){
    function elespare_get_categories_list_dropdown($category_id = 0, $taxonomy='', $default=''){
        $taxonomy = !empty($taxonomy) ? $taxonomy : 'category';

        $text_select =  'Select Categories';
        if($taxonomy == 'post_tag'){
            $text_select = 'Select Tags';
        }

        if ( $category_id > 0 ) {
            $term = get_term_by('id', absint($category_id), $taxonomy );
            if($term)
                return $term->name;


        } else {
            $terms = get_terms(array(
                'taxonomy' => $taxonomy,
                'orderby' => 'name',
                'order' => 'ASC',
                'hide_empty' => true,
            ));


            if (isset($terms) && !empty($terms)) {
                foreach ($terms as $term) {
                    if( $default != 'first' ){
                        $array['0'] = esc_html__($text_select, 'elespare');
                    }
                    $array[$term->term_id] = esc_html($term->name);
                }

                return $array;
            }
        }
    }
}


/**
 * Get excerpt
 */
if(!function_exists('elespare_get_the_excerpt')){
    function elespare_get_the_excerpt($post_id='',$elespare_excerpt_length = 12){
        $excerpt = apply_filters('the_excerpt',
            get_post_field(
                'post_excerpt',
                $post_id,
                'display'
            )
        );

        // $excerpt = apply_filters('the_excerpt',
        //     wp_trim_words(
        //         preg_replace(
        //             array(
        //                 '/\<figcaption>.*\<\/figcaption>/',
        //                 '/\[caption.*\[\/caption\]/',
        //             ),
        //             '',
        //             get_the_content()
        //         ),
        //         $elespare_excerpt_length
        //     )
        // );

        if ( isset( $elespare_excerpt_length ) ) {
            $excerpt = wp_trim_words(
                $excerpt,
                $elespare_excerpt_length
            );
            
        }

        $excerpt = apply_filters( 'the_excerpt', $excerpt );

        if(!$excerpt) {
            $excerpt = null;
        }

        return $excerpt;

    }

}


function elespare_is_free() {
    return true;
}

/**
 * Tag/Category 
 */

function elespare_list_popular_categories($taxonomy = 'category', $title = "Popular Category", $number = 5 ,$primary_icon= '',$secondary_icon= '' ,$vertical_horizontal='',$popular_tag_column='' ,$query_by='')
{
    
    $args = array(
        'taxonomy' => $taxonomy,
        'number' => absint($number),
        'hide_empty' => false

    );
    if($query_by == 'count'){
        $args['orderby']=  $query_by;
        $args['order'] = "DESC";
    }

    if($query_by == 'count'){
        $title_prefix = esc_html__('Popular','elespare-pro');
    }else{
        $title_prefix = esc_html__('Latest','elespare-pro');
    }


    $popular_taxonomies = get_terms($args );

    $html = '';

    if (isset($popular_taxonomies) && !empty($popular_taxonomies)):
        $html .= '<div class="elespare-popular-taxonomies-lists '.$vertical_horizontal.'">';
        if (!empty($title)):
            $html .= '<div class="elespare-popular-tags-text">';
            if (!empty($primary_icon)):
            $html .= '<div class="elespare-primary-icon-wrapper">';
            $html .= '<i class="elespare-primary-icon '.esc_attr($primary_icon).' " aria-hidden="true">';
            $html .= '</i>';
            $html .= '</div>';
            endif;
            $html .= '<strong class="elespare-tag-title-wrapper ">';
            $html .= '<span class="elespare-tag-title-text ">';
            $html .= $title_prefix." ".esc_html($title);
            $html .= '</span>';
            $html .= '</strong>';
            $html .= '</div>';
        endif;
        $html .= '<ul class="'.$popular_tag_column.'" style="list-style-type:none;">';
        foreach ($popular_taxonomies as $tax_term):
            $html .= '<li>';
            $html .= '<span class="elespare-secondry-wrap">';
            if (!empty($secondary_icon)):
            $html .= '<i class="elespare-secondary-icon '.esc_attr($secondary_icon).' " aria-hidden="true">';
            $html .= '</i>';
            endif;
            $html .= '<span>';
            $html .= '<a class="elespare-tag-wrapper" href="' . esc_url(get_term_link($tax_term)) . '">';
            $html .= '<span class="elespare-tag-text">';
            $html .= $tax_term->name;
            $html .= '</span>';
            $html .= '</a>';
            $html .= '</li>';
        endforeach;
        $html .= '</ul>';
        $html .= '</div>';
    endif;

    echo wp_kses_post($html);
}