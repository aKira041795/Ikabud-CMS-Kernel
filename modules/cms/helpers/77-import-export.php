<?php

declare(strict_types=1);

if (!function_exists('cmsHasEnabledWordpressImporter')) {
    function cmsHasEnabledWordpressImporter(): bool
    {
        return cmsCanUseWordpressImporter();
    }
}

if (!function_exists('cmsWordpressImporterModulePath')) {
    function cmsWordpressImporterModulePath(): string
    {
        $base = rtrim((string)(defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 3)), '/');
        // Prefer installed module path; fall back to canonical package source (post-3.2 dedup).
        $installed = $base . '/modules/wordpress-importer';
        if (is_dir($installed)) {
            return $installed;
        }
        return $base . '/packages/cms-wordpress-importer';
    }
}

if (!function_exists('cmsWordpressImporterRouteEnabled')) {
    function cmsWordpressImporterRouteEnabled(): bool
    {
        $modules = getEnabledModules();
        return isset($modules['wordpress-importer']) && function_exists('wordpressImporterAdminPage');
    }
}

if (!function_exists('cmsCanUseWordpressImporter')) {
    function cmsCanUseWordpressImporter(): bool
    {
        if (function_exists('wordpressImporterApiImport')) {
            return true;
        }

        $modulePath = cmsWordpressImporterModulePath();
        if (!is_file($modulePath . '/module.json')) {
            return false;
        }

        $registeredForCms = function_exists('_cmsIsRegisteredSubModule')
            ? _cmsIsRegisteredSubModule('wordpress-importer')
            : false;
        $cmsOwnedOnDisk = is_file($modulePath . '/.cms-owned');

        if (!$registeredForCms && !$cmsOwnedOnDisk) {
            return false;
        }

        $handlersPath = $modulePath . '/handlers.php';
        if (!is_file($handlersPath)) {
            return false;
        }

        require_once $handlersPath;
        return function_exists('wordpressImporterApiImport');
    }
}

if (!function_exists('cmsLooksLikeXmlImport')) {
    function cmsLooksLikeXmlImport(string $raw): bool
    {
        $trimmed = ltrim($raw);
        if ($trimmed === '' || $trimmed[0] !== '<') {
            return false;
        }

        return str_contains($trimmed, '<rss')
            || str_contains($trimmed, '<channel')
            || str_contains($trimmed, '<wp:wxr_version')
            || str_contains($trimmed, '<?xml');
    }
}

if (!function_exists('cmsImportReadUploadedFile')) {
    function cmsImportReadUploadedFile(string $field, int $maxBytes = 10485760): array
    {
        $file = kernelUploadedFile($field);
        if (!is_array($file) || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'status' => 422, 'error' => 'No valid file uploaded'];
        }

        $tmpPath = (string)($file['tmp_name'] ?? '');
        if ($tmpPath === '' || !is_file($tmpPath)) {
            return ['ok' => false, 'status' => 422, 'error' => 'Uploaded file is not available'];
        }

        if (PHP_SAPI !== 'cli' && function_exists('is_uploaded_file') && !is_uploaded_file($tmpPath)) {
            return ['ok' => false, 'status' => 422, 'error' => 'Upload did not arrive through the HTTP upload pipeline'];
        }

        $size = (int)($file['size'] ?? 0);
        if ($size <= 0) {
            return ['ok' => false, 'status' => 422, 'error' => 'Uploaded file is empty'];
        }

        if ($size > $maxBytes) {
            return ['ok' => false, 'status' => 422, 'error' => 'Uploaded file exceeds the maximum allowed size'];
        }

        $raw = @file_get_contents($tmpPath);
        if (!is_string($raw) || trim($raw) === '') {
            return ['ok' => false, 'status' => 422, 'error' => 'Uploaded file is empty'];
        }

        return ['ok' => true, 'file' => $file, 'raw' => $raw];
    }
}