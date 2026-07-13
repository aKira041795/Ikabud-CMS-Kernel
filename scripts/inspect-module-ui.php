#!/usr/bin/env php
<?php
/**
 * Module UI Inspector — emits a JSON manifest describing a module's renderable UI.
 *
 * Reads the module's routes, handlers, templates (DiSyL), entity view configs,
 * and status/workflow definitions. Emits structured JSON that browser tests
 * and CI tooling can consume to build honest selectors.
 *
 * Usage:
 *   php scripts/inspect-module-ui.php project-audit-ledger [--tenant=N]
 *
 * Output (stdout): JSON manifest
 *   {
 *     "module": "project-audit-ledger",
 *     "sidebar": {...},
 *     "pages": {...},
 *     "entity_lists": {...},
 *     "statuses": {...},
 *     "forms": {...},
 *     "transitions": {...}
 *   }
 */

declare(strict_types=1);

// ── Bootstrap ──────────────────────────────────────────────────
global $config;
$bootstrap = __DIR__ . '/../bootstrap.php';
if (!file_exists($bootstrap)) {
    fwrite(STDERR, "bootstrap.php not found\n");
    exit(1);
}
require $bootstrap;

// ── CLI args ───────────────────────────────────────────────────
$moduleId = $argv[1] ?? null;
if (!$moduleId) {
    fwrite(STDERR, "Usage: php scripts/inspect-module-ui.php <module-id> [--tenant=N]\n");
    exit(1);
}

$tenantId = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--tenant=')) {
        $tenantId = (int) substr($arg, 9);
    }
}

// Resolve tenant for URL base
$baseUrl = 'http://localhost';
if ($tenantId) {
    $tenant = app()->db()->query(
        "SELECT domain FROM kernel_tenants WHERE id = " . (int)$tenantId
    )->fetch(PDO::FETCH_ASSOC);
    if ($tenant) {
        $baseUrl = 'http://' . $tenant['domain'];
    }
}

// ── Module path helpers ────────────────────────────────────────
$moduleDir = __DIR__ . '/../modules/' . $moduleId;
$templateDir = $moduleDir . '/templates/' . $moduleId;
$pagesDir = $templateDir . '/pages';
$viewsDir = $moduleDir . '/helpers/views';
$routesFile = $moduleDir . '/routes.php';
$handlersFile = $moduleDir . '/handlers.php';

// ── Manifest ───────────────────────────────────────────────────
$manifest = [
    'module'       => $moduleId,
    'inspected_at' => date('c'),
    'base_url'     => $baseUrl,
    'sidebar'      => [],
    'pages'        => [],
    'entity_lists' => [],
    'statuses'     => [],
    'forms'        => [],
    'transitions'  => [],
    'gaps'         => [],
];

// ── 1. Extract sidebar from shell builder ──────────────────────
$helpersFile = $moduleDir . '/helpers.php';
if (file_exists($helpersFile)) {
    $helpersSrc = file_get_contents($helpersFile);

    // Match ->addNavSection('Section Name', [ ... ]) blocks
    // Extract section titles and their nav items with label/url
    $sidebarItems = [];
    $offset = 0;

    while (preg_match("/->addNavSection\('([^']+)'\s*,\s*\[/", $helpersSrc, $secMatch, PREG_OFFSET_CAPTURE, $offset)) {
        $sectionLabel = $secMatch[1][0];
        $sectionStart = $secMatch[0][1] + strlen($secMatch[0][0]);
        $sidebarItems[] = ['type' => 'section', 'label' => $sectionLabel];

        // Find the matching closing ]) for this section's items array
        $depth = 1;
        $pos = $sectionStart;
        $len = strlen($helpersSrc);
        while ($depth > 0 && $pos < $len) {
            $ch = $helpersSrc[$pos];
            if ($ch === '[') $depth++;
            elseif ($ch === ']') $depth--;
            $pos++;
        }
        $sectionBody = substr($helpersSrc, $sectionStart, $pos - $sectionStart - 1);

        // Extract items: ['label' => '...', 'url' => '...', ...]
        preg_match_all("/'label'\s*=>\s*'([^']+)'\s*,\s*'url'\s*=>\s*'([^']+)'/", $sectionBody, $itemMatches);
        foreach ($itemMatches[1] as $i => $label) {
            $sidebarItems[] = [
                'type'  => 'item',
                'label' => $label,
                'url'   => $itemMatches[2][$i],
            ];
        }

        $offset = $pos; // $pos is absolute position past the closing ]
    }

    $manifest['sidebar'] = $sidebarItems;
} else {
    $manifest['gaps'][] = 'helpers.php not found — cannot extract sidebar';
}

