<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

function encPageIndex(array $params = []): void
{
    if (!function_exists('ehrRequireAdmin') || !function_exists('ehrRender') || !function_exists('ehrAdminContext')) {
        http_response_code(503);
        echo 'EHR admin runtime unavailable';
        return;
    }

    $user = ehrRequireAdmin();
    $input = app()->input();
    $statusFilter = strtolower(trim((string)($input['status'] ?? '')));
    if ($statusFilter !== '' && !encEncounterStatusAllowed($statusFilter)) {
        $statusFilter = '';
    }

    $selectedEncounterId = max(0, (int)($input['encounter_id'] ?? 0));
    $encounters = encHydrateEncounterPatients(encListRecentEncounters($statusFilter, 25));

    $selectedEncounter = null;
    if ($selectedEncounterId > 0) {
        $selectedEncounter = encFetchEncounterByIdOrUuid($selectedEncounterId);
        if (is_array($selectedEncounter)) {
            $selectedEncounter = encHydrateEncounterPatients([$selectedEncounter])[0] ?? $selectedEncounter;
        }
    }

    echo ehrRender('modules/encounters/admin/index.disyl', array_merge(
        ehrAdminContext($user, 'ehr_encounters', [
            'page_title' => 'Encounters',
        ]),
        [
            'status_filter' => $statusFilter,
            'encounters' => $encounters,
            'result_count' => count($encounters),
            'selected_encounter' => $selectedEncounter,
        ]
    ));
}