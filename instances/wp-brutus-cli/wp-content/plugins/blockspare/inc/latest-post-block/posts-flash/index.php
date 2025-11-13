<?php
    if(!function_exists('blockspare_flash_render_block')){
    function blockspare_flash_render_block($attributes)
    {
        ob_start();
        $unq_class = mt_rand(100000,999999);
        $blockuniqueclass = '';
        
        if(!empty($attributes['uniqueClass'])){
            $blockuniqueclass = $attributes['uniqueClass'];
        }else{
            $blockuniqueclass = 'blockspare-posts-block-list-'.$unq_class;
        }
        
        
        
        if ( isset( $attributes['categories'] ) && ! empty( $attributes['categories'] ) && is_array( $attributes['categories'] ) ) {
            $categories = array();
            $i = 1;
            foreach ( $attributes['categories'] as $key => $value ) {
                $categories[] = $value['value'];
            }
        } else {
            $categories = array();
        }
    
        if ( isset( $attributes['tags'] ) && ! empty( $attributes['tags'] ) && is_array( $attributes['tags'] ) ) {
            $tags = array();
            $i = 1;
            foreach ( $attributes['tags'] as $key => $value ) {
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
    
        if($attributes['taxType'] =='category'){
            $query_args['category__in']  =$categories;
            
        }
        if($attributes['taxType'] =='post_tag'){
            $query_args['tag__in']  =$tags;
            
        }
       
       
        if($attributes['taxType'] !='category' && $attributes['taxType'] != 'post_tag'){
                   
            $tax_type = $attributes['taxType'];
            if ( $tax_type ) {
                $query_args['tax_query'][] = array(
                    'taxonomy' => ( isset( $tax_type ) ) ? $tax_type : 'category',
                    'field'    => 'id',
                    'terms'    => $categories,
                    'operator' =>  'IN' ,
                );
            }
        }

        $grid_query = new WP_Query($query_args);
    
      
        
        $responsivelayoutTab = 1;
        $responsivelayoutMobile = 1;
        $verticals = false;

        if($attributes['carouselType']=='vertical'){
            $verticals = true;
        }
        
       
        
        
        
        
        /* Start the loop */
        if ($grid_query->have_posts()) {
            $alignclass = blockspare_checkalignment($attributes['align']);
            /* Build the block classes */
            $class = 'align'.$alignclass;
            
            if (isset($attributes['class'])) {
                $class .= ' ' . $attributes['class'];
            }
            if( $attributes['animation']){
                $class .= ' blockspare-block-animation ' . $attributes['animation'];
            }
          
            
    
          
            //$list_layout_class = $attributes['trending'];
            $listgridClass = 'blockspare-posts-block-is-carousel' ;
            
            /* Layout orientation class */
            $block_post_wrap = $blockuniqueclass . '  blockspare-posts-block-post-wrap';
            $count = 0;
            $layout_class = 'bs-flash-wrap flash-layout ' . $attributes['layoutStyle'];
            
            if($attributes['fixedWidth']) {
                $layout_class .= ' has-fixed-width';
            }

            $exclusive_posts = 'bs-exclusive-posts blockspare-hover-item'; 
            if($attributes['border']){
                $exclusive_posts .= ' bs-border-enabled bs-' . $attributes['borderStyle'];
            } else {
                $exclusive_posts .= ' bs-border-disabled';
            }
            $flash_spiner_class = 'bs-spinner ';
            if($attributes['spinnerOption']=='spinner-option-1'){
                $flash_spiner_class .= $attributes['spinnerStyle'];
            }else{
                $flash_spiner_class .= $attributes['animationStyle'];
            }
            $spinner_icon = $attributes['spinnerIcon'];
            $marquee_class = 'bs-marquee bs-flash-side ' . $attributes['direction'];
            if($attributes['pauseOnHover']){
                $marquee_class .= ' pause-on-hover';
            }
            ?>

<div class="<?php echo esc_attr($class);?> <?php echo esc_attr($attributes['blockHoverEffect']) ?>">
<?php echo latest_posts_style_control_flash($blockuniqueclass ,$attributes);?>
    <section class="<?php echo esc_attr($block_post_wrap);?>">
        <div class="<?php echo esc_attr($layout_class);?>">
            <div class="<?php echo esc_attr($exclusive_posts);?>">
                <div class="bs-exclusive-now">
                    <?php if($attributes['exclusiveSubtitle']){ ?>
                        <span class="bs-exclusive-news-title"><span><?php echo esc_html($attributes['exclusiveSubtitleText']); ?></span></span>
                    <?php } ?>
                    <div class="bs-exclusive-now-txt-animation-wrap">
                        <?php if($attributes['spinner']){ ?>
                            <span class="<?php echo esc_attr($flash_spiner_class); ?>">
                                    <?php if($attributes['spinnerOption'] == 'spinner-option-1') { ?>
                                        <?php if($attributes['spinnerStyle'] != 'spinner-style-4') {?>
                                            <div class="ring"></div>
                                            <div class="ring"></div>
                                            <div class="ring"></div>
                                            <div class="ring"></div>
                                        <?php } else {?>
                                            <div class="ring"></div>
                                            <div class="ring"></div>
                                            <div class="ring"></div>
                                            <div class="ring"></div>
                                        <?php } ?>
                                    <?php } else { ?>
                                        <i class="<?php echo esc_attr($spinner_icon);?>"></i>
                                    <?php } ?>
                            </span>
                        <?php } ?>
                        <span class="bs-exclusive-texts-wrapper">
                            <?php echo $attributes['exclusiveText'] != "" ?  $attributes['exclusiveText'] : "Breaking News";?>
                        </span>
                    </div>
                </div>
                <div class="bs-exclusive-slides">
					<div class="<?php echo esc_attr($marquee_class);?>">
						<div class="bs-marquee-wrapper">
                            <?php while ($grid_query->have_posts()) {
                                $grid_query->the_post();

                                $count++;
                                
                                /* Setup the post ID */
                                $post_id = get_the_ID();
                                
                                /* Setup the featured image ID */
                                $post_thumb_id = get_post_thumbnail_id($post_id);
                                            
                                
                                /* Setup the post classes */
                                $post_classes = 'bs-post-title ';
                                
                                ?>

                                <h4 class="<?php echo esc_attr($post_classes) ?>">
                                    <?php
                                                
                                        if (!empty($attributes['imageSize'])) {
                                            $post_thumb_size = 'thumbnail   ';
                                        } ?>
                                        <a href="<?php echo esc_url(get_permalink($post_id));?>" rel="bookmark" aria-hidden="true"
                                            tabindex="-1">
                                            <span class="bs-circle-marq">
                                                <?php if($attributes['enableCount']){ ?>
                                                    <span class="bs-trending-no"><?php echo esc_html( $count );?></span>
                                                <?php } ?>
                                                <?php
                                                    if(has_post_thumbnail($post_id)){
                                                    echo wp_kses_post(wp_get_attachment_image($post_thumb_id, $post_thumb_size));
                                                    }else{ ?>
                                                            <div class="bs-no-thumbnail-img"> </div>
                                                <?php  } ?>
                                            </span>
                                            <span class="bs-post-title">
                                            <?php          
                                                $title = get_the_title($post_id);
                                                
                                                if (!$title) {
                                                    $title = __('Untitled', 'blockspare');
                                                }
                                                
                                                echo ($title); 
                                            ?>
											</span>
                                        </a>
                                </h4>
                            <?php } ?>
						</div>
                        <div aria-hidden="true"  class="bs-marquee-wrapper">
                            <?php while ($grid_query->have_posts()) {
                                $grid_query->the_post();

                                $count++;
                                
                                /* Setup the post ID */
                                $post_id = get_the_ID();
                                
                                /* Setup the featured image ID */
                                $post_thumb_id = get_post_thumbnail_id($post_id);
                                            
                                
                                /* Setup the post classes */
                                $post_classes = 'bs-post-title ';
                                
                                ?>

                                <h4 class="<?php echo esc_attr($post_classes) ?>">
                                    <?php
                                                
                                        if (!empty($attributes['imageSize'])) {
                                            $post_thumb_size = 'thumbnail   ';
                                        } ?>
                                        <a href="<?php echo esc_url(get_permalink($post_id));?>" rel="bookmark" aria-hidden="true"
                                            tabindex="-1">
                                            <span class="bs-circle-marq">
                                                <?php if($attributes['enableCount']){ ?>
                                                    <span class="bs-trending-no"><?php echo esc_html( $count );?></span>
                                                <?php } ?>
                                                <?php
                                                    if(has_post_thumbnail($post_id)){
                                                    echo wp_kses_post(wp_get_attachment_image($post_thumb_id, $post_thumb_size));
                                                    }else{ ?>
                                                            <div class="bs-no-thumbnail-img"> </div>
                                                <?php  } ?>
                                            </span>
                                            <span class="bs-post-title">
                                            <?php          
                                                $title = get_the_title($post_id);
                                                
                                                if (!$title) {
                                                    $title = __('Untitled', 'blockspare');
                                                }
                                                
                                                echo ($title); 
                                            ?>
											</span>
                                        </a>
                                </h4>
                            <?php } ?>
						</div>
					</div>
				</div>
            </div>
        </div>
    </section>
</div>
<?php wp_reset_postdata();
          
            return   ob_get_clean();
        }
    }
}
    
    /**
     * Registers the post grid block on server
     */
    if(!function_exists('blockspare_flash_register_block_core')){
    function blockspare_flash_register_block_core()
    {
    
        if (!function_exists('register_block_type')) {
            return;
        }
    
    
        ob_start();
       include BLOCKSPARE_PLUGIN_DIR . 'inc/latest-post-block/posts-flash/block.json';

        $metadata = json_decode(ob_get_clean(), true);
        
        
        /* Block attributes */
        register_block_type(
            'blockspare/latest-posts-flash',
            array(
                'attributes' =>$metadata['attributes'],
                'render_callback' => 'blockspare_flash_render_block',
            )
        );
    }
    
    add_action('init', 'blockspare_flash_register_block_core');
}
    
    
    
    
if(!function_exists('latest_posts_style_control_flash')){
    function latest_posts_style_control_flash($blockuniqueclass ,$attributes){
        
        $boxshadowcss = '';

        $block_content = '';
        $block_content .= '<style type="text/css">';

        //post-flash-background
        if($attributes['background']) {
            $block_content .= ' .' . $blockuniqueclass . ' .bs-flash-wrap .bs-exclusive-posts{
                background-color:' . $attributes['backGroundColor'] . ';
            }';
        }

        //post-flash-border-radius
        $block_content .= ' .' . $blockuniqueclass . ' .bs-flash-wrap .bs-exclusive-posts{
            border-radius:' . $attributes['borderRadius'] . 'px;
        }';
        $block_content .= ' .' . $blockuniqueclass . ' .bs-flash-wrap .bs-exclusive-posts .bs-exclusive-now-txt-animation-wrap{
            border-radius:' . $attributes['borderRadius'] . 'px;
        }';
        $block_content .= ' .' . $blockuniqueclass . ' .bs-flash-wrap .bs-exclusive-posts .bs-exclusive-slides{
            border-radius:' . $attributes['borderRadius'] . 'px;
        }';

        //post-image-border-radius
        $block_content .= ' .' . $blockuniqueclass . ' .bs-flash-wrap .bs-exclusive-posts .bs-exclusive-slides .bs-circle-marq{
            border-radius:' . $attributes['imgBorderRadius'] . 'px;
        }';

        //post-flash-animation-speed
        $block_content .= ' .' . $blockuniqueclass . ' .bs-flash-wrap .bs-exclusive-posts .bs-exclusive-slides .bs-marquee .bs-marquee-wrapper{
            animation-duration:' . $attributes['animationSpeed'] . 's;
        }';

        //post-widht
        $block_content .= ' .' . $blockuniqueclass . ' .bs-flash-wrap.has-fixed-width  h4.bs-post-title{
            width:' . $attributes['postWidth'] . 'px;
        }';

        //Title color
        $block_content .= ' .' . $blockuniqueclass . ' .bs-flash-wrap .bs-exclusive-posts .bs-exclusive-slides a .bs-post-title{
            color:' . $attributes['postTitleColor'] . ';
        }';

        //Title hover color
        $block_content .= ' .' . $blockuniqueclass . ' .bs-flash-wrap .bs-exclusive-posts .bs-exclusive-slides a:hover .bs-post-title{
            color:' . $attributes['titleOnHoverColor'] . ';
        }';

        //Exclusive News color and background
        $block_content .= ' .' . $blockuniqueclass . ' .bs-flash-wrap .bs-exclusive-posts .bs-exclusive-now .bs-exclusive-now-txt-animation-wrap{
            color:' . $attributes['newsColor'] . ';
            background-color: ' . $attributes['newsBgColor'] . ';
        }';
        $block_content .= ' .' . $blockuniqueclass . ' .bs-flash-wrap.flash-style-2 .bs-exclusive-posts .bs-exclusive-now .bs-exclusive-now-txt-animation-wrap::after{
            border-left-color:' . $attributes['newsBgColor'] . ';
        }';
        $block_content .= ' .' . $blockuniqueclass . ' .bs-flash-wrap.flash-style-3 .bs-exclusive-posts .bs-exclusive-now .bs-exclusive-now-txt-animation-wrap::after{
            background-color:' . $attributes['newsBgColor'] . ';
        }';
        $block_content .= ' .' . $blockuniqueclass . ' .bs-flash-wrap.flash-style-4 .bs-exclusive-posts .bs-exclusive-now .bs-exclusive-now-txt-animation-wrap::after{
            background-color:' . $attributes['newsBgColor'] . ';
        }';

        //Border
        if($attributes['border']) {
            $block_content .= ' .' . $blockuniqueclass . ' .bs-flash-wrap .bs-exclusive-posts{
                border-width:' . $attributes['borderWidth'] . 'px;
                border-color:' . $attributes['borderColor'] . ';
            }';
        } 

        //Exclusive Subtitle color and background
        if($attributes['exclusiveSubtitle']) {
            $block_content .= ' .' . $blockuniqueclass . ' .bs-flash-wrap .bs-exclusive-posts .bs-exclusive-now > span{
                color:' . $attributes['exclusiveColor'] . ';
                background-color: ' . $attributes['exclusiveBgColor'] . ';
            }';
            $block_content .= ' .' . $blockuniqueclass . ' .bs-flash-wrap .bs-exclusive-posts .bs-exclusive-now > span::before{
                border-top-color:' . $attributes['exclusiveBgColor'] . ';
            }';
            $block_content .= ' .' . $blockuniqueclass . ' .bs-flash-wrap .bs-exclusive-posts .bs-exclusive-now > span::after{
                border-bottom-color:' . $attributes['exclusiveBgColor'] . ';
            }';
        }

        //Block Gaps
        $block_content .= ' .' . $blockuniqueclass . ' .bs-flash-wrap .bs-exclusive-posts{
            margin-top:' . $attributes['marginTop'] . 'px;
            margin-right:' . $attributes['marginRight'] . 'px;
            margin-bottom:' . $attributes['marginBottom'] . 'px;
            margin-left:' . $attributes['marginLeft'] . 'px;
        }';

        $block_content .= ' .' . $blockuniqueclass . ' .bs-flash-wrap .bs-exclusive-slides{
            padding-top:' . $attributes['paddingTop'] . 'px;
            padding-right:' . $attributes['paddingRight'] . 'px;
            padding-bottom:' . $attributes['paddingBottom'] . 'px;
            padding-left:' . $attributes['paddingLeft'] . 'px;
        }';

        // Post Number Color
        if($attributes['enableCount']){
            $block_content .= ' .' . $blockuniqueclass . ' .bs-flash-wrap .bs-exclusive-posts .bs-exclusive-slides .bs-marquee h4.bs-post-title a .bs-circle-marq .bs-trending-no{
                color: ' . $attributes['countColor'] . '; 
            }';
        }
        
        //Font Settings
        
        $block_content .= ' .' . $blockuniqueclass . ' .bs-flash-wrap .bs-exclusive-posts .bs-exclusive-slides a .bs-post-title{
            font-size: ' . $attributes['postTitleFontSize'] . $attributes['titleFontSizeType'] . ';
            '.bscheckFontfamily($attributes['titleFontFamily']).';
            '.bscheckFontfamilyWeight($attributes['titleFontWeight']).';
            line-height: ' . $attributes['postTitleLineHeight'] . ';
        }';

        $block_content .= ' .' . $blockuniqueclass . ' .bs-flash-wrap .bs-exclusive-now .bs-exclusive-now-txt-animation-wrap .bs-exclusive-texts-wrapper{
            font-size: ' . $attributes['exclusiveFontSize'] . $attributes['exclusiveFontSizeType'] . ';
            '.bscheckFontfamily($attributes['exclusiveFontFamily']).';
            '.bscheckFontfamilyWeight($attributes['exclusiveFontWeight']).';
        }';

        $block_content .= ' .' . $blockuniqueclass . ' .bs-flash-wrap .bs-exclusive-now .bs-exclusive-news-title{
            font-size: ' . $attributes['exclusiveSubtitleFontSize'] . $attributes['exclusiveSubtitleFontSizeType'] . ';
            '.bscheckFontfamily($attributes['exclusiveSubtitleFontFamily']).';
            '.bscheckFontfamilyWeight($attributes['exclusiveSubtitleFontWeight']).';
        }';
    
        $block_content .= '@media (max-width: 1025px) { ';
            $block_content .= ' .' . $blockuniqueclass . ' .bs-flash-wrap .bs-exclusive-posts .bs-exclusive-slides a .bs-post-title{
                font-size: ' . $attributes['titleFontSizeTablet'] . $attributes['titleFontSizeType'] . ';
            }';
            $block_content .= ' .' . $blockuniqueclass . ' .bs-flash-wrap .bs-exclusive-now .bs-exclusive-now-txt-animation-wrap .bs-exclusive-texts-wrapper{
                font-size: ' . $attributes['exclusiveFontSizeTablet'] . $attributes['exclusiveFontSizeType'] . ';
            }';
            $block_content .= ' .' . $blockuniqueclass . ' .bs-flash-wrap .bs-exclusive-now .bs-exclusive-news-title{
                font-size: ' . $attributes['exclusiveSubtitleFontSizeTablet'] . $attributes['exclusiveSubtitleFontSizeType'] . ';
            }';
        $block_content .= '}';
        
        $block_content .= '@media (max-width: 767px) { ';
            $block_content .= ' .' . $blockuniqueclass . ' .bs-flash-wrap .bs-exclusive-posts .bs-exclusive-slides a .bs-post-title{
                font-size: ' . $attributes['titleFontSizeMobile'] . $attributes['titleFontSizeType'] . ';
            }';
            $block_content .= ' .' . $blockuniqueclass . ' .bs-flash-wrap .bs-exclusive-now .bs-exclusive-now-txt-animation-wrap .bs-exclusive-texts-wrapper{
                font-size: ' . $attributes['exclusiveFontSizeMobile'] . $attributes['exclusiveFontSizeType'] . ';
            }';$block_content .= ' .' . $blockuniqueclass . ' .bs-flash-wrap .bs-exclusive-now .bs-exclusive-news-title{
                font-size: ' . $attributes['exclusiveSubtitleFontSizeMobile'] . $attributes['exclusiveSubtitleFontSizeType'] . ';
            }';
        $block_content .= '}';
    
        $block_content .= '</style>';
        return $block_content;
    }
}
    
    