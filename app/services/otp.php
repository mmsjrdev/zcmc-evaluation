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
 * An email that was registered but never confirmed with a code is NOT a real
 * account yet, so it must not block the address (the person closed the browser,
 * the code expired, ...). Returns the id of such a leftover row, or null.
 *
 * Only a row that is clearly an abandoned PUBLIC sign-up qualifies:
 *   - the code step is on (otherwise unconfirmed accounts are normal),
 *   - email never confirmed, role evaluator (suppliers, MMS, head nurses and
 *     admins are created by staff and are never taken over),
 *   - status active/pending (an admin's disable/reject decision stays),
 *   - the person never signed in (audit_log has no 'login' for the row, which
 *     protects accounts that existed before the code step was switched on).
 */
function otp_abandoned_registration_id(string $email): ?int
{
    if (!otp_required('register')) {
        return null;
    }
    $stmt = db()->prepare(
        "SELECT u.id FROM users u
          WHERE u.email = :email
            AND u.email_verified_at IS NULL
            AND u.role = 'evaluator'
            AND u.status IN ('active', 'pending')
            AND NOT EXISTS (SELECT 1 FROM audit_log a WHERE a.user_id = u.id AND a.action = 'login')
          LIMIT 1"
    );
    $stmt->execute([':email' => $email]);
    $id = $stmt->fetchColumn();
    return $id === false ? null : (int) $id;
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

// ---------------------------------------------------------------------------
// Pending sign-ups (table: pending_registrations)
//
// A new public sign-up is NOT written to `users` until the email code is
// correct. Until then the form data waits in pending_registrations, together
// with its own code (hash, expiry, tries). Nothing there can block an email:
// registering again with the same email just replaces the waiting row.
// ---------------------------------------------------------------------------

/** One waiting sign-up by id, or null. */
function pending_registration_find(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM pending_registrations WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Seconds left before a new register code may be asked for (0 = now), measured by MySQL.
 * $isWaiting = true for a waiting sign-up (pending_registrations), false for a users row.
 * The same 60-second rule is enforced again in otp_issue() / pending_otp_issue(),
 * so this number is only for showing a countdown on the screen.
 */
function otp_resend_wait_seconds(int $id, bool $isWaiting): int
{
    $sql = $isWaiting
        ? 'SELECT TIMESTAMPDIFF(SECOND, code_sent_at, NOW()) FROM pending_registrations WHERE id = :id'
        : "SELECT TIMESTAMPDIFF(SECOND, created_at, NOW()) FROM otp_codes
            WHERE user_id = :id AND purpose = 'register' ORDER BY id DESC LIMIT 1";
    $stmt = db()->prepare($sql);
    $stmt->execute([':id' => $id]);
    $age = $stmt->fetchColumn();
    if ($age === false || $age === null) {
        return 0;
    }
    return max(0, OTP_RESEND_SECONDS - (int) $age);
}

/** One waiting sign-up by email, or null. */
function pending_registration_by_email(string $email): ?array
{
    $stmt = db()->prepare('SELECT * FROM pending_registrations WHERE email = :email LIMIT 1');
    $stmt->execute([':email' => $email]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** True when the waiting sign-up has a code that is not expired and not out of tries. */
function pending_otp_has_active_code(int $id): bool
{
    $stmt = db()->prepare(
        'SELECT COUNT(*) FROM pending_registrations
          WHERE id = :id AND code_hash IS NOT NULL AND code_expires_at > NOW()
            AND code_attempts < ' . (int) OTP_MAX_ATTEMPTS
    );
    $stmt->execute([':id' => $id]);
    return (int) $stmt->fetchColumn() > 0;
}

/**
 * Create a new code for a waiting sign-up and email it. Same rules as otp_issue():
 * 60 seconds between codes, 10 per hour. The counters live on the waiting row and
 * survive "register again", so registering again cannot be used to skip them.
 * $p needs: id, email, full_name.
 * @return array{ok: bool, message: string}
 */
function pending_otp_issue(array $p): array
{
    $pdo = db();
    $id  = (int) $p['id'];

    $stmt = $pdo->prepare(
        'SELECT TIMESTAMPDIFF(SECOND, code_sent_at, NOW())     AS age,
                TIMESTAMPDIFF(MINUTE, hour_started_at, NOW())  AS hour_age,
                hour_count
           FROM pending_registrations WHERE id = :id LIMIT 1'
    );
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    if (!$row) {
        return ['ok' => false, 'message' => 'This sign-up is no longer saved. Register again.'];
    }

    if ($row['age'] !== null && (int) $row['age'] < OTP_RESEND_SECONDS) {
        $wait = max(1, OTP_RESEND_SECONDS - (int) $row['age']);
        return ['ok' => false, 'message' => 'Please wait ' . $wait . ' more second' . ($wait === 1 ? '' : 's') . ' before asking for a new code.'];
    }
    $sameHour = $row['hour_age'] !== null && (int) $row['hour_age'] < 60;
    if ($sameHour && (int) $row['hour_count'] >= OTP_MAX_PER_HOUR) {
        return ['ok' => false, 'message' => 'Too many codes were asked for. Try again in an hour.'];
    }

    $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    // hour_count is set BEFORE hour_started_at on purpose (MySQL applies SET items left to right).
    $pdo->prepare(
        'UPDATE pending_registrations
            SET code_hash       = :h,
                code_expires_at = DATE_ADD(NOW(), INTERVAL ' . (int) OTP_EXPIRY_MINUTES . ' MINUTE),
                code_attempts   = 0,
                code_sent_at    = NOW(),
                hour_count      = IF(hour_started_at IS NULL OR TIMESTAMPDIFF(MINUTE, hour_started_at, NOW()) >= 60, 1, hour_count + 1),
                hour_started_at = IF(hour_started_at IS NULL OR TIMESTAMPDIFF(MINUTE, hour_started_at, NOW()) >= 60, NOW(), hour_started_at)
          WHERE id = :id'
    )->execute([':h' => password_hash($code, PASSWORD_DEFAULT), ':id' => $id]);

    $body = "Hello " . (string) ($p['full_name'] ?? '') . ",\n\n"
          . "Your one-time code to confirm your email address is:\n\n"
          . "    " . $code . "\n\n"
          . "It works once and expires in " . OTP_EXPIRY_MINUTES . " minutes.\n"
          . "If you did not ask for this code, do not share it with anyone and tell the administrator.\n\n"
          . "ZCMC Evaluation Form System";

    $sent = mail_send((string) $p['email'], (string) ($p['full_name'] ?? ''), 'Your ZCMC one-time code', $body);
    if (!$sent['ok']) {
        // Forget the unsent code so the person can try again right away.
        $pdo->prepare('UPDATE pending_registrations
                          SET code_hash = NULL, code_sent_at = NULL, hour_count = GREATEST(hour_count - 1, 0)
                        WHERE id = :id')->execute([':id' => $id]);
        audit_write(null, 'otp_mail_failed', 'Pending sign-up: ' . $sent['error']);
        $message = 'The email could not be sent. Try again, or tell the administrator.';
        if (ENV === 'local') {
            $message .= ' (' . $sent['error'] . ')';
        }
        return ['ok' => false, 'message' => $message];
    }

    audit_write(null, 'otp_sent', 'Purpose register (sign-up not saved yet)');
    return ['ok' => true, 'message' => 'A 6-digit code was sent to ' . otp_mask_email((string) $p['email'])
        . '. It works once and expires in ' . OTP_EXPIRY_MINUTES . ' minutes.'];
}

/**
 * Check the code of a waiting sign-up. When it is correct, THIS is the moment the
 * account is created: one transaction moves the data into `users` (email already
 * confirmed) and removes the waiting row. A wrong code counts one try.
 * @return array{ok: bool, message: string, user_id: int, gone: bool}
 *         gone = true means the waiting sign-up no longer exists (go back to register).
 */
function pending_registration_complete(int $pendingId, string $code): array
{
    $fail = static function (string $message, bool $gone = false): array {
        return ['ok' => false, 'message' => $message, 'user_id' => 0, 'gone' => $gone];
    };
    $pdo = db();

    $stmt = $pdo->prepare(
        'SELECT *, (code_expires_at IS NOT NULL AND code_expires_at > NOW()) AS code_live
           FROM pending_registrations WHERE id = :id LIMIT 1'
    );
    $stmt->execute([':id' => $pendingId]);
    $p = $stmt->fetch();
    if (!$p) {
        return $fail('This sign-up is no longer saved. Register again.', true);
    }

    $code = (string) preg_replace('/\D/', '', $code);

    if ($p['code_hash'] === null || !(int) $p['code_live']) {
        return $fail('There is no valid code. It may have expired or been used. Ask for a new code.');
    }
    if ((int) $p['code_attempts'] >= OTP_MAX_ATTEMPTS) {
        return $fail('Too many wrong tries for this code. Ask for a new code.');
    }
    if (strlen($code) !== 6) {
        return $fail('Enter the 6-digit code from your email.');
    }
    if (!password_verify($code, (string) $p['code_hash'])) {
        $pdo->prepare('UPDATE pending_registrations SET code_attempts = code_attempts + 1 WHERE id = :id')
            ->execute([':id' => $pendingId]);
        $left = OTP_MAX_ATTEMPTS - (int) $p['code_attempts'] - 1;
        return $fail('That code is not correct. ' . $left . ' ' . ($left === 1 ? 'try' : 'tries') . ' left.');
    }

    // Correct code: create the real account. A head request starts as "pending";
    // the stored role is always evaluator (same rule as before).
    $status = ($p['requested_role'] === 'head') ? 'pending' : 'active';

    try {
        $pdo->beginTransaction();

        // Only one click can win this delete (two clicks at once).
        $del = $pdo->prepare(
            'DELETE FROM pending_registrations
              WHERE id = :id AND code_hash IS NOT NULL AND code_attempts < ' . (int) OTP_MAX_ATTEMPTS
        );
        $del->execute([':id' => $pendingId]);
        if ($del->rowCount() !== 1) {
            $pdo->rollBack();
            return $fail('That code was just used. Sign in to continue.', true);
        }

        $pdo->prepare(
            "INSERT INTO users
                (email, email_verified_at, password_hash, full_name, employee_id, department_id,
                 `position`, contact_no, role, status, requested_role, must_change_password)
             VALUES
                (:email, NOW(), :hash, :name, :employee_id, :department_id,
                 :position, :contact_no, 'evaluator', :status, :requested_role, 0)"
        )->execute([
            ':email'          => $p['email'],
            ':hash'           => $p['password_hash'],
            ':name'           => $p['full_name'],
            ':employee_id'    => $p['employee_id'],
            ':department_id'  => $p['department_id'],
            ':position'       => $p['position'],
            ':contact_no'     => $p['contact_no'],
            ':status'         => $status,
            ':requested_role' => $p['requested_role'],
        ]);
        $newId = (int) $pdo->lastInsertId();
        $pdo->commit();
    } catch (PDOException $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($ex->getCode() === '23000') {
            // The email became a real account in the meantime.
            $pdo->prepare('DELETE FROM pending_registrations WHERE id = :id')->execute([':id' => $pendingId]);
            return $fail('An account with this email already exists. Sign in instead.', true);
        }
        throw $ex;
    }

    audit_write($newId, 'register', 'Registered as ' . $p['requested_role'] . ' (status ' . $status . ')');
    audit_write($newId, 'email_verified', 'Email confirmed with a one-time code');

    return ['ok' => true, 'message' => '', 'user_id' => $newId, 'gone' => false];
}