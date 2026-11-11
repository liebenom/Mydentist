<?php
/** Patient Management: registered patients and guest contacts with visit history. */
require __DIR__ . '/../includes/bootstrap.php';
$user = require_staff();

if (is_post() && input('do') === 'toggle') {
    csrf_check();
    $p = db_one("SELECT * FROM users WHERE id = ? AND role = 'patient'", [input_int('id')]);
    if ($p) {
        q('UPDATE users SET is_active = ? WHERE id = ?', [$p['is_active'] ? 0 : 1, $p['id']]);
        audit($p['is_active'] ? 'patient_deactivated' : 'patient_activated', $p['full_name'] . ' (' . $p['username'] . ')');
        flash('success', 'Account ' . ($p['is_active'] ? 'deactivated' : 'activated') . '.');
    }
    header('Location: ' . $_SERVER['REQUEST_URI']);
    exit;
}

$tab = input('tab', 'registered');
$search = input('q');
$like = '%' . $search . '%';

if ($tab === 'guests') {
    $where = 'a.patient_id IS NULL';
    $params = [];
    if ($search !== '') { $where .= ' AND (a.contact_name LIKE ? OR a.contact_email LIKE ? OR a.contact_phone LIKE ?)'; $params = [$like, $like, $like]; }
    $total = (int) db_val("SELECT COUNT(*) FROM (SELECT COALESCE(a.contact_email, a.contact_phone, a.contact_name) AS k FROM appointments a WHERE $where GROUP BY COALESCE(a.contact_email, a.contact_phone, a.contact_name)) t", $params);
    [$offset, $limit, $page, $pages] = paginate($total, 25);
    $rows = db_all(
        "SELECT MAX(a.contact_name) AS full_name, MAX(a.contact_email) AS email, MAX(a.contact_phone) AS phone, COUNT(*) AS total,
                SUM(CASE WHEN a.status = 'completed' THEN 1 ELSE 0 END) AS done, SUM(CASE WHEN a.status = 'no_show' THEN 1 ELSE 0 END) AS noshow,
                MAX(a.start_at) AS last_visit
         FROM appointments a WHERE $where GROUP BY COALESCE(a.contact_email, a.contact_phone, a.contact_name) ORDER BY last_visit DESC" . sql_limit($limit, $offset),
        $params
    );
} else {
    $tab = 'registered';
    $where = "u.role = 'patient'";
    $params = [];
    if ($search !== '') { $where .= ' AND (u.full_name LIKE ? OR u.email LIKE ? OR u.phone LIKE ? OR u.username LIKE ?)'; $params = [$like, $like, $like, $like]; }
    $total = (int) db_val("SELECT COUNT(*) FROM users u WHERE $where", $params);
    [$offset, $limit, $page, $pages] = paginate($total, 25);
    $rows = db_all(
        "SELECT u.*, (SELECT COUNT(*) FROM appointments a WHERE a.patient_id = u.id) AS total,
                (SELECT COUNT(*) FROM appointments a WHERE a.patient_id = u.id AND a.status = 'completed') AS done,
                (SELECT COUNT(*) FROM appointments a WHERE a.patient_id = u.id AND a.status = 'no_show') AS noshow,
                (SELECT MAX(a.start_at) FROM appointments a WHERE a.patient_id = u.id AND a.status IN ('completed','confirmed')) AS last_visit
         FROM users u WHERE $where ORDER BY u.full_name" . sql_limit($limit, $offset),
        $params
    );
}

$title = 'Patient Management';
$subtitle = number_format($total) . ($tab === 'guests' ? ' guest contacts' : ' registered patients');
$layout = 'admin';
$active = 'patients';
include __DIR__ . '/../includes/header.php';
?>
<div class="d-flex flex-wrap gap-2 align-items-center mb-3">
  <div class="filter-tabs"><a href="?tab=registered" class="<?= $tab === 'registered' ? 'active' : '' ?>">Registered Patients</a><a href="?tab=guests" class="<?= $tab === 'guests' ? 'active' : '' ?>">Guest / Walk-in Contacts</a></div>
  <form class="ms-auto d-flex gap-2" method="get"><input type="hidden" name="tab" value="<?= e($tab) ?>">
    <div class="input-icon"><i class="bi bi-search"></i><input class="form-control form-control-sm" name="q" value="<?= e($search) ?>" placeholder="Search name, email, phone" aria-label="Search"></div>
    <button class="btn btn-sm btn-primary">Search</button></form>
</div>
<div class="card-md">
  <div class="table-responsive"><table class="table table-md">
    <thead><tr><th>Patient</th><th>Contact</th><th class="text-end">Appointments</th><th class="text-end">Completed</th><th class="text-end">No-shows</th><th>Last visit</th><th class="text-end">Actions</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $p): $key = $p['email'] ?: $p['phone'] ?: $p['full_name']; ?>
      <tr class="<?= isset($p['is_active']) && !$p['is_active'] ? 'opacity-50' : '' ?>">
        <td><div class="person"><div class="avatar light sm"><?= e(initials($p['full_name'])) ?></div><div><div class="n"><?= e($p['full_name']) ?></div><div class="s"><?= $tab === 'registered' ? '@' . e($p['username']) . ' · since ' . e(fmt_date($p['created_at'], 'M Y')) : 'Guest' ?></div></div></div></td>
        <td class="small"><div><?= e($p['phone']) ?></div><div class="text-muted"><?= e($p['email']) ?></div></td>
        <td class="text-end"><?= (int) $p['total'] ?></td>
        <td class="text-end"><?= (int) $p['done'] ?></td>
        <td class="text-end <?= $p['noshow'] >= 2 ? 'text-danger fw-bold' : '' ?>"><?= (int) $p['noshow'] ?></td>
        <td class="small"><?= $p['last_visit'] ? e(fmt_date($p['last_visit'])) : '—' ?></td>
        <td class="text-end text-nowrap">
          <a class="btn btn-sm btn-soft" href="<?= url('admin/appointments.php?q=' . urlencode($key)) ?>">Appointments</a>
          <?php if ($tab === 'registered' && is_admin()): ?>
            <form method="post" class="d-inline" data-confirm="<?= $p['is_active'] ? 'Deactivate this account? The patient will not be able to log in.' : 'Activate this account?' ?>"><?= csrf_field() ?><input type="hidden" name="do" value="toggle"><input type="hidden" name="id" value="<?= $p['id'] ?>">
              <button class="btn btn-sm btn-light border" title="<?= $p['is_active'] ? 'Deactivate' : 'Activate' ?>"><i class="bi bi-<?= $p['is_active'] ? 'person-slash' : 'person-check' ?>"></i></button></form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="7" class="text-center text-muted py-4">No patients found.</td></tr><?php endif; ?>
    </tbody></table></div>
  <div class="d-flex justify-content-end p-3"><?= pagination_links($page, $pages) ?></div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
