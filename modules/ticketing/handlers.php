<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

/**
 * Ticketing Module — Handlers
 */

// ─── Helpers ───────────────────────────────────────────────────────────

function tk_nextTicketNo(): string
{
    $ctx = module();
    if (!$ctx) {
        throw new \RuntimeException('Module context unavailable');
    }

    $stmt = $ctx->db()->query('SELECT MAX(id) FROM tickets');
    $maxId = (int) $stmt->fetchColumn();
    return 'TK-' . str_pad((string) ($maxId + 1), 4, '0', STR_PAD_LEFT);
}

function tk_getUsers(): array
{
    $ctx = module();
    if (!$ctx) {
        throw new \RuntimeException('Module context unavailable');
    }

    $stmt = $ctx->db()->query('SELECT id, username, full_name, role FROM users WHERE is_active = 1 ORDER BY full_name');
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function tk_statusBadgeClass(string $status): string
{
    return match ($status) {
        'open'        => 'badge-open',
        'in_progress' => 'badge-progress',
        'resolved'    => 'badge-resolved',
        'closed'      => 'badge-closed',
        default       => '',
    };
}

function tk_priorityBadgeClass(string $priority): string
{
    return match ($priority) {
        'low'    => 'badge-low',
        'medium' => 'badge-medium',
        'high'   => 'badge-high',
        'urgent' => 'badge-urgent',
        default  => '',
    };
}

// ─── Page Handlers ─────────────────────────────────────────────────────

function handleTicketList(array $params = []): void
{
    $ctx = module();
    if (!$ctx) {
        http_response_code(500);
        echo 'Module context unavailable';
        return;
    }

    $user = $ctx->requireAuth();
    $input = $ctx->input();
    $role = (string) ($user['role'] ?? '');
    $userId = (int) ($user['id'] ?? $user['sub'] ?? 0);

    // Filters
    $statusFilter   = trim((string) ($input['status'] ?? ''));
    $priorityFilter = trim((string) ($input['priority'] ?? ''));
    $categoryFilter = trim((string) ($input['category'] ?? ''));

    $where = ['1=1'];
    $bind = [];

    if ($statusFilter !== '') {
        $where[] = 't.status = :status';
        $bind[':status'] = $statusFilter;
    }
    if ($priorityFilter !== '') {
        $where[] = 't.priority = :priority';
        $bind[':priority'] = $priorityFilter;
    }
    if ($categoryFilter !== '') {
        $where[] = 't.category = :category';
        $bind[':category'] = $categoryFilter;
    }

    // Non-admin users only see their own tickets + tickets assigned to them
    if (!in_array($role, ['admin', 'supervisor'], true)) {
        $where[] = '(t.created_by = :uid OR t.assigned_to = :uid2)';
        $bind[':uid'] = $userId;
        $bind[':uid2'] = $userId;
    }

    $sql = 'SELECT t.*, 
                   c.full_name AS creator_name, 
                   a.full_name AS assignee_name
            FROM tickets t
            LEFT JOIN users c ON c.id = t.created_by
            LEFT JOIN users a ON a.id = t.assigned_to
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY FIELD(t.priority, "urgent","high","medium","low"), t.created_at DESC';

    $stmt = $ctx->db()->prepare($sql);
    $stmt->execute($bind);
    $tickets = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Stats
    $statsStmt = $ctx->db()->query(
        'SELECT status, COUNT(*) AS cnt FROM tickets GROUP BY status'
    );
    $statsRaw = $statsStmt->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
    $stats = [
        'open'        => (int) ($statsRaw['open'] ?? 0),
        'in_progress' => (int) ($statsRaw['in_progress'] ?? 0),
        'resolved'    => (int) ($statsRaw['resolved'] ?? 0),
        'closed'      => (int) ($statsRaw['closed'] ?? 0),
    ];
    $stats['total'] = array_sum($stats);

    echo tkRender('modules/ticketing/list.disyl', [
        'page_title'      => 'Tickets',
        'tickets'         => $tickets,
        'stats'           => $stats,
        'status_filter'   => $statusFilter,
        'priority_filter' => $priorityFilter,
        'category_filter' => $categoryFilter,
    ]);
}

function handleTicketCreate(array $params = []): void
{
    $ctx = module();
    if (!$ctx) {
        http_response_code(500);
        echo 'Module context unavailable';
        return;
    }

    $user = $ctx->requireAuth();
    $users = tk_getUsers();

    echo tkRender('modules/ticketing/create.disyl', [
        'page_title' => 'New Ticket',
        'users'      => $users,
    ]);
}

function handleTicketView(array $params = []): void
{
    $ctx = module();
    if (!$ctx) {
        http_response_code(500);
        echo 'Module context unavailable';
        return;
    }

    $user = $ctx->requireAuth();
    $id = (int) ($params['id'] ?? 0);

    $stmt = $ctx->db()->prepare(
        'SELECT t.*, c.full_name AS creator_name, a.full_name AS assignee_name
         FROM tickets t
         LEFT JOIN users c ON c.id = t.created_by
         LEFT JOIN users a ON a.id = t.assigned_to
         WHERE t.id = :id LIMIT 1'
    );
    $stmt->execute([':id' => $id]);
    $ticket = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$ticket) {
        http_response_code(404);
        echo $ctx->render('pages/404.disyl', ['page_title' => 'Ticket Not Found']);
        return;
    }

    // Comments
    $cStmt = $ctx->db()->prepare(
        'SELECT tc.*, u.full_name AS author_name, u.role AS author_role
         FROM ticket_comments tc
         LEFT JOIN users u ON u.id = tc.user_id
         WHERE tc.ticket_id = :tid
         ORDER BY tc.created_at ASC'
    );
    $cStmt->execute([':tid' => $id]);
    $comments = $cStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Attachments
    $attStmt = $ctx->db()->prepare(
        'SELECT * FROM ticket_attachments WHERE ticket_id = :tid ORDER BY created_at ASC'
    );
    $attStmt->execute([':tid' => $id]);
    $attachments = $attStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $users = tk_getUsers();

    echo tkRender('modules/ticketing/view.disyl', [
        'page_title'  => $ticket['ticket_no'] . ' — ' . $ticket['subject'],
        'ticket'      => $ticket,
        'comments'    => $comments,
        'attachments' => $attachments,
        'users'       => $users,
    ]);
}

