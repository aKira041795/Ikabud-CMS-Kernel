<?php
/**
 * DiSyL Bridge Manager
 *
 * Resolves bridge implementations by name.
 * Allows modules to choose which frontend framework bridge to use
 * for {ikb_component} and {state} rendering.
 *
 * Bridges are registered once and resolved by name at render time.
 * The Alpine bridge is the default (backward-compatible).
 *
 * Usage in templates:
 *   {ikb_component name="..." data="..." bridge="htmx"}
 *   {state name="..." bridge="custom"}
 *
 * @package Ikabud\Kernel\DiSyL\Bridge
 * @version 1.0.0
 */

namespace Ikabud\Kernel\DiSyL\Bridge;

class BridgeManager
{
    /** @var array<string, BridgeInterface> */
    private static array $bridges = [];

    /** @var bool */
    private static bool $initialized = false;

    /**
     * Get a bridge by name.
     *
     * @param string $name Bridge identifier ('alpine', 'htmx', 'custom')
     * @return BridgeInterface
     */
    public static function resolve(string $name = 'alpine'): BridgeInterface
    {
        self::ensureInitialized();

        $name = strtolower(trim($name));

        return self::$bridges[$name] ?? self::$bridges['alpine'];
    }

    /**
     * Check if a bridge is registered.
     */
    public static function has(string $name): bool
    {
        self::ensureInitialized();
        return isset(self::$bridges[strtolower(trim($name))]);
    }

    /**
     * Register a custom bridge.
     */
    public static function register(BridgeInterface $bridge): void
    {
        self::$bridges[$bridge->name()] = $bridge;
    }

    /**
     * List all registered bridge names.
     *
     * @return array<string>
     */
    public static function list(): array
    {
        self::ensureInitialized();
        return array_keys(self::$bridges);
    }

    /**
     * Initialize default bridges.
     */
    private static function ensureInitialized(): void
    {
        if (self::$initialized) {
            return;
        }

        self::$initialized = true;

        // Register built-in bridges — Alpine is registered last so it's the fallback
        self::register(new CustomBridge());
        self::register(new HtmxBridge());
        self::register(new ReactBridge());
        self::register(new AlpineBridge());
    }
}
