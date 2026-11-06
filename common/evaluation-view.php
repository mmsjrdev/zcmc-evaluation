<?php
/**
 * common/evaluation-view.php?id=N
 *
 * Read-only full view of a form including the EVALUATOR data (findings,
 * grounds, Sections B and C). Allowed: evaluator, head, admin, mm_staff,
 * head_nurse, each only through can_view_evaluation().
 * The supplier role is always refused: suppliers must never receive evaluator
 * data. They keep supplier/form-view.php.
 */

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/domain/evaluator-forms.php';

// 'supplier' is NOT in this list, so a supplier gets forbidden() here.
$user = require_role(['evaluator', 'head', 'admin', 'mm_staff', 'head_nurse']);

$id   = (int) ($_GET['id'] ?? 0);
$eval = $id > 0 ? ev_load_full_form($id) : null;

if (!$eval || !can_view_evaluation($user, $eval)) {
    forbidden();
}

$items   = ev_load_items_full($id);
$history = ev_load_history($id);
$values  = ev_values_from_db($eval, $items);

$canEdit = can_edit_evaluator_part($user, $eval);

// The "ready" box only matters while the evaluator is still working.
$showReady = in_array($eval['status'], ['pending_evaluation', 'returned_evaluator'], true);
$problems  = [];
if ($showReady) {
    foreach (validate_evaluator_part($values, $items, true) as $key => $message) {
        // "items.2.grounds" -> "Item 3: ..."
        if (preg_match('/^items\.(\d+)\./', $key, $m)) {
            $message = 'Item ' . ((int) $m[1] + 1) . ': ' . $message;
        } elseif ($key === 'b_params' || $key === 'b_details') {
            $message = 'Section B: ' . $message;
        } elseif ($key === 'c_params' || $key === 'c_details') {
            $message = 'Section C: ' . $message;
        }
        $problems[] = $message;
    }
}

$qualityParams = quality_parameters();
$othersParams  = others_parameters();

$pageTitle = 'Evaluation form';
require __DIR__ . '/../app/views/layout/header.php';

page_header('Evaluation form', mms_form_title($eval));
?>

<?php if ($canEdit): ?>
    <p class="mb-4">
        <a href="<?= e(url('evaluator/evaluate.php?id=' . $id)) ?>" class="btn btn-primary w-full sm:w-auto">Edit</a>
    </p>
<?php endif; ?>

<!-- Ready box -->
<?php if ($showReady): ?>
    <?php if ($problems === []): ?>
        <div class="mb-4 rounded-xl border border-green-300 bg-green-50 px-4 py-3 text-sm text-green-900">
            <p class="font-semibold">Ready</p>
            <p class="mt-1">Every item has a finding and Sections B and C are complete. Signing comes in a later step.</p>
        </div>
    <?php else: ?>
        <div class="mb-4 rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900">
            <p class="font-semibold">Not ready yet. This is missing:</p>
            <ul class="mt-2 list-disc space-y-1 pl-5">
                <?php foreach ($problems as $p): ?>
                    <li><?= e($p) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>
<?php endif; ?>

<!-- Header -->
<section class="card mb-4">
    <div class="flex items-start justify-between gap-3">
        <h2 class="text-base font-semibold text-slate-900">Form details</h2>
        <?= status_badge($eval['status']) ?>
    </div>
    <dl class="mt-4 grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
        <div><dt class="text-slate-500">Bid/Reference No.</dt><dd class="font-medium text-slate-900"><?= e($eval['bid_ref_no'] !== '' ? $eval['bid_ref_no'] : '-') ?></dd></div>
        <div><dt class="text-slate-500">Mode of Procurement</dt><dd class="font-medium text-slate-900"><?= e((string) ($eval['mode_name'] ?? '-')) ?></dd></div>
        <div><dt class="text-slate-500">Date of Opening</dt><dd class="font-medium text-slate-900"><?= e(mms_date($eval['opening_date'])) ?></dd></div>
        <div><dt class="text-slate-500">Bidder</dt><dd class="font-medium text-slate-900"><?= e((string) ($eval['bidder'] ?? '-')) ?></dd></div>
        <div><dt class="text-slate-500">Date of Evaluation</dt><dd class="font-medium text-slate-900"><?= e(mms_date($eval['eval_date'])) ?></dd></div>
        <div><dt class="text-slate-500">Item No.</dt><dd class="font-medium text-slate-900"><?= e((string) ($eval['header_item_no'] ?? '-')) ?></dd></div>
        <div><dt class="text-slate-500">Department</dt><dd class="font-medium text-slate-900"><?= e((string) ($eval['department_name'] ?? '-')) ?></dd></div>
        <div><dt class="text-slate-500">Evaluator</dt><dd class="font-medium text-slate-900"><?= e((string) ($eval['evaluator_name'] ?? 'Not assigned yet')) ?></dd></div>
    </dl>
</section>

