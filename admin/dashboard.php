<?php
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/admin_actions.php';
$user = require_staff();
handle_admin_action_post();

$today = date('Y-m-d');
$tomorrow = date('Y-m-d', strtotime('+1 day'));
$now = now_str();

// ---------- KPI tiles
$todayCount = (int) db_val("SELECT COUNT(*) FROM appointments WHERE start_at >= ? AND start_at < ? AND status <> 'cancelled'", ["$today 00:00:00", "$tomorrow 00:00:00"]);
$pendingCount = (int) db_val("SELECT COUNT(*) FROM appointments WHERE status = 'pending' AND start_at >= ?", [$now]);
$confirmedToday = (int) db_val("SELECT COUNT(*) FROM appointments WHERE start_at >= ? AND start_at < ? AND status IN ('confirmed','completed')", ["$today 00:00:00", "$tomorrow 00:00:00"]);
$checkedInToday = (int) db_val('SELECT COUNT(*) FROM appointments WHERE checked_in_at >= ? AND checked_in_at < ?', ["$today 00:00:00", "$tomorrow 00:00:00"]);
$totalPatients = (int) db_val("SELECT COUNT(*) FROM users WHERE role = 'patient'")
    + (int) db_val("SELECT COUNT(DISTINCT contact_email) FROM appointments WHERE patient_id IS NULL AND contact_email IS NOT NULL");
$newPatientsMonth = (int) db_val("SELECT COUNT(*) FROM users WHERE role = 'patient' AND created_at >= ?", [date('Y-m-01 00:00:00')]);

// ---------- Lists
$pending = db_all(
    "SELECT a.*, s.name AS service_name, d.name AS dentist_name FROM appointments a
     JOIN services s ON s.id = a.service_id JOIN dentists d ON d.id = a.dentist_id
     WHERE a.status = 'pending' AND a.start_at >= ? ORDER BY a.start_at" . sql_limit(5),
    [$now]
);
$todayList = db_all(
    "SELECT a.*, s.name AS service_name, d.name AS dentist_name FROM appointments a
     JOIN services s ON s.id = a.service_id JOIN dentists d ON d.id = a.dentist_id
     WHERE a.start_at >= ? AND a.start_at < ? AND a.status <> 'cancelled' ORDER BY a.start_at",
    ["$today 00:00:00", "$tomorrow 00:00:00"]
);

// ---------- Analytics (aggregated in PHP so it works on any database)
$from = min(date('Y-01-01'), date('Y-m-d', strtotime('monday this week -7 weeks')));
$rows = db_all(
    "SELECT a.start_at, a.status, a.patient_id, a.contact_email, a.created_at, s.name AS service
     FROM appointments a JOIN services s ON s.id = a.service_id WHERE a.start_at >= ?",
    ["$from 00:00:00"]
);
$weekStart = strtotime('monday this week');
$weeks = [];
for ($i = 7; $i >= 0; $i--) {
    $weeks[date('Y-m-d', strtotime("-$i weeks", $weekStart))] = 0;
}
$weekDays = ['Mon' => 0, 'Tue' => 0, 'Wed' => 0, 'Thu' => 0, 'Fri' => 0, 'Sat' => 0, 'Sun' => 0];
$months = array_fill_keys(['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'], []);
$serviceCounts = [];
$monthStatus = ['total' => 0, 'cancelled' => 0, 'no_show' => 0, 'attended' => 0];
$monthStart = date('Y-m-01');
$serviceFrom = date('Y-m-d', strtotime('-90 days'));
foreach ($rows as $r) {
    $ts = strtotime($r['start_at']);
    $d = date('Y-m-d', $ts);
    $active = $r['status'] !== 'cancelled';
    $wk = date('Y-m-d', strtotime('monday this week', $ts));
    if ($active && isset($weeks[$wk])) {
        $weeks[$wk]++;
    }
    if ($active && $ts >= $weekStart && $ts < $weekStart + 7 * 86400) {
        $weekDays[date('D', $ts)]++;
    }
    if (in_array($r['status'], ['completed', 'confirmed'], true) && date('Y', $ts) === date('Y') && $ts <= time()) {
        $months[date('M', $ts)][$r['patient_id'] ? 'u' . $r['patient_id'] : 'e' . $r['contact_email']] = true;
    }
    if ($active && $d >= $serviceFrom && $ts <= strtotime('+30 days')) {
        $serviceCounts[$r['service']] = ($serviceCounts[$r['service']] ?? 0) + 1;
    }
    if ($d >= $monthStart && $ts <= time()) {
        $monthStatus['total']++;
        if ($r['status'] === 'cancelled') $monthStatus['cancelled']++;
        if ($r['status'] === 'no_show') $monthStatus['no_show']++;
        if ($r['status'] === 'completed') $monthStatus['attended']++;
    }
}
arsort($serviceCounts);
$topServices = array_slice($serviceCounts, 0, 6, true);
if (count($serviceCounts) > 6) {
    $topServices['Other services'] = array_sum(array_slice($serviceCounts, 6));
}
$currentMonth = (int) date('n');
$monthlyPatients = [];
foreach (array_slice($months, 0, $currentMonth, true) as $m => $set) {
    $monthlyPatients[$m] = count($set);
}
$cancelRate = $monthStatus['total'] ? round($monthStatus['cancelled'] / $monthStatus['total'] * 100, 1) : 0;
$noShowBase = $monthStatus['attended'] + $monthStatus['no_show'];
$noShowRate = $noShowBase ? round($monthStatus['no_show'] / $noShowBase * 100, 1) : 0;

