<?php

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────
// Ecommerce Module — Admin Memberships & Loyalty (handlers/71-admin-memberships-loyalty.php)
// ─────────────────────────────────────────────────────────────────────────

/**
 * GET /ecommerce/admin/memberships — list all memberships
 */
function ecAdminMemberships(): void
{
    $user = ecRequireAdmin();
    $input = ecInput();
    $status = trim((string)($input['status'] ?? ''));
    $search = trim((string)($input['search'] ?? ''));
    $page = max(1, (int)($input['page'] ?? 1));
    $limit = 25;
    $offset = ($page - 1) * $limit;

    $result = ecAdminMembershipList([
        'status' => $status,
        'search' => $search,
        'limit'  => $limit,
        'offset' => $offset,
    ]);

    $ctx = ecAdminContext($user, 'memberships', [
        'memberships' => $result['items'],
        'total'       => (int)$result['total'],
        'total_pages' => max(1, (int)ceil(((int)$result['total']) / $limit)),
        'page'        => $page,
        'status'      => $status,
        'search'      => $search,
        'counts'      => $result['counts'] ?? [],
        'message'     => $_SESSION['ec_message'] ?? null,
    ]);
    unset($_SESSION['ec_message']);

    ecRender('modules/ecommerce/admin/memberships.disyl', $ctx);
}

/**
 * GET /ecommerce/admin/loyalty — loyalty ledger overview
 */
function ecAdminLoyalty(): void
{
    $user = ecRequireAdmin();
    $input = ecInput();
    $search = trim((string)($input['search'] ?? ''));
    $entryType = trim((string)($input['type'] ?? ''));
    $page = max(1, (int)($input['page'] ?? 1));
    $limit = 30;
    $offset = ($page - 1) * $limit;

    $result = ecAdminLoyaltyLedger([
        'search'     => $search,
        'entry_type' => $entryType,
        'limit'      => $limit,
        'offset'     => $offset,
    ]);

    $ctx = ecAdminContext($user, 'loyalty', [
        'entries'     => $result['items'],
        'total'       => (int)$result['total'],
        'total_pages' => max(1, (int)ceil(((int)$result['total']) / $limit)),
        'page'        => $page,
        'search'      => $search,
        'entry_type'  => $entryType,
        'stats'       => $result['stats'] ?? [],
        'message'     => $_SESSION['ec_message'] ?? null,
    ]);
    unset($_SESSION['ec_message']);

    ecRender('modules/ecommerce/admin/loyalty.disyl', $ctx);
}

// ── Helper: list memberships for admin ────────────────────────────────────

function ecAdminMembershipList(array $opts = []): array
{
    if (!ecMembershipStorageAvailable()) {
        return ['items' => [], 'total' => 0, 'counts' => ['active' => 0, 'expired' => 0, 'cancelled' => 0]];
    }

    $db = ecDb();
    $status = trim((string)($opts['status'] ?? ''));
    $search = trim((string)($opts['search'] ?? ''));
    $limit  = max(1, (int)($opts['limit'] ?? 25));
    $offset = max(0, (int)($opts['offset'] ?? 0));

    $where = [];
    $params = [];
    if ($status !== '' && $status !== 'all') {
        if ($status === 'expired') {
            $where[] = "(m.status = 'active' AND m.ends_at IS NOT NULL AND m.ends_at < NOW())";
        } else {
            $where[] = 'm.status = ?';
            $params[] = $status;
            if ($status === 'active') {
                $where[] = '(m.ends_at IS NULL OR m.ends_at >= NOW())';
            }
        }
    }
    if ($search !== '') {
        $like = '%' . $search . '%';
        $where[] = '(m.customer_email LIKE ? OR m.product_title LIKE ? OR m.membership_tier LIKE ? OR u.display_name LIKE ?)';
        $params = array_merge($params, [$like, $like, $like, $like]);
    }

    $whereClause = $where !== [] ? 'WHERE ' . implode(' AND ', $where) : '';

    try {
        $total = (int)$db->query(
            "SELECT COUNT(*) FROM ec_memberships m LEFT JOIN cms_users u ON u.id = m.customer_id {$whereClause}",
            $params
        )->fetchColumn();

        $rows = $db->query(
            "SELECT m.*, u.display_name AS customer_name
               FROM ec_memberships m
               LEFT JOIN cms_users u ON u.id = m.customer_id
               {$whereClause}
             ORDER BY m.created_at DESC, m.id DESC
             LIMIT {$limit} OFFSET {$offset}",
            $params
        )->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        $items = array_map(function (array $row): array {
            $row = ecMembershipNormalizeRow($row);
            $row['customer_name'] = (string)($row['customer_name'] ?? '');
            if ($row['customer_name'] === '' && !empty($row['customer_email'])) {
                $row['customer_name'] = explode('@', (string)$row['customer_email'])[0];
            }
            return $row;
        }, $rows);

        $counts = [
            'active'    => (int)$db->query("SELECT COUNT(*) FROM ec_memberships WHERE status = 'active' AND (ends_at IS NULL OR ends_at >= NOW())")->fetchColumn(),
            'expired'   => (int)$db->query("SELECT COUNT(*) FROM ec_memberships WHERE (status = 'active' AND ends_at IS NOT NULL AND ends_at < NOW()) OR status = 'expired'")->fetchColumn(),
            'cancelled' => (int)$db->query("SELECT COUNT(*) FROM ec_memberships WHERE status = 'cancelled'")->fetchColumn(),
        ];
    } catch (\Throwable $e) {
        return ['items' => [], 'total' => 0, 'counts' => ['active' => 0, 'expired' => 0, 'cancelled' => 0]];
    }

    return ['items' => $items, 'total' => $total, 'counts' => $counts];
}

