<?php
/**
 * Public Booking Route Handlers
 * 
 * Handles public appointment booking (no auth required)
 * 
 * @package Guidance\Routes
 */

/**
 * Get colleges for public booking dropdown
 */
function apiGuidanceGetColleges(): void {
    try {
        $db = guidanceDb();
        $colleges = $db->query("SELECT id, code, name FROM gm_colleges WHERE is_active = 1 ORDER BY sort_order, name")->fetchAll(PDO::FETCH_ASSOC);
        
        if (app()->isHtmx()) {
            $html = '';
            foreach ($colleges as $college) {
                $html .= '<option value="' . (int)$college['id'] . '">' . htmlspecialchars($college['code'] . ' - ' . $college['name']) . '</option>';
            }
            echo $html;
        } else {
            app()->json(['success' => true, 'data' => $colleges]);
        }
    } catch (Exception $e) {
        app()->log('Booking get colleges error: ' . $e->getMessage(), 'error');
        app()->json(['error' => 'Failed to load colleges'], 500);
    }
}

/**
 * Get appointment types for public booking
 */
function apiGuidanceGetAppointmentTypes(): void {
    try {
        $db = guidanceDb();
        $types = $db->query("SELECT id, code, name, description, duration_minutes, color FROM gm_appointment_types WHERE is_active = 1 AND is_public = 1 ORDER BY sort_order, name")->fetchAll(PDO::FETCH_ASSOC);
        
        if (app()->isHtmx()) {
            $html = '';
            foreach ($types as $type) {
                $html .= '<option value="' . (int)$type['id'] . '" data-duration="' . (int)$type['duration_minutes'] . '">' . htmlspecialchars($type['name']) . ' (' . $type['duration_minutes'] . ' min)</option>';
            }
            echo $html;
        } else {
            app()->json(['success' => true, 'data' => $types]);
        }
    } catch (Exception $e) {
        app()->log('Booking get types error: ' . $e->getMessage(), 'error');
        app()->json(['error' => 'Failed to load appointment types'], 500);
    }
}

/**
 * Get available time slots for a date and college
 */
