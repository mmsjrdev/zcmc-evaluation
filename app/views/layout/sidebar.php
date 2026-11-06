<?php
/**
 * app/views/layout/sidebar.php
 *
 * The side menu. header.php includes it. The menu changes with the role.
 * To add a page to a menu, add one line to nav_groups() below.
 *
 * A menu item whose page file does not exist yet (a later Step builds it) is
 * shown greyed out with "Soon", so nobody clicks into a 404.
 */

/** Small line icons (Heroicons style). Each value is the "d" of one SVG path. */
function nav_icon(string $name): string
{
    $paths = [
        'home'     => 'm2.25 12 8.954-8.955a1.126 1.126 0 0 1 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25',
        'list'     => 'M8.25 6.75h12M8.25 12h12m-12 5.25h12M3.75 6.75h.007v.008H3.75V6.75Zm0 5.25h.007v.008H3.75V12Zm0 5.25h.007v.008H3.75v-.008Z',
        'plus'     => 'M12 4.5v15m7.5-7.5h-15',
        'inbox'    => 'M2.25 13.5h3.86a2.25 2.25 0 0 1 2.012 1.244l.256.512a2.25 2.25 0 0 0 2.013 1.244h3.218a2.25 2.25 0 0 0 2.013-1.244l.256-.512a2.25 2.25 0 0 1 2.013-1.244h3.859m-19.5.338V18a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18v-4.162c0-.224-.034-.447-.1-.661L19.24 5.338a2.25 2.25 0 0 0-2.15-1.588H6.911a2.25 2.25 0 0 0-2.15 1.588L2.35 13.177a2.25 2.25 0 0 0-.1.661Z',
        'archive'  => 'm20.25 7.5-.625 10.632a2.25 2.25 0 0 1-2.247 2.118H6.622a2.25 2.25 0 0 1-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125Z',
        'send'     => 'M6 12 3.269 3.125A59.769 59.769 0 0 1 21.485 12 59.768 59.768 0 0 1 3.27 20.875L5.999 12Zm0 0h7.5',
        'users'    => 'M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z',
        'shield'   => 'M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z',
        'pen'      => 'm16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0 1 15.75 21H5.25A2.25 2.25 0 0 1 3 18.75V8.25A2.25 2.25 0 0 1 5.25 6H10',
        'clipboard'=> 'M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 0 0 2.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 0 0-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 0 0 .75-.75 2.25 2.25 0 0 0-.1-.664m-5.8 0A2.251 2.251 0 0 1 13.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25Z',
        'bell'     => 'M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0',
        'key'      => 'M15.75 5.25a3 3 0 0 1 3 3m3 0a6 6 0 0 1-7.029 5.912c-.563-.097-1.159.026-1.563.43L10.5 17.25H8.25v2.25H6v2.25H2.25v-2.818c0-.597.237-1.17.659-1.591l6.499-6.499c.404-.404.527-1 .43-1.563A6 6 0 1 1 21.75 8.25Z',
        'logout'   => 'M15.75 9V5.25A2.25 2.25 0 0 0 13.5 3h-6a2.25 2.25 0 0 0-2.25 2.25v13.5A2.25 2.25 0 0 0 7.5 21h6a2.25 2.25 0 0 0 2.25-2.25V15m3 0 3-3m0 0-3-3m3 3H9',
    ];
    $d = $paths[$name] ?? $paths['list'];
    return '<svg class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6" aria-hidden="true">'
         . '<path stroke-linecap="round" stroke-linejoin="round" d="' . $d . '"/></svg>';
}

/**
 * The menu for one user: a list of groups, each [ heading, [ [label, path, icon], ... ] ].
 * Paths are inside the project. A path may carry a query (?tab=pool).
 */
