<?php
/**
 * app/views/partials/evaluation-summary.php
 *
 * The read-only body of an evaluation form: header card, Section A, Section B
 * and Section C (including the evaluator data). Used by
 *   - common/evaluation-view.php
 *   - common/sign.php and common/return.php (the "read it first" part)
 * Never include it on a supplier page: it shows evaluator data.
 *
 * Needs app/domain/evaluator-forms.php (ev_* helpers), definitions.php and
 * mms-forms.php (mms_date) to be loaded by the page.
 */

/**
 * Prints the summary.
 *   $eval   from ev_load_full_form()
 *   $items  from ev_load_items_full()
 *   $values from ev_values_from_db()
 */
function evaluation_summary(array $eval, array $items, array $values): void
{
    $qualityParams = quality_parameters();
    $othersParams  = others_parameters();
    ?>
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
    <?php
}