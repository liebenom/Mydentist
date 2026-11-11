<?php
/**
 * General helpers: escaping, URLs, flash messages, CSRF, formatting, audit log.
 */

function e($value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function url(string $path = ''): string
{
    return BASE_PATH . '/' . ltrim($path, '/');
}

function abs_url(string $path = ''): string
{
    return rtrim(APP_URL, '/') . '/' . ltrim($path, '/');
}

function redirect(string $path): void
{
    header('Location: ' . (preg_match('#^https?://#', $path) ? $path : url($path)));
    exit;
}

function now_str(): string
{
    return date('Y-m-d H:i:s');
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function input(string $key, $default = '')
{
    $v = $_POST[$key] ?? $_GET[$key] ?? $default;
    return is_string($v) ? trim($v) : $v;
}

function input_int(string $key, int $default = 0): int
{
    $v = $_POST[$key] ?? $_GET[$key] ?? null;
    return is_numeric($v) ? (int) $v : $default;
}

// ------------------------------------------------------------ Flash messages
function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function flash_render(): string
{
    if (empty($_SESSION['flash'])) {
        return '';
    }
    $icons = ['success' => 'check-circle', 'danger' => 'exclamation-octagon', 'warning' => 'exclamation-triangle', 'info' => 'info-circle'];
    $html = '';
    foreach ($_SESSION['flash'] as $f) {
        $icon = $icons[$f['type']] ?? 'info-circle';
        $html .= '<div class="alert alert-' . e($f['type']) . ' alert-dismissible fade show d-flex align-items-start gap-2" role="alert">'
               . '<i class="bi bi-' . $icon . ' mt-1"></i><div>' . $f['message'] . '</div>'
               . '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>';
    }
    unset($_SESSION['flash']);
    return $html;
}

// ------------------------------------------------------------ CSRF protection
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function csrf_check(): void
{
    $sent = $_POST['csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!is_string($sent) || !hash_equals(csrf_token(), $sent)) {
        http_response_code(419);
        flash('danger', 'Your session expired or the form was submitted twice. Please try again.');
        header('Location: ' . ($_SERVER['REQUEST_URI'] ?? url('index.php')));
        exit;
    }
}

// ------------------------------------------------------------ Formatting
function fmt_date(?string $dt, string $format = 'M j, Y'): string
{
    return $dt ? date($format, strtotime($dt)) : '';
}

function fmt_time(?string $dt): string
{
    return $dt ? date('g:i A', strtotime($dt)) : '';
}

function fmt_datetime(?string $dt): string
{
    return $dt ? date('M j, Y g:i A', strtotime($dt)) : '';
}

function peso($amount): string
{
    return '₱' . number_format((float) $amount, 0);
}

function status_label(string $status): string
{
    return [
        'pending'   => 'Pending',
        'confirmed' => 'Confirmed',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
        'no_show'   => 'No-Show',
    ][$status] ?? ucfirst($status);
}

function status_badge(string $status): string
{
    $icons = ['pending' => 'clock', 'confirmed' => 'check-circle', 'completed' => 'check2-all', 'cancelled' => 'x-circle', 'no_show' => 'person-x'];
    return '<span class="status-badge status-' . e($status) . '"><i class="bi bi-' . ($icons[$status] ?? 'dot') . '"></i> ' . e(status_label_t($status)) . '</span>';
}

function source_label(string $source): string
{
    return ['online' => 'Online', 'guest' => 'Guest', 'walk_in' => 'Walk-in / Phone', 'emergency' => 'Emergency'][$source] ?? $source;
}

function initials(string $name): string
{
    $name = preg_replace('/^(dr\.?|dra\.?)\s+/i', '', trim($name));
    $parts = preg_split('/\s+/', $name);
    $out = strtoupper(substr($parts[0] ?? '', 0, 1));
    if (count($parts) > 1) {
        $out .= strtoupper(substr(end($parts), 0, 1));
    }
    return $out ?: '?';
}

function client_ip(): string
{
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? 'cli'), 0, 45);
}

