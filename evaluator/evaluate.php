<?php
/**
 * evaluator/evaluate.php?id=N
 *
 * The evaluator (or a Department Head who evaluates) fills in the evaluator-owned
 * part of the form: Findings and Grounds (Section A), Section B and Section C.
 * Encoding only: no signing and no status change in this Step.
 *
 *   "Save draft"       saves with shape checks only.
 *   "Save and review"  saves the draft first, then checks every rule.
 *
 * In Sections B and C the parameters and the details box are only usable while
 * the box above them is ticked (a few lines of JavaScript at the bottom).
 */

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/domain/evaluator-forms.php';

$user = require_role(['evaluator', 'head']);

$id   = (int) ($_GET['id'] ?? 0);
$eval = $id > 0 ? ev_load_full_form($id) : null;

if (!$eval || !can_view_evaluation($user, $eval)) {
    forbidden();
}
// Not (or no longer) editable by this person: show the read-only view instead.
if (!can_edit_evaluator_part($user, $eval)) {
    redirect('common/evaluation-view.php?id=' . $id);
}

$items = ev_load_items_full($id);

// ---------------------------------------------------------------------------
// The values shown in the page: the saved ones, or what was just typed.
// ---------------------------------------------------------------------------
$values    = ev_values_from_db($eval, $items);
$errors    = [];
$formError = '';
$savedNote = false;   // true after "Save and review" saved but found problems

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string) ($_POST['action'] ?? '') === 'review' ? 'review' : 'draft';

    // Only the fields this role owns at this status survive filter_post().
    $posted = ev_shape_post(filter_post($user['role'], $eval['status'], $_POST));

    if (count($posted['items']) !== count($items)) {
        // The rows are matched by position, so a different count cannot be saved.
        $formError = 'The number of items on the page does not match the saved form. Nothing was saved. Reload the page and try again.';
    } else {
        $values = $posted;                                  // keep what was typed
        $errors = validate_evaluator_part($posted, $items, false);

        if ($errors === []) {
            try {
                ev_save_evaluator_part($user, $id, $posted);

                if ($action === 'draft') {
                    flash('success', 'Draft saved.');
                    redirect('evaluator/evaluate.php?id=' . $id);
                }

                // "Save and review": the draft is saved, now check everything.
                $errors = validate_evaluator_part($posted, $items, true);
                if ($errors === []) {
                    flash('success', 'Saved. Everything is filled in. Check the summary below.');
                    redirect('common/evaluation-view.php?id=' . $id);
                }
                $savedNote = true;
            } catch (WorkflowException $ex) {
                $formError = $ex->getMessage();
            }
        }
    }
}

// ---------------------------------------------------------------------------
// Small helpers for the HTML below
// ---------------------------------------------------------------------------
$qualityParams = quality_parameters();
$othersParams  = others_parameters();

/** Class of the big touch-friendly radio / checkbox boxes. */
$choiceClass = 'flex min-h-11 cursor-pointer items-center gap-3 rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-900 has-[:checked]:border-brand-600 has-[:checked]:bg-brand-50 has-[:disabled]:cursor-not-allowed focus-within:ring-4 focus-within:ring-brand-200';

// Line under the page title: "Bid/Ref. No. 1 · ABC Medical Supplies".
$subtitle = 'Bid/Ref. No. ' . ($eval['bid_ref_no'] !== '' ? $eval['bid_ref_no'] : '-');
if (!empty($eval['bidder'])) {
    $subtitle .= ' · ' . $eval['bidder'];
}

$pageTitle = 'Evaluate form';
require __DIR__ . '/../app/views/layout/header.php';

page_header('Evaluate form', $subtitle);
?>

<?php if ($formError !== ''): ?>
    <div role="alert" class="mb-4 rounded-xl border border-rose-300 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-800"><?= e($formError) ?></div>
<?php endif; ?>

<?php if ($savedNote): ?>
    <div role="alert" class="mb-4 rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900">
        Your draft was saved, but some things still need attention. Fix the fields marked in red, then press "Save and review" again.
    </div>
<?php elseif ($errors !== [] && $formError === ''): ?>
    <div role="alert" class="mb-4 rounded-xl border border-rose-300 bg-rose-50 px-4 py-3 text-sm text-rose-800">
        Nothing was saved. Fix the fields marked in red and try again.
    </div>
<?php endif; ?>

<?php if (!empty($errors['items'])): ?>
    <p class="mb-4 text-sm font-medium text-red-600"><?= e($errors['items']) ?></p>
<?php endif; ?>

<!-- Read-only: supplier header and department -->
<section class="card mb-4">
    <div class="flex items-start justify-between gap-3">
        <h2 class="text-base font-semibold text-slate-900">Form details (read only)</h2>
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
    </dl>
</section>

