<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

get_header();

?>

<div id="content" class="site-content">
    <main id="main" class="site-main">
    
    <div class="newsx-archive-page-wrap<?php echo esc_attr(newsx_get_boxed_content_class()); ?>">

    <?php

    // Content Area
    echo '<div id="primary" class="content-area primary">';

    echo '<h1>'. esc_html__('Page Not Found', 'news-magazine-x') .'</h1>';
    echo '<p>'. esc_html__('It seems we can\'t find what you\'re looking for. Perhaps searching can help.', 'news-magazine-x') .'</p>';

    // Search Form
    echo '<div class="newsx-404-page-search">';
        get_search_form();

        // Search Icon
        echo '<div class="newsx-search-icon">';
            newsx_default_icon_markup('search', true);
        echo '</div>';
	echo '</div>';

    echo '</div>'; // #primary

    ?>

    </div> <!-- .newsx-archive-page-wrap -->

</main>
</div>

<?php

get_footer();
