<?php
/** Backup & Restore (admin): automatic daily backups, manual backups, download, upload and restore. */
require __DIR__ . '/../includes/bootstrap.php';
$user = require_admin();

if (input('download')) {
    $path = backup_path((string) input('download'));
    if ($path) {
        audit('backup_downloaded', basename($path));
        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="' . basename($path) . '"');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;
    }
    flash('danger', 'Backup file not found.');
    redirect('admin/backup.php');
}

if (is_post()) {
    csrf_check();
    $do = input('do');
    try {
        if ($do === 'create') {
            $name = backup_create('manual');
            audit('backup_created', $name);
            flash('success', 'Backup created: ' . e($name));
        } elseif ($do === 'restore' || $do === 'upload') {
            if ($do === 'upload') {
                $f = $_FILES['file'] ?? null;
                if (!$f || $f['error'] !== UPLOAD_ERR_OK || $f['size'] > 50 * 1024 * 1024) {
                    throw new RuntimeException('Please choose a backup file (.json) to upload.');
                }
                $path = $f['tmp_name'];
                $label = 'uploaded file ' . basename($f['name']);
            } else {
                $path = backup_path((string) input('file'));
                if (!$path) throw new RuntimeException('Backup file not found.');
                $label = basename($path);
            }
            if (input('confirm') !== 'RESTORE') {
                throw new RuntimeException('Type RESTORE in the confirmation box to continue.');
            }
            $safety = backup_create('prerestore');
            $counts = backup_restore($path);
            // the restored data may not contain this admin session's user: re-check login
            audit('backup_restored', "Restored from $label (safety copy: $safety). Rows: " . json_encode($counts));
            flash('success', 'Database restored from ' . e($label) . '. A safety backup of the previous data was saved as ' . e($safety) . '.');
        } elseif ($do === 'delete') {
            $path = backup_path((string) input('file'));
            if ($path) {
                unlink($path);
                audit('backup_deleted', basename($path));
                flash('success', 'Backup deleted.');
            }
        }
    } catch (Throwable $ex) {
        flash('danger', e($ex->getMessage()));
    }
    redirect('admin/backup.php');
}

$backups = backup_list();
$title = 'Backup & Restore';
$subtitle = 'Protect appointment records from accidental loss';
$layout = 'admin';
$active = 'backup';
include __DIR__ . '/../includes/header.php';
$types = ['auto' => ['Automatic', 'tint-teal'], 'manual' => ['Manual', 'tint-blue'], 'prerestore' => ['Before restore', 'tint-amber'], 'predemo' => ['Before demo reset', 'tint-amber']];
?>
<div class="row g-3">
  <div class="col-xl-8">
    <div class="card-md">
      <div class="card-head"><h2>Backups</h2>
        <form method="post" class="ms-auto"><?= csrf_field() ?><input type="hidden" name="do" value="create"><button class="btn btn-sm btn-primary"><i class="bi bi-database-add"></i> Back Up Now</button></form></div>
      <div class="table-responsive"><table class="table table-md">
        <thead><tr><th>Created</th><th>Type</th><th class="text-end">Size</th><th class="text-end">Actions</th></tr></thead>
        <tbody>
        <?php foreach ($backups as $b): ?>
          <tr>
            <td><div class="fw-semibold"><?= e(fmt_datetime($b['created'])) ?></div><div class="small text-muted"><?= e($b['file']) ?></div></td>
            <td><span class="badge <?= $types[$b['type']][1] ?? 'text-bg-light' ?>"><?= e($types[$b['type']][0] ?? $b['type']) ?></span></td>
            <td class="text-end small"><?= number_format($b['size'] / 1024, 1) ?> KB</td>
            <td class="text-end text-nowrap">
              <a class="btn btn-sm btn-light border" href="?download=<?= e(urlencode($b['file'])) ?>" title="Download"><i class="bi bi-download"></i></a>
              <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#restoreModal" data-file="<?= e($b['file']) ?>" data-when="<?= e(fmt_datetime($b['created'])) ?>"><i class="bi bi-arrow-counterclockwise"></i> Restore</button>
              <form method="post" class="d-inline" data-confirm="Delete this backup file permanently?"><?= csrf_field() ?><input type="hidden" name="do" value="delete"><input type="hidden" name="file" value="<?= e($b['file']) ?>"><button class="btn btn-sm btn-light border" title="Delete"><i class="bi bi-trash"></i></button></form>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$backups): ?><tr><td colspan="4" class="text-center text-muted py-4">No backups yet. Click "Back Up Now".</td></tr><?php endif; ?>
        </tbody></table></div>
    </div>
  </div>
  <div class="col-xl-4">
    <div class="card-md mb-3"><div class="card-head"><h2>Automatic Backups</h2></div>
      <div class="card-body-md small">
        <p class="mb-2">A full backup is created automatically <strong>once every 24 hours</strong> by the scheduled task. The newest <?= BACKUP_KEEP ?> automatic backups are kept.</p>
        <div class="d-flex justify-content-between border-top pt-2"><span class="text-muted">Last automatic backup</span><strong><?= e(fmt_datetime(setting_get('last_auto_backup_at')) ?: 'Not yet') ?></strong></div>
        <div class="d-flex justify-content-between pt-1"><span class="text-muted">Scheduled task last ran</span><strong><?= e(fmt_datetime(setting_get('last_cron_at')) ?: 'Not yet') ?></strong></div>
        <p class="text-muted mt-2 mb-0">Copy backup files to a USB drive or cloud storage regularly so they survive a computer failure.</p>
      </div></div>
    <div class="card-md"><div class="card-head"><h2>Restore from File</h2></div>
      <div class="card-body-md">
        <form method="post" enctype="multipart/form-data"><?= csrf_field() ?><input type="hidden" name="do" value="upload">
          <input class="form-control form-control-sm mb-2" type="file" name="file" accept=".json,application/json" required aria-label="Backup file">
          <label class="form-label small" for="c2">Type <code>RESTORE</code> to confirm</label>
          <input class="form-control form-control-sm mb-2" id="c2" name="confirm" autocomplete="off" required pattern="RESTORE">
          <button class="btn btn-sm btn-outline-danger w-100"><i class="bi bi-upload"></i> Upload & Restore</button>
        </form>
      </div></div>
  </div>
</div>

<div class="modal fade" id="restoreModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog"><form method="post" class="modal-content">
  <?= csrf_field() ?><input type="hidden" name="do" value="restore"><input type="hidden" name="file" id="rFile">
  <div class="modal-header"><h5 class="modal-title">Restore backup</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
  <div class="modal-body">
    <div class="alert alert-warning small"><i class="bi bi-exclamation-triangle"></i> All current data will be replaced with the backup from <strong id="rWhen"></strong>. Bookings made after that time will be lost. A safety backup of the current data is taken first.</div>
    <label class="form-label" for="c1">Type <code>RESTORE</code> to confirm</label>
    <input class="form-control" id="c1" name="confirm" autocomplete="off" required pattern="RESTORE">
  </div>
  <div class="modal-footer"><button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button><button class="btn btn-danger">Restore Database</button></div>
</form></div></div>
<script>
document.getElementById('restoreModal').addEventListener('show.bs.modal', function (ev) {
  document.getElementById('rFile').value = ev.relatedTarget.dataset.file;
  document.getElementById('rWhen').textContent = ev.relatedTarget.dataset.when;
});
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
