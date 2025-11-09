<?php
/**
 * Changelog template.
 *
 * @author ThemeGrill
 * @package Zakra
 * @since @todo
 */

defined( 'ABSPATH' ) || exit;
?>
<?php if ( $changelog ) : ?>
	<div class="zak-changelog">
		<?php foreach ( $changelog as $change ) : ?>
			<div class="zak-changelog__list-item">
				<div class="zak-changelog__list-head">
					<h4 class="zak-changelog__version">
						<?php echo esc_html( $change['version'] ); ?>
					</h4>
					<p class="zak-changelog__date">
						<?php echo esc_html( $change['date'] ); ?>
					</p>
				</div>
				<div class="zak-changelog__change">
					<?php foreach ( $change['changes'] as $change_tag => $changes ) : ?>
						<div
							class="zak-changelog__change-item item--<?php echo esc_attr( strtolower( $change_tag ) ); ?>">
							<span class="zak-changelog__change-type">
								<?php echo esc_html( strtolower( $change_tag ) ); ?>
							</span>
							<div class="zak-changelog__change-list">
								<?php foreach ( $changes as $change ) : ?>
									<p class="zak-changelog__change-desc">
										<?php echo esc_html( $change ); ?>
									</p>
								<?php endforeach; ?>
							</div>
						</div>
					<?php endforeach ?>
				</div>
			</div>
		<?php endforeach; ?>
	</div>
<?php else : ?>
	<p><?php esc_html_e( 'No changelog data available', 'zakra' ); ?></p>
<?php endif; ?>
