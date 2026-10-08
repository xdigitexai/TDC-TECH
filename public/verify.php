<?php
require_once dirname(__DIR__).'/app/auth_codes.php';
if (!email_code_step_enabled()) {
    unset($_SESSION['pending_uid'],$_SESSION['pending_purpose'],$_SESSION['pending_redirect']);
    header('Location: '.(current_user()?'/dashboard':'/login'));
    exit;
}
require __DIR__.'/verify-view.php';
