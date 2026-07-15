<?php

declare(strict_types=1);

namespace Ikabud\Kernel\Testing;

/**
 * WorkbenchContractValidator — validates component HTML output against
 * contract JSON definitions.
 *
 * Reads contract JSON files from the ARK Workbench profile and generates
 * conformance assertions. Each contract declares required attributes,
 * ARIA roles, and behavioral requirements that the rendered component
 * must satisfy.
 *
 * Usage:
 *   $validator = new WorkbenchContractValidator();
 *   $violations = $validator->validate(
 *       'dialog',
 *       '<div role="dialog" aria-modal="true" ...>',
 *       ['variant' => 'alert']
 *   );
 *   assertEmpty($violations);
 *
 * @package Ikabud\Kernel\Testing
 */
final class WorkbenchContractValidator
{
    /** @var array<string, array> Cached contract definitions */
    private array $contracts = [];

    /**
     * Load a component contract by name.
     *
     * @param string $component Component name (e.g., 'dialog', 'responsive-table')
     * @return array<string, mixed> The contract definition
     * @throws \RuntimeException If contract file not found
     */
    public function loadContract(string $component): array
    {
        if (isset($this->contracts[$component])) {
            return $this->contracts[$component];
        }

        $contractPath = defined('BASE_PATH')
            ? BASE_PATH . '/storage/application-profiles/ark-workbench/contracts/' . $component . '.contract.json'
            : __DIR__ . '/../../storage/application-profiles/ark-workbench/contracts/' . $component . '.contract.json';

        if (!is_file($contractPath)) {
            throw new \RuntimeException("Contract not found: {$component} ({$contractPath})");
        }

        $contract = json_decode(file_get_contents($contractPath), true);
        if (!is_array($contract)) {
            throw new \RuntimeException("Invalid contract JSON: {$component}");
        }

        $this->contracts[$component] = $contract;
        return $contract;
    }

    /**
     * Validate rendered HTML against a component contract.
     *
     * @param string $component Component name
     * @param string $html The rendered HTML to validate
     * @param array<string, mixed> $props Props that were passed to the component
     * @return array<string, string> Violation messages keyed by requirement name
     */
    public function validate(string $component, string $html, array $props = []): array
    {
        $contract = $this->loadContract($component);
        $requirements = $contract['requirements'] ?? [];
        $violations = [];

        foreach ($requirements as $key => $expected) {
            $check = 'check' . $this->camelCase($key);
            if (method_exists($this, $check)) {
                $result = $this->$check($html, $expected, $props);
                if ($result !== null) {
                    $violations[$key] = $result;
                }
            } else {
                $violations[$key] = "Unsupported Workbench contract requirement: {$key}";
            }
        }

        // Validate data-wb-component attribute
        $expectedComponent = $contract['attributes']['data-wb-component']['value'] ?? null;
        if ($expectedComponent && !str_contains($html, "data-wb-component=\"{$expectedComponent}\"")) {
            $violations['data-wb-component'] = "Missing data-wb-component=\"{$expectedComponent}\"";
        }

        // Validate input attributes
        $attrDefs = $contract['attributes'] ?? [];
        foreach ($attrDefs as $attrName => $attrDef) {
            if ($attrName === 'data-wb-component') continue;
            $source = $attrDef['source'] ?? null;
            if ($source !== null) {
                $expectedValue = $this->resolveAttributeSource($source, $props);
                if ($expectedValue === null) {
                    continue;
                }
                $expectedValue = (string)$expectedValue;
                if (!str_contains($html, "{$attrName}=\"{$expectedValue}\"")) {
                    $violations[$attrName] = "Missing {$attrName}=\"{$expectedValue}\"";
                }
            }
        }

        return $violations;
    }

    /**
     * Resolve a contract attribute source expression against component props.
     */
    private function resolveAttributeSource(string $source, array $props): mixed
    {
        if (str_ends_with($source, '|count')) {
            $key = substr($source, 0, -6);
            return isset($props[$key]) && is_countable($props[$key]) ? count($props[$key]) : null;
        }

        return array_key_exists($source, $props) ? $props[$source] : null;
    }

