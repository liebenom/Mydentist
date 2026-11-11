<?php
/**
 * Profile pictures.
 * Files live in storage/avatars/ (blocked from direct web access) and are served only
 * through avatar.php to their owner. Uploads are validated as real JPG/PNG/WEBP images,
 * limited to 2 MB, and re-encoded (when GD is available) so no hidden content survives.
 */

const AVATAR_MAX_BYTES = 2 * 1024 * 1024;
const AVATAR_TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

function avatar_dir(): string
{
    $dir = ROOT_DIR . '/storage/avatars';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    return $dir;
}

function avatar_path(?string $file): ?string
{
    if (!$file || !preg_match('/^u\d+-[a-f0-9]{16}\.(jpg|png|webp)$/', $file)) {
        return null;
    }
    $p = avatar_dir() . '/' . $file;
    return is_file($p) ? $p : null;
}

/**
 * Save an uploaded profile picture for $userId (always the logged-in user).
 * Returns null on success or an error message.
 */
function avatar_save_upload(int $userId, array $file): ?string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return t('Please choose an image first.');
    }
    if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE || ($file['size'] ?? 0) > AVATAR_MAX_BYTES) {
        return t('The image is too large. Maximum size is 2 MB.');
    }
    if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
        return t('Upload failed. Please try again.');
    }
    $ext = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
        return t('Unsupported file type. Please upload a JPG, JPEG, PNG or WEBP image.');
    }
    // Check the real content, not the name: must be a genuine image of an allowed type
    $info = @getimagesize($file['tmp_name']);
    $mime = $info['mime'] ?? '';
    if (function_exists('finfo_open')) {
        $fi = finfo_open(FILEINFO_MIME_TYPE);
        $mime2 = finfo_file($fi, $file['tmp_name']);
        finfo_close($fi);
        if ($mime2 !== $mime) {
            $mime = '';
        }
    }
    if (!$info || !isset(AVATAR_TYPES[$mime]) || $info[0] < 16 || $info[1] < 16 || $info[0] * $info[1] > 40000000) {
        return t('Unsupported file type. Please upload a JPG, JPEG, PNG or WEBP image.');
    }
    $outExt = AVATAR_TYPES[$mime];
    $name = 'u' . $userId . '-' . bin2hex(random_bytes(8)) . '.' . $outExt;
    $dest = avatar_dir() . '/' . $name;

    $reencoded = false;
    if (function_exists('imagecreatetruecolor')) {
        $src = match ($mime) {
            'image/jpeg' => function_exists('imagecreatefromjpeg') ? @imagecreatefromjpeg($file['tmp_name']) : false,
            'image/png'  => function_exists('imagecreatefrompng') ? @imagecreatefrompng($file['tmp_name']) : false,
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($file['tmp_name']) : false,
            default => false,
        };
        if ($src) {
            // square crop + resize to 400x400, which also strips metadata and hidden payloads
            $w = imagesx($src);
            $h = imagesy($src);
            $side = min($w, $h);
            $dst = imagecreatetruecolor(400, 400);
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 255, 255, 255, 127));
            imagecopyresampled($dst, $src, 0, 0, intdiv($w - $side, 2), intdiv($h - $side, 2), 400, 400, $side, $side);
            $ok = match ($outExt) {
                'jpg'  => imagejpeg($dst, $dest, 88),
                'png'  => imagepng($dst, $dest, 6),
                'webp' => function_exists('imagewebp') ? imagewebp($dst, $dest, 85) : false,
            };
            imagedestroy($src);
            imagedestroy($dst);
            $reencoded = (bool) $ok;
        }
    }
    if (!$reencoded && !move_uploaded_file($file['tmp_name'], $dest)) {
        return t('Upload failed. Please try again.');
    }
    // replace the old picture
    $old = db_val('SELECT avatar FROM users WHERE id = ?', [$userId]);
    q('UPDATE users SET avatar = ? WHERE id = ?', [$name, $userId]);
    if ($old && ($p = avatar_path($old))) {
        @unlink($p);
    }
    return null;
}

function avatar_remove(int $userId): void
{
    $old = db_val('SELECT avatar FROM users WHERE id = ?', [$userId]);
    q('UPDATE users SET avatar = NULL WHERE id = ?', [$userId]);
    if ($old && ($p = avatar_path($old))) {
        @unlink($p);
    }
}

/** Avatar for display: the uploaded picture, or the existing initials badge. */
function avatar_html(array $user, string $class = '', int $size = 0): string
{
    $style = $size ? ' style="width:' . $size . 'px;height:' . $size . 'px;font-size:' . max(11, (int) ($size / 2.6)) . 'px"' : '';
    if (!empty($user['avatar']) && avatar_path($user['avatar'])) {
        return '<img class="avatar avatar-img ' . e($class) . '"' . $style . ' src="' . e(url('avatar.php?v=' . urlencode($user['avatar']))) . '" alt="' . te('Profile picture') . '">';
    }
    return '<div class="avatar ' . e($class) . '"' . $style . '>' . e(initials($user['full_name'])) . '</div>';
}
