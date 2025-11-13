<?php

defined('ABSPATH') || exit;


class Elespare_Admin
{


	private static $instance;

	public static function instance()
	{
		if (!isset(self::$instance)) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function __construct()
	{
		$this->hooks();
	}

	public function hooks()
	{

		add_action('admin_enqueue_scripts', array($this, 'load_admin_style'));
		add_filter('manage_elespare_builder_posts_columns', array($this, 'elspare_columns_head'));
		add_action('manage_elespare_builder_posts_custom_column', array($this, 'columns_content'), 10, 2);
		add_action('admin_footer', array($this, 'elespare_lightbox'));


		add_action('wp_ajax_elespare_create_post', array($this, 'elespare_create_ele_hf_post'));
		add_action('wp_ajax_nopriv_elespare_create_post', array($this, 'elespare_create_ele_hf_post'));
	}

	public function load_admin_style()
	{

		wp_enqueue_style(
			'elespare-icons',
			ELESPARE_DIR_URL . 'assets/font/elespare-icons.css',
			null,
			ELESPARE_VERSION
		);
		wp_enqueue_style(
			'elespare-hf-admin',
			ELESPARE_DIR_URL . 'header-footer/assets/css/admin.css',
			array(),
			ELESPARE_VERSION
		);

		wp_enqueue_script(
			'elspare-hf-admin',
			ELESPARE_DIR_URL . 'header-footer/assets/js/admin.js',
			array('jquery', 'suggest'),
			ELESPARE_VERSION,
			true
		);
		$tag_validation = ['article', 'aside', 'div', 'footer', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'header', 'main', 'nav', 'p', 'section', 'span'];
		$admin_vars = array(
			'url'   => admin_url('admin-ajax.php'),
			'nonce' => wp_create_nonce('elespare_hf_nonce'),
			'edit'  => admin_url('edit.php?post_type=elespare_builder'),
			'allowed_tags' => $tag_validation
		);



		wp_localize_script(
			'elspare-hf-admin',
			'elesapreadminloco',

			$admin_vars
		);
	}

	public function elspare_columns_head($columns)
	{
		$date_column = $columns['date'];
		unset($columns['date']);
		$columns['type']      = __('Type', 'elespare');
		$columns['display'] = __('Display On', 'elespare');
		$columns['date']      = $date_column;

		return $columns;
	}

	public function columns_content($column_name, $post_id)
	{
		$type       = get_post_meta($post_id, 'ele_hf_type', true);
		$display_on = get_post_meta($post_id, 'ele_hf_display', true);
		$post_id    = get_post_meta($post_id, 'ele_hf_post', true);
		$post_type  = get_post_meta($post_id, 'ele_hf_post_type', true);

		$display    = '';
		if ('all' == $display_on) {
			$display = __('All', 'elespare');
		} elseif ('blog' == $display_on) {
			$display = __('Blog Page', 'elespare');
		} elseif ('archive' == $display_on) {
			$display = __('Archive', 'elespare');
		} elseif ('search' == $display_on) {
			$display = __('Search', 'elespare');
		} elseif ('not_found' == $display_on) {
			$display = __('404 Page', 'elespare');
		} elseif ('' == $display_on) {
			$display = __('', 'elespare');
		} else {
			if ('all' == $post_id) {
				$display = __('All', 'elespare') . $post_type;
			} else {
				$post_array = explode(',', $post_id);
				$list_title = array();
				foreach ($post_array as $id) {
					$list_title[] = get_the_title($id);
				}

				$display = implode(',', $list_title);
			}
		}
		switch ($column_name) {
			case 'shortcode':
				ob_start();
?>
				<span class="ele-hf-shortcode-col-wrap">
					<input type="text" readonly="readonly" value="[bhf id='<?php echo esc_attr($post_id); ?>' type='<?php echo esc_attr($type); ?>']" class="ele-hf-large-text code">
				</span>

			<?php

				ob_get_contents();
				break;

			case 'type':
				ob_start();
			?>
				<span class="ele-hf-type"><?php echo esc_html($type); ?></span>
			<?php
				ob_get_contents();
				break;

			case 'display':
				ob_start();
			?>
				<span class="ele-hf-type"><?php echo esc_html($display); ?></span>
			<?php
				ob_get_contents();
				break;
		}
	}

	public function elespare_lightbox()
	{
		$object     = get_current_screen();
		$post_type  = $object->post_type;
		$action     = $object->action;
		$type       = elespare_type_builder();
		$post_types = elespare_pt_support();
		if ('elespare_builder' === $post_type && 'add' === $action) { ?>
			<div class="dialog-widget dialog-lightbox-widget elespare-lightbox elespare-templates-modal" id="elespare-new-template-modal">
				<div class="elespare-dialog-widget-content dialog-lightbox-widget-content">
					<div class="dialog-header dialog-lightbox-header">
						<div class="elespare-templates-modal__header">
							<div class="elespare-templates-modal__header__logo-area">
								<div class="elespare-templates-modal__header__logo">
									<span class="elespare-header-logo-icon-wrapper elespare-gradient-logo">
										<img src="<?php echo esc_url(ELESPARE_DIR_URL . 'header-footer/inc/admin/svg/elespare.png'); ?>" alt="<?php echo esc_attr('Elespare Logo'); ?>">
									</span>
								</div>
							</div>

							<div class="elespare-templates-modal__header__items-area">
								<div class="elespare-templates-modal__header__close elespare-templates-modal__header__close--normal elespare-templates-modal__header__item">
									<i class="eicon-close" aria-hidden="true" title="Back"></i>
									<span class="elespare-screen-only">
										<?php echo esc_html__('Back', 'elespare'); ?>
									</span>
								</div>

								<div id="elespare-template-library-header-tools"></div>
							</div>
						</div>
					</div>
					<div class="dialog-message dialog-lightbox-message">
						<div class="dialog-content dialog-lightbox-content">
							<div id="elespare-new-template-dialog-content">
								<form id="elespare-new-template__form" action="">
									<input type="hidden" name="post_type" value="elespare_builder">

									<div id="elementor-new-template__form__title">

										<?php echo esc_html__('Choose Template Type', 'elespare'); ?>
									</div>
									<div class="elespare-template-first-row">
										<div id="elespare-post-title-wrapper" class="elespare-form-field">
											<label for="elespare-new-template__form__post-title" class="elespare-form-field__label"><?php echo esc_html__('Name your template', 'elespare'); ?></label>
											<div class="elespare-form-field__text__wrapper">
												<input type="text" placeholder="Enter template name (optional)" id="elespare-new-template__form__post-title" class="elespare-form-field__text" name="post_title">
											</div>
										</div>
										<div id="elespare-new-template__form__template-type__wrapper" class="elespare-form-field">
											<label for="elespare-template-type" class="elespare-form-field__label">
												<?php echo esc_html__('Select the type of template you want to work on', 'elespare'); ?>
											</label>
											<div class="elespare-form-field__select__wrapper">
												<select id="elespare-template-type" class="elespare-form-field__select" name="template_type" required="">
													<?php foreach ($type as $val => $name) : ?>
														<option value="<?php echo esc_attr($val); ?>"><?php echo esc_html($name); ?></option>
													<?php endforeach ?>
												</select>
											</div>
										</div>
									</div>
									<div class="elespare-template--row elespare-template-row-condition">
										<div class="condition-group display--on">
											<div class="parent-item elespare-form-field ">
												<label class="elespare-form-field__label">
													<?php echo esc_html__('Display On', 'elespare'); ?>
												</label>
												<div class="elespare-form-field__select__wrapper">
													<select name="ele_display" class="display-on elespare-form-field__select">
														<?php foreach ($post_types as $post_type => $name) : ?>
															<option value="<?php echo esc_attr($post_type); ?>">
																<?php echo esc_html($name); ?>
															</option>
														<?php endforeach ?>

													</select>
												</div>
											</div>

											<div class="child-item">
												<div class="input-item-wrapper">
												</div>
											</div>
										</div>
										<div class="condition-group not-display">
											<div class="parent-item elespare-form-field">
												<label class="elespare-form-field__label">
													<?php echo esc_html__('Do Not Display On', 'elespare'); ?>
												</label>
												<?php unset($post_types['all']); ?>
												<div class="elespare-form-field__select__wrapper">
													<select name="ele_hf_no_display" class="no-display-on elespare-form-field__select">

														<?php foreach ($post_types as $post_type => $name) : ?>
															<option value="<?php echo esc_attr($post_type); ?>">
																<?php echo esc_html($name); ?>
															</option>
														<?php endforeach ?>
													</select>
												</div>
											</div>

											<div class="child-item">
												<div class="input-item-wrapper">
												</div>
											</div>
										</div>
									</div>

									<button id="elespare-new-template__form__submit" class="elespare-button elespare-button-success">
										<?php echo esc_html__('Create Template', 'elespare'); ?>
									</button>
								</form>
							</div>
						</div>
						<div class="dialog-loading dialog-lightbox-loading"></div>
					</div>
				</div>
			</div>
<?php
		}
	}

	public function elespare_create_ele_hf_post() {
		
	
		// Capability Check
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'You do not have permission to create posts.' );
			die();
		}
		
