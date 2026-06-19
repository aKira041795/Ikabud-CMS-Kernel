<?php

declare(strict_types=1);

/**
 * Kiosk-style attendance API — no login required.
 *
 * Flow:
 *   1. POST /api/v1/kiosk/search       — search employee by name
 *   2. POST /api/v1/kiosk/clock         — clock in/out with photo + geo-fence
 *   3. POST /api/v1/kiosk/upload-photo  — upload photo via multipart
 */

function kioskApiSearch(array $params = []): void
{
    $q = trim((string)($_GET['q'] ?? $_POST['q'] ?? ''));
    if (strlen($q) < 2) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'Search query must be at least 2 characters']);
        return;
    }

    try {
        $db = aw_db();
        $tid = app()->tenant()->current() ?? '';
        $like = '%' . $q . '%';
        $stmt = $db->prepare(
            "SELECT profile_id, first_name, middle_name, last_name, suffix,
                    employee_number, position, department, onsite_attendance,
                    CONCAT_WS(' ', first_name, middle_name, last_name, suffix) AS full_name
             FROM employee_profiles
             WHERE tenant_id = :tid AND is_active = 1
               AND (first_name LIKE :q1 OR last_name LIKE :q2 OR employee_number LIKE :q3)
             ORDER BY last_name ASC, first_name ASC
             LIMIT 15"
        );
        $stmt->execute([':tid' => $tid, ':q1' => $like, ':q2' => $like, ':q3' => $like]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true, 'data' => $rows]);
    } catch (\Throwable $e) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
}

