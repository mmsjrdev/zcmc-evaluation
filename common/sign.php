<?php
/**
 * common/sign.php?id=N
 *
 * The signing screen for the EVALUATOR and the DEPARTMENT HEAD.
 * First the whole form to read (read only), then the signing box:
 * signature (drawn, or "use my saved signature"), typed full name,
 * acknowledgment, password, and an optional "save this signature".
 *
 * All rules are checked again by signing_sign() in ONE transaction. This page
 * only shows the screen and the messages. It needs JavaScript for the pad.
 */

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/domain/signing.php';
require_once __DIR__ . '/../app/views/partials/evaluation-summary.php';

$user = require_role(['evaluator', 'head']);

$id   = (int) ($_GET['id'] ?? 0);
$eval = $id > 0 ? ev_load_full_form($id) : null;

if (!$eval || !can_view_evaluation($user, $eval)) {
    forbidden();
}

// Not something this person can sign right now: send them to the read-only view.
$role = signing_role_for($user, $eval);
if ($role === null) {
    flash('info', 'This form is not waiting for your signature.');
    redirect('common/evaluation-view.php?id=' . $id);
}

$items  = ev_load_items_full($id);
$values = ev_values_from_db($eval, $items);

// The signer's own profile name and saved signature (shown on the screen).
$meStmt = db()->prepare('SELECT full_name, saved_signature FROM users WHERE id = :id LIMIT 1');
$meStmt->execute([':id' => (int) $user['id']]);
$me = $meStmt->fetch() ?: ['full_name' => '', 'saved_signature' => null];
$savedSignature = signing_clean_png((string) ($me['saved_signature'] ?? ''));

// Problems that stop the evaluator from signing (the same check signing_sign() runs).
$problems = [];
if ($role === 'evaluator') {
    foreach (validate_evaluator_part($values, $items, true) as $key => $message) {
        if (preg_match('/^items\.(\d+)\./', (string) $key, $m)) {
            $message = 'Item ' . ((int) $m[1] + 1) . ': ' . $message;
        } elseif ($key === 'b_params' || $key === 'b_details') {
            $message = 'Section B: ' . $message;
        } elseif ($key === 'c_params' || $key === 'c_details') {
            $message = 'Section C: ' . $message;
        }
        $problems[] = $message;
    }
}

// A head who evaluates needs ANOTHER head or alternate verifier in the department.
$noVerifier = $role === 'evaluator' && $user['role'] === 'head'
    && !department_has_other_verifier((int) $eval['department_id'], (int) $user['id']);

$formError  = '';
$formList   = [];
$typedName  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $typedName = (string) ($_POST['typed_name'] ?? '');

    try {
        signing_sign($user, $id, $_POST);

        if ($role === 'evaluator') {
            flash('success', 'Signed. The form now goes to the Department Head.');
            redirect('common/evaluation-view.php?id=' . $id);
        }
        flash('success', 'Signed. The form now waits for the supplier\'s Conforme.');
        redirect('head/for-signature.php');
    } catch (SigningRefused $ex) {
        $formError = $ex->getMessage();
        $formList  = $ex->problems;
    } catch (WorkflowException $ex) {
        $formError = $ex->getMessage();
    }
}

$canSign = $problems === [] && !$noVerifier;

$ackText = 'I confirm that this is my signature, that I have reviewed this evaluation form, and that I am signing it voluntarily.';
$inputClass = 'mt-1 block min-h-11 w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 focus:border-brand-600 focus:outline-none focus:ring-4 focus:ring-brand-200';

$pageTitle = $role === 'evaluator' ? 'Sign as evaluator' : 'Sign as Department Head';
require __DIR__ . '/../app/views/layout/header.php';

page_header($pageTitle, mms_form_title($eval));
?>

<p class="mb-4 text-sm text-slate-600">
    Read the whole form first. The signing box is at the bottom.
</p>

<?php evaluation_summary($eval, $items, $values); ?>

