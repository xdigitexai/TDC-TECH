<?php
require dirname(__DIR__,2).'/app/bootstrap.php';
$u=current_user();if(!$u){header('Location: '.(!empty($_COOKIE['tdc_device'])?'/app/unlock.php':'/login'));exit;}if($u['role']==='admin'){header('Location: /admin/');exit;}
$slug=basename($_SERVER['SCRIPT_NAME'],'.php');
$allowed=['social-ads','music','verification','web-dev','account-mgmt','press','smm','numbers','bots','proxies'];
if(!in_array($slug,$allowed,true)){http_response_code(404);exit('Service page not found.');}$q=db()->prepare('SELECT active FROM service_pages WHERE slug=?');$q->execute([$slug]);if(!$q->fetchColumn()){http_response_code(404);exit('This service is currently unavailable.');}
$tpl=file_get_contents(dirname(__DIR__,2).'/app/templates/customer-dashboard.html');
$tpl=str_replace('<body>','<body data-page="service" data-service="'.htmlspecialchars($slug,ENT_QUOTES).'">',$tpl);
header('Content-Type: text/html; charset=utf-8'); header('Cache-Control: no-store, private');
echo $tpl;
