<?php
/**
 * admin/users.php
 *
 * Find users, create accounts, and manage one account at a time:
 *   - change role or department
 *   - disable or re-enable
 *   - set a temporary password (shown once)
 *   - change the sign-in email (old and new email go to the audit log)
 *
 * Three views in one page:
 *   admin/users.php          the list with search
 *   admin/users.php?new=1    create an account
 *   admin/users.php?id=7     manage user 7
 *
 * Rules kept here:
 *   - the last active administrator can never be demoted or disabled
 *   - an admin cannot change their own role, department, email or status,
 *     and cannot set their own temporary password
 *   - supplier accounts keep the supplier role
 *   - pending Department Head requests are decided in Role Requests
 *   - this page never writes to the signatures table
 */

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/domain/admin-helpers.php';
require_once __DIR__ . '/../app/views/partials/admin-ui.php';

$admin   = require_role(['admin']);
$adminId = (int) $admin['id'];

// This page can show a temporary password, so the browser must not keep a copy.
header('Cache-Control: no-store');

// Roles an admin may create here (evaluators and heads register themselves).
$createRoles = ['mm_staff', 'head_nurse', 'supplier', 'admin'];
// Roles an existing (non-supplier) account may be switched to.
$changeRoles = ['mm_staff', 'head_nurse', 'evaluator', 'head', 'admin'];

$mode         = null;
$createErrors = [];
$createValues = [
    'role' => 'mm_staff', 'full_name' => '', 'email' => '', 'employee_id' => '',
    'department_id' => 0, 'position' => '', 'contact_no' => '', 'business_name' => '',
];

