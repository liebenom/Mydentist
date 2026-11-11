-- =====================================================================
--  MyDentist Dental Clinic - Web-Based Dental Appointment System
--  MySQL / MariaDB schema (XAMPP compatible)
--
--  Easiest setup: open http://localhost/mydentist/install.php
--  Manual setup:  import this file in phpMyAdmin (only if you use MySQL instead of SQL Server) (it creates the
--                 `mydentist` database, all tables and default data).
-- =====================================================================

CREATE DATABASE IF NOT EXISTS mydentist CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE mydentist;

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS audit_logs;
DROP TABLE IF EXISTS schedule_blocks;
DROP TABLE IF EXISTS appointments;
DROP TABLE IF EXISTS services;
DROP TABLE IF EXISTS dentists;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS settings;
SET FOREIGN_KEY_CHECKS = 1;

-- Accounts: patients, clinic staff and administrators (role-based access)
CREATE TABLE users (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    role          VARCHAR(10)  NOT NULL DEFAULT 'patient',   -- patient | staff | admin
    full_name     VARCHAR(120) NOT NULL,
    username      VARCHAR(50)  NOT NULL UNIQUE,
    email         VARCHAR(150) NOT NULL UNIQUE,
    phone         VARCHAR(30)  NULL,
    password_hash VARCHAR(255) NOT NULL,                     -- bcrypt via password_hash()
    is_active     TINYINT(1)   NOT NULL DEFAULT 1,
    avatar        VARCHAR(100) NULL,                         -- profile picture file name
    last_login_at DATETIME     NULL,
    created_at    DATETIME     NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE dentists (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(120) NOT NULL,
    specialty  VARCHAR(120) NULL,
    is_active  TINYINT(1)   NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Dental services; duration drives how long a booking occupies the schedule
CREATE TABLE services (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name         VARCHAR(120)  NOT NULL,
    description  VARCHAR(255)  NULL,
    category     VARCHAR(10)   NOT NULL DEFAULT 'routine',   -- routine | advanced
    duration_min INT UNSIGNED  NOT NULL DEFAULT 30,
    price        DECIMAL(10,2) NOT NULL DEFAULT 0,
    icon         VARCHAR(40)   NOT NULL DEFAULT 'bi-heart-pulse',
    is_active    TINYINT(1)    NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE appointments (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code            VARCHAR(20)  NOT NULL UNIQUE,            -- reference no. encoded in the QR code
    manage_token    VARCHAR(64)  NOT NULL,                   -- secret for guest manage links
    patient_id      INT UNSIGNED NULL,                       -- NULL for guest / walk-in
    contact_name    VARCHAR(120) NOT NULL,
    contact_email   VARCHAR(150) NULL,
    contact_phone   VARCHAR(30)  NULL,
    service_id      INT UNSIGNED NOT NULL,
    dentist_id      INT UNSIGNED NOT NULL,
    start_at        DATETIME     NOT NULL,
    end_at          DATETIME     NOT NULL,
    status          VARCHAR(12)  NOT NULL DEFAULT 'pending', -- pending|confirmed|completed|cancelled|no_show
    source          VARCHAR(12)  NOT NULL DEFAULT 'online',  -- online|guest|walk_in|emergency
    is_emergency    TINYINT(1)   NOT NULL DEFAULT 0,
    patient_notes   TEXT         NULL,
    staff_notes     TEXT         NULL,
    reminder_24_sent TINYINT(1)  NOT NULL DEFAULT 0,
    reminder_1_sent  TINYINT(1)  NOT NULL DEFAULT 0,
    reminder_12_sent TINYINT(1)  NOT NULL DEFAULT 0,
    reminder_6_sent  TINYINT(1)  NOT NULL DEFAULT 0,
    checked_in_at   DATETIME     NULL,
    extra_services  VARCHAR(100) NULL,                           -- more services in the same visit, e.g. ,3,5,
    is_demo         TINYINT(1)   NOT NULL DEFAULT 0,          -- demo/sample record
    created_at      DATETIME     NOT NULL,
    updated_at      DATETIME     NOT NULL,
    INDEX idx_appt_time (start_at, end_at),
    INDEX idx_appt_status (status),
    INDEX idx_appt_dentist (dentist_id, start_at),
    CONSTRAINT fk_appt_patient FOREIGN KEY (patient_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_appt_service FOREIGN KEY (service_id) REFERENCES services(id),
    CONSTRAINT fk_appt_dentist FOREIGN KEY (dentist_id) REFERENCES dentists(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Blocked time (holidays, breaks, dentist unavailable). dentist_id NULL = whole clinic
CREATE TABLE schedule_blocks (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    dentist_id INT UNSIGNED NULL,
    start_at   DATETIME     NOT NULL,
    end_at     DATETIME     NOT NULL,
    reason     VARCHAR(150) NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME     NOT NULL,
    INDEX idx_block_time (start_at, end_at),
    CONSTRAINT fk_block_dentist FOREIGN KEY (dentist_id) REFERENCES dentists(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Accountability trail of administrative actions
CREATE TABLE audit_logs (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id        INT UNSIGNED NULL,
    action         VARCHAR(60)  NOT NULL,
    details        TEXT         NULL,
    appointment_id INT UNSIGNED NULL,
    ip_address     VARCHAR(45)  NULL,
    created_at     DATETIME     NOT NULL,
    INDEX idx_audit_time (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Key/value store for system state (last cron run, last backup, ...)
CREATE TABLE settings (
    skey   VARCHAR(60) PRIMARY KEY,
    svalue TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
--  Default data
--  admin / Admin@123   staff / Staff@123   maria / Patient@123
--  CHANGE THESE PASSWORDS after the first login (Settings page).
-- ---------------------------------------------------------------------
INSERT INTO users (role, full_name, username, email, phone, password_hash, created_at) VALUES
('admin',   'Dr. Keith Warren Isidro', 'admin', 'admin@mydentist.local', '+63 900 000 0001', '$2y$10$1pVyyvpZM/VrOD0ax6lEEehU9z1bcW.i293Y1s7y07q1Efj8ucEjW', NOW()),
('staff',   'Front Desk Staff',        'staff', 'staff@mydentist.local', '+63 900 000 0002', '$2y$10$x2jHyUdrGqFAzIEF58CekegE./nzdB1omDMEeZzq/Cuc5WCpIBysu', NOW()),
('patient', 'Maria Santos',            'maria', 'maria@example.com',     '+63 917 123 4567', '$2y$10$acfKq16SOJnLLdoz.FLfhubtV9dvE6hCoc96tiVPSq0M18btTZ/E6', NOW());

INSERT INTO dentists (name, specialty) VALUES
('Dr. Keith Warren Isidro', 'General & Cosmetic Dentistry');

INSERT INTO settings (skey, svalue) VALUES ('schema_version', '4');

INSERT INTO services (name, description, category, duration_min, price, icon) VALUES
('Dental Check-Up',          'Routine oral examination and consultation',        'routine',  30,  500, 'bi-clipboard2-pulse'),
('Teeth Cleaning',           'Oral prophylaxis: scaling and polishing',          'routine',  45,  800, 'bi-stars'),
('Pediatric Dentistry',      'Check-up and care for children',                   'routine',  45,  700, 'bi-emoji-smile'),
('Tooth Filling',            'Restoration of decayed or chipped teeth',          'advanced', 60, 1000, 'bi-bandaid'),
('Tooth Extraction',         'Simple tooth removal',                             'advanced', 60, 1000, 'bi-scissors'),
('Teeth Whitening',          'In-clinic whitening session',                      'advanced', 60, 2500, 'bi-brightness-high'),
('Root Canal Therapy',       'Endodontic treatment session',                     'advanced', 90, 5000, 'bi-activity'),
('Braces Adjustment',        'Orthodontic wire adjustment',                      'advanced', 30, 1000, 'bi-sliders'),
('Dental Implant Consult',   'Assessment and treatment planning for implants',   'advanced', 60, 1000, 'bi-gem'),
('Cosmetic Veneer Consult',  'Assessment for veneers and smile design',          'advanced', 60, 1200, 'bi-magic');
