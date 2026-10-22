<?php
require_once dirname(__DIR__,2).'/app/bootstrap.php';
require_method('GET');
$q=db()->query('SELECT slug,name,price,pricing_mode FROM services WHERE active=1 ORDER BY id');
json_out(['services'=>$q->fetchAll()]);
