<?php
/**
 * Scheduled tasks: appointment reminders (24 h & 1 h before) + daily automatic backup.
 * Run every 5–15 minutes.
 *
 *   Windows (XAMPP), Task Scheduler action:
 *       C:\xampp\php\php.exe C:\xampp\htdocs\mydentist\cron\run.php
 *   Linux cron:
 *       *\/10 * * * * php /var/www/mydentist/cron/run.php
 *   Or from a browser / online cron service:
 *       http://localhost/mydentist/cron/run.php?key=YOUR_CRON_KEY
 */
define('SKIP_INSTALL_CHECK', true);
require __DIR__ . '/../includes/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    if (!hash_equals(CRON_KEY, (string) ($_GET['key'] ?? ''))) {
        http_response_code(403);
        exit('Forbidden');
    }
    header('Content-Type: text/plain');
}
if (!is_installed()) {
    exit("Not installed yet.\n");
}
$result = run_scheduled_tasks();
echo '[' . now_str() . '] Reminders sent: 24h=' . $result['reminders']['reminder24'] . ', 12h=' . $result['reminders']['reminder12'] . ', 6h=' . $result['reminders']['reminder6']
    . ($result['backup'] ? ', backup: ' . $result['backup'] : '') . PHP_EOL;
