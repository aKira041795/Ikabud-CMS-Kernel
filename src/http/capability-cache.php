<?php

declare(strict_types=1);

if (!function_exists('capability_cache_path')) {
    function capability_cache_path(string $filename): string
    {
        return rtrim(defined('STORAGE_PATH') ? STORAGE_PATH : __DIR__, '/') . '/cache/' . ltrim($filename, '/');
    }
}

if (!function_exists('load_capability_cache')) {
    function load_capability_cache(string $filename): array
    {
        $path = capability_cache_path($filename);
        if (!is_file($path)) {
            return [];
        }

        $raw = @file_get_contents($path);
        $decoded = $raw ? json_decode($raw, true) : null;

        return is_array($decoded) ? $decoded : [];
    }
}

if (!function_exists('save_capability_cache')) {
    function save_capability_cache(string $filename, array $data): void
    {
        $path = capability_cache_path($filename);
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        @file_put_contents($path, json_encode($data), LOCK_EX);
    }
}