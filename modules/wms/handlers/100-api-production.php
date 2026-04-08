<?php

declare(strict_types=1);

function wmsApiProductionRecipeCreate(array $params = []): void
{
    wmsResponseGuard(function (): void {
        wmsRequireStaff(['admin', 'supervisor']);
        $data = wmsInput();
        $id = wmsRecipeCreate($data);
        wmsJsonOk(['id' => $id]);
    });
}

function wmsApiProductionOrderCreate(array $params = []): void
{
    wmsResponseGuard(function (): void {
        $user = wmsRequireStaff(['admin', 'supervisor']);
        $data = wmsInput();
        $id = wmsProductionOrderCreate($data, (int)$user['id']);
        wmsJsonOk(['id' => $id]);
    });
}

function wmsApiProductionOrderStart(array $params = []): void
{
    wmsResponseGuard(function () use ($params): void {
        $user = wmsRequireStaff(['admin', 'supervisor', 'viewer']);
        $id = (int)($params['id'] ?? 0);
        wmsProductionOrderStart($id, (int)$user['id']);
        wmsJsonOk(['id' => $id]);
    });
}

function wmsApiProductionOrderComplete(array $params = []): void
{
    wmsResponseGuard(function () use ($params): void {
        $user = wmsRequireStaff(['admin', 'supervisor', 'viewer']);
        $id = (int)($params['id'] ?? 0);
        $payload = wmsInput();
        $movements = wmsProductionOrderComplete($id, $payload, (int)$user['id']);
        wmsJsonOk(['id' => $id, 'movements' => $movements]);
    });
}