function handleTicketEdit(array $params = []): void
{
    $ctx = module();
    if (!$ctx) {
        http_response_code(500);
        echo 'Module context unavailable';
        return;
    }

    $user = $ctx->requireAuth();
    $id = (int) ($params['id'] ?? 0);

    $stmt = $ctx->db()->prepare(
        'SELECT t.*, c.full_name AS creator_name, a.full_name AS assignee_name
         FROM tickets t
         LEFT JOIN users c ON c.id = t.created_by
         LEFT JOIN users a ON a.id = t.assigned_to
         WHERE t.id = :id LIMIT 1'
    );
    $stmt->execute([':id' => $id]);
    $ticket = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$ticket) {
        http_response_code(404);
        echo $ctx->render('pages/404.disyl', ['page_title' => 'Ticket Not Found']);
        return;
    }

    // Only open/in_progress tickets can be edited
    if (!in_array($ticket['status'], ['open', 'in_progress'], true)) {
        $ctx->redirect('/tickets/' . $id);
    }

    $users = tk_getUsers();

    echo tkRender('modules/ticketing/edit.disyl', [
        'page_title' => 'Edit ' . $ticket['ticket_no'],
        'ticket'     => $ticket,
        'users'      => $users,
    ]);
}

// ─── API Handlers ──────────────────────────────────────────────────────

function apiUpdateTicket(array $params = []): void
{
    $ctx = module();
    if (!$ctx) {
        http_response_code(500);
        echo 'Module context unavailable';
        return;
    }

    $user = $ctx->requireAuth();
    $input = $ctx->input();

    $ticketId = (int) ($input['ticket_id'] ?? 0);
    $subject = trim((string) ($input['subject'] ?? ''));
    $description = trim((string) ($input['description'] ?? ''));
    $priority = trim((string) ($input['priority'] ?? ''));
    $assignedTo = !empty($input['assigned_to']) ? (int) $input['assigned_to'] : null;

    if ($ticketId < 1 || $subject === '') {
        $ctx->json(['ok' => false, 'error' => 'Ticket ID and subject are required.'], 422);
    }

    // Only open/in_progress tickets can be edited
    $chk = $ctx->db()->prepare('SELECT status FROM tickets WHERE id = :id');
    $chk->execute([':id' => $ticketId]);
    $current = $chk->fetch(PDO::FETCH_ASSOC);
    if (!$current || !in_array($current['status'], ['open', 'in_progress'], true)) {
        $ctx->json(['ok' => false, 'error' => 'Only open or in-progress tickets can be edited.'], 403);
    }

    $stmt = $ctx->db()->prepare(
        'UPDATE tickets SET subject = :subj, description = :desc, priority = :pri, assigned_to = :assignee WHERE id = :id'
    );
    $stmt->execute([
        ':subj'     => $subject,
        ':desc'     => $description ?: null,
        ':pri'      => in_array($priority, ['low','medium','high','urgent']) ? $priority : 'medium',
        ':assignee' => $assignedTo,
        ':id'       => $ticketId,
    ]);

    if ($ctx->isHtmx()) {
        $ctx->redirect('/tickets/' . $ticketId);
    }

    $ctx->json(['ok' => true, 'redirect' => '/tickets/' . $ticketId]);
}

