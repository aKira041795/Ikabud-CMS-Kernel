<?php
/**
 * DiSyL v4.0 Template Compiler
 * 
 * Compiles AST to PHP code for maximum performance.
 * Compiled templates are 10-50x faster than interpreted rendering.
 * 
 * @package Ikabud\Kernel\DiSyL\Compiler
 * @version 4.0.0
 */

namespace Ikabud\Kernel\DiSyL\Compiler;

use Ikabud\Kernel\DiSyL\v4\AST\DocumentNode;
use Ikabud\Kernel\DiSyL\v4\AST\TextNode;
use Ikabud\Kernel\DiSyL\v4\AST\CommentNode;
use Ikabud\Kernel\DiSyL\v4\AST\ExpressionNode;
use Ikabud\Kernel\DiSyL\v4\AST\ControlNode;
use Ikabud\Kernel\DiSyL\v4\AST\IncludeNode;
use Ikabud\Kernel\DiSyL\v4\AST\SlotNode;
use Ikabud\Kernel\DiSyL\v4\AST\IdentifierNode;
use Ikabud\Kernel\DiSyL\v4\AST\PropertyAccessNode;
use Ikabud\Kernel\DiSyL\v4\AST\LiteralNode;
use Ikabud\Kernel\DiSyL\v4\AST\BinaryOpNode;
use Ikabud\Kernel\DiSyL\v4\AST\UnaryOpNode;
use Ikabud\Kernel\DiSyL\v4\AST\ArrayNode;
use Ikabud\Kernel\DiSyL\v4\AST\AbstractNode;
use Ikabud\Kernel\DiSyL\v4\AST\FilterChain;
use Ikabud\Kernel\DiSyL\v4\AST\FunctionCallNode;

/**
 * Compiles DiSyL templates to PHP classes
 */
class TemplateCompiler
{
    /**
     * Compiler version — bump whenever AST structure or code generation logic
     * changes.  TemplateCache includes this in cache filenames so stale
     * compiled files are automatically bypassed after an upgrade.
     */
    public const COMPILER_VERSION = 8;

    private int $indentLevel = 0;
    private string $indent = '    ';
    /** Whether the template being compiled extends a parent (child template) */
    private bool $isChildTemplate = false;
    
    /**
     * Compile AST to PHP class code
     */
    public function compile(DocumentNode $ast, string $className): string
    {
        $this->isChildTemplate = $this->documentHasExtends($ast);
        $body = $this->compileDocument($ast);
        
        $timestamp = $this->timestamp();

        $header = <<<'NOWDOC'
<?php
/**
 * Compiled DiSyL Template
NOWDOC;
        $header .= "\n * Generated: {$timestamp}\n";
        $header .= <<<'NOWDOC'
 * 
 * @generated
 */

namespace Ikabud\Kernel\DiSyL\Compiled;

use Ikabud\Kernel\DiSyL\Compiler\CompiledTemplate;
use Ikabud\Kernel\DiSyL\v4\RenderContext;

NOWDOC;

        return $header . <<<PHP
class {$className} extends CompiledTemplate
{
    public function render(RenderContext \$ctx): string
    {
        \$output = '';
{$body}
        return \$output;
    }
}
PHP;
    }
    
    /**
     * Compile document node
     */
    private function compileDocument(DocumentNode $node): string
    {
        $code = '';
        foreach ($node->getChildren() as $child) {
            $code .= $this->compileNode($child);
        }
        return $code;
    }
    
    /**
     * Compile a single node
     */
    private function compileNode(AbstractNode $node): string
    {
        return match (true) {
            $node instanceof TextNode => $this->compileText($node),
            $node instanceof CommentNode => '', // Strip comments
            $node instanceof ExpressionNode => $this->compileExpression($node),
            $node instanceof ControlNode => $this->compileControl($node),
            $node instanceof IncludeNode => $this->compileInclude($node),
            $node instanceof SlotNode => $this->compileSlot($node),
            default => '',
        };
    }
    
    /**
     * Compile text node
     */
    private function compileText(TextNode $node): string
    {
        $content = $node->getContent();
        $escaped = var_export($content, true);
        return $this->line("\$output .= {$escaped};");
    }
    
    /**
     * Compile expression node {{ expr }} — output context.
     * Applies filters and auto-escape, then emits an `$output .=` statement.
     */
    private function compileExpression(ExpressionNode $node): string
    {
        $expr = $this->compileExpressionRawValue($node);

        // Auto-escape only applies in output context
        if ($node->isAutoEscape()) {
            $expr = "\$this->escape({$expr})";
        }

        return $this->line("\$output .= (string)({$expr});");
    }

