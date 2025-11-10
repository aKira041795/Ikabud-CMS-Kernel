<?php

/**
 * Zakra admin dashboard product page.
 *
 * @author ThemeGrill
 * @package Zakra
 * @since @todo
 */

defined( 'ABSPATH' ) || exit;

?>

<div class="zak-container products-page">
	<div class="postbox-container" style="float: none;">
		<div class="postbox">
			<h1 class="hndle">
				<!--Themes-->
				<span><?php esc_html_e( 'Themes', 'zakra' ); ?></span>
			</h1>
			<div class="inside inside__themes">
				<?php
				$themes_data = array(
					'zakra'    => array(
						'name'            => 'Zakra',
						'slug'            => 'zakra',
						'image'           => ZAKRA_PARENT_URI . '/inc/admin/images/zakra.webp',
						'description'     => 'Multipurpose WordPress theme loaded with intuitive features, powerful customization options, and pre-built demos to create professional websites of any kind.',
						'learn_more_link' => 'https://zakratheme.com/',
						'live_demo_link'  => 'https://zakratheme.com/demos/',
					),
					'colormag' => array(
						'name'            => 'ColorMag',
						'slug'            => 'colormag',
						'image'           => ZAKRA_PARENT_URI . '/inc/admin/images/colormag.webp',
						'description'     => 'Modern and professional WordPress magazine and news portal-styled theme perfect for creating websites for your user-engaging magazines, news channels, etc.',
						'learn_more_link' => 'https://themegrill.com/themes/colormag/',
						'live_demo_link'  => 'https://themegrilldemos.com/colormag-demos/',
					),
					// Add more themes as needed
				);

				foreach ( $themes_data as $theme_info ) :
					$theme_slug = $theme_info['slug'];
					?>
					<div class="item item-<?php echo esc_attr( $theme_slug ); ?>">
						<img class="<?php echo esc_attr( $theme_slug ); ?>-logo" src="<?php echo esc_url( $theme_info['image'] ); ?>" alt="<?php echo esc_attr( $theme_info['name'] ); ?>">
						<div class="content">
							<h3><?php echo esc_html( $theme_info['name'] ); ?></h3>
							<p><?php echo esc_html( $theme_info['description'] ); ?></p>
						</div>
						<div class="cta">
							<div class="cta-text">
								<a href="<?php echo esc_url( $theme_info['learn_more_link'] ); ?>" target="_blank"><?php esc_html_e( 'Learn More', 'zakra' ); ?></a>
								<a href="<?php echo esc_url( $theme_info['live_demo_link'] ); ?>" target="_blank"><?php esc_html_e( 'Live demo', 'zakra' ); ?></a>
							</div>
						</div>
					</div>
				<?php endforeach; ?>
			</div>
			<h1 class="hndle">
				<span><?php esc_html_e( 'Plugins', 'zakra' ); ?></span>
			</h1>
			<div class="inside inside__plugins">
				<?php
				$plugins_data = array(
					'ur'              => array(
						'name'            => 'User Registration',
						'file'            => 'user-registration/user-registration.php',
						'slug'            => 'user-registration',
						'image'           => ZAKRA_PARENT_URI . '/inc/admin/images/ur.webp',
						'description'     => 'Ultimate WordPress user registration plugin to streamline the user signup process. Create registration & login forms, manage users, and more.',
						'learn_more_link' => 'https://wpuserregistration.com/',
						'live_demo_link'  => 'https://demo.wpeverest.com/user-registration/',
					),
					'evf'             => array(
						'name'            => 'Everest Forms',
						'file'            => 'everest-forms/everest-forms.php',
						'slug'            => 'everest-forms',
						'image'           => ZAKRA_PARENT_URI . '/inc/admin/images/evf.webp',
						'description'     => 'Feature-rich and highly customizable WordPress form builder plugin to create different types of professional forms for your website.',
						'learn_more_link' => 'https://everestforms.net/',
						'live_demo_link'  => 'https://demo.wpeverest.com/everest-forms/',
					),
					'masteriyo'       => array(
						'name'            => 'Masteriyo',
						'file'            => 'learning-management-system/lms.php',
						'slug'            => 'learning-management-system',
						'image'           => ZAKRA_PARENT_URI . '/inc/admin/images/masteriyo.webp',
						'description'     => 'Streamline the learning process with an easy-to-use and highly advanced LMS plugin loaded with powerful features to create and sell courses online through your website effortlessly.',
						'learn_more_link' => 'https://masteriyo.com/',
						'live_demo_link'  => 'https://themegrilldemos.com/elearning/',
					),
					'mzb'             => array(
						'name'            => 'Magazine Blocks',
						'file'            => 'magazine-blocks/magazine-blocks.php',
						'slug'            => 'magazine-blocks',
						'image'           => ZAKRA_PARENT_URI . '/inc/admin/images/magazine-blocks.webp',
						'description'     => 'Powerful WordPress Gutenberg block plugin with the perfect blocks and editing options to create magazine-themed websites.',
						'learn_more_link' => 'https://wpblockart.com/magazine-blocks/',
						'live_demo_link'  => 'https://themegrilldemos.com/colormag/',
					),
					'blockart-blocks' => array(
						'name'            => 'BlockArt',
						'file'            => 'blockart-blocks/blockart.php',
						'slug'            => 'blockart-blocks',
						'image'           => ZAKRA_PARENT_URI . '/inc/admin/images/blockart-blocks.webp',
						'description'     => 'Highly customizable WordPress Gutenberg block plugin with pre-made templates and powerful blocks to create websites of any niche in no time. ',
						'learn_more_link' => 'https://wpblockart.com/blockart-blocks/',
						'live_demo_link'  => 'https://zakrademos.com/online-course-02/',
					),
				);

				foreach ( $plugins_data as $plugin_info ) :
					$plugin_file         = $plugin_info['file'];
					$plugin_slug         = $plugin_info['slug'];
					$is_plugin_installed = zakra_is_plugin_installed( $plugin_file );
					$is_plugin_activated = is_plugin_active( $plugin_file );
					?>
					<div class="item item-<?php echo esc_attr( $plugin_slug ); ?>">
						<img class="<?php echo esc_attr( $plugin_slug ); ?>-logo"
							 src="<?php echo esc_url( $plugin_info['image'] ); ?>"
							 alt="<?php echo esc_attr( $plugin_info['name'] ); ?>">
						<div class="content">
							<h3><?php echo esc_html( $plugin_info['name'] ); ?></h3>
							<p><?php echo esc_html( $plugin_info['description'] ); ?></p>
						</div>
						<div class="cta">
							<div class="cta-text">
								<a href="<?php echo esc_url( $plugin_info['learn_more_link'] ); ?>" target="_blank"><?php esc_html_e( 'Learn More', 'zakra' ); ?></a>
								<a href="<?php echo esc_url( $plugin_info['live_demo_link'] ); ?>" target="_blank"><?php esc_html_e( 'Live demo','zakra' ); ?></a>
							</div>
							<div class="cta-button">
								<?php if ( $is_plugin_installed ) : ?>
									<?php if ( $is_plugin_activated ) : ?>
										<span class="activated"><?php esc_html_e( 'Activated','zakra' ); ?></span>
									<?php else : ?>
										<span><a href="#" class="activate-plugin"
												 data-plugin="<?php echo esc_attr( $plugin_file ); ?>"
												 data-slug="<?php echo esc_attr( $plugin_slug ); ?> "><?php esc_html_e( 'Activate','zakra' ); ?></a></span>
									<?php endif; ?>
								<?php else : ?>
									<span><a href="#" class="install-plugin"
											 data-plugin="<?php echo esc_attr( $plugin_file ); ?>"
											 data-slug="<?php echo esc_attr( $plugin_slug ); ?> "><?php esc_html_e( 'Install', 'zakra' ); ?></a></span>
								<?php endif; ?>
							</div>
						</div>
					</div>
				<?php endforeach; ?>
			</div>
		</div>
		<!-- Add other postboxes as needed -->
	</div>
</div>
