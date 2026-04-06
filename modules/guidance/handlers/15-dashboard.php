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
        
        // Active Cases count
        $stmt = $db->query("SELECT COUNT(*) FROM gm_cases WHERE status IN ('open', 'in_progress') AND deleted_at IS NULL");
        $activeCases = (int)$stmt->fetchColumn();

        // Today's Appointments count
        $today = date('Y-m-d');
        $stmt = $db->prepare("SELECT COUNT(*) FROM gm_appointments WHERE scheduled_date = ? AND status IN ('scheduled', 'confirmed') AND cancelled_at IS NULL");
        $stmt->execute([$today]);
        $todayAppointments = (int)$stmt->fetchColumn();

        // Pending Bookings count
        $stmt = $db->query("SELECT COUNT(*) FROM gm_appointments WHERE status = 'pending' AND cancelled_at IS NULL");
        $pendingBookings = (int)$stmt->fetchColumn();

        app()->json([
            'success' => true,
            'stats' => [
                'active_cases' => $activeCases,
                'today_appointments' => $todayAppointments,
                'pending_bookings' => $pendingBookings
            ]
        ]);
        
    } catch (Throwable $e) {
        app()->json(['error' => $e->getMessage()], 400);
    }
}
