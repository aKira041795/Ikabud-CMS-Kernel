<?php
/**
 * Phoenix Template for Joomla
 * 
 * DiSyL-powered Joomla template with modern design
 * 
 * @package     Phoenix
 * @subpackage  Templates.phoenix
 * @version     1.0.0
 * @author      Ikabud Team
 * @copyright   (C) 2025 Ikabud. All rights reserved.
 * @license     GPL-2.0-or-later
 */

defined('_JEXEC') or die;

// Enable error display for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Uri\Uri;

/** @var Joomla\CMS\Document\HtmlDocument $this */

// Load DiSyL Kernel Autoloader
$autoloadPath = '/var/www/html/ikabud-kernel/vendor/autoload.php';
if (file_exists($autoloadPath)) {
    require_once $autoloadPath;
} else {
    error_log('Phoenix Template: DiSyL autoloader not found at ' . $autoloadPath);
}

// Load Phoenix helper functions
require_once __DIR__ . '/includes/helper.php';
require_once __DIR__ . '/includes/disyl-integration.php';

$app   = Factory::getApplication();
$input = $app->getInput();
$wa    = $this->getWebAssetManager();

// Detecting Active Variables
$option   = $input->getCmd('option', '');
$view     = $input->getCmd('view', '');
$layout   = $input->getCmd('layout', '');
$task     = $input->getCmd('task', '');
$itemid   = $input->getCmd('Itemid', '');
$sitename = htmlspecialchars($app->get('sitename'), ENT_QUOTES, 'UTF-8');
$menu     = $app->getMenu()->getActive();
$pageclass = $menu !== null ? $menu->getParams()->get('pageclass_sfx', '') : '';

// Template Parameters
$logoFile = $this->params->get('logoFile');
$siteTitle = $this->params->get('siteTitle');
$siteDescription = $this->params->get('siteDescription');
$stickyHeader = $this->params->get('stickyHeader', 1);
$showSearch = $this->params->get('showSearch', 1);
$footerColumns = $this->params->get('footerColumns', 4);
$showSocial = $this->params->get('showSocial', 1);
$copyrightText = $this->params->get('copyrightText', '© 2025 All rights reserved.');
$colorScheme = $this->params->get('colorScheme', 'default');
$fluidContainer = $this->params->get('fluidContainer', 0);
$backTop = $this->params->get('backTop', 1);

// Logo
if ($logoFile) {
    $logo = HTMLHelper::_('image', Uri::root(false) . htmlspecialchars($logoFile, ENT_QUOTES), $sitename, ['loading' => 'eager', 'decoding' => 'async'], false, 0);
} elseif ($siteTitle) {
    $logo = '<span title="' . $sitename . '">' . htmlspecialchars($siteTitle, ENT_COMPAT, 'UTF-8') . '</span>';
} else {
    $logo = '<span title="' . $sitename . '">' . $sitename . '</span>';
}

// Body classes
$hasClass = '';
if ($this->countModules('sidebar-left', true)) {
    $hasClass .= ' has-sidebar-left';
}
if ($this->countModules('sidebar-right', true)) {
    $hasClass .= ' has-sidebar-right';
}

$wrapper = $fluidContainer ? 'wrapper-fluid' : 'wrapper-static';
$stickyClass = $stickyHeader ? 'position-sticky sticky-top' : '';

// Set metadata
$this->setMetaData('viewport', 'width=device-width, initial-scale=1');

// Use assets from joomla.asset.json
$wa->usePreset('template.phoenix.' . ($this->direction === 'rtl' ? 'rtl' : 'ltr'))
    ->useStyle('template.active.language')
    ->useStyle('template.user')
    ->useScript('template.user');

// Try to render with DiSyL
$disylRendered = false;
$disylContent = '';
$debugInfo = '';

