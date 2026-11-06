<?php
/**
 * auth/pending.php
 *
 * Shown after sign-in to a person whose Department Head request has not been
 * approved yet. Their stored role is "evaluator", so require_role(['evaluator'])
 * is the right guard. They can continue to the dashboard and work as an Evaluator.
 */

require_once __DIR__ . '/../app/bootstrap.php';

$user = require_role(['evaluator']);

// Nothing is pending for this person: go to the dashboard.
if ($user['status'] !== 'pending') {
    redirect('common/dashboard.php');
}

$pageTitle = 'Waiting for approval';
require __DIR__ . '/../app/views/layout/auth-top.php';
?>

<div class="flex items-center gap-3">
    <span class="badge bg-amber-100 text-amber-800">Pending</span>
    <h1 class="text-xl font-semibold text-slate-900">Waiting for approval</h1>
</div>

<p class="mt-3 text-sm text-slate-600">
    Hello, <strong><?= e($user['full_name']) ?></strong>. Your request to be a
    <strong><?= e(role_label((string) $user['requested_role'])) ?></strong>
    is waiting for the administrator to approve it.
</p>
<p class="mt-2 text-sm text-slate-600">
    Until then your account works as an <strong>Evaluator</strong>. You will become a
    Department Head after approval; nothing else is needed from you.
</p>

<div class="mt-5 flex flex-col gap-3">
    <a href="<?= e(url('common/dashboard.php')) ?>" class="btn btn-primary w-full">Continue as Evaluator</a>
    <form method="post" action="<?= e(url('auth/logout.php')) ?>">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn-secondary w-full">Sign out</button>
    </form>
</div>

<?php require __DIR__ . '/../app/views/layout/auth-bottom.php'; ?>
