<?php 
if(!class_exists('BlocksapreMultiAuthorForFrontend')){
    class BlocksapreMultiAuthorForFrontend{
       
    function blockspare_front_by_author($post_id,$authorIcon,$xauthor_id){
        
        if(class_exists('WP_Post_Author')){
            
            
            $awpa_post_authors = get_post_meta($post_id, 'wpma_author');
            $enable_author_metabox_for_post = get_option('awpa_author_metabox_integration');
            $multiauthor_settings = false;
            if($enable_author_metabox_for_post && $enable_author_metabox_for_post['enable_author_metabox']==true){
                $multiauthor_settings = true;
            }
            
            if(isset($awpa_post_authors) && !empty($awpa_post_authors) && $multiauthor_settings == true){
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

                     $this->blockspare_front_author_list($authorIcon,$key,$awpa_post_authors,$post_id,$author_id,$author_type);
                    
                }
            }else{
                $author_id = $xauthor_id;
                
                ?>
                <a class="blockspare-posts-block-text-link" href="<?php echo esc_url(get_author_posts_url($xauthor_id));?>"
                                        itemprop="url" rel="author">
                    <span itemprop="name"><i class="<?php echo $authorIcon;?>"></i><?php echo esc_html(get_the_author_meta('display_name', $xauthor_id));?></span>
                </a>
                <?php

               
            }
        }else{
            $author_id = $xauthor_id;?>
                <a class="blockspare-posts-block-text-link" href="<?php echo esc_url(get_author_posts_url($xauthor_id));?>" itemprop="url" rel="author">
                    <span itemprop="name"><i class="<?php echo $authorIcon;?>"></i><?php echo esc_html(get_the_author_meta('display_name', $xauthor_id));?></span>
                </a>
       <?php }

    }




function blockspare_front_author_list($authorIcon, $key, $awpa_post_authors, $post_id='',$author_id='',$author_type=''){
        
             if($author_type == 'default'){ 
                 $default_author_id = get_post_field('post_author', $post_id);
                 $author_name = get_userdata( $author_id);
                ?>

                <a class="blockspare-posts-block-text-link" href="<?php echo esc_url(get_author_posts_url($author_id));?>" itemprop="url" rel="author">
                    <span itemprop="name"><?php if($key<1){ ?><i class="<?php echo $authorIcon;?>"></i><?php } ?><?php echo esc_html(get_the_author_meta('display_name', $author_id));?><?php if( $key != ( count( $awpa_post_authors ) - 1 ) ){ echo ","; } ?></span>
                </a>
                <?php 
                } 
                if($author_type == 'guest'){
                    $wp_amulti_authors = new WPAMultiAuthors();
                    $guest_user_data = $wp_amulti_authors->get_guest_by_id($author_id);?>
                     
                    <a class="blockspare-posts-block-text-link">
                        <span itemprop="name"><?php if($key<1){ ?><i class="<?php echo esc_attr($authorIcon);?>"></i><?php } ?><?php echo  esc_html($guest_user_data->display_name);?><?php if( $key != ( count( $awpa_post_authors ) - 1 ) ){ echo ","; } ?></span>
                    </a>
               
                <?php } ?>
                
            <?php 
        }
    
    }
}