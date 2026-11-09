<?php
/**
 * app/views/partials/signature-validity.php
 *
 * The Signature Validity panel. common/verify.php and mms/record.php both
 * call signature_validity_panel() with the result of verify_form(), so the
 * two pages show exactly the same thing.
 */

/** A small coloured label for match / mismatch / old. */
function verify_badge_html(string $result): string
{
    if ($result === 'match') {
        return '<span class="inline-block rounded-full bg-emerald-100 px-3 py-1 text-xs font-semibold text-emerald-800">Match</span>';
    }
    if ($result === 'mismatch') {
        return '<span class="inline-block rounded-full bg-rose-600 px-3 py-1 text-xs font-bold text-white">MISMATCH</span>';
    }
    return '<span class="inline-block rounded-full bg-slate-100 px-3 py-1 text-xs font-medium text-slate-600">Superseded version, not checked</span>';
}

/**
 * Prints the banner, the supplier-part line and the signatures table.
 *
 * $form   a row from conforme_load_form()
 * $check  the array returned by verify_form($form)
 */
function signature_validity_panel(array $form, array $check): void
{
    $roleLabels = [
        'evaluator' => 'Evaluated by (Evaluator)',
        'head'      => 'Verified by (Department Head)',
        'conforme'  => 'Conforme (Supplier)',
    ];

    $fmtTime = static function ($t): string {
        $time = $t ? strtotime((string) $t) : false;
        return $time ? date('M j, Y g:i A', $time) : '';
    };

    $paperConforme = in_array((string) $form['status'], ['completed', 'received'], true)
        && (string) ($form['conforme_method'] ?? '') === 'paper';

    $rows    = $check['rows'];
    $version = (int) $check['version'];
    ?>
    <?php if ($check['any_mismatch']): ?>
        <div role="alert" class="mb-4 rounded-xl border-2 border-rose-600 bg-rose-50 px-4 py-4 text-sm text-rose-900">
            <p class="text-base font-bold">MISMATCH</p>
            <p class="mt-1">This form does not match what was signed. Do not rely on it. Please tell MMS and the administrator.</p>
        </div>
    <?php elseif ($check['current_checked'] > 0): ?>
        <div class="mb-4 rounded-xl border border-emerald-300 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-900">
            Every signature of the current version matches the stored form.
        </div>
    <?php else: ?>
        <div class="mb-4 rounded-xl border border-slate-300 bg-slate-50 px-4 py-3 text-sm text-slate-700">
            There are no signatures on the current version yet.
        </div>
    <?php endif; ?>

    <section class="card mb-4">
        <h2 class="text-base font-semibold text-slate-900">Signature validity</h2>

        <?php if ($check['supplier_result'] !== 'none'): ?>
            <p class="mt-3 text-sm text-slate-700">Supplier part (as submitted): <?= verify_badge_html((string) $check['supplier_result']) ?></p>
        <?php endif; ?>

        <?php if ($rows === [] && !$paperConforme): ?>
            <p class="mt-2 text-sm text-slate-600">No signatures yet.</p>
        <?php else: ?>
            <div class="mt-3 overflow-x-auto">
                <table class="min-w-full text-left text-sm">
                    <thead>
                        <tr class="border-b border-slate-300 text-slate-600">
                            <th class="py-2 pr-3 font-semibold">Version</th>
                            <th class="py-2 pr-3 font-semibold">Role</th>
                            <th class="py-2 pr-3 font-semibold">Signer</th>
                            <th class="py-2 pr-3 font-semibold">Signed</th>
                            <th class="py-2 font-semibold">Result</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $s): ?>
                            <tr class="border-b border-slate-200 align-top">
                                <td class="py-2 pr-3"><?= (int) $s['version'] ?></td>
                                <td class="py-2 pr-3"><?= e($roleLabels[$s['role']] ?? (string) $s['role']) ?></td>
                                <td class="py-2 pr-3">
                                    <span class="font-medium"><?= e((string) $s['typed_name']) ?></span>
                                    <?php if ((string) $s['position'] !== ''): ?><br><span class="text-slate-500"><?= e((string) $s['position']) ?></span><?php endif; ?>
                                    <?php if ((string) $s['business_name'] !== ''): ?><br><span class="text-slate-500"><?= e((string) $s['business_name']) ?></span><?php endif; ?>
                                </td>
                                <td class="py-2 pr-3"><?= e($fmtTime($s['signed_at'])) ?></td>
                                <td class="py-2"><?= verify_badge_html((string) $s['result']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if ($paperConforme): ?>
                            <tr class="border-b border-slate-200 align-top">
                                <td class="py-2 pr-3"><?= $version ?></td>
                                <td class="py-2 pr-3"><?= e($roleLabels['conforme']) ?></td>
                                <td class="py-2 pr-3" colspan="3">Signed on paper. The scan is kept by MMS.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
    <?php
}