<?php
/**
 * app/domain/signing.php
 *
 * Step 9: signing (evaluator and department head), returning a form for
 * correction, and cancelling. Every rule runs HERE, inside ONE database
 * transaction per action. The pages only collect input and show messages.
 *
 *   signing_sign($user, $id, $_POST)         add the evaluator's or the head's signature
 *   signing_return_form($user, $id, $text)   head (pending_head) or MMS (completed) returns it
 *   signing_cancel_form($user, $id, $text)   MMS (before completed) or the supplier (draft / returned)
 *   signing_pending_for_head($user)          list for head/for-signature.php
 *
 * Rules that live elsewhere (not repeated here):
 *   - which status may move to which: app/domain/workflow.php (transition())
 *   - who may do what: app/core/permissions.php
 *   - the content hash: app/domain/content-hash.php
 *   - one-time codes: app/services/otp.php (Step 15)
 *
 * The `signatures` table is INSERT-ONLY: this file never runs UPDATE or DELETE on it.
 */

require_once __DIR__ . '/evaluator-forms.php';   // ev_load_full_form(), ev_load_items_full(), ev_values_from_db()
require_once __DIR__ . '/supplier-forms.php';    // supplier_load_form(), supplier_load_items(), supplier_header_values()
require_once __DIR__ . '/content-hash.php';      // form_content_hash()

/**
 * A refused signing. $problems is a list of plain sentences for the page.
 * When $auditAction is set, signing_sign() writes it to the audit log AFTER the
 * transaction is rolled back (an audit row written inside the transaction
 * would be rolled back too).
 * $afterRollback (Step 15) is a small function that also runs AFTER the rollback.
 * It is used to count a wrong one-time code, which must not be undone.
 */
class SigningRefused extends WorkflowException
{
    public array $problems = [];
    public string $auditAction = '';
    public string $auditDetails = '';
    public ?Closure $afterRollback = null;

    public function __construct(string $message, array $problems = [], string $auditAction = '', string $auditDetails = '')
    {
        parent::__construct($message);
        $this->problems     = $problems;
        $this->auditAction  = $auditAction;
        $this->auditDetails = $auditDetails;
    }
}

// ===========================================================================
// Small helpers
// ===========================================================================

/** "  Juan   DELA cruz " -> "juan dela cruz" (for comparing names). */
function signing_normalize_name(string $name): string
{
    return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $name)));
}

/** "  Juan   Dela Cruz " -> "Juan Dela Cruz" (what is stored). */
function signing_tidy_name(string $name): string
{
    return trim((string) preg_replace('/\s+/u', ' ', $name));
}

/**
 * Checks a signature image sent from the browser (or kept as "saved signature").
 * Returns the PNG data URL when it is a real, non-empty PNG of a sensible size,
 * otherwise ''. The server never trusts the JavaScript check alone.
 */
function signing_clean_png(string $dataUrl): string
{
    $prefix = 'data:image/png;base64,';
    if (strncmp($dataUrl, $prefix, strlen($prefix)) !== 0 || strlen($dataUrl) > 600000) {
        return '';
    }
    $binary = base64_decode(substr($dataUrl, strlen($prefix)), true);
    if ($binary === false || strlen($binary) < 100 || strlen($binary) > 400000) {
        return '';
    }
    $info = @getimagesizefromstring($binary);
    if (!$info || $info[2] !== IMAGETYPE_PNG || $info[0] < 100 || $info[0] > 1200 || $info[1] < 30 || $info[1] > 600) {
        return '';
    }

    // Is anything drawn? (Needs the GD extension, which XAMPP and InfinityFree have.)
    if (function_exists('imagecreatefromstring')) {
        $img = @imagecreatefromstring($binary);
        if ($img === false) {
            return '';
        }
        $ink = 0;
        $w = imagesx($img);
        $h = imagesy($img);
        for ($y = 0; $y < $h && $ink < 30; $y += 2) {
            for ($x = 0; $x < $w && $ink < 30; $x += 2) {
                $alpha = (imagecolorat($img, $x, $y) >> 24) & 0x7F;   // 0 = solid, 127 = see-through
                if ($alpha < 100) {
                    $ink++;
                }
            }
        }
        imagedestroy($img);
        if ($ink < 30) {
            return '';
        }
    }
    return $dataUrl;
}

/**
 * Takes the next reference number of this month: EF-YYMM-0001, 0002, ...
 * Runs inside the caller's transaction. LAST_INSERT_ID(expr) hands the new
 * number back safely even when two people sign at the same moment.
 */
