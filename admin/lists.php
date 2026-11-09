<?php
/**
 * admin/lists.php
 *
 * The two lists the forms use: departments and modes of procurement.
 * An admin can add, rename, deactivate (and re-activate) an entry.
 * An entry can be DELETED only while nothing uses it.
 * (An inactive entry disappears from the drop-down lists but old forms keep it.)
 */

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/domain/admin-helpers.php';
require_once __DIR__ . '/../app/views/partials/admin-ui.php';

$admin = require_role(['admin']);

// The two lists. Table names come from this fixed array, never from the visitor.
$kinds = [
    'departments' => ['table' => 'departments',       'title' => 'Departments',           'one' => 'department',          'audit' => 'department'],
    'modes'       => ['table' => 'procurement_modes', 'title' => 'Modes of procurement',  'one' => 'mode of procurement', 'audit' => 'procurement_mode'],
];

$tab = (string) ($_POST['tab'] ?? $_GET['tab'] ?? 'departments');
if (!isset($kinds[$tab])) {
    $tab = 'departments';
}
$kind  = $kinds[$tab];
$table = $kind['table'];

/** How many records use this entry (users, forms, alternate verifiers). */
function list_usage(PDO $pdo, string $tab, int $id): int
{
    if ($tab === 'departments') {
        $stmt = $pdo->prepare(
            'SELECT (SELECT COUNT(*) FROM users WHERE department_id = :a)
                  + (SELECT COUNT(*) FROM evaluations WHERE department_id = :b)
                  + (SELECT COUNT(*) FROM alternate_verifiers WHERE department_id = :c)'
        );
        $stmt->execute([':a' => $id, ':b' => $id, ':c' => $id]);
    } else {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM evaluations WHERE mode_id = :a');
        $stmt->execute([':a' => $id]);
    }
    return (int) $stmt->fetchColumn();
}

/** Throws a RuntimeException when the name is empty, too long, or used by another entry. */
function list_check_name(PDO $pdo, string $table, string $name, int $ownId): void
{
    $length = mb_strlen($name);
    if ($length < 2 || $length > 150) {
        throw new RuntimeException('The name must have 2 to 150 characters.');
    }
    $stmt = $pdo->prepare("SELECT 1 FROM $table WHERE name = :name AND id <> :id LIMIT 1");
    $stmt->execute([':name' => $name, ':id' => $ownId]);
    if ($stmt->fetchColumn() !== false) {
        throw new RuntimeException('An entry with this name already exists.');
    }
}

// ---------------------------------------------------------------------------
// Actions (POST)
// ---------------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    admin_check_csrf();

    $action = (string) ($_POST['action'] ?? '');
    $id     = (int) ($_POST['id'] ?? 0);
    $name   = normalize_name((string) ($_POST['name'] ?? ''));

    admin_transaction(function (PDO $pdo) use ($action, $id, $name, $tab, $kind, $table) {
        $one = $kind['one'];

        if ($action === 'add') {
            list_check_name($pdo, $table, $name, 0);
            $pdo->prepare("INSERT INTO $table (name, active) VALUES (:name, 1)")->execute([':name' => $name]);
            audit($kind['audit'] . '_added', 'Added ' . $one . ' "' . $name . '" (#' . $pdo->lastInsertId() . ').');
            return 'Added "' . $name . '".';
        }

        // Every other action works on one existing entry: lock it first.
        $stmt = $pdo->prepare("SELECT id, name, active FROM $table WHERE id = :id LIMIT 1 FOR UPDATE");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new RuntimeException('That entry does not exist.');
        }

        if ($action === 'rename') {
            if ($name === $row['name']) {
                throw new RuntimeException('There was nothing to change.');
            }
            list_check_name($pdo, $table, $name, $id);
            $pdo->prepare("UPDATE $table SET name = :name WHERE id = :id")->execute([':name' => $name, ':id' => $id]);
            audit($kind['audit'] . '_renamed', 'Renamed ' . $one . ' #' . $id . ' from "' . $row['name'] . '" to "' . $name . '".');
            return 'Renamed to "' . $name . '".';
        }

        if ($action === 'deactivate' || $action === 'activate') {
            $active = $action === 'activate' ? 1 : 0;
            $pdo->prepare("UPDATE $table SET active = :a WHERE id = :id")->execute([':a' => $active, ':id' => $id]);
            audit($kind['audit'] . ($active ? '_activated' : '_deactivated'),
                ($active ? 'Activated ' : 'Deactivated ') . $one . ' "' . $row['name'] . '" (#' . $id . ').');
            return '"' . $row['name'] . '" is now ' . ($active ? 'active.' : 'inactive.');
        }

        if ($action === 'delete') {
            // Never delete something that is in use.
            if (list_usage($pdo, $tab, $id) > 0) {
                throw new RuntimeException('"' . $row['name'] . '" is in use, so it cannot be deleted. Deactivate it instead.');
            }
            $pdo->prepare("DELETE FROM $table WHERE id = :id")->execute([':id' => $id]);
            audit($kind['audit'] . '_deleted', 'Deleted unused ' . $one . ' "' . $row['name'] . '" (#' . $id . ').');
            return 'Deleted "' . $row['name'] . '".';
        }

        throw new RuntimeException('Unknown action.');
    });

    admin_redirect('admin/lists.php?tab=' . $tab);
}

