<?php
// Extracted Dashboard Handlers

function pageGuidanceDashboard(): void {
    guidanceRequireStaff();
    guidanceRender('pages/dashboard.disyl', [
        'title' => 'Dashboard',
        'is_pro' => guidanceIsPro()
    ]);
}

function apiGuidanceDashboardStats(): void {
    guidanceRequireStaff();
    try {
        $db = guidanceDb();

        // Student risk and status breakdown from gm_cases
        $stmt = $db->query("
            SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN severity = 'low' THEN 1 ELSE 0 END) AS low_risk,
                SUM(CASE WHEN severity = 'medium' THEN 1 ELSE 0 END) AS moderate_risk,
                SUM(CASE WHEN severity IN ('high','critical') THEN 1 ELSE 0 END) AS high_risk,
                SUM(CASE WHEN student_status = 'probationary' THEN 1 ELSE 0 END) AS probationary
            FROM gm_cases
            WHERE deleted_at IS NULL
        ");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $total        = (int)($row['total'] ?? 0);
        $lowRisk      = (int)($row['low_risk'] ?? 0);
        $moderateRisk = (int)($row['moderate_risk'] ?? 0);
        $highRisk     = (int)($row['high_risk'] ?? 0);
        $probationary = (int)($row['probationary'] ?? 0);

        $pct = fn(int $n) => $total > 0 ? round($n / $total * 100, 1) : 0;

        app()->json([
            'success' => true,
            'stats' => [
                'total_students'      => $total,
                'low_risk'            => $lowRisk,
                'low_risk_pct'        => $pct($lowRisk),
                'moderate_risk'       => $moderateRisk,
                'moderate_risk_pct'   => $pct($moderateRisk),
                'high_risk'           => $highRisk,
                'high_risk_pct'       => $pct($highRisk),
                'probationary'        => $probationary,
                'probationary_pct'    => $pct($probationary),
            ]
        ]);

    } catch (Throwable $e) {
        app()->json(['error' => $e->getMessage()], 400);
    }
}
