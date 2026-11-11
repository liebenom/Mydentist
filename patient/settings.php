<?php
require __DIR__ . '/../includes/bootstrap.php';
$user = require_patient();
require __DIR__ . '/../includes/account_settings.php';

$title = t('Settings');
$layout = 'patient';
$active = 'settings';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/account_settings_form.php';
include __DIR__ . '/../includes/footer.php';