    /**
     * Compile an ExpressionNode to a raw PHP value string — value context.
     * Applies the filter chain but does NOT wrap in auto-escape, making it
     * safe to use as a condition value, a set-target, or a loop iterable.
     */
    private function compileExpressionRawValue(ExpressionNode $node): string
    {
        $expr = $this->compileExpressionValue($node->getExpression());

        if ($node->hasFilters()) {
            $expr = $this->compileFilterChain($expr, $node->getFilters());
        }

        return $expr;
    }

    /**
     * Compile any expression node to a PHP value string.
     * ExpressionNode (carries a filter chain) is handled via compileExpressionRawValue;
     * all bare node types are compiled directly.
     */
    private function compileExpressionValue(AbstractNode $node): string
    {
        return match (true) {
            $node instanceof ExpressionNode    => $this->compileExpressionRawValue($node),
            $node instanceof IdentifierNode    => $this->compileIdentifier($node),
            $node instanceof LiteralNode       => $this->compileLiteral($node),
            $node instanceof PropertyAccessNode => $this->compilePropertyAccess($node),
            $node instanceof BinaryOpNode      => $this->compileBinaryOp($node),
            $node instanceof UnaryOpNode       => $this->compileUnaryOp($node),
            $node instanceof ArrayNode         => $this->compileArray($node),
            $node instanceof FunctionCallNode  => $this->compileFunctionCall($node),
            default => 'null',
        };
    }
    
    private function compileIdentifier(IdentifierNode $node): string
    {
        $name = var_export($node->getName(), true);
        return "\$ctx->get({$name})";
    }
    
    private function compileLiteral(LiteralNode $node): string
    {
        return var_export($node->getValue(), true);
    }
    
    private function compilePropertyAccess(PropertyAccessNode $node): string
    {
        $object = $this->compileExpressionValue($node->getObject());
        
        if ($node->isComputed()) {
            $property = $this->compileExpressionValue($node->getProperty());
            return "\$ctx->getProperty({$object}, {$property})";
        }
        
        $property = var_export($node->getProperty(), true);
        return "\$ctx->getProperty({$object}, {$property})";
    }
    
    private function compileBinaryOp(BinaryOpNode $node): string
    {
        $left = $this->compileExpressionValue($node->getLeft());
        $right = $this->compileExpressionValue($node->getRight());
        $op = $node->getOperator();
        
        return match ($op) {
            'and' => "(\$this->isTruthy({$left}) && \$this->isTruthy({$right}))",
            'or' => "(\$this->isTruthy({$left}) || \$this->isTruthy({$right}))",
            '==' => "({$left} == {$right})",
            '!=' => "({$left} != {$right})",
            '<' => "({$left} < {$right})",
            '>' => "({$left} > {$right})",
            '<=' => "({$left} <= {$right})",
            '>=' => "({$left} >= {$right})",
            '+' => "({$left} + {$right})",
            '-' => "({$left} - {$right})",
            '*' => "({$left} * {$right})",
            '/' => "({$right} != 0 ? {$left} / {$right} : 0)",
            '%' => "({$right} != 0 ? {$left} % {$right} : 0)",
            '~' => "((string)({$left}) . (string)({$right}))",
            '&' => "({$left} & {$right})",
            '|' => "({$left} | {$right})",
            '^' => "({$left} ^ {$right})",
            '<<' => "({$left} << {$right})",
            '>>' => "({$left} >> {$right})",
            default => "null",
        };
    }
    
    private function compileUnaryOp(UnaryOpNode $node): string
    {
        $operand = $this->compileExpressionValue($node->getOperand());
        
        return match ($node->getOperator()) {
            'not' => "!\$this->isTruthy({$operand})",
            '-' => "-({$operand})",
            'postinc' => "({$operand}++)",
            'postdec' => "({$operand}--)",
            default => $operand,
        };
    }
    
    private function compileArray(ArrayNode $node): string
    {
        $elements = array_map(
            fn($el) => $this->compileExpressionValue($el),
            $node->getElements()
        );
        return '[' . implode(', ', $elements) . ']';
    }

