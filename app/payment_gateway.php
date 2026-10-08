<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';

function pay_config(string $name): string {
    $value = defined($name) ? constant($name) : envv($name);
    return trim((string)$value);
}
function pay_api(string $method, string $path, ?array $body=null): array {
    $key=pay_config('TDC_PAY_API_KEY');
    if($key==='') throw new RuntimeException('Online payments are not configured yet.');
    if(!function_exists('curl_init')) throw new RuntimeException('Payments are temporarily unavailable.');
    $ch=curl_init('https://pay.xdigitex.space/api'.$path);
    $headers=['X-API-Key: '.$key,'Accept: application/json'];
    if($body!==null)$headers[]='Content-Type: application/json';
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$headers,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>25,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2]);
    if($body!==null)curl_setopt($ch,CURLOPT_POSTFIELDS,json_encode($body,JSON_UNESCAPED_SLASHES));
    $raw=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$err=curl_error($ch);curl_close($ch);
    if($raw===false||$status<200||$status>=300) { error_log('Xdigitex Pay request failed; HTTP '.$status.' '.$err); throw new RuntimeException('Payment provider could not process the request. Please try again.'); }
    $data=json_decode((string)$raw,true);
    if(!is_array($data))throw new RuntimeException('Payment provider returned an invalid response.');
    return $data;
}
function pay_currency_rate(string $currency): ?float {
    $q=db()->prepare('SELECT units_per_eur FROM gateway_fx_rates WHERE currency=? AND active=1 AND updated_at>=UTC_TIMESTAMP()-INTERVAL 3 DAY');$q->execute([$currency]);$rate=$q->fetchColumn();
    return $rate!==false && (float)$rate>0 ? (float)$rate : null;
}
function pay_currency_from_phone(string $phone): ?string {
    $prefixes=['+229'=>'XOF','+226'=>'XOF','+237'=>'XAF','+225'=>'XOF','+243'=>'CDF','+241'=>'XAF','+254'=>'KES','+242'=>'XAF','+250'=>'RWF','+221'=>'XOF','+232'=>'SLE','+256'=>'UGX','+260'=>'ZMW'];
    foreach($prefixes as $prefix=>$currency)if(str_starts_with($phone,$prefix))return $currency;
    return null;
}
function pay_sync(string $localRef, array $providerData): array {
    $pdo=db();$q=$pdo->prepare('SELECT * FROM topup_payments WHERE local_reference=?');$q->execute([$localRef]);$payment=$q->fetch();
    if(!$payment)throw new RuntimeException('Payment was not found.');
    if(!in_array($payment['status'],['completed','failed'],true)) {
        $providerRef=(string)($payment['provider_reference']??'');
        if($providerRef===''||!hash_equals($providerRef,(string)($providerData['reference']??'')))throw new RuntimeException('Payment reference verification failed.');
        if(!isset($providerData['currency'],$providerData['amount']))throw new RuntimeException('Incomplete payment verification response.');
        if(strtoupper((string)$providerData['currency'])!==$payment['currency'])throw new RuntimeException('Payment currency verification failed.');
        if(isset($providerData['amount'])&&abs((float)$providerData['amount']-(float)$payment['provider_amount'])>0.02)throw new RuntimeException('Payment amount verification failed.');
        $status=strtolower((string)($providerData['status']??''));
        if(in_array($status,['completed','failed'],true)) {
            $credited=false;$pdo->beginTransaction();
            try {
                $lock=$pdo->prepare('SELECT status,ledger_id FROM topup_payments WHERE id=? FOR UPDATE');$lock->execute([$payment['id']]);$locked=$lock->fetch();
                if(!in_array($locked['status'],['completed','failed'],true)) {
                    $next=$status==='completed'?'completed':'failed';$credited=$next==='completed';
                    $pdo->prepare('UPDATE topup_payments SET status=?,updated_at=UTC_TIMESTAMP() WHERE id=?')->execute([$next,$payment['id']]);
                    $pdo->prepare('UPDATE wallet_ledger SET status=? WHERE id=? AND status=\'pending\'')->execute([$next==='completed'?'posted':'reversed',$locked['ledger_id']]);
                }
                $pdo->commit();
                if($credited){
                    audit('wallet.gateway_payment_completed',(int)$payment['user_id'],['reference'=>$localRef,'provider'=>'xdigitex']);
                    try{$u=$pdo->prepare('SELECT email,name FROM users WHERE id=?');$u->execute([$payment['user_id']]);$customer=$u->fetch();if($customer){require_once __DIR__.'/notifications.php';queue_email($customer['email'],'Wallet top-up received · TDC Tech','Hello '.$customer['name'].",\n\nYour payment is confirmed. €".number_format((float)$payment['requested_gbp'],2).' has been added to your TDC Tech wallet.',(int)$payment['user_id']);}}catch(Throwable $e){error_log('Top-up notification could not be queued: '.$e->getMessage());}
                }
            }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
        } else {
            $normalized=in_array($status,['pending','processing'],true)?$status:'pending';
            $pdo->prepare('UPDATE topup_payments SET status=?,updated_at=UTC_TIMESTAMP() WHERE id=?')->execute([$normalized,$payment['id']]);
        }
    }
    $q=$pdo->prepare('SELECT status FROM topup_payments WHERE id=?');$q->execute([$payment['id']]);$payment['status']=$q->fetchColumn();
    return $payment;
}
function pay_refresh(string $localRef): array {
    $q=db()->prepare('SELECT provider_reference,status FROM topup_payments WHERE local_reference=?');$q->execute([$localRef]);$p=$q->fetch();
    if(!$p)throw new RuntimeException('Payment was not found.');
    if(!in_array($p['status'],['completed','failed'],true)) {
        if(!$p['provider_reference'])return $p;
        $data=pay_api('GET','/payments/'.rawurlencode((string)$p['provider_reference']).'/status');
        return pay_sync($localRef,$data);
    }
    return $p;
}
