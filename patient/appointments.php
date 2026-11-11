<?php
require __DIR__ . '/../includes/bootstrap.php';
$user = require_patient();

$filter = input('filter', 'all');
$now = now_str();
$where = 'a.patient_id = ?';
$params = [$user['id']];
switch ($filter) {
    case 'upcoming':
        $where .= " AND a.status IN ('pending','confirmed') AND a.end_at >= ?";
        $params[] = $now;
        break;
    case 'completed':
        $where .= " AND a.status = 'completed'";
        break;
    case 'cancelled':
        $where .= " AND a.status IN ('cancelled','no_show')";
        break;
    default:
        $filter = 'all';
}
$rows = db_all(
    "SELECT a.*, s.name AS service_name, d.name AS dentist_name FROM appointments a
     JOIN services s ON s.id = a.service_id JOIN dentists d ON d.id = a.dentist_id
     WHERE $where ORDER BY a.start_at DESC",
    $params
);

$title = t('My Appointments');
$layout = 'patient';
$active = 'appointments';
include __DIR__ . '/../includes/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <div class="filter-tabs">
    <?php foreach (['all' => 'All', 'upcoming' => 'Upcoming', 'completed' => 'Completed', 'cancelled' => 'Cancelled / No-show'] as $k => $l): ?>
      <a href="?filter=<?= $k ?>" class="<?= $filter === $k ? 'active' : '' ?>"><?= te($l) ?></a>
    <?php endforeach; ?>
  </div>
  <a href="<?= url('patient/book.php') ?>" class="btn btn-primary btn-sm"><i class="bi bi-calendar-plus"></i> <?= te('Book Appointment') ?></a>
</div>
<div class="card-md">
  <?php if (!$rows): ?>
    <div class="empty-state"><i class="bi bi-journal-x"></i><?= te('No appointments found.') ?></div>
  <?php else: ?>
  <div class="table-responsive">
    <table class="table table-md">
      <thead><tr><th><?= te('Service') ?></th><th><?= te('Dentist') ?></th><th><?= te('Date & Time') ?></th><th><?= te('Status') ?></th><th class="text-end"><?= te('Actions') ?></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $a): ?>
        <tr>
          <td class="fw-semibold"><?= e(svc_t($a['service_name'])) ?></td>
          <td><?= e($a['dentist_name']) ?></td>
          <td><div><?= e(ldate('M j, Y', $a['start_at'])) ?></div><div class="small text-muted"><?= e(fmt_time($a['start_at'])) ?></div></td>
          <td><?= status_badge($a['status']) ?></td>
          <td class="text-end text-nowrap">
            <a class="btn btn-sm btn-soft" href="<?= url('patient/appointment.php?id=' . $a['id']) ?>"><?= te('View') ?></a>
            <?php if (patient_can_change($a)): ?>
              <a class="btn btn-sm btn-outline-primary" href="<?= url('patient/reschedule.php?id=' . $a['id']) ?>"><?= te('Reschedule') ?></a>
              <a class="btn btn-sm btn-outline-danger" href="<?= url('patient/appointment.php?id=' . $a['id'] . '&cancel=1#cancel') ?>"><?= te('Cancel') ?></a>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