// ---------------------------------------------------------------------------
// Actions (POST)
// ---------------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    admin_check_csrf();

    $action   = (string) ($_POST['action'] ?? '');
    $targetId = (int) ($_POST['user_id'] ?? 0);

    // ---- Create an account ------------------------------------------------
    if ($action === 'create') {
        $v = [
            'role'          => (string) ($_POST['role'] ?? ''),
            'full_name'     => normalize_name((string) ($_POST['full_name'] ?? '')),
            'email'         => strtolower(trim((string) ($_POST['email'] ?? ''))),
            'employee_id'   => trim((string) ($_POST['employee_id'] ?? '')),
            'department_id' => (int) ($_POST['department_id'] ?? 0),
            'position'      => trim((string) ($_POST['position'] ?? '')),
            'contact_no'    => trim((string) ($_POST['contact_no'] ?? '')),
            'business_name' => trim((string) ($_POST['business_name'] ?? '')),
        ];
        $createValues = $v;
        $isSupplier   = $v['role'] === 'supplier';

        if (!in_array($v['role'], $createRoles, true)) {
            $createErrors[] = 'Choose a role.';
        }
        $nameLength = mb_strlen($v['full_name']);
        if ($nameLength < 2 || $nameLength > 150) {
            $createErrors[] = 'Enter the full name (2 to 150 characters).';
        }
        $emailProblem = admin_email_problem($v['email']);
        if ($emailProblem !== null) {
            $createErrors[] = $emailProblem;
        } else {
            $dup = db()->prepare('SELECT 1 FROM users WHERE email = :email LIMIT 1');
            $dup->execute([':email' => $v['email']]);
            if ($dup->fetchColumn() !== false) {
                $createErrors[] = 'That email is already used by another account.';
            }
        }
        if (mb_strlen($v['employee_id']) > 50) {
            $createErrors[] = 'The employee ID can have at most 50 characters.';
        }
        if (mb_strlen($v['position']) > 100) {
            $createErrors[] = 'The position can have at most 100 characters.';
        }
        if (mb_strlen($v['contact_no']) > 30) {
            $createErrors[] = 'The contact number can have at most 30 characters.';
        }
        if ($isSupplier && ($v['business_name'] === '' || mb_strlen($v['business_name']) > 190)) {
            $createErrors[] = 'A supplier needs a business name (up to 190 characters).';
        }

        $deptId = null;   // suppliers never get a department
        if (!$isSupplier && $v['department_id'] > 0) {
            if (array_key_exists($v['department_id'], admin_department_names(true))) {
                $deptId = $v['department_id'];
            } else {
                $createErrors[] = 'Choose an active department.';
            }
        }

        if ($createErrors === []) {
            $tempPassword = admin_temp_password();
            $newId        = 0;

            $ok = admin_transaction(function (PDO $pdo) use ($v, $isSupplier, $deptId, $tempPassword, &$newId) {
                $stmt = $pdo->prepare(
                    "INSERT INTO users
                        (email, password_hash, full_name, employee_id, department_id, `position`,
                         contact_no, business_name, role, status, requested_role, must_change_password)
                     VALUES
                        (:email, :hash, :name, :emp, :dept, :pos,
                         :contact, :biz, :role, 'active', NULL, 1)"
                );
                $stmt->execute([
                    ':email'   => $v['email'],
                    ':hash'    => password_hash($tempPassword, PASSWORD_DEFAULT),
                    ':name'    => $v['full_name'],
                    ':emp'     => $v['employee_id'] !== '' ? $v['employee_id'] : null,
                    ':dept'    => $deptId,
                    ':pos'     => $v['position'],
                    ':contact' => $v['contact_no'],
                    ':biz'     => $isSupplier ? $v['business_name'] : null,
                    ':role'    => $v['role'],
                ]);
                $newId = (int) $pdo->lastInsertId();

                audit('user_created', 'Created ' . role_label($v['role']) . ' account for ' . $v['full_name']
                    . ' <' . $v['email'] . '> (user #' . $newId . ').');
                return 'Account created for ' . $v['full_name'] . '.';
            });

            if ($ok) {
                $_SESSION['admin_temp_password'] = [
                    'name' => $v['full_name'], 'email' => $v['email'], 'password' => $tempPassword,
                ];
                admin_redirect('admin/users.php?id=' . $newId);
            }
        }
        $mode = 'create';   // show the form again with what was typed

    } else {
        // ---- Change role or department ------------------------------------
        if ($action === 'change_role') {
            $newRole = (string) ($_POST['role'] ?? '');
            $newDept = (int) ($_POST['department_id'] ?? 0);

            admin_transaction(function (PDO $pdo) use ($targetId, $adminId, $newRole, $newDept, $changeRoles) {
                $u = admin_load_user_locked($pdo, $targetId);

                if ((int) $u['id'] === $adminId) {
                    throw new RuntimeException('You cannot change your own role or department.');
                }
                if (!in_array($u['status'], ['active', 'disabled'], true)) {
                    throw new RuntimeException('Pending and rejected accounts are handled in Role Requests.');
                }
                if ($u['role'] === 'supplier') {
                    throw new RuntimeException('Supplier accounts keep the supplier role.');
                }
                if (!in_array($newRole, $changeRoles, true)) {
                    throw new RuntimeException('Choose a valid role.');
                }

                $oldDeptId = $u['department_id'] === null ? null : (int) $u['department_id'];
                $newDeptId = null;
                if ($newDept > 0) {
                    // An inactive department is allowed only when the person is already in it.
                    $d = $pdo->prepare('SELECT 1 FROM departments WHERE id = :id AND (active = 1 OR id = :cur) LIMIT 1');
                    $d->execute([':id' => $newDept, ':cur' => (int) $oldDeptId]);
                    if ($d->fetchColumn() === false) {
                        throw new RuntimeException('Choose an active department.');
                    }
                    $newDeptId = $newDept;
                }
                if (in_array($newRole, ['evaluator', 'head'], true) && $newDeptId === null) {
                    throw new RuntimeException('Evaluators and Department Heads need a department.');
                }
                if ($newRole === $u['role'] && $newDeptId === $oldDeptId) {
                    throw new RuntimeException('There was nothing to change.');
                }
                if ($u['role'] === 'admin' && $newRole !== 'admin' && admin_is_last_active_admin($pdo, (int) $u['id'])) {
                    throw new RuntimeException('This is the last active administrator, so the role cannot be changed.');
                }

                // A head who stops being a head also stops being an alternate verifier.
                $removedAlternates = 0;
                if ($u['role'] === 'head' && $newRole !== 'head') {
                    $del = $pdo->prepare('DELETE FROM alternate_verifiers WHERE head_user_id = :id');
                    $del->execute([':id' => (int) $u['id']]);
                    $removedAlternates = $del->rowCount();
                }

                $oldDeptName = admin_department_name($pdo, $oldDeptId);
                $newDeptName = admin_department_name($pdo, $newDeptId);
                $openForms   = admin_open_forms_count((int) $u['id']);

                $up = $pdo->prepare('UPDATE users SET role = :role, department_id = :dept WHERE id = :id');
                $up->execute([':role' => $newRole, ':dept' => $newDeptId, ':id' => (int) $u['id']]);

                $details = 'User #' . $u['id'] . ' (' . $u['full_name'] . '): role ' . $u['role'] . ' to ' . $newRole
                         . '; department ' . $oldDeptName . ' to ' . $newDeptName . '.';
                if ($removedAlternates > 0) {
                    $details .= ' Removed ' . $removedAlternates . ' alternate-verifier assignment(s).';
                }
                if ($openForms > 0 && !in_array($newRole, ['evaluator', 'head'], true)) {
                    $details .= ' Warning: ' . $openForms . ' form(s) are still assigned to this person as evaluator.';
                }
                audit('user_role_changed', $details);
                admin_notify((int) $u['id'], 'Your account role is now ' . role_label($newRole) . '.', 'common/dashboard.php');

                return 'Role and department saved.';
            });

        // ---- Disable or re-enable -----------------------------------------
        } elseif ($action === 'disable' || $action === 'enable') {
            admin_transaction(function (PDO $pdo) use ($action, $targetId, $adminId) {
                $u = admin_load_user_locked($pdo, $targetId);

                if ($action === 'disable') {
                    if ((int) $u['id'] === $adminId) {
                        throw new RuntimeException('You cannot disable your own account. Ask another administrator.');
                    }
                    if ($u['status'] !== 'active') {
                        throw new RuntimeException('Only active accounts can be disabled. Decide pending requests in Role Requests.');
                    }
                    if ($u['role'] === 'admin' && admin_is_last_active_admin($pdo, (int) $u['id'])) {
                        throw new RuntimeException('This is the last active administrator, so the account cannot be disabled.');
                    }

                    $openForms = admin_open_forms_count((int) $u['id']);
                    $up = $pdo->prepare("UPDATE users SET status = 'disabled' WHERE id = :id AND status = 'active'");
                    $up->execute([':id' => (int) $u['id']]);

                    $details = 'Disabled ' . $u['full_name'] . ' (user #' . $u['id'] . ', ' . $u['role'] . ').';
                    if ($openForms > 0) {
                        $details .= ' Warning: ' . $openForms . ' form(s) are still assigned to this person as evaluator.';
                    }
                    audit('user_disabled', $details);
                    return $u['full_name'] . ' was disabled and is signed out.';
                }

                if ($u['status'] !== 'disabled') {
                    throw new RuntimeException('Only disabled accounts can be re-enabled.');
                }
                $up = $pdo->prepare("UPDATE users SET status = 'active' WHERE id = :id AND status = 'disabled'");
                $up->execute([':id' => (int) $u['id']]);
                audit('user_enabled', 'Re-enabled ' . $u['full_name'] . ' (user #' . $u['id'] . ', ' . $u['role'] . ').');
                return $u['full_name'] . ' can sign in again.';
            });

        // ---- Temporary password -------------------------------------------
        } elseif ($action === 'temp_password') {
            $temp = admin_temp_password();
            $info = [];

            $ok = admin_transaction(function (PDO $pdo) use ($targetId, $adminId, $temp, &$info) {
                $u = admin_load_user_locked($pdo, $targetId);
                if ((int) $u['id'] === $adminId) {
                    throw new RuntimeException('For your own account use "Change password" in the menu.');
                }

                $up = $pdo->prepare('UPDATE users SET password_hash = :hash, must_change_password = 1 WHERE id = :id');
                $up->execute([':hash' => password_hash($temp, PASSWORD_DEFAULT), ':id' => (int) $u['id']]);

                // A locked-out person should be able to try the new password right away.
                clear_failed_attempts($u['email']);

                // The audit text never contains the password.
                audit('user_password_reset', 'Set a temporary password for ' . $u['full_name'] . ' (user #' . $u['id'] . ').');

                $info = ['name' => $u['full_name'], 'email' => $u['email'], 'password' => $temp];
                return 'A temporary password was set. Copy it now: it is shown only once.';
            });
            if ($ok) {
                $_SESSION['admin_temp_password'] = $info;
            }

        // ---- Change the sign-in email -------------------------------------
        } elseif ($action === 'change_email') {
            $newEmail  = strtolower(trim((string) ($_POST['new_email'] ?? '')));
            $confirmed = ($_POST['confirmed'] ?? '') === '1';

            admin_transaction(function (PDO $pdo) use ($targetId, $adminId, $newEmail, $confirmed) {
                $u = admin_load_user_locked($pdo, $targetId);
                if ((int) $u['id'] === $adminId) {
                    throw new RuntimeException('You cannot change your own sign-in email.');
                }
                if (!$confirmed) {
                    throw new RuntimeException('Tick the box to confirm that you checked the identity of this person.');
                }
                $problem = admin_email_problem($newEmail);
                if ($problem !== null) {
                    throw new RuntimeException($problem);
                }
                if (strcasecmp($u['email'], $newEmail) === 0) {
                    throw new RuntimeException('That is already the sign-in email.');
                }
                $dup = $pdo->prepare('SELECT 1 FROM users WHERE email = :email AND id <> :id LIMIT 1');
                $dup->execute([':email' => $newEmail, ':id' => (int) $u['id']]);
                if ($dup->fetchColumn() !== false) {
                    throw new RuntimeException('That email is already used by another account.');
                }

                $up = $pdo->prepare('UPDATE users SET email = :email WHERE id = :id');
                $up->execute([':email' => $newEmail, ':id' => (int) $u['id']]);

                audit('user_email_changed', 'User #' . $u['id'] . ' (' . $u['full_name'] . '): sign-in email changed from '
                    . $u['email'] . ' to ' . $newEmail . '. Identity confirmed by the administrator.');
                admin_notify((int) $u['id'], 'Your sign-in email was changed to ' . $newEmail . '.');
                return 'The sign-in email was changed.';
            });

        } else {
            flash('error', 'Unknown action.');
        }

        admin_redirect('admin/users.php?id=' . $targetId);
    }
}

