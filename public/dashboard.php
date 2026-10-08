<?php
require dirname(__DIR__).'/app/bootstrap.php';
$user=current_user();if(!$user){header('Location: '.(!empty($_COOKIE['tdc_device'])?'/app/unlock.php':'/login'));exit;}if($user['role']==='admin'){header('Location: /admin/');exit;}
$tpl=file_get_contents(dirname(__DIR__).'/app/templates/customer-dashboard.html');
$tpl=str_replace('<body>','<body data-page="dashboard" data-service="home">',$tpl);
header('Content-Type: text/html; charset=utf-8'); header('Cache-Control: no-store, private');
echo $tpl;
