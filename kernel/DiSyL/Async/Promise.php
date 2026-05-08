<?php

declare(strict_types=1);

namespace Ikabud\Kernel\DiSyL\Async;

/**
 * Minimal Promise A+ subset for DiSyL 4.5 async runtime.
 *
 * Eager-evaluation: a Promise either resolves or rejects synchronously
 * when constructed with an immediate value. Asynchronous resolution is
 * supported through the executor callback (which receives resolve/reject
 * functions) for future Fiber-backed scheduling.
 *
 * Public surface intentionally narrow — only `then`, `catch`, `wait`,
 * static `resolved`, `rejected`. Chaining returns new Promises.
 */
final class Promise
{
    public const PENDING   = 'pending';
    public const FULFILLED = 'fulfilled';
    public const REJECTED  = 'rejected';

    private string $state = self::PENDING;
    private mixed $value = null;
    private ?\Throwable $reason = null;
    /** @var array<int, callable> */
    private array $onFulfilled = [];
    /** @var array<int, callable> */
    private array $onRejected = [];

    public function __construct(?callable $executor = null)
    {
        if ($executor === null) {
            return;
        }
        try {
            $executor(
                fn ($v) => $this->doResolve($v),
                fn (\Throwable $e) => $this->doReject($e),
            );
        } catch (\Throwable $e) {
            $this->doReject($e);
        }
    }

    public static function resolved(mixed $value): self
    {
        $p = new self();
        $p->doResolve($value);
        return $p;
    }

    public static function rejected(\Throwable $reason): self
    {
        $p = new self();
        $p->doReject($reason);
        return $p;
    }

    public function then(?callable $onFulfilled = null, ?callable $onRejected = null): self
    {
        $next = new self();
        $handleFulfilled = function (mixed $v) use ($next, $onFulfilled): void {
            if ($onFulfilled === null) { $next->doResolve($v); return; }
            try { $next->doResolve($onFulfilled($v)); }
            catch (\Throwable $e) { $next->doReject($e); }
        };
        $handleRejected = function (\Throwable $r) use ($next, $onRejected): void {
            if ($onRejected === null) { $next->doReject($r); return; }
            try { $next->doResolve($onRejected($r)); }
            catch (\Throwable $e) { $next->doReject($e); }
        };
        if ($this->state === self::FULFILLED) {
            $handleFulfilled($this->value);
        } elseif ($this->state === self::REJECTED) {
            $handleRejected($this->reason);
        } else {
            $this->onFulfilled[] = $handleFulfilled;
            $this->onRejected[]  = $handleRejected;
        }
        return $next;
    }

    public function catch(callable $onRejected): self
    {
        return $this->then(null, $onRejected);
    }

    /**
     * Block until settled. Returns value or throws reason.
     */
    public function wait(): mixed
    {
        if ($this->state === self::FULFILLED) return $this->value;
        if ($this->state === self::REJECTED)  throw $this->reason;
        throw new \RuntimeException('Promise still pending; no scheduler available to drive it.');
    }

    public function state(): string { return $this->state; }
    public function isFulfilled(): bool { return $this->state === self::FULFILLED; }
    public function isRejected(): bool  { return $this->state === self::REJECTED; }
    public function isPending(): bool   { return $this->state === self::PENDING; }
    public function valueOrNull(): mixed { return $this->state === self::FULFILLED ? $this->value : null; }
    public function reasonOrNull(): ?\Throwable { return $this->state === self::REJECTED ? $this->reason : null; }

    private function doResolve(mixed $v): void
    {
        if ($this->state !== self::PENDING) return;
        if ($v instanceof self) {
            $v->then(
                fn ($x) => $this->doResolve($x),
                fn (\Throwable $e) => $this->doReject($e),
            );
            return;
        }
        $this->state = self::FULFILLED;
        $this->value = $v;
        foreach ($this->onFulfilled as $cb) { $cb($v); }
        $this->onFulfilled = $this->onRejected = [];
    }

    private function doReject(\Throwable $r): void
    {
        if ($this->state !== self::PENDING) return;
        $this->state = self::REJECTED;
        $this->reason = $r;
        foreach ($this->onRejected as $cb) { $cb($r); }
        $this->onFulfilled = $this->onRejected = [];
    }
}
