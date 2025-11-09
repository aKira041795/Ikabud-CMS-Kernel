<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * NEWSX Widget.
 *
 * Class Newsx_Widget
 *
 * @extends  WP_Widget
 */
abstract class Newsx_Widget extends WP_Widget {

	public $widget_cssclass;

	public $widget_description;

	public $widget_id = false;

	public $widget_name;

	public $sections = [];

	public $control_options = [];

	public $customize_selective_refresh = false;

	public function __construct() {

		$widget_options = [
			'classname'                   => $this->widget_cssclass,
			'description'                 => $this->widget_description,
			'customize_selective_refresh' => $this->customize_selective_refresh,
		];

		parent::__construct( $this->widget_id, $this->widget_name, $widget_options, $this->control_options );

	}

	// Update Widget Instance.
	public function update( $new_instance, $old_instance ) {

		$instance = $old_instance;

		if ( empty( $this->sections ) ) {
			return $instance;
		}

		// Loop sections.
		foreach ( $this->sections as $section_id => $section ) :

		// Remove section title entry
		unset( $section['section_title'] );

		// Loop settings and get values to save.
		foreach ( $section as $key => $setting ) {
			if ( ! isset( $setting['type'] ) ) {
				continue;
			}

			// Skip group-title type.
			if ( 'group-title' === $setting['type'] ) {
				continue;
			}

			// Format the value based on settings type.
			switch ( $setting['type'] ) {

				case 'url':
					$instance[ $key ] = isset( $new_instance[ $key ] ) ? esc_url_raw( $new_instance[ $key ] ) : $setting['default'];
					break;

				case 'textarea':
					$instance[ $key ] = wp_kses( trim( wp_unslash( $new_instance[ $key ] ) ), wp_kses_allowed_html( 'post' ) );
					break;

				case 'image':
					/**
					 * Array of valid image file types.
					 *
					 * The array includes image mime types that are included in wp_get_mime_types()
					 */
					$mimes = [
						'jpg|jpeg|jpe' => 'image/jpeg',
						'gif'          => 'image/gif',
						'png'          => 'image/png',
						'bmp'          => 'image/bmp',
						'tiff|tif'     => 'image/tiff',
						'ico'          => 'image/x-icon',
					];

					// Return an array with file extension and mime_type.
					$file = wp_check_filetype( $new_instance[ $key ], $mimes );

					// If $new_instance[ $key ] has a valid mime_type, assign it to $instance[ $key ], otherwise, assign empty value to $instance[ $key ].
					$instance[ $key ] = $file['ext'] ? $new_instance[ $key ] : $setting['default'];
					break;

				case 'checkbox':
				case 'switcher':
					$instance[ $key ] = isset($new_instance[$key]) && ( '1' == $new_instance[ $key ] || 'on' == $new_instance[ $key ] ) ? '1' : '0';
					break;

				case 'visibility':
					$selected_choices     = [];
					$available_choices    = $setting['choices'];
					$new_instance[ $key ] = isset( $new_instance[ $key ] ) ? $new_instance[ $key ] : [];

					foreach ( $new_instance[ $key ] as $selected_key => $selected_value ) {

						if ( array_key_exists( $selected_key, $available_choices ) ) {
							$selected_choices[$selected_key] = $selected_value;
						}
					}

					$instance[ $key ] = $selected_choices;
					break;
					
				case 'number':
					$instance[ $key ] = is_numeric( $new_instance[ $key ] ) ? intval( $new_instance[ $key ] ) : $setting['default'];

					if ( isset( $setting['input_attrs']['min'] ) && '' !== $setting['input_attrs']['min'] ) {
						$instance[ $key ] = max( $instance[ $key ], $setting['input_attrs']['min'] );
					}

					if ( isset( $setting['input_attrs']['max'] ) && '' !== $setting['input_attrs']['max'] ) {
						$instance[ $key ] = min( $instance[ $key ], $setting['input_attrs']['max'] );
					}
					break;

				case 'radio':
				case 'radio-image':	
				case 'select':
					$new_instance[ $key ] = sanitize_key( $new_instance[ $key ] );
					$available_choices    = $setting['choices'];

					$instance[ $key ] = array_key_exists( $new_instance[ $key ], $available_choices ) ? $new_instance[ $key ] : $setting['default'];
					break;

					$new_instance[ $key ] = ( '-1' == $new_instance[ $key ] ) ? '0' : absint( $new_instance[ $key ] );
					$available_users      = [];
					$all_author_users     = get_users(
						[
							'capability' => 'authors',
						]
					);

					foreach ( $all_author_users as $author_user ) {
						$available_users[ $author_user->ID ] = $author_user->display_name;
					}

					$instance[ $key ] = array_key_exists( $new_instance[ $key ], $available_users ) ? $new_instance[ $key ] : $setting['default'];
					break;


				case 'select2':
					$selected_choices     = [];
					$available_choices    = $setting['choices'];
					$new_instance[ $key ] = isset( $new_instance[ $key ] ) ? $new_instance[ $key ] : [];

					foreach ( $new_instance[ $key ] as $selected_key => $selected_value ) {

						if ( array_key_exists( $selected_value, $available_choices ) ) {
							$selected_choices[] = $selected_value;
						}
					}

					$instance[ $key ] = $selected_choices;
					break;

				default:
					$instance[ $key ] = isset( $new_instance[ $key ] ) ? sanitize_text_field( $new_instance[ $key ] ) : $setting['default'];
					break;

			}

			// Sanitize the value of a setting.
			$instance[ $key ] = apply_filters( 'newsx_widget_settings_sanitize_option', $instance[ $key ], $new_instance, $key, $setting );
		}
		
		endforeach;



		return $instance;

	}

