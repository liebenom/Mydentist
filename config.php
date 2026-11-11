<?php
/**
 * MyDentist Dental Clinic - system configuration.
 *
 * Edit the values below for your setup. To keep personal settings (passwords,
 * SMTP credentials) out of this file, create config.local.php and define the
 * same constants there; anything defined there wins.
 */

if (file_exists(__DIR__ . '/config.local.php')) {
    require __DIR__ . '/config.local.php';
}

function conf(string $name, $value): void
{
    if (!defined($name)) {
        define($name, $value);
    }
}

// ---------------------------------------------------------------- App
conf('APP_NAME', 'MyDentist Dental Clinic');
conf('APP_TAGLINE', 'Maramag, Bukidnon');
// Full URL of the site, used for links inside emails (no trailing slash)
conf('APP_URL', 'http://localhost/mydentist');
conf('APP_TIMEZONE', 'Asia/Manila');
conf('CLINIC_ADDRESS', 'Maramag, Bukidnon, Philippines');
conf('CLINIC_PHONE', '+63 900 000 0000');
conf('CLINIC_EMAIL', 'mydentist.clinic@example.com');

// ---------------------------------------------------------------- Database
// Microsoft SQL Server (managed in SSMS). Use the same "Server name" you type in SSMS.
//   SQL Server Express:         localhost\SQLEXPRESS
//   Default instance:           localhost   (or the PC name)
conf('DB_DRIVER', 'sqlsrv');         // 'sqlsrv' (SQL Server), 'mysql', or 'sqlite' (testing only)
conf('DB_HOST', 'localhost\\SQLEXPRESS');
conf('DB_PORT', 0);                  // 0 = default / named instance; e.g. 1433 if you set a fixed TCP port
conf('DB_NAME', 'mydentist');
// Leave DB_USER empty to log in with Windows Authentication (like SSMS's default).
// For SQL Server Authentication, enter the login, e.g. 'sa' and its password.
conf('DB_USER', '');
conf('DB_PASS', '');
conf('DB_SQLITE_PATH', __DIR__ . '/storage/mydentist.sqlite');

// ---------------------------------------------------------------- Dentist
// Dr. Warren is the only dentist. The existing dentist record whose name contains
// this text is used; other dentist records stay only for appointment history.
conf('DENTIST_NAME_MATCH', 'Warren');
conf('DENTIST_DEFAULT_NAME', 'Dr. Keith Warren Isidro');

// ---------------------------------------------------------------- Clinic hours & booking rules
conf('CLINIC_DAYS', [1, 2, 3, 4, 5, 6]);  // ISO weekday numbers: 1 = Mon ... 7 = Sun
conf('CLINIC_OPEN', '08:00');
conf('CLINIC_CLOSE', '17:00');
conf('LUNCH_START', '12:00');             // set both to '' to disable the lunch break
conf('LUNCH_END', '13:00');
conf('SLOT_INTERVAL', 30);                // minutes between possible start times
conf('BOOKING_WINDOW_DAYS', 60);          // how far ahead patients can book
conf('MIN_NOTICE_HOURS', 2);              // earliest booking = now + this many hours
conf('CHANGE_CUTOFF_HOURS', 2);           // patients can't reschedule/cancel within this window
conf('MAX_ACTIVE_PER_PATIENT', 3);        // limit on upcoming bookings per patient / guest email
// false = online bookings wait for staff approval (Pending)
// true  = online bookings are confirmed immediately
conf('AUTO_CONFIRM', false);

// ---------------------------------------------------------------- Email (SMTP)
// With MAIL_ENABLED = false, emails are written to storage/mail.log instead of
// being sent, so the system works offline for demos.
// Gmail: use smtp.gmail.com, port 587, 'tls', and an App Password
// (Google Account > Security > 2-Step Verification > App passwords).
conf('MAIL_ENABLED', false);
conf('SMTP_HOST', 'smtp.gmail.com');
conf('SMTP_PORT', 587);
conf('SMTP_SECURE', 'tls');               // 'tls' (STARTTLS, port 587) or 'ssl' (port 465)
conf('SMTP_USER', 'your.clinic@gmail.com');
conf('SMTP_PASS', 'your-16-char-app-password');
conf('MAIL_FROM', 'your.clinic@gmail.com');
conf('MAIL_FROM_NAME', 'MyDentist Dental Clinic');
// Set to false only if sending fails with a certificate error on Windows/XAMPP
conf('SMTP_VERIFY_SSL', true);

// ---------------------------------------------------------------- Scheduled tasks
// Key required to trigger cron/run.php from a browser: cron/run.php?key=...
conf('CRON_KEY', 'change-this-cron-key');
// Also run reminders/backups automatically when staff pages are opened
// (useful when no cron job / Task Scheduler is configured).
conf('PSEUDO_CRON', true);
conf('BACKUP_KEEP', 14);                  // number of automatic backups to keep
