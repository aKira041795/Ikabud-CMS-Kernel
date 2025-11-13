<?php
if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly.
}

?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>

<head>
	<meta charset="<?php bloginfo('charset'); ?>">
	<?php if (!current_theme_supports('title-tag')) : ?>
		<title><?php echo wp_get_document_title(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped 
				?></title>
	<?php
	endif; ?>
	<?php wp_head(); ?>

</head>

<body <?php body_class('blockspare-blank-canvas'); ?>>
	<?php
	if (function_exists('wp_is_block_theme')) {
		if (wp_is_block_theme()) {
			block_template_part('header');
			
		} else {
			get_header();
		}
	} else {
		get_header();
	}
	?>
	<div class="blockspare-page-section">
		<?php the_content(); ?>
	</div>
	<?php


	if (function_exists('wp_is_block_theme')) {
		if (wp_is_block_theme()) {
			block_template_part('footer');
		} else {
			get_footer();
		}
	} else {
		get_footer();
	}

	wp_footer();
	?>
</body>

</html>