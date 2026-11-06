<?php
/**
 * app/domain/mms-suppliers.php
 *
 * Supplier accounts for the MMS page mms/suppliers.php.
 * MMS may create supplier accounts ONLY. Other roles are created by the admin (Step 12).
 */

/** Every supplier account with the number of forms they own. */
function mms_suppliers_list(): array
{
    return db()->query(
        "SELECT u.id, u.business_name, u.full_name, u.email, u.contact_no, u.status,
                u.must_change_password, u.created_at,
                (SELECT COUNT(*) FROM evaluations e WHERE e.supplier_id = u.id) AS form_count
           FROM users u
          WHERE u.role = 'supplier'
          ORDER BY u.business_name, u.id"
    )->fetchAll();
}

/** Is this email already used by any account? */
function mms_email_taken(string $email): bool
{
    $stmt = db()->prepare('SELECT 1 FROM users WHERE email = :email LIMIT 1');
    $stmt->execute([':email' => $email]);
    return $stmt->fetchColumn() !== false;
}

/**
 * Check the "new supplier" form. Returns a list of messages (empty = all good).
 * $in holds business_name, full_name, email, contact_no (already trimmed).
 */
function mms_supplier_errors(array $in, string $password): array
{
    $errors = [];

    if ($in['business_name'] === '' || mb_strlen($in['business_name']) > 190) {
        $errors[] = 'Enter the business name (up to 190 characters).';
    }
    if ($in['full_name'] === '' || mb_strlen($in['full_name']) > 150) {
        $errors[] = 'Enter the full name of the contact person (up to 150 characters).';
    }
    if (!filter_var($in['email'], FILTER_VALIDATE_EMAIL) || mb_strlen($in['email']) > 190) {
        $errors[] = 'Enter a valid email address.';
    } elseif (mms_email_taken($in['email'])) {
        $errors[] = 'That email address already has an account.';
    }
    if (mb_strlen($in['contact_no']) > 30) {
        $errors[] = 'The contact number is too long (up to 30 characters).';
    }

    // password_problem() gives a message when the password is too weak.
    $problem = password_problem($password);
    if ($problem) {
        $errors[] = (string) $problem;
    }

    return $errors;
}

/**
 * Create a supplier account (role supplier, active, must change the password at
 * first sign-in). Returns the new user id. The password is never written to the audit log.
 */
function mms_create_supplier(array $user, array $in, string $password): int
{
    $stmt = db()->prepare(
        "INSERT INTO users (email, password_hash, full_name, contact_no, business_name, role, status, must_change_password)
         VALUES (:email, :hash, :full_name, :contact_no, :business_name, 'supplier', 'active', 1)"
    );
    $stmt->execute([
        ':email'         => $in['email'],
        ':hash'          => password_hash($password, PASSWORD_DEFAULT),
        ':full_name'     => $in['full_name'],
        ':contact_no'    => $in['contact_no'],
        ':business_name' => $in['business_name'],
    ]);
    $id = (int) db()->lastInsertId();

    audit('supplier_created', 'Supplier #' . $id . ' ' . $in['business_name'] . ' (' . $in['email'] . ') created by user #' . (int) $user['id']);
    return $id;
}

/**
 * Disable (active > disabled) or re-enable (disabled > active) a SUPPLIER account.
 * Returns true when the account was changed.
 */
function mms_set_supplier_status(array $user, int $supplierId, string $newStatus): bool
{
    if (!in_array($newStatus, ['active', 'disabled'], true)) {
        return false;
    }
    $oldStatus = $newStatus === 'disabled' ? 'active' : 'disabled';

    $stmt = db()->prepare(
        "UPDATE users SET status = :new
          WHERE id = :id AND role = 'supplier' AND status = :old"
    );
    $stmt->execute([':new' => $newStatus, ':id' => $supplierId, ':old' => $oldStatus]);
    if ($stmt->rowCount() !== 1) {
        return false;
    }

    audit($newStatus === 'disabled' ? 'supplier_disabled' : 'supplier_enabled', 'Supplier #' . $supplierId . ' by user #' . (int) $user['id']);
    return true;
}