# Multi-CMS Theme Package Example

This example demonstrates how to create a theme package that supports multiple CMSs using DiSyL CMS header declarations.

## Theme Structure

```
my-theme/
├── templates/
│   ├── drupal/
│   │   ├── home.disyl
│   │   ├── article.disyl
│   │   └── archive.disyl
│   ├── wordpress/
│   │   ├── home.disyl
│   │   ├── single.disyl
│   │   └── archive.disyl
│   └── joomla/
│       ├── home.disyl
│       ├── article.disyl
│       └── category.disyl
└── README.md
```

## Drupal Template (templates/drupal/home.disyl)

```disyl
{ikb_cms type="drupal" set="components,filters" /}

{drupal_articles limit=6 /}
{drupal_menu name="main" /}
```

## WordPress Template (templates/wordpress/home.disyl)

```disyl
{ikb_cms type="wordpress" set="components,filters" /}

{wp_posts limit=5 /}
{wp_menu location="primary" /}
```

## Joomla Template (templates/joomla/home.disyl)

```disyl
{ikb_cms type="joomla" set="components" /}

{joomla_articles limit=6 /}
{joomla_menu name="mainmenu" /}
```

## Benefits

1. **Single Theme Package**: One theme supports Drupal, WordPress, and Joomla
2. **Independent Manifests**: Each template loads only its required CMS manifests
3. **Optimized Performance**: No unnecessary manifest loading
4. **Clear Dependencies**: Each template declares its requirements upfront
5. **Easy Maintenance**: CMS-specific templates in separate folders

## Usage

### Drupal
```php
$engine = new Engine();
$html = $engine->renderFile('templates/drupal/home.disyl', $renderer, $context);
```

### WordPress
```php
$engine = new Engine();
$html = $engine->renderFile('templates/wordpress/home.disyl', $renderer, $context);
```

### Joomla
```php
$engine = new Engine();
$html = $engine->renderFile('templates/joomla/home.disyl', $renderer, $context);
```

Each template automatically loads the correct CMS integration layer!
