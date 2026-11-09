<?php
/**
 * admin/audit.php
 *
 * Read-only audit log, newest first. Filters: user, action, date range.
 * Nothing on this page can change or delete a log line.
 */

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/domain/admin-helpers.php';
require_once __DIR__ . '/../app/views/partials/admin-ui.php';

$admin = require_role(['admin']);

/** Returns the text when it is a real date like 2026-11-09, otherwise ''. */
function audit_clean_date(string $text): string
{
    $d = DateTime::createFromFormat('!Y-m-d', $text);
    return ($d !== false && $d->format('Y-m-d') === $text) ? $text : '';
}

$perPage = 50;
$fUser   = (string) ($_GET['user'] ?? '');       // '' = everybody, 'none' = not signed in, or a user id
$fAction = trim((string) ($_GET['action'] ?? ''));
$fFrom   = audit_clean_date((string) ($_GET['from'] ?? ''));
$fTo     = audit_clean_date((string) ($_GET['to'] ?? ''));

$where   = [];
$params  = [];
$filters = [];

if ($fUser === 'none') {
    $where[] = 'a.user_id IS NULL';
    $filters['user'] = 'none';
} elseif (ctype_digit($fUser) && (int) $fUser > 0) {
    $where[] = 'a.user_id = :uid';
    $params[':uid'] = (int) $fUser;
    $filters['user'] = $fUser;
} else {
    $fUser = '';
}
if ($fAction !== '') {
    $where[] = 'a.action = :action';
    $params[':action'] = $fAction;
    $filters['action'] = $fAction;
}
if ($fFrom !== '') {
    $where[] = 'a.created_at >= :from_date';
    $params[':from_date'] = $fFrom . ' 00:00:00';
    $filters['from'] = $fFrom;
}
if ($fTo !== '') {
    $where[] = 'a.created_at < :to_date';
    $params[':to_date'] = date('Y-m-d', strtotime($fTo . ' +1 day')) . ' 00:00:00';
    $filters['to'] = $fTo;
}
$whereSql = $where === [] ? '1 = 1' : implode(' AND ', $where);

$count = db()->prepare("SELECT COUNT(*) FROM audit_log a WHERE $whereSql");
$count->execute($params);
$total = (int) $count->fetchColumn();
$pages = max(1, (int) ceil($total / $perPage));
$page  = min($pages, max(1, (int) ($_GET['page'] ?? 1)));

$list = db()->prepare(
    "SELECT a.id, a.user_id, a.action, a.details, a.ip_address, a.created_at, u.full_name, u.email
       FROM audit_log a
       LEFT JOIN users u ON u.id = a.user_id
      WHERE $whereSql
      ORDER BY a.id DESC
      LIMIT " . (int) $perPage . ' OFFSET ' . (int) (($page - 1) * $perPage)
);
$list->execute($params);
$rows = $list->fetchAll();

// Choices for the two drop-downs.
$actions = db()->query('SELECT DISTINCT action FROM audit_log ORDER BY action')->fetchAll(PDO::FETCH_COLUMN);
$people  = db()->query(
    'SELECT DISTINCT u.id, u.full_name, u.email
       FROM audit_log a JOIN users u ON u.id = a.user_id
      ORDER BY u.full_name LIMIT 500'
)->fetchAll();

// ---------------------------------------------------------------------------
// Page
// ---------------------------------------------------------------------------
$pageTitle = 'Audit log';
require __DIR__ . '/../app/views/layout/header.php';
echo admin_ui_styles();

page_header('Audit log', 'Who did what, newest first. This list cannot be changed.');
?>

<form method="get" class="card mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-[1fr_1fr_160px_160px_auto] lg:items-end">
    <div>
        <label for="user" class="<?= admin_label_class() ?>">User</label>
        <select id="user" name="user" class="<?= admin_input_class() ?>">
            <option value="">Everybody</option>
            <option value="none"<?= $fUser === 'none' ? ' selected' : '' ?>>Not signed in / system</option>
            <?php foreach ($people as $p): ?>
                <option value="<?= (int) $p['id'] ?>"<?= $fUser === (string) $p['id'] ? ' selected' : '' ?>><?= e($p['full_name']) ?> (<?= e($p['email']) ?>)</option>
            <?php endforeach; ?>
        </select>
    </div>
    <div>
        <label for="action" class="<?= admin_label_class() ?>">Action</label>
        <select id="action" name="action" class="<?= admin_input_class() ?>">
            <option value="">All actions</option>
            <?php foreach ($actions as $act): ?>
                <option value="<?= e($act) ?>"<?= $fAction === $act ? ' selected' : '' ?>><?= e($act) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div>
        <label for="from" class="<?= admin_label_class() ?>">From</label>
        <input type="date" id="from" name="from" value="<?= e($fFrom) ?>" class="<?= admin_input_class() ?>">
    </div>
    <div>
        <label for="to" class="<?= admin_label_class() ?>">To</label>
        <input type="date" id="to" name="to" value="<?= e($fTo) ?>" class="<?= admin_input_class() ?>">
    </div>
    <div class="flex gap-2">
        <button type="submit" class="<?= admin_btn('primary') ?>">Filter</button>
        <?php if ($filters !== []): ?>
            <a href="<?= e(url('admin/audit.php')) ?>" class="<?= admin_btn('secondary') ?>">Clear</a>
        <?php endif; ?>
    </div>
</form>

<?php if ($rows === []): ?>
    <?php empty_state('No log entries', 'Nothing matches these filters.'); ?>
<?php else: ?>
    <p class="mb-2 text-sm text-slate-600"><?= (int) $total ?> entr<?= $total === 1 ? 'y' : 'ies' ?></p>
    <div class="card ui-table-card overflow-hidden !p-0">
        <div class="overflow-x-auto">
            <table class="ui-table" style="min-width:920px">
                <thead class="<?= admin_thead_class() ?>">
                    <tr>
                        <th class="px-4 py-3">When</th>
                        <th class="px-4 py-3">User</th>
                        <th class="px-4 py-3">Action</th>
                        <th class="px-4 py-3">Details</th>
                        <th class="px-4 py-3">IP</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                <?php foreach ($rows as $r): ?>
                    <tr class="<?= admin_row_class('align-top') ?>">
                        <td class="whitespace-nowrap px-4 py-3 text-slate-700"><?= e(date('M j, Y g:i A', strtotime((string) $r['created_at']))) ?></td>
                        <td class="px-4 py-3">
                            <?php if ($r['full_name'] !== null): ?>
                                <p class="font-medium text-slate-900"><?= e($r['full_name']) ?></p>
                                <p class="text-xs text-slate-500"><?= e($r['email']) ?></p>
                            <?php else: ?>
                                <span class="text-slate-500"><?= $r['user_id'] === null ? 'Not signed in' : 'Deleted user' ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="whitespace-nowrap px-4 py-3 font-mono text-xs text-slate-800"><?= e($r['action']) ?></td>
                        <td class="max-w-md whitespace-pre-wrap break-words px-4 py-3 text-slate-700"><?= e((string) $r['details']) ?></td>
                        <td class="whitespace-nowrap px-4 py-3 text-xs text-slate-500"><?= e($r['ip_address']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php admin_pager($page, $pages, $filters, 'admin/audit.php'); ?>
<?php endif; ?>

<?php require __DIR__ . '/../app/views/layout/footer.php'; ?>