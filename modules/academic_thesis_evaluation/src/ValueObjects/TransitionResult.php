<?php
declare(strict_types=1);

/**
 * Structured transition result from the workflow engine.
 */
class TransitionResult
{
    public bool $ok;
    public string $stage;
    public string $status;
    public string $message;
    public ?string $error;
    public array $context;

    public function __construct(
        bool $ok,
        string $stage = '',
        string $status = '',
        string $message = '',
        ?string $error = null,
        array $context = []
    ) {
        $this->ok = $ok;
        $this->stage = $stage;
        $this->status = $status;
        $this->message = $message;
        $this->error = $error;
        $this->context = $context;
    }

    public static function success(string $stage, string $status, string $message = '', array $context = []): self
    {
        return new self(true, $stage, $status, $message, null, $context);
    }

    public static function failure(string $error, array $context = []): self
    {
        return new self(false, '', '', '', $error, $context);
    }

    public function toArray(): array
    {
        $result = ['ok' => $this->ok];
        if ($this->ok) {
            $result['stage'] = $this->stage;
            $result['status'] = $this->status;
            $result['message'] = $this->message;
        } else {
            $result['error'] = $this->error;
        }
        if (!empty($this->context)) {
            $result['context'] = $this->context;
        }
        return $result;
    }
}
