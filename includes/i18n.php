<?php
/**
 * Multilingual support for the schedule: English, Filipino and Cebuano (Bisaya).
 *
 * - t('English text', ['name' => 'Ana']) returns the text in the visitor's language
 *   (translations live in lang/fil.php and lang/ceb.php, keyed by the English text).
 *   Missing entries fall back to English, so nothing ever breaks.
 * - Only labels and wording are translated. Stored data (dates, times, statuses,
 *   services in the database) is never changed by switching languages.
 * - The choice is kept in the session and a cookie, set with lang.php?set=fil.
 */

const LANGUAGES = [
    'en'  => ['name' => 'English',  'short' => 'EN',  'html' => 'en'],
    'fil' => ['name' => 'Filipino', 'short' => 'FIL', 'html' => 'fil'],
    'ceb' => ['name' => 'Cebuano',  'short' => 'CEB', 'html' => 'ceb'],
];

function current_lang(): string
{
    static $lang = null;
    if ($lang === null) {
        $lang = $_SESSION['lang'] ?? $_COOKIE['md_lang'] ?? 'en';
        if (!isset(LANGUAGES[$lang])) {
            $lang = 'en';
        }
    }
    return $lang;
}

function set_lang(string $lang): void
{
    if (!isset(LANGUAGES[$lang])) {
        return;
    }
    $_SESSION['lang'] = $lang;
    if (PHP_SAPI !== 'cli') {
        setcookie('md_lang', $lang, [
            'expires' => time() + 365 * 86400, 'path' => BASE_PATH === '' ? '/' : BASE_PATH . '/',
            'samesite' => 'Lax', 'httponly' => true,
        ]);
    }
}

function lang_table(string $lang): array
{
    static $tables = [];
    if (!isset($tables[$lang])) {
        $file = ROOT_DIR . '/lang/' . $lang . '.php';
        $tables[$lang] = ($lang !== 'en' && is_file($file)) ? (require $file) : [];
    }
    return $tables[$lang];
}

/** Translate an English string into the current language. {placeholders} are replaced from $vars. */
function t(string $text, array $vars = []): string
{
    $out = lang_table(current_lang())[$text] ?? $text;
    if ($vars) {
        $out = strtr($out, array_combine(array_map(fn($k) => '{' . $k . '}', array_keys($vars)), array_map('strval', array_values($vars))));
    }
    return $out;
}

/** Escaped translation for HTML output. */
function te(string $text, array $vars = []): string
{
    return e(t($text, $vars));
}

/** Default service names / descriptions are translated for display; custom ones stay as typed. */
function svc_t(?string $text): string
{
    if ($text === null) {
        return '';
    }
    return implode(' + ', array_map('t', explode(' + ', $text)));
}

