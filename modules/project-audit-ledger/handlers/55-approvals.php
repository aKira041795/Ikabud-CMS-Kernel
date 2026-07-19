<?php

declare(strict_types=1);

function palPageApprovalQueue(): void { $u=palCurrentUser(['admin','supervisor']); $s=new palApprovalService(palDb(), (int)($u['tenant_id']??0), (int)$u['id']); $pending=$s->pendingListEnriched(); $recent=$s->recentListEnriched(); $t=__DIR__.'/../templates/project-audit-ledger/shell.disyl'; palRender($t, ['current_user'=>$u, 'page_title'=>'Approvals', 'page_content'=>'approval-queue', 'pending'=>$pending, 'recent'=>$recent]); }
function palApiApprovalDecide(array $routeParams = []): void { palResponseGuard(function() use ($routeParams): void { $u=palCurrentUser(['admin','supervisor']); palEnforceCsrf(); $id=(int)($routeParams['id'] ?? $_GET['id']??$_POST['id']??0); $decision=$_POST['decision']??''; $remarks=$_POST['remarks']??''; $s=new palApprovalService(palDb(), (int)($u['tenant_id']??0), (int)$u['id']); $s->decide($id, $decision, $remarks); palAudit('pal.approval.completed', (int)$u['id'], 'pal_approvals', (string)$id, null, ['decision'=>$decision]); header('Content-Type: application/json'); echo json_encode(['ok'=>true]); }); }
