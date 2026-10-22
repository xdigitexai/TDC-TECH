<?php
/**
 * TDC Tech — WebAuthn / passkey (Face ID · Touch ID) support.
 * Built on web-auth/webauthn-lib v5.
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../vendor/autoload.php';

use Webauthn\CeremonyStep\CeremonyStepManagerFactory;
use Webauthn\Denormalizer\WebauthnSerializerFactory;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialUserEntity;
use Webauthn\PublicKeyCredentialParameters;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\CredentialRecord;
use Webauthn\TrustPath\EmptyTrustPath;
use Symfony\Component\Uid\Uuid;

const WA_RP_ID   = 'tdctech.org';
const WA_RP_NAME = 'TDC Tech';
const WA_ORIGIN  = 'https://tdctech.org';

function wa_serializer()
{
    static $s = null;
    if ($s === null) {
        $asm = new AttestationStatementSupportManager();
        $s = (new WebauthnSerializerFactory($asm))->create();
    }
    return $s;
}

function wa_managers(): array
{
    static $c = null, $r = null;
    if ($c === null) {
        $f = new CeremonyStepManagerFactory();
        $f->setAllowedOrigins([WA_ORIGIN]);
        $c = $f->creationCeremony();
        $r = $f->requestCeremony();
    }
    return [$c, $r];
}

function wa_b64u(string $bin): string { return rtrim(strtr(base64_encode($bin), '+/', '-_'), '='); }
function wa_unb64u(string $s): string
{
    $s = strtr($s, '-_', '+/');
    $pad = strlen($s) % 4;
    if ($pad) $s .= str_repeat('=', 4 - $pad);
    return base64_decode($s) ?: '';
}

/** Serialise options and drop keys browsers warn about (null authenticatorAttachment). */
function wa_json_options(object $opts): string
{
    $json = wa_serializer()->serialize($opts, 'json');
    $data = json_decode($json, true);
    if (is_array($data) && isset($data['authenticatorSelection']) && is_array($data['authenticatorSelection'])) {
        if (array_key_exists('authenticatorAttachment', $data['authenticatorSelection'])
            && $data['authenticatorSelection']['authenticatorAttachment'] === null) {
            unset($data['authenticatorSelection']['authenticatorAttachment']);
        }
    }
    return json_encode($data, JSON_UNESCAPED_SLASHES) ?: $json;
}

/** Remember the last few pending option sets so retries / second tabs still verify. */
function wa_push_pending(string $key, string $json): void
{
    $list = $_SESSION[$key] ?? [];
    if (is_string($list)) $list = [$list];
    if (!is_array($list)) $list = [];
    $list[] = $json;
    $_SESSION[$key] = array_slice($list, -3);
}

function wa_client_challenge(string $credentialJson): string
{
    $d = json_decode($credentialJson, true);
    $cdj = is_array($d) ? ($d['response']['clientDataJSON'] ?? '') : '';
    if (!is_string($cdj) || $cdj === '') return '';
    $raw = json_decode(wa_unb64u($cdj), true);
    return is_array($raw) ? (string)($raw['challenge'] ?? '') : '';
}

/** Pick the pending options matching the credential's challenge (falls back to the newest). */
function wa_take_pending(string $key, string $credentialJson): ?string
{
    $list = $_SESSION[$key] ?? [];
    unset($_SESSION[$key]);
    if (is_string($list)) $list = [$list];
    if (!is_array($list)) $list = [];
    $list = array_values(array_filter($list, 'is_string'));
    if (!$list) return null;
    $want = wa_client_challenge($credentialJson);
    if ($want !== '') {
        foreach (array_reverse($list) as $json) {
            $opts = json_decode($json, true);
            if (is_array($opts) && (string)($opts['challenge'] ?? '') === $want) return $json;
        }
    }
    return end($list) ?: null;
}

function wa_descriptors(int $userId): array
{
    $q = db()->prepare('SELECT id, transports FROM webauthn_credentials WHERE user_id=?');
    $q->execute([$userId]);
    $out = [];
    foreach ($q->fetchAll() as $row) {
        $t = $row['transports'] ? array_values(array_filter(explode(',', (string)$row['transports']))) : [];
        $out[] = PublicKeyCredentialDescriptor::create('public-key', wa_unb64u($row['id']), $t);
    }
    return $out;
}

function wa_count(int $userId): int
{
    $q = db()->prepare('SELECT COUNT(*) FROM webauthn_credentials WHERE user_id=?');
    $q->execute([$userId]);
    return (int)$q->fetchColumn();
}

