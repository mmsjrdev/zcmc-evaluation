<?php
/**
 * supplier/form-view.php?id=N
 *
 * Read-only view of ONE form for the supplier who owns it: the supplier part
 * plus a status timeline. No evaluator data is read or shown here.
 */

require_once __DIR__ . '/../app/bootstrap.php';

$user = require_role(['supplier']);
$id   = (int) ($_GET['id'] ?? 0);

$eval = $id > 0 ? supplier_load_form($id) : null;
// can_view_evaluation(): a supplier sees only their own forms. A form that does
// not exist gets the same "access denied" so ids cannot be probed.
if (!$eval || !can_view_evaluation($user, $eval)) {
    forbidden();
}

$items   = supplier_load_items($id);
$history = supplier_load_history($id);
$canEdit = can_edit_supplier_part($user, $eval);

// The latest reason MMS gave when it returned or cancelled the form.
$returnRemarks = '';
$cancelRemarks = '';
foreach (array_reverse($history) as $h) {
    if ($returnRemarks === '' && $h['to_status'] === 'returned_supplier') {
        $returnRemarks = (string) ($h['remarks'] ?? '');
    }
    if ($cancelRemarks === '' && $h['to_status'] === 'cancelled') {
        $cancelRemarks = (string) ($h['remarks'] ?? '');
    }
}

/** A date from the database as "Nov 6, 2026", or a dash. */
function show_date(?string $value): string
{
    return ($value !== null && $value !== '') ? date('M j, Y', strtotime($value)) : '-';
}

/**
 * The timeline line a supplier reads for one status. Only the steps the
 * supplier should know about get their own words; the rest use the status name.
 */
function supplier_timeline_title(string $status): string
{
    switch ($status) {
        case 'submitted':         return 'Submitted to MMS';
        case 'with_head_nurse':   return 'Forwarded by MMS for evaluation';
        case 'returned_supplier': return 'Returned by MMS';
        case 'cancelled':         return 'Cancelled by MMS';
    }
    return status_label($status);
}

$pageTitle = 'Form ' . ($eval['bid_ref_no'] !== '' ? $eval['bid_ref_no'] : '#' . $id);
require __DIR__ . '/../app/views/layout/header.php';

page_header(
    $eval['bid_ref_no'] !== '' ? $eval['bid_ref_no'] : 'Form #' . $id,
    'Evaluation Form ZCMC-F-PROC-29',
    $canEdit ? 'supplier/form-edit.php?id=' . $id : '',
    $canEdit ? ($eval['status'] === 'returned_supplier' ? 'Edit and resubmit' : 'Edit form') : ''
);
?>

<?php if ($eval['status'] === 'returned_supplier'): ?>
    <div role="alert" class="mb-6 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-900">
        <p class="font-semibold">MMS returned this form to you.</p>
        <?php if ($returnRemarks !== ''): ?>
            <p class="mt-1 whitespace-pre-line"><?= e($returnRemarks) ?></p>
        <?php endif; ?>
    </div>
<?php elseif ($eval['status'] === 'cancelled'): ?>
    <div role="alert" class="mb-6 rounded-xl border border-slate-300 bg-slate-100 px-4 py-3 text-sm text-slate-800">
        <p class="font-semibold">MMS cancelled this form.</p>
        <?php if ($cancelRemarks !== ''): ?>
            <p class="mt-1 whitespace-pre-line"><?= e($cancelRemarks) ?></p>
        <?php endif; ?>
    </div>
<?php endif; ?>

