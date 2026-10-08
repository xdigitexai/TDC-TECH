<?php
require dirname(__DIR__,3).'/app/payment_gateway.php';require_user();require_method('GET');
$rates=db()->query('SELECT currency,units_per_eur FROM gateway_fx_rates WHERE active=1 AND units_per_eur>0 AND updated_at>=UTC_TIMESTAMP()-INTERVAL 3 DAY ORDER BY currency')->fetchAll();
json_out(['configured'=>pay_config('TDC_PAY_API_KEY')!==''&&pay_config('TDC_PAY_WEBHOOK_SECRET')!=='','rates'=>$rates,'minimum_eur'=>5,'maximum_eur'=>10000]);