function signing_next_ref_no(): string
{
    $ym  = date('ym');
    $pdo = db();
    $pdo->prepare(
        'INSERT INTO series_counters (ym, last_no) VALUES (:ym, LAST_INSERT_ID(1))
         ON DUPLICATE KEY UPDATE last_no = LAST_INSERT_ID(last_no + 1)'
    )->execute([':ym' => $ym]);
    $number = (int) $pdo->query('SELECT LAST_INSERT_ID()')->fetchColumn();

    return 'EF-' . $ym . '-' . str_pad((string) $number, 4, '0', STR_PAD_LEFT);
}

/** Active heads who may verify a department (own department or alternate), without $excludeId. */
function signing_verifier_ids(int $departmentId, int $excludeId): array
{
    $stmt = db()->prepare(
        "SELECT u.id FROM users u
          WHERE u.role = 'head' AND u.status = 'active' AND u.id <> :ex
            AND (u.department_id = :d1
                 OR u.id IN (SELECT a.head_user_id FROM alternate_verifiers a WHERE a.department_id = :d2))"
    );
    $stmt->execute([':ex' => $excludeId, ':d1' => $departmentId, ':d2' => $departmentId]);
    return array_map('intval', array_column($stmt->fetchAll(), 'id'));
}

/** Locks one form row for the rest of the transaction. */
function signing_lock(int $id): void
{
    $lock = db()->prepare('SELECT id FROM evaluations WHERE id = :id FOR UPDATE');
    $lock->execute([':id' => $id]);
}

/**
 * Rolls back, then (in this order) counts a wrong one-time code, writes the
 * audit row of a refusal (if it has one), and re-throws.
 */
function signing_abort(Throwable $ex): void
{
    $pdo = db();
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ($ex instanceof SigningRefused) {
        if ($ex->afterRollback !== null) {
            ($ex->afterRollback)();
        }
        if ($ex->auditAction !== '') {
            audit($ex->auditAction, $ex->auditDetails);
        }
    }
    throw $ex;
}

/**
 * Step 15: checks the one-time code typed for signing and uses it up.
 * Call it inside the signing transaction, at the place where the password
 * check used to be. Throws SigningRefused when the code is missing, wrong,
 * expired or already used. A WRONG code is counted (5 tries per code) even
 * though the transaction rolls back, through $afterRollback.
 */
function signing_require_otp(int $userId, string $code, string $auditDetails): void
{
    $check = otp_check(['id' => $userId], 'sign', $code);

    if (!$check['ok']) {
        $refused = new SigningRefused(
            $check['message'],
            [],
            $check['count_failure'] ? 'signing_otp_failed' : '',
            $auditDetails
        );
        if ($check['count_failure']) {
            $codeId = (int) $check['code_id'];
            $refused->afterRollback = static function () use ($codeId): void {
                otp_record_failure($codeId);
            };
        }
        throw $refused;
    }

    // Single use. If the form check fails later, the rollback gives the code back.
    if (!otp_consume((int) $check['code_id'])) {
        throw new SigningRefused('This code was already used or has expired. Ask for a new code.');
    }
}

// ===========================================================================
// Signing
// ===========================================================================

/**
 * Adds the signature of $user (the logged-in person) to form $id.
 *
 * $input is $_POST. Keys used:
 *   signature_source  'draw' (default) or 'saved'
 *   signature_png     PNG data URL from the signature pad
 *   typed_name        the full name typed by the signer
 *   acknowledge       '1' when the box is ticked
 *   password          the signer's password (used while OTP is off)
 *   otp_code          the 6-digit emailed code (used while OTP_ENABLED is true)
 *   save_signature    '1' = keep this drawing as the saved signature
 *
 * Throws SigningRefused (a WorkflowException) with a plain message when
 * anything is wrong. Nothing is saved in that case.
 */