function nav_groups(array $user): array
{
    $role = $user['role'];

    $evaluatorItems = [
        ['Assigned to Me',   'evaluator/assignments.php',          'pen'],
        ['Department Pool',  'evaluator/assignments.php?tab=pool', 'users'],
    ];
    $mmsItems = [
        ['Inbox',     'mms/inbox.php',     'inbox'],
        ['Suppliers', 'mms/suppliers.php', 'users'],
        ['Records',   'mms/records.php',   'archive'],
    ];

    $groups = [['', [['Dashboard', 'common/dashboard.php', 'home']]]];

    switch ($role) {
        case 'supplier':
            $groups[] = ['My forms', [
                ['My Forms', 'supplier/forms.php',     'list'],
                ['New Form', 'supplier/form-edit.php', 'plus'],
            ]];
            break;

        case 'mm_staff':
            $groups[] = ['Material Management', $mmsItems];
            break;

        case 'head_nurse':
            $groups[] = ['Head Nurse', [
                ['To Distribute', 'headnurse/inbox.php',                 'send'],
                ['Distributed',   'headnurse/inbox.php?tab=distributed', 'list'],
            ]];
            break;

        case 'evaluator':
            $groups[] = ['Evaluation', $evaluatorItems];
            break;

        case 'head':
            $groups[] = ['Department Head', [['For Signature', 'head/for-signature.php', 'shield']]];
            $groups[] = ['Evaluation', $evaluatorItems];
            break;

        case 'admin':
            $groups[] = ['Administration', [
                ['Role Requests',  'admin/role-requests.php', 'shield'],
                ['Users',          'admin/users.php',         'users'],
                ['Lists',          'admin/lists.php',         'clipboard'],
                ['Verifiers',      'admin/verifiers.php',     'pen'],
                ['Audit Log',      'admin/audit.php',         'list'],
            ]];
            $groups[] = ['Material Management', $mmsItems];
            break;
    }

    $groups[] = ['Account', [
        ['Notifications',   'common/notifications.php', 'bell'],
        ['Change password', 'auth/change-password.php', 'key'],
    ]];

    return $groups;
}

/** Does this menu item point at the page being shown right now? */
function nav_is_active(string $path): bool
{
    $file  = (string) strtok($path, '?');
    $query = (string) (parse_url($path, PHP_URL_QUERY) ?? '');
    $here  = (string) ($_SERVER['SCRIPT_NAME'] ?? '');

    if (!str_ends_with($here, '/' . $file)) {
        return false;
    }
    if ($query === '') {
        // "To Distribute" must not light up while "Distributed" (?tab=...) is open.
        return !isset($_GET['tab']);
    }
    parse_str($query, $wanted);
    foreach ($wanted as $key => $value) {
        if (($_GET[$key] ?? null) !== $value) {
            return false;
        }
    }
    return true;
}

/** Prints the menu. Lives in a function so its loop variables never leak into the page. */
function render_sidebar(array $user, string $roleText, int $navUnread): void
{
$projectRoot = __DIR__ . '/../../../';
?>
<nav class="flex-1 overflow-y-auto px-3 py-4" aria-label="Main menu">
    <?php foreach (nav_groups($user) as [$heading, $items]): ?>
        <?php if ($heading !== ''): ?>
            <p class="mb-1 mt-5 px-3 text-xs font-semibold uppercase tracking-wider text-brand-300/80"><?= e($heading) ?></p>
        <?php endif; ?>
        <ul class="space-y-0.5">
            <?php foreach ($items as [$label, $path, $icon]): ?>
                <?php
                $exists = is_file($projectRoot . strtok($path, '?'));
                $active = $exists && nav_is_active($path);
                ?>
                <li>
                <?php if ($exists): ?>
                    <a href="<?= e(url($path)) ?>"
                       class="nav-link<?= $active ? ' nav-link-active' : '' ?>"
                       <?= $active ? 'aria-current="page"' : '' ?>>
                        <?= nav_icon($icon) ?>
                        <span class="flex-1"><?= e($label) ?></span>
                        <?php if ($path === 'common/notifications.php' && $navUnread > 0): ?>
                            <span class="rounded-full bg-amber-400 px-2 py-0.5 text-xs font-bold text-slate-900"><?= $navUnread > 99 ? '99+' : $navUnread ?></span>
                        <?php endif; ?>
                    </a>
                <?php else: ?>
                    <span class="nav-link nav-link-disabled" aria-disabled="true">
                        <?= nav_icon($icon) ?>
                        <span class="flex-1"><?= e($label) ?></span>
                        <span class="rounded-full bg-white/10 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide">Soon</span>
                    </span>
                <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endforeach; ?>
</nav>

<div class="border-t border-white/10 p-3">
    <div class="mb-2 px-3">
        <p class="truncate text-sm font-semibold text-white"><?= e($user['full_name']) ?></p>
        <p class="truncate text-xs text-brand-200"><?= e($roleText) ?></p>
    </div>
    <form method="post" action="<?= e(url('auth/logout.php')) ?>">
        <?= csrf_field() ?>
        <button type="submit" class="nav-link w-full">
            <?= nav_icon('logout') ?>
            <span class="flex-1 text-left">Sign out</span>
        </button>
    </form>
</div>
<?php
}
