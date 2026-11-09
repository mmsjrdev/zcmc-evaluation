<?php
/**
 * auth/verify-email.php
 *
 * Step 15: confirms the email address with a 6-digit code.
 * Reached right after registering, or after a correct password when the
 * email is not confirmed yet (only while OTP_ENABLED is true).
 * The person is NOT signed in until the code is correct.
 * The session remembers only "this user id passed the password step".
 */

require_once __DIR__ . '/../app/bootstrap.php';

// Already signed in? Nothing to confirm here.
$already = current_user();
if ($already !== null) {
    redirect(landing_path($already));
}

$pendingId = (int) ($_SESSION['verify_user_id'] ?? 0);
if ($pendingId <= 0 || !otp_required('register')) {
    unset($_SESSION['verify_user_id']);
    flash('info', 'Sign in to continue.');
    redirect('auth/login.php');
}

$stmt = db()->prepare('SELECT id, email, full_name, status, email_verified_at FROM users WHERE id = :id LIMIT 1');
$stmt->execute([':id' => $pendingId]);
$u = $stmt->fetch();

if (!$u || in_array($u['status'], ['disabled', 'rejected'], true)) {
    unset($_SESSION['verify_user_id']);
    flash('error', 'This account cannot sign in. Contact the administrator.');
    redirect('auth/login.php');
}
if ($u['email_verified_at'] !== null) {
    unset($_SESSION['verify_user_id']);
    flash('info', 'Your email is already confirmed. Sign in.');
    redirect('auth/login.php');
}

$notice = '';
$error  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'cancel') {
        unset($_SESSION['verify_user_id']);
        redirect('auth/login.php');

    } elseif ($action === 'send') {
        $sent = otp_issue($u, 'register');
        if ($sent['ok']) {
            $notice = $sent['message'];
        } else {
            $error = $sent['message'];
        }

    } elseif ($action === 'verify') {
        $result = otp_verify($u, 'register', (string) ($_POST['otp_code'] ?? ''));
        if ($result['ok']) {
            db()->prepare('UPDATE users SET email_verified_at = NOW() WHERE id = :id AND email_verified_at IS NULL')
                ->execute([':id' => (int) $u['id']]);
            audit_write((int) $u['id'], 'email_verified', 'Email confirmed with a one-time code');

            unset($_SESSION['verify_user_id']);
            login_user(['id' => (int) $u['id']]);
            flash('success', 'Your email is confirmed. Welcome!');
            redirect(landing_path(current_user()));
        }
        $error = $result['message'];
    }

} elseif (!otp_has_active_code((int) $u['id'], 'register')) {
    // First visit (or the last code expired): send a code now.
    $sent = otp_issue($u, 'register');
    if ($sent['ok']) {
        $notice = $sent['message'];
    } else {
        $error = $sent['message'];
    }
}

$pageTitle = 'Confirm your email';
require __DIR__ . '/../app/views/layout/auth-top.php';
?>

<h1 class="text-xl font-semibold text-slate-900">Confirm your email</h1>
<p class="mt-1 text-sm text-slate-500">
    Enter the 6-digit code sent to <strong><?= e(otp_mask_email((string) $u['email'])) ?></strong>
    to finish. The code works once and expires in <?= (int) OTP_EXPIRY_MINUTES ?> minutes.
</p>

<?php if ($notice !== ''): ?>
    <div role="status" class="mt-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800">
        <?= e($notice) ?>
    </div>
<?php endif; ?>
<?php if ($error !== ''): ?>
    <div role="alert" class="mt-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-800">
        <?= e($error) ?>
    </div>
<?php endif; ?>

<form method="post" action="<?= e(url('auth/verify-email.php')) ?>" class="mt-5 space-y-4" autocomplete="off">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="verify">

    <div>
        <label for="otp_code" class="mb-1 block text-sm font-medium text-slate-700">6-digit code</label>
        <input id="otp_code" name="otp_code" type="text" required maxlength="6" minlength="6"
               inputmode="numeric" pattern="[0-9]{6}" autocomplete="one-time-code" autofocus class="input">
    </div>

    <button type="submit" class="btn btn-primary w-full">Confirm email</button>
</form>

<div class="mt-5 flex flex-col gap-3 border-t border-slate-100 pt-4 sm:flex-row">
    <form method="post" action="<?= e(url('auth/verify-email.php')) ?>" class="w-full sm:w-auto">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="send">
        <button type="submit" class="btn btn-secondary w-full">Send a new code</button>
    </form>
    <form method="post" action="<?= e(url('auth/verify-email.php')) ?>" class="w-full sm:w-auto">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="cancel">
        <button type="submit" class="btn btn-secondary w-full">Back to sign in</button>
    </form>
</div>
<p class="mt-3 text-xs text-slate-500">A new code can be asked for every <?= (int) OTP_RESEND_SECONDS ?> seconds.</p>

<?php require __DIR__ . '/../app/views/layout/auth-bottom.php'; ?>