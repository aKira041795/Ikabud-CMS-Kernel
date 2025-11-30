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

// ============================================================================
// FILESYSTEM-BASED THEME MANAGEMENT (for instances)
// ============================================================================

/**
 * Helper: Detect CMS type from instance directory
 */
function detectCMSType(string $instancePath): ?string {
    if (file_exists($instancePath . '/wp-config.php') || is_dir($instancePath . '/wp-content')) {
        return 'wordpress';
    }
    if (file_exists($instancePath . '/configuration.php') || is_dir($instancePath . '/administrator')) {
        return 'joomla';
    }
    if (file_exists($instancePath . '/core/lib/Drupal') || is_dir($instancePath . '/sites/default')) {
        return 'drupal';
    }
    return 'native';
}

/**
 * Helper: Get theme directory path for CMS
 */
function getThemePath(string $instancePath, string $cmsType): string {
    switch ($cmsType) {
        case 'wordpress':
            return $instancePath . '/wp-content/themes';
        case 'joomla':
            return $instancePath . '/templates';
        case 'drupal':
            return $instancePath . '/themes';
        default:
            return $instancePath . '/themes';
    }
}

/**
 * Helper: Recursively scan directory for file tree
 */
function scanDirectoryTree(string $path, string $basePath = '', int $depth = 0, int $maxDepth = 5): array {
    $result = [];
    if ($depth > $maxDepth || !is_dir($path)) {
        return $result;
    }
    
    $items = scandir($path);
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        
        $fullPath = $path . '/' . $item;
        $relativePath = $basePath ? $basePath . '/' . $item : $item;
        
        if (is_dir($fullPath)) {
            $result[] = [
                'name' => $item,
                'path' => $relativePath,
                'type' => 'directory',
                'children' => scanDirectoryTree($fullPath, $relativePath, $depth + 1, $maxDepth)
            ];
        } else {
            $ext = pathinfo($item, PATHINFO_EXTENSION);
            $result[] = [
                'name' => $item,
                'path' => $relativePath,
                'type' => 'file',
                'extension' => $ext,
                'size' => filesize($fullPath),
                'modified' => filemtime($fullPath)
            ];
        }
    }
    
    // Sort: directories first, then files
    usort($result, function($a, $b) {
        if ($a['type'] !== $b['type']) {
            return $a['type'] === 'directory' ? -1 : 1;
        }
        return strcasecmp($a['name'], $b['name']);
    });
    
    return $result;
}

/**
 * List all instances with their CMS type (filesystem-based)
 */
$app->get('/api/v1/filesystem/instances', function (Request $request, Response $response) {
    $instancesPath = __DIR__ . '/../../instances';
    $instances = [];
    
    if (!is_dir($instancesPath)) {
        $response->getBody()->write(json_encode(['instances' => [], 'total' => 0]));
        return $response->withHeader('Content-Type', 'application/json');
    }
    
    $dirs = scandir($instancesPath);
    foreach ($dirs as $dir) {
        if ($dir === '.' || $dir === '..' || $dir === '.gitkeep') continue;
        
        $fullPath = $instancesPath . '/' . $dir;
        if (!is_dir($fullPath)) continue;
        
        $cmsType = detectCMSType($fullPath);
        $themePath = getThemePath($fullPath, $cmsType);
        $themeCount = 0;
        
        if (is_dir($themePath)) {
            $themes = array_filter(scandir($themePath), function($t) use ($themePath) {
                return $t !== '.' && $t !== '..' && is_dir($themePath . '/' . $t);
            });
            $themeCount = count($themes);
        }
        
        $instances[] = [
            'id' => $dir,
            'name' => $dir,
            'path' => $fullPath,
            'cms_type' => $cmsType,
            'theme_path' => $themePath,
            'theme_count' => $themeCount
        ];
    }
    
    $response->getBody()->write(json_encode([
        'instances' => $instances,
        'total' => count($instances)
    ]));
    
    return $response->withHeader('Content-Type', 'application/json');
});

/**
 * List themes in an instance (filesystem-based)
 */
