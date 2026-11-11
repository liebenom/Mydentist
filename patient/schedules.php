<?php
/** View Schedules: browse open slots for the coming week before booking. */
require __DIR__ . '/../includes/bootstrap.php';
$user = require_patient();

$services = active_services();
$service = find_service(input_int('service_id')) ?: ($services[0] ?? null);
$dentistId = input_int('dentist_id') ?: null;
$offset = max(0, min(8, input_int('week')));

$days = [];
$date = date('Y-m-d', strtotime('+' . ($offset * 7) . ' days'));
$limit = date('Y-m-d', strtotime('+' . BOOKING_WINDOW_DAYS . ' days'));
while (count($days) < 6 && $date <= $limit) {
    if (is_clinic_day($date)) {
        $days[$date] = $service ? available_slots($date, (int) $service['duration_min'], $dentistId) : [];
    }
    $date = date('Y-m-d', strtotime("$date +1 day"));
}

$title = t('View Schedules');
$subtitle = t('Open appointment slots for the coming days');
$layout = 'patient';
$active = 'schedules';
include __DIR__ . '/../includes/header.php';
?>
<form class="card-md card-body-md mb-3" method="get">
  <div class="row g-2 align-items-end">
    <div class="col-md-5"><label class="form-label" for="service_id"><?= te('Service') ?></label>
      <select class="form-select" name="service_id" id="service_id" onchange="this.form.submit()">
        <?php foreach ($services as $s): ?><option value="<?= $s['id'] ?>" <?= $service && (int) $s['id'] === (int) $service['id'] ? 'selected' : '' ?>><?= e(svc_t($s['name'])) ?> (<?= te('{n} min', ['n' => (int) $s['duration_min']]) ?>)</option><?php endforeach; ?>
      </select></div>
    <div class="col-md-4"><label class="form-label" for="dentist_id"><?= te('Dentist') ?></label>
      <select class="form-select" name="dentist_id" id="dentist_id" onchange="this.form.submit()">
        <?php foreach (active_dentists() as $d): ?><option value="<?= $d['id'] ?>" <?= (int) $d['id'] === (int) $dentistId ? 'selected' : '' ?>><?= e($d['name']) ?></option><?php endforeach; ?>
      </select></div>
    <div class="col-md-3 d-flex gap-2">
      <a class="btn btn-light border flex-fill <?= $offset === 0 ? 'disabled' : '' ?>" href="?<?= e(http_build_query(['service_id' => $service['id'] ?? 0, 'dentist_id' => $dentistId, 'week' => $offset - 1])) ?>"><i class="bi bi-chevron-left"></i> <?= te('Prev') ?></a>
      <a class="btn btn-light border flex-fill <?= $date > $limit ? 'disabled' : '' ?>" href="?<?= e(http_build_query(['service_id' => $service['id'] ?? 0, 'dentist_id' => $dentistId, 'week' => $offset + 1])) ?>"><?= te('Next') ?> <i class="bi bi-chevron-right"></i></a>
    </div>
  </div>
</form>

<div class="row g-3">
  <?php foreach ($days as $d => $slots): ?>
    <div class="col-md-6 col-xl-4">
      <div class="card-md h-100">
        <div class="card-head justify-content-between">
          <h3><?= e(ldate('l', $d)) ?> <span class="text-muted fw-normal"><?= e(ldate('M j', $d)) ?></span></h3>
          <span class="status-badge <?= $slots ? 'status-confirmed' : 'status-no_show' ?>"><?= $slots ? e(count($slots) . ' ' . t('open')) : te('Full') ?></span>
        </div>
        <div class="card-body-md">
          <?php if ($slots): ?>
            <div class="d-flex flex-wrap gap-1">
              <?php foreach ($slots as $s): ?>
                <a class="time-btn text-reset" style="font-size:.78rem;padding:.35rem .55rem" href="<?= url('patient/book.php?' . http_build_query(['service_id' => $service['id'], 'dentist_id' => $dentistId ?? 0, 'date' => $d, 'time' => $s['time']])) ?>"><?= e(date('g:i A', strtotime($s['time']))) ?></a>
              <?php endforeach; ?>
            </div>
          <?php else: ?>
            <div class="text-muted small"><?= te('No open slots for this service.') ?></div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
  <?php if (!$days): ?><div class="col-12"><div class="card-md empty-state"><i class="bi bi-calendar-x"></i><?= te('No clinic days in this range.') ?></div></div><?php endif; ?>
</div>
<p class="small text-muted mt-3"><i class="bi bi-info-circle"></i> <?= te('Clinic hours: {days}, {hours}. Tap a time to book it.', ['days' => clinic_days_label_t(), 'hours' => date('g:i A', strtotime(CLINIC_OPEN)) . ' – ' . date('g:i A', strtotime(CLINIC_CLOSE))]) ?></p>
<?php include __DIR__ . '/../includes/footer.php'; ?>
