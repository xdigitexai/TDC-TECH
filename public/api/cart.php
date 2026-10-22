<?php
require_once dirname(__DIR__,2).'/app/bootstrap.php';$u=require_user();$pdo=db();
if($_SERVER['REQUEST_METHOD']==='GET')json_out(['services'=>$pdo->query("SELECT s.id,s.slug,s.name,s.price,a.category,a.stack,a.description,a.demo_url,a.thumbnail FROM services s JOIN template_assets a ON a.service_id=s.id WHERE s.active=1 AND a.source_media_id IS NOT NULL AND s.slug LIKE 'template-%' ORDER BY s.id")->fetchAll(),'csrf'=>csrf_token()]);
require_method('POST');require_csrf();$d=json_body();$slugs=$d['items']??[];
if(!is_array($slugs)||!$slugs||count($slugs)>20)json_out(['error'=>'Choose up to 20 templates.'],422);
foreach($slugs as $slug)if(!is_string($slug)||!preg_match('/^template-[a-z0-9-]+$/',$slug))json_out(['error'=>'Invalid cart item.'],422);
$slugs=array_values(array_unique($slugs));sort($slugs);$hash=hash('sha256',json_encode($slugs));$request=(string)($d['request_id']??'');
if(!preg_match('/^[a-zA-Z0-9_-]{16,80}$/',$request))json_out(['error'=>'Refresh checkout and try again.'],422);
$pdo->beginTransaction();
try{
 $pdo->prepare('SELECT id FROM users WHERE id=? FOR UPDATE')->execute([$u['id']]);
 $q=$pdo->prepare('SELECT * FROM checkout_receipts WHERE user_id=? AND request_id=?');$q->execute([$u['id'],$request]);$old=$q->fetch();if($old){if($old['request_hash']!==$hash){$pdo->rollBack();json_out(['error'=>'Request belongs to a different cart.'],409);}$pdo->commit();json_out(['ok'=>true,'order_ids'=>json_decode($old['order_ids'],true),'amount'=>$old['amount'],'duplicate'=>true]);}
 $q=$pdo->prepare('SELECT s.id,s.slug,s.name,s.price FROM services s JOIN template_assets a ON a.service_id=s.id WHERE a.source_media_id IS NOT NULL AND s.active=1 AND s.pricing_mode=\'fixed\' AND s.slug IN ('.implode(',',array_fill(0,count($slugs),'?')).') ORDER BY s.id FOR UPDATE');$q->execute($slugs);$items=$q->fetchAll();if(count($items)!==count($slugs)){$pdo->rollBack();json_out(['error'=>'A template is no longer available. Refresh the catalog.'],409);}
 $cents=array_sum(array_map(fn($s)=>(int)round((float)$s['price']*100),$items));$amount=$cents/100;
 if(($d['action']??'')==='quote'){$pdo->rollBack();json_out(['items'=>$items,'amount'=>$amount,'currency'=>'EUR']);}
 if(($d['action']??'')!=='checkout'){$pdo->rollBack();json_out(['error'=>'Unknown action.'],400);}
 $q=$pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM wallet_ledger WHERE user_id=? AND status='posted'");$q->execute([$u['id']]);if((int)round((float)$q->fetchColumn()*100)<$cents){$pdo->rollBack();json_out(['error'=>'Insufficient wallet balance. Add funds to complete checkout.'],402);}
 $ids=[];foreach($items as $s){$pdo->prepare("INSERT INTO orders(user_id,service_id,description,details,amount,status) VALUES(?,?,?,'{}',?,'pending')")->execute([$u['id'],$s['id'],$s['name'].' · Template request',$s['price']]);$id=(int)$pdo->lastInsertId();$ids[]=$id;$pdo->prepare("INSERT INTO wallet_ledger(user_id,amount,kind,reference,note) VALUES(?,?,'debit',?,?)")->execute([$u['id'],-(float)$s['price'],'order:'.$id,$s['name']]);}
 $pdo->prepare('INSERT INTO checkout_receipts(user_id,request_id,request_hash,order_ids,amount) VALUES(?,?,?,?,?)')->execute([$u['id'],$request,$hash,json_encode($ids),$amount]);$pdo->commit();try{require_once dirname(__DIR__,2).'/app/documents.php';foreach($ids as $invoiceId){[$invoiceOrder,$invoiceLines]=tdc_invoice($invoiceId);queue_document_email((int)$u['id'],'Template invoice INV-'.$invoiceId,$invoiceLines,'INV-'.$invoiceId.'.pdf');}}catch(Throwable $e){error_log('Template PDF invoice queue failed');}audit('cart.checkout',(int)$u['id'],['order_ids'=>$ids,'amount'=>$amount]);
 try{require_once dirname(__DIR__,2).'/app/notifications.php';queue_email($u['email'],'Template order received · TDC Tech','Your template requests #'.implode(', #',$ids).' are pending fulfillment. EUR '.number_format($amount,2).' was paid from your wallet.',(int)$u['id']);}catch(Throwable $e){error_log('Cart receipt could not be queued');}
 json_out(['ok'=>true,'order_ids'=>$ids,'amount'=>$amount]);
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
