<?php
/**
 * app/views/layout/header.php
 *
 * The TOP of every signed-in page: opens the HTML, draws the sidebar (a slide-in
 * panel on phones) and the top bar, then opens <main>. footer.php closes it all.
 *
 * Use it like this in a page:
 *
 *   require_once __DIR__ . '/../app/bootstrap.php';
 *   $user = require_role([...]);
 *   $pageTitle = 'My forms';
 *   require __DIR__ . '/../app/views/layout/header.php';
 *   ... page content ...
 *   require __DIR__ . '/../app/views/layout/footer.php';
 *
 * Sign-in, register and similar pages use auth-top.php instead.
 */

require_once __DIR__ . '/../partials/status-badge.php';
require_once __DIR__ . '/../partials/empty-state.php';
require_once __DIR__ . '/../partials/page-header.php';
require_once __DIR__ . '/../partials/form-helpers.php';

$user = current_user();
if ($user === null) {          // safety net: a page forgot require_role()
    redirect('auth/login.php');
}

// "Evaluator" for a Department Head whose request is still waiting for approval.
$roleText = role_label($user['role']);
if ($user['status'] === 'pending' && $user['requested_role'] === 'head') {
    $roleText .= ' (Department Head request pending)';
}

$navUnread = unread_notification_count((int) $user['id']);
$pageTitle = $pageTitle ?? 'ZCMC Evaluation Form System';

require __DIR__ . '/head.php';
?>
<body class="min-h-full bg-slate-50">
<a href="#main" class="sr-only focus:not-sr-only focus:fixed focus:left-3 focus:top-3 focus:z-50 focus:rounded-lg focus:bg-white focus:px-4 focus:py-2 focus:text-sm focus:font-semibold focus:shadow-lg">Skip to content</a>

<!-- Dark overlay behind the slide-in menu (phones only) -->
<div id="sidebar-overlay" class="fixed inset-0 z-30 hidden bg-slate-900/50 lg:hidden" aria-hidden="true"></div>

<!-- Sidebar: always visible from 1024 px up; a slide-in panel below that -->
<aside id="sidebar"
       class="fixed inset-y-0 left-0 z-40 flex w-72 max-w-[85vw] -translate-x-full flex-col bg-brand-950 shadow-xl transition-[translate,visibility] duration-200 max-lg:invisible lg:translate-x-0">
    <div class="flex h-16 shrink-0 items-center gap-3 border-b border-white/10 px-4">
        <img src="<?= e(asset('img/zcmc.png')) ?>" alt="" width="40" height="40" class="h-10 w-10 shrink-0 object-contain">
        <div class="min-w-0 leading-tight">
            <p class="truncate text-sm font-bold text-white">ZCMC Evaluation Forms</p>
            <p class="truncate text-xs text-brand-200">Material Management Section</p>
        </div>
        <button type="button" data-menu-close class="ml-auto rounded-lg p-2 text-brand-100 hover:bg-white/10 focus:outline-none focus-visible:ring-4 focus-visible:ring-brand-300 lg:hidden" aria-label="Close menu">
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/></svg>
        </button>
    </div>
    <?php require_once __DIR__ . '/sidebar.php'; render_sidebar($user, $roleText, $navUnread); ?>
</aside>

<div class="lg:pl-72">
    <!-- Top bar -->
    <header class="sticky top-0 z-20 flex h-16 items-center gap-3 border-b border-slate-200 bg-white/95 px-4 backdrop-blur sm:px-6">
        <button type="button" data-menu-toggle aria-controls="sidebar" aria-expanded="false"
                class="-ml-2 inline-flex h-11 w-11 items-center justify-center rounded-xl text-slate-600 hover:bg-slate-100 focus:outline-none focus-visible:ring-4 focus-visible:ring-brand-200 lg:hidden"
                aria-label="Open menu">
            <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5"/></svg>
        </button>

        <p class="min-w-0 flex-1 truncate text-base font-semibold text-slate-900"><?= e($pageTitle) ?></p>

        <!-- Bell with the unread count -->
        <a href="<?= e(url('common/notifications.php')) ?>"
           class="relative inline-flex h-11 w-11 items-center justify-center rounded-xl text-slate-600 hover:bg-slate-100 focus:outline-none focus-visible:ring-4 focus-visible:ring-brand-200"
           aria-label="Notifications<?= $navUnread > 0 ? ', ' . $navUnread . ' unread' : '' ?>">
            <?= nav_icon('bell') ?>
            <?php if ($navUnread > 0): ?>
                <span class="absolute right-1 top-1 flex min-h-5 min-w-5 items-center justify-center rounded-full bg-red-600 px-1 text-[11px] font-bold leading-none text-white ring-2 ring-white"><?= $navUnread > 99 ? '99+' : $navUnread ?></span>
            <?php endif; ?>
        </a>

        <!-- Name, role and sign out (the phone has Sign out inside the menu) -->
        <div class="hidden items-center gap-3 border-l border-slate-200 pl-3 sm:flex">
            <div class="min-w-0 text-right leading-tight">
                <p class="max-w-48 truncate text-sm font-semibold text-slate-900"><?= e($user['full_name']) ?></p>
                <p class="max-w-48 truncate text-xs text-slate-500"><?= e($roleText) ?></p>
            </div>
            <form method="post" action="<?= e(url('auth/logout.php')) ?>">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-secondary !min-h-10 !px-4">Sign out</button>
            </form>
        </div>
    </header>

    <main id="main" class="mx-auto w-full max-w-6xl px-4 py-6 sm:px-6 sm:py-8">
        <?php show_flash(); ?>
