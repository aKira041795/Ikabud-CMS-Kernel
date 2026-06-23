<?php

declare(strict_types=1);

function palPageAuditTrail(): void { $u=palRequireRole('admin'); $db=palDb(); $audit=$db->prepare("SELECT * FROM pal_audit_logs WHERE tenant_id=:tid ORDER BY created_at DESC LIMIT 100"); $audit->execute([':tid'=>$u['tenant_id']??0]); $t=__DIR__.'/../templates/project-audit-ledger/shell.disyl'; palRender($t, ['current_user'=>$u, 'page_title'=>'Audit Trail', 'page_content'=>'audit-trail', 'audit_logs'=>$audit->fetchAll(PDO::FETCH_ASSOC)]); }
