<?php
    
    if(!function_exists('blockspare_search_from_render')){
    function blockspare_search_from_render($attributes)
    { 
        ob_start();
        $unq_class = mt_rand(100000,999999);
        $blockuniqueclass = '';
        
        if(!empty($attributes['uniqueClass'])){
            $blockuniqueclass = $attributes['uniqueClass'];
        }else{
            $blockuniqueclass = 'blockspare-posts-block-list-'.$unq_class;
        }

        $mainClass = $blockuniqueclass;
        $mainClass .=' ' . $attributes['blockHoverEffect'];

        $wrapClass = 'bs-search-wrapper bs-grid-' . $attributes['sectionAlign'];
        $toggleClass = 'bs-search-' . $attributes['sectionAlign'];
        if($attributes['searchStyle'] == "icon" && $attributes['iconOption'] == "dropdown") {
            $toggleClass .= ' bs-search-dropdown-toggle';
        }
        if($attributes['searchStyle'] == "icon" && $attributes['iconOption'] == "overlay") {
            $toggleClass .= ' bs-site-search-toggle';
        }
        if($attributes['searchStyle'] == "form") {
            $toggleClass .= ' bs-search-form-header';
        }
        if( $attributes['animation']){
            $toggleClass .= ' blockspare-block-animation ' . $attributes['animation'];
        }

        $iconClass = 'bs-search-icon--toggle blockspare-hover-item blockspare-hover-text ' . $attributes['searchIcon'];
        $alignclass = blockspare_checkalignment($attributes['align']);
        


         ?>
         <div class="<?php echo esc_attr($mainClass);?> align<?php echo esc_attr($alignclass) ?>">
         <?php echo search_style_control($blockuniqueclass ,$attributes); ?>
             <section class="<?php echo esc_attr($wrapClass);?>">
                 <div class="<?php echo esc_attr($toggleClass);?>">
                    <?php if($attributes['searchStyle'] == "icon"){ ?>
                        <button class="<?php echo esc_attr($iconClass);?>">
							<span class="screen-reader-text">
								Enter Keyword
							</span>
						</button>
                        <?php if($attributes['iconOption'] == "dropdown"){ ?>
                            <div class="bs-search--toggle-dropdown">
									<div class="bs-search--toggle-dropdown-wrapper">
                                        <?php echo blockspare_search_html($attributes);?>  
									</div>
								</div>
                        <?php } ?>
                    <?php } ?>
                    <?php if($attributes['searchStyle'] == "form"){ ?>
                        <div class="bs-search-form--wrapper">
                            <?php echo blockspare_search_html($attributes);?>
						</div>
                    <?php } ?>
                 </div>
                 <?php if($attributes['searchStyle'] == "icon" && $attributes['iconOption'] == "overlay"){ ?>
                        <div class="bs-search--toggle">
                            <div class="bs-search-toggle--wrapper">
                                <?php echo blockspare_search_html($attributes);?>
                            </div>
                            <button class="bs--site-search-close fas fa-times">
                                <span class="screen-reader-text">Close</span>
                            </button>
                        </div>
                    <?php } ?>
            </section>
        </div>

    <?php
       
        return  ob_get_clean();
    }


    function blockspare_search_html($attributes){
        ob_start();
        $btnIconClass = 'btn-bs-search-form';
        if($attributes['buttonType'] == "icon") {
            $btnIconClass .= ' ' . $attributes['buttonIcon'];
        }
        ?>
        <div
            class="bs--search-sidebar-wrapper"
            aria-expanded="false"
            role="form"
        >
            <form role="search" action="<?php echo home_url( '/' ); ?>" method="get" class="search-form site-search-form blockspare-hover-item">
                <span class="screen-reader-text">Search for:</span>

                <input
                    type="text"
                    class="search-field site-search-field"
                    placeholder="<?php echo esc_attr($attributes['placeholder']);?>"
                    name="s"
                />
                <button
                    type="submit"
                    class="<?php echo esc_attr($btnIconClass);?>"
                >
                    <?php if($attributes['buttonType'] == "icon"){ ?>
                        <span class="screen-reader-text">Search</span>
                    <?php } ?>
                    <?php if($attributes['buttonType'] == "text"){ ?>
                        <?php echo $attributes['buttonLabel'] != "" ?  $attributes['buttonLabel'] : "Search";?>
                    <?php } ?>
                </button>
            </form>
        </div>


    <?php return ob_get_clean();}
    
    /**
     * Registers the post grid block on server
     */

    if(!function_exists('blockspare_search_from_init')){
    function blockspare_search_from_init() {
            if (!function_exists('register_block_type')) {
                return;
            }
        
        
            ob_start();
            include BLOCKSPARE_PLUGIN_DIR . 'inc/other-block/search/block.json';
            $metadata = json_decode(ob_get_clean(), true);
            
            /* Block attributes */
            register_block_type(
                'blockspare/search',
                array(
                    'attributes' =>$metadata['attributes'],
                    'render_callback' => 'blockspare_search_from_render',
                )
            );
        }
        
        add_action('init', 'blockspare_search_from_init');
    }
    
    
    
    
}
    
