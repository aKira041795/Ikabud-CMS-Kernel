<?php

declare(strict_types=1);

namespace Ikabud\Kernel\Capabilities;

use PDO;
use PDOException;

final class ServiceProxyV2
{
    private const ALLOWED_ALGS = ['RS256', 'ES256'];
    private const SIGNED_HEADER_ORDER = [
        'method',
        'path',
        'host',
        'body_hash',
        'timestamp',
        'nonce',
        'kid',
        'alg',
        'endpoint',
        'provider',
        'capability',
        'version',
    ];

    public static function canonicalJson(array $data): string
    {
        $normalized = self::normalizeValue($data);
        $json = json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        return rtrim((string) $json, "\n");
    }

    public static function bodyHash(string $body): string
    {
        return hash('sha256', $body);
    }

    public static function sign(array $headers, array $key): string
    {
        $alg = (string) ($key['alg'] ?? $headers['alg'] ?? '');
        $kid = (string) ($key['kid'] ?? $headers['kid'] ?? '');

        if (!in_array($alg, self::ALLOWED_ALGS, true)) {
            throw new CapabilityCallException('algorithm not allowed', '', $headers['provider'] ?? null);
        }

        $notAfter = self::normalizeEpoch($key['not_after'] ?? null);
        if (array_key_exists('active_for_signing', $key)) {
            if (!(bool) $key['active_for_signing']) {
                throw new CapabilityCallException('signing key expired', '', $headers['provider'] ?? null);
            }
        } elseif ($notAfter !== null && time() > $notAfter) {
            throw new CapabilityCallException('signing key expired', '', $headers['provider'] ?? null);
        }

        $headers = self::normalizeHeaders($headers + ['alg' => $alg, 'kid' => $kid]);
        $protected = ['alg' => $alg, 'kid' => $kid];
        $signingInput = self::buildSignedString($headers);
        $privateKey = openssl_pkey_get_private((string) ($key['private_key'] ?? ''));

        if ($privateKey === false) {
            throw new CapabilityCallException('invalid private key', '', $headers['provider'] ?? null);
        }

        $signature = '';
        $ok = openssl_sign($signingInput, $signature, $privateKey, OPENSSL_ALGO_SHA256);
        if (!$ok) {
            throw new CapabilityCallException('signing failed', '', $headers['provider'] ?? null);
        }

        return self::base64urlEncode(self::canonicalJson($protected))
            . '.' . self::base64urlEncode(self::canonicalJson($headers))
            . '.' . self::base64urlEncode($signature);
    }