// A temporary password is shown once: take it out of the session right away.
$tempInfo = $_SESSION['admin_temp_password'] ?? null;
unset($_SESSION['admin_temp_password']);

$viewId = (int) ($_GET['id'] ?? 0);
if ($mode === null) {
    $mode = isset($_GET['new']) ? 'create' : ($viewId > 0 ? 'manage' : 'list');
}

// ---------------------------------------------------------------------------
// Data for the list view
// ---------------------------------------------------------------------------
$rows = [];
$total = 0;
$pages = 1;
$page = 1;
$filters = [];
$q = $fRole = $fStatus = '';

if ($mode === 'list') {
    $q       = trim((string) ($_GET['q'] ?? ''));
    $fRole   = (string) ($_GET['role'] ?? '');
    $fStatus = (string) ($_GET['status'] ?? '');
    $perPage = 20;

    $where  = [];
    $params = [];
    if ($q !== '') {
        $like = admin_like($q);
        $where[] = '(u.full_name LIKE :q1 OR u.email LIKE :q2 OR u.employee_id LIKE :q3)';
        $params[':q1'] = $params[':q2'] = $params[':q3'] = $like;
        $filters['q'] = $q;
    }
    if (in_array($fRole, all_roles(), true)) {
        $where[] = 'u.role = :role';
        $params[':role'] = $fRole;
        $filters['role'] = $fRole;
    } else {
        $fRole = '';
    }
    if (in_array($fStatus, ['active', 'pending', 'disabled', 'rejected'], true)) {
        $where[] = 'u.status = :status';
        $params[':status'] = $fStatus;
        $filters['status'] = $fStatus;
    } else {
        $fStatus = '';
    }
    $whereSql = $where === [] ? '1 = 1' : implode(' AND ', $where);

    $count = db()->prepare("SELECT COUNT(*) FROM users u WHERE $whereSql");
    $count->execute($params);
    $total = (int) $count->fetchColumn();
    $pages = max(1, (int) ceil($total / $perPage));
    $page  = min($pages, max(1, (int) ($_GET['page'] ?? 1)));

    $list = db()->prepare(
        "SELECT u.id, u.full_name, u.email, u.role, u.status, u.requested_role, d.name AS department_name
           FROM users u
           LEFT JOIN departments d ON d.id = u.department_id
          WHERE $whereSql
          ORDER BY u.full_name ASC, u.id ASC
          LIMIT " . (int) $perPage . ' OFFSET ' . (int) (($page - 1) * $perPage)
    );
    $list->execute($params);
    $rows = $list->fetchAll();
}

