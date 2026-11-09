<?php
/**
 * app/views/partials/mms-dashboard.php
 *
 * The MMS part of the dashboard: a count per status (each one links to the
 * filtered list), the "stuck" count, and the completed forms waiting to be
 * received. common/dashboard.php calls mms_dashboard_panel() for mm_staff.
 *
 * The page that calls it must already have loaded:
 *   app/domain/mms-records.php   and   app/views/partials/mms-ui.php
 */

function mms_dashboard_panel(): void
{
    $counts   = mms_status_counts();
    $stuck    = mms_stuck_count();
    $waiting  = mms_waiting_to_receive(20);
    $statuses = status_definitions();
    unset($statuses['supplier_draft']);   // drafts belong to the supplier
    ?>
    <section class="mb-6">
        <h2 class="mb-3 text-base font-semibold text-slate-900">Forms by status</h2>
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
            <?php foreach ($statuses as $code => $def): ?>
                <a href="<?= e(url('mms/records.php?status=' . rawurlencode($code))) ?>"
                   class="card block min-h-11 focus:outline-none focus:ring-4 focus:ring-brand-200">
                    <p class="text-2xl font-bold text-slate-900"><?= (int) ($counts[$code] ?? 0) ?></p>
                    <p class="mt-1 text-sm text-slate-600"><?= e($def[0]) ?></p>
                </a>
            <?php endforeach; ?>
            <a href="<?= e(url('mms/records.php?stuck=1')) ?>"
               class="card block min-h-11 border-amber-300 focus:outline-none focus:ring-4 focus:ring-brand-200">
                <p class="text-2xl font-bold text-amber-800"><?= (int) $stuck ?></p>
                <p class="mt-1 text-sm text-slate-600">Stuck for more than <?= (int) MMS_STUCK_DAYS ?> days</p>
            </a>
        </div>
    </section>

    <section class="card mb-6">
        <div class="flex items-center justify-between gap-3">
            <h2 class="text-base font-semibold text-slate-900">Completed, waiting to be received</h2>
            <a href="<?= e(url('mms/records.php?status=completed')) ?>" class="text-sm font-medium text-brand-700 underline">See all</a>
        </div>

        <?php if ($waiting === []): ?>
            <p class="mt-3 text-sm text-slate-600">Nothing is waiting. All completed forms have been received.</p>
        <?php else: ?>
            <div class="mt-3 overflow-x-auto">
                <table class="min-w-full text-left text-sm">
                    <thead>
                        <tr class="border-b border-slate-300 text-slate-600">
                            <th class="py-2 pr-3 font-semibold">Reference No.</th>
                            <th class="py-2 pr-3 font-semibold">Bidder</th>
                            <th class="py-2 pr-3 font-semibold">Department</th>
                            <th class="py-2 pr-3 font-semibold">Completed</th>
                            <th class="py-2 font-semibold"><span class="sr-only">Open</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($waiting as $r): ?>
                            <?php $time = $r['last_change_at'] ? strtotime((string) $r['last_change_at']) : false; ?>
                            <tr class="border-b border-slate-200 align-top">
                                <td class="py-2 pr-3 font-medium text-slate-900"><?= e((string) ($r['ref_no'] ?? '')) ?></td>
                                <td class="py-2 pr-3"><?= e((string) ($r['bidder'] ?? '')) ?></td>
                                <td class="py-2 pr-3"><?= e((string) ($r['department_name'] ?? '')) ?></td>
                                <td class="py-2 pr-3"><?= e($time ? date('M j, Y', $time) : '') ?></td>
                                <td class="py-2"><a href="<?= e(url('mms/record.php?id=' . (int) $r['id'])) ?>" class="font-medium text-brand-700 underline">Open</a></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
    <?php
}