<!-- Section A -->
<section class="card mb-4">
    <h2 class="text-base font-semibold text-slate-900">A. Compliance to Technical Specifications</h2>
    <?php if ($items === []): ?>
        <p class="mt-3 text-sm text-slate-500">No items.</p>
    <?php else: ?>
        <div class="mt-4 space-y-4">
            <?php foreach ($items as $n => $it): ?>
                <div class="rounded-xl border border-slate-200 bg-slate-50/60 p-4">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h3 class="text-sm font-semibold text-slate-900">Item <?= (int) $n + 1 ?> &middot; No. <?= e($it['item_no']) ?></h3>
                        <?php
                        $f = (string) ($it['finding'] ?? '');
                        $fClass = $f === 'responsive'
                            ? 'bg-green-100 text-green-800 ring-green-300'
                            : ($f === 'non_responsive' ? 'bg-rose-100 text-rose-800 ring-rose-300' : 'bg-slate-100 text-slate-700 ring-slate-300');
                        ?>
                        <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold ring-1 ring-inset <?= $fClass ?>"><?= e(ev_finding_label($f)) ?></span>
                    </div>
                    <dl class="mt-3 grid gap-4 text-sm md:grid-cols-2">
                        <div><dt class="text-slate-500">Technical Specifications</dt><dd class="mt-0.5 whitespace-pre-line text-slate-900"><?= e((string) ($it['specifications'] ?? '')) ?></dd></div>
                        <div><dt class="text-slate-500">Bidder's Specification/Brand</dt><dd class="mt-0.5 whitespace-pre-line text-slate-900"><?= e((string) ($it['bidder_spec'] ?? '')) ?></dd></div>
                    </dl>
                    <?php if ($f === 'non_responsive'): ?>
                        <div class="mt-3 text-sm">
                            <p class="text-slate-500">Ground/s</p>
                            <p class="mt-0.5 whitespace-pre-line text-slate-900"><?= e((string) ($it['grounds'] ?? '') !== '' ? $it['grounds'] : '-') ?></p>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<!-- Section B -->
<section class="card mb-4">
    <h2 class="text-base font-semibold text-slate-900">B. Quality Compliance</h2>
    <p class="mt-2 text-sm text-slate-900">Failed Quality Testing: <strong><?= (int) $values['failed_quality'] === 1 ? 'Yes' : 'No' ?></strong></p>
    <?php if ((int) $values['failed_quality'] === 1): ?>
        <?php $bLabels = ev_param_labels($values['b_params'], $qualityParams); ?>
        <p class="mt-2 text-sm text-slate-600">Parameters: <?= e($bLabels === [] ? '-' : implode(', ', $bLabels)) ?></p>
        <p class="mt-2 whitespace-pre-line text-sm text-slate-900"><?= e($values['b_details'] !== '' ? $values['b_details'] : '-') ?></p>
    <?php endif; ?>
</section>

<!-- Section C -->
<section class="card mb-4">
    <h2 class="text-base font-semibold text-slate-900">C. Others</h2>
    <p class="mt-2 text-sm text-slate-900">Others: <strong><?= (int) $values['others_checked'] === 1 ? 'Yes' : 'No' ?></strong></p>
    <?php if ((int) $values['others_checked'] === 1): ?>
        <?php $cLabels = ev_param_labels($values['c_params'], $othersParams); ?>
        <p class="mt-2 text-sm text-slate-600">Parameters: <?= e($cLabels === [] ? '-' : implode(', ', $cLabels)) ?></p>
        <p class="mt-2 whitespace-pre-line text-sm text-slate-900"><?= e($values['c_details'] !== '' ? $values['c_details'] : '-') ?></p>
    <?php endif; ?>
</section>

<!-- Status history -->
<section class="card mb-4">
    <h2 class="text-base font-semibold text-slate-900">Status history</h2>
    <?php if ($history === []): ?>
        <p class="mt-3 text-sm text-slate-500">No history yet.</p>
    <?php else: ?>
        <ol class="mt-3 space-y-3 text-sm">
            <?php foreach ($history as $h): ?>
                <li class="border-l-2 border-slate-200 pl-3">
                    <p class="font-medium text-slate-900"><?= e(status_label((string) $h['to_status'])) ?></p>
                    <p class="text-xs text-slate-500"><?= e(mms_date_time($h['created_at'])) ?> &middot; <?= e((string) ($h['actor_name'] ?? 'System')) ?></p>
                    <?php if (!empty($h['remarks'])): ?>
                        <p class="mt-1 whitespace-pre-line text-slate-700"><?= e($h['remarks']) ?></p>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ol>
    <?php endif; ?>
</section>

<p>
    <a href="<?= e(url($user['role'] === 'evaluator' || $user['role'] === 'head' ? 'evaluator/assignments.php' : 'common/dashboard.php')) ?>" class="btn btn-secondary w-full sm:w-auto">Back</a>
</p>

<?php require __DIR__ . '/../app/views/layout/footer.php'; ?>