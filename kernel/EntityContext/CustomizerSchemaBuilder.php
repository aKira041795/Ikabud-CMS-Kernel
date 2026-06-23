<?php

declare(strict_types=1);

namespace Ikabud\Kernel\EntityContext;

/**
 * Builds a customizer schema from resolved context capability definitions.
 *
 * Extracted from ContextRegistry (P2.3) for focused testability and
 * single responsibility. Processes capability customizer metadata into
 * a structured fields-per-section schema ordered by priority.
 *
 * @package Ikabud\Kernel\EntityContext
 */
final class CustomizerSchemaBuilder
{
    /**
     * Build a complete customizer schema from a resolved context array.
     *
     * @param array<string, mixed> $resolvedContext  Resolved entity context with 'capabilities' key
     * @param array<int, array<string, mixed>> $baseSections  Pre-defined sections from profile bindings
     * @return array{entity_type: string, context_profile: array, resolved_capabilities: list<string>, sections: list<array>}
     */
    public function build(array $resolvedContext, array $baseSections = []): array
    {
        $sections = $this->buildBaseSections($baseSections);
        $sections = $this->buildCapabilitySections($resolvedContext, $sections);
        $orderedSections = $this->sortSections($sections);

        return [
            'entity_type' => (string)($resolvedContext['entity_type'] ?? ''),
            'context_profile' => is_array($resolvedContext['binding'] ?? null) ? $resolvedContext['binding'] : [],
            'resolved_capabilities' => array_values(array_keys(
                is_array($resolvedContext['capabilities'] ?? null) ? $resolvedContext['capabilities'] : []
            )),
            'sections' => $orderedSections,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $baseSections
     * @return array<string, array>
     */
    private function buildBaseSections(array $baseSections): array
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
        return $sections;
    }

    /**
     * @param array<string, mixed> $resolvedContext
     * @param array<string, array> $sections
     * @return array<string, array>
     */
    private function buildCapabilitySections(array $resolvedContext, array $sections): array
    {
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

        return $sections;
    }

    /**
     * @param array<string, array> $sections
     * @return list<array>
     */
    private function sortSections(array $sections): array
    {
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

        return $orderedSections;
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
     * @param array<string> $values
     * @return list<string>
     */
    private function normalizeStringList(array $values): array
    {
        $normalized = [];
        foreach ($values as $value) {
            if (!is_string($value) || trim($value) === '') {
                continue;
            }
            $normalized[] = $this->normalizeId($value);
        }
        return $normalized;
    }

    private function normalizeId(string $value): string
    {
        $value = trim(strtolower($value));
        // Normalize separators: spacing, underscores, camelCase → dots
        $value = preg_replace('/[\\s_]+/', '.', $value);
        $value = preg_replace('/([a-z])([A-Z])/', '$1.$2', $value);
        $value = preg_replace('/\\.+/', '.', $value);
        return trim($value, '.');
    }
}
