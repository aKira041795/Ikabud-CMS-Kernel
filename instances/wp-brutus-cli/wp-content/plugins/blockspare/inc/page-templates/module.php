<?php
//Add our custom template to the admin's templates dropdown
add_filter( 'theme_page_templates', 'pluginname_template_as_option', 10, 3 );
function pluginname_template_as_option( $page_templates, $theme, $post ){

    $page_templates['canvas.php'] = 'Blockspare Canvas';
    $page_templates['full-width.php'] = 'Blockspare Full-Width';

    return $page_templates;

}

//When our custom template has been chosen then display it for the page
add_filter( 'template_include', 'pluginname_load_template', 99 );
function pluginname_load_template( $template ) {

    global $post;

    if(isset($post)){
        
        $canvas_template_slug   = 'canvas.php';
        $full_width_template_slug   = 'full-width.php';
        $page_template_slug     = get_page_template_slug( $post->ID );
    
        if( $page_template_slug == $canvas_template_slug ){
            return BLOCKSPARE_PLUGIN_DIR . 'inc/page-templates/templates/' . $canvas_template_slug;
        }
    
        if( $page_template_slug == $full_width_template_slug ){
            return BLOCKSPARE_PLUGIN_DIR . 'inc/page-templates/templates/' . $full_width_template_slug;
        }    
        
    }
    return $template;

}