	// Render Settings form.
	public function form( $instance ) {

		if ( empty( $this->sections ) ) {
			return;
		}

		$section_index = 1;
		
		foreach ( $this->sections as $section_id => $section ) {
			
			// Section Title
			if ( isset( $section['section_title'] ) && $section['section_title']['type'] === 'title' ) {
				$section_label = $section['section_title']['label'];
				$active_class = ( $section_index === 1 ) ? ' active' : '';
				$class = 'pro_options_section' === $section_id ? 'newsx-pro-section' : '';
				$icon = isset($icon) ? $icon : '';

				echo '<h3 class="newsx-widget-section-title '. esc_attr($class . $active_class) .'">'. esc_html($section_label) .' '. $icon .'</h3>';
			}

			// Remove section title entry
			unset( $section['section_title'] );

			// Widget Fields
			echo '<div class="newsx-widget-section-content">';

			$this->render_widget_fields( $instance, $section );

			echo '</div>';

			$section_index++;
		}
	}

	public function render_widget_fields( $instance, $section ) {
		foreach ( $section as $key => $setting ) {

			$class = isset( $setting['class'] ) ? $setting['class'] : '';

			$value = '';
			if ( isset( $instance[ $key ] ) ) {
				$value = $instance[ $key ];
			} elseif ( isset( $setting['default'] ) ) {
				$value = $setting['default'];
			}
			
			echo '<div class="newsx-widget-field newsx-widget-field-'. esc_attr($setting['type']) .'">';

			switch ( $setting['type'] ) {

				case 'text':
					?>
						<label class="newsx-widget-field-label" for="<?php echo esc_attr( $this->get_field_id( $key ) ); ?>">
							<?php echo esc_html( $setting['label'] ); ?>
						</label>

						<input type="text"
							   class="widefat <?php echo esc_attr( $class ); ?>"
							   id="<?php echo esc_attr( $this->get_field_id( $key ) ); ?>"
							   name="<?php echo esc_attr( $this->get_field_name( $key ) ); ?>"
							   value="<?php echo esc_attr( $value ); ?>"
						/>

						<?php if ( isset( $setting['description'] ) ) : ?>
							<span class="newsx-widget-field-description"><?php echo wp_kses_post( $setting['description'] ); ?></span>
						<?php endif; ?>
					<?php
					break;

				case 'url':
					?>
						<label class="newsx-widget-field-label" for="<?php echo esc_attr( $this->get_field_id( $key ) ); ?>">
							<?php echo esc_html( $setting['label'] ); ?>
						</label>

						<input type="url"
							   class="widefat <?php echo esc_attr( $class ); ?>"
							   id="<?php echo esc_attr( $this->get_field_id( $key ) ); ?>"
							   name="<?php echo esc_attr( $this->get_field_name( $key ) ); ?>"
							   value="<?php echo esc_attr( $value ); ?>"
						/>
					<?php
					break;

				case 'textarea':
					?>
						<label class="newsx-widget-field-label" for="<?php echo esc_attr( $this->get_field_id( $key ) ); ?>">
							<?php echo esc_html( $setting['label'] ); ?>
						</label>

						<textarea class="widefat <?php echo esc_attr( $class ); ?>"
								  rows="5"
								  cols="20"
								  id="<?php echo esc_attr( $this->get_field_id( $key ) ); ?>"
								  name="<?php echo esc_attr( $this->get_field_name( $key ) ); ?>"
						><?php echo esc_textarea( $value ); ?></textarea>
					<?php
					break;

				case 'image':
					?>
					<div class="media-uploader">
							<label class="newsx-widget-field-label" for="<?php echo esc_attr( $this->get_field_id( $key ) ); ?>">
								<?php echo esc_html( $setting['label'] ); ?>
							</label>

						<div class="media-uploader" id="<?php echo esc_attr( $this->get_field_id( $key ) ); ?>">
							<div class="custom_media_preview">
								<?php if ( $value != '' ) : ?>
									<img class="custom_media_preview_default"
										 src="<?php echo esc_url( $value ); ?>"
										 style="max-width:100%;"
									/>
								<?php endif; ?>
							</div>

							<input type="text"
								   class="widefat custom_media_input"
								   id="<?php echo esc_attr( $this->get_field_id( $key ) ); ?>"
								   name="<?php echo esc_attr( $this->get_field_name( $key ) ); ?>"
								   value="<?php echo esc_attr( $value ); ?>"
								   style="margin-top:5px;"
							/>

							<button class="custom_media_upload button button-secondary button-large"
									id="<?php echo esc_attr( $this->get_field_id( $key ) ); ?>"
									data-choose="<?php esc_attr_e( 'Choose an image', 'news-magazine-x' ); ?>"
									data-update="<?php esc_attr_e( 'Use image', 'news-magazine-x' ); ?>"
									style="width:100%;margin-top:6px;margin-right:30px;"
							>
								<?php esc_html_e( 'Select an Image', 'news-magazine-x' ); ?>
							</button>
						</div>
					</div>
					<?php
					break;

				case 'checkbox':
					?>
						<input class="checkbox"
							   id="<?php echo esc_attr( $this->get_field_id( $key ) ); ?>"
							   name="<?php echo esc_attr( $this->get_field_name( $key ) ); ?>"
							   type="checkbox"
							<?php echo esc_attr( ( $value == 1 ) ? 'checked' : '' ); ?>
						/>

						<label class="newsx-widget-field-label" for="<?php echo esc_attr( $this->get_field_id( $key ) ); ?>">
							<?php echo esc_html( $setting['label'] ); ?>
						</label>
					<?php
					break;

				case 'switcher':
					?>
					<div class="newsx-switcher-control">
						<label class="newsx-widget-field-label newsx-flex">
						<span><?php echo esc_html( $setting['label'] ); ?></span>

						<input class="switcher"
								id="<?php echo esc_attr( $this->get_field_id( $key ) ); ?>"
								name="<?php echo esc_attr( $this->get_field_name( $key ) ); ?>"
								type="checkbox"
							<?php echo esc_attr( ( $value == 1 ) ? 'checked' : '' ); ?>
						/>

						<span class="switcher-label" for="<?php echo esc_attr( $this->get_field_id( $key ) ); ?>"></span>
						</label>
					</div>
					<?php
					break;

				case 'visibility':
					?>
					<label class="newsx-widget-field-label"><?php echo esc_html( $setting['label'] ); ?></label>

					<div class="newsx-visibility">
						<ul>
						<?php foreach ( $setting['choices'] as $choices_key => $choices_value ) : ?>

							<?php
							if ( isset( $value ) ) {
								$checked = array_key_exists( $choices_key, $value );
							} else {
								$checked = false;
							}

							?>
							<li>
							<label class="<?php echo esc_attr( ($checked ? 'checked' : '') ); ?>" for="<?php echo esc_attr( $this->get_field_id( $key ) ); ?>_<?php echo esc_attr( $choices_key ); ?>">

								<input type="checkbox"
										id="<?php echo esc_attr( $this->get_field_id( $key ) ); ?>_<?php echo esc_attr( $choices_key ); ?>"
										name="<?php echo esc_attr( $this->get_field_name( $key ) ); ?>[<?php echo esc_attr( $choices_key ); ?>]"
										value="1"
									<?php
									if ( $checked ) {
										echo 'checked=checked';
									}
									?>
								/>
								
								<span><?php echo esc_html( $choices_value ); ?></span>
							</label>
							</li>
						<?php endforeach; ?>
						</ul>
					</div>

					<div class="clear"></div>
					<?php
					break;
										
				case 'number':
					?>
						<label class="newsx-widget-field-label" for="<?php echo esc_attr( $this->get_field_id( $key ) ); ?>">
							<?php echo esc_html( $setting['label'] ); ?>
						</label>

						<input class="widefat <?php echo esc_attr( $class ); ?>"
							   id="<?php echo esc_attr( $this->get_field_id( $key ) ); ?>"
							   name="<?php echo esc_attr( $this->get_field_name( $key ) ); ?>"
							   type="number"
							   value="<?php echo esc_attr( $value ); ?>"
							<?php if ( isset( $setting['input_attrs']['step'] ) ) { ?>
								step="<?php echo esc_attr( $setting['input_attrs']['step'] ); ?>"
							<?php } ?>
							<?php if ( isset( $setting['input_attrs']['min'] ) ) { ?>
								min="<?php echo esc_attr( $setting['input_attrs']['min'] ); ?>"
							<?php } ?>
							<?php if ( isset( $setting['input_attrs']['max'] ) ) { ?>
								max="<?php echo esc_attr( $setting['input_attrs']['max'] ); ?>"
							<?php } ?>
						/>
					<?php
					break;
				case 'number-responsive':
					?>
						<label class="newsx-widget-field-label" for="<?php echo esc_attr( $this->get_field_id( $key ) ); ?>" class="newsx-widget-field-label newsx-flex">
							<span><?php echo esc_html( $setting['label'] ); ?></span>

							<ul class="newsx-responsive-switcher newsx-flex">
								<li data-device="desktop" class="active"><span class="dashicons dashicons-desktop"></span></li>
								<li data-device="tablet"><span class="dashicons dashicons-tablet"></span></li>
								<li data-device="mobile"><span class="dashicons dashicons-smartphone"></span></li>
							</ul>
						</label>

						<?php
						// Set default values.
						$parsed_value = json_decode($value);
						
						?>

						<input class="widefat newsx-responsive-control active"
								type="number" data-device="desktop"
								value="<?php echo esc_attr( $parsed_value->desktop ); ?>"
							<?php if ( isset( $setting['input_attrs']['step'] ) ) { ?>
								step="<?php echo esc_attr( $setting['input_attrs']['step'] ); ?>"
							<?php } ?>
							<?php if ( isset( $setting['input_attrs']['min'] ) ) { ?>
								min="<?php echo esc_attr( $setting['input_attrs']['min'] ); ?>"
							<?php } ?>
							<?php if ( isset( $setting['input_attrs']['max'] ) ) { ?>
								max="<?php echo esc_attr( $setting['input_attrs']['max'] ); ?>"
							<?php } ?>
						/>

						<input class="widefat newsx-responsive-control"
								type="number" data-device="tablet"
								value="<?php echo esc_attr( $parsed_value->tablet ); ?>"
							<?php if ( isset( $setting['input_attrs']['step'] ) ) { ?>
								step="<?php echo esc_attr( $setting['input_attrs']['step'] ); ?>"
							<?php } ?>
							<?php if ( isset( $setting['input_attrs']['min'] ) ) { ?>
								min="<?php echo esc_attr( $setting['input_attrs']['min'] ); ?>"
							<?php } ?>
							<?php if ( isset( $setting['input_attrs']['max'] ) ) { ?>
								max="<?php echo esc_attr( $setting['input_attrs']['max'] ); ?>"
							<?php } ?>
						/>

						<input class="widefat newsx-responsive-control"
								type="number" data-device="mobile"
								value="<?php echo esc_attr( $parsed_value->mobile ); ?>"
							<?php if ( isset( $setting['input_attrs']['step'] ) ) { ?>
								step="<?php echo esc_attr( $setting['input_attrs']['step'] ); ?>"
							<?php } ?>
							<?php if ( isset( $setting['input_attrs']['min'] ) ) { ?>
								min="<?php echo esc_attr( $setting['input_attrs']['min'] ); ?>"
							<?php } ?>
							<?php if ( isset( $setting['input_attrs']['max'] ) ) { ?>
								max="<?php echo esc_attr( $setting['input_attrs']['max'] ); ?>"
							<?php } ?>
						/>

						<input class="widefat newsx-value-holder <?php echo esc_attr( $class ); ?>"
								id="<?php echo esc_attr( $this->get_field_id( $key ) ); ?>"
								name="<?php echo esc_attr( $this->get_field_name( $key ) ); ?>"
								type="hidden"
								value='<?php echo esc_attr($value); ?>'
							<?php if ( isset( $setting['input_attrs']['step'] ) ) { ?>
								step="<?php echo esc_attr( $setting['input_attrs']['step'] ); ?>"
							<?php } ?>
							<?php if ( isset( $setting['input_attrs']['min'] ) ) { ?>
								min="<?php echo esc_attr( $setting['input_attrs']['min'] ); ?>"
							<?php } ?>
							<?php if ( isset( $setting['input_attrs']['max'] ) ) { ?>
								max="<?php echo esc_attr( $setting['input_attrs']['max'] ); ?>"
							<?php } ?>
						/>
					<?php
					break;
					
				case 'radio':
					?>
						<label class="newsx-widget-field-label" for="<?php echo esc_attr( $this->get_field_id( $key ) ); ?>">
							<?php echo esc_html( $setting['label'] ); ?>
						</label>

						<?php
						$count = 1;
						foreach ( $setting['choices'] as $choices_key => $choices_value ) {
							if ( 1 !== $count ) {
								echo '<br />';
							}
							?>

							<input type="radio"
								   id="<?php echo esc_attr( $this->get_field_id( $choices_key ) ); ?>"
								   name="<?php echo esc_attr( $this->get_field_name( $key ) ); ?>"
								   value="<?php echo esc_attr( $choices_key ); ?>"
								<?php echo esc_attr( ( $choices_key == $value ) ? 'checked' : '' ); ?>
							/>

							<label for="<?php echo esc_attr( $this->get_field_id( $choices_key ) ); ?>">
								<?php echo esc_html( $choices_value ); ?>
							</label>
							<?php
							$count ++;
						}
						?>
					<?php
					break;

				case 'radio-image':
					?>
					<label class="newsx-widget-field-label" for="<?php echo esc_attr( $this->get_field_id( $key ) ); ?>">
						<?php
						echo esc_html( $setting['label'] );
						?>
					</label>

					<div class="radio-image">
						<div class="newsx-radio-image-inner">
						<?php foreach ( $setting['choices'] as $choices_key => $choices_value ) : ?>
							<div>
								<input type="radio"
									id="<?php echo esc_attr( $this->get_field_id( $choices_key ) ); ?>"
									name="<?php echo esc_attr( $this->get_field_name( $key ) ); ?>"
									value="<?php echo esc_attr( $choices_key ); ?>"
									<?php echo esc_attr( ( $choices_key == $value ) ? 'checked' : '' ); ?>
								/>

								<label for="<?php echo esc_attr( $this->get_field_id( $choices_key ) ); ?>">
									<?php
									echo wp_kses( $choices_value['image'], newsx_allow_custom_tags_and_atts_for_svg_and_img() );
									?>
								</label>

								<?php
								if ( '' !== $choices_value['title'] ) {
									echo '<span class="newsx-control-tooltip">'.esc_html( $choices_value['title'] ) . '</span>';
								}
								?>
							</div>
						<?php endforeach; ?>
						</div>
					</div>
					<?php
					break;

				case 'select':
					?>
						<label class="newsx-widget-field-label" for="<?php echo esc_attr( $this->get_field_id( $key ) ); ?>">
							<?php
							echo esc_html( $setting['label'] );
							?>
						</label>

						<select class="widefat <?php echo esc_attr( $class ); ?>"
								id="<?php echo esc_attr( $this->get_field_id( $key ) ); ?>"
								name="<?php echo esc_attr( $this->get_field_name( $key ) ); ?>"
						>
							<?php foreach ( $setting['choices'] as $choices_key => $choices_value ) { ?>
								<option value="<?php echo esc_attr( $choices_key ); ?>"
									<?php selected( $choices_key, $value ); ?>
								>
									<?php echo esc_html( $choices_value ); ?>
								</option>
							<?php } ?>
						</select>
					<?php
					break;


					?>
						<label for="<?php echo esc_attr( $this->get_field_id( $key ) ); ?>">
							<?php echo esc_html( $setting['label'] ); ?>
						</label>

						<?php
						wp_dropdown_users(
							[
								'show_option_none' => ' ',
								'name'             => $this->get_field_name( $key ),
								'selected'         => $value,
								'orderby'          => 'name',
								'order'            => 'ASC',
								'capability'       => 'authors',
								'class'            => 'widefat postform',
							]
						);
						?>
					<?php
					break;

				case 'select2':
					?>
						<label class="newsx-widget-field-label" for="<?php echo esc_attr( $this->get_field_id( $key ) ); ?>">
							<?php echo esc_html( $setting['label'] ); ?>
						</label>

						<?php
						printf(
							/* Translators: 1. Field name, 2. Field id, 3. Custom style declaration */
							'<select multiple="multiple" name="%s[]" id="%s" class="newsx-select2 %s" %s>',
							esc_attr( $this->get_field_name( $key ) ),
							esc_attr( $this->get_field_id( $key ) ),
							esc_attr( $class ),
							'style="width:100%"',
						);

						$available_values = ! empty( $value ) ? $value : [];

						foreach ( $setting['choices'] as $choices_key => $choices_value ) {
							?>
							<option value="<?php echo esc_attr( $choices_key ); ?>"
								<?php
								if ( in_array( (string)$choices_key, $available_values, true ) ) {
									echo ' selected="selected"';
								}
								?>
							>
								<?php echo esc_html( $choices_value ); ?>
							</option>
							<?php
						}

						echo '</select>';
						?>

					<?php
					break;

				case 'colorpicker':
					?>
					<label class="newsx-widget-field-label" for="<?php echo esc_attr( $this->get_field_id( $key ) ); ?>">
						<?php echo esc_html( $setting['label'] ); ?>
					</label>

					<input type="text"
						class="newsx-color-picker <?php echo esc_attr( $class ); ?>"
						id="<?php echo esc_attr( $this->get_field_id( $key ) ); ?>"
						name="<?php echo esc_attr( $this->get_field_name( $key ) ); ?>"
						value="<?php echo esc_attr( $value ); ?>"
					/>
					<?php
					break;

				case 'group-title':
					?>
					<h3 class="newsx-widget-control-group-title">
						<span><?php echo esc_html( $setting['label'] ); ?></span>
					</h3>
					<?php
					break;
					
				case 'separator':
					?>
					<div class="newsx-widget-control-separator <?php echo esc_attr( $class ); ?>"></div>
					<?php
					break;

				case 'pro-features':
					?>
					<div class="newsx-widget-control-pro-features">
						<?php
						echo '<ul>';
							foreach ( $setting['choices'] as $key => $value ) {
								echo '<li><strong><span class="dashicons dashicons-yes"></span> <span class="newsx-upgrade-pro-list-item">' . esc_html( $value ) . '</span></strong></li>';
							}
						echo '</ul>';
						echo '<a href="'. esc_url( $setting['description'] ) .'" class="newsx-upgrade-pro-button" target="_blank">'. esc_html__( 'Upgrade to Pro &#8594;', 'news-magazine-x' ) .'</a>';
						?>
					</div>
					<?php
					break;

				// Default: run an action.
				default:
					do_action( 'colormag_widget_field_' . $setting['type'], $key, $value, $setting, $instance );
					break;
			}

			echo '</div>';
		}
	}

	public function widget_start( $args ) {
		$before_widget = $args['before_widget'];
		$before_widget = str_replace( 'class="', 'class="newsx-widget ', $before_widget );
		
		echo wp_kses_post( $before_widget );
	}

	public function widget_end( $args ) {
		echo wp_kses_post( $args['after_widget'] );
	}

}
