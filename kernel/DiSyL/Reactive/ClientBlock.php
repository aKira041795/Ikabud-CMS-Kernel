<?php
/**
 * DiSyL v6.0 Client-Side Block Compiler
 * Compiles {% client %} blocks to JavaScript modules.
 * @package Ikabud\Kernel\DiSyL\Reactive
 * @version 6.0.0
 */

namespace Ikabud\Kernel\DiSyL\Reactive;

class ClientBlock
{
    private string $id;
    private string $code;
    private array $imports = [];
    private array $exports = [];
    private array $events = [];
    
    public function __construct(string $code)
    {
        $this->id = 'client-' . substr(md5($code), 0, 8);
        $this->code = $code;
        $this->parseImports();
        $this->parseEvents();
    }
    
    private function parseImports(): void
    {
        preg_match_all('/import\s+(?:{([^}]+)}|(\w+))\s+from\s+[\'"]([^\'"]+)[\'"]/', $this->code, $matches, PREG_SET_ORDER);
        foreach ($matches as $m) {
            $this->imports[] = [
                'named' => $m[1] ?? null,
                'default' => $m[2] ?? null,
                'from' => $m[3],
            ];
        }
    }
    
    private function parseEvents(): void
    {
        preg_match_all('/@(\w+)(?:\.(\w+))?="([^"]+)"/', $this->code, $matches, PREG_SET_ORDER);
        foreach ($matches as $m) {
            $this->events[] = [
                'event' => $m[1],
                'modifier' => $m[2] ?? null,
                'handler' => $m[3],
            ];
        }
    }
    
    public function getId(): string { return $this->id; }
    public function getCode(): string { return $this->code; }
    public function getImports(): array { return $this->imports; }
    public function getEvents(): array { return $this->events; }
    
    public function toModule(): string
    {
        // Reject code containing </script> to prevent breaking out of
        // the script tag boundary when this output is embedded in HTML.
        $safeCode = str_replace('</script', '<\/script', $this->code);
        return <<<JS
// DiSyL Client Block: {$this->id}
(function() {
{$safeCode}
})();
JS;
    }
}

class EventHandler
{
    private static array $handlers = [
        'click', 'dblclick', 'mousedown', 'mouseup', 'mousemove', 'mouseenter', 'mouseleave',
        'keydown', 'keyup', 'keypress', 'focus', 'blur', 'change', 'input', 'submit',
        'scroll', 'resize', 'load', 'unload', 'error',
    ];
    
    private static array $modifiers = [
        'prevent' => 'e.preventDefault();',
        'stop' => 'e.stopPropagation();',
        'once' => '', // Handled via addEventListener options
        'passive' => '', // Handled via addEventListener options
        'capture' => '', // Handled via addEventListener options
        'self' => 'if (e.target !== e.currentTarget) return;',
    ];
    
    public static function compile(string $event, ?string $modifier, string $handler, string $elementId): string
    {
        // Validate event name against known-safe event names.
        if (!self::isValidEvent($event)) {
            return '// DiSyL: unknown event "' . addslashes($event) . '"';
        }

        // Escape elementId for safe embedding in JS string literal.
        $safeElementId = addcslashes($elementId, "\\'\"");

        // Escape handler: only allow simple expressions (no script tags, no closing script).
        if (preg_match('/<\/script/i', $handler)) {
            return '// DiSyL: handler rejected (script breakout attempt)';
        }

        $options = [];
        $preCode = '';
        
        if ($modifier) {
            if (isset(self::$modifiers[$modifier])) {
                $preCode = self::$modifiers[$modifier];
            }
            if (in_array($modifier, ['once', 'passive', 'capture'])) {
                $options[$modifier] = true;
            }
        }
        
        $optionsJSON = empty($options) ? '' : ', ' . json_encode($options);
        
        return <<<JS
document.getElementById('{$safeElementId}').addEventListener('{$event}', function(e) {
    {$preCode}
    {$handler}
}{$optionsJSON});
JS;
    }
    
    public static function isValidEvent(string $event): bool
    {
        return in_array($event, self::$handlers);
    }
}

// ClientBlockRegistry moved to separate file: ClientBlockRegistry.php