function apiGuidanceGetAvailableSlots(): void {
    $date = $_GET['date'] ?? null;
    $collegeId = $_GET['college_id'] ?? null;
    $typeId = $_GET['type_id'] ?? null;
    
    if (!$date || !$collegeId) {
        app()->json(['error' => 'Date and college are required'], 400);
        return;
    }
    
    try {
        $db = guidanceDb();
        require_once __DIR__ . '/../helpers/availability.php';
        
        // Validate date is within booking window (from settings)
        $apptSettingsRaw = $db->query("SELECT setting_value FROM gm_settings WHERE setting_key = 'appointment_settings'")->fetchColumn();
        $apptSettingsParsed = json_decode($apptSettingsRaw ?: '{}', true);
        $maxBookingDays = (int) ($apptSettingsParsed['max_booking_days_ahead'] ?? 14);
        
        $today = new DateTime();
        $selectedDate = new DateTime($date);
        $maxDate = (clone $today)->modify("+{$maxBookingDays} days");
        
        if ($selectedDate < $today || $selectedDate > $maxDate) {
            app()->json(['error' => "Date must be within the next {$maxBookingDays} days"], 400);
            return;
        }
        
        // Reuse appointment settings already fetched above
        $slotDuration = $typeId ? getTypeDuration($db, $typeId) : ($apptSettingsParsed['default_duration_minutes'] ?? 30);
        $bufferMinutes = $apptSettingsParsed['buffer_minutes'] ?? 5;
        
        // Get counselor(s) for this college (exclude admin role)
        $counselorStmt = $db->prepare("
            SELECT ca.counselor_id 
            FROM gm_counselor_assignments ca
            JOIN gm_users u ON ca.counselor_id = u.id
            WHERE ca.college_id = ? AND ca.is_active = 1 AND u.role != 'admin'
        ");
        $counselorStmt->execute([$collegeId]);
        $counselorIds = $counselorStmt->fetchAll(PDO::FETCH_COLUMN);
        
        if (empty($counselorIds)) {
            if (app()->isHtmx()) {
                echo '<div class="text-amber-600 p-4 bg-amber-50 rounded-lg"><i class="fas fa-user-slash mr-2"></i>No counselor is assigned to this college yet. Please contact the guidance office.</div>';
            } else {
                app()->json(['error' => 'No counselor assigned to this college'], 400);
            }
            return;
        }

        $placeholders = implode(',', array_fill(0, count($counselorIds), '?'));

        // Check for full-day blocks. Global blocks close the whole day; counselor-specific
        // blocks remove only those counselors from availability consideration.
        $blockedStmt = $db->prepare("
            SELECT counselor_id, reason
            FROM gm_blocked_dates
            WHERE blocked_date = ?
              AND start_time IS NULL
              AND (counselor_id IS NULL OR counselor_id IN ({$placeholders}))
        ");
        $blockedStmt->execute(array_merge([$date], $counselorIds));
        $blockedRows = $blockedStmt->fetchAll(PDO::FETCH_ASSOC);

        $blockedCounselors = [];
        foreach ($blockedRows as $blockedRow) {
            if ($blockedRow['counselor_id'] === null) {
                if (app()->isHtmx()) {
                    echo '<div class="text-red-600 p-4 bg-red-50 rounded-lg"><i class="fas fa-calendar-times mr-2"></i>This date is unavailable: ' . htmlspecialchars($blockedRow['reason']) . '</div>';
                } else {
                    app()->json(['error' => 'Date is blocked', 'reason' => $blockedRow['reason']], 400);
                }
                return;
            }

            $blockedCounselors[(int) $blockedRow['counselor_id']] = true;
        }

        $availableCounselorIds = array_values(array_filter($counselorIds, static function ($counselorId) use ($blockedCounselors) {
            return !isset($blockedCounselors[(int) $counselorId]);
        }));

        if (empty($availableCounselorIds)) {
            if (app()->isHtmx()) {
                echo '<div class="text-amber-600 p-4 bg-amber-50 rounded-lg"><i class="fas fa-user-slash mr-2"></i>No counselor is available on this date.</div>';
            } else {
                app()->json(['error' => 'No counselor is available on this date'], 400);
            }
            return;
        }

        $counselorSchedules = [];
        foreach ($availableCounselorIds as $counselorId) {
            $hours = getCounselorAvailabilityForDate($db, (int) $counselorId, $date);
            if ($hours) {
                $counselorSchedules[(int) $counselorId] = $hours;
            }
        }

        if (empty($counselorSchedules)) {
            if (app()->isHtmx()) {
                echo '<div class="text-amber-600 p-4 bg-amber-50 rounded-lg"><i class="fas fa-clock mr-2"></i>No counselor has availability configured for this day.</div>';
            } else {
                app()->json(['error' => 'No counselor is available on this day'], 400);
            }
            return;
        }
        
        // Get existing appointments for these counselors on this date
        $existingStmt = $db->prepare("
            SELECT scheduled_time, duration_minutes, counselor_id
            FROM gm_appointments 
            WHERE counselor_id IN ({$placeholders})
            AND scheduled_date = ?
            AND status NOT IN ('cancelled', 'rejected')
        ");
        $existingStmt->execute(array_merge($availableCounselorIds, [$date]));
        $existingAppointments = $existingStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Build booked slots per counselor
        $bookedSlots = [];
        foreach ($existingAppointments as $appt) {
            $start = strtotime($date . ' ' . $appt['scheduled_time']);
            $end = $start + ($appt['duration_minutes'] * 60);
            $bookedSlots[$appt['counselor_id']][] = ['start' => $start, 'end' => $end];
        }

        // Get blocked time slots
        $blockedTimesStmt = $db->prepare("
            SELECT start_time, end_time, counselor_id FROM gm_blocked_dates 
            WHERE blocked_date = ? 
            AND start_time IS NOT NULL
            AND (counselor_id IS NULL OR counselor_id IN ({$placeholders}))
        ");
        $blockedTimesStmt->execute(array_merge([$date], $availableCounselorIds));
        $blockedTimes = $blockedTimesStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($blockedTimes as $blocked) {
            $counselorId = $blocked['counselor_id'] ?? 'all';
            $start = strtotime($date . ' ' . $blocked['start_time']);
            $end = strtotime($date . ' ' . $blocked['end_time']);
            if ($counselorId === 'all') {
                foreach (array_keys($counselorSchedules) as $cid) {
                    $bookedSlots[$cid][] = ['start' => $start, 'end' => $end];
                }
            } else {
                $bookedSlots[$counselorId][] = ['start' => $start, 'end' => $end];
            }
        }

        // Generate available slots across all counselors. When multiple counselors
        // can take the same time, the first available counselor is returned.
        $slotsByTime = [];
        $slotSeconds = $slotDuration * 60;
        $bufferSeconds = $bufferMinutes * 60;

        foreach ($counselorSchedules as $counselorId => $schedule) {
            foreach (($schedule['ranges'] ?? []) as $range) {
                $currentTime = strtotime($date . ' ' . $range['start']);
                $endTimeTs = strtotime($date . ' ' . $range['end']);

                while ($currentTime + $slotSeconds <= $endTimeTs) {
                    $slotEnd = $currentTime + $slotSeconds;
                    $isAvailable = true;

                    foreach ($bookedSlots[$counselorId] ?? [] as $booked) {
                        if ($currentTime < ($booked['end'] + $bufferSeconds) && $slotEnd > ($booked['start'] - $bufferSeconds)) {
                            $isAvailable = false;
                            break;
                        }
                    }

                    if ($isAvailable) {
                        $timeKey = date('H:i', $currentTime);
                        if (!isset($slotsByTime[$timeKey])) {
                            $slotsByTime[$timeKey] = [
                                'time' => $timeKey,
                                'display' => date('g:i A', $currentTime),
                                'counselor_id' => $counselorId,
                            ];
                        }
                    }

                    $currentTime += $slotSeconds;
                }
            }
        }

        ksort($slotsByTime);
        $slots = array_values($slotsByTime);
        
        if (app()->isHtmx()) {
            if (empty($slots)) {
                echo '<div class="text-amber-600 p-4 bg-amber-50 rounded-lg"><i class="fas fa-clock mr-2"></i>No available slots for this date. Please try another date or join the waitlist.</div>';
            } else {
                echo '<div class="grid grid-cols-4 sm:grid-cols-6 gap-2">';
                foreach ($slots as $slot) {
                    echo '<button type="button" class="slot-btn px-3 py-2 text-sm border border-gray-300 rounded-lg hover:bg-indigo-50 hover:border-indigo-500 focus:ring-2 focus:ring-indigo-500 transition-colors" data-time="' . htmlspecialchars($slot['time']) . '" data-counselor="' . (int)$slot['counselor_id'] . '">' . htmlspecialchars($slot['display']) . '</button>';
                }
                echo '</div>';
                echo '<input type="hidden" name="scheduled_time" id="selected-time" value="">';
                echo '<input type="hidden" name="counselor_id" id="selected-counselor" value="">';
            }
        } else {
            app()->json(['success' => true, 'data' => $slots]);
        }
    } catch (Exception $e) {
        app()->log('Booking get slots error: ' . $e->getMessage(), 'error');
        app()->json(['error' => 'Failed to load available slots'], 500);
    }
}

/**
 * Get type duration helper
 */
function getTypeDuration(PDO $db, int $typeId): int {
    $stmt = $db->prepare("SELECT duration_minutes FROM gm_appointment_types WHERE id = ?");
    $stmt->execute([$typeId]);
    return (int) ($stmt->fetchColumn() ?: 30);
}

/**
 * Handle public booking submission
 */
function apiGuidancePublicBooking(): void {
    // Rate limit: 5 bookings per 10 minutes per IP
    require_once __DIR__ . '/../helpers/security.php';
    require_once __DIR__ . '/../helpers/booking-security.php';
    if (!rateLimit('booking:' . clientIp(), 5, 600)) {
        if (app()->isHtmx()) {
            header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Too many booking attempts. Please try again later.', 'type' => 'error']]));
            http_response_code(429);
            echo '';
        } else {
            app()->json(['error' => 'Too many booking attempts. Please try again later.'], 429);
        }
        return;
    }
    
    $input = app()->input();
    
    // Validate required fields from dynamic form config + scheduling essentials
    require_once __DIR__ . '/../helpers/form-fields.php';
    $validationErrors = validateFormInput('booking', $input);
    // Scheduling fields are always required regardless of form config
    foreach (['scheduled_date', 'scheduled_time', 'appointment_type_id'] as $f) {
        if (empty($input[$f])) {
            $validationErrors[] = ucfirst(str_replace('_', ' ', $f)) . ' is required';
        }
    }
    
    if (!empty($validationErrors)) {
        if (app()->isHtmx()) {
            header('HX-Trigger: ' . json_encode(['showToast' => ['message' => $validationErrors[0], 'type' => 'error']]));
            http_response_code(400);
            echo '';
        } else {
            app()->json(['error' => $validationErrors[0], 'fields' => $validationErrors], 400);
        }
        return;
    }
    
    // Validate email
    if (!filter_var($input['student_email'], FILTER_VALIDATE_EMAIL)) {
        if (app()->isHtmx()) {
            header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Please enter a valid email address', 'type' => 'error']]));
            http_response_code(400);
            echo '';
        } else {
            app()->json(['error' => 'Invalid email address'], 400);
        }
        return;
    }
    
    try {
        $db = guidanceDb();
        $counselorId = resolvePublicBookingCounselorId(
            $db,
            (int) $input['college_id'],
            !empty($input['counselor_id']) ? (int) $input['counselor_id'] : null
        );
        
        if (!$counselorId) {
            throw new Exception('No counselor assigned to this college');
        }
        
        // Get appointment type details
        $typeStmt = $db->prepare("SELECT duration_minutes FROM gm_appointment_types WHERE id = ?");
        $typeStmt->execute([$input['appointment_type_id']]);
        $duration = (int) ($typeStmt->fetchColumn() ?: 30);
        
        if (!publicBookingSlotIsAvailable($db, (int) $counselorId, (string) $input['scheduled_date'], (string) $input['scheduled_time'])) {
            if (app()->isHtmx()) {
                header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'This time slot is no longer available. Please select another time.', 'type' => 'error']]));
                http_response_code(409);
                echo '';
            } else {
                app()->json(['error' => 'Time slot no longer available'], 409);
            }
            return;
        }
        
        // Create appointment
        $stmt = $db->prepare("
            INSERT INTO gm_appointments (
                counselor_id, student_id, student_name, student_email, student_phone,
                student_college_id, student_year_level, scheduled_date, scheduled_time,
                duration_minutes, appointment_type_id, purpose, status,
                requested_by_student, request_message, is_urgent, created_by, last_modified_by
            ) VALUES (?, 0, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', 1, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $counselorId,
            $input['student_name'],
            $input['student_email'],
            $input['student_phone'] ?? null,
            $input['college_id'],
            $input['year_level'] ?? null,
            $input['scheduled_date'],
            $input['scheduled_time'],
            $duration,
            $input['appointment_type_id'],
            $input['purpose'] ?? null,
            $input['message'] ?? null,
            !empty($input['is_urgent']) ? 1 : 0,
            0,
            0
        ]);
        
        $appointmentId = $db->lastInsertId();
        
        // Queue notification for counselor
        queueCounselorNotification($db, $counselorId, $appointmentId, $input);
        
        // Queue confirmation email for student
        queueStudentConfirmation($db, $appointmentId, $input);
        
        // Fire module hooks (e.g. SMS alert to counselor)
        if (function_exists('fireModuleHook')) {
            fireModuleHook('booking.submitted', [
                'appointment_id' => $appointmentId,
                'counselor_id' => $counselorId,
                'student_name' => $input['student_name'],
                'student_phone' => $input['student_phone'] ?? '',
                'student_email' => $input['student_email'] ?? '',
                'scheduled_date' => date('F j, Y', strtotime($input['scheduled_date'])),
                'scheduled_time' => date('g:i A', strtotime($input['scheduled_time'])),
                'purpose' => $input['purpose'] ?? '',
            ]);
        }
        
        if (app()->isHtmx()) {
            header('HX-Trigger: ' . json_encode([
                'showToast' => [
                    'message' => 'Appointment request submitted! You will receive a confirmation email once approved.',
                    'type' => 'success'
                ],
                'bookingSuccess' => true
            ]));
            echo app()->render('partials/booking-success.disyl', [
                'appointment_id' => $appointmentId,
                'student_name' => $input['student_name'],
                'scheduled_date' => $input['scheduled_date'],
                'scheduled_time' => $input['scheduled_time'],
                'student_email' => $input['student_email']
            ]);
        } else {
            app()->json(['success' => true, 'message' => 'Appointment request submitted', 'appointment_id' => $appointmentId], 201);
        }
    } catch (Exception $e) {
        app()->log('Booking create error: ' . $e->getMessage(), 'error');
        if (app()->isHtmx()) {
            header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Failed to create appointment. Please try again.', 'type' => 'error']]));
            http_response_code(500);
            echo '';
        } else {
            app()->json(['error' => 'Failed to create appointment'], 500);
        }
    }
}

