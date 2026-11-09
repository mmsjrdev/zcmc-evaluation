<?php
/**
 * app/core/permissions.php
 *
 * WHAT each role is allowed to do. Pages ask these functions; they never
 * decide permissions on their own.
 *
 *   - Page guards:  require_login(), require_role([...])
 *   - Form rules:   can_view_evaluation(), can_edit_supplier_part(),
 *                   can_edit_evaluator_part(), can_distribute(),
 *                   can_claim_evaluation(), can_send_back_evaluation(),
 *                   can_verify_evaluation(), can_sign_as_evaluator(),
 *                   can_sign_as_head(), signing_role_for(),
 *                   can_return_evaluation(), can_cancel_evaluation()
 *
 * Which status may move to which status is NOT decided here. That lives only
 * in app/domain/workflow.php.
 *
 * $user  = the array from current_user()
 * $eval  = a row from the evaluations table
 */

// ---------------------------------------------------------------------------
// Roles and status groups
// ---------------------------------------------------------------------------

/** Every role that exists. Use it for pages that any signed-in person may open. */
function all_roles(): array
{
    return ['supplier', 'mm_staff', 'head_nurse', 'evaluator', 'head', 'admin'];
}

/**
 * Statuses an evaluator may see ("from pending_evaluation on").
 * returned_evaluator is included because the evaluator has to fix that form.
 */
function evaluator_visible_statuses(): array
{
    return ['pending_evaluation', 'returned_evaluator', 'pending_head',
            'pending_conforme', 'completed', 'received'];
}

/** Statuses a department head may see ("from pending_head on"). */
function head_visible_statuses(): array
{
    return ['pending_head', 'pending_conforme', 'completed', 'received'];
}

/** An account that is allowed to work. (A pending Department Head is an evaluator for now.) */
function user_is_usable(array $user): bool
{
    return in_array($user['status'] ?? '', ['active', 'pending'], true);
}

// ---------------------------------------------------------------------------
// Page guards
// ---------------------------------------------------------------------------

/**
 * The page needs a signed-in person. Sends everybody else to the login page.
 * Also forces a password change when must_change_password = 1.
 * Returns the user so the page can write:  $user = require_login();
 */
function require_login(): array
{
    $user = current_user();

    if ($user === null) {
        flash('info', 'Please sign in to continue.');
        redirect('auth/login.php');
    }

    // Temporary password? Nothing else works until it is changed.
    $thisPage = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $allowedWhileForced = ['change-password.php', 'logout.php'];
    if ((int) $user['must_change_password'] === 1 && !in_array($thisPage, $allowedWhileForced, true)) {
        flash('info', 'Please choose a new password before you continue.');
        redirect('auth/change-password.php');
    }

    return $user;
}

/**
 * The page is only for some roles. Every page calls this right after bootstrap.
 * Example:  $user = require_role(['mm_staff', 'admin']);
 */
function require_role(array $roles): array
{
    $user = require_login();

    if (!in_array($user['role'], $roles, true)) {
        forbidden();
    }

    return $user;
}

/** Show the "not allowed" page (HTTP 403) and stop. */
function forbidden(): void
{
    http_response_code(403);

    $pageTitle = 'Access denied';
    require __DIR__ . '/../views/layout/auth-top.php';
    ?>
    <h2 class="text-lg font-semibold text-slate-900">You cannot open this page</h2>
    <p class="mt-2 text-sm text-slate-600">
        Your account does not have access to it. If you think this is a mistake,
        contact the administrator.
    </p>
    <a href="<?= e(url('common/dashboard.php')) ?>" class="btn btn-primary mt-5 w-full">Back to the dashboard</a>
    <?php
    require __DIR__ . '/../views/layout/auth-bottom.php';
    exit;
}

// ---------------------------------------------------------------------------
// Department helpers
// ---------------------------------------------------------------------------

/**
 * The departments a Department Head may verify: their own department plus the
 * departments where an admin made them an alternate verifier.
 * current_user() already puts this list in $user['verifier_department_ids'].
 */
function verifier_department_ids(array $user): array
{
    if (isset($user['verifier_department_ids']) && is_array($user['verifier_department_ids'])) {
        return $user['verifier_department_ids'];
    }

    $ids = [];
    if (!empty($user['department_id'])) {
        $ids[] = (int) $user['department_id'];
    }

    $stmt = db()->prepare('SELECT department_id FROM alternate_verifiers WHERE head_user_id = :id');
    $stmt->execute([':id' => (int) $user['id']]);
    foreach ($stmt->fetchAll() as $row) {
        $ids[] = (int) $row['department_id'];
    }

    return array_values(array_unique($ids));
}

/**
 * Has the evaluator already signed THIS version of the form?
 * (A signature counts only when its version equals the form's current version.)
 */
