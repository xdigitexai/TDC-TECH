<?php
require dirname(__DIR__,2).'/app/bootstrap.php';
$admin=require_admin();
if($_SERVER['REQUEST_METHOD']==='GET') {
  $pdo=db();
  $stats=[
    'users'=>(int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='customer'")->fetchColumn(),
    'orders'=>(int)$pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn(),
    'pending_orders'=>(int)$pdo->query("SELECT COUNT(*) FROM orders WHERE status='pending'")->fetchColumn(),
    'pending_topups'=>(int)$pdo->query("SELECT COUNT(*) FROM topup_payments WHERE status IN ('initiated','pending','processing')")->fetchColumn(),
    'revenue'=>(float)$pdo->query("SELECT COALESCE(SUM(-amount),0) FROM wallet_ledger WHERE kind='debit' AND status='posted'")->fetchColumn()
  ];
  $users=$pdo->query("SELECT u.id,u.name,u.email,u.status,u.created_at,COALESCE(SUM(CASE WHEN l.status='posted' THEN l.amount ELSE 0 END),0) balance FROM users u LEFT JOIN wallet_ledger l ON l.user_id=u.id WHERE u.role='customer' GROUP BY u.id ORDER BY u.id DESC LIMIT 200")->fetchAll();
  $orders=$pdo->query('SELECT o.id,o.user_id,u.name customer,u.email,s.name service,s.slug service_slug,(SELECT ps.page_slug FROM service_page_services ps WHERE ps.service_id=s.id ORDER BY ps.page_slug LIMIT 1) admin_page_slug,o.description,o.details,o.admin_note,o.amount,o.status,o.created_at FROM orders o JOIN users u ON u.id=o.user_id JOIN services s ON s.id=o.service_id ORDER BY o.id DESC LIMIT 200')->fetchAll();
  foreach($orders as &$order){$order['admin_note']=decrypt_private_note($order['admin_note']);$q=$pdo->prepare('SELECT id,original_name,kind FROM customer_media WHERE order_id=?');$q->execute([$order['id']]);$order['media']=$q->fetchAll();} unset($order);
  $topups=$pdo->query("SELECT p.local_reference,p.provider_reference,p.requested_gbp amount,p.provider_amount,p.currency,p.gateway,p.status,p.created_at,u.name customer,u.email FROM topup_payments p JOIN users u ON u.id=p.user_id ORDER BY p.id DESC LIMIT 200")->fetchAll();
  $fx=$pdo->query('SELECT currency,units_per_eur,active,updated_at FROM gateway_fx_rates ORDER BY currency')->fetchAll();
  $services=$pdo->query('SELECT id,slug,name,price,pricing_mode,active FROM services ORDER BY id')->fetchAll();
  json_out(['stats'=>$stats,'users'=>$users,'orders'=>$orders,'topups'=>$topups,'fx'=>$fx,'services'=>$services,'csrf'=>csrf_token(),'admin'=>$admin]);
}
require_method('POST'); require_csrf(); $d=json_body(); $act=(string)($d['action']??''); $pdo=db();
if($act==='fx_update') {
  $currency=strtoupper((string)($d['currency']??''));$rate=round((float)($d['units_per_eur']??0),6);$active=($d['active']??false)===true;
  if(!in_array($currency,['KES','CDF','UGX','XOF','XAF','RWF','ZMW','SLE','USD'],true)||$rate<=0||$rate>1000000000)json_out(['error'=>'Enter a valid positive conversion rate for a supported currency.'],422);
  $q=$pdo->prepare('UPDATE gateway_fx_rates SET units_per_eur=?,active=? WHERE currency=?');$q->execute([$rate,$active?1:0,$currency]);if(!$q->rowCount()){$q=$pdo->prepare('SELECT currency FROM gateway_fx_rates WHERE currency=?');$q->execute([$currency]);if(!$q->fetch())json_out(['error'=>'Currency was not found.'],404);}
  audit('gateway.fx_rate_updated',(int)$admin['id'],['currency'=>$currency,'active'=>$active]);json_out(['ok'=>true]);
}
if($act==='order_note') {
  $id=(int)($d['id']??0);$note=trim((string)($d['note']??''));if(text_len($note)>4000)json_out(['error'=>'Note is too long.'],422);
  $q=$pdo->prepare('SELECT o.id,o.user_id,u.email,u.name FROM orders o JOIN users u ON u.id=o.user_id WHERE o.id=?');$q->execute([$id]);$order=$q->fetch();if(!$order)json_out(['error'=>'Order not found.'],404);
  $q=$pdo->prepare('UPDATE orders SET admin_note=? WHERE id=?');$q->execute([$note!==''?encrypt_private_note($note):null,$id]);
  audit('order.fulfillment_note_updated',(int)$admin['id'],['order_id'=>$id]);
  if($note!=='')try{require_once dirname(__DIR__,2).'/app/notifications.php';$body='Hello '.$order['name']."\n\nThere is a new update for your TDC Tech order #".$id.".\n\n".$note."\n\nSign in to your dashboard to view your order.";queue_email($order['email'],'New update for order #'.$id.' · TDC Tech',$body,(int)$order['user_id']);}catch(Throwable $e){error_log('Order note email could not be queued: '.$e->getMessage());}
  json_out(['ok'=>true]);
}
if($act==='order_status') {
  $id=(int)($d['id']??0); $status=(string)($d['status']??'');
  $check=$pdo->prepare('SELECT order_id FROM smm_jobs WHERE order_id=?');$check->execute([$id]);if($check->fetch())json_out(['error'=>'Provider orders are updated through delivery tracking, not manual status changes.'],409);
  if(!in_array($status,['processing','completed','rejected','cancelled'],true)) json_out(['error'=>'Invalid order status.'],422);
  $q=$pdo->prepare('SELECT o.id,o.user_id,o.amount,o.status,u.email,u.name FROM orders o JOIN users u ON u.id=o.user_id WHERE o.id=?');$q->execute([$id]);$o=$q->fetch();if(!$o)json_out(['error'=>'Order not found.'],404);
  $pdo->beginTransaction(); if(in_array($o['status'],['rejected','cancelled'],true)&&$status!==$o['status']){$pdo->rollBack();json_out(['error'=>'Rejected or cancelled orders are final.'],409);}
  $pdo->prepare('UPDATE orders SET status=? WHERE id=?')->execute([$status,$id]);
  $check=$pdo->prepare("SELECT id FROM wallet_ledger WHERE kind='refund' AND reference=? LIMIT 1"); $check->execute(['order:'.$id]);
  if(in_array($status,['rejected','cancelled'],true) && !$check->fetch() && (float)$o['amount']>0) {
    $pdo->prepare("INSERT INTO wallet_ledger(user_id,amount,kind,status,reference,note,created_by,created_at) VALUES(?,?,'refund','posted',?,?,?,UTC_TIMESTAMP())")->execute([$o['user_id'],$o['amount'],'order:'.$id,'Refund for order #'.$id,$admin['id']]);
  }
  $pdo->commit();audit('order.status_changed',(int)$admin['id'],['order_id'=>$id,'status'=>$status]);
  if($status!==$o['status'])try{require_once dirname(__DIR__,2).'/app/notifications.php';queue_email($o['email'],'TDC Tech order update','Hello '.$o['name'].",\n\nYour order #".$id.' is now '.$status.'. Sign in to your TDC Tech dashboard for details.',(int)$o['user_id']);}catch(Throwable $e){error_log('Order status email could not be queued: '.$e->getMessage());}
  json_out(['ok'=>true]);
}
if($act==='service_update') {
  $id=(int)($d['id']??0);$price=round((float)($d['price']??-1),2);$active=($d['active']??false)===true;
  if($price<0 || $price>100000)json_out(['error'=>'Price must be from €0 to €100,000.'],422);
  $exists=$pdo->prepare('SELECT id FROM services WHERE id=?');$exists->execute([$id]);if(!$exists->fetch())json_out(['error'=>'Service was not found.'],404);$q=$pdo->prepare('UPDATE services SET price=?,active=? WHERE id=?');$q->execute([$price,$active?1:0,$id]);
  audit('service.updated',(int)$admin['id'],['service_id'=>$id,'price'=>$price,'active'=>$active]);json_out(['ok'=>true]);
}
if($act==='user_status') {
  $id=(int)($d['id']??0);$status=(string)($d['status']??'');if(!in_array($status,['active','suspended'],true))json_out(['error'=>'Invalid status.'],422);
  if($id===(int)$admin['id'])json_out(['error'=>'You cannot suspend your own admin account.'],422);
  $q=$pdo->prepare("UPDATE users SET status=? WHERE id=? AND role='customer'");$q->execute([$status,$id]);if(!$q->rowCount())json_out(['error'=>'Customer was not updated.'],404);audit('user.status_changed',(int)$admin['id'],['user_id'=>$id,'status'=>$status]);json_out(['ok'=>true]);
}
if($act==='wallet_adjust') {
  $id=(int)($d['id']??0);$amount=round((float)($d['amount']??0),2);$note=trim((string)($d['note']??''));
  if(!$amount || abs($amount)>10000 || text_len($note)<4 || text_len($note)>500)json_out(['error'=>'Enter an adjustment up to €10,000 and a clear note.'],422);
  $q=$pdo->prepare("SELECT id FROM users WHERE id=? AND role='customer'");$q->execute([$id]);if(!$q->fetch())json_out(['error'=>'Customer not found.'],404);
  $pdo->prepare("INSERT INTO wallet_ledger(user_id,amount,kind,status,note,created_by,created_at) VALUES(?,?,'adjustment','posted',?,?,UTC_TIMESTAMP())")->execute([$id,$amount,$note,$admin['id']]);audit('wallet.adjusted',(int)$admin['id'],['user_id'=>$id,'amount'=>$amount]);json_out(['ok'=>true]);
}
json_out(['error'=>'Unknown action.'],400);
