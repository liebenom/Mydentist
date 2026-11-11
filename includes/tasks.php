<?php
/**
 * Scheduled tasks: appointment reminders (24, 12 and 6 hours before) and the daily
 * automatic backup. Run by cron/run.php every 5-15 minutes, or automatically
 * when staff pages are opened (PSEUDO_CRON).
 */

/**
 * Reminder emails for confirmed appointments: 24 hours, 12 hours and 6 hours before.
 * Each reminder is sent once. If a booking is made late (e.g. 8 hours before), only the
 * reminder that is due now is sent, so the patient never gets several at the same time.
 */
function send_due_reminders(): array
{
    $now = time();
    $sent = ['reminder24' => 0, 'reminder12' => 0, 'reminder6' => 0];
    $rows = db_all(
        "SELECT id, start_at, reminder_24_sent, reminder_12_sent, reminder_6_sent FROM appointments
         WHERE status = 'confirmed' AND contact_email IS NOT NULL AND contact_email <> ''
         AND start_at > ? AND start_at <= ? AND (reminder_24_sent = 0 OR reminder_12_sent = 0 OR reminder_6_sent = 0)",
        [date('Y-m-d H:i:s', $now), date('Y-m-d H:i:s', $now + 24 * 3600)]
    );
    foreach ($rows as $r) {
        $hoursLeft = (strtotime($r['start_at']) - $now) / 3600;
        if ($hoursLeft <= 6) {
            if (!$r['reminder_6_sent']) {
                notify((int) $r['id'], 'reminder6');
                q('UPDATE appointments SET reminder_6_sent = 1, reminder_12_sent = 1, reminder_24_sent = 1 WHERE id = ?', [$r['id']]);
                $sent['reminder6']++;
            }
        } elseif ($hoursLeft <= 12) {
            if (!$r['reminder_12_sent']) {
                notify((int) $r['id'], 'reminder12');
                q('UPDATE appointments SET reminder_12_sent = 1, reminder_24_sent = 1 WHERE id = ?', [$r['id']]);
                $sent['reminder12']++;
            }
        } elseif (!$r['reminder_24_sent']) {
            notify((int) $r['id'], 'reminder24');
            q('UPDATE appointments SET reminder_24_sent = 1 WHERE id = ?', [$r['id']]);
            $sent['reminder24']++;
        }
    }
    return $sent;
}

function run_scheduled_tasks(): array
{
    $result = ['reminders' => send_due_reminders(), 'backup' => null];
    $last = setting_get('last_auto_backup_at');
    if (!$last || strtotime($last) < time() - 24 * 3600) {
        $result['backup'] = backup_create('auto');
        setting_set('last_auto_backup_at', now_str());
    }
    setting_set('last_cron_at', now_str());
    return $result;
}

/** Called from staff pages: runs the tasks at most every 5 minutes. */
function maybe_run_scheduled_tasks(): void
{
    if (!PSEUDO_CRON) {
        return;
    }
    $last = setting_get('last_cron_at');
    if ($last && strtotime($last) > time() - 300) {
        return;
    }
    try {
        run_scheduled_tasks();
    } catch (Throwable $ex) {
        file_put_contents(ROOT_DIR . '/storage/cron_errors.log', '[' . now_str() . '] ' . $ex->getMessage() . PHP_EOL, FILE_APPEND);
    }
}
