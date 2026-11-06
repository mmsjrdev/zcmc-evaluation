<?php
/**
 * app/domain/print-form.php
 *
 * Small helpers for the PRINT page (common/print.php) - Step 8.
 *
 * What is here:
 *   - print_form_meta()        form number, revision, reference number (no evaluator data)
 *   - print_load_signatures()  the signatures of the CURRENT version of a form
 *   - print_date(), print_date_time()   dates written the way the paper form shows them
 *   - print_signature_src()    makes sure a signature image is a safe PNG data URL
 *   - print_important_notes()  the four "Important Notes" printed on the form
 *   - print_effectivity_date() the effectivity date printed in the header box
 *
 * Nothing here changes the database. The page itself decides which data a
 * viewer may see; these functions only read.
 */

/** The effectivity date printed in the header box (change it here when the form is revised). */
function print_effectivity_date(): string
{
    return 'March 2, 2026';
}

/**
 * The four "Important Notes" at the bottom of page 1 of the official form.
 * The wording is copied from Evaluation_Form.docx, including its spelling
 * ("Department/nit/Section Head" - the docx has "nit", not "Unit").
 */
function print_important_notes(): array
{
    return [
        'Evaluation not verified by Department/nit/Section Head or Supervisor shall be rejected',
        'The Evaluator shall indicate under the parameters of “B. FAILED” the details of evaluation which lead to determination of “Failed”, either Non-compliance to Technical Specifications or Failed Quality Testing.',
        'Evaluation which failed to indicate the details of evaluation shall not be considered for purposes of disqualification.',
        'Under B.1, “Low Quality” shall not be considered as sufficient evaluation. The Evaluator must indicate the performance requirement/parameter for which the product was tested',
    ];
}

/**
 * Form number, revision, reference number and version of one form.
 * These columns are not evaluator data, so every role that may view the form
 * may read them. Returns null when the form does not exist.
 */
function print_form_meta(int $id): ?array
{
    $stmt = db()->prepare(
        'SELECT id, ref_no, form_no, revision_no, version
           FROM evaluations
          WHERE id = :id
          LIMIT 1'
    );
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * The signatures that count for this form: the ones whose version equals the
 * form's CURRENT version. Returns an array keyed by role
 * ('evaluator', 'head', 'conforme'); a role without a signature is missing.
 *
 * $roles limits which roles are even read from the database. For a supplier
 * the page passes ['conforme'], so the evaluator's and the head's signatures
 * are never loaded on that request.
 */
function print_load_signatures(int $evaluationId, int $version, array $roles): array
{
    $allowed = array_values(array_intersect($roles, ['evaluator', 'head', 'conforme']));
    if ($allowed === []) {
        return [];
    }

    // One named placeholder per role (the values come from the fixed list above).
    $marks  = [];
    $params = [':id' => $evaluationId, ':version' => $version];
    foreach ($allowed as $i => $role) {
        $marks[]           = ':r' . $i;
        $params[':r' . $i] = $role;
    }

    $stmt = db()->prepare(
        'SELECT role, typed_name, signature_png, signed_at
           FROM signatures
          WHERE evaluation_id = :id AND version = :version
            AND role IN (' . implode(', ', $marks) . ')'
    );
    $stmt->execute($params);

    $out = [];
    foreach ($stmt->fetchAll() as $row) {
        $out[$row['role']] = $row;
    }
    return $out;
}

/**
 * The signature image as a value that is safe to put in <img src="...">.
 * Only a PNG data URL made of base64 characters is accepted; anything else
 * returns '' (the page then prints a blank line instead of an image).
 */
function print_signature_src(?string $dataUrl): string
{
    $dataUrl = (string) $dataUrl;
    if (preg_match('#^data:image/png;base64,[A-Za-z0-9+/]+={0,2}$#', $dataUrl) === 1) {
        return $dataUrl;
    }
    return '';
}

/** "2026-11-06" -> "November 6, 2026". Empty or bad input gives ''. */
function print_date(?string $value): string
{
    $value = trim((string) $value);
    if ($value === '' || strpos($value, '0000-00-00') === 0) {
        return '';
    }
    $time = strtotime($value);
    return $time === false ? '' : date('F j, Y', $time);
}

/** "2026-11-06 15:10:00" -> "November 6, 2026 3:10 PM". Empty or bad input gives ''. */
function print_date_time(?string $value): string
{
    $value = trim((string) $value);
    if ($value === '' || strpos($value, '0000-00-00') === 0) {
        return '';
    }
    $time = strtotime($value);
    return $time === false ? '' : date('F j, Y g:i A', $time);
}