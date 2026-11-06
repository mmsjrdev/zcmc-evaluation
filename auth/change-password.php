<?php
/**
 * auth/change-password.php
 *
 * Any signed-in person can change their password here.
 * It is also FORCED (nothing else opens) when must_change_password = 1,
 * which is how temporary passwords of supplier, MMS and Head Nurse accounts work.
 */

require_once __DIR__ . '/../app/bootstrap.php';

$user   = require_role(all_roles());
$forced = ((int) $user['must_change_password'] === 1);

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $current = (string) ($_POST['current_password'] ?? '');
    $new     = (string) ($_POST['new_password'] ?? '');
    $confirm = (string) ($_POST['new_password_confirm'] ?? '');

    // ---- Is the current password right? (wrong guesses are counted like at login)
    if (too_many_failed_attempts($user['email'])) {
        $errors['current_password'] = 'Too many wrong passwords. Wait ' . LOGIN_WINDOW_MINUTES . ' minutes and try again.';
    } else {
        $stmt = db()->prepare('SELECT password_hash FROM users WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => (int) $user['id']]);
        $hash = (string) $stmt->fetchColumn();

        if (!password_verify($current, $hash)) {
            record_failed_attempt($user['email']);
            $errors['current_password'] = 'The current password is wrong.';
        }
    }

    // ---- Is the new password fine?
    $problem = password_problem($new);
    if ($problem !== null) {
        $errors['new_password'] = $problem;
    } elseif ($new === $current) {
        $errors['new_password'] = 'Choose a password that is different from the current one.';
    }
    if ($new !== $confirm) {
        $errors['new_password_confirm'] = 'The two passwords do not match.';
    }

    // ---- Save
    if (!$errors) {
        $upd = db()->prepare('UPDATE users SET password_hash = :hash, must_change_password = 0 WHERE id = :id');
        $upd->execute([':hash' => password_hash($new, PASSWORD_DEFAULT), ':id' => (int) $user['id']]);

        clear_failed_attempts($user['email']);
        audit_write((int) $user['id'], 'password_changed', 'Password changed');

        // New session id after a password change.
        session_regenerate_id(true);

        flash('success', 'Your password was changed.');
        redirect(landing_path(current_user(true)));
    }
}

$pageTitle = 'Change password';
require __DIR__ . '/../app/views/layout/auth-top.php';
?>

<h1 class="text-xl font-semibold text-slate-900">Change password</h1>

<?php if ($forced): ?>
    <div class="mt-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
        You are using a temporary password. Choose a new password to continue.
    </div>
<?php else: ?>
    <p class="mt-1 text-sm text-slate-500">Choose a new password for <?= e($user['email']) ?>.</p>
<?php endif; ?>

<?php if ($errors): ?>
    <div role="alert" class="mt-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-800">
        Please fix the problems marked in red below.
    </div>
<?php endif; ?>

<form method="post" action="<?= e(url('auth/change-password.php')) ?>" class="mt-5 space-y-4">
    <?= csrf_field() ?>

    <div>
        <label for="current_password" class="mb-1 block text-sm font-medium text-slate-700">
            <?= $forced ? 'Temporary password' : 'Current password' ?>
        </label>
        <input id="current_password" name="current_password" type="password" required
               autocomplete="current-password" class="<?= e(input_class($errors, 'current_password')) ?>">
        <?php field_error($errors, 'current_password'); ?>
    </div>

    <div>
        <label for="new_password" class="mb-1 block text-sm font-medium text-slate-700">New password</label>
        <input id="new_password" name="new_password" type="password" required minlength="8" maxlength="72"
               autocomplete="new-password" class="<?= e(input_class($errors, 'new_password')) ?>">
        <p class="mt-1 text-xs text-slate-500">At least 8 characters.</p>
        <?php field_error($errors, 'new_password'); ?>
    </div>

    <div>
        <label for="new_password_confirm" class="mb-1 block text-sm font-medium text-slate-700">Repeat new password</label>
        <input id="new_password_confirm" name="new_password_confirm" type="password" required minlength="8" maxlength="72"
               autocomplete="new-password" class="<?= e(input_class($errors, 'new_password_confirm')) ?>">
        <?php field_error($errors, 'new_password_confirm'); ?>
    </div>

    <button type="submit" class="btn btn-primary w-full">Save new password</button>
</form>

<div class="mt-4 flex flex-col gap-3">
    <?php if (!$forced): ?>
        <a href="<?= e(url('common/dashboard.php')) ?>" class="btn btn-secondary w-full">Cancel</a>
    <?php else: ?>
        <!-- Even with a forced change the person can still sign out. -->
        <form method="post" action="<?= e(url('auth/logout.php')) ?>">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-secondary w-full">Sign out</button>
        </form>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/../app/views/layout/auth-bottom.php'; ?>
