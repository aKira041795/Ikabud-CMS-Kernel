<?php

declare(strict_types=1);

/**
 * Which modules register something at INCLUDE time — the evidence behind
 * docs/architecture/decisions/ADR-006-registration-implementation-separation.md.
 *
 * WHY THIS EXISTS: every request includes every enabled module's helpers.php before any route is matched
 * (public/index.php:490 -> loadModuleRoutes() -> loadModuleHelpers() per module). Whether that can be made
 * lazy depends on one question, and it cannot be answered by grep:
 *
 *     does including a module's helpers.php DO anything, or only define things?
 *
 *     * if it only defines, its include cost can be deferred to the module whose route matched;
 *     * if it registers (an event listener, a hook, a constant), skipping it silently removes behaviour —
 *       skip `search` and content stops reindexing, skip `contact-form` and its admin nav entry vanishes,
 *       with no error and no log line.
 *
 * WHY IT MEASURES INSTEAD OF PARSING: two static analyses of this exact question were wrong in opposite
 * directions in one session. A comment-blind grep flagged all 13 `cms-akira` modules because their
 * `->listen(` hits are commented-out examples; a tokenizer that dropped function bodies then MISSED a real
 * top-level listener in modules/search/helpers.php, because a `{$var}` inside a string emits a `}` token
 * that corrupts brace-depth tracking. So this bootstraps the kernel and diffs the observable registrations
 * around each include. What changes is, by definition, what that module registers.
 *
 * NOTE ON SCOPE: this identifies WHICH modules register and what they register. It is evidence, not a
 * migration basis — ADR-006 explicitly rejects lax inclusion decided by source inspection, and warns that
 * `get_included_files()` / `function_exists()` / global constants are themselves observable, so this
 * instrument will need rethinking once composition stops being eager.
 *
 * Read-only: no writes, no DDL. Run: php tools/chair-module-registration-audit.php
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';

/** Event names that currently have listeners. */
function chairAuditEventSnapshot(): array
{
    try {
        $bus = \Ikabud\Kernel\EventBus::getInstance();
        $counts = [];
        foreach ((array)$bus->registeredEvents() as $event) {
            $counts[(string)$event] = $bus->listenerCount((string)$event);
        }
        return $counts;
    } catch (Throwable $e) {
        return [];
    }
}

/** Hook names that currently have listeners, read through reflection: Hooks exposes no listing. */
function chairAuditHookSnapshot(): array
{
    try {
        $hooks = \Ikabud\Kernel\Hooks::getInstance();
        $prop = (new ReflectionClass($hooks))->getProperty('listeners');
        // No setAccessible(): a no-op since PHP 8.1, and deprecated on 8.5, where it
        // wrote 69 deprecation lines into storage/logs/error.log per run.
        $counts = [];
        foreach ((array)$prop->getValue($hooks) as $hook => $entries) {
            $counts[(string)$hook] = is_array($entries) ? count($entries) : 0;
        }
        return $counts;
    } catch (Throwable $e) {
        return [];
    }
}

function chairAuditConstantSnapshot(): array
{
    $all = get_defined_constants(true);
    return array_keys($all['user'] ?? []);
}

/** Keys whose count changed, plus keys that appeared. */
function chairAuditDelta(array $before, array $after): array
{
    $delta = [];
    foreach ($after as $key => $count) {
        $prior = $before[$key] ?? 0;
        if ($prior !== $count) {
            $delta[$key] = $prior === 0 ? 'new' : $prior . '->' . $count;
        }
    }
    return $delta;
}

echo "== include-time registration per module ==\n\n";

$modules = getEnabledModules();
printf("enabled modules: %d\n\n", count($modules));

$events = chairAuditEventSnapshot();
$hooks = chairAuditHookSnapshot();
$constants = chairAuditConstantSnapshot();

$registrars = [];
$inert = [];

foreach ($modules as $module) {
    $id = (string)($module['id'] ?? '');
    $beforeE = $events;
    $beforeH = $hooks;
    $beforeC = $constants;

    $started = hrtime(true);
    $error = null;
    try {
        loadModuleHelpers($module);
    } catch (Throwable $e) {
        $error = get_class($e) . ': ' . $e->getMessage();
    }
    $ms = (hrtime(true) - $started) / 1_000_000;

    $events = chairAuditEventSnapshot();
    $hooks = chairAuditHookSnapshot();
    $constants = chairAuditConstantSnapshot();

    $entry = [
        'id' => $id,
        'ms' => $ms,
        'events' => chairAuditDelta($beforeE, $events),
        'hooks' => chairAuditDelta($beforeH, $hooks),
        'constants' => array_values(array_diff($constants, $beforeC)),
        'error' => $error,
    ];

    if ($entry['events'] !== [] || $entry['hooks'] !== [] || $entry['constants'] !== []) {
        $registrars[] = $entry;
    } else {
        $inert[] = $entry;
    }
}

$render = static function (array $map): string {
    return implode(', ', array_map(
        static fn($k, $v) => $k . '(' . $v . ')',
        array_keys($map),
        array_values($map)
    ));
};

printf("REGISTER AT INCLUDE TIME: %d of %d\n", count($registrars), count($modules));
foreach ($registrars as $r) {
    printf("  %-34s %7.2f ms", $r['id'], $r['ms']);
    if ($r['events'] !== []) {
        printf("  events: %s", $render($r['events']));
    }
    if ($r['hooks'] !== []) {
        printf("  hooks: %s", $render($r['hooks']));
    }
    if ($r['constants'] !== []) {
        printf("  constants: %s", implode(', ', $r['constants']));
    }
    if ($r['error'] !== null) {
        printf("  ERROR: %s", $r['error']);
    }
    echo "\n";
}

$sum = static fn(array $rows): float => array_sum(array_map(static fn($r) => (float)$r['ms'], $rows));
$inertMs = $sum($inert);
$registrarMs = $sum($registrars);
$totalMs = $inertMs + $registrarMs;

printf("\nINERT (define only, no registration): %d\n", count($inert));
printf("  inert include cost      : %7.2f ms\n", $inertMs);
printf("  registering include cost: %7.2f ms\n", $registrarMs);
printf("  total this run          : %7.2f ms\n", $totalMs);
printf("\n  skipping the inert set before routing would remove %.0f%% of the include cost\n",
    $totalMs > 0 ? 100 * $inertMs / $totalMs : 0);

echo <<<'NOTE'

  READ THE NUMBER WITH ITS LIMITS (ADR-006, gates 1-3):
    * this run has OPcache OFF in CLI. The same files cost ~40x less in the web SAPI with OPcache on, so
      this is the shape of the cost, not the production latency.
    * the total is not the saving. Deferring implementation loading only helps requests that would never
      have needed it; for a request that uses the module anyway the cost moves, it does not disappear.
    * a percentage of INCLUDE cost is not a percentage of REQUEST time.

NOTE;