function evaluator_has_signed(array $eval): bool
{
    $stmt = db()->prepare(
        "SELECT 1 FROM signatures
          WHERE evaluation_id = :id AND version = :version AND role = 'evaluator'
          LIMIT 1"
    );
    $stmt->execute([
        ':id'      => (int) $eval['id'],
        ':version' => (int) $eval['version'],
    ]);
    return $stmt->fetchColumn() !== false;
}

// ---------------------------------------------------------------------------
// Form rules
// ---------------------------------------------------------------------------

/**
 * May this person see this form IN THE ROLE OF AN EVALUATOR?
 * Used for evaluators and for Department Heads who also evaluate.
 * Assigned to somebody: only that person. Not assigned: everybody of the
 * department (the department pool).
 */
function can_view_as_evaluator(array $user, array $eval): bool
{
    if (!in_array($eval['status'] ?? '', evaluator_visible_statuses(), true)) {
        return false;
    }
    // Already assigned to somebody: only that evaluator sees it.
    if (!empty($eval['evaluator_id'])) {
        return (int) $eval['evaluator_id'] === (int) $user['id'];
    }
    // Not assigned yet: it sits in the department pool.
    return !empty($eval['department_id'])
        && !empty($user['department_id'])
        && (int) $eval['department_id'] === (int) $user['department_id'];
}

/**
 * May this person open this form?
 *
 *  supplier    only their own forms
 *  mm_staff    all forms
 *  admin       all forms
 *  head_nurse  forms waiting with her (with_head_nurse) and forms she distributed
 *  evaluator   forms assigned to them, or forms in their department's pool
 *              (no evaluator chosen yet), from pending_evaluation on
 *  head        forms of their department and of departments where they are an
 *              alternate verifier, from pending_head on; plus forms where they
 *              work as the evaluator (same rule as an evaluator)
 */
function can_view_evaluation(array $user, array $eval): bool
{
    if (!user_is_usable($user)) {
        return false;
    }

    $userId = (int) $user['id'];
    $status = (string) ($eval['status'] ?? '');

    switch ($user['role']) {
        case 'admin':
        case 'mm_staff':
            return true;

        case 'supplier':
            return (int) ($eval['supplier_id'] ?? 0) === $userId;

        case 'head_nurse':
            return $status === 'with_head_nurse'
                || (int) ($eval['distributed_by'] ?? 0) === $userId;

        case 'evaluator':
            return can_view_as_evaluator($user, $eval);

        case 'head':
            if (can_view_as_evaluator($user, $eval)) {
                return true;
            }
            if (!in_array($status, head_visible_statuses(), true)) {
                return false;
            }
            return !empty($eval['department_id'])
                && in_array((int) $eval['department_id'], verifier_department_ids($user), true);
    }

    return false;
}

/**
 * May this person change the SUPPLIER-owned fields?
 * Only the supplier who owns the form, and only while it is a draft or was
 * returned to them.
 */
function can_edit_supplier_part(array $user, array $eval): bool
{
    // 'active' only: a supplier account that is not approved can never edit.
    return ($user['status'] ?? '') === 'active'
        && $user['role'] === 'supplier'
        && (int) ($eval['supplier_id'] ?? 0) === (int) $user['id']
        && in_array($eval['status'] ?? '', ['supplier_draft', 'returned_supplier'], true);
}

/**
 * May this person change the EVALUATOR-owned fields (Findings, Grounds,
 * Sections B and C)? Only the assigned person (an evaluator, or a Department
 * Head who evaluates), while the form is waiting for evaluation (or was
 * returned to them), and only until they sign this version.
 */
function can_edit_evaluator_part(array $user, array $eval): bool
{
    return user_is_usable($user)
        && in_array($user['role'], ['evaluator', 'head'], true)
        && !empty($eval['evaluator_id'])
        && (int) $eval['evaluator_id'] === (int) $user['id']
        && in_array($eval['status'] ?? '', ['pending_evaluation', 'returned_evaluator'], true)
        && !evaluator_has_signed($eval);
}

/**
 * May this person send the form to a department and an evaluator?
 * Only a Head Nurse, and only while the form is with the Head Nurse.
 */
function can_distribute(array $user, array $eval): bool
{
    return user_is_usable($user)
        && $user['role'] === 'head_nurse'
        && ($eval['status'] ?? '') === 'with_head_nurse';
}

/**
 * May this person claim this form from their department's pool?
 * An evaluator (or a Department Head who evaluates) of THAT department, while
 * the form is pending_evaluation and nobody has claimed it yet.
 */
function can_claim_evaluation(array $user, array $eval): bool
{
    return user_is_usable($user)
        && in_array($user['role'], ['evaluator', 'head'], true)
        && ($eval['status'] ?? '') === 'pending_evaluation'
        && empty($eval['evaluator_id'])
        && !empty($eval['department_id'])
        && !empty($user['department_id'])
        && (int) $eval['department_id'] === (int) $user['department_id'];
}

/**
 * May this person send the form back to the Head Nurse?
 * Only the assigned evaluator, while it is pending_evaluation and BEFORE any
 * signature on this version (the workflow table decides the status part).
 */
