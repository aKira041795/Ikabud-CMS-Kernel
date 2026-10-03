<?php
/**
 * DiSyL v4.0 Compiled Template Base Class
 * 
 * Base class for compiled templates with runtime helpers.
 * 
 * @package Ikabud\Kernel\DiSyL\Compiler
 * @version 4.0.0
 */

namespace Ikabud\Kernel\DiSyL\Compiler;

use Ikabud\Kernel\DiSyL\v4\RenderContext;
use Ikabud\Kernel\DiSyL\v4\FilterRegistry;
use Ikabud\Kernel\DiSyL\v4\FunctionRegistry;
use Ikabud\Kernel\DiSyL\v4\AST\DocumentNode;
use Ikabud\Kernel\DiSyL\CMS\CMSAdapterInterface;
use Ikabud\Kernel\DiSyL\CMS\NullAdapter;
use Ikabud\Kernel\DiSyL\Component\ExtendsProcessor;

/**
 * Base class for compiled templates
 */
abstract class CompiledTemplate
{
    protected CMSAdapterInterface $cms;
    protected FilterRegistry $filters;
    /** @var callable|null */
    protected $templateLoader = null;
    /** @var callable|null A non-logging loader that returns null for a missing parent. */
    protected $inheritanceLoader = null;
    /** @var callable|null */
    protected $errorHandler = null;
    /** @var array<string, true> Active compiled include names for cycle detection. */
    private static array $includeStack = [];
    private const MAX_INCLUDE_DEPTH = 20;
    
    public function __construct(
        ?CMSAdapterInterface $cms = null,
        ?FilterRegistry $filters = null
    ) {
        $this->cms = $cms ?? new NullAdapter();
        $this->filters = $filters ?? new FilterRegistry();
    }
    
    /**
     * Set CMS adapter
     */
    public function setCMS(CMSAdapterInterface $cms): void
    {
        $this->cms = $cms;
    }
    
    /**
     * Set Filter Registry
     */
    public function setFilters(FilterRegistry $filters): void
    {
        $this->filters = $filters;
    }
    
    /**
     * Set template loader for includes
     */
    public function setTemplateLoader(callable $loader): void
    {
        $this->templateLoader = $loader;
    }

    public function setInheritanceLoader(callable $loader): void
    {
        $this->inheritanceLoader = $loader;
    }

    public function setErrorHandler(callable $handler): void
    {
        $this->errorHandler = $handler;
    }
    
    /**
     * Render the template
     */
    abstract public function render(RenderContext $ctx): string;
    
    /**
     * Execute the template with variables
     */
    public function execute(array $variables = []): string
    {
        $ctx = new RenderContext($variables);
        return $this->render($ctx);
    }

    /**
     * Execute using a pre-built RenderContext (for extends chain handling).
     */
    public function executeRaw(RenderContext $ctx): string
    {
        return $this->render($ctx);
    }

    /**
     * Render this template and resolve its complete inheritance chain.
     *
     * Each successful parent replaces the current template output, matching
     * interpreted inheritance. Includes call this method at their own boundary,
     * preventing an included template's {extends} from escaping into its host.
     */
    public function executeWithInheritance(RenderContext $ctx): string
    {
        $result = $this->render($ctx);
        $seen = [];
        $depth = 0;
        $loader = $this->inheritanceLoader ?? $this->templateLoader;
        $maxDepth = ExtendsProcessor::extendsChainMax();

        while (($parentName = $ctx->getParentTemplate()) !== null) {
            if ($depth >= $maxDepth) {
                $this->reportError('Extends chain depth exceeded maximum (' . $maxDepth . ')');
                $ctx->setParentTemplate(null);
                break;
            }

            $key = trim($parentName);
            if (isset($seen[$key])) {
                $this->reportError("Circular {extends} detected: \"{$parentName}\"");
                $ctx->setParentTemplate(null);
                break;
            }

            // Resolve the parent before rendering it (and therefore before it
            // captures any blocks). A missing parent degrades to the current
            // template as root, just as the interpreted processor does.
            $ctx->setParentTemplate(null);
            if ($loader === null) {
                break;
            }
            try {
                $parent = $loader($parentName);
            } catch (\RuntimeException) {
                break;
            }
            if (!$parent instanceof self) {
                break;
            }

            $seen[$key] = true;
            $result = $parent->render($ctx);
            $depth++;
        }

        return $result;
    }