$app->get('/api/v1/filesystem/instances/{instanceId}/themes', function (Request $request, Response $response, array $args) {
    $instanceId = $args['instanceId'];
    $instancePath = __DIR__ . '/../../instances/' . $instanceId;
    
    if (!is_dir($instancePath)) {
        $response->getBody()->write(json_encode(['error' => 'Instance not found']));
        return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
    }
    
    $cmsType = detectCMSType($instancePath);
    $themePath = getThemePath($instancePath, $cmsType);
    $themes = [];
    
    if (is_dir($themePath)) {
        $dirs = scandir($themePath);
        foreach ($dirs as $dir) {
            if ($dir === '.' || $dir === '..') continue;
            
            $themeFullPath = $themePath . '/' . $dir;
            if (!is_dir($themeFullPath)) continue;
            
            // Try to get theme info
            $themeInfo = [
                'id' => $dir,
                'name' => $dir,
                'path' => $themeFullPath,
                'cms_type' => $cmsType
            ];
            
            // WordPress: read style.css header
            if ($cmsType === 'wordpress' && file_exists($themeFullPath . '/style.css')) {
                $styleContent = file_get_contents($themeFullPath . '/style.css', false, null, 0, 8192);
                if (preg_match('/Theme Name:\s*(.+)/i', $styleContent, $m)) {
                    $themeInfo['name'] = trim($m[1]);
                }
                if (preg_match('/Version:\s*(.+)/i', $styleContent, $m)) {
                    $themeInfo['version'] = trim($m[1]);
                }
                if (preg_match('/Author:\s*(.+)/i', $styleContent, $m)) {
                    $themeInfo['author'] = trim($m[1]);
                }
            }
            
            // Check for DiSyL templates
            $themeInfo['has_disyl'] = is_dir($themeFullPath . '/disyl');
            
            // Check for manifest
            if (file_exists($themeFullPath . '/manifest.json')) {
                $manifest = json_decode(file_get_contents($themeFullPath . '/manifest.json'), true);
                if ($manifest) {
                    $themeInfo['manifest'] = $manifest;
                    $themeInfo['name'] = $manifest['name'] ?? $themeInfo['name'];
                }
            }
            
            $themes[] = $themeInfo;
        }
    }
    
    $response->getBody()->write(json_encode([
        'instance_id' => $instanceId,
        'cms_type' => $cmsType,
        'theme_path' => $themePath,
        'themes' => $themes,
        'total' => count($themes)
    ]));
    
    return $response->withHeader('Content-Type', 'application/json');
});

/**
 * Get theme file tree
 */
$app->get('/api/v1/filesystem/instances/{instanceId}/themes/{themeId}/tree', function (Request $request, Response $response, array $args) {
    $instanceId = $args['instanceId'];
    $themeId = $args['themeId'];
    $instancePath = __DIR__ . '/../../instances/' . $instanceId;
    
    if (!is_dir($instancePath)) {
        $response->getBody()->write(json_encode(['error' => 'Instance not found']));
        return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
    }
    
    $cmsType = detectCMSType($instancePath);
    $themePath = getThemePath($instancePath, $cmsType) . '/' . $themeId;
    
    if (!is_dir($themePath)) {
        $response->getBody()->write(json_encode(['error' => 'Theme not found']));
        return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
    }
    
    $tree = scanDirectoryTree($themePath);
    
    $response->getBody()->write(json_encode([
        'instance_id' => $instanceId,
        'theme_id' => $themeId,
        'theme_path' => $themePath,
        'tree' => $tree
    ]));
    
    return $response->withHeader('Content-Type', 'application/json');
});

/**
 * Read theme file content
 */