try {
    // Check if DiSyL classes are available
    if (!class_exists('PhoenixDisylIntegration')) {
        throw new Exception('PhoenixDisylIntegration class not found');
    }
    
    // Initialize DiSyL renderer
    $disylRenderer = new PhoenixDisylIntegration($this, $app);
    
    // Determine which template to use
    $templateFile = $disylRenderer->getTemplateFile($option, $view, $layout);
    
    error_log("Phoenix: Template file selected: " . ($templateFile ?: 'none'));
    
    if ($templateFile && file_exists($templateFile)) {
        // Build context for DiSyL
        $context = $disylRenderer->buildContext([
            'logo' => $logo,
            'sitename' => $sitename,
            'site_description' => $siteDescription,
            'sticky_header' => $stickyHeader,
            'show_search' => $showSearch,
            'footer_columns' => $footerColumns,
            'show_social' => $showSocial,
            'copyright_text' => $copyrightText,
            'color_scheme' => $colorScheme,
        ]);
        
        // Render with DiSyL
        $disylContent = $disylRenderer->render($templateFile, $context);
        $disylRendered = true;
        
        error_log("Phoenix: DiSyL rendering successful - " . strlen($disylContent) . " bytes");
    } else {
        error_log("Phoenix: Template file not found or doesn't exist: " . $templateFile);
    }
} catch (Exception $e) {
    // Log error and fall back to standard rendering
    error_log('Phoenix DiSyL Error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    error_log('Phoenix DiSyL Stack trace: ' . $e->getTraceAsString());
    
    $debugInfo = '<div style="background: #f44; color: white; padding: 20px; margin: 20px; border-radius: 5px;">';
    $debugInfo .= '<h2>DiSyL Error (Debug Mode)</h2>';
    $debugInfo .= '<p><strong>Message:</strong> ' . htmlspecialchars($e->getMessage()) . '</p>';
    $debugInfo .= '<p><strong>File:</strong> ' . htmlspecialchars($e->getFile()) . ':' . $e->getLine() . '</p>';
    $debugInfo .= '<pre>' . htmlspecialchars($e->getTraceAsString()) . '</pre>';
    $debugInfo .= '</div>';
    
    $disylRendered = false;
}

?>
<!DOCTYPE html>
<html lang="<?php echo $this->language; ?>" dir="<?php echo $this->direction; ?>">
<head>
    <jdoc:include type="metas" />
    <jdoc:include type="styles" />
    <jdoc:include type="scripts" />
    
    <!-- Phoenix Template Styles - Direct Load for Testing -->
    <link rel="stylesheet" href="<?php echo $this->baseurl; ?>/templates/<?php echo $this->template; ?>/assets/css/style.css">
    <link rel="stylesheet" href="<?php echo $this->baseurl; ?>/templates/<?php echo $this->template; ?>/assets/css/disyl-components.css">
    <script src="<?php echo $this->baseurl; ?>/templates/<?php echo $this->template; ?>/assets/js/phoenix.js" defer></script>
</head>
<body class="site phoenix-template <?php echo $option
    . ' ' . $wrapper
    . ' view-' . $view
    . ($layout ? ' layout-' . $layout : ' no-layout')
    . ($task ? ' task-' . $task : ' no-task')
    . ($itemid ? ' itemid-' . $itemid : '')
    . ($pageclass ? ' ' . $pageclass : '')
    . $hasClass
    . ' color-scheme-' . $colorScheme
    . ($this->direction == 'rtl' ? ' rtl' : '');
?>">
<!-- DEBUG: DiSyL Rendered = <?php echo $disylRendered ? 'YES' : 'NO'; ?> -->
<!-- DEBUG: Content Length = <?php echo strlen($disylContent); ?> bytes -->

<?php 
// Output debug info if any
if ($debugInfo) {
    echo $debugInfo;
}
?>

<?php if ($disylRendered): ?>
    <!-- DiSyL Rendered Content START -->
    <?php echo $disylContent; ?>
    <!-- DiSyL Rendered Content END -->
<?php else: ?>
    <!-- Fallback Standard Rendering -->
    <header class="header container-header full-width<?php echo $stickyClass ? ' ' . $stickyClass : ''; ?>">
        <?php if ($this->countModules('topbar')) : ?>
            <div class="container-topbar">
                <jdoc:include type="modules" name="topbar" style="none" />
            </div>
        <?php endif; ?>

        <div class="grid-child">
            <div class="navbar-brand">
                <a class="brand-logo" href="<?php echo $this->baseurl; ?>/">
                    <?php echo $logo; ?>
                </a>
                <?php if ($siteDescription) : ?>
                    <div class="site-description"><?php echo htmlspecialchars($siteDescription); ?></div>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($this->countModules('menu', true) || ($showSearch && $this->countModules('search', true))) : ?>
            <div class="grid-child container-nav">
                <?php if ($this->countModules('menu', true)) : ?>
                    <jdoc:include type="modules" name="menu" style="none" />
                <?php endif; ?>
                <?php if ($showSearch && $this->countModules('search', true)) : ?>
                    <div class="container-search">
                        <jdoc:include type="modules" name="search" style="none" />
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </header>

    <div class="site-grid">
        <?php if ($this->countModules('banner', true)) : ?>
            <div class="container-banner full-width">
                <jdoc:include type="modules" name="banner" style="none" />
            </div>
        <?php endif; ?>

        <?php if ($this->countModules('hero', true)) : ?>
            <div class="container-hero full-width">
                <jdoc:include type="modules" name="hero" style="none" />
            </div>
        <?php endif; ?>

        <?php if ($this->countModules('features', true)) : ?>
            <div class="container-features full-width">
                <jdoc:include type="modules" name="features" style="none" />
            </div>
        <?php endif; ?>

        <?php if ($this->countModules('top-a', true)) : ?>
            <div class="grid-child container-top-a">
                <jdoc:include type="modules" name="top-a" style="card" />
            </div>
        <?php endif; ?>

        <?php if ($this->countModules('sidebar-left', true)) : ?>
            <div class="grid-child container-sidebar-left">
                <jdoc:include type="modules" name="sidebar-left" style="card" />
            </div>
        <?php endif; ?>

        <div class="grid-child container-component">
            <jdoc:include type="modules" name="breadcrumbs" style="none" />
            <jdoc:include type="modules" name="main-top" style="card" />
            <jdoc:include type="message" />
            <main>
                <jdoc:include type="component" />
            </main>
            <jdoc:include type="modules" name="main-bottom" style="card" />
        </div>

        <?php if ($this->countModules('sidebar-right', true)) : ?>
            <div class="grid-child container-sidebar-right">
                <jdoc:include type="modules" name="sidebar-right" style="card" />
            </div>
        <?php endif; ?>

        <?php if ($this->countModules('bottom-a', true)) : ?>
            <div class="grid-child container-bottom-a">
                <jdoc:include type="modules" name="bottom-a" style="card" />
            </div>
        <?php endif; ?>
    </div>

    <?php if ($this->countModules('footer', true) || $this->countModules('footer-1', true) || $this->countModules('footer-2', true) || $this->countModules('footer-3', true) || $this->countModules('footer-4', true)) : ?>
        <footer class="container-footer footer full-width">
            <div class="footer-widgets">
                <?php for ($i = 1; $i <= $footerColumns; $i++) : ?>
                    <?php if ($this->countModules('footer-' . $i, true)) : ?>
                        <div class="footer-column">
                            <jdoc:include type="modules" name="footer-<?php echo $i; ?>" style="none" />
                        </div>
                    <?php endif; ?>
                <?php endfor; ?>
            </div>
            
            <?php if ($this->countModules('footer', true)) : ?>
                <div class="grid-child">
                    <jdoc:include type="modules" name="footer" style="none" />
                </div>
            <?php endif; ?>
            
            <div class="footer-bottom">
                <p><?php echo $copyrightText; ?></p>
            </div>
        </footer>
    <?php endif; ?>
<?php endif; ?>

<?php if ($backTop) : ?>
    <a href="#top" id="back-top" class="back-to-top-link" aria-label="<?php echo Text::_('TPL_PHOENIX_BACKTOTOP'); ?>">
        <span class="icon-arrow-up" aria-hidden="true">↑</span>
    </a>
<?php endif; ?>

<jdoc:include type="modules" name="debug" style="none" />
</body>
</html>
