<?php
/**
 * SMS Gateway — Multi-provider SMS sending abstraction
 * 
 * Supports: Semaphore (PH), Twilio, Vonage (Nexmo), MoceanAPI
 * Adapted for Ikabud kernel v2.
 * 
 * @package Ikabud\Modules\SMS
 */

declare(strict_types=1);

/**
 * Get SMS module settings from registry (modules.json)
 */
function smsSettingsFieldDefinitions(): array
{
    $manifest = kernelReadJsonFile(__DIR__ . '/../module.json');
    $fields = $manifest['settings_fields'] ?? ($manifest['settings'] ?? []);
    return is_array($fields) ? array_values(array_filter($fields, 'is_array')) : [];
}

function smsSettingsDefaults(): array
{
    static $defaults = null;
    if ($defaults !== null) {
        return $defaults;
    }

    $defaults = [];
    foreach (smsSettingsFieldDefinitions() as $field) {
        $key = trim((string)($field['key'] ?? ''));
        if ($key === '' || !array_key_exists('default', $field)) {
            continue;
        }
        $defaults[$key] = (string)$field['default'];
    }

    return $defaults;
}

function smsGetSettings(): array
{
    // Cache keyed by tenant ID so different tenants in the same process
    // don't share each other's SMS configuration (API keys, provider, etc.).
    static $cache = [];
    $tid = app()->tenant()->current();
    if (array_key_exists($tid, $cache)) return $cache[$tid];

    $cache[$tid] = array_merge(smsSettingsDefaults(), getModuleSettings('sms'));

    return $cache[$tid];
}

/**
 * Check if SMS is properly configured
 */
function smsIsConfigured(): bool
{
    $s = smsGetSettings();
    
    switch ($s['sms_provider']) {
        case 'semaphore':
            return !empty($s['sms_api_key']);
        case 'twilio':
            return !empty($s['sms_api_key']) && !empty($s['sms_api_secret']) && !empty($s['sms_sender_name']);
        case 'vonage':
            return !empty($s['sms_api_key']) && !empty($s['sms_api_secret']);
        case 'mocean':
            return !empty($s['sms_api_key']);
        default:
            return false;
    }
}

/**
 * Normalize a phone number to E.164 format
 */
function smsNormalizeNumber(string $number, string $countryCode = '+63'): string
{
    // Strip whitespace, dashes, parentheses
    $number = preg_replace('/[\s\-\(\)]/', '', $number);
    
    // Already in E.164 format
    if (strpos($number, '+') === 0) {
        return $number;
    }
    
    // Remove leading 0
    $number = ltrim($number, '0');
    
    // Prepend country code
    $countryCode = ltrim($countryCode, '+');
    return '+' . $countryCode . $number;
}

function smsMaskNumberForLog(string $number): string
{
    $digitsOnly = preg_replace('/\D+/', '', $number);
    $tail = substr((string)$digitsOnly, -4);
    return '+***' . ($tail !== false ? $tail : '');
}

/**
 * Send an SMS message
 * 
 * @param string $to Recipient phone number
 * @param string $message Message text
 * @param array $meta Optional metadata (trigger_event, trigger_ref_id, recipient_name, sent_by)
 * @return array ['ok' => bool, 'message_id' => string|null, 'error' => string|null, 'log_id' => int]
 */
