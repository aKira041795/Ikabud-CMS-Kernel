<?php
if (!function_exists('blockspare_get_cat_tax_tags')) {
    function blockspare_get_cat_tax_tags($taxType, $post_id, $post_type = '')
    {


        if ('post' == $post_type) {

            if($taxType == 'category' || $taxType == ''){
                $categories_list = get_the_category_list(' ', '', $post_id);
                if ($categories_list) {
                    /* translators: 1: list of categories. */
                    printf(esc_html__('%1$s', 'blockspare'), $categories_list); // WPCS: XSS OK.
                }
            }
            else if($taxType == 'post_tag' ){
                $taxonomy = $taxType; // this is the name of the taxonomy
          
                    $terms = get_the_terms($post_id,$taxonomy);
           
                    if($terms){
                    foreach ( $terms as $term_item ) {?>
                    <a href="" class="category tag" ><?php echo $term_item->name; ?></a> 
                    <?php }
                    
                }   
            }else{
                $taxonomy = $taxType;
                $terms = get_the_terms($post_id,$taxonomy);
           
                    if($terms){
                    foreach ( $terms as $term_item ) {?>
                    <a href="" class="category tag" ><?php echo $term_item->name; ?></a> 
                    <?php }
                    
                }  
            }
        } else {

            $taxonomies = get_object_taxonomies($post_type, 'objects');


            foreach ($taxonomies as $term_slug => $terms) {
                if (!$terms->public || !$terms->show_ui) {
                    continue;
                }
                $terms = get_the_terms($post_id, $term_slug);
                if ($terms) {
                    foreach ($terms as $term_key => $term_item) { ?>
                        <a href="" class="category tag"><?php echo $term_item->name; ?></a>
<?php }
                }
            }
        }
    }
}
