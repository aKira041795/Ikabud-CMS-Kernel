<?php
/**
 * Native/Static Theme Generator
 * 
 * Generates standalone HTML templates with DiSyL support.
 * No CMS required - pure static site generation.
 * 
 * @package IkabudKernel\ThemeGenerator\Generators
 * @version 1.0.0
 */

namespace IkabudKernel\ThemeGenerator\Generators;

use IkabudKernel\ThemeGenerator\AbstractThemeGenerator;

class NativeGenerator extends AbstractThemeGenerator
{
    public function getCmsId(): string
    {
        return 'native';
    }
    
    public function getCmsName(): string
    {
        return 'Native/Static';
    }
    
    public function getSupportedFeatures(): array
    {
        return [
            'static_html' => [
                'name' => 'Static HTML',
                'description' => 'Pure HTML output, no server required',
                'enabled' => true,
            ],
            'portable' => [
                'name' => 'Portable',
                'description' => 'Can be hosted anywhere',
                'enabled' => true,
            ],
            'fast' => [
                'name' => 'Fast Loading',
                'description' => 'No database queries',
                'enabled' => true,
            ],
        ];
    }
    
    public function getBaseTemplates(): array
    {
        return [
            'index' => [
                'name' => 'Homepage',
                'required' => true,
                'description' => 'Main index page',
                'stub' => $this->getIndexStub(),
            ],
            'about' => [
                'name' => 'About Page',
                'required' => false,
                'description' => 'About page template',
                'stub' => '',
            ],
            'contact' => [
                'name' => 'Contact Page',
                'required' => false,
                'description' => 'Contact page template',
                'stub' => '',
            ],
            '404' => [
                'name' => '404 Error',
                'required' => true,
                'description' => 'Page not found',
                'stub' => $this->get404Stub(),
            ],
        ];
    }
    
    public function getBaseComponents(): array
    {
        return [
            'header' => [
                'name' => 'Header',
                'required' => true,
                'description' => 'Site header',
                'stub' => $this->getHeaderStub(),
            ],
            'footer' => [
                'name' => 'Footer',
                'required' => true,
                'description' => 'Site footer',
                'stub' => $this->getFooterStub(),
            ],
            'nav' => [
                'name' => 'Navigation',
                'required' => false,
                'description' => 'Navigation menu',
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
            'disyl',
            'disyl/components',
        ]);
        
        $files = [];
        
        // Generate index.html
        $files['index.html'] = $this->generateIndexHtml($config);
        $this->writeFile($themePath . '/index.html', $files['index.html']);
        
        // Generate 404.html
        $files['404.html'] = $this->generate404Html($config);
        $this->writeFile($themePath . '/404.html', $files['404.html']);
        
        // Generate CSS
        $files['css/style.css'] = $this->generateStyleCss($config);
        $this->writeFile($themePath . '/css/style.css', $files['css/style.css']);
        
        // Generate JS
        $files['js/main.js'] = $this->generateMainJs($config);
        $this->writeFile($themePath . '/js/main.js', $files['js/main.js']);
        
        // Generate DiSyL templates
        foreach ($config['templates'] as $templateId => $content) {
            $templatePath = "disyl/{$templateId}.disyl";
            $files[$templatePath] = $content;
            $this->writeFile($themePath . '/' . $templatePath, $content);
        }
        
        // Generate README
        $files['README.md'] = $this->generateReadme($config);
        $this->writeFile($themePath . '/README.md', $files['README.md']);
        
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
            'index.html' => $this->generateIndexHtml($config),
            'css/style.css' => $this->generateStyleCss($config),
        ];
    }
    
    protected function generateIndexHtml(array $config): string
    {
        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$config['themeName']}</title>
    <meta name="description" content="{$config['description']}">
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <header class="site-header">
        <div class="container">
            <a href="/" class="site-title">{$config['themeName']}</a>
            <nav class="main-nav">
                <ul>
                    <li><a href="/">Home</a></li>
                    <li><a href="/about.html">About</a></li>
                    <li><a href="/contact.html">Contact</a></li>
                </ul>
            </nav>
        </div>
    </header>
    
    <main class="site-main">
        <section class="hero">
            <div class="container">
                <h1>Welcome to {$config['themeName']}</h1>
                <p>{$config['description']}</p>
            </div>
        </section>
        
        <section class="content">
            <div class="container">
                <h2>Features</h2>
                <div class="feature-grid">
                    <div class="feature">
                        <h3>Fast</h3>
                        <p>Static HTML means lightning-fast page loads.</p>
                    </div>
                    <div class="feature">
                        <h3>Portable</h3>
                        <p>Host anywhere - no server requirements.</p>
                    </div>
                    <div class="feature">
                        <h3>Secure</h3>
                        <p>No database means no SQL injection risks.</p>
                    </div>
                </div>
            </div>
        </section>
    </main>
    
    <footer class="site-footer">
        <div class="container">
            <p>&copy; {date('Y')} {$config['themeName']}. All rights reserved.</p>
        </div>
    </footer>
    
    <script src="js/main.js"></script>
