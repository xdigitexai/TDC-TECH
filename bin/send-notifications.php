<?php
require dirname(__DIR__).'/app/notifications.php';
$lock=fopen(dirname(__DIR__).'/storage/xmail-worker.lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))exit;
try{$sent=send_notification_batch((int)($argv[1]??30));fwrite(STDOUT,"Sent {$sent} notification email(s).\n");}
catch(Throwable $e){fwrite(STDERR,'Notification worker failed: '.$e->getMessage()."\n");exit(1);}
