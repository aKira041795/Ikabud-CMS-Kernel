# HARPP Phase 0 and Phase 1 Rollout

## Invariants

- The tenant ModuleDB is the hosted authority. Audit and outbox rows are committed with domain mutations.
- Push and Kernel/event delivery are outbox work, not cross-database atomic work.
- Decisions follow `CREATED -> PENDING -> NOTIFIED -> VIEWED -> DECIDED -> ACKNOWLEDGED -> APPLIED -> CLOSED`. `DECIDED` creates the ADR in the same transaction.
- Terminal decisions are archived. Purge is a delayed, two-actor request/approval record; execution is intentionally unavailable in this cycle.
- Workspace/project membership, participant visibility, receipts, approvals, and fanout are independent features.

## Flags, telemetry, and rollback

| Flag | Default | Enable only after | Observe | Data-preserving rollback |
|---|---:|---|---|---|
| `harpp_lifecycle_v2` | on | migration 007 + lifecycle contract checks | illegal/version conflicts; missing ADR | disable new v2 callers; never restore bypass |
| `harpp_immutable_retention` | on | archive/purge authorization checks | archive and purge-request counts | stop archive/purge writes; retain rows |
| `harpp_outbox` | on | dispatcher configured | pending age, attempts, dead rows | pause dispatcher after operational review |
| `harpp_strict_validation` | on | strict output gate installed | rejected HTML/5xx/PHP/sandbox markers | keep gate; investigate rejected command |
| `harpp_workspace_enforcement` | off | Legacy counts and dual-read comparison match | denied reads by workspace/user | return to compatibility reads |
| `harpp_participant_visibility` | off | two-workspace negative tests | filtered lists/counts/fanout | return to workspace compatibility reads |
| `harpp_per_user_receipts` | off | receipt comparison | unread differences by user | read legacy `read_at`; preserve receipts |
| `harpp_approval_policies` | off | snapshot/quorum/SoD tests | approval-required conflicts and vetoes | stop assigning new policies; preserve evidence |
| `harpp_notification_fanout` | off | visibility/preference tests | recipients, retries, dead letters | return to compatibility recipient behavior |

## Migration/backfill checks

- Exactly one `legacy` workspace exists in each tenant schema.
- Only users active and not deleted when migration 007 runs receive Legacy membership. Future users are not auto-enrolled.
- Existing conversations and decisions point to Legacy and retain stable numeric IDs.
- Existing messages receive deterministic per-conversation sequences before the unique index is created.
- Orphaned actors, inactive-user history, duplicate keys, and invalid JSON block rollout for manual resolution; they are not silently discarded.

Migration 007 is additive but MySQL DDL auto-commits. Take a backup and validate a sanitized legacy-shaped clone before tenant rollout. Emergency rollback is code/flag rollback, not schema deletion.
Migration 007 guards every legacy-table column, index, and foreign key independently through `information_schema`; it applies nullable scope schema, seeds/backfills and validates data, then adds indexes/foreign keys and records `harpp_migration_007_progress=complete`. The disposable-schema test interrupts after completed DDL groups, resumes, reapplies, and proves flag rollback retains additive data. MySQL cannot transactionally roll back an in-flight DDL statement.

## Isolated verification policy

The database-mutating legacy CLI tests are not safe to run against a configured shared tenant. They require a generated disposable tenant/schema, explicit non-production guard, and teardown proof. The default focused check is `php modules/harpp/tests/integrity_collaboration_contract_test.php`; command output is wrapped by `strict-command-gate.php`. A live integration run remains blocked until the disposable tenant fixture is wired to the application tenant resolver.

## Deferred roadmap

- Phase 2: scoped credentials, executors, runs, leases, cancellation, retry, and attested receipts.
- Phase 3: governed L0-L4 actions and vendor adapters.
- FTP/deployment: L4 phone approval, local profile-bound SFTP/verified FTPS executor, staged upload, verification, rollback, and immutable receipts. Plain FTP is opt-in risk; extraction is only an explicitly probed cPanel API 2 operation, allowlisted SSH/SFTP operation, or manual notification.
- Phase 4: personal/team product modes, hosted opt-in control plane, enterprise policy, evidence dashboards, and disaster recovery.

None of these deferred slices is implemented or enabled by Phase 0/1.
