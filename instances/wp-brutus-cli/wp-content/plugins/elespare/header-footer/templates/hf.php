<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<link rel="profile" href="https://gmpg.org/xfn/11">

	<?php wp_head(); ?>
</head>

<body <?php body_class(); ?>>
	<main id="ht-hf-primary">


		<?php
		while ( have_posts() ) :
			the_post();


			elspare_header_footer_content();
			// If comments are open or we have at least one comment, load up the comment template.


		endwhile; // End of the loop.
		wp_reset_postdata();
		?>

	</main><!-- #primary -->
</body>
<?php
wp_footer();

