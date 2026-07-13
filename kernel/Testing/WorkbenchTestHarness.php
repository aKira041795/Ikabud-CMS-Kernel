<?php

declare(strict_types=1);

namespace Ikabud\Kernel\Testing;

/**
 * WorkbenchTestHarness — reusable UI testing utility for ARK Workbench modules.
 *
 * Provides a fluent API for browser-level tests: login, navigate, locate
 * entities, invoke actions, assert state, and capture screenshots.
 *
 * Usage (PHP integration test):
 *   $harness = new WorkbenchTestHarness($baseUrl);
 *   $harness->loginAs('admin', 'pAl123456');
 *   $harness->openPage('/admin/project-audit-ledger');
 *   $harness->assertSee('Dashboard');
 *   $harness->assertEntityPresent('pal_project', '2');
 *
 * Usage (Playwright — use the data-wb-* selectors directly):
 *   await page.locator('[data-wb-component="app-shell"]').waitFor();
 *   await page.locator('[data-wb-action="approve"]').click();
 *   await page.locator('[data-wb-entity="pal_project"][data-wb-entity-id="2"]').waitFor();
 *
 * @package Ikabud\Kernel\Testing
 */
final class WorkbenchTestHarness
{
    private string $baseUrl;
    private string $sessionCookie = '';

    /** @var array<string, string> Current page context */
    private array $context = [];

