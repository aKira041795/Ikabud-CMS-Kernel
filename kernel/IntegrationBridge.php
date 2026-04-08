<?php
namespace Ikabud\Kernel;

class IntegrationBridge
{
    public static function handle(array $payload, string $event): void
    {
        $app = app();
        $db = $app->db();
        
        $stmt = $db->prepare('SELECT * FROM kernel_integrations WHERE trigger_event = ? AND is_active = 1');
        $stmt->execute([$event]);
        $integrations = $stmt->fetchAll();
        
        foreach ($integrations as $intg) {
            $mapping = json_decode($intg['mapping_json'], true) ?: [];
            
            $outPayload = self::applyMapping($payload, $mapping);
            
            if (isset($payload['idempotency_key'])) {
                $outPayload['idempotency_key'] = $payload['idempotency_key'];
            }
            
            $logStatus = 'success';
            $logError = null;
            $capResult = null;

            // Verify capability exists before attempting — fail-fast with clear message.
            if (!$app->capabilities()->has($app->capabilities()->resolve($intg['target_capability']))) {
                $logStatus = 'failed';
                $logError = 'Capability not registered: ' . $intg['target_capability'];
            } else {
                try {
                    $capResult = $app->cap()->call($intg['target_capability'], $outPayload);

                    // Chain: fire a result event into kernelEmitEvent so downstream EventTriggers
                    // can react to the capability outcome without polling or hardcoded coupling.
                    if (function_exists('kernelEmitEvent')) {
                        $resultEvent = 'integration.result.' . str_replace(['@', '.'], ['_v', '.'], $intg['target_capability']);
                        $chainPayload = [
                            'integration_id'     => $intg['id'],
                            'integration_name'   => $intg['name'],
                            'trigger_event'      => $event,
                            'target_capability'  => $intg['target_capability'],
                            'mapped_payload'     => $outPayload,
                            'result'             => is_array($capResult) ? $capResult : [],
                        ];
                        kernelEmitEvent($resultEvent, $chainPayload, 'kernel');
                    }
                } catch (\Throwable $e) {
                    $logStatus = 'failed';
                    $logError = $e->getMessage();
                }
            }

            $logStmt = $db->prepare('INSERT INTO kernel_integration_logs (integration_id, status, payload_in, payload_out, error_message) VALUES (?, ?, ?, ?, ?)');
            $logStmt->execute([
                $intg['id'],
                $logStatus,
                json_encode($payload),
                json_encode($outPayload),
                $logError
            ]);
        }
    }
    
    private static function applyMapping(array $in, array $mapping): array
    {
        $out = [];
        foreach ($mapping as $key => $value) {
            if (is_string($value) && preg_match_all('/\{\{([^}]+)\}\}/', $value, $matches)) {
                $valReplaced = $value;
                foreach ($matches[1] as $i => $path) {
                    $resolved = self::resolveDot($in, trim($path));
                    
                    if ($value === $matches[0][$i]) {
                        $valReplaced = $resolved;
                        break;
                    }
                    $valReplaced = str_replace($matches[0][$i], is_scalar($resolved) ? (string)$resolved : json_encode($resolved), $valReplaced);
                }
                $out[$key] = $valReplaced;
            } else {
                $out[$key] = $value;
            }
        }
        return $out;
    }
    
    private static function resolveDot(array $data, string $path)
    {
        $parts = explode('.', $path);
        foreach ($parts as $part) {
            if (is_array($data) && array_key_exists($part, $data)) {
                $data = $data[$part];
            } else {
                return null;
            }
        }
        return $data;
    }
}
