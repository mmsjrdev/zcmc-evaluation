<?php
/**
 * head/for-signature.php
 *
 * The Department Head's list: forms signed by the evaluator and waiting for
 * verification (status pending_head) in their own department or in a
 * department where they are an alternate verifier. A head never sees their
 * own evaluations here.
 */

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/domain/signing.php';

$user  = require_role(['head']);
$forms = signing_pending_for_head($user);

$pageTitle = 'Forms for signature';
require __DIR__ . '/../app/views/layout/header.php';

page_header('Forms for signature', 'Evaluations waiting for your verification');
?>

<?php if ($forms === []): ?>
    <div class="card text-sm text-slate-600">Nothing is waiting for your signature right now.</div>
<?php else: ?>
    <ul class="space-y-3">
        <?php foreach ($forms as $f): ?>
            <li class="card">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div class="min-w-0">
                        <p class="text-sm font-semibold text-slate-900">
                            <?= e($f['ref_no'] ?: 'Bid/Ref. No. ' . ($f['bid_ref_no'] !== '' ? $f['bid_ref_no'] : '-')) ?>
                            <?php if (!empty($f['bidder'])): ?> &middot; <?= e($f['bidder']) ?><?php endif; ?>
                        </p>
                        <p class="mt-1 text-xs text-slate-500">
                            <?= e((string) ($f['department_name'] ?? '-')) ?>
                            &middot; <?= (int) $f['item_count'] ?> item<?= (int) $f['item_count'] === 1 ? '' : 's' ?>
                            &middot; Evaluator: <?= e((string) ($f['evaluator_name'] ?? '-')) ?>
                            <?php if (!empty($f['evaluator_signed_at'])): ?>
                                &middot; Signed <?= e(mms_date_time($f['evaluator_signed_at'])) ?>
                            <?php endif; ?>
                        </p>
                    </div>
                    <div class="flex flex-col gap-2 sm:flex-row">
                        <a href="<?= e(url('common/sign.php?id=' . (int) $f['id'])) ?>" class="btn btn-primary w-full sm:w-auto">Review and sign</a>
                        <a href="<?= e(url('common/evaluation-view.php?id=' . (int) $f['id'])) ?>" class="btn btn-secondary w-full sm:w-auto">Open</a>
                    </div>
                </div>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

<?php require __DIR__ . '/../app/views/layout/footer.php'; ?>