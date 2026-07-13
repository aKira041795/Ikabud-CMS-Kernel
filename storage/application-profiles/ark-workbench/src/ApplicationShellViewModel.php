<?php

declare(strict_types=1);

namespace Ikabud\ApplicationProfiles\ArkWorkbench;

/**
 * ApplicationShellViewModel — reusable shell context builder for ARK Workbench.
 *
 * Encapsulates the application shell data (sidebar navigation, user display,
 * mobile nav, assets) that workbench:app_shell expects. Modules create an
 * instance, configure it, then call toTemplateContext() to pass as shell=
 * parameter to the include.
 *
 * Usage:
 *   $shell = ApplicationShellViewModel::create()
 *       ->withAppName('Project Audit Ledger')
 *       ->withUser($currentUser)
 *       ->withCurrentRoute('dashboard')
 *       ->addNavSection('Overview', [
 *           ['Dashboard', '/admin/project-audit-ledger', '📊'],
 *       ])
 *       ->addUserAction('Sign Out', '/api/v1/project-audit-ledger/auth/logout');
 *
 *   palRender(..., [
 *       'shell_ctx' => $shell->toTemplateContext(),
 *   ]);
 *
 * @package Ikabud\ApplicationProfiles\ArkWorkbench
 */
final class ApplicationShellViewModel
{
    private string $applicationName = '';
    private string $logoUrl = '';
    private string $userDisplay = '';
    private string $pageTitle = '';
    private string $currentRoute = '';
    private bool $inspectMode = false;
    /** @var array<int, array{label: string, collapsed_default: bool, items: array}> */
    private array $navSections = [];
    /** @var array<int, array{label: string, url: string}> */
    private array $userActions = [];
    /** @var array<int, array{label: string, url: string, icon_key: string}> */
    private array $mobileNav = [];
    /** @var array<int, string> */
    private array $extraStyles = [];
    /** @var array<int, string> */
    private array $extraScripts = [];

    private function __construct() {}

    /**
     * Create a new shell view model with defaults.
     */
    public static function create(): self
    {
        return new self();
    }

    /**
     * Set the application name (shown in sidebar header).
     */
    public function withAppName(string $name): self
    {
        $this->applicationName = $name;
        return $this;
    }

    /**
     * Set the logo URL (optional, shown above app name).
     */
    public function withLogoUrl(string $url): self
    {
        $this->logoUrl = $url;
        return $this;
    }

    /**
     * Set the current user display info from a user row array.
     * Expects 'full_name' key (or pass a string directly).
     *
     * @param array|string $user User array or display name string
     */
    public function withUser(array|string $user): self
    {
        $this->userDisplay = is_string($user) ? $user : ($user['full_name'] ?? '');
        return $this;
    }

    /**
     * Set the current page title.
     */
    public function withPageTitle(string $title): self
    {
        $this->pageTitle = $title;
        return $this;
    }

    /**
     * Enable workbench inspect mode — annotates the DOM with data-wb-* attributes
     * for test automation. Activated by ?wb_inspect=1 in the URL.
     */
    public function withInspectMode(bool $enabled = true): self
    {
        $this->inspectMode = $enabled;
        return $this;
    }

    /**
     * Set the current route identifier for active nav highlighting.
     * Matched against the 'page_content' value used in nav items.
     */
    public function withCurrentRoute(string $route): self
    {
        $this->currentRoute = $route;
        return $this;
    }

    /**
     * Add a navigation section with items.
     *
     * @param string $label Section heading
     * @param array $items Array of item arrays, each with keys:
     *   'label' (string), 'url' (string), 'icon_key' (string, optional),
     *   'routes' (string|array, optional — route(s) that mark this active)
     * @param bool $collapsedDefault Whether section starts collapsed
     */
    public function addNavSection(string $label, array $items, bool $collapsedDefault = false): self
    {
        $this->navSections[] = [
            'label' => $label,
            'collapsed_default' => $collapsedDefault,
            'items' => array_map(fn(array $item) => [
                'label' => $item['label'] ?? '',
                'url' => $item['url'] ?? '#',
                'icon_key' => $item['icon_key'] ?? '•',
                'is_active' => $this->isItemActive($item),
            ], $items),
        ];
        return $this;
    }

    /**
     * Add a user action (shown in sidebar footer, e.g., sign out).
     */
    public function addUserAction(string $label, string $url): self
    {
        $this->userActions[] = ['label' => $label, 'url' => $url];
        return $this;
    }

    /**
     * Add a mobile bottom navigation item.
     */
    public function addMobileNav(string $label, string $url, string $iconKey = '•'): self
    {
        $this->mobileNav[] = [
            'label' => $label,
            'url' => $url,
            'icon_key' => $iconKey,
            'is_active' => $this->isRouteActive($url),
        ];
        return $this;
    }

    /**
     * Add extra stylesheet URL.
     */
    public function addExtraStyle(string $url): self
    {
        $this->extraStyles[] = $url;
        return $this;
    }

    /**
     * Add extra script URL.
     */
    public function addExtraScript(string $url): self
    {
        $this->extraScripts[] = $url;
        return $this;
    }

    /**
     * Build and return the template context array.
     *
     * @return array<string, mixed>
     */
    public function toTemplateContext(): array
    {
        return [
            'application_name'    => $this->applicationName,
            'logo_url'            => $this->logoUrl,
            'user_display'        => $this->userDisplay,
            'page_title'          => $this->pageTitle,
            'navigation_sections' => $this->navSections,
            'user_actions'        => $this->userActions,
            'mobile_navigation'   => $this->mobileNav,
            'extra_styles'        => $this->extraStyles,
            'extra_scripts'       => $this->extraScripts,
            'inspect_mode'        => $this->inspectMode,
        ];
    }

    /**
     * Check if a nav item should be marked active.
     */
    private function isItemActive(array $item): bool
    {
        $routes = $item['routes'] ?? $item['route'] ?? null;
        if ($routes === null) {
            return false;
        }
        $routes = is_array($routes) ? $routes : [$routes];
        return in_array($this->currentRoute, $routes, true);
    }

    /**
     * Check if a URL matches the current route (for mobile nav).
     */
    private function isRouteActive(string $url): bool
    {
        // Simple heuristic: check if the URL path ends with the current route
        // More sophisticated matching can be added later
        return false;
    }
}
