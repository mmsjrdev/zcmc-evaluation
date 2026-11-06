<?php
/**
 * headnurse/distribute.php?id=N
 *
 * The Head Nurse reads the supplier part (read-only) and then either:
 *   - Distributes the form: picks a department (step 1), then an evaluator or
 *     the department pool (step 2). No JavaScript: step 1 reloads the page.
 *   - Returns it to MMS (remarks required).
 * A form that is no longer with the Head Nurse is shown read-only.
 */

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/domain/headnurse-forms.php';
require_once __DIR__ . '/../app/views/partials/supplier-sections.php';

$user = require_role(['head_nurse']);
$id   = (int) ($_GET['id'] ?? 0);

$eval = $id > 0 ? hn_load_form($id) : null;
if (!$eval || !can_view_evaluation($user, $eval)) {
    forbidden();
}

// ---------------------------------------------------------------------------
// A button was pressed
// ---------------------------------------------------------------------------
$error           = '';
$remarks         = '';
$openPanel       = '';
$deptId          = (int) ($_GET['dept'] ?? 0);   // chosen in step 1
$evaluatorChoice = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string) ($_POST['action'] ?? '');

    try {
        if (!can_distribute($user, $eval)) {
            throw new WorkflowException('This form is no longer with the Head Nurse. Reload the page.');
        }

        if ($action === 'distribute') {
            $deptId          = (int) ($_POST['department_id'] ?? 0);
            $evaluatorChoice = (string) ($_POST['evaluator_id'] ?? '');
            $evaluatorId     = $evaluatorChoice === '' ? null : (int) $evaluatorChoice;

            hn_distribute_form($user, $id, $deptId, $evaluatorId);
            flash('success', $evaluatorId === null
                ? 'Form sent to the department pool. The first evaluator to claim it gets it.'
                : 'Form sent to the evaluator.');
            redirect('headnurse/inbox.php');

        } elseif ($action === 'return') {
            $openPanel = 'return';
            $remarks   = trim((string) ($_POST['remarks'] ?? ''));

            if ($remarks === '') {
                $error = 'Please write the remarks for MMS first.';
            } elseif (mb_strlen($remarks) > 1000) {
                $error = 'Please keep the remarks under 1000 characters.';
            } else {
                hn_return_to_mms($user, $id, $remarks);
                flash('success', 'Form returned to MMS.');
                redirect('headnurse/inbox.php');
            }

        } else {
            $error = 'Unknown action.';
        }
    } catch (WorkflowException $ex) {
        $error = $ex->getMessage();
    }

    // Show the form as it is now (its status may have changed).
    $eval = hn_load_form($id) ?? $eval;
}

// ---------------------------------------------------------------------------
// Data for the page
// ---------------------------------------------------------------------------
$items         = supplier_load_items($id);
$canDistribute = can_distribute($user, $eval);
$title         = mms_form_title($eval);

// Step 1: the departments. The chosen one must be in the list.
$departments = hn_active_departments();
$deptNames   = [];
foreach ($departments as $d) {
    $deptNames[(int) $d['id']] = $d['name'];
}
$deptName = $deptNames[$deptId] ?? null;
if ($deptName === null) {
    $deptId = 0;
}

// Step 2: the people of that department.
$evaluators = $deptName !== null ? hn_department_evaluators($deptId) : [];

$labelClass    = 'block text-sm font-medium text-slate-700';
$fieldClass    = 'mt-1 block min-h-11 w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 focus:border-brand-600 focus:outline-none focus-visible:ring-4 focus-visible:ring-brand-200';
$textareaClass = 'mt-1 block w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 focus:border-brand-600 focus:outline-none focus:ring-4 focus:ring-brand-200';

$pageTitle = 'Distribute ' . $title;
require __DIR__ . '/../app/views/layout/header.php';

page_header($title, $canDistribute ? 'Send the form to a department and an evaluator' : 'This form is no longer waiting for distribution');
?>

<?php if ($error !== ''): ?>
    <div role="alert" class="mb-6 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-900"><?= e($error) ?></div>
<?php endif; ?>

