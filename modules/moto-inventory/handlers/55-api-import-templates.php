<?php

declare(strict_types=1);

/**
 * Moto Inventory — Import mapping template API.
 *
 * Lists bundled presets + tenant custom templates, saves custom templates
 * (create/update), and deletes them. Templates only describe how to map a
 * supplier pricelist; they never touch inventory.
 */

function motoApiImportTemplates(array $params = []): void
{
    moto_api_guard(static function (): void {
        $ctx = moto_ctx();
        moto_require_permission($ctx, 'moto_inventory.manage');
        moto_json_ok(ImportTemplateService::all($ctx));
    });
}

function motoApiImportTemplateSave(array $params = []): void
{
    moto_api_guard(static function () use ($params): void {
        $ctx = moto_ctx();
        moto_require_permission($ctx, 'moto_inventory.manage');
        $data = moto_input();
        $id = (int)($params['id'] ?? 0);
        if ($id > 0) {
            $data['id'] = $id;
        }
        $saved = ImportTemplateService::saveCustom($ctx, $data);
        moto_json_ok($saved, 201);
    });
}

function motoApiImportTemplateDelete(array $params = []): void
{
    moto_api_guard(static function () use ($params): void {
        $ctx = moto_ctx();
        moto_require_permission($ctx, 'moto_inventory.manage');
        $id = (int)($params['id'] ?? 0);
        if ($id <= 0) {
            moto_json_error('Template id is required', 422);
            return;
        }
        ImportTemplateService::deleteCustom($ctx, $id);
        moto_json_ok(['id' => $id]);
    });
}
