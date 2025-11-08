<?php
/**
 * DSL Compiler/Executor API Routes
 */

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use IkabudKernel\Core\Kernel;

// Compile DSL query
$app->post('/api/v1/dsl/compile', function (Request $request, Response $response) {
    $body = json_decode($request->getBody()->getContents(), true);
    
    if (!isset($body['query'])) {
        $response->getBody()->write(json_encode(['error' => 'query is required']));
        return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
    }
    
    $query = $body['query'];
    $context = $body['context'] ?? [];
    
    try {
        $compiler = new \IkabudKernel\DSL\QueryCompiler();
        $ast = $compiler->compile($query, $context);
        
        $response->getBody()->write(json_encode([
            'success' => true,
            'ast' => $ast,
            'compilation_time_ms' => $ast['metadata']['compilation_time_ms']
        ]));
        
        return $response->withHeader('Content-Type', 'application/json');
        
    } catch (Exception $e) {
        $response->getBody()->write(json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]));
        return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
    }
});

// Execute DSL query
$app->post('/api/v1/dsl/execute', function (Request $request, Response $response) {
    $body = json_decode($request->getBody()->getContents(), true);
    
    if (!isset($body['query'])) {
        $response->getBody()->write(json_encode(['error' => 'query is required']));
        return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
    }
    
    $query = $body['query'];
    $context = $body['context'] ?? [];
    
    try {
        // Compile query
        $compiler = new \IkabudKernel\DSL\QueryCompiler();
        $ast = $compiler->compile($query, $context);
        
        // Execute query
        $executor = new \IkabudKernel\DSL\QueryExecutor();
        $result = $executor->execute($ast);
        
        if (!$result['success']) {
            throw new Exception($result['error']);
        }
        
        // Render result
        $renderer = new \IkabudKernel\DSL\FormatRenderer();
        $layoutEngine = new \IkabudKernel\DSL\LayoutEngine();
        
        $format = $ast['attributes']['format'] ?? 'card';
        $layout = $ast['attributes']['layout'] ?? 'vertical';
        
        $html = $renderer->render($result['data'], $format);
        $html = $layoutEngine->wrap($html, $layout, $ast['attributes']);
        
        $response->getBody()->write(json_encode([
            'success' => true,
            'html' => $html,
            'data' => $result['data'],
            'execution_time_ms' => $result['execution_time_ms'],
            'cached' => $result['cached']
        ]));
        
        return $response->withHeader('Content-Type', 'application/json');
        
    } catch (Exception $e) {
        $response->getBody()->write(json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]));
        return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
    }
});

// Preview DSL query
$app->post('/api/v1/dsl/preview', function (Request $request, Response $response) {
    $body = json_decode($request->getBody()->getContents(), true);
    
    if (!isset($body['query'])) {
        $response->getBody()->write(json_encode(['error' => 'query is required']));
        return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
    }
    
    $query = $body['query'];
    
    // TODO: Implement DSL preview
    $html = '<div class="preview">' . htmlspecialchars($query) . '</div>';
    
    $response->getBody()->write(json_encode([
        'success' => true,
        'html' => $html
    ]));
    
    return $response->withHeader('Content-Type', 'application/json');
});

// Get DSL grammar
$app->get('/api/v1/dsl/grammar', function (Request $request, Response $response) {
    $grammar = [
        'version' => '1.1',
        'parameters' => [
            'type' => ['required' => true, 'type' => 'string', 'values' => ['post', 'page', 'product', 'category']],
            'limit' => ['required' => false, 'type' => 'integer', 'default' => 10],
            'format' => ['required' => false, 'type' => 'string', 'values' => ['card', 'list', 'grid', 'hero']],
            'layout' => ['required' => false, 'type' => 'string', 'values' => ['vertical', 'grid-2', 'grid-3', 'grid-4']],
        ],
        'placeholders' => ['GET', 'POST', 'ENV', 'SESSION', 'COOKIE'],
        'conditionals' => ['if', 'unless'],
        'operators' => ['AND', 'OR']
    ];
    
    $response->getBody()->write(json_encode($grammar));
    return $response->withHeader('Content-Type', 'application/json');
});

// Get DSL snippets
$app->get('/api/v1/dsl/snippets', function (Request $request, Response $response) {
    $kernel = Kernel::getInstance();
    $db = $kernel->getDatabase();
    
    $category = $request->getQueryParams()['category'] ?? null;
    
    if ($category) {
        $stmt = $db->prepare("SELECT * FROM dsl_snippets WHERE category = ? ORDER BY snippet_name");
        $stmt->execute([$category]);
    } else {
        $stmt = $db->query("SELECT * FROM dsl_snippets ORDER BY category, snippet_name");
    }
    
    $snippets = $stmt->fetchAll();
    
    foreach ($snippets as &$snippet) {
        $snippet['tags'] = json_decode($snippet['tags'] ?? '[]', true);
    }
    
    $response->getBody()->write(json_encode([
        'total' => count($snippets),
        'snippets' => $snippets
    ]));
    
    return $response->withHeader('Content-Type', 'application/json');
});

// Create DSL snippet
$app->post('/api/v1/dsl/snippets', function (Request $request, Response $response) {
    $kernel = Kernel::getInstance();
    $db = $kernel->getDatabase();
    
    $body = json_decode($request->getBody()->getContents(), true);
    
    if (!isset($body['snippet_name']) || !isset($body['snippet_code'])) {
        $response->getBody()->write(json_encode(['error' => 'snippet_name and snippet_code are required']));
        return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
    }
    
    $stmt = $db->prepare("
        INSERT INTO dsl_snippets 
        (snippet_name, snippet_code, description, category, tags, created_by)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    
    $tags = json_encode($body['tags'] ?? []);
    
    $stmt->execute([
        $body['snippet_name'],
        $body['snippet_code'],
        $body['description'] ?? '',
        $body['category'] ?? 'custom',
        $tags,
        $body['created_by'] ?? null
    ]);
    
    $response->getBody()->write(json_encode([
        'success' => true,
        'snippet_id' => $db->lastInsertId(),
        'message' => 'Snippet created successfully'
    ]));
    
    return $response->withStatus(201)->withHeader('Content-Type', 'application/json');
});

// Validate DSL syntax
$app->post('/api/v1/dsl/validate', function (Request $request, Response $response) {
    $body = json_decode($request->getBody()->getContents(), true);
    
    if (!isset($body['query'])) {
        $response->getBody()->write(json_encode(['error' => 'query is required']));
        return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
    }
    
    $query = $body['query'];
    
    // TODO: Implement DSL validation
    $valid = true;
    $errors = [];
    
    $response->getBody()->write(json_encode([
        'valid' => $valid,
        'errors' => $errors
    ]));
    
    return $response->withHeader('Content-Type', 'application/json');
});
