<?php
/**
 * Security Manager
 * 
 * Handles syscall permissions, rate limiting, input validation, and security policies
 */

namespace IkabudKernel\Core;

use PDO;
use Exception;

class SecurityManager
{
    private PDO $db;
    private array $syscallPermissions = [];
    private array $rateLimits = [];
    private array $requestCounts = [];
    private array $roleHierarchy = [];
    private bool $auditMode = false;
    
    // Default syscall permissions (role-based)
    private const DEFAULT_PERMISSIONS = [
        // Content syscalls
        'content.fetch' => ['guest', 'user', 'editor', 'admin'],
        'content.create' => ['editor', 'admin'],
        'content.update' => ['editor', 'admin'],
        'content.delete' => ['admin'],
        
        // Database syscalls
        'db.query' => ['admin', 'developer'],
        'db.insert' => ['admin', 'developer'],
        'db.update' => ['admin', 'developer'],
        'db.delete' => ['admin'],
        
        // HTTP syscalls
        'http.get' => ['user', 'editor', 'admin', 'api'],
        'http.post' => ['editor', 'admin', 'api'],
        
        // Asset syscalls
        'asset.enqueue' => ['guest', 'user', 'editor', 'admin'],
        
        // Theme syscalls
        'theme.render' => ['guest', 'user', 'editor', 'admin'],
        
        // Cache syscalls
        'cache.get' => ['guest', 'user', 'editor', 'admin'],
        'cache.set' => ['editor', 'admin'],
        'cache.clear' => ['admin'],
        
        // Instance syscalls
        'instance.create' => ['admin'],
        'instance.delete' => ['admin'],
        'instance.update' => ['admin']
    ];
    
    // Rate limits per syscall (requests per minute)
    private const DEFAULT_RATE_LIMITS = [
        'content.fetch' => 60,
        'content.create' => 10,
        'content.update' => 20,
        'content.delete' => 5,
        'db.query' => 30,
        'http.get' => 30,
        'http.post' => 10,
        'cache.clear' => 5
    ];
    
    public function __construct(PDO $db, bool $auditMode = false)
    {
        $this->db = $db;
        $this->syscallPermissions = self::DEFAULT_PERMISSIONS;
        $this->rateLimits = self::DEFAULT_RATE_LIMITS;
        $this->auditMode = $auditMode;
        
        // Default role hierarchy
        $this->roleHierarchy = [
            'admin' => ['editor', 'user', 'guest', 'developer', 'api'],
            'editor' => ['user', 'guest'],
            'developer' => ['user', 'guest'],
            'user' => ['guest']
        ];
    }
    
    /**
     * Set role hierarchy
     */
    public function setRoleHierarchy(array $hierarchy): void
    {
        $this->roleHierarchy = $hierarchy;
    }
    
    /**
     * Get all roles inherited by a role
     */
    private function getInheritedRoles(string $role): array
    {
        $roles = [$role];
        
        if (isset($this->roleHierarchy[$role])) {
            foreach ($this->roleHierarchy[$role] as $inheritedRole) {
                $roles = array_merge($roles, $this->getInheritedRoles($inheritedRole));
            }
        }
        
        return array_unique($roles);
    }
    
    /**
     * Check if role has permission for syscall (with hierarchy support)
     */
    public function checkPermission(string $syscall, ?string $role = null): bool
    {
        // If no permissions defined, allow by default (backward compatibility)
        if (!isset($this->syscallPermissions[$syscall])) {
            return true;
        }
        
        // If no role provided, deny
        if ($role === null) {
            return false;
        }
        
        // Get all roles including inherited ones
        $allRoles = $this->getInheritedRoles($role);
        
        // Check if any of the roles (including inherited) have permission
        $allowedRoles = $this->syscallPermissions[$syscall];
        return !empty(array_intersect($allRoles, $allowedRoles));
    }
    
    /**
     * Check rate limit for syscall
     */
    public function checkRateLimit(string $syscall, string $identifier): bool
    {
        // If no rate limit defined, allow
        if (!isset($this->rateLimits[$syscall])) {
            return true;
        }
        
        $key = "{$syscall}:{$identifier}";
        $currentMinute = floor(time() / 60);
        
        // Initialize counter if needed
        if (!isset($this->requestCounts[$key])) {
            $this->requestCounts[$key] = [
                'minute' => $currentMinute,
                'count' => 0
            ];
        }
        
        // Reset counter if new minute
        if ($this->requestCounts[$key]['minute'] !== $currentMinute) {
            $this->requestCounts[$key] = [
                'minute' => $currentMinute,
                'count' => 0
            ];
        }
        
        // Check limit
        if ($this->requestCounts[$key]['count'] >= $this->rateLimits[$syscall]) {
            $this->logRateLimitExceeded($syscall, $identifier);
            
            // In audit mode, log but don't block
            if ($this->auditMode) {
                $this->requestCounts[$key]['count']++;
                return true;
            }
            
            return false;
        }
        
        // Increment counter
        $this->requestCounts[$key]['count']++;
        
        return true;
    }
    