// ------------------------------------------------------------ Localised dates
function lang_days(bool $short = false): array
{
    $sets = [
        'en'  => [['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'], ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat']],
        'fil' => [['Linggo', 'Lunes', 'Martes', 'Miyerkules', 'Huwebes', 'Biyernes', 'Sabado'], ['Lin', 'Lun', 'Mar', 'Miy', 'Huw', 'Biy', 'Sab']],
        'ceb' => [['Domingo', 'Lunes', 'Martes', 'Miyerkules', 'Huwebes', 'Biyernes', 'Sabado'], ['Dom', 'Lun', 'Mar', 'Miy', 'Huw', 'Biy', 'Sab']],
    ];
    return $sets[current_lang()][$short ? 1 : 0];
}

function lang_months(bool $short = false): array
{
    $sets = [
        'en'  => [['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'],
                  ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec']],
        'fil' => [['Enero', 'Pebrero', 'Marso', 'Abril', 'Mayo', 'Hunyo', 'Hulyo', 'Agosto', 'Setyembre', 'Oktubre', 'Nobyembre', 'Disyembre'],
                  ['Ene', 'Peb', 'Mar', 'Abr', 'May', 'Hun', 'Hul', 'Ago', 'Set', 'Okt', 'Nob', 'Dis']],
        'ceb' => [['Enero', 'Pebrero', 'Marso', 'Abril', 'Mayo', 'Hunyo', 'Hulyo', 'Agosto', 'Septiyembre', 'Oktubre', 'Nobyembre', 'Disyembre'],
                  ['Ene', 'Peb', 'Mar', 'Abr', 'May', 'Hun', 'Hul', 'Ago', 'Sep', 'Okt', 'Nob', 'Dis']],
    ];
    return $sets[current_lang()][$short ? 1 : 0];
}

/**
 * date() with translated day and month names.
 * Supports the same letters as date(); D l M F are localised.
 */
function ldate(string $format, $when): string
{
    $ts = is_int($when) ? $when : strtotime((string) $when);
    if ($ts === false) {
        return '';
    }
    $out = '';
    $len = strlen($format);
    for ($i = 0; $i < $len; $i++) {
        $c = $format[$i];
        if ($c === '\\' && $i + 1 < $len) {
            $out .= $format[++$i];
            continue;
        }
        switch ($c) {
            case 'D': $out .= lang_days(true)[(int) date('w', $ts)]; break;
            case 'l': $out .= lang_days()[(int) date('w', $ts)]; break;
            case 'M': $out .= lang_months(true)[(int) date('n', $ts) - 1]; break;
            case 'F': $out .= lang_months()[(int) date('n', $ts) - 1]; break;
            default:  $out .= ctype_alpha($c) ? date($c, $ts) : $c;
        }
    }
    return $out;
}

/** Translated status label for display (stored status values never change). */
function status_label_t(string $status): string
{
    return t(status_label($status));
}

/** Translated clinic-days label, e.g. "Lun – Sab". */
function clinic_days_label_t(): string
{
    $short = lang_days(true);
    $days = CLINIC_DAYS;
    sort($days);
    $name = fn(int $iso) => $short[$iso % 7];
    $contiguous = count($days) > 2 && end($days) - $days[0] === count($days) - 1;
    return $contiguous ? $name($days[0]) . ' – ' . $name(end($days)) : implode(', ', array_map($name, $days));
}

/** Strings and calendar names used by the schedule picker in assets/js/app.js. */
function js_i18n(): array
{
    $keys = [
        'Checking availability…', 'Loading times…', 'open', 'Full', 'No open clinic days in this range.',
        'No available times on this date. Please choose another day.', 'The clinic is closed on this day.',
        'Previous month', 'Next month', 'Closed', 'Has open times', 'Full or closed', 'Click a date to see the open times.', 'Open times on',
    ];
    $s = [];
    foreach ($keys as $k) {
        $s[$k] = t($k);
    }
    return ['lang' => current_lang(), 'days' => lang_days(true), 'daysLong' => lang_days(), 'months' => lang_months(true), 'monthsLong' => lang_months(), 's' => $s];
}

/** Language switcher dropdown (shown in every header). */
function language_switcher(string $extraClass = ''): string
{
    $cur = current_lang();
    $back = $_SERVER['REQUEST_URI'] ?? url('index.php');
    $html = '<div class="dropdown lang-switch ' . e($extraClass) . '">'
        . '<button class="btn btn-sm btn-light border dropdown-toggle d-inline-flex align-items-center gap-1" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="' . te('Language') . '">'
        . '<i class="bi bi-translate"></i> <span>' . e(LANGUAGES[$cur]['short']) . '</span></button>'
        . '<ul class="dropdown-menu dropdown-menu-end shadow-sm">'
        . '<li><h6 class="dropdown-header">' . te('Language') . '</h6></li>';
    foreach (LANGUAGES as $code => $l) {
        $href = url('lang.php?' . http_build_query(['set' => $code, 'back' => $back]));
        $html .= '<li><a class="dropdown-item d-flex justify-content-between gap-3' . ($code === $cur ? ' active' : '') . '" href="' . e($href) . '" lang="' . e($l['html']) . '">'
            . e($l['name']) . ($code === $cur ? ' <i class="bi bi-check2"></i>' : '') . '</a></li>';
    }
    return $html . '</ul></div>';
}
