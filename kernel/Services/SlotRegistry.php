<?php
/**
 * SlotRegistry — Governed theme slot contribution manager.
 *
 * Manages module-contributed slot content for the {ikb_slot} governed component.
 * Modules register contributions during bootstrap with optional display conditions.
 * The TemplateEngine resolves slot contributions at render time based on context.
 *
 * Usage (module bootstrap):
 *   SlotRegistry::register('content.after', [
 *       'id' => 'ecommerce.related_products',
 *       'component' => 'ikb_entity_list',
 *       'attrs' => ['source' => 'ecommerce.product.related', 'view' => 'card'],
 *       'priority' => 10,
 *       'conditions' => [
 *           'entity_type' => 'ecommerce.product',
 *           'view' => 'detail',
 *       ],
 *   ]);
 *
 * @package Ikabud\Kernel\Services
 */

namespace Ikabud\Kernel\Services;

class SlotRegistry
{
    private static ?self $instance = null;

    /** @var array<string, array<int, array>> slot_name => [priority => [contributions]] */
    private static array $slots = [];

    /** @var array<string, array<string, array>> Cached resolved slot contributions per context hash */
    private static array $resolvedCache = [];

    /**
     * Get the singleton instance.
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Register a slot contribution.
     *
     * @param string $slot Slot identifier (e.g., "content.after", "header.main")
     * @param array $config {
     *     @var string   $id         Unique contribution identifier
     *     @var string   $component  Governed component name (e.g., "ikb_entity_list")
     *     @var array    $attrs      Attributes to pass to the component
     *     @var int      $priority   Priority (lower = earlier). Default: 10
     *     @var array    $conditions Display conditions {
     *         @var string|null $entity_type   Match entity type (e.g., "ecommerce.product")
     *         @var string|null $view          Match view (e.g., "detail", "list")
     *         @var string|null $route         Match route pattern
     *         @var string|null $role          Match user role
     *         @var string|null $capability    Require capability (e.g., "ecommerce.cart.add@1")
     *         @var string|null $tenant        Match tenant ID
     *     }
     * }
     */
    public static function register(string $slot, array $config): void
    {
        $id = $config['id'] ?? md5(json_encode($config));
        $priority = $config['priority'] ?? 10;

        if (!isset(self::$slots[$slot])) {
            self::$slots[$slot] = [];
        }
        if (!isset(self::$slots[$slot][$priority])) {
            self::$slots[$slot][$priority] = [];
        }

        // Prevent duplicate registration by ID
        foreach (self::$slots[$slot] as &$group) {
            foreach ($group as $existing) {
                if (($existing['id'] ?? null) === $id) {
                    return; // Already registered
                }
            }
        }

        self::$slots[$slot][$priority][] = array_merge([
            'id' => $id,
            'component' => 'ikb_panel',
            'attrs' => [],
            'priority' => $priority,
            'conditions' => [],
        ], $config);

        // Clear resolved cache for this slot
        unset(self::$resolvedCache[$slot]);
    }

    /**
     * Resolve all contributions for a slot matching the given context.
     *
     * @param string $slot    Slot identifier
     * @param array  $context Rendering context (entity_type, view, route, role, capabilities, tenant)
     * @return array Ordered array of matching contributions
     */
    public static function resolve(string $slot, array $context = []): array
    {
        if (empty(self::$slots[$slot])) {
            return [];
        }

        // Build context hash for caching
        $ctxHash = md5(serialize($context));
        if (isset(self::$resolvedCache[$slot][$ctxHash])) {
            return self::$resolvedCache[$slot][$ctxHash];
        }

        // Sort by priority (ascending)
        ksort(self::$slots[$slot]);

        $matched = [];
        foreach (self::$slots[$slot] as $priority => $group) {
            foreach ($group as $contribution) {
                if (self::matchesConditions($contribution['conditions'] ?? [], $context)) {
                    $matched[] = $contribution;
                }
            }
        }

        // Cache result
        self::$resolvedCache[$slot][$ctxHash] = $matched;

        return $matched;
    }

    /**
     * Check if a set of conditions matches the current context.
     */
    private static function matchesConditions(array $conditions, array $context): bool
    {
        foreach ($conditions as $key => $value) {
            if ($value === null) {
                continue;
            }

            switch ($key) {
                case 'entity_type':
                    $ctxValue = $context['entity_type'] ?? $context['source'] ?? null;
                    if ($ctxValue !== $value) {
                        return false;
                    }
                    break;

                case 'view':
                    $ctxValue = $context['view'] ?? null;
                    if ($ctxValue !== $value) {
                        return false;
                    }
                    break;

                case 'route':
                    $ctxValue = $context['route'] ?? $context['public_route_kind'] ?? null;
                    if ($ctxValue !== null && !fnmatch($value, (string) $ctxValue)) {
                        return false;
                    }
                    break;

                case 'role':
                    $userRole = $context['role'] ?? $context['user']['role'] ?? null;
                    if ($userRole !== $value) {
                        return false;
                    }
                    break;

                case 'capability':
                    $capabilities = $context['capabilities'] ?? $context['user']['capabilities'] ?? [];
                    if (!in_array($value, $capabilities, true)) {
                        return false;
                    }
                    break;

                case 'tenant':
                    $ctxValue = $context['tenant'] ?? $context['tenant_id'] ?? null;
                    if ($ctxValue !== $value) {
                        return false;
                    }
                    break;

                case 'not':
                    // Inverted conditions (e.g., "not.entity_type" match)
                    if (is_array($value)) {
                        foreach ($value as $subKey => $subValue) {
                            if (self::matchesConditions([$subKey => $subValue], $context)) {
                                return false;
                            }
                        }
                    }
                    break;
            }
        }

        return true;
    }

    /**
     * Get all registered slots and their contributions (for inspection/diagnostics).
     */
    public static function all(): array
    {
        $result = [];
        foreach (self::$slots as $slot => $groups) {
            $result[$slot] = [];
            ksort($groups);
            foreach ($groups as $priority => $contributions) {
                foreach ($contributions as $c) {
                    $result[$slot][] = $c;
                }
            }
        }
        return $result;
    }

    /**
     * Get contributions for a specific slot (for diagnostics).
     */
    public static function getSlotContributions(string $slot): array
    {
        return self::$slots[$slot] ?? [];
    }

    /**
     * Check if any contributions exist for a slot.
     */
    public static function hasSlot(string $slot): bool
    {
        return !empty(self::$slots[$slot]);
    }

    /**
     * Clear all registrations (for testing).
     */
    public static function reset(): void
    {
        self::$slots = [];
        self::$resolvedCache = [];
    }
}
