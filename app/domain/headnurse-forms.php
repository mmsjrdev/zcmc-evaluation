<?php
/**
 * app/domain/headnurse-forms.php
 *
 * Database access and actions for the HEAD NURSE pages:
 * headnurse/inbox.php and headnurse/distribute.php.
 *
 * Like the other domain files, columns are listed one by one (never SELECT *).
 * Only the supplier part and the distribution columns are read; evaluator-owned
 * data (findings, grounds, Sections B and C) is not touched here.
 *
 * Every status change goes through transition() in app/domain/workflow.php.
 */

// Small shared helpers: mms_form_title(), mms_date(), mms_date_time(), mms_days_ago().
require_once __DIR__ . '/mms-forms.php';

/** The statuses a form can have AFTER the Head Nurse sent it on (the "Distributed" tab). */
function hn_distributed_statuses(): array
{
    return ['pending_evaluation', 'returned_evaluator', 'pending_head',
            'pending_conforme', 'completed', 'received', 'cancelled'];
}

/** The two tabs of the Head Nurse inbox: key => label. */
function hn_tabs(): array
{
    return [
        'waiting'     => 'Waiting for distribution',
        'distributed' => 'Distributed',
    ];
}

/** How many forms are in each tab: key => number. */
function hn_counts(int $userId): array
{
    $statuses = "'" . implode("','", hn_distributed_statuses()) . "'"; // fixed list from this file

    $stmt = db()->prepare(
        "SELECT
            (SELECT COUNT(*) FROM evaluations WHERE status = 'with_head_nurse') AS waiting,
            (SELECT COUNT(*) FROM evaluations WHERE distributed_by = :me AND status IN ($statuses)) AS distributed"
    );
    $stmt->execute([':me' => $userId]);
    $row = $stmt->fetch();

    return ['waiting' => (int) $row['waiting'], 'distributed' => (int) $row['distributed']];
}

/** Forms with the Head Nurse, oldest first (updated_at = the moment they arrived). */
function hn_waiting_list(): array
{
    return db()->query(
        "SELECT e.id, e.bid_ref_no, e.bidder, e.status, e.updated_at,
                u.business_name, u.full_name AS contact_name,
                (SELECT COUNT(*) FROM evaluation_items i WHERE i.evaluation_id = e.id) AS item_count
           FROM evaluations e
           JOIN users u ON u.id = e.supplier_id
          WHERE e.status = 'with_head_nurse'
          ORDER BY e.updated_at ASC, e.id ASC
          LIMIT 200"
    )->fetchAll();
}

/** Forms this Head Nurse sent on, newest first, with their current status. */
function hn_distributed_list(int $userId): array
{
    $statuses = "'" . implode("','", hn_distributed_statuses()) . "'";

    $stmt = db()->prepare(
        "SELECT e.id, e.bid_ref_no, e.bidder, e.status, e.distributed_at,
                u.business_name, u.full_name AS contact_name,
                d.name AS department_name, ev.full_name AS evaluator_name
           FROM evaluations e
           JOIN users u ON u.id = e.supplier_id
           LEFT JOIN departments d ON d.id = e.department_id
           LEFT JOIN users ev ON ev.id = e.evaluator_id
          WHERE e.distributed_by = :me AND e.status IN ($statuses)
          ORDER BY e.distributed_at DESC, e.id DESC
          LIMIT 200"
    );
    $stmt->execute([':me' => $userId]);
    return $stmt->fetchAll();
}

/**
 * One form for the distribute page: the supplier-visible columns, the supplier's
 * name, and the distribution columns (department, evaluator, who/when). Or null.
 * The caller checks can_view_evaluation().
 */
function hn_load_form(int $id): ?array
{
    $eval = supplier_load_form($id);
    if (!$eval) {
        return null;
    }

    $stmt = db()->prepare(
        'SELECT e.department_id, e.evaluator_id, e.distributed_by, e.distributed_at,
                d.name AS department_name, ev.full_name AS evaluator_name,
                u.business_name AS supplier_business, u.full_name AS supplier_contact
           FROM evaluations e
           JOIN users u ON u.id = e.supplier_id
           LEFT JOIN departments d ON d.id = e.department_id
           LEFT JOIN users ev ON ev.id = e.evaluator_id
          WHERE e.id = :id
          LIMIT 1'
    );
    $stmt->execute([':id' => $id]);
    $extra = $stmt->fetch();

    return $eval + ($extra ?: []);
}

/** The active departments, for the dropdown. */
function hn_active_departments(): array
{
    return db()->query('SELECT id, name FROM departments WHERE active = 1 ORDER BY name')->fetchAll();
}

