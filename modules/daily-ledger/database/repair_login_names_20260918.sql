-- Daily Ledger — repair the two names damaged by the login-overwrite defect.
--
-- Targets each row by BOTH id AND username (never by name). Re-runnable: on a
-- second run the WHERE clauses match nothing, so neither the audit row nor the
-- UPDATE runs and the file is a no-op. Run the whole file in phpMyAdmin; the
-- SELECTs print the before/after values.
--
--   id 20 shiela_baina -> 'Shiela Baina'   (owner directive)
--   id 21 admin_view   -> 'Vivian Baron'    (audit_logs update_user, 2026-09-18)
--
-- This touches dl_users (two rows) and inserts the matching audit_logs records
-- only. It changes no other table and no other row.

START TRANSACTION;

-- BEFORE
SELECT id, username, full_name AS before_name
  FROM dl_users
 WHERE (id = 20 AND username = 'shiela_baina')
    OR (id = 21 AND username = 'admin_view');

-- Audit record for id 20 — only when the value actually differs.
INSERT INTO audit_logs
    (module, actor_source, action, entity_type, entity_id, old_data, new_data, metadata_json, created_at)
SELECT 'daily-ledger', 'operator_repair', 'update_user', 'user', CAST(id AS CHAR),
       JSON_OBJECT('full_name', full_name),
       JSON_OBJECT('full_name', 'Shiela Baina'),
       JSON_OBJECT('actor_name', 'operator_repair', 'purpose', 'login-name-repair'),
       NOW()
  FROM dl_users
 WHERE id = 20 AND username = 'shiela_baina'
   AND (full_name IS NULL OR full_name <> 'Shiela Baina');

UPDATE dl_users
   SET full_name = 'Shiela Baina'
 WHERE id = 20 AND username = 'shiela_baina'
   AND (full_name IS NULL OR full_name <> 'Shiela Baina');

-- Audit record for id 21 — only when the value actually differs.
INSERT INTO audit_logs
    (module, actor_source, action, entity_type, entity_id, old_data, new_data, metadata_json, created_at)
SELECT 'daily-ledger', 'operator_repair', 'update_user', 'user', CAST(id AS CHAR),
       JSON_OBJECT('full_name', full_name),
       JSON_OBJECT('full_name', 'Vivian Baron'),
       JSON_OBJECT('actor_name', 'operator_repair', 'purpose', 'login-name-repair'),
       NOW()
  FROM dl_users
 WHERE id = 21 AND username = 'admin_view'
   AND (full_name IS NULL OR full_name <> 'Vivian Baron');

UPDATE dl_users
   SET full_name = 'Vivian Baron'
 WHERE id = 21 AND username = 'admin_view'
   AND (full_name IS NULL OR full_name <> 'Vivian Baron');

-- AFTER
SELECT id, username, full_name AS after_name
  FROM dl_users
 WHERE (id = 20 AND username = 'shiela_baina')
    OR (id = 21 AND username = 'admin_view');

COMMIT;

-- ─────────────────────────────────────────────────────────────────────
-- Operator one-liner (for a host with PHP CLI, no phpMyAdmin click-through):
--
-- php -r '$p=new PDO("mysql:host=HOST;dbname=DB;charset=utf8mb4","USER","PASS"); foreach([[20,"shiela_baina","Shiela Baina"],[21,"admin_view","Vivian Baron"]] as [$id,$u,$n]){$s=$p->prepare("SELECT full_name FROM dl_users WHERE id=? AND username=?");$s->execute([$id,$u]);$o=$s->fetchColumn();if($o===false){echo "id $id: not found\n";continue;}if((string)$o===$n){echo "id $id: already $n\n";continue;}$p->prepare("INSERT INTO audit_logs (module,actor_source,action,entity_type,entity_id,old_data,new_data,metadata_json,created_at) VALUES (\"daily-ledger\",\"operator_repair\",\"update_user\",\"user\",?,?,?,?,NOW())")->execute([(string)$id,json_encode(["full_name"=>$o]),json_encode(["full_name"=>$n]),json_encode(["actor_name"=>"operator_repair","purpose"=>"login-name-repair"])]);$p->prepare("UPDATE dl_users SET full_name=? WHERE id=? AND username=? AND (full_name IS NULL OR full_name<>?)")->execute([$n,$id,$u,$n]);echo "id $id: ".var_export($o,true)." -> $n\n";}'
--
-- Wait for shell prompts, then paste. It prints "already ..." on a repaired
-- database (no-op) and "<old> -> <new>" the first time.
