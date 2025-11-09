<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

?>

    <!-- Site Footer -->
    <footer id="site-footer" class="newsx-site-footer">
        <?php // Include Templates

        // Top Section
        get_template_part( 'template-parts/footer/sections/top-section' );

        // Middle Section
        get_template_part( 'template-parts/footer/sections/middle-section' );

        // Bottom Section
        get_template_part( 'template-parts/footer/sections/bottom-section' );

        // Back to Top Button
        get_template_part( 'template-parts/footer/elements/back-to-top' );

        // Preloader
        if ( defined('NEWSX_CORE_PRO_VERSION') && newsx_core_pro_fs()->can_use_premium_code() ) {
            $pro_template = NEWSX_CORE_PRO_PATH . 'public/template-parts/preloader.php';
            if ( file_exists($pro_template) ) {
                load_template($pro_template, false);
            }
        }

        ?>
    </footer>

	</div><!-- #page -->

<?php wp_footer(); ?>

</body>
</html>