    /**
     * Validate that the HTML has a specific role attribute.
     */
    private function checkRole(string $html, string $expected): ?string
    {
        if (!preg_match('/role="([^"]+)"/', $html, $m)) {
            return "Missing role attribute (expected: {$expected})";
        }
        if ($m[1] !== $expected) {
            return "Role is '{$m[1]}', expected '{$expected}'";
        }
        return null;
    }

    /**
     * Validate aria-modal is present.
     */
    private function checkAriaModal(string $html, bool $expected): ?string
    {
        if ($expected && !str_contains($html, 'aria-modal="true"')) {
            return 'Missing aria-modal="true"';
        }
        return null;
    }

    /**
     * Validate aria-labelledby is present.
     */
    private function checkAriaLabelledby(string $html, bool $expected): ?string
    {
        if ($expected && !preg_match('/aria-labelledby="[^"]+"/', $html)) {
            return 'Missing aria-labelledby attribute';
        }
        return null;
    }

    /**
     * Validate aria-expanded is present.
     */
    private function checkAriaExpanded(string $html, bool $expected): ?string
    {
        if ($expected && !preg_match('/aria-expanded=\"[^\"]+\"/', $html)) {
            return 'Missing aria-expanded attribute';
        }
        return null;
    }

    /**
     * Validate aria-owns is present.
     */
    private function checkAriaOwns(string $html, bool $expected): ?string
    {
        if ($expected && !preg_match('/aria-owns=\"[^\"]+\"/', $html)) {
            return 'Missing aria-owns attribute';
        }
        return null;
    }

    /**
     * Validate aria-live is set correctly.
     */
    private function checkAriaLive(string $html, string $expected): ?string
    {
        if (!preg_match('/aria-live="([^"]+)"/', $html, $m)) {
            return "Missing aria-live attribute (expected: {$expected})";
        }
        if ($m[1] !== $expected) {
            return "aria-live is '{$m[1]}', expected '{$expected}'";
        }
        return null;
    }

    /**
     * Validate tabindex is set.
     */
    private function checkTabindex(string $html, int $expected): ?string
    {
        if (!preg_match('/tabindex="(-?\d+)"/', $html, $m)) {
            return "Missing tabindex attribute (expected: {$expected})";
        }
        if ((int)$m[1] !== $expected) {
            return "tabindex is '{$m[1]}', expected '{$expected}'";
        }
        return null;
    }

    /**
     * Validate that scope="col" is present on table headers.
     */
    private function checkTableHasScopeCol(string $html, bool $expected): ?string
    {
        if ($expected && !str_contains($html, 'scope="col"')) {
            return 'Table headers missing scope="col"';
        }
        return null;
    }

    /**
     * Validate that cells have data-label attributes.
     */
    private function checkCellsHaveDataLabel(string $html, bool $expected): ?string
    {
        if ($expected && !str_contains($html, 'data-label="')) {
            return 'Table cells missing data-label attribute';
        }
        return null;
    }

    /**
     * Validate clickable table rows expose navigation metadata.
     */
    private function checkClickableRowsHaveDataWbHref(string $html, bool $expected): ?string
    {
        if (!$expected) return null;
        if (preg_match('/<tr\b[^>]*(?:role=\"link\"|tabindex=\"0\")[^>]*>/i', $html)
            && !preg_match('/<tr\b[^>]*data-wb-href=\"[^\"]+\"[^>]*>/i', $html)) {
            return 'Clickable rows missing data-wb-href';
        }
        return null;
    }