    private function compileFunctionCall(FunctionCallNode $node): string
    {
        $name    = var_export($node->getName(), true);
        $argParts = array_map(
            fn($arg) => $this->compileExpressionValue($arg),
            $node->getArguments()
        );
        $argsStr = implode(', ', $argParts);
        // Delegates to CompiledTemplate::callFunction() which routes through
        // FunctionRegistry — only whitelisted functions are executed.
        return "\$this->callFunction({$name}, [{$argsStr}])";
    }
    
    /**
     * Compile filter chain
     */
    private function compileFilterChain(string $expr, FilterChain $chain): string
    {
        foreach ($chain->getFilters() as $filter) {
            $name = var_export($filter->getName(), true);
            $args = array_map(
                fn($arg) => $arg instanceof AbstractNode 
                    ? $this->compileExpressionValue($arg) 
                    : var_export($arg, true),
                $filter->getArguments()
            );
            
            $argsStr = empty($args) ? '' : ', ' . implode(', ', $args);
            $expr = "\$this->filter({$name}, {$expr}{$argsStr})";
        }
        return $expr;
    }
    
    /**
     * Compile control node
     */
    private function compileControl(ControlNode $node): string
    {
        return match ($node->getTag()) {
            'if' => $this->compileIf($node),
            'for' => $this->compileFor($node),
            'cfor' => $this->compileCFor($node),
            'while' => $this->compileWhile($node),
            'set' => $this->compileSet($node),
            'with' => $this->compileWith($node),
            'apply' => $this->compileApply($node),
            'query' => $this->compileQuery($node),
            'menu' => $this->compileMenu($node),
            'block' => $this->compileBlock($node),
            'extends' => $this->compileExtends($node),
            default => $this->compileSelfClosingTag($node),
        };
    }
    
    private function compileIf(ControlNode $node): string
    {
        $condition = $this->compileExpressionValue($node->getAttribute('condition'));
        
        $code = $this->line("if (\$this->isTruthy({$condition})) {");
        $this->indentLevel++;
        
        if ($node->getBody()) {
            $code .= $this->compileDocument($node->getBody());
        }
        
        $this->indentLevel--;
        
        if ($node->hasElse()) {
            $code .= $this->line("} else {");
            $this->indentLevel++;
            $code .= $this->compileDocument($node->getElse());
            $this->indentLevel--;
        }
        
        $code .= $this->line("}");
        return $code;
    }
    
    private function compileFor(ControlNode $node): string
    {
        $itemName = var_export($node->getAttribute('item'), true);
        $iterable = $this->compileExpressionValue($node->getAttribute('iterable'));
        
        $code = $this->line("\$__items = {$iterable};");
        $code .= $this->line("if (is_iterable(\$__items) && (!is_countable(\$__items) || count(\$__items) > 0)) {");
        $this->indentLevel++;
        
        $code .= $this->line("\$__items = is_array(\$__items) ? \$__items : iterator_to_array(\$__items);");
        $code .= $this->line("\$__count = count(\$__items);");
        $code .= $this->line("\$__index = 0;");
        $code .= $this->line("foreach (\$__items as \$__key => \$__item) {");
        $this->indentLevel++;
        
        $code .= $this->line("\$ctx->pushScope([");
        $this->indentLevel++;
        $code .= $this->line("{$itemName} => \$__item,");
        $code .= $this->line("'loop' => [");
        $this->indentLevel++;
        $code .= $this->line("'index' => \$__index,");
        $code .= $this->line("'index0' => \$__index,");
        $code .= $this->line("'index1' => \$__index + 1,");
        $code .= $this->line("'first' => \$__index === 0,");
        $code .= $this->line("'last' => \$__index === \$__count - 1,");
        $code .= $this->line("'length' => \$__count,");
        $code .= $this->line("'key' => \$__key,");
        $this->indentLevel--;
        $code .= $this->line("],");
        $this->indentLevel--;
        $code .= $this->line("]);");
        
        if ($node->getBody()) {
            $code .= $this->compileDocument($node->getBody());
        }
        
        $code .= $this->line("\$ctx->popScope();");
        $code .= $this->line("\$__index++;");
        
        $this->indentLevel--;
        $code .= $this->line("}");
        
        $this->indentLevel--;
        
        if ($node->hasElse()) {
            $code .= $this->line("} else {");
            $this->indentLevel++;
            $code .= $this->compileDocument($node->getElse());
            $this->indentLevel--;
        }
        
        $code .= $this->line("}");
        return $code;
    }

