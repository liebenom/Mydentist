<?php $layout = $layout ?? 'public'; ?>
<?php if ($layout === 'patient' || $layout === 'admin'): ?>
    </div>
  </main>
</div>
<?php elseif ($layout === 'auth'): ?>
  </div>
  <p class="auth-foot"><i class="bi bi-shield-lock"></i> Your connection is secure. Passwords are stored hashed.</p>
</main>
<?php else: ?>
<footer class="site-footer" id="contact">
  <div class="container">
    <div class="row g-4">
      <div class="col-md-5">
        <div class="d-flex align-items-center gap-2 mb-2">
          <img src="<?= url('assets/img/logo-white.svg') ?>" alt="" width="32" height="32">
          <strong class="fs-5">MyDentist Dental Clinic</strong>
        </div>
        <p class="mb-0 opacity-75"><?= te('Book your dental appointment online, any time. Less waiting, more smiling.') ?></p>
      </div>
      <div class="col-md-4">
        <h6 class="text-uppercase small fw-bold opacity-75"><?= te('Contact') ?></h6>
        <div><i class="bi bi-geo-alt me-2"></i><?= e(CLINIC_ADDRESS) ?></div>
        <div><i class="bi bi-telephone me-2"></i><?= e(CLINIC_PHONE) ?></div>
        <div><i class="bi bi-envelope me-2"></i><?= e(CLINIC_EMAIL) ?></div>
      </div>
      <div class="col-md-3">
        <h6 class="text-uppercase small fw-bold opacity-75"><?= te('Clinic Hours') ?></h6>
        <div><?= e(clinic_days_label_t()) ?></div>
        <div><?= e(date('g:i A', strtotime(CLINIC_OPEN)) . ' – ' . date('g:i A', strtotime(CLINIC_CLOSE))) ?></div>
        <?php if (LUNCH_START): ?><div class="small opacity-75"><?= te('Lunch break') ?> <?= e(date('g:i', strtotime(LUNCH_START)) . '–' . date('g:i A', strtotime(LUNCH_END))) ?></div><?php endif; ?>
      </div>
    </div>
    <div class="border-top border-light border-opacity-25 mt-4 pt-3 small opacity-75 d-flex flex-wrap justify-content-between gap-2">
      <span>&copy; <?= date('Y') ?> MyDentist Dental Clinic. <?= te('All rights reserved.') ?></span>
      <span><?= te('Personal data is handled in accordance with the Data Privacy Act of 2012 (RA 10173).') ?></span>
    </div>
  </div>
</footer>
<?php endif; ?>
<script src="<?= lib('bootstrap.js') ?>"></script>
<script src="<?= url('assets/js/app.js') ?>?v=2"></script>
<?php if (!empty($extraScripts)) echo $extraScripts; ?>
</body>
</html>
