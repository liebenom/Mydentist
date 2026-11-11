<?php
/**
 * Emergency Appointment Priority.
 * Staff decide that a case is an emergency; the system places it at the chosen time,
 * overriding the regular schedule, moves conflicting bookings to the next free slot,
 * notifies those patients and records everything in the audit log.
 */
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/patient_fields.php';
$user = require_front_desk();   // STAFF only (front-desk work)

$error = null;
$result = $_SESSION['emergency_result'] ?? null;
unset($_SESSION['emergency_result']);

// Preview conflicts (AJAX) before saving
if (input('preview') === '1') {
    header('Content-Type: application/json');
    $start = input('date') . ' ' . input('time') . ':00';
    $end = date('Y-m-d H:i:s', strtotime($start) + max(5, input_int('duration')) * 60);
    $rows = db_all(
        "SELECT a.code, a.contact_name, a.start_at, s.name AS service FROM appointments a JOIN services s ON s.id = a.service_id
         WHERE a.dentist_id = ? AND a.status IN ('pending','confirmed') AND a.start_at < ? AND a.end_at > ? ORDER BY a.start_at",
        [input_int('dentist_id'), $end, $start]
    );
    echo json_encode(array_map(fn($r) => ['code' => $r['code'], 'name' => $r['contact_name'], 'time' => fmt_time($r['start_at']), 'service' => $r['service']], $rows));
    exit;
}

