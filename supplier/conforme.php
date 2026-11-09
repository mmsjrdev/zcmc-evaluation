<?php
/**
 * supplier/conforme.php?id=N
 *
 * The supplier signs the Conforme in their own account (Step 10).
 * Only for the supplier who owns the form, and only at pending_conforme.
 *
 * SUPPLIER RULE: this page reads the form with conforme_load_form() and the
 * supplier_* queries, which never read Sections B or C. The result summary
 * (findings and grounds only) is loaded ONLY when
 * SUPPLIER_SEES_RESULT_AT_CONFORME is true.
 *
 * All checks run again in conforme_sign(). Needs JavaScript for the pad.
 * Step 15: with OTP_ENABLED true, a one-time code replaces the password.
 */

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/domain/supplier-conforme.php';

$user = require_role(['supplier']);

$id   = (int) ($_GET['id'] ?? 0);
$form = $id > 0 ? conforme_load_form($id) : null;

if (!$form || !can_view_evaluation($user, $form)) {
    forbidden();
}

if ((string) $form['status'] !== 'pending_conforme') {
    flash('info', 'This form is not waiting for your Conforme.');
    redirect('supplier/form-view.php?id=' . $id);
}

// The supplier's own sections (read only).
$supplierRow = supplier_load_form($id);
$header      = $supplierRow ? supplier_header_values($supplierRow) : [];
$items       = supplier_load_items($id);
$modeName    = (string) ($supplierRow['mode_name'] ?? '');

// The result summary only when the switch in config.php is on.
$showResult = defined('SUPPLIER_SEES_RESULT_AT_CONFORME') && SUPPLIER_SEES_RESULT_AT_CONFORME;
$results    = $showResult ? conforme_result_rows($id) : [];

// The signer's profile (name hint and the starting values of the two typed fields).
$meStmt = db()->prepare('SELECT full_name, position, business_name FROM users WHERE id = :id LIMIT 1');
$meStmt->execute([':id' => (int) $user['id']]);
$me = $meStmt->fetch() ?: ['full_name' => '', 'position' => '', 'business_name' => ''];

// Step 15: with OTP on, a one-time code replaces the password.
$useOtp    = otp_required('sign');
$otpNotice = '';
$otpError  = '';

$typedName    = '';
$typedPos     = (string) ($me['position'] ?? '');
$typedBusiness = (string) ($me['business_name'] ?? '');
$formError    = '';
$formList     = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    if ($useOtp && (string) ($_POST['otp_action'] ?? '') === 'send') {
        // The "Send me a code" button: it only emails a code. Nothing is signed.
        $sent = otp_issue($user, 'sign');
        if ($sent['ok']) {
            $otpNotice = $sent['message'];
        } else {
            $otpError = $sent['message'];
        }
    } else {
        $typedName     = (string) ($_POST['typed_name'] ?? '');
        $typedPos      = (string) ($_POST['position'] ?? '');
        $typedBusiness = (string) ($_POST['business_name'] ?? '');

        try {
            conforme_sign($user, $id, $_POST);
            flash('success', 'Your Conforme was signed. The evaluation form is now complete.');
            redirect('supplier/form-view.php?id=' . $id);
        } catch (SigningRefused $ex) {
            $formError = $ex->getMessage();
            $formList  = $ex->problems;
        } catch (WorkflowException $ex) {
            $formError = $ex->getMessage();
        }
    }
}

$fmtDate = static function ($date): string {
    $t = $date ? strtotime((string) $date) : false;
    return $t ? date('F j, Y', $t) : '';
};

$statement = 'I, the authorized representative of the bidder named in this form, have read this Evaluation Form and I conform to it.';
$ackText   = 'I confirm that this is my signature, that I am the authorized representative of the business named below, and that I am signing voluntarily.';
$inputClass = 'mt-1 block min-h-11 w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 focus:border-brand-600 focus:outline-none focus:ring-4 focus:ring-brand-200';

$pageTitle = 'Sign the Conforme';
require __DIR__ . '/../app/views/layout/header.php';

