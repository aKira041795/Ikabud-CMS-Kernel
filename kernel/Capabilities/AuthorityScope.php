<?php

/**
 * Port provenance: copied from /var/www/html/ikabudsix.
 * The ikabudsix and applicationostest lineages have no merge base; do not merge histories.
 */

declare(strict_types=1);

namespace Ikabud\Kernel\Capabilities;

/**
 * Transportable identity of the authority store and caller context.
 *
 * declarationRevision is carried for the ratified declaration model, but this
 * slice does not materialize or enforce declaration revisions yet.
 */
final readonly class AuthorityScope
{
    /** @param array<string, mixed>|null $actor */
    public function __construct(
        public int $tenantId,
        public ?array $actor,
        public ?int $declarationRevision,
        public string $entryPoint,
    ) {
        if ($tenantId <= 0) {
            throw new \InvalidArgumentException('An authority scope requires a positive tenant id.');
        }
        if (!AuthorityScopeResolver::supportsEntryPoint($entryPoint)) {
            throw new \InvalidArgumentException('Unknown authority entry point: ' . $entryPoint);
        }
    }

    /** @return array{tenant_id:int,actor:array<string,mixed>|null,declaration_revision:int|null,entry_point:string} */
    public function toArray(): array
    {
        return [
            'tenant_id' => $this->tenantId,
            'actor' => $this->actor,
            'declaration_revision' => $this->declarationRevision,
            'entry_point' => $this->entryPoint,
        ];
    }
}
