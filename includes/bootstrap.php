<?php
/**
 * Loaded at the top of every page. Sets up config, session, database and helpers.
 */
declare(strict_types=1);

define('ROOT_DIR', dirname(__DIR__));
require_once ROOT_DIR . '/config.php';

date_default_timezone_set(APP_TIMEZONE);
error_reporting(E_ALL);
ini_set('display_errors', '1');

// Base path of the app inside the web server (e.g. "/mydentist" on XAMPP, "" on php -S)
if (!defined('BASE_PATH')) {
    $base = '';
    if (PHP_SAPI !== 'cli' && !empty($_SERVER['DOCUMENT_ROOT'])) {
        $root = str_replace('\\', '/', (string) realpath(ROOT_DIR));
        $doc  = rtrim(str_replace('\\', '/', (string) realpath($_SERVER['DOCUMENT_ROOT'])), '/');
        if ($doc !== '' && stripos($root, $doc) === 0) {
            $base = substr($root, strlen($doc));
        } else {
            $base = (string) parse_url(APP_URL, PHP_URL_PATH);
        }
    }
    define('BASE_PATH', rtrim($base, '/'));
}

if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_NONE) {
    session_name('MYDENTISTSESSID');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => BASE_PATH === '' ? '/' : BASE_PATH . '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);
    session_start();
}

require_once ROOT_DIR . '/includes/db.php';
require_once ROOT_DIR . '/includes/functions.php';
require_once ROOT_DIR . '/includes/auth.php';
require_once ROOT_DIR . '/includes/i18n.php';
require_once ROOT_DIR . '/includes/mailer.php';
require_once ROOT_DIR . '/includes/notifications.php';
require_once ROOT_DIR . '/includes/scheduling.php';
require_once ROOT_DIR . '/includes/backup.php';
require_once ROOT_DIR . '/includes/tasks.php';
require_once ROOT_DIR . '/includes/migrations.php';
require_once ROOT_DIR . '/includes/avatars.php';

// Send visitors to the installer until the database has been set up
if (PHP_SAPI !== 'cli' && !defined('SKIP_INSTALL_CHECK') && !is_installed()) {
    header('Location: ' . BASE_PATH . '/install.php');
    exit;
}

// Upgrade an existing database in place (add-only changes, runs once)
if (is_installed()) {
    run_migrations();
}