<!-- Definition of Terms: the whole top row is the clickable area -->
<details class="card group mb-4">
    <summary class="-m-2 flex min-h-11 cursor-pointer list-none items-center gap-3 rounded-xl p-2 hover:bg-slate-50 focus:outline-none focus-visible:ring-4 focus-visible:ring-brand-200 [&::-webkit-details-marker]:hidden">
        <!-- Book icon -->
        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-50 text-brand-700" aria-hidden="true">
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 0 0 6 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 0 1 6 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 0 1 6-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0 0 18 18a8.967 8.967 0 0 0-6 2.292m0-14.25v14.25"/></svg>
        </span>
        <span class="min-w-0 flex-1">
            <span class="block text-sm font-semibold text-slate-900">Definition of Terms</span>
            <span class="block text-xs text-slate-500">Tap to read the 15 terms used on this form</span>
        </span>
        <!-- Show / Hide label and a chevron that turns when the box is open -->
        <span class="flex shrink-0 items-center gap-1 text-sm font-semibold text-brand-700">
            <span class="group-open:hidden">Show</span>
            <span class="hidden group-open:inline">Hide</span>
            <svg class="h-5 w-5 transition-transform duration-200 group-open:rotate-180" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5"/></svg>
        </span>
    </summary>
    <dl class="mt-4 space-y-3 border-t border-slate-200 pt-4 text-sm">
        <?php foreach (definition_of_terms() as $t): ?>
            <div>
                <dt class="font-semibold text-slate-900"><?= e($t['term']) ?></dt>
                <dd class="text-slate-600"><?= e($t['meaning']) ?></dd>
            </div>
        <?php endforeach; ?>
    </dl>
</details>