    public static function verify(string $token, array $expected, array $keyring, PDO $nonceDb, array $opts = []): array
    {
        $expected = self::normalizeHeaders($expected);
        $parts = explode('.', $token);
        if (count($parts) !== 3 || $parts[0] === '' || $parts[1] === '' || $parts[2] === '') {
            throw new CapabilityCallException('invalid token structure', (string) ($expected['capability'] ?? ''), $expected['provider'] ?? null);
        }

        $protectedJson = self::base64urlDecode($parts[0]);
        $payloadJson = self::base64urlDecode($parts[1]);
        $signature = self::base64urlDecode($parts[2]);
        if ($protectedJson === null || $payloadJson === null || $signature === null) {
            throw new CapabilityCallException('invalid token structure', (string) ($expected['capability'] ?? ''), $expected['provider'] ?? null);
        }

        $protected = json_decode($protectedJson, true);
        $payload = json_decode($payloadJson, true);
        if (!is_array($protected) || !is_array($payload)) {
            throw new CapabilityCallException('invalid token structure', (string) ($expected['capability'] ?? ''), $expected['provider'] ?? null);
        }

        $payload = self::normalizeHeaders($payload);
        $alg = (string) ($protected['alg'] ?? '');
        $kid = (string) ($protected['kid'] ?? '');

        if (!in_array($alg, self::ALLOWED_ALGS, true)) {
            throw new CapabilityCallException('algorithm not allowed', (string) ($payload['capability'] ?? ''), $payload['provider'] ?? null);
        }

        if ($kid === '' || !isset($keyring[$kid]) || !is_array($keyring[$kid])) {
            throw new CapabilityCallException('unknown kid', (string) ($payload['capability'] ?? ''), $payload['provider'] ?? null);
        }

        $now = isset($opts['now']) ? (int) $opts['now'] : time();
        $entry = $keyring[$kid];
        if (!self::keyAllowedForVerification($entry, $now, $opts)) {
            throw new CapabilityCallException('key expired', (string) ($payload['capability'] ?? ''), $payload['provider'] ?? null);
        }
        if ((string) ($entry['alg'] ?? '') !== $alg) {
            throw new CapabilityCallException('invalid signature', (string) ($payload['capability'] ?? ''), $payload['provider'] ?? null);
        }
        if ((string) ($payload['alg'] ?? '') !== $alg || (string) ($payload['kid'] ?? '') !== $kid) {
            throw new CapabilityCallException('invalid signature', (string) ($payload['capability'] ?? ''), $payload['provider'] ?? null);
        }

        $publicKey = openssl_pkey_get_public((string) ($entry['public_key'] ?? ''));
        if ($publicKey === false) {
            throw new CapabilityCallException('verification key unavailable', (string) ($payload['capability'] ?? ''), $payload['provider'] ?? null);
        }

        $verifyOk = openssl_verify(self::buildSignedString($payload), $signature, $publicKey, OPENSSL_ALGO_SHA256);
        if ($verifyOk !== 1) {
            throw new CapabilityCallException('invalid signature', (string) ($payload['capability'] ?? ''), $payload['provider'] ?? null);
        }

        $timestamp = filter_var($payload['timestamp'] ?? null, FILTER_VALIDATE_INT);
        if ($timestamp === false) {
            throw new CapabilityCallException('invalid timestamp', (string) ($payload['capability'] ?? ''), $payload['provider'] ?? null);
        }

        $maxSkew = max(0, (int) ($opts['max_skew_seconds'] ?? 300));
        if (abs($now - (int) $timestamp) > $maxSkew) {
            throw new CapabilityCallException('timestamp skew exceeded', (string) ($payload['capability'] ?? ''), $payload['provider'] ?? null);
        }

        foreach (['method', 'path', 'host', 'endpoint', 'provider', 'capability', 'version', 'body_hash', 'tenant_id'] as $field) {
            if ((string) ($payload[$field] ?? '') !== (string) ($expected[$field] ?? '')) {
                throw new CapabilityCallException('binding mismatch: ' . $field, (string) ($payload['capability'] ?? ''), $payload['provider'] ?? null);
            }
        }

        $namespace = (string) ($expected['tenant_id'] ?? '')
            . ':' . (string) ($expected['provider'] ?? '')
            . ':' . (string) ($expected['capability'] ?? '')
            . ':' . (string) ($expected['version'] ?? '');
        $ttl = max(1, (int) ($opts['nonce_ttl_seconds'] ?? 300));

        if (!self::nonceReserve($nonceDb, $namespace, (string) ($payload['nonce'] ?? ''), $ttl)) {
            throw new CapabilityCallException('replay detected', (string) ($payload['capability'] ?? ''), $payload['provider'] ?? null);
        }

        return [
            'kid' => $kid,
            'capability' => (string) ($payload['capability'] ?? ''),
            'version' => (string) ($payload['version'] ?? ''),
            'provider' => (string) ($payload['provider'] ?? ''),
            'tenant_id' => (string) ($payload['tenant_id'] ?? ''),
        ];
    }

    public static function nonceReserve(PDO $db, string $namespace, string $nonce, int $ttlSeconds): bool
    {
        $ttlSeconds = max(1, $ttlSeconds);
        $sql = 'INSERT INTO nonce_reservations (namespace, nonce, expires_at) VALUES (:ns, :nonce, DATE_ADD(NOW(), INTERVAL ' . $ttlSeconds . ' SECOND))';

        try {
            $stmt = $db->prepare($sql);
            $stmt->execute([':ns' => $namespace, ':nonce' => $nonce]);
            return true;
        } catch (PDOException $e) {
            $sqlState = (string) ($e->getCode() ?? '');
            $driverCode = (int) ($e->errorInfo[1] ?? 0);
            if ($sqlState === '23000' || $driverCode === 1062) {
                return false;
            }
            throw $e;
        }
    }

    public static function nonceSweepExpired(PDO $db): int
    {
        $stmt = $db->prepare('DELETE FROM nonce_reservations WHERE expires_at < NOW()');
        $stmt->execute();
        return $stmt->rowCount();
    }

