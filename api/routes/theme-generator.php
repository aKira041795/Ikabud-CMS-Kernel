<?php
/**
 * Theme Generator API Routes
 * 
 * Extensible theme generation system for DiSyL Visual Builder
 * 
 * @package IkabudKernel
 * @version 1.0.0
 */

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

// Load the theme generator system
require_once __DIR__ . '/../../kernel/ThemeGenerator/ThemeGeneratorFactory.php';

use IkabudKernel\ThemeGenerator\ThemeGeneratorFactory;

/**
 * POST /api/theme/generate
 * 
 * Generate a complete theme package for the specified CMS
 */
$app->post('/api/theme/generate', function (Request $request, Response $response) {
    try {
        $data = json_decode($request->getBody()->getContents(), true);
        
        if (!$data) {
            $response->getBody()->write(json_encode(['error' => 'Invalid JSON payload']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
        
        // Validate required fields
        $required = ['cms', 'themeName', 'templates'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                $response->getBody()->write(json_encode(['error' => "Missing required field: {$field}"]));
                return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
            }
        }
        
        // Get the appropriate generator
        $generator = ThemeGeneratorFactory::create($data['cms']);
        
        if (!$generator) {
            $response->getBody()->write(json_encode(['error' => "Unsupported CMS: {$data['cms']}"]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
        
        // Generate the theme
        $result = $generator->generate($data);
        
        $response->getBody()->write(json_encode([
            'success' => true,
            'theme' => $result['theme'],
            'files' => $result['files'],
            'downloadUrl' => $result['downloadUrl'] ?? null
        ]));
        return $response->withHeader('Content-Type', 'application/json');
        
    } catch (Exception $e) {
        error_log("Theme Generator Error: " . $e->getMessage());
        $response->getBody()->write(json_encode(['error' => $e->getMessage()]));
        return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
    }
});

/**
 * GET /api/theme/templates
 * 
 * Get available base templates for a CMS
 */
$app->get('/api/theme/templates', function (Request $request, Response $response) {
    $params = $request->getQueryParams();
    $cms = $params['cms'] ?? 'wordpress';
    
    try {
        $generator = ThemeGeneratorFactory::create($cms);
        
        if (!$generator) {
            $response->getBody()->write(json_encode(['error' => "Unsupported CMS: {$cms}"]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
        
        $response->getBody()->write(json_encode([
            'cms' => $cms,
            'templates' => $generator->getBaseTemplates(),
            'components' => $generator->getBaseComponents(),
            'features' => $generator->getSupportedFeatures()
        ]));
        return $response->withHeader('Content-Type', 'application/json');
        
    } catch (Exception $e) {
        $response->getBody()->write(json_encode(['error' => $e->getMessage()]));
        return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
    }
});

/**
 * POST /api/theme/preview
 * 
 * Preview generated files without creating the package
 */
$app->post('/api/theme/preview', function (Request $request, Response $response) {
    try {
        $data = json_decode($request->getBody()->getContents(), true);
        
        if (!$data || empty($data['cms']) || empty($data['templates'])) {
            $response->getBody()->write(json_encode(['error' => 'Invalid request']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
        
        $generator = ThemeGeneratorFactory::create($data['cms']);
        
        if (!$generator) {
            $response->getBody()->write(json_encode(['error' => "Unsupported CMS: {$data['cms']}"]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
        
        // Generate preview (files content without saving)
        $preview = $generator->preview($data);
        
        $response->getBody()->write(json_encode([
            'success' => true,
            'files' => $preview
        ]));
        return $response->withHeader('Content-Type', 'application/json');
        
    } catch (Exception $e) {
        $response->getBody()->write(json_encode(['error' => $e->getMessage()]));
        return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
    }
});
