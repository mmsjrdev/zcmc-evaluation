<?php
/**
 * app/domain/admin-helpers.php
 *
 * Small helpers used only by the admin pages (admin/*.php).
 *
 * The admin forms have their own secret token, kept in the session under
 * 'admin_csrf'. If you would rather use the CSRF functions from bootstrap.php,
 * change admin_csrf_field() and admin_check_csrf() below and nothing else.
 */

// ---------------------------------------------------------------------------
// Form safety and redirects
// ---------------------------------------------------------------------------

/** The secret token for admin forms (made once per session). */
function admin_csrf_token(): string
{
    if (!isset($_SESSION['admin_csrf']) || !is_string($_SESSION['admin_csrf'])) {
        $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['admin_csrf'];
}

/** The hidden input every admin POST form must contain. */
function admin_csrf_field(): string
{
    return '<input type="hidden" name="admin_csrf" value="' . e(admin_csrf_token()) . '">';
}

/** Stops the request when the token is missing or wrong. Call it first in every POST. */
function admin_check_csrf(): void
{
    $sent = $_POST['admin_csrf'] ?? '';
    if (is_string($sent) && hash_equals(admin_csrf_token(), $sent)) {
        return;
    }
    flash('error', 'Your page was out of date. Please try again.');
    admin_redirect('admin/' . basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'users.php')));
}

/** Send the browser to a project path (it may carry a query) and stop. */
function admin_redirect(string $path): void
{
    header('Location: ' . url($path));
    exit;
}

// ---------------------------------------------------------------------------
// Running one admin action safely
// ---------------------------------------------------------------------------

/**
 * Runs $work inside ONE database transaction.
 * $work receives the PDO connection and returns the success message.
 * Throw a RuntimeException inside it to show a message to the admin and undo
 * everything. Any other error undoes everything and shows a general message.
 * Returns true when the action worked.
 */
function admin_transaction(callable $work): bool
{
    $pdo = db();
    try {
        $pdo->beginTransaction();
        $message = $work($pdo);
        $pdo->commit();
        flash('success', (string) $message);
        return true;
    } catch (RuntimeException $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash('error', $ex->getMessage());
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Admin action failed: ' . $ex->getMessage());
        flash('error', 'Something went wrong and nothing was changed. Please try again.');
    }
    return false;
}

// ---------------------------------------------------------------------------
// Users
// ---------------------------------------------------------------------------

/**
 * Read one user and LOCK the row until the transaction ends.
 * Throws a RuntimeException when the user does not exist.
 */
function admin_load_user_locked(PDO $pdo, int $id): array
{
    $stmt = $pdo->prepare(
        'SELECT id, email, full_name, role, status, requested_role, department_id
           FROM users
          WHERE id = :id
          LIMIT 1
            FOR UPDATE'
    );
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    if (!$row) {
        throw new RuntimeException('That user does not exist.');
    }
    return $row;
}

/**
 * True when this user is the ONLY active administrator.
 * Call it inside a transaction: it locks every active admin row, so two
 * admins cannot remove each other at the same moment.
 */
function admin_is_last_active_admin(PDO $pdo, int $userId): bool
{
    $ids = $pdo->query("SELECT id FROM users WHERE role = 'admin' AND status = 'active' FOR UPDATE")
               ->fetchAll(PDO::FETCH_COLUMN);
    $ids = array_map('intval', $ids);
    return in_array($userId, $ids, true) && count($ids) <= 1;
}

/** Forms still waiting on this person as evaluator (they get stuck if the person is disabled). */
function admin_open_forms_count(int $userId): int
{
    $stmt = db()->prepare(
        "SELECT COUNT(*) FROM evaluations
          WHERE evaluator_id = :id AND status IN ('pending_evaluation', 'returned_evaluator')"
    );
    $stmt->execute([':id' => $userId]);
    return (int) $stmt->fetchColumn();
}

/** A random temporary password (12 characters, no look-alike letters). */
function admin_temp_password(): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
    $last     = strlen($alphabet) - 1;
    $out      = '';
    for ($i = 0; $i < 12; $i++) {
        $out .= $alphabet[random_int(0, $last)];
    }
    return $out;
}

/** An error message when the email is not acceptable, or null when it is fine. */
function admin_email_problem(string $email): ?string
{
    if ($email === '' || strlen($email) > 190 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        return 'Enter a valid email address (up to 190 characters).';
    }
    return null;
}

/** Turn typed text into a safe LIKE pattern (so % and _ are searched literally). */
function admin_like(string $text): string
{
    return '%' . addcslashes($text, '\\%_') . '%';
}

// ---------------------------------------------------------------------------
// Departments and notifications
// ---------------------------------------------------------------------------

/** Departments as [id => name]. Active ones only by default. */
function admin_department_names(bool $activeOnly = true): array
{
    $sql   = 'SELECT id, name FROM departments' . ($activeOnly ? ' WHERE active = 1' : '') . ' ORDER BY name';
    $names = [];
    foreach (db()->query($sql)->fetchAll() as $row) {
        $names[(int) $row['id']] = $row['name'];
    }
    return $names;
}

/** The name of one department, or 'none' when there is no department. */
function admin_department_name(PDO $pdo, ?int $id): string
{
    if ($id === null || $id <= 0) {
        return 'none';
    }
    $stmt = $pdo->prepare('SELECT name FROM departments WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    $name = $stmt->fetchColumn();
    return $name === false ? 'unknown' : (string) $name;
}

/** Put one in-app notification in somebody's list. $link is a project path or ''. */
function admin_notify(int $userId, string $message, string $link = ''): void
{
    $stmt = db()->prepare('INSERT INTO notifications (user_id, message, link) VALUES (:u, :m, :l)');
    $stmt->execute([
        ':u' => $userId,
        ':m' => mb_substr($message, 0, 255),
        ':l' => $link !== '' ? $link : null,
    ]);
}