/** The active evaluator and head accounts of one department. */
function hn_department_evaluators(int $departmentId): array
{
    $stmt = db()->prepare(
        "SELECT id, full_name, role, position
           FROM users
          WHERE department_id = :d AND status = 'active' AND role IN ('evaluator', 'head')
          ORDER BY full_name"
    );
    $stmt->execute([':d' => $departmentId]);
    return $stmt->fetchAll();
}

/**
 * Distribute a form, all in ONE transaction:
 *   - $evaluatorId given  = that evaluator gets it (only they see it)
 *   - $evaluatorId = null = department pool (the first evaluator to Claim gets it)
 * Sets department_id, evaluator_id, distributed_by, distributed_at and the status
 * (through transition()), then notifies and writes the audit log.
 * Throws WorkflowException with a plain message when something is wrong.
 */
function hn_distribute_form(array $user, int $id, int $departmentId, ?int $evaluatorId): void
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        // Lock the row, then read the form again from the database.
        $lock = $pdo->prepare('SELECT id FROM evaluations WHERE id = :id FOR UPDATE');
        $lock->execute([':id' => $id]);

        $eval = supplier_load_form($id);
        if (!$eval) {
            throw new WorkflowException('This form no longer exists.');
        }

        // The department must exist and be active.
        $deptStmt = $pdo->prepare('SELECT name FROM departments WHERE id = :id AND active = 1');
        $deptStmt->execute([':id' => $departmentId]);
        $departmentName = $deptStmt->fetchColumn();
        if ($departmentName === false) {
            throw new WorkflowException('Choose a department from the list.');
        }

        // A named evaluator must be an active evaluator/head of THAT department.
        if ($evaluatorId !== null) {
            $evStmt = $pdo->prepare(
                "SELECT 1 FROM users
                  WHERE id = :id AND department_id = :d AND status = 'active' AND role IN ('evaluator', 'head')"
            );
            $evStmt->execute([':id' => $evaluatorId, ':d' => $departmentId]);
            if ($evStmt->fetchColumn() === false) {
                throw new WorkflowException('That evaluator does not belong to the chosen department.');
            }
        }

        $title = mms_form_title($eval);

        // Changes the status and writes the status_history row (and refuses if not allowed).
        transition($eval, 'distribute', $user);

        $pdo->prepare(
            'UPDATE evaluations
                SET department_id = :d, evaluator_id = :e, distributed_by = :by, distributed_at = NOW()
              WHERE id = :id'
        )->execute([
            ':d'  => $departmentId,
            ':e'  => $evaluatorId,
            ':by' => (int) $user['id'],
            ':id' => $id,
        ]);

        if ($evaluatorId !== null) {
            notify($evaluatorId, 'Form ' . $title . ' was assigned to you for evaluation.', 'evaluator/assignments.php');
        } else {
            // Pool: tell every active evaluator and head of the department.
            $people = $pdo->prepare(
                "SELECT id FROM users
                  WHERE department_id = :d AND status = 'active' AND role IN ('evaluator', 'head')"
            );
            $people->execute([':d' => $departmentId]);
            $message = mb_substr('Form ' . $title . ' is waiting in the ' . $departmentName . ' pool. Open Assignments to claim it.', 0, 250);
            foreach ($people->fetchAll() as $person) {
                notify((int) $person['id'], $message, 'evaluator/assignments.php?tab=pool');
            }
        }

        audit('form_distributed', 'Evaluation #' . $id . ' (' . $title . ') to ' . $departmentName
            . ($evaluatorId !== null ? ', evaluator #' . $evaluatorId : ', department pool'));

        $pdo->commit();
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $ex;
    }
}

/**
 * The Head Nurse returns a form to MMS (remarks required): status back to
 * submitted, a "returns" row, and MMS is notified. One transaction.
 */
function hn_return_to_mms(array $user, int $id, string $remarks): void
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $lock = $pdo->prepare('SELECT id FROM evaluations WHERE id = :id FOR UPDATE');
        $lock->execute([':id' => $id]);

        $eval = supplier_load_form($id);
        if (!$eval) {
            throw new WorkflowException('This form no longer exists.');
        }

        $title   = mms_form_title($eval);
        $remarks = trim($remarks);
        $from    = (string) $eval['status'];

        $to = transition($eval, 'return_to_mms', $user, $remarks);

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

        notify_role('mm_staff', 'The Head Nurse returned form ' . $title . ' to MMS. Open it to read the remarks.', 'mms/review.php?id=' . $id);
        audit('form_returned_to_mms', 'Evaluation #' . $id . ' (' . $title . '): ' . $remarks);

        $pdo->commit();
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $ex;
    }
}