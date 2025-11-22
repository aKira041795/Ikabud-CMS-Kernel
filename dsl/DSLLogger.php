<?php
/**
 * DSL Logger - Error handling and debugging for DSL operations
 * 
 * Provides centralized logging for DSL compilation, execution, and rendering
 * Supports multiple log levels and context-aware logging
 * 
 * @version 1.2.0
 */

namespace IkabudKernel\DSL;

class DSLLogger
{
    private static array $logs = [];
    private static bool $enabled = false;
    private static string $logLevel = 'info';
    
    // Log levels
    const LEVEL_DEBUG = 'debug';
    const LEVEL_INFO = 'info';
    const LEVEL_WARNING = 'warning';
    const LEVEL_ERROR = 'error';
    const LEVEL_CRITICAL = 'critical';
    
    private static array $levelPriority = [
        'debug' => 0,
        'info' => 1,
        'warning' => 2,
        'error' => 3,
        'critical' => 4
    ];
    
    /**
     * Enable logging
     */
    public static function enable(): void
    {
        self::$enabled = true;
    }
    
    /**
     * Disable logging
     */
    public static function disable(): void
    {
        self::$enabled = false;
    }
    
    /**
     * Set log level
     */
    public static function setLevel(string $level): void
    {
        if (isset(self::$levelPriority[$level])) {
            self::$logLevel = $level;
        }
    }
    
    /**
     * Log a message
     */
    public static function log(string $level, string $message, array $context = []): void
    {
        if (!self::$enabled) {
            return;
        }
        
        // Check if this level should be logged
        if (self::$levelPriority[$level] < self::$levelPriority[self::$logLevel]) {
            return;
        }
        
        self::$logs[] = [
            'timestamp' => microtime(true),
            'datetime' => date('Y-m-d H:i:s'),
            'level' => $level,
            'message' => $message,
            'context' => $context,
            'memory' => memory_get_usage(true),
            'backtrace' => self::getSimpleBacktrace()
        ];
    }
    
    /**
     * Log debug message
     */
    public static function debug(string $message, array $context = []): void
    {
        self::log(self::LEVEL_DEBUG, $message, $context);
    }
    
    /**
     * Log info message
     */
    public static function info(string $message, array $context = []): void
    {
        self::log(self::LEVEL_INFO, $message, $context);
    }
    
    /**
     * Log warning message
     */
    public static function warning(string $message, array $context = []): void
    {
        self::log(self::LEVEL_WARNING, $message, $context);
    }
    
    /**
     * Log error message
     */
    public static function error(string $message, array $context = []): void
    {
        self::log(self::LEVEL_ERROR, $message, $context);
    }
    
    /**
     * Log critical message
     */
    public static function critical(string $message, array $context = []): void
    {
        self::log(self::LEVEL_CRITICAL, $message, $context);
    }
    
    /**
     * Get all logs
     */
    public static function getLogs(): array
    {
        return self::$logs;
    }
    
    /**
     * Get logs by level
     */
    public static function getLogsByLevel(string $level): array
    {
        return array_filter(self::$logs, function($log) use ($level) {
            return $log['level'] === $level;
        });
    }
    
    /**
     * Get formatted logs
     */
    public static function getFormattedLogs(): string
    {
        $output = '';
        
        foreach (self::$logs as $log) {
            $output .= sprintf(
                "[%s] %s: %s\n",
                $log['datetime'],
                strtoupper($log['level']),
                $log['message']
            );
            
            if (!empty($log['context'])) {
                $output .= "  Context: " . json_encode($log['context'], JSON_PRETTY_PRINT) . "\n";
            }
        }
        
        return $output;
    }
    
    /**
     * Clear all logs
     */
    public static function clear(): void
    {
        self::$logs = [];
    }
    
    /**
     * Get statistics
     */
    public static function getStats(): array
    {
        $stats = [
            'total' => count(self::$logs),
            'by_level' => []
        ];
        
        foreach (self::$logs as $log) {
            $level = $log['level'];
            if (!isset($stats['by_level'][$level])) {
                $stats['by_level'][$level] = 0;
            }
            $stats['by_level'][$level]++;
        }
        
        return $stats;
    }
    
    /**
     * Export logs to file
     */
    public static function exportToFile(string $filepath): bool
    {
        try {
            $content = self::getFormattedLogs();
            return file_put_contents($filepath, $content) !== false;
        } catch (\Exception $e) {
            return false;
        }
    }
    
    /**
     * Get simple backtrace (last 3 calls)
     */
    private static function getSimpleBacktrace(): array
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5);
        $simple = [];
        
        foreach (array_slice($trace, 2, 3) as $item) {
            $simple[] = sprintf(
                '%s::%s() in %s:%d',
                $item['class'] ?? '',
                $item['function'] ?? '',
                basename($item['file'] ?? ''),
                $item['line'] ?? 0
            );
        }
        
        return $simple;
    }
    
    /**
     * Check if logging is enabled
     */
    public static function isEnabled(): bool
    {
        return self::$enabled;
    }
}
