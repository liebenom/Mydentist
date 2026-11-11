<?php
/** Schedule Management: weekly grid, block/unblock time slots, block whole days, approval queue. */
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/admin_actions.php';
$user = require_staff();
handle_admin_action_post();

$dentists = active_dentists();
$dentistId = input_int('dentist');
$monday = input('week');
$monday = preg_match('/^\d{4}-\d{2}-\d{2}$/', $monday) ? date('Y-m-d', strtotime('monday this week', strtotime($monday))) : date('Y-m-d', strtotime('monday this week'));
$self = 'admin/schedule.php?' . http_build_query(['week' => $monday, 'dentist' => $dentistId]);

if (is_post()) {
    csrf_check();
    $do = input('do');
    if ($do === 'block_slot') {
        $start = input('start');
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $start)) {
            $s = strtotime($start);
            db_insert('schedule_blocks', [
                'dentist_id' => $dentistId ?: null, 'start_at' => date('Y-m-d H:i:s', $s), 'end_at' => date('Y-m-d H:i:s', $s + SLOT_INTERVAL * 60),
                'reason' => input('reason') ?: 'Blocked by staff', 'created_by' => $user['id'], 'created_at' => now_str(),
            ]);
            audit('slot_blocked', date('M j, Y g:i A', $s) . ($dentistId ? ' for ' . find_dentist($dentistId)['name'] : ' (whole clinic)'));
            flash('success', te('Time slot blocked.'));
        }
    } elseif ($do === 'block_range') {
        $date = input('date');
        $from = input('from') ?: CLINIC_OPEN;
        $to = input('to') ?: CLINIC_CLOSE;
        $bDentist = input_int('block_dentist');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) && $from < $to) {
            $conflicts = (int) db_val(
                "SELECT COUNT(*) FROM appointments WHERE status IN ('pending','confirmed') AND start_at < ? AND end_at > ?" . ($bDentist ? ' AND dentist_id = ' . $bDentist : ''),
                ["$date $to:00", "$date $from:00"]
            );
            db_insert('schedule_blocks', [
                'dentist_id' => $bDentist ?: null, 'start_at' => "$date $from:00", 'end_at' => "$date $to:00",
                'reason' => input('reason') ?: 'Unavailable', 'created_by' => $user['id'], 'created_at' => now_str(),
            ]);
            audit('time_blocked', "{$date} {$from}–{$to}" . ($bDentist ? ' for ' . find_dentist($bDentist)['name'] : ' (whole clinic)') . ': ' . input('reason'));
            flash($conflicts ? 'warning' : 'success', te('Time blocked.') . ($conflicts ? ' ' . te('Note: {n} existing appointment(s) fall inside this period. Please reschedule or cancel them from Appointment Records.', ['n' => $conflicts]) : ''));
        } else {
            flash('danger', te('Please enter a valid date and time range.'));
        }
    } elseif ($do === 'unblock') {
        $b = db_one('SELECT * FROM schedule_blocks WHERE id = ?', [input_int('block_id')]);
        if ($b) {
            q('DELETE FROM schedule_blocks WHERE id = ?', [$b['id']]);
            audit('slot_unblocked', fmt_datetime($b['start_at']) . ' – ' . fmt_time($b['end_at']) . ' (' . $b['reason'] . ')');
            flash('success', te('Block removed. The time is open for booking again.'));
        }
    }
    redirect($self);
}

// ---------- Build the grid
$days = [];
for ($i = 0; $i < 7; $i++) {
    $d = date('Y-m-d', strtotime("$monday +$i days"));
    if (is_clinic_day($d)) {
        $days[] = $d;
    }
}
$weekEnd = date('Y-m-d', strtotime("$monday +7 days"));
$appts = db_all(
    "SELECT a.id, a.dentist_id, a.start_at, a.end_at, a.status, a.contact_name, a.is_emergency, s.name AS service_name
     FROM appointments a JOIN services s ON s.id = a.service_id
     WHERE a.status IN ('pending','confirmed','completed') AND a.start_at < ? AND a.end_at > ?" . ($dentistId ? ' AND a.dentist_id = ' . $dentistId : '') . ' ORDER BY a.start_at',
    ["$weekEnd 00:00:00", "$monday 00:00:00"]
);
$blocks = db_all(
    'SELECT b.*, d.name AS dentist_name FROM schedule_blocks b LEFT JOIN dentists d ON d.id = b.dentist_id WHERE b.start_at < ? AND b.end_at > ?' . ($dentistId ? ' AND (b.dentist_id IS NULL OR b.dentist_id = ' . $dentistId . ')' : ' AND b.dentist_id IS NULL') . ' ORDER BY b.start_at',
    ["$weekEnd 00:00:00", "$monday 00:00:00"]
);
$allBlocks = db_all('SELECT b.*, d.name AS dentist_name FROM schedule_blocks b LEFT JOIN dentists d ON d.id = b.dentist_id WHERE b.end_at >= ? ORDER BY b.start_at' . sql_limit(20), [now_str()]);