$app->get('/api/v1/filesystem/instances/{instanceId}/themes/{themeId}/files', function (Request $request, Response $response, array $args) {
    $instanceId = $args['instanceId'];
    $themeId = $args['themeId'];
    $filePath = $request->getQueryParams()['path'] ?? '';
    
    $instancePath = __DIR__ . '/../../instances/' . $instanceId;
    $cmsType = detectCMSType($instancePath);
    $themePath = getThemePath($instancePath, $cmsType) . '/' . $themeId;
    
    // Security: prevent directory traversal
    $fullPath = realpath($themePath . '/' . $filePath);
    if (!$fullPath || strpos($fullPath, realpath($themePath)) !== 0) {
        $response->getBody()->write(json_encode(['error' => 'Invalid file path']));
        return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
    }
    
    if (!file_exists($fullPath) || is_dir($fullPath)) {
        $response->getBody()->write(json_encode(['error' => 'File not found']));
        return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
    }
    
    $content = file_get_contents($fullPath);
    $ext = pathinfo($fullPath, PATHINFO_EXTENSION);
    
    // Determine language for syntax highlighting
    $languageMap = [
        'php' => 'php',
        'js' => 'javascript',
        'ts' => 'typescript',
        'css' => 'css',
        'scss' => 'scss',
        'html' => 'html',
        'twig' => 'twig',
        'json' => 'json',
        'xml' => 'xml',
        'yml' => 'yaml',
        'yaml' => 'yaml',
        'md' => 'markdown',
        'disyl' => 'disyl'
    ];
    
    $response->getBody()->write(json_encode([
        'path' => $filePath,
        'content' => $content,
        'extension' => $ext,
        'language' => $languageMap[$ext] ?? 'plaintext',
        'size' => strlen($content),
        'modified' => filemtime($fullPath)
    ]));
    
    return $response->withHeader('Content-Type', 'application/json');
});

/**
 * Save theme file content
 */
$app->put('/api/v1/filesystem/instances/{instanceId}/themes/{themeId}/files', function (Request $request, Response $response, array $args) {
    $instanceId = $args['instanceId'];
    $themeId = $args['themeId'];
    $body = json_decode($request->getBody()->getContents(), true);
    
    $filePath = $body['path'] ?? '';
    $content = $body['content'] ?? '';
    
    $instancePath = __DIR__ . '/../../instances/' . $instanceId;
    $cmsType = detectCMSType($instancePath);
    $themePath = getThemePath($instancePath, $cmsType) . '/' . $themeId;
    
    // Security: prevent directory traversal
    $fullPath = $themePath . '/' . $filePath;
    $realThemePath = realpath($themePath);
    
    // For new files, check parent directory
    $parentDir = dirname($fullPath);
    if (!is_dir($parentDir)) {
        mkdir($parentDir, 0755, true);
    }
    
    $realFullPath = realpath(dirname($fullPath)) . '/' . basename($fullPath);
    if (strpos($realFullPath, $realThemePath) !== 0) {
        $response->getBody()->write(json_encode(['error' => 'Invalid file path']));
        return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
    }
    
    // Write file
    $result = file_put_contents($fullPath, $content);
    
    if ($result !== false) {
        $response->getBody()->write(json_encode([
            'success' => true,
            'path' => $filePath,
            'size' => $result
        ]));
        return $response->withHeader('Content-Type', 'application/json');
    } else {
        $response->getBody()->write(json_encode(['error' => 'Failed to write file']));
        return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
    }
});

/**
 * Create new theme in instance
 */
