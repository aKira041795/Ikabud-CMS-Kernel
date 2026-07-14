<?php

declare(strict_types=1);

/**
 * ARK Process Comprehension Engine — discovers a module's forms, buttons,
 * API endpoints, and workflow transitions by scanning its source files.
 *
 * This is the module-level equivalent of static analysis for runtime behavior.
 * It answers:
 *   - Where does the process start?
 *   - What fields does each form have?
 *   - What buttons exist, and what API calls do they trigger?
 *   - What workflow transitions are possible?
 *   - Where do forms submit to?
 *
 * Usage: php tests/ai/comprehend-process.php <module-id>
 *   php tests/ai/comprehend-process.php project-audit-ledger
 *
 * Output: test_results/ai/process-map.json
 */

if (php_sapi_name() !== 'cli') {
    echo "CLI only.\n"; exit(1);
}

$moduleId = $argv[1] ?? '';
if ($moduleId === '') {
    fwrite(STDERR, "Usage: php tests/ai/comprehend-process.php <module-id>\n");
    exit(1);
}

$base = dirname(__DIR__, 2);
$modDir = $base . '/modules/' . $moduleId;
$tplDir = $modDir . '/templates/' . $moduleId;
$handlersDir = $modDir . '/handlers';
$routesFile = $modDir . '/routes.php';
$svcDir = $modDir . '/services';
$contractFile = $modDir . '/test-contract.json';

$processMap = [
    'module' => $moduleId,
    'generated' => date('c'),
    'entry_points' => [],
    'forms' => [],
    'api_endpoints' => [],
    'workflows' => [],
    'button_actions' => [],
    'capabilities' => [],
    'gaps' => [],
];

// ── 1. Discover routes ────────────────────────────────────────
echo "── Routes ──\n";
if (is_file($routesFile)) {
    $routes = null;
    // Try to load via PHP bootstrap for accurate route resolution
    try {
        $_SERVER['HTTP_HOST'] = $moduleId === 'project-audit-ledger' ? 'palsystem.test' : $moduleId . '.test';
        $_SERVER['REQUEST_URI'] = '/';
        require_once $base . '/bootstrap.php';
        require_once $base . '/src/helpers/module-manager.php';
        $allRoutes = kernelCoreRoutes();
        $allRoutes = loadModuleRoutes($allRoutes);
        foreach (['GET', 'POST', 'PUT', 'DELETE'] as $method) {
            foreach (($allRoutes[$method] ?? []) as $path => $handler) {
                if (str_contains($path, $moduleId) && str_contains($handler, $moduleId . ':')) {
                    [, $func] = explode(':', $handler, 2);
                    $processMap['api_endpoints'][] = [
                        'method' => $method,
                        'path' => $path,
                        'handler' => $func,
                        'is_auth_route' => str_contains($path, '/auth/') || str_contains($path, '/login') || str_contains($path, '/logout') || str_contains($path, 'forgot') || str_contains($path, 'reset'),
                        'is_api' => str_contains($path, '/api/'),
                        'requires_id' => (bool) preg_match('/\{id\}/', $path),
                    ];
                }
            }
        }
        echo "  " . count($processMap['api_endpoints']) . " routes found\n";
    } catch (\Throwable $e) {
        echo "  ⚠ Route loading failed: " . $e->getMessage() . "\n";
        // Fallback: parse routes.php directly
        $content = (string) file_get_contents($routesFile);
        preg_match_all("/'([^']+)'\s*=>\s*'[^:]+:([^']+)'/", $content, $matches, PREG_SET_ORDER);
        foreach ($matches as $m) {
            $processMap['api_endpoints'][] = [
                'method' => 'UNKNOWN',
                'path' => $m[1],
                'handler' => $m[2],
                'is_api' => str_contains($m[1], '/api/'),
                'requires_id' => (bool) preg_match('/\{id\}/', $m[1]),
            ];
        }
        echo "  " . count($processMap['api_endpoints']) . " routes (fallback parse)\n";
    }
}

// Build lookup: handler function → endpoint info
$handlerToEndpoint = [];
foreach ($processMap['api_endpoints'] as $ep) {
    $handlerToEndpoint[$ep['handler']] = $ep;
}

// ── 2. Scan templates for forms, buttons, actions ─────────────
echo "── Templates ──\n";
$templateFiles = [];
if (is_dir($tplDir)) {
    $iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tplDir));
    foreach ($iter as $f) {
        if ($f->isFile() && str_ends_with($f->getFilename(), '.disyl')) {
            $templateFiles[] = $f->getPathname();
        }
    }
}

