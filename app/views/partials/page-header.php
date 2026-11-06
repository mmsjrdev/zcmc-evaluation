<?php
/**
 * app/views/partials/page-header.php
 *
 * The card at the top of a page: title, a short line under it and (optionally)
 * one button on the right.
 * Usage:
 *   page_header('My forms', 'Forms you created.');
 *   page_header('My forms', 'Forms you created.', 'supplier/form-edit.php', 'New form');
 */

function page_header(string $title, string $subtitle = '', string $actionPath = '', string $actionLabel = ''): void
{
    ?>
    <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div class="min-w-0">
            <h1 class="text-2xl font-bold tracking-tight text-slate-900"><?= e($title) ?></h1>
            <?php if ($subtitle !== ''): ?>
                <p class="mt-1 text-sm text-slate-500"><?= e($subtitle) ?></p>
            <?php endif; ?>
        </div>
        <?php if ($actionPath !== '' && $actionLabel !== ''): ?>
            <a href="<?= e(url($actionPath)) ?>" class="btn btn-primary shrink-0"><?= e($actionLabel) ?></a>
        <?php endif; ?>
    </div>
    <?php
}

/** A number card for dashboards. Usage: stat_card('Waiting for you', 4, 'Forms to forward', 'mms/inbox.php'); */
function stat_card(string $label, int $value, string $hint = '', string $path = ''): void
{
    // Link the card only when the target page already exists (later Steps add them).
    $href = ($path !== '' && is_file(__DIR__ . '/../../../' . strtok($path, '?'))) ? url($path) : '';
    $tag  = $href !== '' ? 'a' : 'div';
    ?>
    <<?= $tag ?><?= $href !== '' ? ' href="' . e($href) . '"' : '' ?> class="stat-card<?= $href !== '' ? ' stat-card-link' : '' ?>">
        <span class="text-sm font-medium text-slate-500"><?= e($label) ?></span>
        <span class="mt-1 text-3xl font-bold tracking-tight text-slate-900"><?= $value ?></span>
        <?php if ($hint !== ''): ?>
            <span class="mt-1 text-xs text-slate-500"><?= e($hint) ?></span>
        <?php endif; ?>
    </<?= $tag ?>>
    <?php
}
