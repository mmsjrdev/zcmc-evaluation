<?php
/**
 * auth/login.php
 *
 * Sign in with email + password.
 *  - 5 wrong passwords for one email within 10 minutes locks that email for a while.
 *  - Wrong email and wrong password show the SAME message.
 *  - Disabled or rejected accounts cannot sign in.
 */

require_once __DIR__ . '/../app/bootstrap.php';

// Already signed in? Go to your page.
$already = current_user();
if ($already !== null) {
    redirect(landing_path($already));
}

$email = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $email    = strtolower(trim((string) ($_POST['email'] ?? '')));
    $password = (string) ($_POST['password'] ?? '');

    if ($email === '' || $password === '') {
        $error = 'Enter your email and your password.';

    } elseif (too_many_failed_attempts($email)) {
        $error = 'Too many wrong passwords. Wait ' . LOGIN_WINDOW_MINUTES
               . ' minutes and try again, or contact the administrator.';

    } else {
        // Find the account.
        $stmt = db()->prepare('SELECT id, password_hash, status FROM users WHERE email = :email LIMIT 1');
        $stmt->execute([':email' => $email]);
        $row = $stmt->fetch();

        // Always check a password, even when the email does not exist (see DUMMY_PASSWORD_HASH).
        $hash       = $row ? $row['password_hash'] : DUMMY_PASSWORD_HASH;
        $passwordOk = password_verify($password, $hash);

        if (!$row || !$passwordOk) {
            record_failed_attempt($email);
            $error = 'Wrong email or password.';

        } elseif (in_array($row['status'], ['disabled', 'rejected'], true)) {
            $error = 'This account cannot sign in. Contact the administrator.';

        } elseif (otp_required('login')) {
            // Step 15 will send and check the one-time code here.
            // Until it is built we refuse to sign in, so switching OTP_ENABLED on
            // by mistake can never skip the check.
            $error = 'One-time password sign-in is turned on but is not built yet.';

        } else {
            // Correct password: keep the hash up to date with the newest PHP algorithm.
            if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
                $upd = db()->prepare('UPDATE users SET password_hash = :hash WHERE id = :id');
                $upd->execute([':hash' => password_hash($password, PASSWORD_DEFAULT), ':id' => $row['id']]);
            }

            clear_failed_attempts($email);
            login_user(['id' => $row['id']]);
            audit_write((int) $row['id'], 'login', 'Signed in');

            redirect(landing_path(current_user()));
        }
    }
}

$pageTitle = 'Sign in';
require __DIR__ . '/../app/views/layout/auth-top.php';
?>

<h1 class="text-xl font-semibold text-slate-900">Sign in</h1>
<p class="mt-1 text-sm text-slate-500">Use the email and password of your account.</p>

<?php if ($error !== ''): ?>
    <div role="alert" class="mt-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-800">
        <?= e($error) ?>
    </div>
<?php endif; ?>

<form method="post" action="<?= e(url('auth/login.php')) ?>" class="mt-5 space-y-4">
    <?= csrf_field() ?>

    <div>
        <label for="email" class="mb-1 block text-sm font-medium text-slate-700">Email</label>
        <input id="email" name="email" type="email" required maxlength="190"
               autocomplete="username" autofocus
               value="<?= e($email) ?>" class="input">
    </div>

    <div>
        <label for="password" class="mb-1 block text-sm font-medium text-slate-700">Password</label>
        <input id="password" name="password" type="password" required
               autocomplete="current-password" class="input">
    </div>

    <button type="submit" class="btn btn-primary w-full">Sign in</button>
</form>

<div class="mt-5 space-y-2 border-t border-slate-100 pt-4 text-sm text-slate-600">
    <p><span class="font-medium text-slate-700">Forgot your password?</span> Contact the administrator to reset it.</p>
    <p>New evaluator or department head?
        <a href="<?= e(url('auth/register.php')) ?>" class="font-semibold text-brand-700 underline underline-offset-2 hover:text-brand-800">Create an account</a>
    </p>
    <p class="text-xs text-slate-500">Suppliers: your account is created for you by the Material Management Section.</p>
</div>

<?php require __DIR__ . '/../app/views/layout/auth-bottom.php'; ?>
