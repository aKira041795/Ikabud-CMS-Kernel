<?php

namespace Ikabud\Kernel\Capabilities;

/**
 * Shared helpers for parsing versioned capability IDs (e.g. "email.send@1").
 */
trait CapabilityIdTrait
{
    private function baseId(string $capabilityId): string
    {
        return (string)preg_replace('/@\d+$/', '', $capabilityId);
    }

    private function majorVersion(string $capabilityId): ?int
    {
        if (!preg_match('/@(\d+)$/', $capabilityId, $matches)) {
            return null;
        }

        return (int)$matches[1];
    }
}