function kioskApiClock(array $params = []): void
{
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    $input = str_contains($contentType, 'application/json')
        ? (json_decode(file_get_contents('php://input'), true) ?: [])
        : $_POST;

    $profileId = (int)($input['profile_id'] ?? 0);
    $latitude  = (float)($input['latitude'] ?? 0);
    $longitude = (float)($input['longitude'] ?? 0);
    $photoData = (string)($input['photo_data'] ?? ''); // base64-encoded image
    $onsitePlace = trim((string)($input['onsite_place'] ?? ''));

    if ($profileId <= 0) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'Employee profile required']);
        return;
    }

    try {
        $db = aw_db();
        $tid = app()->tenant()->current() ?? '';

        // Look up employee profile
        $stmt = $db->prepare(
            "SELECT ep.*, u.id AS user_id, u.is_active AS user_active
             FROM employee_profiles ep
             LEFT JOIN attendance_wage_users u ON u.id = ep.user_id
             WHERE ep.profile_id = :pid AND ep.tenant_id = :tid AND ep.is_active = 1
             LIMIT 1"
        );
        $stmt->execute([':pid' => $profileId, ':tid' => $tid]);
        $emp = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$emp) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'Employee not found or inactive']);
            return;
        }

        // Resolve or auto-create attendance_wage_users record for this employee
        $userId = (int)($emp['user_id'] ?? 0);
        if ($userId <= 0) {
            $userId = kioskResolveUserId($db, $profileId, $emp);
        }
        if ($userId <= 0) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'Unable to create user account for this employee.']);
            return;
        }

        // ── Location verification (checked first for clarity) ──
        //   1. If GPS provided → check against office geo-fences
        //   2. If within a saved office → record with office name
        //   3. If outside saved offices → check onsite_attendance toggle
        //   4. If onsite toggle is YES → allow as on-site attendance
        //   5. If onsite toggle is NO → block (must be within geo-fence)
        //   6. If no office locations configured → allow everyone
        $locationName = null;
        $locationId   = null;
        $isOnsite     = false;
        $onsiteToggle = (bool)($emp['onsite_attendance'] ?? false);

        // Check if any office locations are configured at all
        $locCount = (int)$db->query("SELECT COUNT(*) FROM office_locations WHERE tenant_id = '{$tid}' AND is_active = 1")->fetchColumn();

        if ($locCount === 0) {
            // No offices configured — auto-pass everyone as on-site
            $isOnsite = true;
        } elseif ($latitude !== 0.0 || $longitude !== 0.0) {
            // GPS provided — check against office geo-fences
            $matched = aw_findLocationByGeo($latitude, $longitude);
            if ($matched) {
                // Within a saved office location ✅
                $locationName = $matched['name'] ?? null;
                $locationId   = (int)($matched['location_id'] ?? 0);
            } elseif ($onsiteToggle) {
                // Outside saved offices, but employee has on-site toggle ✅
                $isOnsite = true;
            } else {
                // Outside saved offices, no on-site toggle ❌
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => false, 'error' => 'You are outside all office locations. If you work remotely or on-site, contact your administrator to enable the On-Site Attendance setting for your profile.']);
                return;
            }
        } else {
            // No GPS — check onsite toggle as fallback
            if ($onsiteToggle) {
                $isOnsite = true;
            } else {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => false, 'error' => 'Location is required. Please enable GPS or ask your administrator to enable On-Site Attendance for your profile.']);
                return;
            }
        }

        // Smart clock-in/out detection: check if already clocked in today
        $existing = $db->prepare(
            "SELECT attendance_id, clock_in FROM attendance_records
             WHERE user_id = :uid AND DATE(clock_in) = CURDATE() AND clock_out IS NULL
             ORDER BY clock_in DESC LIMIT 1"
        );
        $existing->execute([':uid' => $userId]);
        $activeRecord = $existing->fetch(\PDO::FETCH_ASSOC);

        $isClockIn = !$activeRecord;
        $photoFilename = null;

        // Save photo if provided
        if ($photoData !== '') {
            $photoFilename = saveKioskPhoto($photoData, $userId);
        }

        if ($isClockIn) {
            // Clock IN
            if ($isOnsite) {
                $locStr = 'On-site' . ($onsitePlace !== '' ? ': ' . $onsitePlace : '') . ' — ' . ($emp['position'] ?? 'Employee');
            } else {
                $locStr = $locationName ? ($locationName . ' (' . $latitude . ',' . $longitude . ')') : null;
            }

            $ins = $db->prepare(
                "INSERT INTO attendance_records (tenant_id, user_id, clock_in, status, location_in, photo_in)
                 VALUES (:tid, :uid, NOW(), 'active', :loc, :photo)"
            );
            $ins->execute([
                ':tid'   => $tid,
                ':uid'   => $userId,
                ':loc'   => $locStr,
                ':photo' => $photoFilename,
            ]);

            $newId = (int)$db->lastInsertId();
            $empName = trim(
                ($emp['first_name'] ?? '') . ' ' . ($emp['last_name'] ?? '')
            );

            // Auto-recompute salary for current active payroll period
            kioskAutoRecompute($db, $userId);

            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok'          => true,
                'action'      => 'clock_in',
                'message'     => 'Clocked in' . ($isOnsite ? ' (on-site)' : '') . ' — ' . $empName,
                'id'          => $newId,
                'location'    => $isOnsite ? 'On-site Attendance' : $locationName,
                'employee'    => $empName,
                'time'        => date('H:i:s'),
            ]);
        } else {
            // Clock OUT
            $attId = (int)($activeRecord['attendance_id'] ?? 0);
            $upd = $db->prepare(
                "UPDATE attendance_records
                 SET clock_out = NOW(), status = 'completed',
                     location_out = :loc, photo_out = :photo
                 WHERE attendance_id = :id"
            );
            $upd->execute([
                ':loc'   => $isOnsite ? ('On-site' . ($onsitePlace !== '' ? ': ' . $onsitePlace : '')) : ($locationName ?? null),
                ':photo' => $photoFilename,
                ':id'    => $attId,
            ]);

            $clockInTime = $activeRecord['clock_in'] ?? '';
            $empName = trim(
                ($emp['first_name'] ?? '') . ' ' . ($emp['last_name'] ?? '')
            );

            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok'          => true,
                'action'      => 'clock_out',
                'message'     => 'Clocked out — ' . $empName,
                'id'          => $attId,
                'location'    => $isOnsite ? 'On-site Attendance' : $locationName,
                'employee'    => $empName,
                'time'        => date('H:i:s'),
                'clocked_in_at' => $clockInTime,
            ]);
        }
    } catch (\Throwable $e) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
}

