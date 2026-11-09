<?php
/**
 * mms/record.php?id=N
 *
 * The full record of one form (Step 11, extends the Step 10 page):
 *   - summary and routing,
 *   - buttons: Mark as Received, Return with remarks, Print, Verify, scan,
 *   - the Signature Validity panel (same check as common/verify.php),
 *   - "Conforme on paper" upload (Step 10, unchanged),
 *   - the status history.
 *
 * Who: mm_staff may act. admin may only look (the workflow table says no).
 */

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/domain/paper-conforme.php';
require_once __DIR__ . '/../app/domain/mms-records.php';
require_once __DIR__ . '/../app/domain/verify.php';
require_once __DIR__ . '/../app/views/partials/mms-ui.php';
require_once __DIR__ . '/../app/views/partials/signature-validity.php';

$user = require_role(['mm_staff', 'admin']);

$id   = (int) ($_GET['id'] ?? 0);
$form = $id > 0 ? conforme_load_form($id) : null;

if (!$form || !can_view_evaluation($user, $form)) {
    forbidden();
}

$formError   = '';   // shown in the "Conforme on paper" card
$actionError = '';   // shown at the top (Mark as Received)

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string) ($_POST['action'] ?? 'paper');

    try {
        if ($action === 'receive') {
            mms_mark_received($user, $id);
            flash('success', 'The form is marked as received.');
            redirect('mms/record.php?id=' . $id);
        } else {
            conforme_paper_complete($user, $id, $_FILES['scan'] ?? []);
            flash('success', 'The scan was saved. The form is now complete.');
            redirect('mms/record.php?id=' . $id);
        }
    } catch (SigningRefused $ex) {
        $formError = $ex->getMessage();
    } catch (WorkflowException $ex) {
        if ($action === 'receive') {
            $actionError = $ex->getMessage();
        } else {
            $formError = $ex->getMessage();
        }
    }

    // The form may have changed while we tried, so read it again.
    $form = conforme_load_form($id) ?: $form;
}

$status   = (string) $form['status'];
$refNo    = trim((string) ($form['ref_no'] ?? ''));
$extra    = mms_record_extra($id);
$history  = mms_record_history($id);
$check    = verify_form($form);

$canPaper   = $user['role'] === 'mm_staff' && $status === 'pending_conforme' && can_transition($form, 'conforme', $user);
$canReceive = $user['role'] === 'mm_staff' && can_transition($form, 'receive', $user);
$canReturn  = can_return_evaluation($user, $form);
$hasScan    = in_array($status, ['completed', 'received'], true)
    && (string) ($form['conforme_method'] ?? '') === 'paper'
    && (string) ($form['paper_scan_file'] ?? '') !== '';

$fmtDate = static function ($t): string {
    $time = $t ? strtotime((string) $t) : false;
    return $time ? date('M j, Y', $time) : '';
};
$fmtTime = static function ($t): string {
    $time = $t ? strtotime((string) $t) : false;
    return $time ? date('M j, Y g:i A', $time) : '';
};

$supplierText = trim((string) ($extra['supplier_business'] ?? '')) !== ''
    ? (string) $extra['supplier_business'] . ' (' . (string) ($extra['supplier_name'] ?? '') . ')'
    : (string) ($extra['supplier_name'] ?? '');

$pageTitle = 'Form record';
require __DIR__ . '/../app/views/layout/header.php';

page_header($pageTitle, conforme_form_title($form));
?>

<?php if ($actionError !== ''): ?>
    <div role="alert" class="mb-4 rounded-xl border border-rose-300 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-800"><?= e($actionError) ?></div>
<?php endif; ?>

<section class="card mb-4">
    <h2 class="text-base font-semibold text-slate-900">Summary</h2>
    <dl class="mt-3 grid gap-3 text-sm sm:grid-cols-2">
        <div><dt class="text-slate-500">Reference No.</dt><dd class="font-medium text-slate-900"><?= e($refNo !== '' ? $refNo : 'Not yet assigned') ?></dd></div>
        <div><dt class="text-slate-500">Status</dt><dd class="mt-1"><?= status_pill($status) ?> <span class="text-slate-600">version <?= (int) $form['version'] ?></span></dd></div>
        <div><dt class="text-slate-500">Bid/Reference No.</dt><dd class="font-medium text-slate-900"><?= e((string) ($form['bid_ref_no'] ?? '')) ?></dd></div>
        <div><dt class="text-slate-500">Bidder</dt><dd class="font-medium text-slate-900"><?= e((string) ($form['bidder'] ?? '')) ?></dd></div>
        <div><dt class="text-slate-500">Supplier account</dt><dd class="font-medium text-slate-900"><?= e($supplierText) ?></dd></div>
        <div><dt class="text-slate-500">Mode of procurement</dt><dd class="font-medium text-slate-900"><?= e((string) ($extra['mode_name'] ?? '')) ?></dd></div>
        <div><dt class="text-slate-500">Department</dt><dd class="font-medium text-slate-900"><?= e((string) ($extra['department_name'] ?? 'Not assigned yet')) ?></dd></div>
        <div><dt class="text-slate-500">Evaluator</dt><dd class="font-medium text-slate-900"><?= e((string) ($extra['evaluator_name'] ?? 'Not assigned yet')) ?></dd></div>
        <div><dt class="text-slate-500">Distributed by</dt><dd class="font-medium text-slate-900"><?= e((string) ($extra['distributed_by_name'] ?? '')) ?><?= !empty($form['distributed_at']) ? ' on ' . e($fmtDate($form['distributed_at'])) : '' ?></dd></div>
        <div><dt class="text-slate-500">Submitted</dt><dd class="font-medium text-slate-900"><?= e($fmtTime($form['submitted_at'])) ?></dd></div>
        <div><dt class="text-slate-500">Opening date</dt><dd class="font-medium text-slate-900"><?= e($fmtDate($form['opening_date'])) ?></dd></div>
        <div><dt class="text-slate-500">Evaluation date</dt><dd class="font-medium text-slate-900"><?= e($fmtDate($form['eval_date'])) ?></dd></div>
        <?php if (in_array($status, ['completed', 'received'], true) && (string) ($form['conforme_method'] ?? '') !== ''): ?>
            <div><dt class="text-slate-500">Conforme</dt><dd class="font-medium text-slate-900"><?= (string) $form['conforme_method'] === 'paper' ? 'Signed on paper' : 'Signed by the supplier on their device' ?></dd></div>
        <?php endif; ?>
    </dl>

    <div class="mt-4 flex flex-col gap-3 sm:flex-row sm:flex-wrap">
        <a href="<?= e(url('common/evaluation-view.php?id=' . $id)) ?>" class="btn btn-secondary w-full sm:w-auto">Open the form</a>
        <a href="<?= e(url('common/print.php?id=' . $id)) ?>" target="_blank" rel="noopener" class="btn btn-secondary w-full sm:w-auto">Print view</a>
        <?php if ($refNo !== ''): ?>
            <a href="<?= e(url('common/verify.php?code=' . rawurlencode($refNo))) ?>" class="btn btn-secondary w-full sm:w-auto">Verify signatures</a>
        <?php endif; ?>
        <?php if ($hasScan): ?>
            <a href="<?= e(url('common/download.php?id=' . $id)) ?>" target="_blank" rel="noopener" class="btn btn-secondary w-full sm:w-auto">View the paper scan</a>
        <?php endif; ?>
        <a href="<?= e(url('mms/records.php')) ?>" class="btn btn-secondary w-full sm:w-auto">Back to records</a>
    </div>
