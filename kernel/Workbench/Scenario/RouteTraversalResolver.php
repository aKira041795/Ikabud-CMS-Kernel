<?php

declare(strict_types=1);

namespace Ikabud\Kernel\Workbench\Scenario;

/**
 * Route traversal that prefers observed list-to-detail-to-edit links
 * before requesting a provider.
 *
 * Flow:
 *   1. Start with an entry route (e.g. /admin/pal/projects)
 *   2. Try to observe list links → detail links → edit links
 *   3. If a route requires a path parameter (e.g. /admin/pal/projects/{id}),
 *      prefer an observed link href over requesting a provider
 *   4. Only call the scenario data provider as a last resort
 *   5. Classify unavailable parameterized routes as unmet-prerequisite
 */
final class RouteTraversalResolver
{
    /** @param array<string,string> $observedLinks Map of route_pattern => observed_href */
    public function __construct(private array $observedLinks = []) {}

    /**
     * Register observed links from a list page traversal.
     *
     * @param string $routePattern Route pattern (e.g. /admin/pal/projects/{id})
     * @param string $observedHref The actual href found on the page
     */
    public function observeLink(string $routePattern, string $observedHref): void
    {
        $this->observedLinks[$routePattern] = $observedHref;
    }

    /** @param array<string,string> $links */
    public function observeLinks(array $links): void
    {
        foreach ($links as $pattern => $href) {
            $this->observedLinks[$pattern] = $href;
        }
    }

    /**
     * Resolve a route — prefers observed links, falls back to provider.
     *
     * @param string $route The route pattern to resolve (e.g. /admin/pal/projects/{id})
     * @param callable(string $route): ?string $providerFallback Fallback that returns a valid href or null
     * @return array{route: string, resolved_url: ?string, source: string}
     */
    public function resolve(string $route, callable $providerFallback): array
    {
        // 1. An exact declared pattern wins over a compatible sibling pattern.
        // This prevents `{id}` from shadowing a more specific `{project_uuid}` route.
        if (isset($this->observedLinks[$route])) {
            return [
                'route' => $route,
                'resolved_url' => $this->observedLinks[$route],
                'source' => 'observed',
            ];
        }

        // 2. Check compatible observed links.
        foreach ($this->observedLinks as $pattern => $href) {
            if ($this->patternMatches($pattern, $route)) {
                return [
                    'route' => $route,
                    'resolved_url' => $href,
                    'source' => 'observed',
                ];
            }
        }

        // 3. Fall back to provider
        $providerResult = $providerFallback($route);
        if ($providerResult !== null) {
            return [
                'route' => $route,
                'resolved_url' => $providerResult,
                'source' => 'provider',
            ];
        }

        // 4. No resolution possible
        return [
            'route' => $route,
            'resolved_url' => null,
            'source' => 'unresolved',
        ];
    }

    /**
     * Classify unresolved routes.
     *
     * @param array{route: string, resolved_url: ?string, source: string} $resolution
     * @return array<string,mixed>
     */
    public function classifyUnresolved(array $resolution): array
    {
        if ($resolution['resolved_url'] !== null) {
            return ['classification' => 'resolved', 'note' => ''];
        }

        $paramPatterns = ['/\{id\}/', '/\{[a-z_]+\}/', '/\[id\]/', '/\[[a-z_]+\]/'];
        $hasParameter = false;
        foreach ($paramPatterns as $pattern) {
            if (preg_match($pattern, $resolution['route'])) {
                $hasParameter = true;
                break;
            }
        }

        if ($hasParameter) {
            return [
                'classification' => 'unmet-prerequisite',
                'note' => 'No valid entity observed for parameterized route: ' . $resolution['route'],
                'recommendation' => 'Traverse a list route first to observe entity links, or provide a ScenarioDataProvider that can create valid records.',
            ];
        }

        return [
            'classification' => 'confirmed-defect',
            'note' => 'Route not found: ' . $resolution['route'],
            'recommendation' => 'Verify the route is registered and accessible with the current role/permissions.',
        ];
    }

    /** @return array<string,string> */
    public function getObservedLinks(): array
    {
        return $this->observedLinks;
    }

    /**
     * Check if an observed link pattern matches a route.
     */
    private function patternMatches(string $pattern, string $route): bool
    {
        // Convert pattern like /admin/pal/projects/{id} to regex
        $placeholderPattern = '/\{[A-Za-z_][A-Za-z0-9_]*\}/';
        $tokenized = preg_replace($placeholderPattern, '__ARK_ROUTE_PARAMETER__', $pattern) ?? $pattern;
        $regex = str_replace('__ARK_ROUTE_PARAMETER__', '[^/]+', preg_quote($tokenized, '#'));
        return (bool) preg_match('#^' . $regex . '$#', $route);
    }
}
