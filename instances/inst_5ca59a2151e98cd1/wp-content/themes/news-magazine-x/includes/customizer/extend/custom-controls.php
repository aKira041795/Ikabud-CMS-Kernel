<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/*
** Add Custom Controls
*/
add_action( 'customize_register', function( $wp_customize ) {

	// Pro Version Links
	class Newsx_Customize_About_Section_Links extends WP_Customize_Control {
		public $type = 'about-section-links';

		public function render_content() {
			?>
			<ul>
				<li class="customize-control">
					<h3><?php esc_html_e( 'Starter Templates', 'news-magazine-x' ); ?> <span>*</span></h3>
					<p><?php esc_html_e( 'News Magazine X theme comes with multiple starter templates. Click the button below to see what is this theme capable of.', 'news-magazine-x' ); ?></p>
					<a href="<?php echo esc_url('https://wp-royal-themes.com/themes/item-news-magazine-x-free/?ref=newsx-free-customizer-sec-about-demo-preview/#!/demo-preview'); ?>" target="_blank" class="button button-primary widefat"><?php esc_html_e( 'Theme Demo Preview', 'news-magazine-x' ); ?></a>
				</li>

				<?php if ( !defined('NEWSX_CORE_PRO_VERSION') || !newsx_core_pro_fs()->can_use_premium_code() ) : ?>
				<li class="customize-control">
					<h3><?php esc_html_e( 'Upgrade', 'news-magazine-x' ); ?> <span>*</span></h3>
					<p><?php esc_html_e( 'There are lots of reasons to upgrade to Pro version. Unlimited custom Colors, rich Typography options, multiple variation of Blog Feed layout and way much more. Also Premium Support included.', 'news-magazine-x' ); ?></p>
					<a href="<?php echo esc_url('https://wp-royal-themes.com/themes/item-news-magazine-x-pro/?ref=newsx-free-customizer-sec-about-upgrade#features'); ?>" target="_blank" class="button button-primary widefat"><?php esc_html_e( 'Get News Magazine X Pro', 'news-magazine-x' ); ?></a>
					<a href="<?php echo esc_url('https://news-magazine-x-pro.wp-royal-themes.com/test-drive-demo/wp-content/plugins/open-house-theme-options/redirect.php?multisite=test-drive-demo'); ?>" target="_blank" class="button widefat"><?php esc_html_e( 'Try Before Buy ➝', 'news-magazine-x' ); ?></a>
				</li>
				<?php endif; ?>

				<li class="customize-control">
					<h3><?php esc_html_e( 'Support', 'news-magazine-x' ); ?></h3>
					<p><?php esc_html_e( 'If you have any kind of theme related questions, feel free to ask.', 'news-magazine-x' ); ?></p>
					<a href="<?php echo esc_url('https://wp-royal-themes.com/contact/?ref=newsx-free-customizer-sec-about-contact/#!/cform'); ?>" target="_blank" class="button button-primary widefat"><?php esc_html_e( 'Contact Us', 'news-magazine-x' ); ?></a>
				</li>
				<li class="customize-control">
					<h3><?php esc_html_e( 'Demo Import / Getting Started', 'news-magazine-x' ); ?></h3>
					<p><?php esc_html_e( 'All you need for startup: Demo Import, Video Tutorials and more. To see what News Magazine X theme can offer, please visit a ', 'news-magazine-x' ); ?>
					<a href="<?php echo esc_url('https://news-magazine-x-free.wp-royal-themes.com/demo/?ref=newsx-free-customizer-sec-about-demo-preview'); ?>" target="_blank"><?php esc_html_e( 'Demo Preview Page.', 'news-magazine-x' ); ?></a></p>
					<a href="<?php echo esc_url(admin_url('admin.php?page=newsx-options&tab=starter-templates')); ?>" target="_blank" class="button button-primary widefat"><?php esc_html_e( 'Get Started', 'news-magazine-x' ); ?></a>
				</li>
				<li class="customize-control">
					<h3><?php esc_html_e( 'Documentation', 'news-magazine-x' ); ?></h3>
					<p>
					<?php 
						$theme_data	 = wp_get_theme();
						/* translators: %s theme name */
						printf( esc_html__( 'Need more details? Please check our full documentation for detailed information on how to use %s.', 'news-magazine-x' ), esc_html( $theme_data->Name ) );
					?>
					</p>
					<a href="<?php echo esc_url('https://wp-royal-themes.com/new-themes/news-magazine-x/docs/?ref=newsx-free-customizer-sec-about-docs'); ?>" target="_blank" class="button button-primary widefat"><?php esc_html_e( 'Documentation', 'news-magazine-x' ); ?></a>
				</li>
			</ul>
			<?php
		}
	}
	
	// Headline Control
	class Newsx_Headline_Control extends Kirki\Control\Base {
		public $type = 'newsx-headline';

		public function render_content() {
				echo '<div class="newsx-headline-wrap">';

					echo '<div class="newsx-headline-inner">';
						
						echo '<label class="customize-control-label">';
							echo '<span class="customize-control-title">' . esc_html( $this->label ) . '</span>';
						echo '</label>';

						echo !empty($this->description) ? '<p class="customize-control-description">' . esc_html( $this->description ) . '</p>' : '';
					
					echo '</div>';

				echo '</div>';
			?>

			<?php
		}
	}

	// Icon Control
	class Newsx_Icon_Select_Control extends Kirki\Control\Base {
		public $type = 'newsx-icon-select';

		public function render_content() {

			?>
			
			<div class="newsx-icon-select-wrap">

				<label class="customize-control-label">
					<span class="customize-control-title"><?php echo esc_html($this->label); ?></span>
				</label>

				<input type="hidden" class="newsx-icon-value" value="<?php esc_attr( $this->value() ); ?>" <?php $this->link(); ?> id="<?php echo esc_attr($this->id); ?>" />

				<div class="newsx-icon-select-trigger">
					<span class="icon-none <?php echo '' === $this->value() ? ' active' : ''; ?>"><svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 512 512'><path d='M256 8C119.034 8 8 119.033 8 256s111.034 248 248 248 248-111.034 248-248S392.967 8 256 8zm130.108 117.892c65.448 65.448 70 165.481 20.677 235.637L150.47 105.216c70.204-49.356 170.226-44.735 235.638 20.676zM125.892 386.108c-65.448-65.448-70-165.481-20.677-235.637L361.53 406.784c-70.203 49.356-170.226 44.736-235.638-20.676z'></path></svg></span>
					
					<?php if ( '' !== $this->value() ) : ?>
						<span class="icon-holder active" data-selected="<?php echo esc_attr($this->value()); ?>">
						</span>
					<?php else: ?>
						<span class="icon-holder" data-selected="<?php echo esc_attr($this->value()); ?>">
						<svg viewBox="0 0 32 32"><path d="M32 12.408l-11.056-1.607-4.944-10.018-4.944 10.018-11.056 1.607 8 7.798-1.889 11.011 9.889-5.199 9.889 5.199-1.889-11.011 8-7.798zM16 23.547l-6.983 3.671 1.334-7.776-5.65-5.507 7.808-1.134 3.492-7.075 3.492 7.075 7.807 1.134-5.65 5.507 1.334 7.776-6.983-3.671z"></path></svg>
						</span>
					<?php endif; ?>
					
					</span>
				</div>

				<div class="newsx-icon-list-popup"></div>

			</div>

			<?php
		}
	}

	// Upgrade to Pro List
	class Newsx_Upgrade_Pro_List_Control extends Kirki\Control\Base {
		public $type = 'newsx-upgrade-pro-list';

		public function render_content() {
			echo '<div class="newsx-upgrade-pro-list-wrap">';
				echo '<div class="newsx-upgrade-pro-list-header">';
					echo '<span class="dashicons dashicons-info"></span>';
					echo '<p>'. esc_html( $this->label ) .'</p>';
				echo '</div>';

				echo '<ul class="newsx-upgrade-pro-list">';
					foreach ( $this->choices as $key => $value ) {
						echo '<li><strong><span class="dashicons dashicons-yes"></span> <span class="newsx-upgrade-pro-list-item">' . esc_html( $value ) . '</span></strong></li>';
					}
				echo '</ul>';

				// echo '<a href="'. esc_url( $this->description ) .'" class="newsx-upgrade-pro-button" target="_blank">'. esc_html__( 'Upgrade to Pro', 'news-magazine-x' ) .'</a>';
				echo '<a href="https://news-magazine-x-pro.wp-royal-themes.com/test-drive-demo/wp-content/plugins/open-house-theme-options/redirect.php?multisite=test-drive-demo&section='. esc_attr( $this->section ) .'" class="newsx-try-before-buy-button" target="_blank">';
					echo esc_html__( 'Try Options ➝', 'news-magazine-x' );
				echo '</a>';

				echo '<a href="https://wp-royal-themes.com/themes/item-news-magazine-x-pro/?ref=newsx-customizer-upgrade-pro-button#download" class="newsx-try-before-buy-button" target="_blank">';
					echo esc_html__( 'Upgrade to Pro ☆', 'news-magazine-x' );
				echo '</a>';
				echo '</div>';
		}
	}

	// Upgrade to Pro
	class Newsx_Upgrade_Pro_Control extends Kirki\Control\Base {
		public $type = 'newsx-upgrade-pro';

		public function render_content() {
			echo '<div class="newsx-upgrade-pro-wrap">';
				echo '<span class="dashicons dashicons-info"></span>';
				echo '<p class="newsx-upgrade-pro-text">'. esc_html__( 'Need more ', 'news-magazine-x' ) .'<strong>'. esc_html( $this->label ) .'</strong>'. esc_html__( ' Options?', 'news-magazine-x' ) .'</p>';
				echo '<a href="'. esc_url( $this->description ) .'" class="newsx-upgrade-pro-button" target="_blank"><strong>'. esc_html__( 'Upgrade to Pro.', 'news-magazine-x' ) .'</strong></a>';
				echo '</div>';
		}
	}
	
	// Register our custom control with Kirki.
	add_filter( 'kirki_control_types', function( $controls ) {
		$controls['about-section-links'] = 'Newsx_Customize_About_Section_Links';

		$controls['newsx-headline'] = 'Newsx_Headline_Control';
		$controls['newsx-icon-select'] = 'Newsx_Icon_Select_Control';

		// Upgrade to Pro
		$controls['newsx-upgrade-pro'] = 'Newsx_Upgrade_Pro_Control';
		$controls['newsx-upgrade-pro-list'] = 'Newsx_Upgrade_Pro_List_Control';

		return $controls;
	} );
} );