<div class="grid gap-6 lg:grid-cols-3">
    <div class="space-y-6 lg:col-span-2">
        <?php render_supplier_sections($eval, $items); ?>
    </div>

    <aside class="space-y-6">

        <?php if ($canDistribute): ?>

            <!-- Step 1: department -->
            <section class="card">
                <h2 class="text-base font-semibold text-slate-900">1. Department</h2>
                <form method="get" action="<?= e(url('headnurse/distribute.php')) ?>" class="mt-3">
                    <input type="hidden" name="id" value="<?= $id ?>">
                    <label for="dept" class="<?= $labelClass ?>">Department</label>
                    <select id="dept" name="dept" required class="<?= $fieldClass ?>">
                        <option value="">Choose a department</option>
                        <?php foreach ($departments as $d): ?>
                            <option value="<?= (int) $d['id'] ?>" <?= (int) $d['id'] === $deptId ? 'selected' : '' ?>><?= e($d['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn btn-secondary mt-3 w-full">Show evaluators</button>
                </form>
            </section>

            <!-- Step 2: evaluator or pool -->
            <?php if ($deptName !== null): ?>
                <section class="card">
                    <h2 class="text-base font-semibold text-slate-900">2. Evaluator for <?= e($deptName) ?></h2>

                    <?php if ($evaluators === []): ?>
                        <div role="alert" class="mt-3 rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                            <p class="font-semibold">This department has no active evaluator yet.</p>
                            <p class="mt-1">You can still send the form to the department pool, but nobody can claim it until an evaluator or department head of this department has an active account.</p>
                        </div>
                    <?php endif; ?>

                    <form method="post" action="<?= e(url('headnurse/distribute.php?id=' . $id . '&dept=' . $deptId)) ?>" class="mt-3">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="distribute">
                        <input type="hidden" name="department_id" value="<?= $deptId ?>">

                        <label for="evaluator_id" class="<?= $labelClass ?>">Evaluator</label>
                        <select id="evaluator_id" name="evaluator_id" class="<?= $fieldClass ?>">
                            <option value="">Department pool (the first evaluator to claim it)</option>
                            <?php foreach ($evaluators as $p): ?>
                                <option value="<?= (int) $p['id'] ?>" <?= $evaluatorChoice === (string) $p['id'] ? 'selected' : '' ?>>
                                    <?= e($p['full_name']) ?> (<?= e(role_label($p['role'])) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="mt-1 text-xs text-slate-500">Pick a name and only that person sees the form. Leave it on the pool and every evaluator of the department sees it.</p>

                        <button type="submit" class="btn btn-primary mt-4 w-full">Distribute</button>
                    </form>
                </section>
            <?php endif; ?>

            <!-- Return to MMS -->
            <section class="card">
                <details <?= $openPanel === 'return' ? 'open' : '' ?>>
                    <summary class="flex min-h-11 cursor-pointer items-center text-sm font-semibold text-slate-900 focus:outline-none focus-visible:ring-4 focus-visible:ring-brand-200">Return to MMS</summary>
                    <form method="post" action="<?= e(url('headnurse/distribute.php?id=' . $id)) ?>" class="mt-2">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="return">
                        <label for="return_remarks" class="<?= $labelClass ?>">Remarks (MMS will read them)</label>
                        <textarea id="return_remarks" name="remarks" rows="4" maxlength="1000" required class="<?= $textareaClass ?>"><?= $openPanel === 'return' ? e($remarks) : '' ?></textarea>
                        <button type="submit" class="btn btn-secondary mt-3 w-full">Return to MMS</button>
                    </form>
                </details>
            </section>

        <?php else: ?>

            <!-- Read-only summary of where the form went -->
            <section class="card">
                <h2 class="text-base font-semibold text-slate-900">Distribution</h2>
                <dl class="mt-3 space-y-3 text-sm">
                    <div><dt class="text-slate-500">Status</dt><dd class="mt-0.5"><?= status_badge($eval['status']) ?></dd></div>
                    <div><dt class="text-slate-500">Department</dt><dd class="font-medium text-slate-900"><?= e($eval['department_name'] ?? '-') ?></dd></div>
                    <div><dt class="text-slate-500">Evaluator</dt><dd class="font-medium text-slate-900"><?= !empty($eval['evaluator_name']) ? e($eval['evaluator_name']) : 'Department pool (not claimed yet)' ?></dd></div>
                    <div><dt class="text-slate-500">Sent</dt><dd class="font-medium text-slate-900"><?= e(mms_date_time($eval['distributed_at'])) ?></dd></div>
                </dl>
            </section>

        <?php endif; ?>
    </aside>
</div>

<p class="mt-6"><a href="<?= e(url('headnurse/inbox.php')) ?>" class="text-sm font-semibold text-brand-700 underline underline-offset-2 hover:text-brand-800">&larr; Back to the inbox</a></p>

<?php require __DIR__ . '/../app/views/layout/footer.php'; ?>