foreach ($templateFiles as $tf) {
    $relPath = str_replace($base . '/', '', $tf);
    $content = (string) file_get_contents($tf);
    $pageType = 'unknown';
    if (str_contains($relPath, '/pages/')) $pageType = 'page';
    elseif (str_contains($relPath, '/components/')) $pageType = 'component';
    elseif (str_contains($relPath, '/layouts/')) $pageType = 'layout';

    $templateInfo = [
        'file' => $relPath,
        'type' => $pageType,
        'forms' => [],
        'buttons' => [],
        'actions' => [],
        'includes' => [],
    ];

    // Extract forms
    preg_match_all('/<form\s[^>]*action=["\']([^"\']+)["\'][^>]*method=["\']([^"\']+)["\']/i', $content, $formMatches, PREG_SET_ORDER);
    foreach ($formMatches as $fm) {
        $formAction = $fm[1];
        $formMethod = strtoupper($fm[2]);
        $templateInfo['forms'][] = [
            'action' => $formAction,
            'method' => $formMethod,
            'resolved_handler' => null,
        ];
        // Try to resolve form action to a known endpoint
        foreach ($processMap['api_endpoints'] as $ep) {
            if ($ep['path'] === $formAction && $ep['method'] === $formMethod) {
                $templateInfo['forms'][count($templateInfo['forms']) - 1]['resolved_handler'] = $ep['handler'];
                $templateInfo['forms'][count($templateInfo['forms']) - 1]['resolved_path'] = $ep['path'];
                break;
            }
        }
    }

    // Extract buttons with data-wb-action
    preg_match_all('/data-wb-action=["\']([^"\']+)["\']/i', $content, $actionMatches);
    foreach ($actionMatches[1] as $action) {
        $templateInfo['actions'][] = [
            'type' => 'data-wb-action',
            'value' => $action,
        ];
    }

    // Extract buttons with onclick (direct JS calls)
    preg_match_all('/onclick=["\']([^"\']+?)["\']/i', $content, $onclickMatches);
    foreach ($onclickMatches[1] as $oc) {
        $templateInfo['actions'][] = [
            'type' => 'onclick',
            'value' => substr($oc, 0, 120),
        ];
    }

    // Extract buttons by text/label patterns
    preg_match_all('/<button[^>]*>([^<]+)<\/button>/i', $content, $btnMatches);
    foreach ($btnMatches[1] as $btn) {
        $text = trim(strip_tags($btn));
        if ($text !== '') {
            $templateInfo['buttons'][] = ['text' => $text];
        }
    }

    // Extract includes (template composition)
    preg_match_all('/\{include\s+"([^"]+)"/', $content, $incMatches);
    foreach ($incMatches[1] as $inc) {
        $templateInfo['includes'][] = $inc;
    }

    // Extract form fields (inputs, selects, textareas)
    preg_match_all('/<input[^>]*name=["\']([^"\']+)["\']/i', $content, $inputMatches);
    preg_match_all('/<select[^>]*name=["\']([^"\']+)["\']/i', $content, $selectMatches);
    preg_match_all('/<textarea[^>]*name=["\']([^"\']+)["\']/i', $content, $textareaMatches);
    $allFields = array_merge($inputMatches[1], $selectMatches[1], $textareaMatches[1]);

    // Detect required fields
    $requiredFields = [];
    foreach ($inputMatches[0] as $i => $inputHtml) {
        if (str_contains($inputHtml, 'required')) {
            $requiredFields[] = $inputMatches[1][$i];
        }
    }

    $templateInfo['fields'] = [
        'all' => array_unique($allFields),
        'required' => array_unique($requiredFields),
    ];

    if (count($templateInfo['forms']) > 0 || count($templateInfo['actions']) > 0) {
        $processMap['forms'][] = $templateInfo;
    }

    echo "  {$relPath}: " . count($templateInfo['forms']) . " form(s), " . count($templateInfo['actions']) . " action(s), " . count($templateInfo['fields']['all']) . " field(s)\n";
}

// ── 3. Discover entry points ──────────────────────────────────
echo "── Entry Points ──\n";
// Login routes are entry points
foreach ($processMap['api_endpoints'] as $ep) {
    if (str_contains($ep['path'], '/login') && !$ep['is_api']) {
        $processMap['entry_points'][] = [
            'type' => 'login',
            'path' => $ep['path'],
        ];
    }
}
// The dashboard/main page is an entry point
foreach ($processMap['api_endpoints'] as $ep) {
    if ($ep['path'] === '/admin/' . $moduleId && !$ep['is_api']) {
        $processMap['entry_points'][] = [
            'type' => 'dashboard',
            'path' => $ep['path'],
        ];
    }
}
// Create forms are entry points
foreach ($processMap['api_endpoints'] as $ep) {
    if (str_contains($ep['path'], '/create') && !$ep['is_api']) {
        $processMap['entry_points'][] = [
            'type' => 'create-form',
            'path' => $ep['path'],
        ];
    }
}
echo "  " . count($processMap['entry_points']) . " entry point(s)\n";