if (is_post()) {
    csrf_check();
    [$who, $error] = resolve_patient_fields();
    $service = find_service(input_int('service_id'));
    $dentist = find_dentist(input_int('dentist_id'));
    $date = input('date');
    $time = input('time');
    $duration = input_int('duration');
    if (!$error && (!$service || !$dentist)) $error = 'Please choose the service and the dentist who will attend to the emergency.';
    if (!$error && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^\d{2}:\d{2}$/', $time))) $error = 'Please enter the date and time.';
    if (!$error && ($duration < 5 || $duration > 240)) $error = 'Duration must be between 5 and 240 minutes.';
    if (!$error && strtotime("$date $time") < time() - 3600) $error = 'Emergency time cannot be in the past.';
    if (!$error) {
        $res = create_emergency(array_merge($who, [
            'service_id' => $service['id'], 'dentist_id' => $dentist['id'], 'date' => $date, 'time' => $time,
            'duration' => $duration, 'reason' => mb_substr(input('reason'), 0, 300), 'checked_in' => !empty($_POST['checkin']),
        ]));
        $appt = find_appointment($res['id']);
        audit('emergency_priority', 'Emergency for ' . $appt['contact_name'] . ' at ' . fmt_datetime($appt['start_at']) . ' with ' . $dentist['name']
            . '. Reason: ' . (input('reason') ?: 'n/a') . '. Moved ' . count($res['moved']) . ' appointment(s).', $res['id']);
        $summary = ['id' => $res['id'], 'moved' => [], 'unmoved' => []];
        foreach ($res['moved'] as [$old, $newStart]) {
            audit('appointment_moved_emergency', $old['code'] . ' moved from ' . fmt_datetime($old['start_at']) . ' to ' . fmt_datetime($newStart), (int) $old['id']);
            notify((int) $old['id'], 'emergency_moved', ['old_start' => $old['start_at']]);
            $summary['moved'][] = ['id' => $old['id'], 'name' => $old['contact_name'], 'code' => $old['code'], 'from' => $old['start_at'], 'to' => $newStart, 'emailed' => (bool) $old['contact_email']];
        }
        foreach ($res['unmoved'] as $old) {
            $summary['unmoved'][] = ['id' => $old['id'], 'name' => $old['contact_name'], 'code' => $old['code'], 'from' => $old['start_at']];
        }
        if ($appt['contact_email']) {
            notify($res['id'], 'confirmed');
        }
        $_SESSION['emergency_result'] = $summary;
        redirect('admin/emergency.php');
    }
}

$recent = db_all(
    'SELECT a.*, s.name AS service_name, d.name AS dentist_name FROM appointments a JOIN services s ON s.id = a.service_id JOIN dentists d ON d.id = a.dentist_id
     WHERE a.is_emergency = 1 ORDER BY a.created_at DESC' . sql_limit(8)
);
$defaultTime = date('H:i', (int) (ceil(time() / 300) * 300));

$title = 'Emergency Appointment Priority';
$subtitle = 'Accommodate urgent dental cases by overriding the regular schedule';
$layout = 'admin';
$active = 'emergency';
include __DIR__ . '/../includes/header.php';
?>
<?php if ($result): $ea = find_appointment((int) $result['id']); ?>
  <div class="card-md mb-3 border-danger-subtle">
    <div class="card-body-md">
      <div class="d-flex align-items-center gap-2 mb-2"><span class="tag-emergency"><i class="bi bi-lightning-charge"></i> Emergency scheduled</span>
        <a class="fw-bold" href="<?= url('admin/appointment.php?id=' . $ea['id']) ?>"><?= e($ea['contact_name']) ?> · <?= e(fmt_datetime($ea['start_at'])) ?> · <?= e($ea['dentist_name']) ?></a></div>
      <?php if (!$result['moved'] && !$result['unmoved']): ?>
        <p class="mb-0 small text-muted">No existing appointments were affected.</p>
      <?php endif; ?>
      <?php if ($result['moved']): ?>
        <p class="small mb-1 fw-semibold">Moved <?= count($result['moved']) ?> appointment(s) to the dentist's next free slot:</p>
        <ul class="small mb-2">
          <?php foreach ($result['moved'] as $m): ?><li><a href="<?= url('admin/appointment.php?id=' . $m['id']) ?>"><?= e($m['name']) ?></a> (<?= e($m['code']) ?>): <?= e(fmt_datetime($m['from'])) ?> → <strong><?= e(fmt_datetime($m['to'])) ?></strong> <?= $m['emailed'] ? '<span class="text-success">· emailed</span>' : '<span class="text-danger">· no email on file, please call</span>' ?></li><?php endforeach; ?>
        </ul>
      <?php endif; ?>
      <?php if ($result['unmoved']): ?>
        <div class="alert alert-warning small mb-0">Could not find a free slot for: <?php foreach ($result['unmoved'] as $m): ?><a href="<?= url('admin/appointment.php?id=' . $m['id']) ?>"><?= e($m['name']) ?></a> <?php endforeach; ?>. Please reschedule them manually.</div>
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>

<div class="row g-3">
  <div class="col-xl-8">
    <form method="post" class="card-md" id="emgForm">
      <div class="card-head"><h2><i class="bi bi-lightning-charge text-danger"></i> New Emergency Case</h2></div>
      <div class="card-body-md">
        <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
        <?= csrf_field() ?>
        <div class="row g-3">
          <div class="col-md-6"><?php include __DIR__ . '/../includes/patient_picker.php'; ?></div>
          <div class="col-md-6">
            <label class="form-label" for="service_id">Treatment</label>
            <select class="form-select mb-2" name="service_id" id="service_id" required>
              <?php foreach (active_services() as $s): ?><option value="<?= $s['id'] ?>" data-duration="<?= (int) $s['duration_min'] ?>" <?= input_int('service_id') === (int) $s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?>
            </select>
            <label class="form-label" for="dentist_id">Dentist attending</label>
            <select class="form-select mb-2" name="dentist_id" id="dentist_id" required>
              <?php foreach (active_dentists() as $d): ?><option value="<?= $d['id'] ?>" <?= input_int('dentist_id') === (int) $d['id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option><?php endforeach; ?>
            </select>
            <div class="row g-2 mb-2">
              <div class="col-5"><label class="form-label" for="date">Date</label><input class="form-control" type="date" name="date" id="date" value="<?= e(input('date') ?: date('Y-m-d')) ?>" min="<?= date('Y-m-d') ?>" required></div>
              <div class="col-4"><label class="form-label" for="time">Start</label><input class="form-control" type="time" name="time" id="time" value="<?= e(input('time') ?: $defaultTime) ?>" required></div>
              <div class="col-3"><label class="form-label" for="duration">Minutes</label><input class="form-control" type="number" name="duration" id="duration" min="5" max="240" step="5" value="<?= e((string) (input_int('duration') ?: 30)) ?>" required></div>
            </div>
            <label class="form-label" for="reason">Reason / symptoms</label>
            <input class="form-control mb-2" name="reason" id="reason" maxlength="300" value="<?= e(input('reason')) ?>" placeholder="e.g. severe toothache, swelling, dental trauma">
            <div class="form-check"><input class="form-check-input" type="checkbox" name="checkin" value="1" id="checkin" checked><label class="form-check-label" for="checkin">Patient is already at the clinic</label></div>
          </div>
        </div>
        <div class="alert alert-warning small mt-3 mb-3" id="conflicts"><span class="spinner-border spinner-border-sm"></span> Checking affected appointments…</div>
        <button class="btn btn-danger" data-confirm="Prioritize this emergency? Affected appointments will be moved and the patients notified."><i class="bi bi-lightning-charge"></i> Prioritize Emergency</button>
      </div>
    </form>
  </div>
  <div class="col-xl-4">
    <div class="card-md mb-3"><div class="card-head"><h2>How it works</h2></div>
      <div class="card-body-md small text-muted">
        <ol class="ps-3 mb-0">
          <li class="mb-1">Staff decide that the case is an emergency. The system does not diagnose.</li>
          <li class="mb-1">The emergency is booked as <strong>Confirmed</strong> at the chosen time, even if the slot is taken.</li>
          <li class="mb-1">Overlapping bookings of that dentist move to the next free slot, same day first.</li>
          <li>Affected patients get an email; every change is written to the audit log.</li>
        </ol>
      </div></div>
    <div class="card-md"><div class="card-head"><h2>Recent Emergencies</h2></div>
      <div class="card-body-md pt-1">
        <?php if (!$recent): ?><div class="small text-muted">None yet.</div><?php endif; ?>
        <?php foreach ($recent as $a): ?>
          <a class="appt-item text-reset py-2" href="<?= url('admin/appointment.php?id=' . $a['id']) ?>"><div class="flex-grow-1 small"><div class="fw-semibold"><?= e($a['contact_name']) ?></div><div class="text-muted"><?= e(fmt_datetime($a['start_at'])) ?> · <?= e($a['dentist_name']) ?></div></div><?= status_badge($a['status']) ?></a>
        <?php endforeach; ?>
      </div></div>
  </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
  var f = document.getElementById('emgForm'), box = document.getElementById('conflicts');
  var svc = document.getElementById('service_id'), dur = document.getElementById('duration');
  svc.addEventListener('change', function () { dur.value = svc.selectedOptions[0].dataset.duration; check(); });
  <?php if (!is_post()): ?>dur.value = svc.selectedOptions[0].dataset.duration;<?php endif; ?>
  function esc(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }
  function check() {
    var q = new URLSearchParams({ preview: 1, dentist_id: f.dentist_id.value, date: f.date.value, time: f.time.value, duration: dur.value });
    fetch('?' + q).then(function (r) { return r.json(); }).then(function (rows) {
      box.className = 'alert small mt-3 mb-3 ' + (rows.length ? 'alert-warning' : 'alert-success');
      box.innerHTML = rows.length
        ? '<strong><i class="bi bi-exclamation-triangle"></i> ' + rows.length + ' appointment(s) will be moved:</strong><ul class="mb-0 mt-1">' + rows.map(function (r) { return '<li>' + esc(r.time + ' – ' + r.name + ' (' + r.service + ')') + '</li>'; }).join('') + '</ul>'
        : '<i class="bi bi-check-circle"></i> No conflicts. The dentist is free at this time.';
    });
  }
  ['dentist_id', 'date', 'time', 'duration'].forEach(function (id) { document.getElementById(id).addEventListener('change', check); });
  check();
});
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
