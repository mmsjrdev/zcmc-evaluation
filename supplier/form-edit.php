<?php
/**
 * supplier/form-edit.php
 *
 * The supplier creates a form and fills in ONLY the supplier-owned part:
 * the header fields and, per item, Item No., Technical Specifications and the
 * Bidder's Specification/Brand.
 *
 *   form-edit.php            new form
 *   form-edit.php?id=7       edit form 7 (draft or returned to the supplier)
 *   form-edit.php?id=7&review=1   "Ready to submit?" confirmation
 *
 * Buttons: Save draft (saves even if incomplete) and Submit to MMS (checks
 * everything, then asks to confirm). Normal form posts, no AJAX.
 *
 * Evaluator data is never read or printed on this page (see supplier-forms.php).
 */

require_once __DIR__ . '/../app/bootstrap.php';

$user   = require_role(['supplier']);
$userId = (int) $user['id'];

// ---------------------------------------------------------------------------
// Load the form (if editing) and check the owner and the status
// ---------------------------------------------------------------------------
$id   = (int) ($_GET['id'] ?? 0);
$eval = null;

if ($id > 0) {
    $eval = supplier_load_form($id);
    // Someone else's form and a form that does not exist give the same answer.
    if (!$eval || (int) $eval['supplier_id'] !== $userId) {
        forbidden();
    }
    if (!can_edit_supplier_part($user, $eval)) {
        flash('info', 'This form can no longer be edited.');
        redirect('supplier/form-view.php?id=' . $id);
    }
}

$status = $eval['status'] ?? 'supplier_draft';
$errors = [];
$review = false;     // true = show the "Ready to submit?" card

// What the form shows: the saved data, or a blank form for a new one.
if ($eval) {
    $values = supplier_header_values($eval);
    $items  = supplier_load_items($id);
} else {
    $values = ['bid_ref_no' => '', 'mode_id' => '', 'opening_date' => '', 'bidder' => (string) ($user['business_name'] ?? ''),
               'eval_date' => '', 'header_item_no' => ''];
    $items  = [];
}
if ($items === []) {
    $items = [['item_no' => '1', 'specifications' => '', 'bidder_spec' => '']];
}

// ---------------------------------------------------------------------------
// Handle the buttons
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'confirm_submit') {
        // Final step: everything is checked again from the database.
        if ($id <= 0) {
            redirect('supplier/forms.php');
        }
        try {
            $errors = supplier_submit_form($user, $id);
        } catch (WorkflowException $ex) {
            flash('error', $ex->getMessage());
            redirect('supplier/form-view.php?id=' . $id);
        }
        if ($errors === []) {
            flash('success', 'Your form was submitted to the Material Management Section.');
            redirect('supplier/form-view.php?id=' . $id);
        }
        flash('error', 'Some fields are missing. Please fix them and submit again.');

    } elseif ($action === 'save_draft' || $action === 'submit') {
        // Keep ONLY the fields a supplier may write at this status. Anything
        // else that was posted (finding, grounds, department_id, ...) is dropped.
        $data   = filter_post('supplier', $status, $_POST);
        $values = array_merge($values, array_intersect_key($data, $values));
        $items  = $data['items'] ?? $items;
        if ($items === []) {
            $items = [['item_no' => '1', 'specifications' => '', 'bidder_spec' => '']];
        }

        // Shape problems (bad date, text too long) stop even a draft.
        $errors = validate_supplier_part($values, $items, false);

        if ($errors === []) {
            try {
                $id = supplier_save_form($user, $eval, ['items' => $items] + $values);
            } catch (RuntimeException $ex) {
                flash('error', $ex->getMessage());
                redirect('supplier/forms.php');
            }
            audit('form_saved', 'Evaluation #' . $id . ' saved as draft');

            if ($action === 'save_draft') {
                flash('success', 'Draft saved.');
                redirect('supplier/form-edit.php?id=' . $id);
            }

            // "Submit to MMS": is everything filled in?
            $errors = validate_supplier_part($values, $items, true);
            if ($errors === []) {
                redirect('supplier/form-edit.php?id=' . $id . '&review=1');
            }
            // Missing fields: the changes are saved; show the problems beside the fields.
            $eval = supplier_load_form($id);
            flash('error', 'Your changes were saved, but some fields still need to be filled in.');
        } else {
            flash('error', 'Please fix the problems marked in red.');
        }
    } else {
        flash('error', 'Unknown action.');
        redirect('supplier/forms.php');
    }

} elseif ($eval && isset($_GET['review'])) {
    // Arrived from "Submit to MMS": show the confirmation if all is complete.
    $errors = validate_supplier_part($values, $items, true);
    if ($errors === []) {
        $review = true;
    } else {
        flash('error', 'Some fields still need to be filled in.');
    }
}

