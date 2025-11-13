<?php

/**
 * Elementor Classes.
 *
 * @package elespare-pro
 */

namespace Elespare\Widgets;

use Elementor\Widget_Base;

use Elementor\Controls_Manager;
use Elementor\Control_Media;
use Elementor\Utils;

use Elementor\Group_Control_Image_Size;

use Elementor\Plugin;


if (! defined('ABSPATH')) {
  exit;   // Exit if accessed directly.
}

/**
 * Elespare Site Logo widget
 *
 * Elespare widget for Site Logo.
 *
 * @since 1.0.0
 */
class SiteLogo extends Widget_Base
{

  /**
   * Retrieve the widget name.
   *
   * @since 1.0.0
   *
   * @access public
   *
   * @return string Widget name.
   */
  public function get_name()
  {
    return 'elespare-site-logo';
  }

  /**
   * Retrieve the widget title.
   *
   * @since 1.0.0
   *
   * @access public
   *
   * @return string Widget title.
   */
  public function get_title()
  {
    return esc_html__('Site Logo', 'elespare');
  }

  public function has_widget_inner_wrapper(): bool
  {
    return ! Plugin::$instance->experiments->is_feature_active('e_optimized_markup');
  }


  /**
   * Retrieve the widget icon.
   *
   * @since 1.0.0
   *
   * @access public
   *
   * @return string Widget icon.
   */
  public function get_icon()
  {
    return 'demo-icon elespare-icons-site-logo';
  }

  /**
   * Retrieve the list of categories the widget belongs to.
   *
   * Used to determine where to display the widget in the editor.
   *
   * Note that currently Elementor supports only one category.
   * When multiple categories passed, Elementor uses the first one.
   *
   * @since 1.0.0
   *
   * @access public
   *
   * @return array Widget categories.
   */
  public function get_categories()
  {
    return array('elespare');
  }


  /**
   * Register Site Logo controls.
   *
   * @since 1.5.7
   * @access protected
   */
  protected function register_controls()
  {
    $this->register_content_site_logo_controls();
    $this->register_site_logo_styling_controls();
  }

  /**
   * Register Site Logo General Controls.
   *
   * @since 1.0.0
   * @access protected
   */
  protected function register_content_site_logo_controls()
  {
    $this->start_controls_section(
      'section_site_image',
      [
        'label' => esc_html__('Site Logo', 'elespare'),
      ]
    );


    $this->add_control(
      'custom_image',
      [
        'label'     => esc_html__('Add Image', 'elespare'),
        'type'      => Controls_Manager::MEDIA,
        'dynamic'   => [
          'active' => true,
        ],
        'default'   => [
          'url' => Utils::get_placeholder_image_src(),
        ],

        'condition' => [
          'site_logo_fallback' => 'yes'
        ]

      ]
    );

    $this->add_group_control(
      Group_Control_Image_Size::get_type(),
      [
        'name'    => 'site_logo_size',
        'label'   => esc_html__('Image Size', 'elespare'),
        'default' => 'thumbnail',
        'condition' => [
          'site_logo_fallback' => 'yes'
        ]
      ]
    );
    $this->add_responsive_control(
      'align',
      [
        'label'     => esc_html__('Alignment', 'elespare'),
        'type'      => Controls_Manager::CHOOSE,
        'options'   => [
          'left'   => [
            'title' => esc_html__('Left', 'elespare'),
            'icon'  => 'eicon-h-align-left',
          ],
          'center' => [
            'title' => esc_html__('Center', 'elespare'),
            'icon'  => 'eicon-h-align-center',
          ],
          'right'  => [
            'title' => esc_html__('Right', 'elespare'),
            'icon'  => 'eicon-h-align-right',
          ],
        ],
        'default'   => 'left',
        'selectors' => [
          '{{WRAPPER}} .elespare-site-logo-container' => 'text-align: {{VALUE}};',
        ],
      ]
    );



    $this->add_control(
      'link_to',
      [
        'label'   => esc_html__('Link', 'elespare'),
        'type'    => Controls_Manager::SELECT,
        'default' => 'default',
        'options' => [
          'default' => esc_html__('Default', 'elespare'),
          'none'    => esc_html__('None', 'elespare'),

        ],
      ]
    );

    $this->add_control(
      'link',
      [
        'label'       => esc_html__('Link', 'elespare'),
        'type'        => Controls_Manager::URL,
        'dynamic'     => [
          'active' => true,
        ],
        'placeholder' => esc_html__('https://your-link.com', 'elespare'),
        'condition'   => [
          'link_to' => 'custom',
        ],
        'show_label'  => false,
      ]
    );

    $this->end_controls_section();
  }
  /**
   * Register Site Image Style Controls.
   *
   * @since 1.0.0
   * @access protected
   */
  protected function register_site_logo_styling_controls()
  {
    $this->start_controls_section(
      'section_style_site_logo_image',
      [
        'label' => esc_html__('Site logo', 'elespare'),
        'tab'   => Controls_Manager::TAB_STYLE,
      ]
    );



    $this->add_responsive_control(
      'width',
      [
        'label'          => esc_html__('Width', 'elespare'),
        'type'           => Controls_Manager::SLIDER,
        'default'        => [
          'unit' => '%',
        ],
        'tablet_default' => [
          'unit' => '%',
        ],
        'mobile_default' => [
          'unit' => '%',
        ],
        'size_units'     => ['%', 'px', 'vw'],
        'range'          => [
          '%'  => [
            'min' => 1,
            'max' => 100,
          ],
          'px' => [
            'min' => 1,
            'max' => 1000,
          ],
          'vw' => [
            'min' => 1,
            'max' => 100,
          ],
        ],
        'selectors'      => [
          '{{WRAPPER}} .elespare-site-logo-container img' => 'width: {{SIZE}}{{UNIT}};',
        ],
      ]
    );

    $this->add_responsive_control(
      'space',
      [
        'label'          => esc_html__('Max Width', 'elespare') . ' (%)',
        'type'           => Controls_Manager::SLIDER,
        'default'        => [
          'unit' => '%',
        ],
        'tablet_default' => [
          'unit' => '%',
        ],
        'mobile_default' => [
          'unit' => '%',
        ],
        'size_units'     => ['%'],
        'range'          => [
          '%' => [
            'min' => 1,
            'max' => 100,
          ],
        ],
        'selectors'      => [
          '{{WRAPPER}} .elespare-site-logo-container img' => 'max-width: {{SIZE}}{{UNIT}};',
        ],
      ]
    );



    $this->end_controls_section();
  }




