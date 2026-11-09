<?php
/**
 * common/evaluation-view.php?id=N
 *
 * Read-only full view of a form including the EVALUATOR data (findings,
 * grounds, Sections B and C). Allowed: evaluator, head, admin, mm_staff,
 * head_nurse, each only through can_view_evaluation().
 * The supplier role is always refused: suppliers must never receive evaluator
 * data. They keep supplier/form-view.php.
 *
 * Step 9 buttons (each one only where the permission functions allow it):
 *   Print, Edit, Review and sign, Return for correction, Cancel form.
 */

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/domain/evaluator-forms.php';
require_once __DIR__ . '/../app/domain/print-form.php';          // print_load_signatures(), print_date_time()
require_once __DIR__ . '/../app/views/partials/evaluation-summary.php';

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

// What this person may do now (all decided in app/core/permissions.php).
$canEdit   = can_edit_evaluator_part($user, $eval);
$signRole  = signing_role_for($user, $eval);          // 'evaluator', 'head' or null
$canReturn = can_return_evaluation($user, $eval);
$canCancel = can_cancel_evaluation($user, $eval);

// The signatures that count for the current version (names and dates only are shown here).
$signatures = print_load_signatures($id, (int) $eval['version'], ['evaluator', 'head', 'conforme']);

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

$backPage = $user['role'] === 'evaluator'
    ? 'evaluator/assignments.php'
    : ($user['role'] === 'head' ? 'head/for-signature.php' : 'common/dashboard.php');

$pageTitle = 'Evaluation form';
require __DIR__ . '/../app/views/layout/header.php';

page_header('Evaluation form', mms_form_title($eval));
?>

<!-- Buttons -->
<div class="mb-4 flex flex-col gap-3 sm:flex-row sm:flex-wrap">
    <a href="<?= e(url('common/print.php?id=' . $id)) ?>" target="_blank" rel="noopener" class="btn btn-secondary w-full sm:w-auto">Print / Save as PDF</a>
    <?php if ($canEdit): ?>
        <a href="<?= e(url('evaluator/evaluate.php?id=' . $id)) ?>" class="btn btn-primary w-full sm:w-auto">Edit</a>
    <?php endif; ?>
    <?php if ($signRole !== null): ?>
        <a href="<?= e(url('common/sign.php?id=' . $id)) ?>" class="btn btn-primary w-full sm:w-auto">
            <?= $signRole === 'evaluator' ? 'Review and sign as evaluator' : 'Review and sign as Department Head' ?>
        </a>
    <?php endif; ?>
    <?php if ($canReturn): ?>
        <a href="<?= e(url('common/return.php?id=' . $id)) ?>" class="btn btn-secondary w-full sm:w-auto">Return for correction</a>
    <?php endif; ?>
</div>

<!-- Ready box -->
<?php if ($showReady): ?>
    <?php if ($problems === []): ?>
        <div class="mb-4 rounded-xl border border-green-300 bg-green-50 px-4 py-3 text-sm text-green-900">
            <p class="font-semibold">Ready</p>
            <p class="mt-1">
                Every item has a finding and Sections B and C are complete.
                <?= $signRole === 'evaluator' ? 'You can now review and sign.' : '' ?>
            </p>
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

<?php evaluation_summary($eval, $items, $values); ?>

<!-- Signatures of the current version: the drawn signature, name, position, date and time -->
<section class="card mb-4">
    <h2 class="text-base font-semibold text-slate-900">Signatures (version <?= (int) $eval['version'] ?>)</h2>
    <div class="mt-4 grid gap-4 text-sm sm:grid-cols-3">
        <?php foreach (['evaluator' => ['Evaluated by', 'End-user'], 'head' => ['Verified by', 'Department/Unit/Section Head or Supervisor'], 'conforme' => ['Conforme', 'Bidder\'s Authorized Representative']] as $role => $labels): ?>
            <?php
            $sig    = $signatures[$role] ?? null;
            $sigSrc = $sig ? print_signature_src($sig['signature_png'] ?? '') : '';
            ?>
            <div class="rounded-xl border border-slate-200 bg-slate-50/60 p-3">
                <p class="text-slate-500"><?= e($labels[0]) ?></p>
                <div class="mt-2 flex h-20 items-end justify-center rounded-lg bg-white">
                    <?php if ($sigSrc !== ''): ?>
                        <img src="<?= e($sigSrc) ?>" alt="Signature of <?= e($sig['typed_name']) ?>" class="max-h-20 max-w-full object-contain">
                    <?php else: ?>
                        <span class="pb-2 text-xs text-slate-400">Not signed yet</span>
                    <?php endif; ?>
                </div>
                <p class="mt-2 border-t border-slate-400 pt-1 text-center font-semibold uppercase text-slate-900"><?= $sig ? e($sig['typed_name']) : '&nbsp;' ?></p>
                <p class="text-center text-xs text-slate-500"><?= e($labels[1]) ?></p>
                <?php if ($sig): ?>
                    <?php if (!empty($sig['position'])): ?>
                        <p class="mt-1 text-center text-xs text-slate-600"><?= e($sig['position']) ?></p>
                    <?php endif; ?>
                    <p class="mt-1 text-center text-xs text-slate-500">Signed <?= e(print_date_time($sig['signed_at'])) ?></p>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
</section>

<!-- Cancel (MMS only; the server checks again) -->
<?php if ($canCancel): ?>
    <details class="card mb-4">
        <summary class="flex min-h-11 cursor-pointer items-center text-sm font-semibold text-rose-700">Cancel this form</summary>
        <form method="post" action="<?= e(url('common/cancel.php')) ?>" class="mt-3 space-y-3">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int) $id ?>">
            <label for="cancel_reason" class="block text-sm font-medium text-slate-700">Reason for cancelling (required)</label>
            <textarea id="cancel_reason" name="reason" rows="3" required class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-rose-500 focus:outline-none focus:ring-4 focus:ring-rose-100"></textarea>
            <button type="submit" class="btn btn-secondary w-full sm:w-auto" onclick="return confirm('Cancel this form? This cannot be undone.');">Cancel form</button>
        </form>
    </details>
<?php endif; ?>

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
    <a href="<?= e(url($backPage)) ?>" class="btn btn-secondary w-full sm:w-auto">Back</a>
</p>

<?php require __DIR__ . '/../app/views/layout/footer.php'; ?>