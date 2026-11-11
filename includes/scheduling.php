<?php
/**
 * Scheduling engine.
 *
 * Rules
 *  - Each service has its own duration; an appointment occupies [start, start + duration).
 *  - Start times are offered every SLOT_INTERVAL minutes inside clinic hours, never
 *    crossing the lunch break or closing time.
 *  - A dentist is available when the interval does not overlap any of their
 *    Pending/Confirmed appointments, any block for that dentist, or any clinic-wide block.
 *  - Dr. Warren is the only bookable dentist (sole_dentist_id()); other dentist records are history only.
 *  - Emergency appointments ignore these rules; conflicting bookings are moved
 *    to the dentist's next free slot and the patients are notified.
 */

const ACTIVE_STATUSES = ['pending', 'confirmed'];

/**
 * Dr. Warren is the clinic's only dentist. Uses the existing dentist record whose name
 * contains DENTIST_NAME_MATCH (creates it only if none exists). Other dentist records are
 * kept for appointment history but can never be booked.
 */
function sole_dentist_id(): int
{
    static $id = null;
    if ($id === null) {
        $id = (int) db_val('SELECT id FROM dentists WHERE name LIKE ? ORDER BY is_active DESC, id' . sql_limit(1), ['%' . DENTIST_NAME_MATCH . '%']);
        if (!$id) {
            $id = db_insert('dentists', ['name' => DENTIST_DEFAULT_NAME, 'specialty' => 'General & Cosmetic Dentistry', 'is_active' => 1]);
        }
    }
    return $id;
}

function active_dentists(): array
{
    // Only Dr. Warren is offered anywhere in the system
    return db_all('SELECT * FROM dentists WHERE id = ?', [sole_dentist_id()]);
}

function active_services(): array
{
    return db_all("SELECT * FROM services WHERE is_active = 1 ORDER BY CASE category WHEN 'routine' THEN 0 ELSE 1 END, name");
}

/** Service ids from a form: array, "1,3" or a single id. Unique, in the order picked. */
function parse_service_ids($raw): array
{
    if (!is_array($raw)) {
        $raw = explode(',', (string) $raw);
    }
    $ids = [];
    foreach ($raw as $v) {
        $v = (int) $v;
        if ($v > 0 && !in_array($v, $ids, true)) {
            $ids[] = $v;
        }
    }
    return array_slice($ids, 0, 10);
}

/**
 * Several services booked together in one visit, done back to back:
 * the time needed and the starting rate are the totals. Null if any service is invalid.
 */
function service_bundle(array $ids, bool $requireActive = true): ?array
{
    if (!$ids) {
        return null;
    }
    $list = [];
    foreach ($ids as $id) {
        $s = find_service((int) $id);
        if (!$s || ($requireActive && !$s['is_active'])) {
            return null;
        }
        $list[] = $s;
    }
    return [
        'ids'          => array_map(fn($s) => (int) $s['id'], $list),
        'services'     => $list,
        'primary'      => $list[0],
        'duration_min' => array_sum(array_map(fn($s) => (int) $s['duration_min'], $list)),
        'price'        => array_sum(array_map(fn($s) => (float) $s['price'], $list)),
        'name'         => implode(' + ', array_map(fn($s) => $s['name'], $list)),
        'extra'        => count($list) > 1 ? ',' . implode(',', array_map(fn($s) => (int) $s['id'], array_slice($list, 1))) . ',' : null,
    ];
}

function find_service(int $id): ?array
{
    return db_one('SELECT * FROM services WHERE id = ?', [$id]);
}

function find_dentist(int $id): ?array
{
    return db_one('SELECT * FROM dentists WHERE id = ?', [$id]);
}

function is_clinic_day(string $date): bool
{
    return in_array((int) date('N', strtotime($date)), CLINIC_DAYS, true);
}

