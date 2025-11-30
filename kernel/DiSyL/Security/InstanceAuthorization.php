<?php
/**
 * DiSyL Instance Authorization
 * 
 * Controls which instances can query data from other instances.
 * Prevents unauthorized cross-instance data access.
 * 
 * @package IkabudKernel\Core\DiSyL\Security
 * @version 0.6.0
 */

namespace IkabudKernel\Core\DiSyL\Security;

class InstanceAuthorization
{
    /** @var array Permission matrix: source => [allowed targets] */
    private static array $permissions = [];
    
    /** @var array Global allowed content types */
    private static array $allowedTypes = ['post', 'page', 'article', 'node', 'product'];
    
    /** @var int Maximum items per query */
    private static int $maxLimit = 100;
    
    /** @var bool Whether authorization is enabled */
    private static bool $enabled = true;
    
    /** @var string Path to permissions config file */
    private static ?string $configPath = null;
    
    /**
     * Initialize authorization with config file
     * 
     * @param string|null $configPath Path to permissions config
     */
    public static function init(?string $configPath = null): void
    {
        self::$configPath = $configPath ?? dirname(__DIR__, 3) . '/config/cross-instance-permissions.php';
        self::loadConfig();
    }
    
    /**
     * Load permissions from config file
     */
    private static function loadConfig(): void
    {
        if (self::$configPath && file_exists(self::$configPath)) {
            $config = include self::$configPath;
            
            if (isset($config['permissions'])) {
                self::$permissions = $config['permissions'];
            }
            if (isset($config['allowed_types'])) {
                self::$allowedTypes = $config['allowed_types'];
            }
            if (isset($config['max_limit'])) {
                self::$maxLimit = (int)$config['max_limit'];
            }
            if (isset($config['enabled'])) {
                self::$enabled = (bool)$config['enabled'];
            }
        }
    }
    
    /**
     * Enable or disable authorization
     * 
     * @param bool $enabled
     */
    public static function setEnabled(bool $enabled): void
    {
        self::$enabled = $enabled;
    }
    
    /**
     * Check if authorization is enabled
     * 
     * @return bool
     */
    public static function isEnabled(): bool
    {
        return self::$enabled;
    }
    
    /**
     * Set permissions for an instance
     * 
     * @param string $sourceInstance The instance making the query
     * @param array $allowedTargets Array of instance IDs this source can query
     * @param array $allowedTypes Optional content types allowed (default: all)
     * @param int|null $maxLimit Optional max items per query
     */
    public static function setPermissions(
        string $sourceInstance,
        array $allowedTargets,
        array $allowedTypes = [],
        ?int $maxLimit = null
    ): void {
        self::$permissions[$sourceInstance] = [
            'targets' => $allowedTargets,
            'types' => $allowedTypes ?: self::$allowedTypes,
            'max_limit' => $maxLimit ?? self::$maxLimit
        ];
    }
    
    /**
     * Grant all instances access to each other (development mode)
     * WARNING: Only use in development environments
     */
    public static function allowAll(): void
    {
        self::$permissions = ['*' => ['targets' => ['*']]];
    }
    
    /**
     * Check if a cross-instance query is authorized
     * 
     * @param string $sourceInstance Instance making the query
     * @param string $targetInstance Instance being queried
     * @param string $contentType Content type being requested
     * @param int $limit Number of items requested
     * @return AuthorizationResult
     */
    public static function authorize(
        string $sourceInstance,
        string $targetInstance,
        string $contentType,
        int $limit
    ): AuthorizationResult {
        // If authorization is disabled, allow all
        if (!self::$enabled) {
            return new AuthorizationResult(true);
        }
        
        // Same instance always allowed
        if ($sourceInstance === $targetInstance) {
            return new AuthorizationResult(true);
        }
        
        // Check wildcard permission
        if (isset(self::$permissions['*'])) {
            $wildcardPerm = self::$permissions['*'];
            if (in_array('*', $wildcardPerm['targets'] ?? [])) {
                return new AuthorizationResult(true);
            }
        }
        
        // Check specific permissions
        if (!isset(self::$permissions[$sourceInstance])) {
            return new AuthorizationResult(
                false,
                "Instance '{$sourceInstance}' has no cross-instance permissions configured"
            );
        }
        
        $perms = self::$permissions[$sourceInstance];
        
        // Check target instance
        $allowedTargets = $perms['targets'] ?? [];
        if (!in_array('*', $allowedTargets) && !in_array($targetInstance, $allowedTargets)) {
            return new AuthorizationResult(
                false,
                "Instance '{$sourceInstance}' is not authorized to query '{$targetInstance}'"
            );
        }
        
        // Check content type
        $allowedTypes = $perms['types'] ?? self::$allowedTypes;
        if (!in_array($contentType, $allowedTypes)) {
            return new AuthorizationResult(
                false,
                "Content type '{$contentType}' is not allowed for cross-instance queries"
            );
        }
        
        // Check limit
        $maxLimit = $perms['max_limit'] ?? self::$maxLimit;
        if ($limit > $maxLimit) {
            return new AuthorizationResult(
                false,
                "Query limit {$limit} exceeds maximum allowed ({$maxLimit})",
                ['adjusted_limit' => $maxLimit]
            );
        }
        
        return new AuthorizationResult(true);
    }
    
    /**
     * Get current permissions for an instance
     * 
     * @param string $instanceId
     * @return array|null
     */
    public static function getPermissions(string $instanceId): ?array
    {
        return self::$permissions[$instanceId] ?? null;
    }
    
    /**
     * Get all configured permissions
     * 
     * @return array
     */
    public static function getAllPermissions(): array
    {
        return self::$permissions;
    }
    
    /**
     * Clear all permissions
     */
    public static function clearPermissions(): void
    {
        self::$permissions = [];
    }
    
    /**
     * Set global allowed content types
     * 
     * @param array $types
     */
    public static function setAllowedTypes(array $types): void
    {
        self::$allowedTypes = $types;
    }
    
    /**
     * Set global max limit
     * 
     * @param int $limit
     */
    public static function setMaxLimit(int $limit): void
    {
        self::$maxLimit = $limit;
    }
}

/**
 * Authorization result object
 */
class AuthorizationResult
{
    public bool $authorized;
    public ?string $reason;
    public array $metadata;
    
    public function __construct(bool $authorized, ?string $reason = null, array $metadata = [])
    {
        $this->authorized = $authorized;
        $this->reason = $reason;
        $this->metadata = $metadata;
    }
    
    public function isAuthorized(): bool
    {
        return $this->authorized;
    }
    
    public function getReason(): ?string
    {
        return $this->reason;
    }
    
    public function getMetadata(): array
    {
        return $this->metadata;
    }
}