</body>
</html>
HTML;
    }
    
    protected function generate404Html(array $config): string
    {
        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>404 - Page Not Found | {$config['themeName']}</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <div class="error-page">
        <div class="container">
            <h1 class="error-code">404</h1>
            <h2>Page Not Found</h2>
            <p>The page you're looking for doesn't exist.</p>
            <a href="/" class="btn btn-primary">Go Home</a>
        </div>
    </div>
</body>
</html>
HTML;
    }
    
    protected function generateStyleCss(array $config): string
    {
        return <<<CSS
/**
 * {$config['themeName']} Styles
 * Generated by Ikabud Theme Builder
 */

:root {
    --color-primary: #3b82f6;
    --color-secondary: #64748b;
    --color-text: #1e293b;
    --color-text-light: #64748b;
    --color-background: #ffffff;
    --color-surface: #f8fafc;
    --font-sans: system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    --max-width: 1200px;
    --spacing: 1rem;
}

*, *::before, *::after {
    box-sizing: border-box;
}

html {
    font-size: 16px;
    scroll-behavior: smooth;
}

body {
    margin: 0;
    font-family: var(--font-sans);
    font-size: 1rem;
    line-height: 1.6;
    color: var(--color-text);
    background-color: var(--color-background);
}

.container {
    width: 100%;
    max-width: var(--max-width);
    margin: 0 auto;
    padding: 0 var(--spacing);
}

/* Header */
.site-header {
    background: var(--color-background);
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
    padding: 1rem 0;
    position: sticky;
    top: 0;
    z-index: 100;
}

.site-header .container {
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.site-title {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--color-text);
    text-decoration: none;
}

.main-nav ul {
    display: flex;
    gap: 2rem;
    list-style: none;
    margin: 0;
    padding: 0;
}

.main-nav a {
    color: var(--color-text);
    text-decoration: none;
    font-weight: 500;
    transition: color 0.2s;
}

.main-nav a:hover {
    color: var(--color-primary);
}

/* Hero */
.hero {
    background: linear-gradient(135deg, var(--color-primary), #8b5cf6);
    color: white;
    padding: 6rem 0;
    text-align: center;
}

.hero h1 {
    font-size: 3rem;
    margin: 0 0 1rem;
}

.hero p {
    font-size: 1.25rem;
    opacity: 0.9;
    max-width: 600px;
    margin: 0 auto;
}

/* Content */
.content {
    padding: 4rem 0;
}

.content h2 {
    text-align: center;
    margin-bottom: 3rem;
}

.feature-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 2rem;
}

.feature {
    background: var(--color-surface);
    padding: 2rem;
    border-radius: 0.5rem;
    text-align: center;
}

.feature h3 {
    color: var(--color-primary);
    margin-top: 0;
}

/* Footer */
.site-footer {
    background: var(--color-text);
    color: white;
    padding: 2rem 0;
    text-align: center;
}

/* Buttons */
.btn {
    display: inline-block;
    padding: 0.75rem 1.5rem;
    font-size: 1rem;
    font-weight: 500;
    text-decoration: none;
    border-radius: 0.5rem;
    transition: all 0.2s;
    cursor: pointer;
    border: none;
}

.btn-primary {
    background: var(--color-primary);
    color: white;
}

.btn-primary:hover {
    background: #2563eb;
}

/* Error Page */
.error-page {
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    text-align: center;
}

.error-code {
    font-size: 8rem;
    color: var(--color-primary);
    margin: 0;
    line-height: 1;
}

/* Responsive */
@media (max-width: 768px) {
    .hero h1 {
        font-size: 2rem;
    }
    
    .main-nav ul {
        gap: 1rem;
    }
}
CSS;
    }
    
    protected function generateMainJs(array $config): string
    {
        return <<<JS
/**
 * {$config['themeName']} JavaScript
 */

(function() {
    'use strict';
    
    // Smooth scroll for anchor links
    document.querySelectorAll('a[href^="#"]').forEach(anchor => {
        anchor.addEventListener('click', function(e) {
            const targetId = this.getAttribute('href');
            if (targetId === '#') return;
            
            const target = document.querySelector(targetId);
            if (target) {
                e.preventDefault();
                target.scrollIntoView({
                    behavior: 'smooth',
                    block: 'start'
                });
            }
        });
    });
    
    // Add loaded class to body
    document.body.classList.add('loaded');
    
})();
JS;
    }
    
    protected function generateReadme(array $config): string
    {
        return <<<MD
# {$config['themeName']}

{$config['description']}

## Usage

This is a static HTML template. Simply open `index.html` in a browser or upload the files to any web server.

## File Structure

```
{$config['themeSlug']}/
├── css/
│   └── style.css
├── js/
│   └── main.js
├── images/
├── disyl/
│   └── *.disyl
├── index.html
├── 404.html
└── README.md
```

## Customization

- Edit `css/style.css` to change colors and styles
- Modify HTML files directly for content changes
- DiSyL templates in `disyl/` folder can be compiled for dynamic content

## Credits

Generated by [Ikabud Theme Builder](https://ikabud.com)

## License

{$config['license']}
MD;
    }
    
    protected function getIndexStub(): string
    {
        return <<<DISYL
{!-- Homepage Template --}
{ikb_platform type="web" targets="native" /}
{include file="components/header.disyl"}

{ikb_section type="hero" padding="xlarge"}
    {ikb_container size="lg"}
        <div class="text-center">
            {ikb_text size="4xl" weight="bold"}
                Welcome
            {/ikb_text}
            {ikb_text size="xl"}
                Your content here
            {/ikb_text}
        </div>
    {/ikb_container}
{/ikb_section}

{include file="components/footer.disyl"}
DISYL;
    }
    
    protected function get404Stub(): string
    {
        return <<<DISYL
{!-- 404 Error Template --}
{ikb_platform type="web" targets="native" /}

{ikb_section type="content" padding="xlarge"}
    {ikb_container size="sm"}
        <div class="text-center">
            {ikb_text size="6xl" weight="bold"}404{/ikb_text}
            {ikb_text size="xl"}Page Not Found{/ikb_text}
            <a href="/" class="btn btn-primary">Go Home</a>
        </div>
    {/ikb_container}
{/ikb_section}
DISYL;
    }
    
    protected function getHeaderStub(): string
    {
        return <<<DISYL
{!-- Header Component --}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{site.title}</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
<header class="site-header">
    <div class="container">
        <a href="/" class="site-title">{site.name}</a>
        <nav class="main-nav">
            <ul>
                {for items="{menu.primary}" as="item"}
                    <li><a href="{item.url}">{item.title}</a></li>
                {/for}
            </ul>
        </nav>
    </div>
</header>
<main class="site-main">
DISYL;
    }
    
    protected function getFooterStub(): string
    {
        return <<<DISYL
{!-- Footer Component --}
</main>
<footer class="site-footer">
    <div class="container">
        <p>&copy; {year} {site.name}. All rights reserved.</p>
    </div>
</footer>
<script src="js/main.js"></script>
</body>
</html>
DISYL;
    }
}
