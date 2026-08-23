<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use Ikabud\Kernel\Capabilities\CapabilityCallException;
use Ikabud\Kernel\Capabilities\ServiceProxyV2;

$pass = 0;
$fail = 0;
$logEvents = 0;

@file_put_contents(__DIR__ . '/../storage/logs/error.log', '');
@file_put_contents(__DIR__ . '/../storage/logs/app.log', '');

function t(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "  ✅ {$label}\n";
        return;
    }

    $fail++;
    echo "  ❌ {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

function expectFailure(string $label, callable $fn, string $contains = '', string $class = CapabilityCallException::class): void
{
    global $logEvents;
    try {
        $fn();
        t($label, false, 'expected ' . $class);
    } catch (Throwable $e) {
        if (!($e instanceof $class)) {
            t($label, false, get_class($e) . ': ' . $e->getMessage());
            return;
        }
        if ($contains !== '') {
            t($label, str_contains($e->getMessage(), $contains), $e->getMessage());
        } else {
            t($label, true);
        }
        write_log('service_proxy_v2_test denial: ' . $e->getMessage(), 'warning', ['label' => $label]);
        $logEvents++;
    }
}

function testDbConfig(): array
{
    return require __DIR__ . '/../config/database.php';
}

function testDb(): PDO
{
    $config = testDbConfig();
    return new PDO(
        sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $config['host'], $config['port'], $config['database'], $config['charset']),
        $config['username'],
        $config['password'],
        $config['options']
    );
}

function ensureNonceTable(PDO $db): void
{
    $sql = file_get_contents(__DIR__ . '/../database/migrations/024_kernel_service_proxy_v2_nonce.sql');
    $db->exec((string) $sql);
    $db->exec('TRUNCATE TABLE nonce_reservations');
}

function exportKeypair(array $config): array
{
    $resource = openssl_pkey_new($config);
    if ($resource === false) {
        throw new RuntimeException('key generation failed');
    }

    openssl_pkey_export($resource, $privateKey);
    $details = openssl_pkey_get_details($resource);
    if (!is_array($details) || !isset($details['key'])) {
        throw new RuntimeException('key export failed');
    }

    return ['private_key' => $privateKey, 'public_key' => $details['key']];
}

function makeHeaders(array $overrides = []): array
{
    $body = ServiceProxyV2::canonicalJson([
        'z' => ['b' => 2, 'a' => 1],
        'a' => 'hello',
        'list' => [3, 2, 1],
    ]);

    return $overrides + [
        'method' => 'POST',
        'path' => '/capability/call',
        'host' => 'reporting.internal',
        'body_hash' => ServiceProxyV2::bodyHash($body),
        'timestamp' => 1700000000,
        'nonce' => 'nonce-001',
        'kid' => 'rsa-a',
        'alg' => 'RS256',
        'endpoint' => 'https://reporting.internal',
        'provider' => 'services/reporting',
        'capability' => 'report.generate',
        'version' => '2',
        'tenant_id' => 'tenant-a',
    ];
}

function expectedFromHeaders(array $headers): array
{
    return [
        'method' => $headers['method'],
        'path' => $headers['path'],
        'host' => $headers['host'],
        'endpoint' => $headers['endpoint'],
        'provider' => $headers['provider'],
        'capability' => $headers['capability'],
        'version' => $headers['version'],
        'body_hash' => $headers['body_hash'],
        'tenant_id' => $headers['tenant_id'],
    ];
}

