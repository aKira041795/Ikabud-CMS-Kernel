<?php 
use Elementor\Core\Base\Document;
use Elementor\Core\Common\Modules\Ajax\Module as Ajax;
use Elementor\Core\DocumentTypes\Page;
use Elementor\Core\DocumentTypes\Post;
use Elementor\Plugin;
use Elementor\TemplateLibrary\Source_Local;
use Elementor\Utils;
if(!class_exists('Elespare_RestApi_Request')){

    class Elespare_RestApi_Request{
    private $namespace;
    private $query_base;
        public function __construct() {
            $this->namespace = 'elespare/v1';
            $this->query_base = 'template-lists';
        }

        public function elespare_register_routes() {
            register_rest_route(
                'elespare/v1','template-lists',
                array(
                    array(
                        'methods' => \WP_REST_Server::READABLE,
                        'callback'            => array( $this, 'elespare_get_demo_list_items' ),
                        'permission_callback' => function () {
                        return true;
                    },
                    ),
                )
            );

            register_rest_route(
                'elespare/v1','import-template',
                array(
                    array(
                        'methods' => \WP_REST_Server::EDITABLE,
                        'callback'            => array( $this, 'elespare_import_process' ),
                        'permission_callback' =>  array( $this, 'elspare_permission_check' ),
                    ),
                )
            );
        }

        public function elspare_permission_check( $request ) {
			return current_user_can('import');
		}


        public function elespare_get_demo_list_items($request){
           
          
            $params = $request->get_params();
            $nav = $params['nav'];
            if($nav=='home'){
                $nav ='homepage';
            }
            
            $config_file_url = ELESPARE_DIR_URL."template-kits/config.json";
            $response = wp_remote_get($config_file_url);
            $demodataparse = wp_remote_retrieve_body($response);
            $datajson = json_decode($demodataparse, true);
            
            $demodata = array();
            $blocks = array();

            $itemsPerPage = 3;
            $page = isset($params['page']) ? intval($params['page']) : 1;
            $startIndex = ($page - 1) * $itemsPerPage;
            $endIndex = $startIndex + $itemsPerPage;
            
            if($nav =='blocks'){
                foreach ($datajson['blocks'] as $datalist) {
                    if (isset($datalist['type']) && $datalist['type'] === $nav) {
                        $blocks[] = $datalist;
                    }
                }
            }else{
                foreach ($datajson['data'] as $datalist) {
                    if (isset($datalist['type']) && $datalist['type'] === $nav) {
                        $demodata[] = $datalist;
                    }
                }
            }

        
            
            return new WP_REST_Response([
                'status' => 200,
                'data'=>$demodata,
                'blocks'=>$blocks,
                'page' => $page,
                'total_pages' => ceil(count($datajson['data']) / $itemsPerPage), // Calculate total pages
            ]);
            

        }

        public function elespare_import_process(\WP_REST_Request $request){
            $params = $request->get_params();
            
            $content = $params['elepsapre_templates_kit'];
            $type = $params['type'];
            $folder_path = $params['folder_path'];

            $kit_folder_path = '/';

            if(!empty($folder_path)){
                $kit_folder_path = '/'.$folder_path.'/';
            }

          

          
            $local_file_path = ELESPARE_DIR_PATH .'template-kits/'.$type.$kit_folder_path.$content.'.json';
            
            
            $data = json_decode( Utils::file_get_contents( $local_file_path ), true );

           
            
                $tmpl = [
                    "status" => 'success',
                    "code" => 200,
                    "data" => [
                        "template" => [
                            "content" => $data['content']
                        ]
                    ]
                ];
               
            
                $content = $this->process_import_ids($tmpl);
                $content = $this->process_import_content($tmpl, 'on_import');

                
                
                print_r(\wp_json_encode($content));

		        //wp_die();
               
                    // return wp_insert_post(
                    //     array(
                    //         'post_title'    => 'Banner one',
                    //         'post_status'   => 'publish',
                    //         'post_type'     => 'page',
                    //         'page_template' => 'banne-one',
                    //         'meta_input'    => array_merge(
                    //             array(
                    //                 '_elementor_data'          =>  $content,
                    //                 '_elementor_template_type' => 'wp-page',
                    //                 '_elementor_edit_mode'     => 'builder',
                    //             ),
                    //             array()
                    //         ),
                    //     )
                    // );
                
                
        }

        protected function process_import_ids($content)
        {
            return \Elementor\Plugin::$instance->db->iterate_data($content, function ($element)
            {
                $element['id'] = \Elementor\Utils::generate_random_string();
                return $element;
            });
        }
    
        protected function process_import_content($content, $method)
        {
            
            return \Elementor\Plugin::$instance->db->iterate_data($content, function ($element_data) use ($method)
            {
                $element = \Elementor\Plugin::$instance->elements_manager->create_element_instance($element_data);
    
                if (!$element)
                {
                    return null;
                }
    
                $r = $this->process_import_element($element, $method);
                
                return $r;
            });
        }
        
        protected function process_import_element($element, $method)
        {
            $element_data = $element->get_data();
            if (method_exists($element, $method))
            {
                $element_data = $element->{$method}($element_data);
            }
            foreach ($element->get_controls() as $control)
            {
                $control_class = \ELementor\Plugin::$instance
                    ->controls_manager
                    ->get_control($control['type']);
                if (!$control_class)
                {
                    return $element_data;
                }
                if (method_exists($control_class, $method))
                {
                    $element_data['settings'][$control['name']] = $control_class->{$method}($element->get_settings($control['name']) , $control);
                }
            }
            return $element_data;
        }
    }
}