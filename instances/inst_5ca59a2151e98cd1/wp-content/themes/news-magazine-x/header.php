<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

?>

<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1.0" />
	<link rel="profile" href="https://gmpg.org/xfn/11">

<?php wp_head(); ?>
</head>

<body <?php body_class(); ?>>
	<?php do_action( 'wp_body_open' ); ?>

	<a class="skip-link screen-reader-text" href="#content"><?php _e( 'Skip to content', 'news-magazine-x' ); ?></a>
    
    <div id="page" class="site">

	<header id="site-header" class="newsx-site-header">
        <?php // Include Templates

        // Top Section
        get_template_part( 'template-parts/header/sections/top-section' );

        // Middle Section
        get_template_part( 'template-parts/header/sections/middle-section' );

        // Bottom Section
        get_template_part( 'template-parts/header/sections/bottom-section' );

        ?>
	</header>