    /**
     * Validate populated table rows expose entity IDs.
     */
    private function checkEntityRowsHaveDataWbEntityId(string $html, bool $expected): ?string
    {
        if (!$expected) return null;

        preg_match_all('/<tbody\b[^>]*>(.*?)<\/tbody>/is', $html, $bodies);
        foreach ($bodies[1] ?? [] as $bodyHtml) {
            preg_match_all('/<tr\b[^>]*>.*?<\/tr>/is', $bodyHtml, $rows);
            foreach ($rows[0] ?? [] as $rowHtml) {
                if (str_contains($rowHtml, 'colspan=')) {
                    continue;
                }
                if (!preg_match('/data-wb-entity-id=\"[^\"]+\"/i', $rowHtml)) {
                    return 'Entity rows missing data-wb-entity-id';
                }
            }
        }

        return null;
    }

    /**
     * Validate visible text content.
     */
    private function checkVisibleTextLabel(string $html, bool $expected): ?string
    {
        if ($expected) {
            $text = strip_tags($html);
            if (trim($text) === '') {
                return 'Component has no visible text content';
            }
        }
        return null;
    }

    /**
     * Validate tone badges do not rely on color alone.
     */
    private function checkToneNeverCommunicatedByColorAlone(string $html, bool $expected): ?string
    {
        return $this->checkVisibleTextLabel($html, $expected);
    }

    /**
     * Validate data-wb-tone values against the contract tone set.
     */
    private function checkValidTones(string $html, array $expected): ?string
    {
        preg_match_all('/data-wb-tone=\"([^\"]+)\"/', $html, $matches);
        foreach ($matches[1] ?? [] as $tone) {
            if (!in_array($tone, $expected, true)) {
                return "Invalid tone '{$tone}'";
            }
        }
        return null;
    }

    /**
     * Validate that error links point to fields.
     */
    private function checkErrorLinksPointToFields(string $html, bool $expected): ?string
    {
        if (!$expected) return null;
        preg_match_all('/href="#([^"]+)"/', $html, $links);
        if (empty($links[1])) {
            return 'Error summary has no field links';
        }
        return null;
    }

    /**
     * Validate that error links have data-wb-error-for attribute.
     */
    private function checkErrorLinksHaveDataWbErrorFor(string $html, bool $expected): ?string
    {
        if ($expected && !str_contains($html, 'data-wb-error-for="')) {
            return 'Error links missing data-wb-error-for';
        }
        return null;
    }

    /**
     * Validate has-exactly-one-h1 requirement.
     */
    private function checkHasExactlyOneH1(string $html, bool $expected): ?string
    {
        if (!$expected) return null;
        $count = preg_match_all('/<h1[^>]*>/i', $html);
        if ($count !== 1) {
            return "Expected exactly 1 H1, found {$count}";
        }
        return null;
    }

    /**
     * Validate actions have data-wb-action.
     */
    private function checkActionsHaveDataWbAction(string $html, bool $expected): ?string
    {
        if (!$expected) return null;
        // If there are <button> or <a> elements, they should have data-wb-action
        if (preg_match('/<(button|a)\s[^>]*>/', $html)) {
            if (!str_contains($html, 'data-wb-action="')) {
                return 'Actions missing data-wb-action attribute';
            }
        }
        return null;
    }

    /**
     * Validate POST actions expose their method to Workbench observers.
     */
    private function checkPostActionsHaveDataWbMethod(string $html, bool $expected): ?string
    {
        if (!$expected) return null;
        if (preg_match_all('/<form\b[^>]*method="POST"[^>]*>(.*?)<\/form>/is', $html, $forms)) {
            foreach ($forms[1] as $formBody) {
                if (!str_contains($formBody, 'data-wb-method="POST"')) {
                    return 'POST action missing data-wb-method="POST"';
                }
            }
        }
        return null;
    }

    /**
     * Validate breadcrumb markup uses a navigation landmark when present.
     */
    private function checkBreadcrumbHasNavLandmark(string $html, bool $expected): ?string
    {
        if (!$expected || !str_contains($html, 'aria-label="Breadcrumb"')) {
            return null;
        }
        if (!preg_match('/<nav\b[^>]*aria-label="Breadcrumb"[^>]*>/i', $html)) {
            return 'Breadcrumb missing nav landmark';
        }
        return null;
    }

