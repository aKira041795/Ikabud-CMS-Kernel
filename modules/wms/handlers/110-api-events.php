<?php

declare(strict_types=1);

function wmsApiEventWebhookRegistration(array $params = []): void
{
    // This is a placeholder for external webhook registration
    // Kernel OS uses the wmsCtx()->fireEvent() which other modules can listen to.
    wmsResponseGuard(function (): void {
        wmsRequireAnyRole('admin');
        wmsJsonOk(['message' => 'Webhooks are natively handled via Kernel OS Event Bus']);
    });
}
