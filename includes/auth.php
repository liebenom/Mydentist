<?php
/**
 * Authentication and role-based access control.
 * Roles: patient, staff, admin (admin = staff + system settings, backups, audit).
 */

function current_user(): ?array
{
    static $cache = [];
    $id = $_SESSION['user_id'] ?? null;
    if (!$id) {
        return null;
    }
    if (!isset($cache[$id])) {
        $u = db_one('SELECT id, role, full_name, username, email, phone, is_active, ' . (column_exists('users', 'avatar') ? 'avatar' : 'NULL AS avatar') . ' FROM users WHERE id = ?', [$id]);
        if (!$u || !$u['is_active']) {
            unset($_SESSION['user_id']);
            return null;
        }
        $cache[$id] = $u;
    }
    return $cache[$id];
}

function is_staff(?array $user = null): bool
{
    $user = $user ?? current_user();
    return $user && in_array($user['role'], ['staff', 'admin'], true);
}

function is_admin(?array $user = null): bool
{
    $user = $user ?? current_user();
    return $user && $user['role'] === 'admin';
}

function login_user(array $user): void
{
    session_regenerate_id(true);   // prevent session fixation
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['login_time'] = time();
    q('UPDATE users SET last_login_at = ? WHERE id = ?', [now_str(), $user['id']]);
}

function logout_user(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

function home_for(array $user): string
{
    return is_staff($user) ? 'admin/dashboard.php' : 'patient/dashboard.php';
}

/** Require a logged-in user, optionally with one of the given roles. */
function require_login(array $roles = []): array
{
    $user = current_user();
    if (!$user) {
        $_SESSION['after_login'] = $_SERVER['REQUEST_URI'] ?? null;
        flash('warning', 'Please log in to continue.');
        redirect('login.php?show=1');
    }
    if ($roles && !in_array($user['role'], $roles, true)) {
        http_response_code(403);
        flash('danger', 'You do not have permission to open that page.');
        redirect(home_for($user));
    }
    return $user;
}

function require_patient(): array
{
    return require_login(['patient']);
}

function require_staff(): array
{
    return require_login(['staff', 'admin']);
}

/** Front-desk work (walk-in booking, emergency, QR scanner, check-in) is STAFF-only. */
function is_front_desk(?array $user = null): bool
{
    $user = $user ?? current_user();
    return $user && $user['role'] === 'staff';
}

function require_front_desk(): array
{
    return require_login(['staff']);
}

function require_admin(): array
{
    return require_login(['admin']);
}

/** Basic brute-force protection: max 5 failed logins per 10 minutes per session+IP. */
function login_throttled(): bool
{
    $key = 'fails_' . md5(client_ip());
    $fails = array_filter($_SESSION[$key] ?? [], fn($t) => $t > time() - 600);
    $_SESSION[$key] = $fails;
    return count($fails) >= 5;
}

function login_failed(): void
{
    $_SESSION['fails_' . md5(client_ip())][] = time();
}

// ---------------------------------------------------------------------------
// GUEST role and QR code access
//
// A GUEST is a visitor who booked through Guest Mode, is NOT logged in, and has
// proven ownership of the booking with its secret link (code + 32-char token).
// QR codes exist only for Guest Mode bookings and only a GUEST may generate,
// view or download them. Patients, staff and admins never get QR images;
// staff may still read (scan) a guest's QR at check-in.
// ---------------------------------------------------------------------------

function is_guest_booking(array $appt): bool
{
    return ($appt['source'] ?? '') === 'guest';
}

/** Give this browser session the GUEST role for one booking (after its token was verified). */
function grant_guest_access(array $appt): void
{
    if (current_user() || !is_guest_booking($appt)) {
        return;
    }
    if (empty($_SESSION['guest_appts'])) {
        session_regenerate_id(true);
    }
    $_SESSION['guest_appts'][(int) $appt['id']] = true;
}

/** True when the current visitor has the GUEST role (for any booking). */
function is_guest_role(): bool
{
    return !current_user() && !empty($_SESSION['guest_appts']);
}

/**
 * The single permission check for QR codes. Used by qr.php, pages and emails.
 * @param string|null $token the booking's manage token, if supplied with the request
 */
function can_access_qr(array $appt, ?string $token = null): bool
{
    if (current_user()) {
        return false;                       // patients, staff and admins: never
    }
    if (!is_guest_booking($appt) || !in_array($appt['status'], ['pending', 'confirmed'], true)) {
        return false;                       // QR only for active Guest Mode bookings
    }
    if ($token !== null && $token !== '' && hash_equals((string) $appt['manage_token'], $token)) {
        return true;
    }
    return !empty($_SESSION['guest_appts'][(int) $appt['id']]);
}