function apiCreateTicket(array $params = []): void
{
    $ctx = module();
    if (!$ctx) {
        http_response_code(500);
        echo 'Module context unavailable';
        return;
    }

    $user = $ctx->requireAuth();
    $input = $ctx->input();

    $subject = trim((string) ($input['subject'] ?? ''));
    $description = trim((string) ($input['description'] ?? ''));
    $priority = trim((string) ($input['priority'] ?? 'medium'));
    $assignedTo = !empty($input['assigned_to']) ? (int) $input['assigned_to'] : null;

    if ($subject === '') {
        $ctx->json(['ok' => false, 'error' => 'Subject is required.'], 422);
    }

    $ticketNo = tk_nextTicketNo();
    $userId = (int) ($user['id'] ?? $user['sub'] ?? 0);

    $validCategories = ['plumbing','electrical','pest_control','common_area','security','other'];
    $category = in_array(trim((string)($input['category'] ?? '')), $validCategories, true)
        ? trim((string)($input['category']))
        : 'other';
    $unitNo = substr(trim((string)($input['unit_no'] ?? '')), 0, 40);

    $stmt = $ctx->db()->prepare(
        'INSERT INTO tickets (ticket_no, subject, description, priority, created_by, assigned_to, category, unit_no, source)
         VALUES (:no, :subj, :desc, :pri, :creator, :assignee, :cat, :unit, :source)'
    );
    $stmt->execute([
        ':no'       => $ticketNo,
        ':subj'     => $subject,
        ':desc'     => $description ?: null,
        ':pri'      => in_array($priority, ['low','medium','high','urgent']) ? $priority : 'medium',
        ':creator'  => $userId,
        ':assignee' => $assignedTo,
        ':cat'      => $category,
        ':unit'     => $unitNo ?: null,
        ':source'   => 'internal',
    ]);

    $newId = (int) $ctx->db()->lastInsertId();

    // If HTMX, redirect to the ticket
    if ($ctx->isHtmx()) {
        $ctx->redirect('/tickets/' . $newId);
    }

    $ctx->json(['ok' => true, 'ticket_id' => $newId, 'ticket_no' => $ticketNo, 'redirect' => '/tickets/' . $newId]);
}

function apiAddComment(array $params = []): void
{
    $ctx = module();
    if (!$ctx) {
        http_response_code(500);
        echo 'Module context unavailable';
        return;
    }

    $user = $ctx->requireAuth();
    $input = $ctx->input();

    $ticketId = (int) ($input['ticket_id'] ?? 0);
    $body = trim((string) ($input['body'] ?? ''));

    if ($ticketId < 1 || $body === '') {
        $ctx->json(['ok' => false, 'error' => 'Ticket ID and comment body are required.'], 422);
    }

    $userId = (int) ($user['id'] ?? $user['sub'] ?? 0);
    $stmt = $ctx->db()->prepare(
        'INSERT INTO ticket_comments (ticket_id, user_id, body) VALUES (:tid, :uid, :body)'
    );
    $stmt->execute([':tid' => $ticketId, ':uid' => $userId, ':body' => $body]);

    // Return updated comments partial via HTMX
    if ($ctx->isHtmx()) {
        $cStmt = $ctx->db()->prepare(
            'SELECT tc.*, u.full_name AS author_name, u.role AS author_role
             FROM ticket_comments tc
             LEFT JOIN users u ON u.id = tc.user_id
             WHERE tc.ticket_id = :tid
             ORDER BY tc.created_at ASC'
        );
        $cStmt->execute([':tid' => $ticketId]);
        $comments = $cStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        echo tkRender('modules/ticketing/partials/comments.disyl', [
            'comments' => $comments,
        ]);
        return;
    }

    $ctx->json(['ok' => true]);
}

function apiUpdateStatus(array $params = []): void
{
    $ctx = module();
    if (!$ctx) {
        http_response_code(500);
        echo 'Module context unavailable';
        return;
    }

    $user = $ctx->requireAuth();
    $input = $ctx->input();

    $ticketId = (int) ($input['ticket_id'] ?? 0);
    $status = trim((string) ($input['status'] ?? ''));

    if ($ticketId < 1 || !in_array($status, ['open','in_progress','resolved','closed'])) {
        $ctx->json(['ok' => false, 'error' => 'Valid ticket ID and status required.'], 422);
    }

    $closedAt = $status === 'closed' ? date('Y-m-d H:i:s') : null;

    $stmt = $ctx->db()->prepare(
        'UPDATE tickets SET status = :status, closed_at = COALESCE(:closed, closed_at) WHERE id = :id'
    );
    $stmt->execute([':status' => $status, ':closed' => $closedAt, ':id' => $ticketId]);

    if ($ctx->isHtmx()) {
        // Redirect back to the ticket view to refresh
        $ctx->redirect('/tickets/' . $ticketId);
    }

    $ctx->json(['ok' => true]);
}

function apiAssignTicket(array $params = []): void
{
    $ctx = module();
    if (!$ctx) {
        http_response_code(500);
        echo 'Module context unavailable';
        return;
    }

    $user = $ctx->requireAuth();
    $input = $ctx->input();

    $ticketId = (int) ($input['ticket_id'] ?? 0);
    $assignTo = !empty($input['assigned_to']) ? (int) $input['assigned_to'] : null;

    if ($ticketId < 1) {
        $ctx->json(['ok' => false, 'error' => 'Valid ticket ID required.'], 422);
    }

    $stmt = $ctx->db()->prepare('UPDATE tickets SET assigned_to = :assignee WHERE id = :id');
    $stmt->execute([':assignee' => $assignTo, ':id' => $ticketId]);

    if ($ctx->isHtmx()) {
        $ctx->redirect('/tickets/' . $ticketId);
    }

    $ctx->json(['ok' => true]);
}

