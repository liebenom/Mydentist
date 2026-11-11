<?php
/** Email Log: every notification the system generated (sent or saved in demo mode). */
require __DIR__ . '/../includes/bootstrap.php';
$user = require_staff();

$view = input('view');
if ($view !== '') {
    if (!preg_match('/^[\w\-]+\.html$/', $view) || !is_file(mail_dir() . '/' . $view)) {
        http_response_code(404);
        exit('Not found');
    }
    header('Content-Type: text/html; charset=utf-8');
    header("Content-Security-Policy: default-src 'none'; img-src data: https:; style-src 'unsafe-inline'");
    readfile(mail_dir() . '/' . $view);
    exit;
}

// Admin and staff can send a confirmation or reminder email to a patient from here
$canSend = is_staff();
if (is_post() && input('do') === 'send_patient_email') {
    csrf_check();
    if (!$canSend) {
        flash('danger', 'Only clinic staff and administrators can send emails to patients.');
        redirect('admin/emails.php');
    }
    $appt = find_appointment(input_int('appointment_id'));
    $kind = input('kind') === 'reminder' ? 'reminder' : 'confirmation';
    $msg = mb_substr(trim(input('message')), 0, 500);
    if (!$appt || !in_array($appt['status'], ACTIVE_STATUSES, true) || strtotime($appt['start_at']) < time()) {
        flash('danger', 'Please choose an upcoming appointment.');
    } elseif (empty($appt['contact_email'])) {
        flash('danger', 'This patient has no email address. Add one in the appointment record first.');
    } elseif (!empty($appt['is_demo'])) {
        flash('danger', 'Demo appointments never send emails.');
    } elseif ($kind === 'confirmation' && $appt['status'] !== 'confirmed') {
        flash('danger', 'This appointment is still pending. Confirm it first, then send the confirmation email.');
    } else {
        $ok = notify((int) $appt['id'], $kind === 'reminder' ? 'reminder' : 'confirmed', ['note' => $msg]);
        if ($ok) {
            audit('email_sent', ucfirst($kind) . ' email sent to ' . $appt['contact_name'] . ' <' . $appt['contact_email'] . '> for ' . fmt_datetime($appt['start_at']), (int) $appt['id']);
            flash('success', ucfirst($kind) . ' email ' . (mail_cfg('enabled') ? 'sent to' : 'saved for') . ' <strong>' . e($appt['contact_name']) . '</strong> (' . e($appt['contact_email']) . ').');
        } else {
            flash('danger', 'The email could not be sent: ' . e(mail_last_error()));
        }
    }
    redirect('admin/emails.php');
}
$upcoming = $canSend ? db_all(
    "SELECT a.*, s.name AS service_name FROM appointments a JOIN services s ON s.id = a.service_id
     WHERE a.status IN ('pending','confirmed') AND a.start_at >= ? AND a.is_demo = 0 ORDER BY a.start_at" . sql_limit(300),
    [now_str()]
) : [];

$mails = mail_log_list(150);
$title = 'Email Log';
$subtitle = mail_cfg('enabled') ? 'Emails are being sent through ' . mail_cfg('host') : 'Demo mode: emails are saved here instead of being sent';
$layout = 'admin';
$active = 'emails';
include __DIR__ . '/../includes/header.php';
?>
<?php if (!mail_cfg('enabled')): ?>
  <div class="alert alert-info small"><i class="bi bi-info-circle"></i> <?= te('Email sending is off. Confirmations and reminders are saved below instead of being sent.') ?> <?php if (is_admin()): ?><a href="<?= url('admin/settings.php#email-settings') ?>"><?= te('Turn on real emails in Settings') ?></a><?php endif; ?></div>
