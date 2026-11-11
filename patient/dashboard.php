<?php
require __DIR__ . '/../includes/bootstrap.php';
$user = require_patient();

$now = now_str();
$upcoming = db_all(
    "SELECT a.*, s.name AS service_name, d.name AS dentist_name FROM appointments a
     JOIN services s ON s.id = a.service_id JOIN dentists d ON d.id = a.dentist_id
     WHERE a.patient_id = ? AND a.status IN ('pending','confirmed') AND a.end_at >= ? ORDER BY a.start_at" . sql_limit(10),
    [$user['id'], $now]
);
$history = db_all(
    "SELECT a.*, s.name AS service_name, d.name AS dentist_name FROM appointments a
     JOIN services s ON s.id = a.service_id JOIN dentists d ON d.id = a.dentist_id
     WHERE a.patient_id = ? AND (a.status NOT IN ('pending','confirmed') OR a.end_at < ?) ORDER BY a.start_at DESC" . sql_limit(8),
    [$user['id'], $now]
);
$next = $upcoming[0] ?? null;
$visits = (int) db_val("SELECT COUNT(*) FROM appointments WHERE patient_id = ? AND status = 'completed'", [$user['id']]);

$hour = (int) date('G');
$greet = t($hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening'));

$title = t('Patient Dashboard');
$layout = 'patient';
$active = 'dashboard';
include __DIR__ . '/../includes/header.php';

function appt_row(array $a): string
{
    $html = '<a class="appt-item text-reset" href="' . url('patient/appointment.php?id=' . $a['id']) . '">'
        . '<div class="date-box"><div class="m">' . e(ldate('M', $a['start_at'])) . '</div><div class="d">' . e(fmt_date($a['start_at'], 'j')) . '</div></div>'
        . '<div class="flex-grow-1 min-w-0"><div class="fw-bold text-truncate">' . e(svc_t($a['service_name'])) . '</div>'
        . '<div class="small text-muted">' . e(ldate('D', $a['start_at']) . ' · ' . fmt_time($a['start_at']) . ' · ' . $a['dentist_name']) . '</div></div>'
        . status_badge($a['status']) . '</a>';
    return $html;
}
?>
<div class="welcome-banner mb-3">
  <h2><?= $greet ?>, <?= e(explode(' ', $user['full_name'])[0]) ?>! <span aria-hidden="true">👋</span></h2>
  <div class="opacity-75"><?= te('Upcoming appointments: {n} · Completed visits: {v}', ['n' => count($upcoming), 'v' => $visits]) ?></div>
</div>

<div class="card-md mb-3">
  <div class="card-body-md">
    <?php if ($next): ?>
      <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-2">
        <div><div class="small-caps"><?= te('Next appointment') ?></div><div class="fw-800 fs-5"><?= e(svc_t($next['service_name'])) ?></div></div>
        <?= status_badge($next['status']) ?>
      </div>
      <div class="row g-2 mb-3">
        <div class="col-sm-4"><div class="info-tile"><i class="bi bi-calendar-event"></i><div><div class="k"><?= te('Date') ?></div><div class="v"><?= e(ldate('F j, Y', $next['start_at'])) ?></div></div></div></div>
        <div class="col-sm-4"><div class="info-tile"><i class="bi bi-clock"></i><div><div class="k"><?= te('Time') ?></div><div class="v"><?= e(fmt_time($next['start_at'])) ?></div></div></div></div>
        <div class="col-sm-4"><div class="info-tile"><i class="bi bi-person-badge"></i><div><div class="k"><?= te('Dentist') ?></div><div class="v"><?= e($next['dentist_name']) ?></div></div></div></div>
      </div>
      <div class="d-flex flex-wrap gap-2">
        <a href="<?= url('patient/appointment.php?id=' . $next['id']) ?>" class="btn btn-primary flex-grow-1"><i class="bi bi-eye"></i> <?= te('View Details') ?></a>
        <?php if (patient_can_change($next)): ?>
          <a href="<?= url('patient/reschedule.php?id=' . $next['id']) ?>" class="btn btn-outline-primary flex-grow-1"><?= te('Reschedule') ?></a>
          <a href="<?= url('patient/appointment.php?id=' . $next['id'] . '#cancel') ?>" class="btn btn-outline-danger"><?= te('Cancel') ?></a>
        <?php endif; ?>
      </div>
    <?php else: ?>
      <div class="empty-state"><i class="bi bi-calendar-plus"></i><div class="fw-bold text-body mb-1"><?= te('No upcoming appointments') ?></div><?= te('Book your next visit in less than a minute.') ?><div class="mt-3"><a class="btn btn-primary" href="<?= url('patient/book.php') ?>"><?= te('Book Appointment') ?></a></div></div>
    <?php endif; ?>
  </div>
</div>

<h2 class="h6 fw-bold mb-2"><?= te('Quick Actions') ?></h2>
<div class="row g-3 mb-3">
  <?php foreach ([
      ['patient/book.php', 'calendar-plus', 'tint-teal', 'Book Appointment', 'Schedule a new visit'],
      ['patient/schedules.php', 'calendar3', 'tint-amber', 'View Schedules', 'Browse available slots'],
      ['patient/appointments.php', 'journal-medical', 'tint-green', 'My Appointments', 'Track all bookings'],
      ['patient/appointments.php?filter=upcoming', 'arrow-repeat', 'tint-violet', 'Reschedule / Cancel', 'Modify existing bookings'],
  ] as [$href, $icon, $tint, $t, $d]): ?>
    <div class="col-6 col-lg-3"><a class="quick-action" href="<?= url($href) ?>"><div class="icon <?= $tint ?>"><i class="bi bi-<?= $icon ?>"></i></div><div class="t"><?= te($t) ?></div><div class="d"><?= te($d) ?></div></a></div>
  <?php endforeach; ?>
</div>

<div class="card-md">
  <div class="card-head">
    <ul class="nav nav-pills small" role="tablist">
      <li class="nav-item"><button class="nav-link active py-1 px-3" data-bs-toggle="pill" data-bs-target="#tabUp" type="button"><?= te('Upcoming Appointments') ?></button></li>
      <li class="nav-item"><button class="nav-link py-1 px-3" data-bs-toggle="pill" data-bs-target="#tabHist" type="button"><?= te('Appointment History') ?></button></li>
    </ul>
  </div>
  <div class="card-body-md pt-1 tab-content">
    <div class="tab-pane fade show active" id="tabUp">
      <?php if ($upcoming): foreach ($upcoming as $a) echo appt_row($a); else: ?><div class="empty-state"><i class="bi bi-calendar"></i><?= te('No upcoming appointments.') ?></div><?php endif; ?>
    </div>
    <div class="tab-pane fade" id="tabHist">
      <?php if ($history): foreach ($history as $a) echo appt_row($a); else: ?><div class="empty-state"><i class="bi bi-clock-history"></i><?= te('No past appointments yet.') ?></div><?php endif; ?>
    </div>
  </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