function random_code(int $length, string $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'): string
{
    $out = '';
    $max = strlen($alphabet) - 1;
    for ($i = 0; $i < $length; $i++) {
        $out .= $alphabet[random_int(0, $max)];
    }
    return $out;
}

/** Unique booking reference, e.g. MD-7K3QX9. This is what the QR code contains. */
function generate_appointment_code(): string
{
    do {
        $code = 'MD-' . random_code(6);
    } while (db_val('SELECT COUNT(*) FROM appointments WHERE code = ?', [$code]));
    return $code;
}

// ------------------------------------------------------------ Audit log
function audit(string $action, string $details = '', ?int $appointmentId = null): void
{
    $user = current_user();
    db_insert('audit_logs', [
        'user_id'        => $user['id'] ?? null,
        'action'         => $action,
        'details'        => $details,
        'appointment_id' => $appointmentId,
        'ip_address'     => client_ip(),
        'created_at'     => now_str(),
    ]);
}

// ------------------------------------------------------------ Validation helpers
function valid_email(string $email): bool
{
    return (bool) filter_var($email, FILTER_VALIDATE_EMAIL);
}

function valid_phone(string $phone): bool
{
    return (bool) preg_match('/^\+?[0-9][0-9\s\-()]{6,19}$/', $phone);
}

/**
 * Password policy: at least 8 characters, one uppercase letter (A–Z), one number (0–9)
 * and one special character (e.g. @ # $ % ! &). Returns the unmet rules, [] when valid.
 */
function password_rule_failures(string $password): array
{
    $fail = [];
    if (strlen($password) < 8) $fail[] = t('Minimum of 8 characters.');
    if (!preg_match('/[A-Z]/', $password)) $fail[] = t('Uppercase letter required.');
    if (!preg_match('/[0-9]/', $password)) $fail[] = t('Number required.');
    if (!preg_match('/[^A-Za-z0-9\s]/', $password)) $fail[] = t('Special character required (e.g. @ # $ % ! &).');
    return $fail;
}

function password_problem(string $password): ?string
{
    $fail = password_rule_failures($password);
    return $fail ? t('Password does not meet the requirements:') . ' ' . implode(' ', $fail) : null;
}

/** Live password checklist shown under a password field (updated by assets/js/app.js). */
function password_rules_html(): string
{
    $rules = ['length' => 'Minimum of 8 characters.', 'upper' => 'Uppercase letter required.', 'number' => 'Number required.', 'special' => 'Special character required (e.g. @ # $ % ! &).'];
    $html = '<ul class="pw-rules" data-pw-rules aria-live="polite">';
    foreach ($rules as $k => $label) {
        $html .= '<li data-rule="' . $k . '">' . te($label) . '</li>';
    }
    return $html . '</ul>';
}

/** Simple pagination helper: returns [offset, limit, page, pages]. */
function paginate(int $total, int $perPage = 25): array
{
    $pages = max(1, (int) ceil($total / $perPage));
    $page = min($pages, max(1, input_int('page', 1)));
    return [($page - 1) * $perPage, $perPage, $page, $pages];
}

function pagination_links(int $page, int $pages): string
{
    if ($pages <= 1) {
        return '';
    }
    $params = $_GET;
    $html = '<nav><ul class="pagination pagination-sm mb-0">';
    for ($p = 1; $p <= $pages; $p++) {
        if ($pages > 9 && abs($p - $page) > 2 && $p !== 1 && $p !== $pages) {
            if (abs($p - $page) === 3) {
                $html .= '<li class="page-item disabled"><span class="page-link">…</span></li>';
            }
            continue;
        }
        $params['page'] = $p;
        $html .= '<li class="page-item' . ($p === $page ? ' active' : '') . '"><a class="page-link" href="?' . e(http_build_query($params)) . '">' . $p . '</a></li>';
    }
    return $html . '</ul></nav>';
}

/**
 * Front-end libraries load from the CDN, or from assets/vendor/ when they have been
 * downloaded there (run: php tools/download_assets.php) so the system works offline.
 */
const VENDOR_LIBS = [
    'bootstrap.css'  => ['https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.3/css/bootstrap.min.css', 'bootstrap/bootstrap.min.css'],
    'bootstrap.js'   => ['https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.3/js/bootstrap.bundle.min.js', 'bootstrap/bootstrap.bundle.min.js'],
    'icons.css'      => ['https://cdnjs.cloudflare.com/ajax/libs/bootstrap-icons/1.11.3/font/bootstrap-icons.min.css', 'bootstrap-icons/bootstrap-icons.min.css'],
    'chart.js'       => ['https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js', 'chartjs/chart.umd.min.js'],
    'html5-qrcode'   => ['https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js', 'html5-qrcode/html5-qrcode.min.js'],
];

function lib(string $name): string
{
    [$cdn, $local] = VENDOR_LIBS[$name];
    return is_file(ROOT_DIR . '/assets/vendor/' . $local) ? url('assets/vendor/' . $local) : $cdn;
}