function signing_sign(array $user, int $id, array $input): void
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        signing_lock($id);

        $eval = ev_load_full_form($id);
        if (!$eval) {
            throw new SigningRefused('This form no longer exists.');
        }

        // (a) (b) (c) (d): who may sign this form now. The signer is ALWAYS the
        // logged-in user; no user id is read from the input.
        $role = signing_role_for($user, $eval);
        if ($role === null) {
            throw new SigningRefused(
                'You cannot sign this form right now. It may already be signed, be in another step, or belong to someone else.',
                [],
                'signing_refused',
                'Evaluation #' . $id . ': user #' . (int) $user['id'] . ' is not allowed to sign (status ' . $eval['status'] . ').'
            );
        }

        $title = mms_form_title($eval);

        // The signer's own row, read fresh from the database.
        $meStmt = $pdo->prepare('SELECT id, full_name, password_hash, position, saved_signature FROM users WHERE id = :id LIMIT 1');
        $meStmt->execute([':id' => (int) $user['id']]);
        $me = $meStmt->fetch();
        if (!$me) {
            throw new SigningRefused('Your account was not found.');
        }

        // Routing: a head's own evaluation must go to ANOTHER verifier.
        if ($role === 'evaluator' && $user['role'] === 'head'
            && !department_has_other_verifier((int) $eval['department_id'], (int) $user['id'])) {
            throw new SigningRefused('No verifier available. Contact the admin.');
        }

        // (g) Evaluator-part rules and the supplier fingerprint, BEFORE anything else.
        $items = ev_load_items_full($id);
        if ($role === 'evaluator') {
            $problems = [];
            foreach (validate_evaluator_part(ev_values_from_db($eval, $items), $items, true) as $key => $message) {
                if (preg_match('/^items\.(\d+)\./', (string) $key, $m)) {
                    $message = 'Item ' . ((int) $m[1] + 1) . ': ' . $message;
                } elseif ($key === 'b_params' || $key === 'b_details') {
                    $message = 'Section B: ' . $message;
                } elseif ($key === 'c_params' || $key === 'c_details') {
                    $message = 'Section C: ' . $message;
                }
                $problems[] = $message;
            }
            if ($problems !== []) {
                throw new SigningRefused('The form is not complete yet. Finish the items below, then sign.', $problems);
            }
        }

        // The supplier's part must still match what the supplier submitted.
        $supplierRow = supplier_load_form($id);
        $storedHash  = (string) ($pdo->query('SELECT supplier_hash FROM evaluations WHERE id = ' . (int) $id)->fetchColumn() ?: '');
        $nowHash     = $supplierRow ? supplier_hash(supplier_header_values($supplierRow), supplier_load_items($id)) : '';
        if ($storedHash === '' || !hash_equals($storedHash, $nowHash)) {
            throw new SigningRefused(
                'The supplier part of this form no longer matches what the supplier submitted. Signing is blocked. Please tell MMS.',
                [],
                'signing_refused_supplier_hash',
                'Evaluation #' . $id . ' (' . $title . '): supplier_hash mismatch while user #' . (int) $user['id'] . ' tried to sign as ' . $role . '.'
            );
        }

        // The acknowledgment box.
        if ((string) ($input['acknowledge'] ?? '') !== '1') {
            throw new SigningRefused('Please tick the box to confirm your signature.');
        }

        // (e) The typed name must match the profile name (case and extra spaces ignored).
        $typed = signing_tidy_name((string) ($input['typed_name'] ?? ''));
        if ($typed === '' || signing_normalize_name($typed) !== signing_normalize_name((string) $me['full_name'])) {
            throw new SigningRefused('The name you typed does not match the full name in your profile.');
        }

        // The signature image: drawn now, or the saved one.
        $source = (string) ($input['signature_source'] ?? 'draw') === 'saved' ? 'saved' : 'draw';
        $png    = signing_clean_png($source === 'saved' ? (string) ($me['saved_signature'] ?? '') : (string) ($input['signature_png'] ?? ''));
        if ($png === '') {
            throw new SigningRefused($source === 'saved'
                ? 'You have no saved signature. Please draw your signature.'
                : 'Please draw your signature in the box.');
        }

        // (f) Proof that the signer is really them: a fresh one-time code when
        // OTP is on (Step 15), otherwise the password.
        if (otp_required('sign')) {
            signing_require_otp(
                (int) $user['id'],
                (string) ($input['otp_code'] ?? ''),
                'Evaluation #' . $id . ': wrong one-time code while signing as ' . $role . '.'
            );
        } else {
            // Too many wrong tries in 10 minutes block signing for a while.
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
                    'Evaluation #' . $id . ': wrong password while signing as ' . $role . '.'
                );
            }
        }

        // First evaluator signature: the reference number is created now. A
        // returned form keeps the one it already has.
        if ($role === 'evaluator' && trim((string) ($eval['ref_no'] ?? '')) === '') {
            $ref = signing_next_ref_no();
            $pdo->prepare("UPDATE evaluations SET ref_no = :r WHERE id = :id AND (ref_no IS NULL OR ref_no = '')")
                ->execute([':r' => $ref, ':id' => $id]);
        }

        // (h) The content hash of the form exactly as it is now (ref_no included).
        $hash = form_content_hash($id);

        // The signature row (insert only). The unique key stops a second one.
        $pdo->prepare(
            'INSERT INTO signatures (evaluation_id, version, role, user_id, typed_name, position, business_name,
                                     signature_png, content_hash, ip_address, user_agent)
             VALUES (:eid, :version, :role, :uid, :name, :position, :business, :png, :hash, :ip, :ua)'
        )->execute([
            ':eid'      => $id,
            ':version'  => (int) $eval['version'],
            ':role'     => $role,
            ':uid'      => (int) $user['id'],
            ':name'     => $typed,
            ':position' => (string) ($me['position'] ?? ''),
            ':business' => '',
            ':png'      => $png,
            ':hash'     => $hash,
            ':ip'       => substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
            ':ua'       => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        ]);

        // The status change goes through the workflow table.
        transition($eval, $role === 'evaluator' ? 'sign_evaluator' : 'sign_head', $user);

        // "Save this signature for next time" (only a freshly drawn one).
        if ($source === 'draw' && (string) ($input['save_signature'] ?? '') === '1') {
            $pdo->prepare('UPDATE users SET saved_signature = :s WHERE id = :id')
                ->execute([':s' => $png, ':id' => (int) $user['id']]);
        }

        // Notifications.
        if ($role === 'evaluator') {
            foreach (signing_verifier_ids((int) $eval['department_id'], (int) $user['id']) as $headId) {
                notify($headId, 'Form ' . $title . ' was signed by the evaluator and is ready for your verification.', 'common/sign.php?id=' . $id);
            }
        } else {
            if (!empty($eval['evaluator_id'])) {
                notify((int) $eval['evaluator_id'], 'Form ' . $title . ' was verified and signed by the Department Head.', 'common/evaluation-view.php?id=' . $id);
            }
            notify_role('mm_staff', 'Form ' . $title . ' was verified by the Department Head. It now waits for the supplier\'s Conforme.', 'common/evaluation-view.php?id=' . $id);
            notify((int) $eval['supplier_id'], 'Your evaluation form ' . $title . ' is ready for your Conforme.', 'supplier/form-view.php?id=' . $id);
        }

        audit(
            $role === 'evaluator' ? 'form_signed_evaluator' : 'form_signed_head',
            'Evaluation #' . $id . ' (' . $title . ') version ' . (int) $eval['version'] . ' signed by user #' . (int) $user['id'] . '. Hash ' . $hash
        );

        $pdo->commit();
    } catch (Throwable $ex) {
        signing_abort($ex);
    }
}