/** Build creation options for a logged-in user; returns JSON for the browser. */
function wa_creation_options(array $user): string
{
    $challenge = random_bytes(32);
    $rp  = PublicKeyCredentialRpEntity::create(WA_RP_NAME, WA_RP_ID);
    $ue  = PublicKeyCredentialUserEntity::create($user['email'], (string)$user['id'], $user['name'] ?: $user['email']);
    $pp  = [PublicKeyCredentialParameters::createPk(-7), PublicKeyCredentialParameters::createPk(-257)];
    $sel = AuthenticatorSelectionCriteria::create(
        null,
        AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_PREFERRED,
        AuthenticatorSelectionCriteria::RESIDENT_KEY_REQUIREMENT_PREFERRED
    );
    $opts = new PublicKeyCredentialCreationOptions(
        $rp, $ue, $challenge, $pp, $sel,
        PublicKeyCredentialCreationOptions::ATTESTATION_CONVEYANCE_PREFERENCE_NONE,
        wa_descriptors((int)$user['id']), 60000
    );
    $json = wa_json_options($opts);
    wa_push_pending('wa_creation', $json);
    return $json;
}

/** Validate the attestation response and return the CredentialRecord. */
function wa_verify_creation(string $json): CredentialRecord
{
    $s = wa_serializer();
    $pending = wa_take_pending('wa_creation', $json);
    if ($pending === null) throw new RuntimeException('No pending registration.');
    $opts = $s->deserialize($pending, PublicKeyCredentialCreationOptions::class, 'json');
    $pkc  = $s->deserialize($json, PublicKeyCredential::class, 'json');
    if (!$pkc->response instanceof \Webauthn\AuthenticatorAttestationResponse) {
        throw new RuntimeException('Unexpected response.');
    }
    [$cm] = wa_managers();
    $v = AuthenticatorAttestationResponseValidator::create($cm);
    return $v->check($pkc->response, $opts, WA_RP_ID);
}

/** Build request options (assertion); discovers credentials for a user, or usernameless when $userId=0. */
function wa_request_options(int $userId = 0): ?string
{
    $descs = $userId > 0 ? wa_descriptors($userId) : [];
    if ($userId > 0 && !$descs) return null;
    $challenge = random_bytes(32);
    $uv = PublicKeyCredentialRequestOptions::USER_VERIFICATION_REQUIREMENT_PREFERRED;
    $opts = new PublicKeyCredentialRequestOptions($challenge, WA_RP_ID, $descs, $uv, 60000);
    $json = wa_json_options($opts);
    wa_push_pending('wa_request', $json);
    return $json;
}

/** Resolve the owning user of a credential id (base64url), for usernameless sign-in. */
function wa_user_for_credential(string $rawIdB64u): ?int
{
    $q = db()->prepare('SELECT user_id FROM webauthn_credentials WHERE id=?');
    $q->execute([$rawIdB64u]);
    $uid = $q->fetchColumn();
    return $uid === false ? null : (int)$uid;
}

/** Validate an assertion and return the updated CredentialRecord. */
function wa_verify_assertion(string $json, int $userId): CredentialRecord
{
    $s = wa_serializer();
    $pending = wa_take_pending('wa_request', $json);
    if ($pending === null) throw new RuntimeException('No pending sign-in.');
    $opts = $s->deserialize($pending, PublicKeyCredentialRequestOptions::class, 'json');
    $pkc  = $s->deserialize($json, PublicKeyCredential::class, 'json');
    if (!$pkc->response instanceof \Webauthn\AuthenticatorAssertionResponse) {
        throw new RuntimeException('Unexpected response.');
    }
    $id = wa_b64u($pkc->rawId);
    $q = db()->prepare('SELECT * FROM webauthn_credentials WHERE id=? AND user_id=?');
    $q->execute([$id, $userId]);
    $c = $q->fetch();
    if (!$c) throw new RuntimeException('Unknown passkey.');

    $record = new CredentialRecord(
        wa_unb64u($c['id']),
        'public-key',
        $c['transports'] ? array_values(array_filter(explode(',', (string)$c['transports']))) : [],
        'none',
        new EmptyTrustPath(),
        Uuid::fromString('00000000-0000-0000-0000-000000000000'),
        wa_unb64u($c['public_key']),
        (string)$userId,
        (int)$c['sign_count']
    );
    [, $rm] = wa_managers();
    $v = AuthenticatorAssertionResponseValidator::create($rm);
    $updated = $v->check($record, $pkc->response, $opts, WA_RP_ID, (string)$userId);
    db()->prepare('UPDATE webauthn_credentials SET sign_count=?, last_used_at=UTC_TIMESTAMP() WHERE id=?')
        ->execute([$updated->counter, $id]);
    return $updated;
}

/** Store a validated registration. */
function wa_store(int $userId, CredentialRecord $rec, ?string $label = null): void
{
    $id = wa_b64u($rec->publicKeyCredentialId);
    db()->prepare(
        'INSERT INTO webauthn_credentials(id,user_id,public_key,sign_count,transports,label,last_used_at)
         VALUES(?,?,?,?,?,?,UTC_TIMESTAMP())
         ON DUPLICATE KEY UPDATE public_key=VALUES(public_key), sign_count=VALUES(sign_count), transports=VALUES(transports)'
    )->execute([
        $id, $userId, wa_b64u($rec->credentialPublicKey), max(0, (int)$rec->counter),
        $rec->transports ? implode(',', $rec->transports) : null,
        $label ? mb_substr($label, 0, 100) : null,
    ]);
}
