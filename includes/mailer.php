<?php
/**
 * Lightweight SMTP mailer (no Composer packages needed).
 * Supports STARTTLS (587) and SSL (465) with AUTH LOGIN, HTML + plain text,
 * and inline images (used for the appointment QR code).
 *
 * Settings come from Admin > Settings > Email (stored in the database) and fall back
 * to config.php. When sending is off, every message is saved to storage/mail/ instead,
 * and can be viewed from Admin > Email Log.
 */

/** Email setting: Admin > Settings > Email first, then config.php. */
function mail_cfg(string $key)
{
    static $cache = [];
    $map = ['enabled' => 'MAIL_ENABLED', 'host' => 'SMTP_HOST', 'port' => 'SMTP_PORT', 'secure' => 'SMTP_SECURE',
        'user' => 'SMTP_USER', 'pass' => 'SMTP_PASS', 'from' => 'MAIL_FROM', 'from_name' => 'MAIL_FROM_NAME', 'verify' => 'SMTP_VERIFY_SSL'];
    if (!array_key_exists($key, $cache)) {
        $v = null;
        try {
            $v = setting_get('mail_' . $key);
        } catch (Throwable $e) {
        }
        if ($v === null) {
            $v = constant($map[$key]);
            // ignore the example values that come with config.php
            if (in_array($v, ['your-16-char-app-password', 'your.clinic@gmail.com'], true)) {
                $v = '';
            }
        } elseif ($key === 'enabled' || $key === 'verify') {
            $v = $v === '1';
        } elseif ($key === 'port') {
            $v = (int) $v;
        }
        $cache[$key] = $v;
    }
    return $cache[$key];
}

function mail_dir(): string
{
    $dir = ROOT_DIR . '/storage/mail';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    return $dir;
}

/**
 * @param array $inline list of ['cid' => 'qr', 'data' => binary, 'type' => 'image/png', 'name' => 'qr.png']
 */
function send_mail(string $to, string $toName, string $subject, string $html, array $inline = []): bool
{
    if (!valid_email($to)) {
        return false;
    }
    $text = trim(html_entity_decode(strip_tags(preg_replace(['#<br\s*/?>#i', '#</(p|div|tr|h[1-6])>#i'], "\n", $html)), ENT_QUOTES, 'UTF-8'));
    $text = preg_replace("/\n\s*\n\s*\n+/", "\n\n", $text);

    // Always keep a copy for the in-app Email Log. Inline images (the guest's QR code)
    // are NOT stored: QR codes may only be seen by the GUEST, not by staff.
    $preview = preg_replace(
        '#<img[^>]*src="cid:[^"]*"[^>]*>#i',
        '<div style="display:inline-block;width:170px;height:170px;line-height:170px;border:1px dashed #9cb3c2;border-radius:10px;color:#5f7383;font-size:12px">QR code &middot; visible to the guest only</div>',
        $html
    );
    $meta = ['to' => $to, 'name' => $toName, 'subject' => $subject, 'date' => now_str(), 'sent' => mail_cfg('enabled')];
    $file = mail_dir() . '/' . date('Ymd-His') . '-' . random_code(5, 'abcdefghijklmnopqrstuvwxyz0123456789') . '.html';
    file_put_contents($file, '<!--' . json_encode($meta) . "-->\n" . $preview);

    if (!mail_cfg('enabled')) {
        return true;
    }

    try {
        smtp_send($to, $toName, $subject, $html, $text, $inline);
        return true;
    } catch (Throwable $ex) {
        file_put_contents(ROOT_DIR . '/storage/mail_errors.log', '[' . now_str() . "] To $to: " . $ex->getMessage() . PHP_EOL, FILE_APPEND);
        $meta['sent'] = false;
        $meta['error'] = mail_error_explained($ex->getMessage());
        file_put_contents($file, '<!--' . json_encode($meta) . "-->\n" . $preview);
        return false;
    }
}

function mime_header(string $value): string
{
    return preg_match('/[^\x20-\x7E]/', $value) ? '=?UTF-8?B?' . base64_encode($value) . '?=' : $value;
}

function build_mime(string $to, string $toName, string $subject, string $html, string $text, array $inline): string
{
    $altB = 'alt_' . bin2hex(random_bytes(8));
    $relB = 'rel_' . bin2hex(random_bytes(8));
    $domain = substr(strrchr((string) mail_cfg('from'), '@') ?: '@localhost', 1);

    $headers = [
        'Date: ' . date('r'),
        'From: ' . mime_header((string) mail_cfg('from_name')) . ' <' . mail_cfg('from') . '>',
        'To: ' . ($toName !== '' ? mime_header($toName) . ' ' : '') . '<' . $to . '>',
        'Subject: ' . mime_header($subject),
        'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $domain . '>',
        'MIME-Version: 1.0',
        'Content-Type: multipart/alternative; boundary="' . $altB . '"',
    ];

    $body  = "--$altB\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n";
    $body .= chunk_split(base64_encode($text)) . "\r\n";
    $body .= "--$altB\r\n";
    if ($inline) {
        $body .= "Content-Type: multipart/related; boundary=\"$relB\"\r\n\r\n";
        $body .= "--$relB\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n";
        $body .= chunk_split(base64_encode($html)) . "\r\n";
        foreach ($inline as $img) {
            $body .= "--$relB\r\nContent-Type: {$img['type']}; name=\"{$img['name']}\"\r\n"
                   . "Content-Transfer-Encoding: base64\r\nContent-ID: <{$img['cid']}>\r\n"
                   . "Content-Disposition: inline; filename=\"{$img['name']}\"\r\n\r\n"
                   . chunk_split(base64_encode($img['data'])) . "\r\n";
        }
        $body .= "--$relB--\r\n";
    } else {
        $body .= "Content-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n";
        $body .= chunk_split(base64_encode($html)) . "\r\n";
    }
    $body .= "--$altB--\r\n";

    return implode("\r\n", $headers) . "\r\n\r\n" . $body;
}

