<?php
/** Appointment Records: search, filter, act on and export all appointments. */
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/admin_actions.php';
$user = require_staff();
handle_admin_action_post();

$status = input('status', 'all');
$search = input('q');
$from = input('from');
$to = input('to');
$dentist = input_int('dentist');
$service = input_int('service');
$scope = input('scope', 'all');   // all | upcoming | past | today

$where = ['1=1'];
$params = [];
if ($search !== '') {
    $where[] = '(a.contact_name LIKE ? OR a.contact_email LIKE ? OR a.contact_phone LIKE ? OR a.code LIKE ? OR s.name LIKE ?)';
    array_push($params, ...array_fill(0, 5, '%' . $search . '%'));
}
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) { $where[] = 'a.start_at >= ?'; $params[] = "$from 00:00:00"; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) { $where[] = 'a.start_at < ?'; $params[] = date('Y-m-d', strtotime("$to +1 day")) . ' 00:00:00'; }
if ($dentist) { $where[] = 'a.dentist_id = ?'; $params[] = $dentist; }
if ($service) { $where[] = '(a.service_id = ? OR a.extra_services LIKE ?)'; $params[] = $service; $params[] = '%,' . $service . ',%'; }
if ($scope === 'upcoming') { $where[] = 'a.end_at >= ?'; $params[] = now_str(); }
if ($scope === 'past') { $where[] = 'a.end_at < ?'; $params[] = now_str(); }
if ($scope === 'today') { $where[] = 'a.start_at >= ? AND a.start_at < ?'; array_push($params, date('Y-m-d 00:00:00'), date('Y-m-d 00:00:00', strtotime('+1 day'))); }
if ($scope === 'emergency') { $where[] = 'a.is_emergency = 1'; }
$base = 'FROM appointments a JOIN services s ON s.id = a.service_id JOIN dentists d ON d.id = a.dentist_id WHERE ' . implode(' AND ', $where);

// Counts per status (for the tabs) use the same filters
$counts = ['all' => 0];
foreach (db_all("SELECT a.status, COUNT(*) AS n $base GROUP BY a.status", $params) as $r) {
    $counts[$r['status']] = (int) $r['n'];
    $counts['all'] += (int) $r['n'];
}
$statusSql = '';
$statusParams = $params;
if ($status !== 'all' && in_array($status, ['pending', 'confirmed', 'completed', 'cancelled', 'no_show'], true)) {
    $statusSql = ' AND a.status = ?';
    $statusParams[] = $status;
} else {
    $status = 'all';
}
$order = $scope === 'upcoming' || $scope === 'today' || $status === 'pending' ? 'a.start_at ASC' : 'a.start_at DESC';

// ---------- CSV export
if (input('export') === 'csv') {
    $rows = db_all("SELECT a.*, s.name AS service_name, d.name AS dentist_name $base$statusSql ORDER BY $order", $statusParams);
    audit('records_exported', count($rows) . ' appointment records exported to CSV');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="appointments-' . date('Ymd-His') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // Excel UTF-8 BOM
    fputcsv($out, ['Reference', 'Patient', 'Email', 'Phone', 'Service', 'Dentist', 'Date', 'Start', 'End', 'Status', 'Source', 'Emergency', 'Checked in', 'Booked on', 'Patient notes', 'Staff notes']);
    foreach ($rows as $r) {
        fputcsv($out, [$r['code'], $r['contact_name'], $r['contact_email'], $r['contact_phone'], $r['service_name'], $r['dentist_name'],
            fmt_date($r['start_at'], 'Y-m-d'), fmt_time($r['start_at']), fmt_time($r['end_at']), status_label($r['status']), source_label($r['source']),
            $r['is_emergency'] ? 'Yes' : 'No', $r['checked_in_at'] ? fmt_datetime($r['checked_in_at']) : '', fmt_datetime($r['created_at']), $r['patient_notes'], $r['staff_notes']]);
    }
    exit;
}

$total = $status === 'all' ? $counts['all'] : ($counts[$status] ?? 0);
[$offset, $limit, $page, $pages] = paginate($total, 20);
$rows = db_all("SELECT a.*, s.name AS service_name, d.name AS dentist_name $base$statusSql ORDER BY $order" . sql_limit($limit, $offset), $statusParams);

$title = 'Appointment Records';
$subtitle = number_format($total) . ' record' . ($total === 1 ? '' : 's');
$layout = 'admin';
$active = 'appointments';
include __DIR__ . '/../includes/header.php';
$qs = function (array $over) { return '?' . e(http_build_query(array_merge($_GET, $over, ['page' => 1]))); };
$back = 'admin/appointments.php?' . http_build_query($_GET);
?>
<div class="d-flex flex-wrap gap-2 align-items-center mb-3">
  <div class="filter-tabs">
    <?php foreach (['all' => 'All', 'pending' => 'Pending', 'confirmed' => 'Confirmed', 'completed' => 'Completed', 'cancelled' => 'Cancelled', 'no_show' => 'No-Show'] as $k => $l): ?>
      <a href="<?= $qs(['status' => $k]) ?>" class="<?= $status === $k ? 'active' : '' ?>"><?= $l ?><span class="count"><?= $counts[$k] ?? 0 ?></span></a>
    <?php endforeach; ?>
  </div>
  <div class="ms-auto d-flex gap-2">
    <?php if (is_front_desk()): ?><a href="<?= url('admin/book.php') ?>" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg"></i> New Booking</a><?php endif; ?>
    <a href="<?= $qs(['export' => 'csv']) ?>" class="btn btn-sm btn-light border"><i class="bi bi-download"></i> Export CSV</a>
  </div>
