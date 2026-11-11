<?php
/** Patient selection fields for staff forms: registered patient or walk-in/guest details. */
$patientsList = db_all("SELECT id, full_name, email, phone FROM users WHERE role = 'patient' AND is_active = 1 ORDER BY full_name");
$pp = $_POST;
$ptype = $pp['ptype'] ?? 'guest';
?>
<div class="mb-2 d-flex gap-3">
  <div class="form-check"><input class="form-check-input" type="radio" name="ptype" id="pt_guest" value="guest" <?= $ptype === 'guest' ? 'checked' : '' ?>><label class="form-check-label" for="pt_guest">Walk-in / new patient</label></div>
  <div class="form-check"><input class="form-check-input" type="radio" name="ptype" id="pt_reg" value="registered" <?= $ptype === 'registered' ? 'checked' : '' ?>><label class="form-check-label" for="pt_reg">Registered patient</label></div>
</div>
<div id="pp_reg" <?= $ptype === 'registered' ? '' : 'hidden' ?>>
  <label class="form-label" for="patient_id">Patient account</label>
  <select class="form-select mb-2" name="patient_id" id="patient_id">
    <option value="">Select a patient…</option>
    <?php foreach ($patientsList as $p): ?><option value="<?= $p['id'] ?>" <?= (int) ($pp['patient_id'] ?? 0) === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['full_name'] . ' — ' . $p['email']) ?></option><?php endforeach; ?>
  </select>
</div>
<div id="pp_guest" <?= $ptype === 'guest' ? '' : 'hidden' ?>>
  <div class="row g-2 mb-2">
    <div class="col-md-12"><label class="form-label" for="contact_name">Full name</label><input class="form-control" id="contact_name" name="contact_name" value="<?= e($pp['contact_name'] ?? '') ?>"></div>
    <div class="col-md-6"><label class="form-label" for="contact_phone">Contact number</label><input class="form-control" id="contact_phone" name="contact_phone" value="<?= e($pp['contact_phone'] ?? '') ?>"></div>
    <div class="col-md-6"><label class="form-label" for="contact_email">Email <span class="text-muted fw-normal">(optional, for notifications)</span></label><input class="form-control" type="email" id="contact_email" name="contact_email" value="<?= e($pp['contact_email'] ?? '') ?>"></div>
  </div>
</div>
<script>
document.querySelectorAll('input[name=ptype]').forEach(function (r) {
  r.addEventListener('change', function () {
    document.getElementById('pp_guest').hidden = !document.getElementById('pt_guest').checked;
    document.getElementById('pp_reg').hidden = !document.getElementById('pt_reg').checked;
  });
});
</script>