// ===========================================================================
// Return for correction
// ===========================================================================

/**
 * The Department Head (form pending_head) or MMS (form completed) sends the form
 * back to the evaluator. Remarks are required. The version goes up by 1, so the
 * old signatures stay in history but no longer count. The reference number stays.
 */
function signing_return_form(array $user, int $id, string $remarks): void
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        signing_lock($id);

        $eval = ev_load_full_form($id);
        if (!$eval) {
            throw new SigningRefused('This form no longer exists.');
        }
        if (!can_return_evaluation($user, $eval)) {
            throw new SigningRefused('This form cannot be returned right now. It may have changed step.');
        }

        $remarks = trim($remarks);
        if ($remarks === '') {
            throw new SigningRefused('Please write the remarks or reason first.');
        }

        $title  = mms_form_title($eval);
        $from   = (string) $eval['status'];
        $action = $user['role'] === 'mm_staff' ? 'return_completed' : 'return_evaluator';

        $to = transition($eval, $action, $user, $remarks);

        $pdo->prepare(
            'INSERT INTO returns (evaluation_id, version, from_status, to_status, returned_by, remarks)
             VALUES (:id, :version, :from, :to, :by, :remarks)'
        )->execute([
            ':id'      => $id,
            ':version' => (int) $eval['version'],
            ':from'    => $from,
            ':to'      => $to,
            ':by'      => (int) $user['id'],
            ':remarks' => $remarks,
        ]);

        // New version: the signatures of the old version no longer count.
        $pdo->prepare('UPDATE evaluations SET version = version + 1 WHERE id = :id')->execute([':id' => $id]);

        if (!empty($eval['evaluator_id'])) {
            notify((int) $eval['evaluator_id'], 'Form ' . $title . ' was returned to you for correction. Open it to read the remarks.', 'common/evaluation-view.php?id=' . $id);
        }
        if ($user['role'] === 'head') {
            notify_role('mm_staff', 'Form ' . $title . ' was returned to the evaluator by the Department Head.', 'common/evaluation-view.php?id=' . $id);
        }
        audit('form_returned_evaluator', 'Evaluation #' . $id . ' (' . $title . ') version ' . (int) $eval['version'] . ' returned by user #' . (int) $user['id'] . ': ' . $remarks);

        $pdo->commit();
    } catch (Throwable $ex) {
        signing_abort($ex);
    }
}

