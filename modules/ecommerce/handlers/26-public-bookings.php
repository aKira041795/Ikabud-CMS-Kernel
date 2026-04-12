<?php

declare(strict_types=1);

function ecPublicBookingReschedule(): void
{
    csrf_verify();

    $user = app()->user();
    if (!$user || !in_array($user['role'] ?? '', ['subscriber', 'customer', 'editor', 'administrator'], true)) {
        header('Location: /cms/login?redirect=' . urlencode('/ecommerce/my-bookings'));
        exit;
    }

    $input = ecInput();
    $bookingId = (int)($input['booking_id'] ?? 0);
    $newDate = trim((string)($input['reschedule_date'] ?? ''));
    $newTime = trim((string)($input['reschedule_time'] ?? ''));

    $result = ecBookingReschedule($bookingId, $newDate, $newTime, (int)$user['id']);

    if (!empty($result['ok'])) {
        $_SESSION['ec_flash'] = ['type' => 'success', 'message' => 'Booking rescheduled successfully.'];
    } else {
        $_SESSION['ec_flash'] = ['type' => 'error', 'message' => $result['error'] ?? 'Could not reschedule booking.'];
    }

    header('Location: /ecommerce/my-bookings');
    exit;
}

function ecPublicBookingCancel(): void
{
    csrf_verify();

    $user = app()->user();
    if (!$user || !in_array($user['role'] ?? '', ['subscriber', 'customer', 'editor', 'administrator'], true)) {
        header('Location: /cms/login?redirect=' . urlencode('/ecommerce/my-bookings'));
        exit;
    }

    $input = ecInput();
    $bookingId = (int)($input['booking_id'] ?? 0);
    $reason = trim((string)($input['cancel_reason'] ?? ''));

    $result = ecBookingCancel($bookingId, $reason, (int)$user['id']);

    if (!empty($result['ok'])) {
        $_SESSION['ec_flash'] = ['type' => 'success', 'message' => 'Booking cancelled.'];
    } else {
        $_SESSION['ec_flash'] = ['type' => 'error', 'message' => $result['error'] ?? 'Could not cancel booking.'];
    }

    header('Location: /ecommerce/my-bookings');
    exit;
}
