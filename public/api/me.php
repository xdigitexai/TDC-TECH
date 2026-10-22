<?php
require_once dirname(__DIR__,2).'/app/bootstrap.php';
$u=current_user();
if (!$u) json_out(['authenticated'=>false,'csrf'=>csrf_token()]);
$q=db()->prepare("SELECT COALESCE(SUM(CASE WHEN status='posted' THEN amount ELSE 0 END),0) FROM wallet_ledger WHERE user_id=?");
$q->execute([$u['id']]);
json_out(['authenticated'=>true,'user'=>$u,'balance'=>(float)$q->fetchColumn(),'csrf'=>csrf_token()]);
