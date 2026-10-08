<?php
require dirname(__DIR__).'/app/smm.php';
$lock=fopen('/var/lib/tdc-smm/worker.lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))exit;
// A crash during submission has an unknown outcome. Never automatically replay it.
db()->exec("UPDATE smm_jobs SET state='review',provider_status='Submission interrupted; confirm with provider' WHERE state='submitting'");
$jobs=db()->query("SELECT * FROM smm_jobs WHERE state='queued' ORDER BY order_id LIMIT 10")->fetchAll();
foreach($jobs as $j) smm_dispatch($j);
$jobs=db()->query("SELECT * FROM smm_jobs WHERE state='submitted' AND (last_checked_at IS NULL OR last_checked_at < UTC_TIMESTAMP()-INTERVAL 2 MINUTE) ORDER BY order_id LIMIT 100")->fetchAll();
foreach($jobs as $j){
 try{
  $r=smm_api(smm_provider((int)$j['provider_id']),'status',['order'=>$j['remote_order']]);
  if(isset($r['error'])||!isset($r['status']))continue;
  $status=strtolower(trim((string)$r['status']));$state='submitted';$mapped='processing';
  if($status==='completed'){$state='done';$mapped='completed';}
  elseif(in_array($status,['canceled','cancelled'],true)){$state='done';$mapped='cancelled';}
  elseif($status==='partial'){$state='done';$mapped='partial';}
  elseif(in_array($status,['failed','rejected'],true)){$state='failed';$mapped='rejected';}
  // Partial refunds require a valid remaining quantity; hold ambiguous data for review.
  $remains=isset($r['remains'])&&is_numeric($r['remains'])?max(0,(int)$r['remains']):null;
  if($mapped==='partial'&&$remains===null){db()->prepare("UPDATE smm_jobs SET state='review',provider_status='Partial delivery missing remaining quantity' WHERE order_id=?")->execute([$j['order_id']]);continue;}
  smm_finish((int)$j['order_id'],$state,$mapped,$remains,isset($r['start_count'])?text_cut((string)$r['start_count'],0,80):null);
 }catch(Throwable $e){error_log('SMM status check deferred for order '.(int)$j['order_id']);}
}
echo json_encode(['checked'=>count($jobs)]).PHP_EOL;
