# Theme Generator System

Extensible theme generation system for DiSyL Visual Builder. Generates complete, production-ready themes for multiple CMS platforms.

## Architecture

```
ThemeGenerator/
├── ThemeGeneratorInterface.php    # Contract for all generators
├── AbstractThemeGenerator.php     # Base class with common functionality
├── ThemeGeneratorFactory.php      # Factory for creating generators
├── Generators/
│   ├── WordPressGenerator.php     # WordPress theme generator
│   ├── JoomlaGenerator.php        # Joomla template generator
│   ├── DrupalGenerator.php        # Drupal theme generator
│   └── NativeGenerator.php        # Static HTML generator
└── Templates/                     # Stub templates (optional)
    ├── common/                    # Shared templates
    ├── wordpress/                 # WordPress-specific stubs
    ├── joomla/                    # Joomla-specific stubs
    └── drupal/                    # Drupal-specific stubs
```

## Quick Start

### Using the Factory

```php
use IkabudKernel\ThemeGenerator\ThemeGeneratorFactory;

// Create a WordPress generator
$generator = ThemeGeneratorFactory::create('wordpress');

// Generate a theme
$result = $generator->generate([
    'themeName' => 'My Awesome Theme',
    'author' => 'Developer Name',
    'description' => 'A beautiful DiSyL theme',
    'version' => '1.0.0',
    'templates' => [
        'home' => '{ikb_section...}',
        'single' => '...',
        'components/header' => '...',
    ],
    'options' => [
        'includeCustomizer' => true,
        'menuLocations' => ['primary', 'footer', 'social'],
    ]
]);

// Result contains:
// - theme: Theme metadata
// - files: List of generated files
// - downloadUrl: URL to download ZIP
```

### Preview Without Saving

```php
$preview = $generator->preview([
    'themeName' => 'My Theme',
    'templates' => ['home' => '...'],
]);

// Returns file contents without creating files
foreach ($preview as $filename => $content) {
    echo "=== {$filename} ===\n{$content}\n\n";
}
```

## Extending: Adding a New CMS

### 1. Create Your Generator Class

```php
<?php
namespace IkabudKernel\ThemeGenerator\Generators;

use IkabudKernel\ThemeGenerator\AbstractThemeGenerator;

class MyPlatformGenerator extends AbstractThemeGenerator
{
    public function getCmsId(): string
    {
        return 'myplatform';
    }
    
    public function getCmsName(): string
    {
        return 'My Platform';
    }
    
    public function getSupportedFeatures(): array
    {
        return [
            'feature1' => [
                'name' => 'Feature One',
                'description' => 'Description of feature',
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
        ];
    }
    
    public function getBaseComponents(): array
    {
        return [
            'header' => [
                'name' => 'Header',
                'required' => true,
                'description' => 'Site header',
                'stub' => '',
            ],
        ];
    }
    
    public function generate(array $config): array
    {
        $config = $this->normalizeConfig($config);
        $themeSlug = $config['themeSlug'];
        $themePath = $this->storagePath . '/' . $themeSlug;
        
        // Create directories
        $this->createDirectories($themePath, [
            'css', 'js', 'templates', 'disyl'
        ]);
        
        $files = [];
        
        // Generate your platform-specific files
        $files['config.xml'] = $this->generateConfig($config);
        $this->writeFile($themePath . '/config.xml', $files['config.xml']);
        
        // Generate DiSyL templates
        foreach ($config['templates'] as $id => $content) {
            $path = "disyl/{$id}.disyl";
            $files[$path] = $content;
            $this->writeFile($themePath . '/' . $path, $content);
        }
        
        // Create ZIP
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
            'config.xml' => $this->generateConfig($config),
        ];
    }
    
    // Your platform-specific generators
    protected function generateConfig(array $config): string
    {
        return "<?xml version=\"1.0\"?>...";
    }
    
    protected function getIndexStub(): string
    {
        return '{ikb_platform type="web" targets="myplatform" /}...';
    }
}
```

### 2. Register Your Generator

```php
use IkabudKernel\ThemeGenerator\ThemeGeneratorFactory;
use IkabudKernel\ThemeGenerator\Generators\MyPlatformGenerator;

// Register the new generator
ThemeGeneratorFactory::register('myplatform', MyPlatformGenerator::class);

// Now it's available
$generator = ThemeGeneratorFactory::create('myplatform');
```

### 3. Add to Visual Builder (Frontend)

In `admin/src/pages/VisualBuilder.tsx`, add to `CMS_CONFIGS`:

```typescript
const CMS_CONFIGS: Record<CMSType, CMSConfig> = {
  // ... existing configs ...
  myplatform: {
    id: 'myplatform',
    name: 'My Platform',
    icon: '🟢',
    color: 'green',
    description: 'Create themes for My Platform',
    fileExtensions: { template: 'tpl', style: 'css', script: 'js' },
    features: ['Feature One', 'Feature Two']
  }
}
```

## API Endpoints

### POST /api/theme/generate

Generate a complete theme package.

**Request:**
```json
{
  "cms": "wordpress",
  "themeName": "My Theme",
  "themeSlug": "my-theme",
  "author": "Developer",
  "description": "Theme description",
  "version": "1.0.0",
  "templates": {
    "home": "{ikb_section...}",
    "single": "..."
  },
  "options": {
    "includeCustomizer": true,
    "menuLocations": ["primary", "footer"]
  }
}
```

**Response:**
```json
{
  "success": true,
  "theme": {
    "name": "My Theme",
    "slug": "my-theme",
    "version": "1.0.0",
    "cms": "wordpress"
  },
  "files": ["style.css", "functions.php", "..."],
  "downloadUrl": "/storage/themes/my-theme.zip"
}
```

### GET /api/theme/templates?cms=wordpress

Get available base templates for a CMS.

### POST /api/theme/preview

Preview generated files without saving.

## Helper Methods (AbstractThemeGenerator)

| Method | Description |
|--------|-------------|
| `normalizeConfig($config)` | Merge config with defaults |
| `generateSlug($name)` | Create URL-safe slug |
| `createDirectories($path, $dirs)` | Create directory structure |
| `writeFile($path, $content)` | Write file with auto-mkdir |
| `loadStub($name)` | Load template stub file |
| `replacePlaceholders($tpl, $data)` | Replace `{{key}}` placeholders |
| `createZipArchive($dir, $zip)` | Create ZIP from directory |
| `validate($config)` | Validate theme configuration |
| `generateManifest($config)` | Generate manifest.json |

## Generated WordPress Theme Structure

```
my-theme/
├── assets/
│   ├── css/
│   │   └── disyl-components.css
│   └── js/
│       └── theme.js
├── disyl/
│   ├── components/
│   │   ├── header.disyl
│   │   └── footer.disyl
│   ├── home.disyl
│   ├── single.disyl
│   ├── page.disyl
│   ├── archive.disyl
│   └── 404.disyl
├── includes/
│   ├── class-theme-manifest.php
│   ├── class-theme-customizer.php
│   └── template-functions.php
├── functions.php
├── index.php
├── manifest.json
├── style.css
└── README.md
```

## Best Practices

1. **Always extend AbstractThemeGenerator** - It provides common functionality
2. **Use stubs for complex templates** - Keep code clean and maintainable
3. **Validate configuration** - Override `validate()` for custom rules
4. **Support preview mode** - Implement `preview()` for live editing
5. **Document features** - Return detailed feature info from `getSupportedFeatures()`

## License

Part of Ikabud Kernel - GPL-2.0-or-later
