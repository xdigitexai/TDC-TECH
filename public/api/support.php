<?php
require dirname(__DIR__,2).'/app/bootstrap.php';$u=require_user();$admin=$u['role']==='admin';$pdo=db();
function ticket_access(int $id,array $u):array{$q=db()->prepare('SELECT * FROM support_tickets WHERE id=?');$q->execute([$id]);$t=$q->fetch();if(!$t||($u['role']!=='admin'&&(int)$t['user_id']!==(int)$u['id']))json_out(['error'=>'Ticket not found.'],404);return $t;}
if($_SERVER['REQUEST_METHOD']==='GET'){
 if(isset($_GET['id'])){$t=ticket_access((int)$_GET['id'],$u);$q=$pdo->prepare('SELECT m.id,m.body,m.created_at,u.name,u.role FROM support_messages m JOIN users u ON u.id=m.user_id WHERE ticket_id=? ORDER BY m.id LIMIT 1000');$q->execute([$t['id']]);json_out(['ticket'=>$t,'messages'=>$q->fetchAll(),'csrf'=>csrf_token()]);}
 $sql='SELECT t.*,u.name customer,(SELECT MAX(m.id) FROM support_messages m WHERE m.ticket_id=t.id) last_message_id FROM support_tickets t JOIN users u ON u.id=t.user_id'.($admin?'':' WHERE t.user_id=?').' ORDER BY t.updated_at DESC,t.id DESC LIMIT 250';$q=$pdo->prepare($sql);$q->execute($admin?[]:[$u['id']]);$tickets=$q->fetchAll();json_out(['tickets'=>$tickets,'open_count'=>count(array_filter($tickets,fn($t)=>$t['status']!=='closed')),'csrf'=>csrf_token()]);
}
require_method('POST');require_csrf();$d=json_body();$action=$d['action']??'';
if($action==='status'){
 if(!$admin)json_out(['error'=>'Administrator access required.'],403);$t=ticket_access((int)($d['id']??0),$u);$status=$d['status']??'';if(!in_array($status,['open','answered','closed'],true))json_out(['error'=>'Invalid status.'],422);
 $pdo->prepare('UPDATE support_tickets SET status=? WHERE id=?')->execute([$status,$t['id']]);audit('support.status_changed',(int)$u['id'],['ticket_id'=>$t['id'],'status'=>$status]);json_out(['ok'=>true]);
}
$body=trim((string)($d['body']??''));if(text_len($body)<1||text_len($body)>4000)json_out(['error'=>'Write a message of up to 4,000 characters.'],422);
$pdo->beginTransaction();
try{
 $pdo->prepare('SELECT id FROM users WHERE id=? FOR UPDATE')->execute([$u['id']]);
 $q=$pdo->prepare('SELECT COUNT(*) FROM support_messages WHERE user_id=? AND created_at>UTC_TIMESTAMP()-INTERVAL 1 MINUTE');$q->execute([$u['id']]);if($q->fetchColumn()>=10){$pdo->rollBack();json_out(['error'=>'Please wait a minute before sending more messages.'],429);}
 if($action==='create'){
  $subject=trim((string)($d['subject']??''));$request=(string)($d['request_id']??'');if(!$subject||text_len($subject)>160||!preg_match('/^[a-zA-Z0-9_-]{16,80}$/',$request)){$pdo->rollBack();json_out(['error'=>'Enter a subject and refresh the ticket form.'],422);}
  $q=$pdo->prepare('SELECT id FROM support_tickets WHERE user_id=? AND request_id=?');$q->execute([$u['id'],$request]);$old=$q->fetchColumn();if($old){$pdo->commit();json_out(['ok'=>true,'id'=>$old]);}
  $oid=!empty($d['order_id'])?(int)$d['order_id']:null;if($oid){$q=$pdo->prepare('SELECT id FROM orders WHERE id=? AND user_id=?');$q->execute([$oid,$u['id']]);if(!$q->fetch()){$pdo->rollBack();json_out(['error'=>'Choose one of your own orders.'],422);}}
  $pdo->prepare('INSERT INTO support_tickets(user_id,request_id,subject,order_id) VALUES(?,?,?,?)')->execute([$u['id'],$request,$subject,$oid]);$id=(int)$pdo->lastInsertId();
 }elseif($action==='reply'){
  $id=(int)($d['id']??0);$t=ticket_access($id,$u);if($t['status']==='closed'){$pdo->rollBack();json_out(['error'=>'This ticket is closed. Create a new ticket or ask admin to reopen it.'],409);}
 }else{$pdo->rollBack();json_out(['error'=>'Unknown action.'],400);}
 $pdo->prepare('INSERT INTO support_messages(ticket_id,user_id,body) VALUES(?,?,?)')->execute([$id,$u['id'],$body]);
 $pdo->prepare('UPDATE support_tickets SET status=?,updated_at=UTC_TIMESTAMP() WHERE id=?')->execute([$admin?'answered':'open',$id]);$pdo->commit();audit('support.message_sent',(int)$u['id'],['ticket_id'=>$id]);json_out(['ok'=>true,'id'=>$id]);
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
