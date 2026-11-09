<?php
/**
 * app/domain/supplier-conforme.php
 *
 * Step 10: the supplier's Conforme signed on their own device.
 *
 *   conforme_load_form($id)              the form row WITHOUT evaluator content
 *   conforme_result_rows($id)            findings + grounds only (for the supplier)
 *   conforme_integrity_problem(...)      are the earlier signatures and hashes still good?
 *   conforme_sign($user, $id, $_POST)    the supplier signs; form becomes "completed"
 *
 * It reuses the Step 9 helpers (signing_lock, signing_abort, signing_clean_png,
 * signing_tidy_name, signing_normalize_name, SigningRefused). Every check runs
 * inside ONE transaction with a row lock. `signatures` stays insert-only.
 */

require_once __DIR__ . '/signing.php';   // also loads supplier-forms.php, content-hash.php, evaluator-forms.php

/**
 * One form row with only the "who / where / which step" columns and the
 * supplier-owned columns. It never reads Findings, Sections B or C, so it is
 * safe to use on a page a supplier opens.
 */
function conforme_load_form(int $id): ?array
{
    $stmt = db()->prepare(
        'SELECT id, ref_no, supplier_id, bid_ref_no, mode_id, opening_date, header_item_no,
                bidder, eval_date, status, version, supplier_hash, submitted_at,
                department_id, evaluator_id, distributed_by, distributed_at,
                conforme_method, paper_scan_file, form_no, revision_no
           FROM evaluations
          WHERE id = :id
          LIMIT 1'
    );
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** A short name for messages: "EF-2611-0001 (Bidder name)" or "Form #12". */
function conforme_form_title(array $form): string
{
    $ref    = trim((string) ($form['ref_no'] ?? ''));
    $bidder = trim((string) ($form['bidder'] ?? ''));
    $title  = $ref !== '' ? $ref : 'Form #' . (int) $form['id'];
    return $bidder !== '' ? $title . ' (' . $bidder . ')' : $title;
}

function conforme_finding_label(string $finding): string
{
    if ($finding === 'responsive') {
        return 'Responsive';
    }
    if ($finding === 'non_responsive') {
        return 'Non-Responsive';
    }
    return '';
}

/**
 * The read-only result the supplier may see at the Conforme: item number,
 * finding and grounds. NO Section B or C, no remarks, no status history.
 * Call it only when SUPPLIER_SEES_RESULT_AT_CONFORME is true.
 */
function conforme_result_rows(int $id): array
{
    $stmt = db()->prepare(
        'SELECT item_no, finding, grounds
           FROM evaluation_items
          WHERE evaluation_id = :id
          ORDER BY sort_order, id'
    );
    $stmt->execute([':id' => $id]);

    $rows = [];
    foreach ($stmt->fetchAll() as $r) {
        $rows[] = [
            'item_no' => (string) $r['item_no'],
            'finding' => conforme_finding_label((string) ($r['finding'] ?? '')),
            'grounds' => (string) ($r['grounds'] ?? ''),
        ];
    }
    return $rows;
}

/**
 * Before a Conforme (device or paper) counts, the form must still be exactly
 * what was signed. Returns NULL when all is well, otherwise a sentence for
 * the audit log:
 *   1. the evaluator and head signatures of the CURRENT version exist,
 *   2. the supplier fields still match the supplier_hash stored at submit,
 *   3. the whole form still matches the hash stored with those two signatures.
 * Call it inside the caller's transaction.
 */
function conforme_integrity_problem(int $id, int $version): ?string
{
    $stmt = db()->prepare(
        "SELECT role, content_hash FROM signatures
          WHERE evaluation_id = :id AND version = :v AND role IN ('evaluator', 'head')"
    );
    $stmt->execute([':id' => $id, ':v' => $version]);
    $hashes = [];
    foreach ($stmt->fetchAll() as $r) {
        $hashes[(string) $r['role']] = (string) $r['content_hash'];
    }
    foreach (['evaluator', 'head'] as $role) {
        if (empty($hashes[$role])) {
            return 'the ' . $role . ' signature of version ' . $version . ' is missing';
        }
    }

    // The supplier part against the hash stored at submit.
    $hashStmt = db()->prepare('SELECT supplier_hash FROM evaluations WHERE id = :id');
    $hashStmt->execute([':id' => $id]);
    $storedSupplierHash = (string) ($hashStmt->fetchColumn() ?: '');
    $supplierRow        = supplier_load_form($id);
    $nowSupplierHash    = $supplierRow ? supplier_hash(supplier_header_values($supplierRow), supplier_load_items($id)) : '';
    if ($storedSupplierHash === '' || !hash_equals($storedSupplierHash, $nowSupplierHash)) {
        return 'supplier_hash does not match the stored supplier fields';
    }

    // The whole form against the hash saved with the evaluator and head signatures.
    $nowContent = form_content_hash($id);
    foreach (['evaluator', 'head'] as $role) {
        if (!hash_equals($hashes[$role], $nowContent)) {
            return 'the content hash saved with the ' . $role . ' signature does not match the stored form';
        }
    }
    return null;
}

/**
 * The supplier signs the Conforme on their device.
 *
 * $input is $_POST. Keys used: signature_png, typed_name, position,
 * business_name, acknowledge, password.
 *
 * Throws SigningRefused with a plain message when anything is wrong. Nothing
 * is saved in that case.
 */
function conforme_sign(array $user, int $id, array $input): void
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        signing_lock($id);

        $form = conforme_load_form($id);
        if (!$form) {
            throw new SigningRefused('This form no longer exists.');
        }
        $title = conforme_form_title($form);

        // Only the supplier who owns the form, and only at pending_conforme.
        // The workflow table has the last word on the status.
        if (!user_is_usable($user)
            || $user['role'] !== 'supplier'
            || (int) $form['supplier_id'] !== (int) $user['id']
            || (string) $form['status'] !== 'pending_conforme'
            || !can_transition($form, 'conforme', $user)) {
            throw new SigningRefused(
                'You cannot sign the Conforme of this form right now.',
                [],
                'conforme_refused',
                'Evaluation #' . $id . ': user #' . (int) $user['id'] . ' may not sign the Conforme (status ' . $form['status'] . ').'
            );
        }

        // The earlier signatures and the content must still be intact.
        $problem = conforme_integrity_problem($id, (int) $form['version']);
        if ($problem !== null) {
            throw new SigningRefused(
                'This form cannot be completed because its signatures or its content no longer match. Please tell MMS.',
                [],
                'conforme_refused_integrity',
                'Evaluation #' . $id . ' (' . $title . '): ' . $problem . '. User #' . (int) $user['id'] . ' tried to sign the Conforme.'
            );
        }

        // OTP is not built yet (Step 15). If someone switches it on early, refuse safely.
        if (otp_required('sign')) {
            throw new SigningRefused('One-time codes are not available yet. Signing stays closed until Step 15.');
        }

        // The acknowledgment box.
        if ((string) ($input['acknowledge'] ?? '') !== '1') {
            throw new SigningRefused('Please tick the box to confirm your signature.');
        }

        // The signer's own row, read fresh from the database.
        $meStmt = $pdo->prepare('SELECT id, full_name, password_hash FROM users WHERE id = :id LIMIT 1');
        $meStmt->execute([':id' => (int) $user['id']]);
        $me = $meStmt->fetch();
        if (!$me) {
            throw new SigningRefused('Your account was not found.');
        }

        // The typed name must match the profile name (case and extra spaces ignored).
        $typed = signing_tidy_name((string) ($input['typed_name'] ?? ''));
        if ($typed === '' || signing_normalize_name($typed) !== signing_normalize_name((string) $me['full_name'])) {
            throw new SigningRefused('The name you typed does not match the full name in your profile.');
        }

        // Position and business name are typed by the supplier.
        $position = signing_tidy_name((string) ($input['position'] ?? ''));
        if ($position === '' || mb_strlen($position) > 100) {
            throw new SigningRefused('Please type your position (up to 100 characters).');
        }
        $business = signing_tidy_name((string) ($input['business_name'] ?? ''));
        if ($business === '' || mb_strlen($business) > 190) {
            throw new SigningRefused('Please type the business name (up to 190 characters).');
        }

        // The drawn signature.
        $png = signing_clean_png((string) ($input['signature_png'] ?? ''));
        if ($png === '') {
            throw new SigningRefused('Please draw your signature in the box.');
        }

        // The password. Too many wrong tries in 10 minutes block signing for a while.
        $failCount = $pdo->prepare(
            "SELECT COUNT(*) FROM audit_log
              WHERE user_id = :u AND action = 'signing_password_failed'
                AND created_at >= DATE_SUB(NOW(), INTERVAL 10 MINUTE)"
        );
        $failCount->execute([':u' => (int) $user['id']]);
        if ((int) $failCount->fetchColumn() >= 5) {
            throw new SigningRefused('Too many wrong passwords. Wait 10 minutes and try again.');
        }
        if (!password_verify((string) ($input['password'] ?? ''), (string) $me['password_hash'])) {
            throw new SigningRefused(
                'The password is not correct.',
                [],
                'signing_password_failed',
                'Evaluation #' . $id . ': wrong password while signing the Conforme.'
            );
        }

        // The content hash of the form exactly as it is now.
        $hash = form_content_hash($id);

        // The signature row (insert only). The unique key stops a second one.
        $pdo->prepare(
            'INSERT INTO signatures (evaluation_id, version, role, user_id, typed_name, position, business_name,
                                     signature_png, content_hash, ip_address, user_agent)
             VALUES (:eid, :version, :role, :uid, :name, :position, :business, :png, :hash, :ip, :ua)'
        )->execute([
            ':eid'      => $id,
            ':version'  => (int) $form['version'],
            ':role'     => 'conforme',
            ':uid'      => (int) $user['id'],
            ':name'     => $typed,
            ':position' => $position,
            ':business' => $business,
            ':png'      => $png,
            ':hash'     => $hash,
            ':ip'       => substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
            ':ua'       => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        ]);

        // How the Conforme was given, then the status change through the workflow table.
        $pdo->prepare("UPDATE evaluations SET conforme_method = 'device' WHERE id = :id")->execute([':id' => $id]);
        transition($form, 'conforme', $user);

        // Notifications.
        if (!empty($form['evaluator_id'])) {
            notify((int) $form['evaluator_id'], 'The supplier signed the Conforme of form ' . $title . '. The form is complete.', 'common/evaluation-view.php?id=' . $id);
        }
        notify_role('mm_staff', 'The supplier signed the Conforme of form ' . $title . '. The form is complete.', 'common/evaluation-view.php?id=' . $id);

        audit('form_conforme_device', 'Evaluation #' . $id . ' (' . $title . ') version ' . (int) $form['version'] . ' Conforme signed by user #' . (int) $user['id'] . '. Hash ' . $hash);

        $pdo->commit();
    } catch (Throwable $ex) {
        signing_abort($ex);
    }
}