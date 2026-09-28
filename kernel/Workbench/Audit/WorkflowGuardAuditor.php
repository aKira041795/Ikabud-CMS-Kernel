<?php

/**
 * Port provenance: copied from /var/www/html/ikabudsix.
 * The ikabudsix and applicationostest lineages have no merge base; do not merge histories.
 */

declare(strict_types=1);

namespace Ikabud\Kernel\Workbench\Audit;

/**
 * Deterministic, method-local regression tripwire for WorkflowEngine.
 *
 * HEURISTIC GUARANTEE: this auditor checks for the presence and ordering of
 * canonical hardened source patterns in kernel/WorkflowEngine.php using a
 * token_get_all()-based statement model. It is not a correctness prover and
 * does not establish feasible-path behavior, reaching definitions, resolved
 * values at program points, or all-path exit guarantees. Those dataflow
 * escalations are explicitly out of scope. Runtime invariant checks, Phase-1
 * concurrency tests, and human review remain the correctness authorities.
 */
final class WorkflowGuardAuditor
{
    /** @return list<array{code: string, severity: string, file: string, line: int, message: string}> */
    public function audit(string $file): array
    {
        $source = @file_get_contents($file);
        if ($source === false) {
            return [$this->finding('WORKFLOW_SOURCE_UNREADABLE', 'critical', $file, 1, 'WorkflowEngine source could not be read.')];
        }

        $scan = $this->withoutComments($source);
        $findings = [];
        $start = $this->methodModel($scan, 'start');
        $advance = $this->methodModel($scan, 'advance');
        $cancel = $this->methodModel($scan, 'cancel');
        $replay = $this->methodModel($scan, 'replay');
        $interrupt = $this->methodModel($scan, 'interruptStepAfterDispatch');

        $this->auditStart($file, $start, $findings);
        $this->auditAdvance($file, $advance, $findings);
        $this->auditRunMutation($file, 'cancel', $cancel, $findings);
        $this->auditRunMutation($file, 'replay', $replay, $findings);
        $this->auditFailClosed($file, $advance, $interrupt, $findings);
        $this->auditMysqlCompatibility($file, $scan, $findings);

        usort($findings, static fn (array $a, array $b): int => [$a['line'], $a['code']] <=> [$b['line'], $b['code']]);
        return $findings;
    }

    /** @param array<string, mixed>|null $method
     * @param list<array{code: string, severity: string, file: string, line: int, message: string}> $findings */
    private function auditStart(string $file, ?array $method, array &$findings): void
    {
        if ($method === null) {
            $findings[] = $this->finding('WORKFLOW_START_LOCK_SYMMETRY', 'critical', $file, 1, 'start() is missing; its tuple lock cannot be verified.');
            return;
        }

        $getCandidate = $this->firstSql($method, '~\bGET_LOCK\s*\(~i');
        $get = $this->firstExecutedSql($method, '~\bGET_LOCK\s*\(~i');
        $release = $this->firstExecutedSql($method, '~\bRELEASE_LOCK\s*\(~i');
        $sameName = $get !== null && $release !== null
            && $this->boundLockName($get, 'GET_LOCK') !== null
            && $this->boundLockName($get, 'GET_LOCK') === $this->boundLockName($release, 'RELEASE_LOCK');
        $directFinally = $release !== null
            && ($release['region'] ?? '') === 'finally'
            && ($release['direct_control'] ?? '') === ($release['region_id'] ?? '')
            && ($release['execution_direct_control'] ?? '') === ($release['region_id'] ?? '')
            && !$this->isConditionalExpression((string)$release['text'])
            && !$this->isConditionalExpression((string)($release['execution_text'] ?? ''));
        if ($get === null || $release === null || $release['offset'] < $get['offset'] || !$sameName || !$directFinally) {
            $findings[] = $this->atStatement(
                'WORKFLOW_START_LOCK_SYMMETRY',
                'critical',
                $file,
                $method,
                $get ?? $getCandidate,
                'start() must execute release of the same bound tuple-lock value as unconditional finally cleanup.'
            );
        }

        $insertCandidate = $this->firstSql($method, '~^\s*INSERT\s+INTO\s+workflow_runs\b~i');
        $insert = $this->firstExecutedSql($method, '~^\s*INSERT\s+INTO\s+workflow_runs\b~i');
        $dedupe = $this->firstStatement($method, static fn (array $s): bool => $s['reachable']
            && str_contains((string)$s['text'], 'findActiveRun('));
        if ($insert === null || $dedupe === null || $dedupe['offset'] > $insert['offset']) {
            $findings[] = $this->atStatement(
                'WORKFLOW_START_DEDUPE',
                'critical',
                $file,
                $method,
                $insert ?? $insertCandidate,
                'start() must reach findActiveRun() before executing a workflow-run INSERT.'
            );
        }
    }

