<?php

defined( 'ABSPATH' ) || exit;

class Elespare_Metabox {
    private static $instance;
    public static function instance() {
		if ( ! isset( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}
    
    public function __construct() {
		add_action( 'add_meta_boxes', array( $this, 'pagesetting_meta_box' ) );
        add_action( 'save_post', array( $this, 'pagesetting_save' ) );
	}


    public function pagesetting_meta_box() {
		add_meta_box( 'elespare_settings', 'Template Settings', array( $this, 'elespare_setting_output' ), 'elespare_builder', 'side', 'high' );
	}

    
    
    
    

    public function elespare_setting_output($post){
        $types         = elespare_type_builder();
		$type          = get_post_meta( $post->ID, 'ele_hf_type', true );
		$display       = get_post_meta( $post->ID, 'ele_hf_display', true );
		$posts         = get_post_meta( $post->ID, 'ele_hf_post', true );
		$post_type     = get_post_meta( $post->ID, 'ele_hf_post_type', true );
		$select_footer = '';
		$select_header = '';

		wp_nonce_field( 'elespare_hf_action', 'elespare_hf' );
    
    ?>
    <div class="form-meta-footer">

			<!-- Choose Header or Footer -->
			<div class="input-wrapper">
                <h4>
				<label for="container"><?php echo esc_html__( 'Type of Template', 'slespare' ); ?></label>
                </h4>
				<select name="ele_type" id="container">
					<?php foreach ( $types as $key => $val ) : ?>
						<?php $selected = ( $key === $type ) ? 'selected' : ''; ?>
						<option value="<?php echo esc_attr( $key ); ?>" <?php echo esc_attr( $selected ); ?>><?php echo esc_html( $val ); ?></option>
					<?php endforeach ?>
				</select>
			</div>

			<?php
			if ( 'sub_menu' !== $type ) {
				$this->ele_hf_display( $post );
			}

			?>

		</div>
		<?php
    }

    public function pagesetting_save($post_id){

        
        
        $nonce_name   = ( array_key_exists( 'elespare_hf', $_POST ) ) ? sanitize_text_field( $_POST['elespare_hf'] ) : '';
		$nonce_action = 'elespare_hf_action';

    

		if ( ! isset( $nonce_name ) ) {
			return;
		}
        
		// Check if a nonce is valid.
		if ( ! wp_verify_nonce( $nonce_name, $nonce_action ) ) {
			return;
		}

		// Check if the user has permissions to save data.
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// Check if it's not an autosave.
		if ( wp_is_post_autosave( $post_id ) ) {
			return;
		}

		// Check if it's not a revision.
		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		// Type of Template Builder
		$type = sanitize_text_field( $_POST['ele_type'] );
        
		if ( empty( $type ) ) {
			$type = 'header';
		}

		update_post_meta(
			$post_id,
			'ele_hf_type',
			$type
		);

        
		if ( 'sub_menu' !== $type ) {
            
            
            

			// Display On
			$display = sanitize_text_field( $_POST['ele_hf_display'] );
            
            //var_dump($display);die;
			update_post_meta(
				$post_id,
				'ele_hf_display',
				$display
			);

			// Do Not Display On
			$no_display = sanitize_text_field( $_POST['ele_hf_no_display'] );

			update_post_meta(
				$post_id,
				'ele_hf_no_display',
				$no_display
			);

			// Post
			if ( array_key_exists( 'ele_hf_post', $_POST ) ) {
				$post = sanitize_text_field( $_POST['ele_hf_post'] );

				update_post_meta(
					$post_id,
					'ele_hf_post',
					$post
				);
			} else {
				update_post_meta(
					$post_id,
					'ele_hf_post',
					''
				);
			}

			// Ex Post
			if ( array_key_exists( 'ele_hf_ex_post', $_POST ) ) {
				$ex_post = sanitize_text_field( $_POST['ele_hf_ex_post'] );

				update_post_meta(
					$post_id,
					'ele_hf_ex_post',
					$ex_post
				);
			} else {
				update_post_meta(
					$post_id,
					'ele_hf_ex_post',
					''
				);
			}

			// Ex Post Type
			if ( array_key_exists( 'ele_hf_ex_post_type', $_POST ) ) {
				$ex_post_type = sanitize_text_field( $_POST['ele_hf_ex_post_type'] );

				update_post_meta(
					$post_id,
					'ele_hf_ex_post_type',
					$ex_post_type
				);
			} else {
				update_post_meta(
					$post_id,
					'ele_hf_ex_post_type',
					''
				);
			}

			// Post Type
			if ( array_key_exists( 'ele_hf_post_type', $_POST ) ) {
				$post_type = sanitize_text_field( $_POST['ele_hf_post_type'] );
                

				update_post_meta(
					$post_id,
					'ele_hf_post_type',
					$post_type
				);
			} else {
				update_post_meta(
					$post_id,
					'ele_hf_post_type',
					''
				);
			}
		}

    }

    public function ele_hf_display( $post ) {

		$options      = elespare_pt_support();
		$display      = get_post_meta( $post->ID, 'ele_hf_display', true );
		$no_display   = get_post_meta( $post->ID, 'ele_hf_no_display', true );
		$post_id      = get_post_meta( $post->ID, 'ele_hf_post', true );
		$post_type    = get_post_meta( $post->ID, 'ele_hf_post_type', true );
		$ex_post_id   = get_post_meta( $post->ID, 'ele_hf_ex_post', true );
		$ex_post_type = get_post_meta( $post->ID, 'ele_hf_ex_post_type', true );
		$list_post    = $post_id;
		$list_ex_post = $ex_post_id;
		if ( 'all' !== $post_id ) {
			$list_post = explode( ',', $post_id );
		}

		if ( 'all' !== $ex_post_id ) {
			$list_ex_post = explode( ',', $ex_post_id );
		}

		?>
			<div class="input-wrapper">
				<div class="condition-group display--on">
					<div class="parent-item">
                        <h4>
						<label><?php echo esc_html__( 'Display On', 'elespare' ); ?></label>
                        </h4>
						<select name="ele_hf_display" class="display-on">
							<?php
							foreach ( $options as $key => $option ) :
								$selected = ( $key == $display ) ? 'selected' : ''; // phpcs:ignore
								?>
								<option value="<?php echo esc_attr( $key ); ?>" <?php echo esc_attr( $selected ); ?>><?php echo esc_html( $option ); ?></option>
							<?php endforeach ?>
						</select>
					</div>

					<div class="child-item">
						<div class="input-item-wrapper">
							<?php
							if ( ! empty( $post_id ) && ! empty( $post_type ) ) :

								?>
							<div class="elespare-section-select-post <?php echo ( is_string( $list_post ) ? 'select-all' : 'render--post has-option' ); ?>">

								<span class="elespare-select-all-post<?php echo ( is_string( $list_post ) ? '' : ' hidden' ); ?>">
									<span class="elespare-select-all"><?php echo esc_html__( 'All', 'elespare' ); ?></span>
									<span class="elespare-arrow ion-chevron-down"></span>
								</span>

								<div class="elespare-section-render--post <?php echo ( is_string( $list_post ) ? 'hidden' : '' ); ?>">
									<div class="elespare-auto-complete-field">
										<?php
										if ( is_array( $list_post ) ) :

											foreach ( $list_post as $id ) :
												$id = (int) $id;
												?>

												<span class="elespare-auto-complete-key">
													<span class="elespare-title"><?php echo esc_html( get_the_title( $id ) ); ?></span>
													<span class="btn-elespare-auto-complete-delete ion-close" data-item="<?php echo esc_attr( $id ); ?>"></span>
												</span>
												<?php
											endforeach;
										endif;
										?>
										<input type="text" class="elespare--hf-post-name" aria-autocomplete="list" size="1">
									</div>
								</div>

							</div>
							<input type="hidden" name="ele_post" value="<?php echo esc_html( $post_id ); ?>">
							<input type="hidden" name="ele_post_type" value="<?php echo esc_attr( $post_type ); ?>" class="ele-hf-post-type">
							<div class="elespare-data"></div>
								<?php
							endif;
							?>
						</div>
					</div>
				</div>

				<div class="condition-group not-display">
					<div class="parent-item">
                        <h4>
						<label><?php echo esc_html__( 'Do Not Display On', 'elespare' ); ?></label>
                        </h4>
						<select name="ele_hf_no_display" class="no-display-on">
							<?php
							unset( $options['all'] );
							?>
		
							<?php
							foreach ( $options as $key => $option ) :
								$selected = ( $key == $no_display ) ? 'selected' : ''; // phpcs:ignore
								?>
								<option value="<?php echo esc_attr( $key ); ?>" <?php echo esc_attr( $selected ); ?>><?php echo esc_html( $option ); ?></option>
							<?php endforeach ?>
						</select>
					</div>

					<div class="child-item">
						<div class="input-item-wrapper">
							<?php
							if ( ! empty( $ex_post_id ) && ! empty( $ex_post_type ) ) :

								?>
							<div class="elespare-section-select-post <?php echo ( is_string( $list_ex_post ) ? 'select-all' : 'render--post has-option' ); ?>">

								<span class="elespare-select-all-post<?php echo ( is_string( $list_ex_post ) ? '' : ' hidden' ); ?>">
									<span class="elespare-select-all"><?php echo esc_html__( 'All', 'elespare' ); ?></span>
									<span class="elespare-arrow ion-chevron-down"></span>
								</span>

								<div class="elespare-section-render--post <?php echo ( is_string( $list_ex_post ) ? 'hidden' : '' ); ?>">
									<div class="elespare-auto-complete-field">
										<?php
										if ( is_array( $list_ex_post ) ) :

											foreach ( $list_ex_post as $id ) :
												$id = (int) $id;
												?>

												<span class="elespare-auto-complete-key">
													<span class="elespare-title"><?php echo esc_html( get_the_title( $id ) ); ?></span>
													<span class="btn-elespare-auto-complete-delete ion-close" data-item="<?php echo esc_attr( $id ); ?>"></span>
												</span>
												<?php
											endforeach;
										endif;
										?>
										<input type="text" class="elespare--hf-post-name" aria-autocomplete="list" size="1">
									</div>
								</div>

							</div>
							<input type="hidden" name="ele_ex_post_type" value="<?php echo esc_attr( $ex_post_type ); ?>" class="ele-hf-post-type">
							<input type="hidden" name="ele_ex_post" value="<?php echo esc_html( $ex_post_id ); ?>">
							<div class="elespare-data"></div>
								<?php
							endif;
							?>
						</div>
					</div>
				</div>
			</div>
		<?php
	}
}
Elespare_Metabox::instance();