$app->post('/api/v1/filesystem/instances/{instanceId}/themes', function (Request $request, Response $response, array $args) {
    $instanceId = $args['instanceId'];
    $body = json_decode($request->getBody()->getContents(), true);
    
    $themeName = $body['name'] ?? '';
    $themeSlug = $body['slug'] ?? preg_replace('/[^a-z0-9-]/', '-', strtolower($themeName));
    
    if (empty($themeName)) {
        $response->getBody()->write(json_encode(['error' => 'Theme name is required']));
        return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
    }
    
    $instancePath = __DIR__ . '/../../instances/' . $instanceId;
    
    if (!is_dir($instancePath)) {
        $response->getBody()->write(json_encode(['error' => 'Instance not found']));
        return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
    }
    
    $cmsType = detectCMSType($instancePath);
    $themePath = getThemePath($instancePath, $cmsType) . '/' . $themeSlug;
    
    if (is_dir($themePath)) {
        $response->getBody()->write(json_encode(['error' => 'Theme already exists']));
        return $response->withStatus(409)->withHeader('Content-Type', 'application/json');
    }
    
    // Create theme directory structure
    mkdir($themePath, 0755, true);
    mkdir($themePath . '/disyl', 0755, true);
    mkdir($themePath . '/assets', 0755, true);
    mkdir($themePath . '/assets/css', 0755, true);
    mkdir($themePath . '/assets/js', 0755, true);
    
    // Create CMS-specific files
    if ($cmsType === 'wordpress') {
        // style.css with theme header
        $styleCss = <<<CSS
/*
Theme Name: {$themeName}
Theme URI: 
Author: Ikabud
Author URI: 
Description: Theme created with Ikabud Visual Builder
Version: 1.0.0
License: GPL-2.0+
License URI: http://www.gnu.org/licenses/gpl-2.0.html
Text Domain: {$themeSlug}
*/
CSS;
        file_put_contents($themePath . '/style.css', $styleCss);
        
        // functions.php
        $functionsPhp = <<<PHP
<?php
/**
 * {$themeName} functions and definitions
 */

// Theme setup
function {$themeSlug}_setup() {
    add_theme_support('title-tag');
    add_theme_support('post-thumbnails');
    add_theme_support('html5', ['search-form', 'comment-form', 'comment-list', 'gallery', 'caption']);
    
    register_nav_menus([
        'primary' => __('Primary Menu', '{$themeSlug}'),
        'footer' => __('Footer Menu', '{$themeSlug}'),
    ]);
}
add_action('after_setup_theme', '{$themeSlug}_setup');

// Enqueue scripts and styles
function {$themeSlug}_scripts() {
    wp_enqueue_style('{$themeSlug}-style', get_stylesheet_uri(), [], '1.0.0');
}
add_action('wp_enqueue_scripts', '{$themeSlug}_scripts');
PHP;
        file_put_contents($themePath . '/functions.php', $functionsPhp);
        
        // index.php
        $indexPhp = <<<PHP
<?php get_header(); ?>
<main>
    <?php if (have_posts()): while (have_posts()): the_post(); ?>
        <article>
            <h2><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2>
            <?php the_excerpt(); ?>
        </article>
    <?php endwhile; endif; ?>
</main>
<?php get_footer(); ?>
PHP;
        file_put_contents($themePath . '/index.php', $indexPhp);
    }
    
    // Create manifest.json
    $manifest = [
        'name' => $themeName,
        'slug' => $themeSlug,
        'version' => '1.0.0',
        'cms_type' => $cmsType,
        'created' => date('Y-m-d H:i:s'),
        'disyl_enabled' => true
    ];
    file_put_contents($themePath . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT));
    
    // Create default DiSyL templates
    $homeDisyl = <<<DISYL
{ikb_platform type="web" /}
{ikb_cms type="{$cmsType}" /}

{include file="header.disyl" /}

{ikb_section type="hero" padding="large"}
    {ikb_container size="xlarge"}
        {ikb_text tag="h1" size="4xl" weight="bold" align="center"}
            Welcome to {$themeName}
        {/ikb_text}
    {/ikb_container}
{/ikb_section}

{ikb_section type="content"}
    {ikb_container}
        {ikb_query type="post" limit="6" orderby="date" order="desc"}
            {for items="{posts}" as="post"}
                {ikb_card}
                    {if condition="{post.thumbnail}"}
                        {ikb_image src="{post.thumbnail}" alt="{post.title}" /}
                    {/if}
                    {ikb_text tag="h2" size="xl"}{post.title | esc_html}{/ikb_text}
                    {ikb_text}{post.excerpt | truncate:150}{/ikb_text}
                {/ikb_card}
            {/for}
        {/ikb_query}
    {/ikb_container}
{/ikb_section}

{include file="footer.disyl" /}
DISYL;
    file_put_contents($themePath . '/disyl/home.disyl', $homeDisyl);
    
    $headerDisyl = <<<DISYL
{ikb_section type="header"}
    {ikb_container}
        {ikb_row align="center" justify="between"}
            {ikb_col span="3"}
                {ikb_link href="{site.url}"}
                    {ikb_text tag="span" size="xl" weight="bold"}{site.name}{/ikb_text}
                {/ikb_link}
            {/ikb_col}
            {ikb_col span="9"}
                {ikb_menu location="primary" /}
            {/ikb_col}
        {/ikb_row}
    {/ikb_container}
{/ikb_section}
DISYL;
    file_put_contents($themePath . '/disyl/header.disyl', $headerDisyl);
    
    $footerDisyl = <<<DISYL
{ikb_section type="footer" bg="dark"}
    {ikb_container}
        {ikb_row}
            {ikb_col span="6"}
                {ikb_text color="#ffffff"}© {site.name} - All rights reserved{/ikb_text}
            {/ikb_col}
            {ikb_col span="6"}
                {ikb_menu location="footer" /}
            {/ikb_col}
        {/ikb_row}
    {/ikb_container}
{/ikb_section}
DISYL;
    file_put_contents($themePath . '/disyl/footer.disyl', $footerDisyl);
    
    $response->getBody()->write(json_encode([
        'success' => true,
        'theme_id' => $themeSlug,
        'theme_path' => $themePath,
        'cms_type' => $cmsType
    ]));
    
    return $response->withStatus(201)->withHeader('Content-Type', 'application/json');
});

