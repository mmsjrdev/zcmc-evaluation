<?php
/**
 * mms/records.php
 *
 * Step 11: every submitted form, with search, filters and paging.
 * A normal GET form and full page loads (no fetch). Who: mm_staff and admin.
 *
 * Filters: q (reference no., bid/reference no., supplier, bidder or department),
 * status, date submitted (from / to), department, mode of procurement,
 * and "stuck" (no status change for more than 7 days, not finished).
 */

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/domain/mms-records.php';
require_once __DIR__ . '/../app/views/partials/mms-ui.php';

$user = require_role(['mm_staff', 'admin']);

$f = mms_records_filters($_GET);

$total     = mms_records_count($f);
$pageCount = max(1, (int) ceil($total / MMS_RECORDS_PAGE_SIZE));
$page      = min($f['page'], $pageCount);
$rows      = mms_records_fetch($f, MMS_RECORDS_PAGE_SIZE, ($page - 1) * MMS_RECORDS_PAGE_SIZE);

$departments = mms_department_options();
$modes       = mms_mode_options();

$statuses = status_definitions();
unset($statuses['supplier_draft']);

/** The name of an option from a list of [id, name] rows (for the chips). */
$nameOf = static function (array $list, int $id): string {
    foreach ($list as $item) {
        if ((int) $item['id'] === $id) {
            return (string) $item['name'];
        }
    }
    return '#' . $id;
};

$fmtDate = static function ($t): string {
    $time = $t ? strtotime((string) $t) : false;
    return $time ? date('M j, Y', $time) : '';
};

// The chips under the search bar: one per active filter (not the search text).
// Each chip links to the same list WITHOUT that one filter.
$chips = [];
if ($f['status'] !== '') {
    $chips[] = ['Status: ' . status_label($f['status']), ['status' => '']];
}
if ($f['department'] > 0) {
    $chips[] = ['Department: ' . $nameOf($departments, $f['department']), ['department' => 0]];
}
if ($f['mode'] > 0) {
    $chips[] = ['Mode: ' . $nameOf($modes, $f['mode']), ['mode' => 0]];
}
if ($f['from'] !== '') {
    $chips[] = ['From ' . $fmtDate($f['from']), ['from' => '']];
}
if ($f['to'] !== '') {
    $chips[] = ['To ' . $fmtDate($f['to']), ['to' => '']];
}
if ($f['stuck'] === 1) {
    $chips[] = ['Stuck for more than ' . (int) MMS_STUCK_DAYS . ' days', ['stuck' => 0]];
}

$hasFilter = $chips !== [] || $f['q'] !== '';

$inputClass = 'block min-h-11 w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 focus:border-brand-600 focus:outline-none focus:ring-4 focus:ring-brand-200';
$labelClass = 'block text-xs font-semibold uppercase tracking-wide text-slate-500';

$pageTitle = 'Form records';
require __DIR__ . '/../app/views/layout/header.php';

page_header($pageTitle, 'Search and filter every submitted form.');
?>