// ── 2. Extract routes ─────────────────────────────────────────
if (file_exists($routesFile)) {
    $routesSrc = file_get_contents($routesFile);
    // Match GET/POST route => handler patterns
    preg_match_all(
        "#(GET|POST)\s+'([^']+)'\s*=>\s*'([^']+)'#",
        $routesSrc,
        $routeMatches
    );
    $pageRoutes = [];
    foreach ($routeMatches[1] ?? [] as $i => $method) {
        $path = $routeMatches[2][$i];
        $handler = $routeMatches[3][$i];
        // Only admin page routes (not API)
        if (str_starts_with($path, '/admin/')) {
            $pageRoutes[$handler] = [
                'method'  => $method,
                'path'    => $path,
                'handler' => $handler,
            ];
        }
    }
} else {
    $manifest['gaps'][] = 'routes.php not found';
    $pageRoutes = [];
}

// ── 3. Extract page_content → template mappings ────────────────
if (file_exists($helpersFile)) {
    $helpersSrc = file_get_contents($helpersFile);
    // Look for $pageTemplate assignment patterns:
    // $pageContent . '.disyl' or "pages/{$pageContent}.disyl"
    preg_match_all(
        "/case\s+'([^']+)'\s*:\s*\\\$pageContent\s*=\s*'([^']+)'/",
        $helpersSrc,
        $pageContentMatches
    );
    $pageContentMap = [];
    foreach ($pageContentMatches[1] ?? [] as $i => $handler) {
        $pageContentMap[$handler] = $pageContentMatches[2][$i];
    }
    $manifest['page_content_map'] = $pageContentMap;
}

// ── 4. Scan page templates for buttons, forms, entity lists ───
if (is_dir($pagesDir)) {
    foreach (scandir($pagesDir) as $file) {
        if (!str_ends_with($file, '.disyl')) continue;
        $templatePath = $pagesDir . '/' . $file;
        $src = file_get_contents($templatePath);
        $pageKey = basename($file, '.disyl');
        $page = [
            'template'  => 'pages/' . $file,
            'buttons'   => [],
            'forms'     => [],
            'selectors' => [],
        ];

        // ── Buttons: <button ...>Text</button> or <a class="...button..."> ──
        preg_match_all(
            '/<button[^>]*>([^<]+)<\/button>/',
            $src,
            $buttonMatches
        );
        foreach ($buttonMatches[1] ?? [] as $text) {
            $text = trim(strip_tags($text));
            if ($text !== '' && strlen($text) < 60) {
                $page['buttons'][] = $text;
            }
        }

        // ── Action links (buttons styled as links) ──
        preg_match_all(
            '/<a[^>]*class="[^"]*btn[^"]*"[^>]*>([^<]+)<\/a>/',
            $src,
            $linkBtnMatches
        );
        foreach ($linkBtnMatches[1] ?? [] as $text) {
            $text = trim(strip_tags($text));
            if ($text !== '' && strlen($text) < 60 && !in_array($text, $page['buttons'])) {
                $page['buttons'][] = $text;
            }
        }

        // ── Forms: <form ...> with method/action ──
        preg_match_all(
            '/<form[^>]*method="([^"]+)"[^>]*action="([^"]*)"[^>]*>/',
            $src,
            $formMatches
        );
        foreach ($formMatches[1] ?? [] as $i => $method) {
            $fields = [];
            preg_match_all(
                '/<input[^>]*name="([^"]+)"[^>]*type="([^"]*)"[^>]*>/',
                $src,
                $fieldMatches
            );
            foreach ($fieldMatches[1] ?? [] as $j => $name) {
                $fields[] = [
                    'name' => $name,
                    'type' => $fieldMatches[2][$j] ?: 'text',
                ];
            }
            // Also textareas and selects
            preg_match_all('/<textarea[^>]*name="([^"]+)"[^>]*>/', $src, $taMatches);
            foreach ($taMatches[1] ?? [] as $name) {
                $fields[] = ['name' => $name, 'type' => 'textarea'];
            }
            preg_match_all('/<select[^>]*name="([^"]+)"[^>]*>/', $src, $selMatches);
            foreach ($selMatches[1] ?? [] as $name) {
                $fields[] = ['name' => $name, 'type' => 'select'];
            }

            $page['forms'][] = [
                'method' => $method,
                'action' => $formMatches[2][$i] ?? '',
                'fields' => $fields,
            ];
        }

        // ── Entity lists: {ikb_entity_list source="..." view="..."} ──
        preg_match_all(
            '/\{ikb_entity_list[^}]*source="([^"]+)"[^}]*view="([^"]+)"[^}]*\}/',
            $src,
            $elMatches
        );
        foreach ($elMatches[1] ?? [] as $i => $source) {
            $manifest['entity_lists'][] = [
                'source'   => $source,
                'view'     => $elMatches[2][$i] ?? 'table',
                'selector' => 'data-ikb-list="' . $source . '"',
                'page'     => $pageKey,
            ];
        }

        // ── Data attributes ──
        preg_match_all('/data-([a-z-]+)="([^"]+)"/', $src, $dataMatches);
        foreach ($dataMatches[1] ?? [] as $i => $attr) {
            $page['selectors'][] = 'data-' . $attr . '="' . $dataMatches[2][$i] . '"';
        }

        $manifest['pages'][$pageKey] = $page;
    }
}

