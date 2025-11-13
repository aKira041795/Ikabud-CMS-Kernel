<?php 

if(!class_exists('BlocksapreMultiAuthorForBackend')){
    class BlocksapreMultiAuthorForBackend{

        function blockspare_by_author($post){
            
            if(class_exists('WP_Post_Author')){
                $data = array();
                $post_id =  $post->ID;
                $awpa_post_authors = get_post_meta($post_id, 'wpma_author');
                $enable_author_metabox_for_post = get_option('awpa_author_metabox_integration');
                $multiauthor_settings = false;
                if($enable_author_metabox_for_post && $enable_author_metabox_for_post['enable_author_metabox']==true){
                    $multiauthor_settings = true;
                }
                
                if(isset($awpa_post_authors) && !empty($awpa_post_authors) && $multiauthor_settings == true){
                    $data = array();
                    foreach ($awpa_post_authors as $key=>$author_id) {
                       
                        $needle = 'guest-';
                        if (strpos($author_id, $needle) !== false) {
                            $filter_id = substr($author_id, strpos($author_id, "-") + 1);
                            $author_id = $filter_id;
                            $author_type = 'guest';
                        } else {
                            $author_id = $author_id;
                            $author_type = 'default';
                        }
    
                        $data['info'][] = $this->blockspare_author_list($post,$post_id,$author_id,$author_type, $author_avatar=false);
                        
                        
                       
                        
                    }
                }else{
                    $data['info'][] = get_the_author_meta( 'display_name', absint( $post->post_author ) );
                    

                }
                
            }else{
                $data['info'][] =  get_the_author_meta( 'display_name', absint( $post->post_author ) );

            }
            return $data;
        }
        public function blockspare_author_list($post,$post_id='',$author_id='',$author_type='',$author_avatar=false){
            ob_start();
            if($author_type == 'default'){ 
                $default_author_id = get_post_field('post_author', $post_id);
                $author_name = get_userdata( $author_id);
               ?><?php echo esc_html($author_name->display_name);?>
               
            <?php }
            if($author_type == 'guest'){
                $wp_amulti_authors = new WPAMultiAuthors();
                $guest_user_data = $wp_amulti_authors->get_guest_by_id($author_id);
                $author_data['display_name']= $guest_user_data->display_name;
                ?>
                 <?php echo  $guest_user_data->display_name;?>
                 <?php 
                
            }

            return ob_get_clean();
        }
    
                
    }
}