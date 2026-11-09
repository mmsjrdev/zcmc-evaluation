<?php
/**
 * common/print.php?id=N
 *
 * Print view of the Evaluation Form ZCMC-F-PROC-29 (Rev. 0, 8.5 x 13 in).
 * Layout follows Evaluation_Form.docx. Uses its own print stylesheet
 * (assets/css/print.css), not Tailwind, so the paper layout is exact.
 *
 * WHO MAY OPEN IT: anybody who passes can_view_evaluation().
 *
 * SUPPLIER RULE (same filter rule as Step 4): a supplier never receives
 * evaluator data. For a supplier this page
 *   - loads the form with supplier_load_form() / supplier_load_items()
 *     (these queries never read evaluator columns),
 *   - prints Section A without the Findings and Ground/s columns,
 *   - leaves out Sections B and C and the "Evaluated by" / "Verified by" blocks,
 *   - loads only the Conforme signature (the evaluator's and the head's
 *     signature rows are not even read).
 * The evaluator data is loaded in the other branch only, so it cannot leak by mistake.
 *
 * Step 10: the footer also shows the verify address
 * (BASE_URL + /common/verify.php?code=REF-NO). It holds no form content.
 * The stylesheet link carries the file's change time, so the browser never
 * shows an old print.css after you edit it.
 */

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/domain/mms-forms.php';
require_once __DIR__ . '/../app/domain/supplier-forms.php';
require_once __DIR__ . '/../app/domain/evaluator-forms.php';
require_once __DIR__ . '/../app/domain/print-form.php';

$user = require_role(all_roles());

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    forbidden();
}

$isSupplier = $user['role'] === 'supplier';

// ---------------------------------------------------------------------------
// Load the data. Supplier branch and everybody-else branch are kept apart.
// ---------------------------------------------------------------------------
if ($isSupplier) {
    $eval = supplier_load_form($id);                  // supplier columns only
    if (!$eval || !can_view_evaluation($user, $eval)) {
        forbidden();
    }
    $items         = supplier_load_items($id);        // item_no, specifications, bidder_spec
    $showEvaluator = false;
    $values        = null;                            // no evaluator values exist on this path
    $sigRoles      = ['conforme'];
    $backUrl       = 'supplier/form-view.php?id=' . $id;
} else {
    $eval = ev_load_full_form($id);
    if (!$eval || !can_view_evaluation($user, $eval)) {
        forbidden();
    }
    $items         = ev_load_items_full($id);         // includes finding and grounds
    $showEvaluator = true;
    $values        = ev_values_from_db($eval, $items);
    $sigRoles      = ['evaluator', 'head', 'conforme'];
    $backUrl       = 'common/evaluation-view.php?id=' . $id;
}

$meta = print_form_meta($id);
if (!$meta) {
    forbidden();
}
$signatures = print_load_signatures($id, (int) $eval['version'], $sigRoles);

// ---------------------------------------------------------------------------
// Small values used by the page
// ---------------------------------------------------------------------------
$status    = (string) $eval['status'];
$refNo     = trim((string) ($meta['ref_no'] ?? ''));
$formNo    = (string) $meta['form_no'];
$revNo     = (string) $meta['revision_no'];
$effective = print_effectivity_date();

// The address printed in the footer so anybody can check the form (Step 10).
$verifyUrl = $refNo !== ''
    ? rtrim(BASE_URL, '/') . '/common/verify.php?code=' . rawurlencode($refNo)
    : '';

// The print stylesheet with its change time, so a new print.css always loads.
$cssVersion = (int) @filemtime(__DIR__ . '/../assets/css/print.css');

// Watermark: DRAFT until the form is completed (CANCELLED for a cancelled form).
$watermark = '';
if ($status === 'cancelled') {
    $watermark = 'CANCELLED';
} elseif (!in_array($status, ['completed', 'received'], true)) {
    $watermark = 'DRAFT';
}

// The letterhead image. If the file is missing the page prints a text header instead.
$letterheadFile = __DIR__ . '/../assets/img/zcmc-letterhead.png';
$hasLetterhead  = is_file($letterheadFile);

