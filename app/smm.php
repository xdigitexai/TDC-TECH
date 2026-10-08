<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';

// Resolve and pin a public HTTPS address. Redirects are deliberately disabled.
function smm_endpoint(string $url): array {
    $p=parse_url($url);
    if(!$p || ($p['scheme']??'')!=='https' || empty($p['host']) || isset($p['user']) || isset($p['pass']) || isset($p['fragment']) || (isset($p['port']) && $p['port']!==443)) throw new InvalidArgumentException('Enter a public HTTPS API endpoint.');
    $host=$p['host'];
    if(!preg_match('/^[a-z0-9.-]+$/i',$host)) throw new InvalidArgumentException('Invalid API hostname.');
    $ips=gethostbynamel($host)?:[];
    if(!$ips) throw new InvalidArgumentException('API hostname could not be resolved.');
    foreach($ips as $ip) if(!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)) throw new InvalidArgumentException('API must use a public address.');
    return [$host,$ips[0]];
}
function smm_api(array $provider,string $action,array $params=[]): array {
    [$host,$ip]=smm_endpoint($provider['api_url']);
    $ch=curl_init($provider['api_url']);
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,
        CURLOPT_POSTFIELDS=>http_build_query(['key'=>decrypt_private_note($provider['encrypted_key']),'action'=>$action]+$params),
        CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>45,CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
        CURLOPT_RESOLVE=>[$host.':443:'.$ip],CURLOPT_HTTPHEADER=>['Accept: application/json'],
        CURLOPT_WRITEFUNCTION=>static function($ch,$chunk) use (&$body){$body=($body??'').$chunk;return strlen($body)>8*1024*1024?0:strlen($chunk);}]);
    $body='';$ok=curl_exec($ch);$status=curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);
    if($ok===false || $status!==200) throw new RuntimeException('Provider response unavailable.');
    $data=json_decode($body,true);
    if(!is_array($data)) throw new RuntimeException('Provider returned an invalid response.');
    // Provider error text can contain credentials or internal details; never expose it.
    return $data;
}
function smm_provider(int $id): array {
    $q=db()->prepare('SELECT * FROM smm_providers WHERE id=?');$q->execute([$id]);
    $p=$q->fetch();if(!$p)throw new InvalidArgumentException('Provider not found.');return $p;
}
function smm_price(float $rate,float $fx,float $profit): float {
    if(!is_finite($rate)||$rate<=0||!is_finite($fx)||$fx<=0||!is_finite($profit)||$profit<0||$profit>10000)throw new InvalidArgumentException('Invalid rate, conversion or profit.');
    return ceil(($rate/$fx)*(1+$profit/100)*100)/100;
}
function smm_sync(int $id): array {
    $p=smm_provider($id);if(!$p['active'])throw new InvalidArgumentException('Enable the provider before syncing.');
    $balance=smm_api($p,'balance');
    if(isset($balance['error'])||!preg_match('/^[A-Z]{3}$/',(string)($balance['currency']??'')))throw new InvalidArgumentException('Provider authentication or currency check failed.');
    if($balance['currency']!==$p['currency'])throw new InvalidArgumentException('Provider currency differs from the configured conversion.');
    $rows=smm_api($p,'services');
    if(isset($rows['error'])||!array_is_list($rows)||!$rows)throw new InvalidArgumentException('No valid provider catalog was returned.');
    $valid=[];$unsupported=0;$invalid=0;
    foreach($rows as $r){
        if(!is_array($r)||!preg_match('/^[a-zA-Z0-9_-]{1,60}$/',(string)($r['service']??''))||empty($r['name'])){$invalid++;continue;}
        if(!in_array($r['type']??'',['Default','Package','Custom Comments','Custom Comments Package'],true)){$unsupported++;continue;}
        $min=filter_var($r['min']??null,FILTER_VALIDATE_INT);$max=filter_var($r['max']??null,FILTER_VALIDATE_INT);
        if(!$min||$min<1||!$max||$max<$min||$max>100000000||!is_numeric($r['rate']??null)||(float)$r['rate']<=0){$invalid++;continue;}
        $r['price']=smm_price((float)$r['rate'],(float)$p['units_per_gbp'],(float)$p['profit_percent']);
        if($r['price']>100000){$invalid++;continue;}$valid[(string)$r['service']]=$r;
    }
    if(!$valid)throw new InvalidArgumentException('No supported order types in this catalog.');
    $db=db();$db->beginTransaction();
    try{
        $db->prepare('SELECT id FROM smm_providers WHERE id=? FOR UPDATE')->execute([$id]);
        $db->prepare('UPDATE services s JOIN smm_services m ON m.service_id=s.id SET s.active=0 WHERE m.provider_id=?')->execute([$id]);
        foreach($valid as $remote=>$r){
            $slug='smm-p'.$id.'-'.$remote;$mode=str_contains($r['type'],'Package')?'fixed':'per_1000';
            $db->prepare("INSERT INTO services(slug,name,price,pricing_mode,active,dashboard_icon) VALUES(?,?,?,?,1,'❤️') ON DUPLICATE KEY UPDATE name=VALUES(name),price=VALUES(price),pricing_mode=VALUES(pricing_mode),active=1")->execute([$slug,text_cut($r['name'],0,160),$r['price'],$mode]);
            $q=$db->prepare('SELECT id FROM services WHERE slug=?');$q->execute([$slug]);$sid=$q->fetchColumn();
            $db->prepare('INSERT INTO smm_services(service_id,provider_id,remote_id,category,service_type,provider_rate,min_quantity,max_quantity,refill,cancel) VALUES(?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE category=VALUES(category),service_type=VALUES(service_type),provider_rate=VALUES(provider_rate),min_quantity=VALUES(min_quantity),max_quantity=VALUES(max_quantity),refill=VALUES(refill),cancel=VALUES(cancel)')->execute([$sid,$id,$remote,text_cut((string)($r['category']??'Other'),0,255),$r['type'],$r['rate'],$r['min'],$r['max'],!empty($r['refill'])?1:0,!empty($r['cancel'])?1:0]);
            $db->prepare("INSERT IGNORE INTO service_page_services(page_slug,service_id) VALUES('smm',?)")->execute([$sid]);
        }
        // Disable the old sample catalog, without deleting its order history.
        $db->exec("UPDATE services SET active=0 WHERE slug IN ('smm-followers','smm-likes','smm-views','smm-comments')");
        $db->prepare('UPDATE smm_providers SET last_synced_at=UTC_TIMESTAMP() WHERE id=?')->execute([$id]);
        $db->commit();return ['imported'=>count($valid),'unsupported'=> $unsupported,'invalid'=>$invalid,'currency'=>$p['currency']];
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
function smm_validate(array $s,array $details): array {
    $link=trim((string)($details['link']??''));$url=parse_url($link);
    if(strlen($link)>1500||!$url||!in_array($url['scheme']??'',['http','https'],true)||empty($url['host'])||isset($url['user'])||isset($url['pass']))throw new InvalidArgumentException('Enter a valid public profile or content URL.');
    $qty=filter_var($details['quantity']??null,FILTER_VALIDATE_INT);
    $payload=['service'=>$s['remote_id'],'link'=>$link];
    if(str_contains($s['service_type'],'Comments')){
        $comments=trim((string)($details['comments']??''));
        if(strlen($comments)>4500||!$comments)throw new InvalidArgumentException('Enter one comment per line.');
        $lines=preg_split('/\r?\n/',$comments);$lines=array_filter(array_map('trim',$lines),fn($x)=>$x!=='');
        $qty=count($lines);$payload['comments']=implode("\n",$lines);
    }
    if(str_contains($s['service_type'],'Package'))$qty=(int)$s['min_quantity'];
    if(!$qty||$qty<(int)$s['min_quantity']||$qty>(int)$s['max_quantity'])throw new InvalidArgumentException('Quantity must be between '.$s['min_quantity'].' and '.$s['max_quantity'].'.');
    if(!str_contains($s['service_type'],'Comments')&&!str_contains($s['service_type'],'Package'))$payload['quantity']=$qty;
    $price=(float)$s['price'];$amount=$s['pricing_mode']==='fixed'?$price:ceil(($qty/1000)*$price*100-0.0000001)/100;
    if($amount<=0||$amount>100000)throw new InvalidArgumentException('Invalid order amount.');
    return [$payload,$qty,$amount];
}
function smm_submit_order(array $u,array $s,array $d): array {
    [$payload,$qty,$amount]=smm_validate($s,$d['details']??[]);
    if(($d['action']??'')==='quote')return ['amount'=>$amount,'currency'=>'EUR'];
    $request=(string)($d['request_id']??'');if(!preg_match('/^[a-zA-Z0-9_-]{16,80}$/',$request))throw new InvalidArgumentException('Refresh the order form and try again.');
    $hash=hash('sha256',json_encode([$s['id'],$payload]));$db=db();$db->beginTransaction();
    try{
        $db->prepare('SELECT id FROM users WHERE id=? FOR UPDATE')->execute([$u['id']]);
        $q=$db->prepare('SELECT order_id,request_hash FROM smm_jobs WHERE user_id=? AND request_id=?');$q->execute([$u['id'],$request]);$old=$q->fetch();
        if($old){if($old['request_hash']!==$hash)throw new InvalidArgumentException('This request ID belongs to a different order.');$db->commit();return ['ok'=>true,'order_id'=>$old['order_id'],'duplicate'=>true];}
        $q=$db->prepare('SELECT s.*,m.*,p.active provider_active FROM services s JOIN smm_services m ON m.service_id=s.id JOIN smm_providers p ON p.id=m.provider_id WHERE s.id=? FOR UPDATE');$q->execute([$s['id']]);$now=$q->fetch();
        if(!$now||!$now['active']||!$now['provider_active']||(float)$now['price']!==(float)$s['price'])throw new InvalidArgumentException('The service changed. Refresh your quote.');
        [$payload,$qty,$amount]=smm_validate($now,$d['details']??[]);
        $q=$db->prepare("SELECT COALESCE(SUM(amount),0) FROM wallet_ledger WHERE user_id=? AND status='posted'");$q->execute([$u['id']]);
        if((float)$q->fetchColumn()<$amount)throw new InvalidArgumentException('Insufficient wallet balance. Add funds before ordering.');
        $details=['link'=>$payload['link'],'quantity'=>$qty];if(isset($payload['comments']))$details['comments']=$payload['comments'];
        $db->prepare("INSERT INTO orders(user_id,service_id,description,details,amount,status) VALUES(?,?,?,?,?,'pending')")->execute([$u['id'],$s['id'],$s['name'],json_encode($details),$amount]);$oid=(int)$db->lastInsertId();
        $db->prepare("INSERT INTO wallet_ledger(user_id,amount,kind,status,reference,note) VALUES(?,?,'debit','posted',?,?)")->execute([$u['id'],-$amount,'order:'.$oid,$s['name']]);
        $db->prepare('INSERT INTO smm_jobs(order_id,user_id,request_id,request_hash,provider_id,remote_service,payload) VALUES(?,?,?,?,?,?,?)')->execute([$oid,$u['id'],$request,$hash,$s['provider_id'],$s['remote_id'],json_encode($payload)]);
        $db->commit();audit('smm.order.queued',(int)$u['id'],['order_id'=>$oid]);
        try{require_once __DIR__.'/notifications.php';queue_email($u['email'],'Order #'.$oid.' received · TDC Tech','Your '.$s['name'].' order is queued. EUR '.number_format($amount,2).' was deducted from your wallet. Sign in to follow delivery.',(int)$u['id']);}catch(Throwable $e){error_log('SMM notification queue unavailable');}
        return ['ok'=>true,'order_id'=>$oid,'amount'=>$amount,'message'=>'Order queued for delivery.'];
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
function smm_finish(int $oid,string $state,string $status,?int $remains=null,?string $start=null): void {
    $db=db();$db->beginTransaction();
    try{
        $q=$db->prepare('SELECT o.*,j.state job_state,j.payload FROM orders o JOIN smm_jobs j ON j.order_id=o.id WHERE o.id=? FOR UPDATE');$q->execute([$oid]);$o=$q->fetch();
        if(!$o||in_array($o['job_state'],['done','failed'],true)){$db->commit();return;}
        $final=in_array($state,['done','failed'],true);$refund=0;
        if($final && in_array($status,['cancelled','rejected'],true))$refund=(float)$o['amount'];
        if($final && $status==='partial' && $remains!==null){$details=json_decode($o['details'],true);$qty=max(1,(int)($details['quantity']??1));$refund=floor((float)$o['amount']*min($qty,max(0,$remains))/$qty*100)/100;}
        if($refund>0)$db->prepare("INSERT IGNORE INTO wallet_ledger(user_id,amount,kind,status,reference,note) VALUES(?,?,'refund','posted',?,'SMM undelivered allocation refund')")->execute([$o['user_id'],$refund,'order:'.$oid]);
        $mapped=$status==='partial'?'completed':($final?$status:'processing');
        $db->prepare('UPDATE orders SET status=? WHERE id=?')->execute([$mapped,$oid]);
        $db->prepare('UPDATE smm_jobs SET state=?,provider_status=?,remains=?,start_count=?,last_checked_at=UTC_TIMESTAMP() WHERE order_id=?')->execute([$state,$status,$remains,$start,$oid]);$db->commit();
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
function smm_dispatch(array $j,?callable $api=null): void {
    $q=db()->prepare("UPDATE smm_jobs SET state='submitting' WHERE order_id=? AND state='queued'");$q->execute([$j['order_id']]);if(!$q->rowCount())return;
    try{
        $p=smm_provider((int)$j['provider_id']);
        if(!$p['active']){smm_finish((int)$j['order_id'],'failed','rejected');return;}
        $r=($api??'smm_api')($p,'add',json_decode($j['payload'],true));
        if(isset($r['order'])&&preg_match('/^[a-zA-Z0-9_-]{1,80}$/',(string)$r['order'])){
            db()->prepare("UPDATE smm_jobs SET remote_order=?,state='submitted',provider_status='Pending',last_checked_at=UTC_TIMESTAMP() WHERE order_id=?")->execute([(string)$r['order'],$j['order_id']]);
            db()->prepare("UPDATE orders SET status='processing' WHERE id=?")->execute([$j['order_id']]);
        }elseif(isset($r['error']))smm_finish((int)$j['order_id'],'failed','rejected');
        else throw new RuntimeException('Uncertain submission');
    }catch(Throwable $e){db()->prepare("UPDATE smm_jobs SET state='review',provider_status='Unknown provider outcome; manual reconciliation required' WHERE order_id=?")->execute([$j['order_id']]);}
}
