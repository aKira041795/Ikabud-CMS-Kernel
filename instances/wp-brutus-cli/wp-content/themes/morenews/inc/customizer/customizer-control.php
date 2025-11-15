<?php
/**
 * Custom Customizer Controls.
 *
 * @package MoreNews
 */

/**
 * Custom Controls of theme
 *
 * @package StoreCommerce
 */
class MoreNews_Section_Title extends WP_Customize_Control
{
    public $type = 'section-title';
    public $label = '';
    public $description = '';

    public function enqueue()
    {

        wp_enqueue_style('morenews-custom-controls-css', trailingslashit(get_template_directory_uri()) . 'inc/customizer/css/customizer.css', array(), '1.0', 'all');
    }

    public function render_content()
    {
        ?>
        <h3><?php echo esc_html($this->label); ?></h3>
        <?php if (!empty($this->description)) { ?>
        <span class="customize-control-description"><?php echo esc_html($this->description); ?></span>
    <?php } ?>
        <?php
    }
}


/**
 * Custom Controls of theme
 *
 * @package StoreCommerce
 */
class MoreNews_Section_Subtitle extends WP_Customize_Control
{
    public $type = 'section-subtitle';
    public $label = '';
    public $description = '';

    public function enqueue()
    {
        wp_enqueue_style('morenews-custom-controls-css', trailingslashit(get_template_directory_uri()) . 'inc/customizer/css/customizer.css', array(), '1.0', 'all');
    }

    public function render_content()
    {
        ?>
        <h4><?php echo esc_html($this->label); ?></h4>
        <?php if (!empty($this->description)) { ?>
        <span class="customize-control-description"><?php echo esc_html($this->description); ?></span>
    <?php } ?>
        <?php
    }
}


/**
 * Simple Notice Custom Control
 *
 * @since 1.0.0
 *
 * @see WP_Customize_Control
 */
class MoreNews_Simple_Notice_Custom_Control extends WP_Customize_Control
{
    /**
     * The type of control being rendered
     */
    public $type = 'simple_notice';

    /**
     * Render the control in the customizer
     */
    public function render_content()
    {
        $allowed_html = array(
            'a' => array(
                'href' => array(),
                'title' => array(),
                'class' => array(),
                'target' => array(),
            ),
            'br' => array(),
            'em' => array(),
            'strong' => array(),
            'i' => array(
                'class' => array()
            ),
            'span' => array(
                'class' => array(),
            ),
            'code' => array(),
        );
        ?>
        <div class="simple-notice-custom-control">
            <?php if (!empty($this->label)) { ?>
                <span class="customize-control-title"><?php echo esc_html($this->label); ?></span>
            <?php } ?>
            <?php if (!empty($this->description)) { ?>
                <span class="customize-control-description"><?php echo wp_kses($this->description, $allowed_html); ?></span>
            <?php } ?>
        </div>
        <?php
    }
}


/**
 * Customize Control for Taxonomy Select.
 *
 * @since 1.0.0
 *
 * @see WP_Customize_Control
 */
class MoreNews_Dropdown_Taxonomies_Control extends WP_Customize_Control
{

    /**
     * Control type.
     *
     * @access public
     * @var string
     */
    public $type = 'dropdown-taxonomies';

    /**
     * Taxonomy.
     *
     * @access public
     * @var string
     */
    public $taxonomy = '';

    /**
     * Constructor.
     *
     * @param WP_Customize_Manager $manager Customizer bootstrap instance.
     * @param string $id Control ID.
     * @param array $args Optional. Arguments to override class property defaults.
     * @since 1.0.0
     *
     */
    public function __construct($manager, $id, $args = array())
    {

        $our_taxonomy = 'category';
        if (isset($args['taxonomy'])) {
            $taxonomy_exist = taxonomy_exists($args['taxonomy']);
            if (true === $taxonomy_exist) {
                $our_taxonomy = $args['taxonomy'];
            }
        }
        $args['taxonomy'] = $our_taxonomy;
        $this->taxonomy = $our_taxonomy;

        parent::__construct($manager, $id, $args);
    }

