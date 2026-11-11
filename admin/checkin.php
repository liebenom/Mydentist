<?php
/**
 * Check-in (STAFF). Registered patients are found by NAME (they have no reference number).
 * Guests give their reference number or show their QR code: type it, or scan it with the
 * camera / USB scanner. Staff never generate or see QR images (QR is GUEST-only):
 * a scan (via=qr) is accepted only for Guest Mode bookings.
 */
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/admin_actions.php';
$user = require_front_desk();   // STAFF only (front-desk work)
handle_admin_action_post();

$raw = trim((string) input('code'));
$code = strtoupper($raw);
$nameMatches = null;
if (preg_match('/MD-[A-Z0-9]{6}/', $code, $m)) {
    $code = $m[0];   // accepts a full URL or extra text around the code
} elseif ($raw !== '' && empty($_GET['id'])) {
    // search today's appointments by patient name
    $nameMatches = db_all(
        "SELECT a.*, s.name AS service_name FROM appointments a JOIN services s ON s.id = a.service_id
         WHERE a.contact_name LIKE ? AND a.start_at >= ? AND a.start_at < ? AND a.status IN ('pending','confirmed','completed') ORDER BY a.start_at",
        ['%' . $raw . '%', date('Y-m-d 00:00:00'), date('Y-m-d 00:00:00', strtotime('+1 day'))]
    );
    $code = '';
}
$appt = $code ? find_appointment_by_code($code) : (input_int('id') ? find_appointment(input_int('id')) : null);
if (!$code && $appt) {
    $code = $appt['code'];
}
$viaQr = input('via') === 'qr';
$qrRejected = false;
if ($appt && $viaQr && !is_guest_booking($appt)) {
    // A QR scan can only belong to a Guest Mode booking; anything else is not a valid guest QR
    audit('qr_scan_rejected', 'Scanned code ' . $appt['code'] . ' is not a guest booking', (int) $appt['id']);
    $qrRejected = true;
    $appt = null;
}
$todayCount = (int) db_val('SELECT COUNT(*) FROM appointments WHERE checked_in_at >= ?', [date('Y-m-d 00:00:00')]);
$waiting = db_all(
    "SELECT a.*, s.name AS service_name FROM appointments a JOIN services s ON s.id = a.service_id
     WHERE a.checked_in_at >= ? AND a.status = 'confirmed' ORDER BY a.start_at",
    [date('Y-m-d 00:00:00')]
);

