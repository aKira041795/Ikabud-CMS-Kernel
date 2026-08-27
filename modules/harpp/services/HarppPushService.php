<?php

declare(strict_types=1);

namespace Harpp\Services;

use Ikabud\Kernel\Contracts\ModuleDB;
use PDO;
use Throwable;

final class HarppPushService
{
    /** Process-local fallback only; never written to harpp_settings. */
    private static ?array $ephemeralVapid = null;

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

    public function subscribe(array $actor, array $input, ?int $tenantId = null)
    {
        if (!$this->scope($tenantId) || !$this->role($actor, ['owner', 'admin', 'member'])) {
            return HarppServiceResult::failure('Forbidden.', 403, 'forbidden');
        }
        $endpoint = trim((string)($input['endpoint'] ?? ''));
        $keys = is_array($input['keys'] ?? null) ? $input['keys'] : [];
        $p256dh = trim((string)($keys['p256dh'] ?? ''));
        $auth = trim((string)($keys['auth'] ?? ''));
        if (!$this->validEndpoint($endpoint) || !$this->validEncodedKey($p256dh, 33) || !$this->validEncodedKey($auth, 8)) {
            return HarppServiceResult::failure('A valid HTTPS Web Push subscription and keys are required.');
        }
        $expiresAt = null;
        if (($input['expirationTime'] ?? $input['expires_at'] ?? null) !== null) {
            $raw = $input['expirationTime'] ?? $input['expires_at'];
            $timestamp = is_numeric($raw) ? (int)((float)$raw / ((float)$raw > 9999999999 ? 1000 : 1)) : strtotime((string)$raw);
            if (!$timestamp || $timestamp <= time()) {
                return HarppServiceResult::failure('Subscription expiry must be in the future.');
            }
            $expiresAt = date('Y-m-d H:i:s', $timestamp);
        }
        try {
            $hash = hash('sha256', $endpoint);
            $encodedKeys = json_encode(['p256dh' => $p256dh, 'auth' => $auth], JSON_THROW_ON_ERROR);
            $this->db()->beginTransaction();
            $existing = $this->db()->prepare('SELECT user_id FROM harpp_push_subscriptions WHERE endpoint_hash = :hash FOR UPDATE');
            $existing->execute([':hash' => $hash]);
            $existingUser = $existing->fetchColumn();
            if ($existingUser !== false && (int)$existingUser !== (int)$actor['id']) {
                $this->db()->rollBack();
                return HarppServiceResult::failure('Subscription endpoint is already registered.', 409, 'subscription_conflict');
            }
            if ($existingUser === false) {
                $stmt = $this->db()->prepare('INSERT INTO harpp_push_subscriptions (user_id, endpoint, endpoint_hash, `keys`, expires_at, created_at, updated_at) VALUES (:user, :endpoint, :hash, :keys, :expiry, NOW(), NOW())');
            } else {
                $stmt = $this->db()->prepare('UPDATE harpp_push_subscriptions SET endpoint = :endpoint, `keys` = :keys, expires_at = :expiry, updated_at = NOW() WHERE user_id = :user AND endpoint_hash = :hash');
            }
            $stmt->execute([':user' => (int)$actor['id'], ':endpoint' => $endpoint, ':hash' => $hash, ':keys' => $encodedKeys, ':expiry' => $expiresAt]);
            $event = $this->effects('harpp.push.subscription.saved', 'push.subscription.saved', $actor, $hash, ['endpoint_hash'=>$hash]);
            $this->db()->commit();
            $this->audit('push.subscription.saved', $actor, ['endpoint_hash' => $hash]);
            return HarppServiceResult::success(['endpoint_hash' => $hash], '', [$event], 'harpp_push_subscription', $hash);
        } catch (Throwable $e) {
            if ($this->db()->inTransaction()) { $this->db()->rollBack(); }
            $this->log('subscription save failed', $e);
            return HarppServiceResult::failure('Unable to save push subscription.', 500);
        }
    }