function can_send_back_evaluation(array $user, array $eval): bool
{
    return user_is_usable($user)
        && can_transition($eval, 'send_back', $user)
        && !evaluator_has_signed($eval);
}

/**
 * May this person verify and sign the form as Department Head?
 * Only a head of that department (or its alternate verifier), while the form is
 * pending_head, and NEVER when the head is the evaluator of that form.
 */
function can_verify_evaluation(array $user, array $eval): bool
{
    if (!user_is_usable($user) || $user['role'] !== 'head') {
        return false;
    }
    if (($eval['status'] ?? '') !== 'pending_head') {
        return false;
    }
    // A head never verifies their own evaluation.
    if ((int) ($eval['evaluator_id'] ?? 0) === (int) $user['id']) {
        return false;
    }

    return !empty($eval['department_id'])
        && in_array((int) $eval['department_id'], verifier_department_ids($user), true);
}
// ---------------------------------------------------------------------------
// Step 9: signing, returning and cancelling
// ---------------------------------------------------------------------------

/**
 * Has this person ever signed this form in the given role ('evaluator' or
 * 'head'), in ANY version? Used so nobody signs as both evaluator and head.
 */
function user_has_signed_as(array $user, array $eval, string $role): bool
{
    $stmt = db()->prepare(
        'SELECT 1 FROM signatures
          WHERE evaluation_id = :id AND user_id = :u AND role = :r
          LIMIT 1'
    );
    $stmt->execute([':id' => (int) $eval['id'], ':u' => (int) $user['id'], ':r' => $role]);
    return $stmt->fetchColumn() !== false;
}

/**
 * Is there at least one ACTIVE Department Head (or alternate verifier) for this
 * department other than $excludeUserId? Used when a head evaluates: their own
 * evaluation must go to somebody else.
 */
function department_has_other_verifier(int $departmentId, int $excludeUserId): bool
{
    $stmt = db()->prepare(
        "SELECT 1 FROM users u
          WHERE u.role = 'head' AND u.status = 'active' AND u.id <> :ex
            AND (u.department_id = :d1
                 OR u.id IN (SELECT a.head_user_id FROM alternate_verifiers a WHERE a.department_id = :d2))
          LIMIT 1"
    );
    $stmt->execute([':ex' => $excludeUserId, ':d1' => $departmentId, ':d2' => $departmentId]);
    return $stmt->fetchColumn() !== false;
}

/**
 * May this person sign the form as EVALUATOR right now?
 * Only the assigned evaluator (an evaluator, or a head who evaluates), from
 * pending_evaluation or returned_evaluator, not yet signed in this version,
 * and never somebody who already signed this form as head.
 * (The status part comes from the workflow table.)
 */
function can_sign_as_evaluator(array $user, array $eval): bool
{
    return user_is_usable($user)
        && in_array($user['role'], ['evaluator', 'head'], true)
        && can_transition($eval, 'sign_evaluator', $user)
        && !evaluator_has_signed($eval)
        && !user_has_signed_as($user, $eval, 'head');
}

/**
 * May this person sign the form as DEPARTMENT HEAD right now?
 * can_verify_evaluation() holds the department and "never your own evaluation"
 * rules. On top of that: never somebody who signed this form as evaluator.
 */
function can_sign_as_head(array $user, array $eval): bool
{
    return can_verify_evaluation($user, $eval)
        && can_transition($eval, 'sign_head', $user)
        && !user_has_signed_as($user, $eval, 'evaluator');
}

/**
 * Which signature could this person add now? 'evaluator', 'head' or null.
 * admin, mm_staff, head_nurse and supplier always get null.
 */
function signing_role_for(array $user, array $eval): ?string
{
    if (can_sign_as_evaluator($user, $eval)) {
        return 'evaluator';
    }
    if (can_sign_as_head($user, $eval)) {
        return 'head';
    }
    return null;
}

/**
 * May this person send the form back to the evaluator for correction?
 *   head      while the form is pending_head (and may verify it)
 *   mm_staff  while the form is completed
 */
function can_return_evaluation(array $user, array $eval): bool
{
    if (!user_is_usable($user)) {
        return false;
    }
    if ($user['role'] === 'head') {
        return can_verify_evaluation($user, $eval) && can_transition($eval, 'return_evaluator', $user);
    }
    if ($user['role'] === 'mm_staff') {
        return can_transition($eval, 'return_completed', $user);
    }
    return false;
}

/**
 * May this person cancel the form?
 *   mm_staff  at any step before completed
 *   supplier  only while the form is theirs to edit (draft or returned to them)
 */
function can_cancel_evaluation(array $user, array $eval): bool
{
    if (!user_is_usable($user)) {
        return false;
    }
    if ($user['role'] === 'mm_staff') {
        return can_transition($eval, 'cancel', $user);
    }
    if ($user['role'] === 'supplier') {
        return can_transition($eval, 'cancel_supplier', $user);
    }
    return false;
}