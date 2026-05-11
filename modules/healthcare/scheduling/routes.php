<?php

declare(strict_types=1);

return [
    'GET' => [
        '/admin/ehr/appointments' => 'scheduling:schedPageIndex',
        '/ehr/queue-monitor' => 'scheduling:schedPageMonitor',
    ],
    'POST' => [
        '/admin/ehr/appointments' => 'scheduling:schedSaveAppointment',
        '/admin/ehr/appointments/transition' => 'scheduling:schedTransitionAppointment',
        '/admin/ehr/appointments/handoff' => 'scheduling:schedHandoffAppointment',
    ],
];