<?php

declare(strict_types=1);

namespace Harpp\Services;

use ArrayAccess;
use JsonSerializable;

/** Structured service result with array compatibility for the stable JSON API. */
final class HarppServiceResult implements ArrayAccess, JsonSerializable
{
    public readonly bool $ok;
    public readonly mixed $data;
    /** @var array<int, array{message:string,code:string}> */
    public readonly array $errors;
    /** @var array<int, array{name:string,payload:array}> */
    public readonly array $events;
    public readonly ?string $entityType;
    public readonly int|string|null $entityId;
    private array $wire;

    private function __construct(bool $ok, mixed $data, array $errors, array $events, ?string $entityType, int|string|null $entityId, array $meta)
    {
        $this->ok = $ok;
        $this->data = $data;
        $this->errors = $errors;
        $this->events = $events;
        $this->entityType = $entityType;
        $this->entityId = $entityId;
        $this->wire = ['ok' => $ok, 'data' => $data, 'errors' => $errors, 'events' => $events, 'entity' => ['type' => $entityType, 'id' => $entityId]] + $meta;
    }

    public static function success(array $data = [], string $message = '', array $events = [], ?string $entityType = null, int|string|null $entityId = null): self
    {
        return new self(true, $data, [], $events, $entityType, $entityId, $message !== '' ? ['message' => $message] : []);
    }

    public static function failure(string $error, int $status = 422, string $code = ''): self
    {
        $item = ['message' => $error, 'code' => $code];
        return new self(false, [], [$item], [], null, null, ['error' => $error, 'status' => $status] + ($code !== '' ? ['code' => $code] : []));
    }

    public function toArray(): array { return $this->wire; }
    public function jsonSerialize(): array { return $this->wire; }
    public function offsetExists(mixed $offset): bool { return isset($this->wire[$offset]); }
    public function &offsetGet(mixed $offset): mixed { return $this->wire[$offset]; }
    public function offsetSet(mixed $offset, mixed $value): void { if ($offset !== null) $this->wire[$offset] = $value; }
    public function offsetUnset(mixed $offset): void { unset($this->wire[$offset]); }
}
