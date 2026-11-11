-- SQLite version of the schema, used only for quick local testing without MySQL.
-- (Set DB_DRIVER to 'sqlite' in config.local.php.) Production/XAMPP uses schema.sql.
PRAGMA foreign_keys = ON;

CREATE TABLE users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    role TEXT NOT NULL DEFAULT 'patient',
    full_name TEXT NOT NULL,
    username TEXT NOT NULL UNIQUE,
    email TEXT NOT NULL UNIQUE,
    phone TEXT,
    password_hash TEXT NOT NULL,
    is_active INTEGER NOT NULL DEFAULT 1,
    avatar TEXT,
    last_login_at TEXT,
    created_at TEXT NOT NULL
);
CREATE TABLE dentists (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    specialty TEXT,
    is_active INTEGER NOT NULL DEFAULT 1
);
CREATE TABLE services (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    description TEXT,
    category TEXT NOT NULL DEFAULT 'routine',
    duration_min INTEGER NOT NULL DEFAULT 30,
    price REAL NOT NULL DEFAULT 0,
    icon TEXT NOT NULL DEFAULT 'bi-heart-pulse',
    is_active INTEGER NOT NULL DEFAULT 1
);
CREATE TABLE appointments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    code TEXT NOT NULL UNIQUE,
    manage_token TEXT NOT NULL,
    patient_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
    contact_name TEXT NOT NULL,
    contact_email TEXT,
    contact_phone TEXT,
    service_id INTEGER NOT NULL REFERENCES services(id),
    dentist_id INTEGER NOT NULL REFERENCES dentists(id),
    start_at TEXT NOT NULL,
    end_at TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'pending',
    source TEXT NOT NULL DEFAULT 'online',
    is_emergency INTEGER NOT NULL DEFAULT 0,
    patient_notes TEXT,
    staff_notes TEXT,
    reminder_24_sent INTEGER NOT NULL DEFAULT 0,
    reminder_1_sent INTEGER NOT NULL DEFAULT 0,
    reminder_12_sent INTEGER NOT NULL DEFAULT 0,
    reminder_6_sent INTEGER NOT NULL DEFAULT 0,
    checked_in_at TEXT,
    extra_services TEXT NULL,
    is_demo INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
CREATE INDEX idx_appt_time ON appointments(start_at, end_at);
CREATE TABLE schedule_blocks (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    dentist_id INTEGER REFERENCES dentists(id) ON DELETE CASCADE,
    start_at TEXT NOT NULL,
    end_at TEXT NOT NULL,
    reason TEXT,
    created_by INTEGER,
    created_at TEXT NOT NULL
);
CREATE TABLE audit_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER,
    action TEXT NOT NULL,
    details TEXT,
    appointment_id INTEGER,
    ip_address TEXT,
    created_at TEXT NOT NULL
);
CREATE TABLE settings (
    skey TEXT PRIMARY KEY,
    svalue TEXT
);

INSERT INTO users (role, full_name, username, email, phone, password_hash, created_at) VALUES
('admin',   'Dr. Keith Warren Isidro', 'admin', 'admin@mydentist.local', '+63 900 000 0001', '$2y$10$1pVyyvpZM/VrOD0ax6lEEehU9z1bcW.i293Y1s7y07q1Efj8ucEjW', datetime('now','localtime')),
('staff',   'Front Desk Staff',        'staff', 'staff@mydentist.local', '+63 900 000 0002', '$2y$10$x2jHyUdrGqFAzIEF58CekegE./nzdB1omDMEeZzq/Cuc5WCpIBysu', datetime('now','localtime')),
('patient', 'Maria Santos',            'maria', 'maria@example.com',     '+63 917 123 4567', '$2y$10$acfKq16SOJnLLdoz.FLfhubtV9dvE6hCoc96tiVPSq0M18btTZ/E6', datetime('now','localtime'));

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
