<?php

declare(strict_types=1);

namespace Ikabud\Kernel\Workbench\Comprehension;

use Ikabud\Kernel\Workbench\Comprehension\Contracts\{
    ActionContract, ChainLink, EffectContract, EntityContract, InvariantContract,
    ModuleComprehensionProvider, ScenarioContract, WorkflowContract
};

/** Generic adapter from the module-owned Workbench contract to the Comprehension Engine. */
class ContractComprehensionProvider implements ModuleComprehensionProvider
{
    /** @var array<string,mixed> */
    private array $contract;

    public function __construct(string $modulePath)
    {
        $path = rtrim($modulePath, '/') . '/workbench-contract.json';
        $value = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        if (!is_array($value)) throw new \RuntimeException("Invalid Workbench contract: {$path}");
        $this->contract = $value;
    }

    public function entities(): array
    {
        return array_map(static fn(string $table): EntityContract => new EntityContract($table, ucwords(str_replace('_', ' ', $table)), $table), (array) ($this->contract['ownership']['tables'] ?? []));
    }

    public function routes(): array
    {
        $result = [];
        foreach ((array) ($this->contract['ownership']['routes'] ?? []) as $method => $routes) {
            foreach ((array) $routes as $route) $result[] = ['method' => (string) $method, 'path' => (string) $route, 'handler' => 'module-owned'];
        }
        return $result;
    }

    public function workflows(): array
    {
        return array_map(static fn(array $item): WorkflowContract => new WorkflowContract((string) $item['id'], (string) ($item['entity_type'] ?? 'module.entity'), (array) ($item['states'] ?? []), (array) ($item['transitions'] ?? [])), (array) ($this->contract['workflows'] ?? []));
    }

    public function actions(): array
    {
        return array_map(static function (array $item): ActionContract {
            $chain = array_map(static fn(array $effect): ChainLink => new ChainLink((string) ($effect['step'] ?? 'effect'), (string) ($effect['description'] ?? $effect['step'] ?? 'Expected effect'), (string) ($effect['category'] ?? 'service'), isset($effect['probe']) ? (string) $effect['probe'] : null), (array) ($item['effects'] ?? []));
            return new ActionContract((string) $item['id'], (string) ($item['label'] ?? $item['id']), (string) ($item['entity_type'] ?? 'module.entity'), (string) ($item['route'] ?? '/'), (string) ($item['method'] ?? 'POST'), (array) ($item['requires'] ?? []), $chain);
        }, (array) ($this->contract['actions'] ?? []));
    }

    public function capabilities(): array { return array_values((array) ($this->contract['ownership']['capabilities'] ?? [])); }

    public function invariants(): array
    {
        return array_map(static fn(array $item): InvariantContract => new InvariantContract((string) ($item['description'] ?? $item['id']), (string) ($item['scope'] ?? 'module')), (array) ($this->contract['invariants'] ?? []));
    }

    public function expectedEffects(): array
    {
        return array_map(static fn(array $action): EffectContract => new EffectContract((string) $action['id'], ['chain' => (array) ($action['effects'] ?? [])]), (array) ($this->contract['actions'] ?? []));
    }

    public function testScenarios(): array
    {
        return array_map(static fn(array $item): ScenarioContract => new ScenarioContract((string) $item['id'], (string) ($item['description'] ?? $item['id']), (array) ($item['actions'] ?? [])), (array) ($this->contract['scenarios'] ?? []));
    }
}