$times = [];
for ($t = strtotime('2000-01-01 ' . CLINIC_OPEN); $t < strtotime('2000-01-01 ' . CLINIC_CLOSE); $t += SLOT_INTERVAL * 60) {
    $times[] = date('H:i', $t);
}
$stats = ['booked' => 0, 'open' => 0, 'blocked' => 0];
$cells = [];
$nowTs = time();
foreach ($days as $d) {
    foreach ($times as $tm) {
        $s = strtotime("$d $tm");
        $e = $s + SLOT_INTERVAL * 60;
        $inWindow = false;
        foreach (day_windows($d) as [$ws, $we]) {
            if ($s >= $ws && $e <= $we) $inWindow = true;
        }
        if (!$inWindow) { $cells[$d][$tm] = ['type' => 'closed']; continue; }
        $hit = array_values(array_filter($appts, fn($a) => strtotime($a['start_at']) < $e && strtotime($a['end_at']) > $s));
        $blk = array_values(array_filter($blocks, fn($b) => strtotime($b['start_at']) < $e && strtotime($b['end_at']) > $s));
        $capacity = $dentistId ? 1 : count($dentists);
        if ($blk) {
            $cells[$d][$tm] = ['type' => 'blocked', 'block' => $blk[0]];
            $stats['blocked']++;
        } elseif (count($hit) >= $capacity) {
            $cells[$d][$tm] = ['type' => 'booked', 'appts' => $hit];
            $stats['booked']++;
        } else {
            $cells[$d][$tm] = ['type' => 'open', 'appts' => $hit];
            $hit ? $stats['booked']++ : $stats['open']++;
        }
        $cells[$d][$tm]['past'] = $e <= $nowTs;
    }
}
$queue = db_all(
    "SELECT a.*, s.name AS service_name FROM appointments a JOIN services s ON s.id = a.service_id
     WHERE a.status = 'pending' AND a.start_at >= ? ORDER BY a.start_at" . sql_limit(8),
    [now_str()]
);