<form method="post" action="<?= e(url('evaluator/evaluate.php?id=' . $id)) ?>" novalidate>
    <?= csrf_field() ?>

    <!-- Section A -->
    <section class="mb-4">
        <h2 class="mb-3 text-base font-semibold text-slate-900">A. Compliance to Technical Specifications</h2>

        <?php if ($items === []): ?>
            <div class="card text-sm text-slate-600">This form has no items.</div>
        <?php endif; ?>

        <div class="space-y-4">
            <?php foreach ($items as $n => $it): ?>
                <?php $finding = (string) $values['items'][$n]['finding']; ?>
                <div class="card" data-item-row>
                    <h3 class="text-sm font-semibold text-slate-900">Item <?= (int) $n + 1 ?> &middot; No. <?= e($it['item_no']) ?></h3>

                    <dl class="mt-3 grid gap-4 text-sm md:grid-cols-2">
                        <div><dt class="text-slate-500">Technical Specifications</dt><dd class="mt-0.5 whitespace-pre-line text-slate-900"><?= e((string) ($it['specifications'] ?? '')) ?></dd></div>
                        <div><dt class="text-slate-500">Bidder's Specification/Brand</dt><dd class="mt-0.5 whitespace-pre-line text-slate-900"><?= e((string) ($it['bidder_spec'] ?? '')) ?></dd></div>
                    </dl>

                    <fieldset class="mt-4">
                        <legend class="text-sm font-medium text-slate-700">Findings</legend>
                        <div class="mt-2 grid gap-2 sm:grid-cols-2">
                            <?php foreach (['responsive' => 'Responsive', 'non_responsive' => 'Non-Responsive'] as $val => $label): ?>
                                <label class="<?= $choiceClass ?>">
                                    <input type="radio" name="items[<?= (int) $n ?>][finding]" value="<?= e($val) ?>" data-finding
                                           class="h-5 w-5 accent-brand-600" <?= $finding === $val ? 'checked' : '' ?>>
                                    <span><?= e($label) ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <?php field_error($errors, "items.$n.finding"); ?>
                    </fieldset>

                    <!-- Always in the page; the server ignores it for Responsive. JS hides it. -->
                    <div class="mt-4" data-grounds>
                        <label for="grounds_<?= (int) $n ?>" class="block text-sm font-medium text-slate-700">Ground/s, if Non-Responsive</label>
                        <textarea id="grounds_<?= (int) $n ?>" name="items[<?= (int) $n ?>][grounds]" rows="3"
                                  class="mt-1 <?= e(input_class($errors, "items.$n.grounds")) ?>"><?= e($values['items'][$n]['grounds']) ?></textarea>
                        <?php field_error($errors, "items.$n.grounds"); ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <!-- Section B -->
    <section class="card mb-4">
        <h2 class="text-base font-semibold text-slate-900">B. Quality Compliance</h2>
        <label class="<?= $choiceClass ?> mt-3">
            <input type="checkbox" name="failed_quality" value="1" data-gate-checkbox="b"
                   class="h-5 w-5 accent-brand-600" <?= (int) $values['failed_quality'] === 1 ? 'checked' : '' ?>>
            <span>Failed Quality Testing</span>
        </label>
        <p data-gate-hint="b" class="mt-2 hidden text-xs text-slate-500">Tick "Failed Quality Testing" to choose parameters and write the details.</p>

        <!-- Usable only while "Failed Quality Testing" is ticked -->
        <div data-gate-body="b" class="mt-4">
            <fieldset>
                <legend class="text-sm font-medium text-slate-700">Parameters (tick at least one)</legend>
                <div class="mt-2 grid gap-2 sm:grid-cols-2">
                    <?php foreach ($qualityParams as $code => $label): ?>
                        <label class="<?= $choiceClass ?>">
                            <input type="checkbox" name="b_params[]" value="<?= e($code) ?>" class="h-5 w-5 accent-brand-600" <?= in_array($code, $values['b_params'], true) ? 'checked' : '' ?>>
                            <span><?= e($label) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <?php field_error($errors, 'b_params'); ?>
            </fieldset>

            <div class="mt-4">
                <label for="b_details" class="block text-sm font-medium text-slate-700">Details (use a separate sheet when necessary)</label>
                <p class="mt-1 text-xs text-slate-500">'Low Quality' alone is not sufficient. Describe what failed.</p>
                <textarea id="b_details" name="b_details" rows="4" class="mt-1 <?= e(input_class($errors, 'b_details')) ?>"><?= e($values['b_details']) ?></textarea>
                <?php field_error($errors, 'b_details'); ?>
            </div>
        </div>
    </section>

    <!-- Section C -->
    <section class="card mb-4">
        <h2 class="text-base font-semibold text-slate-900">C. Others</h2>
        <label class="<?= $choiceClass ?> mt-3">
            <input type="checkbox" name="others_checked" value="1" data-gate-checkbox="c"
                   class="h-5 w-5 accent-brand-600" <?= (int) $values['others_checked'] === 1 ? 'checked' : '' ?>>
            <span>Others</span>
        </label>
        <p data-gate-hint="c" class="mt-2 hidden text-xs text-slate-500">Tick "Others" to choose parameters and write the details.</p>

        <!-- Usable only while "Others" is ticked -->
        <div data-gate-body="c" class="mt-4">
            <fieldset>
                <legend class="text-sm font-medium text-slate-700">Parameters (tick at least one)</legend>
                <div class="mt-2 grid gap-2 sm:grid-cols-2">
                    <?php foreach ($othersParams as $code => $label): ?>
                        <label class="<?= $choiceClass ?>">
                            <input type="checkbox" name="c_params[]" value="<?= e($code) ?>" class="h-5 w-5 accent-brand-600" <?= in_array($code, $values['c_params'], true) ? 'checked' : '' ?>>
                            <span><?= e($label) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <?php field_error($errors, 'c_params'); ?>
            </fieldset>

            <div class="mt-4">
                <label for="c_details" class="block text-sm font-medium text-slate-700">Details (use a separate sheet when necessary)</label>
                <textarea id="c_details" name="c_details" rows="4" class="mt-1 <?= e(input_class($errors, 'c_details')) ?>"><?= e($values['c_details']) ?></textarea>
                <?php field_error($errors, 'c_details'); ?>
            </div>
        </div>
    </section>

    <div class="flex flex-col gap-3 sm:flex-row">
        <button type="submit" name="action" value="draft" class="btn btn-secondary w-full sm:w-auto">Save draft</button>
        <button type="submit" name="action" value="review" class="btn btn-primary w-full sm:w-auto">Save and review</button>
        <a href="<?= e(url('evaluator/assignments.php')) ?>" class="btn btn-secondary w-full sm:w-auto">Back to assignments</a>
    </div>
</form>

<script>
// 1) Show the "Ground/s" box only while Non-Responsive is picked.
// 2) In Sections B and C, switch the parameters and the details box off
//    (greyed out, not clickable) until the box above them is ticked.
// Without JavaScript everything is simply always usable; the server ignores
// the parameters and details of an unticked box anyway.
document.querySelectorAll('[data-item-row]').forEach(function (row) {
    var box = row.querySelector('[data-grounds]');
    var radios = row.querySelectorAll('input[data-finding]');
    function update() {
        var picked = row.querySelector('input[data-finding]:checked');
        box.classList.toggle('hidden', !(picked && picked.value === 'non_responsive'));
    }
    radios.forEach(function (r) { r.addEventListener('change', update); });
    update();
});

document.querySelectorAll('[data-gate-checkbox]').forEach(function (checkbox) {
    var key  = checkbox.getAttribute('data-gate-checkbox');
    var body = document.querySelector('[data-gate-body="' + key + '"]');
    var hint = document.querySelector('[data-gate-hint="' + key + '"]');
    function update() {
        var on = checkbox.checked;
        body.querySelectorAll('input, textarea').forEach(function (el) { el.disabled = !on; });
        body.classList.toggle('opacity-50', !on);
        hint.classList.toggle('hidden', on);
    }
    checkbox.addEventListener('change', update);
    update();
});
</script>

<?php require __DIR__ . '/../app/views/layout/footer.php'; ?>