    public static function keyRingFromConfig(array $config): array
    {
        $now = isset($config['__now']) ? (int) $config['__now'] : time();
        $overlap = max(0, (int) ($config['__overlap_window_seconds'] ?? 3600));
        $allowLegacy = !array_key_exists('__allow_legacy_keys', $config) || (bool) $config['__allow_legacy_keys'];
        unset($config['__now'], $config['__overlap_window_seconds'], $config['__allow_legacy_keys']);

        $keyring = [];
        foreach ($config as $kid => $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $alg = (string) ($entry['alg'] ?? '');
            if (!in_array($alg, self::ALLOWED_ALGS, true)) {
                throw new CapabilityCallException('algorithm not allowed', '', is_string($kid) ? $kid : null);
            }

            $notAfter = self::normalizeEpoch($entry['not_after'] ?? null);
            $activeForSigning = $notAfter === null || $now <= $notAfter;
            $verifyUntil = $notAfter === null ? null : ($allowLegacy ? $notAfter + $overlap : $notAfter);

            $keyring[(string) $kid] = [
                'kid' => (string) $kid,
                'alg' => $alg,
                'public_key' => (string) ($entry['public_key'] ?? ''),
                'private_key' => (string) ($entry['private_key'] ?? ''),
                'not_after' => $notAfter,
                'active_for_signing' => $activeForSigning,
                'verify_until' => $verifyUntil,
            ];
        }

        return $keyring;
    }

    private static function buildSignedString(array $headers): string
    {
        $headers = self::normalizeHeaders($headers);
        $lines = [];
        foreach (self::SIGNED_HEADER_ORDER as $field) {
            if (!array_key_exists($field, $headers)) {
                throw new CapabilityCallException('missing signed header: ' . $field, (string) ($headers['capability'] ?? ''), $headers['provider'] ?? null);
            }
            $label = $field === 'body_hash' ? 'body-hash' : $field;
            $value = $headers[$field];
            if (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            } elseif ($value === null) {
                $value = '';
            }
            $lines[] = $label . ':' . (string) $value;
        }

        return implode("\n", $lines);
    }

    private static function normalizeHeaders(array $headers): array
    {
        if (array_key_exists('body-hash', $headers) && !array_key_exists('body_hash', $headers)) {
            $headers['body_hash'] = $headers['body-hash'];
            unset($headers['body-hash']);
        }
        return self::normalizeValue($headers);
    }

    private static function normalizeValue(mixed $value): mixed
    {
        if (is_string($value)) {
            return function_exists('mb_convert_encoding')
                ? mb_convert_encoding($value, 'UTF-8', 'UTF-8')
                : $value;
        }

        if (!is_array($value)) {
            return $value;
        }

        if (self::isList($value)) {
            $out = [];
            foreach ($value as $item) {
                $out[] = self::normalizeValue($item);
            }
            return $out;
        }

        $normalized = [];
        $keys = array_keys($value);
        $keys = array_map(static fn ($key) => is_string($key) && function_exists('mb_convert_encoding') ? mb_convert_encoding($key, 'UTF-8', 'UTF-8') : (string) $key, $keys);
        sort($keys, SORT_STRING);
        foreach ($keys as $key) {
            $sourceKey = array_key_exists($key, $value) ? $key : self::findOriginalKey($value, $key);
            if ($sourceKey === null) {
                continue;
            }
            $normalized[$key] = self::normalizeValue($value[$sourceKey]);
        }
        return $normalized;
    }

    private static function findOriginalKey(array $value, string $normalizedKey): int|string|null
    {
        foreach ($value as $key => $_) {
            $candidate = is_string($key) && function_exists('mb_convert_encoding')
                ? mb_convert_encoding($key, 'UTF-8', 'UTF-8')
                : (string) $key;
            if ($candidate === $normalizedKey) {
                return $key;
            }
        }
        return null;
    }

    private static function isList(array $value): bool
    {
        if (function_exists('array_is_list')) {
            return array_is_list($value);
        }

        $index = 0;
        foreach ($value as $key => $_) {
            if ($key !== $index++) {
                return false;
            }
        }
        return true;
    }

    private static function normalizeEpoch(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int) $value;
        }
        $parsed = strtotime((string) $value);
        return $parsed === false ? null : $parsed;
    }

    private static function keyAllowedForVerification(array $key, int $now, array $opts): bool
    {
        $notAfter = self::normalizeEpoch($key['not_after'] ?? null);
        if ($notAfter === null) {
            return true;
        }

        $verifyUntil = $key['verify_until'] ?? null;
        if ($verifyUntil === null) {
            $overlap = max(0, (int) ($opts['overlap_window_seconds'] ?? 3600));
            $allowLegacy = !array_key_exists('allow_legacy_keys', $opts) || (bool) $opts['allow_legacy_keys'];
            $verifyUntil = $allowLegacy ? $notAfter + $overlap : $notAfter;
        }

        return $now <= (int) $verifyUntil;
    }

    private static function base64urlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function base64urlDecode(string $value): ?string
    {
        if ($value === '' || preg_match('/^[A-Za-z0-9\-_]+$/', $value) !== 1) {
            return null;
        }
        $remainder = strlen($value) % 4;
        if ($remainder > 0) {
            $value .= str_repeat('=', 4 - $remainder);
        }
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        return $decoded === false ? null : $decoded;
    }
}
