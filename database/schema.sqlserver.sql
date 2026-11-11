/* =====================================================================
   MyDentist Dental Clinic - Web-Based Dental Appointment System
   Microsoft SQL Server schema  (run in SQL Server Management Studio)

   Option A (easiest): open http://localhost/mydentist/install.php
   Option B (SSMS):    File > Open > this file, then press Execute (F5).
                       It creates the [mydentist] database, all tables and
                       the default accounts / dentists / services.
                       Afterwards create an empty file storage/installed.lock
   ===================================================================== */

IF DB_ID(N'mydentist') IS NULL
    CREATE DATABASE mydentist;
GO

USE mydentist;
GO

/* Drop in dependency order so the script can be re-run */
IF OBJECT_ID(N'dbo.audit_logs', N'U')      IS NOT NULL DROP TABLE dbo.audit_logs;
IF OBJECT_ID(N'dbo.schedule_blocks', N'U') IS NOT NULL DROP TABLE dbo.schedule_blocks;
IF OBJECT_ID(N'dbo.appointments', N'U')    IS NOT NULL DROP TABLE dbo.appointments;
IF OBJECT_ID(N'dbo.services', N'U')        IS NOT NULL DROP TABLE dbo.services;
IF OBJECT_ID(N'dbo.dentists', N'U')        IS NOT NULL DROP TABLE dbo.dentists;
IF OBJECT_ID(N'dbo.users', N'U')           IS NOT NULL DROP TABLE dbo.users;
IF OBJECT_ID(N'dbo.settings', N'U')        IS NOT NULL DROP TABLE dbo.settings;
GO

/* Accounts: patients, clinic staff and administrators (role-based access) */
CREATE TABLE dbo.users (
    id            INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_users PRIMARY KEY,
    role          NVARCHAR(10)  NOT NULL CONSTRAINT DF_users_role DEFAULT N'patient'
                  CONSTRAINT CK_users_role CHECK (role IN (N'patient', N'staff', N'admin')),
    full_name     NVARCHAR(120) NOT NULL,
    username      NVARCHAR(50)  NOT NULL CONSTRAINT UQ_users_username UNIQUE,
    email         NVARCHAR(150) NOT NULL CONSTRAINT UQ_users_email UNIQUE,
    phone         NVARCHAR(30)  NULL,
    password_hash NVARCHAR(255) NOT NULL,          -- bcrypt hash from PHP password_hash()
    is_active     BIT           NOT NULL CONSTRAINT DF_users_active DEFAULT 1,
    avatar        NVARCHAR(100) NULL,                -- profile picture file name (storage/avatars)
    last_login_at DATETIME2(0)  NULL,
    created_at    DATETIME2(0)  NOT NULL
);
GO

CREATE TABLE dbo.dentists (
    id         INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_dentists PRIMARY KEY,
    name       NVARCHAR(120) NOT NULL,
    specialty  NVARCHAR(120) NULL,
    is_active  BIT           NOT NULL CONSTRAINT DF_dentists_active DEFAULT 1
);
GO

/* Dental services; duration drives how long a booking occupies the schedule */
CREATE TABLE dbo.services (
    id           INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_services PRIMARY KEY,
    name         NVARCHAR(120) NOT NULL,
    description  NVARCHAR(255) NULL,
    category     NVARCHAR(10)  NOT NULL CONSTRAINT DF_services_category DEFAULT N'routine'
                 CONSTRAINT CK_services_category CHECK (category IN (N'routine', N'advanced')),
    duration_min INT           NOT NULL CONSTRAINT DF_services_duration DEFAULT 30,
    price        DECIMAL(10,2) NOT NULL CONSTRAINT DF_services_price DEFAULT 0,
    icon         NVARCHAR(40)  NOT NULL CONSTRAINT DF_services_icon DEFAULT N'bi-heart-pulse',
    is_active    BIT           NOT NULL CONSTRAINT DF_services_active DEFAULT 1
);
GO

