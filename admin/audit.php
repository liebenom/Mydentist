<?php
/** Audit Logs (admin): who did what and when. */
require __DIR__ . '/../includes/bootstrap.php';
$user = require_admin();

$search = input('q');
$action = input('action');
$from = input('from');
$to = input('to');
$where = ['1=1'];
$params = [];
if ($search !== '') { $where[] = '(l.details LIKE ? OR u.full_name LIKE ?)'; array_push($params, "%$search%", "%$search%"); }
if ($action !== '') { $where[] = 'l.action = ?'; $params[] = $action; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) { $where[] = 'l.created_at >= ?'; $params[] = "$from 00:00:00"; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) { $where[] = 'l.created_at < ?'; $params[] = date('Y-m-d', strtotime("$to +1 day")) . ' 00:00:00'; }
$base = 'FROM audit_logs l LEFT JOIN users u ON u.id = l.user_id WHERE ' . implode(' AND ', $where);
$total = (int) db_val("SELECT COUNT(*) $base", $params);
[$offset, $limit, $page, $pages] = paginate($total, 40);
$rows = db_all("SELECT l.*, u.full_name, u.role $base ORDER BY l.id DESC" . sql_limit($limit, $offset), $params);
$actions = db_all('SELECT DISTINCT action FROM audit_logs ORDER BY action');

$title = 'Audit Logs';
$subtitle = number_format($total) . ' recorded actions';
$layout = 'admin';
$active = 'audit';
include __DIR__ . '/../includes/header.php';
$tone = fn($a) => str_contains($a, 'emergency') ? 'tint-red' : (str_contains($a, 'cancel') || str_contains($a, 'decline') || str_contains($a, 'delete') || str_contains($a, 'restore') ? 'tint-amber' : (str_contains($a, 'login') || str_contains($a, 'logout') ? 'text-bg-light' : 'tint-teal'));
?>
<form class="card-md card-body-md mb-3" method="get">
  <div class="row g-2 align-items-end">
    <div class="col-md-4"><label class="form-label small" for="q">Search</label><input class="form-control form-control-sm" id="q" name="q" value="<?= e($search) ?>" placeholder="Details or user"></div>
    <div class="col-md-3"><label class="form-label small" for="action">Action</label><select class="form-select form-select-sm" id="action" name="action"><option value="">All actions</option><?php foreach ($actions as $a): ?><option value="<?= e($a['action']) ?>" <?= $action === $a['action'] ? 'selected' : '' ?>><?= e(str_replace('_', ' ', $a['action'])) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-2 col-6"><label class="form-label small" for="from">From</label><input type="date" class="form-control form-control-sm" id="from" name="from" value="<?= e($from) ?>"></div>
    <div class="col-md-2 col-6"><label class="form-label small" for="to">To</label><input type="date" class="form-control form-control-sm" id="to" name="to" value="<?= e($to) ?>"></div>
    <div class="col-md-1"><button class="btn btn-sm btn-primary w-100">Filter</button></div>
  </div>
</form>
<div class="card-md">
  <div class="table-responsive"><table class="table table-md">
    <thead><tr><th>When</th><th>User</th><th>Action</th><th>Details</th><th>IP</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td class="text-nowrap small"><?= e(fmt_datetime($r['created_at'])) ?></td>
        <td class="small"><?= e($r['full_name'] ?? 'Patient / guest / system') ?><?php if ($r['role']): ?><div class="text-muted"><?= e($r['role']) ?></div><?php endif; ?></td>
        <td><span class="badge <?= $tone($r['action']) ?>"><?= e(str_replace('_', ' ', $r['action'])) ?></span></td>
        <td class="small"><?= e($r['details']) ?><?php if ($r['appointment_id']): ?> <a href="<?= url('admin/appointment.php?id=' . $r['appointment_id']) ?>">open</a><?php endif; ?></td>
        <td class="small text-muted"><?= e($r['ip_address']) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="5" class="text-center text-muted py-4">No log entries.</td></tr><?php endif; ?>
    </tbody></table></div>
  <div class="d-flex justify-content-end p-3"><?= pagination_links($page, $pages) ?></div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
