<?php

declare(strict_types=1);

namespace Ikabud\Kernel\Workbench\Comprehension\Contracts;

/**
 * Declare the entities, routes, actions, workflows, capabilities,
 * invariants, expected effects, and test scenarios a module supports.
 *
 * Every Workbench-compatible module should eventually implement this.
 * For now, PAL declares its contract manually via this file.
 */
interface ModuleComprehensionProvider
{
    /** @return EntityContract[] */
    public function entities(): array;

    /** @return array<int, array{method: string, path: string, handler: string}> */
    public function routes(): array;

    /** @return WorkflowContract[] */
    public function workflows(): array;

    /** @return ActionContract[] */
    public function actions(): array;

    /** @return array<int, string> Capability IDs */
    public function capabilities(): array;

    /** @return InvariantContract[] */
    public function invariants(): array;

    /** @return EffectContract[] */
    public function expectedEffects(): array;

    /** @return ScenarioContract[] */
    public function testScenarios(): array;
}
