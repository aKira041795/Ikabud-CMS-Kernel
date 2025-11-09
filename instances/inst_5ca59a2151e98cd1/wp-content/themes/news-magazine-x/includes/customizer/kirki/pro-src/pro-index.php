<?php

defined( 'ABSPATH' ) || exit;

// Run only in customizer preview
if ( !is_customize_preview() ) {
	return;
}

if ( ! class_exists( '\Kirki\Pro\HeadlineDivider\Init' ) ) {
	require_once NEWSX_CUSTOMIZER_DIR .'/kirki/pro-src/packages/kirki-pro-headline-divider/vendor/autoload.php';
	new \Kirki\Pro\HeadlineDivider\Init();
}

if ( ! class_exists( '\Kirki\Pro\InputSlider\Init' ) ) {
	require_once NEWSX_CUSTOMIZER_DIR .'/kirki/pro-src/packages/kirki-pro-input-slider/vendor/autoload.php';
	new \Kirki\Pro\InputSlider\Init();
}

if ( ! class_exists( '\Kirki\Pro\MarginPadding\Init' ) ) {
	require_once NEWSX_CUSTOMIZER_DIR .'/kirki/pro-src/packages/kirki-pro-margin-padding/vendor/autoload.php';
	new \Kirki\Pro\MarginPadding\Init();
}

if ( ! class_exists( '\Kirki\Pro\Tabs\Init' ) ) {
	require_once NEWSX_CUSTOMIZER_DIR .'/kirki/pro-src/packages/kirki-pro-tabs/vendor/autoload.php';
	new \Kirki\Pro\Tabs\Init();
}

if ( ! class_exists( '\Kirki\Pro\Responsive\Init' ) ) {
	require_once NEWSX_CUSTOMIZER_DIR .'/kirki/pro-src/packages/kirki-pro-responsive/vendor/autoload.php';
	new \Kirki\Pro\Responsive\Init();
}