// ═══════════════════════════════════════════════════════════════════════
// PROPERTY MANAGEMENT EXTENSIONS
// ═══════════════════════════════════════════════════════════════════════

// ─── Settings ──────────────────────────────────────────────────────────

function tkGetSettings(): array
{
    // Cache keyed by tenant ID so different tenants in the same process
    // don't share each other's ticketing configuration.
    static $cache = [];
    $tid = app()->tenantId();
    if (array_key_exists($tid, $cache)) {
        return $cache[$tid];
    }

    try {
        $ctx = module();
        $db  = $ctx ? $ctx->db() : app()->db();
        $stmt = $db->query('SELECT setting_key, setting_value FROM ticketing_settings');
        $cache[$tid] = $stmt->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
    } catch (\Throwable $e) {
        $cache[$tid] = [];
    }

    return array_merge(tkSettingsDefaults(), $cache[$tid]);
}

function tkSettingsDefaults(): array
{
    static $defaults = null;
    if ($defaults !== null) {
        return $defaults;
    }

    $defaults = [];
    $manifest = discoverModules()['ticketing'] ?? [];
    $fields = is_array($manifest['settings_fields'] ?? null) ? $manifest['settings_fields'] : [];

    foreach ($fields as $field) {
        if (!is_array($field)) {
            continue;
        }

        $key = trim((string)($field['key'] ?? ''));
        if ($key === '' || !array_key_exists('default', $field)) {
            continue;
        }

        $defaults[$key] = (string)$field['default'];
    }

    return $defaults;
}

function tkSaveSetting(string $key, string $value): void
{
    $ctx = module();
    $db  = $ctx ? $ctx->db() : app()->db();
    $db->prepare(
        'INSERT INTO ticketing_settings (setting_key, setting_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    )->execute([$key, $value]);
}

// ─── Uploads directory helpers ─────────────────────────────────────────

function tkUploadsPath(): string
{
    return rtrim(defined('IK_ROOT') ? IK_ROOT : dirname(__DIR__, 2), '/') . '/public/uploads/tickets';
}

function tkUploadsUrl(string $relPath): string
{
    $base = external_base_url((string)config('app.url', ''));
    return $base . '/uploads/tickets/' . ltrim($relPath, '/');
}

// ─── Stateless HMAC Captcha ────────────────────────────────────────────

function tkGenerateCaptcha(): array
{
    $a  = random_int(2, 12);
    $b  = random_int(2, 12);
    $useMultiply = (bool) random_int(0, 1);
    $op     = $useMultiply ? '×' : '+';
    $answer = $useMultiply ? ($a * $b) : ($a + $b);

    $payload = base64_encode((string) json_encode(['a' => (string) $answer, 'e' => time() + 900]));
    $secret  = $_ENV['JWT_SECRET'] ?? 'ticketing-captcha-fallback';
    $token   = $payload . '.' . hash_hmac('sha256', $payload, $secret);

    return [
        'question' => "What is {$a} {$op} {$b}?",
        'token'    => $token,
    ];
}

function tkVerifyCaptcha(string $token, string $submitted): bool
{
    if ($token === '' || $submitted === '') {
        return false;
    }

    $parts = explode('.', $token, 2);
    if (count($parts) !== 2) {
        return false;
    }

    [$payload, $sig] = $parts;
    $secret   = $_ENV['JWT_SECRET'] ?? 'ticketing-captcha-fallback';
    $expected = hash_hmac('sha256', $payload, $secret);

    if (!hash_equals($expected, $sig)) {
        return false;
    }

    $data = json_decode((string) base64_decode($payload), true);
    if (!is_array($data) || (int) ($data['e'] ?? 0) < time()) {
        return false;
    }

    return strtolower(trim($submitted)) === strtolower(trim((string) ($data['a'] ?? '')));
}

// ─── Rate Limiting (Public Submissions) ────────────────────────────────

function tkIsRateLimited(string $ip): bool
{
    if ($ip === '') {
        return false;
    }

    try {
        $ctx  = module();
        $db   = $ctx ? $ctx->db() : app()->db();
        $stmt = $db->prepare(
            "SELECT COUNT(*) FROM tickets
             WHERE source = 'public' AND ip_address = ?
               AND created_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)"
        );
        $stmt->execute([$ip]);
        return (int) $stmt->fetchColumn() >= 5;
    } catch (\Throwable $e) {
        return false; // fail open on DB error
    }
}

// ─── Attachment Processing ─────────────────────────────────────────────

