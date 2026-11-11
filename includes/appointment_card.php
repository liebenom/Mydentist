<?php
/**
 * Appointment detail card. Expects $appt (from find_appointment()).
 * Optional: $cardActions (HTML for the actions area), $guestToken (guest pages only)
 * Reference number and QR code exist only for GUEST bookings (no account). Registered
 * patients never see them: they simply give their name at the front desk.
 */
$isPast = strtotime($appt['end_at']) < time();
$isGuest = is_guest_booking($appt);
$showQr = can_access_qr($appt, $guestToken ?? null);
?>
<div class="card-md">
  <div class="card-body-md">
    <div class="row g-4 align-items-center">
      <div class="col-md-7">
        <div class="d-flex align-items-center gap-2 flex-wrap mb-2">
          <?php if ($isGuest): ?><span class="small-caps"><?= te('Reference') ?></span><span class="code-ref fs-5"><?= e($appt['code']) ?></span><?php endif; ?>
          <?= status_badge($appt['status']) ?>
          <?php if ($appt['is_emergency']): ?><span class="tag-emergency"><i class="bi bi-lightning-charge"></i> <?= te('Emergency') ?></span><?php endif; ?>
        </div>
        <h2 class="h4 fw-800 mb-1"><?= e(svc_t($appt['service_name'])) ?></h2>
        <p class="text-muted mb-3"><?= e($appt['dentist_name']) ?></p>
        <div class="row g-2">
          <div class="col-sm-6"><div class="info-tile"><i class="bi bi-calendar-event"></i><div><div class="k"><?= te('Date') ?></div><div class="v"><?= e(ldate('D, M j, Y', $appt['start_at'])) ?></div></div></div></div>
          <div class="col-sm-6"><div class="info-tile"><i class="bi bi-clock"></i><div><div class="k"><?= te('Time') ?></div><div class="v"><?= e(fmt_time($appt['start_at']) . ' – ' . fmt_time($appt['end_at'])) ?></div></div></div></div>
          <div class="col-sm-6"><div class="info-tile"><i class="bi bi-person"></i><div><div class="k"><?= te('Patient') ?></div><div class="v"><?= e($appt['contact_name']) ?></div></div></div></div>
          <div class="col-sm-6"><div class="info-tile"><i class="bi bi-cash-coin"></i><div><div class="k"><?= te('Starting rate') ?></div><div class="v"><?= peso($appt['price']) ?> <span class="small text-muted fw-normal"><?= te('pay at clinic') ?></span></div></div></div></div>
        </div>
        <?php if ($appt['patient_notes']): ?><div class="small mt-3"><span class="text-muted"><?= te('Your notes:') ?></span> <?= nl2br(e($appt['patient_notes'])) ?></div><?php endif; ?>
        <?php if ($appt['status'] === 'pending'): ?>
          <div class="alert alert-warning small mt-3 mb-0"><i class="bi bi-hourglass-split"></i> <?= te("Waiting for the clinic to confirm. You'll get an email once it's confirmed.") ?></div>
        <?php elseif ($appt['status'] === 'confirmed' && !$isPast): ?>
          <div class="alert alert-info small mt-3 mb-0"><i class="bi bi-info-circle"></i> <?= $showQr ? te('Please arrive 10 minutes early and show your QR code at the front desk.') : ($isGuest ? te('Please arrive 10 minutes early and give your reference number at the front desk.') : te('Please arrive 10 minutes early and give your name at the front desk.')) ?></div>
        <?php endif; ?>
        <?php if (!empty($cardActions)): ?><div class="d-flex flex-wrap gap-2 mt-3"><?= $cardActions ?></div><?php endif; ?>
      </div>
      <div class="col-md-5 text-center">
        <?php if ($showQr): $qs = 'code=' . urlencode($appt['code']) . '&token=' . urlencode($appt['manage_token']); ?>
          <div class="qr-box"><img src="<?= url('qr.php?' . $qs) ?>" alt="<?= te('QR code') ?> <?= e($appt['code']) ?>"></div>
          <div class="small text-muted mt-2"><?= te('Scan at the front desk for check-in') ?></div>
          <a class="btn btn-sm btn-soft mt-2" href="<?= url('qr.php?' . $qs . '&download=1') ?>"><i class="bi bi-download"></i> <?= te('Save QR code') ?></a>
        <?php elseif ($isGuest): ?>
          <div class="info-tile d-inline-flex text-start"><i class="bi bi-ticket-perforated"></i><div><div class="k"><?= te('Reference number') ?></div><div class="v code-ref fs-5"><?= e($appt['code']) ?></div></div></div>
          <div class="small text-muted mt-2"><?= te('Give this number at the front desk for check-in.') ?></div>
        <?php else: ?>
          <div class="info-tile d-inline-flex text-start"><i class="bi bi-person-check"></i><div><div class="k"><?= te('Check-in') ?></div><div class="v"><?= te('Give your name at the front desk.') ?></div></div></div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
