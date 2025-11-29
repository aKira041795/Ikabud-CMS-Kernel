<?php
/**
 * Joomla Template Generator
 * 
 * Generates Joomla templates with DiSyL support.
 * 
 * @package IkabudKernel\ThemeGenerator\Generators
 * @version 1.0.0
 */

namespace IkabudKernel\ThemeGenerator\Generators;

use IkabudKernel\ThemeGenerator\AbstractThemeGenerator;

class JoomlaGenerator extends AbstractThemeGenerator
{
    public function getCmsId(): string
    {
        return 'joomla';
    }
    
    public function getCmsName(): string
    {
        return 'Joomla';
    }
    
    public function getSupportedFeatures(): array
    {
        return [
            'module_positions' => [
                'name' => 'Module Positions',
                'description' => 'Define custom module positions',
                'enabled' => true,
            ],
            'template_params' => [
                'name' => 'Template Parameters',
                'description' => 'Configurable template options',
                'enabled' => true,
            ],
            'menu_items' => [
                'name' => 'Menu Items',
                'description' => 'Navigation menu support',
                'enabled' => true,
            ],
            'overrides' => [
                'name' => 'Template Overrides',
                'description' => 'Component/module output overrides',
                'enabled' => true,
            ],
        ];
    }
    
    public function getBaseTemplates(): array
    {
        return [
            'index' => [
                'name' => 'Main Template',
                'required' => true,
                'description' => 'Main template file',
                'stub' => $this->getIndexStub(),
            ],
            'component' => [
                'name' => 'Component',
                'required' => false,
                'description' => 'Component output template',
                'stub' => '',
            ],
            'error' => [
                'name' => 'Error Page',
                'required' => true,
                'description' => 'Error page template',
                'stub' => $this->getErrorStub(),
            ],
            'offline' => [
                'name' => 'Offline Page',
                'required' => false,
                'description' => 'Site offline template',
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
                'description' => 'Site header module position',
                'stub' => '',
            ],
            'footer' => [
                'name' => 'Footer',
                'required' => true,
                'description' => 'Site footer module position',
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
            'html',
            'disyl',
            'disyl/components',
        ]);
        
        $files = [];
        
        // Generate templateDetails.xml
        $files['templateDetails.xml'] = $this->generateTemplateDetails($config);
        $this->writeFile($themePath . '/templateDetails.xml', $files['templateDetails.xml']);
        
        // Generate index.php
        $files['index.php'] = $this->generateIndexPhp($config);
        $this->writeFile($themePath . '/index.php', $files['index.php']);
        
        // Generate error.php
        $files['error.php'] = $this->generateErrorPhp($config);
        $this->writeFile($themePath . '/error.php', $files['error.php']);
        
        // Generate CSS
        $files['css/template.css'] = $this->generateTemplateCss($config);
        $this->writeFile($themePath . '/css/template.css', $files['css/template.css']);
        
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
        
        return [
            'templateDetails.xml' => $this->generateTemplateDetails($config),
            'index.php' => $this->generateIndexPhp($config),
            'error.php' => $this->generateErrorPhp($config),
        ];
    }
    
    protected function generateTemplateDetails(array $config): string
    {
        $positions = $config['options']['modulePositions'] ?? [
            'header', 'menu', 'banner', 'left', 'right', 
            'main-top', 'main-bottom', 'footer', 'debug'
        ];
        
        $positionsXml = '';
        foreach ($positions as $position) {
            $positionsXml .= "        <position>{$position}</position>\n";
        }
        
        return <<<XML
<?xml version="1.0" encoding="utf-8"?>
<extension type="template" client="site" method="upgrade">
    <name>{$config['themeName']}</name>
    <version>{$config['version']}</version>
    <creationDate>{date('F Y')}</creationDate>
    <author>{$config['author']}</author>
    <authorUrl>{$config['authorUri']}</authorUrl>
    <copyright>Copyright (C) {date('Y')} {$config['author']}</copyright>
    <license>{$config['license']}</license>
    <description>{$config['description']}</description>
    
    <files>
        <filename>index.php</filename>
        <filename>error.php</filename>
        <filename>templateDetails.xml</filename>
        <folder>css</folder>
        <folder>js</folder>
        <folder>images</folder>
        <folder>html</folder>
        <folder>disyl</folder>
    </files>
    
    <positions>
{$positionsXml}    </positions>
    
    <config>
        <fields name="params">
            <fieldset name="basic">
                <field
                    name="logoFile"
                    type="media"
                    label="Logo"
                    description="Select a logo image"
                />
                <field
                    name="siteTitle"
                    type="text"
                    label="Site Title"
                    description="Override site title"
                    default=""
                />
                <field
                    name="colorScheme"
                    type="list"
                    label="Color Scheme"
                    default="light"
                >
                    <option value="light">Light</option>
                    <option value="dark">Dark</option>
                </field>
            </fieldset>
        </fields>
    </config>
</extension>
XML;
    }
    