// ---------------------------------------------------------------------------
// Data for the manage view
// ---------------------------------------------------------------------------
$u = null;
if ($mode === 'manage') {
    $stmt = db()->prepare(
        'SELECT u.id, u.email, u.full_name, u.employee_id, u.department_id, u.`position`, u.contact_no,
                u.business_name, u.role, u.status, u.requested_role, u.must_change_password, u.created_at,
                d.name AS department_name
           FROM users u
           LEFT JOIN departments d ON d.id = u.department_id
          WHERE u.id = :id
          LIMIT 1'
    );
    $stmt->execute([':id' => $viewId]);
    $u = $stmt->fetch();
    if (!$u) {
        flash('error', 'That user does not exist.');
        admin_redirect('admin/users.php');
    }

    $isSelf    = (int) $u['id'] === $adminId;
    $openForms = in_array($u['role'], ['evaluator', 'head'], true) ? admin_open_forms_count((int) $u['id']) : 0;

    $altDepartments = [];
    if ($u['role'] === 'head') {
        $alt = db()->prepare(
            'SELECT d.name FROM alternate_verifiers a
               JOIN departments d ON d.id = a.department_id
              WHERE a.head_user_id = :id ORDER BY d.name'
        );
        $alt->execute([':id' => (int) $u['id']]);
        $altDepartments = $alt->fetchAll(PDO::FETCH_COLUMN);
    }

    // Department choices: active ones, plus the person's current one even if it is inactive.
    $deptChoices = admin_department_names(true);
    if (!empty($u['department_id']) && !isset($deptChoices[(int) $u['department_id']])) {
        $deptChoices[(int) $u['department_id']] = $u['department_name'] . ' (inactive)';
    }
}

