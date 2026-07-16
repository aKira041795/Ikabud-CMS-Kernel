<?php

declare(strict_types=1);

namespace Ikabud\Kernel\Workbench\Contracts;

final class WorkbenchTestContract
{
    public const SCHEMA = 'ark.workbench-test-contract.v1';
    public const VERSION = '1.0.0';
    public const FILE = 'workbench-contract.json';

    /** @return array<string,mixed> */
    public static function read(string $path): array
    {
        if (!is_file($path)) {
            throw new \RuntimeException("Contract not found: {$path}");
        }
        $data = json_decode((string) file_get_contents($path), true);
        if (!is_array($data)) {
            throw new \RuntimeException("Invalid contract JSON: {$path}");
        }
        return $data;
    }

    /** @param array<string,mixed> $contract */
    public static function encode(array $contract): string
    {
        return json_encode($contract, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    }
}
