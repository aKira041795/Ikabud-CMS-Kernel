<?php

declare(strict_types=1);

namespace Ikabud\Kernel\Http;

/**
 * Input validation framework with rule-based validation.
 *
 * Rules are pipe-delimited strings: 'required|email|min:8|max:128|in:admin,cashier'
 *
 * Built-in rules: required, nullable, email, min, max, int, numeric, string, in, boolean
 * Custom rules: register with Validator::registerRule('name', callable)
 * Extend: subclass and add protected validate<Rule>() methods
 *
 * Usage:
 *   $v = new Validator(app()->input(), [
 *       'email' => 'required|email',
 *       'name'  => 'required|string|min:3|max:100',
 *       'role'  => 'required|in:admin,cashier,supervisor',
 *   ]);
 *   $clean = $v->validated(); // emits 422 JSON + exits on failure
 */
class Validator
{
    private array $errors = [];
    private array $data;
    private array $rules;

    /** @var array<string, callable> Registered custom rules */
    protected static array $customRules = [];

    /**
     * @param array $data  Input data (e.g. app()->input() or $_POST)
     * @param array $rules Rule map: ['field' => 'required|email|...']
     */
    public function __construct(array $data, array $rules)
    {
        $this->data = $data;
        $this->rules = $rules;
    }

    /**
     * Register a custom rule for all Validator instances.
     *
     * The callable receives (string $field, mixed $value, array $params): ?string
     * Return null for pass, string error message for failure.
     */
    public static function registerRule(string $name, callable $fn): void
    {
        self::$customRules[$name] = $fn;
    }

    /**
     * Run validation without side effects. Returns true if all rules pass.
     */
    public function passes(): bool
    {
        $this->errors = [];
        foreach ($this->rules as $field => $ruleString) {
            $value = $this->data[$field] ?? null;
            $isRequired = str_contains($ruleString, 'required');
            $isNullable = str_contains($ruleString, 'nullable');

            // Skip validation for absent optional fields (unless present with non-null value)
            $isAbsent = !array_key_exists($field, $this->data) || $value === null || $value === '';
            if (!$isRequired && !$isNullable && $isAbsent) {
                continue;
            }
            // For nullable fields, skip further rules when value is null/absent
            if ($isNullable && $isAbsent) {
                continue;
            }

            foreach (explode('|', $ruleString) as $rule) {
                $rule = trim($rule);
                if ($rule === '' || $rule === 'nullable') {
                    continue;
                }
                $params = [];
                if (str_contains($rule, ':')) {
                    [$rule, $paramStr] = explode(':', $rule, 2);
                    $params = explode(',', $paramStr);
                }

                $error = $this->applyRule($field, $value, $rule, $params);
                if ($error !== null) {
                    $this->errors[$field] = $error;
                    break; // first error per field
                }
            }
        }
        return empty($this->errors);
    }

    /**
     * Validate and return the subset of input keys listed in rules.
     * Emits 422 JSON and exits on failure.
     *
     * NOTE: Returns raw values — does NOT cast or sanitize.
     * Use for validation only; sanitize separately.
     */
    public function validated(): array
    {
        if (!$this->passes()) {
            ApiResponse::validationError($this->errors);
        }
        $result = [];
        foreach ($this->rules as $field => $_) {
            if (array_key_exists($field, $this->data)) {
                $result[$field] = $this->data[$field];
            }
        }
        return $result;
    }

    /**
     * Get the errors array (field => message).
     */
    public function errors(): array
    {
        return $this->errors;
    }

    // ── Rule resolution ────────────────────────────────────

    private function applyRule(string $field, mixed $value, string $rule, array $params): ?string
    {
        // 1. Built-in method: validate<Rule>()
        $method = 'validate' . ucfirst($rule);
        if (method_exists($this, $method)) {
            return $this->$method($field, $value, $params);
        }

        // 2. Registered custom rule
        if (isset(self::$customRules[$rule])) {
            return call_user_func(self::$customRules[$rule], $field, $value, $params);
        }

        // 3. Subclass method (protected extension)
        if (is_callable([$this, $method])) {
            return $this->$method($field, $value, $params);
        }

        // Unknown rule → configuration error (fail loudly)
        throw new \RuntimeException("Unknown validation rule: '{$rule}' on field '{$field}'");
    }

    // ── Built-in rules (protected for subclass override) ────

    protected function validateRequired(string $field, mixed $value, array $params): ?string
    {
        if ($value === null || $value === '' || (is_array($value) && empty($value))) {
            return 'The ' . $field . ' field is required.';
        }
        return null;
    }

    protected function validateEmail(string $field, mixed $value, array $params): ?string
    {
        if ($value !== null && $value !== '' && !filter_var((string)$value, FILTER_VALIDATE_EMAIL)) {
            return 'The ' . $field . ' must be a valid email address.';
        }
        return null;
    }

    protected function validateMin(string $field, mixed $value, array $params): ?string
    {
        $min = (int)($params[0] ?? 0);
        if (is_string($value) && mb_strlen($value) < $min) {
            return "The {$field} must be at least {$min} characters.";
        }
        if (is_numeric($value) && (float)$value < $min) {
            return "The {$field} must be at least {$min}.";
        }
        return null;
    }

    protected function validateMax(string $field, mixed $value, array $params): ?string
    {
        $max = (int)($params[0] ?? 0);
        if (is_string($value) && mb_strlen($value) > $max) {
            return "The {$field} must not exceed {$max} characters.";
        }
        if (is_numeric($value) && (float)$value > $max) {
            return "The {$field} must not exceed {$max}.";
        }
        return null;
    }

    protected function validateInt(string $field, mixed $value, array $params): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $filtered = filter_var($value, FILTER_VALIDATE_INT, ['flags' => FILTER_NULL_ON_FAILURE]);
        if ($filtered === null) {
            return "The {$field} must be an integer.";
        }
        return null;
    }

    protected function validateNumeric(string $field, mixed $value, array $params): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_numeric($value)) {
            return "The {$field} must be numeric.";
        }
        return null;
    }

    protected function validateString(string $field, mixed $value, array $params): ?string
    {
        if ($value !== null && !is_string($value)) {
            return "The {$field} must be a string.";
        }
        return null;
    }

    protected function validateIn(string $field, mixed $value, array $params): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!in_array((string)$value, $params, true)) {
            return "The {$field} must be one of: " . implode(', ', $params) . '.';
        }
        return null;
    }

    protected function validateBoolean(string $field, mixed $value, array $params): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $valid = [true, false, 1, 0, '1', '0', 'true', 'false'];
        if (!in_array($value, $valid, true)) {
            return "The {$field} must be a boolean.";
        }
        return null;
    }
}