/**
 * Auto-create an attendance_wage_users record for an employee profile
 * that doesn't have one yet, and link it back to the profile.
 */
function kioskResolveUserId(\PDO $db, int $profileId, array $emp): int
{
    $username = 'aw-' . ($emp['employee_number'] ?: 'emp' . $profileId);
    $fullName = trim(
        ($emp['first_name'] ?? '') . ' ' .
        ($emp['middle_name'] ?? '') . ' ' .
        ($emp['last_name'] ?? '') . ' ' .
        ($emp['suffix'] ?? '')
    );
    $fullName = trim($fullName) !== '' ? trim($fullName) : ('Employee #' . $profileId);

    $stmt = $db->prepare(
        "INSERT INTO attendance_wage_users (username, email, password_hash, full_name, role, is_active)
         VALUES (:u, :e, :ph, :fn, 'employee', 1)
         ON DUPLICATE KEY UPDATE is_active = 1"
    );
    $stmt->execute([
        ':u'  => $username,
        ':e'  => $username . '@zap.local',
        ':ph' => password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT),
        ':fn' => $fullName,
    ]);
    $userId = (int)$db->lastInsertId();
    if ($userId <= 0) {
        $lu = $db->prepare("SELECT id FROM attendance_wage_users WHERE username = :u LIMIT 1");
        $lu->execute([':u' => $username]);
        $userId = (int)$lu->fetchColumn();
    }
    // Link the user_id back to the employee profile
    if ($userId > 0) {
        $db->prepare("UPDATE employee_profiles SET user_id = :uid WHERE profile_id = :pid")
           ->execute([':uid' => $userId, ':pid' => $profileId]);
    }
    return $userId;
}

/**
 * Save a base64-encoded JPEG/PNG photo to the attendance uploads directory.
 */
function saveKioskPhoto(string $base64Data, int $userId): ?string
{
    if ($base64Data === '') return null;

    // Strip data URI prefix if present
    $data = $base64Data;
    if (str_starts_with($data, 'data:image/')) {
        $commaPos = strpos($data, ',');
        if ($commaPos !== false) {
            $data = substr($data, $commaPos + 1);
        }
    }

    $decoded = base64_decode($data, true);
    if ($decoded === false || strlen($decoded) === 0) return null;

    // Limit photo size to 10 MB to prevent disk exhaustion
    if (strlen($decoded) > 10 * 1024 * 1024) return null;

    // Validate it's actually an image
    $finfo = new \finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->buffer($decoded);
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) return null;

    $ext = match ($mime) {
        'image/png'  => 'png',
        'image/webp' => 'webp',
        default      => 'jpg',
    };

    $dir = '/var/www/html/applicationostest/storage/uploads/attendance';
    if (!is_dir($dir)) { mkdir($dir, 0755, true); }

    $filename = date('Ymd_His') . '_' . $userId . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $path = $dir . '/' . $filename;

    if (file_put_contents($path, $decoded) === false) return null;

    return $filename;
}

/**
 * Reverse-geocode coordinates to a human-readable place name.
 * Uses Google Maps Geocoding API if key is configured, otherwise returns null
 * (client-side falls back to OSM Nominatim).
 */
function kioskApiReverseGeocode(array $params = []): void
{
    $lat = (float)($_GET['lat'] ?? $params['lat'] ?? 0);
    $lng = (float)($_GET['lng'] ?? $params['lng'] ?? 0);

    if ($lat === 0.0 && $lng === 0.0) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'Coordinates required']);
        return;
    }

    try {
        $settings = getModuleSettings('attendance-wage');
        $apiKey = trim((string)($settings['google_maps_api_key'] ?? ''));

        if ($apiKey !== '') {
            $url = "https://maps.googleapis.com/maps/api/geocode/json?latlng={$lat},{$lng}&key=" . urlencode($apiKey);
            $resp = @file_get_contents($url);
            if ($resp !== false) {
                $data = json_decode($resp, true);
                if (is_array($data) && ($data['status'] ?? '') === 'OK' && !empty($data['results'])) {
                    $place = $data['results'][0]['formatted_address'] ?? '';
                    if ($place !== '') {
                        header('Content-Type: application/json; charset=utf-8');
                        echo json_encode(['ok' => true, 'place' => $place]);
                        return;
                    }
                }
            }
        }
    } catch (\Throwable $e) {
        // Fall through — client will use OSM Nominatim
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false]);
}

