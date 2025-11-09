<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

get_header();

?>

<div id="content" class="site-content">
    <main id="main" class="site-main">

        <?php get_template_part( 'template-parts/blog-single/single-wrap' ); ?>

    </main>
</div>

<?php

get_footer();
