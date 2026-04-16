<?php

namespace Ikabud\Kernel\DiSyL\CMS;

class NullAdapter implements CMSAdapterInterface
{
    public function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    public function query(string $type, array $options = []): iterable
    {
        return [];
    }

    public function getMenu(string $location): array
    {
        return [];
    }

    public function getSetting(string $key, mixed $default = null): mixed
    {
        return $default;
    }

    public function getAssetUrl(string $path): string
    {
        return $path;
    }

    public function formatDate(mixed $value, ?string $format = null): string
    {
        $format = $format ?? 'Y-m-d';
        if (is_numeric($value)) {
            return date($format, (int)$value);
        }
        $ts = strtotime((string)$value);
        return $ts !== false ? date($format, $ts) : '';
    }
}
