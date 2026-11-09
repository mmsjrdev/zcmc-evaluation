<?php
/**
 * admin/role-requests.php
 *
 * Department Head requests. A person who registers as a head is stored as
 * role 'evaluator', status 'pending', requested_role 'head' (Step 2).
 *   Approve: role becomes 'head', status 'active', tied to the chosen department.
 *   Reject:  status becomes 'rejected' (the remarks go to the audit log).
 * Only an administrator may open this page.
 */

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/domain/admin-helpers.php';
require_once __DIR__ . '/../app/views/partials/admin-ui.php';

$admin = require_role(['admin']);

// ---------------------------------------------------------------------------
// Approve or reject
// ---------------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    admin_check_csrf();

    $action = (string) ($_POST['action'] ?? '');
    $userId = (int) ($_POST['user_id'] ?? 0);

    admin_transaction(function (PDO $pdo) use ($action, $userId) {
        // Lock the request, so two clicks cannot both act on it.
        $stmt = $pdo->prepare(
            "SELECT id, full_name, email, employee_id
               FROM users
              WHERE id = :id AND role = 'evaluator' AND status = 'pending' AND requested_role = 'head'
              LIMIT 1
                FOR UPDATE"
        );
        $stmt->execute([':id' => $userId]);
        $person = $stmt->fetch();
        if (!$person) {
            throw new RuntimeException('That request was already handled or no longer exists.');
        }

        $who = $person['full_name'] . ' (user #' . $person['id'] . ', employee ID '
             . ($person['employee_id'] ?: 'none') . ')';

        if ($action === 'approve') {
            $deptId = (int) ($_POST['department_id'] ?? 0);
            $d = $pdo->prepare('SELECT name FROM departments WHERE id = :id AND active = 1 LIMIT 1');
            $d->execute([':id' => $deptId]);
            $deptName = $d->fetchColumn();
            if ($deptName === false) {
                throw new RuntimeException('Please choose an active department for this head.');
            }

            $up = $pdo->prepare(
                "UPDATE users SET role = 'head', status = 'active', department_id = :dept
                  WHERE id = :id AND status = 'pending'"
            );
            $up->execute([':dept' => $deptId, ':id' => $userId]);

            audit('head_approved', 'Approved ' . $who . ' as Department Head of ' . $deptName . '.');
            admin_notify($userId, 'Your Department Head request was approved for ' . $deptName . '.', 'common/dashboard.php');
            return $person['full_name'] . ' is now a Department Head of ' . $deptName . '.';
        }

        if ($action === 'reject') {
            $remarks = trim((string) ($_POST['remarks'] ?? ''));
            $length  = mb_strlen($remarks);
            if ($length < 3 || $length > 500) {
                throw new RuntimeException('Write remarks of 3 to 500 characters when you reject a request.');
            }

            $up = $pdo->prepare("UPDATE users SET status = 'rejected' WHERE id = :id AND status = 'pending'");
            $up->execute([':id' => $userId]);

            audit('head_rejected', 'Rejected ' . $who . '. Remarks: ' . $remarks);
            admin_notify($userId, 'Your Department Head request was not approved. Remarks: ' . $remarks);
            return 'The request of ' . $person['full_name'] . ' was rejected.';
        }

        throw new RuntimeException('Unknown action.');
    });

    admin_redirect('admin/role-requests.php');
}

// ---------------------------------------------------------------------------
// Page data
// ---------------------------------------------------------------------------
$requests = db()->query(
    "SELECT u.id, u.full_name, u.email, u.employee_id, u.department_id, u.`position`, u.created_at,
            d.name AS department_name, d.active AS department_active
       FROM users u
       LEFT JOIN departments d ON d.id = u.department_id
      WHERE u.role = 'evaluator' AND u.status = 'pending' AND u.requested_role = 'head'
      ORDER BY u.created_at ASC, u.id ASC"
)->fetchAll();

$departments = admin_department_names(true);

// ---------------------------------------------------------------------------
// Page
// ---------------------------------------------------------------------------
$pageTitle = 'Role requests';
require __DIR__ . '/../app/views/layout/header.php';
echo admin_ui_styles();

page_header('Role requests', 'People who asked to become a Department Head.');
?>

<?php if ($requests === []): ?>
    <?php empty_state('No pending requests', 'When someone registers as a Department Head, the request appears here.'); ?>
<?php else: ?>
    <div class="card ui-table-card overflow-hidden !p-0">
        <div class="overflow-x-auto">
            <table class="ui-table" style="min-width:980px">
                <thead class="<?= admin_thead_class() ?>">
                    <tr>
                        <th class="px-4 py-3">Person</th>
                        <th class="px-4 py-3">Employee ID</th>
                        <th class="px-4 py-3">Department asked for</th>
                        <th class="px-4 py-3">Position</th>
                        <th class="px-4 py-3">Requested</th>
                        <th class="px-4 py-3">Decision</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                <?php foreach ($requests as $r): ?>
                    <?php
                    $selected = isset($departments[(int) $r['department_id']]) ? (int) $r['department_id'] : 0;
                    ?>
                    <tr class="<?= admin_row_class('align-top') ?>">
                        <td class="px-4 py-3">
                            <p class="font-semibold text-slate-900"><?= e($r['full_name']) ?></p>
                            <p class="text-xs text-slate-500"><?= e($r['email']) ?></p>
                        </td>
                        <td class="px-4 py-3 text-slate-700"><?= e($r['employee_id'] ?: '-') ?></td>
                        <td class="px-4 py-3 text-slate-700">
                            <?= e($r['department_name'] ?: 'None chosen') ?>
                            <?php if ($r['department_name'] !== null && (int) $r['department_active'] !== 1): ?>
                                <span class="block text-xs text-amber-700">This department is inactive.</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3 text-slate-700"><?= e($r['position'] ?: '-') ?></td>
                        <td class="px-4 py-3 text-slate-700"><?= e(date('M j, Y', strtotime((string) $r['created_at']))) ?></td>
                        <td class="px-4 py-3">
                            <form method="post" class="ui-inline-form">
                                <?= admin_csrf_field() ?>
                                <input type="hidden" name="action" value="approve">
                                <input type="hidden" name="user_id" value="<?= (int) $r['id'] ?>">
                                <select name="department_id" required aria-label="Department for <?= e($r['full_name']) ?>"
                                        class="<?= admin_input_class() ?>">
                                    <?= admin_department_options($departments, $selected, 'Choose department...') ?>
                                </select>
                                <button type="submit" class="<?= admin_btn('primary', 'shrink-0') ?>">Approve</button>
                            </form>

                            <form method="post" class="mt-2 ui-inline-form"
                                  onsubmit="return confirm('Reject this request?');">
                                <?= admin_csrf_field() ?>
                                <input type="hidden" name="action" value="reject">
                                <input type="hidden" name="user_id" value="<?= (int) $r['id'] ?>">
                                <input type="text" name="remarks" required minlength="3" maxlength="500"
                                       placeholder="Remarks (required)" aria-label="Remarks for rejecting <?= e($r['full_name']) ?>"
                                       class="<?= admin_input_class() ?>">
                                <button type="submit"
                                        class="<?= admin_btn('danger', 'shrink-0') ?>">Reject</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/../app/views/layout/footer.php'; ?>