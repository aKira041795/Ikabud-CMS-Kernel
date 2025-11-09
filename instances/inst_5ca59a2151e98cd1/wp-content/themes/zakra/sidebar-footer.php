<?php
/**
 * The sidebar containing the footer widget area
 *
 * @link    https://developer.wordpress.org/themes/basics/template-files/#template-partials
 *
 * @package zakra
 */

if (
	! is_active_sidebar( 'footer-sidebar-1' ) &&
	! is_active_sidebar( 'footer-sidebar-2' ) &&
	! is_active_sidebar( 'footer-sidebar-3' ) &&
	! is_active_sidebar( 'footer-sidebar-4' )
) {
	return;
}

$zakra_footer_layout                = get_theme_mod( 'zakra_footer_column_layout', 'layout-1' );
$zakra_footer_column_layout_1_style = apply_filters(
	'zakra_footer_column_layout_1_style',
	get_theme_mod( 'zakra_footer_column_layout_1_style', 'style-4' )
);

$zakra_footer_column_layout_2__style = apply_filters(
	'zakra_footer_column_layout_2_style',
	get_theme_mod( 'zakra_footer_column_layout_12_style', 'style-12' )
);

$footer_sidebar_classes = apply_filters(
	'zakra_footer_sidebar_filter',
	array(
		'style-1' => array(
			'1',
		),
		'style-2' => array(
			'1',
			'2',
		),
		'style-3' => array(
			'1',
			'2',
			'3',
		),
		'style-4' => array(
			'1',
			'2',
			'3',
			'4',
		),
	)
);
?>

<?php
foreach ( $footer_sidebar_classes as $footer_sidebar_key => $footer_sidebar_class ) {
	if ( $footer_sidebar_key === $zakra_footer_column_layout_1_style && 'layout-1' === $zakra_footer_layout ) {
		zakra_footer_column_sidebar( $footer_sidebar_class );
	}

	if ( $footer_sidebar_key === $zakra_footer_column_layout_2__style && 'layout-2' === $zakra_footer_layout ) {
		zakra_footer_column_sidebar( $footer_sidebar_class );
	}
}

function zakra_footer_column_sidebar( $footer_sidebar_class ) {
	foreach ( $footer_sidebar_class as $footer_sidebar_display ) {
		?>
		<div class="zak-footer-col zak-footer-col--<?php echo esc_attr( $footer_sidebar_display ); ?>">
			<?php
			if ( is_active_sidebar( 'footer-sidebar-' . $footer_sidebar_display ) ) {
				dynamic_sidebar( 'footer-sidebar-' . $footer_sidebar_display );
			}
			?>
		</div>
		<?php
	}
}