/**
 * Queue notification for counselor about new booking
 */
function queueCounselorNotification(PDO $db, int $counselorId, int $appointmentId, array $input): void {
    try {
        // Create in-app notification
        $db->prepare("
            INSERT INTO gm_notifications (user_id, type, title, message, data, link)
            VALUES (?, 'appointment_request', 'New Appointment Request', ?, ?, ?)
        ")->execute([
            $counselorId,
            "New appointment request from {$input['student_name']} for " . date('M j, Y', strtotime($input['scheduled_date'])),
            json_encode(['appointment_id' => $appointmentId, 'student_name' => $input['student_name']]),
            "/pages/appointments?highlight={$appointmentId}"
        ]);
    } catch (Exception $e) {
        app()->log('Booking: failed to queue counselor notification: ' . $e->getMessage(), 'error');
    }
    
    // Send email notification to counselor
    try {
        $stmt = $db->prepare("SELECT first_name, last_name, email FROM gm_users WHERE id = ?");
        $stmt->execute([$counselorId]);
        $counselor = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        if ($counselor && !empty($counselor['email'])) {
            require_once __DIR__ . '/../helpers/mailer.php';
            sendAppointmentEmail('booking_counselor_alert', $counselor['email'], [
                'counselor_name' => $counselor['first_name'] . ' ' . $counselor['last_name'],
                'student_name' => $input['student_name'],
                'student_email' => $input['student_email'] ?? '',
                'student_phone' => $input['student_phone'] ?? '',
                'date' => date('F j, Y', strtotime($input['scheduled_date'])),
                'time' => date('g:i A', strtotime($input['scheduled_time'])),
                'purpose' => $input['purpose'] ?? '',
            ], $counselor['first_name'] . ' ' . $counselor['last_name']);
        }
    } catch (Exception $e) {
        app()->log('Booking: failed to send counselor email: ' . $e->getMessage(), 'error');
    }
}

