<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/app/bootstrap.php';

/**
 * Google OAuth 2.0 sign-in. Additive endpoint: it mirrors the session
 * conventions used by /api/auth.php and never touches the password login flow.
 */

function google_oauth_client(): array {
    $id = defined('TDC_GOOGLE_CLIENT_ID') ? (string)TDC_GOOGLE_CLIENT_ID : (string)envv('TDC_GOOGLE_CLIENT_ID','');
    $secret = defined('TDC_GOOGLE_CLIENT_SECRET') ? (string)TDC_GOOGLE_CLIENT_SECRET : (string)envv('TDC_GOOGLE_CLIENT_SECRET','');
    return [$id, $secret];
}
function google_oauth_redirect_uri(): string {
    return 'https://tdctech.org/api/google-oauth.php';
}
function google_oauth_fail(string $code): never {
    header('Location: /login?error='.$code);
    exit;
}
function google_oauth_http(string $url, ?array $post=null): array {
    $ch=curl_init($url);
    curl_setopt_array($ch,[
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_CONNECTTIMEOUT=>8,
        CURLOPT_TIMEOUT=>20,
        CURLOPT_SSL_VERIFYPEER=>true,
        CURLOPT_SSL_VERIFYHOST=>2,
    ]);
    if($post!==null){
        curl_setopt($ch,CURLOPT_POST,true);
        curl_setopt($ch,CURLOPT_POSTFIELDS,http_build_query($post));
    }
    $raw=curl_exec($ch);
    $status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $data=json_decode((string)$raw,true);
    return [$status, is_array($data)?$data:[]];
}

[$clientId,$clientSecret]=google_oauth_client();
$action=(string)($_GET['action']??'');

if($action==='start'){
    if($clientId===''){ google_oauth_fail('google_failed'); }
    $state=bin2hex(random_bytes(32));
    $_SESSION['g_state']=$state;
    $_SESSION['g_state_time']=time();
    header('Cache-Control: no-store');
    header('Referrer-Policy: no-referrer');
    header('Location: https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query([
        'client_id'=>$clientId,
        'redirect_uri'=>google_oauth_redirect_uri(),
        'response_type'=>'code',
        'scope'=>'openid email profile',
        'state'=>$state,
        'prompt'=>'select_account',
        'access_type'=>'online',
    ]));
    exit;
}

try {
    if($clientId===''||$clientSecret===''){ google_oauth_fail('google_failed'); }

    // Google sends ?error=... when the user cancels, ?code=&state= on success.
    if((string)($_GET['error']??'')!=='' || (string)($_GET['code']??'')===''){ google_oauth_fail('google_failed'); }

    $expected=(string)($_SESSION['g_state']??'');
    $started=(int)($_SESSION['g_state_time']??0);
    unset($_SESSION['g_state'],$_SESSION['g_state_time']);
    $provided=(string)($_GET['state']??'');
    if($expected==='' || !hash_equals($expected,$provided) || $started===0 || time()-$started>600){
        google_oauth_fail('google_state');
    }

    [$status,$tok]=google_oauth_http('https://oauth2.googleapis.com/token',[
        'code'=>(string)$_GET['code'],
        'client_id'=>$clientId,
        'client_secret'=>$clientSecret,
        'redirect_uri'=>google_oauth_redirect_uri(),
        'grant_type'=>'authorization_code',
    ]);
    $idToken=(string)($tok['id_token']??'');
    if($status!==200 || $idToken===''){ google_oauth_fail('google_failed'); }

    [$vStatus,$info]=google_oauth_http('https://oauth2.googleapis.com/tokeninfo?id_token='.rawurlencode($idToken));
    $aud=(string)($info['aud']??'');
    $verified=filter_var($info['email_verified']??false,FILTER_VALIDATE_BOOLEAN);
    $email=strtolower(trim((string)($info['email']??'')));
    $name=trim((string)($info['name']??''));
    if($vStatus!==200 || $aud!==$clientId || !$verified || !filter_var($email,FILTER_VALIDATE_EMAIL)){
        google_oauth_fail('google_failed');
    }

    $pdo=db();
    $q=$pdo->prepare('SELECT id,name,role,status FROM users WHERE email=?');
    $q->execute([$email]);
    $user=$q->fetch();
    if($user){
        if($user['status']!=='active'){ google_oauth_fail('google_inactive'); }
        $uid=(int)$user['id'];
        $role=(string)$user['role'];
    } else {
        $local=explode('@',$email)[0];
        $display=$name!==''?text_cut($name,0,120):text_cut($local,0,120);
        if(trim($display)===''){ $display='TDC Tech User'; }
        $ins=$pdo->prepare("INSERT INTO users(name,email,password_hash,role,status) VALUES(?,?,?,'customer','active')");
        try {
            $ins->execute([$display,$email,password_hash(bin2hex(random_bytes(32)),PASSWORD_DEFAULT)]);
            $uid=(int)$pdo->lastInsertId();
        } catch (PDOException $e) {
            // Account created concurrently between the SELECT and the INSERT.
            if((string)$e->getCode()!=='23000'){ throw $e; }
            $q=$pdo->prepare('SELECT id,role,status FROM users WHERE email=?');
            $q->execute([$email]);
            $u=$q->fetch();
            if(!$u || $u['status']!=='active'){ google_oauth_fail($u?'google_inactive':'google_failed'); }
            $uid=(int)$u['id'];
        }
        $role='customer';
    }

    session_regenerate_id(true);
    $_SESSION['uid']=$uid;
    $_SESSION['csrf']=bin2hex(random_bytes(32));
    try { audit('account.login',$uid,['via'=>'google']); } catch (Throwable $e) { error_log('Google login audit failed: '.$e->getMessage()); }
    header('Location: '.($role==='admin'?'/admin/':'/dashboard'));
    exit;
} catch (Throwable $e) {
    error_log('Google OAuth failed: '.$e->getMessage());
    google_oauth_fail('google_failed');
}