$formUrl = url('supplier/form-edit.php' . ($id > 0 ? '?id=' . $id : ''));
$modes   = procurement_modes_active();

/** Prints one Section A card. $i = position, $row = values, $err = errors (or [] for the blank template). */
function render_item_card(int $i, array $row, array $err): void
{
    $field = static fn(string $name) => "items[$i][$name]";
    $key   = static fn(string $name) => "items.$i.$name";
    ?>
    <div data-item class="rounded-xl border border-slate-200 bg-slate-50/60 p-4">
        <div class="mb-3 flex items-center justify-between gap-3">
            <h3 data-item-title class="text-sm font-semibold text-slate-900">Item <?= $i + 1 ?></h3>
            <button type="button" data-remove-item
                    class="inline-flex min-h-10 items-center rounded-lg px-3 text-sm font-medium text-red-700 hover:bg-red-50 focus:outline-none focus-visible:ring-4 focus-visible:ring-red-200">
                Remove
            </button>
        </div>
        <div class="grid gap-4 md:grid-cols-[8rem_1fr_1fr]">
            <div>
                <label class="mb-1 block text-sm font-medium text-slate-700">Item No.</label>
                <input name="<?= e($field('item_no')) ?>" type="text" maxlength="20" required
                       value="<?= e($row['item_no'] ?? '') ?>" class="<?= e(input_class($err, $key('item_no'))) ?>">
                <?php field_error($err, $key('item_no')); ?>
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium text-slate-700">Technical Specifications</label>
                <textarea name="<?= e($field('specifications')) ?>" rows="4" maxlength="5000" required
                          class="<?= e(input_class($err, $key('specifications'))) ?>"><?= e($row['specifications'] ?? '') ?></textarea>
                <?php field_error($err, $key('specifications')); ?>
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium text-slate-700">Bidder's Specification/Brand</label>
                <textarea name="<?= e($field('bidder_spec')) ?>" rows="4" maxlength="5000" required
                          class="<?= e(input_class($err, $key('bidder_spec'))) ?>"><?= e($row['bidder_spec'] ?? '') ?></textarea>
                <?php field_error($err, $key('bidder_spec')); ?>
            </div>
        </div>
    </div>
    <?php
}

// ---------------------------------------------------------------------------
// Page
// ---------------------------------------------------------------------------
$isReturned = ($status === 'returned_supplier');
$pageTitle  = $id > 0 ? 'Edit form' : 'New form';
require __DIR__ . '/../app/views/layout/header.php';

page_header(
    $id > 0 ? 'Edit form' : 'New form',
    $isReturned ? 'MMS returned this form. Correct it and submit it again.' : 'Fill in the supplier part of Evaluation Form ZCMC-F-PROC-29.'
);

if ($review): ?>
    <section class="card max-w-2xl">
        <h2 class="text-lg font-semibold text-slate-900">Ready to submit?</h2>
        <dl class="mt-4 space-y-2 text-sm">
            <div class="flex justify-between gap-4"><dt class="text-slate-500">Bid/Reference No.</dt><dd class="font-medium text-slate-900"><?= e($values['bid_ref_no']) ?></dd></div>
            <div class="flex justify-between gap-4"><dt class="text-slate-500">Bidder</dt><dd class="text-right font-medium text-slate-900"><?= e($values['bidder']) ?></dd></div>
            <div class="flex justify-between gap-4"><dt class="text-slate-500">Items</dt><dd class="font-medium text-slate-900"><?= count($items) ?></dd></div>
        </dl>
        <p class="mt-4 rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-900">
            After you submit, you cannot change the form unless MMS returns it to you.
        </p>
        <form method="post" action="<?= e($formUrl) ?>" class="mt-5 flex flex-col gap-3 sm:flex-row">
            <?= csrf_field() ?>
            <button type="submit" name="action" value="confirm_submit" class="btn btn-primary sm:flex-1">Yes, submit to MMS</button>
            <a href="<?= e(url('supplier/form-edit.php?id=' . $id)) ?>" class="btn btn-secondary sm:flex-1">Back to editing</a>
        </form>
    </section>

