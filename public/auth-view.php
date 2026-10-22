<?php
require dirname(__DIR__).'/app/bootstrap.php';
$mode=basename($_SERVER['SCRIPT_NAME'])==='register.php'?'register':'login';
$title=$mode==='register'?'Create your account':'Welcome back';
header('Content-Type: text/html; charset=utf-8'); header('X-Content-Type-Options: nosniff');
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=htmlspecialchars($title)?> · TDC Tech</title><link rel="preconnect" href="https://fonts.googleapis.com"><link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet"><link rel="stylesheet" href="/assets/auth.css"><link rel="manifest" href="/app/manifest.webmanifest"><meta name="theme-color" content="#101116"><link rel="apple-touch-icon" href="/app/icon.png"><script src="/assets/install-app.js?v=20261011-master" defer></script><link rel="stylesheet" href="/assets/site-theme.css?v=20261011-master"><script src="/assets/site-icons.js?v=20261011-master" defer></script><script src="/assets/branding.js?v=20261011-master" defer></script><script src="/assets/google-login.js?v=20261011-master" defer></script></head><body><main class="auth"><a class="back" href="/">← TDC Tech</a><div class="brand"><span class="mark"><svg viewBox="0 0 24 24"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg></span>TDC Tech</div><h1><?=htmlspecialchars($title)?></h1><p><?=$mode==='register'?'Create an account to manage your services, orders, and wallet.':'Sign in to continue to your TDC Tech dashboard.'?></p><form id="auth-form" novalidate><?php if($mode==='register'):?><div class="field"><label for="name">Full name</label><input id="name" name="name" autocomplete="name" required maxlength="120"></div><?php endif;?><div class="field"><label for="email">Email</label><input id="email" name="email" type="email" autocomplete="<?=$mode==='register'?'email':'username webauthn'?>" required maxlength="254"></div><div class="field"><label for="password">Password</label><input id="password" name="password" type="password" autocomplete="<?=$mode==='register'?'new-password':'current-password'?>" required minlength="<?=$mode==='register'?'12':'1'?>" maxlength="200"><?php if($mode==='register'):?><small style="display:block;color:#71717a;margin-top:6px">Use at least 12 characters.</small><?php endif;?></div><div class="message" id="message" role="alert"></div><button class="submit" id="submit" type="submit"><?=$mode==='register'?'Create account':'Sign in'?></button></form><?php if(defined('TDC_GOOGLE_CLIENT_ID') && TDC_GOOGLE_CLIENT_ID!==''):?><div style="display:flex;align-items:center;gap:12px;margin:20px 0"><span style="flex:1;height:1px;background:var(--border)"></span><span style="color:var(--muted);font-size:.76rem;letter-spacing:.1em;font-weight:700">OR</span><span style="flex:1;height:1px;background:var(--border)"></span></div><a data-google-oauth href="/api/google-oauth.php?action=start" style="display:flex;align-items:center;justify-content:center;gap:10px;width:100%;padding:13px;border:1px solid var(--border);border-radius:10px;background:#fff;color:#18181b;font:inherit;font-weight:700;text-decoration:none;cursor:pointer"><svg width="18" height="18" viewBox="0 0 48 48" aria-hidden="true"><path fill="#ea4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/><path fill="#4285f4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/><path fill="#fbbc05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/><path fill="#34a853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/></svg>Continue with Google</a><?php endif;?><?php if($mode==='login'):?><div id="passkey-sep" style="display:none;align-items:center;gap:12px;margin:20px 0"><span style="flex:1;height:1px;background:var(--border)"></span><span style="color:var(--muted);font-size:.76rem;letter-spacing:.1em;font-weight:700">OR</span><span style="flex:1;height:1px;background:var(--border)"></span></div><button type="button" id="passkey-btn" style="display:none;align-items:center;justify-content:center;gap:10px;width:100%;padding:13px;border:1px solid var(--border);border-radius:10px;background:var(--card);color:var(--text);font:inherit;font-weight:700;cursor:pointer">🔑 Sign in with a passkey</button><p id="passkey-note" style="display:none;margin:12px 0 0;color:var(--muted);font-size:.78rem;line-height:1.5">Biometric sign-in isn’t available on this device. Use your email and password.</p><?php endif;?>
<div class="foot"><?=$mode==='register'?'Already have an account? <a href="/login">Sign in</a>':'New to TDC Tech? <a href="/register">Create account</a>'?></div><?php if($mode==='login'):?><div class="foot"><a href="/admin/login">Administrator sign in</a></div><?php endif;?></main><script>
const form=document.getElementById('auth-form'),msg=document.getElementById('message'),btn=document.getElementById('submit');
form.addEventListener('submit',async e=>{e.preventDefault();msg.textContent='';btn.disabled=true;try{const token=await fetch('/api/csrf.php',{credentials:'same-origin'}).then(r=>r.json());const payload={action:<?=json_encode($mode)?>,email:form.email.value,password:form.password.value};<?php if($mode==='register'):?>payload.name=form.name.value;<?php endif;?>const r=await fetch('/api/auth.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':token.csrf},body:JSON.stringify(payload)});const data=await r.json();if(!r.ok)throw new Error(data.error||'Unable to continue.');location.href=data.redirect;}catch(e){msg.textContent=e.message||'Unable to continue.';}finally{btn.disabled=false;}});
<?php if($mode==='login'):?>
function pkB(s){s=s.replace(/-/g,'+').replace(/_/g,'/');while(s.length%4)s+='=';const b=atob(s),o=new Uint8Array(b.length);for(let i=0;i<b.length;i++)o[i]=b.charCodeAt(i);return o}
function pkU(buf){const b=new Uint8Array(buf);let s='';for(let i=0;i<b.length;i++)s+=String.fromCharCode(b[i]);return btoa(s).replace(/\+/g,'-').replace(/\//g,'_').replace(/=+$/,'')}
const pkBtn=document.getElementById('passkey-btn');
const pkSep=document.getElementById('passkey-sep');
const pkNote=document.getElementById('passkey-note');
let pkToken=null,pkCond=null,pkBusy=false;
const pkCsrf=async(force)=>{if(pkToken&&!force)return pkToken;const t=await fetch('/api/csrf.php',{credentials:'same-origin',cache:'no-store'}).then(r=>r.json());return pkToken=t.csrf;};
const pkCall=async(p,token,retried)=>{
  const r=await fetch('/api/webauthn.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':token},body:JSON.stringify(p)});
  const d=await r.json().catch(()=>({}));
  if(r.status===419&&!retried){const fresh=await pkCsrf(true);return pkCall(p,fresh,true);}
  if(!r.ok)throw new Error(d.error||'Unable to continue.');
  return d;
};
const pkCred=c=>({id:c.id,rawId:pkU(c.rawId),type:c.type,response:{clientDataJSON:pkU(c.response.clientDataJSON),authenticatorData:pkU(c.response.authenticatorData),signature:pkU(c.response.signature),userHandle:c.response.userHandle?pkU(c.response.userHandle):null}});
const pkShow=on=>{if(pkBtn)pkBtn.style.display=on?'flex':'none';if(pkSep)pkSep.style.display=on?'flex':'none';if(pkNote)pkNote.style.display=on?'none':'block';};
const pkAbortCond=()=>{if(pkCond){try{pkCond.abort()}catch(_e){}pkCond=null;}};
async function pkPlatformReady(){
  if(!window.PublicKeyCredential)return false;
  if(!PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable)return false;
  try{return !!(await PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable())}catch(_e){return false}
}
async function pkSignIn(email){
  if(!window.PublicKeyCredential)throw new Error('This browser does not support passkeys. Use your password instead.');
  const token=await pkCsrf();
  const start=await pkCall(email?{action:'login_options',email}:{action:'login_options'},token);
  const o=start.options;o.challenge=pkB(o.challenge);(o.allowCredentials||[]).forEach(c=>c.id=pkB(c.id));
  const cred=await navigator.credentials.get({publicKey:o});
  if(!cred)return;
  const done=await pkCall(email?{action:'login_verify',email,credential:pkCred(cred)}:{action:'login_verify',credential:pkCred(cred)},token);
  location.href=done.redirect;
}
pkBtn.addEventListener('click',async()=>{
  if(pkBusy)return;
  pkBusy=true;pkAbortCond();
  msg.textContent='';pkBtn.disabled=true;
  try{await pkSignIn(form.email.value.trim());}
  catch(e){
    msg.textContent=(e&&e.name==='NotAllowedError')
      ?'Passkey sign-in was cancelled or no passkey is available on this device. Use your password instead.'
      :((e&&e.message)||'Unable to continue.');
  }
  finally{pkBusy=false;pkBtn.disabled=false;}
});
// Only offer the button when this device can actually do biometrics.
pkPlatformReady().then(ok=>pkShow(ok)).catch(()=>pkShow(false));
// Conditional UI: surface saved passkeys in the browser account picker without blocking the form.
(async()=>{
  try{
    if(!(await pkPlatformReady()))return;
    if(!PublicKeyCredential.isConditionalMediationAvailable)return;
    if(!(await PublicKeyCredential.isConditionalMediationAvailable()))return;
    pkToken=await pkCsrf();
    const start=await pkCall({action:'login_options'},pkToken);
    const o=start.options;o.challenge=pkB(o.challenge);
    pkCond=new AbortController();
    const cred=await navigator.credentials.get({publicKey:o,mediation:'conditional',signal:pkCond.signal});
    pkCond=null;
    if(!cred||pkBusy)return;
    pkBusy=true;
    const done=await pkCall({action:'login_verify',credential:pkCred(cred)},pkToken);
    location.href=done.redirect;
  }catch(_e){/* AbortError when the button is used, or unsupported — the form still works */}
})();
<?php endif;?>
</script></body></html>
