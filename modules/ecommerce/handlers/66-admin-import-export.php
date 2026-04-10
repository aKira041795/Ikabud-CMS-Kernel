<?php

declare(strict_types=1);

function ecAdminImportExport(): void
{
    $user = ecRequireAdmin();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_verify();
        $action = trim((string)(ecInput()['action'] ?? ''));

        if ($action === 'import_products') {
            $upload = ecImportReadUploadedCsv('products_csv');
            if (empty($upload['ok'])) {
                $_SESSION['ec_message'] = ['type' => 'error', 'text' => (string)($upload['error'] ?? 'CSV import failed.')];
            } else {
                try {
                    $result = ecImportProductsFromCsv((string)$upload['raw'], (int)($user['id'] ?? 0));
                    $_SESSION['ec_import_export_result'] = $result;
                    $_SESSION['ec_message'] = [
                        'type' => $result['errors'] === [] ? 'success' : 'error',
                        'text' => 'Product CSV import finished: '
                            . (int)($result['created'] ?? 0) . ' created, '
                            . (int)($result['updated'] ?? 0) . ' updated, '
                            . count((array)($result['errors'] ?? [])) . ' errors.',
                    ];
                } catch (Throwable $e) {
                    $_SESSION['ec_message'] = ['type' => 'error', 'text' => 'Product CSV import failed: ' . $e->getMessage()];
                }
            }
        } else {
            $_SESSION['ec_message'] = ['type' => 'error', 'text' => 'Unsupported import action.'];
        }

        header('Location: ' . ecGetBaseUrl() . '/ecommerce/admin/import-export');
        exit;
    }

    $ctx = ecAdminContext($user, 'import_export', [
        'export_resources' => ecCsvExportResources(),
        'product_import_headers' => ecCsvProductHeaders(),
        'import_result' => $_SESSION['ec_import_export_result'] ?? null,
        'message' => $_SESSION['ec_message'] ?? null,
        'page_title' => 'Ecommerce — Import / Export',
    ]);
    unset($_SESSION['ec_message'], $_SESSION['ec_import_export_result']);

    ecRender('modules/ecommerce/admin/import-export.disyl', $ctx);

    if (function_exists('releaseSessionAfterRender')) {
        releaseSessionAfterRender();
    }
}

function ecAdminExportCsv(array $params = []): void
{
    ecRequireAdmin();

    $resource = trim((string)($params['resource'] ?? ''));
    $definition = ecCsvExportDefinition($resource);
    if ($definition === null) {
        http_response_code(404);
        ecRender('modules/ecommerce/admin/404.disyl', ['message' => 'CSV export not found.']);
        return;
    }

    ecCsvResponse(
        (string)$definition['filename'],
        (array)$definition['headers'],
        (array)$definition['rows']
    );
}