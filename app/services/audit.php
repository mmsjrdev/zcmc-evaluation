<?php
/**
 * app/services/audit.php
 *
 * audit('action_name', 'what happened') writes one line in the audit log for
 * the person who is signed in. (audit_write() in app/core/auth.php is the
 * lower-level function that takes the user id explicitly.)
 */

function audit(string $action, string $details = ''): void
{
    $user = current_user();
    audit_write($user ? (int) $user['id'] : null, $action, $details);
}
