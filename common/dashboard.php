<?php
/**
 * common/dashboard.php
 *
 * One dashboard for every role: a few number cards and a "recent forms" list.
 * The numbers are REAL counts from the evaluations table (they stay at 0 until
 * Step 4 lets suppliers create forms).
 *
 * To change what a role sees, edit its block in the switch below.
 */

require_once __DIR__ . '/../app/bootstrap.php';

$user   = require_role(all_roles());
$userId = (int) $user['id'];

// ---------------------------------------------------------------------------
// Small query helpers. $where is always written by us (never by the visitor);
// every value goes through :placeholders.
// ---------------------------------------------------------------------------

/** Counts of forms per status for a WHERE condition: ['submitted' => 3, ...]. */
function status_counts(string $where, array $params = []): array
{
    $stmt = db()->prepare("SELECT status, COUNT(*) AS n FROM evaluations WHERE $where GROUP BY status");
    $stmt->execute($params);
    $out = [];
    foreach ($stmt->fetchAll() as $row) {
        $out[$row['status']] = (int) $row['n'];
    }
    return $out;
}

/** Add up the counts of several statuses. */
function count_of(array $counts, array $statuses): int
{
    return array_sum(array_intersect_key($counts, array_flip($statuses)));
}

/** The newest forms for a WHERE condition. */
function recent_forms(string $where, array $params = [], string $order = 'updated_at DESC', int $limit = 8): array
{
    $stmt = db()->prepare(
        "SELECT id, ref_no, bid_ref_no, bidder, status, updated_at
           FROM evaluations
          WHERE $where
          ORDER BY $order
          LIMIT " . (int) $limit
    );
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/** The page that opens one form for this role, or '' while that page is not built yet. */
function form_page(string $role, int $id): string
{
    $pages = [
        'supplier'   => 'supplier/form-view.php',
        'mm_staff'   => 'mms/review.php',
        'head_nurse' => 'headnurse/distribute.php',
        'evaluator'  => 'common/evaluation-view.php',
        'head'       => 'common/evaluation-view.php',
        'admin'      => 'common/evaluation-view.php',
    ];
    $file = $pages[$role] ?? '';
    return ($file !== '' && is_file(__DIR__ . '/../' . $file)) ? $file . '?id=' . $id : '';
}

// ---------------------------------------------------------------------------
// Numbers and list for this role
// ---------------------------------------------------------------------------
$cards       = [];   // each: [label, value, hint, link path]
$listTitle   = 'Recent forms';
$listEmpty   = 'Forms will appear here once they exist.';
$recent      = [];
$note        = '';

switch ($user['role']) {

    case 'supplier':
        $c = status_counts('supplier_id = :me', [':me' => $userId]);
        $cards = [
            ['Drafts',               count_of($c, ['supplier_draft']),                                                              'Not sent yet',            'supplier/forms.php'],
            ['Returned to you',      count_of($c, ['returned_supplier']),                                                           'Please correct and resend', 'supplier/forms.php'],
            ['In progress',          count_of($c, ['submitted', 'with_head_nurse', 'pending_evaluation', 'pending_head', 'returned_evaluator']), 'With the hospital',      'supplier/forms.php'],
            ['Waiting for your Conforme', count_of($c, ['pending_conforme']),                                                       'Please review and sign',  'supplier/forms.php'],
            ['Completed',            count_of($c, ['completed', 'received']),                                                       'Finished forms',          'supplier/forms.php'],
        ];
        $listTitle = 'My recent forms';
        $listEmpty = 'You have not created a form yet.';
        $recent    = recent_forms('supplier_id = :me', [':me' => $userId]);
        break;

    case 'mm_staff':
        $c = status_counts('1 = 1');
        $cards = [
            ['To review',        count_of($c, ['submitted']),                                                'Forward or return',        'mms/inbox.php'],
            ['With Head Nurse',  count_of($c, ['with_head_nurse']),                                          'Waiting to be distributed', 'mms/inbox.php?tab=with_head_nurse'],
            ['In evaluation',    count_of($c, ['pending_evaluation', 'pending_head', 'returned_evaluator']), 'Evaluator or Head',        'mms/records.php'],
            ['Waiting for Conforme', count_of($c, ['pending_conforme']),                                     'Supplier or paper scan',   'mms/records.php'],
            ['To receive',       count_of($c, ['completed']),                                                'Completed, not yet received', 'mms/records.php'],
        ];
        $listTitle = 'Latest activity';
        $listEmpty = 'No forms have been submitted yet.';
        $recent    = recent_forms("status <> 'supplier_draft'");
        break;

    case 'head_nurse':
        $c    = status_counts('1 = 1');
        $mine = status_counts('distributed_by = :me', [':me' => $userId]);
        $cards = [
            ['To distribute',       count_of($c, ['with_head_nurse']),   'Waiting for you',        'headnurse/inbox.php'],
            ['Distributed by you',  array_sum($mine),                    'All time',               'headnurse/inbox.php?tab=distributed'],
            ['Still with evaluators', count_of($mine, ['pending_evaluation', 'pending_head', 'returned_evaluator']), 'Not yet verified', 'headnurse/inbox.php?tab=distributed'],
            ['Completed',           count_of($mine, ['pending_conforme', 'completed', 'received']), 'Evaluated and signed', 'headnurse/inbox.php?tab=distributed'],
        ];
        $listTitle = 'Waiting for you';
        $listEmpty = 'Nothing to distribute right now.';
        $recent    = recent_forms("status = 'with_head_nurse'", [], 'submitted_at ASC, id ASC');
        break;

    case 'evaluator':
    case 'head':
        $deptId = (int) ($user['department_id'] ?? 0);
        $mine   = status_counts('evaluator_id = :me', [':me' => $userId]);
        $pool   = status_counts(
            "evaluator_id IS NULL AND status = 'pending_evaluation' AND department_id = :dept",
            [':dept' => $deptId]
        );
        $cards = [
            ['Assigned to me',    count_of($mine, ['pending_evaluation', 'returned_evaluator']), 'To fill in and sign', 'evaluator/assignments.php'],
            ['Department pool',   count_of($pool, ['pending_evaluation']),                       'You can claim these', 'evaluator/assignments.php?tab=pool'],
            ['Waiting for Head',  count_of($mine, ['pending_head']),                             'Signed by you',       'evaluator/assignments.php'],
            ['Finished',          count_of($mine, ['pending_conforme', 'completed', 'received']), 'Fully signed',        'evaluator/assignments.php'],
        ];

        if ($user['role'] === 'head') {
            // Forms this head may verify: own department and alternate-verifier departments,
            // never a form they evaluated themselves.
            $deptIds = verifier_department_ids($user);
            if ($deptIds !== []) {
                $marks  = implode(',', array_fill(0, count($deptIds), '?'));
                $stmt   = db()->prepare(
                    "SELECT COUNT(*) FROM evaluations
                      WHERE status = 'pending_head'
                        AND department_id IN ($marks)
                        AND (evaluator_id IS NULL OR evaluator_id <> ?)"
                );
                $stmt->execute([...$deptIds, $userId]);
                $forSignature = (int) $stmt->fetchColumn();
            } else {
                $forSignature = 0;
            }
            array_unshift($cards, ['For your signature', $forSignature, 'Verify and sign', 'head/for-signature.php']);
        }

        if ($user['status'] === 'pending') {
            $note = 'Your Department Head request is waiting for the administrator. Until then you work as an Evaluator.';
        }
        $listTitle = 'Assigned to me';
        $listEmpty = 'No form is assigned to you yet.';
        $recent    = recent_forms('evaluator_id = :me', [':me' => $userId]);
        break;

    case 'admin':
        $c = status_counts('1 = 1');
        $pendingHeads = (int) db()->query(
            "SELECT COUNT(*) FROM users WHERE status = 'pending' AND requested_role = 'head'"
        )->fetchColumn();
        $activeUsers = (int) db()->query("SELECT COUNT(*) FROM users WHERE status = 'active'")->fetchColumn();
        $cards = [
            ['Head requests',   $pendingHeads,                         'Waiting for approval', 'admin/role-requests.php'],
            ['Active users',    $activeUsers,                          'Can sign in',          'admin/users.php'],
            ['Forms in progress', array_sum($c) - count_of($c, ['supplier_draft', 'completed', 'received', 'cancelled']), 'Between submit and Conforme', 'mms/records.php'],
            ['Completed forms', count_of($c, ['completed', 'received']), 'Finished',            'mms/records.php'],
        ];
        $listTitle = 'Latest activity';
        $listEmpty = 'No forms have been submitted yet.';
        $recent    = recent_forms("status <> 'supplier_draft'");
        break;
}

// ---------------------------------------------------------------------------
// Page
// ---------------------------------------------------------------------------
$pageTitle = 'Dashboard';
require __DIR__ . '/../app/views/layout/header.php';

page_header('Dashboard', 'Welcome, ' . $user['full_name'] . '.');
?>

<?php if ($note !== ''): ?>
    <div class="mb-6 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
        <?= e($note) ?>
    </div>
<?php endif; ?>

<section aria-label="Summary" class="grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-4">
    <?php foreach ($cards as [$label, $value, $hint, $path]): ?>
        <?php stat_card($label, (int) $value, $hint, $path); ?>
    <?php endforeach; ?>
</section>

<section class="mt-8" aria-label="<?= e($listTitle) ?>">
    <h2 class="mb-3 text-base font-semibold text-slate-900"><?= e($listTitle) ?></h2>

    <?php if ($recent === []): ?>
        <?php empty_state('Nothing here yet', $listEmpty); ?>
    <?php else: ?>
        <ul class="card divide-y divide-slate-100 !p-0 overflow-hidden">
            <?php foreach ($recent as $f): ?>
                <?php $href = form_page($user['role'], (int) $f['id']); ?>
                <li class="flex flex-col gap-2 px-4 py-3 sm:flex-row sm:items-center sm:justify-between sm:px-6">
                    <div class="min-w-0">
                        <p class="truncate text-sm font-semibold text-slate-900">
                            <?php if ($href !== ''): ?>
                                <a href="<?= e(url($href)) ?>" class="hover:underline focus:outline-none focus-visible:underline"><?= e($f['bid_ref_no'] !== '' ? $f['bid_ref_no'] : 'No bid number yet') ?></a>
                            <?php else: ?>
                                <?= e($f['bid_ref_no'] !== '' ? $f['bid_ref_no'] : 'No bid number yet') ?>
                            <?php endif; ?>
                        </p>
                        <p class="truncate text-xs text-slate-500">
                            <?= e($f['bidder'] ?: 'No bidder name') ?>
                            <?= $f['ref_no'] ? ' &middot; ' . e($f['ref_no']) : '' ?>
                            &middot; <?= e(date('M j, Y', strtotime((string) $f['updated_at']))) ?>
                        </p>
                    </div>
                    <div class="shrink-0"><?= status_badge($f['status']) ?></div>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/../app/views/layout/footer.php'; ?>