    protected function generateIndexPhp(array $config): string
    {
        return <<<PHP
<?php
/**
 * {$config['themeName']} - Main Template
 * 
 * @package    {$config['themeSlug']}
 * @version    {$config['version']}
 */

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Uri\Uri;

\$app = Factory::getApplication();
\$doc = \$app->getDocument();
\$wa = \$doc->getWebAssetManager();
\$params = \$app->getTemplate(true)->params;

// Add template stylesheets
\$wa->registerAndUseStyle('template-css', 'templates/' . \$this->template . '/css/template.css');

// Get template parameters
\$logo = \$params->get('logoFile', '');
\$siteTitle = \$params->get('siteTitle', \$app->get('sitename'));
\$colorScheme = \$params->get('colorScheme', 'light');
?>
<!DOCTYPE html>
<html lang="<?php echo \$this->language; ?>" dir="<?php echo \$this->direction; ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <jdoc:include type="metas" />
    <jdoc:include type="styles" />
    <jdoc:include type="scripts" />
</head>
<body class="site <?php echo \$colorScheme; ?>">
    <header class="site-header">
        <div class="container">
            <div class="site-branding">
                <?php if (\$logo): ?>
                    <a href="<?php echo Uri::base(); ?>">
                        <img src="<?php echo \$logo; ?>" alt="<?php echo \$siteTitle; ?>" class="logo">
                    </a>
                <?php else: ?>
                    <a href="<?php echo Uri::base(); ?>" class="site-title"><?php echo \$siteTitle; ?></a>
                <?php endif; ?>
            </div>
            
            <jdoc:include type="modules" name="menu" style="none" />
        </div>
    </header>
    
    <main class="site-main">
        <jdoc:include type="modules" name="banner" style="container" />
        
        <div class="container">
            <div class="content-area">
                <jdoc:include type="modules" name="main-top" style="container" />
                <jdoc:include type="message" />
                <jdoc:include type="component" />
                <jdoc:include type="modules" name="main-bottom" style="container" />
            </div>
            
            <?php if (\$this->countModules('left') || \$this->countModules('right')): ?>
            <aside class="sidebar">
                <jdoc:include type="modules" name="left" style="card" />
                <jdoc:include type="modules" name="right" style="card" />
            </aside>
            <?php endif; ?>
        </div>
    </main>
    
    <footer class="site-footer">
        <div class="container">
            <jdoc:include type="modules" name="footer" style="none" />
            <p class="copyright">&copy; <?php echo date('Y'); ?> <?php echo \$siteTitle; ?></p>
        </div>
    </footer>
    
    <jdoc:include type="modules" name="debug" style="none" />
</body>
</html>
PHP;
    }
    
    protected function generateErrorPhp(array $config): string
    {
        return <<<PHP
<?php
/**
 * {$config['themeName']} - Error Page
 * 
 * @package    {$config['themeSlug']}
 */

defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;

\$this->setTitle(Text::_('Error') . ': ' . \$this->error->getCode());
?>
<!DOCTYPE html>
<html lang="<?php echo \$this->language; ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo \$this->title; ?></title>
    <link rel="stylesheet" href="<?php echo \$this->baseurl; ?>/templates/<?php echo \$this->template; ?>/css/template.css">
</head>
<body class="error-page">
    <div class="error-container">
        <h1 class="error-code"><?php echo \$this->error->getCode(); ?></h1>
        <h2 class="error-message"><?php echo htmlspecialchars(\$this->error->getMessage(), ENT_QUOTES, 'UTF-8'); ?></h2>
        <p><a href="<?php echo \$this->baseurl; ?>" class="btn btn-primary"><?php echo Text::_('JERROR_LAYOUT_GO_TO_THE_HOME_PAGE'); ?></a></p>
    </div>
</body>
</html>
PHP;
    }
    
    protected function generateTemplateCss(array $config): string
    {
        return <<<CSS
/**
 * {$config['themeName']} Template Styles
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

.container {
    max-width: 1200px;
    margin: 0 auto;
    padding: 0 1rem;
}

.site-header {
    background: #fff;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    padding: 1rem 0;
}

.site-footer {
    background: #1e293b;
    color: #fff;
    padding: 2rem 0;
    margin-top: 3rem;
}

.error-container {
    text-align: center;
    padding: 4rem 1rem;
}

.error-code {
    font-size: 6rem;
    color: var(--color-primary);
    margin: 0;
}

.btn {
    display: inline-block;
    padding: 0.75rem 1.5rem;
    background: var(--color-primary);
    color: #fff;
    text-decoration: none;
    border-radius: 0.5rem;
}
CSS;
    }
    
    protected function getIndexStub(): string
    {
        return '';
    }
    
    protected function getErrorStub(): string
    {
        return '';
    }
}
