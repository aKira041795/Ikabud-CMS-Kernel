<?php

declare(strict_types=1);

namespace Ikabud\Kernel\EntityContext;

final class ContextRegistry
{
    /**
     * @var array<string, array<int, array{provider: string, priority: int, definition: array<string, mixed>}>>
     */
    private array $contextDefinitions = [];

    /**
     * @var array<string, array<int, array{provider: string, priority: int, extension: array<string, mixed>}>>
     */
    private array $contextExtensions = [];

    /**
     * @var array<string, array<int, array{provider: string, priority: int, binding: array<string, mixed>}>>
     */
    private array $entityTypeBindings = [];

    /**
     * @var array<string, array<int, array{provider: string, priority: int, definition: array<string, mixed>}>>
     */
    private array $capabilityDefinitions = [];

    /**
     * @param array<string, mixed> $definition
     */
    public function registerContext(string $contextId, array $definition, string $providerId = 'kernel', int $priority = 10): void
    {
        $contextId = $this->normalizeId($contextId);
        if ($contextId === '') {
            return;
        }

        $definition['id'] = $contextId;
        $this->contextDefinitions[$contextId][] = [
            'provider' => $this->normalizeProvider($providerId),
            'priority' => $priority,
            'definition' => $this->normalizeContextDefinition($definition),
        ];
        $this->sortEntries($this->contextDefinitions[$contextId]);
    }

    /**
     * @param array<string, mixed> $extension
     */
    public function extendContext(string $contextId, array $extension, string $providerId = 'kernel', int $priority = 10): void
    {
        $contextId = $this->normalizeId($contextId);
        if ($contextId === '') {
            return;
        }

        $this->contextExtensions[$contextId][] = [
            'provider' => $this->normalizeProvider($providerId),
            'priority' => $priority,
            'extension' => $this->normalizeContextDefinition($extension),
        ];
        $this->sortEntries($this->contextExtensions[$contextId]);
    }

    /**
     * @param array<string, mixed> $binding
     */
    public function bindEntityType(string $entityType, array $binding, string $providerId = 'kernel', int $priority = 10): void
    {
        $entityType = $this->normalizeId($entityType);
        if ($entityType === '') {
            return;
        }

        $this->entityTypeBindings[$entityType][] = [
            'provider' => $this->normalizeProvider($providerId),
            'priority' => $priority,
            'binding' => $this->normalizeBinding($binding, $entityType),
        ];
        $this->sortEntries($this->entityTypeBindings[$entityType]);
    }

    /**
     * @param array<string, mixed> $definition
     */
    public function registerCapability(string $capabilityId, array $definition, string $providerId = 'kernel', int $priority = 10): void
    {
        $capabilityId = $this->normalizeId($capabilityId);
        if ($capabilityId === '') {
            return;
        }

        $definition['id'] = $capabilityId;
        $this->capabilityDefinitions[$capabilityId][] = [
            'provider' => $this->normalizeProvider($providerId),
            'priority' => $priority,
            'definition' => $this->normalizeCapabilityDefinition($definition),
        ];
        $this->sortEntries($this->capabilityDefinitions[$capabilityId]);
    }

    public function hasContext(string $contextId): bool
    {
        return !empty($this->contextDefinitions[$this->normalizeId($contextId)]);
    }

    public function hasEntityType(string $entityType): bool
    {
        return !empty($this->entityTypeBindings[$this->normalizeId($entityType)]);
    }

    /**
     * @return string[]
     */
    public function contextIds(): array
    {
        $ids = array_keys($this->contextDefinitions);
        sort($ids);
        return $ids;
    }

    /**
     * @return string[]
     */
    public function capabilityIds(): array
    {
        $ids = array_keys($this->capabilityDefinitions);
        sort($ids);
        return $ids;
    }

