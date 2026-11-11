<?php
require __DIR__ . '/includes/bootstrap.php';

if ($u = current_user()) {
    if (is_staff($u)) {
        audit('logout', 'Staff logout: ' . $u['username']);
    }
}
logout_user();
session_start();
flash('success', 'You have been logged out.');
redirect('login.php?show=1');