function smtp_send(string $to, string $toName, string $subject, string $html, string $text, array $inline): void
{
    $ctx = stream_context_create(['ssl' => [
        'verify_peer'      => (bool) mail_cfg('verify'),
        'verify_peer_name' => (bool) mail_cfg('verify'),
        'allow_self_signed' => !mail_cfg('verify'),
    ]]);
    $remote = (mail_cfg('secure') === 'ssl' ? 'ssl://' : 'tcp://') . mail_cfg('host') . ':' . mail_cfg('port');
    $fp = @stream_socket_client($remote, $errno, $errstr, 20, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) {
        throw new RuntimeException("Cannot connect to SMTP server $remote: $errstr ($errno)");
    }
    stream_set_timeout($fp, 30);

    $read = function () use ($fp): string {
        $data = '';
        while (($line = fgets($fp, 1024)) !== false) {
            $data .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') {
                break;
            }
        }
        return $data;
    };
    $cmd = function (?string $command, array $expect) use ($fp, $read): string {
        if ($command !== null) {
            fwrite($fp, $command . "\r\n");
        }
        $resp = $read();
        if (!in_array((int) substr($resp, 0, 3), $expect, true)) {
            $shown = $command && stripos($command, 'AUTH') === false && strlen($command) < 80 ? $command : '(command)';
            throw new RuntimeException("SMTP error after $shown: " . trim($resp));
        }
        return $resp;
    };

    $hostname = gethostname() ?: 'localhost';
    $cmd(null, [220]);
    $cmd("EHLO $hostname", [250]);
    if (mail_cfg('secure') === 'tls') {
        $cmd('STARTTLS', [220]);
        if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT | (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT') ? STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT : 0))) {
            throw new RuntimeException('STARTTLS negotiation failed');
        }
        $cmd("EHLO $hostname", [250]);
    }
    if ((string) mail_cfg('user') !== '') {
        $cmd('AUTH LOGIN', [334]);
        $cmd(base64_encode((string) mail_cfg('user')), [334]);
        $cmd(base64_encode((string) mail_cfg('pass')), [235]);
    }
    $cmd('MAIL FROM:<' . mail_cfg('from') . '>', [250]);
    $cmd('RCPT TO:<' . $to . '>', [250, 251]);
    $cmd('DATA', [354]);
    $message = build_mime($to, $toName, $subject, $html, $text, $inline);
    $message = preg_replace('/^\./m', '..', $message);   // dot-stuffing
    fwrite($fp, $message . "\r\n.\r\n");
    $cmd(null, [250]);
    $cmd('QUIT', [221, 250]);
    fclose($fp);
}

/** Most recent SMTP error message (for the Settings test email). */
function mail_last_error(): string
{
    $f = ROOT_DIR . '/storage/mail_errors.log';
    if (!is_file($f)) {
        return 'unknown error';
    }
    $lines = file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $last = (string) end($lines);
    $msg = preg_replace('/^\[[^\]]*\] To [^:]*: /', '', $last) ?: 'unknown error';
    return mail_error_explained($msg);
}

/** Turns common Gmail/SMTP errors into plain words for the clinic. */
function mail_error_explained(string $msg): string
{
    if (preg_match('/\b535\b|BadCredentials|Username and Password not accepted/i', $msg)) {
        return 'Gmail did not accept the email address or App Password in Settings → Email. '
             . 'Use the 16-letter App Password (not your normal Gmail password), created while signed in to that same Gmail address, then save and try again.';
    }
    if (preg_match('/534|InvalidSecondFactor|Application-specific password required/i', $msg)) {
        return 'Gmail requires an App Password. Turn on 2-Step Verification, create an App Password and paste it in Settings → Email.';
    }
    if (preg_match('/Cannot connect|getaddrinfo|timed out|Connection refused/i', $msg)) {
        return 'Could not reach the Gmail server. Check that this computer is connected to the internet.';
    }
    if (preg_match('/certificate|SSL operation failed|crypto/i', $msg)) {
        return 'A security certificate problem stopped the email. Open config.php and set SMTP_VERIFY_SSL to false, then try again.';
    }
    return $msg;
}

/** Read logged emails (newest first) for the Email Log page. */
function mail_log_list(int $limit = 100): array
{
    $files = glob(mail_dir() . '/*.html') ?: [];
    rsort($files);
    $out = [];
    foreach (array_slice($files, 0, $limit) as $f) {
        $first = fgets(fopen($f, 'r'));
        $meta = json_decode(trim(substr(trim($first), 4, -3)), true) ?: [];
        $meta['file'] = basename($f);
        $out[] = $meta;
    }
    return $out;
}
