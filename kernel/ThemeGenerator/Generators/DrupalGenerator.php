<?php
/**
 * Drupal Theme Generator
 * 
 * Generates Drupal themes with DiSyL support.
 * 
 * @package IkabudKernel\ThemeGenerator\Generators
 * @version 1.0.0
 */

namespace IkabudKernel\ThemeGenerator\Generators;

use IkabudKernel\ThemeGenerator\AbstractThemeGenerator;

class DrupalGenerator extends AbstractThemeGenerator
{
    public function getCmsId(): string
    {
        return 'drupal';
    }
    
    public function getCmsName(): string
    {
        return 'Drupal';
    }
    
    public function getSupportedFeatures(): array
    {
        return [
            'regions' => [
                'name' => 'Regions',
                'description' => 'Define block placement regions',
                'enabled' => true,
            ],
            'libraries' => [
                'name' => 'Asset Libraries',
                'description' => 'CSS/JS library management',
                'enabled' => true,
            ],
            'twig' => [
                'name' => 'Twig Templates',
                'description' => 'Twig template engine',
                'enabled' => true,
            ],
            'breakpoints' => [
                'name' => 'Breakpoints',
                'description' => 'Responsive breakpoint definitions',
                'enabled' => true,
            ],
        ];
    }
    
    public function getBaseTemplates(): array
    {
        return [
            'page' => [
                'name' => 'Page Template',
                'required' => true,
                'description' => 'Main page template',
                'stub' => $this->getPageStub(),
            ],
            'node' => [
                'name' => 'Node Template',
                'required' => false,
                'description' => 'Content node template',
                'stub' => '',
            ],
            'block' => [
                'name' => 'Block Template',
                'required' => false,
                'description' => 'Block template',
                'stub' => '',
            ],
        ];
    }
    
    public function getBaseComponents(): array
    {
        return [
            'header' => [
                'name' => 'Header',
                'required' => true,
                'description' => 'Site header region',
                'stub' => '',
            ],
            'footer' => [
                'name' => 'Footer',
                'required' => true,
                'description' => 'Site footer region',
                'stub' => '',
            ],
        ];
    }
    
    public function generate(array $config): array
    {
        $config = $this->normalizeConfig($config);
        $themeSlug = $config['themeSlug'];
        $themePath = $this->storagePath . '/' . $themeSlug;
        
        // Create directory structure
        $this->createDirectories($themePath, [
            'css',
            'js',
            'images',
            'templates',
            'disyl',
            'disyl/components',
        ]);
        
        $files = [];
        
        // Generate theme.info.yml
        $files["{$themeSlug}.info.yml"] = $this->generateInfoYml($config);
        $this->writeFile($themePath . "/{$themeSlug}.info.yml", $files["{$themeSlug}.info.yml"]);
        
        // Generate theme.libraries.yml
        $files["{$themeSlug}.libraries.yml"] = $this->generateLibrariesYml($config);
        $this->writeFile($themePath . "/{$themeSlug}.libraries.yml", $files["{$themeSlug}.libraries.yml"]);
        
        // Generate page.html.twig
        $files['templates/page.html.twig'] = $this->generatePageTwig($config);
        $this->writeFile($themePath . '/templates/page.html.twig', $files['templates/page.html.twig']);
        
        // Generate html.html.twig
        $files['templates/html.html.twig'] = $this->generateHtmlTwig($config);
        $this->writeFile($themePath . '/templates/html.html.twig', $files['templates/html.html.twig']);
        
        // Generate CSS
        $files['css/style.css'] = $this->generateStyleCss($config);
        $this->writeFile($themePath . '/css/style.css', $files['css/style.css']);
        
        // Generate DiSyL templates
        foreach ($config['templates'] as $templateId => $content) {
            $templatePath = "disyl/{$templateId}.disyl";
            $files[$templatePath] = $content;
            $this->writeFile($themePath . '/' . $templatePath, $content);
        }
        
        // Create ZIP archive
        $zipPath = $this->storagePath . '/' . $themeSlug . '.zip';
        $this->createZipArchive($themePath, $zipPath);
        
        return [
            'theme' => [
                'name' => $config['themeName'],
                'slug' => $themeSlug,
                'version' => $config['version'],
                'cms' => $this->getCmsId(),
            ],
            'files' => array_keys($files),
            'downloadUrl' => '/storage/themes/' . $themeSlug . '.zip',
        ];
    }
    
