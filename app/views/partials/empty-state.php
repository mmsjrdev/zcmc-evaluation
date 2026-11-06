<?php
/**
 * app/views/partials/empty-state.php
 *
 * A friendly card for "nothing here yet".
 * Usage:
 *   empty_state('No forms yet', 'Forms you create will appear here.');
 *   empty_state('No forms yet', 'Start your first one.', 'supplier/form-edit.php', 'New form');
 *
 * $actionPath is a path inside the project (url() is added for you).
 */

function empty_state(string $title, string $text = '', string $actionPath = '', string $actionLabel = ''): void
{
    ?>
    <div class="card flex flex-col items-center px-6 py-10 text-center">
        <span class="mb-3 flex h-12 w-12 items-center justify-center rounded-full bg-brand-50 text-brand-600" aria-hidden="true">
            <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                <path stroke-linecap="round" stroke-linejoin="round" d="M20 13V7a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v6m16 0v4a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2v-4m16 0h-5l-1 2h-4l-1-2H4"/>
            </svg>
        </span>
        <h2 class="text-base font-semibold text-slate-900"><?= e($title) ?></h2>
        <?php if ($text !== ''): ?>
            <p class="mt-1 max-w-sm text-sm text-slate-500"><?= e($text) ?></p>
        <?php endif; ?>
        <?php if ($actionPath !== '' && $actionLabel !== ''): ?>
            <a href="<?= e(url($actionPath)) ?>" class="btn btn-primary mt-5"><?= e($actionLabel) ?></a>
        <?php endif; ?>
    </div>
    <?php
}
