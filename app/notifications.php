<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';
function queue_email(string $to,string $subject,string $body,?int $userId=null): void {
  if(tdc_email_suppressed($to)||!filter_var($to,FILTER_VALIDATE_EMAIL)||preg_match('/[\r\n]/',$subject))return;
  $q=db()->prepare("INSERT INTO notification_outbox(user_id,recipient_email,subject,body_text,status,created_at,updated_at) VALUES(?,?,?,?,'queued',UTC_TIMESTAMP(),UTC_TIMESTAMP())");$q->execute([$userId,$to,text_cut($subject,0,250),text_cut($body,0,20000)]);
}
function smtp_setting(string $name,string $default=''): string {$value=defined($name)?constant($name):envv($name,$default);return trim((string)$value);}
function smtp_configured(): bool {return smtp_setting('TDC_SMTP_HOST')!==''&&smtp_setting('TDC_SMTP_USER')!==''&&smtp_setting('TDC_SMTP_PASS')!==''&&filter_var(smtp_setting('TDC_SMTP_FROM'),FILTER_VALIDATE_EMAIL)!==false;}
function smtp_read($socket,array $expected): string {
  $response='';while(($line=fgets($socket,515))!==false){$response.=$line;if(strlen($line)>=4&&$line[3]===' ')break;}
  
$code=(int)substr($response,0,3);
  
if(!in_array($code,$expected,true)) {
  
    throw new RuntimeException('SMTP server rejected a command: ' . (($response === '') ? '(empty response)' : trim($response)));
  
}
  
return $response;
}
function smtp_command($socket,string $command,array $expected): string {if(fwrite($socket,$command."\r\n")===false)throw new RuntimeException('SMTP connection failed.');return smtp_read($socket,$expected);}
function smtp_send(string $to,string $subject,string $body): void {
  if(tdc_email_suppressed($to))throw new RuntimeException('Recipient suppressed after prior bounce.');
  $host=smtp_setting('TDC_SMTP_HOST');$port=(int)smtp_setting('TDC_SMTP_PORT','587');$user=smtp_setting('TDC_SMTP_USER');$pass=smtp_setting('TDC_SMTP_PASS');$secure=strtolower(smtp_setting('TDC_SMTP_ENCRYPTION','tls'));$from=smtp_setting('TDC_SMTP_FROM');$fromName=smtp_setting('TDC_SMTP_FROM_NAME','TDC Tech');
  if($host===''||$port<1||$user===''||$pass===''||!filter_var($from,FILTER_VALIDATE_EMAIL))throw new RuntimeException('SMTP settings are not configured.');
  if(!in_array($secure,['tls','ssl'],true))throw new RuntimeException('SMTP encryption must be TLS or SSL.');
  if(preg_match('/[\r\n]/',$host.$user.$from.$fromName)||!filter_var($to,FILTER_VALIDATE_EMAIL))throw new RuntimeException('Invalid email address.');
  $context=stream_context_create(['ssl'=>['verify_peer'=>true,'verify_peer_name'=>true,'allow_self_signed'=>false]]);$target=($secure==='ssl'?'ssl://':'').$host.':'.$port;$socket=@stream_socket_client($target,$errno,$errstr,15,STREAM_CLIENT_CONNECT,$context);
  if(!$socket)throw new RuntimeException('SMTP server connection failed.');stream_set_timeout($socket,15);
  try{
    smtp_read($socket,[220]);$ehlo=gethostname()?:'localhost';smtp_command($socket,'EHLO '.$ehlo,[250]);
    if($secure==='tls'){smtp_command($socket,'STARTTLS',[220]);if(!stream_socket_enable_crypto($socket,true,STREAM_CRYPTO_METHOD_TLS_CLIENT))throw new RuntimeException('SMTP TLS negotiation failed.');smtp_command($socket,'EHLO '.$ehlo,[250]);}
    smtp_command($socket,'AUTH LOGIN',[334]);smtp_command($socket,base64_encode($user),[334]);smtp_command($socket,base64_encode($pass),[235]);
    smtp_command($socket,'MAIL FROM:<'.$from.'>',[250]);smtp_command($socket,'RCPT TO:<'.$to.'>',[250,251]);smtp_command($socket,'DATA',[354]);
    $encodedSubject='=?UTF-8?B?'.base64_encode($subject).'?=';$safeName='=?UTF-8?B?'.base64_encode($fromName).'?=';$date=gmdate('D, d M Y H:i:s').' +0000';
    $message='Date: '.$date."\r\nFrom: ".$safeName.' <'.$from.">\r\nTo: <".$to.">\r\nSubject: ".$encodedSubject."\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n";
    $text=str_replace(["\r\n","\r"],"\n",$body);$message.=preg_replace('/^\./m','..',$text);$message=preg_replace('/(?<!\r)\n/','\r\n',$message);fwrite($socket,$message."\r\n.\r\n");smtp_read($socket,[250]);smtp_command($socket,'QUIT',[221]);
  }finally{fclose($socket);}
}
function send_notification_batch(int $limit=30): int {
  if(PHP_SAPI!=='cli')throw new RuntimeException('Notification worker is CLI-only.');if(is_file(__DIR__.'/../storage/mail-delivery.disabled')||!smtp_configured())return 0;$limit=max(1,min(100,$limit));$sent=0;
  db()->exec("UPDATE notification_outbox SET status='queued' WHERE status='sending' AND updated_at<UTC_TIMESTAMP()-INTERVAL 15 MINUTE");
  for($i=0;$i<$limit;$i++){
    $pdo=db();$pdo->beginTransaction();$q=$pdo->query("SELECT * FROM notification_outbox WHERE status='queued' ORDER BY id LIMIT 1 FOR UPDATE");$row=$q->fetch();if(!$row){$pdo->commit();break;}$pdo->prepare("UPDATE notification_outbox SET status='sending',attempts=attempts+1 WHERE id=?")->execute([$row['id']]);$pdo->commit();
    try{smtp_send($row['recipient_email'],$row['subject'],$row['body_text']);$pdo->prepare("UPDATE notification_outbox SET status='sent',sent_at=UTC_TIMESTAMP(),last_error=NULL WHERE id=?")->execute([$row['id']]);$sent++;}
    catch(Throwable $e){$pdo->prepare("UPDATE notification_outbox SET status='failed',last_error=? WHERE id=?")->execute([text_cut($e->getMessage(),0,500),$row['id']]);error_log('TDC notification email failed (id '.$row['id'].'): '.$e->getMessage());}
  }
  return $sent;
}

function notify_successful_login(int $uid): void {
  try {
    $q=db()->prepare('SELECT email,name FROM users WHERE id=?');$q->execute([$uid]);$u=$q->fetch();
    if($u)queue_email($u['email'],'New sign-in to TDC Tech',"Hello ".$u['name'].",\n\nA successful sign-in to your TDC Tech account occurred at ".gmdate('Y-m-d H:i:s')." UTC.\n\nIf this was not you, change your password and contact support.\nhttps://tdctech.org/profile",$uid);
  } catch(Throwable $e){error_log('TDC login notification could not be queued.');}
}

function tdc_email_suppressed(string $email): bool {
  static $hashes=null;
  if($hashes===null)$hashes=json_decode(file_get_contents(__DIR__.'/../storage/mail-suppressions.json'),true)??[];
  return in_array(hash('sha256',strtolower(trim($email))),$hashes,true);
}
