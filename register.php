<?php
require __DIR__ . '/includes/bootstrap.php';

if ($u = current_user()) {
    redirect(home_for($u));
}

$f = ['full_name' => '', 'phone' => '', 'email' => '', 'username' => ''];
$errors = [];
if (is_post()) {
    csrf_check();
    foreach ($f as $k => $_) {
        $f[$k] = input($k);
    }
    $f['email'] = strtolower($f['email']);
    $pass = (string) ($_POST['password'] ?? '');
    $pass2 = (string) ($_POST['password_confirm'] ?? '');

    if (mb_strlen($f['full_name']) < 3) $errors['full_name'] = t('Please enter your full name.');
    if (!valid_phone($f['phone'])) $errors['phone'] = t('Please enter a valid contact number.');
    if (!valid_email($f['email'])) $errors['email'] = t('Please enter a valid email address.');
    elseif (db_val('SELECT COUNT(*) FROM users WHERE email = ?', [$f['email']])) $errors['email'] = t('An account with this email already exists.');
    if (!preg_match('/^[A-Za-z0-9._]{4,30}$/', $f['username'])) $errors['username'] = t('Use 4–30 letters, numbers, dots or underscores.');
    elseif (db_val('SELECT COUNT(*) FROM users WHERE username = ?', [$f['username']])) $errors['username'] = t('This username is already taken.');
    if ($p = password_problem($pass)) $errors['password'] = $p;
    if ($pass !== $pass2) $errors['password_confirm'] = t('Passwords do not match.');
    if (empty($_POST['privacy'])) $errors['privacy'] = t('Please agree to the data privacy notice.');

    if (!$errors) {
        $id = db_insert('users', [
            'role' => 'patient', 'full_name' => $f['full_name'], 'username' => $f['username'],
            'email' => $f['email'], 'phone' => $f['phone'],
            'password_hash' => password_hash($pass, PASSWORD_DEFAULT),   // bcrypt; never stored in plain text
            'is_active' => 1, 'created_at' => now_str(),
        ]);
        // Privacy: a new account starts with NO appointments. Earlier Guest Mode bookings are
        // never attached by email (an email address alone does not prove who owns a booking).
        // Not signed in automatically: the new patient logs in with their new username and password
        flash('success', te('Your account has been created, {name}! Please log in with your username and password.', ['name' => $f['full_name']]));
        redirect('login.php?show=1');
    }
}

function field_error(array $errors, string $k): string
{
    return isset($errors[$k]) ? '<div class="invalid-feedback d-block">' . e($errors[$k]) . '</div>' : '';
}

$title = t('Create Account');
$layout = 'auth';
include __DIR__ . '/includes/header.php';
?>
<h1><?= te('Create Your Account') ?></h1>
<p class="sub"><?= te('Join us and start booking appointments online') ?></p>
<form method="post" class="needs-validation" novalidate data-register-form>
  <?= csrf_field() ?>
  <div class="mb-3">
    <label class="form-label" for="full_name"><?= te('Full name') ?></label>
    <div class="input-icon"><i class="bi bi-person"></i><input class="form-control <?= isset($errors['full_name']) ? 'is-invalid' : '' ?>" id="full_name" name="full_name" value="<?= e($f['full_name']) ?>" placeholder="e.g. Maria Santos" required minlength="3" autocomplete="name"></div>
    <?= field_error($errors, 'full_name') ?>
  </div>
  <div class="mb-3">
    <label class="form-label" for="phone"><?= te('Contact number') ?></label>
    <div class="input-icon"><i class="bi bi-telephone"></i><input class="form-control <?= isset($errors['phone']) ? 'is-invalid' : '' ?>" id="phone" name="phone" value="<?= e($f['phone']) ?>" placeholder="+63 9XX XXX XXXX" required autocomplete="tel"></div>
    <?= field_error($errors, 'phone') ?>
  </div>
  <div class="mb-3">
    <label class="form-label" for="email"><?= te('Email address') ?></label>
    <div class="input-icon"><i class="bi bi-envelope"></i><input class="form-control <?= isset($errors['email']) ? 'is-invalid' : '' ?>" id="email" type="email" name="email" readonly data-noautofill autocomplete="off" value="<?= e($f['email']) ?>" placeholder="you@example.com" required autocomplete="email"></div>
    <?= field_error($errors, 'email') ?>
  </div>
  <div class="mb-3">
    <label class="form-label" for="username"><?= te('Username') ?></label>
    <div class="input-icon"><i class="bi bi-at"></i><input class="form-control <?= isset($errors['username']) ? 'is-invalid' : '' ?>" id="username" name="username" readonly data-noautofill autocomplete="off" value="<?= e($f['username']) ?>" placeholder="e.g. maria_santos" required pattern="[A-Za-z0-9._]{4,30}" autocomplete="username"></div>
    <?= field_error($errors, 'username') ?>
  </div>
  <div class="mb-2">
    <label class="form-label" for="password"><?= te('Password') ?></label>
    <div class="input-icon"><i class="bi bi-lock"></i><input class="form-control <?= isset($errors['password']) ? 'is-invalid' : '' ?>" id="password" type="password" name="password" required data-password readonly data-noautofill autocomplete="new-password"><button type="button" class="toggle-pass" aria-label="<?= te('Show or hide password') ?>"><i class="bi bi-eye"></i></button></div>
    <?= password_rules_html() ?>
    <?= field_error($errors, 'password') ?>
  </div>
  <div class="mb-3">
    <label class="form-label" for="password_confirm"><?= te('Confirm Password') ?></label>
    <div class="input-icon"><i class="bi bi-lock-fill"></i><input class="form-control <?= isset($errors['password_confirm']) ? 'is-invalid' : '' ?>" id="password_confirm" type="password" name="password_confirm" required data-password-confirm readonly data-noautofill autocomplete="new-password"><button type="button" class="toggle-pass" aria-label="<?= te('Show or hide password') ?>"><i class="bi bi-eye"></i></button></div>
    <div class="pw-match small mt-1" data-pw-match data-ok="<?= te('Passwords match.') ?>" data-bad="<?= te('Passwords do not match.') ?>" aria-live="polite"></div>
    <?= field_error($errors, 'password_confirm') ?>
  </div>
  <div class="form-check mb-3 small">
    <input class="form-check-input <?= isset($errors['privacy']) ? 'is-invalid' : '' ?>" type="checkbox" name="privacy" value="1" id="privacy" required <?= !empty($_POST['privacy']) ? 'checked' : '' ?>>
    <label class="form-check-label text-muted" for="privacy"><?= te('I agree that MyDentist Dental Clinic may store my personal information for appointment purposes, in line with the Data Privacy Act of 2012.') ?></label>
    <?= field_error($errors, 'privacy') ?>
  </div>
  <button class="btn btn-primary w-100 py-2"><?= te('Create Account') ?></button>
</form>
<p class="text-center small mt-3 mb-0"><?= te('Already have an account?') ?> <a href="<?= url('login.php?show=1') ?>" class="fw-semibold"><?= te('Log in') ?></a></p>
<?php include __DIR__ . '/includes/footer.php'; ?>
