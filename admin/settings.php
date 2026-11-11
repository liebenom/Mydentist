<?php
/** Settings: own profile & password; admins can manage staff accounts and see system configuration. */
require __DIR__ . '/../includes/bootstrap.php';
$user = require_staff();
require __DIR__ . '/../includes/account_settings.php';

$staffErrors = [];
if (is_admin() && is_post()) {
    $do = input('do');
    if ($do === 'add_staff') {
        csrf_check();
        $f = ['full_name' => input('full_name'), 'username' => input('username'), 'email' => strtolower(input('email')), 'phone' => input('phone'), 'role' => input('role') === 'admin' ? 'admin' : 'staff'];
        $pass = (string) ($_POST['password'] ?? '');
        if (mb_strlen($f['full_name']) < 3) $staffErrors[] = 'Enter the full name.';
        if (!preg_match('/^[A-Za-z0-9._]{4,30}$/', $f['username']) || db_val('SELECT COUNT(*) FROM users WHERE username = ?', [$f['username']])) $staffErrors[] = 'Username is invalid or already taken.';
        if (!valid_email($f['email']) || db_val('SELECT COUNT(*) FROM users WHERE email = ?', [$f['email']])) $staffErrors[] = 'Email is invalid or already used.';
        if ($p = password_problem($pass)) $staffErrors[] = $p;
        if (!$staffErrors) {
            db_insert('users', $f + ['password_hash' => password_hash($pass, PASSWORD_DEFAULT), 'is_active' => 1, 'created_at' => now_str()]);
            audit('staff_added', $f['full_name'] . ' (' . $f['username'] . ', ' . $f['role'] . ')');
            flash('success', 'Staff account created.');
            redirect('admin/settings.php');
        }
    } elseif ($do === 'toggle_staff') {
        csrf_check();
        $s = db_one("SELECT * FROM users WHERE id = ? AND role IN ('staff','admin')", [input_int('id')]);
        if ($s && (int) $s['id'] !== (int) $user['id']) {
            q('UPDATE users SET is_active = ? WHERE id = ?', [$s['is_active'] ? 0 : 1, $s['id']]);
            audit($s['is_active'] ? 'staff_deactivated' : 'staff_activated', $s['full_name']);
            flash('success', 'Account updated.');
        }
        redirect('admin/settings.php');
    } elseif ($do === 'reset_password') {
        csrf_check();
        $target = db_one('SELECT * FROM users WHERE id = ?', [input_int('id')]);
        $pass = (string) ($_POST['password'] ?? '');
        if ($target && !password_problem($pass)) {
            q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($pass, PASSWORD_DEFAULT), $target['id']]);
            audit('password_reset', 'Password reset for ' . $target['full_name'] . ' (' . $target['username'] . ')');
            flash('success', 'Password reset for ' . e($target['full_name']) . '. Share the new password with them securely.');
        } else {
            flash('danger', password_problem($pass) ?? 'User not found.');
        }
        redirect('admin/settings.php');
    } elseif ($do === 'save_email') {
        csrf_check();
        $addr = strtolower(input('mail_user'));
        $pass = preg_replace('/\s+/', '', (string) ($_POST['mail_pass'] ?? ''));   // Gmail shows the App Password in groups of 4
        $enable = input('mail_enabled') === '1';
        $host = input('mail_host') ?: 'smtp.gmail.com';
        $secure = input('mail_secure') === 'ssl' ? 'ssl' : 'tls';
        $port = input_int('mail_port', $secure === 'ssl' ? 465 : 587);
        $mailErr = [];
        if ($addr !== '' && !valid_email($addr)) $mailErr[] = t('Enter a valid email address.');
        if ($enable && $addr === '') $mailErr[] = t('Enter the clinic email address.');
        if ($enable && $pass === '' && (string) mail_cfg('pass') === '') $mailErr[] = t('Enter the App Password.');
        if ($port < 1 || $port > 65535) $mailErr[] = t('Enter a valid port.');
        if ($mailErr) {
            flash('danger', implode(' ', array_map('e', $mailErr)));
        } else {
            setting_set('mail_enabled', $enable ? '1' : '0');
            setting_set('mail_host', $host);
            setting_set('mail_port', (string) $port);
            setting_set('mail_secure', $secure);
            // Gmail: the login is the Gmail address. Other services (e.g. Brevo) give a separate SMTP login.
            $login = trim(input('mail_login'));
            setting_set('mail_user', $login !== '' ? $login : $addr);
            setting_set('mail_login_custom', $login);
            setting_set('mail_from', $addr);
            setting_set('mail_from_name', input('mail_from_name') ?: 'MyDentist Dental Clinic');
            if ($pass !== '') {
                setting_set('mail_pass', $pass);
            }
            audit('email_settings', 'Email settings updated (' . ($enable ? 'sending on' : 'sending off') . ', ' . $addr . ')');
            flash('success', t('Email settings saved.') . ' ' . ($enable ? t('Send a test email to check that it works.') : ''));
        }
        redirect('admin/settings.php#email-settings');
    } elseif ($do === 'test_email') {
        csrf_check();
        $to = strtolower(input('test_to')) ?: $user['email'];
        if (!valid_email($to)) {
            flash('danger', t('Enter a valid email address.'));
            redirect('admin/settings.php#email-settings');
        }
        $ok = send_mail($to, '', 'MyDentist test email', email_layout('Test email', '<p>If you can read this, email notifications are working.</p>', ''));
        flash($ok ? 'success' : 'danger', $ok ? (mail_cfg('enabled') ? t('Test email sent to') . ' ' . e($to) . '. ' . t('Check the inbox (and the Spam folder).') : t('Email sending is off, so the test email was saved to the Email Log.')) : t('Sending failed:') . ' ' . e(mail_last_error()) );
        redirect('admin/settings.php#email-settings');
    }
}

