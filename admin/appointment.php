<?php
/** Appointment detail for staff: status actions, reschedule, notes, activity history. */
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/admin_actions.php';
$user = require_staff();
handle_admin_action_post();

$appt = find_appointment(input_int('id'));
if (!$appt) {
    flash('danger', 'Appointment not found.');
    redirect('admin/appointments.php');
}
$self = 'admin/appointment.php?id=' . $appt['id'];
$rsError = null;

if (is_post()) {
    csrf_check();
    if (input('do') === 'reschedule') {
        $res = reschedule_appointment((int) $appt['id'], input_int('dentist_id') ?: null, input('date'), input('time'), time());
        if ($res['ok']) {
            audit('appointment_rescheduled', $appt['code'] . ' moved from ' . fmt_datetime($appt['start_at']) . ' to ' . input('date') . ' ' . input('time'), (int) $appt['id']);
            notify((int) $appt['id'], 'rescheduled', ['old_start' => $res['old_start'], 'note' => input('note')]);
            flash('success', 'Appointment rescheduled and the patient was notified by email.');
            redirect($self);
        }
        $rsError = $res['error'];
    } elseif (input('do') === 'notes') {
        db_update('appointments', ['staff_notes' => mb_substr(input('staff_notes'), 0, 2000), 'updated_at' => now_str()], 'id = :id', ['id' => $appt['id']]);
        audit('notes_updated', 'Staff notes updated for ' . $appt['code'], (int) $appt['id']);
        flash('success', 'Notes saved.');
        redirect($self);
    } elseif (input('do') === 'resend') {
        $type = $appt['status'] === 'confirmed' ? 'confirmed' : ($appt['status'] === 'pending' ? 'received' : null);
        if ($type && notify((int) $appt['id'], $type)) {
            flash('success', 'Email sent again to ' . e($appt['contact_email']) . '.');
        } else {
            flash('warning', 'No email address on file, or the appointment is no longer active.');
        }
        redirect($self);
    }
}

$history = db_all('SELECT l.*, u.full_name FROM audit_logs l LEFT JOIN users u ON u.id = l.user_id WHERE l.appointment_id = ? ORDER BY l.id DESC', [$appt['id']]);
$patientStats = $appt['patient_id'] ? db_one(
    "SELECT COUNT(*) AS total, SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS done, SUM(CASE WHEN status = 'no_show' THEN 1 ELSE 0 END) AS noshow FROM appointments WHERE patient_id = ?",
    [$appt['patient_id']]
) : null;
$isActive = in_array($appt['status'], ACTIVE_STATUSES, true);

