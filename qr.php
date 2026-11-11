<?php
/**
 * QR code image for a GUEST booking: qr.php?code=MD-XXXXXX&token=...[&download=1]
 * Access is enforced by can_access_qr(): only a not-logged-in GUEST holding the
 * booking's secret token (or a guest session for it) gets the image.
 */
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/qrcode.php';

$code = strtoupper((string) ($_GET['code'] ?? ''));
$token = (string) ($_GET['token'] ?? '');
$appt = preg_match('/^MD-[A-Z0-9]{6}$/', $code) ? find_appointment_by_code($code) : null;

if (!$appt || !can_access_qr($appt, $token)) {
    if ($appt && current_user()) {
        audit('qr_access_denied', 'QR request for ' . $code . ' by ' . current_user()['role'] . ' ' . current_user()['username'], (int) $appt['id']);
    }
    http_response_code(403);
    header('Content-Type: text/plain');
    exit('QR codes are only available to guests for their own Guest Mode booking.');
}

header('Cache-Control: private, no-store');
header('X-Robots-Tag: noindex');
if (!qr_can_png()) {
    // GD extension not enabled: send the QR code as SVG instead
    header('Content-Type: image/svg+xml');
    if (!empty($_GET['download'])) {
        header('Content-Disposition: attachment; filename="MyDentist-' . $code . '.svg"');
    }
    echo (new QrCode($code))->svg();
    exit;
}
header('Content-Type: image/png');
if (!empty($_GET['download'])) {
    header('Content-Disposition: attachment; filename="MyDentist-' . $code . '.png"');
}
echo qr_png($code, 10);
