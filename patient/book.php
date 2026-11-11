<?php
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/booking_submit.php';
$user = require_patient();

$old = [];
$wizardError = null;
if (is_post()) {
    csrf_check();
    $res = handle_booking_submit('patient', $user);
    if ($res['ok']) {
        $appt = find_appointment($res['id']);
        flash('success', ($appt['status'] === 'confirmed' ? te('Appointment confirmed!') : te('Appointment request sent!')) . ' ' . te('A confirmation email has been sent to {email}.', ['email' => $user['email']]) . ' ' . te("Can't find our email? Check your Spam or Promotions folder and mark it as 'Not spam'."));
        redirect('patient/appointment.php?id=' . $res['id']);
    }
    $wizardError = $res['error'];
    $old = $_POST;
    $old['_step'] = $res['step'] ?? 3;
} elseif (input_int('service_id')) {
    // Coming from "View Schedules": jump straight to the schedule step
    $old = ['service_id' => input_int('service_id'), 'dentist_id' => input_int('dentist_id'), 'date' => input('date'), 'time' => input('time'), '_step' => input('time') ? 4 : 3];
}

$title = t('Book an Appointment');
$subtitle = t('Follow the steps to schedule your visit');
$layout = 'patient';
$active = 'book';
$wizardMode = 'patient';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/booking_wizard.php';
include __DIR__ . '/../includes/footer.php';