    /** @param array<string, mixed>|null $method
     * @param list<array{code: string, severity: string, file: string, line: int, message: string}> $findings */
    private function auditAdvance(string $file, ?array $method, array &$findings): void
    {
        if ($method === null) {
            $findings[] = $this->finding('WORKFLOW_STEP_CLAIM_GUARD', 'critical', $file, 1, 'advance() is missing; atomic claiming cannot be verified.');
            return;
        }

        $claimPattern = "~^\\s*UPDATE\\s+workflow_run_steps\\s+SET\\s+status\\s*=\\s*['\"]running['\"]~i";
        $claimCandidate = $this->firstSql($method, $claimPattern);
        $claim = $this->firstExecutedSql($method, $claimPattern);
        $claimSafe = $claim !== null && $this->statusInValues($claim, 'pending', 'failed');
        if (!$claimSafe) {
            $findings[] = $this->atStatement(
                'WORKFLOW_STEP_CLAIM_GUARD',
                'critical',
                $file,
                $method,
                $claim ?? $claimCandidate,
                'advance() must execute one claim UPDATE restricted to bound pending/failed statuses.'
            );
        }
        if ($claim === null || preg_match('~\battempt\s*=\s*attempt\s*\+\s*1\b~i', (string)$claim['sql']) !== 1) {
            $findings[] = $this->atStatement(
                'WORKFLOW_STEP_ATTEMPT_ATOMIC',
                'critical',
                $file,
                $method,
                $claim ?? $claimCandidate,
                'advance() must increment attempt in the executed conditional claim UPDATE.'
            );
        }

        $guard = $claim === null ? null : $this->rowCountControl($method, (string)($claim['variable'] ?? ''), '!==', 1, (int)$claim['offset']);
        $rejects = $guard !== null
            && $this->directStatementMatches($method, (string)$guard['id'], '~->rollBack\s*\(~')
            && $this->directStatementMatches($method, (string)$guard['id'], '~^\s*return\s+\$this->runBusyResult\s*\(~');
        if (!$rejects) {
            $findings[] = $this->atStatement(
                'WORKFLOW_STEP_CLAIM_ROWCOUNT',
                'critical',
                $file,
                $method,
                $claim ?? $claimCandidate,
                'The reachable claim-failure branch must directly roll back and return busy.'
            );
        }

        $begin = $this->firstStatement($method, static fn (array $s): bool => $s['reachable'] && $s['kind'] === 'begin');
        $runLock = $this->firstExecutedSql($method, '~\bFROM\s+workflow_runs\b.*\bFOR\s+UPDATE\b~is');
        $commit = $this->firstStatementAfter($method, 'commit', (int)($claim['offset'] ?? 0));
        $dispatches = $this->statements($method, static fn (array $s): bool => $s['reachable'] && $s['kind'] === 'dispatch');
        $dispatch = $dispatches[0] ?? null;
        $ordered = $begin !== null && $runLock !== null && $claim !== null && $guard !== null
            && $commit !== null && $dispatch !== null
            && $begin['offset'] < $runLock['offset'] && $runLock['offset'] < $claim['offset']
            && $claim['offset'] < $guard['offset'] && $guard['offset'] < $commit['offset']
            && $commit['offset'] < $dispatch['offset'];
        foreach ($dispatches as $candidate) {
            $latestLock = $this->lastExecutedSqlBefore($method, '~\bFROM\s+workflow_runs\b.*\bFOR\s+UPDATE\b~is', (int)$candidate['offset']);
            if ($latestLock !== null) {
                $closingCommit = $this->firstStatementAfter($method, 'commit', (int)$latestLock['offset']);
                if ($closingCommit === null || $closingCommit['offset'] > $candidate['offset']) {
                    $ordered = false;
                    $dispatch = $candidate;
                    break;
                }
            }
        }
        if (!$ordered) {
            $findings[] = $this->atStatement(
                'WORKFLOW_DISPATCH_OUTSIDE_LOCK',
                'critical',
                $file,
                $method,
                $dispatch ?? $runLock,
                'advance() must begin, execute the run lock and claim, reject contention, commit, then dispatch.'
            );
        }
    }

    /** @param array<string, mixed>|null $method
     * @param list<array{code: string, severity: string, file: string, line: int, message: string}> $findings */
    private function auditRunMutation(string $file, string $name, ?array $method, array &$findings): void
    {
        $upper = strtoupper($name);
        if ($method === null) {
            $findings[] = $this->finding("WORKFLOW_{$upper}_RUN_GUARD", 'critical', $file, 1, "{$name}() is missing; its run guard cannot be verified.");
            return;
        }

        $mutations = $this->executedSqlStatements($method, '~^\s*UPDATE\s+workflow_run_steps\b.*\bSET\b.*\bstatus\s*=~is');
        $anchor = $mutations[0] ?? null;
        $lock = $this->firstExecutedSql($method, '~\bFROM\s+workflow_runs\b.*\bFOR\s+UPDATE\b~is');
        $blocked = $this->firstStatement($method, static fn (array $s): bool => $s['reachable']
            && preg_match('~^\s*\$[A-Za-z_]\w*\s*=.*\bfindBlockedStep\s*\(~s', (string)$s['text']) === 1);
        $blockedVar = $blocked !== null && preg_match('~^\s*(\$[A-Za-z_]\w*)\s*=~', (string)$blocked['text'], $match) === 1
            ? $match[1]
            : '';
        $busyGuard = $blockedVar === '' ? null : $this->firstControl($method, static fn (array $c): bool => $c['kind'] === 'if'
            && $c['reachable'] && preg_match(
                '~' . preg_quote($blockedVar, '~') . "\\s*\\[['\"]status['\"]\\]\\s*===\\s*['\"]running['\"]~",
                (string)$c['condition']
            ) === 1);
        $busyExit = $busyGuard !== null
            && $this->directStatementMatches($method, (string)$busyGuard['id'], '~^\s*return\s+\$this->runBusyResult\s*\(~');
        $effective = $lock !== null && $blocked !== null && $busyGuard !== null && $busyExit && $mutations !== []
            && $lock['offset'] < $blocked['offset'] && $blocked['offset'] < $busyGuard['offset']
            && $busyGuard['end'] < $mutations[0]['offset'];
        if (!$effective) {
            $findings[] = $this->atStatement(
                "WORKFLOW_{$upper}_RUN_GUARD",
                'critical',
                $file,
                $method,
                $anchor,
                "{$name}() must execute the run lock and reach an early running-step busy return before mutation."
            );
        }

        foreach ($mutations as $mutation) {
            $predicate = $this->whereClause((string)$mutation['sql']);
            $safeStatus = $this->predicateExcludesRunning($predicate, (array)$mutation['params']);
            $safeTargetedRange = $effective
                && preg_match('~\bordinal\s*>=\s*\(\s*SELECT\s+MIN\s*\(\s*ordinal\s*\)~i', $predicate) === 1;
            if (!$safeStatus && !$safeTargetedRange) {
                $findings[] = $this->atStatement(
                    "WORKFLOW_{$upper}_RUNNING_RECLAIM",
                    'critical',
                    $file,
                    $method,
                    $mutation,
                    "{$name}() step mutation predicate must prove exclusion of the bound running status."
                );
            }
        }
    }

