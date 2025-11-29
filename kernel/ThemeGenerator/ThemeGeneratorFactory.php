<?php
/**
 * Theme Generator Factory
 * 
 * Factory for creating CMS-specific theme generators.
 * Register new generators here to extend platform support.
 * 
 * @package IkabudKernel\ThemeGenerator
 * @version 1.0.0
 */

namespace IkabudKernel\ThemeGenerator;

// Load dependencies
require_once __DIR__ . '/ThemeGeneratorInterface.php';
require_once __DIR__ . '/AbstractThemeGenerator.php';
require_once __DIR__ . '/Generators/WordPressGenerator.php';
require_once __DIR__ . '/Generators/JoomlaGenerator.php';
require_once __DIR__ . '/Generators/DrupalGenerator.php';
require_once __DIR__ . '/Generators/NativeGenerator.php';

class ThemeGeneratorFactory
{
    /**
     * Registered generators
     * 
     * @var array<string, class-string<ThemeGeneratorInterface>>
     */
    private static array $generators = [
        'wordpress' => Generators\WordPressGenerator::class,
        'joomla' => Generators\JoomlaGenerator::class,
        'drupal' => Generators\DrupalGenerator::class,
        'native' => Generators\NativeGenerator::class,
    ];
    
    /**
     * Create a generator for the specified CMS
     * 
     * @param string $cms CMS identifier
     * @return ThemeGeneratorInterface|null Generator instance or null if not found
     */
    public static function create(string $cms): ?ThemeGeneratorInterface
    {
        $cms = strtolower($cms);
        
        if (!isset(self::$generators[$cms])) {
            return null;
        }
        
        $class = self::$generators[$cms];
        return new $class();
    }
    
    /**
     * Register a custom generator
     * 
     * Use this to add support for additional CMS platforms:
     * 
     * ```php
     * ThemeGeneratorFactory::register('myplatform', MyPlatformGenerator::class);
     * ```
     * 
     * @param string $cms CMS identifier
     * @param class-string<ThemeGeneratorInterface> $generatorClass Generator class name
     */
    public static function register(string $cms, string $generatorClass): void
    {
        self::$generators[strtolower($cms)] = $generatorClass;
    }
    
    /**
     * Check if a CMS is supported
     * 
     * @param string $cms CMS identifier
     * @return bool True if supported
     */
    public static function isSupported(string $cms): bool
    {
        return isset(self::$generators[strtolower($cms)]);
    }
    
    /**
     * Get all supported CMS platforms
     * 
     * @return array<string, array> CMS info with id, name, and features
     */
    public static function getSupportedPlatforms(): array
    {
        $platforms = [];
        
        foreach (self::$generators as $cms => $class) {
            $generator = new $class();
            $platforms[$cms] = [
                'id' => $generator->getCmsId(),
                'name' => $generator->getCmsName(),
                'features' => $generator->getSupportedFeatures(),
            ];
        }
        
        return $platforms;
    }
    
    /**
     * Unregister a generator (useful for testing)
     * 
     * @param string $cms CMS identifier
     */
    public static function unregister(string $cms): void
    {
        unset(self::$generators[strtolower($cms)]);
    }
}
