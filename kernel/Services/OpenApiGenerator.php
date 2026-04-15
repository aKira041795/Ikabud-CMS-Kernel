<?php

declare(strict_types=1);

namespace Ikabud\Kernel\Services;

/**
 * 4.3: OpenAPI 3.0 Spec Generator
 *
 * Reads kernel core routes + module routes and produces an OpenAPI 3.0 JSON document.
 * Routes are classified by prefix into tags (admin, superadmin, auth, public, module-specific).
 */
class OpenApiGenerator
{
    private array $routes;
    private string $title;
    private string $version;

    public function __construct(array $routes, string $title = 'Ikabud Platform API', string $version = '1.0.0')
    {
        $this->routes = $routes;
        $this->title = $title;
        $this->version = $version;
    }

    /**
     * Generate a complete OpenAPI 3.0 spec as a PHP array.
     */
    public function generate(): array
    {
        $paths = [];
        $tags = [];
        $tagIndex = [];

        foreach (['GET', 'POST', 'PUT', 'DELETE', 'PATCH'] as $method) {
            foreach ($this->routes[$method] ?? [] as $pattern => $handler) {
                $openApiPath = $this->convertPatternToOpenApi($pattern);
                $tag = $this->resolveTag($pattern, $handler);

                if (!isset($tagIndex[$tag])) {
                    $tagIndex[$tag] = true;
                    $tags[] = ['name' => $tag, 'description' => ucfirst($tag) . ' endpoints'];
                }

                $operation = $this->buildOperation($method, $pattern, $handler, $tag);

                if (!isset($paths[$openApiPath])) {
                    $paths[$openApiPath] = [];
                }
                $paths[$openApiPath][strtolower($method)] = $operation;
            }
        }

        // Sort paths alphabetically
        ksort($paths);

        // Sort tags
        usort($tags, fn($a, $b) => strcmp($a['name'], $b['name']));

        return [
            'openapi' => '3.0.3',
            'info' => [
                'title' => $this->title,
                'version' => $this->version,
                'description' => 'Auto-generated API specification from route declarations.',
            ],
            'tags' => $tags,
            'paths' => $paths,
            'components' => [
                'securitySchemes' => [
                    'bearerAuth' => [
                        'type' => 'http',
                        'scheme' => 'bearer',
                        'bearerFormat' => 'JWT',
                    ],
                    'cookieAuth' => [
                        'type' => 'apiKey',
                        'in' => 'cookie',
                        'name' => 'auth_token',
                    ],
                ],
            ],
            'security' => [
                ['bearerAuth' => []],
            ],
        ];
    }

    /**
     * Convert route pattern to OpenAPI path (e.g. /api/v1/products/{id}).
     */
    private function convertPatternToOpenApi(string $pattern): string
    {
        // Convert :param or {param} patterns
        return preg_replace('/\{([^}]+)\}/', '{$1}', $pattern) ?? $pattern;
    }

    /**
     * Resolve tag from route pattern and handler.
     */
    private function resolveTag(string $pattern, string $handler): string
    {
        // Module-scoped routes => module tag
        if (str_contains($handler, ':')) {
            $parts = explode(':', $handler);
            return $parts[0];
        }

        if (str_starts_with($pattern, '/api/v1/superadmin/') || str_starts_with($pattern, '/superadmin/')) {
            return 'superadmin';
        }
        if (str_starts_with($pattern, '/api/v1/admin/') || str_starts_with($pattern, '/admin/')) {
            return 'admin';
        }
        if (str_starts_with($pattern, '/api/v1/auth/') || str_starts_with($pattern, '/auth/')) {
            return 'auth';
        }
        if (str_starts_with($pattern, '/api/v1/kernel/') || str_starts_with($pattern, '/kernel/')) {
            return 'kernel';
        }

        return 'public';
    }

    /**
     * Build an operation object for a single route.
     */
    private function buildOperation(string $method, string $pattern, string $handler, string $tag): array
    {
        $operationId = $this->deriveOperationId($handler);
        $summary = $this->deriveSummary($handler);

        $operation = [
            'tags' => [$tag],
            'summary' => $summary,
            'operationId' => $operationId,
            'responses' => [
                '200' => [
                    'description' => 'Successful response',
                ],
            ],
        ];

        // Extract path parameters
        $pathParams = [];
        if (preg_match_all('/\{([^}]+)\}/', $pattern, $matches)) {
            foreach ($matches[1] as $paramName) {
                $pathParams[] = [
                    'name' => $paramName,
                    'in' => 'path',
                    'required' => true,
                    'schema' => ['type' => 'string'],
                ];
            }
            $operation['parameters'] = $pathParams;
        }

        // POST/PUT/DELETE get a request body placeholder
        if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true) && str_starts_with($pattern, '/api/')) {
            $operation['requestBody'] = [
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                        ],
                    ],
                ],
            ];
        }

        // Admin/superadmin routes require auth
        if (str_contains($pattern, '/admin/') || str_contains($pattern, '/superadmin/')) {
            $operation['security'] = [['bearerAuth' => []]];
        }

        // Public auth routes don't require auth
        if (in_array($pattern, ['/auth/login', '/api/v1/auth/login', '/login', '/api/v1/health'], true)) {
            $operation['security'] = [];
        }

        return $operation;
    }

    /**
     * Derive an operationId from the handler string.
     */
    private function deriveOperationId(string $handler): string
    {
        // Module handler: "cms:cmsApiContentList" => "cms_cmsApiContentList"
        if (str_contains($handler, ':')) {
            return str_replace(':', '_', $handler);
        }
        return $handler;
    }

    /**
     * Derive a human-readable summary from handler name.
     */
    private function deriveSummary(string $handler): string
    {
        $name = $handler;
        if (str_contains($name, ':')) {
            $name = explode(':', $name)[1];
        }

        // CamelCase / snake_case to words:  "apiAdminCreateUser" => "Api Admin Create User"
        $words = preg_replace('/([a-z])([A-Z])/', '$1 $2', $name) ?? $name;
        $words = preg_replace('/([A-Z]+)([A-Z][a-z])/', '$1 $2', $words) ?? $words;

        return ucfirst(trim($words));
    }

    /**
     * Output as JSON string.
     */
    public function toJson(): string
    {
        return json_encode($this->generate(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}';
    }
}