    /**
     * @param array<string, mixed>|null $advance
     * @param array<string, mixed>|null $interrupt
     * @param list<array{code: string, severity: string, file: string, line: int, message: string}> $findings
     */
    private function auditFailClosed(string $file, ?array $advance, ?array $interrupt, array &$findings): void
    {
        $completePattern = "~^\\s*UPDATE\\s+workflow_run_steps\\s+SET\\s+status\\s*=\\s*['\"]completed['\"]~i";
        $completeCandidate = $advance === null ? null : $this->firstSql($advance, $completePattern);
        $complete = $advance === null ? null : $this->firstExecutedSql($advance, $completePattern);
        $branch = $advance === null || $complete === null
            ? null
            : $this->rowCountControl($advance, (string)($complete['variable'] ?? ''), '!==', 1, (int)$complete['offset']);
        $associated = false;
        if ($advance !== null && $branch !== null) {
            $directInterrupt = $this->directStatementMatches($advance, (string)$branch['id'], '~interruptStepAfterDispatch\s*\(~');
            $directReturn = $this->directStatementMatches($advance, (string)$branch['id'], '~^\s*return\b~');
            $directThrow = $this->directStatementMatches($advance, (string)$branch['id'], '~^\s*throw\b~');
            if ($directInterrupt && $directReturn) {
                $associated = true;
            } elseif ($directThrow && ($branch['try_id'] ?? null) !== null) {
                $tryId = (string)$branch['try_id'];
                $catchIds = (array)($advance['try_catches'][$tryId] ?? []);
                foreach ($catchIds as $catchId) {
                    if ($this->directStatementMatches($advance, (string)$catchId, '~interruptStepAfterDispatch\s*\(~')
                        && $this->directStatementMatches($advance, (string)$catchId, '~^\s*return\b~')) {
                        $associated = true;
                        break;
                    }
                }
            }
        }

        $helperSafe = $interrupt !== null && $this->interruptHelperSafe($interrupt);
        if ($advance === null || $complete === null || !$associated || !$helperSafe) {
            $method = $advance ?? $interrupt;
            if ($method === null) {
                $findings[] = $this->finding('WORKFLOW_POST_DISPATCH_FAIL_CLOSED', 'critical', $file, 1, 'Post-dispatch persistence cannot be verified.');
                return;
            }
            $findings[] = $this->atStatement(
                'WORKFLOW_POST_DISPATCH_FAIL_CLOSED',
                'critical',
                $file,
                $method,
                $complete ?? $completeCandidate,
                'The canonical completion UPDATE must be followed by the recognized fail-closed throw/interrupt pattern.'
            );
        }
    }

    /** @param array<string, mixed> $method */
    private function interruptHelperSafe(array $method): bool
    {
        $update = $this->firstExecutedSql(
            $method,
            "~^\\s*UPDATE\\s+workflow_run_steps\\s+SET\\s+status\\s*=\\s*['\"]interrupted['\"].*\\bWHERE\\b.*\\bstatus\\s*=\\s*['\"]running['\"]~is"
        );
        if ($update === null) {
            return false;
        }
        $success = $this->rowCountControl($method, (string)($update['variable'] ?? ''), '===', 1, (int)$update['offset']);
        if ($success === null || !$this->directStatementMatches($method, (string)$success['id'], "~^\\s*return\\s+['\"]interrupted['\"]~")) {
            return false;
        }
        foreach ($this->executedSqlStatements($method, '~^\s*UPDATE\s+workflow_run_steps\b~i') as $mutation) {
            if (preg_match("~SET\\s+status\\s*=\\s*['\"](?:pending|failed)['\"]~i", (string)$mutation['sql']) === 1) {
                return false;
            }
        }
        return $this->firstStatement($method, static fn (array $s): bool => $s['reachable']
            && $s['kind'] === 'return' && $s['direct_control'] === ''
            && preg_match("~^\\s*return\\s+['\"]running['\"]~", (string)$s['text']) === 1) !== null;
    }

    /** @param list<array{code: string, severity: string, file: string, line: int, message: string}> $findings */
    private function auditMysqlCompatibility(string $file, string $source, array &$findings): void
    {
        $patterns = [
            'MYSQL8_WINDOW_FUNCTION' => ['~\bOVER\s*\(~i', 'Window-function SQL is not compatible with MySQL 5.7.'],
            'MYSQL8_CTE' => ['~\bWITH\s+[a-z_][a-z0-9_]*(?:\s*\([^)]*\))?\s+AS\s*\(~i', 'CTE SQL is not compatible with MySQL 5.7.'],
            'MYSQL8_JSON_TABLE' => ['~\bJSON_TABLE\s*\(~i', 'JSON table expansion is not compatible with MySQL 5.7.'],
            'MYSQL8_SKIP_LOCKED' => ['~\bSKIP\s+LOCKED\b~i', 'SKIP LOCKED SQL is not compatible with MySQL 5.7.'],
        ];
        foreach ($patterns as $code => [$pattern, $message]) {
            preg_match_all($pattern, $source, $matches, PREG_OFFSET_CAPTURE);
            foreach ($matches[0] as $match) {
                $findings[] = $this->finding($code, 'major', $file, $this->lineAt($source, $match[1]), $message);
            }
        }
    }