function tkProcessAttachments(int $ticketId, array $files): void
{
    $allowedMimes = ['image/jpeg', 'image/png', 'image/webp'];
    $processed    = 0;

    $uploadsBase = tkUploadsPath();
    $subDir      = date('Y') . '/' . date('m');
    $uploadsDir  = $uploadsBase . '/' . $subDir;

    foreach ($files as $file) {
        if ($processed >= 3) {
            break;
        }
        if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            continue;
        }

        $tmpPath = (string) ($file['tmp_name'] ?? '');
        if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
            continue;
        }

        // Re-verify MIME from actual file bytes, not user-supplied header
        $mime = function_exists('mime_content_type') ? (string) mime_content_type($tmpPath) : '';
        if ($mime === '') {
            $mime = (string) ($file['type'] ?? '');
        }

        if (!in_array($mime, $allowedMimes, true)) {
            continue;
        }

        $size = (int) ($file['size'] ?? 0);
        if ($size < 1 || $size > 2 * 1024 * 1024) {
            continue;
        }

        $ext = match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp',
            default      => 'jpg',
        };

        $filename = date('Ymd_His') . '_' . substr(bin2hex(random_bytes(4)), 0, 8) . '.' . $ext;

        if (!is_dir($uploadsDir)) {
            kernelEnsureDirectory($uploadsDir);
        }

        $destPath = $uploadsDir . '/' . $filename;
        if (!kernelCopyFile($tmpPath, $destPath)) {
            write_log("ticketing: failed to copy attachment to {$destPath}", 'error');
            continue;
        }

        $relPath = $subDir . '/' . $filename;
        $fileUrl = tkUploadsUrl($relPath);

        try {
            $ctx = module();
            $db  = $ctx ? $ctx->db() : app()->db();
            $db->prepare(
                'INSERT INTO ticket_attachments (ticket_id, file_url, filename) VALUES (?, ?, ?)'
            )->execute([$ticketId, $fileUrl, basename((string) ($file['name'] ?? $filename))]);
            $processed++;
        } catch (\Throwable $e) {
            write_log('ticketing: attachment DB insert failed: ' . $e->getMessage(), 'error');
        }
    }
}

// ─── Admin Notifications ───────────────────────────────────────────────

function tkNotifyAdmins(array $ticket): void
{
    $settings      = tkGetSettings();
    $ticketNo      = $ticket['ticket_no']    ?? 'TK-????';
    $subject       = $ticket['subject']      ?? '(no subject)';
    $category      = $ticket['category']     ?? 'other';
    $unitNo        = $ticket['unit_no']      ?? '';
    $contactName   = $ticket['contact_name'] ?? 'Anonymous';
    $id            = (int) ($ticket['id']    ?? 0);

    $categoryLabel = ucwords(str_replace('_', ' ', $category));
    $unitLabel     = $unitNo !== '' ? "Unit {$unitNo}" : 'No unit specified';

    // ── SMS ──────────────────────────────────────────────────────────
    if (($settings['notify_sms'] ?? '0') === '1') {
        $phone = trim((string) ($settings['admin_phone'] ?? ''));
        if ($phone !== '') {
            try {
                $msg = "New #{$ticketNo} ({$categoryLabel}): {$subject} — {$unitLabel}. From: {$contactName}";
                if (strlen($msg) > 160) {
                    $msg = substr($msg, 0, 157) . '...';
                }
                app()->cap()->call('sms.send@1', [
                    'to'             => $phone,
                    'message'        => $msg,
                    'trigger_event'  => 'ticket.created',
                    'trigger_ref_id' => (string) $id,
                ], ['mode' => 'first']);
            } catch (\Throwable $e) {
                write_log('ticketing: SMS notification failed: ' . $e->getMessage(), 'error');
            }
        }
    }

    // ── Email ─────────────────────────────────────────────────────────
    if (($settings['notify_email'] ?? '0') === '1') {
        $email = trim((string) ($settings['admin_email'] ?? ''));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            try {
                $baseUrl = external_base_url((string)config('app.url', ''));
                $link    = $baseUrl . '/tickets/' . $id;

                $content = '<p style="margin:0 0 16px;color:#4b5563;">A new maintenance request has been submitted.</p>'
                    . '<table style="width:100%;border-collapse:collapse;margin:0 0 20px;">'
                    . '<tr style="background:#f9fafb;"><td style="padding:9px 12px;color:#6b7280;font-weight:600;width:140px;">Ticket #</td>'
                    .   '<td style="padding:9px 12px;font-family:monospace;">' . htmlspecialchars($ticketNo) . '</td></tr>'
                    . '<tr><td style="padding:9px 12px;color:#6b7280;font-weight:600;">Category</td>'
                    .   '<td style="padding:9px 12px;">' . htmlspecialchars($categoryLabel) . '</td></tr>'
                    . '<tr style="background:#f9fafb;"><td style="padding:9px 12px;color:#6b7280;font-weight:600;">Unit</td>'
                    .   '<td style="padding:9px 12px;">' . htmlspecialchars($unitLabel) . '</td></tr>'
                    . '<tr><td style="padding:9px 12px;color:#6b7280;font-weight:600;">Submitted By</td>'
                    .   '<td style="padding:9px 12px;">' . htmlspecialchars($contactName) . '</td></tr>'
                    . '<tr style="background:#f9fafb;"><td style="padding:9px 12px;color:#6b7280;font-weight:600;">Subject</td>'
                    .   '<td style="padding:9px 12px;">' . htmlspecialchars($subject) . '</td></tr>'
                    . '</table>';

                if (function_exists('buildEmailTemplate')) {
                    $body = buildEmailTemplate("New Ticket: {$ticketNo}", $content, 'View Ticket', $link);
                } else {
                    $body = $content;
                }

                if (function_exists('sendEmail')) {
                    sendEmail($email, "New Support Ticket #{$ticketNo} — {$categoryLabel}", $body);
                }
            } catch (\Throwable $e) {
                write_log('ticketing: email notification failed: ' . $e->getMessage(), 'error');
            }
        }
    }
}

