<?php
/**
 * Zakra dashboard help page.
 *
 * @author ThemeGrill
 * @package Zakra
 * @since @todo
 */

defined( 'ABSPATH' ) || exit;

if ( ! is_child_theme() ) {
	$theme = wp_get_theme();
} else {
	$theme = wp_get_theme()->parent();
}

$star_icon = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 76 11" fill="#FFB900" width="76px" height="12px">
  <path d="m4.121 3.669-3.19.462-.056.012a.5.5 0 0 0-.22.842l2.31 2.25-.544 3.177-.007.055a.5.5 0 0 0 .732.472L6 9.439l2.847 1.5.05.023a.5.5 0 0 0 .676-.55l-.546-3.178 2.312-2.25.04-.042a.5.5 0 0 0-.317-.81l-3.19-.463L6.445.779a.5.5 0 0 0-.897 0L4.12 3.669Zm16 0-3.19.462-.056.012a.5.5 0 0 0-.22.842l2.31 2.25-.544 3.177-.007.055a.5.5 0 0 0 .732.472L22 9.439l2.847 1.5.05.023a.5.5 0 0 0 .676-.55l-.546-3.178 2.312-2.25.04-.042a.5.5 0 0 0-.317-.81l-3.19-.463-1.426-2.89a.5.5 0 0 0-.897 0l-1.427 2.89Zm16 0-3.19.462-.056.012a.5.5 0 0 0-.22.842l2.31 2.25-.544 3.177-.007.055a.5.5 0 0 0 .732.472L38 9.439l2.847 1.5.05.023a.5.5 0 0 0 .676-.55l-.546-3.178 2.312-2.25.04-.042a.5.5 0 0 0-.317-.81l-3.19-.463-1.426-2.89a.5.5 0 0 0-.897 0l-1.427 2.89Zm16 0-3.19.462-.056.012a.5.5 0 0 0-.22.842l2.31 2.25-.544 3.177-.007.055a.5.5 0 0 0 .732.472L54 9.439l2.847 1.5.05.023a.5.5 0 0 0 .676-.55l-.546-3.178 2.312-2.25.04-.042a.5.5 0 0 0-.317-.81l-3.19-.463-1.426-2.89a.5.5 0 0 0-.897 0l-1.427 2.89Zm16 0-3.19.462-.056.012a.5.5 0 0 0-.22.842l2.31 2.25-.544 3.177-.007.055a.5.5 0 0 0 .732.472L70 9.439l2.847 1.5.05.023a.5.5 0 0 0 .676-.55l-.546-3.178 2.312-2.25.04-.042a.5.5 0 0 0-.317-.81l-3.19-.463-1.426-2.89a.5.5 0 0 0-.897 0l-1.427 2.89Z"/>
