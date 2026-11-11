<?php
/** Reports: daily, weekly, monthly or custom-range summaries; completed, cancellation and no-show reports. */
require __DIR__ . '/../includes/bootstrap.php';
$user = require_staff();

$period = input('period', 'monthly');
$ref = input('date') ?: date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ref)) $ref = date('Y-m-d');
$type = input('type', 'all');   // all | completed | cancelled | no_show
switch ($period) {
    case 'daily':
        $from = $ref; $to = $ref; $label = fmt_date($ref, 'l, F j, Y'); break;
    case 'weekly':
        $from = date('Y-m-d', strtotime('monday this week', strtotime($ref)));
        $to = date('Y-m-d', strtotime("$from +6 days"));
        $label = 'Week of ' . fmt_date($from, 'M j') . ' – ' . fmt_date($to, 'M j, Y'); break;
    case 'custom':
        $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', input('from')) ? input('from') : date('Y-m-01');
        $to = preg_match('/^\d{4}-\d{2}-\d{2}$/', input('to')) ? input('to') : date('Y-m-d');
        if ($to < $from) [$from, $to] = [$to, $from];
        $label = fmt_date($from, 'M j, Y') . ' – ' . fmt_date($to, 'M j, Y'); break;
    default:
        $period = 'monthly';
        $from = date('Y-m-01', strtotime($ref)); $to = date('Y-m-t', strtotime($ref));
        $label = fmt_date($from, 'F Y');
}
$rows = db_all(
    "SELECT a.*, s.name AS service_name, s.price, d.name AS dentist_name FROM appointments a
     JOIN services s ON s.id = a.service_id JOIN dentists d ON d.id = a.dentist_id
     WHERE a.start_at >= ? AND a.start_at < ? ORDER BY a.start_at",
    ["$from 00:00:00", date('Y-m-d', strtotime("$to +1 day")) . ' 00:00:00']
);

$sum = ['total' => count($rows), 'pending' => 0, 'confirmed' => 0, 'completed' => 0, 'cancelled' => 0, 'no_show' => 0, 'emergency' => 0, 'revenue' => 0];
$byService = [];
$byDentist = [];
$bySource = [];
foreach ($rows as $r) {
    $sum[$r['status']]++;
    if ($r['is_emergency']) $sum['emergency']++;
    $svc = &$byService[$r['service_name']];
    $svc = $svc ?? ['total' => 0, 'completed' => 0, 'cancelled' => 0, 'no_show' => 0, 'revenue' => 0];
    $svc['total']++;
    if (isset($svc[$r['status']])) $svc[$r['status']]++;
    if ($r['status'] === 'completed') { $svc['revenue'] += $r['price']; $sum['revenue'] += $r['price']; }
    unset($svc);
    $byDentist[$r['dentist_name']][$r['status']] = ($byDentist[$r['dentist_name']][$r['status']] ?? 0) + 1;
    $bySource[source_label($r['source'])] = ($bySource[source_label($r['source'])] ?? 0) + 1;
}
uasort($byService, fn($a, $b) => $b['total'] <=> $a['total']);
$finished = $sum['completed'] + $sum['no_show'];
$rate = fn($n, $d) => $d ? round($n / $d * 100, 1) . '%' : '—';
$list = $type === 'all' ? $rows : array_values(array_filter($rows, fn($r) => $r['status'] === $type));
$typeLabels = ['all' => 'All Appointments', 'completed' => 'Completed Appointments', 'cancelled' => 'Cancellations', 'no_show' => 'No-Shows'];
if (!isset($typeLabels[$type])) $type = 'all';

if (input('export') === 'csv') {
    audit('report_exported', $typeLabels[$type] . ' report, ' . $label);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="report-' . $period . '-' . $from . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, [APP_NAME . ' – ' . $typeLabels[$type] . ' Report', $label]);
    fputcsv($out, ['Total', $sum['total'], 'Completed', $sum['completed'], 'Cancelled', $sum['cancelled'], 'No-show', $sum['no_show']]);
    fputcsv($out, []);
    fputcsv($out, ['Reference', 'Date', 'Time', 'Patient', 'Phone', 'Service', 'Dentist', 'Status', 'Source', 'Emergency']);
    foreach ($list as $r) {
        fputcsv($out, [$r['code'], fmt_date($r['start_at'], 'Y-m-d'), fmt_time($r['start_at']), $r['contact_name'], $r['contact_phone'], $r['service_name'], $r['dentist_name'], status_label($r['status']), source_label($r['source']), $r['is_emergency'] ? 'Yes' : 'No']);
    }
    exit;
}

