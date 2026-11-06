<?php
/**
 * app/views/partials/supplier-sections.php
 *
 * Read-only cards with the SUPPLIER part of a form: who sent it, the header
 * and Section A. Used by the Head Nurse page (later Steps can reuse it).
 *
 *   render_supplier_sections($eval, $items);
 *
 * $eval needs the supplier-visible columns plus supplier_business and
 * supplier_contact; $items is the output of supplier_load_items().
 */

require_once __DIR__ . '/../../domain/mms-forms.php';

function render_supplier_sections(array $eval, array $items): void
{
    ?>
    <section class="card">
        <div class="flex items-start justify-between gap-3">
            <h2 class="text-base font-semibold text-slate-900">Supplier</h2>
            <?= status_badge($eval['status']) ?>
        </div>
        <dl class="mt-4 grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
            <div><dt class="text-slate-500">Business name</dt><dd class="font-medium text-slate-900"><?= e($eval['supplier_business'] ?? '-') ?></dd></div>
            <div><dt class="text-slate-500">Contact person</dt><dd class="font-medium text-slate-900"><?= e($eval['supplier_contact'] ?? '-') ?></dd></div>
            <div><dt class="text-slate-500">Submitted</dt><dd class="font-medium text-slate-900"><?= e(mms_date_time($eval['submitted_at'])) ?></dd></div>
        </dl>
    </section>

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
    <?php
}