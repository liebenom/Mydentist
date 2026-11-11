<?php
/**
 * Page header. Set before including:
 *   $title  - page title
 *   $layout - 'public' | 'auth' | 'patient' | 'admin'
 *   $active - key of the active sidebar item
 *   $subtitle (optional) - shown under the title on dashboard layouts
 */
$title = $title ?? APP_NAME;
$layout = $layout ?? 'public';
$active = $active ?? '';
$user = current_user();

if ($layout === 'admin') {
    maybe_run_scheduled_tasks();
}

$menus = [
    'patient' => [
        ['dashboard', 'patient/dashboard.php', 'grid-1x2', 'Dashboard'],
        ['schedules', 'patient/schedules.php', 'calendar3', 'View Schedules'],
        ['book', 'patient/book.php', 'calendar-plus', 'Book Appointment'],
        ['appointments', 'patient/appointments.php', 'journal-medical', 'My Appointments'],
        ['settings', 'patient/settings.php', 'gear', 'Settings'],
    ],
    'admin' => [
        ['dashboard', 'admin/dashboard.php', 'grid-1x2', 'Dashboard'],
        ['schedule', 'admin/schedule.php', 'calendar-week', 'Schedule Management'],
        ['appointments', 'admin/appointments.php', 'journal-text', 'Appointment Records'],
        ['book', 'admin/book.php', 'person-plus', 'Walk-in / Phone Booking'],
        ['emergency', 'admin/emergency.php', 'lightning-charge', 'Emergency Priority'],
        ['checkin', 'admin/checkin.php', 'person-check', 'Check-in'],
        ['reports', 'admin/reports.php', 'file-earmark-bar-graph', 'Reports'],
        ['patients', 'admin/patients.php', 'people', 'Patient Management'],
        ['emails', 'admin/emails.php', 'envelope-paper', 'Email Log'],
        ['__admin', null, null, 'Administration'],
        ['services', 'admin/services.php', 'clipboard2-pulse', 'Services & Dentists'],
        ['backup', 'admin/backup.php', 'database-check', 'Backup & Restore'],
        ['audit', 'admin/audit.php', 'shield-check', 'Audit Logs'],
        ['settings', 'admin/settings.php', 'gear', 'Settings'],
    ],
];
$pendingCount = ($layout === 'admin') ? (int) db_val("SELECT COUNT(*) FROM appointments WHERE status = 'pending' AND start_at >= ?", [now_str()]) : 0;
?><!doctype html>
<html lang="<?= e(LANGUAGES[current_lang()]['html']) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#2a759a">
<title><?= e($title) ?> · <?= e(APP_NAME) ?></title>
<link rel="icon" href="<?= url('assets/img/logo.svg') ?>" type="image/svg+xml">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= lib('bootstrap.css') ?>">
<link rel="stylesheet" href="<?= lib('icons.css') ?>">
<link rel="stylesheet" href="<?= url('assets/css/style.css') ?>?v=2">
<script>window.APP = {base: <?= json_encode(BASE_PATH) ?>, csrf: <?= json_encode(csrf_token()) ?>};
window.I18N = <?= json_encode(js_i18n(), JSON_UNESCAPED_UNICODE) ?>;</script>
</head>
<body class="layout-<?= e($layout) ?>">
<?php if ($layout === 'patient' || $layout === 'admin'): ?>
<div class="app-shell">
  <aside class="sidebar sidebar-<?= e($layout) ?>" id="sidebar">
    <a class="sidebar-brand" href="<?= url($layout === 'admin' ? 'admin/dashboard.php' : 'patient/dashboard.php') ?>">
      <img src="<?= url('assets/img/logo-white.svg') ?>" alt="" width="34" height="34">
      <span><strong>MyDentist</strong><small><?= $layout === 'admin' ? te('Admin Portal') : te('Dental Clinic') ?></small></span>
    </a>
    <div class="sidebar-user">
      <?= avatar_html($user) ?>
      <div class="min-w-0">
        <div class="name text-truncate"><?= e($user['full_name']) ?></div>
        <?php if ($user['role'] !== 'patient'): ?><div class="role"><span class="dot"></span><?= te(ucfirst($user['role'])) ?></div><?php endif; ?>
      </div>
    </div>
    <nav class="sidebar-nav">
      <?php foreach ($menus[$layout] as [$key, $href, $icon, $label]): ?>
        <?php if (in_array($key, ['book', 'emergency', 'checkin'], true) && !is_front_desk()) continue;   // STAFF-only items ?>
        <?php if ($key === '__admin'): if (!is_admin()) break; ?>
          <div class="nav-section"><?= te($label) ?></div>
        <?php else: ?>
          <a href="<?= url($href) ?>" class="<?= $active === $key ? 'active' : '' ?>">
            <i class="bi bi-<?= $icon ?>"></i><span><?= te($label) ?></span>
            <?php if ($key === 'appointments' && $pendingCount): ?><span class="badge rounded-pill ms-auto"><?= $pendingCount ?></span><?php endif; ?>
          </a>
        <?php endif; ?>
      <?php endforeach; ?>
    </nav>
    <div class="sidebar-foot">
      <a href="<?= url('index.php') ?>"><i class="bi bi-box-arrow-up-left"></i><span><?= te('Back to Site') ?></span></a>
      <a href="<?= url('logout.php') ?>"><i class="bi bi-box-arrow-right"></i><span><?= te('Sign Out') ?></span></a>
    </div>
  </aside>
  <div class="sidebar-backdrop" data-sidebar-close></div>
  <main class="app-main">
    <header class="topbar">
      <button class="btn btn-icon d-lg-none" type="button" data-sidebar-toggle aria-label="Open menu"><i class="bi bi-list"></i></button>
      <div class="min-w-0">
        <h1 class="page-title text-truncate"><?= e($title) ?></h1>
        <div class="page-sub"><?= e($subtitle ?? ldate('l, F j, Y', time())) ?></div>
      </div>
      <div class="ms-auto d-flex align-items-center gap-2">
        <?= language_switcher() ?>
        <?php if ($layout === 'admin'): ?>
          <a href="<?= url('admin/appointments.php?status=pending') ?>" class="btn btn-icon position-relative" title="<?= te('Pending requests') ?>">
            <i class="bi bi-bell"></i><?php if ($pendingCount): ?><span class="notif-dot"></span><?php endif; ?>
          </a>
          <a href="<?= url('admin/schedule.php') ?>" class="btn btn-primary btn-sm d-none d-md-inline-flex align-items-center gap-1"><i class="bi bi-calendar-week"></i> <?= te('Manage Schedules') ?></a>
          <a href="<?= url('admin/settings.php') ?>" class="d-none d-lg-inline-flex" title="<?= te('My profile') ?>"><?= avatar_html($user, 'sm') ?></a>
        <?php else: ?>
          <a href="<?= url('patient/book.php') ?>" class="btn btn-primary btn-sm d-none d-md-inline-flex align-items-center gap-1"><i class="bi bi-calendar-plus"></i> <?= te('Book') ?></a>
          <a class="user-chip text-reset" href="<?= url('patient/settings.php') ?>" title="<?= te('My profile') ?>"><?= avatar_html($user, 'sm') ?><span class="d-none d-sm-inline"><?= e(explode(' ', $user['full_name'])[0]) ?></span></a>
        <?php endif; ?>
      </div>
    </header>
    <div class="app-content">
      <?= flash_render() ?>