page_header($pageTitle, conforme_form_title($form));
?>

<p class="mb-4 text-sm text-slate-600">
    Read your form<?= $showResult ? ' and the result' : '' ?> first. The signing box is at the bottom.
</p>

<!-- The supplier's own sections -->
<section class="card mb-4">
    <h2 class="text-base font-semibold text-slate-900">Your form</h2>

    <dl class="mt-3 grid gap-3 text-sm sm:grid-cols-2">
        <div><dt class="text-slate-500">Bid/Reference No.</dt><dd class="font-medium text-slate-900"><?= e((string) ($header['bid_ref_no'] ?? '')) ?></dd></div>
        <div><dt class="text-slate-500">Mode of Procurement</dt><dd class="font-medium text-slate-900"><?= e($modeName) ?></dd></div>
        <div><dt class="text-slate-500">Date of Opening</dt><dd class="font-medium text-slate-900"><?= e($fmtDate($header['opening_date'] ?? null)) ?></dd></div>
        <div><dt class="text-slate-500">Bidder</dt><dd class="font-medium text-slate-900"><?= e((string) ($header['bidder'] ?? '')) ?></dd></div>
        <div><dt class="text-slate-500">Date of Evaluation</dt><dd class="font-medium text-slate-900"><?= e($fmtDate($header['eval_date'] ?? null)) ?></dd></div>
        <div><dt class="text-slate-500">Item No.</dt><dd class="font-medium text-slate-900"><?= e((string) ($header['header_item_no'] ?? '')) ?></dd></div>
    </dl>

    <div class="mt-4 overflow-x-auto">
        <table class="min-w-full text-left text-sm">
            <thead>
                <tr class="border-b border-slate-300 text-slate-600">
                    <th class="py-2 pr-3 font-semibold">Item No.</th>
                    <th class="py-2 pr-3 font-semibold">Technical Specifications</th>
                    <th class="py-2 font-semibold">Bidder&rsquo;s Specification/Brand</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $it): ?>
                    <tr class="border-b border-slate-200 align-top">
                        <td class="py-2 pr-3"><?= e((string) $it['item_no']) ?></td>
                        <td class="whitespace-pre-line py-2 pr-3"><?= e((string) ($it['specifications'] ?? '')) ?></td>
                        <td class="whitespace-pre-line py-2"><?= e((string) ($it['bidder_spec'] ?? '')) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<?php if ($showResult): ?>
    <!-- Result summary: findings and grounds only -->
    <section class="card mb-4">
        <h2 class="text-base font-semibold text-slate-900">Evaluation result</h2>
        <p class="mt-1 text-sm text-slate-600">The finding for each of your items. This is read only.</p>

        <div class="mt-3 overflow-x-auto">
            <table class="min-w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-slate-300 text-slate-600">
                        <th class="py-2 pr-3 font-semibold">Item No.</th>
                        <th class="py-2 pr-3 font-semibold">Finding</th>
                        <th class="py-2 font-semibold">Ground/s, if Non-responsive</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($results as $r): ?>
                        <tr class="border-b border-slate-200 align-top">
                            <td class="py-2 pr-3"><?= e($r['item_no']) ?></td>
                            <td class="py-2 pr-3 font-medium"><?= e($r['finding']) ?></td>
                            <td class="whitespace-pre-line py-2"><?= e($r['grounds']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endif; ?>

<!-- Signing box -->
<section class="card mb-4" id="signing-box">
    <h2 class="text-base font-semibold text-slate-900">Conforme</h2>
    <p class="mt-2 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-800"><?= e($statement) ?></p>

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

    <noscript>
        <p class="mt-3 rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900">Signing needs JavaScript to draw the signature. Please turn it on.</p>
    </noscript>

    <?php if ($useOtp): ?>
        <!-- Step 1 of 2: ask for the one-time code (a separate small form) -->
        <div class="mt-4 rounded-xl border border-slate-200 bg-slate-50 p-4">
            <h3 class="text-sm font-semibold text-slate-900">Step 1: Get a one-time code</h3>
            <p class="mt-1 text-sm text-slate-600">
                We email a 6-digit code to <strong><?= e(otp_mask_email((string) $user['email'])) ?></strong>.
                It replaces your password, works once, and expires in <?= (int) OTP_EXPIRY_MINUTES ?> minutes.
                Ask for the code first, then fill in the form below.
            </p>

            <?php if ($otpNotice !== ''): ?>
                <p role="status" class="mt-3 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800"><?= e($otpNotice) ?></p>
            <?php endif; ?>
            <?php if ($otpError !== ''): ?>
                <p role="alert" class="mt-3 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-800"><?= e($otpError) ?></p>
            <?php endif; ?>

            <form method="post" action="<?= e(url('supplier/conforme.php?id=' . $id)) ?>" class="mt-3">
                <?= csrf_field() ?>
                <input type="hidden" name="otp_action" value="send">
                <button type="submit" class="btn btn-secondary w-full sm:w-auto">Send me a code</button>
            </form>
        </div>
    <?php endif; ?>

    <form method="post" action="<?= e(url('supplier/conforme.php?id=' . $id)) ?>" class="mt-4 space-y-5" novalidate autocomplete="off">
        <?= csrf_field() ?>

        <!-- Signature -->
        <fieldset>
            <legend class="text-sm font-medium text-slate-700">Signature</legend>
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
            </div>
        </fieldset>

        <!-- Typed name -->
        <div>
            <label for="typed_name" class="block text-sm font-medium text-slate-700">Type your complete name</label>
            <p class="mt-1 text-xs text-slate-500">It must match the full name in your profile: <strong><?= e($me['full_name']) ?></strong></p>
            <input type="text" id="typed_name" name="typed_name" value="<?= e($typedName) ?>" autocomplete="off" class="<?= $inputClass ?>">
        </div>

        <!-- Position -->
        <div>
            <label for="position" class="block text-sm font-medium text-slate-700">Your position</label>
            <input type="text" id="position" name="position" value="<?= e($typedPos) ?>" maxlength="100" autocomplete="off" class="<?= $inputClass ?>">
        </div>

        <!-- Business name -->
        <div>
            <label for="business_name" class="block text-sm font-medium text-slate-700">Business name</label>
            <input type="text" id="business_name" name="business_name" value="<?= e($typedBusiness) ?>" maxlength="190" autocomplete="off" class="<?= $inputClass ?>">
        </div>

        <!-- Acknowledgment -->
        <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-slate-300 bg-white px-3 py-3 text-sm text-slate-900 has-[:checked]:border-brand-600 has-[:checked]:bg-brand-50">
            <input type="checkbox" name="acknowledge" value="1" class="mt-0.5 h-5 w-5 shrink-0 accent-brand-600">
            <span><?= e($ackText) ?></span>
        </label>

        <?php if ($useOtp): ?>
            <!-- Step 2 of 2: the one-time code replaces the password -->
            <div>
                <label for="otp_code" class="block text-sm font-medium text-slate-700">Step 2: Enter the 6-digit code from your email</label>
                <input type="text" id="otp_code" name="otp_code" maxlength="6" inputmode="numeric" pattern="[0-9]*"
                       autocomplete="one-time-code" class="<?= $inputClass ?>">
            </div>
        <?php else: ?>
            <!-- Password -->
            <div>
                <label for="password" class="block text-sm font-medium text-slate-700">Enter your password to sign</label>
                <input type="password" id="password" name="password" autocomplete="current-password" class="<?= $inputClass ?>">
            </div>
        <?php endif; ?>

        <div class="flex flex-col gap-3 sm:flex-row">
            <button type="submit" class="btn btn-primary w-full sm:w-auto">Sign the Conforme</button>
            <a href="<?= e(url('supplier/form-view.php?id=' . $id)) ?>" class="btn btn-secondary w-full sm:w-auto">Cancel</a>
        </div>
    </form>

    <script src="<?= e(url('assets/js/signature-pad.js')) ?>"></script>
</section>

<?php require __DIR__ . '/../app/views/layout/footer.php'; ?>