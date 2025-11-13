<?php 

defined( 'ABSPATH' ) || exit;

if(!class_exists('Elespare_General_Dashboard')){
    class Elespare_General_Dashboard{
        private static $instance;

		public static function instance() {
			if ( ! isset( self::$instance ) ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

        

        public static function elespare_render_menu_page(){?>
            <div class="wrap elespare-wrap">
                <header class="elespare-header">
                    <div class="elespare-header-logo">
                        <img src="<?php echo esc_url(plugins_url('svg/elespare.png', __FILE__)) ?>"
                         alt="<?php esc_attr_e('Elespare', 'elespare') ?>" />
                    </div>     
                    <strong class="es-title">
                        <?php esc_attr_e('Elespare', 'elespare') ?>
                    </strong>
                    <strong><?php esc_attr_e('Empower Elementor Page Builder Using Advanced & Easy to Use Addons', 'elespare') ?></strong>
                </header>
                <section class="elespare-body-container">
                    <div class="elespare-responsive">
                        <img src="<?php echo esc_url(plugins_url('svg/elespare-responsive.png', __FILE__)) ?>"
                         alt="<?php esc_attr_e('Elespare', 'elespare') ?>" />
                    </div>   
                    <div class="elespare-body">

                        <article class="elespare-section elespare-block-intro">

                            <div class="elespare-common-header-wrapper">
                                <span class="elespare-subtitle"><?php esc_attr_e('160+ Layouts in 23 Widgets', 'elespare') ?></span>
                                <strong><?php esc_attr_e('Elespare Custom Widgets', 'elespare') ?></strong>
                                <p>
                                    <?php esc_attr_e('A stunning assortment of WordPress post widgets. It allows you to display your posts in many formats such as grid, list, tile, full, express, and so on.', 'elespare') ?>
                                </p>
                            </div>

                            <!-- We put all the block controls here. -->
                            <div class="elespare-available-blocks-wrapper">
                                <?php
                                $elespare_blocks = elespare_blocks_list();
                                if (isset($elespare_blocks) && !empty($elespare_blocks)):
                                    foreach ($elespare_blocks as $block):
                                        $blocks_name = $block['name'];
                                        $icon_url = plugins_url("svg/" .$block['slug'] . ".svg", __FILE__);
                                        $demo_url = 'https://afthemes.com/plugins/elespare/layout/'.$block['slug'];

                                        ?>
                                        <a class="elespare-explore-btn" href="<?php echo esc_url($demo_url) ?>" target="_blank">
                                            <div class="elespare-available-blocks">
                                                <div class="elespare-available-blocks-img">
                                                    <img src="<?php echo esc_attr($icon_url); ?>" alt="<?php echo esc_attr($blocks_name) ?>">
                                                </div>
                                                <h4>
                                                    <?php echo esc_attr($blocks_name) ?>
                                                </h4>



                                            </div>
                                        </a>
                                    <?php
                                    endforeach;
                                endif;

                                ?>
                            </div>
                            <div class="elespare-all-blocks-button">
                                <a href="https://afthemes.com/plugins/elespare/" class="elespare-button" target="_blank"
                                                                          title="<?php esc_attr_e('Explore All Widgets', 'elespare') ?>"><?php esc_attr_e('Explore All Widgets', 'elespare') ?></a>
                            </div>
                        </article>
                        <aside class="elespare-backward-compatibility-control-wrapper"></aside>

                    </div>
                    <div class="elespare-below-widgets">

                        <div class="elespare-intro-and-pro">
                            <article class="elespare-intro elespare-section">
                                <div class="elespare-intro-col elespare-intro-desc">
                                    <div class="elespare-common-header-wrapper">

                                        <strong><?php esc_attr_e('Envision Amazing Layouts with Elespare', 'elespare') ?></strong>
                                    </div>
                                    <p><?php esc_attr_e('Now you have Elespare page builder for your WordPress', 'elespare') ?></p>
                                    <ol>
                                        <li>
                                            <?php esc_attr_e('Download Elespare plugin', 'elespare') ?>
                                        </li>
                                        <li>
                                            <?php esc_attr_e('Upload to your plugin directory or simply install via WordPress admin interface.', 'elespare') ?>
                                        </li>
                                        <li>
                                            <?php esc_attr_e('Install Elementor Plugin (If not already)', 'elespare') ?>
                                        </li>
                                        <li>
                                            <?php esc_attr_e('Active Elespare Plugin', 'elespare') ?>
                                        </li>
                                        <li>
                                            <?php esc_attr_e('Go to page/post and edit the page with Elementor', 'elespare') ?>
                                        </li>
                                        <li>
                                            <?php esc_attr_e('Click on the button: ', 'elespare') ?> <span class="plus-btn-svg"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 20 20" fill="#222"><path d="M10 1c-5 0-9 4-9 9s4 9 9 9 9-4 9-9-4-9-9-9zm0 16c-3.9 0-7-3.1-7-7s3.1-7 7-7 7 3.1 7 7-3.1 7-7 7zm1-11H9v3H6v2h3v3h2v-3h3V9h-3V6z"></path></svg></span>
                                        </li>
                                        <li>
                                            <?php esc_attr_e('Search and Expand Elespare panel!', 'elespare') ?>
                                        </li>
                                        <li>
                                            <?php esc_attr_e('Select to add and customize a widget!', 'elespare') ?>
                                        </li>
                                    </ol>

                                    <a href="<?php echo esc_url(admin_url('post-new.php?post_type=page&elespare_show_intro=true')) ?>"
                                    class="elespare-button"
                                    title="<?php esc_attr_e('Try it on New Page', 'elespare') ?>"><?php esc_attr_e('Try it on New Page', 'elespare') ?></a>
                                    </p>
                                </div>
                            </article>
                            <?php
                            $elespare_pro = false;
                            if ($elespare_pro == false ) : ?>
                                <aside class="elespare-section elespare-premium-section">
                                    <strong><?php esc_attr_e('Elespare Pro', 'elespare') ?></strong>
                                    <p><?php esc_attr_e('Want to use Elespare Pro to build an awesome site with more options and controls?', 'elespare') ?></p>
                                    <ul class="elespare-premium-features">
                                        <li><?php esc_attr_e('23+ Custom Elementor Widgets', 'elespare') ?></li>
                                        <li><?php esc_attr_e('160+ Pre-designed Layouts', 'elespare') ?></li>
                                        <li><?php esc_attr_e('800+ Google fonts family with font weight and subset controls', 'elespare') ?></li>
                                        <li><?php esc_attr_e('Dark mode and Background options', 'elespare') ?></li>
                                        <li><?php esc_attr_e('Gravatar, Icon options for meta', 'elespare') ?></li>
                                        <li><?php esc_attr_e('Multiple posts query controls like category select, order, number of posts, etc.', 'elespare') ?></li>
                                        <li><?php esc_attr_e('Multiple category display designs', 'elespare') ?></li>
                                        <li><?php esc_attr_e('Posts block image size controls', 'elespare') ?></li>
                                        <li><?php esc_attr_e('Excerpt toggle and length options', 'elespare') ?></li>
                                        <li><?php esc_attr_e('Typography and color controls', 'elespare') ?></li>
                                        <li><?php esc_attr_e('Box-shadow, borders, border radius and image radius ', 'elespare') ?></li>
                                        <li><?php esc_attr_e('Box content gaps and spacing controls', 'elespare') ?></li>
                                        <li><?php esc_attr_e('Highly customizable layouts', 'elespare') ?></li>
                                        <li><?php esc_attr_e('Customer Email Support', 'elespare') ?></li>
                                        <li><?php esc_attr_e('Regular Updates & Premium Support', 'elespare') ?></li>
                                    </ul>
                                    </p>
                                    <p>
                                        <a href="<?php echo esc_url('https://afthemes.com/plugins/elespare/'); ?>" class="elespare-button"
                                           title="<?php esc_attr_e('All Features Available with Elespare Pro', 'elespare') ?>"><?php esc_attr_e('All Features Available with Elespare Pro', 'elespare') ?></a>
                                    </p>

                                </aside>
                            <?php endif; ?>
                            </div>

                            <aside class="elespare-section-knowledge-base">
                                <div class="elespare-common-header-wrapper">
                                    <span class="elespare-subtitle"><?php esc_attr_e('Enhanced set of News/Magazine Widgets', 'elespare') ?></span>
                                    <strong><?php esc_attr_e('Knowledge Base', 'elespare') ?></strong>
                                    <p>
                                        <?php esc_attr_e('Make The Most Of Your News Page Creation Using Elementor', 'elespare') ?>
                                    </p>
                                </div>
                                <ul class="elespare-list elespare-knowledge-base">
                                    <li>
                                        <strong class="elespare-sub-headings">
                                            <a href="https://elespare.com/elespare-docs/"><?php esc_html_e('Documentations', 'elespare') ?> →</a>
                                        </strong>
                                        <p><?php esc_html_e('Do you have any difficulties regarding plugin setup and uses? Please visit Documentations page.', 'elespare') ?></p>
                                    </li>
                                    <li>
                                        <strong class="elespare-sub-headings">
                                            <a href="https://elespare.com/all-demos/"><?php esc_html_e('Demos', 'elespare') ?> →</a>
                                        </strong>
                                        <p><?php esc_html_e('We have also designed and displayed multiple type of layouts with Elespare. For more details please visit our site.', 'elespare') ?></p>
                                    </li>
                                    <li>
                                        <strong class="elespare-sub-headings">
                                            <a href="https://afthemes.com/supports/"><?php esc_html_e('Support', 'elespare') ?> →</a>
                                        </strong>
                                        <p><?php esc_html_e('You directly come upon to our support guys with your valuable queries.', 'elespare') ?></p>
                                    </li>
                                    <li>
                                        <strong class="elespare-sub-headings">
                                            <a href="https://wordpress.org/support/plugin/elespare/"><?php esc_html_e('WordPress Support Forum', 'elespare') ?> →</a>
                                        </strong>
                                        <p><?php esc_html_e('You can also go to our .org forum for any kind of support enquiries.', 'elespare') ?></p>
                                    </li>
                                    <li>
                                        <strong class="elespare-sub-headings">
                                            <a href="https://afthemes.com/"><?php esc_html_e('Compatible Themes', 'elespare') ?> →</a>
                                        </strong>
                                        <p><?php esc_html_e('Get tested compatible themes for Elespare.', 'elespare') ?></p>
                                    </li>
                                    <li>
                                        <strong class="elespare-sub-headings">
                                            <a href="https://afthemes.com/blog/"><?php esc_html_e('Latest Blog', 'elespare') ?> →</a>
                                        </strong>
                                        <p><?php esc_html_e('Get latest articles about WordPress.', 'elespare') ?></p>
                                    </li>
                                </ul>

                                </p>
                            </aside>
                    </div>
                </section>
            </div>
            <?php
        }

    }
    Elespare_General_Dashboard::instance();
}