<section class="card mb-4">
    <form method="get" action="<?= e(url('mms/records.php')) ?>">
        <label for="q" class="block text-sm font-medium text-slate-700">Search</label>
        <div class="mt-1 flex flex-col gap-3 sm:flex-row">
            <input type="text" id="q" name="q" value="<?= e($f['q']) ?>" maxlength="100"
                   placeholder="Reference no., bid no., supplier, bidder or department" autocomplete="off"
                   class="<?= $inputClass ?> flex-1">
            <button type="submit" class="btn btn-primary w-full sm:w-auto">Search</button>
        </div>

        <details class="mt-4">
            <summary class="inline-flex min-h-11 cursor-pointer list-none items-center gap-2 rounded-xl border border-slate-300 bg-white px-4 text-sm font-medium text-slate-700 hover:bg-slate-50 focus:outline-none focus:ring-4 focus:ring-brand-200 [&::-webkit-details-marker]:hidden">
                Filters
                <?php if ($chips !== []): ?>
                    <span class="rounded-full bg-emerald-600 px-2 py-0.5 text-xs font-semibold text-white"><?= count($chips) ?></span>
                <?php endif; ?>
            </summary>

            <div class="mt-3 rounded-xl border border-slate-200 bg-slate-50 p-4">
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <div>
                        <label for="status" class="<?= $labelClass ?>">Status</label>
                        <select id="status" name="status" class="<?= $inputClass ?> mt-1">
                            <option value="">All statuses</option>
                            <?php foreach ($statuses as $code => $def): ?>
                                <option value="<?= e($code) ?>" <?= $f['status'] === $code ? 'selected' : '' ?>><?= e($def[0]) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="department" class="<?= $labelClass ?>">Department</label>
                        <select id="department" name="department" class="<?= $inputClass ?> mt-1">
                            <option value="">All departments</option>
                            <?php foreach ($departments as $d): ?>
                                <option value="<?= (int) $d['id'] ?>" <?= $f['department'] === (int) $d['id'] ? 'selected' : '' ?>><?= e((string) $d['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="mode" class="<?= $labelClass ?>">Mode of procurement</label>
                        <select id="mode" name="mode" class="<?= $inputClass ?> mt-1">
                            <option value="">All modes</option>
                            <?php foreach ($modes as $m): ?>
                                <option value="<?= (int) $m['id'] ?>" <?= $f['mode'] === (int) $m['id'] ? 'selected' : '' ?>><?= e((string) $m['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="from" class="<?= $labelClass ?>">Submitted from</label>
                        <input type="date" id="from" name="from" value="<?= e($f['from']) ?>" class="<?= $inputClass ?> mt-1">
                    </div>
                    <div>
                        <label for="to" class="<?= $labelClass ?>">Submitted to</label>
                        <input type="date" id="to" name="to" value="<?= e($f['to']) ?>" class="<?= $inputClass ?> mt-1">
                    </div>
                    <div class="flex items-end">
                        <label for="stuck" class="flex min-h-11 w-full cursor-pointer items-center gap-3 rounded-xl border border-slate-300 bg-white px-3 text-sm font-medium text-slate-700">
                            <input type="checkbox" id="stuck" name="stuck" value="1" <?= $f['stuck'] === 1 ? 'checked' : '' ?>
                                   class="h-5 w-5 rounded border-slate-300 text-brand-700 focus:ring-4 focus:ring-brand-200">
                            Stuck &gt; <?= (int) MMS_STUCK_DAYS ?> days
                        </label>
                    </div>
                </div>

                <div class="mt-4 flex flex-col gap-3 sm:flex-row">
                    <button type="submit" class="btn btn-primary w-full sm:w-auto">Apply filters</button>
                    <a href="<?= e(mms_records_url(['q' => $f['q']])) ?>" class="btn btn-secondary w-full sm:w-auto">Reset filters</a>
                </div>
            </div>
        </details>
    </form>

    <?php if ($chips !== []): ?>
        <ul class="mt-4 flex flex-wrap gap-2" aria-label="Active filters">
            <?php foreach ($chips as $chip): ?>
                <li>
                    <a href="<?= e(mms_records_url($f, array_merge($chip[1], ['page' => 1]))) ?>"
                       class="inline-flex min-h-8 items-center gap-2 rounded-full bg-emerald-50 px-3 py-1 text-xs font-medium text-emerald-800 ring-1 ring-inset ring-emerald-200 hover:bg-emerald-100 focus:outline-none focus:ring-4 focus:ring-brand-200">
                        <?= e($chip[0]) ?>
                        <span aria-hidden="true">&times;</span>
                        <span class="sr-only">Remove this filter</span>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>

<section class="card mb-4">
    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <p class="text-sm text-slate-600">
            <?= (int) $total ?> form<?= $total === 1 ? '' : 's' ?> found<?= $hasFilter ? ' with these filters' : '' ?>
            &middot; page <?= (int) $page ?> of <?= (int) $pageCount ?>
        </p>
        <p class="flex gap-4 text-sm font-medium">
            <?php if ($hasFilter): ?>
                <a href="<?= e(url('mms/records.php')) ?>" class="text-brand-700 underline">Clear all</a>
            <?php endif; ?>
            <a href="<?= e(mms_records_url($f, ['page' => 1], 'mms/export.php')) ?>" class="text-brand-700 underline">Export CSV</a>
        </p>
    </div>

    <?php if ($rows === []): ?>
        <p class="mt-4 text-sm text-slate-700">No forms match. Try fewer filters or a shorter search.</p>
    <?php else: ?>
        <ul class="mt-3 divide-y divide-slate-200 border-t border-slate-200">
            <?php foreach ($rows as $r): ?>
                <?php
                $supplier = trim((string) ($r['supplier_business'] ?? '')) !== ''
                    ? (string) $r['supplier_business']
                    : (string) ($r['supplier_name'] ?? '');
                $bidder = trim((string) ($r['bidder'] ?? ''));

                // The small grey line: only the parts that have a value.
                $meta = [];
                if ((string) ($r['bid_ref_no'] ?? '') !== '') {
                    $meta[] = 'Bid no. ' . $r['bid_ref_no'];
                }
                if ($bidder !== '' && $bidder !== $supplier) {
                    $meta[] = 'Bidder: ' . $bidder;
                }
                if ((string) ($r['department_name'] ?? '') !== '') {
                    $meta[] = $r['department_name'];
                }
                if ((string) ($r['mode_name'] ?? '') !== '') {
                    $meta[] = $r['mode_name'];
                }
                if ($fmtDate($r['submitted_at']) !== '') {
                    $meta[] = 'Submitted ' . $fmtDate($r['submitted_at']);
                }
                if ($fmtDate($r['last_change_at']) !== '') {
                    $meta[] = 'Updated ' . $fmtDate($r['last_change_at']);
                }
                ?>
                <li>
                    <a href="<?= e(url('mms/record.php?id=' . (int) $r['id'])) ?>"
                       class="block px-1 py-3 hover:bg-slate-50 focus:outline-none focus:ring-4 focus:ring-brand-200">
                        <span class="flex items-start justify-between gap-3">
                            <span class="font-semibold text-slate-900"><?= e((string) ($r['ref_no'] ?? '') !== '' ? (string) $r['ref_no'] : 'Form #' . (int) $r['id']) ?></span>
                            <?= status_pill((string) $r['status']) ?>
                        </span>
                        <span class="mt-1 block text-sm text-slate-700"><?= e($supplier) ?></span>
                        <span class="mt-1 block text-xs text-slate-500"><?= e(implode(' · ', $meta)) ?></span>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>

        <?php if ($pageCount > 1): ?>
            <nav class="mt-4 flex flex-col gap-3 sm:flex-row sm:justify-between" aria-label="Pages">
                <?php if ($page > 1): ?>
                    <a href="<?= e(mms_records_url($f, ['page' => $page - 1])) ?>" class="btn btn-secondary w-full sm:w-auto">Previous</a>
                <?php else: ?>
                    <span></span>
                <?php endif; ?>
                <?php if ($page < $pageCount): ?>
                    <a href="<?= e(mms_records_url($f, ['page' => $page + 1])) ?>" class="btn btn-secondary w-full sm:w-auto">Next</a>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/../app/views/layout/footer.php'; ?>