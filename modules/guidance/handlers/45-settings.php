<?php
/**
 * Settings Route Handlers
 * 
 * @package Guidance\Routes
 */

function apiGuidanceGetSettings(): void {
    guidanceUser();
    $db = guidanceDb();
    
    try {
        $stmt = $db->query("SELECT setting_key, setting_value, setting_type FROM gm_settings");
        $rows = $stmt->fetchAll();
        
        $settings = [];
        foreach ($rows as $row) {
            $value = $row['setting_value'];
            switch ($row['setting_type'] ?? 'string') {
                case 'json': $value = json_decode($value, true); break;
                case 'boolean': $value = (bool) $value; break;
                case 'integer': $value = (int) $value; break;
            }
            $settings[$row['setting_key']] = $value;
        }
        
        $defaults = [
            'retention_active_years' => 7, 'retention_closed_years' => 5,
            'reminder_hours_before' => 24, 'working_hours_start' => '08:00',
            'working_hours_end' => '17:00', 'appointment_slot_minutes' => 30,
            'sync_conflict_resolution' => 'server_wins',
        ];
        foreach ($defaults as $key => $default) {
            if (!isset($settings[$key])) $settings[$key] = $default;
        }
        
        app()->json(['success' => true, 'data' => $settings]);
    } catch (Exception $e) {
        app()->log('Settings get error: ' . $e->getMessage(), 'error');
        app()->json(['error' => 'Failed to fetch settings'], 500);
    }
}

function apiGuidanceUpdateSettings(): void {
    app()->requireRole('admin');
    $input = app()->input();
    $db = guidanceDb();
    $user = app()->user();
    
    if (empty($input)) {
        if (app()->isHtmx()) {
            header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Settings saved', 'type' => 'success']]));
            echo '';
            return;
        }
        app()->json(['error' => 'No settings provided'], 400);
    }
    
    try {
        $updateStmt = $db->prepare("
            INSERT INTO gm_settings (setting_key, setting_value, setting_type, updated_by, updated_at)
            VALUES (?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by), updated_at = NOW()
        ");
        
        foreach ($input as $key => $value) {
            $type = 'string';
            $storeValue = $value;
            if (is_array($value)) { $type = 'json'; $storeValue = json_encode($value); }
            elseif (is_bool($value)) { $type = 'boolean'; $storeValue = $value ? '1' : '0'; }
            elseif (is_int($value)) { $type = 'integer'; $storeValue = (string) $value; }
            $updateStmt->execute([$key, $storeValue, $type, $user['sub']]);
        }
        
        if (app()->isHtmx()) {
            header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Settings saved successfully', 'type' => 'success']]));
            echo '';
            return;
        }
        app()->json(['success' => true, 'message' => 'Settings updated']);
    } catch (Exception $e) {
        app()->log('Settings update error: ' . $e->getMessage(), 'error');
        app()->json(['error' => 'Failed to update settings'], 500);
    }
}