$title = 'Reports';
$subtitle = $label;
$layout = 'admin';
$active = 'reports';
include __DIR__ . '/../includes/header.php';
$link = fn(array $o) => '?' . e(http_build_query(array_merge(['period' => $period, 'date' => $ref, 'from' => input('from'), 'to' => input('to'), 'type' => $type], $o)));
?>
<div class="print-only mb-3">
  <h2 class="fw-800 mb-0"><?= e(APP_NAME) ?></h2>
  <div><?= e($typeLabels[$type]) ?> Report · <?= e($label) ?></div>
  <div class="small text-muted">Generated <?= e(fmt_datetime(now_str())) ?> by <?= e($user['full_name']) ?></div><hr>
</div>

<form method="get" class="card-md card-body-md mb-3 no-print">
  <div class="row g-2 align-items-end">
    <div class="col-md-3"><label class="form-label small">Report period</label>
      <div class="btn-group w-100" role="group">
        <?php foreach (['daily' => 'Daily', 'weekly' => 'Weekly', 'monthly' => 'Monthly', 'custom' => 'Custom'] as $k => $l): ?>
          <input type="radio" class="btn-check" name="period" id="p_<?= $k ?>" value="<?= $k ?>" <?= $period === $k ? 'checked' : '' ?> onchange="this.form.submit()"><label class="btn btn-sm btn-outline-primary" for="p_<?= $k ?>"><?= $l ?></label>
        <?php endforeach; ?>
      </div></div>
    <?php if ($period === 'custom'): ?>
      <div class="col-md-2 col-6"><label class="form-label small" for="from">From</label><input type="date" class="form-control form-control-sm" name="from" id="from" value="<?= e($from) ?>"></div>
      <div class="col-md-2 col-6"><label class="form-label small" for="to">To</label><input type="date" class="form-control form-control-sm" name="to" id="to" value="<?= e($to) ?>"></div>
    <?php else: ?>
      <div class="col-md-3"><label class="form-label small" for="date"><?= $period === 'monthly' ? 'Any day in the month' : ($period === 'weekly' ? 'Any day in the week' : 'Date') ?></label><input type="date" class="form-control form-control-sm" name="date" id="date" value="<?= e($ref) ?>"></div>
    <?php endif; ?>
    <div class="col-md-2"><label class="form-label small" for="type">Report type</label>
      <select class="form-select form-select-sm" name="type" id="type"><?php foreach ($typeLabels as $k => $l): ?><option value="<?= $k ?>" <?= $type === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
    <div class="col-md d-flex gap-2 flex-wrap">
      <button class="btn btn-sm btn-primary"><i class="bi bi-arrow-repeat"></i> Generate</button>
      <a class="btn btn-sm btn-light border" href="<?= $link(['export' => 'csv']) ?>"><i class="bi bi-filetype-csv"></i> CSV</a>
      <button type="button" class="btn btn-sm btn-light border" onclick="window.print()"><i class="bi bi-printer"></i> Print / PDF</button>
    </div>
  </div>
</form>

<div class="row g-3 mb-3">
  <?php foreach ([
      ['Total', $sum['total'], 'calendar3', 'tint-blue', $label],
      ['Completed', $sum['completed'], 'check2-all', 'tint-green', 'Attendance ' . $rate($sum['completed'], $finished)],
      ['Cancelled', $sum['cancelled'], 'x-circle', 'tint-amber', 'Cancellation rate ' . $rate($sum['cancelled'], $sum['total'])],
      ['No-Shows', $sum['no_show'], 'person-x', 'tint-red', 'No-show rate ' . $rate($sum['no_show'], $finished)],
      ['Upcoming', $sum['pending'] + $sum['confirmed'], 'hourglass-split', 'tint-violet', $sum['pending'] . ' pending · ' . $sum['confirmed'] . ' confirmed'],
      ['Est. Revenue', peso($sum['revenue']), 'cash-coin', 'tint-teal', 'Starting rates of completed'],
  ] as [$l, $v, $i, $t, $h]): ?>
    <div class="col-6 col-md-4 col-xl-2"><div class="card-md stat-card"><div class="stat-icon <?= $t ?>"><i class="bi bi-<?= $i ?>"></i></div><div class="stat-value"><?= e((string) $v) ?></div><div class="stat-label"><?= e($l) ?></div><div class="small text-muted"><?= e($h) ?></div></div></div>
  <?php endforeach; ?>