/**
 * Send confirmation email for student booking request
 */
function queueStudentConfirmation(PDO $db, int $appointmentId, array $input): void {
    try {
        require_once __DIR__ . '/../helpers/mailer.php';
        sendAppointmentEmail('booking_received', $input['student_email'], [
            'student_name' => $input['student_name'],
            'date' => date('F j, Y', strtotime($input['scheduled_date'])),
            'time' => date('g:i A', strtotime($input['scheduled_time'])),
        ], $input['student_name']);
    } catch (Exception $e) {
        app()->log('Booking: failed to send student confirmation: ' . $e->getMessage(), 'error');
    }
}

/**
 * Render public booking page
 */
function renderPublicBookingPage(): void {
    try {
        $db = guidanceDb();
        
        // Get colleges
        $colleges = $db->query("SELECT id, code, name FROM gm_colleges WHERE is_active = 1 ORDER BY sort_order, name")->fetchAll(PDO::FETCH_ASSOC);
        
        // Get appointment types
        $types = $db->query("SELECT id, code, name, duration_minutes, color FROM gm_appointment_types WHERE is_active = 1 AND is_public = 1 ORDER BY sort_order, name")->fetchAll(PDO::FETCH_ASSOC);
        
        // Get settings
        $settingsStmt = $db->query("SELECT setting_value FROM gm_settings WHERE setting_key = 'appointment_settings'");
        $settings = json_decode($settingsStmt->fetchColumn() ?: '{}', true);
        
        $schoolStmt = $db->query("SELECT setting_value FROM gm_settings WHERE setting_key = 'school_info'");
        $schoolInfo = json_decode($schoolStmt->fetchColumn() ?: '{}', true);
        
        require_once __DIR__ . '/../helpers/form-fields.php';
        $bookingFieldsHtml = renderFormFields('booking', [], ['colleges' => $colleges]);
        
        // Check if 2FA is enabled for booking
        $twoFaBooking = '0';
        try {
            $tfStmt = $db->prepare("SELECT setting_value FROM gm_settings WHERE setting_key = 'two_fa_booking'");
            $tfStmt->execute();
            $twoFaBooking = $tfStmt->fetchColumn() ?: '0';
        } catch (\Exception $e) {}
        
        echo app()->render('pages/public-booking.disyl', [
            'colleges' => $colleges,
            'appointment_types' => $types,
            'settings' => $settings,
            'school_info' => $schoolInfo,
            'max_booking_days' => $settings['max_booking_days_ahead'] ?? 14,
            'min_date' => date('Y-m-d'),
            'max_date' => date('Y-m-d', strtotime('+' . ($settings['max_booking_days_ahead'] ?? 14) . ' days')),
            'booking_fields_html' => $bookingFieldsHtml,
            'two_fa_booking' => $twoFaBooking,
        ]);
    } catch (Exception $e) {
        app()->log('Booking render error: ' . $e->getMessage(), 'error');
        echo '<h1>Error loading booking page</h1>';
    }
}
