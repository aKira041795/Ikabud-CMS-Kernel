<?php
/**
 * DiSyL v11.0 Turbo Stream Response
 * 
 * @package Ikabud\Kernel\DiSyL\Reactive
 * @version 11.0.0
 */

namespace Ikabud\Kernel\DiSyL\Reactive;

/**
 * Turbo Stream response (Hotwire compatibility)
 */
class TurboStreamResponse
{
    private array $streams = [];
    
    public function append(string $target, string $content): self
    {
        $this->streams[] = $this->createStream('append', $target, $content);
        return $this;
    }
    
    public function prepend(string $target, string $content): self
    {
        $this->streams[] = $this->createStream('prepend', $target, $content);
        return $this;
    }
    
    public function replace(string $target, string $content): self
    {
        $this->streams[] = $this->createStream('replace', $target, $content);
        return $this;
    }
    
    public function update(string $target, string $content): self
    {
        $this->streams[] = $this->createStream('update', $target, $content);
        return $this;
    }
    
    public function remove(string $target): self
    {
        $this->streams[] = $this->createStream('remove', $target, '');
        return $this;
    }
    
    public function before(string $target, string $content): self
    {
        $this->streams[] = $this->createStream('before', $target, $content);
        return $this;
    }
    
    public function after(string $target, string $content): self
    {
        $this->streams[] = $this->createStream('after', $target, $content);
        return $this;
    }
    
    public function render(): string
    {
        return implode("\n", $this->streams);
    }
    
    public function send(): void
    {
        header('Content-Type: text/vnd.turbo-stream.html');
        echo $this->render();
    }
    
    private function createStream(string $action, string $target, string $content): string
    {
        $safeAction = htmlspecialchars($action, ENT_QUOTES, 'UTF-8');
        $safeTarget = htmlspecialchars($target, ENT_QUOTES, 'UTF-8');

        if ($action === 'remove') {
            return "<turbo-stream action=\"{$safeAction}\" target=\"{$safeTarget}\"></turbo-stream>";
        }

        // Content goes inside <template> which the browser parses as HTML.
        // Content is expected to be pre-escaped safe HTML; do not double-escape.
        return "<turbo-stream action=\"{$safeAction}\" target=\"{$safeTarget}\"><template>{$content}</template></turbo-stream>";
    }
}
