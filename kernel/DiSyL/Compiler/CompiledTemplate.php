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
use Ikabud\Kernel\DiSyL\v4\AST\DocumentNode;
use Ikabud\Kernel\DiSyL\CMS\CMSAdapterInterface;
use Ikabud\Kernel\DiSyL\CMS\NullAdapter;

/**
 * Base class for compiled templates
 */
abstract class CompiledTemplate
{
    protected CMSAdapterInterface $cms;
    protected FilterRegistry $filters;
    /** @var callable|null */
    protected $templateLoader = null;
    
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
     * Apply a filter
     */
    protected function filter(string $name, mixed $value, mixed ...$args): mixed
    {
        return $this->filters->apply($name, $value, $args);
    }
    
    /**
     * Check if value is truthy
     */
    protected function isTruthy(mixed $value): bool
    {
        if ($value === null || $value === false || $value === '' || $value === 0) {
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
        
        $loaded = ($this->templateLoader)($template);
        
        if ($loaded instanceof CompiledTemplate) {
            $loaded->setCMS($this->cms);
            $loaded->setTemplateLoader($this->templateLoader);
            
            $ctx->pushScope($variables);
            $output = $loaded->render($ctx);
            $ctx->popScope();
            
            return $output;
        }
        
        return '';
    }
    
    /**
     * Render a block
     */
    protected function renderBlock(mixed $block, RenderContext $ctx): string
    {
        if ($block instanceof DocumentNode) {
            return "<!-- block content -->";
        }
        return (string)$block;
    }
    
    /**
     * Render a slot
     */
    protected function renderSlot(mixed $slot, RenderContext $ctx): string
    {
        if ($slot instanceof DocumentNode) {
            return "<!-- slot content -->";
        }
        return (string)$slot;
    }
}
