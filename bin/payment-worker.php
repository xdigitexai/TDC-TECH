<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/payment_gateway.php';
$lock=fopen('/var/lib/tdc-smm/payment.lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))exit;
$rows=db()->query("SELECT local_reference FROM topup_payments WHERE status IN ('pending','processing') AND provider_reference IS NOT NULL ORDER BY updated_at LIMIT 20")->fetchAll();$checked=0;$errors=0;
foreach($rows as $row){try{pay_refresh($row['local_reference']);$checked++;}catch(Throwable $e){$errors++;}}
echo json_encode(['time'=>gmdate('c'),'checked'=>$checked,'errors'=>$errors]).PHP_EOL;