$staff = is_admin() ? db_all("SELECT * FROM users WHERE role IN ('staff','admin') ORDER BY role, full_name") : [];
$title = t('Settings');
$layout = 'admin';
$active = 'settings';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/account_settings_form.php';
?>
<?php if (is_admin()): ?>
<div class="row g-3 mt-1">
  <div class="col-xl-7">
    <div class="card-md h-100">
      <div class="card-head"><h2><i class="bi bi-people text-primary"></i> Staff Accounts</h2></div>
      <div class="table-responsive"><table class="table table-md">
        <thead><tr><th>Name</th><th>Role</th><th>Last login</th><th class="text-end">Actions</th></tr></thead>
        <tbody>
        <?php foreach ($staff as $s): ?>
          <tr class="<?= $s['is_active'] ? '' : 'opacity-50' ?>">
            <td><div class="n fw-semibold"><?= e($s['full_name']) ?></div><div class="small text-muted">@<?= e($s['username']) ?> · <?= e($s['email']) ?></div></td>
            <td><span class="badge <?= $s['role'] === 'admin' ? 'tint-violet' : 'tint-teal' ?>"><?= ucfirst($s['role']) ?></span></td>
            <td class="small"><?= e(fmt_datetime($s['last_login_at']) ?: 'Never') ?></td>
            <td class="text-end text-nowrap">
              <button class="btn btn-sm btn-light border" data-bs-toggle="collapse" data-bs-target="#rp<?= $s['id'] ?>" title="Reset password"><i class="bi bi-key"></i></button>
              <?php if ((int) $s['id'] !== (int) $user['id']): ?>
                <form method="post" class="d-inline" data-confirm="<?= $s['is_active'] ? 'Deactivate' : 'Activate' ?> this account?"><?= csrf_field() ?><input type="hidden" name="do" value="toggle_staff"><input type="hidden" name="id" value="<?= $s['id'] ?>"><button class="btn btn-sm btn-light border" title="<?= $s['is_active'] ? 'Deactivate' : 'Activate' ?>"><i class="bi bi-<?= $s['is_active'] ? 'person-slash' : 'person-check' ?>"></i></button></form>
              <?php endif; ?>
            </td>
          </tr>
          <tr class="collapse" id="rp<?= $s['id'] ?>"><td colspan="4">
            <form method="post" class="d-flex gap-2"><?= csrf_field() ?><input type="hidden" name="do" value="reset_password"><input type="hidden" name="id" value="<?= $s['id'] ?>">
              <input class="form-control form-control-sm" name="password" readonly data-noautofill autocomplete="new-password" placeholder="New password (min. 8, letters + numbers)" required minlength="8" aria-label="New password"><button class="btn btn-sm btn-primary text-nowrap">Reset</button></form>
          </td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
      <div class="card-body-md pt-0 small text-muted">To reset a patient's password, find their username in Patient Management and use the form below.</div>
      <form method="post" class="card-body-md pt-0 d-flex gap-2 flex-wrap"><?= csrf_field() ?><input type="hidden" name="do" value="reset_password">
        <select class="form-select form-select-sm" name="id" style="max-width:260px" aria-label="Patient"><?php foreach (db_all("SELECT id, full_name, username FROM users WHERE role = 'patient' ORDER BY full_name") as $p): ?><option value="<?= $p['id'] ?>"><?= e($p['full_name'] . ' (@' . $p['username'] . ')') ?></option><?php endforeach; ?></select>
        <input class="form-control form-control-sm" style="max-width:240px" name="password" readonly data-noautofill autocomplete="new-password" placeholder="New password" required minlength="8" aria-label="New patient password"><button class="btn btn-sm btn-light border">Reset Patient Password</button></form>
    </div>
  </div>
  <div class="col-xl-5">
    <form method="post" class="card-md mb-3">
      <div class="card-head"><h2><i class="bi bi-person-plus text-primary"></i> Add Staff Account</h2></div>
      <div class="card-body-md">
        <?php if ($staffErrors): ?><div class="alert alert-danger small"><?= implode('<br>', array_map('e', $staffErrors)) ?></div><?php endif; ?>
        <?= csrf_field() ?><input type="hidden" name="do" value="add_staff">
        <div class="row g-2">
          <div class="col-12"><input class="form-control form-control-sm" name="full_name" placeholder="Full name" required aria-label="Full name" value="<?= e(input('full_name')) ?>"></div>
          <div class="col-6"><input class="form-control form-control-sm" name="username" readonly data-noautofill autocomplete="off" placeholder="Username" required aria-label="Username" value="<?= e(input('username')) ?>"></div>
          <div class="col-6"><select class="form-select form-select-sm" name="role" aria-label="Role"><option value="staff">Staff</option><option value="admin">Administrator</option></select></div>
          <div class="col-6"><input class="form-control form-control-sm" type="email" name="email" readonly data-noautofill autocomplete="off" placeholder="Email" required aria-label="Email" value="<?= e(input('email')) ?>"></div>
          <div class="col-6"><input class="form-control form-control-sm" name="phone" placeholder="Phone" aria-label="Phone" value="<?= e(input('phone')) ?>"></div>
          <div class="col-12"><input class="form-control form-control-sm" name="password" readonly data-noautofill autocomplete="new-password" placeholder="Temporary password" required minlength="8" aria-label="Temporary password"></div>
        </div>
        <button class="btn btn-sm btn-primary w-100 mt-3">Create Account</button>
      </div>
    </form>
    <div class="card-md">
      <div class="card-head"><h2><i class="bi bi-sliders text-primary"></i> System Configuration</h2></div>
      <div class="card-body-md small">
        <?php foreach ([
            'Clinic hours' => clinic_days_label() . ', ' . date('g:i A', strtotime(CLINIC_OPEN)) . ' – ' . date('g:i A', strtotime(CLINIC_CLOSE)),
            'Lunch break' => LUNCH_START ? date('g:i', strtotime(LUNCH_START)) . ' – ' . date('g:i A', strtotime(LUNCH_END)) : 'None',
            'Slot interval' => SLOT_INTERVAL . ' minutes',
            'Booking window' => BOOKING_WINDOW_DAYS . ' days ahead, ' . MIN_NOTICE_HOURS . ' h minimum notice',
            'Patient changes allowed until' => CHANGE_CUTOFF_HOURS . ' h before',
            'Online bookings' => AUTO_CONFIRM ? 'Confirmed automatically' : 'Pending until approved',
            'Email' => mail_cfg('enabled') ? 'Sending via ' . mail_cfg('host') : 'Off (saved to Email Log)',
            'Database' => ['sqlite' => 'SQLite (testing)', 'mysql' => 'MySQL', 'sqlsrv' => 'SQL Server'][DB_DRIVER] . ' · ' . DB_NAME . ' @ ' . DB_HOST,
        ] as $k => $v): ?>
          <div class="d-flex justify-content-between gap-3 py-1 border-bottom"><span class="text-muted"><?= e($k) ?></span><strong class="text-end"><?= e($v) ?></strong></div>
        <?php endforeach; ?>
        <p class="text-muted mt-2 mb-0">These are set in <code>config.php</code>.</p>
      </div>
    </div>
  </div>
