<?php
/**
 * app/services/otp.php
 *
 * One-time passwords by email (Step 15).
 *
 *   otp_required($purpose)               does this action need a code? (false while OTP_ENABLED is false)
 *   otp_issue($user, $purpose)           create a code and email it
 *   otp_verify($user, $purpose, $code)   check it, count a wrong try, consume it (use OUTSIDE a transaction)
 *   otp_check / otp_record_failure / otp_consume
 *                                        the same in three pieces, for code that runs
 *                                        inside a database transaction (signing)
 *
 * Rules: only a hash is stored, a code lives 10 minutes, works once,
 * allows 5 tries, and a new one can be asked for after 60 seconds.
 * Purposes: 'register' (confirm the email) and 'sign' (signing a form).
 * Login stays email + password.
 */

require_once __DIR__ . '/mail.php';

const OTP_EXPIRY_MINUTES = 10;
const OTP_MAX_ATTEMPTS   = 5;
const OTP_RESEND_SECONDS = 60;
const OTP_MAX_PER_HOUR   = 10;   // stops someone from filling an inbox

/** Does this action need a one-time code right now? */
function otp_required(string $purpose): bool
{
    return OTP_ENABLED && in_array($purpose, ['register', 'sign'], true);
}

/** "juan.delacruz@mail.com" -> "j*****@mail.com" (shown on screen). */
function otp_mask_email(string $email): string
{
    $at = strrpos($email, '@');
    if ($at === false || $at < 1) {
        return $email;
    }
    $local = substr($email, 0, $at);
    return substr($local, 0, 1) . str_repeat('*', max(2, min(6, strlen($local) - 1))) . substr($email, $at);
}

/** True when this user has a code that is not used, not expired and not out of tries. */
function otp_has_active_code(int $userId, string $purpose): bool
{
    $stmt = db()->prepare(
        'SELECT COUNT(*) FROM otp_codes
          WHERE user_id = :u AND purpose = :p AND used_at IS NULL
            AND expires_at > NOW() AND attempts < ' . (int) OTP_MAX_ATTEMPTS
    );
    $stmt->execute([':u' => $userId, ':p' => $purpose]);
    return (int) $stmt->fetchColumn() > 0;
}

/**
 * Create a new code and email it. Older unused codes stop working.
 * $user needs: id, email, full_name.
 * @return array{ok: bool, message: string}
 */
function otp_issue(array $user, string $purpose): array
{
    if (!in_array($purpose, ['register', 'sign'], true)) {
        return ['ok' => false, 'message' => 'Unknown code type.'];
    }

    $userId = (int) $user['id'];
    $pdo    = db();

    // Signing codes go only to an email address that was already confirmed.
    if ($purpose !== 'register') {
        $chk = $pdo->prepare('SELECT email_verified_at FROM users WHERE id = :id LIMIT 1');
        $chk->execute([':id' => $userId]);
        if ($chk->fetchColumn() === null) {
            return ['ok' => false, 'message' => 'Your email is not confirmed yet. Sign out and sign in again to confirm it first.'];
        }
    }

    // 60 seconds between two codes (age is measured by MySQL, so time zones cannot confuse it).
    $last = $pdo->prepare(
        'SELECT TIMESTAMPDIFF(SECOND, created_at, NOW()) AS age
           FROM otp_codes WHERE user_id = :u AND purpose = :p
          ORDER BY id DESC LIMIT 1'
    );
    $last->execute([':u' => $userId, ':p' => $purpose]);
    $row = $last->fetch();
    if ($row && (int) $row['age'] < OTP_RESEND_SECONDS) {
        $wait = max(1, OTP_RESEND_SECONDS - (int) $row['age']);
        return ['ok' => false, 'message' => 'Please wait ' . $wait . ' more second' . ($wait === 1 ? '' : 's') . ' before asking for a new code.'];
    }

    // At most 10 codes per hour.
    $hour = $pdo->prepare('SELECT COUNT(*) FROM otp_codes WHERE user_id = :u AND created_at > (NOW() - INTERVAL 1 HOUR)');
    $hour->execute([':u' => $userId]);
    if ((int) $hour->fetchColumn() >= OTP_MAX_PER_HOUR) {
        return ['ok' => false, 'message' => 'Too many codes were asked for. Try again in an hour.'];
    }

    // Housekeeping, then retire any older unused code (only the newest one works).
    $pdo->exec('DELETE FROM otp_codes WHERE created_at < (NOW() - INTERVAL 1 DAY)');
    $pdo->prepare('UPDATE otp_codes SET used_at = NOW() WHERE user_id = :u AND purpose = :p AND used_at IS NULL')
        ->execute([':u' => $userId, ':p' => $purpose]);

    // A fresh 6-digit code. Only its hash is stored.
    $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $pdo->prepare(
        'INSERT INTO otp_codes (user_id, purpose, code_hash, expires_at)
         VALUES (:u, :p, :h, DATE_ADD(NOW(), INTERVAL ' . (int) OTP_EXPIRY_MINUTES . ' MINUTE))'
    )->execute([':u' => $userId, ':p' => $purpose, ':h' => password_hash($code, PASSWORD_DEFAULT)]);
    $codeId = (int) $pdo->lastInsertId();

    // Email it.
    $what = $purpose === 'register' ? 'confirm your email address' : 'sign your evaluation form';
    $body = "Hello " . (string) ($user['full_name'] ?? '') . ",\n\n"
          . "Your one-time code to " . $what . " is:\n\n"
          . "    " . $code . "\n\n"
          . "It works once and expires in " . OTP_EXPIRY_MINUTES . " minutes.\n"
          . "If you did not ask for this code, do not share it with anyone and tell the administrator.\n\n"
          . "ZCMC Evaluation Form System";

    $sent = mail_send((string) $user['email'], (string) ($user['full_name'] ?? ''), 'Your ZCMC one-time code', $body);
    if (!$sent['ok']) {
        // Remove the unsent code so the person can try again right away.
        $pdo->prepare('DELETE FROM otp_codes WHERE id = :id')->execute([':id' => $codeId]);
        audit_write($userId, 'otp_mail_failed', 'Purpose ' . $purpose . ': ' . $sent['error']);
        $message = 'The email could not be sent. Try again, or tell the administrator.';
        if (ENV === 'local') {
            $message .= ' (' . $sent['error'] . ')';
        }
        return ['ok' => false, 'message' => $message];
    }

    audit_write($userId, 'otp_sent', 'Purpose ' . $purpose);
    return ['ok' => true, 'message' => 'A 6-digit code was sent to ' . otp_mask_email((string) $user['email'])
        . '. It works once and expires in ' . OTP_EXPIRY_MINUTES . ' minutes.'];
}