    /** {for init; condition; increment}...{/for} — C-style for loop */
    private function compileCFor(ControlNode $node): string
    {
        $initNode = $node->getAttribute('init');
        $condition = $this->compileExpressionValue($node->getAttribute('condition'));
        $incNode = $node->getAttribute('increment');

        // Compile init: handle "var = expr" by using ctx->set
        $init = $this->compileCForInit($initNode);

        // Compile increment: handle postinc/postdec by using ctx->set
        $increment = $this->compileCForIncrement($incNode);

        $code = $this->line("for ({$init}; \$this->isTruthy({$condition}); {$increment}) {");
        $this->indentLevel++;

        if ($node->getBody()) {
            $code .= $this->compileDocument($node->getBody());
        }

        $this->indentLevel--;
        $code .= $this->line("}");
        return $code;
    }

    /** Compile a C-style for-loop init expression, handling "var = expr" assignment. */
    private function compileCForInit(AbstractNode $node): string
    {
        // If it's a LiteralNode like "i = 1", parse it as a set operation
        if ($node instanceof LiteralNode && is_string($node->getValue())) {
            $val = $node->getValue();
            if (preg_match('/^(\w+)\s*=\s*(.+)$/s', $val, $m)) {
                $varName = var_export($m[1], true);
                $exprVal = $m[2];
                // Try to resolve the value expression
                $resolved = $this->compileCForValue($exprVal);
                return "\$ctx->set({$varName}, {$resolved})";
            }
        }
        // Fall back to standard expression compilation
        return $this->compileExpressionValue($node);
    }

    /** Compile a C-style for-loop increment, handling ++/-- as ctx->set. */
    private function compileCForIncrement(AbstractNode $node): string
    {
        if ($node instanceof UnaryOpNode) {
            $op = $node->getOperator();
            if ($op === 'postinc' || $op === 'postdec') {
                $operand = $node->getOperand();
                if ($operand instanceof IdentifierNode) {
                    $varName = var_export($operand->getName(), true);
                    $opChar = $op === 'postinc' ? '+' : '-';
                    return "\$ctx->set({$varName}, \$ctx->get({$varName}) {$opChar} 1)";
                }
            }
        }
        // Fall back to standard expression compilation
        return $this->compileExpressionValue($node);
    }

    /** Resolve a simple expression string to a compiled value. */
    private function compileCForValue(string $expr): string
    {
        // Numeric literal
        if (is_numeric($expr)) {
            return var_export($expr + 0, true);
        }
        // String literal
        if (preg_match('/^["\'](.*)["\']$/s', $expr, $m)) {
            return var_export($m[1], true);
        }
        // Variable reference (identifier)
        if (preg_match('/^[a-zA-Z_]\w*$/', $expr)) {
            return "\$ctx->get(" . var_export($expr, true) . ")";
        }
        // Last resort: treat as literal string
        return var_export($expr, true);
    }

    /** {while condition}...{/while} */
    private function compileWhile(ControlNode $node): string
    {
        $condition = $this->compileExpressionValue($node->getAttribute('condition'));

        $code = $this->line("while (\$this->isTruthy({$condition})) {");
        $this->indentLevel++;

        if ($node->getBody()) {
            $code .= $this->compileDocument($node->getBody());
        }

        $this->indentLevel--;
        $code .= $this->line("}");
        return $code;
    }

    private function compileSet(ControlNode $node): string
    {
        $name = var_export($node->getAttribute('name'), true);
        $value = $this->compileExpressionValue($node->getAttribute('value'));
        $compound = $node->getAttribute('compound');
        if ($compound !== null) {
            $op = match ($compound) {
                '+=' => '+',
                '-=' => '-',
                '*=' => '*',
                '/=' => '/',
                default => null,
            };
            if ($op !== null) {
                return $this->line("\$ctx->set({$name}, \$ctx->get({$name}) {$op} {$value});");
            }
        }
        return $this->line("\$ctx->set({$name}, {$value});");
    }
    
    private function compileWith(ControlNode $node): string
    {
        $variables = $node->getAttribute('variables') ?? [];
        
        $code = $this->line("\$ctx->pushScope([");
        $this->indentLevel++;
        
        foreach ($variables as $name => $expr) {
            $nameStr = var_export($name, true);
            $valueStr = $this->compileExpressionValue($expr);
            $code .= $this->line("{$nameStr} => {$valueStr},");
        }
        
        $this->indentLevel--;
        $code .= $this->line("]);");
        
        if ($node->getBody()) {
            $code .= $this->compileDocument($node->getBody());
        }
        
        $code .= $this->line("\$ctx->popScope();");
        return $code;
    }
    