// ---------------------------------------------------------------------------
// Page data
// ---------------------------------------------------------------------------
if ($tab === 'departments') {
    $sql = 'SELECT d.id, d.name, d.active,
                   (SELECT COUNT(*) FROM users u WHERE u.department_id = d.id) AS users_n,
                   (SELECT COUNT(*) FROM evaluations e WHERE e.department_id = d.id) AS forms_n,
                   (SELECT COUNT(*) FROM alternate_verifiers a WHERE a.department_id = d.id) AS alt_n
              FROM departments d
             ORDER BY d.active DESC, d.name';
} else {
    $sql = 'SELECT m.id, m.name, m.active,
                   0 AS users_n,
                   (SELECT COUNT(*) FROM evaluations e WHERE e.mode_id = m.id) AS forms_n,
                   0 AS alt_n
              FROM procurement_modes m
             ORDER BY m.active DESC, m.name';
}
$items = db()->query($sql)->fetchAll();

// ---------------------------------------------------------------------------
// Page
// ---------------------------------------------------------------------------
$pageTitle = 'Lists';
require __DIR__ . '/../app/views/layout/header.php';
echo admin_ui_styles();

page_header('Lists', 'Departments and modes of procurement used on the forms.');

?>

<nav class="mb-4 flex flex-wrap gap-2" aria-label="Lists">
    <?php foreach ($kinds as $key => $k): ?>
        <a href="<?= e(url('admin/lists.php?tab=' . $key)) ?>" class="<?= admin_tab_class($tab === $key) ?>"
           <?= $tab === $key ? 'aria-current="page"' : '' ?>><?= e($k['title']) ?></a>
    <?php endforeach; ?>
</nav>

<form method="post" class="card mb-6 flex flex-col gap-3 sm:flex-row sm:items-end">
    <?= admin_csrf_field() ?>
    <input type="hidden" name="action" value="add">
    <input type="hidden" name="tab" value="<?= e($tab) ?>">
    <div class="flex-1">
        <label for="new_name" class="<?= admin_label_class() ?>">Add a <?= e($kind['one']) ?></label>
        <input type="text" id="new_name" name="name" required minlength="2" maxlength="150" class="<?= admin_input_class() ?>">
    </div>
    <button type="submit" class="<?= admin_btn('primary') ?>">Add</button>
</form>

<?php if ($items === []): ?>
    <?php empty_state('Nothing here yet', 'Add the first entry above.'); ?>
<?php else: ?>
    <div class="card ui-table-card overflow-hidden !p-0">
        <div class="overflow-x-auto">
            <table class="ui-table" style="min-width:820px">
                <thead class="<?= admin_thead_class() ?>">
                    <tr>
                        <th class="px-4 py-3">Name</th>
                        <th class="px-4 py-3">In use</th>
                        <th class="px-4 py-3">Status</th>
                        <th class="px-4 py-3">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                <?php foreach ($items as $it): ?>
                    <?php
                    $usedBy = (int) $it['users_n'] + (int) $it['forms_n'] + (int) $it['alt_n'];
                    $parts  = [];
                    if ($tab === 'departments') {
                        $parts[] = (int) $it['users_n'] . ' user(s)';
                        $parts[] = (int) $it['alt_n'] . ' alternate verifier(s)';
                    }
                    $parts[] = (int) $it['forms_n'] . ' form(s)';
                    ?>
                    <tr class="<?= admin_row_class('align-top') ?>">
                        <td class="px-4 py-3">
                            <form method="post" class="ui-inline-form">
                                <?= admin_csrf_field() ?>
                                <input type="hidden" name="action" value="rename">
                                <input type="hidden" name="tab" value="<?= e($tab) ?>">
                                <input type="hidden" name="id" value="<?= (int) $it['id'] ?>">
                                <input type="text" name="name" value="<?= e($it['name']) ?>" required minlength="2" maxlength="150"
                                       aria-label="Name" class="<?= admin_input_class() ?>">
                                <button type="submit" class="<?= admin_btn('secondary', 'shrink-0') ?>">Rename</button>
                            </form>
                        </td>
                        <td class="px-4 py-3 text-slate-700"><?= e(implode(', ', $parts)) ?></td>
                        <td class="px-4 py-3"><?= (int) $it['active'] === 1 ? admin_pill('Active', 'green') : admin_pill('Inactive', 'slate') ?></td>
                        <td class="px-4 py-3">
                            <div class="flex flex-wrap gap-2">
                                <form method="post">
                                    <?= admin_csrf_field() ?>
                                    <input type="hidden" name="action" value="<?= (int) $it['active'] === 1 ? 'deactivate' : 'activate' ?>">
                                    <input type="hidden" name="tab" value="<?= e($tab) ?>">
                                    <input type="hidden" name="id" value="<?= (int) $it['id'] ?>">
                                    <button type="submit" class="<?= admin_btn('secondary') ?>">
                                        <?= (int) $it['active'] === 1 ? 'Deactivate' : 'Activate' ?>
                                    </button>
                                </form>
                                <?php if ($usedBy === 0): ?>
                                    <form method="post" onsubmit="return confirm('Delete this entry for good?');">
                                        <?= admin_csrf_field() ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="tab" value="<?= e($tab) ?>">
                                        <input type="hidden" name="id" value="<?= (int) $it['id'] ?>">
                                        <button type="submit" class="<?= admin_btn('danger') ?>">Delete</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/../app/views/layout/footer.php'; ?>