<?php

declare(strict_types=1);

$root = dirname(__DIR__, 3);
require $root . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/services/HarppPushService.php';

use Harpp\Services\HarppPushService;

$b64 = static fn(string $value): string => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
$point = static function (array $details): string {
    return "\x04" . (string)$details['ec']['x'] . (string)$details['ec']['y'];
};
$pem = static function (string $publicPoint): string {
    $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $publicPoint;
    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
};
$expand = static fn(string $prk, string $info, int $length): string => substr(hash_hmac('sha256', $info . "\x01", $prk, true), 0, $length);

$vapid = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
if ($vapid === false || !openssl_pkey_export($vapid, $vapidPem)) throw new RuntimeException('Unable to generate test VAPID key.');
putenv('HARPP_VAPID_PRIVATE_KEY=' . $vapidPem);
putenv('HARPP_VAPID_PUBLIC_KEY=' . $b64($point(openssl_pkey_get_details($vapid))));
putenv('HARPP_VAPID_SUBJECT=mailto:test@example.com');

$client = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
if ($client === false) throw new RuntimeException('Unable to generate test client key.');
$clientPublic = $point(openssl_pkey_get_details($client));
$auth = random_bytes(16);
$payload = ['subject' => 'Build finished', 'body' => 'Worker completed the HARPP task.', 'url' => '/harpp?conversation=42'];
$request = (new HarppPushService())->buildRequest('https://1.1.1.1/push', 1700000000, ['p256dh' => $b64($clientPublic), 'auth' => $b64($auth)], $payload);

$body = (string)$request['body'];
$salt = substr($body, 0, 16);
$recordSize = unpack('N', substr($body, 16, 4))[1];
$keyLength = ord($body[20] ?? "\0");
$serverPublic = substr($body, 21, $keyLength);
$encrypted = substr($body, 21 + $keyLength);
$ciphertext = substr($encrypted, 0, -16);
$tag = substr($encrypted, -16);
$serverKey = openssl_pkey_get_public($pem($serverPublic));
$shared = $serverKey === false ? false : openssl_pkey_derive($serverKey, $client, 32);
if (!is_string($shared)) throw new RuntimeException('Unable to derive test shared secret.');
$prkKey = hash_hmac('sha256', $shared, $auth, true);
$ikm = $expand($prkKey, "WebPush: info\0" . $clientPublic . $serverPublic, 32);
$prk = hash_hmac('sha256', $ikm, $salt, true);
$plain = openssl_decrypt($ciphertext, 'aes-128-gcm', $expand($prk, "Content-Encoding: aes128gcm\0", 16), OPENSSL_RAW_DATA, $expand($prk, "Content-Encoding: nonce\0", 12), $tag);
$decoded = is_string($plain) ? json_decode(substr($plain, 0, -1), true) : null;

$ok = $recordSize === 4096
    && $keyLength === 65
    && $decoded === $payload
    && str_ends_with((string)$plain, "\x02")
    && in_array('Content-Encoding: aes128gcm', $request['headers'], true)
    && !str_contains($body, $payload['body']);
fwrite(STDOUT, $ok ? "PASS encrypted Web Push payload round-trip\n" : "FAIL encrypted Web Push payload round-trip\n");
exit($ok ? 0 : 1);