</div>

<form class="card-md card-body-md mb-3" method="get">
  <input type="hidden" name="status" value="<?= e($status) ?>">
  <div class="row g-2 align-items-end">
    <div class="col-lg-3 col-md-6"><label class="form-label small" for="q">Search</label>
      <div class="input-icon"><i class="bi bi-search"></i><input class="form-control form-control-sm" id="q" name="q" value="<?= e($search) ?>" placeholder="Name, email, phone, reference…"></div></div>
    <div class="col-lg-2 col-md-3 col-6"><label class="form-label small" for="scope">When</label>
      <select class="form-select form-select-sm" name="scope" id="scope">
        <?php foreach (['all' => 'Any time', 'today' => 'Today', 'upcoming' => 'Upcoming', 'past' => 'Past', 'emergency' => 'Emergencies only'] as $k => $l): ?><option value="<?= $k ?>" <?= $scope === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?>
      </select></div>
    <div class="col-lg-2 col-md-3 col-6"><label class="form-label small" for="from">From</label><input class="form-control form-control-sm" type="date" name="from" id="from" value="<?= e($from) ?>"></div>
    <div class="col-lg-2 col-md-3 col-6"><label class="form-label small" for="to">To</label><input class="form-control form-control-sm" type="date" name="to" id="to" value="<?= e($to) ?>"></div>
    <div class="col-lg-1 col-md-3 col-6"><label class="form-label small" for="dentist">Dentist</label>
      <select class="form-select form-select-sm" name="dentist" id="dentist"><option value="0">All</option><?php foreach (db_all('SELECT id, name FROM dentists ORDER BY name') as $d): ?><option value="<?= $d['id'] ?>" <?= $dentist === (int) $d['id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option><?php endforeach; ?></select></div>
    <div class="col-lg-2 col-md-6 d-flex gap-2"><button class="btn btn-sm btn-primary flex-fill"><i class="bi bi-funnel"></i> Filter</button><a class="btn btn-sm btn-light border" href="<?= url('admin/appointments.php') ?>">Reset</a></div>
  </div>
</form>

<div class="card-md">
  <?php if (!$rows): ?><div class="empty-state"><i class="bi bi-journal-x"></i>No appointments match these filters.</div><?php else: ?>
  <div class="table-responsive">
    <table class="table table-md">
      <thead><tr><th>Patient</th><th>Contact</th><th>Service</th><th>Date & Time</th><th>Dentist</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $a): ?>
        <tr>
          <td><div class="person"><div class="avatar light sm"><?= e(initials($a['contact_name'])) ?></div><div class="min-w-0"><a class="n text-reset" href="<?= url('admin/appointment.php?id=' . $a['id']) ?>"><?= e($a['contact_name']) ?></a>
            <div class="s"><?php if (is_guest_booking($a)): ?><span class="code-ref"><?= e($a['code']) ?></span> · <?php endif; ?><?= e(source_label($a['source'])) ?> <?php if ($a['is_emergency']): ?><span class="tag-emergency">EMG</span><?php endif; ?><?php if (!empty($a['is_demo'])): ?> <span class="badge tint-violet">Demo</span><?php endif; ?></div></div></div></td>
          <td class="small"><div><?= e($a['contact_phone']) ?></div><div class="text-muted"><?= e($a['contact_email']) ?></div></td>
          <td><?= e($a['service_name']) ?></td>
          <td class="text-nowrap"><div><i class="bi bi-calendar3 text-muted"></i> <?= e(fmt_date($a['start_at'], 'M j, Y')) ?></div><div class="small text-muted"><?= e(fmt_time($a['start_at'])) ?></div></td>
          <td class="small"><?= e($a['dentist_name']) ?></td>
          <td><?= status_badge($a['status']) ?><?php if ($a['checked_in_at'] && $a['status'] === 'confirmed'): ?><div class="small text-success mt-1"><i class="bi bi-person-check"></i> Checked in</div><?php endif; ?></td>
          <td class="text-end text-nowrap">
            <?= action_button($a, 'approve', '', 'btn-primary', 'check-lg', $back) ?>
            <?php if ($a['status'] === 'confirmed' && strtotime($a['start_at']) < time() + 3600): ?>
              <?= action_button($a, 'complete', '', 'btn-success', 'check2-all', $back) ?>
              <?= action_button($a, 'no_show', '', 'btn-light border', 'person-x', $back, 'Mark as no-show?') ?>
            <?php endif; ?>
            <a class="btn btn-sm btn-light border" href="<?= url('admin/appointment.php?id=' . $a['id']) ?>" title="Open"><i class="bi bi-three-dots"></i></a>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="d-flex justify-content-between align-items-center p-3 small text-muted"><span>Showing <?= $offset + 1 ?>–<?= $offset + count($rows) ?> of <?= number_format($total) ?></span><?= pagination_links($page, $pages) ?></div>
  <?php endif; ?>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
