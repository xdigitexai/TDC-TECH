<?php
require dirname(__DIR__,2).'/app/bootstrap.php';
$admin=require_admin();$pdo=db();
if($_SERVER['REQUEST_METHOD']==='GET') {
  require_method('GET');$slug=preg_replace('/[^a-z0-9-]/','',(string)($_GET['slug']??''));
  $q=$pdo->prepare('SELECT slug,name,description,icon,active,sort_order FROM service_pages WHERE slug=?');$q->execute([$slug]);$page=$q->fetch();if(!$page)json_out(['error'=>'Service page not found.'],404);
  $q=$pdo->prepare('SELECT s.id,s.slug,s.name,s.price,s.pricing_mode,s.active,s.dashboard_icon FROM services s JOIN service_page_services ps ON ps.service_id=s.id WHERE ps.page_slug=? ORDER BY s.id');$q->execute([$slug]);$services=$q->fetchAll();
  $q=$pdo->prepare("SELECT COUNT(*) total,COALESCE(SUM(amount),0) order_value,COALESCE(AVG(amount),0) average_value,COUNT(DISTINCT user_id) customers,COALESCE(SUM(status='pending'),0) pending,COALESCE(SUM(status='processing'),0) processing,COALESCE(SUM(status='completed'),0) completed,COALESCE(SUM(status IN ('rejected','cancelled')),0) closed FROM orders WHERE service_id IN (SELECT service_id FROM service_page_services WHERE page_slug=?)");$q->execute([$slug]);$stats=$q->fetch();
  $q=$pdo->prepare("SELECT COALESCE(SUM(CASE WHEN kind='debit' THEN -amount WHEN kind='refund' THEN amount ELSE 0 END),0) FROM wallet_ledger WHERE status='posted' AND reference LIKE 'order:%' AND CAST(SUBSTRING(reference,7) AS UNSIGNED) IN (SELECT id FROM orders WHERE service_id IN (SELECT service_id FROM service_page_services WHERE page_slug=?))");$q->execute([$slug]);$stats['income']=(float)$q->fetchColumn();
  $q=$pdo->prepare("SELECT DATE(o.created_at) day,COUNT(*) orders_count,COALESCE(SUM(o.amount),0) value_total FROM orders o WHERE o.service_id IN (SELECT service_id FROM service_page_services WHERE page_slug=?) AND o.created_at>=UTC_DATE()-INTERVAL 29 DAY GROUP BY DATE(o.created_at) ORDER BY day");$q->execute([$slug]);$trend=$q->fetchAll();
  $q=$pdo->prepare('SELECT o.id,o.user_id,u.name customer,u.email,s.name service,s.slug service_slug,o.description,o.details,o.admin_note,o.amount,o.status,o.created_at,o.updated_at FROM orders o JOIN users u ON u.id=o.user_id JOIN services s ON s.id=o.service_id WHERE o.service_id IN (SELECT service_id FROM service_page_services WHERE page_slug=?) ORDER BY o.id DESC LIMIT 250');$q->execute([$slug]);$orders=$q->fetchAll();foreach($orders as &$o){$o['admin_note']=decrypt_private_note($o['admin_note']);}unset($o);
  json_out(['page'=>$page,'stats'=>$stats,'trend'=>$trend,'services'=>$services,'orders'=>$orders,'csrf'=>csrf_token()]);
}
require_method('POST');require_csrf();$d=json_body();$slug=preg_replace('/[^a-z0-9-]/','',(string)($d['slug']??''));
if(($d['action']??'')==='page_update'){
 $name=trim((string)($d['name']??''));$description=trim((string)($d['description']??''));$icon=trim((string)($d['icon']??''));$active=($d['active']??false)===true;
 if($name===''||text_len($name)>160||text_len($description)>500||$icon===''||text_len($icon)>80)json_out(['error'=>'Enter a service name, description, and icon.'],422);
 $q=$pdo->prepare('UPDATE service_pages SET name=?,description=?,icon=?,active=? WHERE slug=?');$q->execute([$name,$description,$icon,$active?1:0,$slug]);if(!$q->rowCount()){$q=$pdo->prepare('SELECT slug FROM service_pages WHERE slug=?');$q->execute([$slug]);if(!$q->fetch())json_out(['error'=>'Service page not found.'],404);}
 audit('service_page.updated',(int)$admin['id'],['slug'=>$slug]);json_out(['ok'=>true]);
}
if(($d['action']??'')==='order_update'){
 $guard=$pdo->prepare('SELECT order_id FROM smm_jobs WHERE order_id=?');$guard->execute([(int)($d['id']??0)]);if($guard->fetch())json_out(['error'=>'Provider orders use automatic delivery tracking.'],409);
 $id=(int)($d['id']??0);$status=(string)($d['status']??'');if(!in_array($status,['pending','processing','completed','rejected','cancelled'],true))json_out(['error'=>'Invalid order status.'],422);
 $q=$pdo->prepare('SELECT o.id,o.user_id,o.amount,o.status,u.email,u.name FROM orders o JOIN users u ON u.id=o.user_id WHERE o.id=? AND o.service_id IN (SELECT service_id FROM service_page_services WHERE page_slug=?)');$q->execute([$id,$slug]);$o=$q->fetch();if(!$o)json_out(['error'=>'Order is not part of this service.'],404);
 if(in_array($o['status'],['rejected','cancelled'],true)&&$status!==$o['status'])json_out(['error'=>'Rejected or cancelled requests are final.'],409);
 $note=trim((string)($d['note']??''));if(text_len($note)>4000)json_out(['error'=>'Note is too long.'],422);
 $pdo->beginTransaction();$pdo->prepare('UPDATE orders SET status=?,admin_note=? WHERE id=?')->execute([$status,$note!==''?encrypt_private_note($note):null,$id]);
 if(in_array($status,['rejected','cancelled'],true)&&(float)$o['amount']>0){$q=$pdo->prepare("SELECT id FROM wallet_ledger WHERE kind='refund' AND reference=?");$q->execute(['order:'.$id]);if(!$q->fetch())$pdo->prepare("INSERT INTO wallet_ledger(user_id,amount,kind,status,reference,note,created_by,created_at) VALUES(?,?,'refund','posted',?,?,?,UTC_TIMESTAMP())")->execute([$o['user_id'],$o['amount'],'order:'.$id,'Refund for order #'.$id,$admin['id']]);}
 $pdo->commit();audit('service_order.updated',(int)$admin['id'],['slug'=>$slug,'order_id'=>$id,'status'=>$status]);
 try{if($status!==$o['status']){require_once dirname(__DIR__,2).'/app/notifications.php';queue_email($o['email'],'TDC Tech order update','Hello '.$o['name'].",

Your order #".$id.' is now '.$status.'. Sign in to your TDC Tech dashboard to view details.',(int)$o['user_id']);}}catch(Throwable $e){error_log('Service order email could not be queued: '.$e->getMessage());}
 json_out(['ok'=>true]);
}
if(($d['action']??'')==='service_update'){
 $id=(int)($d['id']??0);$price=round((float)($d['price']??-1),2);$active=($d['active']??false)===true;
 if($price<0||$price>100000)json_out(['error'=>'Price must be between €0 and €100,000.'],422);
 $q=$pdo->prepare('UPDATE services SET price=?,active=? WHERE id=? AND id IN (SELECT service_id FROM service_page_services WHERE page_slug=?)');$q->execute([$price,$active?1:0,$id,$slug]);if(!$q->rowCount()){$q=$pdo->prepare('SELECT id FROM services WHERE id=? AND id IN (SELECT service_id FROM service_page_services WHERE page_slug=?)');$q->execute([$id,$slug]);if(!$q->fetch())json_out(['error'=>'Catalog item does not belong to this page.'],404);}
 audit('service.price_updated',(int)$admin['id'],['service_slug'=>$slug,'service_id'=>$id,'price'=>$price,'active'=>$active]);json_out(['ok'=>true]);
}
json_out(['error'=>'Unknown action.'],400);
