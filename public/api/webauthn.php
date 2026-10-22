<?php
/**
 * TDC Tech — WebAuthn / passkey endpoints (Face ID · Touch ID · Windows Hello).
 * Actions: register_options | register_verify | login_options | login_verify | list | delete
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/webauthn.php';
require_once dirname(__DIR__, 2) . '/app/notifications.php';

require_method('POST');
$body = json_body();
$action = (string)($body['action'] ?? '');

function wa_fail(Throwable $e): never
{
    error_log('WebAuthn: ' . $e->getMessage());
    json_out(['error' => $e->getMessage()], 400);
}

switch ($action) {

    case 'register_options': {
        require_csrf();
        $u = require_user();
        try {
            $json = wa_creation_options($u);
        } catch (Throwable $e) {
            wa_fail($e);
        }
        json_out(['ok' => true, 'options' => json_decode($json, true), 'csrf' => csrf_token()]);
    }

    case 'register_verify': {
        require_csrf();
        $u = require_user();
        $cred = $body['credential'] ?? null;
        if (!is_array($cred)) json_out(['error' => 'Missing credential.'], 422);
        try {
            $rec = wa_verify_creation(json_encode($cred));
            $label = isset($body['label']) ? (string)$body['label'] : null;
            wa_store((int)$u['id'], $rec, $label);
        } catch (Throwable $e) {
            wa_fail($e);
        }
        audit('account.passkey_added', (int)$u['id']);
        json_out(['ok' => true, 'csrf' => csrf_token()]);
    }

    case 'login_options': {
        require_csrf();
        $email = trim((string)($body['email'] ?? ''));
        $uid = 0;
        if ($email !== '') {
            $q = db()->prepare('SELECT id FROM users WHERE email=? AND status="active"');
            $q->execute([$email]);
            $uid = (int)$q->fetchColumn();
        }
        $useUid = ($uid > 0 && wa_count($uid) > 0) ? $uid : 0;
        try {
            $json = wa_request_options($useUid);
        } catch (Throwable $e) {
            wa_fail($e);
        }
        if (!$json) json_out(['error' => 'No passkey is set up for this account.'], 404);
        json_out(['ok' => true, 'options' => json_decode($json, true), 'csrf' => csrf_token()]);
    }

    case 'login_verify': {
        require_csrf();
        $email = trim((string)($body['email'] ?? ''));
        $cred = $body['credential'] ?? null;
        if (!is_array($cred)) json_out(['error' => 'Missing credential.'], 422);
        if ($email !== '') {
            $q = db()->prepare('SELECT id,name,email,role,status FROM users WHERE email=?');
            $q->execute([$email]);
            $u = $q->fetch();
        } else {
            $rawId = (string)($cred['rawId'] ?? '');
            $uid = $rawId !== '' ? (wa_user_for_credential($rawId) ?? 0) : 0;
            if (!$uid) json_out(['error' => 'This passkey is not registered. Sign in with your email and password.'], 401);
            $q = db()->prepare('SELECT id,name,email,role,status FROM users WHERE id=?');
            $q->execute([$uid]);
            $u = $q->fetch();
        }
        if (!$u || $u['status'] !== 'active') json_out(['error' => 'Unable to sign in with this passkey.'], 401);
        try {
            wa_verify_assertion(json_encode($cred), (int)$u['id']);
        } catch (Throwable $e) {
            wa_fail($e);
        }
        session_regenerate_id(true);
        $_SESSION['uid'] = (int)$u['id'];
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        unset($_SESSION['wa_request'], $_SESSION['wa_creation']);
        audit('account.login', (int)$u['id'], ['method' => 'passkey']);
        notify_successful_login((int)$u['id']);
        json_out(['ok' => true, 'redirect' => $u['role'] === 'admin' ? '/admin/' : '/dashboard', 'csrf' => csrf_token()]);
    }

    case 'list': {
        $u = require_user();
        $q = db()->prepare('SELECT id,label,created_at,last_used_at FROM webauthn_credentials WHERE user_id=? ORDER BY created_at DESC');
        $q->execute([(int)$u['id']]);
        json_out(['ok' => true, 'passkeys' => $q->fetchAll(), 'csrf' => csrf_token()]);
    }

    case 'delete': {
        require_csrf();
        $u = require_user();
        $id = (string)($body['id'] ?? '');
        db()->prepare('DELETE FROM webauthn_credentials WHERE id=? AND user_id=?')->execute([$id, (int)$u['id']]);
        audit('account.passkey_removed', (int)$u['id']);
        json_out(['ok' => true, 'csrf' => csrf_token()]);
    }

    default:
        json_out(['error' => 'Unknown action.'], 400);
}
