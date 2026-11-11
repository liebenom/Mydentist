<?php
/**
 * Database migrations. Run automatically (once) when the site is opened after an
 * update, so existing installations upgrade in place. Every migration only ADDS
 * columns or updates flags: no table is dropped and no record is deleted.
 *
 * The same changes for SQL Server are in database/migration_002.sqlserver.sql
 * (for running manually in SSMS, if preferred).
 */

const SCHEMA_VERSION = 4;

function schema_version(): int
{
    try {
        return (int) setting_get('schema_version', 1);
    } catch (Throwable $e) {
        return 1;
    }
}

function column_exists(string $table, string $column): bool
{
    try {
        if (DB_DRIVER === 'sqlite') {
            foreach (db_all("PRAGMA table_info($table)") as $c) {
                if ($c['name'] === $column) return true;
            }
            return false;
        }
        if (DB_DRIVER === 'sqlsrv') {
            return (bool) db_val('SELECT COUNT(*) FROM sys.columns WHERE object_id = OBJECT_ID(?) AND name = ?', ['dbo.' . $table, $column]);
        }
        return (bool) db_val('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$table, $column]);
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Migration 2
 *  - users.avatar       stored profile picture file name
 *  - appointments.is_demo  marks demo/sample appointments so they are never treated as real bookings
 *  - Guest Mode bookings are detached from accounts that were linked only by email
 *  - only Dr. Warren stays active as a dentist (others kept, inactive, for history)
 */
function migration_2(): void
{
    $pdo = db();
    $add = [
        ['users', 'avatar', [
            'sqlsrv' => 'ALTER TABLE dbo.users ADD avatar NVARCHAR(100) NULL',
            'mysql'  => 'ALTER TABLE users ADD COLUMN avatar VARCHAR(100) NULL',
            'sqlite' => 'ALTER TABLE users ADD COLUMN avatar TEXT NULL',
        ]],
        ['appointments', 'is_demo', [
            'sqlsrv' => 'ALTER TABLE dbo.appointments ADD is_demo BIT NOT NULL CONSTRAINT DF_appt_demo DEFAULT 0',
            'mysql'  => 'ALTER TABLE appointments ADD COLUMN is_demo TINYINT(1) NOT NULL DEFAULT 0',
            'sqlite' => 'ALTER TABLE appointments ADD COLUMN is_demo INTEGER NOT NULL DEFAULT 0',
        ]],
    ];
    foreach ($add as [$table, $col, $sql]) {
        if (!column_exists($table, $col)) {
            $pdo->exec($sql[DB_DRIVER]);
        }
    }
    // Privacy: older versions attached Guest Mode bookings to any account registered with the
    // same email. A GUEST booking belongs only to whoever holds its secret link, so detach them
    // (the booking itself is kept unchanged and the guest still opens it with their link).
    $linked = (int) db_val("SELECT COUNT(*) FROM appointments WHERE source = 'guest' AND patient_id IS NOT NULL");
    if ($linked) {
        q("UPDATE appointments SET patient_id = NULL WHERE source = 'guest' AND patient_id IS NOT NULL");
        setting_set('migration_2_detached_guest_bookings', $linked);
    }
    // Dr. Warren becomes the only bookable dentist; other dentists stay for history
    $warren = sole_dentist_id();
    if ($warren) {
        q('UPDATE dentists SET is_active = 0 WHERE id <> ?', [$warren]);
        q('UPDATE dentists SET is_active = 1 WHERE id = ?', [$warren]);
    }
}

/**
 * Migration 3
 *  - appointments.extra_services  more services booked in the same visit (",3,5,"); service_id stays the first one
 */
function migration_3(): void
{
    if (!column_exists('appointments', 'extra_services')) {
        db()->exec([
            'sqlsrv' => 'ALTER TABLE dbo.appointments ADD extra_services NVARCHAR(100) NULL',
            'mysql'  => 'ALTER TABLE appointments ADD COLUMN extra_services VARCHAR(100) NULL',
            'sqlite' => 'ALTER TABLE appointments ADD COLUMN extra_services TEXT NULL',
        ][DB_DRIVER]);
    }
}

/**
 * Migration 4
 *  - appointments.reminder_12_sent / reminder_6_sent  for the 12-hour and 6-hour reminder emails
 */
function migration_4(): void
{
    foreach (['reminder_12_sent' => 'DF_appt_r12', 'reminder_6_sent' => 'DF_appt_r6'] as $col => $df) {
        if (!column_exists('appointments', $col)) {
            db()->exec([
                'sqlsrv' => "ALTER TABLE dbo.appointments ADD $col BIT NOT NULL CONSTRAINT $df DEFAULT 0",
                'mysql'  => "ALTER TABLE appointments ADD COLUMN $col TINYINT(1) NOT NULL DEFAULT 0",
                'sqlite' => "ALTER TABLE appointments ADD COLUMN $col INTEGER NOT NULL DEFAULT 0",
            ][DB_DRIVER]);
        }
    }
}

function run_migrations(): void
{
    $v = schema_version();
    if ($v >= SCHEMA_VERSION) {
        return;
    }
    if ($v < 2) {
        migration_2();
        setting_set('schema_version', 2);
        try {
            db_insert('audit_logs', ['user_id' => null, 'action' => 'database_migrated', 'details' => 'Schema upgraded to version 2: users.avatar and appointments.is_demo added; ' . (int) setting_get('migration_2_detached_guest_bookings', 0) . ' guest booking(s) detached from email-linked accounts; Dr. Warren set as the only active dentist.', 'appointment_id' => null, 'ip_address' => client_ip(), 'created_at' => now_str()]);
        } catch (Throwable $e) {
        }
    }
    if ($v < 3) {
        migration_3();
        setting_set('schema_version', 3);
        try {
            db_insert('audit_logs', ['user_id' => null, 'action' => 'database_migrated', 'details' => 'Schema upgraded to version 3: appointments.extra_services added (several services per booking). No data changed.', 'appointment_id' => null, 'ip_address' => client_ip(), 'created_at' => now_str()]);
        } catch (Throwable $e) {
        }
    }
    if ($v < 4) {
        migration_4();
        setting_set('schema_version', 4);
        try {
            db_insert('audit_logs', ['user_id' => null, 'action' => 'database_migrated', 'details' => 'Schema upgraded to version 4: 12-hour and 6-hour reminder columns added. No data changed.', 'appointment_id' => null, 'ip_address' => client_ip(), 'created_at' => now_str()]);
        } catch (Throwable $e) {
        }
    }
}