function b64url(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function tamperTokenPayload(string $token, callable $mutate): string
{
    [$protected, $payload, $signature] = explode('.', $token);
    $data = json_decode(base64_decode(strtr($payload, '-_', '+/')), true, flags: JSON_THROW_ON_ERROR);
    $mutated = $mutate($data);
    return $protected . '.' . b64url(ServiceProxyV2::canonicalJson($mutated)) . '.' . $signature;
}

class ThrowingInsertPdo extends PDO
{
    public function __construct()
    {
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        if (str_starts_with(trim($query), 'INSERT INTO nonce_reservations')) {
            throw new PDOException('nonce store unavailable');
        }

        throw new PDOException('unsupported');
    }
}

echo "=== ServiceProxyV2 Tests ===\n\n";

$db = testDb();
ensureNonceTable($db);

$rsa = exportKeypair(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
$ec = exportKeypair(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);

$keyring = ServiceProxyV2::keyRingFromConfig([
    'rsa-a' => ['alg' => 'RS256', 'public_key' => $rsa['public_key'], 'private_key' => $rsa['private_key'], 'not_after' => 1700003600],
    'ec-a' => ['alg' => 'ES256', 'public_key' => $ec['public_key'], 'private_key' => $ec['private_key'], 'not_after' => 1700003600],
    '__now' => 1700000000,
]);

$canonicalA = ServiceProxyV2::canonicalJson(json_decode("{\n  \"z\": {\"b\": 2, \"a\": 1},\n  \"a\": \"hello\",\n  \"list\": [3,2,1]\n}\n", true, flags: JSON_THROW_ON_ERROR));
$canonicalB = ServiceProxyV2::canonicalJson(json_decode('{"list":[3,2,1],"a":"hello","z":{"a":1,"b":2}}', true, flags: JSON_THROW_ON_ERROR));
t('canonicalJson normalizes equivalent payloads', $canonicalA === $canonicalB, $canonicalA . ' != ' . $canonicalB);
t('canonicalJson has no trailing newline', !str_ends_with($canonicalA, "\n"));
t('canonicalJson keeps UTF-8 valid', mb_check_encoding(ServiceProxyV2::canonicalJson(['emoji' => "mañana ☀️"]), 'UTF-8'));
t('canonicalJson deep-sorts nested keys', str_contains($canonicalA, '"z":{"a":1,"b":2}'), $canonicalA);

$headers = makeHeaders();
$expected = expectedFromHeaders($headers);
$token = ServiceProxyV2::sign($headers, $keyring['rsa-a']);
$verified = ServiceProxyV2::verify($token, $expected, $keyring, $db, ['now' => 1700000000]);
t('sign→verify round trip passes', $verified['kid'] === 'rsa-a' && $verified['tenant_id'] === 'tenant-a', json_encode($verified));

$db->exec('TRUNCATE TABLE nonce_reservations');
foreach ([
    'method' => 'GET',
    'path' => '/capability/other',
    'body_hash' => str_repeat('a', 64),
    'nonce' => 'nonce-002',
    'capability' => 'report.delete',
] as $field => $value) {
    if ($field === 'nonce') {
        $tamperedToken = tamperTokenPayload($token, static function (array $data): array {
            $data['nonce'] = 'nonce-002';
            return $data;
        });
        expectFailure('tamper nonce fails', fn() => ServiceProxyV2::verify($tamperedToken, $expected, $keyring, $db, ['now' => 1700000000]), 'invalid signature');
        continue;
    }

    $tamperedExpected = $expected;
    if ($field === 'body_hash') {
        $tamperedExpected['body_hash'] = $value;
    } else {
        $tamperedExpected[$field] = $value;
    }
    expectFailure('tamper ' . $field . ' fails', fn() => ServiceProxyV2::verify($token, $tamperedExpected, $keyring, $db, ['now' => 1700000000]), 'binding mismatch');
}
expectFailure('body tamper fails', fn() => ServiceProxyV2::verify($token, array_merge($expected, ['body_hash' => ServiceProxyV2::bodyHash('{"bad":true}')]), $keyring, $db, ['now' => 1700000000]), 'binding mismatch');

$db->exec('TRUNCATE TABLE nonce_reservations');
$replayHeaders = makeHeaders(['nonce' => 'replay-1']);
$replayToken = ServiceProxyV2::sign($replayHeaders, $keyring['rsa-a']);
ServiceProxyV2::verify($replayToken, expectedFromHeaders($replayHeaders), $keyring, $db, ['now' => 1700000000]);
expectFailure('replay rejected on second verify', fn() => ServiceProxyV2::verify($replayToken, $expected, $keyring, $db, ['now' => 1700000000]), 'replay detected');

$db->exec('TRUNCATE TABLE nonce_reservations');
expectFailure('cross-tenant replay fails binding', fn() => ServiceProxyV2::verify($token, array_merge($expected, ['tenant_id' => 'tenant-b']), $keyring, $db, ['now' => 1700000000]), 'binding mismatch: tenant_id');
$db->exec('TRUNCATE TABLE nonce_reservations');
expectFailure('cross-provider replay fails binding', fn() => ServiceProxyV2::verify($token, array_merge($expected, ['provider' => 'services/other']), $keyring, $db, ['now' => 1700000000]), 'binding mismatch: provider');

$db->exec('TRUNCATE TABLE nonce_reservations');
$futureHeaders = makeHeaders(['nonce' => 'skew-future', 'timestamp' => 1700000301]);
$futureToken = ServiceProxyV2::sign($futureHeaders, $keyring['rsa-a']);
expectFailure('timestamp now+301 fails', fn() => ServiceProxyV2::verify($futureToken, expectedFromHeaders($futureHeaders), $keyring, $db, ['now' => 1700000000]), 'timestamp skew exceeded');

$db->exec('TRUNCATE TABLE nonce_reservations');
$pastHeaders = makeHeaders(['nonce' => 'skew-past', 'timestamp' => 1699999699]);
$pastToken = ServiceProxyV2::sign($pastHeaders, $keyring['rsa-a']);
expectFailure('timestamp now-301 fails', fn() => ServiceProxyV2::verify($pastToken, expectedFromHeaders($pastHeaders), $keyring, $db, ['now' => 1700000000]), 'timestamp skew exceeded');

$db->exec('TRUNCATE TABLE nonce_reservations');
$nearHeaders = makeHeaders(['nonce' => 'skew-pass', 'timestamp' => 1700000299]);
$nearToken = ServiceProxyV2::sign($nearHeaders, $keyring['rsa-a']);
$near = ServiceProxyV2::verify($nearToken, expectedFromHeaders($nearHeaders), $keyring, $db, ['now' => 1700000000]);
t('timestamp now+299 passes', $near['kid'] === 'rsa-a');

$badProtected = b64url(ServiceProxyV2::canonicalJson(['alg' => 'HS256', 'kid' => 'rsa-a']));
[$_, $payloadSegment, $sigSegment] = explode('.', $token);
expectFailure('alg HS256 fails', fn() => ServiceProxyV2::verify($badProtected . '.' . $payloadSegment . '.' . $sigSegment, $expected, $keyring, $db, ['now' => 1700000000]), 'algorithm not allowed');
$noneProtected = b64url(ServiceProxyV2::canonicalJson(['alg' => 'none', 'kid' => 'rsa-a']));
expectFailure('alg none fails', fn() => ServiceProxyV2::verify($noneProtected . '.' . $payloadSegment . '.' . $sigSegment, $expected, $keyring, $db, ['now' => 1700000000]), 'algorithm not allowed');

$unknownProtected = b64url(ServiceProxyV2::canonicalJson(['alg' => 'RS256', 'kid' => 'missing']));
expectFailure('unknown kid fails', fn() => ServiceProxyV2::verify($unknownProtected . '.' . $payloadSegment . '.' . $sigSegment, $expected, $keyring, $db, ['now' => 1700000000]), 'unknown kid');

$db->exec('TRUNCATE TABLE nonce_reservations');
$rotationRing = ServiceProxyV2::keyRingFromConfig([
    'kid-a' => ['alg' => 'RS256', 'public_key' => $rsa['public_key'], 'private_key' => $rsa['private_key'], 'not_after' => 1699999900],
    'kid-b' => ['alg' => 'ES256', 'public_key' => $ec['public_key'], 'private_key' => $ec['private_key'], 'not_after' => 1700007200],
    '__now' => 1700000000,
    '__overlap_window_seconds' => 3600,
]);
$aHeaders = makeHeaders(['kid' => 'kid-a', 'alg' => 'RS256', 'nonce' => 'rotate-a']);
$aToken = ServiceProxyV2::sign($aHeaders, array_merge($rotationRing['kid-a'], ['not_after' => 1700000100, 'active_for_signing' => true]));
$rotationVerifyRing = $rotationRing;
$rotationVerifyRing['kid-a']['not_after'] = 1699999900;
$rotationVerifyRing['kid-a']['verify_until'] = 1700003500;
$okA = ServiceProxyV2::verify($aToken, expectedFromHeaders($aHeaders), $rotationVerifyRing, $db, ['now' => 1700000000, 'overlap_window_seconds' => 3600]);
t('legacy A token verifies within overlap', $okA['kid'] === 'kid-a');
$db->exec('TRUNCATE TABLE nonce_reservations');
expectFailure('legacy A token fails after overlap', fn() => ServiceProxyV2::verify($aToken, expectedFromHeaders($aHeaders), $rotationVerifyRing, $db, ['now' => 1700003601, 'overlap_window_seconds' => 3600]), 'key expired');
$db->exec('TRUNCATE TABLE nonce_reservations');
$bHeaders = makeHeaders(['kid' => 'kid-b', 'alg' => 'ES256', 'nonce' => 'rotate-b', 'timestamp' => 1700003601]);
$bToken = ServiceProxyV2::sign($bHeaders, $rotationVerifyRing['kid-b']);
$okB = ServiceProxyV2::verify($bToken, expectedFromHeaders($bHeaders), $rotationVerifyRing, $db, ['now' => 1700003601]);
t('B token verifies after rotation', $okB['kid'] === 'kid-b');

expectFailure('nonce outage is fail-closed', fn() => ServiceProxyV2::verify($token, $expected, $keyring, new ThrowingInsertPdo(), ['now' => 1700000000]), 'nonce store unavailable', PDOException::class);

$db->exec('TRUNCATE TABLE nonce_reservations');
expectFailure('body-hash mismatch fails', fn() => ServiceProxyV2::verify($token, array_merge($expected, ['body_hash' => ServiceProxyV2::bodyHash('{"expected":"other"}')]), $keyring, $db, ['now' => 1700000000]), 'binding mismatch: body_hash');

$db->exec('TRUNCATE TABLE nonce_reservations');
$db->exec("INSERT INTO nonce_reservations (namespace, nonce, expires_at) VALUES ('ns-old', 'n1', DATE_SUB(NOW(), INTERVAL 10 SECOND)), ('ns-new', 'n2', DATE_ADD(NOW(), INTERVAL 10 SECOND))");
$removed = ServiceProxyV2::nonceSweepExpired($db);
$countLeft = (int) $db->query('SELECT COUNT(*) FROM nonce_reservations')->fetchColumn();
t('nonceSweepExpired removes expired rows', $removed === 1 && $countLeft === 1, 'removed=' . $removed . ' left=' . $countLeft);

echo "\n=== Results: {$pass} passed, {$fail} failed; {$logEvents} denials logged ===\n";
exit($fail > 0 ? 1 : 0);