</div>

<?php $mOn = (bool) mail_cfg('enabled'); $hasPass = (string) mail_cfg('pass') !== '' && mail_cfg('pass') !== 'your-16-char-app-password'; $mUser = (string) mail_cfg('from'); $mLogin = (string) setting_get('mail_login_custom', ''); if ($mUser === 'your.clinic@gmail.com') $mUser = ''; ?>
<div class="row g-3 mt-1" id="email-settings">
  <div class="col-xl-7">
    <form method="post" class="card-md h-100" autocomplete="off">
      <?= csrf_field() ?><input type="hidden" name="do" value="save_email">
      <div class="card-head"><h2><i class="bi bi-envelope-at text-primary"></i> <?= te('Email') ?></h2>
        <span class="badge <?= $mOn ? 'tint-teal' : 'text-bg-light border' ?>"><?= $mOn ? te('Sending real emails') : te('Off – saved to Email Log') ?></span></div>
      <div class="card-body-md">
        <p class="small text-muted"><?= te('Booking confirmations, changes and reminders are sent to the email address the patient or guest entered.') ?></p>
        <div class="form-check form-switch mb-3">
          <input class="form-check-input" type="checkbox" role="switch" id="mail_enabled" name="mail_enabled" value="1" <?= $mOn ? 'checked' : '' ?>>
          <label class="form-check-label fw-semibold" for="mail_enabled"><?= te('Send real emails') ?></label>
        </div>
        <div class="row g-2">
          <div class="col-md-6"><label class="form-label small" for="mail_user"><?= te('Clinic email address') ?></label>
            <input class="form-control form-control-sm" type="email" id="mail_user" name="mail_user" readonly data-noautofill autocomplete="off" placeholder="yourclinic@gmail.com" value="<?= e($mUser) ?>"></div>
          <div class="col-md-6"><label class="form-label small" for="mail_pass"><?= te('App Password / SMTP key') ?></label>
            <div class="input-group input-group-sm"><input class="form-control" type="password" id="mail_pass" name="mail_pass" readonly data-noautofill autocomplete="new-password" placeholder="<?= $hasPass ? e(t('Saved – leave blank to keep')) : 'xxxx xxxx xxxx xxxx' ?>">
            <button type="button" class="btn btn-light border toggle-pass" aria-label="<?= te('Show password') ?>"><i class="bi bi-eye"></i></button></div></div>
          <div class="col-md-6"><label class="form-label small" for="mail_from_name"><?= te('Sender name') ?></label>
            <input class="form-control form-control-sm" id="mail_from_name" name="mail_from_name" value="<?= e((string) mail_cfg('from_name')) ?>"></div>
        </div>
        <details class="mt-3 small"><summary class="text-muted"><?= te('Advanced (only change if you are not using Gmail)') ?></summary>
          <div class="row g-2 mt-1">
            <div class="col-md-6"><label class="form-label small" for="mail_host"><?= te('SMTP server') ?></label><input class="form-control form-control-sm" id="mail_host" name="mail_host" value="<?= e((string) mail_cfg('host')) ?>"></div>
            <div class="col-md-3"><label class="form-label small" for="mail_port"><?= te('Port') ?></label><input class="form-control form-control-sm" type="number" id="mail_port" name="mail_port" value="<?= (int) mail_cfg('port') ?>"></div>
            <div class="col-12"><label class="form-label small" for="mail_login"><?= te('SMTP login (only if your email service gives a separate login, e.g. Brevo)') ?></label><input class="form-control form-control-sm" id="mail_login" name="mail_login" value="<?= e($mLogin) ?>" readonly data-noautofill autocomplete="off" placeholder="<?= te('Leave blank for Gmail') ?>"></div>
            <div class="col-md-3"><label class="form-label small" for="mail_secure"><?= te('Security') ?></label><select class="form-select form-select-sm" id="mail_secure" name="mail_secure"><option value="tls" <?= mail_cfg('secure') !== 'ssl' ? 'selected' : '' ?>>TLS (587)</option><option value="ssl" <?= mail_cfg('secure') === 'ssl' ? 'selected' : '' ?>>SSL (465)</option></select></div>
          </div>
        </details>
        <button class="btn btn-sm btn-primary mt-3"><i class="bi bi-save"></i> <?= te('Save Email Settings') ?></button>
      </div>
    </form>
  </div>
  <div class="col-xl-5">
    <div class="card-md mb-3">
      <div class="card-head"><h2><i class="bi bi-envelope-check text-primary"></i> <?= te('Send a Test Email') ?></h2></div>
      <form method="post" class="card-body-md d-flex gap-2"><?= csrf_field() ?><input type="hidden" name="do" value="test_email">
        <input class="form-control form-control-sm" type="email" name="test_to" value="<?= e($user['email']) ?>" aria-label="<?= te('Send test email to') ?>"><button class="btn btn-sm btn-light border text-nowrap"><?= te('Send Test') ?></button></form>
    </div>
    <div class="card-md">
      <div class="card-head"><h2><i class="bi bi-key text-primary"></i> <?= te('How to get a Gmail App Password') ?></h2></div>
      <ol class="card-body-md small mb-0 ps-4">
        <li><?= te('Sign in to the clinic Gmail account and open myaccount.google.com/security.') ?></li>
        <li><?= te('Turn on 2-Step Verification.') ?></li>
        <li><?= te('Open myaccount.google.com/apppasswords, type a name such as MyDentist and click Create.') ?></li>
        <li><?= te('Copy the 16-letter password, paste it above, switch on "Send real emails" and save.') ?></li>
      </ol>
      <div class="card-body-md pt-0 small text-muted"><?= te('No App Passwords option? Use a free email-sending service such as Brevo: put its SMTP server, port, login and SMTP key under Advanced.') ?></div>
    </div>
  </div>
</div>
<?php endif; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
