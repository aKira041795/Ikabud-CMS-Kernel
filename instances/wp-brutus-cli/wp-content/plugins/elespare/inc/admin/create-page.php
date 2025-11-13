<?php
use Elementor\Core\Base\Document;
use Elementor\Core\Common\Modules\Ajax\Module as Ajax;
use Elementor\Core\DocumentTypes\Page;
use Elementor\Core\DocumentTypes\Post;
use Elementor\Plugin;
use Elementor\TemplateLibrary\Source_Local;
use Elementor\Utils;

if (!class_exists('Elespare_create_page')) {
    class Elespare_create_page {
        public function __construct() {
            add_action('init', [$this, 'create_page_from_url']);
            add_action('admin_enqueue_scripts',[$this,'elespare_load_scripts']);
        }

        public function elespare_load_scripts(){
            wp_enqueue_style(
                'elespare-admindashboard',
                ELESPARE_DIR_URL . 'assets/admin/css/admin-dashboard.css',
                null,
                ELESPARE_VERSION
            );
        
        }

        public function create_page_from_url() {
            if (isset($_GET['elespare_create_block']) && isset($_GET['page_title']) && isset($_GET['action']) && $_GET['action'] === 'elementor') {
                if (!current_user_can('manage_options')) {
                    return;
                }

                $page_title = sanitize_text_field($_GET['page_title']);
                $page_content_key = sanitize_text_field($_GET['elespare_create_block']);
                $content = strtolower($page_content_key);
                $type = sanitize_text_field($_GET['type']);
                $folder_path = sanitize_text_field($_GET['folder_path']);

                $kit_folder_path = '/';
                if (!empty($folder_path)) {
                    $kit_folder_path = '/' . $folder_path . '/';
                }

                $local_file_path = ELESPARE_DIR_PATH . 'template-kits/' . $type . $kit_folder_path . $content . '.json';
               
                
                if (file_exists($local_file_path)) {
                    $data = json_decode(Utils::file_get_contents($local_file_path), true);

                    if ($data && isset($data['content'])) {
                        $tmpl = [
                            "status" => 'success',
                            "code" => 200,
                            "data" => [
                                "template" => [
                                    "content" => $data['content']
                                ]
                            ]
                        ];

                        $content = $this->elspare_process_import_ids($tmpl);
                        $content = $this->elspare_process_import_content($tmpl, 'on_import');

                       
                        $new_page = array(
                            'post_title'    => $page_title, // Title of the new page
                            'post_status'   => 'publish',   // Status of the new page (publish, draft, etc.)
                            'post_type'     => 'page',      // Type of the new post (page, post, etc.)
                            'meta_input'    => array(       // Meta input for additional data
                                '_elementor_data' => wp_json_encode($content['data']['template']['content']), // Elementor content
                                // 'page_settings' => array(
                                //     'layout' => 'full_width', // Page layout setting
                                // ),
                                '_elementor_template_type' => 'wp-page', // Template type for Elementor
                                '_elementor_edit_mode'     => 'builder', // Elementor edit mode
                                '_elementor_version'     => ELEMENTOR_VERSION, // Elementor edit mode
                                '_wp_page_template'     => 'elementor_header_footer', // Elementor edit mode
                            ),
                        );
                        
                        // Insert the post into the database
                        $new_page_id = wp_insert_post($new_page);

                        if (!is_wp_error($new_page_id)) {
                            // Page created, redirect to Elementor editor
                            $elementor_url = admin_url('post.php?post=' . $new_page_id . '&action=elementor');
                            wp_redirect($elementor_url);
                            exit;
                        } else {
                            // Handle error
                            error_log('Failed to create new page: ' . $new_page_id->get_error_message());
                        }
                    } else {
                        error_log('Failed to decode JSON content.');
                    }
                } else {
                    error_log('File does not exist: ' . $local_file_path);
                }
            }
        }

        protected function elspare_process_import_ids($content) {
            return \Elementor\Plugin::$instance->db->iterate_data($content, function ($element) {
                $element['id'] = \Elementor\Utils::generate_random_string();
                return $element;
            });
        }

        protected function elspare_process_import_content($content, $method) {
            return \Elementor\Plugin::$instance->db->iterate_data($content, function ($element_data) use ($method) {
                $element = \Elementor\Plugin::$instance->elements_manager->create_element_instance($element_data);

                if (!$element) {
                    return null;
                }

                return $this->elspare_process_import_element($element, $method);
            });
        }

        protected function elspare_process_import_element($element, $method) {
            $element_data = $element->get_data();
            if (method_exists($element, $method)) {
                $element_data = $element->{$method}($element_data);
            }

            foreach ($element->get_controls() as $control) {
                $control_class = \Elementor\Plugin::$instance->controls_manager->get_control($control['type']);
                if (!$control_class) {
                    continue;
                }
                if (method_exists($control_class, $method)) {
                    $element_data['settings'][$control['name']] = $control_class->{$method}($element->get_settings($control['name']), $control);
                }
            }

            return $element_data;
        }
    }
}

new Elespare_create_page();
