<?php
require dirname(__DIR__,2).'/app/smm.php';
$admin=require_admin();
if($_SERVER['REQUEST_METHOD']==='GET'){
 $providers=db()->query('SELECT id,name,api_url,currency,units_per_eur,profit_percent,active,last_synced_at FROM smm_providers ORDER BY id')->fetchAll();
 $review=db()->query("SELECT j.order_id,j.state,j.provider_status,j.remote_order,o.description FROM smm_jobs j JOIN orders o ON o.id=j.order_id WHERE j.state IN ('review','submitting') ORDER BY j.order_id DESC LIMIT 100")->fetchAll();
 json_out(['providers'=>$providers,'review'=>$review,'csrf'=>csrf_token()]);
}
require_method('POST');require_csrf();$d=json_body();$action=$d['action']??'';
try{
 if($action==='save'){
  $id=(int)($d['id']??0);$old=$id?smm_provider($id):null;
  $url=trim((string)($d['api_url']??''));smm_endpoint($url);
  $name=text_cut(trim((string)($d['name']??'')),0,120);$currency=strtoupper((string)($d['currency']??''));
  $fx=(float)($d['units_per_eur']??0);$profit=(float)($d['profit_percent']??-1);
  if(!$name||!preg_match('/^[A-Z]{3}$/',$currency)||$fx<=0||$fx>100000000||$profit<0||$profit>10000||($currency==='EUR'&&$fx!==1.0))throw new InvalidArgumentException('Enter a name, currency, valid conversion and profit percentage.');
  $key=trim((string)($d['api_key']??''));
  if(strlen($key)>1000||(!$old&&!$key))throw new InvalidArgumentException('Enter a valid API key.');
  $secret=$key?encrypt_private_note($key):$old['encrypted_key'];$active=!empty($d['active'])?1:0;
  if($active){$p=['api_url'=>$url,'encrypted_key'=>$secret];$balance=smm_api($p,'balance');
  if(isset($balance['error'])||($balance['currency']??'')!==$currency)throw new InvalidArgumentException('Key validation failed, or provider currency does not match.');}
  if($id)db()->prepare('UPDATE smm_providers SET name=?,api_url=?,encrypted_key=?,currency=?,units_per_eur=?,profit_percent=?,active=? WHERE id=?')->execute([$name,$url,$secret,$currency,$fx,$profit,$active,$id]);
  else{db()->prepare('INSERT INTO smm_providers(name,api_url,encrypted_key,currency,units_per_eur,profit_percent,active) VALUES(?,?,?,?,?,?,?)')->execute([$name,$url,$secret,$currency,$fx,$profit,$active]);$id=(int)db()->lastInsertId();}
  if(!$active)db()->prepare('UPDATE services s JOIN smm_services m ON m.service_id=s.id SET s.active=0 WHERE m.provider_id=?')->execute([$id]);
  audit('smm.provider.saved',(int)$admin['id'],['provider_id'=>$id]);json_out(['ok'=>true,'id'=>$id,'message'=>'Provider saved. Sync to apply catalog prices.']);
 }
 if($action==='sync'){$id=(int)($d['id']??0);$result=smm_sync($id);audit('smm.catalog.synced',(int)$admin['id'],['provider_id'=>$id]+$result);json_out(['ok'=>true]+$result);}
 if($action==='balance'){$p=smm_provider((int)($d['id']??0));$r=smm_api($p,'balance');if(isset($r['error']))throw new InvalidArgumentException('Provider balance unavailable.');json_out(['balance'=>$r['balance']??null,'currency'=>$r['currency']??null]);}
 // Reconcile ambiguous submissions only with an externally confirmed provider ID.
 if($action==='reconcile'){
  $oid=(int)($d['order_id']??0);$remote=(string)($d['remote_order']??'');if(!preg_match('/^[a-zA-Z0-9_-]{1,80}$/',$remote))throw new InvalidArgumentException('Enter the confirmed provider order ID.');
  $q=db()->prepare("SELECT * FROM smm_jobs WHERE order_id=? AND state='review'");$q->execute([$oid]);$job=$q->fetch();if(!$job)throw new InvalidArgumentException('Order is not awaiting review.');
  $r=smm_api(smm_provider((int)$job['provider_id']),'status',['order'=>$remote]);if(isset($r['error'])||!isset($r['status']))throw new InvalidArgumentException('Provider order could not be verified.');
  db()->prepare("UPDATE smm_jobs SET remote_order=?,state='submitted' WHERE order_id=? AND state='review'")->execute([$remote,$oid]);audit('smm.order.reconciled',(int)$admin['id'],['order_id'=>$oid]);json_out(['ok'=>true]);
 }
 json_out(['error'=>'Unknown action.'],400);
}catch(InvalidArgumentException $e){json_out(['error'=>$e->getMessage()],422);}catch(Throwable $e){error_log('SMM administration failed');json_out(['error'=>'Provider could not be reached. Nothing was submitted for delivery.'],502);}