$chartData = [
    'weeks'    => ['labels' => array_map(fn($w) => date('M j', strtotime($w)), array_keys($weeks)), 'values' => array_values($weeks)],
    'daily'    => ['labels' => array_keys($weekDays), 'values' => array_values($weekDays)],
    'monthly'  => ['labels' => array_keys($monthlyPatients), 'values' => array_values($monthlyPatients)],
    'services' => ['labels' => array_keys($topServices), 'values' => array_values($topServices)],
];

$title = 'Admin Dashboard';
$subtitle = date('l, F j, Y') . ' — Overview';
$layout = 'admin';
$active = 'dashboard';
include __DIR__ . '/../includes/header.php';
?>
<div class="row g-3 mb-3">
  <?php foreach ([
      [$todayCount, "Today's Appointments", 'calendar-event', 'tint-blue', $checkedInToday . ' checked in'],
      [$pendingCount, 'Pending Requests', 'hourglass-split', 'tint-amber', $pendingCount ? 'Needs your action' : 'All caught up'],
      [$confirmedToday, 'Confirmed Today', 'check2-circle', 'tint-green', 'Confirmed or completed'],
      [number_format($totalPatients), 'Total Patients', 'people', 'tint-violet', '+' . $newPatientsMonth . ' accounts this month'],
  ] as [$v, $l, $icon, $tint, $hint]): ?>
    <div class="col-6 col-xl-3">
      <div class="card-md stat-card">
        <div class="d-flex justify-content-between align-items-start"><div class="stat-icon <?= $tint ?>"><i class="bi bi-<?= $icon ?>"></i></div><span class="small text-muted text-end d-none d-sm-inline"><?= e($hint) ?></span></div>
        <div class="stat-value"><?= e((string) $v) ?></div>
        <div class="stat-label"><?= e($l) ?></div>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<div class="row g-3 mb-3">
  <div class="col-xl-5">
    <div class="card-md h-100">
      <div class="card-head"><h2>Pending Appointment Requests</h2><span class="status-badge status-pending ms-auto"><?= $pendingCount ?> pending</span></div>
      <div class="card-body-md pt-1">
        <?php if (!$pending): ?><div class="empty-state"><i class="bi bi-inbox"></i>No pending requests.</div><?php endif; ?>
        <?php foreach ($pending as $a): ?>
          <div class="appt-item flex-wrap">
            <div class="avatar light"><?= e(initials($a['contact_name'])) ?></div>
            <div class="flex-grow-1 min-w-0">
              <div class="d-flex gap-2 align-items-center"><a class="fw-bold text-reset text-truncate" href="<?= url('admin/appointment.php?id=' . $a['id']) ?>"><?= e($a['contact_name']) ?></a><?php if ($a['source'] === 'guest'): ?><span class="badge text-bg-light">Guest</span><?php endif; ?></div>
              <div class="small text-muted"><?= e($a['service_name']) ?> · <?= e(fmt_date($a['start_at'], 'M j') . ', ' . fmt_time($a['start_at'])) ?> · <?= e($a['dentist_name']) ?></div>
            </div>
            <div class="d-flex gap-1 w-100 justify-content-end">
              <?= action_button($a, 'approve', 'Approve', 'btn-primary', 'check-lg', 'admin/dashboard.php') ?>
              <a class="btn btn-sm btn-light border" href="<?= url('admin/appointment.php?id=' . $a['id'] . '#reschedule') ?>"><i class="bi bi-arrow-repeat"></i> Reschedule</a>
              <?= action_button($a, 'decline', 'Decline', 'btn-outline-danger', 'x-lg', 'admin/dashboard.php', 'Decline this request? The patient will be notified by email.') ?>
            </div>
          </div>
        <?php endforeach; ?>
        <?php if ($pendingCount > 5): ?><a class="small fw-semibold" href="<?= url('admin/appointments.php?status=pending') ?>">View all <?= $pendingCount ?> requests →</a><?php endif; ?>
      </div>
    </div>
  </div>
  <div class="col-xl-3 col-md-6">
    <div class="card-md h-100">
      <div class="card-head"><h2>Today's Schedule</h2></div>
      <div class="card-body-md pt-1" style="max-height:420px;overflow:auto">
        <?php if (!$todayList): ?><div class="empty-state"><i class="bi bi-cup-hot"></i>No appointments today.</div><?php endif; ?>
        <?php foreach ($todayList as $a): ?>
          <a class="appt-item text-reset" href="<?= url('admin/appointment.php?id=' . $a['id']) ?>">
            <div class="small fw-bold text-muted" style="width:62px"><?= e(fmt_time($a['start_at'])) ?></div>
            <div class="flex-grow-1 min-w-0"><div class="fw-semibold text-truncate"><?= e($a['contact_name']) ?> <?php if ($a['is_emergency']): ?><span class="tag-emergency">EMG</span><?php endif; ?></div><div class="small text-muted text-truncate"><?= e($a['service_name']) ?></div></div>
            <?= $a['checked_in_at'] && $a['status'] === 'confirmed' ? '<span class="status-badge status-completed"><i class="bi bi-person-check"></i> In</span>' : status_badge($a['status']) ?>
          </a>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
  <div class="col-xl-4 col-md-6">
    <div class="card-md h-100">
      <div class="card-head"><h2>Appointment Trends</h2><span class="small text-muted ms-auto">per week, last 8 weeks</span></div>
      <div class="card-body-md"><div class="chart-box"><canvas id="chWeeks" role="img" aria-label="Appointments per week"></canvas></div></div>
    </div>
  </div>