    private function compileApply(ControlNode $node): string
    {
        $filters = $node->getAttribute('filters') ?? [];
        
        $code = $this->line("\$__applyContent = '';");
        $code .= $this->line("ob_start();");
        
        // Temporarily redirect output
        $code .= $this->line("\$__savedOutput = \$output;");
        $code .= $this->line("\$output = '';");
        
        if ($node->getBody()) {
            $code .= $this->compileDocument($node->getBody());
        }
        
        $code .= $this->line("\$__applyContent = \$output;");
        $code .= $this->line("\$output = \$__savedOutput;");
        $code .= $this->line("ob_end_clean();");
        
        // Apply filters
        foreach ($filters as $filter) {
            $name = var_export($filter['name'], true);
            $args = array_map(fn($a) => var_export($a, true), $filter['args'] ?? []);
            $argsStr = empty($args) ? '' : ', ' . implode(', ', $args);
            $code .= $this->line("\$__applyContent = \$this->filter({$name}, \$__applyContent{$argsStr});");
        }
        
        $code .= $this->line("\$output .= \$__applyContent;");
        return $code;
    }
    
    private function compileQuery(ControlNode $node): string
    {
        $itemName = var_export($node->getAttribute('item'), true);
        $type = var_export($node->getAttribute('type'), true);
        $where = var_export($node->getAttribute('where') ?? [], true);
        $orderBy = var_export($node->getAttribute('order_by'), true);
        $order = var_export($node->getAttribute('order') ?? 'DESC', true);
        $limit = var_export($node->getAttribute('limit'), true);
        $offset = var_export($node->getAttribute('offset'), true);
        
        $code = $this->line("\$__queryResults = \$this->cms->query({$type}, [");
        $this->indentLevel++;
        $code .= $this->line("'where' => {$where},");
        $code .= $this->line("'order_by' => {$orderBy},");
        $code .= $this->line("'order' => {$order},");
        $code .= $this->line("'limit' => {$limit},");
        $code .= $this->line("'offset' => {$offset},");
        $this->indentLevel--;
        $code .= $this->line("]);");
        
        $code .= $this->line("\$__items = is_array(\$__queryResults) ? \$__queryResults : iterator_to_array(\$__queryResults);");
        $code .= $this->line("if (!empty(\$__items)) {");
        $this->indentLevel++;
        
        // Reuse for loop logic
        $code .= $this->line("\$__count = count(\$__items);");
        $code .= $this->line("\$__index = 0;");
        $code .= $this->line("foreach (\$__items as \$__key => \$__item) {");
        $this->indentLevel++;
        
        $code .= $this->line("\$ctx->pushScope([{$itemName} => \$__item, 'loop' => ['index' => \$__index, 'first' => \$__index === 0, 'last' => \$__index === \$__count - 1, 'length' => \$__count]]);");
        
        if ($node->getBody()) {
            $code .= $this->compileDocument($node->getBody());
        }
        
        $code .= $this->line("\$ctx->popScope();");
        $code .= $this->line("\$__index++;");
        
        $this->indentLevel--;
        $code .= $this->line("}");
        $this->indentLevel--;
        
        if ($node->hasElse()) {
            $code .= $this->line("} else {");
            $this->indentLevel++;
            $code .= $this->compileDocument($node->getElse());
            $this->indentLevel--;
        }
        
        $code .= $this->line("}");
        return $code;
    }
    
    private function compileMenu(ControlNode $node): string
    {
        $location = var_export($node->getAttribute('location'), true);
        $itemName = var_export($node->getAttribute('item'), true);
        
        $code = $this->line("\$__menuItems = \$this->cms->getMenu({$location});");
        $code .= $this->line("if (!empty(\$__menuItems)) {");
        $this->indentLevel++;
        
        $code .= $this->line("\$__count = count(\$__menuItems);");
        $code .= $this->line("\$__index = 0;");
        $code .= $this->line("foreach (\$__menuItems as \$__item) {");
        $this->indentLevel++;
        
        $code .= $this->line("\$ctx->pushScope([{$itemName} => \$__item, 'loop' => ['index' => \$__index, 'first' => \$__index === 0, 'last' => \$__index === \$__count - 1]]);");
        
        if ($node->getBody()) {
            $code .= $this->compileDocument($node->getBody());
        }
        
        $code .= $this->line("\$ctx->popScope();");
        $code .= $this->line("\$__index++;");
        
        $this->indentLevel--;
        $code .= $this->line("}");
        $this->indentLevel--;
        $code .= $this->line("}");
        
        return $code;
    }
    