    /**
     * Validate summary card label text.
     */
    private function checkDisplaysLabel(string $html, bool $expected, array $props = []): ?string
    {
        if (!$expected) return null;
        $label = trim((string)($props['label'] ?? ''));
        if ($label !== '' && !str_contains(strip_tags($html), $label)) {
            return "Missing label text '{$label}'";
        }
        return null;
    }

    /**
     * Validate summary card value text.
     */
    private function checkDisplaysValue(string $html, bool $expected, array $props = []): ?string
    {
        if (!$expected) return null;
        $value = trim((string)($props['value'] ?? ''));
        if ($value !== '' && !str_contains(strip_tags($html), $value)) {
            return "Missing value text '{$value}'";
        }
        return null;
    }

    /**
     * Validate tone metadata is present.
     */
    private function checkHasDataWbTone(string $html, bool $expected): ?string
    {
        if ($expected && !preg_match('/data-wb-tone="[^"]+"/', $html)) {
            return 'Missing data-wb-tone attribute';
        }
        return null;
    }

    /**
     * Validate href cards expose link affordances.
     */
    private function checkClickableWhenHrefPresent(string $html, bool $expected, array $props = []): ?string
    {
        if (!$expected || empty($props['href'])) return null;
        if (!str_contains($html, 'data-wb-href="' . (string)$props['href'] . '"')) {
            return 'Clickable summary card missing data-wb-href';
        }
        if (!str_contains($html, 'role="link"')) {
            return 'Clickable summary card missing role="link"';
        }
        return null;
    }

    /**
     * Behavioral requirements are asserted by browser harnesses; their
     * presence here prevents JSON contract drift from being silently ignored.
     */
    private function checkKeyboardNavigation(string $html, array $expected): ?string { return null; }
    private function checkFocusTrapsWithinListbox(string $html, bool $expected): ?string { return null; }
    private function checkEscapeCloses(string $html, bool $expected): ?string { return null; }
    private function checkFocusTrap(string $html, bool $expected): ?string { return null; }
    private function checkRestoresFocus(string $html, bool $expected): ?string { return null; }
    private function checkCloseOnBackdrop(string $html, bool $expected): ?string { return null; }
    private function checkReceivesFocusOnError(string $html, bool $expected): ?string { return null; }
    private function checkMobileBreakpoint(string $html, string $expected): ?string { return null; }

    /**
     * Convert snake_case or dot-separated to CamelCase.
     */
    private function camelCase(string $key): string
    {
        return str_replace(' ', '', ucwords(str_replace(['_', '.'], ' ', $key)));
    }

    /**
     * Validate HTML of a specific scenario against its component contract.
     *
     * @param string $component Component name
     * @param string $scenarioName Scenario name from scenarios JSON
     * @param string $html Rendered HTML
     * @param array $props Props used
     * @return array<string, string>
     */
    public function validateScenario(string $component, string $scenarioName, string $html, array $props = []): array
    {
        $scenarioPath = defined('BASE_PATH')
            ? BASE_PATH . '/storage/application-profiles/ark-workbench/scenarios/' . $component . '.scenarios.json'
            : __DIR__ . '/../../storage/application-profiles/ark-workbench/scenarios/' . $component . '.scenarios.json';

        $violations = $this->validate($component, $html, $props);

        if (is_file($scenarioPath)) {
            $scenarios = json_decode(file_get_contents($scenarioPath), true);
            $scenario = $scenarios['scenarios'][$scenarioName] ?? null;
            if ($scenario && isset($scenario['expected'])) {
                foreach ($scenario['expected'] as $key => $expected) {
                    if ($key === 'error_count') {
                        preg_match_all('/<li/', $html, $matches);
                        $count = count($matches[0]);
                        if ($count !== $expected) {
                            $violations["scenario.{$key}"] = "Expected {$expected} errors, found {$count}";
                        }
                    }
                    if ($key === 'error_links_have_href') {
                        if ($expected && !preg_match('/href="#[^"]+"/', $html)) {
                            $violations["scenario.{$key}"] = 'Error links missing href attribute';
                        }
                    }
                }
            }
        }

        return $violations;
    }
}
