<?php
/**
 * common/verify.php?code=EF-YYMM-0001
 *
 * Checks that a signed form is still exactly what was signed (Step 10).
 * Login required. Anybody who passes can_view_evaluation() sees the SAME
 * safe page: reference number, status, signer names, roles, times and
 * Match / MISMATCH. Never an evaluator section, so a supplier may open it
 * for their own form.
 *
 * Step 11: the checking itself moved to verify_form() in app/domain/verify.php
 * and the table to the shared Signature Validity panel, so mms/record.php
 * shows exactly the same result.
 */

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/domain/verify.php';
require_once __DIR__ . '/../app/views/partials/signature-validity.php';

$user = require_role(all_roles());

$code    = strtoupper(trim((string) ($_GET['code'] ?? '')));
$form    = null;
$message = '';

if ($code !== '') {
    if (!preg_match('/^[A-Z0-9-]{3,30}$/', $code)) {
        $message = 'That is not a valid reference number.';
    } else {
        $stmt = db()->prepare('SELECT id FROM evaluations WHERE ref_no = :c LIMIT 1');
        $stmt->execute([':c' => $code]);
        $foundId   = (int) $stmt->fetchColumn();
        $candidate = $foundId > 0 ? conforme_load_form($foundId) : null;

        // One answer for "does not exist" and "not yours", so nothing leaks.
        if ($candidate && can_view_evaluation($user, $candidate)) {
            $form = $candidate;
        } else {
            $message = 'No form with that reference number was found, or you are not allowed to open it.';
        }
    }
}

$check = $form ? verify_form($form) : null;
$id    = $form ? (int) $form['id'] : 0;

$inputClass = 'block min-h-11 w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 focus:border-brand-600 focus:outline-none focus:ring-4 focus:ring-brand-200';

$pageTitle = 'Verify a form';
require __DIR__ . '/../app/views/layout/header.php';

page_header($pageTitle, $form ? conforme_form_title($form) : 'Enter the reference number printed at the bottom of the form.');
?>

<?php if (!$form): ?>
    <section class="card mb-4">
        <form method="get" action="<?= e(url('common/verify.php')) ?>" class="flex flex-col gap-3 sm:flex-row sm:items-end">
            <div class="flex-1">
                <label for="code" class="block text-sm font-medium text-slate-700">Reference number</label>
                <input type="text" id="code" name="code" value="<?= e($code) ?>" placeholder="EF-2611-0001" autocomplete="off" class="<?= $inputClass ?> mt-1">
            </div>
            <button type="submit" class="btn btn-primary w-full sm:w-auto">Check</button>
        </form>

        <?php if ($message !== ''): ?>
            <p role="alert" class="mt-3 rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900"><?= e($message) ?></p>
        <?php endif; ?>
    </section>
<?php else: ?>

    <section class="card mb-4">
        <h2 class="text-base font-semibold text-slate-900">Form</h2>
        <dl class="mt-3 grid gap-3 text-sm sm:grid-cols-3">
            <div><dt class="text-slate-500">Reference No.</dt><dd class="font-medium text-slate-900"><?= e((string) $form['ref_no']) ?></dd></div>
            <div><dt class="text-slate-500">Status</dt><dd class="font-medium text-slate-900"><?= e(status_label((string) $form['status'])) ?></dd></div>
            <div><dt class="text-slate-500">Current version</dt><dd class="font-medium text-slate-900"><?= (int) $form['version'] ?></dd></div>
        </dl>
    </section>

    <?php signature_validity_panel($form, $check); ?>

    <div class="flex flex-col gap-3 sm:flex-row">
        <?php if ($user['role'] === 'supplier'): ?>
            <a href="<?= e(url('supplier/form-view.php?id=' . $id)) ?>" class="btn btn-secondary w-full sm:w-auto">Back to my form</a>
        <?php else: ?>
            <a href="<?= e(url('common/evaluation-view.php?id=' . $id)) ?>" class="btn btn-secondary w-full sm:w-auto">Open the form</a>
        <?php endif; ?>
        <a href="<?= e(url('common/verify.php')) ?>" class="btn btn-secondary w-full sm:w-auto">Check another reference</a>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/../app/views/layout/footer.php'; ?>