<?php
/**
 * admin/verifiers.php
 *
 * Alternate verifiers: an ACTIVE Department Head who may also verify and sign
 * forms of ANOTHER department (table: alternate_verifiers).
 *
 * The rule "a head never verifies their own evaluation" is not decided here.
 * It stays in can_verify_evaluation() in app/core/permissions.php.
 * A head who is disabled, or whose assignment is removed, stops verifying on
 * the next page they open, because permissions are read from the database.
 */

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/domain/admin-helpers.php';
require_once __DIR__ . '/../app/views/partials/admin-ui.php';

$admin = require_role(['admin']);

// ---------------------------------------------------------------------------
// Actions (POST)
// ---------------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    admin_check_csrf();

    $action = (string) ($_POST['action'] ?? '');
    $deptId = (int) ($_POST['department_id'] ?? 0);
    $headId = (int) ($_POST['head_user_id'] ?? 0);

    admin_transaction(function (PDO $pdo) use ($action, $deptId, $headId) {

        if ($action === 'add') {
            $d = $pdo->prepare('SELECT name FROM departments WHERE id = :id AND active = 1 LIMIT 1');
            $d->execute([':id' => $deptId]);
            $deptName = $d->fetchColumn();
            if ($deptName === false) {
                throw new RuntimeException('Choose an active department.');
            }

            // Only an ACTIVE Department Head account may be an alternate verifier.
            $h = $pdo->prepare(
                "SELECT id, full_name, department_id FROM users
                  WHERE id = :id AND role = 'head' AND status = 'active'
                  LIMIT 1 FOR UPDATE"
            );
            $h->execute([':id' => $headId]);
            $head = $h->fetch();
            if (!$head) {
                throw new RuntimeException('Only active Department Head accounts can be alternate verifiers.');
            }
            if ((int) $head['department_id'] === $deptId) {
                throw new RuntimeException($head['full_name'] . ' already verifies this department as its own head.');
            }

            $x = $pdo->prepare('SELECT 1 FROM alternate_verifiers WHERE department_id = :d AND head_user_id = :h LIMIT 1');
            $x->execute([':d' => $deptId, ':h' => $headId]);
            if ($x->fetchColumn() !== false) {
                throw new RuntimeException($head['full_name'] . ' is already an alternate verifier here.');
            }

            $pdo->prepare('INSERT INTO alternate_verifiers (department_id, head_user_id) VALUES (:d, :h)')
                ->execute([':d' => $deptId, ':h' => $headId]);

            audit('alternate_verifier_added', $head['full_name'] . ' (user #' . $headId . ') can now verify for '
                . $deptName . ' (department #' . $deptId . ').');
            admin_notify($headId, 'You can now also verify forms of ' . $deptName . ' as an alternate verifier.', 'head/for-signature.php');
            return $head['full_name'] . ' is now an alternate verifier for ' . $deptName . '.';
        }

        if ($action === 'remove') {
            $stmt = $pdo->prepare(
                'SELECT d.name AS department_name, u.full_name
                   FROM alternate_verifiers a
                   JOIN departments d ON d.id = a.department_id
                   JOIN users u ON u.id = a.head_user_id
                  WHERE a.department_id = :d AND a.head_user_id = :h
                  LIMIT 1 FOR UPDATE'
            );
            $stmt->execute([':d' => $deptId, ':h' => $headId]);
            $row = $stmt->fetch();
            if (!$row) {
                throw new RuntimeException('That assignment no longer exists.');
            }

            $pdo->prepare('DELETE FROM alternate_verifiers WHERE department_id = :d AND head_user_id = :h')
                ->execute([':d' => $deptId, ':h' => $headId]);

            audit('alternate_verifier_removed', $row['full_name'] . ' (user #' . $headId . ') no longer verifies for '
                . $row['department_name'] . ' (department #' . $deptId . ').');
            admin_notify($headId, 'You are no longer an alternate verifier for ' . $row['department_name'] . '.');
            return $row['full_name'] . ' was removed as an alternate verifier for ' . $row['department_name'] . '.';
        }

        throw new RuntimeException('Unknown action.');
    });

    admin_redirect('admin/verifiers.php');
}

// ---------------------------------------------------------------------------
// Page data
// ---------------------------------------------------------------------------
$departments = db()->query('SELECT id, name, active FROM departments ORDER BY active DESC, name')->fetchAll();

// Every ACTIVE head: used for "own department head" and for the add drop-down.
$heads = db()->query(
    "SELECT id, full_name, email, department_id FROM users
      WHERE role = 'head' AND status = 'active' ORDER BY full_name"
)->fetchAll();

