<?php
require dirname(__DIR__,2).'/app/bootstrap.php';
require_method('POST');
require_csrf();
require_once dirname(__DIR__,2).'/app/auth_codes.php';
$data=json_body();
$action=(string)($data['action']??'');
if (!email_code_step_enabled() && in_array($action,['verify_code','resend_code'],true)) {
    unset($_SESSION['pending_uid'],$_SESSION['pending_purpose'],$_SESSION['pending_redirect']);
    json_out(['error'=>'Email verification is disabled. Sign in with your email and password.','redirect'=>'/login'],409);
}

/**
 * Park a verified password behind an emailed code instead of issuing a session.
 * The user is not authenticated until verify_code succeeds.
 */
function begin_code_step(int $uid,string $purpose,string $email,string $name,string $redirect): never {
    issue_email_code($uid,$purpose,$email,$name);
    $_SESSION['pending_uid']=$uid;
    $_SESSION['pending_purpose']=$purpose;
    $_SESSION['pending_redirect']=$redirect;
    audit('account.code_issued',$uid,['purpose'=>$purpose]);
    json_out(['ok'=>true,'step'=>'code','redirect'=>'/verify','csrf'=>csrf_token()]);
}

if ($action==='register') {
    $name=trim((string)($data['name']??''));
    $email=strtolower(trim((string)($data['email']??'')));
    $password=(string)($data['password']??'');
    if (text_len($name)<2 || text_len($name)>120 || !filter_var($email,FILTER_VALIDATE_EMAIL) || strlen($password)<12 || strlen($password)>200) json_out(['error'=>'Enter a valid name and email. Password must be at least 12 characters.'],422);
    try {
        $q=db()->prepare("INSERT INTO users(name,email,password_hash) VALUES(?,?,?)");
        $q->execute([$name,$email,password_hash($password,PASSWORD_DEFAULT)]);
        $uid=(int)db()->lastInsertId();
    } catch (PDOException $e) {
        if ((string)$e->getCode()==='23000') json_out(['error'=>'An account with this email already exists.'],409);
        throw $e;
    }
    audit('account.registered',$uid);
    try { queue_email($email,'Welcome to TDC Tech','Hello '.$name.",\n\nYour account is ready. Sign in to manage your services, orders, and wallet.",$uid); } catch(Throwable $e) { error_log('Welcome email could not be queued: '.$e->getMessage()); }
    if (email_code_step_enabled()) {
        begin_code_step($uid,'register',$email,$name,'/dashboard');
    }
    unset($_SESSION['pending_uid'],$_SESSION['pending_purpose'],$_SESSION['pending_redirect']);
    session_regenerate_id(true); $_SESSION['uid']=$uid; $_SESSION['csrf']=bin2hex(random_bytes(32));
    json_out(['ok'=>true,'redirect'=>'/dashboard','csrf'=>csrf_token()]);
}

if (in_array($action,['login','admin_login'],true)) {
    $adminLogin=$action==='admin_login';
    $email=strtolower(trim((string)($data['email']??'')));
    $password=(string)($data['password']??'');
    if (!filter_var($email,FILTER_VALIDATE_EMAIL) || $password==='') json_out(['error'=>'Invalid email or password.'],422);
    $ip=hash('sha256',(string)($_SERVER['REMOTE_ADDR']??'')); $eh=hash('sha256',$email);
    db()->prepare('INSERT INTO login_attempt_limits(email_hash,ip_hash,attempt_count,window_started) VALUES(?,?,1,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE attempt_count=IF(window_started<UTC_TIMESTAMP()-INTERVAL 15 MINUTE,1,attempt_count+1),window_started=IF(window_started<UTC_TIMESTAMP()-INTERVAL 15 MINUTE,UTC_TIMESTAMP(),window_started)')->execute([$eh,$ip]);
    $q=db()->prepare('SELECT attempt_count FROM login_attempt_limits WHERE email_hash=? AND ip_hash=?');$q->execute([$eh,$ip]);
    if((int)$q->fetchColumn()>8)json_out(['error'=>'Too many attempts. Wait 15 minutes and try again.'],429);
    $q=db()->prepare('SELECT id,password_hash,status,role FROM users WHERE email=?'); $q->execute([$email]); $u=$q->fetch();
    if (!$u || $u['status']!=='active' || !password_verify($password,$u['password_hash']) || ($adminLogin && $u['role']!=='admin')) json_out(['error'=>'Invalid email or password.'],401);
    db()->prepare('DELETE FROM login_attempt_limits WHERE email_hash=? AND ip_hash=?')->execute([$eh,$ip]);
    if (password_needs_rehash($u['password_hash'],PASSWORD_DEFAULT)) db()->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([password_hash($password,PASSWORD_DEFAULT),$u['id']]);
    $redirect=$u['role']==='admin'?'/admin/':'/dashboard';
    if (email_code_applies_to((string)$u['role'],$email)) {
        begin_code_step((int)$u['id'],'login',$email,'',$redirect);
    }
    unset($_SESSION['pending_uid'],$_SESSION['pending_purpose'],$_SESSION['pending_redirect']);
    session_regenerate_id(true); $_SESSION['uid']=(int)$u['id']; $_SESSION['csrf']=bin2hex(random_bytes(32));
    audit('account.login',(int)$u['id']);
    notify_successful_login((int)$u['id']);
    json_out(['ok'=>true,'redirect'=>$redirect,'csrf'=>csrf_token()]);
}