<!-- Signing box -->
<section class="card mb-4" id="signing-box">
    <h2 class="text-base font-semibold text-slate-900">
        Your signature as <?= $role === 'evaluator' ? 'evaluator ("Evaluated by")' : 'Department Head ("Verified by")' ?>
    </h2>

    <?php if ($formError !== ''): ?>
        <div role="alert" class="mt-3 rounded-xl border border-rose-300 bg-rose-50 px-4 py-3 text-sm text-rose-800">
            <p class="font-semibold"><?= e($formError) ?></p>
            <?php if ($formList !== []): ?>
                <ul class="mt-2 list-disc space-y-1 pl-5">
                    <?php foreach ($formList as $p): ?><li><?= e($p) ?></li><?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($noVerifier): ?>
        <div role="alert" class="mt-3 rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900">
            <p class="font-semibold">No verifier available. Contact the admin.</p>
            <p class="mt-1">Your own evaluation must be verified by another Department Head or alternate verifier of this department, and there is none yet.</p>
        </div>
    <?php elseif ($problems !== []): ?>
        <div role="alert" class="mt-3 rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900">
            <p class="font-semibold">You cannot sign yet. This is missing:</p>
            <ul class="mt-2 list-disc space-y-1 pl-5">
                <?php foreach ($problems as $p): ?><li><?= e($p) ?></li><?php endforeach; ?>
            </ul>
            <a href="<?= e(url('evaluator/evaluate.php?id=' . $id)) ?>" class="btn btn-secondary mt-3 w-full sm:w-auto">Go back and edit</a>
        </div>
    <?php endif; ?>

    <?php if ($canSign): ?>
        <noscript>
            <p class="mt-3 rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900">Signing needs JavaScript to draw the signature. Please turn it on.</p>
        </noscript>

        <form method="post" action="<?= e(url('common/sign.php?id=' . $id)) ?>" class="mt-4 space-y-5" novalidate autocomplete="off">
            <?= csrf_field() ?>

            <!-- Signature -->
            <fieldset>
                <legend class="text-sm font-medium text-slate-700">Signature</legend>

                <?php if ($savedSignature !== ''): ?>
                    <div class="mt-2 grid gap-2 sm:grid-cols-2">
                        <label class="flex min-h-11 cursor-pointer items-center gap-3 rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-900 has-[:checked]:border-brand-600 has-[:checked]:bg-brand-50">
                            <input type="radio" name="signature_source" value="draw" class="h-5 w-5 accent-brand-600" checked>
                            <span>Draw a new signature</span>
                        </label>
                        <label class="flex min-h-11 cursor-pointer items-center gap-3 rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-900 has-[:checked]:border-brand-600 has-[:checked]:bg-brand-50">
                            <input type="radio" name="signature_source" value="saved" class="h-5 w-5 accent-brand-600">
                            <span>Use my saved signature</span>
                        </label>
                    </div>
                    <div class="mt-2 rounded-xl border border-slate-200 bg-white p-2">
                        <p class="text-xs text-slate-500">Your saved signature:</p>
                        <img src="<?= e($savedSignature) ?>" alt="Your saved signature" class="mt-1 h-16 w-auto">
                    </div>
                <?php endif; ?>

                <div data-signature-pad class="mt-2">
                    <div class="overflow-hidden rounded-xl border-2 border-dashed border-slate-400 bg-white">
                        <canvas data-pad-canvas class="block aspect-[3/1] w-full touch-none" aria-label="Signature box. Draw your signature here."></canvas>
                    </div>
                    <div class="mt-2 flex gap-2">
                        <button type="button" data-pad-undo class="btn btn-secondary flex-1 sm:flex-none">Undo</button>
                        <button type="button" data-pad-clear class="btn btn-secondary flex-1 sm:flex-none">Clear</button>
                    </div>
                    <p data-pad-error hidden role="alert" class="mt-2 text-sm font-medium text-red-600"></p>
                    <input type="hidden" name="signature_png" data-pad-output>

                    <label class="mt-3 flex min-h-11 cursor-pointer items-center gap-3 text-sm text-slate-700">
                        <input type="checkbox" name="save_signature" value="1" class="h-5 w-5 accent-brand-600">
                        <span>Save this signature for next time</span>
                    </label>
                </div>
            </fieldset>

            <!-- Typed name -->
            <div>
                <label for="typed_name" class="block text-sm font-medium text-slate-700">Type your complete name</label>
                <p class="mt-1 text-xs text-slate-500">It must match the full name in your profile: <strong><?= e($me['full_name']) ?></strong></p>
                <input type="text" id="typed_name" name="typed_name" value="<?= e($typedName) ?>" autocomplete="off" class="<?= $inputClass ?>">
            </div>

            <!-- Acknowledgment -->
            <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-slate-300 bg-white px-3 py-3 text-sm text-slate-900 has-[:checked]:border-brand-600 has-[:checked]:bg-brand-50">
                <input type="checkbox" name="acknowledge" value="1" class="mt-0.5 h-5 w-5 shrink-0 accent-brand-600">
                <span><?= e($ackText) ?></span>
            </label>

            <!-- Password (an OTP replaces this in Step 15) -->
            <div>
                <label for="password" class="block text-sm font-medium text-slate-700">Enter your password to sign</label>
                <input type="password" id="password" name="password" autocomplete="current-password" class="<?= $inputClass ?>">
            </div>

            <div class="flex flex-col gap-3 sm:flex-row">
                <button type="submit" class="btn btn-primary w-full sm:w-auto">Sign</button>
                <?php if ($role === 'head'): ?>
                    <a href="<?= e(url('common/return.php?id=' . $id)) ?>" class="btn btn-secondary w-full sm:w-auto">Return for correction</a>
                <?php endif; ?>
                <a href="<?= e(url('common/evaluation-view.php?id=' . $id)) ?>" class="btn btn-secondary w-full sm:w-auto">Cancel</a>
            </div>
        </form>

        <script src="<?= e(url('assets/js/signature-pad.js')) ?>"></script>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/../app/views/layout/footer.php'; ?>