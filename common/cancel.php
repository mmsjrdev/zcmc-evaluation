<?php
/**
 * common/cancel.php   (POST only)
 *
 * Cancels a form. Posted fields: id, reason (required), csrf token.
 *   - MMS: at any step before completed
 *   - supplier: only while the form is a draft or was returned to them
 * signing_cancel_form() checks the permission again, writes the reason into
 * the status history and the audit log, and does not touch any signature.
 * There is no screen here: the buttons live on common/evaluation-view.php and
 * on the supplier's form page.
 */

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/domain/signing.php';

$user = require_role(['mm_staff', 'supplier']);

// POST only: opening the address in a browser just goes back to the dashboard.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('common/dashboard.php');
}
csrf_check();

$id     = (int) ($_POST['id'] ?? 0);
$reason = (string) ($_POST['reason'] ?? '');

// Where to go afterwards.
$isSupplier = $user['role'] === 'supplier';
$backPage   = $isSupplier ? 'supplier/form-view.php?id=' . $id : 'common/evaluation-view.php?id=' . $id;

try {
    signing_cancel_form($user, $id, $reason);
    flash('success', 'The form was cancelled.');
    redirect($isSupplier ? 'supplier/forms.php' : $backPage);
} catch (WorkflowException $ex) {
    flash('error', $ex->getMessage());
    redirect($backPage);
}