// Every alternate assignment, with the head's current state.
$alternates = db()->query(
    'SELECT a.department_id, a.head_user_id, u.full_name, u.email, u.role, u.status
       FROM alternate_verifiers a
       JOIN users u ON u.id = a.head_user_id
      ORDER BY u.full_name'
)->fetchAll();

$altByDept = [];
foreach ($alternates as $a) {
    $altByDept[(int) $a['department_id']][] = $a;
}

// ---------------------------------------------------------------------------
// Page
// ---------------------------------------------------------------------------
$pageTitle = 'Verifiers';
require __DIR__ . '/../app/views/layout/header.php';
echo admin_ui_styles();

page_header('Alternate verifiers', 'Let a Department Head also verify forms of another department.');
?>

<?php if ($departments === []): ?>
    <?php empty_state('No departments yet', 'Add departments first in Lists.'); ?>
<?php else: ?>
    <div class="card ui-table-card overflow-hidden !p-0">
        <div class="overflow-x-auto">
            <table class="ui-table" style="min-width:960px">
                <thead class="<?= admin_thead_class() ?>">
                    <tr>
                        <th class="px-4 py-3">Department</th>
                        <th class="px-4 py-3">Its own head(s)</th>
                        <th class="px-4 py-3">Alternate verifiers</th>
                        <th class="px-4 py-3">Add an alternate</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                <?php foreach ($departments as $d): ?>
                    <?php
                    $dId        = (int) $d['id'];
                    $own        = array_filter($heads, static fn ($h) => (int) $h['department_id'] === $dId);
                    $assigned   = $altByDept[$dId] ?? [];
                    $assignedIds = array_map(static fn ($a) => (int) $a['head_user_id'], $assigned);
                    $candidates = array_filter(
                        $heads,
                        static fn ($h) => (int) $h['department_id'] !== $dId && !in_array((int) $h['id'], $assignedIds, true)
                    );
                    ?>
                    <tr class="<?= admin_row_class('align-top') ?>">
                        <td class="px-4 py-3">
                            <p class="font-semibold text-slate-900"><?= e($d['name']) ?></p>
                            <?php if ((int) $d['active'] !== 1): ?><div class="mt-1"><?= admin_pill('Inactive', 'slate') ?></div><?php endif; ?>
                        </td>
                        <td class="px-4 py-3 text-slate-700">
                            <?php if ($own === []): ?>
                                <span class="text-amber-700">No active head</span>
                            <?php else: ?>
                                <?php foreach ($own as $h): ?><p><?= e($h['full_name']) ?></p><?php endforeach; ?>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3">
                            <?php if ($assigned === []): ?>
                                <span class="text-slate-500">None</span>
                            <?php endif; ?>
                            <?php foreach ($assigned as $a): ?>
                                <?php $works = $a['role'] === 'head' && $a['status'] === 'active'; ?>
                                <form method="post" class="mb-2 ui-inline-form ui-between">
                                    <?= admin_csrf_field() ?>
                                    <input type="hidden" name="action" value="remove">
                                    <input type="hidden" name="department_id" value="<?= $dId ?>">
                                    <input type="hidden" name="head_user_id" value="<?= (int) $a['head_user_id'] ?>">
                                    <span class="min-w-0">
                                        <span class="block truncate font-medium text-slate-900"><?= e($a['full_name']) ?></span>
                                        <?php if (!$works): ?>
                                            <span class="block text-xs text-red-700">Not an active head: cannot verify. Remove.</span>
                                        <?php else: ?>
                                            <span class="block truncate text-xs text-slate-500"><?= e($a['email']) ?></span>
                                        <?php endif; ?>
                                    </span>
                                    <button type="submit" class="<?= admin_btn('danger', 'shrink-0') ?>">Remove</button>
                                </form>
                            <?php endforeach; ?>
                        </td>
                        <td class="px-4 py-3">
                            <?php if ((int) $d['active'] !== 1): ?>
                                <span class="text-slate-500">Department is inactive.</span>
                            <?php elseif ($candidates === []): ?>
                                <span class="text-slate-500">No other active head to add.</span>
                            <?php else: ?>
                                <form method="post" class="ui-inline-form">
                                    <?= admin_csrf_field() ?>
                                    <input type="hidden" name="action" value="add">
                                    <input type="hidden" name="department_id" value="<?= $dId ?>">
                                    <select name="head_user_id" required aria-label="Head to add for <?= e($d['name']) ?>"
                                            class="<?= admin_input_class() ?>">
                                        <option value="">Choose a head...</option>
                                        <?php foreach ($candidates as $h): ?>
                                            <option value="<?= (int) $h['id'] ?>"><?= e($h['full_name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="submit" class="<?= admin_btn('primary', 'shrink-0') ?>">Designate</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/../app/views/layout/footer.php'; ?>