// Section A shows at least 4 rows, like the blank paper form.
$minRows  = 4;
$padRows  = max(0, $minRows - count($items));
$colCount = $showEvaluator ? 5 : 3;

$qualityParams = quality_parameters();
$othersParams  = others_parameters();

/** A printed check box: [X] when ticked, [ ] when not. */
$box = static function (bool $on): string {
    return '<span class="box">' . ($on ? 'X' : '&nbsp;') . '</span>';
};

/**
 * The "details" lines: every line of text sits on a ruled line, and empty
 * ruled lines are added until there are at least $min of them (like the paper form).
 */
$ruled = static function (string $text, int $min): string {
    $lines = $text === '' ? [] : (preg_split('/\R/', $text) ?: []);
    $html  = '';
    foreach ($lines as $line) {
        $html .= '<div class="rule">' . e($line) . '</div>';
    }
    for ($i = count($lines); $i < $min; $i++) {
        $html .= '<div class="rule">&nbsp;</div>';
    }
    return '<div class="details">' . $html . '</div>';
};

/**
 * One signature: the drawn image sits on the line, the typed name is printed
 * on the line, and (when asked) the date and time of signing appears below.
 * With no signature it prints an empty line.
 */
$signature = static function (?array $sig): string {
    $src  = $sig ? print_signature_src($sig['signature_png'] ?? '') : '';
    $name = $sig ? (string) $sig['typed_name'] : '';
    $html = '<div class="sig-image">';
    if ($src !== '') {
        $html .= '<img src="' . e($src) . '" alt="Signature of ' . e($name) . '">';
    }
    $html .= '</div><div class="sig-line">' . e($name) . '</div>';
    return $html;
};

$pageTitle = 'Print - Evaluation form';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($refNo !== '' ? $refNo : 'Evaluation form') ?> - Evaluation Form</title>
    <link rel="stylesheet" href="<?= e(url('assets/css/print.css')) ?>?v=<?= $cssVersion ?>">
</head>
<body>

<!-- Top bar: only on screen, never printed -->
<div class="topbar no-print">
    <a href="<?= e(url($backUrl)) ?>" class="topbar-btn">Back</a>
    <button type="button" class="topbar-btn topbar-primary" id="print-button">Print / Save as PDF</button>
    <span class="topbar-hint">Paper size is 8.5 x 13 in. Page breaks and page numbers show in the print preview. In the print dialog turn on "Background graphics" if the yellow header does not show.</span>
</div>

<?php if ($watermark !== ''): ?>
    <div class="watermark" aria-hidden="true"><?= e($watermark) ?></div>
<?php endif; ?>

<!-- Header: repeats on every printed page -->
<header class="page-header">
    <div class="letterhead">
        <?php if ($hasLetterhead): ?>
            <img src="<?= e(url('assets/img/zcmc-letterhead.png')) ?>" alt="Zamboanga City Medical Center letterhead">
        <?php else: ?>
            <div class="letterhead-text">
                <p>Republic of the Philippines</p>
                <p>Department of Health</p>
                <p class="letterhead-name">ZAMBOANGA CITY MEDICAL CENTER</p>
                <p>Dr. D. Evangelista St., Sta. Catalina, Zamboanga City 7000</p>
                <p>Tel. No(s): (062) 991-0573, 992-0160</p>
            </div>
        <?php endif; ?>
    </div>
    <table class="form-box">
        <tr><th>Form No:</th><td><?= e($formNo) ?></td></tr>
        <tr><th>Revision No:</th><td><?= e($revNo) ?></td></tr>
        <tr><th>Effectivity Date:</th><td><?= e($effective) ?></td></tr>
    </table>
</header>

