<?php
/**
 * DiSyL v11.0 HTMX Request
 * 
 * @package Ikabud\Kernel\DiSyL\Reactive
 * @version 11.0.0
 */

namespace Ikabud\Kernel\DiSyL\Reactive;

/**
 * HTMX request parser
 */
class HTMXRequest
{
    private bool $isHtmx;
    private bool $isBoosted;
    private ?string $currentUrl;
    private bool $historyRestoreRequest;
    private ?string $prompt;
    private ?string $target;
    private ?string $triggerName;
    private ?string $triggerId;
    
    public function __construct(array $headers = [])
    {
        $headers = array_change_key_case($headers, CASE_LOWER);
        
        $this->isHtmx = isset($headers['hx-request']) && $headers['hx-request'] === 'true';
        $this->isBoosted = isset($headers['hx-boosted']) && $headers['hx-boosted'] === 'true';
        $this->currentUrl = $headers['hx-current-url'] ?? null;
        $this->historyRestoreRequest = isset($headers['hx-history-restore-request']) && $headers['hx-history-restore-request'] === 'true';
        $this->prompt = $headers['hx-prompt'] ?? null;
        $this->target = $headers['hx-target'] ?? null;
        $this->triggerName = $headers['hx-trigger-name'] ?? null;
        $this->triggerId = $headers['hx-trigger'] ?? null;
    }
    
    public static function fromGlobals(): self
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $headerName = str_replace('_', '-', substr($key, 5));
                $headers[strtolower($headerName)] = $value;
            }
        }
        return new self($headers);
    }
    
    public function isHtmxRequest(): bool
    {
        return $this->isHtmx;
    }
    
    public function isBoosted(): bool
    {
        return $this->isBoosted;
    }
    
    public function getCurrentUrl(): ?string
    {
        return $this->currentUrl;
    }
    
    public function isHistoryRestoreRequest(): bool
    {
        return $this->historyRestoreRequest;
    }
    
    public function getPrompt(): ?string
    {
        return $this->prompt;
    }
    
    public function getTarget(): ?string
    {
        return $this->target;
    }
    
    public function getTriggerName(): ?string
    {
        return $this->triggerName;
    }
    
    public function getTriggerId(): ?string
    {
        return $this->triggerId;
    }
}
