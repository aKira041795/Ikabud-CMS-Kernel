<?php
/**
 * DiSyL v11.0 HTMX Response
 * 
 * @package Ikabud\Kernel\DiSyL\Reactive
 * @version 11.0.0
 */

namespace Ikabud\Kernel\DiSyL\Reactive;

/**
 * HTMX response builder
 */
class HTMXResponse
{
    private string $content = '';
    private array $headers = [];
    private array $oobSwaps = [];
    private array $triggers = [];
    private array $triggersAfterSettle = [];
    private array $triggersAfterSwap = [];
    
    public function setContent(string $content): self
    {
        $this->content = $content;
        return $this;
    }
    
    public function getContent(): string
    {
        return $this->content;
    }
    
    public function getBody(): string
    {
        $output = $this->content;
        foreach ($this->oobSwaps as $swap) {
            $output .= "\n" . $swap->render();
        }
        return $output;
    }
    
    public function addOOBSwap(string $targetId, string $content, SwapStrategy $strategy = SwapStrategy::OUTER_HTML): self
    {
        $this->oobSwaps[] = new OOBSwap($targetId, $content, $strategy);
        return $this;
    }
    
    public function trigger(string $event, mixed $detail = null): self
    {
        if ($detail !== null) {
            $this->triggers[$event] = $detail;
        } else {
            $this->triggers[] = $event;
        }
        return $this;
    }
    
    public function triggerAfterSettle(string $event, mixed $detail = null): self
    {
        if ($detail !== null) {
            $this->triggersAfterSettle[$event] = $detail;
        } else {
            $this->triggersAfterSettle[] = $event;
        }
        return $this;
    }
    
    public function triggerAfterSwap(string $event, mixed $detail = null): self
    {
        if ($detail !== null) {
            $this->triggersAfterSwap[$event] = $detail;
        } else {
            $this->triggersAfterSwap[] = $event;
        }
        return $this;
    }
    
    public function pushUrl(string $url): self
    {
        $this->headers[HTMXHeaders::HX_PUSH_URL] = $url;
        return $this;
    }
    
    public function replaceUrl(string $url): self
    {
        $this->headers[HTMXHeaders::HX_REPLACE_URL] = $url;
        return $this;
    }
    
    public function redirect(string $url): self
    {
        $this->headers[HTMXHeaders::HX_REDIRECT] = $url;
        return $this;
    }
    
    public function refresh(): self
    {
        $this->headers[HTMXHeaders::HX_REFRESH] = 'true';
        return $this;
    }
    
    public function reswap(SwapStrategy $strategy): self
    {
        $this->headers[HTMXHeaders::HX_RESWAP] = $strategy->value;
        return $this;
    }
    
    public function retarget(string $selector): self
    {
        $this->headers[HTMXHeaders::HX_RETARGET] = $selector;
        return $this;
    }
    
    public function reselect(string $selector): self
    {
        $this->headers[HTMXHeaders::HX_RESELECT] = $selector;
        return $this;
    }
    
    public function getHeaders(): array
    {
        $headers = $this->headers;
        
        if (!empty($this->triggers)) {
            $headers[HTMXHeaders::HX_TRIGGER] = $this->formatTriggers($this->triggers);
        }
        
        if (!empty($this->triggersAfterSettle)) {
            $headers[HTMXHeaders::HX_TRIGGER_AFTER_SETTLE] = $this->formatTriggers($this->triggersAfterSettle);
        }
        
        if (!empty($this->triggersAfterSwap)) {
            $headers[HTMXHeaders::HX_TRIGGER_AFTER_SWAP] = $this->formatTriggers($this->triggersAfterSwap);
        }
        
        return $headers;
    }
    
    public function render(): string
    {
        $output = $this->content;
        
        foreach ($this->oobSwaps as $swap) {
            $output .= $swap->render();
        }
        
        return $output;
    }
    
    public function send(): void
    {
        foreach ($this->getHeaders() as $name => $value) {
            header("{$name}: {$value}");
        }
        
        echo $this->render();
    }
    
    private function formatTriggers(array $triggers): string
    {
        $hasDetails = false;
        foreach ($triggers as $key => $value) {
            if (!is_int($key)) {
                $hasDetails = true;
                break;
            }
        }
        
        if ($hasDetails) {
            return json_encode($triggers);
        }
        
        return implode(', ', $triggers);
    }
}
