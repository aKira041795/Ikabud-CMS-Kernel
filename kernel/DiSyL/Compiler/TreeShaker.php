<?php
/**
 * DiSyL v7.0 Tree Shaker
 * Removes unused code from compiled templates.
 * @package Ikabud\Kernel\DiSyL\Compiler
 * @version 7.0.0
 */

namespace Ikabud\Kernel\DiSyL\Compiler;

use Ikabud\Kernel\DiSyL\v4\AST\DocumentNode;
use Ikabud\Kernel\DiSyL\v4\AST\AbstractNode;
use Ikabud\Kernel\DiSyL\v4\AST\ControlNode;
use Ikabud\Kernel\DiSyL\v4\AST\ExpressionNode;

class TreeShaker
{
    private array $usedFilters = [];
    private array $usedMacros = [];
    private array $usedComponents = [];
    private array $definedMacros = [];
    
    public function analyze(DocumentNode $ast): TreeShakeResult
    {
        $this->reset();
        $this->walk($ast);
        
        $unusedMacros = array_diff(array_keys($this->definedMacros), $this->usedMacros);
        
        return new TreeShakeResult(
            usedFilters: array_unique($this->usedFilters),
            usedMacros: array_unique($this->usedMacros),
            usedComponents: array_unique($this->usedComponents),
            unusedMacros: $unusedMacros
        );
    }
    
    public function shake(DocumentNode $ast): DocumentNode
    {
        $result = $this->analyze($ast);
        return $this->removeUnused($ast, $result->unusedMacros);
    }
    
    private function reset(): void
    {
        $this->usedFilters = [];
        $this->usedMacros = [];
        $this->usedComponents = [];
        $this->definedMacros = [];
    }
    
    private function walk(AbstractNode $node): void
    {
        if ($node instanceof DocumentNode) {
            foreach ($node->getChildren() as $child) {
                $this->walk($child);
            }
            return;
        }
        
        if ($node instanceof ExpressionNode) {
            if ($node->hasFilters()) {
                foreach ($node->getFilters()->getFilters() as $filter) {
                    $this->usedFilters[] = $filter->getName();
                }
            }
            return;
        }
        
        if ($node instanceof ControlNode) {
            $tag = $node->getTag();
            
            if ($tag === 'macro') {
                $name = $node->getAttribute('name');
                $this->definedMacros[$name] = $node;
            }
            
            if ($tag === 'call' || $tag === 'macro_call') {
                $this->usedMacros[] = $node->getAttribute('name');
            }
            
            if ($tag === 'component') {
                $this->usedComponents[] = $node->getAttribute('name');
            }
            
            if ($node->getBody()) {
                $this->walk($node->getBody());
            }
            if ($node->hasElse()) {
                $this->walk($node->getElse());
            }
        }
    }
    
    private function removeUnused(DocumentNode $ast, array $unusedMacros): DocumentNode
    {
        $children = [];
        foreach ($ast->getChildren() as $child) {
            if ($child instanceof ControlNode && $child->getTag() === 'macro') {
                $name = $child->getAttribute('name');
                if (in_array($name, $unusedMacros)) {
                    continue; // Skip unused macro
                }
            }
            $children[] = $child;
        }
        
        return new DocumentNode($ast->getSpan(), $children);
    }
}

class TreeShakeResult
{
    public function __construct(
        public array $usedFilters,
        public array $usedMacros,
        public array $usedComponents,
        public array $unusedMacros
    ) {}
    
    public function getFilterImports(): string
    {
        $imports = [];
        foreach ($this->usedFilters as $filter) {
            $imports[] = "'{$filter}' => \$this->filters->get('{$filter}')";
        }
        return implode(",\n", $imports);
    }
}