</div>

<div class="row g-3 mb-3">
  <div class="col-xl-7">
    <div class="card-md h-100"><div class="card-head"><h2>By Service</h2></div>
      <div class="table-responsive"><table class="table table-md">
        <thead><tr><th>Service</th><th class="text-end">Total</th><th class="text-end">Completed</th><th class="text-end">Cancelled</th><th class="text-end">No-show</th><th class="text-end">Est. revenue</th></tr></thead>
        <tbody>
          <?php foreach ($byService as $n => $s): ?><tr><td class="fw-semibold"><?= e($n) ?></td><td class="text-end"><?= $s['total'] ?></td><td class="text-end"><?= $s['completed'] ?></td><td class="text-end"><?= $s['cancelled'] ?></td><td class="text-end"><?= $s['no_show'] ?></td><td class="text-end"><?= peso($s['revenue']) ?></td></tr><?php endforeach; ?>
          <?php if (!$byService): ?><tr><td colspan="6" class="text-center text-muted py-4">No appointments in this period.</td></tr><?php endif; ?>
        </tbody></table></div></div>
  </div>
  <div class="col-xl-5">
    <div class="card-md mb-3"><div class="card-head"><h2>By Dentist</h2></div>
      <div class="table-responsive"><table class="table table-md"><thead><tr><th>Dentist</th><th class="text-end">Total</th><th class="text-end">Completed</th><th class="text-end">No-show</th></tr></thead><tbody>
        <?php foreach ($byDentist as $n => $s): ?><tr><td><?= e($n) ?></td><td class="text-end"><?= array_sum($s) ?></td><td class="text-end"><?= $s['completed'] ?? 0 ?></td><td class="text-end"><?= $s['no_show'] ?? 0 ?></td></tr><?php endforeach; ?>
        <?php if (!$byDentist): ?><tr><td colspan="4" class="text-center text-muted py-3">—</td></tr><?php endif; ?>
      </tbody></table></div></div>
    <div class="card-md"><div class="card-head"><h2>By Booking Channel</h2></div>
      <div class="card-body-md pt-1">
        <?php foreach ($bySource as $n => $c): ?><div class="d-flex justify-content-between py-1 small border-bottom"><span><?= e($n) ?></span><strong><?= $c ?> <span class="text-muted fw-normal">(<?= $rate($c, $sum['total']) ?>)</span></strong></div><?php endforeach; ?>
        <?php if ($sum['emergency']): ?><div class="d-flex justify-content-between py-1 small"><span>Emergency cases</span><strong><?= $sum['emergency'] ?></strong></div><?php endif; ?>
        <?php if (!$bySource): ?><div class="small text-muted">—</div><?php endif; ?>
      </div></div>
  </div>
</div>

<div class="card-md">
  <div class="card-head"><h2><?= e($typeLabels[$type]) ?></h2>
    <div class="filter-tabs ms-auto no-print"><?php foreach ($typeLabels as $k => $l): ?><a href="<?= $link(['type' => $k]) ?>" class="<?= $type === $k ? 'active' : '' ?>"><?= $k === 'all' ? 'All' : $l ?></a><?php endforeach; ?></div></div>
  <div class="table-responsive"><table class="table table-md">
    <thead><tr><th>Date</th><th>Time</th><th>Reference</th><th>Patient</th><th>Service</th><th>Dentist</th><th>Status</th></tr></thead>
    <tbody>
      <?php foreach ($list as $r): ?><tr><td class="text-nowrap"><?= e(fmt_date($r['start_at'], 'D, M j')) ?></td><td class="text-nowrap"><?= e(fmt_time($r['start_at'])) ?></td><td><a class="code-ref small" href="<?= url('admin/appointment.php?id=' . $r['id']) ?>"><?= e($r['code']) ?></a></td><td><?= e($r['contact_name']) ?></td><td><?= e($r['service_name']) ?></td><td class="small"><?= e($r['dentist_name']) ?></td><td><?= status_badge($r['status']) ?></td></tr><?php endforeach; ?>
      <?php if (!$list): ?><tr><td colspan="7" class="text-center text-muted py-4">No records for this report.</td></tr><?php endif; ?>
    </tbody></table></div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
