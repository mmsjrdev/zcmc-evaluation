<?php
/**
 * app/core/auth.php
 *
 * Who is signed in? Sign in, sign out, and the small helpers that go with it
 * (failed-login counting and the audit log).
 *
 * The session stores ONLY the user's id. Everything else (role, status,
 * department) is read from the database on every page load, so when an admin
 * disables an account it stops working immediately.
 *
 * Rules about what a user may DO live in app/core/permissions.php.
 */

// Lock a sign-in after this many wrong passwords ...
const LOGIN_MAX_FAILS = 5;
// ... within this many minutes.
const LOGIN_WINDOW_MINUTES = 10;

// A real bcrypt hash of a throw-away password. When the email does not exist
// we still check the typed password against this, so a wrong email takes about
// as long as a wrong password (nobody can find out which emails exist).
const DUMMY_PASSWORD_HASH = '$2y$10$y.B6AtZv55U62MdcEHBfquW.gJatl6OtTfv/Hd2wyUStICg.7fkn2';

// ---------------------------------------------------------------------------
// Small helpers
// ---------------------------------------------------------------------------

/** The visitor's IP address (as the web server reports it). */
function client_ip(): string
{
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
}

/**
 * Clean a person's name: remove extra spaces at the ends and in the middle.
 * "  Juan   dela  Cruz " becomes "Juan dela Cruz".
 */
function normalize_name(string $name): string
{
    return trim((string) preg_replace('/\s+/u', ' ', $name));
}

/** Returns an error message if the password is not allowed, or null if it is fine. */
function password_problem(string $password): ?string
{
    if (strlen($password) < 8) {
        return 'The password must be at least 8 characters.';
    }
    // bcrypt only reads the first 72 bytes, so longer passwords would be cut silently.
    if (strlen($password) > 72) {
        return 'The password can be at most 72 characters.';
    }
    return null;
}

/** The nice name of a role, for screens. */
function role_label(string $role): string
{
    $labels = [
        'supplier'   => 'Supplier',
        'mm_staff'   => 'Material Management Staff',
        'head_nurse' => 'Head Nurse',
        'evaluator'  => 'Evaluator',
        'head'       => 'Department Head',
        'admin'      => 'Administrator',
    ];
    return $labels[$role] ?? $role;
}

// ---------------------------------------------------------------------------
// Who is signed in
// ---------------------------------------------------------------------------

/**
 * The signed-in user as an array, or null when nobody is signed in.
 * The password hash and the saved signature are NOT included on purpose.
 * Pass true to force a fresh read from the database.
 */
function current_user(bool $reload = false): ?array
{
    static $loaded = false;
    static $user   = null;

    if ($reload) {
        $loaded = false;
        $user   = null;
    }
    if ($loaded) {
        return $user;
    }
    $loaded = true;

    $id = (int) ($_SESSION['user_id'] ?? 0);
    if ($id <= 0) {
        return null;
    }

    $stmt = db()->prepare(
        'SELECT id, email, full_name, employee_id, department_id, `position`, contact_no,
                business_name, role, status, requested_role, must_change_password, created_at
           FROM users
          WHERE id = :id
          LIMIT 1'
    );
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();

    // The account was deleted, disabled or rejected after the person signed in.
    if (!$row || in_array($row['status'], ['disabled', 'rejected'], true)) {
        unset($_SESSION['user_id']);
        if ($row) {
            flash('error', 'This account cannot sign in. Contact the administrator.');
        }
        return null;
    }

    // Department heads: remember which departments they may verify.
    if ($row['role'] === 'head') {
        $row['verifier_department_ids'] = verifier_department_ids($row);
    }

    $user = $row;
    return $user;
}

/** True when somebody is signed in. */
function is_logged_in(): bool
{
    return current_user() !== null;
}

/** Sign a user in. Call this only after the password was checked. */
function login_user(array $user): void
{
    // A new session id after login stops "session fixation" attacks.
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];
    unset($_SESSION['csrf_token']);   // a fresh form token for the new session
    current_user(true);
}

/** Sign the user out and start a clean, empty session (so flash() still works). */
function logout_user(): void
{
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
    session_start();
    session_regenerate_id(true);
    current_user(true);
}

/**
 * Where a user should land right after signing in (or after changing the
 * password): the password page if they must change it, the waiting page if a
 * Department Head request is pending, otherwise the dashboard.
 */
function landing_path(array $user): string
{
    if ((int) $user['must_change_password'] === 1) {
        return 'auth/change-password.php';
    }
    if ($user['status'] === 'pending') {
        return 'auth/pending.php';
    }
    return 'common/dashboard.php';
}

// ---------------------------------------------------------------------------
// Failed sign-in counting (table: login_attempts)
// ---------------------------------------------------------------------------

/** How many wrong passwords were typed for this email in the last few minutes. */
function failed_attempt_count(string $email): int
{
    $stmt = db()->prepare(
        'SELECT COUNT(*) FROM login_attempts
          WHERE email = :email
            AND attempted_at > (NOW() - INTERVAL ' . (int) LOGIN_WINDOW_MINUTES . ' MINUTE)'
    );
    $stmt->execute([':email' => substr($email, 0, 190)]);
    return (int) $stmt->fetchColumn();
}

/** True when this email is locked for a while. */
function too_many_failed_attempts(string $email): bool
{
    return failed_attempt_count($email) >= LOGIN_MAX_FAILS;
}

/** Write down one wrong password. */
function record_failed_attempt(string $email): void
{
    $stmt = db()->prepare('INSERT INTO login_attempts (email, ip_address) VALUES (:email, :ip)');
    $stmt->execute([':email' => substr($email, 0, 190), ':ip' => client_ip()]);

    // Housekeeping: forget attempts older than one day.
    db()->exec('DELETE FROM login_attempts WHERE attempted_at < (NOW() - INTERVAL 1 DAY)');
}

/** Forget the wrong passwords for this email (after a correct sign-in). */
function clear_failed_attempts(string $email): void
{
    $stmt = db()->prepare('DELETE FROM login_attempts WHERE email = :email');
    $stmt->execute([':email' => substr($email, 0, 190)]);
}

// ---------------------------------------------------------------------------
// Audit log (table: audit_log)
// ---------------------------------------------------------------------------

/** Write one line in the audit log. $userId may be null (for example a failed event). */
function audit_write(?int $userId, string $action, string $details = ''): void
{
    $stmt = db()->prepare(
        'INSERT INTO audit_log (user_id, action, details, ip_address)
         VALUES (:uid, :action, :details, :ip)'
    );
    $stmt->execute([
        ':uid'     => $userId,
        ':action'  => substr($action, 0, 80),
        ':details' => $details,
        ':ip'      => client_ip(),
    ]);
}
