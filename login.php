<?php
require __DIR__ . '/includes/bootstrap.php';

if ($u = current_user()) {
    redirect(home_for($u));
}

// Opening login.php directly (typed or bookmarked) shows the homepage first.
// The login form opens from the site's own Login / Admin buttons.
if (!is_post() && !isset($_GET['show']) && !isset($_GET['staff']) && empty($_SESSION['flash']) && empty($_SESSION['after_login'])) {
    $ref = (string) ($_SERVER['HTTP_REFERER'] ?? '');
    $sameSite = $ref !== '' && parse_url($ref, PHP_URL_HOST) === preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? ''));
    if (!$sameSite) {
        redirect('index.php');
    }
}

$login = '';
if (is_post()) {
    csrf_check();
    $login = input('login');
    $password = (string) ($_POST['password'] ?? '');
    if (login_throttled()) {
        flash('danger', 'Too many failed attempts. Please wait 10 minutes and try again.');
    } else {
        $user = db_one('SELECT * FROM users WHERE username = ? OR email = ?', [$login, strtolower($login)]);
        if ($user && $user['is_active'] && password_verify($password, $user['password_hash'])) {
            if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
                q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $user['id']]);
            }
            login_user($user);
            if (is_staff($user)) {
                audit('login', 'Staff login: ' . $user['username']);
            }
            $next = $_SESSION['after_login'] ?? null;
            unset($_SESSION['after_login']);
            if ($next && str_starts_with($next, BASE_PATH . '/') && !str_contains($next, '//')) {
                header('Location: ' . $next);
                exit;
            }
            redirect(home_for($user));
        }
        login_failed();
        flash('danger', $user && !$user['is_active'] ? 'This account has been deactivated. Please contact the clinic.' : 'Incorrect username or password.');
    }
}

$title = 'Login';
$layout = 'auth';
$staffMode = isset($_GET['staff']);
include __DIR__ . '/includes/header.php';
?>
<h1><?= $staffMode ? 'Staff Sign In' : 'Welcome Back' ?></h1>
<p class="sub"><?= $staffMode ? 'Sign in to the clinic admin portal' : 'Sign in to manage your appointments' ?></p>
<?= flash_render() ?>
<form method="post" class="needs-validation" novalidate autocomplete="off">
  <?= csrf_field() ?>
  <div class="mb-3">
    <label class="form-label" for="login">Username or email</label>
    <div class="input-icon"><i class="bi bi-person"></i>
      <input class="form-control" id="login" name="login" value="" placeholder="Enter your username" required autofocus readonly data-noautofill autocomplete="off"></div>
  </div>
  <div class="mb-3">
    <label class="form-label" for="password">Password</label>
    <div class="input-icon"><i class="bi bi-lock"></i>
      <input class="form-control" id="password" type="password" name="password" placeholder="Enter your password" required readonly data-noautofill autocomplete="new-password">
      <button type="button" class="toggle-pass" aria-label="Show password"><i class="bi bi-eye"></i></button></div>
  </div>
  <button class="btn btn-primary w-100 py-2">Login</button>
</form>
<?php if (!$staffMode): ?>
  <div class="text-center my-3 text-muted small">or</div>
  <a href="<?= url('guest/book.php') ?>" class="btn btn-soft w-100"><i class="bi bi-person-badge"></i> Book as Guest (no account)</a>
  <p class="text-center small mt-3 mb-0">Don't have an account? <a href="<?= url('register.php') ?>" class="fw-semibold">Register now</a></p>
<?php endif; ?>
<p class="text-center small text-muted mt-2 mb-0">Forgot your password? Please contact the clinic at <?= e(CLINIC_PHONE) ?>.</p>
<?php include __DIR__ . '/includes/footer.php'; ?>
