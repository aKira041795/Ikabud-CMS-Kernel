<?php 
include BLOCKSPARE_BASE_DIR .'/inc/template-library/init.php';
if(!function_exists('blockspare_register_block_pattern')){
function blockspare_register_block_pattern() {
               
                //Get all blocks from template library
                $blocks_lists = array();

                $templates_lists = apply_filters( 'blockspare_template_library', $blocks_lists );

                $templates_lists = apply_filters( 'blockspare_template_library', $blocks_lists );
                $block_pattern_categories =[];
                foreach($templates_lists as $tl){
                    if($tl['content'] !== 'https://www.blockspare.com/'){
                   array_push($block_pattern_categories,$tl['item']);
                    }
                }

                foreach($block_pattern_categories as $cat_name){
                   foreach(array_unique($cat_name) as $name){
                    if ( ! WP_Block_Pattern_Categories_Registry::get_instance()->is_registered( $name ) ) {
                        register_block_pattern_category(
                            $name,
                            array( 'label' => $name )
                        );
                    }
                    }
                }

                $empty_template_array = [];
                foreach($templates_lists as $template){
                    if($template['content'] !== 'https://www.blockspare.com/'){
                    $new_template_array = [];
                    $new_template_array =  array(
                        'title'=>$template['name'],
                        'categories'=>$template['item'],
                        'content'=>$template['content']
                    );

                    array_push($empty_template_array,$new_template_array);
                }
                    
                }
                // Register pattern
                if(!empty($empty_template_array)){
                    $i=0;
                    foreach($empty_template_array as $new_template){
                        $i++;
                            register_block_pattern(
                                'blockspare/blockspare-pattern-'.$i,
                                $new_template
                                
                            );
                        }
                }
        
    }
    add_action('init','blockspare_register_block_pattern');
}
    
    