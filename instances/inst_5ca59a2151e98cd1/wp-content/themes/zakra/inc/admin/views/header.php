<?php
/**
 * Zakra dashboard starter templates page.
 *
 * @author ThemeGrill
 * @package Zakra
 * @since @todo
 */

defined( 'ABSPATH' ) || exit;

?>

<div class="zak-wrap">
	<div class="zak-metabox-holder">
		<div class="zakra-header">
			<div class="zak-container" id="zak-dashboard-menu">
				<a class="zak-title" href="<?php echo esc_url( 'https://themegrill.com/themes/zakra/' ); ?>"
					target="_blank">
					<img class="zak-icon"
						src="<?php echo esc_url( ZAKRA_PARENT_URI . '/inc/admin/images/zak-logo.png' ); ?>"
						alt="<?php esc_attr_e( 'Zakra', 'zakra' ); ?>">
				</a>
				<div class="zak-dashboard-menu-container">
					<ul id="zak-dashboard-menu-primary" class="zak-dashboard-menu-primary">
						<li>
							<a
								href="<?php echo admin_url() . 'themes.php?page=zakra#/dashboard'; ?>">
								<?php esc_html_e( 'Dashboard', 'zakra' ); ?>
							</a>
						</li>
						<?php if ( zakra_is_zakra_pro_active() && zakra_plugin_version_compare( 'zakra-pro/zakra-pro.php', '3.1.0', '>=' ) ) : ?>
						<li class="zak-starter-templates-active">
							<a
								href="<?php echo admin_url() . 'admin.php?page=zakra-starter-templates&browse=all'; ?>">
								<?php esc_html_e( 'Starter Templates', 'zakra' ); ?>
							</a>
						</li>
						<?php else : ?>
							<li class="zak-starter-templates-active">
							<a
								href="<?php echo admin_url() . 'themes.php?page=zakra-starter-templates&browse=all'; ?>">
								<?php esc_html_e( 'Starter Templates', 'zakra' ); ?>
							</a>
						</li>
						<?php endif; ?>
						<li>
							<a
								href="<?php echo admin_url() . 'themes.php?page=zakra#/products'; ?>">
								<?php esc_html_e( 'Products', 'zakra' ); ?>
							</a>
						</li>
						<li>
							<a
								href="<?php echo admin_url() . 'themes.php?page=zakra#/free-vs-pro'; ?>">
								<?php esc_html_e( 'Free vs Pro', 'zakra' ); ?>
							</a>
						</li>
						<li>
							<a
								href="<?php echo admin_url() . 'themes.php?page=zakra#/help'; ?>">
								<?php esc_html_e( 'Help', 'zakra' ); ?>
							</a>
						</li>
					</ul>
				</div>
				<div class="zak-dashboard-head-left">
					<span class="zak-version">
						<?php echo esc_html( $this->theme->get( 'Version' ) ); ?>
						<span class="zak-core">
							<?php esc_html_e( 'Core', 'zakra' ); ?>
						</span>
					</span>
					<?php if ( zakra_is_zakra_pro_active() ) : ?>
					<span class="zak-version zakra-pro-version" style="border-color: #27AE60; color: #27AE60;">
						<?php echo esc_html( ZAKRA_PRO_VERSION ); ?>
						<span class="zak-core">
							<?php esc_html_e( 'Pro', 'zakra' ); ?>
						</span>
					</span>
					<?php else : ?>
					<a class="zakra-pro-version"
						href="<?php echo esc_url( 'https://zakratheme.com/pricing/?utm_medium=dash-header&utm_source=zakra-theme&utm_campaign=header-upgrade-btn&utm_content=upgrade-to-pro' ); ?>"
						target="_blank">
						<span class="zak-upgrade-to-pro">
							<?php esc_html_e( 'Upgrade to Pro', 'zakra' ); ?>
						</span>
					</a>
					<?php endif; ?>
				</div>
			</div>
			<!--/.zak-container-->
		</div>
		<!--/.zakra-header-->
	</div>
	<div class="zak-container m-auto">
		<?php
		if ( is_plugin_active( 'themegrill-demo-importer/themegrill-demo-importer.php' ) ) {
			include plugin_dir_path( TGDM_PLUGIN_FILE ) . '/includes/admin/views/html-admin-page-importer.php';
			return;
		}
		?>
	</div>
	<!--/.zak-metabox-holder-->
</div>
