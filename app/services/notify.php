<?php
/**
 * app/services/notify.php
 *
 * In-app notifications (the bell). PHP mail() is switched off on InfinityFree,
 * so every message lives in the `notifications` table instead.
 *
 * Later Steps call:
 *   notify($userId, 'Your form was forwarded.', 'supplier/form-view.php?id=7');
 *   notify_role('mm_staff', 'A new form was submitted.', 'mms/review.php?id=7');
 *
 * $link is a PATH inside the project (no https://...). url() adds the site address.
 */

/** Send one notification to one user. */
function notify(int $userId, string $message, string $link = ''): void
{
    $stmt = db()->prepare(
        'INSERT INTO notifications (user_id, message, link) VALUES (:uid, :msg, :link)'
    );
    $stmt->execute([
        ':uid'  => $userId,
        ':msg'  => mb_substr($message, 0, 255),
        ':link' => $link === '' ? null : substr($link, 0, 255),
    ]);
}

/** Send the same notification to every ACTIVE user of a role (for example all mm_staff). */
function notify_role(string $role, string $message, string $link = ''): void
{
    $stmt = db()->prepare("SELECT id FROM users WHERE role = :role AND status = 'active'");
    $stmt->execute([':role' => $role]);
    foreach ($stmt->fetchAll() as $row) {
        notify((int) $row['id'], $message, $link);
    }
}

/** How many unread notifications this user has (the number on the bell). */
function unread_notification_count(int $userId): int
{
    $stmt = db()->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = :uid AND is_read = 0');
    $stmt->execute([':uid' => $userId]);
    return (int) $stmt->fetchColumn();
}

/**
 * Is this link safe to open? Only a plain path inside the project is allowed,
 * never another website (that would be an "open redirect").
 */
function notification_link_is_safe(string $link): bool
{
    return $link !== ''
        && !preg_match('#^[a-z][a-z0-9+.-]*:#i', $link)   // no http:, javascript:, ...
        && !str_starts_with($link, '//')                  // no //other-site.com
        && !str_contains($link, '\\')
        && !str_contains($link, "\n")
        && !str_contains($link, "\r");
}
