<?php
require dirname(__DIR__,2).'/app/bootstrap.php';
require_user();require_method('GET');
$rows=db()->query('SELECT s.id,s.slug,s.name,s.price,s.pricing_mode,m.category,m.service_type,m.min_quantity,m.max_quantity,m.refill,m.cancel FROM services s JOIN smm_services m ON m.service_id=s.id JOIN smm_providers p ON p.id=m.provider_id WHERE s.active=1 AND p.active=1 ORDER BY m.category,s.name')->fetchAll();
json_out(['services'=>$rows,'currency'=>'EUR']);
