<?php
/** Handles profile and password updates for the logged-in user ($user). */
$settingsErrors = [];
if (is_post() && in_array(input('form'), ['profile', 'password', 'avatar'], true)) {
    csrf_check();
    if (input('form') === 'avatar') {
        // Ownership: always the logged-in user's own id; any user id sent by the browser is ignored
        if (input('avatar_action') === 'remove') {
            avatar_remove((int) $user['id']);
            flash('success', te('Profile picture removed.'));
        } elseif ($err = avatar_save_upload((int) $user['id'], $_FILES['avatar'] ?? [])) {
            flash('danger', e($err));
        } else {
            flash('success', te('Profile picture updated.'));
        }
        if ($user['role'] !== 'patient') {
            audit('profile_picture_changed', 'Updated own profile picture');
        }
        header('Location: ' . $_SERVER['REQUEST_URI']);
        exit;
    }
    if (input('form') === 'profile') {
        $name = input('full_name');
        $email = strtolower(input('email'));
        $phone = input('phone');
        if (mb_strlen($name) < 3) $settingsErrors[] = 'Please enter your full name.';
        if (!valid_email($email)) $settingsErrors[] = 'Please enter a valid email address.';
        elseif (db_val('SELECT COUNT(*) FROM users WHERE email = ? AND id <> ?', [$email, $user['id']])) $settingsErrors[] = 'That email is used by another account.';
        if ($phone !== '' && !valid_phone($phone)) $settingsErrors[] = 'Please enter a valid contact number.';
        if (!$settingsErrors) {
            db_update('users', ['full_name' => $name, 'email' => $email, 'phone' => $phone], 'id = :id', ['id' => $user['id']]);
            if ($user['role'] === 'patient') {
                // keep contact details on upcoming appointments in sync
                q("UPDATE appointments SET contact_name = ?, contact_email = ?, contact_phone = ? WHERE patient_id = ? AND status IN ('pending','confirmed')", [$name, $email, $phone, $user['id']]);
            } else {
                audit('profile_updated', 'Updated own profile');
            }
            flash('success', te('Your profile has been updated.'));
            header('Location: ' . $_SERVER['REQUEST_URI']);
            exit;
        }
    } else {
        $hash = db_val('SELECT password_hash FROM users WHERE id = ?', [$user['id']]);
        $new = (string) ($_POST['new_password'] ?? '');
        if (!password_verify((string) ($_POST['current_password'] ?? ''), $hash)) $settingsErrors[] = t('Your current password is incorrect.');
        elseif ($p = password_problem($new)) $settingsErrors[] = $p;
        elseif ($new !== ($_POST['confirm_password'] ?? '')) $settingsErrors[] = t('Passwords do not match.');
        if (!$settingsErrors) {
            q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $user['id']]);
            if ($user['role'] !== 'patient') {
                audit('password_changed', 'Changed own password');
            }
            session_regenerate_id(true);
            flash('success', te('Your password has been changed.'));
            header('Location: ' . $_SERVER['REQUEST_URI']);
            exit;
        }
    }
}
