<?php
/**
 * Automatic database backup and restore.
 * Backups are JSON snapshots of every table, stored in storage/backups/
 * (protected from web access by .htaccess). They restore on MySQL or SQLite.
 */

const BACKUP_TABLES = ['users', 'dentists', 'services', 'appointments', 'schedule_blocks', 'audit_logs', 'settings'];

function backup_dir(): string
{
    $dir = ROOT_DIR . '/storage/backups';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    return $dir;
}

function backup_create(string $type = 'manual'): string
{
    $data = ['app' => 'mydentist', 'version' => 1, 'type' => $type, 'created_at' => now_str(), 'tables' => []];
    foreach (BACKUP_TABLES as $t) {
        $data['tables'][$t] = db_all("SELECT * FROM $t");
    }
    $name = 'mydentist-backup-' . date('Ymd-His') . '-' . $type . '.json';
    file_put_contents(backup_dir() . '/' . $name, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    setting_set('last_backup_at', now_str());
    backup_prune();
    return $name;
}

function backup_list(): array
{
    $files = glob(backup_dir() . '/mydentist-backup-*.json') ?: [];
    rsort($files);
    $out = [];
    foreach ($files as $f) {
        preg_match('/backup-(\d{8}-\d{6})-(\w+)\.json$/', $f, $m);
        $out[] = [
            'file'    => basename($f),
            'size'    => filesize($f),
            'created' => isset($m[1]) ? DateTime::createFromFormat('Ymd-His', $m[1])->format('Y-m-d H:i:s') : date('Y-m-d H:i:s', filemtime($f)),
            'type'    => $m[2] ?? 'manual',
        ];
    }
    return $out;
}

function backup_path(string $file): ?string
{
    if (!preg_match('/^mydentist-backup-[\w\-]+\.json$/', $file)) {
        return null;   // prevents path traversal
    }
    $path = backup_dir() . '/' . $file;
    return is_file($path) ? $path : null;
}

/** Keep only the newest BACKUP_KEEP automatic backups (manual ones are kept). */
function backup_prune(): void
{
    $auto = array_values(array_filter(backup_list(), fn($b) => $b['type'] === 'auto'));
    foreach (array_slice($auto, BACKUP_KEEP) as $old) {
        @unlink(backup_dir() . '/' . $old['file']);
    }
}

function backup_restore(string $path): array
{
    $data = json_decode((string) file_get_contents($path), true);
    if (!is_array($data) || ($data['app'] ?? '') !== 'mydentist' || empty($data['tables'])) {
        throw new RuntimeException('This file is not a valid MyDentist backup.');
    }
    $pdo = db();
    $counts = [];
    // Tables are emptied children-first and refilled parents-first, so foreign keys stay valid.
    if (DB_DRIVER === 'sqlite') {
        $pdo->exec('PRAGMA foreign_keys = OFF');
    } elseif (DB_DRIVER === 'mysql') {
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    }
    $pdo->beginTransaction();
    try {
        foreach (array_reverse(BACKUP_TABLES) as $t) {
            $pdo->exec("DELETE FROM $t");
        }
        foreach (BACKUP_TABLES as $t) {
            $rows = $data['tables'][$t] ?? [];
            // SQL Server only accepts explicit ids in IDENTITY columns with IDENTITY_INSERT on
            $identity = DB_DRIVER === 'sqlsrv' && $t !== 'settings' && $rows;
            if ($identity) {
                $pdo->exec("SET IDENTITY_INSERT $t ON");
            }
            foreach ($rows as $row) {
                db_insert($t, $row);
            }
            if ($identity) {
                $pdo->exec("SET IDENTITY_INSERT $t OFF");
            }
            $counts[$t] = count($rows);
        }
        $pdo->commit();
    } catch (Throwable $ex) {
        $pdo->rollBack();
        throw $ex;
    } finally {
        if (DB_DRIVER === 'sqlite') {
            $pdo->exec('PRAGMA foreign_keys = ON');
        } elseif (DB_DRIVER === 'mysql') {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        }
    }
    return $counts;
}
