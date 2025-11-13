<?php
/**
 * Register layouts and sections for the Layout block.
 *
 * @package Blockspare
 */

namespace Blockspare\Layouts;
include BLOCKSPARE_PLUGIN_DIR .'inc/template-library/init.php';
add_action( 'plugins_loaded', __NAMESPACE__ . '\register_components', 11 );
/**
 * Registers section and layout components.
 *
 * @since 2.0
 */
function register_components() {
	$blocks_lists = array();

	$templates = apply_filters( 'blockspare_template_library', $blocks_lists );
	$get_templates = get_posts(array('post_type'=>'bs_templates','posts_per_page'=>-1));
	if(!empty($get_templates)){
		foreach($get_templates as $res){
			//$get_meta = get_post_meta($res->ID,'bs_template_category');
			$array=array(
			'type'     => 'templates',
			//'item'     =>($get_meta)?$get_meta:$res->post_title,
			'item'     =>[$res->post_title],
			'key'      => 'bs_template_'.$res->ID,
			'name'     =>$res->post_title,
			'blockLink'=> get_the_permalink($res->ID),
			'content'  =>$res->post_content
		);

		

		array_push($templates,$array);

		}
	}

	

	if(!empty($templates)){
		foreach($templates as $temp){
			
		blockspare_register_layout_component($temp);
		}
	}

}




