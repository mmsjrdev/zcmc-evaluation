<?php
/**
 * app/views/layout/auth-top.php
 *
 * The TOP of every "centered card" page (login, register, password, ...).
 * It opens the page and the white card. auth-bottom.php closes them.
 *
 * Before including it, a page may set:
 *   $pageTitle  the text in the browser tab (default below)
 *   $maxWidth   a Tailwind width class for the card, e.g. 'max-w-lg' (default 'max-w-md')
 */

$pageTitle = $pageTitle ?? 'ZCMC Evaluation Form System';
$maxWidth  = $maxWidth ?? 'max-w-md';

require_once __DIR__ . '/../partials/form-helpers.php';
?>
<?php require __DIR__ . '/head.php'; ?>
<body class="min-h-full">
<main class="mx-auto flex min-h-screen w-full <?= e($maxWidth) ?> flex-col justify-center px-4 py-8 sm:py-12">

    <header class="mb-6 text-center">
        <img src="<?= e(asset('img/zcmc.png')) ?>" alt="" width="72" height="72" class="mx-auto mb-3 h-[72px] w-[72px] object-contain">
        <p class="text-sm font-semibold uppercase tracking-wide text-brand-700">Zamboanga City Medical Center</p>
        <p class="mt-1 text-xl font-bold text-slate-900 sm:text-2xl">Evaluation Form System</p>
        <p class="mt-0.5 text-xs text-slate-500">Form ZCMC-F-PROC-29 &middot; Material Management Section</p>
    </header>

    <?php show_flash(); ?>

    <section class="card">
