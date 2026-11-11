<?php
/**
 * Server-side handling of the booking wizard (patient and guest).
 * Returns ['ok' => true, 'id' => int] or ['ok' => false, 'error' => string].
 */
function handle_booking_submit(string $mode, ?array $user): array
{
    $serviceIds = parse_service_ids($_POST['service_ids'] ?? input('service_id'));   // one or more services
    $dentistId = input_int('dentist_id');
    $date = input('date');
    $time = input('time');
    $notes = mb_substr(input('patient_notes'), 0, 500);

    if ($mode === 'guest') {
        $name = input('contact_name');
        $email = strtolower(input('contact_email'));
        $phone = input('contact_phone');
        if (mb_strlen($name) < 3) return ['ok' => false, 'step' => 4, 'error' => t('Please enter your full name.')];
        if (!valid_email($email)) return ['ok' => false, 'step' => 4, 'error' => t('Please enter a valid email address so we can send your QR code.')];
        if (!valid_phone($phone)) return ['ok' => false, 'step' => 4, 'error' => t('Please enter a valid contact number.')];
        if (empty($_POST['privacy'])) return ['ok' => false, 'step' => 4, 'error' => t('Please agree to the data privacy notice.')];
        // Guest Mode (with QR code) is only for people WITHOUT an account.
        // Someone who already has an account must log in and book from their account.
        if (db_val('SELECT COUNT(*) FROM users WHERE LOWER(email) = ?', [$email])) {
            return ['ok' => false, 'step' => 4, 'error' => t('This email already has an account. Please log in to book with your account (Guest Mode is only for people without an account).')];
        }
        // GUEST booking: owned only through its secret link (code + token), never attached
        // to an account by email, so no account can see a guest's appointment.
        $patientId = null;
    } else {
        $name = $user['full_name'];
        $email = $user['email'];
        $phone = $user['phone'];
        $patientId = (int) $user['id'];
    }

    if (active_booking_count($patientId, $email) >= MAX_ACTIVE_PER_PATIENT) {
        return ['ok' => false, 'error' => t('You already have {n} upcoming appointments. Please attend or cancel one before booking another.', ['n' => MAX_ACTIVE_PER_PATIENT])];
    }

    $res = create_appointment([
        'service_ids' => $serviceIds, 'service_id' => $serviceIds[0] ?? 0, 'dentist_id' => $dentistId, 'date' => $date, 'time' => $time,
        'contact_name' => $name, 'contact_email' => $email, 'contact_phone' => $phone,
        'patient_id' => $patientId, 'source' => $mode === 'guest' ? 'guest' : 'online',
        'status' => AUTO_CONFIRM ? 'confirmed' : 'pending', 'patient_notes' => $notes ?: null,
    ]);
    if ($res['ok']) {
        notify($res['id'], AUTO_CONFIRM ? 'confirmed' : 'received');
    }
    return $res;
}