    /**
     * @return array<string, mixed>
     */
    public function contextDefinition(string $contextId): array
    {
        $contextId = $this->normalizeId($contextId);
        $entries = $this->contextDefinitions[$contextId] ?? [];
        if ($entries === []) {
            return [
                'id' => $contextId,
                'label' => $this->defaultLabel($contextId),
                'capabilities' => [],
                'meta' => [],
                'providers' => [],
            ];
        }

        $profile = new ContextProfile($contextId);
        foreach (array_reverse($entries) as $entry) {
            $definition = $entry['definition'];
            $definition['source'] = $entry['provider'];
            $profile->merge($definition);
        }

        $data = $profile->toArray();
        $data['providers'] = array_values(array_map(static fn(array $entry): string => $entry['provider'], $entries));
        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    public function capabilityDefinition(string $capabilityId): array
    {
        $capabilityId = $this->normalizeId($capabilityId);
        $entries = $this->capabilityDefinitions[$capabilityId] ?? [];
        $definition = [
            'id' => $capabilityId,
            'priority' => 10,
            'block' => '',
            'meta' => [],
            'customizer' => [],
            'providers' => [],
        ];

        foreach (array_reverse($entries) as $entry) {
            $definition = array_replace_recursive($definition, $entry['definition']);
        }

        $definition['providers'] = array_values(array_map(static fn(array $entry): string => $entry['provider'], $entries));

        return $definition;
    }

    /**
     * @return array<string, mixed>
     */
    public function entityTypeBinding(string $entityType): array
    {
        $entityType = $this->normalizeId($entityType);
        $entries = $this->entityTypeBindings[$entityType] ?? [];
        $binding = [
            'entity_type' => $entityType,
            'base' => '',
            'extensions' => [],
            'overrides' => [],
            'providers' => [],
        ];

        foreach (array_reverse($entries) as $entry) {
            $candidate = $entry['binding'];
            if (($candidate['base'] ?? '') !== '') {
                $binding['base'] = (string)$candidate['base'];
            }
            foreach ($candidate['extensions'] ?? [] as $extension) {
                if (is_string($extension) && $extension !== '' && !in_array($extension, $binding['extensions'], true)) {
                    $binding['extensions'][] = $extension;
                }
            }
            if (is_array($candidate['overrides'] ?? null)) {
                $binding['overrides'] = array_replace_recursive($binding['overrides'], $candidate['overrides']);
            }
        }

        $binding['providers'] = array_values(array_map(static fn(array $entry): string => $entry['provider'], $entries));

        return $binding;
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function resolve(string $entityType, array $options = []): array
    {
        $entityType = $this->normalizeId($entityType);
        $binding = $this->entityTypeBinding($entityType);
        $profileOverride = is_array($options['profile'] ?? null) ? $options['profile'] : [];
        $attachedCapabilities = is_array($options['attached_capabilities'] ?? null) ? $options['attached_capabilities'] : [];

        $base = $this->normalizeId((string)($profileOverride['base'] ?? $binding['base'] ?? ''));
        $extensions = $this->normalizeStringList(array_merge(
            is_array($binding['extensions'] ?? null) ? $binding['extensions'] : [],
            is_array($profileOverride['extensions'] ?? null) ? $profileOverride['extensions'] : []
        ));
        $overrides = array_replace_recursive(
            is_array($binding['overrides'] ?? null) ? $binding['overrides'] : [],
            is_array($profileOverride['overrides'] ?? null) ? $profileOverride['overrides'] : []
        );

        $contexts = [
            'base' => null,
            'extensions' => [],
        ];
        $resolvedCapabilities = [];

        if ($base !== '') {
            $baseProfile = $this->buildContextProfile($base, ['entity_type' => $entityType] + $options);
            $contexts['base'] = $baseProfile->toArray();
            $this->applyProfileCapabilities($resolvedCapabilities, $baseProfile, $base, $attachedCapabilities);
        }

        foreach ($extensions as $extensionId) {
            $profile = $this->buildContextProfile($extensionId, ['entity_type' => $entityType] + $options);
            $contexts['extensions'][] = $profile->toArray();
            $this->applyProfileCapabilities($resolvedCapabilities, $profile, $extensionId, $attachedCapabilities);
        }

        foreach ($attachedCapabilities as $capabilityId => $config) {
            if (is_int($capabilityId) && is_string($config)) {
                $capabilityId = $config;
                $config = [];
            }

            if (!is_string($capabilityId) || trim($capabilityId) === '') {
                continue;
            }

            $capabilityId = $this->normalizeId($capabilityId);
            if (!isset($resolvedCapabilities[$capabilityId])) {
                $resolvedCapabilities[$capabilityId] = $this->capabilityDefinition($capabilityId);
                $resolvedCapabilities[$capabilityId]['id'] = $capabilityId;
                $resolvedCapabilities[$capabilityId]['sources'] = [];
            }

            if (!in_array('attached', $resolvedCapabilities[$capabilityId]['sources'], true)) {
                $resolvedCapabilities[$capabilityId]['sources'][] = 'attached';
            }

            if (is_array($config)) {
                $resolvedCapabilities[$capabilityId]['config'] = $config;
            }
        }

        foreach ($resolvedCapabilities as $capabilityId => &$definition) {
            $definition['id'] = $capabilityId;
            $definition['config'] = is_array($definition['config'] ?? null) ? $definition['config'] : [];
            $definition['priority'] = (int)($definition['priority'] ?? 10);
            $definition['block'] = trim((string)($definition['block'] ?? ''));
            $definition['sources'] = $this->normalizeStringList(is_array($definition['sources'] ?? null) ? $definition['sources'] : []);
        }
        unset($definition);

        ksort($resolvedCapabilities);

        return [
            'entity_type' => $entityType,
            'binding' => [
                'base' => $base,
                'extensions' => $extensions,
                'overrides' => $overrides,
                'providers' => $binding['providers'] ?? [],
            ],
            'contexts' => $contexts,
            'capabilities' => $resolvedCapabilities,
            'capability_ids' => array_keys($resolvedCapabilities),
            'capability_flags' => array_fill_keys(array_keys($resolvedCapabilities), true),
            'blocks' => $this->buildBlocks($resolvedCapabilities),
            'overrides' => $overrides,
        ];
    }

    /**
     * @param array<string, mixed> $resolvedContext
     * @param array<int, array<string, mixed>> $baseSections
     * @return array<string, mixed>
     */
    public function buildCustomizerSchema(array $resolvedContext, array $baseSections = []): array
    {
        $sections = [];

        foreach ($baseSections as $section) {
            if (!is_array($section)) {
                continue;
            }

            $sectionId = $this->normalizeId((string)($section['id'] ?? ''));
            if ($sectionId === '') {
                continue;
            }

            $sections[$sectionId] = $this->normalizeSection($section, $sectionId);
        }

        $capabilities = is_array($resolvedContext['capabilities'] ?? null) ? $resolvedContext['capabilities'] : [];
        foreach ($capabilities as $capabilityId => $definition) {
            if (!is_array($definition)) {
                continue;
            }

            $customizer = is_array($definition['customizer'] ?? null) ? $definition['customizer'] : [];
            if ($customizer === []) {
                continue;
            }

            $sectionMeta = is_array($customizer['section'] ?? null) ? $customizer['section'] : [];
            $sectionId = $this->normalizeId((string)($sectionMeta['id'] ?? $capabilityId));
            if ($sectionId === '') {
                continue;
            }

            if (!isset($sections[$sectionId])) {
                $sections[$sectionId] = $this->normalizeSection($sectionMeta, $sectionId);
            } else {
                $existing = $sections[$sectionId];
                $incoming = $this->normalizeSection($sectionMeta, $sectionId);
                $existing['priority'] = max((int)$existing['priority'], (int)$incoming['priority']);
                if (($incoming['label'] ?? '') !== '') {
                    $existing['label'] = $incoming['label'];
                }
                $existing['visibility'] = $this->mergeVisibility(
                    is_array($existing['visibility'] ?? null) ? $existing['visibility'] : [],
                    is_array($incoming['visibility'] ?? null) ? $incoming['visibility'] : []
                );
                $sections[$sectionId] = $existing;
            }

            $sections[$sectionId]['visibility'] = $this->mergeVisibility(
                is_array($sections[$sectionId]['visibility'] ?? null) ? $sections[$sectionId]['visibility'] : [],
                ['requires_any_capabilities' => [$capabilityId]]
            );

            foreach (($customizer['fields'] ?? []) as $field) {
                if (!is_array($field)) {
                    continue;
                }

                $fieldName = trim((string)($field['name'] ?? ''));
                if ($fieldName === '') {
                    continue;
                }

                $normalizedField = $this->normalizeField($field, $capabilityId);
                $sections[$sectionId]['fields'][$fieldName] = array_replace_recursive(
                    $sections[$sectionId]['fields'][$fieldName] ?? [],
                    $normalizedField
                );
            }
        }

        $orderedSections = array_values($sections);
        foreach ($orderedSections as &$section) {
            $fields = array_values($section['fields'] ?? []);
            usort($fields, static function (array $left, array $right): int {
                $priority = ((int)($right['priority'] ?? 0)) <=> ((int)($left['priority'] ?? 0));
                if ($priority !== 0) {
                    return $priority;
                }

                return strcmp((string)($left['name'] ?? ''), (string)($right['name'] ?? ''));
            });
            $section['fields'] = $fields;
        }
        unset($section);

        usort($orderedSections, static function (array $left, array $right): int {
            $priority = ((int)($right['priority'] ?? 0)) <=> ((int)($left['priority'] ?? 0));
            if ($priority !== 0) {
                return $priority;
            }

            return strcmp((string)($left['id'] ?? ''), (string)($right['id'] ?? ''));
        });

        return [
            'entity_type' => (string)($resolvedContext['entity_type'] ?? ''),
            'context_profile' => is_array($resolvedContext['binding'] ?? null) ? $resolvedContext['binding'] : [],
            'resolved_capabilities' => array_values(array_keys($capabilities)),
            'sections' => $orderedSections,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function export(): array
    {
        $contexts = [];
        foreach ($this->contextIds() as $contextId) {
            $contexts[$contextId] = $this->contextDefinition($contextId);
        }

        $bindings = [];
        $entityTypes = array_keys($this->entityTypeBindings);
        sort($entityTypes);
        foreach ($entityTypes as $entityType) {
            $bindings[$entityType] = $this->entityTypeBinding($entityType);
        }

        $capabilities = [];
        foreach ($this->capabilityIds() as $capabilityId) {
            $capabilities[$capabilityId] = $this->capabilityDefinition($capabilityId);
        }

        return [
            'contexts' => $contexts,
            'bindings' => $bindings,
            'capabilities' => $capabilities,
        ];
    }

    /**
     * @param array<string, mixed> $options
     */
    private function buildContextProfile(string $contextId, array $options = []): ContextProfile
    {
        $definition = $this->contextDefinition($contextId);
        $profile = new ContextProfile($contextId, $definition);

        foreach (array_reverse($this->contextExtensions[$contextId] ?? []) as $entry) {
            $extension = $entry['extension'];
            $extension['source'] = $entry['provider'];
            $profile->merge($extension);
        }

        if (\function_exists('app')) {
            try {
                $filtered = \app()->hooks()->filter('context.extend.' . $contextId, $profile, $options);
                if ($filtered instanceof ContextProfile) {
                    $profile = $filtered;
                } elseif (is_array($filtered)) {
                    $profile->merge($filtered);
                }
            } catch (\Throwable $e) {
            }
        }

        return $profile;
    }

    /**
     * @param array<string, array<string, mixed>> $resolvedCapabilities
     * @param array<int|string, mixed> $attachedCapabilities
     */
    private function applyProfileCapabilities(array &$resolvedCapabilities, ContextProfile $profile, string $sourceId, array $attachedCapabilities): void
    {
        foreach ($profile->capabilities() as $capabilityId => $profileDefinition) {
            $capability = $this->capabilityDefinition($capabilityId);
            $capability = array_replace_recursive($capability, $profileDefinition);
            $capability['id'] = $capabilityId;
            $capability['sources'] = $this->normalizeStringList(array_merge(
                is_array($resolvedCapabilities[$capabilityId]['sources'] ?? null) ? $resolvedCapabilities[$capabilityId]['sources'] : [],
                [$sourceId]
            ));

            if (array_key_exists($capabilityId, $attachedCapabilities) && is_array($attachedCapabilities[$capabilityId])) {
                $capability['config'] = $attachedCapabilities[$capabilityId];
            } elseif (!isset($capability['config']) || !is_array($capability['config'])) {
                $capability['config'] = [];
            }

            $resolvedCapabilities[$capabilityId] = array_replace_recursive(
                $resolvedCapabilities[$capabilityId] ?? [],
                $capability
            );
        }
    }

    /**
     * @param array<string, array<string, mixed>> $resolvedCapabilities
     * @return array<int, array{capability: string, block: string, priority: int}>
     */
    private function buildBlocks(array $resolvedCapabilities): array
    {
        $blocks = [];
        foreach ($resolvedCapabilities as $capabilityId => $definition) {
            $block = trim((string)($definition['block'] ?? ''));
            if ($block === '') {
                continue;
            }

            $blocks[] = [
                'capability' => $capabilityId,
                'block' => $block,
                'priority' => (int)($definition['priority'] ?? 10),
            ];
        }

        usort($blocks, static function (array $left, array $right): int {
            $priority = $right['priority'] <=> $left['priority'];
            if ($priority !== 0) {
                return $priority;
            }

            $capability = strcmp($left['capability'], $right['capability']);
            if ($capability !== 0) {
                return $capability;
            }

            return strcmp($left['block'], $right['block']);
        });

        return $blocks;
    }

    /**
     * @param array<string, mixed> $definition
     * @return array<string, mixed>
     */
    private function normalizeContextDefinition(array $definition): array
    {
        $capabilities = [];
        foreach (($definition['capabilities'] ?? []) as $key => $entry) {
            if (is_string($entry)) {
                $capabilities[$this->normalizeId($entry)] = ['id' => $this->normalizeId($entry)];
                continue;
            }

            if (is_array($entry)) {
                $capabilityId = '';
                if (isset($entry['id']) && is_string($entry['id'])) {
                    $capabilityId = $this->normalizeId($entry['id']);
                } elseif (is_string($key)) {
                    $capabilityId = $this->normalizeId($key);
                }
                if ($capabilityId !== '') {
                    $entry['id'] = $capabilityId;
                    $capabilities[$capabilityId] = $entry;
                }
                continue;
            }

            if (is_string($key) && $key !== '') {
                $capabilities[$this->normalizeId($key)] = ['id' => $this->normalizeId($key)];
            }
        }

        return [
            'id' => $this->normalizeId((string)($definition['id'] ?? '')),
            'label' => trim((string)($definition['label'] ?? '')),
            'capabilities' => $capabilities,
            'meta' => is_array($definition['meta'] ?? null) ? $definition['meta'] : [],
        ];
    }

    /**
     * @param array<string, mixed> $binding
     * @return array<string, mixed>
     */
    private function normalizeBinding(array $binding, string $entityType): array
    {
        return [
            'entity_type' => $entityType,
            'base' => $this->normalizeId((string)($binding['base'] ?? '')),
            'extensions' => $this->normalizeStringList(is_array($binding['extensions'] ?? null) ? $binding['extensions'] : []),
            'overrides' => is_array($binding['overrides'] ?? null) ? $binding['overrides'] : [],
        ];
    }

    /**
     * @param array<string, mixed> $definition
     * @return array<string, mixed>
     */
    private function normalizeCapabilityDefinition(array $definition): array
    {
        $customizer = is_array($definition['customizer'] ?? null) ? $definition['customizer'] : [];

        return [
            'id' => $this->normalizeId((string)($definition['id'] ?? '')),
            'label' => trim((string)($definition['label'] ?? '')),
            'priority' => (int)($definition['priority'] ?? 10),
            'block' => trim((string)($definition['block'] ?? '')),
            'meta' => is_array($definition['meta'] ?? null) ? $definition['meta'] : [],
            'customizer' => [
                'section' => is_array($customizer['section'] ?? null) ? $customizer['section'] : [],
                'fields' => is_array($customizer['fields'] ?? null) ? $customizer['fields'] : [],
            ],
        ];
    }

    private function normalizeProvider(string $providerId): string
    {
        $providerId = trim($providerId);
        return $providerId !== '' ? $providerId : 'kernel';
    }

    private function normalizeId(string $value): string
    {
        return strtolower(trim($value));
    }

    /**
     * @param array<int, array{provider: string, priority: int, definition?: array<string, mixed>, extension?: array<string, mixed>, binding?: array<string, mixed>}> $entries
     */
    private function sortEntries(array &$entries): void
    {
        usort($entries, static function (array $left, array $right): int {
            $priority = ($right['priority'] ?? 0) <=> ($left['priority'] ?? 0);
            if ($priority !== 0) {
                return $priority;
            }

            return strcmp((string)($left['provider'] ?? ''), (string)($right['provider'] ?? ''));
        });
    }

    /**
     * @param array<int, string> $values
     * @return string[]
     */
    private function normalizeStringList(array $values): array
    {
        $normalized = [];
        foreach ($values as $value) {
            if (!is_string($value)) {
                continue;
            }
            $value = $this->normalizeId($value);
            if ($value === '' || in_array($value, $normalized, true)) {
                continue;
            }
            $normalized[] = $value;
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $section
     * @return array<string, mixed>
     */
    private function normalizeSection(array $section, string $sectionId): array
    {
        return [
            'id' => $sectionId,
            'label' => trim((string)($section['label'] ?? $this->defaultLabel($sectionId))),
            'priority' => (int)($section['priority'] ?? 10),
            'description' => trim((string)($section['description'] ?? '')),
            'visibility' => is_array($section['visibility'] ?? null) ? $section['visibility'] : [],
            'fields' => is_array($section['fields'] ?? null) ? $section['fields'] : [],
        ];
    }

    /**
     * @param array<string, mixed> $field
     * @return array<string, mixed>
     */
    private function normalizeField(array $field, string $capabilityId): array
    {
        $visibility = is_array($field['visibility'] ?? null) ? $field['visibility'] : [];
        $visibility = $this->mergeVisibility($visibility, ['requires_any_capabilities' => [$capabilityId]]);

        $dependsOn = is_array($field['depends_on'] ?? null) ? $field['depends_on'] : [];

        return [
            'name' => trim((string)($field['name'] ?? '')),
            'label' => trim((string)($field['label'] ?? '')),
            'type' => trim((string)($field['type'] ?? 'text')),
            'priority' => (int)($field['priority'] ?? 10),
            'options' => is_array($field['options'] ?? null) ? $field['options'] : [],
            'default' => $field['default'] ?? null,
            'description' => trim((string)($field['description'] ?? '')),
            'min' => $field['min'] ?? null,
            'max' => $field['max'] ?? null,
            'step' => $field['step'] ?? null,
            'unit' => trim((string)($field['unit'] ?? '')),
            'placeholder' => trim((string)($field['placeholder'] ?? '')),
            'empty_option_label' => trim((string)($field['empty_option_label'] ?? '')),
            'depends_on' => $dependsOn,
            'visibility' => $visibility,
        ];
    }

    /**
     * @param array<string, mixed> $left
     * @param array<string, mixed> $right
     * @return array<string, mixed>
     */
    private function mergeVisibility(array $left, array $right): array
    {
        $merged = array_replace_recursive($left, $right);
        foreach (['requires_any_capabilities', 'requires_all_capabilities'] as $key) {
            if (!is_array($merged[$key] ?? null)) {
                continue;
            }
            $merged[$key] = $this->normalizeStringList($merged[$key]);
        }

        return $merged;
    }

    private function defaultLabel(string $id): string
    {
        return ucwords(str_replace(['_', '.'], ' ', $id));
    }

    /**
     * Phase 3B: Expose all registered schemas for introspection and debugging.
     */
    public function getRegisteredSchemas(): array
    {
        return $this->schemas;
    }

    /**
     * Phase 3B: Expose all registered profiles for introspection and debugging.
     */
    public function getRegisteredProfiles(): array
    {
        return $this->profiles;
    }
    
    /**
     * Phase 3B: Expose all registered modes for introspection and debugging.
     */
    public function getRegisteredModes(): array
    {
        return $this->modes;
    }
}
