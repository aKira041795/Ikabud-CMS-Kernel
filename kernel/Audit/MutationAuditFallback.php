<?php
declare(strict_types=1);

namespace Ikabud\Kernel\Audit;

use Ikabud\Kernel\Contracts\ModuleContext;

/**
 * Mutation audit fallback.
 *
 * A module handler is expected to describe its own state changes via
 * ModuleContext::audit(). When one does not, the change would vanish from the
 * audit trail entirely — the trail would be a partial record that reads like a
 * complete one, which is worse than having no trail at all.
 *
 * This class is the kernel's safety net for that gap. It records a generic row
 * for a state-mutating request that finished successfully without any handler
 * writing a specific one, so nothing is silently unlogged.
 *
 * Scope is deliberate and configured, never blanket: `app.audit
 * .mutation_fallback_modules` lists the modules that opt in. A module not in
 * that list is untouched. The mechanism lives here in the kernel so enabling a
 * module later is configuration, not new plumbing.
 *
 * This records *that* a change happened, not what changed. A module with real
 * audit coverage should keep it — a specific row always beats the fallback.
 *
 * Known boundary: writes are observed on the module's scoped database
 * (ModuleDB), which is the enforced path for a module's own tables. A write that
 * bypasses it — kernel-escalated module settings, for instance — is not seen, so
 * a handler that only writes that way is not covered. dc-cafe audits its
 * settings explicitly, and a module with such writes needs its own audit calls
 * rather than relying on this net.
 */
final class MutationAuditFallback
{
    /**
     * Methods that change state. PATCH is included even though the module
     * dispatcher does not route it today, so adding one cannot silently skip
     * the net.
     */
    private const MUTATING_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    /** Guards against registering more than one shutdown hook per request. */
    private static bool $registered = false;

    /**
     * Whether the handler issued a write statement.
     *
     * A state-mutating HTTP method is not proof of a state change — some POSTs
     * are lookups (quote a voucher, price a cart). Recording those would put
     * rows in the trail claiming something changed when nothing did, which is
     * the same loss of trust as a missing row. A write statement is the actual
     * evidence, so the net waits for one.
     *
     * Counted only from the moment the net is armed, so writes made while
     * bootstrapping or by other modules are not mistaken for the handler's.
     */
    private static bool $wroteThisRequest = false;

    /**
     * Whether the fallback covers this module.
     */
    public static function enabledFor(string $moduleId): bool
    {
        if ($moduleId === '') {
            return false;
        }
        return in_array($moduleId, self::configuredModules(), true);
    }

    /**
     * Modules opted in via config/app.php.
     *
     * @return array<int, string>
     */
    public static function configuredModules(): array
    {
        $configured = function_exists('config')
            ? config('app.audit.mutation_fallback_modules', [])
            : [];

        if (is_string($configured)) {
            $configured = array_map('trim', explode(',', $configured));
        }
        if (!is_array($configured)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn($m) => trim((string) $m), $configured),
            static fn($m) => $m !== ''
        ));
    }

    /**
     * Whether this HTTP method changes state.
     */
    public static function isMutating(string $method): bool
    {
        return in_array(strtoupper($method), self::MUTATING_METHODS, true);
    }

    /**
     * Note that the module handed a write statement to the database.
     *
     * Called by ModuleDB on its execution paths. Only INSERT/UPDATE/DELETE/
     * REPLACE count: a SELECT-through-a-POST changes nothing, and module-level
     * DDL is already forbidden. Sticky for the request — one write is enough to
     * make the request a mutation.
     */
    public static function noteStatement(string $sql): void
    {
        // Only observe while armed. Before the net is armed a write belongs to
        // bootstrap or to another module, and a module that never opted in pays
        // nothing at all — this sits on every statement ModuleDB runs.
        if (!self::$registered || self::$wroteThisRequest) {
            return;
        }
        if (preg_match('/\A\s*(?:\/\*.*?\*\/\s*)*(INSERT|UPDATE|DELETE|REPLACE)\b/i', $sql) === 1) {
            self::$wroteThisRequest = true;
        }
    }

    /**
     * Whether a write statement was observed since the net was armed.
     */
    public static function wrote(): bool
    {
        return self::$wroteThisRequest;
    }

    /**
     * Whether the shutdown hook is armed for this request.
     */
    public static function isRegistered(): bool
    {
        return self::$registered;
    }

    /**
     * Clear per-request state. For long-running processes (CLI, tests, workers)
     * that serve more than one logical request.
     */
    public static function resetTracking(): void
    {
        self::$registered = false;
        self::$wroteThisRequest = false;
    }

    /**
     * Arrange for an unaudited mutation to be recorded when the request ends.
     *
     * A shutdown hook rather than a wrapper because handlers are free to exit —
     * a JSON handler that calls exit() on success would escape any code placed
     * after the callable returns, which is exactly the case this must cover.
     */
    public static function register(string $moduleId, string $method, string $path): void
    {
        if (self::$registered || !self::enabledFor($moduleId) || !self::isMutating($method)) {
            return;
        }

        // Hold the connection the handler writes through. Re-resolving it after
        // the request has ended can land on the base database instead of the
        // tenant one (the request-tenant resolver is no longer available), which
        // would file the change in the wrong place. Without a connection there
        // is nothing trustworthy to write to, so the net stands down rather than
        // record the change somewhere it did not happen.
        try {
            $connection = app()->db();
        } catch (\Throwable $e) {
            return;
        }

        self::$registered = true;

        // Writes issued before this point belong to bootstrap or to another
        // module; only what the handler does is evidence about the handler.
        // Starting the count here is what makes "a POST that only reads is not
        // recorded" actually true.
        self::$wroteThisRequest = false;

        $method = strtoupper($method);

        register_shutdown_function(static function () use ($moduleId, $method, $path, $connection): void {
            try {
                // A fatal error means the request did not complete as designed;
                // whatever it wrote may have been rolled back or half-applied.
                // Recording success here would be a false statement.
                $lastError = error_get_last();
                if (is_array($lastError) && in_array(
                    (int) ($lastError['type'] ?? 0),
                    [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR],
                    true
                )) {
                    return;
                }

                // Nothing was written, so nothing changed.
                if (!self::$wroteThisRequest) {
                    return;
                }

                // A handler already described this change; the specific row wins.
                if (ModuleContext::auditWritten()) {
                    return;
                }

                // Only successful mutations. A rejected request changed nothing,
                // and failures worth keeping are the handler's to record with
                // their own detail.
                //
                // Under a CLI/worker SAPI no status code is set and
                // http_response_code() reports false; that is "no failure
                // declared", not an error code, so it passes.
                $status = function_exists('http_response_code') ? http_response_code() : false;
                if ($status !== false && ((int) $status < 200 || (int) $status >= 300)) {
                    return;
                }

                $ctx = function_exists('module') ? module($moduleId) : null;
                if (!$ctx instanceof ModuleContext) {
                    return;
                }

                // The method and path go in new_data: there is no record to
                // point at, so the request itself is the detail.
                $ctx->audit('request.mutated', null, null, null, null, [
                    'method' => $method,
                    'path' => $path,
                ], null, $connection);
            } catch (\Throwable $e) {
                // A safety net that breaks a response is worse than a gap in the
                // trail. Log and move on.
                if (function_exists('write_log')) {
                    write_log('audit.mutation_fallback_failed', 'warning', [
                        'module' => $moduleId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        });
    }
}
