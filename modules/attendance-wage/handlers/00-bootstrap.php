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
        if ($isFormPost) {
            $base = rtrim((string)(defined('BASE_URL') ? BASE_URL : ''), '/');
            header('Location: ' . $base . '/attendance-wage/login?error=session_expired');
            exit;
        }
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'Authentication required']);
        exit;
    }
    return $user;
}

function aw_currentUserId(): int
{
    $user = attendanceWageUser();
    return (int)($user['id'] ?? 0);
}
