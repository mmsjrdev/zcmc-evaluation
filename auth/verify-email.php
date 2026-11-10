<?php
/**
 * auth/verify-email.php
 *
 * Step 15: confirms the email address with a 6-digit code.
 * Reached right after registering, or after a correct email + password while the
 * sign-up is still unconfirmed (only while OTP_ENABLED is true).
 * The person is NOT signed in until the code is correct.
 *
 * Two kinds of "not confirmed yet":
 *   - a WAITING SIGN-UP in pending_registrations (session: verify_pending_id).
 *     The account does not exist in `users` yet; the correct code creates it.
 *   - an old unconfirmed ROW in `users` (session: verify_user_id), e.g. an account
 *     that existed before the code step was switched on.
 */

require_once __DIR__ . '/../app/bootstrap.php';

// Already signed in? Nothing to confirm here.
$already = current_user();
if ($already !== null) {
    redirect(landing_path($already));
}

$waitingId = (int) ($_SESSION['verify_pending_id'] ?? 0);   // waiting sign-up
$pendingId = (int) ($_SESSION['verify_user_id'] ?? 0);      // old unconfirmed users row
if (!otp_required('register') || ($waitingId <= 0 && $pendingId <= 0)) {
    unset($_SESSION['verify_pending_id'], $_SESSION['verify_user_id']);
    flash('info', 'Sign in to continue.');
    redirect('auth/login.php');
}

$isWaiting = $waitingId > 0;

if ($isWaiting) {
    $u = pending_registration_find($waitingId);
    if (!$u) {
        unset($_SESSION['verify_pending_id']);
        flash('info', 'Your sign-up is no longer saved. Register again, or sign in if you already confirmed it.');
        redirect('auth/register.php');
    }
} else {
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
}

$notice = '';
$error  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'cancel') {
        unset($_SESSION['verify_pending_id'], $_SESSION['verify_user_id']);
        redirect('auth/login.php');

    } elseif ($action === 'send') {
        $sent = $isWaiting ? pending_otp_issue($u) : otp_issue($u, 'register');
        if ($sent['ok']) {
            $notice = $sent['message'];
        } else {
            $error = $sent['message'];
        }

    } elseif ($action === 'verify' && $isWaiting) {
        // The account is created only now, with the correct code.
        $result = pending_registration_complete((int) $u['id'], (string) ($_POST['otp_code'] ?? ''));
        if ($result['ok']) {
            unset($_SESSION['verify_pending_id']);
            login_user(['id' => (int) $result['user_id']]);
            flash('success', 'Your email is confirmed and your account is created. Welcome!');
            redirect(landing_path(current_user()));
        }
        if ($result['gone']) {
            unset($_SESSION['verify_pending_id']);
            flash('error', $result['message']);
            redirect('auth/register.php');
        }
        $error = $result['message'];

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

} elseif (!($isWaiting ? pending_otp_has_active_code((int) $u['id']) : otp_has_active_code((int) $u['id'], 'register'))) {
    // First visit (or the last code expired): send a code now.
    $sent = $isWaiting ? pending_otp_issue($u) : otp_issue($u, 'register');
    if ($sent['ok']) {
        $notice = $sent['message'];
    } else {
        $error = $sent['message'];
    }
}

// Seconds until "Send a new code" may be used again (read AFTER any code sent above).
// The server enforces the same wait; this number only drives the countdown on the button.
$resendWait = otp_resend_wait_seconds((int) $u['id'], $isWaiting);

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
        <button type="submit" id="resend-btn" class="btn btn-secondary w-full"
                data-wait="<?= (int) $resendWait ?>" <?= $resendWait > 0 ? 'disabled' : '' ?>>
            <?= $resendWait > 0 ? 'Send a new code (' . (int) $resendWait . 's)' : 'Send a new code' ?>
        </button>
    </form>
    <form method="post" action="<?= e(url('auth/verify-email.php')) ?>" class="w-full sm:w-auto">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="cancel">
        <button type="submit" class="btn btn-secondary w-full">Back to sign in</button>
    </form>
</div>
<p class="mt-3 text-xs text-slate-500">A new code can be asked for every <?= (int) OTP_RESEND_SECONDS ?> seconds.</p>

<style>
    #resend-btn[disabled] { opacity: .55; cursor: not-allowed; }
</style>
<script>
(function () {
    var btn = document.getElementById('resend-btn');
    if (!btn) { return; }

    var label = 'Send a new code';
    var left  = parseInt(btn.getAttribute('data-wait'), 10) || 0;
    var timer = null;

    function paint() {
        if (left > 0) {
            btn.disabled = true;
            btn.textContent = label + ' (' + left + 's)';
        } else {
            btn.disabled = false;
            btn.textContent = label;
        }
    }

    function tick() {
        left -= 1;
        paint();
        if (left <= 0 && timer !== null) { clearInterval(timer); timer = null; }
    }

    paint();
    if (left > 0) { timer = setInterval(tick, 1000); }

    // One click only: stops a double-tap from sending two requests.
    btn.form.addEventListener('submit', function () { btn.disabled = true; });

    // Coming back with the browser's Back button: ask the server again (fresh page).
    window.addEventListener('pageshow', function (e) { if (e.persisted) { location.reload(); } });
})();
</script>
<p class="mt-2 text-xs text-slate-500">
    Typed the wrong email or details?
    <a href="<?= e(url('auth/register.php')) ?>" class="font-semibold text-brand-700 underline underline-offset-2 hover:text-brand-800">Register again</a>
    <?= $isWaiting ? '(nothing is saved until you confirm, so you can simply start again).' : '(your unconfirmed sign-up is replaced).' ?>
</p>

<?php require __DIR__ . '/../app/views/layout/auth-bottom.php'; ?>