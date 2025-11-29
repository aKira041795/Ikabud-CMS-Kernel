<?php
/**
 * Theme Generator API Routes
 * 
 * Extensible theme generation system for DiSyL Visual Builder
 * 
 * @package IkabudKernel
 * @version 1.0.0
 */

// Load the theme generator system
require_once __DIR__ . '/../../kernel/ThemeGenerator/ThemeGeneratorFactory.php';

use IkabudKernel\ThemeGenerator\ThemeGeneratorFactory;

/**
 * POST /api/theme/generate
 * 
 * Generate a complete theme package for the specified CMS
 * 
 * Request body:
 * {
 *   "cms": "wordpress|joomla|drupal|native",
 *   "themeName": "My Theme",
 *   "themeSlug": "my-theme",
 *   "author": "Developer Name",
 *   "description": "Theme description",
 *   "version": "1.0.0",
 *   "templates": {
 *     "home": "{ikb_section...}",
 *     "single": "...",
 *     "components/header": "..."
 *   },
 *   "options": {
 *     "includeCustomizer": true,
 *     "includeWidgetAreas": true,
 *     "menuLocations": ["primary", "footer"],
 *     "colorScheme": {...}
 *   }
 * }
 */
$router->post('/theme/generate', function($request) {
    try {
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (!$data) {
            return jsonResponse(['error' => 'Invalid JSON payload'], 400);
        }
        
        // Validate required fields
        $required = ['cms', 'themeName', 'templates'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                return jsonResponse(['error' => "Missing required field: {$field}"], 400);
            }
        }
        
        // Get the appropriate generator
        $generator = ThemeGeneratorFactory::create($data['cms']);
        
        if (!$generator) {
            return jsonResponse(['error' => "Unsupported CMS: {$data['cms']}"], 400);
        }
        
        // Generate the theme
        $result = $generator->generate($data);
        
        return jsonResponse([
            'success' => true,
            'theme' => $result['theme'],
            'files' => $result['files'],
            'downloadUrl' => $result['downloadUrl'] ?? null
        ]);
        
    } catch (Exception $e) {
        error_log("Theme Generator Error: " . $e->getMessage());
        return jsonResponse(['error' => $e->getMessage()], 500);
    }
});

/**
 * GET /api/theme/templates
 * 
 * Get available base templates for a CMS
 */
$router->get('/theme/templates', function($request) {
    $cms = $_GET['cms'] ?? 'wordpress';
    
    try {
        $generator = ThemeGeneratorFactory::create($cms);
        
        if (!$generator) {
            return jsonResponse(['error' => "Unsupported CMS: {$cms}"], 400);
        }
        
        return jsonResponse([
            'cms' => $cms,
            'templates' => $generator->getBaseTemplates(),
            'components' => $generator->getBaseComponents(),
            'features' => $generator->getSupportedFeatures()
        ]);
        
    } catch (Exception $e) {
        return jsonResponse(['error' => $e->getMessage()], 500);
    }
});

/**
 * GET /api/theme/preview
 * 
 * Preview generated files without creating the package
 */
$router->post('/theme/preview', function($request) {
    try {
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (!$data || empty($data['cms']) || empty($data['templates'])) {
            return jsonResponse(['error' => 'Invalid request'], 400);
        }
        
        $generator = ThemeGeneratorFactory::create($data['cms']);
        
        if (!$generator) {
            return jsonResponse(['error' => "Unsupported CMS: {$data['cms']}"], 400);
        }
        
        // Generate preview (files content without saving)
        $preview = $generator->preview($data);
        
        return jsonResponse([
            'success' => true,
            'files' => $preview
        ]);
        
    } catch (Exception $e) {
        return jsonResponse(['error' => $e->getMessage()], 500);
    }
});

/**
 * Helper function for JSON responses
 */
function jsonResponse($data, $status = 200) {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_PRETTY_PRINT);
    exit;
}