    public function unsubscribe(array $actor, string $endpoint, ?int $tenantId = null)
    {
        if (!$this->scope($tenantId) || !$this->role($actor, ['owner', 'admin', 'member']) || !$this->validEndpoint($endpoint)) {
            return HarppServiceResult::failure('Forbidden or invalid subscription.', 403);
        }
        $hash=hash('sha256',$endpoint);
        try{$this->db()->beginTransaction();$stmt = $this->db()->prepare('DELETE FROM harpp_push_subscriptions WHERE user_id = :user AND endpoint_hash = :hash');
        $stmt->execute([':user' => (int)$actor['id'], ':hash' => $hash]);$removed=$stmt->rowCount();$event=$this->effects('harpp.push.subscription.deleted','push.subscription.deleted',$actor,$hash,['removed'=>$removed]);$this->db()->commit();
        $this->audit('push.subscription.deleted', $actor, ['endpoint_hash' => $hash]);
        return HarppServiceResult::success(['removed' => $removed],'',[$event],'harpp_push_subscription',$hash);}catch(Throwable$e){if($this->db()->inTransaction())$this->db()->rollBack();$this->log('subscription delete failed',$e);return HarppServiceResult::failure('Unable to remove push subscription.',500);}
    }

    public function publicKey(?int $tenantId = null)
    {
        if (!$this->scope($tenantId)) {
            return HarppServiceResult::failure('Tenant scope mismatch.', 403);
        }
        try {
            $keys = $this->vapidKeys();
            return HarppServiceResult::success(['public_key' => $keys['public']]);
        } catch (Throwable $e) {
            $this->log('VAPID key initialization failed', $e);
            return HarppServiceResult::failure('Unable to initialize VAPID.', 500);
        }
    }

    /** Build the network request separately so it is testable without delivery. */
    public function buildRequest(string $endpoint, ?int $now = null, array $subscriptionKeys = [], array $payload = [])
    {
        if (!$this->validEndpoint($endpoint)) {
            throw new \InvalidArgumentException('Invalid push endpoint.');
        }
        $keys = $this->vapidKeys();
        $now ??= time();
        $target = $this->resolveEndpoint($endpoint);
        $parts = parse_url($endpoint);
        $audience = (string)$parts['scheme'] . '://' . (string)$parts['host'] . (isset($parts['port']) ? ':' . (int)$parts['port'] : '');
        $header = $this->b64(json_encode(['typ' => 'JWT', 'alg' => 'ES256'], JSON_THROW_ON_ERROR));
        $claims = $this->b64(json_encode(['aud' => $audience, 'exp' => $now + 43200, 'sub' => $keys['subject']], JSON_THROW_ON_ERROR));
        $input = $header . '.' . $claims;
        if (!openssl_sign($input, $derSignature, $keys['private'], OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('Unable to sign the VAPID request.');
        }
        $jwt = $input . '.' . $this->b64($this->derToJose($derSignature));
        $body = '';
        $ttl = (int)($payload['ttl'] ?? 14400);
        $headers = ['TTL: ' . max(60, min(86400, $ttl))];
        if ($payload !== []) {
            $body = $this->encryptPayload($payload, $subscriptionKeys);
            $headers[] = 'Content-Encoding: aes128gcm';
            $headers[] = 'Content-Type: application/octet-stream';
            $headers[] = 'Urgency: ' . (($payload['urgency'] ?? '') === 'high' ? 'high' : 'normal');
            if (trim((string)($payload['tag'] ?? '')) !== '') $headers[] = 'Topic: ' . substr(hash('sha256', (string)$payload['tag']), 0, 32);
        }
        $headers[] = 'Content-Length: ' . strlen($body);
        $headers[] = 'Authorization: vapid t=' . $jwt . ', k=' . $keys['public'];
        $headers[] = 'Crypto-Key: p256ecdsa=' . $keys['public'];
        return [
            'url' => $endpoint,
            'method' => 'POST',
            'headers' => $headers,
            'body' => $body,
            'jwt' => $jwt,
            'resolved_ip' => $target['ip'],
            'host' => $target['host'],
            'port' => $target['port'],
        ];
    }

    public function dispatchToUser(int $userId, array $payload = [])
    {
        try {
            $enabled = $this->setting('push_enabled', '1') === '1';
            if (!$enabled || $userId <= 0) {
                return HarppServiceResult::success(['attempted' => 0, 'sent' => 0]);
            }
            $stmt = $this->db()->prepare('SELECT id, endpoint, `keys` FROM harpp_push_subscriptions WHERE user_id = :user AND (expires_at IS NULL OR expires_at > NOW()) ORDER BY id ASC');
            $stmt->execute([':user' => $userId]);
            $sent = 0;
            $attempted = 0;
            $expired = 0;
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $attempted++;
                try {
                    $subscriptionKeys = json_decode((string)$row['keys'], true, 8, JSON_THROW_ON_ERROR);
                    $request = $this->buildRequest((string)$row['endpoint'], null, $subscriptionKeys, $payload);
                    $status = $this->send($request);
                    if ($status >= 200 && $status < 300) {
                        $sent++;
                    } elseif (in_array($status, [404, 410], true)) {
                        $delete = $this->db()->prepare('DELETE FROM harpp_push_subscriptions WHERE id = :id AND user_id = :user');
                        $delete->execute([':id' => (int)$row['id'], ':user' => $userId]);
                        $expired += $delete->rowCount();
                    } else {
                        throw new \RuntimeException('Push endpoint returned HTTP ' . $status);
                    }
                } catch (Throwable $e) {
                    $this->log('push send failed', $e, ['subscription_id' => (int)$row['id']]);
                }
            }
            return HarppServiceResult::success(['attempted' => $attempted, 'sent' => $sent, 'expired_removed' => $expired]);
        } catch (Throwable $e) {
            $this->log('push dispatch failed', $e);
            return HarppServiceResult::failure('Push dispatch failed.', 500);
        }
    }