    private function compileBlock(ControlNode $node): string
    {
        $name = var_export($node->getAttribute('name'), true);

        if ($this->isChildTemplate) {
            // Child template: capture block content into a string and register it in context.
            // The parent (layout) template will read it via hasBlock/getBlock.
            $code = $this->line("{ // setBlock({$name})");
            $this->indentLevel++;
            $code .= $this->line("\$__prev = \$output; \$output = '';");
            if ($node->getBody()) {
                $code .= $this->compileDocument($node->getBody());
            }
            $code .= $this->line("\$ctx->setBlock({$name}, \$output);");
            $code .= $this->line("\$output = \$__prev;");
            $this->indentLevel--;
            $code .= $this->line("}");
            return $code;
        }

        // Layout template: use the child's block if registered, else render default.
        $code = $this->line("if (\$ctx->hasBlock({$name})) {");
        $this->indentLevel++;
        $code .= $this->line("\$output .= \$this->renderBlock(\$ctx->getBlock({$name}), \$ctx);");
        $this->indentLevel--;
        $code .= $this->line("} else {");
        $this->indentLevel++;
        
        if ($node->getBody()) {
            $code .= $this->compileDocument($node->getBody());
        }
        
        $this->indentLevel--;
        $code .= $this->line("}");
        
        return $code;
    }

    /**
     * Detect if a DocumentNode contains an {extends} node at the top level.
     */
    private function documentHasExtends(DocumentNode $ast): bool
    {
        foreach ($ast->getChildren() as $child) {
            if ($child instanceof ControlNode && $child->getTag() === 'extends') {
                return true;
            }
        }
        return false;
    }
    
    private function compileExtends(ControlNode $node): string
    {
        $template = var_export($node->getAttribute('template'), true);
        return $this->line("\$ctx->setParentTemplate({$template});");
    }
    
    private function compileSelfClosingTag(ControlNode $node): string
    {
        $tag = $node->getTag();
        $attrs = $node->getAttributes();
        
        return match ($tag) {
            'setting', 'native_setting' => $this->compileSetting($attrs),
            'theme_url' => $this->compileThemeUrl($attrs),
            'date' => $this->compileDate($attrs),
            default => $this->line("// Unsupported tag: {$tag}"),
        };
    }
    
    private function compileSetting(array $attrs): string
    {
        $key = var_export($attrs['key'] ?? $attrs['name'] ?? '', true);
        $default = var_export($attrs['default'] ?? '', true);
        return $this->line("\$output .= \$this->escape(\$this->cms->getSetting({$key}, {$default}));");
    }
    
    private function compileThemeUrl(array $attrs): string
    {
        $path = var_export($attrs['path'] ?? '', true);
        return $this->line("\$output .= \$this->cms->getAssetUrl({$path});");
    }
    
    private function compileDate(array $attrs): string
    {
        $value = var_export($attrs['value'] ?? 'now', true);
        $format = var_export($attrs['format'] ?? null, true);
        return $this->line("\$output .= \$this->cms->formatDate({$value} === 'now' ? time() : {$value}, {$format});");
    }
    
    private function compileInclude(IncludeNode $node): string
    {
        $template = var_export($node->getTemplate(), true);
        $vars = var_export($node->getVariables(), true);
        
        return $this->line("\$output .= \$this->include({$template}, {$vars}, \$ctx);");
    }
    
    private function compileSlot(SlotNode $node): string
    {
        $name = var_export($node->getName(), true);
        
        $code = $this->line("if (\$ctx->hasSlot({$name})) {");
        $this->indentLevel++;
        $code .= $this->line("\$output .= \$this->renderSlot(\$ctx->getSlot({$name}), \$ctx);");
        $this->indentLevel--;
        
        if ($node->hasDefaultContent()) {
            $code .= $this->line("} else {");
            $this->indentLevel++;
            $code .= $this->compileDocument($node->getBody());
            $this->indentLevel--;
        }
        
        $code .= $this->line("}");
        return $code;
    }
    
    /**
     * Generate indented line
     */
    private function line(string $code): string
    {
        return str_repeat($this->indent, $this->indentLevel + 2) . $code . "\n";
    }
    
    /**
     * Get current timestamp
     */
    private function timestamp(): string
    {
        return date('Y-m-d H:i:s');
    }
}
