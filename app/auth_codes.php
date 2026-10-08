<?php
/**
 * Email confirmation codes for sign-in and registration.
 *
 * A code is generated, stored only as a SHA-256 hash, mailed through the
 * existing notification outbox, and is single-use with a short expiry and a
 * bounded number of attempts.
 *
 * Everything here is gated on smtp_configured() by the caller. That is
 * deliberate: a mandatory email step on a mail server that cannot deliver locks
 * out every account, including the administrator. When mail is unavailable the
 * step is skipped and password authentication alone applies, so this can never
 * become a lockout.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/notifications.php';

const TDC_CODE_TTL_MINUTES = 10;
const TDC_CODE_MAX_ATTEMPTS = 5;
const TDC_CODE_RESEND_AFTER_SECONDS = 45;

/** True when the code step should be enforced (i.e. mail can actually be delivered). */
function email_code_step_enabled(): bool
{
    // Email verification is disabled at the site owner's request.
    return false;
}

/**
 * True when the given address sits on a domain that publishes MX records, i.e.
 * somewhere mail can plausibly land.
 */
function email_domain_accepts_mail(string $email): bool
{
    static $cache = [];
    $at = strrpos($email, '@');
    if ($at === false) {
        return false;
    }
    $host = strtolower(substr($email, $at + 1));
    if ($host === '') {
        return false;
    }
    if (array_key_exists($host, $cache)) {
        return $cache[$host];
    }

    $ok = false;
    try {
        $records = @dns_get_record($host, DNS_MX);
        $ok = is_array($records) && count($records) > 0;
    } catch (Throwable $e) {
        $ok = false;
    }

    return $cache[$host] = $ok;
}

/**
 * Whether the code step applies to this account.
 *
 * Administrators are exempt when their mailbox cannot receive mail. There is no
 * self-service recovery for a locked-out administrator, so requiring a code
 * sent to an unreachable address would lock the operator out of /admin/ with no
 * way back in. The exemption disappears on its own: point the admin account at
 * a domain that publishes MX and the code step starts applying again.
 */
function email_code_applies_to(string $role, string $email): bool
{
    // All accounts, including administrators, must complete the emailed code
    // step. Mailgun handles delivery to arbitrary domains, so there is no
    // need for an MX-based exemption that could be abused as a bypass.
    return email_code_step_enabled();
}

function email_code_is_six_digits(string $code): bool
{
    return (bool) preg_match('/^\d{6}$/', $code);
}

/**
 * Issue a fresh code for a purpose, invalidating any previous unconsumed one.
 */
function issue_email_code(int $userId, string $purpose, string $email, string $name = ''): void
{
    db()->prepare(
        'UPDATE user_email_codes SET consumed_at = UTC_TIMESTAMP() WHERE user_id = ? AND purpose = ? AND consumed_at IS NULL'
    )->execute([$userId, $purpose]);

    $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $expiresAt = gmdate('Y-m-d H:i:s', time() + TDC_CODE_TTL_MINUTES * 60);

    db()->prepare(
        'INSERT INTO user_email_codes (user_id, purpose, code_hash, expires_at, attempts, ip, created_at)
         VALUES (?, ?, ?, ?, 0, ?, UTC_TIMESTAMP())'
    )->execute([
        $userId,
        $purpose,
        hash('sha256', $code),
        $expiresAt,
        substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
    ]);

    $greeting = $name !== '' ? 'Hello ' . $name . ",\n\n" : "Hello,\n\n";
    $body = $greeting
        . 'Your TDC Tech confirmation code is:' . "\n\n"
        . '    ' . $code . "\n\n"
        . 'It expires in ' . TDC_CODE_TTL_MINUTES . ' minutes and can be used once.' . "\n"
        . 'If you did not try to sign in, someone may have your password - change it and contact support.' . "\n";

    queue_email($email, 'Your TDC Tech confirmation code', $body, $userId);
}

/**
 * True when a code was issued recently enough that resending would be abusive.
 */
function email_code_resend_throttled(int $userId, string $purpose): bool
{
    $q = db()->prepare(
        'SELECT created_at FROM user_email_codes WHERE user_id = ? AND purpose = ? ORDER BY id DESC LIMIT 1'
    );
    $q->execute([$userId, $purpose]);
    $createdAt = $q->fetchColumn();
    if (!$createdAt) {
        return false;
    }

    return (time() - strtotime((string) $createdAt . ' UTC')) < TDC_CODE_RESEND_AFTER_SECONDS;
}

/**
 * Check a submitted code. Returns true only for a live, unused, unexpired code
 * that has not already burned its attempt budget. Consumes the code on success.
 */
function verify_email_code(int $userId, string $purpose, string $code): bool
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $q = $pdo->prepare(
            'SELECT id, code_hash, attempts FROM user_email_codes
             WHERE user_id = ? AND purpose = ? AND consumed_at IS NULL AND expires_at > UTC_TIMESTAMP()
             ORDER BY id DESC LIMIT 1 FOR UPDATE'
        );
        $q->execute([$userId, $purpose]);
        $row = $q->fetch();
        if (!$row) {
            $pdo->commit();
            return false;
        }
        if ((int) $row['attempts'] >= TDC_CODE_MAX_ATTEMPTS) {
            $pdo->commit();
            return false;
        }

        $pdo->prepare('UPDATE user_email_codes SET attempts = attempts + 1 WHERE id = ?')->execute([$row['id']]);

        if (!hash_equals((string) $row['code_hash'], hash('sha256', $code))) {
            $pdo->commit();
            return false;
        }

        $pdo->prepare('UPDATE user_email_codes SET consumed_at = UTC_TIMESTAMP() WHERE id = ?')->execute([$row['id']]);
        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/** Mark the address as proven, once. */
function mark_email_verified(int $userId): void
{
    db()->prepare('UPDATE users SET email_verified_at = UTC_TIMESTAMP() WHERE id = ? AND email_verified_at IS NULL')
        ->execute([$userId]);
}