    private function send(array $request): int
    {
        if (!function_exists('curl_init')) {
            $this->log('push send failed', new \RuntimeException('cURL unavailable'));
            return 0;
        }
        $curl = curl_init($request['url']);
        $ip = str_contains((string)$request['resolved_ip'], ':') ? '[' . $request['resolved_ip'] . ']' : $request['resolved_ip'];
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => (string)$request['body'],
            CURLOPT_HTTPHEADER => $request['headers'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 3,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_RESOLVE => [$request['host'] . ':' . $request['port'] . ':' . $ip],
        ]);
        curl_exec($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        if ($status === 0 && $error !== '') throw new \RuntimeException('Push transport failed: ' . $error);
        return $status;
    }

    private function vapidKeys()
    {
        $public = trim((string)(getenv('HARPP_VAPID_PUBLIC_KEY') ?: ''));
        $privatePem = str_replace('\\n', "\n", trim((string)(getenv('HARPP_VAPID_PRIVATE_KEY') ?: '')));
        $subject = trim((string)(getenv('HARPP_VAPID_SUBJECT') ?: 'mailto:harpp@localhost'));
        if ($privatePem !== '') {
            $resource = openssl_pkey_get_private($privatePem);
            if ($resource === false) throw new \RuntimeException('HARPP_VAPID_PRIVATE_KEY is invalid.');
            $details = openssl_pkey_get_details($resource);
            $derived = $this->publicFromDetails($details);
            if ($public === '') $public = $derived;
            if (!hash_equals($derived, $public)) throw new \RuntimeException('Configured VAPID public/private keys do not match.');
            return ['public'=>$public,'private'=>$privatePem,'subject'=>$subject];
        }
        if ($public !== '') throw new \RuntimeException('HARPP_VAPID_PRIVATE_KEY is required when a public key is configured.');
        if (self::$ephemeralVapid !== null) return self::$ephemeralVapid;
        if (!function_exists('openssl_pkey_new')) throw new \RuntimeException('OpenSSL EC support is required for VAPID.');
        $resource=openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_EC,'curve_name'=>'prime256v1']);
        if($resource===false||!openssl_pkey_export($resource,$privatePem))throw new \RuntimeException('Unable to generate a VAPID key pair.');
        $details=openssl_pkey_get_details($resource);
        return self::$ephemeralVapid=['public'=>$this->publicFromDetails($details),'private'=>$privatePem,'subject'=>$subject];
    }

    /** Encrypt a notification as an RFC 8291 aes128gcm Web Push record. */
    private function encryptPayload(array $payload, array $subscriptionKeys): string
    {
        $clientPublic = $this->decodeB64(trim((string)($subscriptionKeys['p256dh'] ?? '')));
        $authSecret = $this->decodeB64(trim((string)($subscriptionKeys['auth'] ?? '')));
        if (strlen($clientPublic) !== 65 || $clientPublic[0] !== "\x04" || strlen($authSecret) < 16) {
            throw new \InvalidArgumentException('Invalid Web Push encryption keys.');
        }
        $serverPrivate = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        if ($serverPrivate === false) throw new \RuntimeException('Unable to create Web Push encryption key.');
        $serverDetails = openssl_pkey_get_details($serverPrivate);
        $serverPublic = $this->decodeB64($this->publicFromDetails($serverDetails));
        $clientKey = openssl_pkey_get_public($this->publicKeyPem($clientPublic));
        if ($clientKey === false || !function_exists('openssl_pkey_derive')) throw new \RuntimeException('OpenSSL ECDH support is required for Web Push.');
        $sharedSecret = openssl_pkey_derive($clientKey, $serverPrivate, 32);
        if (!is_string($sharedSecret) || strlen($sharedSecret) !== 32) throw new \RuntimeException('Unable to derive Web Push encryption secret.');

        $keyInfo = "WebPush: info\0" . $clientPublic . $serverPublic;
        $prkKey = hash_hmac('sha256', $sharedSecret, $authSecret, true);
        $ikm = $this->hkdfExpand($prkKey, $keyInfo, 32);
        $salt = random_bytes(16);
        $prk = hash_hmac('sha256', $ikm, $salt, true);
        $cek = $this->hkdfExpand($prk, "Content-Encoding: aes128gcm\0", 16);
        $nonce = $this->hkdfExpand($prk, "Content-Encoding: nonce\0", 12);
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (strlen($json) > 3500) throw new \InvalidArgumentException('Web Push payload is too large.');
        $ciphertext = openssl_encrypt($json . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);
        if (!is_string($ciphertext) || strlen($tag) !== 16) throw new \RuntimeException('Unable to encrypt Web Push payload.');
        return $salt . pack('N', 4096) . chr(strlen($serverPublic)) . $serverPublic . $ciphertext . $tag;
    }

    private function hkdfExpand(string $prk, string $info, int $length): string
    {
        return substr(hash_hmac('sha256', $info . "\x01", $prk, true), 0, $length);
    }

    private function publicKeyPem(string $point): string
    {
        $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $point;
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    private function publicFromDetails(array|false $details): string
    {
        $x=(string)($details['ec']['x']??'');$y=(string)($details['ec']['y']??'');
        if(strlen($x)!==32||strlen($y)!==32)throw new \RuntimeException('Invalid VAPID EC key.');
        return $this->b64("\x04".$x.$y);
    }

    private function setting(string $key, string $default): string
    {
        $stmt = $this->db()->prepare('SELECT setting_value FROM harpp_settings WHERE setting_key = :key LIMIT 1');
        $stmt->execute([':key' => $key]);
        $value = $stmt->fetchColumn();
        return $value === false ? $default : (string)$value;
    }

    private function validEndpoint(string $endpoint): bool
    {
        try { $this->resolveEndpoint($endpoint); return true; } catch (\InvalidArgumentException) { return false; }
    }

    /** Resolve once, reject every non-public answer, and return the IP to pin in cURL. */
    private function resolveEndpoint(string $endpoint): array
    {
        if ($endpoint === '' || strlen($endpoint) > 4096 || filter_var($endpoint, FILTER_VALIDATE_URL) === false) throw new \InvalidArgumentException('Malformed push endpoint.');
        $parts=parse_url($endpoint);
        if(!is_array($parts)||strtolower((string)($parts['scheme']??''))!=='https'||isset($parts['user'])||isset($parts['pass'])||isset($parts['fragment']))throw new \InvalidArgumentException('Push endpoint must be an HTTPS URL without credentials or fragments.');
        $host=strtolower(rtrim((string)($parts['host']??''),'.'));
        if($host===''||(!filter_var($host,FILTER_VALIDATE_IP)&&preg_match('/^[a-z0-9.-]+$/',$host)!==1))throw new \InvalidArgumentException('Push endpoint host is invalid.');
        $ips=[];
        if(filter_var($host,FILTER_VALIDATE_IP))$ips[]=$host;
        else{
            if(function_exists('dns_get_record')){foreach((array)@dns_get_record($host,DNS_A|DNS_AAAA) as$r){$ip=(string)($r['ip']??$r['ipv6']??'');if($ip!=='')$ips[]=$ip;}}
            foreach((array)@gethostbynamel($host) as$ip)$ips[]=$ip;
        }
        $ips=array_values(array_unique($ips));
        if($ips===[])throw new \InvalidArgumentException('Push endpoint host cannot be resolved.');
        foreach($ips as$ip){if(filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)===false)throw new \InvalidArgumentException('Push endpoint resolves to a non-public address.');}
        $port=(int)($parts['port']??443);if($port<1||$port>65535)throw new \InvalidArgumentException('Push endpoint port is invalid.');
        return ['host'=>$host,'ip'=>$ips[0],'port'=>$port];
    }

    private function validEncodedKey(string $value, int $minimumBytes): bool
    {
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $value)) { return false; }
        $decoded = base64_decode(strtr($value, '-_', '+/') . str_repeat('=', (4 - strlen($value) % 4) % 4), true);
        return $decoded !== false && strlen($decoded) >= $minimumBytes;
    }

    private function decodeB64(string $value): string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/') . str_repeat('=', (4 - strlen($value) % 4) % 4), true);
        return $decoded === false ? '' : $decoded;
    }
    private function derToJose(string $der): string
    {
        $offset = 2;
        if (ord($der[1]) > 0x80) $offset = 2 + (ord($der[1]) & 0x7f);
        if (($der[$offset] ?? '') !== "\x02") throw new \RuntimeException('Invalid ECDSA signature.');
        $rLength = ord($der[++$offset]); $r = substr($der, ++$offset, $rLength); $offset += $rLength;
        if (($der[$offset] ?? '') !== "\x02") throw new \RuntimeException('Invalid ECDSA signature.');
        $sLength = ord($der[++$offset]); $s = substr($der, ++$offset, $sLength);
        return str_pad(ltrim($r, "\0"), 32, "\0", STR_PAD_LEFT) . str_pad(ltrim($s, "\0"), 32, "\0", STR_PAD_LEFT);
    }
    private function effects(string$event,string$action,array$actor,string$id,array$after):array{return(new HarppFoundationService($this->db()))->recordEffect($event,$action,$actor,'harpp_push_subscription',substr($id,0,40),null,$after);}
    private function b64(string $value): string { return rtrim(strtr(base64_encode($value), '+/', '-_'), '='); }
    private function scope(?int $tenantId): bool { $current = (int)(\app()->tenant()->current() ?? 0); return $current > 0 && ($tenantId === null || $tenantId === $current); }
    private function role(array $actor, array $roles): bool { return (int)($actor['id'] ?? 0) > 0 && ($actor['source'] ?? 'harpp') === 'harpp' && in_array((string)($actor['role'] ?? ''), $roles, true); }
    private function audit(string $action, array $actor, array $context): void { if (function_exists('write_log')) { \write_log('HARPP audit', 'info', ['module' => 'harpp', 'action' => $action, 'actor_user_id' => (int)($actor['id'] ?? 0)] + $context); } }
    private function log(string $message, Throwable $e, array $context = []): void { if (function_exists('write_log')) { \write_log('HARPP ' . $message, 'error', ['module' => 'harpp', 'error' => $e->getMessage()] + $context); } }
}
