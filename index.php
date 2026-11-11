<?php
require __DIR__ . '/includes/bootstrap.php';

$title = 'Home';
$layout = 'public';
$active = 'home';
$services = active_services();
$dentists = active_dentists();

// Live preview of the next open day's slots for the hero card
$preview = null;
$checkup = db_one("SELECT * FROM services WHERE is_active = 1 ORDER BY duration_min, id" . sql_limit(1));
if ($checkup) {
    foreach (available_days((int) $checkup['duration_min'], null, null, 6) as $d) {
        if ($d['slots'] > 0) {
            $preview = ['date' => $d['date'], 'slots' => array_slice(available_slots($d['date'], (int) $checkup['duration_min']), 0, 3)];
            break;
        }
    }
}
$stats = [
    'patients' => (int) db_val("SELECT COUNT(*) FROM users WHERE role = 'patient'") + (int) db_val("SELECT COUNT(DISTINCT contact_email) FROM appointments WHERE patient_id IS NULL"),
    'services' => count($services),
    'dentists' => count($dentists),
];
include __DIR__ . '/includes/header.php';
?>
<section class="hero">
  <div class="container">
    <div class="row align-items-center g-5">
      <div class="col-lg-7">
        <span class="pill mb-3"><span class="live"></span> Now accepting online bookings</span>
        <h1 class="mb-3">Your Smile Deserves<br>the <span>Best Care</span></h1>
        <p class="lead mb-4">Skip the long wait. Check open schedules, choose your dental service and book your visit to MyDentist Dental Clinic online, any time of the day.</p>
        <div class="d-flex flex-wrap gap-2 mb-4">
          <a href="<?= url(current_user() && !is_staff() ? 'patient/book.php' : 'guest/book.php') ?>" class="btn btn-primary btn-lg px-4"><i class="bi bi-calendar-plus"></i> Book Appointment</a>
          <?php if (!current_user()): ?><a href="<?= url('register.php') ?>" class="btn btn-outline-primary btn-lg px-4">Create Account</a><?php endif; ?>
        </div>
        <?php if (!current_user()): ?><p class="small text-muted mb-4"><i class="bi bi-info-circle"></i> No account? You can book as a <a href="<?= url('guest/book.php') ?>">guest</a> and receive a QR code for check-in.</p><?php endif; ?>
        <div class="d-flex gap-4 hero-stats">
          <div><div class="num"><?= e(CLINIC_OPEN === '' ? '' : date('ga', strtotime(CLINIC_OPEN))) ?>–<?= e(date('ga', strtotime(CLINIC_CLOSE))) ?></div><div class="lbl"><?= e(clinic_days_label()) ?></div></div>
          <div><div class="num"><?= $stats['services'] ?></div><div class="lbl">Dental services</div></div>
          <div><div class="num"><?= $stats['dentists'] ?></div><div class="lbl">Dentists</div></div>
        </div>
      </div>
      <div class="col-lg-5">
        <div class="hero-card">
          <div class="d-flex align-items-center gap-2 mb-3">
            <div class="stat-icon tint-teal" style="width:38px;height:38px;border-radius:10px;display:grid;place-items:center"><i class="bi bi-calendar-check"></i></div>
            <div><div class="fw-bold">Next open schedule</div><div class="small text-muted"><?= $preview ? e(fmt_date($preview['date'], 'l, F j')) : 'Check back soon' ?></div></div>
          </div>
          <?php if ($preview && $preview['slots']): ?>
            <?php foreach ($preview['slots'] as $s): ?>
              <div class="slot-row"><span><i class="bi bi-clock me-1"></i><?= e(date('g:i A', strtotime($s['time']))) ?></span><span class="small"><?= e($checkup['name']) ?></span><span class="status-badge status-confirmed">Open</span></div>
            <?php endforeach; ?>
          <?php else: ?>
            <div class="slot-row taken"><span>No open slots in the next days</span></div>
          <?php endif; ?>
          <a href="<?= url(current_user() && !is_staff() ? 'patient/book.php' : 'guest/book.php') ?>" class="btn btn-primary w-100 mt-2">Book a Slot</a>
        </div>
      </div>
    </div>
  </div>
