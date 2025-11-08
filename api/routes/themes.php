<?php
/**
 * Theme Management API Routes
 */

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use IkabudKernel\Core\Kernel;

// List all themes
$app->get('/api/v1/themes', function (Request $request, Response $response) {
    $kernel = Kernel::getInstance();
    $db = $kernel->getDatabase();
    
    $type = $request->getQueryParams()['type'] ?? null;
    
    if ($type) {
        $stmt = $db->prepare("SELECT * FROM themes WHERE theme_type = ? ORDER BY theme_name");
        $stmt->execute([$type]);
    } else {
        $stmt = $db->query("SELECT * FROM themes ORDER BY theme_name");
    }
    
    $themes = $stmt->fetchAll();
    
    foreach ($themes as &$theme) {
        $theme['supports'] = json_decode($theme['supports'] ?? '[]', true);
    }
    
    $response->getBody()->write(json_encode([
        'total' => count($themes),
        'themes' => $themes
    ]));
    
    return $response->withHeader('Content-Type', 'application/json');
});

// Get single theme
$app->get('/api/v1/themes/{id}', function (Request $request, Response $response, array $args) {
    $kernel = Kernel::getInstance();
    $db = $kernel->getDatabase();
    
    $themeId = $args['id'];
    
    $stmt = $db->prepare("SELECT * FROM themes WHERE theme_id = ?");
    $stmt->execute([$themeId]);
    $theme = $stmt->fetch();
    
    if (!$theme) {
        $response->getBody()->write(json_encode(['error' => 'Theme not found']));
        return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
    }
    
    $theme['supports'] = json_decode($theme['supports'] ?? '[]', true);
    
    // Get theme files
    $stmt = $db->prepare("SELECT * FROM theme_files WHERE theme_id = ? ORDER BY file_path");
    $stmt->execute([$themeId]);
    $theme['files'] = $stmt->fetchAll();
    
    $response->getBody()->write(json_encode($theme));
    return $response->withHeader('Content-Type', 'application/json');
});

