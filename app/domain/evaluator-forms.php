<?php
/**
 * app/domain/evaluator-forms.php
 *
 * Database access and actions for the EVALUATOR assignment pages
 * (evaluator/assignments.php). Also used by Department Heads who evaluate.
 *
 * Only columns that are not evaluator-owned form content are read here.
 * The evaluator form itself (findings, grounds, Sections B and C) is Step 7.
 */

// Small shared helpers: mms_form_title(), mms_date(), mms_date_time(), mms_days_ago().
require_once __DIR__ . '/mms-forms.php';

/** Forms assigned to this person that still need their work, oldest first. */
function ev_assigned_list(int $userId): array
{
    $stmt = db()->prepare(
        "SELECT e.id, e.bid_ref_no, e.bidder, e.status, e.version, e.supplier_id, e.evaluator_id,
                e.department_id, e.distributed_at,
                u.business_name, u.full_name AS contact_name, d.name AS department_name,
                (SELECT COUNT(*) FROM evaluation_items i WHERE i.evaluation_id = e.id) AS item_count
           FROM evaluations e
           JOIN users u ON u.id = e.supplier_id
           LEFT JOIN departments d ON d.id = e.department_id
          WHERE e.evaluator_id = :me AND e.status IN ('pending_evaluation', 'returned_evaluator')
          ORDER BY e.distributed_at ASC, e.id ASC
          LIMIT 200"
    );
    $stmt->execute([':me' => $userId]);
    return $stmt->fetchAll();
}

/** Forms in a department's pool (nobody has claimed them yet), oldest first. */
function ev_pool_list(int $departmentId): array
{
    $stmt = db()->prepare(
        "SELECT e.id, e.bid_ref_no, e.bidder, e.status, e.version, e.supplier_id, e.evaluator_id,
                e.department_id, e.distributed_at,
                u.business_name, u.full_name AS contact_name, d.name AS department_name,
                (SELECT COUNT(*) FROM evaluation_items i WHERE i.evaluation_id = e.id) AS item_count
           FROM evaluations e
           JOIN users u ON u.id = e.supplier_id
           LEFT JOIN departments d ON d.id = e.department_id
          WHERE e.department_id = :d AND e.evaluator_id IS NULL AND e.status = 'pending_evaluation'
          ORDER BY e.distributed_at ASC, e.id ASC
          LIMIT 200"
    );
    $stmt->execute([':d' => $departmentId]);
    return $stmt->fetchAll();
}

/** The columns the permission checks and transition() need (no form content). Or null. */
function ev_load_form(int $id): ?array
{
    $stmt = db()->prepare(
        'SELECT id, bid_ref_no, supplier_id, status, version, department_id, evaluator_id, distributed_by
           FROM evaluations
          WHERE id = :id
          LIMIT 1'
    );
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Claim a form from the department pool.
 * ONE atomic UPDATE with "evaluator_id IS NULL": if two people press Claim at
 * the same moment, only one UPDATE changes a row. Returns true when THIS person got it.
 */
function ev_claim_form(array $user, int $id): bool
{
    $eval = ev_load_form($id);
    if (!$eval || !can_claim_evaluation($user, $eval)) {
        return false;
    }

    $stmt = db()->prepare(
        "UPDATE evaluations
            SET evaluator_id = :me
          WHERE id = :id AND evaluator_id IS NULL AND status = 'pending_evaluation'
            AND department_id = :dept"
    );
    $stmt->execute([
        ':me'   => (int) $user['id'],
        ':id'   => $id,
        ':dept' => (int) $eval['department_id'],
    ]);
    if ($stmt->rowCount() !== 1) {
        return false; // somebody else was faster
    }

    $title = mms_form_title($eval);
    audit('form_claimed', 'Evaluation #' . $id . ' (' . $title . ') claimed by user #' . (int) $user['id']);
    if (!empty($eval['distributed_by'])) {
        notify((int) $eval['distributed_by'], $user['full_name'] . ' claimed form ' . $title . ' from the department pool.', 'headnurse/distribute.php?id=' . $id);
    }
    return true;
}

/**
 * The assigned evaluator sends the form back to the Head Nurse (remarks
 * required), only before any signature. Status goes to with_head_nurse and
 * evaluator_id is cleared. One transaction.
 */
function ev_send_back_form(array $user, int $id, string $remarks): void
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $lock = $pdo->prepare('SELECT id FROM evaluations WHERE id = :id FOR UPDATE');
        $lock->execute([':id' => $id]);

        $eval = ev_load_form($id);
        if (!$eval) {
            throw new WorkflowException('This form no longer exists.');
        }
        if (!can_send_back_evaluation($user, $eval)) {
            throw new WorkflowException('This form can no longer be sent back. It may already be signed or reassigned.');
        }

        $title   = mms_form_title($eval);
        $remarks = trim($remarks);
        $from    = (string) $eval['status'];

        $to = transition($eval, 'send_back', $user, $remarks);

        // The form has no evaluator again; the Head Nurse picks one.
        $pdo->prepare('UPDATE evaluations SET evaluator_id = NULL WHERE id = :id')->execute([':id' => $id]);

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

        notify_role('head_nurse', 'Form ' . $title . ' was sent back by the evaluator. Open it to read the remarks.', 'headnurse/distribute.php?id=' . $id);
        audit('form_sent_back', 'Evaluation #' . $id . ' (' . $title . '): ' . $remarks);

        $pdo->commit();
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $ex;
    }
}