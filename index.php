<?php
/**
 * index.php
 *
 * The front door. It shows nothing itself, it only sends the visitor on:
 *   - not signed in         -> the login page
 *   - signed in             -> their own page (dashboard, or the password page
 *                              if a temporary password must be changed first)
 *
 * If the database is not reachable (MySQL stopped in XAMPP, wrong settings in
 * config/config.php) a short help message is shown instead of a PHP error.
 */

require_once __DIR__ . '/app/bootstrap.php';

try {
    $user = current_user();
} catch (PDOException $ex) {
    http_response_code(503);
    $pageTitle = 'Cannot reach the database';
    require __DIR__ . '/app/views/layout/auth-top.php';
    ?>
    <h1 class="text-xl font-semibold text-slate-900">The system cannot reach its database</h1>
    <p class="mt-2 text-sm text-slate-600">
        Check that MySQL is running in the XAMPP Control Panel and that the database
        settings in <code class="rounded bg-slate-100 px-1.5 py-0.5 text-xs">config/config.php</code> are correct, then reload this page.
    </p>
    <?php if (ENV === 'local'): ?>
        <p class="mt-3 break-words rounded-xl bg-slate-50 px-4 py-3 text-xs text-slate-500"><?= e($ex->getMessage()) ?></p>
    <?php endif; ?>
    <a href="<?= e(url('')) ?>" class="btn btn-primary mt-5 w-full">Try again</a>
    <?php
    require __DIR__ . '/app/views/layout/auth-bottom.php';
    exit;
}

redirect($user !== null ? landing_path($user) : 'auth/login.php');