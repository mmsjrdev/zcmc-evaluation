<?php
/**
 * mms/review.php?id=N
 *
 * MMS reviews the SUPPLIER part of one form (read-only), sees a completeness
 * checklist and can:
 *   - Forward to the Head Nurse   (only while status = submitted)
 *   - Return to the supplier      (only while status = submitted, remarks required)
 *   - Cancel                      (any step before completed, reason required)
 *
 * No evaluator data is read or shown here. Status changes go through
 * transition() inside mms_apply_action().
 */

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/domain/mms-forms.php';

$user = require_role(['mm_staff']);
$id   = (int) ($_GET['id'] ?? 0);

$eval = $id > 0 ? mms_load_form($id) : null;
if (!$eval || !can_view_evaluation($user, $eval)) {
    forbidden();
}

// ---------------------------------------------------------------------------
// A button was pressed
// ---------------------------------------------------------------------------
$error     = '';
$remarks   = '';
$openPanel = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action    = (string) ($_POST['action'] ?? '');
    $remarks   = trim((string) ($_POST['remarks'] ?? ''));
    $openPanel = $action;

    try {
        if ($action === 'forward') {
            mms_apply_action($user, $id, 'forward');
            flash('success', 'Form forwarded to the Head Nurse.');
            redirect('mms/inbox.php');

        } elseif ($action === 'return' || $action === 'cancel') {
            if ($remarks === '') {
                $error = $action === 'return' ? 'Please write the remarks for the supplier first.' : 'Please write the reason for cancelling first.';
            } elseif (mb_strlen($remarks) > 1000) {
                $error = 'Please keep the text under 1000 characters.';
            } elseif ($action === 'return') {
                mms_apply_action($user, $id, 'return_supplier', $remarks);
                flash('success', 'Form returned to the supplier.');
                redirect('mms/inbox.php');
            } else {
                mms_apply_action($user, $id, 'cancel', $remarks);
                flash('success', 'Form cancelled.');
                redirect('mms/inbox.php');
            }

        } else {
            $error = 'Unknown action.';
        }
    } catch (WorkflowException $ex) {
        // For example: someone else moved the form first.
        $error = $ex->getMessage();
    }

    // Show the form as it is now (its status may have changed).
    $eval = mms_load_form($id) ?? $eval;
}

// ---------------------------------------------------------------------------
// Data for the page
// ---------------------------------------------------------------------------
$items   = supplier_load_items($id);
$header  = supplier_header_values($eval);
$history = supplier_load_history($id);

$canForward = can_transition($eval, 'forward', $user);
$canReturn  = can_transition($eval, 'return_supplier', $user);
$canCancel  = can_transition($eval, 'cancel', $user);

// The completeness checklist: each entry is [text, true when OK].
$headerLabels = [
    'bid_ref_no'     => 'Bid/Reference No.',
    'mode_id'        => 'Mode of Procurement',
    'opening_date'   => 'Date of Opening',
    'bidder'         => 'Bidder',
    'eval_date'      => 'Date of Evaluation',
    'header_item_no' => 'Item No.',
];
$checks = [];
foreach ($headerLabels as $key => $label) {
    $checks[] = [$label . ' is filled in', trim($header[$key]) !== ''];
}

$missingSpecs  = 0;
$missingBidder = 0;
foreach ($items as $row) {
    if (trim((string) ($row['specifications'] ?? '')) === '') {
        $missingSpecs++;
    }
    if (trim((string) ($row['bidder_spec'] ?? '')) === '') {
        $missingBidder++;
    }
}
$checks[] = ['Section A has at least one item', $items !== []];
$checks[] = ['Every item has Technical Specifications', $items !== [] && $missingSpecs === 0];
$checks[] = ["Every item has the Bidder's Specification/Brand", $items !== [] && $missingBidder === 0];

// Has the supplier part changed since it was submitted? Only meaningful while
// the supplier cannot edit it (a returned form is allowed to change).
if (!in_array($eval['status'], ['supplier_draft', 'returned_supplier'], true) && !empty($eval['supplier_hash'])) {
    $checks[] = [
        'Content matches what the supplier submitted',
        hash_equals((string) $eval['supplier_hash'], (string) supplier_hash($header, $items)),
    ];
}

$allOk = true;
foreach ($checks as $check) {
    if (!$check[1]) {
        $allOk = false;
    }
}

$title = mms_form_title($eval);