/**
 * Looks at the newest usable code and compares it. Changes NOTHING.
 * @return array{ok: bool, message: string, code_id: int, count_failure: bool}
 *         count_failure = true means "a wrong code was typed: call otp_record_failure()".
 */
function otp_check(array $user, string $purpose, string $code): array
{
    $result = ['ok' => false, 'message' => '', 'code_id' => 0, 'count_failure' => false];

    $code = (string) preg_replace('/\D/', '', $code);

    $stmt = db()->prepare(
        'SELECT id, code_hash, attempts FROM otp_codes
          WHERE user_id = :u AND purpose = :p AND used_at IS NULL AND expires_at > NOW()
          ORDER BY id DESC LIMIT 1'
    );
    $stmt->execute([':u' => (int) $user['id'], ':p' => $purpose]);
    $row = $stmt->fetch();

    if (!$row) {
        $result['message'] = 'There is no valid code. It may have expired or been used. Ask for a new code.';
        return $result;
    }
    if ((int) $row['attempts'] >= OTP_MAX_ATTEMPTS) {
        $result['message'] = 'Too many wrong tries for this code. Ask for a new code.';
        return $result;
    }
    if (strlen($code) !== 6) {
        $result['message'] = 'Enter the 6-digit code from your email.';
        return $result;
    }

    $result['code_id'] = (int) $row['id'];
    if (!password_verify($code, (string) $row['code_hash'])) {
        $left = OTP_MAX_ATTEMPTS - (int) $row['attempts'] - 1;
        $result['count_failure'] = true;
        $result['message'] = 'That code is not correct. ' . $left . ' ' . ($left === 1 ? 'try' : 'tries') . ' left.';
        return $result;
    }

    $result['ok'] = true;
    return $result;
}

/** Counts one wrong try on a code. */
function otp_record_failure(int $codeId): void
{
    db()->prepare('UPDATE otp_codes SET attempts = attempts + 1 WHERE id = :id')->execute([':id' => $codeId]);
}

/** Uses the code up. False when it was already used, expired or out of tries (two clicks at once). */
function otp_consume(int $codeId): bool
{
    $stmt = db()->prepare(
        'UPDATE otp_codes SET used_at = NOW()
          WHERE id = :id AND used_at IS NULL AND expires_at > NOW() AND attempts < ' . (int) OTP_MAX_ATTEMPTS
    );
    $stmt->execute([':id' => $codeId]);
    return $stmt->rowCount() === 1;
}

/**
 * Check + count a wrong try + consume, in one call.
 * Use it OUTSIDE a database transaction (for example the email confirmation page).
 * @return array{ok: bool, message: string}
 */
function otp_verify(array $user, string $purpose, string $code): array
{
    $check = otp_check($user, $purpose, $code);
    if (!$check['ok']) {
        if ($check['count_failure']) {
            otp_record_failure($check['code_id']);
        }
        return ['ok' => false, 'message' => $check['message']];
    }
    if (!otp_consume($check['code_id'])) {
        return ['ok' => false, 'message' => 'That code was just used. Ask for a new code.'];
    }
    return ['ok' => true, 'message' => ''];
}