<?php
require dirname(__DIR__,2).'/app/bootstrap.php';
$u=require_user();
if ($_SERVER['REQUEST_METHOD']==='GET') {
    $q=db()->prepare('SELECT o.id,s.name service,s.slug service_slug,(SELECT ps.page_slug FROM service_page_services ps WHERE ps.service_id=s.id LIMIT 1) page_slug,o.description,o.details,o.admin_note,o.amount,o.status,o.created_at,j.provider_status,j.remains,j.start_count FROM orders o JOIN services s ON s.id=o.service_id LEFT JOIN smm_jobs j ON j.order_id=o.id WHERE o.user_id=? ORDER BY o.id DESC LIMIT 100'); $q->execute([$u['id']]); $orders=$q->fetchAll(); foreach($orders as &$order){$order['admin_note']=decrypt_private_note($order['admin_note']);} unset($order);
    $q=db()->prepare('SELECT id,amount,kind,status,note,created_at FROM wallet_ledger WHERE user_id=? ORDER BY id DESC LIMIT 100'); $q->execute([$u['id']]);
    $transactions=$q->fetchAll();
    $q=db()->prepare("SELECT COUNT(*) total,SUM(status IN ('pending','processing')) active,SUM(status='completed') completed FROM orders WHERE user_id=?");$q->execute([$u['id']]);$totals=$q->fetch();
    $q=db()->prepare("SELECT -COALESCE(SUM(amount),0) FROM wallet_ledger WHERE user_id=? AND status='posted' AND kind IN ('debit','refund')");$q->execute([$u['id']]);$totals['spent']=$q->fetchColumn();
    json_out(['orders'=>$orders,'transactions'=>$transactions,'totals'=>$totals]);
}
require_method('POST'); require_csrf(); $data=json_body(); $action=$data['action']??'order';
$slug=preg_replace('/[^a-z0-9-]/','',(string)($data['service_slug']??''));
if(str_starts_with($slug,'smm-p')){
    if(!in_array($action,['order','quote'],true))json_out(['error'=>'Unknown order action.'],400);
    if(!is_array($data['details']??null))json_out(['error'=>'Invalid order details.'],422);
    require_once dirname(__DIR__,2).'/app/smm.php';
    $q=db()->prepare('SELECT s.*,m.*,p.active provider_active FROM services s JOIN smm_services m ON m.service_id=s.id JOIN smm_providers p ON p.id=m.provider_id WHERE s.slug=? AND s.active=1 AND p.active=1');$q->execute([$slug]);$s=$q->fetch();
    if(!$s)json_out(['error'=>'Service unavailable. Refresh the catalog.'],404);
    try{json_out(smm_submit_order($u,$s,$data));}catch(InvalidArgumentException $e){json_out(['error'=>$e->getMessage()],str_contains($e->getMessage(),'Insufficient')?402:422);}
}
$q=db()->prepare('SELECT id,name,price,pricing_mode FROM services WHERE slug=? AND active=1'); $q->execute([$slug]); $service=$q->fetch();
if(!$service) json_out(['error'=>'This service is currently unavailable.'],404);
$desc=trim((string)($data['description']??$service['name'])); $desc=text_cut($desc,0,255);
$details=$data['details']??[]; if(!is_array($details)) $details=[]; if(strlen(json_encode($details)?:'')>5000) json_out(['error'=>'Order details are too long.'],422);
$mediaIds=$details['media_ids']??[];if(!is_array($mediaIds)||count($mediaIds)>20||array_filter($mediaIds,fn($id)=>!is_string($id)||!preg_match('/^[a-f0-9]{32}$/',$id)))json_out(['error'=>'Invalid media selection.'],422);$mediaIds=array_values(array_unique($mediaIds));
if($slug==='music'&&(trim((string)($details['release_title']??''))===''||trim((string)($details['artist']??''))===''||!$mediaIds))json_out(['error'=>'Enter a release title and artist, then upload and select your audio and artwork.'],422);
// SMM prices are computed on the server from the configured per-1,000 rate.
$amount=(float)$service['price'];
if($service['pricing_mode']==='per_1000') { $qty=filter_var($details['quantity']??0,FILTER_VALIDATE_INT); if(!$qty||$qty<100||$qty>1000000) json_out(['error'=>'Quantity must be from 100 to 1,000,000.'],422); $amount=round(($qty/1000)*(float)$service['price'],2); }
if($service['pricing_mode']==='per_gb') { $qty=filter_var($details['gb']??0,FILTER_VALIDATE_INT); if(!$qty||$qty<1||$qty>100) json_out(['error'=>'Proxy amount must be from 1 to 100 GB.'],422); $amount=round($qty*(float)$service['price'],2); }
if(isset($details['quantity'])) $details['quantity']=(int)$details['quantity'];
if(isset($details['gb'])) $details['gb']=(int)$details['gb'];
if($amount<0 || $amount>100000) json_out(['error'=>'Invalid order amount.'],422);
if(($data['action']??'')==='quote') json_out(['amount'=>$amount,'currency'=>'EUR']);
$pdo=db(); $pdo->beginTransaction();
try {
    $lock=$pdo->prepare('SELECT id FROM users WHERE id=? FOR UPDATE'); $lock->execute([$u['id']]);
    if($mediaIds){$marks=implode(',',array_fill(0,count($mediaIds),'?'));$q=$pdo->prepare("SELECT id,kind FROM customer_media WHERE id IN ($marks) AND user_id=? AND order_id IS NULL FOR UPDATE");$q->execute([...$mediaIds,$u['id']]);$files=$q->fetchAll();if(count($files)!==count($mediaIds)){ $pdo->rollBack();json_out(['error'=>'Select your own unattached uploaded files.'],422);}if($slug==='music'&&(!in_array('audio',array_column($files,'kind'),true)||!in_array('artwork',array_column($files,'kind'),true))){$pdo->rollBack();json_out(['error'=>'Select both audio and artwork for the release.'],422);}}
    $bal=$pdo->prepare("SELECT COALESCE(SUM(CASE WHEN status='posted' THEN amount ELSE 0 END),0) FROM wallet_ledger WHERE user_id=?"); $bal->execute([$u['id']]);
    if((float)$bal->fetchColumn()<$amount) { $pdo->rollBack(); json_out(['error'=>'Insufficient wallet balance. Request a top-up and wait for confirmation.'],402); }
    $q=$pdo->prepare('INSERT INTO orders(user_id,service_id,description,details,amount,status,created_at) VALUES(?,?,?,?,?,\'pending\',UTC_TIMESTAMP())'); $q->execute([$u['id'],$service['id'],$desc,json_encode($details),$amount]); $orderId=(int)$pdo->lastInsertId();
    if($mediaIds){$q=$pdo->prepare('UPDATE customer_media SET order_id=? WHERE id=? AND user_id=? AND order_id IS NULL');foreach($mediaIds as $id)$q->execute([$orderId,$id,$u['id']]);}
    if($amount>0) { $q=$pdo->prepare("INSERT INTO wallet_ledger(user_id,amount,kind,status,reference,note,created_at) VALUES(?,?,'debit','posted',?,?,UTC_TIMESTAMP())"); $q->execute([$u['id'],-$amount,'order:'.$orderId,$desc]); }
    $pdo->commit(); audit('order.created',(int)$u['id'],['order_id'=>$orderId,'amount'=>$amount]);
    try {
        require_once dirname(__DIR__,2).'/app/notifications.php';
        $inv='INV-'.str_pad((string)$orderId,6,'0',STR_PAD_LEFT);
        $rule=str_repeat('-',42);
        $body='Hello '.$u['name'].",\n\n"
            ."INVOICE ".$inv."\n".$rule."\n"
            ."Date            : ".gmdate('Y-m-d H:i')." UTC\n"
            ."Billed to       : ".$u['name']." <".$u['email'].">\n"
            ."Order reference : #".$orderId."\n"
            ."Service         : ".$service['name']."\n"
            ."Description     : ".$desc."\n"
            ."Amount          : EUR ".number_format($amount,2)."\n"
            ."Payment method  : Wallet balance (debited immediately)\n"
            ."Order status    : Pending\n"
            ."Currency        : EUR\n".$rule."\n\n"
            ."EUR ".number_format($amount,2)." was debited from your wallet for this order.\n"
            ."Sign in to your dashboard to follow progress on order #".$orderId.".\n\n"
            ."TDC Tech\n";
        queue_email($u['email'],'Invoice '.$inv.' · TDC Tech',$body,(int)$u['id']);
    } catch(Throwable $e) { error_log('Order invoice could not be queued: '.$e->getMessage()); }
    json_out(['ok'=>true,'order_id'=>$orderId,'amount'=>$amount,'message'=>'Order submitted.']);
} catch(Throwable $e) { if($pdo->inTransaction()) $pdo->rollBack(); throw $e; }

