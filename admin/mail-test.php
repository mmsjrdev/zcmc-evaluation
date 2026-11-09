<?php
/**
 * admin/mail-test.php
 *
 * Step 15: sends one test email so the admin can check the SMTP settings
 * in config/config.php before OTP_ENABLED is turned on.
 * Works even while OTP_ENABLED is false. Shows the host, port, user and
 * sender, but never the password.
 */

require_once __DIR__ . '/../app/bootstrap.php';

$user = require_role(['admin']);

$to     = (string) ($user['email'] ?? '');
$result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $to = strtolower(trim((string) ($_POST['to'] ?? '')));

    if (!filter_var($to, FILTER_VALIDATE_EMAIL) || strlen($to) > 190) {
        $result = ['ok' => false, 'error' => 'Enter a valid email address.'];
    } else {
        $result = mail_send(
            $to,
            'ZCMC Test',
            'ZCMC Evaluation System: email test',
            "This is a test email from the ZCMC Evaluation Form System.\n\nIf you can read this, the SMTP settings work."
        );
        audit_write((int) $user['id'], 'mail_test', ($result['ok'] ? 'Sent' : 'Failed: ' . $result['error']) . ' (to ' . $to . ')');
    }
}

$pageTitle = 'Email test';
require __DIR__ . '/../app/views/layout/header.php';

page_header($pageTitle, 'Check that the one-time code email works.');
?>

<section class="card mb-4">
    <h2 class="text-base font-semibold text-slate-900">Current settings</h2>
    <dl class="mt-3 grid gap-3 text-sm sm:grid-cols-2">
        <div><dt class="text-slate-500">OTP_ENABLED</dt><dd class="font-medium text-slate-900"><?= OTP_ENABLED ? 'true' : 'false' ?></dd></div>
        <div><dt class="text-slate-500">PHPMailer installed</dt><dd class="font-medium text-slate-900"><?= mail_library_installed() ? 'Yes' : 'No (vendor/autoload.php is missing)' ?></dd></div>
        <div><dt class="text-slate-500">SMTP_HOST</dt><dd class="font-medium text-slate-900"><?= e(defined('SMTP_HOST') ? (string) SMTP_HOST : '(not set)') ?></dd></div>
        <div><dt class="text-slate-500">SMTP_PORT</dt><dd class="font-medium text-slate-900"><?= e(defined('SMTP_PORT') ? (string) SMTP_PORT : '(not set)') ?></dd></div>
        <div><dt class="text-slate-500">SMTP_USER</dt><dd class="font-medium text-slate-900"><?= e(defined('SMTP_USER') ? (string) SMTP_USER : '(not set)') ?></dd></div>
        <div><dt class="text-slate-500">SMTP_FROM</dt><dd class="font-medium text-slate-900"><?= e(defined('SMTP_FROM') ? (string) SMTP_FROM : '(not set)') ?></dd></div>
    </dl>
    <?php if (!mail_is_configured()): ?>
        <p class="mt-3 rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900">
            Some SMTP settings are empty. Fill them in config/config.php first.
        </p>
    <?php endif; ?>
</section>

<section class="card mb-4">
    <h2 class="text-base font-semibold text-slate-900">Send a test email</h2>

    <?php if ($result !== null && $result['ok']): ?>
        <p role="status" class="mt-3 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800">
            Sent. Check the inbox (and the spam folder) of <?= e($to) ?>.
        </p>
    <?php elseif ($result !== null): ?>
        <div role="alert" class="mt-3 rounded-xl border border-rose-300 bg-rose-50 px-4 py-3 text-sm text-rose-800">
            <p class="font-semibold">The email was not sent.</p>
            <p class="mt-1"><?= e($result['error']) ?></p>
        </div>
    <?php endif; ?>

    <form method="post" action="<?= e(url('admin/mail-test.php')) ?>" class="mt-4 space-y-4">
        <?= csrf_field() ?>
        <div>
            <label for="to" class="block text-sm font-medium text-slate-700">Send to</label>
            <input type="email" id="to" name="to" required maxlength="190" value="<?= e($to) ?>"
                   class="mt-1 block min-h-11 w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 focus:border-brand-600 focus:outline-none focus:ring-4 focus:ring-brand-200">
        </div>
        <button type="submit" class="btn btn-primary w-full sm:w-auto">Send test email</button>
    </form>
</section>

<?php require __DIR__ . '/../app/views/layout/footer.php'; ?>