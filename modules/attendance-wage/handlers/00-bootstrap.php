<?php

declare(strict_types=1);

function attendanceWageUser(): ?array
{
    $user = app()->user();
    if (is_array($user) && (($user['source'] ?? '') === 'attendance-wage')) {
        return $user;
    }
    return null;
}

function attendanceWageGuard(string $capability = ''): ?array
{
    $user = attendanceWageUser();
    if (!$user) {
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        $isFormPost = !str_contains($contentType, 'application/json') && $_SERVER['REQUEST_METHOD'] === 'POST';
        $isPageRequest = $_SERVER['REQUEST_METHOD'] === 'GET' && !str_starts_with(($_SERVER['REQUEST_URI'] ?? ''), '/api/');
        if ($isFormPost || $isPageRequest) {
            $base = rtrim((string)(defined('BASE_URL') ? BASE_URL : ''), '/');
            header('Location: ' . $base . '/attendance-wage/login?error=session_expired');
            exit;
        }
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'Authentication required']);
        exit;
    }

    // Enforce capability check when a specific capability is required
    if ($capability !== '') {
        try {
            if (!app()->capabilities()->check($capability, $user)) {
                http_response_code(403);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => false, 'error' => 'Insufficient permissions']);
                exit;
            }
        } catch (\Throwable $e) {
            // If capability system is unavailable, fall back to role-based check
            $userRole = $user['role'] ?? '';
            if ($userRole !== 'admin') {
                http_response_code(403);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => false, 'error' => 'Insufficient permissions']);
                exit;
            }
        }
    }

    // CSRF enforcement for all authenticated POST requests
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        app()->csrfEnforce();
    }
    return $user;
}

function aw_currentUserId(): int
{
    $user = attendanceWageUser();
    return aw_extractUserId($user);
}

/**
 * Extract numeric user ID from the user array, handling both JWT (sub-only)
 * and full auth result (has id) formats.
 */
function aw_extractUserId(?array $user): int
{
    if (!is_array($user)) return 0;
    $id = (int)($user['id'] ?? 0);
    if ($id > 0) return $id;
    $sub = (string)($user['sub'] ?? '');
    if (str_starts_with($sub, 'attendance-wage:')) {
        return (int)substr($sub, strlen('attendance-wage:'));
    }
    return 0;
}