<div class="grid gap-6 lg:grid-cols-3">
    <div class="space-y-6 lg:col-span-2">

        <section class="card">
            <div class="flex items-start justify-between gap-3">
                <h2 class="text-base font-semibold text-slate-900">Header</h2>
                <?= status_badge($eval['status']) ?>
            </div>
            <dl class="mt-4 grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
                <div><dt class="text-slate-500">Bid/Reference No.</dt><dd class="font-medium text-slate-900"><?= e($eval['bid_ref_no'] !== '' ? $eval['bid_ref_no'] : '-') ?></dd></div>
                <div><dt class="text-slate-500">Mode of Procurement</dt><dd class="font-medium text-slate-900"><?= e($eval['mode_name'] ?? '-') ?></dd></div>
                <div><dt class="text-slate-500">Date of Opening</dt><dd class="font-medium text-slate-900"><?= e(show_date($eval['opening_date'])) ?></dd></div>
                <div><dt class="text-slate-500">Bidder</dt><dd class="font-medium text-slate-900"><?= e($eval['bidder'] ?? '-') ?></dd></div>
                <div><dt class="text-slate-500">Date of Evaluation</dt><dd class="font-medium text-slate-900"><?= e(show_date($eval['eval_date'])) ?></dd></div>
                <div><dt class="text-slate-500">Item No.</dt><dd class="font-medium text-slate-900"><?= e($eval['header_item_no'] ?? '-') ?></dd></div>
                <?php if (!empty($eval['ref_no'])): ?>
                    <div><dt class="text-slate-500">Reference number</dt><dd class="font-medium text-slate-900"><?= e($eval['ref_no']) ?></dd></div>
                <?php endif; ?>
            </dl>
        </section>

        <section class="card">
            <h2 class="text-base font-semibold text-slate-900">Section A: Items</h2>
            <?php if ($items === []): ?>
                <p class="mt-3 text-sm text-slate-500">No items yet.</p>
            <?php else: ?>
                <div class="mt-4 space-y-4">
                    <?php foreach ($items as $n => $row): ?>
                        <div class="rounded-xl border border-slate-200 bg-slate-50/60 p-4">
                            <h3 class="text-sm font-semibold text-slate-900">Item <?= (int) $n + 1 ?> &middot; No. <?= e($row['item_no']) ?></h3>
                            <dl class="mt-3 grid gap-4 text-sm md:grid-cols-2">
                                <div><dt class="text-slate-500">Technical Specifications</dt><dd class="mt-0.5 whitespace-pre-line text-slate-900"><?= e($row['specifications'] ?? '') ?></dd></div>
                                <div><dt class="text-slate-500">Bidder's Specification/Brand</dt><dd class="mt-0.5 whitespace-pre-line text-slate-900"><?= e($row['bidder_spec'] ?? '') ?></dd></div>
                            </dl>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </div>

    <!-- Timeline -->
    <aside>
        <section class="card">
            <h2 class="text-base font-semibold text-slate-900">Progress</h2>
            <ol class="mt-4 space-y-4 border-l-2 border-slate-200 pl-4">
                <?php foreach (array_reverse($history) as $h): ?>
                    <li class="relative">
                        <span class="absolute -left-[1.4rem] top-1.5 h-2.5 w-2.5 rounded-full <?= $h['to_status'] === 'cancelled' ? 'bg-slate-500' : ($h['to_status'] === 'returned_supplier' ? 'bg-rose-500' : 'bg-brand-600') ?> ring-4 ring-white" aria-hidden="true"></span>
                        <p class="text-sm font-semibold text-slate-900"><?= e(supplier_timeline_title($h['to_status'])) ?></p>
                        <p class="text-xs text-slate-500"><?= e(date('M j, Y g:i A', strtotime((string) $h['created_at']))) ?></p>
                        <?php // Remarks are shown only for MMS returns and cancellations (other remarks are internal). ?>
                        <?php if (in_array($h['to_status'], ['returned_supplier', 'cancelled'], true) && !empty($h['remarks'])): ?>
                            <p class="mt-1 whitespace-pre-line text-sm text-slate-600"><?= e($h['remarks']) ?></p>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
                <li class="relative">
                    <span class="absolute -left-[1.4rem] top-1.5 h-2.5 w-2.5 rounded-full bg-slate-300 ring-4 ring-white" aria-hidden="true"></span>
                    <p class="text-sm font-semibold text-slate-900">Form created</p>
                    <p class="text-xs text-slate-500"><?= e(date('M j, Y g:i A', strtotime((string) $eval['created_at']))) ?></p>
                </li>
            </ol>
        </section>
    </aside>
</div>

<p class="mt-6"><a href="<?= e(url('supplier/forms.php')) ?>" class="text-sm font-semibold text-brand-700 underline underline-offset-2 hover:text-brand-800">&larr; Back to my forms</a></p>

<?php require __DIR__ . '/../app/views/layout/footer.php'; ?>