<?php 

if ( ! class_exists( 'Blockspare_Design_Image_Importer' ) ) :

    class Blockspare_Design_Image_Importer{

        private static $instance;

        private $already_imported_ids = array();

        public static function get_instance() {
			if ( ! isset( self::$instance ) ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

        public function __construct() {

			if ( ! function_exists( 'WP_Filesystem' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}
            if ( ! function_exists( 'wp_crop_image' ) ) {
                include( ABSPATH . 'wp-admin/includes/image.php' );
              }

			WP_Filesystem();
		}

        public function blockspare_process( $attachments ) {

			$downloaded_images = array();

			foreach ( $attachments as $key => $attachment ) {
				$downloaded_images[] = $this->import( $attachment );
			}

			return $downloaded_images;
		}

        public function blockspare_get_hash_image( $attachment_url ) {
			return sha1( $attachment_url );
		}

        private function blockspare_get_saved_image( $attachment ) {

			global $wpdb;
			$post_id = $wpdb->get_var( $wpdb->prepare( 'SELECT `post_id` FROM `' . $wpdb->postmeta . '` WHERE `meta_key` = \'blockspare_templates_image_hash\' AND `meta_value` = %s;', $this->blockspare_get_hash_image( $attachment['url'] ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

			if ( empty( $post_id ) ) {
				$filename = basename( $attachment['url'] );

				$post_id = $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value LIKE %s", '%/' . $filename . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				
			}

			if ( $post_id ) {
				$new_attachment               = array(
					'id'  => $post_id,
					'url' => wp_get_attachment_url( $post_id ),
				);
				$this->already_imported_ids[] = $post_id;

				return array(
					'status'     => true,
					'attachment' => $new_attachment,
				);
			}

			return array(
				'status'     => false,
				'attachment' => $attachment,
			);
		}

        public function blockspare_import( $attachment ) {

            $saved_image = $this->blockspare_get_saved_image( $attachment );
            if ( $saved_image['status'] ) {
				return $saved_image['attachment'];
			}

			$file_content = wp_remote_retrieve_body(
				wp_safe_remote_get(
					$attachment['url'],
					array(
						'timeout'   => '60',
						'sslverify' => false,
					)
				)
			);

			
			if ( empty( $file_content ) ) {
				
				return $attachment;
			}

			$filename = basename( $attachment['url'] );

			$upload = wp_upload_bits( $filename, null, $file_content );
            $post = array(
				'post_title' => $filename,
				'guid'       => $upload['url'],
			);

            $info = wp_check_filetype( $upload['file'] );
			if ( $info ) {
				$post['post_mime_type'] = $info['type'];
			} else {
				// For now just return the origin attachment.
				return $attachment;
			}

            $post_id = wp_insert_attachment( $post, $upload['file'] );
            apply_filters('wp_handle_upload', array('file' => $file_path, 'url' => $file_url, 'type' => $file_type), 'upload');

            $metadata = wp_generate_attachment_metadata( $post_id, $upload['file'] );
			wp_update_attachment_metadata(
				$post_id,
                $metadata
				
			);
			update_post_meta( $post_id, 'blockspare_templates_image_hash', $this->blockspare_get_hash_image( $attachment['url'] ) );

			$new_attachment = array(
				'id'  => $post_id,
				'url' => $upload['url'],
			);

            $this->already_imported_ids[] = $post_id;

			return $new_attachment;
		}
    }

    Blockspare_Design_Image_Importer::get_instance();
endif;