// ── Helper: list loyalty ledger for admin ────────────────────────────────

function ecAdminLoyaltyLedger(array $opts = []): array
{
    if (!ecLoyaltyStorageAvailable()) {
        return ['items' => [], 'total' => 0, 'stats' => ['total_earned' => 0, 'total_redeemed' => 0, 'unique_customers' => 0]];
    }

    $db = ecDb();
    $search    = trim((string)($opts['search'] ?? ''));
    $entryType = trim((string)($opts['entry_type'] ?? ''));
    $limit     = max(1, (int)($opts['limit'] ?? 30));
    $offset    = max(0, (int)($opts['offset'] ?? 0));

    $where = [];
    $params = [];
    if ($entryType !== '' && $entryType !== 'all') {
        $where[] = 'l.entry_type = ?';
        $params[] = $entryType;
    }
    if ($search !== '') {
        $like = '%' . $search . '%';
        $where[] = '(u.display_name LIKE ? OR u.email LIKE ? OR l.description LIKE ?)';
        $params = array_merge($params, [$like, $like, $like]);
    }

    $whereClause = $where !== [] ? 'WHERE ' . implode(' AND ', $where) : '';

    try {
        $total = (int)$db->query(
            "SELECT COUNT(*) FROM ec_loyalty_ledger l LEFT JOIN cms_users u ON u.id = l.customer_id {$whereClause}",
            $params
        )->fetchColumn();

        $rows = $db->query(
            "SELECT l.*, u.display_name AS customer_name, u.email AS customer_email
               FROM ec_loyalty_ledger l
               LEFT JOIN cms_users u ON u.id = l.customer_id
               {$whereClause}
             ORDER BY l.created_at DESC, l.id DESC
             LIMIT {$limit} OFFSET {$offset}",
            $params
        )->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        $items = array_map(function (array $row): array {
            $row['customer_name'] = (string)($row['customer_name'] ?? '');
            if ($row['customer_name'] === '' && !empty($row['customer_email'])) {
                $row['customer_name'] = explode('@', (string)$row['customer_email'])[0];
            }
            $row['points'] = (int)($row['points'] ?? 0);
            return $row;
        }, $rows);

        $stats = [
            'total_earned'     => (int)$db->query("SELECT COALESCE(SUM(points), 0) FROM ec_loyalty_ledger WHERE entry_type = 'earn'")->fetchColumn(),
            'total_redeemed'   => abs((int)$db->query("SELECT COALESCE(SUM(points), 0) FROM ec_loyalty_ledger WHERE entry_type = 'redeem'")->fetchColumn()),
            'unique_customers' => (int)$db->query("SELECT COUNT(DISTINCT customer_id) FROM ec_loyalty_ledger")->fetchColumn(),
        ];
    } catch (\Throwable $e) {
        return ['items' => [], 'total' => 0, 'stats' => ['total_earned' => 0, 'total_redeemed' => 0, 'unique_customers' => 0]];
    }

    return ['items' => $items, 'total' => $total, 'stats' => $stats];
}
