<?php

namespace Ikabud\Kernel\DiSyL\v4;

final class FilterRegistry
{
    /** @var array<string, callable> */
    private array $filters = [];

    public function __construct()
    {
        $this->registerDefaults();
    }

    public function register(string $name, callable $filter): void
    {
        $this->filters[$name] = $filter;
    }

    public function get(string $name): callable
    {
        if (!isset($this->filters[$name])) {
            throw new \RuntimeException("Unknown filter: {$name}");
        }
        return $this->filters[$name];
    }

    public function has(string $name): bool
    {
        return isset($this->filters[$name]);
    }

    public function apply(string $name, mixed $value, array $args = []): mixed
    {
        if (!isset($this->filters[$name])) {
            // Log warning for unknown filters so template bugs don't hide silently
            if (function_exists('write_log')) {
                write_log("disyl.filter.unknown: filter '{$name}' not registered — value passed through unchanged", 'warning');
            }
            return $value;
        }
        // Some filters expect positional and named args separately.
        // Unpack the combined $args into the two arrays.
        $positional = [];
        $named = [];
        foreach ($args as $k => $v) {
            if (is_int($k)) {
                $positional[] = $v;
            } else {
                $named[$k] = $v;
            }
        }
        return ($this->filters[$name])($value, $positional, $named);
    }

    private function registerDefaults(): void
    {
        $this->filters = [
            'escape' => function($v, $args) {
                $mode = $args[0] ?? 'html';
                $filters = [
                    'esc_html' => fn($x) => htmlspecialchars((string) $x, ENT_QUOTES, 'UTF-8'),
                    'esc_attr' => fn($x) => htmlspecialchars((string) $x, ENT_QUOTES, 'UTF-8'),
                    'esc_js' => fn($x) => str_replace(
                        ['\\', "'", '"', "\n", "\r", '</', "\xe2\x80\xa8", "\xe2\x80\xa9"],
                        ['\\\\', "\\'", '\\"', '\\n', '\\r', '<\\/', '\\u2028', '\\u2029'],
                        (string) $x
                    ),
                    'esc_url' => function($x) {
                        $url = filter_var((string) $x, FILTER_SANITIZE_URL);
                        if (str_starts_with($url, '//')) return '#';
                        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
                        if ($scheme !== '' && !in_array($scheme, ['http', 'https', 'mailto', 'tel', 'ftp'], true)) return '#';
                        return $url;
                    },
                ];
                $target = match($mode) { 'js'=>'esc_js', 'attr'=>'esc_attr', 'url'=>'esc_url', default=>'esc_html' };
                return ($filters[$target])($v);
            },
            'esc_html' => fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'),
            'esc_attr' => fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'),
            'esc_url' => function ($v) {
                $url = filter_var((string) $v, FILTER_SANITIZE_URL);
                if (str_starts_with($url, '//')) {
                    return '#';
                }
                $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
                if ($scheme !== '' && !in_array($scheme, ['http', 'https', 'mailto', 'tel', 'ftp'], true)) {
                    return '#';
                }
                return $url;
            },
            'esc_js' => fn($v) => str_replace(
                ['\\', "'", '"', "\n", "\r", '</', "\xe2\x80\xa8", "\xe2\x80\xa9"],
                ['\\\\', "\\'", '\\"', '\\n', '\\r', '<\\/', '\\u2028', '\\u2029'],
                (string) $v
            ),
            'raw' => fn($v) => $v,
            'upper' => fn($v) => strtoupper((string) $v),
            'lower' => fn($v) => strtolower((string) $v),
            'capitalize' => fn($v) => ucfirst((string) $v),
            'title' => fn($v) => ucwords(str_replace('_', ' ', (string) $v)),
            'trim' => fn($v) => trim((string) $v),
            'truncate' => fn($v, $a) => mb_strlen((string)$v) > (int)($a[0] ?? 100)
                ? mb_substr((string)$v, 0, (int)($a[0] ?? 100)) . '...'
                : (string)$v,
            'nl2br' => fn($v) => nl2br((string) $v),
            'json' => fn($v) => json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'date' => fn($v, $a) => $v ? date($a[0] ?? 'Y-m-d', is_numeric($v) ? (int)$v : strtotime((string)$v)) : '',
            'default' => fn($v, $a) => ($v !== null && $v !== '') ? $v : ($a[0] ?? ''),
            'count' => fn($v) => is_countable($v) ? count($v) : 0,
            'join' => fn($v, $a) => is_array($v) ? implode($a[0] ?? ', ', $v) : $v,
            'first' => fn($v) => is_array($v) ? reset($v) : (is_string($v) ? mb_substr($v, 0, 1) : $v),
            'last' => fn($v) => is_array($v) ? end($v) : $v,
            'keys' => fn($v) => is_array($v) ? array_keys($v) : [],
            'values' => fn($v) => is_array($v) ? array_values($v) : [],
            'number_format' => fn($v, $a) => number_format((float)$v, (int)($a[0] ?? 0)),
            'abs' => fn($v) => abs((float)$v),
            'round' => fn($v, $a) => round((float)$v, (int)($a[0] ?? 0)),
            'floor' => fn($v) => floor((float)$v),
            'ceil' => fn($v) => ceil((float)$v),
            'length' => fn($v) => is_array($v) ? count($v) : mb_strlen((string)$v),
            'reverse' => fn($v) => is_array($v) ? array_reverse($v) : strrev((string)$v),
            'sort' => function ($v) { if (is_array($v)) { sort($v); return $v; } return $v; },
            'unique' => fn($v) => is_array($v) ? array_unique($v) : $v,
            'slice' => fn($v, $a) => is_array($v)
                ? array_slice($v, (int)($a[0] ?? 0), isset($a[1]) ? (int)$a[1] : null)
                : mb_substr((string)$v, (int)($a[0] ?? 0), isset($a[1]) ? (int)$a[1] : null),
            'split' => fn($v, $a) => explode($a[0] ?? ',', (string)$v),
            'replace' => fn($v, $a) => str_replace($a[0] ?? '', $a[1] ?? '', (string)$v),
            'strip_tags' => fn($v) => strip_tags((string)$v),
            'url_encode' => fn($v) => urlencode((string)$v),
            'base64' => fn($v) => base64_encode((string)$v),
            'md5' => fn($v) => md5((string)$v),
        ];
    }
}