/** Open intervals [startTs, endTs] for a date (morning and afternoon around lunch). */
function day_windows(string $date): array
{
    if (!is_clinic_day($date)) {
        return [];
    }
    $open = strtotime("$date " . CLINIC_OPEN);
    $close = strtotime("$date " . CLINIC_CLOSE);
    if (LUNCH_START !== '' && LUNCH_END !== '') {
        $ls = strtotime("$date " . LUNCH_START);
        $le = strtotime("$date " . LUNCH_END);
        if ($ls > $open && $le < $close && $ls < $le) {
            return [[$open, $ls], [$le, $close]];
        }
    }
    return [[$open, $close]];
}

/** Busy intervals per dentist for one date: [dentistId => [[s, e, label], ...]]. */
function busy_map(string $date, ?int $excludeAppointmentId = null): array
{
    $dayStart = "$date 00:00:00";
    $dayEnd = date('Y-m-d', strtotime("$date +1 day")) . ' 00:00:00';
    $map = [];
    foreach (active_dentists() as $d) {
        $map[(int) $d['id']] = [];
    }
    $rows = db_all(
        "SELECT id, dentist_id, start_at, end_at, contact_name FROM appointments
         WHERE status IN ('pending','confirmed') AND start_at < ? AND end_at > ? AND id <> ?",
        [$dayEnd, $dayStart, $excludeAppointmentId ?? 0]
    );
    foreach ($rows as $r) {
        $map[(int) $r['dentist_id']][] = [strtotime($r['start_at']), strtotime($r['end_at']), 'appt'];
    }
    $blocks = db_all('SELECT dentist_id, start_at, end_at FROM schedule_blocks WHERE start_at < ? AND end_at > ?', [$dayEnd, $dayStart]);
    foreach ($blocks as $b) {
        $iv = [strtotime($b['start_at']), strtotime($b['end_at']), 'block'];
        if ($b['dentist_id'] === null) {
            foreach (array_keys($map) as $did) {
                $map[$did][] = $iv;
            }
        } else {
            $map[(int) $b['dentist_id']][] = $iv;
        }
    }
    return $map;
}

function overlaps_any(array $intervals, int $s, int $e): bool
{
    foreach ($intervals as $iv) {
        if ($iv[0] < $e && $iv[1] > $s) {
            return true;
        }
    }
    return false;
}

/**
 * Start times that can be booked on $date for a service of $duration minutes.
 * Returns [['time' => 'HH:MM', 'dentists' => [ids...]], ...]
 *
 * @param int|null $earliestTs no slot may start before this timestamp (defaults to now + MIN_NOTICE_HOURS)
 */
function available_slots(string $date, int $duration, ?int $dentistId = null, ?int $excludeId = null, ?int $earliestTs = null): array
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $duration <= 0) {
        return [];
    }
    $earliestTs = $earliestTs ?? time() + MIN_NOTICE_HOURS * 3600;
    $busy = busy_map($date, $excludeId);
    $dentists = $dentistId ? array_intersect([$dentistId], array_keys($busy)) : array_keys($busy);
    if (!$dentists) {
        return [];
    }
    $step = SLOT_INTERVAL * 60;
    $len = $duration * 60;
    $slots = [];
    foreach (day_windows($date) as [$ws, $we]) {
        for ($t = $ws; $t + $len <= $we; $t += $step) {
            if ($t < $earliestTs) {
                continue;
            }
            $free = [];
            foreach ($dentists as $d) {
                if (!overlaps_any($busy[$d], $t, $t + $len)) {
                    $free[] = $d;
                }
            }
            if ($free) {
                // lightest load first, so "any dentist" spreads the work
                usort($free, fn($a, $b) => count($busy[$a]) <=> count($busy[$b]) ?: $a <=> $b);
                $slots[] = ['time' => date('H:i', $t), 'dentists' => array_values($free)];
            }
        }
    }
    return $slots;
}

