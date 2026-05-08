<?php

declare(strict_types=1);

namespace Ikabud\Kernel\DiSyL\Security;

/**
 * DiSyL 4.4 — Capability set.
 *
 * Immutable bag of capability tags. Operations always *narrow* — there is no
 * way to widen a capability set from inside a template. Composition is by
 * intersection: a child sandbox can only have a subset of the parent's
 * permissions.
 *
 * Capability tags (4.4 initial set):
 *   raw.html, db.read, db.write, network, filesystem, ai, experiment,
 *   cache.invalidate, script
 */
final class CapabilitySet
{
    public const ALL_TAGS = [
        'raw.html', 'db.read', 'db.write', 'network', 'filesystem',
        'ai', 'experiment', 'cache.invalidate', 'script', 'federation',
    ];

    /** Strict policy revokes everything. */
    public const STRICT_DENIES = self::ALL_TAGS;

    /** @var array<string,true> */
    private array $allowed;

    /** @param iterable<string> $allowed */
    public function __construct(iterable $allowed)
    {
        $map = [];
        foreach ($allowed as $tag) {
            if (in_array($tag, self::ALL_TAGS, true)) $map[$tag] = true;
        }
        $this->allowed = $map;
    }

    public static function full(): self
    {
        return new self(self::ALL_TAGS);
    }

    public static function strict(): self
    {
        return new self([]);
    }

    public function allows(string $tag): bool
    {
        return isset($this->allowed[$tag]);
    }

    /**
     * Narrow this set by removing $deny and intersecting with $allow (when
     * non-empty). $allow without intersection means "limit to listed".
     *
     * @param list<string> $deny
     * @param list<string> $allow
     */
    public function narrow(array $deny, array $allow = []): self
    {
        $current = array_keys($this->allowed);
        if ($allow !== []) {
            $current = array_values(array_intersect($current, $allow));
        }
        $current = array_values(array_diff($current, $deny));
        return new self($current);
    }

    /** @return list<string> */
    public function tags(): array
    {
        return array_keys($this->allowed);
    }

    public function hash(): string
    {
        $tags = array_keys($this->allowed);
        sort($tags);
        return hash('sha256', implode(',', $tags));
    }
}
