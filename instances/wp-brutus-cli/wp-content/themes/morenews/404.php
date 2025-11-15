<?php
/**
 * The template for displaying 404 pages (not found)
 *
 * @link https://codex.wordpress.org/Creating_an_Error_404_Page
 *
 * @package MoreNews
 */

get_header(); ?>
    <div class="section-block-upper">

                <div id="primary" class="content-area">
                    <main id="main" class="site-main">

                        <section class="error-404 not-found">
                            <div class="error-wrap">
                            <header class="header-title-wrapper">
                                <h1 class="page-title"><?php esc_html_e('Oops! That page can&rsquo;t be found.', 'morenews'); ?></h1>
                            </header><!-- .header-title-wrapper -->

                            <div class="page-content">
                                <p><?php esc_html_e('It looks like nothing was found at this location. Maybe try one of the links below or a search?', 'morenews'); ?></p>

                                <?php
                                get_search_form();
                                ?>
                            </div><!-- .page-content -->
                            </div>
                        </section><!-- .error-404 -->

                    </main><!-- #main -->
                </div><!-- #primary -->
            

    </div>
<?php


get_footer();
