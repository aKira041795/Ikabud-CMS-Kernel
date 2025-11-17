<?php

namespace Drupal\phoenix\TwigExtension;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Twig extension for Phoenix theme DiSyL integration.
 */
class PhoenixTwigExtension extends AbstractExtension {

  /**
   * {@inheritdoc}
   */
  public function getFunctions() {
    return [
      new TwigFunction('phoenix_disyl_render', [$this, 'renderDisyl'], ['is_safe' => ['html']]),
    ];
  }

  /**
   * Render a DiSyL template.
   *
   * @param string $template_name
   *   The DiSyL template name (without .disyl extension).
   * @param array $context
   *   Additional context variables.
   *
   * @return string
   *   The rendered HTML.
   */
  public function renderDisyl($template_name, array $context = []) {
    return phoenix_render_disyl($template_name, $context);
  }

  /**
   * {@inheritdoc}
   */
  public function getName() {
    return 'phoenix.twig_extension';
  }

}