</svg>';
?>
<div class="zak-container help-page">
	<div class="postbox-container" style="float: none;">
		<div class="col-70">
			<h2 style="height:0;margin:0;"><!-- admin notices below this element --></h2>
			<div class="zak-help-top-row">
				<div class="postbox">
					<svg xmlns="http://www.w3.org/2000/svg" width="33" height="33" viewBox="0 0 33 33" fill="none">
						<path d="M19.833 3.00977H8.49967C7.79243 3.00977 7.11415 3.29072 6.61406 3.79081C6.11396 4.29091 5.83301 4.96919 5.83301 5.67643V27.0098C5.83301 27.717 6.11396 28.3953 6.61406 28.8954C7.11415 29.3955 7.79243 29.6764 8.49967 29.6764H24.4997C25.2069 29.6764 25.8852 29.3955 26.3853 28.8954C26.8854 28.3953 27.1663 27.717 27.1663 27.0098V10.3431L19.833 3.00977Z" stroke="#2563EB" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
						<path d="M19.1665 3.00977V11.0098H27.1665" stroke="#2563EB" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
						<path d="M21.8332 17.6758H11.1665" stroke="#2563EB" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
						<path d="M21.8332 23.0098H11.1665" stroke="#2563EB" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
						<path d="M13.8332 12.3428H11.1665" stroke="#2563EB" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
					</svg>
					<h3><?php esc_html_e( 'Need Some Help?','zakra' ); ?></h3>
					<p><?php esc_html_e( 'Please check out basic documentation for detailed information on how to use Zakra.','zakra' ); ?></p>
					<a href="<?php echo esc_url( 'https://docs.zakratheme.com/en/' ); ?>" class="help-box" target="_blank"><span><?php esc_html_e( 'View Now','zakra' ); ?></span></a>
				</div>
				<div class="postbox">
					<svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 33 33" fill="none">
						<path d="M19.1667 20.3428V23.0094C19.1667 23.3631 19.0262 23.7022 18.7761 23.9523C18.5261 24.2023 18.187 24.3428 17.8333 24.3428H8.5L4.5 28.3428V15.0094C4.5 14.6558 4.64048 14.3167 4.89052 14.0666C5.14057 13.8166 5.47971 13.6761 5.83333 13.6761H8.5M28.5 19.0094L24.5 15.0094H15.1667C14.813 15.0094 14.4739 14.869 14.2239 14.6189C13.9738 14.3689 13.8333 14.0297 13.8333 13.6761V5.67611C13.8333 5.32248 13.9738 4.98335 14.2239 4.7333C14.4739 4.48325 14.813 4.34277 15.1667 4.34277H27.1667C27.5203 4.34277 27.8594 4.48325 28.1095 4.7333C28.3595 4.98335 28.5 5.32248 28.5 5.67611V19.0094Z" stroke="#2563EB" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
					</svg>
					<h3><?php esc_html_e( 'Support','zakra' ); ?></h3>
					<p><?php esc_html_e( 'We would be happy to guide you through any issues and queries you have regarding Zakra!','zakra' ); ?></p>
					<a href="<?php echo esc_url( 'https://zakratheme.com/support/' ); ?>" target="_blank"><span><?php esc_html_e( 'Contact Support','zakra' ); ?></span></a>
				</div>
			</div>
			<h2><?php esc_html_e( 'Join Our Community','zakra' ); ?></h2>
			<div class="postbox zak-community">
				<div class="zak-image">
					<img src="<?php echo esc_url( ZAKRA_PARENT_URI . '/inc/admin/images/facebook.webp' ); ?>" alt="<?php esc_attr_e( 'facebook', 'zakra' ); ?>">
				</div>
				<div class="zak-content">
					<h3><?php esc_html_e( 'Facebook Community', 'zakra' ); ?></h3>
					<p><?php esc_html_e( 'Join our Facebook community to connect with fellow users for the latest news, updates, and discussions.','zakra' ); ?></p>
					<a href="<?php echo esc_url( 'https://www.facebook.com/groups/themegrill/' ); ?>" target="_blank"><span><?php esc_html_e( 'Join us on Facebook','zakra' ); ?></span></a>
				</div>
			</div>
			<div class="postbox zak-community">
				<div class="zak-image">
					<img src="<?php echo esc_url( ZAKRA_PARENT_URI . '/inc/admin/images/x.webp' ); ?>" alt="<?php esc_attr_e( 'x', 'zakra' ); ?>">
				</div>
				<div class="zak-content">
					<h3><?php esc_html_e( 'X Community','zakra' ); ?></h3>
					<p><?php esc_html_e( 'Follow us on Twitter and stay in the loop for the latest news and updates on our products!','zakra' ); ?></p>
					<a href="<?php echo esc_url( 'https://twitter.com/themegrill' ); ?>" target="_blank"><span><?php esc_html_e( 'Join us on Twitter','zakra' ); ?></span></a>
				</div>
			</div>
			<div class="postbox zak-community">
				<div class="zak-image">
					<img src="<?php echo esc_url( ZAKRA_PARENT_URI . '/inc/admin/images/youtube.webp' ); ?>" alt="<?php esc_attr_e( 'youtube', 'zakra' ); ?>">
				</div>
				<div class="zak-content">
					<h3><?php esc_html_e( 'Youtube Community','zakra' ); ?></h3>
					<p><?php esc_html_e( 'Visit our YouTube channel for videos about tutorials, updates, and news on our products!','zakra' ); ?></p>
					<a href="<?php echo esc_url( 'https://www.youtube.com/@ThemeGrillOfficial' ); ?>" target="_blank"><span><?php esc_html_e( 'Visit us on YouTube','zakra' ); ?></span></a>
				</div>
			</div>
		</div> <!--/.col-70-->
		<div class="col-30">
			<div class="postbox">
				<h3 class="zak-review-title hndle">
					<svg xmlns="http://www.w3.org/2000/svg" width="21" height="20" viewBox="0 0 21 20" fill="none">
						<path
								d="M10.5001 1.66699L13.0751 6.88366L18.8334 7.72533L14.6667 11.7837L15.6501 17.517L10.5001 14.8087L5.35008 17.517L6.33341 11.7837L2.16675 7.72533L7.92508 6.88366L10.5001 1.66699Z"
								stroke="#2563EB" stroke-width="1.66667" stroke-linecap="round"
								stroke-linejoin="round"/>
					</svg>
					<span><?php esc_html_e( 'Leave us a Review', 'zakra' ); ?></span>
				</h3>
				<div class="inside">
					<div class="ratings">
								<span>
										<?php
										echo $star_icon;
										?>
								</span>
						<span><?php esc_html_e( 'Based on 1430+ Reviews', 'zakra' ); ?></span>
					</div>
					<p>
						<?php
						echo sprintf(
						/* translators: %s: Theme Name. */
							esc_html__( 'Sharing your review is a valuable way to help us enhance your experience.', 'zakra' ),
							$theme->Name
						);
						?>
					</p>
					<a href="<?php echo esc_url( 'https://wordpress.org/support/theme/zakra/reviews/?rate=5#new-post' ); ?>"
					   target="_blank"><?php esc_html_e( 'Submit a Review', 'zakra' ); ?></a>
				</div>
			</div>
			<div class="postbox">
				<h3 class="zak-feature-request-title hndle">
					<svg xmlns="http://www.w3.org/2000/svg" width="21" height="20" viewBox="0 0 21 20"
						 fill="none">
						<path
								d="M12.9998 11.667C13.1664 10.8337 13.5831 10.2503 14.2498 9.58366C15.0831 8.83366 15.4998 7.75033 15.4998 6.66699C15.4998 5.34091 14.973 4.06914 14.0353 3.13146C13.0976 2.19378 11.8258 1.66699 10.4998 1.66699C9.17367 1.66699 7.9019 2.19378 6.96422 3.13146C6.02654 4.06914 5.49976 5.34091 5.49976 6.66699C5.49976 7.50033 5.66642 8.50033 6.74976 9.58366C7.33309 10.167 7.83309 10.8337 7.99976 11.667"
								stroke="#2563EB" stroke-width="1.66667" stroke-linecap="round"
								stroke-linejoin="round"/>
						<path d="M7.99988 15H12.9999" stroke="#2563EB" stroke-width="1.66667"
							  stroke-linecap="round" stroke-linejoin="round"/>
						<path d="M8.83325 18.333H12.1666" stroke="#2563EB" stroke-width="1.66667"
							  stroke-linecap="round" stroke-linejoin="round"/>
					</svg>
					<span><?php esc_html_e( 'Feature Request', 'zakra' ); ?></span>
				</h3>
				<div class="inside">
					<p>
						<?php
						echo sprintf(
						/* translators: %s: Theme Name. */
							esc_html__( 'Please take a moment to suggest any features that could enhance our product.', 'zakra' ),
						);
						?>
					</p>
					<a href="<?php echo esc_url( 'https://zakra.feedbear.com/boards/feature-requests' ); ?>"
					   target="_blank"><?php esc_html_e( 'Request a Feature', 'zakra' ); ?></a>
				</div>
			</div>
			<div class="postbox zak-useful-plugins">
				<h3 class="zak-useful-plugins-title hndle">
					<span><?php esc_html_e( 'Useful Plugins', 'zakra' ); ?></span>
				</h3>
				<?php
				$useful_plugins = array(
					array(
						'name'        => 'Everest Forms',
						'file'        => 'everest-forms/everest-forms.php',
						'slug'        => 'everest-forms',
						'description' => 'Form Builder Plugin',
						'color'       => '#5317AA',
						'svg'         => '<svg xmlns="http://www.w3.org/2000/svg" width="41" height="40" viewBox="0 0 41 40 fill="none">
                                            <rect x="0.5" width="40" height="40" rx="3.63636" fill="#5317AA"/>
                                            <path d="M27.309 11.1045H22.9999L24.3223 13.3268H28.6186L27.309 11.1045Z"
                                                  fill="white"/>
                                            <path d="M30.0183 15.5527H25.7156L27.1025 17.7751H31.4085L30.0183 15.5527Z"
                                                  fill="white"/>
                                            <path
                                                d="M29.9506 26.6704H13.5493L20.4292 15.4136L23.2772 20.0002H20.4292L19.11 22.2225H27.2412L20.4292 11.2432L9.5885 28.8959H31.3408L29.9506 26.6704Z"
                                                fill="white"/>
                                        </svg>',
					),
					array(
						'name'        => 'BlockArt',
						'file'        => 'blockart-blocks/blockart.php',
						'slug'        => 'blockart',
						'description' => 'Page Builder Plugin',
						'color'       => '#2563EB',
						'svg'         => '<svg xmlns="http://www.w3.org/2000/svg" width="41" height="40" viewBox="0 0 41 40" fill="none">
                                            <rect x="0.5" width="40" height="40" rx="3.63636" fill="#2563EB"/>
                                            <path fill-rule="evenodd" clip-rule="evenodd"
                                                  d="M8.5 31.2027H32.5V8.5H8.5V31.2027ZM31.2027 29.9054H9.79728V9.7973H31.2027V29.9054ZM20.7109 11.7432L22.3204 17.1421L19.094 17.1563L20.7109 11.7432ZM18.0383 20.7249H23.3909L24.3433 25.5563L24.4319 27.3107H16.8496L16.9161 25.5351L18.0383 20.7249Z"
                                                  fill="white"/>
                                        </svg>',
					),
					// Add more plugins as needed
				);

				// Loop through the plugins
				foreach ( $useful_plugins as $useful_plugin ) {
					$plugin_file         = $useful_plugin['file'];
					$plugin_slug         = $useful_plugin['slug'];
					$is_plugin_installed = zakra_is_plugin_installed( $plugin_file );
					$is_plugin_activated = is_plugin_active( $plugin_file );
					?>
					<div class="inside">
						<div class="content-left">
							<?php echo wp_kses( $useful_plugin['svg'], Zakra_SVG_Icons::$allowed_html ); ?>
							<div class="content-info">
								<h4><?php echo esc_html( $useful_plugin['name'] ); ?></h4>
								<p><?php echo esc_html( $useful_plugin['description'] ); ?></p>
							</div>
						</div>
						<?php if ( $is_plugin_installed ) : ?>
							<?php if ( $is_plugin_activated ) : ?>
								<span><?php esc_html_e( 'Activated', 'zakra' ); ?></span>
							<?php else : ?>
								<span><a href="#" class="activate-plugin" data-plugin="<?php echo esc_attr( $plugin_file ); ?>" data-slug="<?php echo esc_attr( $plugin_slug ); ?> "><?php esc_html_e( 'Activate', 'zakra' ); ?></span></a>
							<?php endif; ?>
						<?php else : ?>
							<span><a href="#" class="install-plugin" data-plugin="<?php echo esc_attr( $plugin_file ); ?>" data-slug="<?php echo esc_attr( $plugin_slug ); ?> "><?php esc_html_e( 'Install', 'zakra' ); ?></span></a>
						<?php endif; ?>
					</div>
					<?php
				}
				?>
			</div>
		</div><!--/.col-30-->
	</div>
</div>