$title = is_guest_booking($appt) ? 'Appointment ' . $appt['code'] : 'Appointment · ' . $appt['contact_name'];
$subtitle = $appt['contact_name'] . ' · ' . fmt_datetime($appt['start_at']);
$layout = 'admin';
$active = 'appointments';
include __DIR__ . '/../includes/header.php';
?>
<a href="<?= url('admin/appointments.php') ?>" class="btn btn-sm btn-light border mb-3"><i class="bi bi-chevron-left"></i> Appointment Records</a>
<div class="row g-3">
  <div class="col-lg-8">
    <div class="card-md mb-3">
      <div class="card-body-md">
        <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
          <?php if (is_guest_booking($appt)): ?><span class="code-ref fs-5"><?= e($appt['code']) ?></span><?php endif; ?><?= status_badge($appt['status']) ?>
          <?php if ($appt['is_emergency']): ?><span class="tag-emergency"><i class="bi bi-lightning-charge"></i> Emergency</span><?php endif; ?>
          <?php if ($appt['checked_in_at']): ?><span class="status-badge status-completed"><i class="bi bi-person-check"></i> Checked in <?= e(fmt_time($appt['checked_in_at'])) ?></span><?php endif; ?>
          <?php if (!empty($appt['is_demo'])): ?><span class="badge tint-violet">Demo record</span><?php endif; ?>
          <span class="badge text-bg-light ms-auto"><?= e(source_label($appt['source'])) ?></span>
        </div>
        <div class="row g-2 mb-3">
          <?php foreach ([
              ['person', 'Patient', $appt['contact_name'] . ($appt['patient_id'] ? '' : ' (guest)')],
              ['telephone', 'Phone', $appt['contact_phone'] ?: '—'],
              ['envelope', 'Email', $appt['contact_email'] ?: '—'],
              ['clipboard2-pulse', 'Service', $appt['service_name'] . ' · ' . $appt['duration_min'] . ' min · ' . peso($appt['price'])],
              ['person-badge', 'Dentist', $appt['dentist_name']],
              ['calendar-event', 'Schedule', fmt_date($appt['start_at'], 'D, M j, Y') . ' · ' . fmt_time($appt['start_at']) . '–' . fmt_time($appt['end_at'])],
          ] as [$i, $k, $v]): ?>
            <div class="col-md-6"><div class="info-tile"><i class="bi bi-<?= $i ?>"></i><div class="min-w-0"><div class="k"><?= $k ?></div><div class="v text-break"><?= e($v) ?></div></div></div></div>
          <?php endforeach; ?>
        </div>
        <?php if ($appt['patient_notes']): ?><div class="alert alert-light border small"><strong>Patient notes:</strong> <?= nl2br(e($appt['patient_notes'])) ?></div><?php endif; ?>

        <div class="d-flex flex-wrap gap-2">
          <?= action_button($appt, 'approve', 'Approve', 'btn-primary', 'check-lg') ?>
          <?= action_button($appt, 'checkin', 'Check In', 'btn-soft', 'person-check') ?>
          <?= action_button($appt, 'complete', 'Mark Completed', 'btn-success', 'check2-all') ?>
          <?= action_button($appt, 'no_show', 'No-Show', 'btn-light border', 'person-x', '', 'Mark this patient as a no-show?') ?>
          <?php if ($isActive): ?>
            <button class="btn btn-sm btn-light border" data-bs-toggle="collapse" data-bs-target="#reschedule"><i class="bi bi-arrow-repeat"></i> Reschedule</button>
            <button class="btn btn-sm btn-outline-danger" data-bs-toggle="collapse" data-bs-target="#cancelBox"><i class="bi bi-x-circle"></i> <?= $appt['status'] === 'pending' ? 'Decline / Cancel' : 'Cancel' ?></button>
            <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="do" value="resend"><button class="btn btn-sm btn-light border"><i class="bi bi-envelope"></i> Resend Email</button></form>
          <?php endif; ?>
          <?= action_button($appt, 'reopen', 'Reopen as Confirmed', 'btn-light border', 'arrow-counterclockwise', '', 'Set this appointment back to Confirmed?') ?>
        </div>

        <div class="collapse mt-3" id="cancelBox">
          <form method="post" class="border rounded-3 p-3" data-confirm="Cancel this appointment? The patient will be notified by email.">
            <?= csrf_field() ?><input type="hidden" name="appt_id" value="<?= $appt['id'] ?>">
            <input type="hidden" name="appt_action" value="<?= $appt['status'] === 'pending' ? 'decline' : 'cancel' ?>">
            <label class="form-label" for="note">Message to the patient (included in the email)</label>
            <input class="form-control mb-2" id="note" name="note" maxlength="300" placeholder="e.g. The dentist is unavailable on this date. Please choose another schedule.">
            <button class="btn btn-sm btn-danger">Confirm <?= $appt['status'] === 'pending' ? 'Decline' : 'Cancellation' ?></button>
          </form>
        </div>
      </div>
    </div>

    <?php if ($isActive): ?>
    <div class="card-md mb-3 collapse <?= $rsError ? 'show' : '' ?>" id="reschedule">
      <div class="card-head"><h2><?= te('Reschedule') ?></h2></div>
      <div class="card-body-md">
        <?php if ($rsError): ?><div class="alert alert-danger"><?= te($rsError) ?></div><?php endif; ?>
        <form method="post">
          <?= csrf_field() ?><input type="hidden" name="do" value="reschedule">
          <input type="hidden" name="date" id="f_date"><input type="hidden" name="time" id="f_time">
          <div class="row g-2 mb-3">
            <div class="col-md-6"><label class="form-label" for="dentist_id"><?= te('Dentist') ?></label>
              <select class="form-select" name="dentist_id" id="dentist_id">
                <?php foreach (active_dentists() as $d): ?><option value="<?= $d['id'] ?>" <?= (int) $d['id'] === (int) $appt['dentist_id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option><?php endforeach; ?></select></div>
          </div>
          <div id="picker"><p class="small text-muted" data-slot-note></p><div class="day-strip mb-3" data-days></div><div class="time-grid" data-times></div></div>
          <label class="form-label mt-3" for="rs_note"><?= te('Message to the patient (optional)') ?></label>
          <input class="form-control mb-3" id="rs_note" name="note" maxlength="300">
          <button class="btn btn-primary" id="btnRs" disabled><i class="bi bi-check2"></i> <?= te('Save New Schedule & Notify Patient') ?></button>
        </form>
      </div>
    </div>
    <?php endif; ?>

    <form method="post" class="card-md">
      <div class="card-head"><h2>Staff Notes</h2><span class="small text-muted ms-auto">Visible to staff only</span></div>
      <div class="card-body-md">
        <?= csrf_field() ?><input type="hidden" name="do" value="notes">
        <textarea class="form-control mb-2" name="staff_notes" rows="4" maxlength="2000"><?= e($appt['staff_notes']) ?></textarea>
        <button class="btn btn-sm btn-primary">Save Notes</button>
      </div>
    </form>
  </div>

  <div class="col-lg-4">
    <div class="card-md mb-3 text-center">
      <div class="card-body-md">
        <?php if (is_guest_booking($appt)): ?>
          <div class="small-caps mb-1">Guest reference number</div>
          <div class="code-ref fs-4"><?= e($appt['code']) ?></div>
          <div class="small text-muted mt-1"><i class="bi bi-qr-code"></i> Guest booking: the guest holds the QR code</div>
        <?php else: ?>
          <div class="small-caps mb-1">Registered patient</div>
          <div class="fw-bold"><?= e($appt['contact_name']) ?></div>
          <div class="small text-muted mt-1">Has an account, so no reference number or QR code. Check in by name.</div>
        <?php endif; ?>
        <div class="small text-muted mt-2">Booked <?= e(fmt_datetime($appt['created_at'])) ?></div>
      </div>
    </div>
    <?php if ($patientStats): ?>
      <div class="card-md mb-3"><div class="card-head"><h2>Patient History</h2></div>
        <div class="card-body-md small">
          <div class="d-flex justify-content-between py-1"><span class="text-muted">Total appointments</span><strong><?= (int) $patientStats['total'] ?></strong></div>
          <div class="d-flex justify-content-between py-1"><span class="text-muted">Completed</span><strong><?= (int) $patientStats['done'] ?></strong></div>
          <div class="d-flex justify-content-between py-1"><span class="text-muted">No-shows</span><strong><?= (int) $patientStats['noshow'] ?></strong></div>
          <a class="d-block mt-2 fw-semibold" href="<?= url('admin/appointments.php?q=' . urlencode($appt['contact_email'] ?? $appt['contact_name'])) ?>">View all their appointments →</a>
        </div></div>
    <?php endif; ?>
    <div class="card-md"><div class="card-head"><h2>Activity</h2></div>
      <div class="card-body-md pt-1">
        <?php if (!$history): ?><div class="small text-muted">No staff activity yet.</div><?php endif; ?>
        <?php foreach ($history as $h): ?>
          <div class="small py-2 border-bottom"><div class="fw-semibold"><?= e(ucwords(str_replace(['appointment_', '_'], ['', ' '], $h['action']))) ?></div>
            <div class="text-muted"><?= e(fmt_datetime($h['created_at'])) ?> · <?= e($h['full_name'] ?? 'Patient / system') ?></div></div>
        <?php endforeach; ?>
      </div></div>
  </div>
</div>
<?php if ($isActive): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
  var box = document.getElementById('reschedule'), picker = null;
  function init() {
    if (picker) return;
    picker = SlotPicker({
      root: document.getElementById('picker'), exclude: <?= (int) $appt['id'] ?>, mode: 'staff',
      serviceInput: function () { return <?= json_encode(implode(',', array_merge([(int) $appt['service_id']], extra_service_ids($appt['extra_services'] ?? '')))) ?>; },
      dentistInput: function () { return document.getElementById('dentist_id').value; },
      dateInput: document.getElementById('f_date'), timeInput: document.getElementById('f_time'),
      onChange: function (s) { document.getElementById('btnRs').disabled = !(s.date && s.time); }
    });
    picker.reload();
    document.getElementById('dentist_id').addEventListener('change', function () { picker.reload(); });
  }
  box.addEventListener('show.bs.collapse', init);
  if (location.hash === '#reschedule' || box.classList.contains('show')) { new bootstrap.Collapse(box, { toggle: false }).show(); init(); }
});
</script>
<?php endif; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