<?php endif; ?>
<?php if ($canSend): ?>
<form method="post" class="card-md mb-3" id="sendPatientEmail">
  <?= csrf_field() ?><input type="hidden" name="do" value="send_patient_email">
  <div class="card-head"><h2><i class="bi bi-send text-primary"></i> Send Email to a Patient</h2></div>
  <div class="card-body-md">
    <?php if (!mail_cfg('enabled')): ?>
      <div class="alert alert-warning small"><i class="bi bi-exclamation-triangle"></i> <strong>Real emails are off</strong>, so emails sent here are only saved in the log below and do not reach the patient yet.
        <?php if (is_admin()): ?><a href="<?= url('admin/settings.php#email-settings') ?>">Turn on real emails in Settings</a> (Gmail address + App Password).<?php else: ?>Ask the administrator to turn on real emails in Settings.<?php endif; ?></div>
    <?php endif; ?>
    <div class="row g-3">
      <div class="col-lg-6">
        <label class="form-label" for="apptSearch">Appointment</label>
        <input class="form-control form-control-sm mb-2" id="apptSearch" placeholder="Search name, email or date…" autocomplete="off">
        <select class="form-select" name="appointment_id" id="apptSel" size="6" required>
          <?php foreach ($upcoming as $u): ?>
            <option value="<?= (int) $u['id'] ?>" data-status="<?= e($u['status']) ?>" data-email="<?= e((string) $u['contact_email']) ?>"
              data-search="<?= e(strtolower($u['contact_name'] . ' ' . $u['contact_email'] . ' ' . fmt_date($u['start_at'], 'M j D'))) ?>">
              <?= e(fmt_date($u['start_at'], 'D, M j') . ' ' . fmt_time($u['start_at']) . ' – ' . $u['contact_name'] . ' – ' . $u['service_name'] . ($u['status'] === 'pending' ? ' (pending)' : '') . ($u['contact_email'] ? '' : ' (no email)')) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <?php if (!$upcoming): ?><div class="small text-muted mt-1">No upcoming appointments.</div><?php endif; ?>
      </div>
      <div class="col-lg-6">
        <div class="form-label">Type of email</div>
        <div class="d-flex gap-3 mb-2">
          <div class="form-check"><input class="form-check-input" type="radio" name="kind" value="confirmation" id="kConf" checked><label class="form-check-label" for="kConf">Confirmation</label></div>
          <div class="form-check"><input class="form-check-input" type="radio" name="kind" value="reminder" id="kRem"><label class="form-check-label" for="kRem">Reminder</label></div>
        </div>
        <label class="form-label" for="emMsg">Message <span class="text-muted fw-normal small">(optional, added to the email)</span></label>
        <textarea class="form-control mb-2" id="emMsg" name="message" rows="2" maxlength="500" placeholder="e.g. Please bring your previous X-ray."></textarea>
        <div class="small mb-2" id="emTo"></div>
        <button class="btn btn-primary" id="emSend" disabled><i class="bi bi-send"></i> Send Email</button>
      </div>
    </div>
  </div>
</form>
<script>
document.addEventListener('DOMContentLoaded', function () {
  var sel = document.getElementById('apptSel'), q = document.getElementById('apptSearch'), btn = document.getElementById('emSend'), to = document.getElementById('emTo');
  function update() {
    var o = sel.options[sel.selectedIndex], kind = document.querySelector('input[name=kind]:checked').value, msg = '', ok = false;
    if (!o) msg = '<span class="text-muted">Choose an appointment.</span>';
    else if (!o.dataset.email) msg = '<span class="text-danger">This patient has no email address.</span>';
    else if (kind === 'confirmation' && o.dataset.status !== 'confirmed') msg = '<span class="text-danger">Still pending – confirm the appointment first, or send a reminder.</span>';
    else { ok = true; msg = '<span class="text-muted">Will be sent to</span> <strong>' + o.dataset.email.replace(/[<>&"]/g, '') + '</strong>'; }
    to.innerHTML = msg; btn.disabled = !ok;
  }
  q.addEventListener('input', function () {
    var v = q.value.trim().toLowerCase();
    Array.prototype.forEach.call(sel.options, function (o) { o.hidden = v !== '' && o.dataset.search.indexOf(v) === -1; });
  });
  sel.addEventListener('change', update);
  document.querySelectorAll('input[name=kind]').forEach(function (r) { r.addEventListener('change', update); });
  update();
});
</script>
<?php endif; ?>
<div class="row g-3">
  <div class="col-lg-5">
    <div class="card-md">
      <div class="list-group list-group-flush" style="max-height:72vh;overflow:auto">
        <?php foreach ($mails as $i => $m): ?>
          <a href="?view=<?= e($m['file']) ?>" target="mailview" class="list-group-item list-group-item-action py-2 <?= $i === 0 ? 'active-mail' : '' ?>" onclick="document.querySelectorAll('.active-mail').forEach(function(x){x.classList.remove('active-mail')});this.classList.add('active-mail')">
            <div class="d-flex justify-content-between gap-2"><span class="fw-semibold small text-truncate"><?= e($m['subject'] ?? '') ?></span>
              <?php if (!empty($m['error'])): ?><span class="badge text-bg-danger" title="<?= e($m['error']) ?>">failed</span><?php elseif (!empty($m['sent'])): ?><span class="badge text-bg-success">sent</span><?php else: ?><span class="badge text-bg-light">saved</span><?php endif; ?></div>
            <div class="small text-muted text-truncate"><?= e(($m['name'] ?? '') . ' <' . ($m['to'] ?? '') . '>') ?> · <?= e(fmt_datetime($m['date'] ?? '')) ?></div>
          </a>
        <?php endforeach; ?>
        <?php if (!$mails): ?><div class="empty-state"><i class="bi bi-envelope"></i>No emails yet. Book an appointment to generate one.</div><?php endif; ?>
      </div>
    </div>
  </div>
  <div class="col-lg-7">
    <div class="card-md overflow-hidden"><iframe name="mailview" title="Email preview" style="width:100%;height:72vh;border:0;background:#e3f1f7" <?= $mails ? 'src="?view=' . e($mails[0]['file']) . '"' : '' ?>></iframe></div>
  </div>
</div>
<style>.active-mail{background:var(--md-teal-50)!important;border-left:3px solid var(--md-teal)!important}</style>
<?php include __DIR__ . '/../includes/footer.php'; ?>
