# MyDentist Dental Clinic – Web-Based Dental Appointment System

Capstone system for **MyDentist Dental Clinic, Maramag, Bukidnon**
(Abapo, Booc, Cambarihan – BSIS, STI College Valencia).

Built with **HTML, CSS, JavaScript, Bootstrap 5 and PHP 8** on **XAMPP (Apache)**, with **Microsoft SQL Server** as the database, managed in **SQL Server Management Studio (SSMS)**.
It needs no Composer or npm packages.

> The code also still runs on MySQL. Set `DB_DRIVER` to `'mysql'` in `config.php` and use `database/schema.mysql.sql`.

---

## 1. Install (SQL Server + SSMS + XAMPP)

### Step 1 – SQL Server and SSMS
1. Install **SQL Server Express** (free) and **SQL Server Management Studio (SSMS)** from Microsoft.
2. Open SSMS and connect. Note the **Server name** you use, usually `localhost\SQLEXPRESS`.

### Step 2 – Let XAMPP's PHP talk to SQL Server
PHP needs Microsoft's driver. XAMPP does not include it.

1. Check your PHP version: open `http://localhost/dashboard/phpinfo.php`, or run `C:\xampp\php\php.exe -v`. Example: PHP **8.2**, *Thread Safe*, x64.
2. Download **Microsoft Drivers for PHP for SQL Server** (version 5.11 or newer) and extract it.
3. Copy the file that matches your PHP into `C:\xampp\php\ext\`. For PHP 8.2 that is `php_pdo_sqlsrv_82_ts_x64.dll`.
4. Open `C:\xampp\php\php.ini` and add this line under the other `extension=` lines, using your version number:
   ```
   extension=pdo_sqlsrv_82_ts_x64
   ```
5. Install **Microsoft ODBC Driver 18 for SQL Server**. The PHP driver needs it.
6. Restart Apache in the XAMPP Control Panel. `pdo_sqlsrv` should now appear in phpinfo.

### Step 3 – Copy the project and create the database
1. Copy the `mydentist` folder into `C:\xampp\htdocs\`.
2. In `config.php`, set `DB_HOST` to the **same server name you use in SSMS**. The default is `localhost\SQLEXPRESS`.
3. Create the database in **one** of these two ways:
   - **Installer:** open **http://localhost/mydentist/** and click **Install now**. If something is missing (such as the SQL Server driver), the installer lists it first.
   - **SSMS:** open `database/schema.sqlserver.sql` and press **Execute (F5)**. Then create an empty file named `storage/installed.lock`.
4. Refresh **Databases** in SSMS. You will see `mydentist` with its tables and the view `v_appointments`.

### Logging in to SQL Server: Windows vs SQL Server Authentication
- **Windows Authentication (default):** leave `DB_USER` empty in `config.php`. This works when Apache is started from the XAMPP Control Panel under your Windows account, the same way SSMS connects with "Windows Authentication".
- **SQL Server Authentication:** use this if Apache runs as a Windows service, or if you prefer a SQL login.
  1. In SSMS, right-click the server → **Properties → Security** and choose **SQL Server and Windows Authentication mode**. Restart the SQL Server service.
  2. Create a login, for example under **Security → Logins → New Login**, and give it `db_owner` on `mydentist`.
  3. Put that login in `DB_USER` and `DB_PASS` in `config.php`.

### Default accounts
| Role | Username | Password |
|---|---|---|
| Administrator (Dr. Isidro) | `admin` | `Admin@123` |
| Clinic staff (front desk) | `staff` | `Staff@123` |
| Sample patient | `maria` | `Patient@123` |

Change these passwords from **Settings** after the first login.

**Reinstalling:** delete `storage/installed.lock` and open the site again. This erases all data. You can also re-run the SSMS script, which drops and recreates the tables.

### Common connection errors
| Message | Fix |
|---|---|
| `could not find driver` | `pdo_sqlsrv` is not loaded. Check the DLL name and the `extension=` line in php.ini, then restart Apache. |
| `ODBC Driver ... not found` | Install Microsoft ODBC Driver 18 for SQL Server. |
| `Login failed for user` | Fix `DB_USER` / `DB_PASS`, or switch to Windows Authentication. Check that mixed mode is on if you use a SQL login. |
| `server was not found` | `DB_HOST` must match the SSMS server name. Make sure the *SQL Server (SQLEXPRESS)* service is running. |

---

## 2. Settings in `config.php`

| Setting | Default | What it does |
|---|---|---|
| `CLINIC_DAYS`, `CLINIC_OPEN`, `CLINIC_CLOSE` | Mon–Sat, 8:00–17:00 | Clinic hours |
| `LUNCH_START`, `LUNCH_END` | 12:00–13:00 | No appointments during the lunch break |
| `SLOT_INTERVAL` | 30 | Minutes between possible start times |
| `BOOKING_WINDOW_DAYS` | 60 | How far ahead patients can book |
| `MIN_NOTICE_HOURS` | 2 | Earliest online booking is now + 2 hours |
| `CHANGE_CUTOFF_HOURS` | 2 | Patients can't reschedule or cancel online within 2 hours of the visit |
| `MAX_ACTIVE_PER_PATIENT` | 3 | Limit on upcoming bookings per patient or guest email |
| `AUTO_CONFIRM` | `false` | `false`: online bookings stay **Pending** until staff approve. `true`: confirmed instantly |
| `DB_HOST`, `DB_USER`, `DB_PASS` | `localhost\SQLEXPRESS`, Windows auth | SQL Server connection (see section 1) |
| `MAIL_ENABLED` + `SMTP_*` | off | Email fallback. Easier: **Admin → Settings → Email** (see section 4) |
| `CRON_KEY` | change it | Password for running the scheduled task from a browser |

Settings you don't want to keep in `config.php` can go in a separate `config.local.php`.
Define the same constants there and they take priority over `config.php`.

---

## 3. Features and where to find them

### Patient side
| Feature | Page |
|---|---|
| Register / log in (passwords hashed with bcrypt) | `register.php`, `login.php` |
| Patient dashboard: next appointment, quick actions, history | `patient/dashboard.php` |
| View available schedules (6 days at a time, per service and dentist) | `patient/schedules.php` |
| 4-step booking: Service → Dentist → Date & Time → Confirm | `patient/book.php` |
| Appointment details with reference number | `patient/appointment.php` |
| Reschedule / cancel (blocked within 2 hours of the visit) | `patient/reschedule.php`, `patient/appointments.php` |
| **Guest Mode** booking (no account), QR code, guest cancel link | `guest/book.php`, `guest/manage.php` |
| Profile and password | `patient/settings.php` |

### Clinic staff / admin side
| Feature | Page |
|---|---|
| **Dashboard analytics**: today's appointments, pending requests, total patients, weekly trend, most availed services, daily bookings, monthly patients, cancellation rate, no-show rate | `admin/dashboard.php` |
| **Schedule management**: weekly grid; click to block or unblock slots; block days off and holidays; approval queue | `admin/schedule.php` |
| **Appointment records**: search, filter, approve, decline, complete, no-show, **CSV export** | `admin/appointments.php` |
| Appointment detail: check in, reschedule, cancel with a message, staff notes, activity history | `admin/appointment.php` |
| Walk-in / phone booking | `admin/book.php` |
| **Emergency Appointment Priority** | `admin/emergency.php` |
| **Check-in**: type any reference number, or scan a guest's QR code (camera or USB scanner) | `admin/checkin.php` |
| **Reports**: daily, weekly, monthly or custom range; completed, cancellation and no-show reports; print/PDF and CSV | `admin/reports.php` |
| Patient management (registered and guest contacts, no-show counts) | `admin/patients.php` |
| Email log (every email the system generated) | `admin/emails.php` |
| Services and dentists (durations, prices) – *admin only* | `admin/services.php` |
| **Automatic database backup and restore** – *admin only* | `admin/backup.php` |
| **Audit logs** – *admin only* | `admin/audit.php` |
| Staff accounts, password resets, test email – *admin only* | `admin/settings.php` |

### How scheduling works
- Each service has a **duration**, for example a check-up is 30 minutes and a root canal is 90 minutes. A booking occupies exactly that time.
- Patients only see start times where the whole appointment fits inside clinic hours, does not cross the lunch break, and does not overlap the chosen dentist's other Pending/Confirmed bookings or any blocked time.
- **"Any available dentist"** assigns the free dentist with the lightest load that day.
- Availability is checked again on the server inside a database lock when the booking is saved. If two people pick the same slot at the same moment, only one gets it.

### Updating an existing installation (version 2)
Copy the new files over the old ones, but **keep your own `config.php`**. Then open the site.
The database upgrades itself once and adds nothing destructive:

| Change | Why |
|---|---|
| `users.avatar` column | profile pictures |
| `appointments.is_demo` column | marks demo appointments |
| Other dentists set to inactive | Dr. Warren is the only bookable dentist. Their old appointments keep their history. |
| Guest Mode bookings detached from accounts that were linked **only by email** | guest privacy; the guest still opens them with their own link |

`database/migration_002.sqlserver.sql` contains the same changes if you prefer to run them yourself in SSMS.

### Accounts, passwords and profile pictures
- **Password rules:** at least 8 characters, with one **uppercase letter**, one **number** and one **special character** (e.g. `Warren@123`).
  - The checklist under the field updates as you type.
  - Both Password and Confirm Password have a show/hide eye button.
  - Passwords that don't match are rejected.
  - Passwords are stored as bcrypt hashes.
- **Profile pictures:** go to **Settings**. Pick a JPG, JPEG, PNG or WEBP (max 2 MB), check the preview, then save. You can also change or remove it.
  - The picture appears in the sidebar and top bar.
  - Files are checked to be real images and re-encoded.
  - Pictures are stored outside the web folder and served only to their owner.

### Dr. Warren only
Every booking path assigns Dr. Warren and rejects any other dentist, including a forged form. This covers the patient wizard, Guest Mode, walk-in, emergency and rescheduling.

### Who does what
- **Staff (front desk):** Walk-in / Phone Booking, Emergency Priority and **Check-in**. These are hidden from Admin and blocked for Admin on the server.
- **Admin:** dashboard, schedule, appointment records, reports, patients, email log, services, backup, audit logs and settings (including Email).
- **Reference numbers and QR codes exist only for Guest bookings** (no account).
  - Registered patients never see one. They give their **name** at the front desk.
  - Staff find them on the Check-in page by typing the name.

### Guest privacy
- A **GUEST** is a no-account Guest Mode booking. It is owned only by its secret link (code + token).
  - Registering an account never pulls in guest bookings, even with the same email.
  - A guest's page lists only bookings that share their private link.
- At the clinic, Staff check guests in on the **Check-in** page by typing the reference number or scanning the guest's QR with the camera.
  - Check-in accepts guest codes only, rejects cancelled bookings, and finds the booking from the database, not from the QR content.
- No staff or admin user ever sees or generates a guest's QR image.

### Languages: English, Filipino, Cebuano
- Use the **language button** (🌐 EN / FIL / CEB) at the top of every page. The choice is remembered for a year.
- Translated:
  - all schedule screens: booking wizard, View Schedules, rescheduling, Schedule Management grid, availability picker
  - appointment details and appointment lists
  - menus, status labels, day and month names, and default service names
- Switching language changes **only the wording**. Slots, bookings, statuses and everything stored in the database stay exactly the same.
- Not translated: other admin pages (records, reports, settings) and emails stay in English.
- Translations live in `lang/fil.php` and `lang/ceb.php`, one line per phrase. To fix a word, edit the right-hand side. A missing phrase simply shows in English.

### QR codes are GUEST-only
- A **GUEST** is someone who booked through Guest Mode, is **not logged in**, and opened their booking with its secret link (from the confirmation email or the confirmation page).
- Only a GUEST can see, download or receive a QR code, and only for their own active booking. One function enforces this on the server: `can_access_qr()` in `includes/auth.php`.
- **Patients, staff and admins never get QR images.**
  - `qr.php` refuses them (HTTP 403), even with a valid link, and the attempt is written to the audit log.
  - Their pages show the **reference number** instead.
  - Patient emails contain no QR code.
  - The Email Log hides guests' QR images from staff.
- Staff can still check guests in by **scanning** the guest's QR code on the Check-in page. A scanned code is accepted only if it belongs to a guest booking. Registered patients are checked in by typing their reference number.

### How Emergency Priority works
1. Staff decide that a case is an emergency. The system does not diagnose, as the Scope and Limitations section states.
2. Staff choose the dentist, start time and duration. The page previews which bookings will be affected.
3. The emergency is saved as **Confirmed** at that time, even if the slot was taken.
4. Each overlapping booking of that dentist moves to the dentist's **next free slot**, same day first.
5. Affected patients get an email, and every change is written to the audit log.

---

## 4. Email notifications

These emails are sent automatically:

| Email | When it is sent |
|---|---|
| Request received | A booking is submitted and is pending approval |
| Confirmed | Staff approve a booking, or `AUTO_CONFIRM` is on |
| Declined | Staff decline a request |
| Rescheduled | The appointment time changes |
| Cancelled | The appointment is cancelled |
| Schedule changed (emergency) | The booking was moved for an emergency |
| Reminder | **24 hours** before the appointment |
| Reminder | **12 hours** before the appointment |
| Reminder | **6 hours** before the appointment |
| Confirmation or reminder sent by hand | Admin or Staff use **Send Email to a Patient** in the Email Log |

Reminders go only to **confirmed** appointments. If a booking is made late (for example 8 hours before), only the reminder that is due is sent, never several at once.

Every email includes the booking reference. **Guest Mode bookings** also get an embedded QR code. Registered patients use their reference number instead (see section 3, *QR codes are GUEST-only*).

**Emails go to the address the patient or guest typed** when booking or registering.

**Email sending is off at first:** emails are not sent. They are saved and shown in **Admin → Email Log**, so you can demonstrate them without internet.

**Sending real emails through Gmail (no file editing needed):**
1. Sign in to the clinic's Gmail account and turn on **2-Step Verification** (myaccount.google.com/security).
2. Open **myaccount.google.com/apppasswords**, type a name such as `MyDentist`, click **Create**, and copy the 16-letter password.
3. Log in as Admin and open **Settings → Email**.
4. Type the Gmail address and paste the App Password, switch on **Send real emails**, then click **Save Email Settings**.
5. Use **Send a Test Email** to check it. Look in the Spam folder too.

The PC needs internet to send. The App Password is saved in the database and is never shown again; leave the box blank later to keep it. (The old `MAIL_ENABLED`/`SMTP_*` lines in `config.php` still work as a fallback.)

If XAMPP on Windows shows a certificate error, set `SMTP_VERIFY_SSL` to `false` in `config.php`.

---

## 5. Reminders and automatic backups (scheduled task)

`cron/run.php` does two jobs:
- sends the 24-hour and 1-hour reminders
- makes the daily automatic backup

**Without any setup**, these jobs also run whenever a staff member opens an admin page, at most every 5 minutes (`PSEUDO_CRON`).
That is enough for a demo. For a real clinic, schedule the task to run every 10 minutes.

**Windows Task Scheduler:**
1. Create a basic task with the trigger *Daily*.
2. Under *Advanced*, set it to repeat every 10 minutes.
3. Set the action to *Start a program*:
   - Program: `C:\xampp\php\php.exe`
   - Arguments: `C:\xampp\htdocs\mydentist\cron\run.php`

**Linux cron:**
```
*/10 * * * * php /var/www/html/mydentist/cron/run.php
```

**From a browser or an online cron service:**
```
http://your-site/mydentist/cron/run.php?key=YOUR_CRON_KEY
```

Backups are JSON files in `storage/backups/`. You can download, restore or upload them from **Admin → Backup & Restore**. A safety backup is always made before a restore.

These are separate from SQL Server's own `.bak` backups, which you can also make in SSMS: right-click the database → **Tasks → Back Up**.

---

## 6. Working offline (important for the defense)

The pages load Bootstrap, Bootstrap Icons, Chart.js and the QR camera scanner from a CDN.
To make the system work without internet, run this **once while connected**:

```
C:\xampp\php\php.exe C:\xampp\htdocs\mydentist\tools\download_assets.php
```

The files are saved to `assets/vendor/`, and the pages switch to the local copies automatically.
The Google font falls back to the system font when offline.

Notes on the QR features:
- QR codes are generated by the system itself (`includes/qrcode.php`), so they work offline.
- The camera scanner works on `localhost` or HTTPS. A USB QR scanner or typing the code works anywhere.

---

## 7. Android app (WebView)

See `android/README.md`. It explains how to wrap the website in an Android app using Android Studio.

---

## 8. Security measures (proposal Table 7)

**Implemented:**
- **Secure login:**
  - session ID regenerated at login
  - HttpOnly / SameSite cookies
  - lockout after 5 failed attempts in 10 minutes
- **Password hashing:** bcrypt (`password_hash`), with a minimum of 8 characters including letters and numbers.
- **Input validation:** on both client and server. All SQL uses PDO prepared statements (works the same on SQL Server), and all output is escaped (XSS protection).
- **CSRF tokens** on every form.
- **Role-based access control:**
  - patients can only see their own appointments
  - staff can use the admin module
  - only admins can manage services, backups, audit logs and accounts
- **Guest links** use a secret 32-character token.
- **Audit logs:**
  - approvals, cancellations, reschedules and emergency overrides
  - blocked time, exports, backups and restores
  - logins and account changes
- **Private folders:** `storage/` (backups, logs, emails), `includes/`, `database/` and `tools/` are blocked from web access by `.htaccess`.
- **Privacy:** registration and guest booking ask for consent under the Data Privacy Act of 2012 (RA 10173).

**Not included** (outside the proposal's scope): forgot-password emails. An admin resets passwords from **Settings**.

---

## 9. Folder structure

```
mydentist/
├── index.php, login.php, register.php, logout.php, install.php, qr.php
├── config.php                  ← settings
├── patient/                    ← patient module
├── guest/                      ← Guest Mode booking + manage link
├── admin/                      ← staff / admin module
├── api/slots.php               ← availability (JSON) for the booking forms
├── cron/run.php                ← reminders + automatic backup
├── includes/                   ← shared PHP: db, auth, scheduling engine, mailer, QR, notifications, backup
├── assets/                     ← css, js, logo
├── database/schema.sqlserver.sql ← SQL Server script for SSMS (tables, defaults, view)
├── database/schema.mysql.sql   ← same schema for MySQL (optional)
├── storage/                    ← backups, saved emails, logs (not web-accessible)
├── tools/download_assets.php   ← offline libraries
└── android/                    ← WebView app files
```

### Database tables
| Table | Holds |
|---|---|
| `users` | patients, staff and admins |
| `dentists` | dentist list |
| `services` | dental services with duration and price |
| `appointments` | all bookings: status, source, emergency flag, reminder flags, check-in time |
| `schedule_blocks` | blocked times, for one dentist or clinic-wide |
| `audit_logs` | record of staff and system actions |
| `settings` | system state (last cron run, last backup) |

---

## 10. Testing checklist for the evaluation (ISO/IEC 25010 / SUS)

1. Register a patient. Book a check-up. Check that the request appears under *Pending* on the admin dashboard and in the Email Log.
2. Approve the booking. Check that the confirmation email with the QR code appears.
3. Try to book the same dentist, date and time as another patient. Check that the slot is not offered.
4. Reschedule and cancel the booking as the patient. Check that each change sends an email.
5. Book as a **guest**. Scan or type the QR code in **QR Check-in**.
6. Create an **emergency** over an existing booking. Check that the booking moves and the patient is notified.
7. Block a day in **Schedule Management**. Check that patients can no longer pick it.
8. Generate the daily, weekly and monthly reports. Export them to CSV and print them.
9. Create a backup, change some data, restore the backup, and check that the data comes back.
10. Open the site on a phone. Every page is responsive.