// ── 4. Scan service files for workflow transitions ────────────
echo "── Workflows ──\n";
if (is_dir($svcDir)) {
    $svcFiles = glob($svcDir . '/*.php');
    foreach ($svcFiles as $sf) {
        $content = (string) file_get_contents($sf);
        $relPath = str_replace($base . '/', '', $sf);

        // Look for workflow transition arrays/maps
        $workflow = [
            'file' => $relPath,
            'transitions' => [],
        ];

        // Detect transition methods
        preg_match_all('/function\s+(isAllowed|transition|apply|canTransition)\b[^;]+/', $content, $methodMatches);
        $workflow['methods'] = [];
        foreach ($methodMatches[0] as $mm) {
            $sig = substr(trim($mm), 0, 100);
            $workflow['methods'][] = $sig;
        }

        // Detect status constants or arrays
        preg_match_all("/['\"](\w+)['\"]\s*=>\s*['\"](\w+)['\"]/", $content, $transMatches, PREG_SET_ORDER);
        foreach ($transMatches as $tm) {
            // Skip non-workflow key=>value pairs
            if (in_array($tm[1], ['draft', 'pending', 'approved', 'started', 'ongoing', 'completed', 'cancelled', 'closed', 'pending_approval', 'rejected', 'paid'])
                || in_array($tm[2], ['draft', 'pending', 'approved', 'started', 'ongoing', 'completed', 'cancelled', 'closed', 'pending_approval', 'rejected', 'paid'])) {
                $workflow['transitions'][] = ['from' => $tm[1], 'to' => $tm[2]];
            }
        }

        if (!empty($workflow['methods']) || !empty($workflow['transitions'])) {
            $processMap['workflows'][] = $workflow;
            echo "  {$relPath}: " . count($workflow['transitions']) . " transition(s), " . count($workflow['methods']) . " method(s)\n";
        }
    }
}

// ── 5. Map buttons to API handlers ────────────────────────────
echo "── Button→API Mapping ──\n";
foreach ($processMap['forms'] as $form) {
    foreach ($form['forms'] as $f) {
        if ($f['resolved_handler']) {
            $handlerFile = null;
            // Find the handler file
            foreach (glob($handlersDir . '/*.php') as $hf) {
                $hContent = (string) file_get_contents($hf);
                if (str_contains($hContent, 'function ' . $f['resolved_handler'])) {
                    $handlerFile = str_replace($base . '/', '', $hf);
                    break;
                }
            }
            $processMap['button_actions'][] = [
                'source_template' => $form['file'],
                'form_action' => $f['action'],
                'form_method' => $f['method'],
                'handler' => $f['resolved_handler'],
                'handler_file' => $handlerFile,
            ];
        }
    }
}
echo "  " . count($processMap['button_actions']) . " button→API mapping(s)\n";

// ── 6. Compare against test contract ──────────────────────────
echo "── Contract Gaps ──\n";
if (is_file($contractFile)) {
    $contract = json_decode((string) file_get_contents($contractFile), true);
    $tc = $contract['test_contract'] ?? [];

    $claimedRoutes = array_merge(
        $tc['routes_claimed']['GET'] ?? [],
        $tc['routes_claimed']['POST'] ?? []
    );
    $actualRoutes = array_map(fn($ep) => $ep['path'], $processMap['api_endpoints']);

    foreach ($claimedRoutes as $cr) {
        // Normalize for comparison
        $crNorm = preg_replace('/\{[^}]+\}/', '{param}', $cr);
        $found = false;
        foreach ($actualRoutes as $ar) {
            $arNorm = preg_replace('/\{[^}]+\}/', '{param}', $ar);
            if ($crNorm === $arNorm) {
                $found = true;
                break;
            }
        }
        if (!$found) {
            $processMap['gaps'][] = [
                'type' => 'missing-route',
                'claim' => $cr,
                'detail' => 'Claimed in test-contract.json but no matching route in routes.php',
            ];
        }
    }
}
echo "  " . count($processMap['gaps']) . " gap(s)\n";

// ── Output ────────────────────────────────────────────────────
$outDir = $base . '/test_results/ai';
if (!is_dir($outDir)) {
    mkdir($outDir, 0777, true);
}

file_put_contents(
    $outDir . '/process-map.json',
    json_encode($processMap, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
);

echo "\n═══ Process Map Generated ═══\n";
echo "  Routes: " . count($processMap['api_endpoints']) . "\n";
echo "  Forms/Templates: " . count($processMap['forms']) . "\n";
echo "  Workflows: " . count($processMap['workflows']) . "\n";
echo "  Button→API: " . count($processMap['button_actions']) . "\n";
echo "  Gaps: " . count($processMap['gaps']) . "\n";
echo "  Output: test_results/ai/process-map.json\n";
