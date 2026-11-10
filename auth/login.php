<?php
/**
 * auth/login.php
 *
 * Sign in with email + password.
 *  - 5 wrong passwords for one email within 10 minutes locks that email for a while.
 *  - Specific messages: invalid email format, account not found, wrong password.
 *  - Disabled or rejected accounts cannot sign in.
 *  - Step 15: while OTP_ENABLED is true, an account whose email is not confirmed
 *    yet is sent to auth/verify-email.php after the correct password.
 *    Login itself stays email + password.
 */

require_once __DIR__ . '/../app/bootstrap.php';

// Already signed in? Go to your page.
$already = current_user();
if ($already !== null) {
    redirect(landing_path($already));
}

$email  = '';
$error  = '';      // general message (locked, disabled, ...)
$errors = [];      // message per field: $errors['email'], $errors['password']

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $email    = strtolower(trim((string) ($_POST['email'] ?? '')));
    $password = (string) ($_POST['password'] ?? '');

    if ($email === '') {
        $errors['email'] = 'Enter your email.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
        $errors['email'] = 'Enter a valid email address.';
    }
    if ($password === '') {
        $errors['password'] = 'Enter your password.';
    }

    if ($errors) {
        // Nothing else to check until the form is filled in correctly.

    } elseif (too_many_failed_attempts($email)) {
        $error = 'Too many wrong passwords. Wait ' . LOGIN_WINDOW_MINUTES
               . ' minutes and try again, or contact the administrator.';

    } else {
        // Find the account.
        $stmt = db()->prepare('SELECT id, password_hash, status, email_verified_at FROM users WHERE email = :email LIMIT 1');
        $stmt->execute([':email' => $email]);
        $row = $stmt->fetch();

        // Always check a password, even when the email does not exist (see DUMMY_PASSWORD_HASH).
        $hash       = $row ? $row['password_hash'] : DUMMY_PASSWORD_HASH;
        $passwordOk = password_verify($password, $hash);

        // A sign-up that is still waiting for its email code is not in `users` yet.
        $waiting = (!$row && otp_required('register')) ? pending_registration_by_email($email) : null;

        if ($waiting && password_verify($password, (string) $waiting['password_hash'])) {
            // Closed the browser before entering the code? Show the code screen again.
            clear_failed_attempts($email);
            session_regenerate_id(true);
            unset($_SESSION['verify_user_id']);
            $_SESSION['verify_pending_id'] = (int) $waiting['id'];
            redirect('auth/verify-email.php');

        } elseif ($waiting) {
            record_failed_attempt($email);
            $errors['password'] = 'Wrong password.';

        } elseif (!$row) {
            // Still counted, so guessing emails is slowed down by the same lock.
            record_failed_attempt($email);
            $errors['email'] = 'Account not found. Check the email or create an account.';

        } elseif (!$passwordOk) {
            record_failed_attempt($email);
            $errors['password'] = 'Wrong password.';

        } elseif (in_array($row['status'], ['disabled', 'rejected'], true)) {
            $error = 'This account cannot sign in. Contact the administrator.';

        } elseif (otp_required('register') && empty($row['email_verified_at'])) {
            // Correct password, but the email was never confirmed with a code.
            // Do NOT sign in yet: remember who passed the password step and ask for the code.
            clear_failed_attempts($email);
            session_regenerate_id(true);
            $_SESSION['verify_user_id'] = (int) $row['id'];
            redirect('auth/verify-email.php');

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
               value="<?= e($email) ?>" class="<?= e(input_class($errors, 'email')) ?>">
        <?php field_error($errors, 'email'); ?>
    </div>

    <div>
        <label for="password" class="mb-1 block text-sm font-medium text-slate-700">Password</label>
        <div class="pw-wrap">
            <input id="password" name="password" type="password" required
                   autocomplete="current-password" class="<?= e(input_class($errors, 'password')) ?>">
            <!-- Show/Hide password: phones only (styled in the <style> block below). Not a submit button, no name, never sent. -->
            <button type="button" id="password-toggle" class="pw-toggle"
                    aria-label="Show password" aria-pressed="false" aria-controls="password">
                <!-- eye-slash = password is hidden now -->
                <svg data-icon="hidden" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 0 0 1.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.451 10.451 0 0 1 12 4.5c4.756 0 8.773 3.162 10.065 7.498a10.522 10.522 0 0 1-4.293 5.774M6.228 6.228 3 3m3.228 3.228 3.65 3.65m7.894 7.894L21 21m-3.228-3.228-3.65-3.65m0 0a3 3 0 1 0-4.243-4.243m4.242 4.242L9.88 9.88"/>
                </svg>
                <!-- eye = password is visible now -->
                <svg data-icon="visible" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z"/>
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/>
                </svg>
            </button>
        </div>
        <?php field_error($errors, 'password'); ?>
    </div>

    <button type="submit" class="btn btn-primary w-full">Sign in</button>
</form>

<style>
    /* Show/Hide password button. Self-contained on purpose, so it does not depend on
       which Tailwind classes happen to be compiled into css/app.css. Phones only. */
    .pw-wrap { position: relative; }
    .pw-toggle {
        display: none; position: absolute; top: 0; bottom: 0; right: 0; width: 3rem;
        align-items: center; justify-content: center;
        padding: 0; border: 0; background: transparent; border-radius: .75rem;
        color: #64748b; cursor: pointer; -webkit-tap-highlight-color: transparent;
    }
    .pw-toggle:hover { color: #334155; }
    .pw-toggle:focus-visible { outline: 2px solid #168049; outline-offset: -2px; }
    .pw-toggle svg { display: block; width: 1.25rem; height: 1.25rem; }
    .pw-toggle [data-icon="visible"] { display: none; }
    .pw-toggle.is-visible [data-icon="hidden"] { display: none; }
    .pw-toggle.is-visible [data-icon="visible"] { display: block; }
    @media (max-width: 767px) {
        .pw-toggle { display: flex; }
        #password { padding-right: 3rem; }
    }
</style>
<script>
(function () {
    var input = document.getElementById('password');
    var btn   = document.getElementById('password-toggle');
    if (!input || !btn) { return; }

    // Keep the keyboard open and the caret in the field when the button is pressed.
    btn.addEventListener('mousedown', function (e) { e.preventDefault(); });

    btn.addEventListener('click', function () {
        var show = input.type === 'password';
        input.type = show ? 'text' : 'password';
        btn.setAttribute('aria-pressed', show ? 'true' : 'false');
        btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
        btn.classList.toggle('is-visible', show);
        input.focus();
    });
})();
</script>

<div class="mt-5 space-y-2 border-t border-slate-100 pt-4 text-sm text-slate-600">
    <p><span class="font-medium text-slate-700">Forgot your password?</span> Contact the administrator to reset it.</p>
    <p>New evaluator or department head?
        <a href="<?= e(url('auth/register.php')) ?>" class="font-semibold text-brand-700 underline underline-offset-2 hover:text-brand-800">Create an account</a>
    </p>
    <p class="text-xs text-slate-500">Suppliers: your account is created for you by the Material Management Section.</p>
</div>

<?php require __DIR__ . '/../app/views/layout/auth-bottom.php'; ?>