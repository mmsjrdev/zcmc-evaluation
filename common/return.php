<?php
/**
 * common/return.php?id=N
 *
 * Send a form back to the evaluator for correction, with required remarks.
 *   - the Department Head, while the form is pending_head
 *   - MMS, while the form is completed
 * The rules run in signing_return_form(): a returns row is added, the form
 * version goes up by 1 (old signatures stay in history but stop counting),
 * the status becomes returned_evaluator, the reference number stays.
 */

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/domain/signing.php';
require_once __DIR__ . '/../app/views/partials/evaluation-summary.php';

$user = require_role(['head', 'mm_staff']);

$id   = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$eval = $id > 0 ? ev_load_full_form($id) : null;

if (!$eval || !can_view_evaluation($user, $eval)) {
    forbidden();
}
if (!can_return_evaluation($user, $eval)) {
    flash('info', 'This form cannot be returned right now.');
    redirect('common/evaluation-view.php?id=' . $id);
}

$items  = ev_load_items_full($id);
$values = ev_values_from_db($eval, $items);

$formError = '';
$remarks   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $remarks = (string) ($_POST['remarks'] ?? '');

    try {
        signing_return_form($user, $id, $remarks);
        flash('success', 'The form was returned to the evaluator.');
        redirect($user['role'] === 'head' ? 'head/for-signature.php' : 'common/evaluation-view.php?id=' . $id);
    } catch (WorkflowException $ex) {
        $formError = $ex->getMessage();
    }
}

$pageTitle = 'Return for correction';
require __DIR__ . '/../app/views/layout/header.php';

page_header('Return for correction', mms_form_title($eval));
?>

<p class="mb-4 text-sm text-slate-600">
    The form goes back to the evaluator, who corrects it and signs again.
    The signatures given so far stay in the history but no longer count.
</p>

<section class="card mb-4">
    <h2 class="text-base font-semibold text-slate-900">Remarks for the evaluator</h2>

    <?php if ($formError !== ''): ?>
        <div role="alert" class="mt-3 rounded-xl border border-rose-300 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-800"><?= e($formError) ?></div>
    <?php endif; ?>

    <form method="post" action="<?= e(url('common/return.php?id=' . $id)) ?>" class="mt-3 space-y-3" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int) $id ?>">
        <label for="remarks" class="block text-sm font-medium text-slate-700">What must be corrected? (required)</label>
        <textarea id="remarks" name="remarks" rows="5" class="block w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 focus:border-brand-600 focus:outline-none focus:ring-4 focus:ring-brand-200"><?= e($remarks) ?></textarea>
        <div class="flex flex-col gap-3 sm:flex-row">
            <button type="submit" class="btn btn-primary w-full sm:w-auto">Return to evaluator</button>
            <a href="<?= e(url('common/evaluation-view.php?id=' . $id)) ?>" class="btn btn-secondary w-full sm:w-auto">Back</a>
        </div>
    </form>
</section>

<?php evaluation_summary($eval, $items, $values); ?>

<?php require __DIR__ . '/../app/views/layout/footer.php'; ?>