		check_ajax_referer( 'elespare_hf_nonce' );
	
		$post_type        = $_POST['post_type'];
		$post_title       = $_POST['post_title'];
		$template_type    = $_POST['template_type'];
		$display          = $_POST['ele_display'];
		$no_display       = $_POST['ele_no_display'];
		$ele_hf_post_type    = '';
		$ele_hf_post         = '';
		$ele_hf_ex_post_type = '';
		$ele_hf_ex_post      = '';
		$url              = admin_url( '/' );
	
		if ( array_key_exists( 'ele_hf_post_type', $_POST ) ) {
			$ele_hf_post_type = $_POST['ele_hf_post_type'];
		}
		if ( array_key_exists( 'ele_hf_post', $_POST ) ) {
			$ele_hf_post = $_POST['ele_hf_post'];
		}
		if ( array_key_exists( 'ele_hf_ex_post_type', $_POST ) ) {
			$ele_hf_ex_post_type = $_POST['ele_hf_ex_post_type'];
		}
		if ( array_key_exists( 'ele_hf_ex_post', $_POST ) ) {
			$ele_hf_ex_post = $_POST['ele_hf_ex_post'];
		}
	
		$args = array(
			'post_title'  => $post_title,
			'post_type'   => $post_type,
			'post_status' => 'publish'
		);
	
		$post_id = wp_insert_post( $args );
		if ( $post_id ) {
			flush_rewrite_rules();
		}
	
		add_post_meta( $post_id, 'ele_hf_type', sanitize_text_field( $template_type ) );
		add_post_meta( $post_id, 'ele_hf_display', sanitize_text_field( $display ) );
		add_post_meta( $post_id, 'ele_hf_no_display', sanitize_text_field( $no_display ) );
		add_post_meta( $post_id, 'ele_hf_post_type', sanitize_text_field( $ele_hf_post_type ) );
		add_post_meta( $post_id, 'ele_hf_post', sanitize_text_field( $ele_hf_post ) );
		add_post_meta( $post_id, 'ele_hf_post_type', sanitize_text_field( $ele_hf_ex_post_type ) );
		add_post_meta( $post_id, 'ele_hf_ex_post', sanitize_text_field( $ele_hf_ex_post ) );
	
		$url .= 'post.php?post=' . $post_id . '&action=elementor';
	
		wp_send_json( $url );
		die();
	}
	
}

Elespare_Admin::instance();
