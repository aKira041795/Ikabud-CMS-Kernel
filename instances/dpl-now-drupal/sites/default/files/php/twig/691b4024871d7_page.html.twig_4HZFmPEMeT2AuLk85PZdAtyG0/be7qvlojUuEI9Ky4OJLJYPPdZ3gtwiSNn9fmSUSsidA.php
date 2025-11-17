<?php

use Twig\Environment;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Extension\CoreExtension;
use Twig\Extension\SandboxExtension;
use Twig\Markup;
use Twig\Sandbox\SecurityError;
use Twig\Sandbox\SecurityNotAllowedTagError;
use Twig\Sandbox\SecurityNotAllowedFilterError;
use Twig\Sandbox\SecurityNotAllowedFunctionError;
use Twig\Source;
use Twig\Template;
use Twig\TemplateWrapper;

/* themes/phoenix/templates/page.html.twig */
class __TwigTemplate_dd8553cccf12c40fffc3af3d05d19a78 extends Template
{
    private Source $source;
    /**
     * @var array<string, Template>
     */
    private array $macros = [];

    public function __construct(Environment $env)
    {
        parent::__construct($env);

        $this->source = $this->getSourceContext();

        $this->parent = false;

        $this->blocks = [
        ];
        $this->sandbox = $this->extensions[SandboxExtension::class];
        $this->checkSecurity();
    }

    protected function doDisplay(array $context, array $blocks = []): iterable
    {
        $macros = $this->macros;
        // line 13
        yield "<div class=\"layout-container phoenix-page\">
    
    ";
        // line 16
        yield "    ";
        if (($context["disyl_content"] ?? null)) {
            // line 17
            yield "      <main role=\"main\" id=\"main-content\" class=\"site-main\">
        <a id=\"main-content\" tabindex=\"-1\"></a>
        ";
            // line 19
            yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, ($context["disyl_content"] ?? null), "html", null, true);
            yield "
      </main>
    ";
        } else {
            // line 22
            yield "      ";
            // line 23
            yield "      <div class=\"layout-container\">
        ";
            // line 24
            yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, CoreExtension::getAttribute($this->env, $this->source, ($context["page"] ?? null), "header", [], "any", false, false, true, 24), "html", null, true);
            yield "
        
        ";
            // line 26
            yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, CoreExtension::getAttribute($this->env, $this->source, ($context["page"] ?? null), "primary_menu", [], "any", false, false, true, 26), "html", null, true);
            yield "
        ";
            // line 27
            yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, CoreExtension::getAttribute($this->env, $this->source, ($context["page"] ?? null), "secondary_menu", [], "any", false, false, true, 27), "html", null, true);
            yield "
        
        ";
            // line 29
            yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, CoreExtension::getAttribute($this->env, $this->source, ($context["page"] ?? null), "breadcrumb", [], "any", false, false, true, 29), "html", null, true);
            yield "
        
        ";
            // line 31
            yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, CoreExtension::getAttribute($this->env, $this->source, ($context["page"] ?? null), "highlighted", [], "any", false, false, true, 31), "html", null, true);
            yield "
        
        <main role=\"main\" id=\"main-content\" class=\"main-content\">
          <a id=\"main-content\" tabindex=\"-1\"></a>
          
          <div class=\"layout-content\">
            ";
            // line 37
            yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, CoreExtension::getAttribute($this->env, $this->source, ($context["page"] ?? null), "content", [], "any", false, false, true, 37), "html", null, true);
            yield "
          </div>
          
          ";
            // line 40
            if (CoreExtension::getAttribute($this->env, $this->source, ($context["page"] ?? null), "sidebar_first", [], "any", false, false, true, 40)) {
                // line 41
                yield "            <aside class=\"layout-sidebar-first\" role=\"complementary\">
              ";
                // line 42
                yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, CoreExtension::getAttribute($this->env, $this->source, ($context["page"] ?? null), "sidebar_first", [], "any", false, false, true, 42), "html", null, true);
                yield "
            </aside>
          ";
            }
            // line 45
            yield "          
          ";
            // line 46
            if (CoreExtension::getAttribute($this->env, $this->source, ($context["page"] ?? null), "sidebar_second", [], "any", false, false, true, 46)) {
                // line 47
                yield "            <aside class=\"layout-sidebar-second\" role=\"complementary\">
              ";
                // line 48
                yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, CoreExtension::getAttribute($this->env, $this->source, ($context["page"] ?? null), "sidebar_second", [], "any", false, false, true, 48), "html", null, true);
                yield "
            </aside>
          ";
            }
            // line 51
            yield "        </main>
        
        ";
            // line 53
            if (CoreExtension::getAttribute($this->env, $this->source, ($context["page"] ?? null), "footer", [], "any", false, false, true, 53)) {
                // line 54
                yield "          <footer role=\"contentinfo\">
            ";
                // line 55
                yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, CoreExtension::getAttribute($this->env, $this->source, ($context["page"] ?? null), "footer", [], "any", false, false, true, 55), "html", null, true);
                yield "
          </footer>
        ";
            }
            // line 58
            yield "      </div>
    ";
        }
        // line 60
        yield "</div>
";
        $this->env->getExtension('\Drupal\Core\Template\TwigExtension')
            ->checkDeprecations($context, ["disyl_content", "page"]);        yield from [];
    }

    /**
     * @codeCoverageIgnore
     */
    public function getTemplateName(): string
    {
        return "themes/phoenix/templates/page.html.twig";
    }

    /**
     * @codeCoverageIgnore
     */
    public function isTraitable(): bool
    {
        return false;
    }

    /**
     * @codeCoverageIgnore
     */
    public function getDebugInfo(): array
    {
        return array (  144 => 60,  140 => 58,  134 => 55,  131 => 54,  129 => 53,  125 => 51,  119 => 48,  116 => 47,  114 => 46,  111 => 45,  105 => 42,  102 => 41,  100 => 40,  94 => 37,  85 => 31,  80 => 29,  75 => 27,  71 => 26,  66 => 24,  63 => 23,  61 => 22,  55 => 19,  51 => 17,  48 => 16,  44 => 13,);
    }

    public function getSourceContext(): Source
    {
        return new Source("", "themes/phoenix/templates/page.html.twig", "/var/www/html/ikabud-kernel/instances/dpl-now-drupal/themes/phoenix/templates/page.html.twig");
    }
    
    public function checkSecurity()
    {
        static $tags = array("if" => 16);
        static $filters = array("escape" => 19);
        static $functions = array();

        try {
            $this->sandbox->checkSecurity(
                ['if'],
                ['escape'],
                [],
                $this->source
            );
        } catch (SecurityError $e) {
            $e->setSourceContext($this->source);

            if ($e instanceof SecurityNotAllowedTagError && isset($tags[$e->getTagName()])) {
                $e->setTemplateLine($tags[$e->getTagName()]);
            } elseif ($e instanceof SecurityNotAllowedFilterError && isset($filters[$e->getFilterName()])) {
                $e->setTemplateLine($filters[$e->getFilterName()]);
            } elseif ($e instanceof SecurityNotAllowedFunctionError && isset($functions[$e->getFunctionName()])) {
                $e->setTemplateLine($functions[$e->getFunctionName()]);
            }

            throw $e;
        }

    }
}
