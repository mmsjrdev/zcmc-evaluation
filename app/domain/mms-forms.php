<?php
/**
 * app/domain/mms-forms.php
 *
 * Database access and actions for the MMS (Material Management Section) pages:
 * mms/inbox.php and mms/review.php.
 *
 * Like supplier-forms.php, these queries list columns ONE BY ONE and never use
 * SELECT *. MMS only needs the supplier-owned part of a form in this Step, so
 * evaluator data (findings, grounds, Sections B and C) is never read here.
 *
 * Every status change goes through transition() in app/domain/workflow.php.
 */

require_once __DIR__ . '/supplier-forms.php';

/** The three tabs of the MMS inbox: status => tab label. */
function mms_tabs(): array
{
    return [
        'submitted'         => 'Waiting for review',
        'with_head_nurse'   => 'With Head Nurse',
        'returned_supplier' => 'Returned to supplier',
    ];
}

/** How many forms sit in each tab: status => number. */
function mms_tab_counts(): array
{
    $counts = array_fill_keys(array_keys(mms_tabs()), 0);

    $rows = db()->query(
        "SELECT status, COUNT(*) AS total
           FROM evaluations
          WHERE status IN ('submitted', 'with_head_nurse', 'returned_supplier')
          GROUP BY status"
    )->fetchAll();

    foreach ($rows as $row) {
        $counts[$row['status']] = (int) $row['total'];
    }
    return $counts;
}

/**
 * The forms of one tab. $search looks in the bid number, the bidder and the
 * supplier's business name and contact person.
 * "Waiting for review" shows the oldest first; the other tabs show the newest first.
 */
function mms_inbox_list(string $status, string $search): array
{
    $sql = 'SELECT e.id, e.bid_ref_no, e.bidder, e.status, e.submitted_at, e.updated_at,
                   u.business_name, u.full_name AS contact_name
              FROM evaluations e
              JOIN users u ON u.id = e.supplier_id
             WHERE e.status = :status';
    $params = [':status' => $status];

    if ($search !== '') {
        // Escape the LIKE wildcards so "50%" is searched as plain text.
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search) . '%';
        // PDO needs a different name each time a value is used, so four names.
        $sql .= ' AND (e.bid_ref_no LIKE :q1 OR e.bidder LIKE :q2
                       OR u.business_name LIKE :q3 OR u.full_name LIKE :q4)';
        $params[':q1'] = $like;
        $params[':q2'] = $like;
        $params[':q3'] = $like;
        $params[':q4'] = $like;
    }

    $sql .= $status === 'submitted'
        ? ' ORDER BY e.submitted_at ASC, e.id ASC'
        : ' ORDER BY e.updated_at DESC, e.id DESC';
    $sql .= ' LIMIT 200';

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * One form for the MMS review page: the supplier-visible columns plus the
 * supplier's account details and the hash stored at submit. Or null.
 * The caller checks can_view_evaluation().
 */
function mms_load_form(int $id): ?array
{
    $eval = supplier_load_form($id);
    if (!$eval) {
        return null;
    }

    $stmt = db()->prepare(
        'SELECT e.supplier_hash,
                u.business_name AS supplier_business, u.full_name AS supplier_contact,
                u.email AS supplier_email, u.contact_no AS supplier_contact_no
           FROM evaluations e
           JOIN users u ON u.id = e.supplier_id
          WHERE e.id = :id
          LIMIT 1'
    );
    $stmt->execute([':id' => $id]);
    $extra = $stmt->fetch();

    return $eval + ($extra ?: []);
}

/** A short title for a form: the bid number, or "Form #12" when there is none yet. */
function mms_form_title(array $row): string
{
    $bid = trim((string) ($row['bid_ref_no'] ?? ''));
    return $bid !== '' ? $bid : 'Form #' . (int) $row['id'];
}

/** A date from the database as "Nov 6, 2026", or a dash. */
function mms_date(?string $value): string
{
    return ($value !== null && $value !== '') ? date('M j, Y', strtotime($value)) : '-';
}

/** A date and time as "Nov 6, 2026 3:05 PM", or a dash. */
function mms_date_time(?string $value): string
{
    return ($value !== null && $value !== '') ? date('M j, Y g:i A', strtotime($value)) : '-';
}

/** "today", "1 day ago", "5 days ago". */
function mms_days_ago(?string $value): string
{
    if ($value === null || $value === '') {
        return '';
    }
    $days = (int) floor((time() - strtotime($value)) / 86400);
    if ($days <= 0) {
        return 'today';
    }
    return $days === 1 ? '1 day ago' : $days . ' days ago';
}

/**
 * Run one MMS action on one form, all in ONE transaction:
 *
 *   'forward'         submitted > with_head_nurse   (notifies the Head Nurses)
 *   'return_supplier' submitted > returned_supplier (remarks required, adds a "returns" row, notifies the supplier)
 *   'cancel'          any step before completed > cancelled (reason required, notifies the supplier)
 *
 * The form is locked and re-read from the database first, so a double click or
 * a second MMS user cannot run the action twice. transition() decides whether
 * the move is allowed and throws WorkflowException if it is not.
 */
function mms_apply_action(array $user, int $id, string $action, string $remarks = ''): void
{
    if (!in_array($action, ['forward', 'return_supplier', 'cancel'], true)) {
        throw new WorkflowException('Unknown action.');
    }

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

        $title      = mms_form_title($eval);
        $supplierId = (int) $eval['supplier_id'];
        $remarks    = trim($remarks);

        // Changes the status and writes the status_history row.
        $to = transition($eval, $action, $user, $remarks);

        if ($action === 'forward') {
            notify_role('head_nurse', 'Form ' . $title . ' is ready for distribution.', 'headnurse/inbox.php');
            audit('form_forwarded', 'Evaluation #' . $id . ' (' . $title . ') forwarded to the Head Nurse');

        } elseif ($action === 'return_supplier') {
            $pdo->prepare(
                'INSERT INTO returns (evaluation_id, version, from_status, to_status, returned_by, remarks)
                 VALUES (:id, :version, :from, :to, :by, :remarks)'
            )->execute([
                ':id'      => $id,
                ':version' => (int) $eval['version'],
                ':from'    => (string) $eval['status'],
                ':to'      => $to,
                ':by'      => (int) $user['id'],
                ':remarks' => $remarks,
            ]);
            notify($supplierId, 'MMS returned your form ' . $title . '. Open it to read the remarks.', 'supplier/form-view.php?id=' . $id);
            audit('form_returned_to_supplier', 'Evaluation #' . $id . ' (' . $title . '): ' . $remarks);

        } else { // cancel
            notify($supplierId, 'MMS cancelled your form ' . $title . '. Open it to read the reason.', 'supplier/form-view.php?id=' . $id);
            audit('form_cancelled', 'Evaluation #' . $id . ' (' . $title . ') cancelled: ' . $remarks);
        }

        $pdo->commit();
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $ex;
    }
}