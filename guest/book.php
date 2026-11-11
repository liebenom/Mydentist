<?php
/** Guest Mode: book without an account. A QR code is issued for check-in. */
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/booking_submit.php';

$user = current_user();
if ($user && !is_staff($user)) {
    redirect('patient/book.php');
}

$old = [];
$wizardError = null;
if (is_post()) {
    csrf_check();
    $res = handle_booking_submit('guest', null);
    if ($res['ok']) {
        $appt = find_appointment($res['id']);
        redirect('guest/manage.php?code=' . urlencode($appt['code']) . '&token=' . $appt['manage_token'] . '&new=1');
    }
    $wizardError = $res['error'];
    $old = $_POST;
    $old['_step'] = $res['step'] ?? 3;
}

$title = t('Book as Guest');
$layout = 'public';
$wizardMode = 'guest';
include __DIR__ . '/../includes/header.php';
?>
<div class="container py-4" style="max-width:1080px">
  <div class="d-flex flex-wrap justify-content-between align-items-end gap-2 mb-3">
    <div>
      <div class="small-caps"><?= te('Guest Mode') ?></div>
      <h1 class="h3 fw-800 mb-1"><?= te('Book an Appointment') ?></h1>
      <p class="text-muted mb-0"><?= te("No account needed. You'll get a QR code by email for quick check-in at the clinic.") ?></p>
    </div>
    <a href="<?= url('register.php') ?>" class="btn btn-soft btn-sm"><i class="bi bi-person-plus"></i> <?= te('Create an account instead') ?></a>
  </div>
  <?php include __DIR__ . '/../includes/booking_wizard.php'; ?>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
