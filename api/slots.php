<?php
/**
 * JSON availability endpoint used by the booking, reschedule and staff booking forms.
 *   ?action=days&service_id=1&dentist_id=0[&from=YYYY-MM-DD]
 *   ?action=slots&service_id=1&dentist_id=0&date=YYYY-MM-DD
 * Optional: exclude=<appointment id> (when rescheduling), mode=staff (no minimum notice)
 */
require __DIR__ . '/../includes/bootstrap.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

$user = current_user();
// service_id may hold several ids ("1,3"): their times are added together
$service = service_bundle(parse_service_ids(input('service_id')));
if (!$service) {
    echo json_encode(['days' => [], 'slots' => [], 'message' => t('Choose a service first.')]);
    exit;
}
$dentistId = input_int('dentist_id') ?: null;

// Only the owner of an appointment (or staff) may exclude it from the busy check
$exclude = input_int('exclude') ?: null;
if ($exclude) {
    $owner = db_val('SELECT patient_id FROM appointments WHERE id = ?', [$exclude]);
    if (!$user || (!is_staff($user) && (int) $owner !== (int) $user['id'])) {
        $exclude = null;
    }
}

// Staff may book from "now" (walk-ins); patients need MIN_NOTICE_HOURS notice
$earliest = (input('mode') === 'staff' && is_staff($user)) ? time() : null;

// Month calendar: every date of the month with how many start times are still open
if (input('action') === 'month') {
    $month = preg_match('/^\d{4}-\d{2}$/', input('month')) ? input('month') : date('Y-m');
    $today = date('Y-m-d');
    $limit = is_staff($user) ? date('Y-m-d', strtotime('+180 days')) : date('Y-m-d', strtotime('+' . BOOKING_WINDOW_DAYS . ' days'));
    if ($month < date('Y-m')) {
        $month = date('Y-m');
    }
    if ($month > substr($limit, 0, 7)) {
        $month = substr($limit, 0, 7);
    }
    $days = [];
    $n = (int) date('t', strtotime("$month-01"));
    for ($i = 1; $i <= $n; $i++) {
        $date = sprintf('%s-%02d', $month, $i);
        if ($date < $today) {
            $state = 'past'; $slots = 0;
        } elseif ($date > $limit) {
            $state = 'beyond'; $slots = 0;
        } elseif (!is_clinic_day($date)) {
            $state = 'closed'; $slots = 0;
        } else {
            $slots = count(available_slots($date, (int) $service['duration_min'], $dentistId, $exclude, $earliest));
            $state = $slots ? 'open' : 'full';
        }
        $days[] = ['date' => $date, 'slots' => $slots, 'state' => $state];
    }
    echo json_encode([
        'month'    => $month,
        'prev'     => $month > date('Y-m') ? date('Y-m', strtotime("$month-01 -1 month")) : null,
        'next'     => $month < substr($limit, 0, 7) ? date('Y-m', strtotime("$month-01 +1 month")) : null,
        'days'     => $days,
    ]);
    exit;
}

if (input('action') === 'days') {
    $from = input('from');
    $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) && $from >= date('Y-m-d') ? $from : null;
    $days = [];
    $date = $from ?? date('Y-m-d');
    $limit = is_staff($user) ? date('Y-m-d', strtotime('+180 days')) : date('Y-m-d', strtotime('+' . BOOKING_WINDOW_DAYS . ' days'));
    while (count($days) < 14 && $date <= $limit) {
        if (is_clinic_day($date)) {
            $days[] = ['date' => $date, 'slots' => count(available_slots($date, (int) $service['duration_min'], $dentistId, $exclude, $earliest))];
        }
        $date = date('Y-m-d', strtotime("$date +1 day"));
    }
    echo json_encode(['days' => $days]);
    exit;
}

$date = input('date');
$slots = array_map(fn($s) => ['time' => $s['time'], 'dentists' => $s['dentists']], available_slots($date, (int) $service['duration_min'], $dentistId, $exclude, $earliest));
// Only the wording is translated; the slot data itself is identical in every language
$message = is_clinic_day($date)
    ? t('Times shown fit a {n}-minute {service} appointment.', ['n' => (int) $service['duration_min'], 'service' => svc_t($service['name'])])
    : t('The clinic is closed on this day.');
echo json_encode(['slots' => $slots, 'message' => $message], JSON_UNESCAPED_UNICODE);