    /**
     * Validate syscall arguments
     */
    public function validateArgs(string $syscall, array $args): array
    {
        // Sanitize all string values
        array_walk_recursive($args, function(&$value) {
            if (is_string($value)) {
                $value = $this->sanitizeInput($value);
            }
        });
        
        // Syscall-specific validation
        switch ($syscall) {
            case 'content.fetch':
                $this->validateContentFetchArgs($args);
                break;
            case 'content.create':
            case 'content.update':
                $this->validateContentWriteArgs($args);
                break;
            case 'db.query':
                $this->validateDbQueryArgs($args);
                break;
            case 'http.get':
            case 'http.post':
                $this->validateHttpArgs($args);
                break;
        }
        
        return $args;
    }
    
    /**
     * Sanitize input string
     */
    private function sanitizeInput(string $input): string
    {
        // Remove null bytes
        $input = str_replace("\0", '', $input);
        
        // Trim whitespace
        $input = trim($input);
        
        return $input;
    }
    
    /**
     * Validate content fetch arguments
     */
    private function validateContentFetchArgs(array $args): void
    {
        if (!isset($args['instance_id']) || !is_string($args['instance_id'])) {
            throw new Exception("Invalid instance_id");
        }
        
        if (isset($args['post_id']) && !is_numeric($args['post_id'])) {
            throw new Exception("Invalid post_id");
        }
    }
    
    /**
     * Validate content write arguments
     */
    private function validateContentWriteArgs(array $args): void
    {
        if (!isset($args['instance_id']) || !is_string($args['instance_id'])) {
            throw new Exception("Invalid instance_id");
        }
        
        if (!isset($args['content']) || !is_array($args['content'])) {
            throw new Exception("Invalid content");
        }
        
        // Validate content fields
        $content = $args['content'];
        
        if (isset($content['title']) && strlen($content['title']) > 255) {
            throw new Exception("Title too long (max 255 characters)");
        }
        
        if (isset($content['body']) && strlen($content['body']) > 1000000) {
            throw new Exception("Body too long (max 1MB)");
        }
    }
    
    /**
     * Validate database query arguments
     */
    private function validateDbQueryArgs(array $args): void
    {
        if (!isset($args['query']) || !is_string($args['query'])) {
            throw new Exception("Invalid query");
        }
        
        // Check for dangerous SQL patterns
        $dangerousPatterns = [
            '/DROP\s+TABLE/i',
            '/DROP\s+DATABASE/i',
            '/TRUNCATE/i',
            '/ALTER\s+TABLE/i',
            '/CREATE\s+TABLE/i',
            '/GRANT/i',
            '/REVOKE/i'
        ];
        
        foreach ($dangerousPatterns as $pattern) {
            if (preg_match($pattern, $args['query'])) {
                throw new Exception("Dangerous SQL operation detected");
            }
        }
    }
    
    /**
     * Validate HTTP arguments
     */
    private function validateHttpArgs(array $args): void
    {
        if (!isset($args['url']) || !is_string($args['url'])) {
            throw new Exception("Invalid URL");
        }
        
        // Validate URL format
        if (!filter_var($args['url'], FILTER_VALIDATE_URL)) {
            throw new Exception("Invalid URL format");
        }
        
        // Check for SSRF attempts (internal IPs)
        $host = parse_url($args['url'], PHP_URL_HOST);
        if ($this->isInternalIP($host)) {
            throw new Exception("Access to internal resources not allowed");
        }
    }
    
    /**
     * Check if host is internal IP
     */
    private function isInternalIP(string $host): bool
    {
        $ip = gethostbyname($host);
        
        // Check for private IP ranges
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) === false;
    }
    
    /**
     * Set custom permission for syscall
     */
    public function setPermission(string $syscall, array $roles): void
    {
        $this->syscallPermissions[$syscall] = $roles;
    }
    
    /**
     * Set rate limit for syscall
     */
    public function setRateLimit(string $syscall, int $requestsPerMinute): void
    {
        $this->rateLimits[$syscall] = $requestsPerMinute;
    }
    
    /**
     * Log rate limit exceeded
     */
    private function logRateLimitExceeded(string $syscall, string $identifier): void
    {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO kernel_security_log 
                (event_type, syscall_name, identifier, details, created_at)
                VALUES ('rate_limit_exceeded', ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $syscall,
                $identifier,
                json_encode(['limit' => $this->rateLimits[$syscall]])
            ]);
        } catch (Exception $e) {
            error_log("Failed to log rate limit: " . $e->getMessage());
        }
    }
    
    /**
     * Log security event
     */
    public function logSecurityEvent(string $eventType, array $details): void
    {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO kernel_security_log 
                (event_type, details, created_at)
                VALUES (?, ?, NOW())
            ");
            $stmt->execute([
                $eventType,
                json_encode($details)
            ]);
        } catch (Exception $e) {
            error_log("Failed to log security event: " . $e->getMessage());
        }
    }
    
    /**
     * Get security statistics
     */
    public function getStats(): array
    {
        return [
            'active_rate_limits' => count($this->requestCounts),
            'syscalls_protected' => count($this->syscallPermissions),
            'rate_limits_configured' => count($this->rateLimits)
        ];
    }
}
