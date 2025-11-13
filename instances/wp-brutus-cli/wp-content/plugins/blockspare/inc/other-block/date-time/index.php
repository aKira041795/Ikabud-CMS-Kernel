<?php
if(!function_exists('blockspare_date_time_render')){
    function blockspare_date_time_render($attributes){

        ob_start();
        $unq_class = mt_rand(100000,999999);
        $blockuniqueclass = '';
        if(!empty($attributes['uniqueClass'])){
            $blockuniqueclass = $attributes['uniqueClass'];
        }else{
            $blockuniqueclass = 'blockspare-date-time-'.$unq_class;
        }

        $wrapperClass = $blockuniqueclass;
        $mainClass = $attributes['layoutOption'].' ' .$attributes['layoutType'].' '.$attributes['dateOrder'].' bs-date-time-'.$attributes['sectionAlign'].' ';
        $mainClass .= $attributes['borderRadius'];
        if($attributes['animation']){
            $mainClass .= ' blockspare-block-animation ';
        }else{
            $mainClass .=' ';
        }
        $mainClass .=$attributes['animation'].' '.$attributes['blockHoverEffect'];
      

        $separator = ',';

        $alignclass = blockspare_checkalignment($attributes['align']);

        $dateClass = 'bs-date-time bs-date-wrapper blockspare-hover-item';
        if($attributes['layoutOption'] == 'date-time-style-1') {
            $dateClass .= ' blockspare-hover-text ';
        }

        $timeClass = 'bs-date-time bs-time-wrapper blockspare-hover-item';
        if($attributes['layoutOption'] == 'date-time-style-1') {
            $timeClass .= ' blockspare-hover-text ';
        }
        
        $date_format =  date_i18n(get_option('date_format'), current_time('timestamp'));
        ?>

            <div class="<?php echo esc_attr($wrapperClass); ?> align<?php echo esc_attr($alignclass) ?>">
            <?php echo blockspare_date_time_style_control($blockuniqueclass ,$attributes);?>
                <div class="bs-date-time-widget <?php echo esc_attr($mainClass);?>">
                    <?php if($attributes['date']){ ?>
                        <div class="<?php echo esc_attr($dateClass); ?>">
                            <?php if($attributes['dateIconToggle']) { ?>
                                <div class='bs-icon-wrapper'> 
                                    <i class="bs-date-icon <?php echo esc_attr($attributes['dateIcon']);?>"></i>
                                </div>
                            <?php } ?>

                            <div class="bs-date">
                                <?php if($attributes['textBeforeDate'] !=''){ ?>
                                    <span class="bs-before-date-text">
                                       <?php echo $attributes['textBeforeDate'];?>
                                    </span>
                                <?php } ?> 
                                <span class="bs-date-text" ><?php  echo $date_format;?></span>
                                <?php if($attributes['textAfterDate'] !=''){ ?>
                                    <span class="bs-after-date-text">
                                        <?php echo $attributes['textAfterDate'];?>
                                    </span>
                                <?php } ?>    
                            </div>
                        </div>
                    <?php } ?>

                    <?php if($attributes['time']){ ?>
                            <div class="<?php echo esc_attr($timeClass); ?>" bs-fromat="<?php echo $date_format;?>" id="bs-date-time">
                                <?php if($attributes['timeIconToggle']){ ?>
                                    <div class="bs-icon-wrapper">
                                        <i class="bs-time-icon <?php echo esc_attr( $attributes['timeIcon'])?>"></i> 
                                    </div>
                                <?php } ?>
                                <span class="bs-time-text"></span>
                            </div>

                    <?php } ?>
                </div>
            </div>
    <?php
       
      return ob_get_clean();
    }
}