<?php else: ?>
    <?php if ($errors !== []): ?>
        <div role="alert" class="mb-6 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-800">
            Please check the fields marked in red below.
        </div>
    <?php endif; ?>

    <form method="post" action="<?= e($formUrl) ?>" class="space-y-6" novalidate>
        <?= csrf_field() ?>

        <!-- Header -->
        <section class="card">
            <h2 class="text-base font-semibold text-slate-900">Header</h2>
            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="bid_ref_no" class="mb-1 block text-sm font-medium text-slate-700">Bid/Reference No.</label>
                    <input id="bid_ref_no" name="bid_ref_no" type="text" maxlength="100" value="<?= e($values['bid_ref_no']) ?>" class="<?= e(input_class($errors, 'bid_ref_no')) ?>">
                    <?php field_error($errors, 'bid_ref_no'); ?>
                </div>
                <div>
                    <label for="mode_id" class="mb-1 block text-sm font-medium text-slate-700">Mode of Procurement</label>
                    <select id="mode_id" name="mode_id" class="<?= e(input_class($errors, 'mode_id')) ?>">
                        <option value="">Choose one</option>
                        <?php foreach ($modes as $m): ?>
                            <option value="<?= (int) $m['id'] ?>" <?= (string) $values['mode_id'] === (string) $m['id'] ? 'selected' : '' ?>><?= e($m['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php field_error($errors, 'mode_id'); ?>
                </div>
                <div>
                    <label for="opening_date" class="mb-1 block text-sm font-medium text-slate-700">Date of Opening</label>
                    <input id="opening_date" name="opening_date" type="date" value="<?= e($values['opening_date']) ?>" class="<?= e(input_class($errors, 'opening_date')) ?>">
                    <?php field_error($errors, 'opening_date'); ?>
                </div>
                <div>
                    <label for="bidder" class="mb-1 block text-sm font-medium text-slate-700">Bidder</label>
                    <input id="bidder" name="bidder" type="text" maxlength="190" value="<?= e($values['bidder']) ?>" class="<?= e(input_class($errors, 'bidder')) ?>">
                    <?php field_error($errors, 'bidder'); ?>
                </div>
                <div>
                    <label for="eval_date" class="mb-1 block text-sm font-medium text-slate-700">Date of Evaluation</label>
                    <input id="eval_date" name="eval_date" type="date" value="<?= e($values['eval_date']) ?>" class="<?= e(input_class($errors, 'eval_date')) ?>">
                    <?php field_error($errors, 'eval_date'); ?>
                </div>
                <div>
                    <label for="header_item_no" class="mb-1 block text-sm font-medium text-slate-700">Item No.</label>
                    <input id="header_item_no" name="header_item_no" type="text" maxlength="50" value="<?= e($values['header_item_no']) ?>" class="<?= e(input_class($errors, 'header_item_no')) ?>">
                    <?php field_error($errors, 'header_item_no'); ?>
                </div>
            </div>
        </section>

        <!-- Section A -->
        <section class="card">
            <h2 class="text-base font-semibold text-slate-900">Section A: Items</h2>
            <p class="mt-1 text-sm text-slate-500">One card per item. The hospital completes the rest of the form after you submit.</p>
            <?php field_error($errors, 'items'); ?>

            <div id="items" data-max="<?= MAX_FORM_ITEMS ?>" class="mt-4 space-y-4">
                <?php foreach ($items as $i => $row): ?>
                    <?php render_item_card((int) $i, $row, $errors); ?>
                <?php endforeach; ?>
            </div>

            <template id="item-template">
                <?php render_item_card(0, ['item_no' => ''], []); ?>
            </template>

            <button type="button" id="add-item" class="btn btn-secondary mt-4 w-full sm:w-auto">+ Add item</button>
        </section>

        <div class="flex flex-col gap-3 sm:flex-row sm:justify-end">
            <a href="<?= e(url('supplier/forms.php')) ?>" class="btn btn-secondary">Cancel</a>
            <button type="submit" name="action" value="save_draft" class="btn btn-secondary">Save draft</button>
            <button type="submit" name="action" value="submit" class="btn btn-primary">Submit to MMS</button>
        </div>
    </form>

    <script src="<?= e(asset('js/form-items.js')) ?>" defer></script>
<?php endif; ?>

<?php require __DIR__ . '/../app/views/layout/footer.php'; ?>
