<?php
/**
 * app/domain/supplier-forms.php
 *
 * Database access for the SUPPLIER pages (supplier/forms.php, form-edit.php,
 * form-view.php).
 *
 * IMPORTANT: these queries list the supplier-owned columns ONE BY ONE and never
 * use SELECT *. Evaluator data (findings, grounds, Sections B and C) is never
 * even read from the database on a supplier page, so it can never leak into the
 * page output by mistake.
 */

/** The active modes of procurement, for the dropdown. */
function procurement_modes_active(): array
{
    return db()->query('SELECT id, name FROM procurement_modes WHERE active = 1 ORDER BY id')->fetchAll();
}

/** One form (supplier-visible columns only), or null. The caller checks the owner. */
function supplier_load_form(int $id): ?array
{
    $stmt = db()->prepare(
        'SELECT e.id, e.ref_no, e.supplier_id, e.bid_ref_no, e.mode_id, m.name AS mode_name,
                e.opening_date, e.header_item_no, e.bidder, e.eval_date,
                e.status, e.version, e.submitted_at, e.created_at, e.updated_at
           FROM evaluations e
           LEFT JOIN procurement_modes m ON m.id = e.mode_id
          WHERE e.id = :id
          LIMIT 1'
    );
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** The Section A rows of a form: only item_no, specifications, bidder_spec. */
function supplier_load_items(int $evaluationId): array
{
    $stmt = db()->prepare(
        'SELECT item_no, specifications, bidder_spec
           FROM evaluation_items
          WHERE evaluation_id = :id
          ORDER BY sort_order, id'
    );
    $stmt->execute([':id' => $evaluationId]);
    return $stmt->fetchAll();
}

/** The six header values as plain text (used for the page and for supplier_hash()). */
function supplier_header_values(array $eval): array
{
    $out = [];
    foreach (['bid_ref_no', 'mode_id', 'opening_date', 'bidder', 'eval_date', 'header_item_no'] as $name) {
        $out[$name] = (string) ($eval[$name] ?? '');
    }
    return $out;
}

/** The status history of one form, oldest first. */
function supplier_load_history(int $evaluationId): array
{
    $stmt = db()->prepare(
        'SELECT to_status, remarks, created_at
           FROM status_history
          WHERE evaluation_id = :id
          ORDER BY created_at, id'
    );
    $stmt->execute([':id' => $evaluationId]);
    return $stmt->fetchAll();
}

/**
 * Save the supplier-owned part: creates the form when $eval is null, otherwise
 * updates it, then replaces the Section A rows. All in one transaction.
 * $data is the output of filter_post() (so it holds supplier fields only).
 * Returns the form id.
 */
function supplier_save_form(array $user, ?array $eval, array $data): int
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $params = [
            ':bid_ref_no'     => (string) ($data['bid_ref_no'] ?? ''),
            ':mode_id'        => ($data['mode_id'] ?? '') === '' ? null : (int) $data['mode_id'],
            ':opening_date'   => ($data['opening_date'] ?? '') === '' ? null : $data['opening_date'],
            ':header_item_no' => ($data['header_item_no'] ?? '') === '' ? null : $data['header_item_no'],
            ':bidder'         => ($data['bidder'] ?? '') === '' ? null : $data['bidder'],
            ':eval_date'      => ($data['eval_date'] ?? '') === '' ? null : $data['eval_date'],
        ];

        if ($eval === null) {
            $params[':supplier_id'] = (int) $user['id'];
            $pdo->prepare(
                'INSERT INTO evaluations (supplier_id, bid_ref_no, mode_id, opening_date, header_item_no, bidder, eval_date)
                 VALUES (:supplier_id, :bid_ref_no, :mode_id, :opening_date, :header_item_no, :bidder, :eval_date)'
            )->execute($params);
            $id = (int) $pdo->lastInsertId();
        } else {
            $id = (int) $eval['id'];
            $params[':id']          = $id;
            $params[':supplier_id'] = (int) $user['id'];
            $stmt = $pdo->prepare(
                "UPDATE evaluations
                    SET bid_ref_no = :bid_ref_no, mode_id = :mode_id, opening_date = :opening_date,
                        header_item_no = :header_item_no, bidder = :bidder, eval_date = :eval_date
                  WHERE id = :id AND supplier_id = :supplier_id
                    AND status IN ('supplier_draft', 'returned_supplier')"
            );
            $stmt->execute($params);
            // Not changed rows are fine (same values); a missing form is not.
            $check = $pdo->prepare("SELECT 1 FROM evaluations WHERE id = :id AND supplier_id = :s AND status IN ('supplier_draft','returned_supplier')");
            $check->execute([':id' => $id, ':s' => (int) $user['id']]);
            if ($check->fetchColumn() === false) {
                throw new RuntimeException('This form can no longer be edited.');
            }
        }

        // Replace the Section A rows. At this stage no evaluator data exists yet
        // (a form only comes back to the supplier from MMS), so nothing is lost.
        $pdo->prepare('DELETE FROM evaluation_items WHERE evaluation_id = :id')->execute([':id' => $id]);
        $ins = $pdo->prepare(
            'INSERT INTO evaluation_items (evaluation_id, item_no, specifications, bidder_spec, sort_order)
             VALUES (:id, :item_no, :specifications, :bidder_spec, :sort)'
        );
        foreach (array_values($data['items'] ?? []) as $n => $row) {
            $ins->execute([
                ':id'             => $id,
                ':item_no'        => (string) $row['item_no'],
                ':specifications' => $row['specifications'] === '' ? null : $row['specifications'],
                ':bidder_spec'    => $row['bidder_spec'] === '' ? null : $row['bidder_spec'],
                ':sort'           => $n + 1,
            ]);
        }

        $pdo->commit();
        return $id;
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $ex;
    }
}

/**
 * Submit (or resubmit) to MMS. Re-checks everything from the database, stores
 * the supplier hash, moves the status and tells MMS. One transaction.
 * Returns an array of validation errors (empty = submitted).
 */
function supplier_submit_form(array $user, int $id): array
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        // Lock the row so a double click cannot submit twice.
        $lock = $pdo->prepare('SELECT id FROM evaluations WHERE id = :id FOR UPDATE');
        $lock->execute([':id' => $id]);

        $eval = supplier_load_form($id);
        if (!$eval || !can_edit_supplier_part($user, $eval)) {
            throw new WorkflowException('This form cannot be submitted.');
        }

        $header = supplier_header_values($eval);
        $items  = supplier_load_items($id);

        $errors = validate_supplier_part($header, $items, true);
        if ($errors !== []) {
            $pdo->rollBack();
            return $errors;
        }

        $action = $eval['status'] === 'returned_supplier' ? 'resubmit' : 'submit';
        transition($eval, $action, $user);

        $pdo->prepare('UPDATE evaluations SET supplier_hash = :h, submitted_at = NOW() WHERE id = :id')
            ->execute([':h' => supplier_hash($header, $items), ':id' => $id]);

        notify_role('mm_staff', 'New form from ' . ($header['bidder'] ?: 'a supplier') . ' (' . $header['bid_ref_no'] . ') is ready for review.', 'mms/review.php?id=' . $id);
        audit($action === 'resubmit' ? 'form_resubmitted' : 'form_submitted', 'Evaluation #' . $id . ', bid ' . $header['bid_ref_no']);

        $pdo->commit();
        return [];
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $ex;
    }
}
