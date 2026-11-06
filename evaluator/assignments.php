<?php
/**
 * evaluator/assignments.php
 *
 * For evaluators and for Department Heads who also evaluate.
 *   Tab "Assigned to me":  forms given to this person. Each has "Send back to
 *                          Head Nurse" (reason required) until the first signature.
 *   Tab "Department pool": forms of this person's department that nobody has
 *                          claimed. "Claim" is a POST; only one person can win.
 * Filling in the evaluator part of a form is Step 7.
 */

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/domain/evaluator-forms.php';

$user = require_role(['evaluator', 'head']);

// ---------------------------------------------------------------------------
// A button was pressed
// ---------------------------------------------------------------------------
$errorId     = 0;   // which form's panel shows the error
$errorText   = '';
$oldRemarks  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string) ($_POST['action'] ?? '');
    $formId = (int) ($_POST['id'] ?? 0);

    if ($action === 'claim') {
        if (ev_claim_form($user, $formId)) {
            flash('success', 'You claimed the form. It is now in "Assigned to me".');
            redirect('evaluator/assignments.php?tab=assigned');
        }
        flash('error', 'This form could not be claimed. Somebody else may have claimed it first.');
        redirect('evaluator/assignments.php?tab=pool');

    } elseif ($action === 'send_back') {
        $oldRemarks = trim((string) ($_POST['remarks'] ?? ''));
        $errorId    = $formId;

        if ($oldRemarks === '') {
            $errorText = 'Please write the reason first.';
        } elseif (mb_strlen($oldRemarks) > 1000) {
            $errorText = 'Please keep the reason under 1000 characters.';
        } else {
            try {
                ev_send_back_form($user, $formId, $oldRemarks);
                flash('success', 'Form sent back to the Head Nurse.');
                redirect('evaluator/assignments.php?tab=assigned');
            } catch (WorkflowException $ex) {
                $errorText = $ex->getMessage();
            }
        }
    }
}

// ---------------------------------------------------------------------------
// Data for the page
// ---------------------------------------------------------------------------
$tab = (string) ($_GET['tab'] ?? 'assigned');
if (!in_array($tab, ['assigned', 'pool'], true)) {
    $tab = 'assigned';
}
// After a send-back error the page reloads on the tab that has the panel.
if ($errorId > 0) {
    $tab = 'assigned';
}

$hasDepartment = !empty($user['department_id']);
$assigned      = ev_assigned_list((int) $user['id']);
$pool          = $hasDepartment ? ev_pool_list((int) $user['department_id']) : [];
$forms         = $tab === 'assigned' ? $assigned : $pool;

$textareaClass = 'mt-1 block w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 focus:border-brand-600 focus:outline-none focus:ring-4 focus:ring-brand-200';

$pageTitle = 'Assignments';
require __DIR__ . '/../app/views/layout/header.php';

page_header('Assignments', 'Forms for you to evaluate');
?>

<!-- Tabs -->
<nav class="mb-6 flex flex-wrap gap-2" aria-label="Assignment tabs">
    <a href="<?= e(url('evaluator/assignments.php?tab=assigned')) ?>" <?= $tab === 'assigned' ? 'aria-current="page"' : '' ?>
       class="btn <?= $tab === 'assigned' ? 'btn-primary' : 'btn-secondary' ?>">Assigned to me (<?= count($assigned) ?>)</a>
    <a href="<?= e(url('evaluator/assignments.php?tab=pool')) ?>" <?= $tab === 'pool' ? 'aria-current="page"' : '' ?>
       class="btn <?= $tab === 'pool' ? 'btn-primary' : 'btn-secondary' ?>">Department pool (<?= count($pool) ?>)</a>
</nav>

<?php if ($tab === 'pool' && !$hasDepartment): ?>
    <div role="alert" class="rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900">
        Your account has no department, so there is no pool for you. Ask the administrator to set your department.
    </div>

<?php elseif ($forms === []): ?>
    <div class="card text-sm text-slate-600">
        <?= $tab === 'assigned' ? 'No forms are assigned to you right now.' : 'No unclaimed forms in your department pool right now.' ?>
    </div>

<?php else: ?>
    <ul class="space-y-3">
        <?php foreach ($forms as $f): ?>
            <li class="card">
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
                    <?= e($f['department_name'] ?? '-') ?>
                    &middot; <?= (int) $f['item_count'] ?> item<?= (int) $f['item_count'] === 1 ? '' : 's' ?>
                    &middot; Sent <?= e(mms_date_time($f['distributed_at'])) ?>
                </p>

                <?php if ($tab === 'pool' && can_claim_evaluation($user, $f)): ?>
                    <form method="post" action="<?= e(url('evaluator/assignments.php?tab=pool')) ?>" class="mt-3">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="claim">
                        <input type="hidden" name="id" value="<?= (int) $f['id'] ?>">
                        <button type="submit" class="btn btn-primary w-full sm:w-auto">Claim this form</button>
                    </form>
                <?php endif; ?>

                <?php if ($tab === 'assigned' && can_send_back_evaluation($user, $f)): ?>
                    <details class="mt-3 rounded-xl border border-slate-200 p-3" <?= $errorId === (int) $f['id'] ? 'open' : '' ?>>
                        <summary class="flex min-h-11 cursor-pointer items-center text-sm font-semibold text-slate-900 focus:outline-none focus-visible:ring-4 focus-visible:ring-brand-200">Send back to Head Nurse</summary>
                        <form method="post" action="<?= e(url('evaluator/assignments.php?tab=assigned')) ?>" class="mt-2">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="send_back">
                            <input type="hidden" name="id" value="<?= (int) $f['id'] ?>">
                            <?php if ($errorId === (int) $f['id'] && $errorText !== ''): ?>
                                <p role="alert" class="mb-2 text-sm font-semibold text-rose-800"><?= e($errorText) ?></p>
                            <?php endif; ?>
                            <label for="remarks_<?= (int) $f['id'] ?>" class="block text-sm font-medium text-slate-700">Reason (the Head Nurse will read it)</label>
                            <textarea id="remarks_<?= (int) $f['id'] ?>" name="remarks" rows="3" maxlength="1000" required class="<?= $textareaClass ?>"><?= $errorId === (int) $f['id'] ? e($oldRemarks) : '' ?></textarea>
                            <button type="submit" class="btn btn-secondary mt-3 w-full sm:w-auto">Send back</button>
                        </form>
                    </details>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

<?php require __DIR__ . '/../app/views/layout/footer.php'; ?>