CREATE TABLE dbo.appointments (
    id               INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_appointments PRIMARY KEY,
    code             NVARCHAR(20)  NOT NULL CONSTRAINT UQ_appointments_code UNIQUE,  -- reference no. in the QR code
    manage_token     NVARCHAR(64)  NOT NULL,           -- secret for guest manage links
    patient_id       INT           NULL CONSTRAINT FK_appt_patient REFERENCES dbo.users(id) ON DELETE SET NULL,
    contact_name     NVARCHAR(120) NOT NULL,
    contact_email    NVARCHAR(150) NULL,
    contact_phone    NVARCHAR(30)  NULL,
    service_id       INT           NOT NULL CONSTRAINT FK_appt_service REFERENCES dbo.services(id),
    dentist_id       INT           NOT NULL CONSTRAINT FK_appt_dentist REFERENCES dbo.dentists(id),
    start_at         DATETIME2(0)  NOT NULL,
    end_at           DATETIME2(0)  NOT NULL,
    status           NVARCHAR(12)  NOT NULL CONSTRAINT DF_appt_status DEFAULT N'pending'
                     CONSTRAINT CK_appt_status CHECK (status IN (N'pending', N'confirmed', N'completed', N'cancelled', N'no_show')),
    source           NVARCHAR(12)  NOT NULL CONSTRAINT DF_appt_source DEFAULT N'online'
                     CONSTRAINT CK_appt_source CHECK (source IN (N'online', N'guest', N'walk_in', N'emergency')),
    is_emergency     BIT           NOT NULL CONSTRAINT DF_appt_emergency DEFAULT 0,
    patient_notes    NVARCHAR(MAX) NULL,
    staff_notes      NVARCHAR(MAX) NULL,
    reminder_24_sent BIT           NOT NULL CONSTRAINT DF_appt_r24 DEFAULT 0,
    reminder_1_sent  BIT           NOT NULL CONSTRAINT DF_appt_r1 DEFAULT 0,
    reminder_12_sent BIT           NOT NULL CONSTRAINT DF_appt_r12 DEFAULT 0,
    reminder_6_sent  BIT           NOT NULL CONSTRAINT DF_appt_r6 DEFAULT 0,
    checked_in_at    DATETIME2(0)  NULL,
    extra_services   NVARCHAR(100) NULL,                                           -- more services in the same visit, e.g. ,3,5,
    is_demo          BIT           NOT NULL CONSTRAINT DF_appt_demo DEFAULT 0,   -- demo/sample record, not a real booking
    created_at       DATETIME2(0)  NOT NULL,
    updated_at       DATETIME2(0)  NOT NULL,
    CONSTRAINT CK_appt_times CHECK (end_at > start_at)
);
CREATE INDEX IX_appt_time    ON dbo.appointments (start_at, end_at);
CREATE INDEX IX_appt_status  ON dbo.appointments (status);
CREATE INDEX IX_appt_dentist ON dbo.appointments (dentist_id, start_at);
CREATE INDEX IX_appt_patient ON dbo.appointments (patient_id);
GO

/* Blocked time (holidays, breaks, dentist unavailable). dentist_id NULL = whole clinic */
CREATE TABLE dbo.schedule_blocks (
    id         INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_schedule_blocks PRIMARY KEY,
    dentist_id INT           NULL CONSTRAINT FK_block_dentist REFERENCES dbo.dentists(id) ON DELETE CASCADE,
    start_at   DATETIME2(0)  NOT NULL,
    end_at     DATETIME2(0)  NOT NULL,
    reason     NVARCHAR(150) NULL,
    created_by INT           NULL,
    created_at DATETIME2(0)  NOT NULL
);
CREATE INDEX IX_block_time ON dbo.schedule_blocks (start_at, end_at);
GO

/* Accountability trail of administrative actions */
CREATE TABLE dbo.audit_logs (
    id             INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_audit_logs PRIMARY KEY,
    user_id        INT           NULL,
    action         NVARCHAR(60)  NOT NULL,
    details        NVARCHAR(MAX) NULL,
    appointment_id INT           NULL,
    ip_address     NVARCHAR(45)  NULL,
    created_at     DATETIME2(0)  NOT NULL
);
CREATE INDEX IX_audit_time ON dbo.audit_logs (created_at);
GO