if(!function_exists('search_style_control')){
    function search_style_control($blockuniqueclass ,$attributes)
    {
        $block_content = '';
        $block_content .= '<style type="text/css">';

        //icon font size
        $block_content .= ' .' . $blockuniqueclass . ' .bs-search-wrapper .bs-search-icon--toggle{
            font-size:' . $attributes['iconSize'] . 'px;
        }';

        //search form bordar radius
        $block_content .= ' .' . $blockuniqueclass . ' .bs-search-wrapper .site-search-form{
            border-radius:' . $attributes['borderRadius'] . 'px;
        }';

        //search form height
        $block_content .= ' .' . $blockuniqueclass . ' .bs-search-wrapper .bs-search-form-header .site-search-field{
            height:' . $attributes['height'] . 'px;
        }';

        //search form background color
        $block_content .= ' .' . $blockuniqueclass . ' .bs-search-wrapper .bs--search-sidebar-wrapper .site-search-form{
            background-color:' . $attributes['bgColor'] . ';
        }';

        //search button background color
        if(isset($attributes['buttonBgColor'])){
            $block_content .= ' .' . $blockuniqueclass . ' .bs-search-wrapper .bs--search-sidebar-wrapper .btn-bs-search-form{
                background-color:' . $attributes['buttonBgColor'] . ';
            }';
        }

        //placeholder color
        if(isset($attributes['placeholderTextColor'])){
            $block_content .= ' .' . $blockuniqueclass . ' .bs-search-wrapper .site-search-field::placeholder{
                color:' . $attributes['placeholderTextColor'] . ';
            }';
        }

        //input color
        if(isset($attributes['inputTextColor'])){
            $block_content .= ' .' . $blockuniqueclass . ' .bs-search-wrapper .site-search-field{
                color:' . $attributes['inputTextColor'] . ';
            }';
        }

        //close icon color
        if(isset($attributes['closeIconColor'])){
            $block_content .= ' .' . $blockuniqueclass . ' .bs-search-wrapper .bs--site-search-close{
                color:' . $attributes['closeIconColor'] . ';
            }';
        }

        //icon color
        if($attributes['searchStyle'] == "icon" || $attributes['buttonType'] == "icon") {
            if(isset($attributes['iconColor'])){
                $block_content .= ' .' . $blockuniqueclass . ' .bs-search-wrapper .bs-search-icon--toggle{
                    color:' . $attributes['iconColor'] . ';
                }';
            }
        }
        if($attributes['searchStyle'] == "icon" || $attributes['buttonType'] == "icon") {
            if(isset($attributes['formIconColor'])){
                $block_content .= ' .' . $blockuniqueclass . ' .bs-search-wrapper .btn-bs-search-form::before{
                    color:' . $attributes['formIconColor'] . ';
                }';
            }
        }

        //button text color
        if($attributes['buttonType'] == "text") {
            if(isset($attributes['buttonTextColor'])){
                $block_content .= ' .' . $blockuniqueclass . ' .bs-search-wrapper .bs--search-sidebar-wrapper .btn-bs-search-form{
                    color:' . $attributes['buttonTextColor'] . ';
                }';
            }
        }

        //toogle icon Gaps
        $block_content .= ' .' . $blockuniqueclass . ' .bs-search-wrapper .bs-search-icon--toggle{
            padding-top:' . $attributes['toggleIconPaddingTop'] . 'px;
            padding-right:' . $attributes['toggleIconPaddingRight'] . 'px;
            padding-bottom:' . $attributes['toggleIconPaddingBottom'] . 'px;
            padding-left:' . $attributes['toggleIconPaddingLeft'] . 'px;
        }';

        //search form Gaps
        $block_content .= ' .' . $blockuniqueclass . ' .bs-search-wrapper .bs--search-sidebar-wrapper .site-search-form{
            padding-top:' . $attributes['searchFormPaddingTop'] . 'px;
            padding-right:' . $attributes['searchFormPaddingRight'] . 'px;
            padding-bottom:' . $attributes['searchFormPaddingBottom'] . 'px;
            padding-left:' . $attributes['searchFormPaddingLeft'] . 'px;
        }';

        //Block Gaps
        $block_content .= ' .' . $blockuniqueclass . ' .bs-search-wrapper{
            margin-top:' . $attributes['marginTop'] . 'px;
            margin-right:' . $attributes['marginRight'] . 'px;
            margin-bottom:' . $attributes['marginBottom'] . 'px;
            margin-left:' . $attributes['marginLeft'] . 'px;
            padding-top:' . $attributes['paddingTop'] . 'px;
            padding-right:' . $attributes['paddingRight'] . 'px;
            padding-bottom:' . $attributes['paddingBottom'] . 'px;
            padding-left:' . $attributes['paddingLeft'] . 'px;
        }';

        //search input gaps
        $block_content .= ' .' . $blockuniqueclass . ' .bs-search-wrapper .site-search-form .site-search-field{
            padding-right:' . $attributes['searchInputPaddingRight'] . 'px;
            padding-left:' . $attributes['searchInputPaddingLeft'] . 'px;
        }';

        //Font Settings
        
        $block_content .= ' .' . $blockuniqueclass . ' .bs-search-wrapper .site-search-field::placeholder{
            font-size: ' . $attributes['placeholderFontSize'] . $attributes['placeholderFontSizeType'] . ';
            '.bscheckFontfamily($attributes['placeholderFontFamily']).';
            '.bscheckFontfamilyWeight($attributes['placeholderFontWeight']).';
            
        }';

        $block_content .= ' .' . $blockuniqueclass . ' .bs-search-wrapper .site-search-field{
            font-size: ' . $attributes['inputFontSize'] . $attributes['inputFontSizeType'] . ';
            '.bscheckFontfamily($attributes['inputFontFamily']).';
            '.bscheckFontfamilyWeight($attributes['inputFontWeight']).';
        }';

        $block_content .= ' .' . $blockuniqueclass . ' .bs-search-wrapper .bs--search-sidebar-wrapper .btn-bs-search-form:not(.fas){
            font-size: ' . $attributes['searchFontSize'] . $attributes['searchFontSizeType'] . ';
            '.bscheckFontfamily($attributes['searchFontFamily']).';
            '.bscheckFontfamilyWeight($attributes['searchFontWeight']).';
        }';
    
        $block_content .= '@media (max-width: 1025px) { ';
            $block_content .= ' .' . $blockuniqueclass . ' .bs-search-wrapper .site-search-field::placeholder{
                font-size: ' . $attributes['placeholderFontSizeTablet'] . $attributes['placeholderFontSizeType'] . ';
            }';
    
            $block_content .= ' .' . $blockuniqueclass . ' .bs-search-wrapper .site-search-field{
                font-size: ' . $attributes['inputFontSizeTablet'] . $attributes['inputFontSizeType'] . ';
            }';
    
            $block_content .= ' .' . $blockuniqueclass . ' .bs-search-wrapper .bs--search-sidebar-wrapper .btn-bs-search-form:not(.fas){
                font-size: ' . $attributes['searchFontSizeTablet'] . $attributes['searchFontSizeType'] . ';
            }';
        $block_content .= '}';
        
        $block_content .= '@media (max-width: 767px) { ';
            $block_content .= ' .' . $blockuniqueclass . ' .bs-search-wrapper .site-search-field::placeholder{
                font-size: ' . $attributes['placeholderFontSizeMobile'] . $attributes['placeholderFontSizeType'] . ';
            }';
    
            $block_content .= ' .' . $blockuniqueclass . ' .bs-search-wrapper .site-search-field{
                font-size: ' . $attributes['inputFontSizeMobile'] . $attributes['inputFontSizeType'] . ';
            }';
    
            $block_content .= ' .' . $blockuniqueclass . ' .bs-search-wrapper .bs--search-sidebar-wrapper .btn-bs-search-form:not(.fas){
                font-size: ' . $attributes['searchFontSizeMobile'] . $attributes['searchFontSizeType'] . ';
            }';
        $block_content .= '}';


        $block_content .= '</style>';
        return $block_content;
    }
}