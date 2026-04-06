<?php
/**
 * Reports Route Handlers
 * 
 * @package Guidance\Routes
 */

function apiGuidanceReportsSummary(): void {
    $user = guidanceUser();
    guidanceRequireStaff();
    $db = guidanceDb();
    
    try {
        // Date range filter
        $startDate = $_GET['start_date'] ?? null;
        $endDate = $_GET['end_date'] ?? null;
        
        $counselorFilter = '';
        $params = [];
        $dateFilter = '';
        $dateParams = [];
        
        if ($user['role'] === 'counselor') {
            $counselorFilter = 'AND c.counselor_id = ?';
            $params[] = $user['sub'];
        }
        
        if ($startDate && $endDate) {
            $dateFilter = 'AND c.created_at BETWEEN ? AND ?';
            $dateParams = [$startDate . ' 00:00:00', $endDate . ' 23:59:59'];
        }
        
        $caseParams = array_merge($params, $dateParams);
        
        // ── Cases by Status ──
        $statusStmt = $db->prepare("SELECT status, COUNT(*) as count FROM gm_cases c WHERE c.deleted_at IS NULL {$counselorFilter} {$dateFilter} GROUP BY status");
        $statusStmt->execute($caseParams);
        $byStatus = $statusStmt->fetchAll(PDO::FETCH_KEY_PAIR);
        
        // ── Cases by Severity (active only) ──
        $severityStmt = $db->prepare("SELECT severity, COUNT(*) as count FROM gm_cases c WHERE c.deleted_at IS NULL AND c.status NOT IN ('closed', 'archived') {$counselorFilter} {$dateFilter} GROUP BY severity");
        $severityStmt->execute($caseParams);
        $bySeverity = $severityStmt->fetchAll(PDO::FETCH_KEY_PAIR);
        
        // ── Cases by Category ──
        $categoryStmt = $db->prepare("SELECT COALESCE(NULLIF(category,''), 'uncategorized') as cat, COUNT(*) as count FROM gm_cases c WHERE c.deleted_at IS NULL {$counselorFilter} {$dateFilter} GROUP BY cat ORDER BY count DESC");
        $categoryStmt->execute($caseParams);
        $byCategory = $categoryStmt->fetchAll(PDO::FETCH_KEY_PAIR);
        $totalCases = array_sum($byCategory);
        
        // Build category items with percentages for progress bars
        $categoryItems = [];
        $catColors = ['academic'=>'indigo','behavioral'=>'rose','emotional'=>'amber','family'=>'teal','social'=>'violet','career'=>'cyan','mental_health'=>'red','substance'=>'orange','uncategorized'=>'gray'];
        foreach ($byCategory as $cat => $cnt) {
            $pct = $totalCases > 0 ? round(($cnt / $totalCases) * 100) : 0;
            $color = $catColors[strtolower($cat)] ?? 'indigo';
            $categoryItems[] = ['name' => ucfirst(str_replace('_', ' ', $cat)), 'count' => $cnt, 'pct' => $pct, 'color' => $color];
        }
        
        // ── Appointment Stats ──
        $apptCounselorFilter = $user['role'] === 'counselor' ? 'AND a.counselor_id = ?' : '';
        $apptParams = $user['role'] === 'counselor' ? [$user['sub']] : [];
        $apptDateFilter = '';
        $apptDateParams = [];
        if ($startDate && $endDate) {
            $apptDateFilter = 'AND a.scheduled_date BETWEEN ? AND ?';
            $apptDateParams = [$startDate, $endDate];
        }
        $apptAllParams = array_merge($apptParams, $apptDateParams);
        
        $apptStatusStmt = $db->prepare("SELECT a.status, COUNT(*) as count FROM gm_appointments a WHERE 1=1 {$apptCounselorFilter} {$apptDateFilter} GROUP BY a.status");
        $apptStatusStmt->execute($apptAllParams);
        $apptByStatus = $apptStatusStmt->fetchAll(PDO::FETCH_KEY_PAIR);
        
        $upcomingStmt = $db->prepare("SELECT COUNT(*) FROM gm_appointments a WHERE a.status IN ('scheduled', 'confirmed') AND a.scheduled_date >= CURDATE() AND a.scheduled_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY) {$apptCounselorFilter}");
        $upcomingStmt->execute($apptParams);
        $upcomingAppointments = (int) $upcomingStmt->fetchColumn();
        
        // ── Notes & Session Stats ──
        $notesCounselorFilter = $user['role'] === 'counselor' ? 'AND n.counselor_id = ?' : '';
        $notesParams = $user['role'] === 'counselor' ? [$user['sub']] : [];
        $notesDateFilter = '';
        $notesDateParams = [];
        if ($startDate && $endDate) {
            $notesDateFilter = 'AND n.session_date BETWEEN ? AND ?';
            $notesDateParams = [$startDate, $endDate];
        }
        $notesAllParams = array_merge($notesParams, $notesDateParams);
        
        $notesStmt = $db->prepare("SELECT COUNT(*) FROM gm_counselor_notes n WHERE 1=1 {$notesCounselorFilter} {$notesDateFilter}");
        $notesStmt->execute($notesAllParams);
        $totalNotes = (int) $notesStmt->fetchColumn();
        
        $sessionHoursStmt = $db->prepare("SELECT COALESCE(SUM(n.session_duration_minutes), 0) FROM gm_counselor_notes n WHERE 1=1 {$notesCounselorFilter} {$notesDateFilter}");
        $sessionHoursStmt->execute($notesAllParams);
        $totalSessionMinutes = (int) $sessionHoursStmt->fetchColumn();
        
        $avgRiskStmt = $db->prepare("SELECT n.risk_level, COUNT(*) as cnt FROM gm_counselor_notes n WHERE n.risk_level IS NOT NULL AND n.risk_level != '' AND n.risk_level != 'none' {$notesCounselorFilter} {$notesDateFilter} GROUP BY n.risk_level ORDER BY cnt DESC");
        $avgRiskStmt->execute($notesAllParams);
        $riskDistribution = $avgRiskStmt->fetchAll(PDO::FETCH_KEY_PAIR);
        
        // ── Monthly Trend (last 6 months) ──
        $trendParams = $user['role'] === 'counselor' ? [$user['sub']] : [];
        $trendStmt = $db->prepare("
            SELECT DATE_FORMAT(c.created_at, '%Y-%m') as month, COUNT(*) as opened,
                   SUM(CASE WHEN c.status = 'closed' THEN 1 ELSE 0 END) as closed
            FROM gm_cases c
            WHERE c.deleted_at IS NULL AND c.created_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
            " . ($user['role'] === 'counselor' ? 'AND c.counselor_id = ?' : '') . "
            GROUP BY month ORDER BY month ASC
        ");
        $trendStmt->execute($trendParams);
        $monthlyTrend = $trendStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Format trend for display
        $trendItems = [];
        foreach ($monthlyTrend as $m) {
            $trendItems[] = [
                'label' => date('M Y', strtotime($m['month'] . '-01')),
                'opened' => (int) $m['opened'],
                'closed' => (int) $m['closed'],
            ];
        }
        
        // ── Counselor Caseload (admin/supervisor only) ──
        $counselorStats = [];
        if (in_array($user['role'], ['admin', 'supervisor'])) {
            $clStmt = $db->prepare("
                SELECT u.id, CONCAT(u.first_name, ' ', u.last_name) as name,
                       COUNT(CASE WHEN c.status NOT IN ('closed','archived') THEN 1 END) as active,
                       COUNT(CASE WHEN c.status = 'closed' THEN 1 END) as closed,
                       COUNT(c.id) as total
                FROM gm_users u
                LEFT JOIN gm_cases c ON c.counselor_id = u.id AND c.deleted_at IS NULL
                WHERE u.role IN ('counselor','supervisor') AND u.deleted_at IS NULL
                GROUP BY u.id, name
                ORDER BY active DESC
            ");
            $clStmt->execute();
            $counselorStats = $clStmt->fetchAll(PDO::FETCH_ASSOC);
        }
        
        // ── Referral Source Breakdown ──
        $refStmt = $db->prepare("SELECT COALESCE(NULLIF(c.referral_source,''), 'walk-in') as src, COUNT(*) as cnt FROM gm_cases c WHERE c.deleted_at IS NULL {$counselorFilter} {$dateFilter} GROUP BY src ORDER BY cnt DESC");
        $refStmt->execute($caseParams);
        $byReferral = $refStmt->fetchAll(PDO::FETCH_KEY_PAIR);
        
        $totalActive = ($byStatus['open'] ?? 0) + ($byStatus['in_progress'] ?? 0) + ($byStatus['on_hold'] ?? 0);
        
        $data = [
            'summary' => [
                'total_cases' => $totalCases,
                'active_cases' => $totalActive,
                'closed_cases' => $byStatus['closed'] ?? 0,
                'critical_cases' => $bySeverity['critical'] ?? 0,
                'high_priority_cases' => $bySeverity['high'] ?? 0,
                'upcoming_appointments' => $upcomingAppointments,
                'total_notes' => $totalNotes,
                'total_session_hours' => round($totalSessionMinutes / 60, 1),
            ],
            'by_status' => $byStatus,
            'by_severity' => $bySeverity,
            'by_category' => $byCategory,
            'category_items' => $categoryItems,
            'appointments' => [
                'completed' => ($apptByStatus['completed'] ?? 0),
                'scheduled' => ($apptByStatus['scheduled'] ?? 0) + ($apptByStatus['confirmed'] ?? 0),
                'pending' => ($apptByStatus['pending'] ?? 0),
                'no_show' => ($apptByStatus['no_show'] ?? 0),
                'cancelled' => ($apptByStatus['cancelled'] ?? 0) + ($apptByStatus['rejected'] ?? 0),
                'upcoming' => $upcomingAppointments,
            ],
            'risk_distribution' => $riskDistribution,
            'monthly_trend' => $trendItems,
            'counselor_stats' => $counselorStats,
            'by_referral' => $byReferral,
            'has_date_filter' => (bool) ($startDate && $endDate),
            'start_date' => $startDate ?? '',
            'end_date' => $endDate ?? '',
        ];
        
        if (app()->isHtmx()) {
            echo app()->render('partials/reports-summary.disyl', $data);
        } else {
            app()->json(['success' => true, 'data' => $data]);
        }
    } catch (Exception $e) {
        app()->log('Reports summary error: ' . $e->getMessage(), 'error');
        app()->json(['error' => 'Failed to generate report'], 500);
    }
}