/* Key/value store for system state (last cron run, last backup, ...) */
CREATE TABLE dbo.settings (
    skey   NVARCHAR(60)  NOT NULL CONSTRAINT PK_settings PRIMARY KEY,
    svalue NVARCHAR(MAX) NULL
);
GO

/* ---------------------------------------------------------------------
   Default data
   admin / Admin@123    staff / Staff@123    maria / Patient@123
   CHANGE THESE PASSWORDS after the first login (Settings page).
   --------------------------------------------------------------------- */
INSERT INTO dbo.users (role, full_name, username, email, phone, password_hash, created_at) VALUES
(N'admin',   N'Dr. Keith Warren Isidro', N'admin', N'admin@mydentist.local', N'+63 900 000 0001', N'$2y$10$1pVyyvpZM/VrOD0ax6lEEehU9z1bcW.i293Y1s7y07q1Efj8ucEjW', SYSDATETIME()),
(N'staff',   N'Front Desk Staff',        N'staff', N'staff@mydentist.local', N'+63 900 000 0002', N'$2y$10$x2jHyUdrGqFAzIEF58CekegE./nzdB1omDMEeZzq/Cuc5WCpIBysu', SYSDATETIME()),
(N'patient', N'Maria Santos',            N'maria', N'maria@example.com',     N'+63 917 123 4567', N'$2y$10$acfKq16SOJnLLdoz.FLfhubtV9dvE6hCoc96tiVPSq0M18btTZ/E6', SYSDATETIME());

-- Dr. Warren is the clinic's only dentist
INSERT INTO dbo.dentists (name, specialty) VALUES
(N'Dr. Keith Warren Isidro', N'General & Cosmetic Dentistry');

INSERT INTO dbo.settings (skey, svalue) VALUES (N'schema_version', N'4');

INSERT INTO dbo.services (name, description, category, duration_min, price, icon) VALUES
(N'Dental Check-Up',         N'Routine oral examination and consultation',      N'routine',  30,  500, N'bi-clipboard2-pulse'),
(N'Teeth Cleaning',          N'Oral prophylaxis: scaling and polishing',        N'routine',  45,  800, N'bi-stars'),
(N'Pediatric Dentistry',     N'Check-up and care for children',                 N'routine',  45,  700, N'bi-emoji-smile'),
(N'Tooth Filling',           N'Restoration of decayed or chipped teeth',        N'advanced', 60, 1000, N'bi-bandaid'),
(N'Tooth Extraction',        N'Simple tooth removal',                           N'advanced', 60, 1000, N'bi-scissors'),
(N'Teeth Whitening',         N'In-clinic whitening session',                    N'advanced', 60, 2500, N'bi-brightness-high'),
(N'Root Canal Therapy',      N'Endodontic treatment session',                   N'advanced', 90, 5000, N'bi-activity'),
(N'Braces Adjustment',       N'Orthodontic wire adjustment',                    N'advanced', 30, 1000, N'bi-sliders'),
(N'Dental Implant Consult',  N'Assessment and treatment planning for implants', N'advanced', 60, 1000, N'bi-gem'),
(N'Cosmetic Veneer Consult', N'Assessment for veneers and smile design',        N'advanced', 60, 1200, N'bi-magic');
GO

/* Handy view for SSMS: appointments with names (not used by the PHP code) */
CREATE OR ALTER VIEW dbo.v_appointments AS
SELECT a.id, a.code, a.contact_name, a.contact_phone, a.contact_email,
       s.name AS service, d.name AS dentist, a.start_at, a.end_at,
       a.status, a.source, a.is_emergency, a.checked_in_at, a.created_at
FROM dbo.appointments a
JOIN dbo.services s ON s.id = a.service_id
JOIN dbo.dentists d ON d.id = a.dentist_id;
GO

PRINT 'MyDentist database is ready.';
