<?php

declare(strict_types=1);

namespace Harpp\Services;

use Ikabud\Kernel\Contracts\ModuleDB;
use PDO;
use Throwable;

final class HarppPasswordResetService
{
    public function __construct(private ?ModuleDB $database = null)
    {
    }

    private function db(): ModuleDB
    {
        if ($this->database instanceof ModuleDB) {
            return $this->database;
        }
        $db = \module('harpp')->db();
        if (!$db instanceof ModuleDB) {
            throw new \RuntimeException('HARPP module database is unavailable.');
        }
        return $this->database = $db;
    }

    public function forgotPassword(string $email)
    {
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return HarppServiceResult::failure('A valid email address is required.');
        }

        $generic = 'If an active HARPP account matches that email, a reset link has been sent.';
        try {
            $stmt = $this->db()->prepare('SELECT id, email, full_name FROM harpp_users WHERE LOWER(email) = :email AND is_active = 1 AND deleted_at IS NULL LIMIT 1');
            $stmt->execute([':email' => $email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($user)) {
                return HarppServiceResult::success([], $generic);
            }

            $rawToken = bin2hex(random_bytes(32));
            $tokenHash = hash('sha256', $rawToken);
            $ttl = max(15, min(120, (int)(function_exists('kernel_password_reset_policy')
                ? (\kernel_password_reset_policy()['token_ttl_minutes'] ?? 60)
                : 60)));

            $this->db()->beginTransaction();
            $invalidate = $this->db()->prepare('UPDATE harpp_password_resets SET used_at = NOW() WHERE user_id = :user_id AND used_at IS NULL');
            $invalidate->execute([':user_id' => (int)$user['id']]);
            $insert = $this->db()->prepare(
                'INSERT INTO harpp_password_resets (user_id, token, expires_at, used_at, created_at) VALUES (:user_id, :token, DATE_ADD(NOW(), INTERVAL ' . $ttl . ' MINUTE), NULL, NOW())'
            );
            $insert->execute([':user_id' => (int)$user['id'], ':token' => $tokenHash]);
            $this->db()->commit();

            $url = $this->resetUrl($rawToken);
            if (function_exists('write_log')) {
                \write_log('HARPP password reset link issued', 'HARPP', ['module' => 'harpp', 'user_id' => (int)$user['id'], 'reset_url' => $url]);
            }
            $this->sendResetEmail($user, $url, $ttl);
            return HarppServiceResult::success([], $generic);
        } catch (Throwable $e) {
            if ($this->db()->inTransaction()) {
                $this->db()->rollBack();
            }
            if (function_exists('write_log')) {
                \write_log('HARPP forgot-password failed', 'error', ['module' => 'harpp', 'error' => $e->getMessage()]);
            }
            return HarppServiceResult::failure('Unable to process the password reset request.', 500);
        }
    }

    public function resetPassword(string $rawToken, string $password, string $confirmation)
    {
        $rawToken = trim($rawToken);
        $auth = new HarppAuthService($this->db());
        if (preg_match('/^[a-f0-9]{64}$/', $rawToken) !== 1) {
            return HarppServiceResult::failure('The reset token is invalid or expired.', 422, 'invalid_token');
        }
        if ($password !== $confirmation) {
            return HarppServiceResult::failure('Passwords do not match.');
        }
        if (!$auth->validPassword($password)) {
            return HarppServiceResult::failure('Password must be at least 12 characters and contain upper, lower, and numeric characters.');
        }

        try {
            $tokenHash = hash('sha256', $rawToken);
            $this->db()->beginTransaction();
            $stmt = $this->db()->prepare(
                'SELECT pr.id, pr.user_id FROM harpp_password_resets pr INNER JOIN harpp_users u ON u.id = pr.user_id WHERE pr.token = :token AND pr.used_at IS NULL AND pr.expires_at > NOW() AND u.is_active = 1 AND u.deleted_at IS NULL LIMIT 1 FOR UPDATE'
            );
            $stmt->execute([':token' => $tokenHash]);
            $reset = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($reset)) {
                $this->db()->rollBack();
                return HarppServiceResult::failure('The reset token is invalid or expired.', 422, 'invalid_token');
            }

            $update = $this->db()->prepare('UPDATE harpp_users SET password_hash = :hash, updated_at = NOW() WHERE id = :user_id AND is_active = 1 AND deleted_at IS NULL');
            $update->execute([':hash' => password_hash($password, PASSWORD_BCRYPT), ':user_id' => (int)$reset['user_id']]);
            $consume = $this->db()->prepare('UPDATE harpp_password_resets SET used_at = NOW() WHERE user_id = :user_id AND used_at IS NULL');
            $consume->execute([':user_id' => (int)$reset['user_id']]);
            $this->db()->commit();
            return HarppServiceResult::success([], 'Password reset complete.');
        } catch (Throwable $e) {
            if ($this->db()->inTransaction()) {
                $this->db()->rollBack();
            }
            if (function_exists('write_log')) {
                \write_log('HARPP reset-password failed', 'error', ['module' => 'harpp', 'error' => $e->getMessage()]);
            }
            return HarppServiceResult::failure('Unable to reset the password.', 500);
        }
    }

    private function resetUrl(string $token): string
    {
        $baseUrl = function_exists('external_base_url') ? \external_base_url() : '';
        if ($baseUrl === '') {
            $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
            if ($host === '' || preg_match('/^[A-Za-z0-9.-]+(?::[0-9]{1,5})?$/', $host) !== 1) {
                $host = 'localhost';
            }
            $scheme = function_exists('request_scheme') ? \request_scheme() : 'http';
            $baseUrl = $scheme . '://' . $host;
        }
        return rtrim($baseUrl, '/') . '/harpp/reset-password?token=' . urlencode($token);
    }

    private function sendResetEmail(array $user, string $url, int $ttl): void
    {
        if (!function_exists('buildEmailTemplate') || !function_exists('sendEmail')) {
            if (function_exists('write_log')) {
                \write_log('HARPP reset email helpers unavailable', 'error', ['module' => 'harpp', 'user_id' => (int)$user['id']]);
            }
            return;
        }

        try {
            $name = htmlspecialchars((string)($user['full_name'] ?? 'HARPP operator'), ENT_QUOTES, 'UTF-8');
            $content = '<p>Hi ' . $name . ',</p><p>Use the button below to reset your HARPP password. This link expires in ' . $ttl . ' minutes.</p><p>If you did not request this, ignore this email.</p>';
            $body = \buildEmailTemplate('Reset your HARPP password', $content, 'Reset password', $url);
            if (!\sendEmail((string)$user['email'], 'HARPP password reset', $body) && function_exists('write_log')) {
                \write_log('HARPP reset email dispatch failed', 'error', ['module' => 'harpp', 'user_id' => (int)$user['id']]);
            }
        } catch (Throwable $e) {
            if (function_exists('write_log')) {
                \write_log('HARPP reset email dispatch failed', 'error', ['module' => 'harpp', 'user_id' => (int)$user['id'], 'error' => $e->getMessage()]);
            }
        }
    }
}