// ── 5. Extract entity view configs ─────────────────────────────
if (is_dir($viewsDir)) {
    foreach (scandir($viewsDir) as $file) {
        if (!str_ends_with($file, '.disyl')) continue;
        $src = file_get_contents($viewsDir . '/' . $file);
        $entityKey = basename($file, '.disyl');

        // Extract column definitions from {field ...} tags
        preg_match_all(
            '/\{field[^}]*header="([^"]*)"[^}]*\}/',
            $src,
            $colMatches
        );
        preg_match_all(
            '/\{field[^}]*name="([^"]*)"[^}]*\}/',
            $src,
            $colNameMatches
        );

        // Extract action links
        preg_match_all(
            '/<a[^>]*href="([^"]+)"[^>]*>([^<]+)<\/a>/',
            $src,
            $actionMatches
        );

        $columns = [];
        foreach ($colMatches[1] ?? [] as $i => $header) {
            $columns[] = [
                'header' => $header,
                'name'   => $colNameMatches[1][$i] ?? '',
            ];
        }

        $actions = [];
        foreach ($actionMatches[1] ?? [] as $i => $href) {
            $actions[] = [
                'label' => trim($actionMatches[2][$i] ?? ''),
                'href'  => $href,
            ];
        }

        $manifest['entity_views'][$entityKey] = [
            'file'    => 'helpers/views/' . $file,
            'columns' => $columns,
            'actions' => $actions,
        ];
    }
}

// ── 6. Extract statuses and transitions ────────────────────────
$workflowFile = $moduleDir . '/services/JobOrderWorkflow.php';
if (file_exists($workflowFile)) {
    $src = file_get_contents($workflowFile);

    // Status labels: const LABELS = ['draft' => 'Draft', ...]
    preg_match_all("/'([a-z_]+)'\s*=>\s*'([^']+)'/", $src, $labelMatches);
    $labels = [];
    foreach ($labelMatches[1] ?? [] as $i => $key) {
        $labels[$key] = $labelMatches[2][$i];
    }

    // Transitions: const TRANSITIONS = ['draft' => ['pending', 'cancelled'], ...]
    // We need a more targeted extraction — look for the TRANSITIONS block
    if (preg_match('/const\s+TRANSITIONS\s*=\s*\[(.*?)\];/s', $src, $transBlock)) {
        preg_match_all("/'([a-z_]+)'\s*=>\s*\[([^\]]+)\]/", $transBlock[1], $transMatches);
        $transitions = [];
        foreach ($transMatches[1] ?? [] as $i => $from) {
            preg_match_all("/'([a-z_]+)'/", $transMatches[2][$i], $toMatches);
            $transitions[$from] = $toMatches[1] ?? [];
        }
        $manifest['transitions'] = $transitions;
    }

    $manifest['statuses'] = $labels;
} else {
    $manifest['gaps'][] = 'JobOrderWorkflow.php not found — no status/transition data';
}

// ── 7. Cross-reference: which pages have which buttons ─────────
// Match route handlers to page templates via page_content_map
foreach ($pageRoutes as $handler => $route) {
    $pageContent = $pageContentMap[$handler] ?? null;
    if ($pageContent && isset($manifest['pages'][$pageContent])) {
        $manifest['pages'][$pageContent]['route'] = $route;
    }
}

// ── Emit ───────────────────────────────────────────────────────
echo json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
