-- Academic Thesis Evaluation — Allow repeated immutable AISS snapshots
-- Historical unique key blocked regenerate/fallback runs for the same manuscript.

SELECT IF(
    EXISTS(
        SELECT * FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'ate_aiss_evidence_snapshots'
          AND INDEX_NAME = 'idx_snapshot_manuscript_version'
    ),
    'SELECT 1',
    'ALTER TABLE ate_aiss_evidence_snapshots ADD KEY idx_snapshot_manuscript_version (manuscript_version_id, evidence_version)'
) INTO @stmt; PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;

SELECT IF(
    EXISTS(
        SELECT * FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'ate_aiss_evidence_snapshots'
          AND INDEX_NAME = 'uq_snapshot_manuscript'
    ),
    'ALTER TABLE ate_aiss_evidence_snapshots DROP INDEX uq_snapshot_manuscript',
    'SELECT 1'
) INTO @stmt; PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;
