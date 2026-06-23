<?php

declare(strict_types=1);

/**
 * Guidance module — Entity view contract registrations.
 *
 * Replaces kernel-level built-in defaults so guidance entities
 * are explicitly owned by the guidance module (P2.1 migration).
 */

if (\function_exists('app') && ($app = \app()) !== null && method_exists($app, 'entityViews')) {
    $views = $app->entityViews();
    $moduleId = 'guidance';

    $views->registerView('guidance_case', 'compact', [
        'fields' => ['id', 'student_name', 'status', 'created_at', 'counselor_name'],
        'actions' => ['view'],
        'limit' => 15,
        'empty_state' => 'No cases found.',
        'sortable_fields' => ['student_name' => 'student_name', 'status' => 'status', 'created_at' => 'created_at'],
    ], $moduleId);

    $views->registerView('guidance_appointment', 'compact', [
        'fields' => ['id', 'title', 'date', 'status', 'student_name'],
        'actions' => ['view', 'cancel'],
        'limit' => 10,
        'empty_state' => 'No appointments.',
        'sortable_fields' => ['title' => 'title', 'date' => 'date', 'status' => 'status'],
    ], $moduleId);
}