</section>

<section class="section" id="services">
  <div class="container">
    <div class="text-center mb-4">
      <div class="small-caps">What we offer</div>
      <h2 class="section-title">Dental Services</h2>
      <p class="text-muted">From routine check-ups to advanced procedures. Each service books the right amount of time.</p>
    </div>
    <div class="row g-3">
      <?php foreach ($services as $s): ?>
        <div class="col-sm-6 col-lg-4 col-xl-3">
          <div class="service-card">
            <div class="icon"><i class="bi <?= e($s['icon']) ?>"></i></div>
            <div class="d-flex justify-content-between align-items-start gap-2">
              <h3 class="h6 fw-bold mb-1"><?= e($s['name']) ?></h3>
              <span class="badge rounded-pill <?= $s['category'] === 'routine' ? 'text-bg-light' : 'tint-violet' ?>"><?= $s['category'] === 'routine' ? 'Routine' : 'Advanced' ?></span>
            </div>
            <p class="small text-muted mb-2"><?= e($s['description']) ?></p>
            <div class="d-flex justify-content-between small"><span><i class="bi bi-clock"></i> <?= (int) $s['duration_min'] ?> min</span><strong class="text-primary">from <?= peso($s['price']) ?></strong></div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="section bg-white" id="about">
  <div class="container">
    <div class="row g-5 align-items-center">
      <div class="col-lg-5">
        <div class="small-caps">How it works</div>
        <h2 class="section-title mb-3">Book in four easy steps</h2>
        <p class="text-muted">MyDentist Dental Clinic in Maramag, Bukidnon now takes appointments online so you can secure your preferred time before you leave home, with fewer phone calls and shorter waiting time at the clinic.</p>
        <ul class="list-unstyled text-muted small mb-0">
          <li class="mb-1"><i class="bi bi-check2-circle text-primary me-1"></i> Email confirmation and reminders 24, 12 and 6 hours before</li>
          <li class="mb-1"><i class="bi bi-check2-circle text-primary me-1"></i> Reschedule or cancel online</li>
          <li><i class="bi bi-check2-circle text-primary me-1"></i> Guests get a QR code for fast check-in at the front desk</li>
        </ul>
      </div>
      <div class="col-lg-7">
        <div class="row g-3">
          <?php foreach ([
              ['Choose a service', 'Pick from check-ups, cleaning, fillings, extractions and more.'],
              ['Pick a dentist', 'Choose your dentist or let us assign whoever is available.'],
              ['Select date & time', 'Only open slots that fit your service are shown.'],
              ['Get confirmed', 'Receive your email confirmation and reference number.'],
          ] as $i => [$t, $d]): ?>
            <div class="col-sm-6">
              <div class="service-card d-flex gap-3">
                <div class="step-num flex-shrink-0"><?= $i + 1 ?></div>
                <div><div class="fw-bold mb-1"><?= e($t) ?></div><div class="small text-muted"><?= e($d) ?></div></div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>
</section>

<section class="section">
  <div class="container">
    <div class="text-center mb-4"><div class="small-caps">Our team</div><h2 class="section-title">Meet Our Dentists</h2></div>
    <div class="row g-3 justify-content-center">
      <?php foreach ($dentists as $d): ?>
        <div class="col-sm-6 col-lg-4">
          <div class="service-card d-flex align-items-center gap-3">
            <div class="avatar" style="width:52px;height:52px;font-size:1rem"><?= e(initials($d['name'])) ?></div>
            <div><div class="fw-bold"><?= e($d['name']) ?></div><div class="small text-muted"><?= e($d['specialty']) ?></div></div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php include __DIR__ . '/includes/footer.php'; ?>