// ─── Public Page Handlers ──────────────────────────────────────────────

function handlePublicSubmitForm(array $params = []): void
{
    $ctx = module();
    if (!$ctx) {
        http_response_code(500);
        echo 'Service unavailable';
        return;
    }

    $settings = tkGetSettings();
    if (($settings['public_form_enabled'] ?? '1') !== '1') {
        http_response_code(403);
        echo $ctx->render('pages/404.disyl', ['page_title' => 'Form Unavailable']);
        return;
    }

    $captcha = tkGenerateCaptcha();

    echo tkRender('modules/ticketing/public-submit.disyl', [
        'page_title'      => 'Submit a Maintenance Request',
        'captcha_question' => $captcha['question'],
        'captcha_token'   => $captcha['token'],
        'base_url'        => external_base_url((string)config('app.url', '')),
    ]);
}

function handlePublicSubmitSuccess(array $params = []): void
{
    $ctx = module();
    if (!$ctx) {
        http_response_code(500);
        echo 'Service unavailable';
        return;
    }

    // Sanitise ticket number from query string — only allow TK-NNNN format
    $raw      = strtoupper(trim((string) ($_GET['t'] ?? '')));
    $ticketNo = preg_match('/^TK-[0-9A-Z]{1,10}$/', $raw) ? $raw : '';

    echo tkRender('modules/ticketing/public-success.disyl', [
        'page_title' => 'Request Submitted',
        'ticket_no'  => $ticketNo,
        'base_url'   => external_base_url((string)config('app.url', '')),
    ]);
}

function apiGetCaptcha(array $params = []): void
{
    $captcha = tkGenerateCaptcha();
    header('Content-Type: application/json');
    echo (string) json_encode(['question' => $captcha['question'], 'token' => $captcha['token']]);
}

function apiPublicSubmitTicket(array $params = []): void
{
    header('Content-Type: application/json');

    $ctx = module();
    if (!$ctx) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Service unavailable']);
        return;
    }

    $settings = tkGetSettings();
    if (($settings['public_form_enabled'] ?? '1') !== '1') {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Public submissions are currently disabled.']);
        return;
    }

    // Parse multipart POST body — $_POST is already populated by PHP for multipart/form-data
    $input = $_POST;
    if (empty($input)) {
        $raw = (string) file_get_contents('php://input');
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $input = $decoded;
            }
        }
    }

    $honeypotTriggered = function_exists('antispamHoneypotTriggered')
        ? antispamHoneypotTriggered($input, '_hp_name')
        : !empty($input['_hp_name']);

    // Honeypot — silently accept but do nothing
    if ($honeypotTriggered) {
        http_response_code(200);
        echo json_encode(['ok' => true, 'ticket_no' => 'TK-' . strtoupper(substr(md5(microtime()), 0, 6))]);
        return;
    }

    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

    // Rate limit: max 5 public submissions per IP per 15 minutes
    if (tkIsRateLimited($ip)) {
        http_response_code(429);
        echo json_encode(['ok' => false, 'error' => 'Too many submissions. Please wait a few minutes before trying again.']);
        return;
    }

    // Captcha verification
    $captchaToken  = trim((string) ($input['captcha_token']  ?? ''));
    $captchaAnswer = trim((string) ($input['captcha_answer'] ?? ''));

    if (!tkVerifyCaptcha($captchaToken, $captchaAnswer)) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Incorrect answer. Please try again.', 'refresh_captcha' => true]);
        return;
    }

    // Validate required fields
    $contactName  = trim((string) ($input['contact_name']  ?? ''));
    $subject      = trim((string) ($input['subject']       ?? ''));
    $description  = trim((string) ($input['description']   ?? ''));
    $unitNo       = substr(trim((string) ($input['unit_no'] ?? '')), 0, 40);
    $contactEmail = trim((string) ($input['contact_email'] ?? ''));
    $contactPhone = trim((string) ($input['contact_phone'] ?? ''));

    $validCategories = ['plumbing', 'electrical', 'pest_control', 'common_area', 'security', 'other'];
    $category = in_array(trim((string) ($input['category'] ?? '')), $validCategories, true)
        ? trim((string) ($input['category']))
        : 'other';

    if ($contactName === '') {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Your name is required.']);
        return;
    }
    if ($subject === '') {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Issue subject is required.']);
        return;
    }
    if (strlen($subject) > 255) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Subject is too long (max 255 characters).']);
        return;
    }
    if ($contactEmail !== '' && !filter_var($contactEmail, FILTER_VALIDATE_EMAIL)) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Invalid email address format.']);
        return;
    }

    $ticketNo = tk_nextTicketNo();

    try {
        $stmt = $ctx->db()->prepare(
            'INSERT INTO tickets
                (ticket_no, subject, description, priority, created_by, source,
                 contact_name, contact_email, contact_phone, unit_no, category, ip_address)
             VALUES
                (:no, :subj, :desc, :pri, 0, :src,
                 :cname, :cemail, :cphone, :unit, :cat, :ip)'
        );
        $stmt->execute([
            ':no'     => $ticketNo,
            ':subj'   => $subject,
            ':desc'   => $description ?: null,
            ':pri'    => 'medium',
            ':src'    => 'public',
            ':cname'  => $contactName,
            ':cemail' => $contactEmail ?: null,
            ':cphone' => $contactPhone ?: null,
            ':unit'   => $unitNo ?: null,
            ':cat'    => $category,
            ':ip'     => $ip,
        ]);
        $newId = (int) $ctx->db()->lastInsertId();
    } catch (\Throwable $e) {
        write_log('ticketing: public submit DB failed: ' . $e->getMessage(), 'error');
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Failed to submit ticket. Please try again.']);
        return;
    }

    // Process image attachments (up to 3, ≤2MB each, jpeg/png/webp only)
    $uploadedFiles = kernelUploadedFile('attachments') ?? [];
    if (!empty($uploadedFiles) && is_array($uploadedFiles)) {
        // PHP multi-file structure: $_FILES['attachments']['name'][0], etc.
        $files = [];
        if (isset($uploadedFiles['name']) && is_array($uploadedFiles['name'])) {
            foreach ($uploadedFiles['name'] as $i => $name) {
                $files[] = [
                    'name'     => $name,
                    'type'     => $uploadedFiles['type'][$i]     ?? '',
                    'tmp_name' => $uploadedFiles['tmp_name'][$i] ?? '',
                    'error'    => $uploadedFiles['error'][$i]    ?? UPLOAD_ERR_NO_FILE,
                    'size'     => $uploadedFiles['size'][$i]     ?? 0,
                ];
            }
        } elseif (isset($uploadedFiles['tmp_name'])) {
            $files = [$uploadedFiles];
        }
        tkProcessAttachments($newId, $files);
    }

    // Notify admins — fire-and-forget; never block ticket creation
    tkNotifyAdmins([
        'id'           => $newId,
        'ticket_no'    => $ticketNo,
        'subject'      => $subject,
        'category'     => $category,
        'unit_no'      => $unitNo,
        'contact_name' => $contactName,
    ]);

    $baseUrl  = external_base_url((string)config('app.url', ''));
    $redirect = $baseUrl . '/submit-ticket/success?t=' . urlencode($ticketNo);

    echo json_encode(['ok' => true, 'ticket_no' => $ticketNo, 'redirect' => $redirect]);
}