if ($action==='verify_code') {
    $code=preg_replace('/\D/','',(string)($data['code']??''));
    $uid=(int)($_SESSION['pending_uid']??0);
    $purpose=(string)($_SESSION['pending_purpose']??'');
    $redirect=(string)($_SESSION['pending_redirect']??'/dashboard');
    if (!$uid || !in_array($purpose,['login','register'],true)) json_out(['error'=>'Your sign-in session expired. Enter your details again.'],419);
    if (!email_code_is_six_digits($code)) json_out(['error'=>'Enter the 6-digit code from your email.'],422);
    if (!verify_email_code($uid,$purpose,$code)) {
        audit('account.code_failed',$uid,['purpose'=>$purpose]);
        json_out(['error'=>'That code is incorrect or has expired. Request a new one.'],401);
    }
    $q=db()->prepare('SELECT id,role,status FROM users WHERE id=?'); $q->execute([$uid]); $u=$q->fetch();
    if (!$u || $u['status']!=='active') json_out(['error'=>'This account is not active.'],403);
    if ($purpose==='register') mark_email_verified($uid);
    session_regenerate_id(true);
    $_SESSION['uid']=(int)$u['id'];
    $_SESSION['csrf']=bin2hex(random_bytes(32));
    unset($_SESSION['pending_uid'],$_SESSION['pending_purpose'],$_SESSION['pending_redirect']);
    audit('account.code_verified',(int)$u['id'],['purpose'=>$purpose]);
    if($purpose==='login')notify_successful_login((int)$u['id']);
    json_out(['ok'=>true,'redirect'=>$u['role']==='admin'?'/admin/':$redirect,'csrf'=>csrf_token()]);
}

if ($action==='resend_code') {
    $uid=(int)($_SESSION['pending_uid']??0);
    $purpose=(string)($_SESSION['pending_purpose']??'');
    if (!$uid || !in_array($purpose,['login','register'],true)) json_out(['error'=>'Your sign-in session expired. Enter your details again.'],419);
    if (email_code_resend_throttled($uid,$purpose)) json_out(['error'=>'A code was just sent. Wait a moment before requesting another.'],429);
    $q=db()->prepare('SELECT name,email FROM users WHERE id=?'); $q->execute([$uid]); $u=$q->fetch();
    if (!$u) json_out(['error'=>'Account not found.'],404);
    issue_email_code($uid,$purpose,(string)$u['email'],(string)$u['name']);
    audit('account.code_resent',$uid,['purpose'=>$purpose]);
    json_out(['ok'=>true,'step'=>'code']);
}

if ($action==='logout') {
    if(!empty($_COOKIE['tdc_device'])){db()->prepare('DELETE FROM app_devices WHERE token_hash=?')->execute([hash('sha256',(string)$_COOKIE['tdc_device'])]);setcookie('tdc_device','',['expires'=>time()-3600,'path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Strict']);}
    $uid=(int)($_SESSION['uid']??0); if($uid) audit('account.logout',$uid);
    $_SESSION=[]; if(ini_get('session.use_cookies')) { $p=session_get_cookie_params(); setcookie(session_name(),'',time()-42000,$p['path'],$p['domain']??'',(bool)$p['secure'],(bool)$p['httponly']); }
    session_destroy(); json_out(['ok'=>true,'redirect'=>'/']);
}
json_out(['error'=>'Unknown action.'],400);
