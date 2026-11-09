-- 099: sender and receiver remarks on delivery variance records.
-- Existing rows remain unchanged: all six columns are nullable and no backfill runs.
-- @mysql57-compat: individually guarded ALTERs; no CTE/window/CHECK/JSON_TABLE.

SET @vf_sender_remark := IF(
  EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'dl_variance_flags' AND column_name = 'sender_remark'),
  'SELECT 1',
  'ALTER TABLE dl_variance_flags ADD COLUMN sender_remark TEXT NULL AFTER resolution_choice'
);
PREPARE vf_sender_remark_st FROM @vf_sender_remark; EXECUTE vf_sender_remark_st; DEALLOCATE PREPARE vf_sender_remark_st;

SET @vf_sender_remark_by := IF(
  EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'dl_variance_flags' AND column_name = 'sender_remark_by'),
  'SELECT 1',
  'ALTER TABLE dl_variance_flags ADD COLUMN sender_remark_by INT UNSIGNED NULL AFTER sender_remark'
);
PREPARE vf_sender_remark_by_st FROM @vf_sender_remark_by; EXECUTE vf_sender_remark_by_st; DEALLOCATE PREPARE vf_sender_remark_by_st;

SET @vf_sender_remark_branch := IF(
  EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'dl_variance_flags' AND column_name = 'sender_remark_branch_id'),
  'SELECT 1',
  'ALTER TABLE dl_variance_flags ADD COLUMN sender_remark_branch_id INT UNSIGNED NULL AFTER sender_remark_by'
);
PREPARE vf_sender_remark_branch_st FROM @vf_sender_remark_branch; EXECUTE vf_sender_remark_branch_st; DEALLOCATE PREPARE vf_sender_remark_branch_st;

SET @vf_sender_remarked_at := IF(
  EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'dl_variance_flags' AND column_name = 'sender_remarked_at'),
  'SELECT 1',
  'ALTER TABLE dl_variance_flags ADD COLUMN sender_remarked_at DATETIME NULL AFTER sender_remark_branch_id'
);
PREPARE vf_sender_remarked_at_st FROM @vf_sender_remarked_at; EXECUTE vf_sender_remarked_at_st; DEALLOCATE PREPARE vf_sender_remarked_at_st;

SET @vf_receiver_remark := IF(
  EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'dl_variance_flags' AND column_name = 'receiver_remark'),
  'SELECT 1',
  'ALTER TABLE dl_variance_flags ADD COLUMN receiver_remark TEXT NULL AFTER sender_remarked_at'
);
PREPARE vf_receiver_remark_st FROM @vf_receiver_remark; EXECUTE vf_receiver_remark_st; DEALLOCATE PREPARE vf_receiver_remark_st;

SET @vf_receiver_remark_by := IF(
  EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'dl_variance_flags' AND column_name = 'receiver_remark_by'),
  'SELECT 1',
  'ALTER TABLE dl_variance_flags ADD COLUMN receiver_remark_by INT UNSIGNED NULL AFTER receiver_remark'
);
PREPARE vf_receiver_remark_by_st FROM @vf_receiver_remark_by; EXECUTE vf_receiver_remark_by_st; DEALLOCATE PREPARE vf_receiver_remark_by_st;

SET @vf_receiver_remark_branch := IF(
  EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'dl_variance_flags' AND column_name = 'receiver_remark_branch_id'),
  'SELECT 1',
  'ALTER TABLE dl_variance_flags ADD COLUMN receiver_remark_branch_id INT UNSIGNED NULL AFTER receiver_remark_by'
);
PREPARE vf_receiver_remark_branch_st FROM @vf_receiver_remark_branch; EXECUTE vf_receiver_remark_branch_st; DEALLOCATE PREPARE vf_receiver_remark_branch_st;

SET @vf_receiver_remarked_at := IF(
  EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'dl_variance_flags' AND column_name = 'receiver_remarked_at'),
  'SELECT 1',
  'ALTER TABLE dl_variance_flags ADD COLUMN receiver_remarked_at DATETIME NULL AFTER receiver_remark_branch_id'
);
PREPARE vf_receiver_remarked_at_st FROM @vf_receiver_remarked_at; EXECUTE vf_receiver_remarked_at_st; DEALLOCATE PREPARE vf_receiver_remarked_at_st;