// ─── Admin Settings Page Handlers ──────────────────────────────────────

function handleSettingsPage(array $params = []): void
{
    $ctx = module();
    if (!$ctx) {
        http_response_code(500);
        echo 'Module context unavailable';
        return;
    }

    $user = $ctx->requireAuth();
    $role = (string) ($user['role'] ?? '');
    if (!in_array($role, ['admin', 'supervisor'], true)) {
        http_response_code(403);
        echo $ctx->render('pages/404.disyl', ['page_title' => 'Access Denied']);
        return;
    }

    $settings = tkGetSettings();

    echo tkRender('modules/ticketing/pages/settings.disyl', [
        'page_title' => 'Ticketing — Settings',
        'settings'   => $settings,
    ]);
}

function apiSaveSettings(array $params = []): void
{
    $ctx = module();
    if (!$ctx) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Service unavailable']);
        return;
    }

    $user = $ctx->requireAuth();
    $role = (string) ($user['role'] ?? '');
    if (!in_array($role, ['admin', 'supervisor'], true)) {
        $ctx->json(['ok' => false, 'error' => 'Access denied.'], 403);
    }

    $input = $ctx->input();

    $adminEmail = trim((string) ($input['admin_email'] ?? ''));
    if ($adminEmail !== '' && !filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
        $ctx->json(['ok' => false, 'error' => 'Invalid admin email format.'], 422);
    }

    $fields = [
        'admin_phone'         => substr(trim((string) ($input['admin_phone']         ?? '')), 0, 30),
        'admin_email'         => $adminEmail,
        'notify_sms'          => ($input['notify_sms']          ?? '') === '1' ? '1' : '0',
        'notify_email'        => ($input['notify_email']         ?? '') === '1' ? '1' : '0',
        'public_form_enabled' => ($input['public_form_enabled']  ?? '') === '1' ? '1' : '0',
    ];

    foreach ($fields as $key => $value) {
        tkSaveSetting($key, $value);
    }

    if ($ctx->isHtmx()) {
        $ctx->redirect('/admin/ticketing/settings');
    }

    $ctx->json(['ok' => true, 'message' => 'Settings saved.']);
}

// ─── CMS Block Extension Hooks (Phase 6 — optional) ───────────────────

function tkCmsBlockTypes(array $payload): array
{
    $types = is_array($payload['types'] ?? null) ? $payload['types'] : [];
    $types[] = [
        'type'     => 'ticketing-form',
        'label'    => 'Maintenance Request Form',
        'icon'     => 'tool',
        'category' => 'Forms',
    ];
    return array_merge($payload, ['types' => $types]);
}

