<?php
/**
 * auth/logout.php
 *
 * Signing out is done with a POST form (with the CSRF token), so another
 * website cannot sign you out by linking to this page.
 * Opening the page directly (GET) shows a small "Sign out?" card.
 */

require_once __DIR__ . '/../app/bootstrap.php';

$user = require_role(all_roles());

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    audit_write((int) $user['id'], 'logout', 'Signed out');
    logout_user();
    flash('success', 'You have been signed out.');
    redirect('auth/login.php');
}

$pageTitle = 'Sign out';
require __DIR__ . '/../app/views/layout/auth-top.php';
?>

<h1 class="text-xl font-semibold text-slate-900">Sign out?</h1>
<p class="mt-1 text-sm text-slate-500">You are signed in as <?= e($user['full_name']) ?>.</p>

<form method="post" action="<?= e(url('auth/logout.php')) ?>" class="mt-5 space-y-3">
    <?= csrf_field() ?>
    <button type="submit" class="btn btn-primary w-full">Sign out</button>
    <a href="<?= e(url('common/dashboard.php')) ?>" class="btn btn-secondary w-full">Cancel</a>
</form>

<?php require __DIR__ . '/../app/views/layout/auth-bottom.php'; ?>