function smsSend(string $to, string $message, array $meta = []): array
{
    $settings = smsGetSettings();
    $provider = $settings['sms_provider'];
    $countryCode = $settings['sms_country_code'] ?: '+63';
    $to = smsNormalizeNumber($to, $countryCode);

    $ctx = function_exists('module') ? module('sms') : null;
    if (!$ctx) {
        return ['ok' => false, 'error' => 'Module context unavailable'];
    }
    $db = $ctx->db();
    
    // Insert log entry (pending)
    $stmt = $db->prepare("
        INSERT INTO sms_log (recipient, recipient_name, message, provider, status, trigger_event, trigger_ref_id, sent_by, created_at)
        VALUES (?, ?, ?, ?, 'pending', ?, ?, ?, NOW())
    ");
    $stmt->execute([
        $to,
        $meta['recipient_name'] ?? null,
        $message,
        $provider,
        $meta['trigger_event'] ?? 'manual',
        $meta['trigger_ref_id'] ?? null,
        $meta['sent_by'] ?? null,
    ]);
    $logId = (int) $db->lastInsertId();
    
    // Test mode — simulate send
    if (!empty($settings['sms_test_mode'])) {
        $db->prepare("UPDATE sms_log SET status = 'simulated', provider_response = 'Test mode — not sent', sent_at = NOW() WHERE id = ?")
            ->execute([$logId]);

        $maskedTo = smsMaskNumberForLog($to);
        $messageLen = strlen($message);
        $traceRef = (string)($meta['trigger_ref_id'] ?? (string)$logId);
        $safeLog = "[SMS:test] To: {$maskedTo} | Message: [redacted] | chars={$messageLen} | ref={$traceRef}";

        $ctx->log($safeLog, 'info');
        return ['ok' => true, 'message_id' => 'test_' . $logId, 'log_id' => $logId, 'simulated' => true];
    }
    
    // Check configuration
    if (!smsIsConfigured()) {
        $db->prepare("UPDATE sms_log SET status = 'failed', error_message = 'SMS provider not configured' WHERE id = ?")
            ->execute([$logId]);
        return ['ok' => false, 'error' => 'SMS provider not configured', 'log_id' => $logId];
    }
    
    // Dispatch to provider
    switch ($provider) {
        case 'semaphore':
            $result = smsSendViaSemaphore($to, $message, $settings);
            break;
        case 'twilio':
            $result = smsSendViaTwilio($to, $message, $settings);
            break;
        case 'vonage':
            $result = smsSendViaVonage($to, $message, $settings);
            break;
        case 'mocean':
            $result = smsSendViaMocean($to, $message, $settings);
            break;
        default:
            $result = ['ok' => false, 'error' => 'Unknown provider: ' . $provider];
    }
    
    // Update log
    if ($result['ok']) {
        $db->prepare("UPDATE sms_log SET status = 'sent', provider_message_id = ?, provider_response = ?, sent_at = NOW() WHERE id = ?")
            ->execute([$result['message_id'] ?? null, $result['raw_response'] ?? null, $logId]);
    } else {
        $db->prepare("UPDATE sms_log SET status = 'failed', error_message = ?, provider_response = ? WHERE id = ?")
            ->execute([$result['error'] ?? 'Unknown error', $result['raw_response'] ?? null, $logId]);
    }
    
    $result['log_id'] = $logId;
    return $result;
}

/**
 * Send via Semaphore (Philippines)
 */
function smsSendViaSemaphore(string $to, string $message, array $settings): array
{
    $apiKey = $settings['sms_api_key'];
    $sender = $settings['sms_sender_name'] ?: 'SEMAPHORE';
    
    // Semaphore expects local format (09xx) — strip +63
    $localNumber = $to;
    if (strpos($to, '+63') === 0) {
        $localNumber = '0' . substr($to, 3);
    }
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => 'https://api.semaphore.co/api/v4/messages',
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'apikey' => $apiKey,
            'number' => $localNumber,
            'message' => $message,
            'sendername' => $sender,
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        return ['ok' => false, 'error' => 'cURL error: ' . $error, 'raw_response' => $response];
    }
    
    $body = json_decode($response, true);
    
    if ($httpCode === 200 && isset($body[0]['message_id'])) {
        return ['ok' => true, 'message_id' => (string) $body[0]['message_id'], 'raw_response' => $response];
    }
    
    $errorMsg = $body['message'] ?? $body[0]['status'] ?? 'HTTP ' . $httpCode;
    return ['ok' => false, 'error' => $errorMsg, 'raw_response' => $response];
}

/**
 * Send via Twilio
 */
function smsSendViaTwilio(string $to, string $message, array $settings): array
{
    $sid = $settings['sms_api_key'];
    $token = $settings['sms_api_secret'];
    $from = $settings['sms_sender_name'];
    
    $url = "https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json";
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_POST => true,
        CURLOPT_USERPWD => "{$sid}:{$token}",
        CURLOPT_POSTFIELDS => http_build_query([
            'To' => $to,
            'From' => $from,
            'Body' => $message,
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        return ['ok' => false, 'error' => 'cURL error: ' . $error, 'raw_response' => $response];
    }
    
    $body = json_decode($response, true);
    
    if ($httpCode >= 200 && $httpCode < 300 && isset($body['sid'])) {
        return ['ok' => true, 'message_id' => $body['sid'], 'raw_response' => $response];
    }
    
    $errorMsg = $body['message'] ?? 'HTTP ' . $httpCode;
    return ['ok' => false, 'error' => $errorMsg, 'raw_response' => $response];
}

/**
 * Send via Vonage (Nexmo)
 */
function smsSendViaVonage(string $to, string $message, array $settings): array
{
    $apiKey = $settings['sms_api_key'];
    $apiSecret = $settings['sms_api_secret'];
    $from = $settings['sms_sender_name'] ?: 'VONAGE';
    
    $vonageTo = ltrim($to, '+');
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => 'https://rest.nexmo.com/sms/json',
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'api_key' => $apiKey,
            'api_secret' => $apiSecret,
            'to' => $vonageTo,
            'from' => $from,
            'text' => $message,
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        return ['ok' => false, 'error' => 'cURL error: ' . $error, 'raw_response' => $response];
    }
    
    $body = json_decode($response, true);
    
    if (isset($body['messages'][0]['status']) && $body['messages'][0]['status'] === '0') {
        $messageId = $body['messages'][0]['message-id'] ?? '';
        return ['ok' => true, 'message_id' => $messageId, 'raw_response' => $response];
    }
    
    $errorMsg = $body['messages'][0]['error-text'] ?? 'HTTP ' . $httpCode;
    return ['ok' => false, 'error' => $errorMsg, 'raw_response' => $response];
}