/**
 * Upload theme ZIP to instance
 */
$app->post('/api/v1/filesystem/instances/{instanceId}/themes/upload', function (Request $request, Response $response, array $args) {
    $instanceId = $args['instanceId'];
    $instancePath = __DIR__ . '/../../instances/' . $instanceId;
    
    if (!is_dir($instancePath)) {
        $response->getBody()->write(json_encode(['error' => 'Instance not found']));
        return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
    }
    
    $uploadedFiles = $request->getUploadedFiles();
    
    if (empty($uploadedFiles['theme'])) {
        $response->getBody()->write(json_encode(['error' => 'No theme file uploaded']));
        return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
    }
    
    $uploadedFile = $uploadedFiles['theme'];
    
    if ($uploadedFile->getError() !== UPLOAD_ERR_OK) {
        $response->getBody()->write(json_encode(['error' => 'Upload failed']));
        return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
    }
    
    // Check file type
    $filename = $uploadedFile->getClientFilename();
    if (pathinfo($filename, PATHINFO_EXTENSION) !== 'zip') {
        $response->getBody()->write(json_encode(['error' => 'Only ZIP files are allowed']));
        return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
    }
    
    $cmsType = detectCMSType($instancePath);
    $themesPath = getThemePath($instancePath, $cmsType);
    
    // Save uploaded file temporarily
    $tempPath = sys_get_temp_dir() . '/' . uniqid('theme_') . '.zip';
    $uploadedFile->moveTo($tempPath);
    
    // Extract ZIP
    $zip = new ZipArchive();
    if ($zip->open($tempPath) !== true) {
        unlink($tempPath);
        $response->getBody()->write(json_encode(['error' => 'Invalid ZIP file']));
        return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
    }
    
    // Get theme folder name from ZIP
    $themeName = null;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if (strpos($name, '/') !== false) {
            $themeName = explode('/', $name)[0];
            break;
        }
    }
    
    if (!$themeName) {
        $themeName = pathinfo($filename, PATHINFO_FILENAME);
    }
    
    // Extract to themes directory
    $extractPath = $themesPath;
    $zip->extractTo($extractPath);
    $zip->close();
    
    // Clean up
    unlink($tempPath);
    
    $response->getBody()->write(json_encode([
        'success' => true,
        'theme_id' => $themeName,
        'theme_path' => $themesPath . '/' . $themeName
    ]));
    
    return $response->withStatus(201)->withHeader('Content-Type', 'application/json');
});

/**
 * Delete theme from instance
 */
$app->delete('/api/v1/filesystem/instances/{instanceId}/themes/{themeId}', function (Request $request, Response $response, array $args) {
    $instanceId = $args['instanceId'];
    $themeId = $args['themeId'];
    
    $instancePath = __DIR__ . '/../../instances/' . $instanceId;
    
    if (!is_dir($instancePath)) {
        $response->getBody()->write(json_encode(['error' => 'Instance not found']));
        return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
    }
    
    $cmsType = detectCMSType($instancePath);
    $themePath = getThemePath($instancePath, $cmsType) . '/' . $themeId;
    
    if (!is_dir($themePath)) {
        $response->getBody()->write(json_encode(['error' => 'Theme not found']));
        return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
    }
    
    // Recursively delete theme directory
    $deleteDir = function($dir) use (&$deleteDir) {
        if (!is_dir($dir)) return;
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $deleteDir($path) : unlink($path);
        }
        rmdir($dir);
    };
    
    $deleteDir($themePath);
    
    $response->getBody()->write(json_encode([
        'success' => true,
        'message' => 'Theme deleted'
    ]));
    
    return $response->withHeader('Content-Type', 'application/json');
});