$title = t('Schedule Management');
$subtitle = t('Week of {from} – {to}', ['from' => ldate('F j', $monday), 'to' => ldate('F j, Y', date('Y-m-d', strtotime("$monday +6 days")))]);
$layout = 'admin';
$active = 'schedule';
include __DIR__ . '/../includes/header.php';
$weekLink = fn(string $w) => url('admin/schedule.php?' . http_build_query(['week' => $w, 'dentist' => $dentistId]));
?>
<div class="d-flex flex-wrap gap-2 align-items-center mb-3">
  <form method="get" class="d-flex gap-2 align-items-center">
    <input type="hidden" name="week" value="<?= e($monday) ?>">
    <select name="dentist" class="form-select form-select-sm" onchange="this.form.submit()" aria-label="<?= te('Dentist') ?>">
      <option value="0"><?= te('All dentists (clinic-wide)') ?></option>
      <?php foreach ($dentists as $d): ?><option value="<?= $d['id'] ?>" <?= $dentistId === (int) $d['id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option><?php endforeach; ?>
    </select>
  </form>
  <div class="ms-auto d-flex gap-2">
    <a class="btn btn-sm btn-light border" href="<?= $weekLink(date('Y-m-d', strtotime("$monday -7 days"))) ?>"><i class="bi bi-chevron-left"></i> <?= te('Prev Week') ?></a>
    <a class="btn btn-sm btn-light border" href="<?= $weekLink(date('Y-m-d')) ?>"><?= te('This Week') ?></a>
    <a class="btn btn-sm btn-light border" href="<?= $weekLink(date('Y-m-d', strtotime("$monday +7 days"))) ?>"><?= te('Next Week') ?> <i class="bi bi-chevron-right"></i></a>
  </div>
</div>

<div class="row g-3 mb-3">
  <div class="col-4"><div class="card-md stat-card text-center"><div class="stat-value text-primary"><?= $stats['booked'] ?></div><div class="stat-label"><?= te('Booked Slots') ?></div></div></div>
  <div class="col-4"><div class="card-md stat-card text-center"><div class="stat-value"><?= $stats['open'] ?></div><div class="stat-label"><?= te('Available Slots') ?></div></div></div>
  <div class="col-4"><div class="card-md stat-card text-center"><div class="stat-value text-danger"><?= $stats['blocked'] ?></div><div class="stat-label"><?= te('Blocked Slots') ?></div></div></div>
</div>

<div class="row g-3">
  <div class="col-xl-9">
    <div class="card-md">
      <div class="card-head"><h2><?= te('Weekly Schedule Grid') ?></h2>
        <div class="legend ms-auto"><span class="l-open"><?= te('Available') ?></span><span class="l-booked"><?= te('Booked') ?></span><span class="l-pending"><?= te('Pending') ?></span><span class="l-blocked"><?= te('Blocked') ?></span></div></div>
      <div class="card-body-md">
        <p class="small text-muted mb-2"><i class="bi bi-hand-index"></i> <?= $dentistId ? te('Click an open slot to block it for this dentist; click a blocked slot to reopen it; click a booking to open it.') : te('Click an open slot to block it for the whole clinic; click a blocked slot to reopen it; click a booking to open it.') ?></p>
        <div class="table-responsive">
          <table class="sched-grid">
            <thead><tr><th></th>
              <?php foreach ($days as $d): ?><th class="<?= $d === date('Y-m-d') ? 'today' : '' ?>"><?= e(ldate('D', $d)) ?><span class="dd"><?= e(ldate('M j', $d)) ?></span></th><?php endforeach; ?>
            </tr></thead>
            <tbody>
            <?php foreach ($times as $tm): ?>
              <tr><td class="time"><?= e(date('g:i A', strtotime($tm))) ?></td>
              <?php foreach ($days as $d): $c = $cells[$d][$tm]; $past = !empty($c['past']) ? ' past' : ''; ?>
                <td>
                <?php if ($c['type'] === 'closed'): ?>
                  <div class="cell closed"><?= LUNCH_START && $tm >= LUNCH_START && $tm < LUNCH_END ? te('Lunch') : '—' ?></div>
                <?php elseif ($c['type'] === 'blocked'): ?>
                  <form method="post" class="m-0" data-confirm="<?= te('Reopen this blocked time?') ?>"><?= csrf_field() ?><input type="hidden" name="do" value="unblock"><input type="hidden" name="block_id" value="<?= $c['block']['id'] ?>">
                    <button class="cell blocked<?= $past ?>" title="<?= te((string) $c['block']['reason']) ?>"><i class="bi bi-slash-circle"></i> <?= te('Blocked') ?></button></form>
                <?php elseif ($c['type'] === 'booked' || !empty($c['appts'])): $a = $c['appts'][0]; $cls = $a['is_emergency'] ? ' emergency' : ($a['status'] === 'pending' ? ' pending' : ''); ?>
                  <a class="cell booked<?= $cls . $past ?>" href="<?= url('admin/appointment.php?id=' . $a['id']) ?>" title="<?= e($a['contact_name'] . ' – ' . svc_t($a['service_name'])) ?>">
                    <span class="text-truncate" style="max-width:110px"><?= e(explode(' ', $a['contact_name'])[0]) ?><?= count($c['appts']) > 1 ? ' +' . (count($c['appts']) - 1) : '' ?></span>
                    <?php if ($c['type'] === 'open' && !$past): ?><span class="opacity-75">· <?= te('free') ?></span><?php endif; ?></a>
                <?php elseif ($past): ?>
                  <div class="cell closed past">—</div>
                <?php else: ?>
                  <form method="post" class="m-0"><?= csrf_field() ?><input type="hidden" name="do" value="block_slot"><input type="hidden" name="start" value="<?= e("$d $tm") ?>">
                    <button class="cell open" title="<?= te('Click to block this slot') ?>"><i class="bi bi-plus-lg"></i> <?= te('Open') ?></button></form>
                <?php endif; ?>
                </td>
              <?php endforeach; ?>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <div class="col-xl-3">
    <div class="card-md mb-3">
      <div class="card-head"><h2><?= te('Appointment Status Queue') ?></h2></div>
      <div class="card-body-md pt-1">
        <?php if (!$queue): ?><div class="text-muted small py-2"><?= te('No pending approvals.') ?></div><?php endif; ?>
        <?php foreach ($queue as $a): ?>
          <div class="appt-item d-block">
            <div class="d-flex justify-content-between"><a class="fw-bold text-reset" href="<?= url('admin/appointment.php?id=' . $a['id']) ?>"><?= e($a['contact_name']) ?></a><?= status_badge('pending') ?></div>
            <div class="small text-muted mb-2"><?= e(svc_t($a['service_name'])) ?> · <?= e(ldate('M j', $a['start_at']) . ' ' . fmt_time($a['start_at'])) ?></div>
            <div class="d-flex gap-1"><?= action_button($a, 'approve', t('Approve'), 'btn-primary flex-fill', 'check-lg', $self) ?><?= action_button($a, 'decline', t('Decline'), 'btn-outline-danger', 'x-lg', $self, t('Decline this request?')) ?></div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <form method="post" class="card-md mb-3">
      <div class="card-head"><h2><?= te('Block Time / Day Off') ?></h2></div>
      <div class="card-body-md">
        <?= csrf_field() ?><input type="hidden" name="do" value="block_range">
        <div class="mb-2"><label class="form-label" for="b_date"><?= te('Date') ?></label><input class="form-control form-control-sm" type="date" name="date" id="b_date" required min="<?= date('Y-m-d') ?>"></div>
        <div class="row g-2 mb-2">
          <div class="col-6"><label class="form-label" for="b_from"><?= te('From') ?></label><input class="form-control form-control-sm" type="time" name="from" id="b_from" value="<?= e(CLINIC_OPEN) ?>" step="<?= SLOT_INTERVAL * 60 ?>"></div>
          <div class="col-6"><label class="form-label" for="b_to"><?= te('To') ?></label><input class="form-control form-control-sm" type="time" name="to" id="b_to" value="<?= e(CLINIC_CLOSE) ?>" step="<?= SLOT_INTERVAL * 60 ?>"></div>
        </div>
        <div class="mb-2"><label class="form-label" for="b_dentist"><?= te('Applies to') ?></label>
          <select class="form-select form-select-sm" name="block_dentist" id="b_dentist"><option value="0"><?= te('Whole clinic') ?></option><?php foreach ($dentists as $d): ?><option value="<?= $d['id'] ?>"><?= e($d['name']) ?></option><?php endforeach; ?></select></div>
        <div class="mb-3"><label class="form-label" for="b_reason"><?= te('Reason') ?></label><input class="form-control form-control-sm" name="reason" id="b_reason" placeholder="<?= te('e.g. Holiday, seminar') ?>" maxlength="150"></div>
        <button class="btn btn-sm btn-primary w-100"><i class="bi bi-slash-circle"></i> <?= te('Block Time') ?></button>
      </div>
    </form>

    <div class="card-md">
      <div class="card-head"><h2><?= te('Upcoming Blocks') ?></h2></div>
      <div class="card-body-md pt-1">
        <?php if (!$allBlocks): ?><div class="text-muted small py-2"><?= te('No blocked time.') ?></div><?php endif; ?>
        <?php foreach ($allBlocks as $b): ?>
          <div class="appt-item py-2">
            <div class="flex-grow-1 small"><div class="fw-semibold"><?= e(ldate('D, M j', $b['start_at'])) ?> · <?= e(fmt_time($b['start_at']) . '–' . fmt_time($b['end_at'])) ?></div><div class="text-muted"><?= te((string) $b['reason']) ?> · <?= e($b['dentist_name'] ?? t('Whole clinic')) ?></div></div>
            <form method="post" data-confirm="<?= te('Remove this block?') ?>"><?= csrf_field() ?><input type="hidden" name="do" value="unblock"><input type="hidden" name="block_id" value="<?= $b['id'] ?>"><button class="btn btn-sm btn-light border" title="<?= te('Remove') ?>"><i class="bi bi-trash"></i></button></form>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
