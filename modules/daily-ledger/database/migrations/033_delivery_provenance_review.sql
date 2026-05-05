-- Migration 033: Track paper-DR provenance review state on deliveries
ALTER TABLE dl_deliveries
    ADD COLUMN provenance_status ENUM('none','paper_dr_pending','accepted','discrepant') NOT NULL DEFAULT 'none' AFTER remarks,
    ADD COLUMN provenance_reviewed_by INT UNSIGNED NULL DEFAULT NULL AFTER provenance_status,
    ADD COLUMN provenance_reviewed_at DATETIME NULL DEFAULT NULL AFTER provenance_reviewed_by,
    ADD COLUMN provenance_review_note TEXT NULL DEFAULT NULL AFTER provenance_reviewed_at;

UPDATE dl_deliveries
   SET provenance_status = 'paper_dr_pending'
 WHERE remarks = '[captured-from-paper-dr]'
   AND provenance_status = 'none';