    public function preview(array $config): array
    {
        $config = $this->normalizeConfig($config);
        $themeSlug = $config['themeSlug'];
        
        return [
            "{$themeSlug}.info.yml" => $this->generateInfoYml($config),
            "{$themeSlug}.libraries.yml" => $this->generateLibrariesYml($config),
            'templates/page.html.twig' => $this->generatePageTwig($config),
        ];
    }
    
    protected function generateInfoYml(array $config): string
    {
        $regions = $config['options']['regions'] ?? [
            'header' => 'Header',
            'primary_menu' => 'Primary Menu',
            'secondary_menu' => 'Secondary Menu',
            'highlighted' => 'Highlighted',
            'help' => 'Help',
            'content' => 'Content',
            'sidebar_first' => 'Sidebar First',
            'sidebar_second' => 'Sidebar Second',
            'footer' => 'Footer',
        ];
        
        $regionsYml = '';
        foreach ($regions as $machine => $label) {
            $regionsYml .= "  {$machine}: '{$label}'\n";
        }
        
        return <<<YAML
name: '{$config['themeName']}'
type: theme
description: '{$config['description']}'
core_version_requirement: ^9 || ^10
base theme: false

libraries:
  - {$config['themeSlug']}/global

regions:
{$regionsYml}
YAML;
    }
    
    protected function generateLibrariesYml(array $config): string
    {
        return <<<YAML
global:
  version: {$config['version']}
  css:
    theme:
      css/style.css: {}
  js:
    js/script.js: {}
  dependencies:
    - core/drupal
    - core/jquery
YAML;
    }
    
    protected function generatePageTwig(array $config): string
    {
        return <<<TWIG
{#
/**
 * {$config['themeName']} - Page Template
 */
#}
<div class="layout-container">
  <header role="banner" class="site-header">
    {{ page.header }}
    {{ page.primary_menu }}
  </header>

  {{ page.highlighted }}
  {{ page.help }}

  <main role="main" class="site-main">
    <a id="main-content" tabindex="-1"></a>

    <div class="layout-content">
      {{ page.content }}
    </div>

    {% if page.sidebar_first %}
      <aside class="layout-sidebar-first" role="complementary">
        {{ page.sidebar_first }}
      </aside>
    {% endif %}

    {% if page.sidebar_second %}
      <aside class="layout-sidebar-second" role="complementary">
        {{ page.sidebar_second }}
      </aside>
    {% endif %}
  </main>

  {% if page.footer %}
    <footer role="contentinfo" class="site-footer">
      {{ page.footer }}
    </footer>
  {% endif %}
</div>
TWIG;
    }
    
    protected function generateHtmlTwig(array $config): string
    {
        return <<<TWIG
{#
/**
 * {$config['themeName']} - HTML Template
 */
#}
<!DOCTYPE html>
<html{{ html_attributes }}>
  <head>
    <head-placeholder token="{{ placeholder_token }}">
    <title>{{ head_title|safe_join(' | ') }}</title>
    <css-placeholder token="{{ placeholder_token }}">
    <js-placeholder token="{{ placeholder_token }}">
  </head>
  <body{{ attributes }}>
    <a href="#main-content" class="visually-hidden focusable skip-link">
      {{ 'Skip to main content'|t }}
    </a>
    {{ page_top }}
    {{ page }}
    {{ page_bottom }}
    <js-bottom-placeholder token="{{ placeholder_token }}">
  </body>
</html>
TWIG;
    }
    
    protected function generateStyleCss(array $config): string
    {
        return <<<CSS
/**
 * {$config['themeName']} Theme Styles
 */

:root {
    --color-primary: #3b82f6;
    --color-text: #1e293b;
    --color-background: #ffffff;
}

* { box-sizing: border-box; }

body {
    margin: 0;
    font-family: system-ui, sans-serif;
    color: var(--color-text);
    background: var(--color-background);
}

.layout-container {
    max-width: 1200px;
    margin: 0 auto;
    padding: 0 1rem;
}

.site-header {
    padding: 1rem 0;
    border-bottom: 1px solid #e2e8f0;
}

.site-main {
    padding: 2rem 0;
}

.site-footer {
    background: #1e293b;
    color: #fff;
    padding: 2rem 0;
    margin-top: 2rem;
}

.skip-link {
    position: absolute;
    top: -40px;
    left: 0;
    background: var(--color-primary);
    color: white;
    padding: 8px;
    z-index: 100;
}

.skip-link:focus {
    top: 0;
}
CSS;
    }
    
    protected function getPageStub(): string
    {
        return '';
    }
}
