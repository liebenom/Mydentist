<?php
/**
 * One-time installer: creates the database, tables and default accounts.
 * Open http://localhost/mydentist/install.php after copying the project to htdocs.
 */
define('SKIP_INSTALL_CHECK', true);
require __DIR__ . '/includes/bootstrap.php';

$done = null;
$error = null;
$installed = is_installed();

// Safety: if the database already has the clinic's data (for example the project folder was
// replaced and storage/installed.lock went missing), never run the installer over it.
// Just reconnect to the existing database and open the website. Nothing is erased.
if (!$installed) {
    $hasData = false;
    try {
        if (DB_DRIVER === 'sqlite') {
            // test database: check with a separate connection so the file can be replaced afterwards
            $hasData = is_file(DB_SQLITE_PATH) && filesize(DB_SQLITE_PATH) > 0
                && (int) (new PDO('sqlite:' . DB_SQLITE_PATH))->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0;
        } else {
            $hasData = (int) db_val('SELECT COUNT(*) FROM users') > 0;
        }
    } catch (Throwable $e) {
        $hasData = false;   // no database or tables yet: a real first-time install
    }
    if ($hasData) {
        if (!is_dir(ROOT_DIR . '/storage')) {
            mkdir(ROOT_DIR . '/storage', 0775, true);
        }
        file_put_contents(ROOT_DIR . '/storage/installed.lock', 'Existing database reconnected ' . now_str());
        header('Location: ' . url('index.php'));
        exit;
    }
}

if (!$installed && is_post()) {
    try {
        if (!is_dir(ROOT_DIR . '/storage')) {
            mkdir(ROOT_DIR . '/storage', 0775, true);
        }
        if (DB_DRIVER === 'sqlite') {
            if (file_exists(DB_SQLITE_PATH)) {
                unlink(DB_SQLITE_PATH);
            }
            $sql = file_get_contents(ROOT_DIR . '/database/schema.sqlite.sql');
            db()->exec($sql);
        } elseif (DB_DRIVER === 'sqlsrv') {
            // SQL Server: run the same script you can open in SSMS, batch by batch (split on GO)
            $server = db_connect(false);
            $sql = file_get_contents(ROOT_DIR . '/database/schema.sqlserver.sql');
            $sql = str_replace(["N'mydentist'", 'CREATE DATABASE mydentist;', 'USE mydentist;'],
                ["N'" . str_replace("'", "''", DB_NAME) . "'", 'CREATE DATABASE [' . DB_NAME . '];', 'USE [' . DB_NAME . '];'], $sql);
            foreach (preg_split('/^\s*GO\s*$/mi', str_replace("\r\n", "\n", $sql)) as $batch) {
                if (trim(preg_replace('#/\*.*?\*/#s', '', $batch)) !== '') {
                    $server->exec($batch);
                }
            }
        } else {
            $server = db_connect(false);
            $sql = file_get_contents(ROOT_DIR . '/database/schema.mysql.sql');
            $sql = str_replace(['CREATE DATABASE IF NOT EXISTS mydentist', 'USE mydentist;'],
                ['CREATE DATABASE IF NOT EXISTS `' . DB_NAME . '`', 'USE `' . DB_NAME . '`;'], $sql);
            $sql = preg_replace('/^\s*--.*$/m', '', $sql);
            foreach (array_filter(array_map('trim', explode(";\n", str_replace("\r\n", "\n", $sql)))) as $stmt) {
                $server->exec($stmt);
            }
        }
        file_put_contents(ROOT_DIR . '/storage/installed.lock', 'Installed ' . now_str());
        $done = 0;
    } catch (Throwable $ex) {
        $error = $ex->getMessage();
    }
}

$checks = [
    ['PHP 8.0 or newer', version_compare(PHP_VERSION, '8.0.0', '>='), PHP_VERSION],
    ['PDO ' . DB_DRIVER . ' extension' . (DB_DRIVER === 'sqlsrv' ? ' (Microsoft Drivers for PHP)' : ''), extension_loaded('pdo_' . DB_DRIVER), ''],
    ['GD extension (QR codes)', extension_loaded('gd'), ''],
    ['OpenSSL extension (email)', extension_loaded('openssl'), ''],
    ['storage/ folder writable', is_writable(ROOT_DIR . '/storage'), ''],
];
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Install · MyDentist</title>
<link rel="stylesheet" href="<?= lib('bootstrap.css') ?>">
<link rel="stylesheet" href="<?= lib('icons.css') ?>">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/style.css?v=2">
</head><body>
<main class="auth-wrap">
<div class="auth-card" style="max-width:560px">
  <div class="text-center mb-3"><img src="assets/img/logo.svg" width="48" height="48" alt=""><div class="brand-mini">MyDentist Dental Clinic</div></div>
  <h1>System Installer</h1>
  <p class="sub">Sets up the database for the Web-Based Dental Appointment System.</p>

  <?php if ($installed && $done === null): ?>
    <div class="alert alert-info">The system is already installed. To reinstall from scratch, delete <code>storage/installed.lock</code> and reload this page. <strong>Reinstalling erases all data.</strong></div>
    <a class="btn btn-primary w-100" href="index.php">Go to the website</a>
  <?php elseif ($done !== null): ?>
    <div class="alert alert-success"><i class="bi bi-check-circle"></i> Installation complete.</div>
    <table class="table table-sm">
      <thead><tr><th>Role</th><th>Username</th><th>Password</th></tr></thead>
      <tbody>
        <tr><td>Administrator</td><td><code>admin</code></td><td><code>Admin@123</code></td></tr>
        <tr><td>Clinic staff</td><td><code>staff</code></td><td><code>Staff@123</code></td></tr>
        <tr><td>Sample patient</td><td><code>maria</code></td><td><code>Patient@123</code></td></tr>
      </tbody>
    </table>
    <p class="small text-muted">Change these passwords from the Settings page after logging in.</p>
    <a class="btn btn-primary w-100" href="login.php?show=1">Log in</a>
  <?php else: ?>
    <?php if ($error): ?><div class="alert alert-danger"><strong>Installation failed:</strong> <?= e($error) ?><br><small>Is the SQL Server service running, and does the server name in config.php match what you use in SSMS? Is the pdo_sqlsrv extension enabled in php.ini? See README section 1.</small></div><?php endif; ?>
    <?php $missing = array_filter($checks, fn($c) => !$c[1]); ?>
    <?php if ($missing): /* only shown when something needs fixing */ ?>
      <div class="alert alert-warning small"><strong>Please fix these first:</strong>
        <ul class="mb-0 mt-1"><?php foreach ($missing as [$label]): ?><li><?= e($label) ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>
    <form method="post">
      <button class="btn btn-primary w-100"><i class="bi bi-database-add"></i> Install now</button>
    </form>
  <?php endif; ?>
</div>
</main>
</body></html>