    /**
     * Render content.
     *
     * @since 1.0.0
     */
    public function render_content()
    {

        $tax_args = array(
            'hierarchical' => 0,
            'taxonomy' => $this->taxonomy,
        );
        $all_taxonomies = get_categories($tax_args);

        ?>
        <label>
            <span class="customize-control-title"><?php echo esc_html($this->label); ?></span>
            <select <?php $this->link(); ?>>
                <?php
                printf('<option value="%s" %s>%s</option>', 0, selected($this->value(), '', false), esc_html__('-- Select --', 'morenews'));
                ?>
                <?php if (!empty($all_taxonomies)) : ?>
                    <?php foreach ($all_taxonomies as $key => $tax) : ?>
                        <?php
                        printf('<option value="%s" %s>%s</option>', esc_attr($tax->term_id), selected($this->value(), $tax->term_id, false), esc_html($tax->name));
                        ?>
                    <?php endforeach ?>
                <?php endif ?>
            </select>
        </label>
        <?php
    }
}


/**
 * Customize Control for Radio Image.
 *
 * @since 1.0.0
 *
 * @see WP_Customize_Control
 */
class MoreNews_Radio_Image_Control extends WP_Customize_Control
{

    /**
     * Control type.
     *
     * @access public
     * @var string
     */
    public $type = 'radio-image';

    /**
     * Render content.
     *
     * @since 1.0.0
     */
    public function render_content()
    {

        if (empty($this->choices)) {
            return;
        }

        $name = '_customize-radio-' . $this->id;

        ?>
        <label>
            <?php if (!empty($this->label)) : ?>
                <span class="customize-control-title"><?php echo esc_html($this->label); ?></span>
            <?php endif; ?>
            <?php if (!empty($this->description)) : ?>
                <span class="description customize-control-description"><?php echo wp_kses_post($this->description); ?></span>
            <?php endif; ?>

            <?php foreach ($this->choices as $value => $label) : ?>
                <label>
                    <input type="radio" value="<?php echo esc_attr($value); ?>" <?php $this->link();
                    checked($this->value(), $value); ?> class="np-radio-image" name="<?php echo esc_attr($name); ?>"/>
                    <span><img src="<?php echo esc_url($label); ?>" alt="<?php echo esc_attr($value); ?>"/></span>
                </label>
            <?php endforeach; ?>
        </label>
        <?php
    }
}


/**
 * Upsell customizer section.
 *
 * @since  1.0.0
 * @access public
 */
class MoreNews_Customize_Section_Upsell extends WP_Customize_Section
{

    /**
     * The type of customize section being rendered.
     *
     * @since  1.0.0
     * @access public
     * @var    string
     */
    public $type = 'upsell';

    /**
     * Custom button text to output.
     *
     * @since  1.0.0
     * @access public
     * @var    string
     */
    public $pro_text = '';

    /**
     * Custom pro button URL.
     *
     * @since  1.0.0
     * @access public
     * @var    string
     */
    public $pro_url = '';

    /**
     * Add custom parameters to pass to the JS via JSON.
     *
     * @return void
     * @since  1.0.0
     * @access public
     */
    public function json()
    {
        $json = parent::json();

        $json['pro_text'] = $this->pro_text;
        $json['pro_url'] = esc_url($this->pro_url);

        return $json;
    }

    /**
     * Outputs the Underscore.js template.
     *
     * @return void
     * @since  1.0.0
     * @access public
     */
    protected function render_template()
    { ?>

        <li id="accordion-section-{{ data.id }}"
            class="accordion-section control-section control-section-{{ data.type }} cannot-expand">

            <h3 class="accordion-section-title">
                {{ data.title }}

                <# if ( data.pro_text && data.pro_url ) { #>
                <a href="{{ data.pro_url }}" class="button button-secondary alignright" target="_blank">{{ data.pro_text
                    }}</a>
                <# } #>
            </h3>
        </li>
    <?php }
}