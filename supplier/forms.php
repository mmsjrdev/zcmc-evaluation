<?php
/**
 * supplier/forms.php
 *
 * "My Forms": the supplier's own forms with status badges and a status filter.
 * Only the supplier's own rows are read (WHERE supplier_id = me).
 */

require_once __DIR__ . '/../app/bootstrap.php';

$user   = require_role(['supplier']);
$userId = (int) $user['id'];

// ---- Filter -----------------------------------------------------------------
// Groups the supplier thinks in, mapped to real statuses.
$filters = [
    ''          => ['All',            []],
    'drafts'    => ['Drafts',         ['supplier_draft']],
    'returned'  => ['Returned to me', ['returned_supplier']],
    'progress'  => ['In progress',    ['submitted', 'with_head_nurse', 'pending_evaluation', 'pending_head', 'returned_evaluator']],
    'conforme'  => ['Needs my Conforme', ['pending_conforme']],
    'done'      => ['Completed',      ['completed', 'received']],
    'cancelled' => ['Cancelled',      ['cancelled']],
];
$filter = (string) ($_GET['filter'] ?? '');
if (!isset($filters[$filter])) {
    $filter = '';
}
$statuses = $filters[$filter][1];

$sql    = 'SELECT e.id, e.bid_ref_no, e.opening_date, e.status, e.updated_at, m.name AS mode_name,
                  (SELECT COUNT(*) FROM evaluation_items i WHERE i.evaluation_id = e.id) AS item_count
             FROM evaluations e
             LEFT JOIN procurement_modes m ON m.id = e.mode_id
            WHERE e.supplier_id = ?';
$params = [$userId];
if ($statuses !== []) {
    $sql   .= ' AND e.status IN (' . implode(',', array_fill(0, count($statuses), '?')) . ')';
    $params = array_merge($params, $statuses);
}
$sql .= ' ORDER BY e.updated_at DESC, e.id DESC LIMIT 100';

$stmt = db()->prepare($sql);
$stmt->execute($params);
$forms = $stmt->fetchAll();

// ---- Page -------------------------------------------------------------------
$pageTitle = 'My forms';
require __DIR__ . '/../app/views/layout/header.php';

page_header('My forms', 'Evaluation forms you created.', 'supplier/form-edit.php', 'New form');
?>

<nav class="-mx-4 mb-4 flex gap-2 overflow-x-auto px-4 pb-1 sm:mx-0 sm:flex-wrap sm:px-0" aria-label="Filter by status">
    <?php foreach ($filters as $key => [$label]): ?>
        <a href="<?= e(url('supplier/forms.php' . ($key !== '' ? '?filter=' . $key : ''))) ?>"
           class="inline-flex min-h-10 shrink-0 items-center rounded-full border px-4 text-sm font-medium focus:outline-none focus-visible:ring-4 focus-visible:ring-brand-200
                  <?= $key === $filter ? 'border-brand-600 bg-brand-600 text-white' : 'border-slate-300 bg-white text-slate-700 hover:bg-slate-50' ?>"
           <?= $key === $filter ? 'aria-current="true"' : '' ?>><?= e($label) ?></a>
    <?php endforeach; ?>
</nav>

<?php if ($forms === []): ?>
    <?php if ($filter === ''): ?>
        <?php empty_state('No forms yet', 'Start your first evaluation form.', 'supplier/form-edit.php', 'New form'); ?>
    <?php else: ?>
        <?php empty_state('Nothing in this filter', 'Try another status above.'); ?>
    <?php endif; ?>
<?php else: ?>
    <ul class="card divide-y divide-slate-100 !p-0 overflow-hidden">
        <?php foreach ($forms as $f): ?>
            <?php
            $editable = in_array($f['status'], ['supplier_draft', 'returned_supplier'], true);
            $target   = ($editable ? 'supplier/form-edit.php?id=' : 'supplier/form-view.php?id=') . (int) $f['id'];
            ?>
            <li class="flex flex-col gap-2 px-4 py-3 sm:flex-row sm:items-center sm:justify-between sm:px-6">
                <div class="min-w-0">
                    <p class="truncate text-sm font-semibold text-slate-900">
                        <a href="<?= e(url('supplier/form-view.php?id=' . (int) $f['id'])) ?>" class="hover:underline focus:outline-none focus-visible:underline">
                            <?= e($f['bid_ref_no'] !== '' ? $f['bid_ref_no'] : 'No bid number yet') ?>
                        </a>
                    </p>
                    <p class="truncate text-xs text-slate-500">
                        <?= e($f['mode_name'] ?? 'No mode chosen') ?>
                        &middot; <?= (int) $f['item_count'] ?> item<?= (int) $f['item_count'] === 1 ? '' : 's' ?>
                        &middot; updated <?= e(date('M j, Y', strtotime((string) $f['updated_at']))) ?>
                    </p>
                </div>
                <div class="flex shrink-0 items-center gap-3">
                    <?= status_badge($f['status']) ?>
                    <a href="<?= e(url($target)) ?>" class="btn btn-secondary !min-h-10 !px-4"><?= $editable ? 'Edit' : 'View' ?></a>
                </div>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

<?php require __DIR__ . '/../app/views/layout/footer.php'; ?>
