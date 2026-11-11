<?php
/** Staff booking for walk-in and phone patients (confirmed immediately). */
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/patient_fields.php';
$user = require_front_desk();   // STAFF only (front-desk work)

$error = null;
if (is_post()) {
    csrf_check();
    [$who, $error] = resolve_patient_fields();
    if (!$error) {
        $res = create_appointment(array_merge($who, [
            'service_ids' => parse_service_ids($_POST['service_ids'] ?? input('service_id')), 'service_id' => 0, 'dentist_id' => input_int('dentist_id'),
            'date' => input('date'), 'time' => input('time'),
            'source' => 'walk_in', 'status' => 'confirmed',
            'staff_notes' => input('staff_notes') ?: null,
        ]), time());
        if ($res['ok']) {
            if (!empty($_POST['checkin'])) {
                q('UPDATE appointments SET checked_in_at = ? WHERE id = ?', [now_str(), $res['id']]);
            }
            $appt = find_appointment($res['id']);
            audit('appointment_created', 'Walk-in/phone booking ' . $appt['code'] . ' for ' . $appt['contact_name'] . ' on ' . fmt_datetime($appt['start_at']), $res['id']);
            notify($res['id'], 'confirmed');
            flash('success', 'Booked <strong>' . e($appt['contact_name']) . '</strong> for ' . e(fmt_datetime($appt['start_at'])) . ' with ' . e($appt['dentist_name']) . '.');
            redirect('admin/appointment.php?id=' . $res['id']);
        }
        $error = $res['error'];
    }
}

$title = 'Walk-in / Phone Booking';
$subtitle = 'Book an appointment on behalf of a patient';
$layout = 'admin';
$active = 'book';
include __DIR__ . '/../includes/header.php';
?>
<form method="post" class="row g-3">
  <?= csrf_field() ?>
  <input type="hidden" name="date" id="f_date" value="<?= e(input('date')) ?>"><input type="hidden" name="time" id="f_time" value="<?= e(input('time')) ?>">
  <div class="col-lg-5">
    <div class="card-md mb-3"><div class="card-head"><h2>1. Patient</h2></div><div class="card-body-md"><?php include __DIR__ . '/../includes/patient_picker.php'; ?></div></div>
    <div class="card-md"><div class="card-head"><h2>2. Service & Dentist</h2></div>
      <div class="card-body-md">
        <div class="form-label">Services <span class="text-muted fw-normal small">(tick one or more)</span></div>
        <?php $pickedSvc = parse_service_ids($_POST['service_ids'] ?? input('service_id')); ?>
        <div class="svc-checks mb-2" id="svcChecks">
          <?php foreach (active_services() as $i => $s): ?>
            <div class="form-check"><input class="form-check-input" type="checkbox" name="service_ids[]" value="<?= $s['id'] ?>" id="svc<?= $s['id'] ?>" <?= in_array((int) $s['id'], $pickedSvc, true) || (!$pickedSvc && $i === 0) ? 'checked' : '' ?>>
              <label class="form-check-label d-flex justify-content-between gap-2" for="svc<?= $s['id'] ?>"><span><?= e($s['name']) ?></span><span class="text-muted small text-nowrap"><?= (int) $s['duration_min'] ?> min</span></label></div>
          <?php endforeach; ?>
        </div>
        <div class="small text-muted mb-2" id="svcSum"></div>
        <label class="form-label" for="dentist_id"><?= te('Dentist') ?></label>
        <select class="form-select mb-2" name="dentist_id" id="dentist_id">
          <?php foreach (active_dentists() as $d): ?><option value="<?= $d['id'] ?>" <?= input_int('dentist_id') === (int) $d['id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option><?php endforeach; ?></select>
        <label class="form-label" for="staff_notes">Staff notes</label>
        <textarea class="form-control" name="staff_notes" id="staff_notes" rows="2"><?= e(input('staff_notes')) ?></textarea>
      </div></div>
  </div>
  <div class="col-lg-7">
    <div class="card-md">
      <div class="card-head"><h2>3. <?= te('Date & Time') ?></h2></div>
      <div class="card-body-md">
        <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
        <div id="picker"><p class="small text-muted" data-slot-note></p><div class="day-strip mb-3" data-days></div><div class="time-grid" data-times></div></div>
        <div class="form-check mt-3"><input class="form-check-input" type="checkbox" name="checkin" value="1" id="checkin"><label class="form-check-label" for="checkin">Patient is at the clinic now (mark as checked in)</label></div>
        <div class="alert alert-info small mt-3"><i class="bi bi-info-circle"></i> Staff bookings are <strong>confirmed</strong> immediately. If an email is given, the patient receives the confirmation with their reference number. For urgent cases that must override existing bookings, use <a href="<?= url('admin/emergency.php') ?>">Emergency Priority</a>.</div>
        <button class="btn btn-primary w-100" id="btnBook" disabled><i class="bi bi-calendar-check"></i> Confirm Booking</button>
      </div>
    </div>
  </div>
</form>
<script>
document.addEventListener('DOMContentLoaded', function () {
  function svcIds() { return Array.prototype.map.call(document.querySelectorAll('#svcChecks input:checked'), function (c) { return c.value; }); }
  function svcSum() {
    var n = 0, boxes = document.querySelectorAll('#svcChecks input:checked');
    boxes.forEach(function (c) { n += parseInt(c.closest('.form-check').querySelector('.text-muted').textContent, 10) || 0; });
    document.getElementById('svcSum').textContent = boxes.length ? boxes.length + ' selected · ' + n + ' min in total' : 'Tick at least one service.';
  }
  svcSum();
  var picker = SlotPicker({
    root: document.getElementById('picker'), mode: 'staff',
    serviceInput: function () { return svcIds().join(','); },
    dentistInput: function () { return document.getElementById('dentist_id').value; },
    dateInput: document.getElementById('f_date'), timeInput: document.getElementById('f_time'),
    onChange: function (s) { document.getElementById('btnBook').disabled = !(s.date && s.time); }
  });
  picker.reload();
  document.getElementById('dentist_id').addEventListener('change', function () { picker.reload(); });
  document.querySelectorAll('#svcChecks input').forEach(function (c) { c.addEventListener('change', function () { svcSum(); document.getElementById('f_time').value = ''; picker.reload(); }); });
});
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