// Create new theme
$app->post('/api/v1/themes', function (Request $request, Response $response) {
    $kernel = Kernel::getInstance();
    $db = $kernel->getDatabase();
    
    $body = json_decode($request->getBody()->getContents(), true);
    
    if (!isset($body['theme_name']) || !isset($body['theme_type'])) {
        $response->getBody()->write(json_encode(['error' => 'theme_name and theme_type are required']));
        return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
    }
    
    $themeId = 'theme_' . bin2hex(random_bytes(8));
    $themePath = 'themes/' . $themeId;
    
    $stmt = $db->prepare("
        INSERT INTO themes 
        (theme_id, theme_name, theme_type, version, author, description, path, supports)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    
    $supports = json_encode($body['supports'] ?? ['dsl' => true, 'widgets' => true]);
    
    $stmt->execute([
        $themeId,
        $body['theme_name'],
        $body['theme_type'],
        $body['version'] ?? '1.0.0',
        $body['author'] ?? 'Unknown',
        $body['description'] ?? '',
        $themePath,
        $supports
    ]);
    
    // Create theme directory
    $fullPath = __DIR__ . '/../../' . $themePath;
    if (!is_dir($fullPath)) {
        mkdir($fullPath, 0755, true);
        mkdir($fullPath . '/templates', 0755, true);
        mkdir($fullPath . '/assets', 0755, true);
        mkdir($fullPath . '/assets/css', 0755, true);
        mkdir($fullPath . '/assets/js', 0755, true);
    }
    
    // Create default template files
    $defaultTemplates = [
        'index.ikb' => '<!DOCTYPE html><html><head><title>{ikb_site_name}</title></head><body><h1>Welcome</h1>{ikb_query type=post limit=5}</body></html>',
        'single.ikb' => '<!DOCTYPE html><html><head><title>{ikb_the_title}</title></head><body>{ikb_the_content}</body></html>',
        'style.css' => '/* Theme styles */',
    ];
    
    foreach ($defaultTemplates as $filename => $content) {
        $fileType = pathinfo($filename, PATHINFO_EXTENSION) === 'ikb' ? 'dsl' : 
                   (pathinfo($filename, PATHINFO_EXTENSION) === 'css' ? 'style' : 'template');
        
        $stmt = $db->prepare("
            INSERT INTO theme_files (theme_id, file_path, file_type, file_language, content)
            VALUES (?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $themeId,
            'templates/' . $filename,
            $fileType,
            pathinfo($filename, PATHINFO_EXTENSION),
            $content
        ]);
        
        file_put_contents($fullPath . '/templates/' . $filename, $content);
    }
    
    $response->getBody()->write(json_encode([
        'success' => true,
        'theme_id' => $themeId,
        'message' => 'Theme created successfully'
    ]));
    
    return $response->withStatus(201)->withHeader('Content-Type', 'application/json');
});

// Get theme files
$app->get('/api/v1/themes/{id}/files', function (Request $request, Response $response, array $args) {
    $kernel = Kernel::getInstance();
    $db = $kernel->getDatabase();
    
    $themeId = $args['id'];
    
    $stmt = $db->prepare("SELECT * FROM theme_files WHERE theme_id = ? ORDER BY file_path");
    $stmt->execute([$themeId]);
    $files = $stmt->fetchAll();
    
    $response->getBody()->write(json_encode([
        'total' => count($files),
        'files' => $files
    ]));
    
    return $response->withHeader('Content-Type', 'application/json');
});

// Create theme file
$app->post('/api/v1/themes/{id}/files', function (Request $request, Response $response, array $args) {
    $kernel = Kernel::getInstance();
    $db = $kernel->getDatabase();
    
    $themeId = $args['id'];
    $body = json_decode($request->getBody()->getContents(), true);
    
    if (!isset($body['file_path']) || !isset($body['content'])) {
        $response->getBody()->write(json_encode(['error' => 'file_path and content are required']));
        return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
    }
    
    $stmt = $db->prepare("
        INSERT INTO theme_files (theme_id, file_path, file_type, file_language, content)
        VALUES (?, ?, ?, ?, ?)
    ");
    
    $stmt->execute([
        $themeId,
        $body['file_path'],
        $body['file_type'] ?? 'template',
        $body['file_language'] ?? 'html',
        $body['content']
    ]);
    
    $response->getBody()->write(json_encode([
        'success' => true,
        'file_id' => $db->lastInsertId(),
        'message' => 'File created successfully'
    ]));
    
    return $response->withStatus(201)->withHeader('Content-Type', 'application/json');
});

// Update theme file
$app->put('/api/v1/themes/{id}/files/{fileId}', function (Request $request, Response $response, array $args) {
    $kernel = Kernel::getInstance();
    $db = $kernel->getDatabase();
    
    $themeId = $args['id'];
    $fileId = $args['fileId'];
    $body = json_decode($request->getBody()->getContents(), true);
    
    if (!isset($body['content'])) {
        $response->getBody()->write(json_encode(['error' => 'content is required']));
        return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
    }
    
    $stmt = $db->prepare("
        UPDATE theme_files 
        SET content = ?, is_compiled = FALSE, updated_at = NOW()
        WHERE id = ? AND theme_id = ?
    ");
    
    $stmt->execute([$body['content'], $fileId, $themeId]);
    
    if ($stmt->rowCount() > 0) {
        $response->getBody()->write(json_encode(['success' => true, 'message' => 'File updated']));
        return $response->withHeader('Content-Type', 'application/json');
    } else {
        $response->getBody()->write(json_encode(['error' => 'File not found']));
        return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
    }
});

// Delete theme file
$app->delete('/api/v1/themes/{id}/files/{fileId}', function (Request $request, Response $response, array $args) {
    $kernel = Kernel::getInstance();
    $db = $kernel->getDatabase();
    
    $themeId = $args['id'];
    $fileId = $args['fileId'];
    
    $stmt = $db->prepare("DELETE FROM theme_files WHERE id = ? AND theme_id = ?");
    $stmt->execute([$fileId, $themeId]);
    
    if ($stmt->rowCount() > 0) {
        $response->getBody()->write(json_encode(['success' => true, 'message' => 'File deleted']));
        return $response->withHeader('Content-Type', 'application/json');
    } else {
        $response->getBody()->write(json_encode(['error' => 'File not found']));
        return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
    }
});

// Activate theme
$app->post('/api/v1/themes/{id}/activate', function (Request $request, Response $response, array $args) {
    $kernel = Kernel::getInstance();
    $db = $kernel->getDatabase();
    
    $themeId = $args['id'];
    
    // Deactivate all themes
    $db->exec("UPDATE themes SET is_active = FALSE");
    
    // Activate selected theme
    $stmt = $db->prepare("UPDATE themes SET is_active = TRUE WHERE theme_id = ?");
    $stmt->execute([$themeId]);
    
    if ($stmt->rowCount() > 0) {
        $response->getBody()->write(json_encode(['success' => true, 'message' => 'Theme activated']));
        return $response->withHeader('Content-Type', 'application/json');
    } else {
        $response->getBody()->write(json_encode(['error' => 'Theme not found']));
        return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
    }
});

// Preview theme
$app->get('/api/v1/themes/{id}/preview', function (Request $request, Response $response, array $args) {
    $kernel = Kernel::getInstance();
    $db = $kernel->getDatabase();
    
    $themeId = $args['id'];
    
    // Get index template
    $stmt = $db->prepare("
        SELECT content FROM theme_files 
        WHERE theme_id = ? AND file_path LIKE '%index.ikb'
        LIMIT 1
    ");
    $stmt->execute([$themeId]);
    $file = $stmt->fetch();
    
    if (!$file) {
        $response->getBody()->write('<html><body><h1>No preview available</h1></body></html>');
        return $response->withHeader('Content-Type', 'text/html');
    }
    
    // TODO: Compile DSL and render
    $html = $file['content'];
    
    $response->getBody()->write($html);
    return $response->withHeader('Content-Type', 'text/html');
});
