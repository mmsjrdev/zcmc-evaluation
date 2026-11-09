<?php
/**
 * app/domain/verify.php
 *
 * Step 11: the ONE place that checks whether a signed form is still exactly
 * what was signed. common/verify.php and the Signature Validity panel on
 * mms/record.php both call verify_form(), so they always agree.
 *
 *   verify_form($form)   returns plain data (no HTML)
 *
 * $form is a row from conforme_load_form() (it has no evaluator content).
 * form_content_hash() can only be recomputed from the CURRENT stored form, so
 * only signatures of the current version are checked. Older versions get
 * the result 'old' ("Superseded version, not checked").
 */

require_once __DIR__ . '/supplier-conforme.php';   // also loads signing.php, content-hash.php, supplier-forms.php

/** Writes a mismatch to the audit log, but only once per hour for the same finding. */
function verify_audit_once(string $action, string $details): void
{
    $stmt = db()->prepare(
        'SELECT 1 FROM audit_log
          WHERE action = :a AND details = :d AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
          LIMIT 1'
    );
    $stmt->execute([':a' => $action, ':d' => $details]);
    if ($stmt->fetchColumn() === false) {
        audit($action, $details);
    }
}

/**
 * Check every signature of a form.
 *
 * Returns:
 *   version          the form's current version
 *   rows             one entry per signature (newest version first), with the
 *                    signature columns (no image, no hash) plus 'result' =
 *                    match | mismatch | old
 *   any_mismatch     true if a current signature or the supplier part does not match
 *   current_checked  how many current-version signatures were compared
 *   supplier_result  none | match | mismatch   (supplier fields against supplier_hash)
 *
 * $writeAudit = false skips the audit log.
 */
function verify_form(array $form, bool $writeAudit = true): array
{
    $id      = (int) $form['id'];
    $version = (int) $form['version'];
    $title   = conforme_form_title($form);

    // The signature images are not needed here, so they are not read.
    $sigStmt = db()->prepare(
        "SELECT id, version, role, typed_name, position, business_name, signed_at, content_hash
           FROM signatures
          WHERE evaluation_id = :id
          ORDER BY version DESC, FIELD(role, 'evaluator', 'head', 'conforme'), id"
    );
    $sigStmt->execute([':id' => $id]);
    $sigs = $sigStmt->fetchAll();

    // Only compute the content hash when a current-version signature exists.
    $hasCurrent = false;
    foreach ($sigs as $s) {
        if ((int) $s['version'] === $version) {
            $hasCurrent = true;
            break;
        }
    }
    $nowHash = $hasCurrent ? form_content_hash($id) : '';

    $rows           = [];
    $anyMismatch    = false;
    $currentChecked = 0;

    foreach ($sigs as $s) {
        if ((int) $s['version'] !== $version) {
            $result = 'old';
        } elseif (hash_equals((string) $s['content_hash'], $nowHash)) {
            $result = 'match';
            $currentChecked++;
        } else {
            $result = 'mismatch';
            $anyMismatch = true;
            $currentChecked++;
            if ($writeAudit) {
                verify_audit_once(
                    'verify_mismatch',
                    'Evaluation #' . $id . ' (' . $title . ') version ' . $version . ': signature #' . (int) $s['id'] . ' (' . $s['role'] . ') does not match the stored form.'
                );
            }
        }
        unset($s['content_hash']);
        $s['result'] = $result;
        $rows[] = $s;
    }

    // The supplier-owned fields against the hash stored at submit.
    $supplierResult     = 'none';
    $storedSupplierHash = (string) ($form['supplier_hash'] ?? '');
    if ($storedSupplierHash !== '') {
        $supplierRow = supplier_load_form($id);
        $nowSupplier = $supplierRow ? supplier_hash(supplier_header_values($supplierRow), supplier_load_items($id)) : '';
        if ($nowSupplier !== '' && hash_equals($storedSupplierHash, $nowSupplier)) {
            $supplierResult = 'match';
        } else {
            $supplierResult = 'mismatch';
            $anyMismatch    = true;
            if ($writeAudit) {
                verify_audit_once(
                    'verify_mismatch',
                    'Evaluation #' . $id . ' (' . $title . '): the supplier fields do not match the supplier_hash stored at submit.'
                );
            }
        }
    }

    return [
        'version'         => $version,
        'rows'            => $rows,
        'any_mismatch'    => $anyMismatch,
        'current_checked' => $currentChecked,
        'supplier_result' => $supplierResult,
    ];
}