$dangerButton = 'inline-flex min-h-11 w-full items-center justify-center rounded-xl bg-rose-600 px-4 text-sm font-semibold text-white hover:bg-rose-700 focus:outline-none focus-visible:ring-4 focus-visible:ring-rose-200';
$textareaClass = 'mt-1 block w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 focus:border-brand-600 focus:outline-none focus:ring-4 focus:ring-brand-200';

$pageTitle = 'Review ' . $title;
require __DIR__ . '/../app/views/layout/header.php';

page_header($title, 'Review the supplier part of Evaluation Form ZCMC-F-PROC-29');
?>

<?php if ($error !== ''): ?>
    <div role="alert" class="mb-6 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-900"><?= e($error) ?></div>
<?php endif; ?>

<div class="grid gap-6 lg:grid-cols-3">
    <div class="space-y-6 lg:col-span-2">

        <!-- Who sent it -->
        <section class="card">
            <div class="flex items-start justify-between gap-3">
                <h2 class="text-base font-semibold text-slate-900">Supplier</h2>
                <?= status_badge($eval['status']) ?>
            </div>
            <dl class="mt-4 grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
                <div><dt class="text-slate-500">Business name</dt><dd class="font-medium text-slate-900"><?= e($eval['supplier_business'] ?? '-') ?></dd></div>
                <div><dt class="text-slate-500">Contact person</dt><dd class="font-medium text-slate-900"><?= e($eval['supplier_contact'] ?? '-') ?></dd></div>
                <div><dt class="text-slate-500">Email</dt><dd class="font-medium text-slate-900"><?= e($eval['supplier_email'] ?? '-') ?></dd></div>
                <div><dt class="text-slate-500">Contact number</dt><dd class="font-medium text-slate-900"><?= e(($eval['supplier_contact_no'] ?? '') !== '' ? $eval['supplier_contact_no'] : '-') ?></dd></div>
                <div><dt class="text-slate-500">Submitted</dt><dd class="font-medium text-slate-900"><?= e(mms_date_time($eval['submitted_at'])) ?></dd></div>
            </dl>
        </section>

        <!-- Header fields -->
        <section class="card">
            <h2 class="text-base font-semibold text-slate-900">Header</h2>
            <dl class="mt-4 grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
                <div><dt class="text-slate-500">Bid/Reference No.</dt><dd class="font-medium text-slate-900"><?= e($eval['bid_ref_no'] !== '' ? $eval['bid_ref_no'] : '-') ?></dd></div>
                <div><dt class="text-slate-500">Mode of Procurement</dt><dd class="font-medium text-slate-900"><?= e($eval['mode_name'] ?? '-') ?></dd></div>
                <div><dt class="text-slate-500">Date of Opening</dt><dd class="font-medium text-slate-900"><?= e(mms_date($eval['opening_date'])) ?></dd></div>
                <div><dt class="text-slate-500">Bidder</dt><dd class="font-medium text-slate-900"><?= e($eval['bidder'] ?? '-') ?></dd></div>
                <div><dt class="text-slate-500">Date of Evaluation</dt><dd class="font-medium text-slate-900"><?= e(mms_date($eval['eval_date'])) ?></dd></div>
                <div><dt class="text-slate-500">Item No.</dt><dd class="font-medium text-slate-900"><?= e($eval['header_item_no'] ?? '-') ?></dd></div>
            </dl>
        </section>

        <!-- Section A -->
        <section class="card">
            <h2 class="text-base font-semibold text-slate-900">Section A: Items</h2>
            <?php if ($items === []): ?>
                <p class="mt-3 text-sm text-slate-500">No items.</p>
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

    <aside class="space-y-6">

        <!-- Completeness checklist -->
        <section class="card">
            <h2 class="text-base font-semibold text-slate-900">Checklist</h2>
            <ul class="mt-3 space-y-2 text-sm">
                <?php foreach ($checks as [$text, $ok]): ?>
                    <li class="flex items-start gap-2">
                        <span class="mt-0.5 font-bold <?= $ok ? 'text-emerald-600' : 'text-rose-600' ?>" aria-hidden="true"><?= $ok ? '&#10003;' : '&#10007;' ?></span>
                        <span class="sr-only"><?= $ok ? 'Done:' : 'Missing:' ?></span>
                        <span class="<?= $ok ? 'text-slate-700' : 'font-medium text-rose-800' ?>"><?= e($text) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
            <p class="mt-3 text-xs text-slate-500">
                <?= $allOk ? 'Everything is in order.' : 'Something is missing. Consider returning the form to the supplier.' ?>
            </p>
        </section>

        <!-- Actions -->
        <section class="card">
            <h2 class="text-base font-semibold text-slate-900">Actions</h2>

            <?php if (!$canForward && !$canReturn && !$canCancel): ?>
                <p class="mt-3 text-sm text-slate-600">No MMS action is available for this form at this status.</p>
            <?php endif; ?>

            <div class="mt-4 space-y-3">
                <?php if ($canForward): ?>
                    <form method="post" action="<?= e(url('mms/review.php?id=' . $id)) ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="forward">
                        <button type="submit" class="btn btn-primary w-full">Forward to Head Nurse</button>
                    </form>
                <?php endif; ?>

                <?php if ($canReturn): ?>
                    <details class="rounded-xl border border-slate-200 p-3" <?= $openPanel === 'return' ? 'open' : '' ?>>
                        <summary class="flex min-h-11 cursor-pointer items-center text-sm font-semibold text-slate-900 focus:outline-none focus-visible:ring-4 focus-visible:ring-brand-200">Return to supplier</summary>
                        <form method="post" action="<?= e(url('mms/review.php?id=' . $id)) ?>" class="mt-2">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="return">
                            <label for="return_remarks" class="block text-sm font-medium text-slate-700">Remarks (the supplier will read them)</label>
                            <textarea id="return_remarks" name="remarks" rows="4" maxlength="1000" required class="<?= $textareaClass ?>"><?= $openPanel === 'return' ? e($remarks) : '' ?></textarea>
                            <button type="submit" class="btn btn-secondary mt-3 w-full">Return to supplier</button>
                        </form>
                    </details>
                <?php endif; ?>

                <?php if ($canCancel): ?>
                    <details class="rounded-xl border border-rose-200 p-3" <?= $openPanel === 'cancel' ? 'open' : '' ?>>
                        <summary class="flex min-h-11 cursor-pointer items-center text-sm font-semibold text-rose-800 focus:outline-none focus-visible:ring-4 focus-visible:ring-rose-200">Cancel this form</summary>
                        <form method="post" action="<?= e(url('mms/review.php?id=' . $id)) ?>" class="mt-2">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="cancel">
                            <label for="cancel_remarks" class="block text-sm font-medium text-slate-700">Reason (the supplier will read it)</label>
                            <textarea id="cancel_remarks" name="remarks" rows="3" maxlength="1000" required class="<?= $textareaClass ?>"><?= $openPanel === 'cancel' ? e($remarks) : '' ?></textarea>
                            <button type="submit" class="<?= $dangerButton ?> mt-3">Cancel form</button>
                        </form>
                    </details>
                <?php endif; ?>
            </div>
        </section>

        <!-- History -->
        <section class="card">
            <h2 class="text-base font-semibold text-slate-900">History</h2>
            <ol class="mt-4 space-y-4 border-l-2 border-slate-200 pl-4">
                <?php foreach (array_reverse($history) as $h): ?>
                    <li class="relative">
                        <span class="absolute -left-[1.4rem] top-1.5 h-2.5 w-2.5 rounded-full bg-brand-600 ring-4 ring-white" aria-hidden="true"></span>
                        <p class="text-sm font-semibold text-slate-900"><?= e(status_label($h['to_status'])) ?></p>
                        <p class="text-xs text-slate-500"><?= e(mms_date_time((string) $h['created_at'])) ?></p>
                        <?php if (!empty($h['remarks'])): ?>
                            <p class="mt-1 whitespace-pre-line text-sm text-slate-600"><?= e($h['remarks']) ?></p>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
                <li class="relative">
                    <span class="absolute -left-[1.4rem] top-1.5 h-2.5 w-2.5 rounded-full bg-slate-300 ring-4 ring-white" aria-hidden="true"></span>
                    <p class="text-sm font-semibold text-slate-900">Form created</p>
                    <p class="text-xs text-slate-500"><?= e(mms_date_time((string) $eval['created_at'])) ?></p>
                </li>
            </ol>
        </section>
    </aside>
</div>

<p class="mt-6"><a href="<?= e(url('mms/inbox.php')) ?>" class="text-sm font-semibold text-brand-700 underline underline-offset-2 hover:text-brand-800">&larr; Back to the inbox</a></p>

<?php require __DIR__ . '/../app/views/layout/footer.php'; ?>