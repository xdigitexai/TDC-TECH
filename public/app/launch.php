<?php
require dirname(__DIR__,2).'/app/bootstrap.php';
header('Cache-Control: no-store, private');
if(!empty($_COOKIE['tdc_device'])){header('Location: /app/unlock.php');exit;}
header('Location: '.(current_user()?'/profile?setup-passcode=1':'/login?app=1'));exit;
