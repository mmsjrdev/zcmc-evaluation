<?php
/**
 * mms/inbox.php
 *
 * The MMS inbox. Tabs: forms waiting for review (oldest first), forms that are
 * with the Head Nurse, and forms returned to the supplier. Search by bid
 * number, bidder or supplier.
 */

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/domain/mms-forms.php';

$user = require_role(['mm_staff']);

// Which tab? Anything unknown falls back to "Waiting for review".
$tabs = mms_tabs();
$tab  = (string) ($_GET['tab'] ?? 'submitted');
if (!array_key_exists($tab, $tabs)) {
    $tab = 'submitted';
}
$search = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100);

$counts = mms_tab_counts();
$forms  = mms_inbox_list($tab, $search);

$pageTitle = 'Inbox';
require __DIR__ . '/../app/views/layout/header.php';

page_header('Inbox', 'Forms sent by suppliers');
?>

<!-- Tabs: the open tab is a solid button, the others are outline buttons. All three share the same size. -->
<nav class="mb-4 flex flex-wrap gap-2" aria-label="Inbox tabs">
    <?php foreach ($tabs as $status => $label): ?>
        <?php $isCurrent = $status === $tab; ?>
        <a href="<?= e(url('mms/inbox.php?tab=' . $status . ($search !== '' ? '&q=' . urlencode($search) : ''))) ?>"
           <?= $isCurrent ? 'aria-current="page"' : '' ?>
           class="btn <?= $isCurrent ? 'btn-primary' : 'btn-secondary' ?>">
            <?= e($label) ?> (<?= (int) $counts[$status] ?>)
        </a>
    <?php endforeach; ?>
</nav>

<!-- Search -->
<form method="get" action="<?= e(url('mms/inbox.php')) ?>" class="mb-6 flex flex-col gap-2 sm:flex-row">
    <input type="hidden" name="tab" value="<?= e($tab) ?>">
    <label for="q" class="sr-only">Search by bid number, bidder or supplier</label>
    <input id="q" name="q" type="search" value="<?= e($search) ?>" maxlength="100"
           placeholder="Search by bid number, bidder or supplier"
           class="block min-h-11 w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 focus:border-brand-600 focus:outline-none focus:ring-4 focus:ring-brand-200">
    <button type="submit" class="btn btn-primary sm:w-auto">Search</button>
    <?php if ($search !== ''): ?>
        <a href="<?= e(url('mms/inbox.php?tab=' . $tab)) ?>" class="btn btn-secondary sm:w-auto">Clear</a>
    <?php endif; ?>
</form>

<?php if ($forms === []): ?>
    <div class="card text-sm text-slate-600">
        <?= $search !== '' ? 'No forms match your search in this tab.' : 'No forms in this tab right now.' ?>
    </div>
<?php else: ?>
    <ul class="space-y-3">
        <?php foreach ($forms as $f): ?>
            <li>
                <a href="<?= e(url('mms/review.php?id=' . (int) $f['id'])) ?>"
                   class="block rounded-2xl border border-slate-200 bg-white p-4 shadow-sm hover:border-brand-300 hover:bg-brand-50/40 focus:outline-none focus-visible:ring-4 focus-visible:ring-brand-200">
                    <div class="flex flex-wrap items-start justify-between gap-2">
                        <div class="min-w-0">
                            <p class="truncate text-sm font-semibold text-slate-900"><?= e(mms_form_title($f)) ?></p>
                            <p class="truncate text-sm text-slate-600">
                                <?= e($f['business_name'] ?? $f['contact_name']) ?>
                                <?= !empty($f['bidder']) ? ' &middot; Bidder: ' . e($f['bidder']) : '' ?>
                            </p>
                        </div>
                        <?= status_badge($f['status']) ?>
                    </div>
                    <p class="mt-2 text-xs text-slate-500">
                        Submitted <?= e(mms_date_time($f['submitted_at'])) ?>
                        <?php if ($tab === 'submitted' && !empty($f['submitted_at'])): ?>
                            (<?= e(mms_days_ago($f['submitted_at'])) ?>)
                        <?php endif; ?>
                        &middot; Last update <?= e(mms_date_time($f['updated_at'])) ?>
                    </p>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>
    <?php if (count($forms) === 200): ?>
        <p class="mt-3 text-xs text-slate-500">Showing the first 200 forms. Use the search box to narrow the list.</p>
    <?php endif; ?>
<?php endif; ?>

<?php require __DIR__ . '/../app/views/layout/footer.php'; ?>