<!-- Footer: repeats on every printed page. "Page X of Y" is added by print.css -->
<footer class="page-footer">
    <div class="footer-code"><?= e($refNo !== '' ? $refNo : 'EF-YYMM-(Series No.)') ?></div>
    <div class="footer-title">
        <strong>EVALUATION FORM</strong> |
        <?= e((string) ($eval['bidder'] ?? '') !== '' ? $eval['bidder'] : 'Bidder’s Name') ?>
        <br>
        <?= e((string) ($eval['header_item_no'] ?? '') !== '' ? 'Item No. ' . $eval['header_item_no'] : 'Technical Specifications/Item Description') ?>
        <?php if ($verifyUrl !== ''): ?>
            <br>
            <span class="footer-verify">Verify: <?= e($verifyUrl) ?></span>
        <?php endif; ?>
    </div>
</footer>

<!-- The spacer rows repeat on every printed page, so the content never runs under the fixed header and footer. -->
<table class="paper">
<thead><tr><td><div class="head-space"></div></td></tr></thead>
<tfoot><tr><td><div class="foot-space"></div></td></tr></tfoot>
<tbody><tr><td>

<main class="sheet">

    <h1 class="form-title">EVALUATION FORM</h1>
    <p class="ref-line">
        Reference No.: <strong><?= e($refNo !== '' ? $refNo : 'Not yet assigned') ?></strong>
        &middot; Version <?= (int) $eval['version'] ?>
    </p>

    <!-- Header fields -->
    <table class="head-fields">
        <tr>
            <th>Bid/Reference No:</th>
            <td class="line"><?= e((string) ($eval['bid_ref_no'] ?? '')) ?></td>
            <td class="gap"></td>
            <th class="mode-label">Mode of Procurement:</th>
        </tr>
        <tr>
            <th>Date of Opening:</th>
            <td class="line"><?= e(print_date($eval['opening_date'] ?? null)) ?></td>
            <td class="gap"></td>
            <td class="mode-value" rowspan="4"><?= e((string) ($eval['mode_name'] ?? '')) ?></td>
        </tr>
        <tr>
            <th>Bidder:</th>
            <td class="line"><?= e((string) ($eval['bidder'] ?? '')) ?></td>
            <td class="gap"></td>
        </tr>
        <tr>
            <th>Date of Evaluation:</th>
            <td class="line"><?= e(print_date($eval['eval_date'] ?? null)) ?></td>
            <td class="gap"></td>
        </tr>
        <tr>
            <th>Item No.</th>
            <td class="line"><?= e((string) ($eval['header_item_no'] ?? '')) ?></td>
            <td class="gap"></td>
        </tr>
    </table>

    <!-- Section A -->
    <p class="section-title"><span class="section-letter">A.</span> Compliance to Technical Specifications:</p>

    <table class="items<?= $showEvaluator ? '' : ' items-supplier' ?>">
        <thead>
            <tr>
                <th class="col-no">Item No.</th>
                <th class="col-spec">Technical Specifications</th>
                <th class="col-bidder">Bidder’s Specification/ Brand</th>
                <?php if ($showEvaluator): ?>
                    <th class="col-finding">Findings<br>(Responsive/ Non-Responsive)</th>
                    <th class="col-grounds">Ground/s, if Non-responsive</th>
                <?php endif; ?>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($items as $it): ?>
                <tr>
                    <td class="center"><?= e((string) $it['item_no']) ?></td>
                    <td class="text"><?= e((string) ($it['specifications'] ?? '')) ?></td>
                    <td class="text"><?= e((string) ($it['bidder_spec'] ?? '')) ?></td>
                    <?php if ($showEvaluator): ?>
                        <?php $f = (string) ($it['finding'] ?? ''); ?>
                        <td class="center"><?= e($f === '' ? '' : ev_finding_label($f)) ?></td>
                        <td class="text"><?= e((string) ($it['grounds'] ?? '')) ?></td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            <?php for ($i = 0; $i < $padRows; $i++): ?>
                <tr class="blank-row">
                    <?php for ($c = 0; $c < $colCount; $c++): ?><td>&nbsp;</td><?php endfor; ?>
                </tr>
            <?php endfor; ?>
        </tbody>
    </table>

    <?php if ($showEvaluator): ?>
        <?php
        $failed  = (int) $values['failed_quality'] === 1;
        $others  = (int) $values['others_checked'] === 1;
        $bTicked = $failed ? $values['b_params'] : [];
        $cTicked = $others ? $values['c_params'] : [];
        ?>

        <!-- Section B -->
        <section class="block">
            <p class="section-title"><span class="section-letter">B.</span> Quality Compliance:</p>
            <p class="section-sub"><span class="section-letter">B.1</span> [<?= $failed ? 'X' : '&nbsp;&nbsp;' ?>] <strong>Failed Quality Testing:</strong></p>
            <div class="params params-4">
                <?php foreach ($qualityParams as $code => $label): ?>
                    <span class="param"><?= $box(in_array($code, $bTicked, true)) ?> <?= e($label) ?></span>
                <?php endforeach; ?>
            </div>
            <p class="details-label">Please provide the details (use a separate sheet when necessary):</p>
            <?= $ruled($failed ? (string) $values['b_details'] : '', 5) ?>
        </section>

        <!-- Section C -->
        <section class="block">
            <p class="section-sub"><span class="section-letter">C.</span> [<?= $others ? 'X' : '&nbsp;&nbsp;' ?>] <strong>Others:</strong></p>
            <div class="params params-3">
                <?php foreach ($othersParams as $code => $label): ?>
                    <span class="param"><?= $box(in_array($code, $cTicked, true)) ?> <?= e($label) ?></span>
                <?php endforeach; ?>
            </div>
            <p class="details-label">Please provide the details (use a separate sheet when necessary):</p>
            <?= $ruled($others ? (string) $values['c_details'] : '', 3) ?>
        </section>
    <?php endif; ?>

    <!-- Signatures: kept together on one page -->
    <section class="block signatures">
        <?php if ($showEvaluator): ?>
            <div class="sig-row">
                <div class="sig-box">
                    <p class="sig-heading">Evaluated by:</p>
                    <?= $signature($signatures['evaluator'] ?? null) ?>
                    <p class="sig-caption">Signature over printed name</p>
                    <p class="sig-caption">End-user</p>
                    <?php if (!empty($signatures['evaluator'])): ?>
                        <p class="sig-date">Signed: <?= e(print_date_time($signatures['evaluator']['signed_at'])) ?></p>
                    <?php endif; ?>
                </div>
                <div class="sig-box">
                    <p class="sig-heading">Verified by:</p>
                    <?= $signature($signatures['head'] ?? null) ?>
                    <p class="sig-caption">Signature over printed name</p>
                    <p class="sig-caption">Department/Unit/Section Head or Supervisor</p>
                    <?php if (!empty($signatures['head'])): ?>
                        <p class="sig-date">Signed: <?= e(print_date_time($signatures['head']['signed_at'])) ?></p>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <div class="conforme">
            <p class="sig-heading">Conforme:</p>
            <?= $signature($signatures['conforme'] ?? null) ?>
            <p class="sig-caption"><em>(Signature of Printed Name of the Bidder’s Authorized Representative)</em></p>
            <p class="conforme-date">Date and Time:
                <span class="conforme-date-line"><?= e(!empty($signatures['conforme']) ? print_date_time($signatures['conforme']['signed_at']) : '') ?></span>
            </p>
        </div>
    </section>

    <!-- Important Notes -->
    <section class="block notes">
        <p><em>Important Notes:</em></p>
        <ol>
            <?php foreach (print_important_notes() as $note): ?>
                <li><em><?= e($note) ?></em></li>
            <?php endforeach; ?>
        </ol>
    </section>

    <!-- Definition of Terms: always starts a new page, so it is the last page -->
    <section class="terms">
        <h2 class="terms-title">DEFINITION OF TERMS</h2>
        <?php foreach (definition_of_terms() as $t): ?>
            <p class="term"><strong><?= e($t['term']) ?></strong> - <?= e($t['meaning']) ?></p>
        <?php endforeach; ?>
    </section>

</main>

</td></tr></tbody>
</table>

<script>
    // The only script on this page: the Print button.
    document.getElementById('print-button').addEventListener('click', function () {
        window.print();
    });
</script>
</body>
</html>