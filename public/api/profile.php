<?php
require dirname(__DIR__,2).'/app/bootstrap.php';$u=require_user();$pdo=db();
if($_SERVER['REQUEST_METHOD']==='GET'){$q=$pdo->prepare('SELECT bio,avatar_url,timezone,theme FROM customer_profiles WHERE user_id=?');$q->execute([$u['id']]);json_out(['user'=>$u,'profile'=>$q->fetch()?:['bio'=>'','avatar_url'=>'','timezone'=>'Africa/Nairobi','theme'=>'dark'],'csrf'=>csrf_token()]);}
require_method('POST');require_csrf();$d=json_body();$action=$d['action']??'';
if(in_array($action,['name','profile'],true)){
 $name=trim((string)($d['name']??''));$bio=trim((string)($d['bio']??''));$tz=(string)($d['timezone']??'Africa/Nairobi');$theme=(string)($d['theme']??'dark');
 if(text_len($name)<2||text_len($name)>120||text_len($bio)>600||!in_array($tz,DateTimeZone::listIdentifiers(),true)||!in_array($theme,['dark','light','system'],true))json_out(['error'=>'Check your name, bio and preferences.'],422);
 $pdo->beginTransaction();try{$pdo->prepare('UPDATE users SET name=? WHERE id=?')->execute([$name,$u['id']]);$pdo->prepare('INSERT INTO customer_profiles(user_id,bio,timezone,theme) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE bio=VALUES(bio),timezone=VALUES(timezone),theme=VALUES(theme)')->execute([$u['id'],$bio,$tz,$theme]);$pdo->commit();}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}audit('profile.updated',(int)$u['id']);json_out(['ok'=>true]);
}
if(!in_array($action,['email','password'],true))json_out(['error'=>'Unknown profile action.'],400);
$q=$pdo->prepare('SELECT password_hash FROM users WHERE id=?');$q->execute([$u['id']]);if(!password_verify((string)($d['current_password']??''),(string)$q->fetchColumn()))json_out(['error'=>'Current password is incorrect.'],401);
if($action==='email'){
 $email=strtolower(trim((string)($d['email']??'')));if(!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($email)>254)json_out(['error'=>'Enter a valid email address.'],422);
 try{$pdo->prepare('UPDATE users SET email=?,email_verified_at=NULL WHERE id=?')->execute([$email,$u['id']]);}catch(PDOException $e){if($e->getCode()==='23000')json_out(['error'=>'This email is already registered.'],409);throw $e;}
}else{
 $password=(string)($d['password']??'');if(strlen($password)<12||strlen($password)>200)json_out(['error'=>'Use a password of 12–200 characters.'],422);
 $pdo->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([password_hash($password,PASSWORD_DEFAULT),$u['id']]);
}
db()->prepare('DELETE FROM app_devices WHERE user_id=?')->execute([$u['id']]);
session_regenerate_id(true);$_SESSION['csrf']=bin2hex(random_bytes(32));audit('profile.'.$action.'_changed',(int)$u['id']);json_out(['ok'=>true,'csrf'=>csrf_token()]);
