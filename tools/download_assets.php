<?php
/**
 * Downloads Bootstrap, Bootstrap Icons, Chart.js and the QR scanner library into
 * assets/vendor/ so the system works without internet (e.g. during the defense).
 * Run once, while connected:   php tools/download_assets.php
 * (XAMPP on Windows:  C:\xampp\php\php.exe C:\xampp\htdocs\mydentist\tools\download_assets.php)
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Run this from the command line.');
}
$root = dirname(__DIR__) . '/assets/vendor/';
$files = [
    'bootstrap/bootstrap.min.css'            => 'https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.3/css/bootstrap.min.css',
    'bootstrap/bootstrap.bundle.min.js'      => 'https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.3/js/bootstrap.bundle.min.js',
    'bootstrap-icons/bootstrap-icons.min.css' => 'https://cdnjs.cloudflare.com/ajax/libs/bootstrap-icons/1.11.3/font/bootstrap-icons.min.css',
    'bootstrap-icons/fonts/bootstrap-icons.woff2' => 'https://cdnjs.cloudflare.com/ajax/libs/bootstrap-icons/1.11.3/font/fonts/bootstrap-icons.woff2',
    'bootstrap-icons/fonts/bootstrap-icons.woff'  => 'https://cdnjs.cloudflare.com/ajax/libs/bootstrap-icons/1.11.3/font/fonts/bootstrap-icons.woff',
    'chartjs/chart.umd.min.js'               => 'https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js',
    'html5-qrcode/html5-qrcode.min.js'       => 'https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js',
];
$ctx = stream_context_create(['http' => ['timeout' => 60, 'follow_location' => 1, 'user_agent' => 'MyDentist-Setup']]);
$ok = 0;
foreach ($files as $local => $remote) {
    $dest = $root . $local;
    if (!is_dir(dirname($dest))) {
        mkdir(dirname($dest), 0775, true);
    }
    echo str_pad($local, 48) . ' ';
    $data = @file_get_contents($remote, false, $ctx);
    if ($data === false || strlen($data) < 100) {
        echo "FAILED\n";
        continue;
    }
    file_put_contents($dest, $data);
    echo number_format(strlen($data) / 1024, 1) . " KB\n";
    $ok++;
}
echo "\n$ok of " . count($files) . " files downloaded. The pages now use the local copies automatically.\n";
