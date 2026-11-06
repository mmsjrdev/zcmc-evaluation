<?php
/**
 * mms/suppliers.php
 *
 * MMS creates supplier accounts here and can disable or re-enable them.
 * Only the "supplier" role is handled on this page.
 */

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/domain/mms-suppliers.php';

$user = require_role(['mm_staff']);

$errors = [];
$old    = ['business_name' => '', 'full_name' => '', 'email' => '', 'contact_no' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'create') {
        foreach (array_keys($old) as $key) {
            $old[$key] = trim((string) ($_POST[$key] ?? ''));
        }
        $old['email'] = strtolower($old['email']);
        $password     = (string) ($_POST['temp_password'] ?? '');

        $errors = mms_supplier_errors($old, $password);
        if ($errors === []) {
            try {
                mms_create_supplier($user, $old, $password);
                flash('success', 'Supplier account created. Give the temporary password to the supplier; they must change it when they first sign in.');
                redirect('mms/suppliers.php');
            } catch (PDOException $ex) {
                // 23000 = duplicate email (two people saved the same email at once).
                if ($ex->getCode() !== '23000') {
                    throw $ex;
                }
                $errors[] = 'That email address already has an account.';
            }
        }

    } elseif ($action === 'disable' || $action === 'enable') {
        $supplierId = (int) ($_POST['supplier_id'] ?? 0);
        if (mms_set_supplier_status($user, $supplierId, $action === 'disable' ? 'disabled' : 'active')) {
            flash('success', $action === 'disable' ? 'Supplier account disabled.' : 'Supplier account enabled again.');
        } else {
            flash('error', 'That account could not be changed. Reload the page and try again.');
        }
        redirect('mms/suppliers.php');
    }
}

$suppliers = mms_suppliers_list();

// Shared Tailwind classes for the form fields on this page.
$labelClass = 'block text-sm font-medium text-slate-700';
$fieldClass = 'mt-1 block min-h-11 w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 focus:border-brand-600 focus:outline-none focus:ring-4 focus:ring-brand-200';

$pageTitle = 'Suppliers';
require __DIR__ . '/../app/views/layout/header.php';

page_header('Suppliers', 'Create and manage supplier accounts');
?>

<?php if ($errors !== []): ?>
    <div role="alert" class="mb-6 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-900">
        <p class="font-semibold">The supplier account was not created:</p>
        <ul class="mt-1 list-disc space-y-0.5 pl-5">
            <?php foreach ($errors as $message): ?>
                <li><?= e($message) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<!-- New supplier (opens by itself when there are errors) -->
<details class="card mb-6" <?= $errors !== [] ? 'open' : '' ?>>
    <summary class="flex min-h-11 cursor-pointer items-center text-base font-semibold text-slate-900 focus:outline-none focus-visible:ring-4 focus-visible:ring-brand-200">
        Add a supplier
    </summary>

    <form method="post" action="<?= e(url('mms/suppliers.php')) ?>" class="mt-4 grid gap-4 sm:grid-cols-2" autocomplete="off">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create">

        <div class="sm:col-span-2">
            <label for="business_name" class="<?= $labelClass ?>">Business name</label>
            <input id="business_name" name="business_name" type="text" required maxlength="190"
                   value="<?= e($old['business_name']) ?>" class="<?= $fieldClass ?>">
        </div>
        <div>
            <label for="full_name" class="<?= $labelClass ?>">Contact person (full name)</label>
            <input id="full_name" name="full_name" type="text" required maxlength="150"
                   value="<?= e($old['full_name']) ?>" class="<?= $fieldClass ?>">
        </div>
        <div>
            <label for="contact_no" class="<?= $labelClass ?>">Contact number</label>
            <input id="contact_no" name="contact_no" type="text" maxlength="30"
                   value="<?= e($old['contact_no']) ?>" class="<?= $fieldClass ?>">
        </div>
        <div>
            <label for="email" class="<?= $labelClass ?>">Email (used to sign in)</label>
            <input id="email" name="email" type="email" required maxlength="190"
                   value="<?= e($old['email']) ?>" class="<?= $fieldClass ?>">
        </div>
        <div>
            <label for="temp_password" class="<?= $labelClass ?>">Temporary password</label>
            <input id="temp_password" name="temp_password" type="text" required autocomplete="off"
                   class="<?= $fieldClass ?>">
            <p class="mt-1 text-xs text-slate-500">The supplier must choose a new password at first sign-in.</p>
        </div>

        <div class="sm:col-span-2">
            <button type="submit" class="btn btn-primary w-full sm:w-auto">Create supplier account</button>
        </div>
    </form>
</details>

<!-- Existing suppliers -->
<section>
    <h2 class="mb-3 text-base font-semibold text-slate-900">All suppliers (<?= count($suppliers) ?>)</h2>

    <?php if ($suppliers === []): ?>
        <div class="card text-sm text-slate-600">No supplier accounts yet. Use "Add a supplier" above.</div>
    <?php else: ?>
        <ul class="space-y-3">
            <?php foreach ($suppliers as $s): ?>
                <?php $isActive = $s['status'] === 'active'; ?>
                <li class="card">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="truncate text-sm font-semibold text-slate-900"><?= e($s['business_name'] ?? '-') ?></p>
                            <p class="truncate text-sm text-slate-600"><?= e($s['full_name']) ?> &middot; <?= e($s['email']) ?></p>
                            <p class="mt-1 text-xs text-slate-500">
                                <?= $s['contact_no'] !== '' ? e($s['contact_no']) . ' &middot; ' : '' ?>
                                <?= (int) $s['form_count'] ?> form<?= (int) $s['form_count'] === 1 ? '' : 's' ?>
                                <?= (int) $s['must_change_password'] === 1 ? ' &middot; has not changed the temporary password yet' : '' ?>
                            </p>
                        </div>

                        <div class="flex items-center gap-3">
                            <span class="rounded-full px-2.5 py-1 text-xs font-semibold <?= $isActive ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-200 text-slate-700' ?>">
                                <?= e(ucfirst($s['status'])) ?>
                            </span>

                            <?php if ($isActive || $s['status'] === 'disabled'): ?>
                                <form method="post" action="<?= e(url('mms/suppliers.php')) ?>">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="supplier_id" value="<?= (int) $s['id'] ?>">
                                    <input type="hidden" name="action" value="<?= $isActive ? 'disable' : 'enable' ?>">
                                    <button type="submit" class="btn btn-secondary !min-h-10 !px-4"><?= $isActive ? 'Disable' : 'Enable' ?></button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/../app/views/layout/footer.php'; ?>