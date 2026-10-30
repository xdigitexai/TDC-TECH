<?php
require dirname(__DIR__, 2) . '/app/bootstrap.php';
$u = current_user();
if (!empty($_SESSION['impersonator_uid'])) {
    $_SESSION['uid'] = (int) $_SESSION['impersonator_uid'];
    unset($_SESSION['impersonator_uid']);
    header('Location: /admin/');
    exit;
}
header('Location: ' . ($u ? '/dashboard.php' : '/login'));
exit;
