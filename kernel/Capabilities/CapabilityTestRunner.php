<?php

declare(strict_types=1);

namespace Ikabud\Kernel\Capabilities;

final class CapabilityTestRunner
{
    public function __construct(
        private readonly CapabilityBus $bus
    ) {
    }

    /**
     * @return array{ok: bool, passed: int, failed: int, failures: array<int, array{name: string, error: string}>}
     */
    public function runFixture(array $fixture, array $globalOptions = []): array
    {
        $capId = (string)($fixture['capability_id'] ?? '');
        $mode = (string)($fixture['mode'] ?? ($globalOptions['mode'] ?? 'first'));
        $cases = $fixture['cases'] ?? [];

        $passed = 0;
        $failed = 0;
        $failures = [];

        if ($capId === '' || !is_array($cases)) {
            return [
                'ok' => false,
                'passed' => 0,
                'failed' => 1,
                'failures' => [['name' => 'fixture', 'error' => 'Invalid fixture format']]
            ];
        }

        foreach ($cases as $case) {
            $name = (string)($case['name'] ?? 'case');
            $payload = $case['payload'] ?? null;
            $opts = is_array($case['options'] ?? null) ? $case['options'] : [];

            $callOpts = ['mode' => $mode];
            if (isset($opts['mode'])) {
                $callOpts['mode'] = (string)$opts['mode'];
            }
            if (isset($opts['provider']) && is_string($opts['provider'])) {
                $callOpts['provider'] = $opts['provider'];
            }

            try {
                $result = $this->bus->call($capId, $payload, $callOpts);
                $ok = $this->assertExpected($result, $case['expect'] ?? null);
                if ($ok === true) {
                    $passed++;
                } else {
                    $failed++;
                    $failures[] = ['name' => $name, 'error' => $ok];
                }
            } catch (\Throwable $e) {
                $failed++;
                $failures[] = ['name' => $name, 'error' => $e->getMessage()];
            }
        }

        return [
            'ok' => $failed === 0,
            'passed' => $passed,
            'failed' => $failed,
            'failures' => $failures,
        ];
    }

    private function assertExpected(mixed $result, mixed $expect): bool|string
    {
        if (!is_array($expect) || !isset($expect['result']) || !is_array($expect['result'])) {
            return 'Missing expect.result';
        }

        $er = $expect['result'];

        if (!empty($er['is_null'])) {
            return $result === null ? true : 'Expected null result';
        }

        if (isset($er['has_keys']) && is_array($er['has_keys'])) {
            if (!is_array($result)) {
                return 'Expected result to be an object/array';
            }
            foreach ($er['has_keys'] as $k) {
                if (!is_string($k)) continue;
                if (!array_key_exists($k, $result)) {
                    return "Missing key: {$k}";
                }
            }
        }

        if (isset($er['equals']) && is_array($er['equals'])) {
            if (!is_array($result)) {
                return 'Expected result to be an object/array for equals assertions';
            }
            foreach ($er['equals'] as $k => $v) {
                if (!array_key_exists((string)$k, $result)) {
                    return "Missing key for equals: {$k}";
                }
                if ($result[(string)$k] !== $v) {
                    return "Expected {$k} to equal " . json_encode($v) . ", got " . json_encode($result[(string)$k]);
                }
            }
        }

        return true;
    }
}