<?php elseif ($layout === 'auth'): ?>
<main class="auth-wrap">
  <a href="<?= url('index.php') ?>" class="btn btn-light btn-sm auth-back"><i class="bi bi-chevron-left"></i> <?= te('Back') ?></a>
  <div class="auth-lang"><?= language_switcher() ?></div>
  <div class="auth-card">
    <div class="text-center mb-3">
      <img src="<?= url('assets/img/logo.svg') ?>" alt="" width="44" height="44">
      <div class="brand-mini">MyDentist Dental Clinic</div>
    </div>
    <?= flash_render() ?>
<?php else: ?>
<nav class="navbar navbar-expand-lg site-nav sticky-top">
  <div class="container">
    <a class="navbar-brand d-flex align-items-center gap-2" href="<?= url('index.php') ?>">
      <img src="<?= url('assets/img/logo.svg') ?>" alt="" width="34" height="34">
      <span class="brand-text"><strong>MyDentist</strong><small>Dental Clinic</small></span>
    </a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#siteNav" aria-label="Menu"><i class="bi bi-list"></i></button>
    <div class="collapse navbar-collapse" id="siteNav">
      <ul class="navbar-nav mx-auto">
        <li class="nav-item"><a class="nav-link <?= $active === 'home' ? 'active' : '' ?>" href="<?= url('index.php') ?>"><?= te('Home') ?></a></li>
        <li class="nav-item"><a class="nav-link" href="<?= url('index.php#services') ?>"><?= te('Services') ?></a></li>
        <li class="nav-item"><a class="nav-link" href="<?= url('index.php#about') ?>"><?= te('About Us') ?></a></li>
        <li class="nav-item"><a class="nav-link" href="<?= url('index.php#contact') ?>"><?= te('Contact') ?></a></li>
      </ul>
      <div class="d-flex gap-2 flex-wrap align-items-center">
        <?= language_switcher() ?>
        <?php if ($user): ?>
          <a href="<?= url(home_for($user)) ?>" class="btn btn-primary btn-sm"><i class="bi bi-grid-1x2"></i> <?= te('My Dashboard') ?></a>
          <a href="<?= url('logout.php') ?>" class="btn btn-outline-primary btn-sm"><?= te('Logout') ?></a>
        <?php else: ?>
          <a href="<?= url('login.php?show=1') ?>" class="btn btn-outline-primary btn-sm"><?= te('Login') ?></a>
          <a href="<?= url('register.php') ?>" class="btn btn-primary btn-sm"><?= te('Register') ?></a>
          <a href="<?= url('login.php?staff=1') ?>" class="btn btn-dark btn-sm"><?= te('Admin') ?></a>
        <?php endif; ?>
      </div>
    </div>
  </div>
</nav>
<div class="container"><?= flash_render() ?></div>
<?php endif; ?>
