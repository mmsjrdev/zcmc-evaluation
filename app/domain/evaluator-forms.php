<?php
/**
 * app/domain/evaluator-forms.php
 *
 * Database access and actions for the EVALUATOR pages:
 *   - evaluator/assignments.php (list, claim, send back)
 *   - evaluator/evaluate.php and common/evaluation-view.php (Step 7: the
 *     evaluator-owned part of the form: Findings, Grounds, Sections B and C)
 * Also used by Department Heads who evaluate.
 */

// Small shared helpers: mms_form_title(), mms_date(), mms_date_time(), mms_days_ago().
require_once __DIR__ . '/mms-forms.php';
// Field ownership and the evaluator rules (validate_evaluator_part, ev_params_to_array).
require_once __DIR__ . '/form-fields.php';
require_once __DIR__ . '/validation.php';

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

// ===========================================================================
// STEP 7: the evaluator part of the form
// ===========================================================================

/**
 * The whole form for the evaluator pages: header, mode, department name,
 * evaluator name and the evaluator-owned columns. Or null.
 * Columns are listed one by one (no SELECT *). This is also the row that the
 * permission functions need (id, status, version, evaluator_id, ...).
 */
function ev_load_full_form(int $id): ?array
{
    $stmt = db()->prepare(
        'SELECT e.id, e.ref_no, e.supplier_id, e.bid_ref_no, e.mode_id, m.name AS mode_name,
                e.opening_date, e.header_item_no, e.bidder, e.eval_date,
                e.status, e.version, e.submitted_at,
                e.department_id, d.name AS department_name,
                e.evaluator_id, ev.full_name AS evaluator_name, e.distributed_by,
                e.failed_quality, e.b_params, e.b_details,
                e.others_checked, e.c_params, e.c_details
           FROM evaluations e
           LEFT JOIN procurement_modes m ON m.id = e.mode_id
           LEFT JOIN departments d ON d.id = e.department_id
           LEFT JOIN users ev ON ev.id = e.evaluator_id
          WHERE e.id = :id
          LIMIT 1'
    );
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** The Section A rows with the evaluator columns, in form order. */
function ev_load_items_full(int $id): array
{
    $stmt = db()->prepare(
        'SELECT id, item_no, specifications, bidder_spec, finding, grounds
           FROM evaluation_items
          WHERE evaluation_id = :id
          ORDER BY sort_order, id'
    );
    $stmt->execute([':id' => $id]);
    return $stmt->fetchAll();
}

/** The status history of one form (with who did it), oldest first. */
function ev_load_history(int $id): array
{
    $stmt = db()->prepare(
        'SELECT h.to_status, h.remarks, h.created_at, u.full_name AS actor_name
           FROM status_history h
           LEFT JOIN users u ON u.id = h.actor_id
          WHERE h.evaluation_id = :id
          ORDER BY h.created_at, h.id'
    );
    $stmt->execute([':id' => $id]);
    return $stmt->fetchAll();
}

/**
 * The saved evaluator values in the same shape the page and the validator use:
 *   failed_quality, b_params (list), b_details, others_checked, c_params (list),
 *   c_details, items (list of [finding, grounds] in form order).
 */
function ev_values_from_db(array $eval, array $items): array
{
    $rows = [];
    foreach ($items as $it) {
        $rows[] = [
            'finding' => (string) ($it['finding'] ?? ''),
            'grounds' => (string) ($it['grounds'] ?? ''),
        ];
    }
    return [
        'failed_quality' => (int) $eval['failed_quality'] === 1 ? 1 : 0,
        'b_params'       => ev_params_to_array($eval['b_params'] ?? ''),
        'b_details'      => (string) ($eval['b_details'] ?? ''),
        'others_checked' => (int) $eval['others_checked'] === 1 ? 1 : 0,
        'c_params'       => ev_params_to_array($eval['c_params'] ?? ''),
        'c_details'      => (string) ($eval['c_details'] ?? ''),
        'items'          => $rows,
    ];
}

/**
 * Turns the output of filter_post() into the plain shape used by the page:
 * checkboxes become 0/1, parameter lists become arrays, every item row has
 * exactly 'finding' and 'grounds'. It does NOT drop wrong values: the
 * validator needs to see them so it can complain.
 */
function ev_shape_post(array $data): array
{
    $out = [
        'failed_quality' => ((string) ($data['failed_quality'] ?? '') === '1') ? 1 : 0,
        'b_params'       => ev_params_to_array($data['b_params'] ?? []),
        'b_details'      => (string) ($data['b_details'] ?? ''),
        'others_checked' => ((string) ($data['others_checked'] ?? '') === '1') ? 1 : 0,
        'c_params'       => ev_params_to_array($data['c_params'] ?? []),
        'c_details'      => (string) ($data['c_details'] ?? ''),
        'items'          => [],
    ];
    foreach (array_values($data['items'] ?? []) as $row) {
        $out['items'][] = [
            'finding' => (string) ($row['finding'] ?? ''),
            'grounds' => (string) ($row['grounds'] ?? ''),
        ];
    }
    return $out;
}

/** "Responsive" / "Non-Responsive" / "Not set" for a stored finding. */
function ev_finding_label(?string $finding): string
{
    if ($finding === 'responsive') {
        return 'Responsive';
    }
    if ($finding === 'non_responsive') {
        return 'Non-Responsive';
    }
    return 'Not set';
}

/** Turns a list of parameter codes into their labels, using one of the code => label arrays. */
function ev_param_labels($codes, array $map): array
{
    $labels = [];
    foreach (ev_params_to_array($codes) as $code) {
        if (isset($map[$code])) {
            $labels[] = $map[$code];
        }
    }
    return $labels;
}

/**
 * Save the evaluator-owned part in ONE transaction.
 *
 * $data is the output of ev_shape_post() (it came from filter_post(), so it
 * holds evaluator fields only). This function:
 *   - locks the form row and re-checks can_edit_evaluator_part() from the database
 *   - UPDATEs only failed_quality, b_params, b_details, others_checked, c_params,
 *     c_details on evaluations, and only finding and grounds on evaluation_items
 *   - never inserts, deletes or touches a supplier column, so supplier_hash
 *     still matches after a save
 * Throws WorkflowException with a plain message when the save is refused.
 */
function ev_save_evaluator_part(array $user, int $id, array $data): void
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        // Lock the row so two saves (or a save and a signature) cannot overlap.
        $lock = $pdo->prepare('SELECT id FROM evaluations WHERE id = :id FOR UPDATE');
        $lock->execute([':id' => $id]);

        $eval = ev_load_form($id);
        if (!$eval) {
            throw new WorkflowException('This form no longer exists.');
        }
        if (!can_edit_evaluator_part($user, $eval)) {
            throw new WorkflowException('This form can no longer be edited. It may be signed, reassigned or no longer waiting for evaluation.');
        }

        // The stored items, in form order. The posted rows are matched by position.
        $idStmt = $pdo->prepare('SELECT id FROM evaluation_items WHERE evaluation_id = :id ORDER BY sort_order, id');
        $idStmt->execute([':id' => $id]);
        $itemIds = array_map('intval', array_column($idStmt->fetchAll(), 'id'));

        $rows = array_values($data['items'] ?? []);
        if (count($rows) !== count($itemIds)) {
            throw new WorkflowException('The number of items on the page does not match the saved form. Nothing was saved. Reload the page and try again.');
        }

        // ---- Clear what an unticked box or a Responsive item must not keep ----
        $failed  = ((int) ($data['failed_quality'] ?? 0)) === 1;
        $others  = ((int) ($data['others_checked'] ?? 0)) === 1;
        $bParams = $failed ? array_values(array_intersect(ev_params_to_array($data['b_params'] ?? []), array_keys(quality_parameters()))) : [];
        $cParams = $others ? array_values(array_intersect(ev_params_to_array($data['c_params'] ?? []), array_keys(others_parameters()))) : [];
        $bDetails = $failed ? trim((string) ($data['b_details'] ?? '')) : '';
        $cDetails = $others ? trim((string) ($data['c_details'] ?? '')) : '';

        $pdo->prepare(
            'UPDATE evaluations
                SET failed_quality = :fq, b_params = :bp, b_details = :bd,
                    others_checked = :oc, c_params = :cp, c_details = :cd
              WHERE id = :id AND evaluator_id = :me'
        )->execute([
            ':fq' => $failed ? 1 : 0,
            ':bp' => $bParams === [] ? null : implode(',', $bParams),
            ':bd' => $bDetails === '' ? null : $bDetails,
            ':oc' => $others ? 1 : 0,
            ':cp' => $cParams === [] ? null : implode(',', $cParams),
            ':cd' => $cDetails === '' ? null : $cDetails,
            ':id' => $id,
            ':me' => (int) $user['id'],
        ]);

        // ---- Findings and grounds, by item id (never by what was posted) ----
        $upd = $pdo->prepare(
            'UPDATE evaluation_items SET finding = :f, grounds = :g
              WHERE id = :iid AND evaluation_id = :id'
        );
        foreach ($itemIds as $n => $itemId) {
            $finding = (string) ($rows[$n]['finding'] ?? '');
            if (!in_array($finding, ['responsive', 'non_responsive'], true)) {
                $finding = '';
            }
            $grounds = $finding === 'non_responsive' ? trim((string) ($rows[$n]['grounds'] ?? '')) : '';
            $upd->execute([
                ':f'   => $finding === '' ? null : $finding,
                ':g'   => $grounds === '' ? null : $grounds,
                ':iid' => $itemId,
                ':id'  => $id,
            ]);
        }

        audit('evaluator_part_saved', 'Evaluation #' . $id . ' evaluator part saved by user #' . (int) $user['id']);

        $pdo->commit();
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $ex;
    }
}