/** Next $count bookable dates with their number of free start times. */
function available_days(int $duration, ?int $dentistId = null, ?int $excludeId = null, int $count = 14, ?string $from = null): array
{
    $days = [];
    $date = $from ?? date('Y-m-d');
    $limit = date('Y-m-d', strtotime('+' . BOOKING_WINDOW_DAYS . ' days'));
    while (count($days) < $count && $date <= $limit) {
        if (is_clinic_day($date)) {
            $n = count(available_slots($date, $duration, $dentistId, $excludeId));
            $days[] = ['date' => $date, 'slots' => $n];
        }
        $date = date('Y-m-d', strtotime("$date +1 day"));
    }
    return $days;
}

/**
 * Validate a requested booking and resolve the dentist.
 * Returns ['ok' => true, 'dentist_id' => .., 'start' => .., 'end' => ..] or ['ok' => false, 'error' => '...'].
 */
function check_booking($serviceIds, ?int $dentistId, string $date, string $time, ?int $excludeId = null, ?int $earliestTs = null, bool $requireActive = true): array
{
    // one service id, or several services done back to back in one visit
    $service = service_bundle(parse_service_ids($serviceIds), $requireActive);
    if (!$service) {
        return ['ok' => false, 'error' => t('Please choose a valid dental service.')];
    }
    // Backend rule: every new or moved appointment is with Dr. Warren
    if ($dentistId && $dentistId !== sole_dentist_id()) {
        return ['ok' => false, 'error' => t('The selected dentist is not available.')];
    }
    $dentistId = sole_dentist_id();
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^\d{2}:\d{2}$/', $time)) {
        return ['ok' => false, 'error' => t('Please choose a date and time.')];
    }
    if ($earliestTs === null && $date > date('Y-m-d', strtotime('+' . BOOKING_WINDOW_DAYS . ' days'))) {
        return ['ok' => false, 'error' => t('Bookings can only be made up to {n} days ahead.', ['n' => BOOKING_WINDOW_DAYS])];
    }
    foreach (available_slots($date, (int) $service['duration_min'], $dentistId ?: null, $excludeId, $earliestTs) as $slot) {
        if ($slot['time'] === $time) {
            $start = "$date $time:00";
            return [
                'ok'         => true,
                'dentist_id' => $slot['dentists'][0],
                'start'      => $start,
                'end'        => date('Y-m-d H:i:s', strtotime($start) + $service['duration_min'] * 60),
                'service'    => $service,
                'bundle'     => $service,
            ];
        }
    }
    return ['ok' => false, 'error' => t('Sorry, that time slot is no longer available. Please pick another one.')];
}

/** Serialise bookings so two people can't grab the same slot at the same moment. */
function booking_lock(bool $acquire): void
{
    if (DB_DRIVER === 'mysql') {
        q($acquire ? "SELECT GET_LOCK('mydentist_booking', 10)" : "SELECT RELEASE_LOCK('mydentist_booking')");
    } elseif (DB_DRIVER === 'sqlsrv') {
        db()->exec($acquire
            ? "EXEC sp_getapplock @Resource = 'mydentist_booking', @LockMode = 'Exclusive', @LockOwner = 'Session', @LockTimeout = 10000"
            : "EXEC sp_releaseapplock @Resource = 'mydentist_booking', @LockOwner = 'Session'");
    }
}

/**
 * Create an appointment after re-checking availability inside a lock.
 * $data: service_id, dentist_id (0 = any), date, time, contact_name, contact_email,
 *        contact_phone, patient_id, source, status, patient_notes, staff_notes
 * Returns ['ok' => true, 'id' => int] or ['ok' => false, 'error' => string]
 */
