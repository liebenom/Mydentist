<?php
require __DIR__ . '/../includes/bootstrap.php';
$user = require_patient();

$appt = find_appointment(input_int('id'));
if (!$appt || (int) $appt['patient_id'] !== (int) $user['id']) {
    flash('danger', 'Appointment not found.');
    redirect('patient/appointments.php');
}

if (is_post() && input('action') === 'cancel') {
    csrf_check();
    if (!patient_can_change($appt)) {
        flash('danger', te('This appointment can no longer be cancelled online (less than {n} hours before). Please call the clinic.', ['n' => CHANGE_CUTOFF_HOURS]));
    } else {
        set_status((int) $appt['id'], 'cancelled', 'Cancelled by patient: ' . mb_substr(input('reason'), 0, 200));
        audit('appointment_cancelled', 'Patient cancelled ' . $appt['code'], (int) $appt['id']);
        notify((int) $appt['id'], 'cancelled');
        flash('success', te('Your appointment has been cancelled. A confirmation email has been sent.'));
    }
    redirect('patient/appointment.php?id=' . $appt['id']);
}

$title = t('Appointment') . ' · ' . ldate('M j, Y', $appt['start_at']);
$layout = 'patient';
$active = 'appointments';
$cardActions = '';
if (patient_can_change($appt)) {
    $cardActions = '<a class="btn btn-primary btn-sm" href="' . url('patient/reschedule.php?id=' . $appt['id']) . '"><i class="bi bi-arrow-repeat"></i> ' . te('Reschedule') . '</a>'
        . '<button class="btn btn-outline-danger btn-sm" data-bs-toggle="collapse" data-bs-target="#cancel"><i class="bi bi-x-circle"></i> ' . te('Cancel appointment') . '</button>';
} elseif (in_array($appt['status'], ACTIVE_STATUSES, true)) {
    $cardActions = '<span class="small text-muted"><i class="bi bi-info-circle"></i> ' . te('Changes within {n} hours of the appointment must be made by calling {phone}.', ['n' => CHANGE_CUTOFF_HOURS, 'phone' => CLINIC_PHONE]) . '</span>';
}
include __DIR__ . '/../includes/header.php';
?>
<a href="<?= url('patient/appointments.php') ?>" class="btn btn-sm btn-light border mb-3"><i class="bi bi-chevron-left"></i> <?= te('My Appointments') ?></a>
<?php include __DIR__ . '/../includes/appointment_card.php'; ?>
<div class="collapse mt-3 <?= isset($_GET['cancel']) ? 'show' : '' ?>" id="cancel">
  <form method="post" class="card-md card-body-md" data-confirm="<?= te('Cancel this appointment?') ?>">
    <?= csrf_field() ?><input type="hidden" name="action" value="cancel">
    <h3 class="h6 fw-bold"><?= te('Cancel appointment') ?></h3>
    <label class="form-label" for="reason"><?= te('Reason (optional)') ?></label>
    <input class="form-control mb-2" id="reason" name="reason" maxlength="200" placeholder="<?= te('e.g. schedule conflict') ?>">
    <button class="btn btn-danger btn-sm"><?= te('Yes, cancel my appointment') ?></button>
  </form>
</div>
<script>if (location.hash === '#cancel') document.addEventListener('DOMContentLoaded', function () { var c = document.getElementById('cancel'); if (c) c.classList.add('show'); });</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