/**
 * Send via MoceanAPI
 */
function smsSendViaMocean(string $to, string $message, array $settings): array
{
    $apiKey = trim($settings['sms_api_key']);
    $apiSecret = trim($settings['sms_api_secret'] ?? '');
    $sender = $settings['sms_sender_name'] ?: 'MOCEAN';
    
    $moceanTo = ltrim($to, '+');
    
    $headers = ['Accept: application/json'];
    
    if (!empty($apiSecret)) {
        $postFields = [
            'mocean-api-key' => $apiKey,
            'mocean-api-secret' => $apiSecret,
            'mocean-from' => $sender,
            'mocean-to' => $moceanTo,
            'mocean-text' => $message,
            'mocean-resp-format' => 'json',
        ];
    } else {
        $headers[] = 'Authorization: Bearer ' . $apiKey;
        $postFields = [
            'mocean-from' => $sender,
            'mocean-to' => $moceanTo,
            'mocean-text' => $message,
            'mocean-resp-format' => 'json',
        ];
    }
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => 'https://rest.moceanapi.com/rest/2/sms',
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($postFields),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    app()->log("[SMS:mocean] HTTP {$httpCode} | Response: " . ($response ?: '(empty)'), 'info');
    
    if ($error) {
        return ['ok' => false, 'error' => 'cURL error: ' . $error, 'raw_response' => $response];
    }
    
    $body = json_decode($response, true);
    
    if ($httpCode >= 200 && $httpCode < 300 && isset($body['messages'][0]['msgid'])) {
        return ['ok' => true, 'message_id' => $body['messages'][0]['msgid'], 'raw_response' => $response];
    }
    
    if ($httpCode >= 200 && $httpCode < 300 && isset($body['msgid'])) {
        return ['ok' => true, 'message_id' => $body['msgid'], 'raw_response' => $response];
    }
    
    $errorMsg = $body['err_msg'] ?? $body['messages'][0]['err_msg'] ?? 'HTTP ' . $httpCode;
    return ['ok' => false, 'error' => $errorMsg, 'raw_response' => $response];
}

/**
 * Get SMS balance/credits for current provider
 */
function smsGetBalance(): array
{
    $settings = smsGetSettings();
    
    switch ($settings['sms_provider']) {
        case 'semaphore':
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => 'https://api.semaphore.co/api/v4/account?' . http_build_query(['apikey' => $settings['sms_api_key']]),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 15,
            ]);
            $response = curl_exec($ch);
            $error = curl_error($ch);
            curl_close($ch);
            
            if ($error) return ['ok' => false, 'error' => $error];
            
            $body = json_decode($response, true);
            if (isset($body['credit_balance'])) {
                return ['ok' => true, 'balance' => $body['credit_balance'], 'currency' => 'credits'];
            }
            return ['ok' => false, 'error' => 'Could not retrieve balance'];
            
        default:
            return ['ok' => false, 'error' => 'Balance check not supported for ' . $settings['sms_provider']];
    }
}

/**
 * Render an SMS template by replacing placeholders
 */
function smsRenderTemplate(string $template, array $data): string
{
    foreach ($data as $key => $value) {
        $template = str_replace('{' . $key . '}', (string) $value, $template);
    }
    // Remove any unreplaced placeholders
    $template = preg_replace('/\{[a-z_]+\}/', '', $template);
    return trim($template);
}

/**
 * Get an SMS template by event key
 */
function smsGetTemplate(string $eventKey): ?array
{
    $ctx = function_exists('module') ? module('sms') : null;
    if (!$ctx) {
        return null;
    }
    $db = $ctx->db();
    $stmt = $db->prepare("SELECT * FROM sms_templates WHERE event_key = ? AND is_enabled = 1");
    $stmt->execute([$eventKey]);
    return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
}