function create_appointment(array $data, ?int $earliestTs = null): array
{
    booking_lock(true);
    try {
        $check = check_booking($data['service_ids'] ?? $data['service_id'], (int) ($data['dentist_id'] ?? 0) ?: null, $data['date'], $data['time'], null, $earliestTs);
        if (!$check['ok']) {
            return $check;
        }
        $now = now_str();
        $id = db_insert('appointments', [
            'code'          => generate_appointment_code(),
            'manage_token'  => bin2hex(random_bytes(16)),
            'patient_id'    => $data['patient_id'] ?? null,
            'contact_name'  => $data['contact_name'],
            'contact_email' => $data['contact_email'] ?: null,
            'contact_phone' => $data['contact_phone'] ?: null,
            'service_id'    => $check['bundle']['ids'][0],
            'extra_services' => $check['bundle']['extra'],
            'dentist_id'    => $check['dentist_id'],
            'start_at'      => $check['start'],
            'end_at'        => $check['end'],
            'status'        => $data['status'] ?? 'pending',
            'source'        => $data['source'] ?? 'online',
            'is_emergency'  => 0,
            'patient_notes' => $data['patient_notes'] ?? null,
            'staff_notes'   => $data['staff_notes'] ?? null,
            'created_at'    => $now,
            'updated_at'    => $now,
        ]);
        return ['ok' => true, 'id' => $id];
    } finally {
        booking_lock(false);
    }
}

/** Move an existing appointment to a new date/time (and optionally dentist). */
function reschedule_appointment(int $id, ?int $dentistId, string $date, string $time, ?int $earliestTs = null): array
{
    $appt = find_appointment($id);
    if (!$appt || !in_array($appt['status'], ACTIVE_STATUSES, true)) {
        return ['ok' => false, 'error' => t('This appointment can no longer be rescheduled.')];
    }
    booking_lock(true);
    try {
        $check = check_booking(array_merge([(int) $appt['service_id']], extra_service_ids($appt['extra_services'] ?? '')), $dentistId, $date, $time, $id, $earliestTs, false);
        if (!$check['ok']) {
            return $check;
        }
        db_update('appointments', [
            'dentist_id'       => $check['dentist_id'],
            'start_at'         => $check['start'],
            'end_at'           => $check['end'],
            'reminder_24_sent' => 0,
            'reminder_12_sent' => 0,
            'reminder_6_sent'  => 0,
            'reminder_1_sent'  => 0,
            'updated_at'       => now_str(),
        ], 'id = :id', ['id' => $id]);
        return ['ok' => true, 'id' => $id, 'old_start' => $appt['start_at']];
    } finally {
        booking_lock(false);
    }
}

function find_appointment(int $id): ?array
{
    return db_one(
        'SELECT a.*, s.name AS service_name, s.duration_min, s.price, s.category, s.icon, d.name AS dentist_name,
                u.username AS patient_username
         FROM appointments a
         JOIN services s ON s.id = a.service_id
         JOIN dentists d ON d.id = a.dentist_id
         LEFT JOIN users u ON u.id = a.patient_id
         WHERE a.id = ?',
        [$id]
    );
}

function find_appointment_by_code(string $code): ?array
{
    $id = db_val('SELECT id FROM appointments WHERE code = ?', [strtoupper(trim($code))]);
    return $id ? find_appointment((int) $id) : null;
}

function set_status(int $id, string $status, ?string $note = null): void
{
    $data = ['status' => $status, 'updated_at' => now_str()];
    if ($note !== null && $note !== '') {
        $old = (string) db_val('SELECT staff_notes FROM appointments WHERE id = ?', [$id]);
        $data['staff_notes'] = trim($old . "\n[" . date('M j g:i A') . '] ' . $note);
    }
    db_update('appointments', $data, 'id = :id', ['id' => $id]);
}

/** Can a patient still change this appointment themselves? */
function patient_can_change(array $appt): bool
{
    return in_array($appt['status'], ACTIVE_STATUSES, true)
        && strtotime($appt['start_at']) - time() >= CHANGE_CUTOFF_HOURS * 3600;
}

function active_booking_count(?int $patientId, ?string $email): int
{
    $now = now_str();
    if ($patientId) {
        return (int) db_val("SELECT COUNT(*) FROM appointments WHERE patient_id = ? AND status IN ('pending','confirmed') AND start_at >= ?", [$patientId, $now]);
    }
    return (int) db_val("SELECT COUNT(*) FROM appointments WHERE contact_email = ? AND status IN ('pending','confirmed') AND start_at >= ?", [$email, $now]);
}

