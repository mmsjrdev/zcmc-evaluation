<?php
/**
 * common/notifications.php
 *
 * The person's notifications, newest first.
 *  - Clicking one opens its link and marks it read (?open=ID).
 *  - "Mark all read" is a POST form with the CSRF token.
 * A person can only ever see and open THEIR OWN notifications.
 */

require_once __DIR__ . '/../app/bootstrap.php';

$user   = require_role(all_roles());
$userId = (int) $user['id'];

// ---- Mark all read (POST) ---------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $stmt = db()->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = :uid AND is_read = 0');
    $stmt->execute([':uid' => $userId]);

    flash('success', 'All notifications are marked as read.');
    redirect('common/notifications.php');
}

// ---- Open one notification (GET ?open=ID) -----------------------------------
if (isset($_GET['open'])) {
    $id = (int) $_GET['open'];

    $stmt = db()->prepare('SELECT id, link FROM notifications WHERE id = :id AND user_id = :uid LIMIT 1');
    $stmt->execute([':id' => $id, ':uid' => $userId]);
    $row = $stmt->fetch();

    if ($row) {
        $upd = db()->prepare('UPDATE notifications SET is_read = 1 WHERE id = :id AND user_id = :uid');
        $upd->execute([':id' => $id, ':uid' => $userId]);

        $link = (string) ($row['link'] ?? '');
        if (notification_link_is_safe($link)) {
            redirect($link);
        }
    }
    redirect('common/notifications.php');
}

// ---- Show the list ----------------------------------------------------------
$stmt = db()->prepare(
    'SELECT id, message, link, is_read, created_at
       FROM notifications
      WHERE user_id = :uid
      ORDER BY created_at DESC, id DESC
      LIMIT 100'
);
$stmt->execute([':uid' => $userId]);
$items  = $stmt->fetchAll();
$unread = unread_notification_count($userId);

$pageTitle = 'Notifications';
require __DIR__ . '/../app/views/layout/header.php';

page_header('Notifications', $unread > 0 ? "You have $unread unread." : 'You are all caught up.');
?>

<?php if ($items === []): ?>
    <?php empty_state('No notifications yet', 'Messages about your forms will appear here.'); ?>
<?php else: ?>
    <?php if ($unread > 0): ?>
        <form method="post" action="<?= e(url('common/notifications.php')) ?>" class="mb-4 flex justify-end">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-secondary">Mark all read</button>
        </form>
    <?php endif; ?>

    <ul class="card divide-y divide-slate-100 !p-0 overflow-hidden">
        <?php foreach ($items as $n): ?>
            <li>
                <a href="<?= e(url('common/notifications.php?open=' . (int) $n['id'])) ?>"
                   class="flex min-h-14 items-start gap-3 px-4 py-3 hover:bg-slate-50 focus:outline-none focus-visible:bg-brand-50 sm:px-6">
                    <span class="mt-1.5 h-2.5 w-2.5 shrink-0 rounded-full <?= $n['is_read'] ? 'bg-slate-200' : 'bg-brand-600' ?>"
                          aria-hidden="true"></span>
                    <span class="min-w-0 flex-1">
                        <span class="block text-sm <?= $n['is_read'] ? 'text-slate-600' : 'font-semibold text-slate-900' ?>"><?= e($n['message']) ?></span>
                        <span class="mt-0.5 block text-xs text-slate-400">
                            <?= e(date('M j, Y g:i A', strtotime((string) $n['created_at']))) ?>
                            <?= $n['is_read'] ? '' : ' &middot; Unread' ?>
                        </span>
                    </span>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

<?php require __DIR__ . '/../app/views/layout/footer.php'; ?>
