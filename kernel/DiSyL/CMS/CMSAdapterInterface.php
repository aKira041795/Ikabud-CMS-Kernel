<?php

namespace Ikabud\Kernel\DiSyL\CMS;

interface CMSAdapterInterface
{
    public function escape(string $value): string;

    /**
     * @return iterable<array>
     */
    public function query(string $type, array $options = []): iterable;

    public function getMenu(string $location): array;

    public function getSetting(string $key, mixed $default = null): mixed;

    public function getAssetUrl(string $path): string;

    public function formatDate(mixed $value, ?string $format = null): string;
}
