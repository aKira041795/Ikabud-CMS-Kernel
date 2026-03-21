<?php

declare(strict_types=1);

function cmsApiSavedBlockList(array $params = []): void
{
    cmsRequireCap('saved_blocks.list');
    $cat = isset($_GET['category']) ? trim($_GET['category']) : null;
    app()->json(['ok' => true, 'blocks' => cmsGetSavedBlocks($cat ?: null)]);
}

function cmsApiSavedBlockCreate(array $params = []): void
{
    $user = cmsRequireCap('saved_blocks.create');
    $input = cmsInput();
    $input['created_by'] = (int)($user['id'] ?? 0);
    app()->json(cmsSavedBlockCreate($input));
}

function cmsApiSavedBlockUpdate(array $params = []): void
{
    cmsRequireCap('saved_blocks.edit');
    $id = (int)($params['id'] ?? 0);
    if ($id <= 0) { app()->json(['ok' => false, 'error' => 'Invalid ID']); return; }
    $input = cmsInput();
    if (!empty($input['usage_increment'])) {
        cmsSavedBlockIncrementUsage($id);
        app()->json(['ok' => true]);
        return;
    }
    app()->json(cmsSavedBlockUpdate($id, $input));
}

function cmsApiSavedBlockDelete(array $params = []): void
{
    cmsRequireCap('saved_blocks.delete');
    $id = (int)($params['id'] ?? 0);
    if ($id <= 0) { app()->json(['ok' => false, 'error' => 'Invalid ID']); return; }
    app()->json(cmsSavedBlockDelete($id));
}