$title = 'Check-in';
$subtitle = 'Search a patient by name, or enter / scan a guest\'s reference';
$layout = 'admin';
$active = 'checkin';
include __DIR__ . '/../includes/header.php';
?>
<div class="row g-3">
  <div class="col-lg-5">
    <div class="card-md mb-3">
      <div class="card-head"><h2><i class="bi bi-person-check text-primary"></i> Find Appointment</h2></div>
      <div class="card-body-md">
        <div id="qr-reader" class="mb-3" hidden></div>
        <button type="button" class="btn btn-primary w-100 mb-3" id="btnCam"><i class="bi bi-camera"></i> Scan Guest QR Code</button>
        <form method="get" class="d-flex gap-2">
          <input class="form-control code-ref" name="code" id="codeInput" value="<?= e($nameMatches !== null ? $raw : ($appt && !is_guest_booking($appt) ? $appt['contact_name'] : $code)) ?>" placeholder="Patient name or guest code MD-XXXXXX" autocomplete="off" autofocus aria-label="Patient name or guest reference">
          <button class="btn btn-dark">Find</button>
        </form>
        <p class="small text-muted mt-2 mb-0">Registered patients: type their name. Guests: type their reference number or scan their QR code (camera or USB scanner; click the box first). The camera needs <code>localhost</code> or HTTPS.</p>
      </div>
    </div>
    <div class="card-md">
      <div class="card-head"><h2>Checked In Today</h2><span class="badge text-bg-light ms-auto"><?= $todayCount ?></span></div>
      <div class="card-body-md pt-1">
        <?php if (!$waiting): ?><div class="small text-muted">No patients waiting.</div><?php endif; ?>
        <?php foreach ($waiting as $w): ?>
          <div class="appt-item py-2"><div class="small fw-bold text-muted" style="width:60px"><?= e(fmt_time($w['start_at'])) ?></div>
            <div class="flex-grow-1 small"><a class="fw-semibold text-reset" href="<?= url('admin/appointment.php?id=' . $w['id']) ?>"><?= e($w['contact_name']) ?></a><div class="text-muted"><?= e($w['service_name']) ?> · in at <?= e(fmt_time($w['checked_in_at'])) ?></div></div>
            <?= action_button($w, 'complete', 'Done', 'btn-success', 'check2-all', 'admin/checkin.php') ?></div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
  <div class="col-lg-7">
    <?php if ($nameMatches !== null): ?>
      <div class="card-md"><div class="card-head"><h2>Today's appointments matching “<?= e($raw) ?>”</h2></div>
        <div class="card-body-md pt-1">
          <?php if (!$nameMatches): ?><div class="empty-state"><i class="bi bi-search"></i>No appointment today for that name.</div><?php endif; ?>
          <?php foreach ($nameMatches as $nm): ?>
            <a class="appt-item text-reset" href="<?= url('admin/checkin.php?id=' . $nm['id']) ?>">
              <div class="small fw-bold text-muted" style="width:62px"><?= e(fmt_time($nm['start_at'])) ?></div>
              <div class="flex-grow-1"><div class="fw-semibold"><?= e($nm['contact_name']) ?></div><div class="small text-muted"><?= e($nm['service_name']) ?> · <?= is_guest_booking($nm) ? 'Guest' : 'Registered patient' ?></div></div>
              <?= $nm['checked_in_at'] ? '<span class="status-badge status-completed"><i class="bi bi-person-check"></i> In</span>' : status_badge($nm['status']) ?>
            </a>
          <?php endforeach; ?>
        </div></div>
    <?php elseif ($qrRejected): ?>
      <div class="card-md"><div class="card-body-md empty-state"><i class="bi bi-shield-x text-danger"></i><div class="fw-bold text-body">Not a guest QR code</div>QR codes are issued only for guest bookings. Ask the patient for their reference number and type it in the box instead.</div></div>
    <?php elseif ($code && !$appt): ?>
      <div class="card-md"><div class="card-body-md empty-state"><i class="bi bi-x-octagon text-danger"></i><div class="fw-bold text-body">No appointment found for <?= e($code) ?></div>Check the code and try again.</div></div>
    <?php elseif ($appt):
        $isToday = date('Y-m-d', strtotime($appt['start_at'])) === date('Y-m-d');
        $back = 'admin/checkin.php?id=' . (int) $appt['id']; ?>
      <div class="card-md">
        <div class="card-body-md">
          <div class="d-flex flex-wrap align-items-center gap-2 mb-3"><?php if (is_guest_booking($appt)): ?><span class="code-ref fs-5"><?= e($appt['code']) ?></span><span class="badge text-bg-light">Guest</span><?php else: ?><span class="badge text-bg-light">Registered patient</span><?php endif; ?><?= status_badge($appt['status']) ?></div>
          <div class="d-flex align-items-center gap-3 mb-3"><div class="avatar" style="width:52px;height:52px;font-size:1rem"><?= e(initials($appt['contact_name'])) ?></div>
            <div><div class="fs-5 fw-800"><?= e($appt['contact_name']) ?></div><div class="text-muted small"><?= e($appt['contact_phone']) ?> · <?= e($appt['contact_email']) ?></div></div></div>
          <ul class="summary-list mb-3">
            <li><span>Service</span><span><?= e($appt['service_name']) ?> (<?= (int) $appt['duration_min'] ?> min)</span></li>
            <li><span>Dentist</span><span><?= e($appt['dentist_name']) ?></span></li>
            <li><span>Schedule</span><span><?= e(fmt_date($appt['start_at'], 'D, M j, Y') . ' · ' . fmt_time($appt['start_at'])) ?></span></li>
            <?php if ($appt['patient_notes']): ?><li><span>Patient notes</span><span><?= e($appt['patient_notes']) ?></span></li><?php endif; ?>
          </ul>
          <?php if ($appt['checked_in_at']): ?>
            <div class="alert alert-success"><i class="bi bi-person-check"></i> Already checked in at <?= e(fmt_datetime($appt['checked_in_at'])) ?>.</div>
          <?php elseif (!in_array($appt['status'], ACTIVE_STATUSES, true)): ?>
            <div class="alert alert-danger"><i class="bi bi-exclamation-octagon"></i> This appointment is <?= e(status_label($appt['status'])) ?> and cannot be checked in.</div>
          <?php elseif (!$isToday): ?>
            <div class="alert alert-warning"><i class="bi bi-calendar-x"></i> This appointment is scheduled for <strong><?= e(fmt_date($appt['start_at'], 'l, M j')) ?></strong>, not today.</div>
          <?php elseif ($appt['status'] === 'pending'): ?>
            <div class="alert alert-warning"><i class="bi bi-hourglass-split"></i> This booking is still pending. Checking in will also confirm it.</div>
          <?php endif; ?>
          <div class="d-flex flex-wrap gap-2">
            <?php if (!$appt['checked_in_at']) echo action_button($appt, 'checkin', 'Check In Patient', 'btn-primary btn-lg', 'person-check', $back); ?>
            <?= action_button($appt, 'complete', 'Mark Completed', 'btn-success', 'check2-all', $back) ?>
            <a class="btn btn-light border" href="<?= url('admin/appointment.php?id=' . $appt['id']) ?>">Open Record</a>
          </div>
        </div>
      </div>
    <?php else: ?>
      <div class="card-md"><div class="card-body-md empty-state py-5"><i class="bi bi-qr-code"></i><div class="fw-bold text-body mb-1">Ready to scan</div>Type a patient's name, or a guest's reference number / QR code, to pull up today's appointment.</div></div>
    <?php endif; ?>
  </div>
</div>
<?php
$extraScripts = '<script src="' . lib('html5-qrcode') . '"></script>' . <<<'JS'
<script>
document.getElementById('btnCam').addEventListener('click', function () {
  var btn = this, box = document.getElementById('qr-reader');
  if (!window.Html5Qrcode) { alert('The scanner library could not load. Check the internet connection, or type the code instead.'); return; }
  box.hidden = false; btn.disabled = true; btn.innerHTML = '<i class="bi bi-camera-video"></i> Point the camera at the QR code';
  var scanner = new Html5Qrcode('qr-reader');
  scanner.start({ facingMode: 'environment' }, { fps: 10, qrbox: 220 }, function (text) {
    scanner.stop();
    var m = (text || '').toUpperCase().match(/MD-[A-Z0-9]{6}/);
    location.href = '?via=qr&code=' + encodeURIComponent(m ? m[0] : text);
  }).catch(function (err) {
    box.hidden = true; btn.disabled = false; btn.innerHTML = '<i class="bi bi-camera"></i> Scan Guest QR Code';
    alert('Camera not available: ' + err + '\nUse the code box instead.');
  });
});
</script>
JS;
include __DIR__ . '/../includes/footer.php';
