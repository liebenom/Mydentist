<?php
/** Switch the display language: lang.php?set=en|fil|ceb&back=/return/url */
require __DIR__ . '/includes/bootstrap.php';

set_lang((string) ($_GET['set'] ?? 'en'));

$back = (string) ($_GET['back'] ?? '');
// only return to a page inside this site
if ($back === '' || !str_starts_with($back, BASE_PATH . '/') || str_contains($back, '//') || str_contains($back, "\n")) {
    $back = url('index.php');
}
header('Location: ' . $back);
exit;