if(!function_exists('blockspare_date_time_init')){
    add_action('init', 'blockspare_date_time_init');
    function blockspare_date_time_init(){
        if (!function_exists('register_block_type')) {
            return;
        }

        ob_start();
            include BLOCKSPARE_PLUGIN_DIR . 'inc/other-block/date-time/block.json';
            $metadata = json_decode(ob_get_clean(), true);
            
            /* Block attributes */
            register_block_type(
                'blockspare/date-time',
                array(
                    'attributes' =>$metadata['attributes'],
                    'render_callback' => 'blockspare_date_time_render',
                )
            );

    }
}
if(!function_exists('blockspare_date_time_style_control')){
    function blockspare_date_time_style_control($blockuniqueclass ,$attributes){
        $block_content = '';
        $block_content .= '<style type="text/css">';
        
        $block_content .= ' .' . $blockuniqueclass . ' .bs-date-time-widget{
            margin-top:' . $attributes['marginTop'] . 'px;
            margin-right:' . $attributes['marginRight'] . 'px;
            margin-bottom:' . $attributes['marginBottom'] . 'px;
            margin-left:' . $attributes['marginLeft'] . 'px;
        }';

        $block_content .= ' .' . $blockuniqueclass . ' .bs-date-wrapper i.bs-date-icon{
            color:' . $attributes['dateIconColor'].';
            font-size: ' . $attributes['dateIconSize'] . $attributes['dateFontSizeType'] . ';
        }';

        $block_content .= ' .' . $blockuniqueclass . ' .bs-date-wrapper span.bs-date-text{
            color:' . $attributes['dateColor'].';
        }';

        $block_content .= ' .' . $blockuniqueclass . ' .bs-date-wrapper .bs-date .bs-before-date-text{
            color:' . $attributes['textBeforeColor'].';
        }';


        $block_content .= ' .' . $blockuniqueclass . ' .bs-date-wrapper .bs-date .bs-after-date-text{
            color:' . $attributes['textAfterColor'].';
        }';

        $block_content .= ' .' . $blockuniqueclass . ' .bs-time-wrapper i.bs-time-icon{
            color:' . $attributes['timeIconColor'].';
            font-size: ' . $attributes['timeIconSize'] . $attributes['dateFontSizeType'] . ';
        }';

        $block_content .= ' .' . $blockuniqueclass . ' .bs-time-wrapper span.bs-time-text{
            color:' . $attributes['timeColor'].';
        }';

        $block_content .= ' .' . $blockuniqueclass . ' .bs-date-time-widget:not(.date-time-style-1) .bs-time-wrapper{
            background-color:' . $attributes['bgColor'].';
        }';

        $block_content .= ' .' . $blockuniqueclass . ' .bs-date-time-widget:not(.date-time-style-1) .bs-date-wrapper{
            background-color:' . $attributes['bgColor'].';
        }';

        //Font Settings
        
        $block_content .= ' .' . $blockuniqueclass . ' .bs-date-text{
            font-size: ' . $attributes['dateFontSize'] . $attributes['dateFontSizeType'] . ';
            '.bscheckFontfamily($attributes['dateFontFamily']).';
            '.bscheckFontfamilyWeight($attributes['dateFontWeight']).';
            
        }';

        $block_content .= ' .' . $blockuniqueclass . ' .bs-before-date-text{
            font-size: ' . $attributes['beforeDateFontSize'] . $attributes['beforeDateFontSizeType'] . ';
            '.bscheckFontfamily($attributes['beforeDateFontFamily']).';
            '.bscheckFontfamilyWeight($attributes['beforeDateFontWeight']).';
        }';

        $block_content .= ' .' . $blockuniqueclass . ' .bs-after-date-text{
            font-size: ' . $attributes['afterDateFontSize'] . $attributes['afterDateFontSizeType'] . ';
            '.bscheckFontfamily($attributes['afterDateFontFamily']).';
            '.bscheckFontfamilyWeight($attributes['afterDateFontWeight']).';
            
        }';

        $block_content .= ' .' . $blockuniqueclass . ' .bs-time-text{
            font-size: ' . $attributes['timeFontSize'] . $attributes['timeFontSizeType'] . ';
            '.bscheckFontfamily($attributes['timeFontFamily']).';
            '.bscheckFontfamilyWeight($attributes['timeFontWeight']).';
        }';

    
        $block_content .= '@media (max-width: 1025px) { ';
            $block_content .= ' .' . $blockuniqueclass . ' .bs-date-text{
                font-size: ' . $attributes['dateFontSizeTablet'] . $attributes['dateFontSizeType'] . ';
            }';
    
            $block_content .= ' .' . $blockuniqueclass . ' .bs-before-date-text{
                font-size: ' . $attributes['beforeDateFontSizeTablet'] . $attributes['beforeDateFontSizeTablet'] . ';
            }';

            $block_content .= ' .' . $blockuniqueclass . ' .bs-after-date-text{
                font-size: ' . $attributes['afterDateFontSizeTablet'] . $attributes['afterDateFontSizeType'] . ';
            }';
            $block_content .= ' .' . $blockuniqueclass . ' .bs-time-text{
                font-size: ' . $attributes['timeFontSizeTablet'] . $attributes['timeFontSizeType'] . ';
            }';
        $block_content .= '}';
        
        $block_content .= '@media (max-width: 767px) { ';
            $block_content .= ' .' . $blockuniqueclass . ' .bs-date-text{
                font-size: ' . $attributes['dateFontSizeMobile'] . $attributes['dateFontSizeType'] . ';
            }';
    
            $block_content .= ' .' . $blockuniqueclass . ' .bs-before-date-text{
                font-size: ' . $attributes['beforeDateFontSizeMobile'] . $attributes['beforeDateFontSizeType'] . ';
            }';

            $block_content .= ' .' . $blockuniqueclass . ' .bs-after-date-text{
                font-size: ' . $attributes['afterDateFontSizeMobile'] . $attributes['afterDateFontSizeType'] . ';
            }';

            $block_content .= ' .' . $blockuniqueclass . ' .bs-time-text{
                font-size: ' . $attributes['timeFontSizeMobile'] . $attributes['timeFontSizeType'] . ';
            }';
        $block_content .= '}';
        

        $block_content .= '</style>';
        return $block_content;
    
        
    }
}    