</section>

<?php if ($canReceive || $canReturn): ?>
    <section class="card mb-4">
        <h2 class="text-base font-semibold text-slate-900">This form is completed</h2>
        <p class="mt-2 text-sm text-slate-700">
            Mark it as received when MMS has the finished form, or send it back to the evaluator with remarks.
            Sending it back starts a new version, so the old signatures stop counting.
        </p>
        <div class="mt-4 flex flex-col gap-3 sm:flex-row">
            <?php if ($canReceive): ?>
                <form method="post" action="<?= e(url('mms/record.php?id=' . $id)) ?>" class="w-full sm:w-auto">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="receive">
                    <button type="submit" class="btn btn-primary w-full sm:w-auto">Mark as Received</button>
                </form>
            <?php endif; ?>
            <?php if ($canReturn): ?>
                <a href="<?= e(url('common/return.php?id=' . $id)) ?>" class="btn btn-secondary w-full sm:w-auto">Return with remarks</a>
            <?php endif; ?>
        </div>
    </section>
<?php endif; ?>

<?php signature_validity_panel($form, $check); ?>

<?php if ($status === 'pending_conforme'): ?>
    <section class="card mb-4">
        <h2 class="text-base font-semibold text-slate-900">Conforme on paper</h2>
        <ol class="mt-2 list-decimal space-y-1 pl-5 text-sm text-slate-700">
            <li>Open the <strong>Print view</strong> and print the form.</li>
            <li>The supplier&rsquo;s authorized representative signs the Conforme by hand.</li>
            <li>Scan the signed paper and upload it here (PDF, JPG or PNG, 5 MB at most).</li>
        </ol>

        <?php if ($formError !== ''): ?>
            <div role="alert" class="mt-3 rounded-xl border border-rose-300 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-800"><?= e($formError) ?></div>
        <?php endif; ?>

        <?php if ($canPaper): ?>
            <form method="post" enctype="multipart/form-data" action="<?= e(url('mms/record.php?id=' . $id)) ?>" class="mt-4 space-y-4">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="paper">
                <div>
                    <label for="scan" class="block text-sm font-medium text-slate-700">Scanned Conforme</label>
                    <input type="file" id="scan" name="scan" required accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png"
                           class="mt-1 block w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 focus:border-brand-600 focus:outline-none focus:ring-4 focus:ring-brand-200">
                </div>
                <button type="submit" class="btn btn-primary w-full sm:w-auto">Upload and complete the form</button>
            </form>
        <?php else: ?>
            <p class="mt-3 text-sm text-slate-600">Only MMS staff can upload the scan.</p>
        <?php endif; ?>
    </section>
<?php endif; ?>

<section class="card mb-4">
    <h2 class="text-base font-semibold text-slate-900">Status history</h2>
    <?php if ($history === []): ?>
        <p class="mt-2 text-sm text-slate-600">No status changes yet.</p>
    <?php else: ?>
        <ol class="mt-3 space-y-3 text-sm">
            <?php foreach ($history as $h): ?>
                <li class="border-l-4 border-slate-200 pl-3">
                    <p class="font-medium text-slate-900">
                        <?= e((string) ($h['from_status'] ?? '') !== '' ? status_label((string) $h['from_status']) : 'New') ?>
                        &rarr; <?= e(status_label((string) $h['to_status'])) ?>
                    </p>
                    <p class="text-slate-500"><?= e((string) ($h['actor_name'] ?? 'System')) ?> &middot; <?= e($fmtTime($h['created_at'])) ?></p>
                    <?php if ((string) ($h['remarks'] ?? '') !== ''): ?>
                        <p class="mt-1 text-slate-700"><?= nl2br(e((string) $h['remarks'])) ?></p>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ol>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/../app/views/layout/footer.php'; ?>