// ---------------------------------------------------------------------------
// Page
// ---------------------------------------------------------------------------
$pageTitle = 'Users';
require __DIR__ . '/../app/views/layout/header.php';
echo admin_ui_styles();

if ($mode === 'create') {
    page_header('New account', 'For Material Management staff, Head Nurses, suppliers and administrators.');
} elseif ($mode === 'manage') {
    page_header($u['full_name'], role_label($u['role']) . ' · ' . $u['email']);
} else {
    page_header('Users', 'Search accounts, create new ones and manage access.');
}
?>

<?php if (is_array($tempInfo)): ?>
    <div class="mb-6 rounded-xl border-2 border-amber-300 bg-amber-50 p-4 sm:p-5" role="alert">
        <p class="text-sm font-semibold text-amber-900">
            Temporary password for <?= e($tempInfo['name']) ?> (<?= e($tempInfo['email']) ?>)
        </p>
        <p class="mt-2 select-all rounded-lg border border-amber-200 bg-white px-4 py-3 font-mono text-lg tracking-wider text-slate-900"><?= e($tempInfo['password']) ?></p>
        <p class="mt-2 text-xs text-amber-800">
            This is shown only once. Give it to the person privately. They must choose a new password the first time they sign in.
        </p>
    </div>
<?php endif; ?>