/**
 * Verify if a given (lat, lng) falls within any active office location geo-fence.
 * GET or POST.
 */
function kioskApiVerifyLocation(array $params = []): void
{
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    $input = str_contains($contentType, 'application/json')
        ? (json_decode(file_get_contents('php://input'), true) ?: [])
        : ($_GET ?: $_POST);

    $lat = (float)($input['latitude'] ?? 0);
    $lng = (float)($input['longitude'] ?? 0);

    if ($lat === 0.0 && $lng === 0.0) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'Latitude and longitude required']);
        return;
    }

    try {
        $db = aw_db();
        $tid = app()->tenant()->current() ?? '';

        // If no office locations exist at all, auto-pass everyone
        $locCount = (int)$db->query("SELECT COUNT(*) FROM office_locations WHERE tenant_id = '{$tid}' AND is_active = 1")->fetchColumn();
        if ($locCount === 0) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => true, 'no_locations' => true, 'location_name' => null]);
            return;
        }

        $matched = aw_findLocationByGeo($lat, $lng);
        header('Content-Type: application/json; charset=utf-8');
        if ($matched) {
            echo json_encode([
                'ok'            => true,
                'location_name' => $matched['name'] ?? null,
                'location_id'   => (int)($matched['location_id'] ?? 0),
                'distance_m'    => $matched['distance_meters'] ?? null,
            ]);
        } else {
            echo json_encode(['ok' => false, 'error' => 'You are outside all office locations.']);
        }
    } catch (\Throwable $e) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
}

/**
 * Check clock-in status for an employee (by profile_id).
 */
function kioskApiStatus(array $params = []): void
{
    $profileId = (int)($_GET['profile_id'] ?? $params['profile_id'] ?? 0);

    if ($profileId <= 0) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'Missing profile_id']);
        return;
    }

    try {
        $db = aw_db();
        // Get user_id from employee profile
        $stmt = $db->prepare("SELECT user_id FROM employee_profiles WHERE profile_id = :pid LIMIT 1");
        $stmt->execute([':pid' => $profileId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $userId = (int)($row['user_id'] ?? 0);

        $clockedIn = false;
        $clockInTime = null;

        if ($userId > 0) {
            $check = $db->prepare(
                "SELECT clock_in FROM attendance_records
                 WHERE user_id = :uid AND DATE(clock_in) = CURDATE() AND clock_out IS NULL
                 ORDER BY clock_in DESC LIMIT 1"
            );
            $check->execute([':uid' => $userId]);
            $active = $check->fetch(\PDO::FETCH_ASSOC);
            if ($active) {
                $clockedIn = true;
                $clockInTime = $active['clock_in'];
            }
        }

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok'          => true,
            'clocked_in'  => $clockedIn,
            'clock_in_at' => $clockInTime,
        ]);
    } catch (\Throwable $e) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
}

/**
 * Fetch recent attendance records for a given employee (by profile_id).
 * Used by the kiosk "View My Attendance" feature after clock-in/out.
 */
function kioskApiMyRecords(array $params = []): void
{
    $profileId = (int)($_GET['profile_id'] ?? $params['profile_id'] ?? 0);

    if ($profileId <= 0) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'Missing profile_id']);
        return;
    }

    try {
        $db = aw_db();
        $stmt = $db->prepare("SELECT user_id FROM employee_profiles WHERE profile_id = :pid LIMIT 1");
        $stmt->execute([':pid' => $profileId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $userId = (int)($row['user_id'] ?? 0);

        $records = [];
        if ($userId > 0) {
            $recStmt = $db->prepare(
                "SELECT attendance_id, clock_in, clock_out, location_in, status
                 FROM attendance_records
                 WHERE user_id = :uid
                 ORDER BY clock_in DESC
                 LIMIT 10"
            );
            $recStmt->execute([':uid' => $userId]);
            $records = $recStmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        }

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok'      => true,
            'records' => $records,
        ]);
    } catch (\Throwable $e) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
}