  /**
   * Render Site Image output on the frontend.
   *
   * Written in PHP and used to generate the final HTML.
   *
   * @since 1.0.0
   * @param array $size returns the size of an image.
   * @access public
   */
  public function elespare_site_image_url($size)
  {
    $settings = $this->get_settings_for_display();
    if (! empty($settings['custom_image']['url'])) {
      $logo = wp_get_attachment_image_src($settings['custom_image']['id'], $size, true);
    } else {
      $logo = wp_get_attachment_image_src(get_theme_mod('custom_logo'), $size, true);
    }
    return $logo[0];
  }

  /**
   * Render Site Image output on the frontend.
   *
   * Written in PHP and used to generate the final HTML.
   *
   * @since 1.0.0
   * @access protected
   */
  protected function render()
  {
    $settings = $this->get_settings_for_display();

    $custom_logo_id = get_theme_mod('custom_logo');

    $this->add_render_attribute('elespare-wrapper', 'class', 'elespare-site-logo');

    $size = $settings['site_logo_size_size'];

    $site_image = $this->elespare_site_image_url($size);

    $custom_logo_id = get_theme_mod('custom_logo');



    if (includes_url('/images/media/default.png') === $site_image) {
      $site_image = ELEMENTOR_URL . '/assets/images/placeholder.png';
    }

    if ('default' === $settings['link_to']) {
      $link = site_url();
    } else {
      $link     = $this->elespare_get_link_url($settings);
    }
    $class = '';
    if (Plugin::$instance->editor->is_edit_mode()) {
      $class = 'elementor-non-clickable';
    } else {
      $class = 'elementor-clickable';
    }
?>
    <div <?php echo esc_attr($this->get_render_attribute_string('elespare-wrapper')); ?>>

      <?php if ($link) :   ?>
        <a class='<?php echo  esc_attr($class); ?>' href="<?php echo esc_url($link) ?>">
        <?php endif;

      if (empty($site_image)) {
        return;
      }
      $img_animation = '';

      if ('custom' !== $size) {
        $image_size = $size;
      } else {
        require_once ELEMENTOR_PATH . 'includes/libraries/bfi-thumb/bfi-thumb.php';

        $image_dimension = $settings['site_logo_size_custom_dimension'];

        $image_size = [

          'bfi_thumb' => true,
          'crop'      => true,
        ];

        $has_custom_size = false;
        if (! empty($image_dimension['width'])) {
          $has_custom_size = true;
          $image_size[0]   = $image_dimension['width'];
        }

        if (! empty($image_dimension['height'])) {
          $has_custom_size = true;
          $image_size[1]   = $image_dimension['height'];
        }

        if (! $has_custom_size) {
          $image_size = 'full';
        }
      }

      $image_url = $site_image;

      if (! empty($settings['custom_image']['url'])) {
        $image_data = wp_get_attachment_image_src($settings['custom_image']['id'], $image_size, true);
      } else {
        $image_data = wp_get_attachment_image_src(get_theme_mod('custom_logo'), $image_size, true);
      }

      $site_image_class = 'elementor-animation-';

      if (! empty($settings['hover_animation'])) {
        $img_animation = $settings['hover_animation'];
      }
      if (! empty($image_data)) {
        $image_url = $image_data[0];
      }

      if (includes_url('/images/media/default.png') === $image_url) {
        $image_url = ELEMENTOR_URL . '/assets/images/placeholder.png';
      }

      $class_animation = $site_image_class . $img_animation;

      $image_unset = ELEMENTOR_URL . '/assets/images/placeholder.png';

      if ($image_unset !== $image_url) {
        $image_url = $image_url;
      }

        ?>
        <div class="elespare-site-logo-set">
          <div class="elespare-site-logo-container">
            <img class="elespare-site-logo-img <?php echo esc_attr($class_animation); ?>" src="<?php echo esc_url($image_url); ?>" alt="<?php echo esc_attr(Control_Media::get_image_alt($settings['custom_image'])); ?>" />
          </div>
        </div>
        <?php if ($link) : ?>
        </a>
      <?php endif; ?>
    </div>
<?php
  }

  /**
   * Retrieve Site Logo widget link URL.
   *
   * @since 1.0.0
   * @access private
   *
   * @param array $settings returns settings.
   * @return array|string|false An array/string containing the link URL, or false if no link.
   */
  private function elespare_get_link_url($settings)
  {
    if ('none' === $settings['link_to']) {
      return false;
    }

    if ('custom' === $settings['link_to']) {
      if (empty($settings['link']['url'])) {
        return false;
      }
      return $settings['link'];
    }

    if ('default' === $settings['link_to']) {
      if (empty($settings['link']['url'])) {
        return false;
      }
      return site_url();
    }
  }
}