// ===========================================================================
// Cancel
// ===========================================================================

/**
 * Cancels a form. MMS before it is completed; the supplier only while the form
 * is a draft or returned to them. The reason is required and goes into the
 * status history and the audit log. No signature row is touched.
 */
function signing_cancel_form(array $user, int $id, string $reason): void
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        signing_lock($id);

        $eval = ev_load_full_form($id);
        if (!$eval) {
            throw new SigningRefused('This form no longer exists.');
        }
        if (!can_cancel_evaluation($user, $eval)) {
            throw new SigningRefused('This form cannot be cancelled by you right now.');
        }

        $reason = trim($reason);
        if ($reason === '') {
            throw new SigningRefused('Please write the reason for cancelling.');
        }

        $title  = mms_form_title($eval);
        $action = $user['role'] === 'supplier' ? 'cancel_supplier' : 'cancel';

        transition($eval, $action, $user, $reason);

        if ($user['role'] === 'supplier') {
            notify_role('mm_staff', 'The supplier cancelled form ' . $title . '.', 'common/evaluation-view.php?id=' . $id);
        } else {
            notify((int) $eval['supplier_id'], 'Your form ' . $title . ' was cancelled by MMS. Open it to read the reason.', 'supplier/form-view.php?id=' . $id);
            if (!empty($eval['evaluator_id'])) {
                notify((int) $eval['evaluator_id'], 'Form ' . $title . ' was cancelled by MMS.', 'common/evaluation-view.php?id=' . $id);
            }
            if (!empty($eval['distributed_by']) && (int) $eval['distributed_by'] !== (int) $user['id']) {
                notify((int) $eval['distributed_by'], 'Form ' . $title . ' was cancelled by MMS.', 'common/evaluation-view.php?id=' . $id);
            }
        }
        audit('form_cancelled', 'Evaluation #' . $id . ' (' . $title . ') cancelled by user #' . (int) $user['id'] . ': ' . $reason);

        $pdo->commit();
    } catch (Throwable $ex) {
        signing_abort($ex);
    }
}

// ===========================================================================
// List for head/for-signature.php
// ===========================================================================

/**
 * Forms waiting for this Department Head: pending_head, in their own department
 * or one where they are an alternate verifier, never one they evaluated.
 * Oldest first.
 */
function signing_pending_for_head(array $user): array
{
    $ids = verifier_department_ids($user);
    if ($ids === []) {
        return [];
    }

    $marks  = [];
    $params = [':me' => (int) $user['id']];
    foreach (array_values($ids) as $i => $deptId) {
        $marks[]           = ':d' . $i;
        $params[':d' . $i] = (int) $deptId;
    }

    $stmt = db()->prepare(
        "SELECT e.id, e.ref_no, e.bid_ref_no, e.bidder, e.version, e.department_id,
                d.name AS department_name, ev.full_name AS evaluator_name,
                (SELECT MAX(s.signed_at) FROM signatures s
                  WHERE s.evaluation_id = e.id AND s.version = e.version AND s.role = 'evaluator') AS evaluator_signed_at,
                (SELECT COUNT(*) FROM evaluation_items i WHERE i.evaluation_id = e.id) AS item_count
           FROM evaluations e
           LEFT JOIN departments d ON d.id = e.department_id
           LEFT JOIN users ev ON ev.id = e.evaluator_id
          WHERE e.status = 'pending_head'
            AND e.department_id IN (" . implode(', ', $marks) . ")
            AND (e.evaluator_id IS NULL OR e.evaluator_id <> :me)
          ORDER BY evaluator_signed_at ASC, e.id ASC
          LIMIT 200"
    );
    $stmt->execute($params);
    return $stmt->fetchAll();
}