</div>

<div class="row g-3 mb-3">
  <div class="col-lg-4">
    <div class="card-md h-100">
      <div class="card-head"><h2>Most Availed Services</h2><span class="small text-muted ms-auto">last 90 days</span></div>
      <div class="card-body-md"><div class="chart-box"><canvas id="chServices" role="img" aria-label="Most availed services"></canvas></div></div>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="card-md h-100">
      <div class="card-head"><h2>Daily Bookings</h2><span class="small text-muted ms-auto">this week</span></div>
      <div class="card-body-md"><div class="chart-box"><canvas id="chDaily" role="img" aria-label="Bookings per day this week"></canvas></div></div>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="card-md h-100">
      <div class="card-head"><h2>Monthly Patients</h2><span class="small text-muted ms-auto">unique patients seen, <?= date('Y') ?></span></div>
      <div class="card-body-md"><div class="chart-box"><canvas id="chMonthly" role="img" aria-label="Unique patients per month"></canvas></div></div>
    </div>
  </div>
</div>

<div class="row g-3">
  <div class="col-md-6">
    <div class="card-md stat-card">
      <div class="d-flex justify-content-between"><span class="fw-bold">Cancellation Rate</span><span class="small text-muted"><?= date('F') ?> to date</span></div>
      <div class="d-flex align-items-end gap-3">
        <div class="stat-value" style="font-size:2.3rem"><?= $cancelRate ?>%</div>
        <div class="small text-muted pb-2"><?= $monthStatus['cancelled'] ?> cancelled of <?= $monthStatus['total'] ?> appointments</div>
      </div>
      <div class="progress" style="height:6px" role="progressbar" aria-label="Cancellation rate" aria-valuenow="<?= $cancelRate ?>" aria-valuemin="0" aria-valuemax="100"><div class="progress-bar" style="width:<?= $cancelRate ?>%;background:#b45309"></div></div>
    </div>
  </div>
  <div class="col-md-6">
    <div class="card-md stat-card">
      <div class="d-flex justify-content-between"><span class="fw-bold">No-Show Rate</span><span class="small text-muted"><?= date('F') ?> to date</span></div>
      <div class="d-flex align-items-end gap-3">
        <div class="stat-value" style="font-size:2.3rem"><?= $noShowRate ?>%</div>
        <div class="small text-muted pb-2"><?= $monthStatus['no_show'] ?> no-shows of <?= $noShowBase ?> past appointments (<?= $monthStatus['attended'] ?> attended)</div>
      </div>
      <div class="progress" style="height:6px" role="progressbar" aria-label="No-show rate" aria-valuenow="<?= $noShowRate ?>" aria-valuemin="0" aria-valuemax="100"><div class="progress-bar" style="width:<?= $noShowRate ?>%;background:#b91c1c"></div></div>
    </div>
  </div>
</div>
<p class="small text-muted mt-3 mb-0"><i class="bi bi-arrow-repeat"></i> Data is live from the database. Last reminder check: <?= e(fmt_datetime(setting_get('last_cron_at')) ?: 'not yet run') ?>. Last backup: <?= e(fmt_datetime(setting_get('last_backup_at')) ?: 'none yet') ?>.</p>

<?php
$extraScripts = '<script src="' . lib('chart.js') . '"></script>'
    . '<script>window.DASH = ' . json_encode($chartData) . ';</script>'
    . '<script src="' . url('assets/js/charts.js') . '?v=2"></script>';
include __DIR__ . '/../includes/footer.php';