/**
 * Emergency Appointment Priority.
 * Inserts a confirmed emergency appointment at the requested time for the dentist,
 * then moves every overlapping Pending/Confirmed appointment of that dentist to the
 * next free slot (same day first, then following clinic days) and notifies the patient.
 * Returns ['id' => int, 'moved' => [[appt, new_start], ...], 'unmoved' => [appt, ...]]
 */
function create_emergency(array $data): array
{
    booking_lock(true);
    try {
        $service = find_service((int) $data['service_id']);
        $start = $data['date'] . ' ' . $data['time'] . ':00';
        $end = date('Y-m-d H:i:s', strtotime($start) + (int) $data['duration'] * 60);
        $now = now_str();
        $dentistId = sole_dentist_id();   // emergencies are also handled by Dr. Warren

        $id = db_insert('appointments', [
            'code'          => generate_appointment_code(),
            'manage_token'  => bin2hex(random_bytes(16)),
            'patient_id'    => $data['patient_id'] ?? null,
            'contact_name'  => $data['contact_name'],
            'contact_email' => $data['contact_email'] ?: null,
            'contact_phone' => $data['contact_phone'] ?: null,
            'service_id'    => (int) $service['id'],
            'dentist_id'    => $dentistId,
            'start_at'      => $start,
            'end_at'        => $end,
            'status'        => 'confirmed',
            'source'        => 'emergency',
            'is_emergency'  => 1,
            'patient_notes' => null,
            'staff_notes'   => $data['reason'] ? 'Emergency: ' . $data['reason'] : 'Emergency case',
            'checked_in_at' => !empty($data['checked_in']) ? $now : null,
            'created_at'    => $now,
            'updated_at'    => $now,
        ]);

        $conflicts = db_all(
            "SELECT id FROM appointments WHERE dentist_id = ? AND id <> ? AND is_emergency = 0
             AND status IN ('pending','confirmed') AND start_at < ? AND end_at > ? ORDER BY start_at",
            [$dentistId, $id, $end, $start]
        );

        $moved = [];
        $unmoved = [];
        foreach ($conflicts as $c) {
            $appt = find_appointment((int) $c['id']);
            $newStart = next_free_start($appt, $dentistId, strtotime($end));
            if ($newStart === null) {
                $unmoved[] = $appt;
                continue;
            }
            db_update('appointments', [
                'start_at'         => date('Y-m-d H:i:s', $newStart),
                'end_at'           => date('Y-m-d H:i:s', $newStart + $appt['duration_min'] * 60),
                'reminder_24_sent' => 0,
                'reminder_12_sent' => 0,
                'reminder_6_sent'  => 0,
                'reminder_1_sent'  => 0,
                'updated_at'       => now_str(),
            ], 'id = :id', ['id' => $appt['id']]);
            $moved[] = [$appt, date('Y-m-d H:i:s', $newStart)];
        }
        return ['id' => $id, 'moved' => $moved, 'unmoved' => $unmoved];
    } finally {
        booking_lock(false);
    }
}

/** First free start time for $appt with this dentist at or after $afterTs (searches 14 clinic days). */
function next_free_start(array $appt, int $dentistId, int $afterTs): ?int
{
    $date = date('Y-m-d', $afterTs);
    for ($i = 0; $i < 30; $i++) {
        $slots = available_slots($date, (int) $appt['duration_min'], $dentistId, (int) $appt['id'], max($afterTs, time()));
        if ($slots) {
            return strtotime($date . ' ' . $slots[0]['time']);
        }
        $date = date('Y-m-d', strtotime("$date +1 day"));
    }
    return null;
}

function clinic_days_label(): string
{
    $names = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];
    $days = CLINIC_DAYS;
    sort($days);
    $contiguous = count($days) > 2 && end($days) - $days[0] === count($days) - 1;
    return $contiguous ? $names[$days[0]] . ' – ' . $names[end($days)] : implode(', ', array_map(fn($d) => $names[$d], $days));
}
