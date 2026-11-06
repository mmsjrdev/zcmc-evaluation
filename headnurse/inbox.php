<?php
/**
 * headnurse/inbox.php
 *
 * The Head Nurse inbox. Tab "Waiting for distribution": forms with status
 * with_head_nurse, oldest first. Tab "Distributed": forms she sent on, with
 * their current status.
 */

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/domain/headnurse-forms.php';

$user = require_role(['head_nurse']);

$tabs = hn_tabs();
$tab  = (string) ($_GET['tab'] ?? 'waiting');
if (!array_key_exists($tab, $tabs)) {
    $tab = 'waiting';
}

$counts = hn_counts((int) $user['id']);
$forms  = $tab === 'waiting' ? hn_waiting_list() : hn_distributed_list((int) $user['id']);

$pageTitle = 'Inbox';
require __DIR__ . '/../app/views/layout/header.php';

page_header('Inbox', 'Forms to send to a department and an evaluator');
?>

<!-- Tabs: the open tab is a solid button, the other is an outline button -->
<nav class="mb-6 flex flex-wrap gap-2" aria-label="Inbox tabs">
    <?php foreach ($tabs as $key => $label): ?>
        <?php $isCurrent = $key === $tab; ?>
        <a href="<?= e(url('headnurse/inbox.php?tab=' . $key)) ?>"
           <?= $isCurrent ? 'aria-current="page"' : '' ?>
           class="btn <?= $isCurrent ? 'btn-primary' : 'btn-secondary' ?>">
            <?= e($label) ?> (<?= (int) $counts[$key] ?>)
        </a>
    <?php endforeach; ?>
</nav>

<?php if ($forms === []): ?>
    <div class="card text-sm text-slate-600">
        <?= $tab === 'waiting' ? 'No forms are waiting for distribution right now.' : 'You have not distributed any forms yet.' ?>
    </div>
<?php else: ?>
    <ul class="space-y-3">
        <?php foreach ($forms as $f): ?>
            <li>
                <a href="<?= e(url('headnurse/distribute.php?id=' . (int) $f['id'])) ?>"
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

                    <?php if ($tab === 'waiting'): ?>
                        <p class="mt-2 text-xs text-slate-500">
                            <?= (int) $f['item_count'] ?> item<?= (int) $f['item_count'] === 1 ? '' : 's' ?>
                            &middot; With you since <?= e(mms_date_time($f['updated_at'])) ?> (<?= e(mms_days_ago($f['updated_at'])) ?>)
                        </p>
                    <?php else: ?>
                        <p class="mt-2 text-xs text-slate-500">
                            <?= e($f['department_name'] ?? '-') ?>
                            &middot; <?= !empty($f['evaluator_name']) ? e($f['evaluator_name']) : 'Department pool (not claimed yet)' ?>
                            &middot; Sent <?= e(mms_date_time($f['distributed_at'])) ?>
                        </p>
                    <?php endif; ?>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>
    <?php if (count($forms) === 200): ?>
        <p class="mt-3 text-xs text-slate-500">Showing the first 200 forms.</p>
    <?php endif; ?>
<?php endif; ?>

<?php require __DIR__ . '/../app/views/layout/footer.php'; ?>