    /** @return array<string, mixed>|null */
    private function methodModel(string $source, string $name): ?array
    {
        $tokens = $this->tokens($source);
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            if (($tokens[$i]['id'] ?? null) !== T_FUNCTION) {
                continue;
            }
            $nameIndex = $this->nextSignificant($tokens, $i + 1);
            if ($nameIndex === null || ($tokens[$nameIndex]['text'] ?? '') === '&') {
                $nameIndex = $nameIndex === null ? null : $this->nextSignificant($tokens, $nameIndex + 1);
            }
            if ($nameIndex === null || ($tokens[$nameIndex]['text'] ?? '') !== $name) {
                continue;
            }
            $open = $this->nextTokenText($tokens, $nameIndex + 1, '{');
            if ($open === null) {
                return null;
            }
            $close = $this->matchingToken($tokens, $open, '{', '}');
            if ($close === null) {
                return null;
            }
            $methodOffset = (int)$tokens[$i]['offset'];
            $model = [
                'source' => substr($source, $methodOffset, (int)$tokens[$close]['offset'] + 1 - $methodOffset),
                'offset' => $methodOffset,
                'line' => (int)$tokens[$i]['line'],
                'statements' => [],
                'controls' => [],
                'operations' => [],
                'try_catches' => [],
            ];
            $context = ['parents' => [], 'try_id' => null, 'region' => '', 'region_id' => ''];
            $this->parseBlock($tokens, $open + 1, $close, true, $context, $model);
            usort($model['statements'], static fn (array $a, array $b): int => $a['offset'] <=> $b['offset']);
            $this->associateExecutions($model);
            // The contract-facing ordered operation list includes both leaf
            // statements and their if/try/finally control-flow parents.
            $model['operations'] = array_merge($model['statements'], $model['controls']);
            usort($model['operations'], static fn (array $a, array $b): int => $a['offset'] <=> $b['offset']);
            return $model;
        }
        return null;
    }

    /**
     * @param list<array{id: int|null, text: string, line: int, offset: int}> $tokens
     * @param array<string, mixed> $context
     * @param array<string, mixed> $model
     */
    private function parseBlock(array $tokens, int $start, int $end, bool $reachable, array $context, array &$model): bool
    {
        $terminated = false;
        for ($i = $start; $i < $end;) {
            $i = $this->nextSignificant($tokens, $i) ?? $end;
            if ($i >= $end) {
                break;
            }
            $id = $tokens[$i]['id'];
            if ($id === T_IF) {
                [$i, $ifTerminates] = $this->parseIf($tokens, $i, $end, $reachable && !$terminated, $context, $model);
                if ($ifTerminates && $reachable && !$terminated) {
                    $terminated = true;
                }
                continue;
            }
            if ($id === T_TRY) {
                $i = $this->parseTry($tokens, $i, $end, $reachable && !$terminated, $context, $model);
                continue;
            }
            $statementEnd = $this->statementEnd($tokens, $i, $end);
            $statement = $this->makeStatement($tokens, $i, $statementEnd, $reachable && !$terminated, $context);
            $model['statements'][] = $statement;
            if ($statement['reachable'] && in_array($statement['kind'], ['return', 'throw'], true)) {
                $terminated = true;
            }
            $i = $statementEnd + 1;
        }
        return $terminated;
    }

    /**
     * @param list<array{id: int|null, text: string, line: int, offset: int}> $tokens
     * @param array<string, mixed> $context
     * @param array<string, mixed> $model
     * @return array{int, bool}
     */
    private function parseIf(array $tokens, int $index, int $end, bool $reachable, array $context, array &$model): array
    {
        $paren = $this->nextTokenText($tokens, $index + 1, '(');
        $parenEnd = $paren === null ? null : $this->matchingToken($tokens, $paren, '(', ')');
        $open = $parenEnd === null ? null : $this->nextSignificant($tokens, $parenEnd + 1);
        if ($paren === null || $parenEnd === null || $open === null || $tokens[$open]['text'] !== '{') {
            $statementEnd = $this->statementEnd($tokens, $index, $end);
            $model['statements'][] = $this->makeStatement($tokens, $index, $statementEnd, $reachable, $context);
            return [$statementEnd + 1, false];
        }
        $close = $this->matchingToken($tokens, $open, '{', '}') ?? $end - 1;
        $condition = trim($this->tokenText($tokens, $paren + 1, $parenEnd));
        $truth = $this->constantTruth($condition);
        $controlId = 'if:' . $tokens[$index]['offset'];
        $control = $this->control($tokens, $index, $close, 'if', $condition, $reachable && $truth !== false, $context, $controlId);
        $model['controls'][] = $control;
        $child = $context;
        $child['parents'][] = $controlId;
        $thenTerminates = $this->parseBlock($tokens, $open + 1, $close, $reachable && $truth !== false, $child, $model);
        $next = $this->nextSignificant($tokens, $close + 1) ?? $end;
        $elseTerminates = false;
        $hasElse = false;
        if ($next < $end && $tokens[$next]['id'] === T_ELSE) {
            $elseOpen = $this->nextSignificant($tokens, $next + 1);
            if ($elseOpen !== null && $tokens[$elseOpen]['text'] === '{') {
                $elseClose = $this->matchingToken($tokens, $elseOpen, '{', '}') ?? $end - 1;
                $elseId = 'else:' . $tokens[$next]['offset'];
                $model['controls'][] = $this->control($tokens, $next, $elseClose, 'if', 'else', $reachable && $truth !== true, $context, $elseId);
                $elseContext = $context;
                $elseContext['parents'][] = $elseId;
                $elseTerminates = $this->parseBlock($tokens, $elseOpen + 1, $elseClose, $reachable && $truth !== true, $elseContext, $model);
                $next = $elseClose + 1;
                $hasElse = true;
            }
        }
        $guaranteed = $truth === true ? $thenTerminates : ($truth === false ? ($hasElse && $elseTerminates) : ($hasElse && $thenTerminates && $elseTerminates));
        return [$next, $guaranteed];
    }

    /**
     * @param list<array{id: int|null, text: string, line: int, offset: int}> $tokens
     * @param array<string, mixed> $context
     * @param array<string, mixed> $model
     */
    private function parseTry(array $tokens, int $index, int $end, bool $reachable, array $context, array &$model): int
    {
        $open = $this->nextSignificant($tokens, $index + 1);
        if ($open === null || $tokens[$open]['text'] !== '{') {
            return $index + 1;
        }
        $close = $this->matchingToken($tokens, $open, '{', '}') ?? $end - 1;
        $tryId = 'try:' . $tokens[$index]['offset'];
        $model['controls'][] = $this->control($tokens, $index, $close, 'try', '', $reachable, $context, $tryId);
        $tryContext = $context;
        $tryContext['parents'][] = $tryId;
        $tryContext['try_id'] = $tryId;
        $tryContext['region'] = 'try';
        $tryContext['region_id'] = $tryId;
        $this->parseBlock($tokens, $open + 1, $close, $reachable, $tryContext, $model);
        $next = $this->nextSignificant($tokens, $close + 1) ?? $end;
        while ($next < $end && $tokens[$next]['id'] === T_CATCH) {
            $catchOpen = $this->nextTokenText($tokens, $next + 1, '{');
            if ($catchOpen === null) {
                break;
            }
            $catchClose = $this->matchingToken($tokens, $catchOpen, '{', '}') ?? $end - 1;
            $catchId = 'catch:' . $tokens[$next]['offset'];
            $model['controls'][] = $this->control($tokens, $next, $catchClose, 'try', 'catch', $reachable, $context, $catchId, $tryId);
            $model['try_catches'][$tryId][] = $catchId;
            $catchContext = $context;
            $catchContext['parents'][] = $catchId;
            $catchContext['try_id'] = $tryId;
            $catchContext['region'] = 'catch';
            $catchContext['region_id'] = $catchId;
            $this->parseBlock($tokens, $catchOpen + 1, $catchClose, $reachable, $catchContext, $model);
            $next = $this->nextSignificant($tokens, $catchClose + 1) ?? $end;
        }
        if ($next < $end && $tokens[$next]['id'] === T_FINALLY) {
            $finallyOpen = $this->nextSignificant($tokens, $next + 1);
            if ($finallyOpen !== null && $tokens[$finallyOpen]['text'] === '{') {
                $finallyClose = $this->matchingToken($tokens, $finallyOpen, '{', '}') ?? $end - 1;
                $finallyId = 'finally:' . $tokens[$next]['offset'];
                $model['controls'][] = $this->control($tokens, $next, $finallyClose, 'finally', '', $reachable, $context, $finallyId, $tryId);
                $finallyContext = $context;
                $finallyContext['parents'][] = $finallyId;
                $finallyContext['try_id'] = $tryId;
                $finallyContext['region'] = 'finally';
                $finallyContext['region_id'] = $finallyId;
                $this->parseBlock($tokens, $finallyOpen + 1, $finallyClose, $reachable, $finallyContext, $model);
                return $finallyClose + 1;
            }
        }
        return $next;
    }

    /**
     * @param list<array{id: int|null, text: string, line: int, offset: int}> $tokens
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function makeStatement(array $tokens, int $start, int $end, bool $reachable, array $context): array
    {
        $text = trim($this->tokenText($tokens, $start, $end + 1));
        $kind = 'call';
        if (($tokens[$start]['id'] ?? null) === T_RETURN) {
            $kind = 'return';
        } elseif (($tokens[$start]['id'] ?? null) === T_THROW) {
            $kind = 'throw';
        } elseif (preg_match('~->beginTransaction\s*\(~', $text) === 1) {
            $kind = 'begin';
        } elseif (preg_match('~->rollBack\s*\(~', $text) === 1) {
            $kind = 'rollback';
        } elseif (preg_match('~->commit\s*\(~', $text) === 1) {
            $kind = 'commit';
        } elseif (preg_match('~->cap\s*\(\s*\)\s*->call\s*\(~', $text) === 1) {
            $kind = 'dispatch';
        } elseif (preg_match('~->execute\s*\(~', $text) === 1) {
            $kind = 'execute';
        } elseif (preg_match('~->prepare\s*\(~', $text) === 1) {
            $kind = 'prepare';
        }
        $variable = preg_match('~^\s*(\$[A-Za-z_]\w*)\s*=~', $text, $match) === 1 ? $match[1] : null;
        $executeVariable = preg_match('~^\s*(\$[A-Za-z_]\w*)\s*->execute\s*\(~', $text, $match) === 1 ? $match[1] : null;
        $sql = $this->prepareSql($text);
        if ($sql !== null && preg_match('~^\s*UPDATE\b~i', $sql) === 1) {
            $kind = 'update';
        } elseif ($sql !== null && preg_match('~\b(?:GET_LOCK|RELEASE_LOCK)\s*\(~i', $sql) === 1) {
            $kind = 'lock call';
        }
        $parents = (array)$context['parents'];
        return [
            'kind' => $kind,
            'variable' => $variable,
            'execute_variable' => $executeVariable,
            'sql' => $sql,
            'params' => $this->executeParams($text),
            'text' => $text,
            'offset' => (int)$tokens[$start]['offset'],
            'line' => (int)$tokens[$start]['line'],
            'end' => (int)$tokens[$end]['offset'] + strlen((string)$tokens[$end]['text']),
            'reachable' => $reachable,
            'parents' => $parents,
            'direct_control' => $parents === [] ? '' : (string)end($parents),
            'try_id' => $context['try_id'],
            'region' => $context['region'],
            'region_id' => $context['region_id'],
            'chained_execute' => $sql !== null && preg_match('~\)\s*->execute\s*\(~s', $text) === 1,
            'executed' => false,
            'execution_direct_control' => null,
            'execution_text' => null,
        ];
    }

    /** @param array<string, mixed> $model */
    private function associateExecutions(array &$model): void
    {
        $bindings = [];
        foreach ($model['statements'] as $statement) {
            if (!$statement['reachable'] || preg_match(
                '~^\s*(\$[A-Za-z_]\w*)\s*->bindValue\s*\(\s*([\'\"]:[A-Za-z_]\w*[\'\"])\s*,\s*([^,)]+)~s',
                (string)$statement['text'],
                $match
            ) !== 1) {
                continue;
            }
            $bindings[$match[1]][$this->decodePhpString($match[2])] = $this->canonicalValue($match[3]);
        }
        foreach ($model['statements'] as $index => &$statement) {
            if ($statement['sql'] === null || !$statement['reachable']) {
                continue;
            }
            if ($statement['chained_execute']) {
                $statement['executed'] = true;
                $statement['execution_direct_control'] = $statement['direct_control'];
                $statement['execution_text'] = $statement['text'];
                continue;
            }
            $variable = $statement['variable'];
            if (!is_string($variable)) {
                continue;
            }
            foreach ($model['statements'] as $execution) {
                if ($execution['reachable'] && $execution['offset'] > $statement['offset']
                    && $execution['execute_variable'] === $variable) {
                    $statement['executed'] = true;
                    $statement['params'] = (array)$execution['params'] + (array)($bindings[$variable] ?? []);
                    $statement['execution_direct_control'] = $execution['direct_control'];
                    $statement['execution_text'] = $execution['text'];
                    break;
                }
            }
        }
        unset($statement);
    }

    /**
     * @param list<array{id: int|null, text: string, line: int, offset: int}> $tokens
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function control(array $tokens, int $start, int $end, string $kind, string $condition, bool $reachable, array $context, string $id, ?string $tryId = null): array
    {
        $parents = (array)$context['parents'];
        return [
            'id' => $id, 'kind' => $kind, 'condition' => $condition, 'reachable' => $reachable,
            'variable' => null, 'sql' => null, 'params' => [],
            'offset' => (int)$tokens[$start]['offset'], 'line' => (int)$tokens[$start]['line'],
            'end' => (int)$tokens[$end]['offset'] + strlen((string)$tokens[$end]['text']),
            'parents' => $parents, 'direct_control' => $parents === [] ? '' : (string)end($parents),
            'try_id' => $tryId ?? $context['try_id'],
        ];
    }

    /**
     * @param array<string, mixed> $method
     * @return array<string, mixed>|null
     */
    private function firstSql(array $method, string $pattern): ?array
    {
        return $this->firstStatement($method, static fn (array $statement): bool => is_string($statement['sql'])
            && preg_match($pattern, $statement['sql']) === 1);
    }

    /**
     * @param array<string, mixed> $method
     * @return array<string, mixed>|null
     */
    private function firstExecutedSql(array $method, string $pattern): ?array
    {
        $all = $this->executedSqlStatements($method, $pattern);
        return $all[0] ?? null;
    }

    /**
     * @param array<string, mixed> $method
     * @return list<array<string, mixed>>
     */
    private function executedSqlStatements(array $method, string $pattern): array
    {
        return $this->statements($method, static fn (array $s): bool => $s['reachable'] && $s['executed']
            && is_string($s['sql']) && preg_match($pattern, $s['sql']) === 1);
    }

    /**
     * @param array<string, mixed> $method
     * @return array<string, mixed>|null
     */
    private function lastExecutedSqlBefore(array $method, string $pattern, int $before): ?array
    {
        $found = null;
        foreach ($this->executedSqlStatements($method, $pattern) as $statement) {
            if ($statement['offset'] < $before) {
                $found = $statement;
            }
        }
        return $found;
    }

    /**
     * @param array<string, mixed> $method
     * @param callable(array<string, mixed>): bool $predicate
     * @return array<string, mixed>|null
     */
    private function firstStatement(array $method, callable $predicate): ?array
    {
        foreach ($method['statements'] as $statement) {
            if ($predicate($statement)) {
                return $statement;
            }
        }
        return null;
    }

    /**
     * @param array<string, mixed> $method
     * @param callable(array<string, mixed>): bool $predicate
     * @return list<array<string, mixed>>
     */
    private function statements(array $method, callable $predicate): array
    {
        return array_values(array_filter($method['statements'], $predicate));
    }

    /**
     * @param array<string, mixed> $method
     * @return array<string, mixed>|null
     */
    private function firstStatementAfter(array $method, string $kind, int $after): ?array
    {
        return $this->firstStatement($method, static fn (array $s): bool => $s['reachable']
            && $s['kind'] === $kind && $s['offset'] > $after);
    }

    /**
     * @param array<string, mixed> $method
     * @param callable(array<string, mixed>): bool $predicate
     * @return array<string, mixed>|null
     */
    private function firstControl(array $method, callable $predicate): ?array
    {
        foreach ($method['controls'] as $control) {
            if ($predicate($control)) {
                return $control;
            }
        }
        return null;
    }

    /**
     * @param array<string, mixed> $method
     * @return array<string, mixed>|null
     */
    private function rowCountControl(array $method, string $variable, string $operator, int $number, int $after): ?array
    {
        if ($variable === '') {
            return null;
        }
        return $this->firstControl($method, static fn (array $control): bool => $control['kind'] === 'if'
            && $control['reachable'] && $control['offset'] > $after
            && preg_match(
                '~^\s*' . preg_quote($variable, '~') . '\s*->rowCount\s*\(\s*\)\s*'
                    . preg_quote($operator, '~') . '\s*' . $number . '\s*$~',
                (string)$control['condition']
            ) === 1);
    }

    /** @param array<string, mixed> $method */
    private function directStatementMatches(array $method, string $controlId, string $pattern): bool
    {
        return $this->firstStatement($method, static fn (array $statement): bool => $statement['reachable']
            && $statement['direct_control'] === $controlId
            && preg_match($pattern, (string)$statement['text']) === 1) !== null;
    }

    /** @param array<string, mixed> $statement */
    private function boundLockName(array $statement, string $function): ?string
    {
        if (preg_match('~\b' . preg_quote($function, '~') . '\s*\(\s*([^,)]+)~i', (string)$statement['sql'], $match) !== 1) {
            return null;
        }
        $argument = trim($match[1]);
        if (preg_match("~^['\"]~", $argument) === 1) {
            return $this->canonicalValue($argument);
        }
        if (str_starts_with($argument, ':')) {
            return $statement['params'][$argument] ?? null;
        }
        return $this->canonicalValue($argument);
    }

    /** @param array<string, mixed> $statement */
    private function statusInValues(array $statement, string ...$expected): bool
    {
        $where = $this->whereClause((string)$statement['sql']);
        if (preg_match('~\bstatus\s+IN\s*\(([^)]*)\)~i', $where, $match) !== 1) {
            return false;
        }
        $values = $this->resolvedSqlValues($match[1], (array)$statement['params']);
        sort($values);
        sort($expected);
        return $values === $expected;
    }

    /** @param array<string, string> $params */
    private function predicateExcludesRunning(string $predicate, array $params): bool
    {
        if (preg_match('~\bstatus\s+IN\s*\(([^)]*)\)~i', $predicate, $match) === 1) {
            $values = $this->resolvedSqlValues($match[1], $params);
            return $values !== [] && !in_array('running', $values, true) && !in_array('?', $values, true);
        }
        if (preg_match("~\\bstatus\\s*=\\s*([^\\s)]+)~i", $predicate, $match) === 1) {
            $value = $this->resolveSqlValue($match[1], $params);
            return $value !== null && $value !== 'running';
        }
        if (preg_match("~\\bstatus\\s*(?:<>|!=)\\s*([^\\s)]+)~i", $predicate, $match) === 1) {
            return $this->resolveSqlValue($match[1], $params) === 'running';
        }
        return false;
    }

    /**
     * @param array<string, string> $params
     * @return list<string>
     */
    private function resolvedSqlValues(string $list, array $params): array
    {
        $values = [];
        foreach (explode(',', $list) as $raw) {
            $values[] = $this->resolveSqlValue(trim($raw), $params) ?? '?';
        }
        return $values;
    }

    /** @param array<string, string> $params */
    private function resolveSqlValue(string $raw, array $params): ?string
    {
        $raw = trim($raw, " \t\n\r\0\x0B`");
        if (str_starts_with($raw, ':')) {
            return $params[$raw] ?? null;
        }
        if (preg_match("~^(['\"]).*\\1$~s", $raw) === 1) {
            return $this->canonicalValue($raw);
        }
        return null;
    }

    private function whereClause(string $sql): string
    {
        $parts = preg_split('~\bWHERE\b~i', $sql, 2);
        return $parts[1] ?? '';
    }

    private function isConditionalExpression(string $text): bool
    {
        return str_contains($text, '&&') || str_contains($text, '||') || str_contains($text, '?');
    }

    private function prepareSql(string $text): ?string
    {
        $at = strpos($text, '->prepare');
        if ($at === false) {
            return null;
        }
        $open = strpos($text, '(', $at);
        $close = $open === false ? null : $this->matchingCharacter($text, $open, '(', ')');
        if ($open === false || $close === null) {
            return null;
        }
        $expression = substr($text, $open + 1, $close - $open - 1);
        $sql = '';
        foreach (token_get_all('<?php ' . $expression . ';') as $token) {
            if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING) {
                $sql .= $this->decodePhpString($token[1]);
            }
        }
        return $sql;
    }

    /** @return array<string, string> */
    private function executeParams(string $text): array
    {
        if (preg_match('~->execute\s*\(\s*\[(.*)\]\s*\)~sU', $text, $match) !== 1) {
            return [];
        }
        $params = [];
        if (preg_match_all("~(['\"]:[A-Za-z_]\\w*['\"])\\s*=>\\s*([^,\\]]+)~s", $match[1], $pairs, PREG_SET_ORDER) === false) {
            return [];
        }
        foreach ($pairs as $pair) {
            $params[$this->decodePhpString(trim($pair[1]))] = $this->canonicalValue($pair[2]);
        }
        return $params;
    }

    private function canonicalValue(string $expression): string
    {
        $expression = trim($expression);
        if (preg_match("~^(['\"]).*\\1$~s", $expression) === 1) {
            return strtolower($this->decodePhpString($expression));
        }
        return preg_replace('~\s+~', '', $expression) ?? $expression;
    }

    private function decodePhpString(string $literal): string
    {
        $literal = trim($literal);
        if (strlen($literal) < 2) {
            return $literal;
        }
        $quote = $literal[0];
        $value = substr($literal, 1, -1);
        return $quote === "'" ? str_replace(["\\\\", "\\'"], ["\\", "'"], $value) : stripcslashes($value);
    }

    private function constantTruth(string $condition): ?bool
    {
        $normalized = strtolower(trim($condition));
        if (in_array($normalized, ['false', '0', 'null'], true)) {
            return false;
        }
        if (in_array($normalized, ['true', '1'], true)) {
            return true;
        }
        return null;
    }

    /** @return list<array{id: int|null, text: string, line: int, offset: int}> */
    private function tokens(string $source): array
    {
        $result = [];
        $offset = 0;
        foreach (token_get_all($source) as $token) {
            $text = is_array($token) ? $token[1] : $token;
            $result[] = [
                'id' => is_array($token) ? $token[0] : null,
                'text' => $text,
                'line' => is_array($token) ? $token[2] : $this->lineAt($source, $offset),
                'offset' => $offset,
            ];
            $offset += strlen($text);
        }
        return $result;
    }

    /** @param list<array{id: int|null, text: string, line: int, offset: int}> $tokens */
    private function nextSignificant(array $tokens, int $start): ?int
    {
        for ($i = $start, $count = count($tokens); $i < $count; $i++) {
            if (!in_array($tokens[$i]['id'], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                return $i;
            }
        }
        return null;
    }

    /** @param list<array{id: int|null, text: string, line: int, offset: int}> $tokens */
    private function nextTokenText(array $tokens, int $start, string $text): ?int
    {
        for ($i = $start, $count = count($tokens); $i < $count; $i++) {
            if ($tokens[$i]['text'] === $text) {
                return $i;
            }
        }
        return null;
    }

    /** @param list<array{id: int|null, text: string, line: int, offset: int}> $tokens */
    private function matchingToken(array $tokens, int $open, string $left, string $right): ?int
    {
        $depth = 0;
        for ($i = $open, $count = count($tokens); $i < $count; $i++) {
            if ($tokens[$i]['text'] === $left) {
                $depth++;
            } elseif ($tokens[$i]['text'] === $right && --$depth === 0) {
                return $i;
            }
        }
        return null;
    }

    /** @param list<array{id: int|null, text: string, line: int, offset: int}> $tokens */
    private function statementEnd(array $tokens, int $start, int $limit): int
    {
        $round = 0;
        $square = 0;
        $curly = 0;
        for ($i = $start; $i < $limit; $i++) {
            $text = $tokens[$i]['text'];
            $round += $text === '(' ? 1 : ($text === ')' ? -1 : 0);
            $square += $text === '[' ? 1 : ($text === ']' ? -1 : 0);
            $curly += $text === '{' ? 1 : ($text === '}' ? -1 : 0);
            if ($text === ';' && $round === 0 && $square === 0 && $curly === 0) {
                return $i;
            }
        }
        return max($start, $limit - 1);
    }

    /** @param list<array{id: int|null, text: string, line: int, offset: int}> $tokens */
    private function tokenText(array $tokens, int $start, int $end): string
    {
        $text = '';
        for ($i = $start; $i < $end; $i++) {
            $text .= $tokens[$i]['text'];
        }
        return $text;
    }

    private function matchingCharacter(string $source, int $open, string $left, string $right): ?int
    {
        $depth = 0;
        $quote = null;
        $escaped = false;
        for ($i = $open, $length = strlen($source); $i < $length; $i++) {
            $char = $source[$i];
            if ($quote !== null) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($char === '\\') {
                    $escaped = true;
                } elseif ($char === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($char === "'" || $char === '"') {
                $quote = $char;
            } elseif ($char === $left) {
                $depth++;
            } elseif ($char === $right && --$depth === 0) {
                return $i;
            }
        }
        return null;
    }

    private function withoutComments(string $source): string
    {
        $output = '';
        foreach (token_get_all($source) as $token) {
            $output .= is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)
                ? (string)preg_replace('/[^\r\n]/', ' ', $token[1])
                : (is_array($token) ? $token[1] : $token);
        }
        return $output;
    }

    /**
     * @param array<string, mixed> $method
     * @param array<string, mixed>|null $statement
     * @return array{code: string, severity: string, file: string, line: int, message: string}
     */
    private function atStatement(string $code, string $severity, string $file, array $method, ?array $statement, string $message): array
    {
        return $this->finding($code, $severity, $file, (int)($statement['line'] ?? $method['line']), $message);
    }

    /** @return array{code: string, severity: string, file: string, line: int, message: string} */
    private function finding(string $code, string $severity, string $file, int $line, string $message): array
    {
        return ['code' => $code, 'severity' => $severity, 'file' => $file, 'line' => $line, 'message' => $message];
    }

    private function lineAt(string $source, int $offset): int
    {
        return 1 + substr_count(substr($source, 0, $offset), "\n");
    }
}
