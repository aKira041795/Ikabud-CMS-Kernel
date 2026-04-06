<?php
$files = glob('guidance/src/routes/*.php');
foreach ($files as $file) {
    if (basename($file) === 'auth.php' || basename($file) === 'dashboard.php') continue; // already ported
    
    $content = file_get_contents($file);
    
    // Replace typical references
    $content = str_replace('app()->db()', 'guidanceDb()', $content);
    $content = str_replace('app()->requireAuth()', 'guidanceUser()', $content);
    $content = str_replace('app()->json(', 'app()->json(', $content); // No change
    $content = preg_replace('/function handle([A-Za-z0-9_]+)/', 'function apiGuidance$1', $content);
    $content = preg_replace('/function page([A-Za-z0-9_]+)/', 'function pageGuidance$1', $content);
    $content = preg_replace('/function process([A-Za-z0-9_]+)/', 'function processGuidance$1', $content);
    
    // Set proper target filename
    $targetName = '';
    $bName = basename($file);
    switch ($bName) {
        case 'cases.php': $targetName = '20-cases.php'; break;
        case 'appointments.php': $targetName = '25-appointments.php'; break;
        case 'booking.php': $targetName = '30-booking.php'; break;
        case 'users.php': $targetName = '35-users.php'; break;
        case 'colleges.php': $targetName = '40-colleges.php'; break;
        case 'settings.php': $targetName = '45-settings.php'; break;
        case 'student-statuses.php': $targetName = '50-profile.php'; /* We'll map differently later */ break;
        case 'reports.php': $targetName = '55-reports.php'; break;
        case 'notes.php': $targetName = '60-notes.php'; break;
        case 'tracker.php': $targetName = '65-tracker.php'; break;
        default: continue 2;
    }
    
    file_put_contents('modules/guidance/handlers/' . $targetName, $content);
    echo "Converted $bName -> $targetName\n";
}