    private function reportError(string $message): void
    {
        if ($this->errorHandler !== null) {
            ($this->errorHandler)($message);
        }
    }
    
    /**
     * Escape HTML
     */
    protected function escape(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        return $this->cms->escape((string)$value);
    }
    
    /**
     * Escape a value embedded in a script/style body without changing benign
     * URL/path characters such as `/`.
     */
    protected function escapeScript(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        return strtr((string) $value, [
            '\\' => '\\u005C',
            '<' => '\\u003C',
            '>' => '\\u003E',
            '&' => '\\u0026',
            "'" => '\\u0027',
            '"' => '\\u0022',
        ]);
    }

    /**
     * Apply a filter
     */
    protected function filter(string $name, mixed $value, mixed ...$args): mixed
    {
        return $this->filters->apply($name, $value, $args);
    }

    /**
     * Call a built-in template function via FunctionRegistry.
     * Only whitelisted functions are executed; unknown names return null.
     *
     * @param mixed[] $args Pre-evaluated argument values.
     */
    protected function callFunction(string $name, array $args): mixed
    {
        return FunctionRegistry::call($name, $args);
    }
    
    /**
     * Check if value is truthy
     */
    protected function isTruthy(mixed $value): bool
    {
        if ($value === null || $value === false || $value === '' || $value === 0 || $value === '0') {
            return false;
        }
        if (is_array($value) && count($value) === 0) {
            return false;
        }
        return true;
    }
    
    /**
     * Include another template
     */
    protected function include(string $template, array $variables, RenderContext $ctx): string
    {
        if ($this->templateLoader === null) {
            return "<!-- include: {$template} -->";
        }
        
        $key = trim($template);
        if ($key === '' || isset(self::$includeStack[$key]) || count(self::$includeStack) >= self::MAX_INCLUDE_DEPTH) {
            if ($this->errorHandler !== null) {
                ($this->errorHandler)($key === ''
                    ? 'Blank compiled include rejected'
                    : "Circular or excessively deep include detected: {$template}");
            }
            return '';
        }

        self::$includeStack[$key] = true;
        try {
            $loaded = ($this->templateLoader)($template);
        
            if ($loaded instanceof CompiledTemplate) {
                $loaded->setCMS($this->cms);
                $loaded->setTemplateLoader($this->templateLoader);
                if ($this->errorHandler !== null) {
                    $loaded->setErrorHandler($this->errorHandler);
                }

                $ctx->pushScope($variables);
                $ctx->pushInheritanceScope();
                try {
                    return $loaded->executeWithInheritance($ctx);
                } finally {
                    $ctx->popInheritanceScope();
                    $ctx->popScope();
                }
            }

            return '';
        } finally {
            unset(self::$includeStack[$key]);
        }
    }
    
    /**
     * Render a block
     *
     * NOTE: In compiled mode, DocumentNode content is rendered via the
     * interpreted fallback pipeline. If a DocumentNode is received here,
     * it means the compiled template's block resolution didn't inline it,
     * and we delegate to string conversion as a best-effort fallback.
     */
    protected function renderBlock(mixed $block, RenderContext $ctx): string
    {
        if ($block instanceof DocumentNode) {
            return (string)$block;
        }
        return (string)$block;
    }

    /**
     * Render a slot
     *
     * NOTE: Same DocumentNode constraint as renderBlock(). Slot content
     * that remains as AST nodes falls back to string conversion.
     */
    protected function renderSlot(mixed $slot, RenderContext $ctx): string
    {
        if ($slot instanceof DocumentNode) {
            return (string)$slot;
        }
        return (string)$slot;
    }
}
