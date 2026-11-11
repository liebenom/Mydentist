<?php
/** Guest appointment page (link from the confirmation email): view QR (GUEST role only), cancel. */
require __DIR__ . '/../includes/bootstrap.php';

$appt = find_appointment_by_code((string) input('code'));
$token = (string) input('token');
if (!$appt || !hash_equals($appt['manage_token'], $token)) {
    http_response_code(404);
    $title = 'Appointment not found';
    $layout = 'public';
    include __DIR__ . '/../includes/header.php';
    echo '<div class="container py-5" style="max-width:640px"><div class="card-md"><div class="card-body-md empty-state"><i class="bi bi-search"></i><h1 class="h5">Appointment not found</h1><p>The link may be incomplete. Please use the link in your confirmation email or contact the clinic.</p><a class="btn btn-primary" href="' . url('guest/book.php') . '">Book an appointment</a></div></div></div>';
    include __DIR__ . '/../includes/footer.php';
    exit;
}
$self = 'guest/manage.php?code=' . urlencode($appt['code']) . '&token=' . $appt['manage_token'];
grant_guest_access($appt);   // token verified: this visitor is the GUEST for this booking
$guestToken = $token;
// The GUEST's private booking history: only bookings that share this secret link key
// (e.g. follow-ups Admin booked by scanning this guest's QR code). Never other people's.
$guestBookings = current_user() ? [] : db_all(
    'SELECT a.*, s.name AS service_name FROM appointments a JOIN services s ON s.id = a.service_id
     WHERE a.manage_token = ? AND a.id <> ? AND a.source = ? ORDER BY a.start_at DESC',
    [$appt['manage_token'], $appt['id'], 'guest']
);
foreach ($guestBookings as $gb) {
    grant_guest_access($gb);
}

if (is_post() && input('action') === 'cancel') {
    csrf_check();
    if (!patient_can_change($appt)) {
        flash('danger', te('This appointment can no longer be cancelled online (less than {n} hours before). Please call the clinic.', ['n' => CHANGE_CUTOFF_HOURS]));
    } else {
        set_status((int) $appt['id'], 'cancelled', 'Cancelled by guest: ' . mb_substr(input('reason'), 0, 200));
        audit('appointment_cancelled', 'Guest cancelled ' . $appt['code'], (int) $appt['id']);
        notify((int) $appt['id'], 'cancelled');
        flash('success', te('Your appointment has been cancelled. A confirmation email has been sent.'));
    }
    redirect($self);
}

$title = t('Your Appointment');
$layout = 'public';
$cardActions = '';
if (patient_can_change($appt)) {
    $cardActions = '<button class="btn btn-outline-danger btn-sm" data-bs-toggle="collapse" data-bs-target="#cancelBox"><i class="bi bi-x-circle"></i> ' . te('Cancel appointment') . '</button>';
}
include __DIR__ . '/../includes/header.php';
?>
<div class="container py-4" style="max-width:960px">
  <?php if (input('new')): ?>
    <div class="text-center mb-4">
      <div class="stat-icon tint-green mx-auto mb-2" style="width:56px;height:56px;border-radius:50%;display:grid;place-items:center;font-size:1.6rem"><i class="bi bi-check-lg"></i></div>
      <h1 class="h3 fw-800"><?= $appt['status'] === 'confirmed' ? te('Appointment Confirmed!') : te('Booking Request Received!') ?></h1>
      <p class="text-muted"><?= can_access_qr($appt, $token) ? te('We sent the details and your QR code to {email}. Save this page or the QR code for check-in.', ['email' => $appt['contact_email']]) : te('We sent the details to {email}. Save this page for check-in.', ['email' => $appt['contact_email']]) ?></p>
      <p class="small text-warning-emphasis bg-warning-subtle d-inline-block rounded-3 px-3 py-2"><i class="bi bi-exclamation-circle"></i> <?= te("Can't find our email? Check your Spam or Promotions folder and mark it as 'Not spam'.") ?></p>
    </div>
  <?php endif; ?>
  <?php include __DIR__ . '/../includes/appointment_card.php'; ?>
  <?php if ($guestBookings): ?>
    <div class="card-md mt-3">
      <div class="card-head"><h2><?= te('Your other bookings') ?></h2></div>
      <div class="card-body-md pt-1">
        <?php foreach ($guestBookings as $gb): ?>
          <a class="appt-item text-reset" href="<?= url('guest/manage.php?code=' . urlencode($gb['code']) . '&token=' . urlencode($gb['manage_token'])) ?>">
            <div class="date-box"><div class="m"><?= e(ldate('M', $gb['start_at'])) ?></div><div class="d"><?= e(ldate('j', $gb['start_at'])) ?></div></div>
            <div class="flex-grow-1 min-w-0"><div class="fw-bold"><?= e(svc_t($gb['service_name'])) ?></div><div class="small text-muted"><?= e(ldate('D', $gb['start_at']) . ' · ' . fmt_time($gb['start_at'])) ?> · <span class="code-ref"><?= e($gb['code']) ?></span></div></div>
            <?= status_badge($gb['status']) ?>
          </a>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>
  <div class="collapse mt-3" id="cancelBox">
    <form method="post" class="card-md card-body-md" data-confirm="<?= te('Cancel this appointment?') ?>">
      <?= csrf_field() ?><input type="hidden" name="action" value="cancel">
      <label class="form-label" for="reason"><?= te('Reason for cancelling (optional)') ?></label>
      <input class="form-control mb-2" id="reason" name="reason" maxlength="200">
      <button class="btn btn-danger btn-sm"><?= te('Yes, cancel my appointment') ?></button>
    </form>
  </div>
  <p class="text-center small text-muted mt-4"><?= te('Need to change the schedule? Cancel and book a new time, or call {phone}.', ['phone' => CLINIC_PHONE]) ?> <a href="<?= url('guest/book.php') ?>"><?= te('Book a new time') ?></a> · <a href="<?= url('register.php') ?>"><?= te('Create an account') ?></a></p>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
