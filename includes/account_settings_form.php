<?php $me = db_one('SELECT * FROM users WHERE id = ?', [$user['id']]); ?>
<?php if (!empty($settingsErrors)): ?><div class="alert alert-danger"><?= implode('<br>', array_map('e', $settingsErrors)) ?></div><?php endif; ?>
<form method="post" enctype="multipart/form-data" class="card-md mb-3" id="avatarForm">
  <div class="card-head"><h2><i class="bi bi-person-circle text-primary"></i> <?= te('Profile Picture') ?></h2></div>
  <div class="card-body-md d-flex flex-wrap align-items-center gap-4">
    <?= csrf_field() ?><input type="hidden" name="form" value="avatar"><input type="hidden" name="avatar_action" value="upload" id="avatarAction">
    <div class="text-center">
      <img id="avatarPreview" class="avatar-preview" alt="<?= te('Preview') ?>" hidden>
      <div id="avatarCurrent"><?= avatar_html($me, 'avatar-lg') ?></div>
    </div>
    <div class="flex-grow-1" style="min-width:240px">
      <div class="fw-bold mb-1"><?= e($me['full_name']) ?></div>
      <div class="small text-muted mb-2"><?= te('JPG, JPEG, PNG or WEBP, up to 2 MB.') ?></div>
      <input class="form-control form-control-sm mb-2" type="file" name="avatar" id="avatarInput" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" aria-label="<?= te('Choose a profile picture') ?>">
      <div class="small text-danger mb-2" id="avatarError" hidden></div>
      <div class="d-flex flex-wrap gap-2">
        <button class="btn btn-sm btn-primary" id="avatarSave" disabled><i class="bi bi-upload"></i> <?= empty($me['avatar']) ? te('Upload Profile Picture') : te('Change Profile Picture') ?></button>
        <?php if (!empty($me['avatar'])): ?>
          <button class="btn btn-sm btn-outline-danger" type="submit" data-confirm="<?= te('Remove your profile picture?') ?>" onclick="document.getElementById('avatarAction').value='remove'"><i class="bi bi-trash"></i> <?= te('Remove') ?></button>
        <?php endif; ?>
      </div>
    </div>
  </div>
</form>
<div class="row g-3">
  <div class="col-lg-6">
    <form method="post" class="card-md h-100">
      <div class="card-head"><h2><i class="bi bi-person-vcard text-primary"></i> <?= te('Profile') ?></h2></div>
      <div class="card-body-md">
        <?= csrf_field() ?><input type="hidden" name="form" value="profile">
        <div class="mb-2"><label class="form-label"><?= te('Username') ?></label><input class="form-control" value="<?= e($me['username']) ?>" disabled></div>
        <div class="mb-2"><label class="form-label" for="full_name"><?= te('Full name') ?></label><input class="form-control" id="full_name" name="full_name" value="<?= e($me['full_name']) ?>" required></div>
        <div class="mb-2"><label class="form-label" for="email"><?= te('Email address') ?></label><input class="form-control" type="email" id="email" name="email" value="<?= e($me['email']) ?>" required></div>
        <div class="mb-3"><label class="form-label" for="phone"><?= te('Contact number') ?></label><input class="form-control" id="phone" name="phone" value="<?= e($me['phone']) ?>"></div>
        <button class="btn btn-primary"><?= te('Save Profile') ?></button>
      </div>
    </form>
  </div>
  <div class="col-lg-6">
    <form method="post" class="card-md h-100 needs-validation" novalidate>
      <div class="card-head"><h2><i class="bi bi-shield-lock text-primary"></i> <?= te('Change Password') ?></h2></div>
      <div class="card-body-md">
        <?= csrf_field() ?><input type="hidden" name="form" value="password">
        <div class="mb-2"><label class="form-label" for="cp"><?= te('Current password') ?></label><div class="input-icon"><i class="bi bi-key"></i><input class="form-control" type="password" id="cp" name="current_password" required readonly data-noautofill autocomplete="new-password"><button type="button" class="toggle-pass" aria-label="<?= te('Show or hide password') ?>"><i class="bi bi-eye"></i></button></div></div>
        <div class="mb-2"><label class="form-label" for="np"><?= te('New password') ?></label><div class="input-icon"><i class="bi bi-lock"></i><input class="form-control" type="password" id="np" name="new_password" required readonly data-noautofill autocomplete="new-password" data-password><button type="button" class="toggle-pass" aria-label="<?= te('Show or hide password') ?>"><i class="bi bi-eye"></i></button></div>
          <?= password_rules_html() ?></div>
        <div class="mb-3"><label class="form-label" for="np2"><?= te('Confirm new password') ?></label><div class="input-icon"><i class="bi bi-lock-fill"></i><input class="form-control" type="password" id="np2" name="confirm_password" required readonly data-noautofill autocomplete="new-password" data-password-confirm><button type="button" class="toggle-pass" aria-label="<?= te('Show or hide password') ?>"><i class="bi bi-eye"></i></button></div>
          <div class="pw-match small mt-1" data-pw-match data-ok="<?= te('Passwords match.') ?>" data-bad="<?= te('Passwords do not match.') ?>" aria-live="polite"></div></div>
        <p class="small text-muted"><?= te('Passwords are stored as secure bcrypt hashes, never as plain text.') ?></p>
        <button class="btn btn-primary"><?= te('Update Password') ?></button>
      </div>
    </form>
  </div>
</div>
<script>
(function () {
  var input = document.getElementById('avatarInput'), prev = document.getElementById('avatarPreview'),
      cur = document.getElementById('avatarCurrent'), save = document.getElementById('avatarSave'), err = document.getElementById('avatarError');
  var MSG = <?= json_encode(['type' => t('Unsupported file type. Please upload a JPG, JPEG, PNG or WEBP image.'), 'size' => t('The image is too large. Maximum size is 2 MB.')], JSON_UNESCAPED_UNICODE) ?>;
  input.addEventListener('change', function () {
    var f = input.files[0]; err.hidden = true; save.disabled = true;
    if (!f) { prev.hidden = true; cur.hidden = false; return; }
    if (!/^image\/(jpeg|png|webp)$/.test(f.type) || !/\.(jpe?g|png|webp)$/i.test(f.name)) { err.textContent = MSG.type; err.hidden = false; input.value = ''; return; }
    if (f.size > 2 * 1024 * 1024) { err.textContent = MSG.size; err.hidden = false; input.value = ''; return; }
    var r = new FileReader();
    r.onload = function (e) { prev.src = e.target.result; prev.hidden = false; cur.hidden = true; save.disabled = false; };
    r.readAsDataURL(f);
  });
})();
</script>
