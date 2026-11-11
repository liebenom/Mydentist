<?php
require __DIR__ . '/../includes/bootstrap.php';
$user = require_patient();

$appt = find_appointment(input_int('id'));
if (!$appt || (int) $appt['patient_id'] !== (int) $user['id']) {
    flash('danger', 'Appointment not found.');
    redirect('patient/appointments.php');
}
if (!patient_can_change($appt)) {
    flash('warning', te('This appointment can no longer be rescheduled online. Please call the clinic at {phone}.', ['phone' => CLINIC_PHONE]));
    redirect('patient/appointment.php?id=' . $appt['id']);
}

$error = null;
if (is_post()) {
    csrf_check();
    $res = reschedule_appointment((int) $appt['id'], input_int('dentist_id') ?: null, input('date'), input('time'));
    if ($res['ok']) {
        if (!AUTO_CONFIRM && $appt['status'] === 'confirmed') {
            set_status((int) $appt['id'], 'pending', 'Rescheduled by patient; awaiting re-confirmation');
        }
        audit('appointment_rescheduled', 'Patient moved ' . $appt['code'] . ' from ' . $appt['start_at'], (int) $appt['id']);
        notify((int) $appt['id'], 'rescheduled', ['old_start' => $res['old_start']]);
        flash('success', te('Your appointment has been moved. An updated confirmation was sent to your email.'));
        redirect('patient/appointment.php?id=' . $appt['id']);
    }
    $error = $res['error'];
}

$title = t('Reschedule Appointment');
$layout = 'patient';
$active = 'appointments';
include __DIR__ . '/../includes/header.php';
?>
<a href="<?= url('patient/appointment.php?id=' . $appt['id']) ?>" class="btn btn-sm btn-light border mb-3"><i class="bi bi-chevron-left"></i> <?= te('Back') ?></a>
<div class="card-md">
  <div class="card-body-md">
    <div class="d-flex flex-wrap gap-3 align-items-center mb-3">
      <div class="flex-grow-1"><div class="small-caps"><?= te('Current schedule') ?></div>
        <div class="fw-bold"><?= e(svc_t($appt['service_name'])) ?> · <?= e(ldate('D, M j', $appt['start_at']) . ', ' . fmt_time($appt['start_at'])) ?> · <?= e($appt['dentist_name']) ?></div></div>
    </div>
    <?php if ($error): ?><div class="alert alert-danger"><?= te($error) ?></div><?php endif; ?>
    <form method="post" id="rsForm">
      <?= csrf_field() ?>
      <input type="hidden" name="date" id="f_date"><input type="hidden" name="time" id="f_time">
      <div class="row g-2 align-items-end mb-3">
        <div class="col-sm-6"><label class="form-label" for="dentist_id"><?= te('Dentist') ?></label>
          <select class="form-select" name="dentist_id" id="dentist_id">
            <?php foreach (active_dentists() as $d): ?><option value="<?= $d['id'] ?>" <?= (int) $d['id'] === (int) $appt['dentist_id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option><?php endforeach; ?>
          </select></div>
      </div>
      <div id="picker">
        <p class="small text-muted" data-slot-note></p>
        <div class="day-strip mb-3" data-days></div>
        <div class="time-grid" data-times></div>
      </div>
      <?php if (!AUTO_CONFIRM && $appt['status'] === 'confirmed'): ?>
        <div class="alert alert-info small mt-3 mb-0"><i class="bi bi-info-circle"></i> <?= te('The new schedule will be marked pending until the clinic confirms it again.') ?></div>
      <?php endif; ?>
      <div class="wizard-nav"><span></span><button class="btn btn-primary px-4" id="btnSave" disabled><i class="bi bi-check2"></i> <?= te('Confirm New Schedule') ?></button></div>
    </form>
  </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
  var root = document.getElementById('picker');
  var picker = SlotPicker({
    root: root, exclude: <?= (int) $appt['id'] ?>,
    serviceInput: function () { return <?= json_encode(implode(',', array_merge([(int) $appt['service_id']], extra_service_ids($appt['extra_services'] ?? '')))) ?>; },
    dentistInput: function () { return document.getElementById('dentist_id').value; },
    dateInput: document.getElementById('f_date'), timeInput: document.getElementById('f_time'),
    onChange: function (s) { document.getElementById('btnSave').disabled = !(s.date && s.time); }
  });
  document.getElementById('dentist_id').addEventListener('change', function () { picker.reload(); });
  picker.reload();
});
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