    public function __construct(string $baseUrl = 'http://palsystem.test')
    {
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    // ── Authentication ───────────────────────────────────────────

    /**
     * Log in as a PAL user via the login form.
     *
     * @param string $username
     * @param string $password
     * @return $this
     */
    public function loginAs(string $username, string $password): self
    {
        $ch = curl_init($this->baseUrl . '/project-audit-ledger/login');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'username' => $username,
                'password' => $password,
            ]),
            CURLOPT_HEADER => true,
            CURLOPT_COOKIESESSION => true,
        ]);
        $response = curl_exec($ch);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $headers = substr($response, 0, $headerSize);

        // Extract session cookie
        if (preg_match('/Set-Cookie:\s*(PHPSESSID=[^;]+)/i', $headers, $m)) {
            $this->sessionCookie = $m[1];
        }

        curl_close($ch);
        return $this;
    }

    /**
     * Get cURL handle with session cookie set.
     *
     * @return array{cURL handle, array}
     */
    private function request(string $method, string $path, array $data = []): array
    {
        $url = $this->baseUrl . $path;
        $ch = curl_init($url);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_COOKIE => $this->sessionCookie,
        ];

        if ($method === 'POST') {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = http_build_query($data);
        }

        curl_setopt_array($ch, $options);
        $response = curl_exec($ch);
        $info = curl_getinfo($ch);
        curl_close($ch);

        return [$response, $info];
    }

    // ── Navigation ───────────────────────────────────────────────

    /**
     * Open a page and extract context from data-wb-* attributes.
     *
     * @param string $path Absolute path (e.g., "/admin/project-audit-ledger")
     * @return $this
     */
    public function openPage(string $path): self
    {
        [$response, $info] = $this->request('GET', $path);
        $body = $this->extractBody($response);

        $this->context = [
            'url' => $path,
            'status' => (string)($info['http_code'] ?? ''),
            'body' => $body,
        ];

        // Extract data-wb-page-family from main element
        if (preg_match('/data-wb-page-family="([^"]+)"/', $body, $m)) {
            $this->context['page_family'] = $m[1];
        }

        return $this;
    }

    // ── Assertions ───────────────────────────────────────────────

    /**
     * Assert that the response body contains a given string.
     */
    public function assertSee(string $text): self
    {
        $body = $this->context['body'] ?? '';
        assertTrue(str_contains($body, $text), "Expected text not found: {$text}");
        return $this;
    }

    /**
     * Assert that a data-wb-component is present.
     */
    public function assertComponent(string $component): self
    {
        $body = $this->context['body'] ?? '';
        assertTrue(
            str_contains($body, "data-wb-component=\"{$component}\""),
            "Workbench component not found: {$component}"
        );
        return $this;
    }

    /**
     * Assert that an entity with given type/id is present.
     */
    public function assertEntityPresent(string $entityType, string $entityId): self
    {
        $body = $this->context['body'] ?? '';
        $found = str_contains($body, "data-wb-entity=\"{$entityType}\"")
            && str_contains($body, "data-wb-entity-id=\"{$entityId}\"");
        assertTrue($found, "Entity not found: {$entityType}#{$entityId}");
        return $this;
    }

    /**
     * Assert that an action button is present.
     */
    public function assertActionPresent(string $actionKey): self
    {
        $body = $this->context['body'] ?? '';
        assertTrue(
            str_contains($body, "data-wb-action=\"{$actionKey}\""),
            "Action not found: {$actionKey}"
        );
        return $this;
    }

    /**
     * Assert that an action button is NOT present.
     */
    public function assertActionMissing(string $actionKey): self
    {
        $body = $this->context['body'] ?? '';
        assertFalse(
            str_contains($body, "data-wb-action=\"{$actionKey}\""),
            "Action should not be present: {$actionKey}"
        );
        return $this;
    }

    /**
     * Assert that the HTTP status matches.
     */
    public function assertStatus(int $code): self
    {
        $actual = (int)($this->context['status'] ?? 0);
        assertTrue($actual === $code, "Expected status {$code}, got {$actual}");
        return $this;
    }

    /**
     * Assert the current page family.
     */
    public function assertPageFamily(string $family): self
    {
        $actual = $this->context['page_family'] ?? '';
        assertTrue(
            $actual === $family,
            "Expected page family '{$family}', got '{$actual}'"
        );
        return $this;
    }

    /**
     * Submit a POST action and assert the result.
     */
    public function submitAction(string $path, array $data = []): self
    {
        [$response, $info] = $this->request('POST', $path, $data);
        $this->context = [
            'url' => $path,
            'status' => (string)($info['http_code'] ?? ''),
            'body' => $this->extractBody($response),
        ];
        return $this;
    }

    // ── Getters ──────────────────────────────────────────────────

    public function getContext(): array
    {
        return $this->context;
    }

    public function getBody(): string
    {
        return $this->context['body'] ?? '';
    }

    // ── Helpers ──────────────────────────────────────────────────

    /**
     * Extract response body from cURL response (strip headers).
     */
    private function extractBody(string $response): string
    {
        $headerSize = 0;
        // Find the double CRLF that separates headers from body
        $pos = strpos($response, "\r\n\r\n");
        if ($pos !== false) {
            return substr($response, $pos + 4);
        }
        return $response;
    }

    /**
     * Create a scenario fixture with seeded data.
     *
     * @param array $data Key-value pairs to seed
     * @return array{entities: array, expected: array}
     */
    public static function scenario(string $name, array $overrides = []): array
    {
        $scenarios = [
            'empty' => [
                'entities' => [],
                'expected' => [
                    'count' => 0,
                    'status' => 'empty',
                ],
            ],
            'basic' => [
                'entities' => [
                    'project' => [
                        'id' => 1,
                        'project_id' => 'P-20260713-000001',
                        'title' => 'Test Project',
                        'client_name' => 'Test Client',
                        'contract_amount' => 5000000,
                        'status' => 'ongoing',
                        'start_date' => '2026-07-13',
                    ],
                ],
                'expected' => [
                    'count' => 1,
                    'status' => 'ongoing',
                ],
            ],
        ];

        $scenario = $scenarios[$name] ?? $scenarios['basic'];
        if (!empty($overrides)) {
            $scenario = array_merge_recursive($scenario, $overrides);
        }

        return $scenario;
    }
}

// Fallback assertion helpers for non-PHPUnit environments
if (!function_exists('assertTrue')) {
    function assertTrue(bool $condition, string $message = ''): void
    {
        if (!$condition) {
            throw new \RuntimeException("Assertion failed: {$message}");
        }
    }
}
if (!function_exists('assertFalse')) {
    function assertFalse(bool $condition, string $message = ''): void
    {
        if ($condition) {
            throw new \RuntimeException("Assertion failed: {$message}");
        }
    }
}
