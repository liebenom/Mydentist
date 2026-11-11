<?php
/**
 * Serves the logged-in user's OWN profile picture. There is no user-id parameter, so
 * nobody can request another user's picture through this address.
 */
require __DIR__ . '/includes/bootstrap.php';

$user = current_user();
$path = $user ? avatar_path($user['avatar'] ?? null) : null;
if (!$path) {
    http_response_code(404);
    exit;
}
$types = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
header('Content-Type: ' . $types[pathinfo($path, PATHINFO_EXTENSION)]);
header('Cache-Control: private, max-age=86400');
header('X-Content-Type-Options: nosniff');
header('Content-Length: ' . filesize($path));
readfile($path);
