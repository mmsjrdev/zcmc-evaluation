<?php
/**
 * app/domain/workflow.php
 *
 * The ONLY place that says which status may move to which status, who may do
 * it, and whether remarks are required. Every status change in the whole
 * system goes through transition().
 *
 *   $newStatus = transition($eval, 'submit', $user);
 *
 * transition() changes the status, writes the status_history row and returns the
 * new status. It does NOT notify anybody and does NOT check the page-level
 * permissions (can_view_evaluation() and friends): the page does that first.
 * Call it inside a database transaction when other changes must stay together.
 *
 * $eval needs at least: id, status, supplier_id (and evaluator_id for the
 * evaluator actions). If a refusal happens it throws WorkflowException.
 */

class WorkflowException extends RuntimeException
{
}

/**
 * The allowed transitions (guide, Section 7.2).
 *   from     statuses the action may start from
 *   to       the new status
 *   who      who may do it: owner_supplier | mm_staff | head_nurse |
 *            assigned_evaluator | verifier
 *   remarks  true = text is required (return, send back, cancel)
 */
function workflow_table(): array
{
    return [
        'submit'           => ['from' => ['supplier_draft'],      'to' => 'submitted',          'who' => ['owner_supplier'],                'remarks' => false],
        'resubmit'         => ['from' => ['returned_supplier'],   'to' => 'submitted',          'who' => ['owner_supplier'],                'remarks' => false],
        'forward'          => ['from' => ['submitted'],           'to' => 'with_head_nurse',    'who' => ['mm_staff'],                      'remarks' => false],
        'return_supplier'  => ['from' => ['submitted'],           'to' => 'returned_supplier',  'who' => ['mm_staff'],                      'remarks' => true],
        'distribute'       => ['from' => ['with_head_nurse'],     'to' => 'pending_evaluation', 'who' => ['head_nurse'],                    'remarks' => false],
        'return_to_mms'    => ['from' => ['with_head_nurse'],     'to' => 'submitted',          'who' => ['head_nurse'],                    'remarks' => true],
        'send_back'        => ['from' => ['pending_evaluation'],  'to' => 'with_head_nurse',    'who' => ['assigned_evaluator'],            'remarks' => true],
        'sign_evaluator'   => ['from' => ['pending_evaluation', 'returned_evaluator'], 'to' => 'pending_head', 'who' => ['assigned_evaluator'], 'remarks' => false],
        'sign_head'        => ['from' => ['pending_head'],        'to' => 'pending_conforme',   'who' => ['verifier'],                      'remarks' => false],
        'return_evaluator' => ['from' => ['pending_head'],        'to' => 'returned_evaluator', 'who' => ['verifier'],                      'remarks' => true],
        'conforme'         => ['from' => ['pending_conforme'],    'to' => 'completed',          'who' => ['owner_supplier', 'mm_staff'],    'remarks' => false],
        'receive'          => ['from' => ['completed'],           'to' => 'received',           'who' => ['mm_staff'],                      'remarks' => false],
        'return_completed' => ['from' => ['completed'],           'to' => 'returned_evaluator', 'who' => ['mm_staff'],                      'remarks' => true],
        // Cancel: the supplier only while the form is theirs to edit; MMS at any step before completed.
        'cancel_supplier'  => ['from' => ['supplier_draft', 'returned_supplier'], 'to' => 'cancelled', 'who' => ['owner_supplier'],         'remarks' => true],
        'cancel'           => ['from' => ['supplier_draft', 'submitted', 'with_head_nurse', 'pending_evaluation', 'pending_head',
                                          'pending_conforme', 'returned_supplier', 'returned_evaluator'],
                                                                  'to' => 'cancelled',          'who' => ['mm_staff'],                      'remarks' => true],
    ];
}

/** Does this person fit one of the "who" rules for this form? */
function workflow_actor_matches(string $rule, array $eval, array $actor): bool
{
    $actorId = (int) ($actor['id'] ?? 0);

    switch ($rule) {
        case 'owner_supplier':
            return ($actor['role'] ?? '') === 'supplier'
                && (int) ($eval['supplier_id'] ?? 0) === $actorId;

        case 'mm_staff':
        case 'head_nurse':
            return ($actor['role'] ?? '') === $rule;

        case 'assigned_evaluator':
            // A department head may also evaluate (they are stored as role "head").
            return in_array($actor['role'] ?? '', ['evaluator', 'head'], true)
                && !empty($eval['evaluator_id'])
                && (int) $eval['evaluator_id'] === $actorId;

        case 'verifier':
            // The detailed rules (own department, never the evaluator) live in
            // can_verify_evaluation(); here we only keep other roles out.
            return ($actor['role'] ?? '') === 'head'
                && (int) ($eval['evaluator_id'] ?? 0) !== $actorId;
    }
    return false;
}

/** Is this person allowed to run this action on this form right now? (true/false, never throws) */
function can_transition(array $eval, string $action, array $actor): bool
{
    $table = workflow_table();
    if (!isset($table[$action])) {
        return false;
    }
    $rule = $table[$action];

    if (!in_array($eval['status'] ?? '', $rule['from'], true)) {
        return false;
    }
    foreach ($rule['who'] as $who) {
        if (workflow_actor_matches($who, $eval, $actor)) {
            return true;
        }
    }
    return false;
}

/**
 * Move a form to its next status.
 * Returns the new status. Throws WorkflowException if the move is not allowed.
 */
function transition(array $eval, string $action, array $actor, string $remarks = ''): string
{
    $table = workflow_table();
    if (!isset($table[$action])) {
        throw new WorkflowException('Unknown action.');
    }
    $rule = $table[$action];

    if (!in_array($eval['status'] ?? '', $rule['from'], true)) {
        throw new WorkflowException('This form is not in the right status for that action.');
    }
    if (!can_transition($eval, $action, $actor)) {
        throw new WorkflowException('You are not allowed to do that.');
    }

    $remarks = trim($remarks);
    if ($rule['remarks'] && $remarks === '') {
        throw new WorkflowException('Please write the remarks or reason first.');
    }

    $from = (string) $eval['status'];
    $to   = $rule['to'];

    // "AND status = :from" makes sure two people cannot move the same form at once.
    $stmt = db()->prepare('UPDATE evaluations SET status = :to WHERE id = :id AND status = :from');
    $stmt->execute([':to' => $to, ':id' => (int) $eval['id'], ':from' => $from]);
    if ($stmt->rowCount() !== 1) {
        throw new WorkflowException('The form was changed by someone else. Reload the page and try again.');
    }

    $hist = db()->prepare(
        'INSERT INTO status_history (evaluation_id, from_status, to_status, actor_id, remarks)
         VALUES (:id, :from, :to, :actor, :remarks)'
    );
    $hist->execute([
        ':id'      => (int) $eval['id'],
        ':from'    => $from,
        ':to'      => $to,
        ':actor'   => (int) $actor['id'],
        ':remarks' => $remarks === '' ? null : $remarks,
    ]);

    return $to;
}
