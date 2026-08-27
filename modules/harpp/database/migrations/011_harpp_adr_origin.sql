-- HARPP ADR origin provenance (2026-08-27, module 2.2.0):
--   * stamp each ADR with its creation origin so direct-closure fallback ADRs
--     are provably distinct from operator-recorded decision ADRs.
-- MySQL 5.7 compatible, additive only.

ALTER TABLE `harpp_adrs`
    ADD COLUMN `adr_origin` VARCHAR(32) NOT NULL DEFAULT 'decision' AFTER `rationale`;