<?php if ($mode === 'list'): ?>

    <form method="get" class="mb-4 grid gap-3 sm:grid-cols-[1fr_200px_180px_auto]">
        <div>
            <label for="q" class="sr-only">Search</label>
            <input type="search" id="q" name="q" value="<?= e($q) ?>" placeholder="Search name, email or employee ID"
                   class="<?= admin_input_class() ?>">
        </div>
        <div>
            <label for="role" class="sr-only">Role</label>
            <select id="role" name="role" class="<?= admin_input_class() ?>">
                <option value="">All roles</option>
                <?php foreach (all_roles() as $r): ?>
                    <option value="<?= e($r) ?>"<?= $fRole === $r ? ' selected' : '' ?>><?= e(role_label($r)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label for="status" class="sr-only">Status</label>
            <select id="status" name="status" class="<?= admin_input_class() ?>">
                <option value="">All statuses</option>
                <?php foreach (['active', 'pending', 'disabled', 'rejected'] as $s): ?>
                    <option value="<?= e($s) ?>"<?= $fStatus === $s ? ' selected' : '' ?>><?= e(ucfirst($s)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="flex gap-2">
            <button type="submit" class="<?= admin_btn('primary') ?>">Search</button>
            <a href="<?= e(url('admin/users.php?new=1')) ?>"
               class="<?= admin_btn('secondary') ?>">New account</a>
        </div>
    </form>

    <?php if ($rows === []): ?>
        <?php empty_state('No users found', 'Try a different search or clear the filters.'); ?>
    <?php else: ?>
        <p class="mb-2 text-sm text-slate-600"><?= (int) $total ?> user<?= $total === 1 ? '' : 's' ?></p>
        <div class="card ui-table-card overflow-hidden !p-0">
            <div class="overflow-x-auto">
                <table class="ui-table" style="min-width:720px">
                    <thead class="<?= admin_thead_class() ?>">
                        <tr>
                            <th class="px-4 py-3">Person</th>
                            <th class="px-4 py-3">Role</th>
                            <th class="px-4 py-3">Department</th>
                            <th class="px-4 py-3">Status</th>
                            <th class="px-4 py-3"><span class="sr-only">Manage</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                    <?php foreach ($rows as $r): ?>
                        <tr class="<?= admin_row_class() ?>">
                            <td class="px-4 py-3">
                                <p class="font-semibold text-slate-900"><?= e($r['full_name']) ?></p>
                                <p class="text-xs text-slate-500"><?= e($r['email']) ?></p>
                            </td>
                            <td class="px-4 py-3 text-slate-700">
                                <?= e(role_label($r['role'])) ?>
                                <?php if ($r['status'] === 'pending' && $r['requested_role'] === 'head'): ?>
                                    <span class="block text-xs text-amber-700">Asked to be Department Head</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-3 text-slate-700"><?= e($r['department_name'] ?: '-') ?></td>
                            <td class="px-4 py-3"><?= admin_user_status_pill($r['status']) ?></td>
                            <td class="px-4 py-3 text-right">
                                <a href="<?= e(url('admin/users.php?id=' . (int) $r['id'])) ?>"
                                   class="<?= admin_btn('secondary', 'ui-btn-sm') ?>">Manage</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php admin_pager($page, $pages, $filters, 'admin/users.php'); ?>
    <?php endif; ?>

<?php elseif ($mode === 'create'): ?>

    <?php if ($createErrors !== []): ?>
        <div class="mb-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800" role="alert">
            <ul class="list-disc space-y-1 pl-5">
                <?php foreach ($createErrors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form method="post" class="card space-y-5">
        <?= admin_csrf_field() ?>
        <input type="hidden" name="action" value="create">

        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label for="role" class="<?= admin_label_class() ?>">Role</label>
                <select id="role" name="role" required class="<?= admin_input_class() ?>">
                    <?php foreach ($createRoles as $r): ?>
                        <option value="<?= e($r) ?>"<?= $createValues['role'] === $r ? ' selected' : '' ?>><?= e(role_label($r)) ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="mt-1 text-xs text-slate-500">Evaluators and Department Heads register themselves.</p>
            </div>
            <div>
                <label for="full_name" class="<?= admin_label_class() ?>">Full name</label>
                <input type="text" id="full_name" name="full_name" required maxlength="150"
                       value="<?= e($createValues['full_name']) ?>" class="<?= admin_input_class() ?>">
            </div>
            <div>
                <label for="email" class="<?= admin_label_class() ?>">Sign-in email</label>
                <input type="email" id="email" name="email" required maxlength="190"
                       value="<?= e($createValues['email']) ?>" class="<?= admin_input_class() ?>">
            </div>
            <div>
                <label for="employee_id" class="<?= admin_label_class() ?>">Employee ID (optional)</label>
                <input type="text" id="employee_id" name="employee_id" maxlength="50"
                       value="<?= e($createValues['employee_id']) ?>" class="<?= admin_input_class() ?>">
            </div>
            <div>
                <label for="department_id" class="<?= admin_label_class() ?>">Department (optional, not used for suppliers)</label>
                <select id="department_id" name="department_id" class="<?= admin_input_class() ?>">
                    <?= admin_department_options(admin_department_names(true), (int) $createValues['department_id'], 'No department') ?>
                </select>
            </div>
            <div>
                <label for="position" class="<?= admin_label_class() ?>">Position (optional)</label>
                <input type="text" id="position" name="position" maxlength="100"
                       value="<?= e($createValues['position']) ?>" class="<?= admin_input_class() ?>">
            </div>
            <div>
                <label for="contact_no" class="<?= admin_label_class() ?>">Contact number (optional)</label>
                <input type="text" id="contact_no" name="contact_no" maxlength="30"
                       value="<?= e($createValues['contact_no']) ?>" class="<?= admin_input_class() ?>">
            </div>
            <div>
                <label for="business_name" class="<?= admin_label_class() ?>">Business name (suppliers only)</label>
                <input type="text" id="business_name" name="business_name" maxlength="190"
                       value="<?= e($createValues['business_name']) ?>" class="<?= admin_input_class() ?>">
            </div>
        </div>

        <p class="text-sm text-slate-600">
            A temporary password is made for the account and shown to you once. The person must change it at the first sign-in.
        </p>
        <div class="flex flex-col gap-2 sm:flex-row">
            <button type="submit" class="<?= admin_btn('primary') ?>">Create account</button>
            <a href="<?= e(url('admin/users.php')) ?>"
               class="<?= admin_btn('secondary') ?>">Cancel</a>
        </div>
    </form>

<?php else: /* manage */ ?>

    <p class="mb-4">
        <a href="<?= e(url('admin/users.php')) ?>" class="text-sm font-semibold text-brand-700 hover:underline">&larr; All users</a>
    </p>

    <section class="card mb-6" aria-label="Account details">
        <dl class="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2 lg:grid-cols-3">
            <div><dt class="text-xs uppercase tracking-wide text-slate-500">Status</dt><dd class="mt-1"><?= admin_user_status_pill($u['status']) ?></dd></div>
            <div><dt class="text-xs uppercase tracking-wide text-slate-500">Role</dt><dd class="mt-1 text-slate-900"><?= e(role_label($u['role'])) ?></dd></div>
            <div><dt class="text-xs uppercase tracking-wide text-slate-500">Department</dt><dd class="mt-1 text-slate-900"><?= e($u['department_name'] ?: '-') ?></dd></div>
            <div><dt class="text-xs uppercase tracking-wide text-slate-500">Employee ID</dt><dd class="mt-1 text-slate-900"><?= e($u['employee_id'] ?: '-') ?></dd></div>
            <div><dt class="text-xs uppercase tracking-wide text-slate-500">Position</dt><dd class="mt-1 text-slate-900"><?= e($u['position'] ?: '-') ?></dd></div>
            <div><dt class="text-xs uppercase tracking-wide text-slate-500">Contact</dt><dd class="mt-1 text-slate-900"><?= e($u['contact_no'] ?: '-') ?></dd></div>
            <?php if ($u['business_name'] !== null && $u['business_name'] !== ''): ?>
                <div><dt class="text-xs uppercase tracking-wide text-slate-500">Business</dt><dd class="mt-1 text-slate-900"><?= e($u['business_name']) ?></dd></div>
            <?php endif; ?>
            <div><dt class="text-xs uppercase tracking-wide text-slate-500">Must change password</dt><dd class="mt-1 text-slate-900"><?= (int) $u['must_change_password'] === 1 ? 'Yes, at the next sign-in' : 'No' ?></dd></div>
            <div><dt class="text-xs uppercase tracking-wide text-slate-500">Created</dt><dd class="mt-1 text-slate-900"><?= e(date('M j, Y', strtotime((string) $u['created_at']))) ?></dd></div>
            <?php if ($altDepartments !== []): ?>
                <div class="sm:col-span-2 lg:col-span-3">
                    <dt class="text-xs uppercase tracking-wide text-slate-500">Alternate verifier for</dt>
                    <dd class="mt-1 text-slate-900"><?= e(implode(', ', $altDepartments)) ?>
                        (<a href="<?= e(url('admin/verifiers.php')) ?>" class="font-semibold text-brand-700 hover:underline">change</a>)</dd>
                </div>
            <?php endif; ?>
        </dl>
        <?php if ($u['status'] === 'pending'): ?>
            <p class="mt-4 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900">
                This person asked to become a Department Head.
                <a href="<?= e(url('admin/role-requests.php')) ?>" class="font-semibold underline">Decide in Role Requests</a>.
            </p>
        <?php endif; ?>
        <?php if ($openForms > 0): ?>
            <p class="mt-4 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900">
                <?= (int) $openForms ?> form<?= $openForms === 1 ? ' is' : 's are' ?> waiting on this person as evaluator.
                They stay stuck if the account is disabled or the role is changed.
            </p>
        <?php endif; ?>
    </section>

    <?php if ($isSelf): ?>
        <p class="mb-6 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700">
            This is your own account. You cannot change your role, department, email or status here, and you cannot set your own temporary password.
            Use "Change password" in the menu.
        </p>
    <?php endif; ?>

    <?php if (!$isSelf): ?>

        <section class="card mb-6" aria-label="Role and department">
            <h2 class="text-base font-semibold text-slate-900">Role and department</h2>
            <?php if ($u['role'] === 'supplier'): ?>
                <p class="mt-2 text-sm text-slate-600">Supplier accounts keep the supplier role and have no department.</p>
            <?php elseif (!in_array($u['status'], ['active', 'disabled'], true)): ?>
                <p class="mt-2 text-sm text-slate-600">Pending and rejected accounts are handled in Role Requests.</p>
            <?php else: ?>
                <form method="post" class="mt-4 space-y-4">
                    <?= admin_csrf_field() ?>
                    <input type="hidden" name="action" value="change_role">
                    <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label for="m_role" class="<?= admin_label_class() ?>">Role</label>
                            <select id="m_role" name="role" class="<?= admin_input_class() ?>">
                                <?php foreach ($changeRoles as $r): ?>
                                    <option value="<?= e($r) ?>"<?= $u['role'] === $r ? ' selected' : '' ?>><?= e(role_label($r)) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label for="m_dept" class="<?= admin_label_class() ?>">Department</label>
                            <select id="m_dept" name="department_id" class="<?= admin_input_class() ?>">
                                <?= admin_department_options($deptChoices, (int) ($u['department_id'] ?? 0), 'No department') ?>
                            </select>
                            <p class="mt-1 text-xs text-slate-500">Required for Evaluator and Department Head.</p>
                        </div>
                    </div>
                    <?php if ($u['role'] === 'head'): ?>
                        <p class="text-xs text-slate-500">Changing a head to another role also removes their alternate-verifier assignments.</p>
                    <?php endif; ?>
                    <button type="submit" class="<?= admin_btn('primary') ?>">Save role and department</button>
                </form>
            <?php endif; ?>
        </section>

        <section class="card mb-6" aria-label="Access">
            <h2 class="text-base font-semibold text-slate-900">Access</h2>
            <?php if ($u['status'] === 'active'): ?>
                <p class="mt-2 text-sm text-slate-600">A disabled account is signed out at once and cannot sign in until you re-enable it.</p>
                <form method="post" class="mt-4" onsubmit="return confirm('Disable this account?');">
                    <?= admin_csrf_field() ?>
                    <input type="hidden" name="action" value="disable">
                    <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
                    <button type="submit" class="<?= admin_btn('danger') ?>">Disable account</button>
                </form>
            <?php elseif ($u['status'] === 'disabled'): ?>
                <p class="mt-2 text-sm text-slate-600">This account is disabled.</p>
                <form method="post" class="mt-4">
                    <?= admin_csrf_field() ?>
                    <input type="hidden" name="action" value="enable">
                    <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
                    <button type="submit" class="<?= admin_btn('primary') ?>">Re-enable account</button>
                </form>
            <?php else: ?>
                <p class="mt-2 text-sm text-slate-600">Only active or disabled accounts can be switched here.</p>
            <?php endif; ?>
        </section>

        <section class="card mb-6" aria-label="Temporary password">
            <h2 class="text-base font-semibold text-slate-900">Temporary password</h2>
            <p class="mt-2 text-sm text-slate-600">
                Replaces the current password. You see the new one once; the person must change it at the next sign-in.
            </p>
            <form method="post" class="mt-4" onsubmit="return confirm('Set a new temporary password for this person?');">
                <?= admin_csrf_field() ?>
                <input type="hidden" name="action" value="temp_password">
                <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
                <button type="submit" class="<?= admin_btn('secondary') ?>">Set temporary password</button>
            </form>
        </section>

        <section class="card mb-6" aria-label="Sign-in email">
            <h2 class="text-base font-semibold text-slate-900">Sign-in email</h2>
            <p class="mt-2 text-sm text-slate-600">Change it only after you have confirmed who the person is. The old and the new email are written to the audit log.</p>
            <form method="post" class="mt-4 space-y-4">
                <?= admin_csrf_field() ?>
                <input type="hidden" name="action" value="change_email">
                <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
                <div class="max-w-md">
                    <label for="new_email" class="<?= admin_label_class() ?>">New email</label>
                    <input type="email" id="new_email" name="new_email" required maxlength="190" class="<?= admin_input_class() ?>">
                </div>
                <label class="flex items-start gap-3 text-sm text-slate-700">
                    <input type="checkbox" name="confirmed" value="1" required class="mt-0.5 h-5 w-5 rounded border-slate-300">
                    <span>I have confirmed the identity of this person.</span>
                </label>
                <button type="submit" class="<?= admin_btn('primary') ?>">Change email</button>
            </form>
        </section>

    <?php endif; ?>

<?php endif; ?>

<?php require __DIR__ . '/../app/views/layout/footer.php'; ?>