function tkCmsBlockRenderer(array $payload): string
{
    if (($payload['block']['type'] ?? '') !== 'ticketing-form') {
        return '';
    }

    $settings = tkGetSettings();
    if (($settings['public_form_enabled'] ?? '1') !== '1') {
        return '<p style="color:#9ca3af;font-size:14px;">Maintenance request form is currently disabled.</p>';
    }

    $captcha = tkGenerateCaptcha();
    $base    = external_base_url((string)config('app.url', ''));
    $heading = htmlspecialchars((string) ($payload['block']['props']['heading'] ?? 'Submit a Maintenance Request'));

    ob_start();
    ?>
<div class="tk-cms-block" style="max-width:600px;margin:0 auto;">
    <h2 style="font-size:1.4rem;font-weight:700;margin-bottom:1.2rem;"><?= $heading ?></h2>
    <form id="tkCmsForm" enctype="multipart/form-data" novalidate>
        <input type="hidden" name="captcha_token"  value="<?= htmlspecialchars($captcha['token']) ?>">
        <input type="text"   name="_hp_name"       style="display:none;" tabindex="-1" autocomplete="off">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">
            <div><label style="display:block;font-size:13px;font-weight:600;margin-bottom:4px;">Your Name *</label>
                <input type="text" name="contact_name" required style="width:100%;padding:8px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:14px;box-sizing:border-box;">
            </div>
            <div><label style="display:block;font-size:13px;font-weight:600;margin-bottom:4px;">Unit / Room</label>
                <input type="text" name="unit_no" placeholder="e.g. Unit 4B" style="width:100%;padding:8px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:14px;box-sizing:border-box;">
            </div>
        </div>
        <div style="margin-bottom:12px;"><label style="display:block;font-size:13px;font-weight:600;margin-bottom:4px;">Category *</label>
            <select name="category" style="width:100%;padding:8px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:14px;">
                <option value="plumbing">Plumbing</option>
                <option value="electrical">Electrical</option>
                <option value="pest_control">Pest Control</option>
                <option value="common_area">Common Area</option>
                <option value="security">Security</option>
                <option value="other" selected>Other</option>
            </select>
        </div>
        <div style="margin-bottom:12px;"><label style="display:block;font-size:13px;font-weight:600;margin-bottom:4px;">Subject *</label>
            <input type="text" name="subject" required placeholder="Brief description of the issue" style="width:100%;padding:8px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:14px;box-sizing:border-box;">
        </div>
        <div style="margin-bottom:12px;"><label style="display:block;font-size:13px;font-weight:600;margin-bottom:4px;">Details</label>
            <textarea name="description" rows="4" placeholder="Describe the issue in detail (optional)" style="width:100%;padding:8px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:14px;resize:vertical;box-sizing:border-box;"></textarea>
        </div>
        <div style="margin-bottom:12px;"><label style="display:block;font-size:13px;font-weight:600;margin-bottom:4px;">Photos (optional, up to 3)</label>
            <input type="file" name="attachments[]" accept="image/jpeg,image/png,image/webp" multiple style="font-size:14px;">
        </div>
        <div style="margin-bottom:16px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:6px;padding:12px;">
            <label style="display:block;font-size:13px;font-weight:600;margin-bottom:6px;" id="tkCmsCaptchaQ"><?= htmlspecialchars($captcha['question']) ?></label>
            <input type="number" name="captcha_answer" required placeholder="Answer" style="width:120px;padding:8px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:14px;">
        </div>
        <div id="tkCmsError" style="display:none;color:#dc2626;font-size:13px;margin-bottom:10px;"></div>
        <button type="submit" style="background:#2563eb;color:#fff;padding:10px 24px;border:none;border-radius:6px;font-size:14px;font-weight:600;cursor:pointer;">Submit Request</button>
    </form>
    <script>
    (function(){
        var form = document.getElementById('tkCmsForm');
        var errBox = document.getElementById('tkCmsError');
        var base = '<?= $base ?>';
        form.addEventListener('submit', function(e){
            e.preventDefault();
            errBox.style.display = 'none';
            var fd = new FormData(form);
            fetch(base + '/api/v1/tickets/public-submit', {method:'POST', body:fd})
                .then(function(r){ return r.json(); })
                .then(function(d){
                    if(d.ok){ window.location.href = d.redirect; return; }
                    errBox.textContent = d.error || 'An error occurred.';
                    errBox.style.display = 'block';
                    if(d.refresh_captcha){
                        fetch(base + '/api/v1/tickets/captcha')
                            .then(function(r){ return r.json(); })
                            .then(function(c){
                                document.getElementById('tkCmsCaptchaQ').textContent = c.question;
                                form.querySelector('[name="captcha_token"]').value = c.token;
                                form.querySelector('[name="captcha_answer"]').value = '';
                            });
                    }
                })
                .catch(function(){ errBox.textContent='Network error. Please try again.'; errBox.style.display='block'; });
        });
    })();
    </script>
</div>
    <?php
    return (string) ob_get_clean();
}
