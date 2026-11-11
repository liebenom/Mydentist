<?php
/**
 * Four-step booking wizard shared by patient/book.php and guest/book.php.
 * Expects: $wizardMode ('patient' | 'guest'), $old (posted values), $wizardError (string|null)
 */
$services = active_services();
$dentists = active_dentists();
$old = $old ?? [];
$startStep = (int) ($old['_step'] ?? (!empty($wizardError) ? 3 : 1));
$groups = ['routine' => t('Routine check-ups & care'), 'advanced' => t('Advanced procedures')];
$svcJson = [];
foreach ($services as $s) {
    $svcJson[$s['id']] = ['name' => svc_t($s['name']), 'duration' => (int) $s['duration_min'], 'p' => (float) $s['price']];
}
// Services already picked (after an error, or coming from View Schedules)
$picked = parse_service_ids($old['service_ids'] ?? ($old['service_id'] ?? ''));
$dentJson = [];
$old['dentist_id'] = sole_dentist_id();   // Dr. Warren is always the dentist
foreach ($dentists as $d) {
    $dentJson[$d['id']] = $d['name'];
}
?>
<div class="card-md">
  <div class="card-body-md">
    <div class="stepper" id="stepper">
      <?php foreach (['Service', 'Dentist', 'Schedule', 'Confirm'] as $i => $label): ?>
        <div class="st" data-step-ind="<?= $i + 1 ?>"><div class="c"><?= $i + 1 ?></div><?= te($label) ?></div>
      <?php endforeach; ?>
    </div>

    <?php if (!empty($wizardError)): ?><div class="alert alert-danger"><i class="bi bi-exclamation-triangle"></i> <?= te($wizardError) ?>
      <?php if ($wizardMode === 'guest' && str_contains($wizardError, 'Guest Mode')): ?> <a class="fw-semibold" href="<?= url('login.php?show=1') ?>"><?= te('Log in') ?></a><?php endif; ?></div><?php endif; ?>

    <form method="post" id="bookingForm" novalidate>
      <?= csrf_field() ?>
      <input type="hidden" name="date" id="f_date" value="<?= e($old['date'] ?? '') ?>">
      <input type="hidden" name="time" id="f_time" value="<?= e($old['time'] ?? '') ?>">

      <!-- Step 1: service -->
      <section data-step="1">
        <h2 class="h6 fw-bold mb-1"><?= te('Select Dental Services') ?></h2>
        <p class="small text-muted"><?= te('You can choose more than one service. They are done in one visit, one after the other, and the time needed is added up.') ?></p>
        <?php foreach ($groups as $cat => $label): ?>
          <div class="cat-label"><?= e($label) ?></div>
          <div class="row g-3 mb-2">
            <?php foreach ($services as $s): if ($s['category'] !== $cat) continue; ?>
              <div class="col-sm-6 col-xl-4">
                <label class="choice choice-multi">
                  <input type="checkbox" name="service_ids[]" value="<?= (int) $s['id'] ?>" <?= in_array((int) $s['id'], $picked, true) ? 'checked' : '' ?>>
                  <div class="box">
                    <div class="icon"><i class="bi <?= e($s['icon']) ?>"></i></div>
                    <div class="t"><?= e(svc_t($s['name'])) ?></div>
                    <div class="small text-muted"><?= e(svc_t($s['description'])) ?></div>
                    <div class="meta"><span><i class="bi bi-clock"></i> <?= te('{n} min', ['n' => (int) $s['duration_min']]) ?></span><span class="price"><?= peso($s['price']) ?></span></div>
                  </div>
                </label>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endforeach; ?>
        <div class="svc-total" id="svcTotal" hidden></div>
        <p class="small text-muted mb-0"><i class="bi bi-info-circle"></i> <?= te('Prices are starting rates; the dentist will confirm the final cost after examination. Payment is made at the clinic.') ?></p>
      </section>

      <!-- Step 2: dentist -->
      <section data-step="2" hidden>
        <h2 class="h6 fw-bold mb-1"><?= te('Choose Your Dentist') ?></h2>
        <p class="small text-muted"><?= te('All appointments are with our dentist, Dr. Warren.') ?></p>
        <div class="row g-3">
          <?php foreach ($dentists as $d): ?>
            <div class="col-sm-6 col-xl-4">
              <label class="choice"><input type="radio" name="dentist_id" value="<?= (int) $d['id'] ?>" <?= (int) ($old['dentist_id'] ?? 0) === (int) $d['id'] ? 'checked' : '' ?>>
                <div class="box d-flex gap-3 align-items-center"><div class="avatar"><?= e(initials($d['name'])) ?></div><div><div class="t"><?= e($d['name']) ?></div><div class="small text-muted"><?= e(svc_t($d['specialty'])) ?></div></div></div></label>
            </div>
          <?php endforeach; ?>
        </div>
      </section>

      <!-- Step 3: schedule -->
      <section data-step="3" hidden id="slotPicker">
        <div class="d-flex flex-wrap justify-content-between align-items-end gap-2 mb-2">
          <div><h2 class="h6 fw-bold mb-1"><?= te('Pick a Date & Time') ?></h2><p class="small text-muted mb-0" data-slot-note></p></div>
        </div>
        <div class="day-strip mb-3" data-days></div>
        <div class="time-grid" data-times></div>
      </section>

      <!-- Step 4: confirm -->
      <section data-step="4" hidden>
        <div class="row g-4">
          <div class="col-lg-6">
            <h2 class="h6 fw-bold mb-2"><?= te('Appointment Summary') ?></h2>
            <ul class="summary-list" id="summary"></ul>
          </div>
          <div class="col-lg-6">
            <?php if ($wizardMode === 'guest'): ?>
              <h2 class="h6 fw-bold mb-2"><?= te('Your Details') ?></h2>
              <div class="mb-2"><label class="form-label" for="g_name"><?= te('Full name') ?></label><input class="form-control" id="g_name" name="contact_name" value="<?= e($old['contact_name'] ?? '') ?>" required minlength="3" autocomplete="name"></div>
              <div class="mb-2"><label class="form-label" for="g_email"><?= te('Email (for your confirmation & QR code)') ?></label><input class="form-control" id="g_email" type="email" name="contact_email" value="<?= e($old['contact_email'] ?? '') ?>" required autocomplete="email"></div>
              <div class="mb-2"><label class="form-label" for="g_phone"><?= te('Contact number') ?></label><input class="form-control" id="g_phone" name="contact_phone" value="<?= e($old['contact_phone'] ?? '') ?>" placeholder="+63 9XX XXX XXXX" required autocomplete="tel"></div>
            <?php endif; ?>
            <div class="mb-2"><label class="form-label" for="notes"><?= te('Notes for the dentist') ?> <span class="text-muted fw-normal">(<?= te('optional') ?>)</span></label>
              <textarea class="form-control" id="notes" name="patient_notes" rows="3" maxlength="500" placeholder="<?= te('e.g. tooth sensitivity on the upper left') ?>"><?= e($old['patient_notes'] ?? '') ?></textarea></div>
            <?php if ($wizardMode === 'guest'): ?>
              <div class="form-check small mt-2">
                <input class="form-check-input" type="checkbox" name="privacy" value="1" id="privacy" required <?= !empty($old['privacy']) ? 'checked' : '' ?>>
                <label class="form-check-label text-muted" for="privacy"><?= te('I agree that the clinic may store my details for this appointment (Data Privacy Act of 2012).') ?></label>
              </div>
            <?php endif; ?>
            <div class="alert alert-info small mt-3 mb-0"><i class="bi bi-envelope-check"></i>
              <?= AUTO_CONFIRM ? te('Your appointment is confirmed right away.') : te('Your request will stay pending until the clinic confirms it.') ?>
              <?= $wizardMode === 'guest' ? te("You'll receive an email with your QR code and reminders 24, 12 and 6 hours before.") : te("You'll receive a confirmation email and reminders 24, 12 and 6 hours before.") ?>
              <div class="mt-1"><i class="bi bi-exclamation-circle"></i> <?= te("Can't find our email? Check your Spam or Promotions folder and mark it as 'Not spam'.") ?></div></div>
          </div>
        </div>
      </section>

      <div class="alert alert-warning small mt-3 mb-0" id="stepError" hidden></div>
      <div class="wizard-nav">
        <button type="button" class="btn btn-light border" id="btnBack"><i class="bi bi-chevron-left"></i> <?= te('Back') ?></button>
        <button type="button" class="btn btn-primary px-4" id="btnNext"><?= te('Next Step') ?> <i class="bi bi-chevron-right"></i></button>
        <button type="submit" class="btn btn-primary px-4" id="btnSubmit" hidden><i class="bi bi-calendar-check"></i> <?= te('Confirm Booking') ?></button>
      </div>
    </form>
  </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
  var services = <?= json_encode($svcJson, JSON_UNESCAPED_UNICODE) ?>, dentists = <?= json_encode($dentJson, JSON_UNESCAPED_UNICODE) ?>;
  var L = <?= json_encode(['Services' => t('Services'), 'selected' => t('selected'), 'Total' => t('Total'), 'Service' => t('Service'), 'Duration' => t('Duration'), 'minutes' => t('minutes'), 'Starting rate' => t('Starting rate'), 'Dentist' => t('Dentist'), 'Date' => t('Date'), 'Time' => t('Time'),
    'e1' => t('Please select at least one dental service.'), 'e2' => t('Please choose the dentist.'), 'e3' => t('Please pick a date and an available time.'), 'e4' => t('Please complete the required details.')], JSON_UNESCAPED_UNICODE) ?>;
  var form = document.getElementById('bookingForm');
  var step = <?= max(1, min(4, $startStep)) ?>;
  var err = document.getElementById('stepError');
  var picker = null;
  function val(name) { var el = form.querySelector('input[name="' + name + '"]:checked'); return el ? el.value : ''; }
  // all services ticked, in the order they appear
  function svcIds() { return Array.prototype.map.call(form.querySelectorAll('input[name="service_ids[]"]:checked'), function (c) { return c.value; }); }
  function peso(n) { return '₱' + Math.round(n).toLocaleString('en-US'); }
  function bundle() {
    var ids = svcIds(), names = [], dur = 0, price = 0;
    ids.forEach(function (id) { var s = services[id]; if (s) { names.push(s.name); dur += s.duration; price += s.p; } });
    return { ids: ids, names: names, duration: dur, price: price };
  }
  function renderTotal() {
    var b = bundle(), box = document.getElementById('svcTotal');
    box.hidden = !b.ids.length;
    box.innerHTML = '<i class="bi bi-check2-square"></i> <strong>' + b.ids.length + ' ' + (b.ids.length === 1 ? L['Service'] : L['Services']) + ' ' + L['selected'] + '</strong>'
      + ' <span>· ' + b.duration + ' ' + L['minutes'] + ' · ' + L['Starting rate'] + ' ' + peso(b.price) + '</span>';
  }
  function show(n) {
    step = n;
    form.querySelectorAll('[data-step]').forEach(function (s) { s.hidden = +s.dataset.step !== n; });
    document.querySelectorAll('[data-step-ind]').forEach(function (s) {
      var i = +s.dataset.stepInd; s.classList.toggle('done', i < n); s.classList.toggle('current', i === n);
    });
    document.getElementById('btnBack').style.visibility = n === 1 ? 'hidden' : 'visible';
    document.getElementById('btnNext').hidden = n === 4;
    document.getElementById('btnSubmit').hidden = n !== 4;
    err.hidden = true;
    if (n === 3) {
      if (!picker) {
        picker = SlotPicker({
          root: document.getElementById('slotPicker'),
          serviceInput: function () { return svcIds().join(','); },
          dentistInput: function () { return val('dentist_id') || 0; },
          dateInput: document.getElementById('f_date'), timeInput: document.getElementById('f_time'),
          preselectDate: <?= json_encode($old['date'] ?? '') ?>
        });
      }
      picker.reload();
    }
    if (n === 4) renderSummary();
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }
  function renderSummary() {
    var b = bundle();
    var d = document.getElementById('f_date').value, t = document.getElementById('f_time').value;
    var dt = new Date(d + 'T00:00:00'), I = window.I18N;
    var dateText = d ? I.daysLong[dt.getDay()] + ', ' + I.monthsLong[dt.getMonth()] + ' ' + dt.getDate() + ', ' + dt.getFullYear() : '';
    var rows = [[b.ids.length > 1 ? L['Services'] : L['Service'], b.names.join(' + ')], [L['Duration'], b.duration + ' ' + L['minutes']], [b.ids.length > 1 ? L['Total'] + ' (' + L['Starting rate'] + ')' : L['Starting rate'], peso(b.price)], [L['Dentist'], dentists[val('dentist_id') || 0]],
      [L['Date'], dateText],
      [L['Time'], t ? fmt(t) : '']];
    var esc = function (x) { var el = document.createElement('span'); el.textContent = x == null ? '' : x; return el.innerHTML; };
    document.getElementById('summary').innerHTML = rows.map(function (r) { return '<li><span>' + esc(r[0]) + '</span><span>' + esc(r[1]) + '</span></li>'; }).join('');
  }
  function fmt(t) { var p = t.split(':'), h = +p[0]; return ((h % 12) || 12) + ':' + p[1] + (h >= 12 ? ' PM' : ' AM'); }
  function fail(msg) { err.textContent = msg; err.hidden = false; }
  document.getElementById('btnNext').addEventListener('click', function () {
    if (step === 1 && !svcIds().length) return fail(L.e1);
    if (step === 2 && val('dentist_id') === '') return fail(L.e2);
    if (step === 3 && (!document.getElementById('f_date').value || !document.getElementById('f_time').value)) return fail(L.e3);
    show(step + 1);
  });
  document.getElementById('btnBack').addEventListener('click', function () { if (step > 1) show(step - 1); });
  form.querySelectorAll('input[name="service_ids[]"], input[name=dentist_id]').forEach(function (r) {
    r.addEventListener('change', function () { document.getElementById('f_time').value = ''; renderTotal(); });
  });
  renderTotal();
  form.addEventListener('submit', function (ev) {
    if (!form.checkValidity()) { ev.preventDefault(); ev.stopImmediatePropagation(); form.classList.add('was-validated'); fail(L.e4); }
  }, true);
  var firstDentist = form.querySelector('input[name=dentist_id]'); if (firstDentist && !val('dentist_id')) firstDentist.checked = true;
  show(step);
});
</script>
