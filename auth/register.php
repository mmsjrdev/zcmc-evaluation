<?php
/**
 * auth/register.php
 *
 * Public sign-up for two kinds of people only:
 *   - Evaluator:        active right away.
 *   - Department Head:  status "pending" until an admin approves.
 *                       Until then the account works as an Evaluator.
 * Suppliers, MMS staff, Head Nurses and admins can NOT register here.
 * Whatever is chosen is only a REQUEST: the stored role is always "evaluator".
 *
 * Step 15: while OTP_ENABLED is true, the new account is not usable until the
 * email is confirmed with a 6-digit code (auth/verify-email.php).
 */

require_once __DIR__ . '/../app/bootstrap.php';

// Already signed in? No need to register.
$already = current_user();
if ($already !== null) {
    redirect(landing_path($already));
}

// Departments the person can pick from.
$departments = db()->query('SELECT id, name FROM departments WHERE active = 1 ORDER BY name')->fetchAll();

$errors = [];     // error text per field, e.g. $errors['email']
$values = [       // what the person typed (passwords are never sent back)
    'email'         => '',
    'full_name'     => '',
    'employee_id'   => '',
    'department_id' => '',
    'position'      => '',
    'contact_no'    => '',
    'register_as'   => 'evaluator',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    // ---- Read the form -----------------------------------------------------
    $values['email']         = strtolower(trim((string) ($_POST['email'] ?? '')));
    $values['full_name']     = normalize_name((string) ($_POST['full_name'] ?? ''));
    $values['employee_id']   = trim((string) ($_POST['employee_id'] ?? ''));
    $values['department_id'] = trim((string) ($_POST['department_id'] ?? ''));
    $values['position']      = trim((string) ($_POST['position'] ?? ''));
    $values['contact_no']    = trim((string) ($_POST['contact_no'] ?? ''));
    $values['register_as']   = (string) ($_POST['register_as'] ?? '');
    $password                = (string) ($_POST['password'] ?? '');
    $confirm                 = (string) ($_POST['password_confirm'] ?? '');

    // ---- Check it ----------------------------------------------------------
    // Email
    if (!filter_var($values['email'], FILTER_VALIDATE_EMAIL) || strlen($values['email']) > 190) {
        $errors['email'] = 'Enter a valid email address.';
    } else {
        $check = db()->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
        $check->execute([':email' => $values['email']]);
        if ($check->fetch()) {
            $errors['email'] = 'An account with this email already exists.';
        }
    }

    // Password
    $problem = password_problem($password);
    if ($problem !== null) {
        $errors['password'] = $problem;
    }
    if ($password !== $confirm) {
        $errors['password_confirm'] = 'The two passwords do not match.';
    }

    // Full name: at least two words (first and last name)
    if (mb_strlen($values['full_name']) > 150) {
        $errors['full_name'] = 'The name is too long (150 characters at most).';
    } elseif (count(explode(' ', $values['full_name'])) < 2) {
        $errors['full_name'] = 'Enter your complete name (first name and last name).';
    }

    // Employee ID
    if ($values['employee_id'] === '' || strlen($values['employee_id']) > 50) {
        $errors['employee_id'] = 'Enter your employee ID (50 characters at most).';
    }

    // Department: must be one of the active departments in the list
    $departmentId = null;
    foreach ($departments as $dep) {
        if ((string) $dep['id'] === $values['department_id']) {
            $departmentId = (int) $dep['id'];
        }
    }
    if ($departmentId === null) {
        $errors['department_id'] = 'Choose your department.';
    }

    // Position
    if ($values['position'] === '' || mb_strlen($values['position']) > 100) {
        $errors['position'] = 'Enter your position (100 characters at most).';
    }

    // Contact number (optional): digits, spaces and + - ( ) only
    if ($values['contact_no'] !== ''
        && (strlen($values['contact_no']) > 30 || !preg_match('/^[0-9+\-\s()]+$/', $values['contact_no']))) {
        $errors['contact_no'] = 'Use numbers only (you may use + - ( ) and spaces).';
    }

    // Registering as: only these two are allowed
    if (!in_array($values['register_as'], ['evaluator', 'head'], true)) {
        $errors['register_as'] = 'Choose Evaluator or Department Head.';
    }

    // ---- Save --------------------------------------------------------------
    if (!$errors) {
        // A head request starts as "pending". The stored role is always evaluator.
        $status = ($values['register_as'] === 'head') ? 'pending' : 'active';

        try {
            $insert = db()->prepare(
                "INSERT INTO users
                    (email, password_hash, full_name, employee_id, department_id, `position`,
                     contact_no, role, status, requested_role, must_change_password)
                 VALUES
                    (:email, :hash, :name, :employee_id, :department_id, :position,
                     :contact_no, 'evaluator', :status, :requested_role, 0)"
            );
            $insert->execute([
                ':email'          => $values['email'],
                ':hash'           => password_hash($password, PASSWORD_DEFAULT),
                ':name'           => $values['full_name'],
                ':employee_id'    => $values['employee_id'],
                ':department_id'  => $departmentId,
                ':position'       => $values['position'],
                ':contact_no'     => $values['contact_no'],
                ':status'         => $status,
                ':requested_role' => $values['register_as'],
            ]);
            $newId = (int) db()->lastInsertId();

            audit_write($newId, 'register', 'Registered as ' . $values['register_as'] . ' (status ' . $status . ')');

            // Step 15: with OTP on, the email must be confirmed with a code before
            // the account can be used. email_verified_at stays empty until then.
            if (otp_required('register')) {
                session_regenerate_id(true);
                $_SESSION['verify_user_id'] = $newId;
                flash('success', 'Account created. Confirm your email with the code we send you to finish.');
                redirect('auth/verify-email.php');
            }

            if ($status === 'pending') {
                flash('success', 'Account created. Your Department Head request waits for the administrator. '
                    . 'Until it is approved you can sign in and work as an Evaluator.');
            } else {
                flash('success', 'Account created. You can sign in now.');
            }
            redirect('auth/login.php');

        } catch (PDOException $ex) {
            // 23000 = duplicate email (two people registering at the same moment)
            if ($ex->getCode() === '23000') {
                $errors['email'] = 'An account with this email already exists.';
            } else {
                throw $ex;
            }
        }
    }
}

$pageTitle = 'Create an account';
$maxWidth  = 'max-w-lg';
require __DIR__ . '/../app/views/layout/auth-top.php';
?>

<h1 class="text-xl font-semibold text-slate-900">Create an account</h1>
<p class="mt-1 text-sm text-slate-500">For hospital evaluators and department heads.</p>

<?php if ($errors): ?>
    <div role="alert" class="mt-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-800">
        Please fix the problems marked in red below.
    </div>
<?php endif; ?>

<form method="post" action="<?= e(url('auth/register.php')) ?>" class="mt-5 space-y-5">
    <?= csrf_field() ?>

    <!-- Who are you registering as? -->
    <fieldset>
        <legend class="mb-2 block text-sm font-medium text-slate-700">I am registering as</legend>
        <div class="grid gap-3 sm:grid-cols-2">
            <label class="flex min-h-14 cursor-pointer items-start gap-3 rounded-xl border border-slate-300 bg-white p-3
                          has-[:checked]:border-brand-500 has-[:checked]:bg-brand-50 has-[:focus-visible]:ring-4 has-[:focus-visible]:ring-brand-200">
                <input type="radio" name="register_as" value="evaluator" class="mt-1 size-4 accent-brand-600"
                       <?= $values['register_as'] === 'evaluator' ? 'checked' : '' ?>>
                <span>
                    <span class="block text-sm font-semibold text-slate-900">Evaluator</span>
                    <span class="block text-xs text-slate-500">Active right away.</span>
                </span>
            </label>
            <label class="flex min-h-14 cursor-pointer items-start gap-3 rounded-xl border border-slate-300 bg-white p-3
                          has-[:checked]:border-brand-500 has-[:checked]:bg-brand-50 has-[:focus-visible]:ring-4 has-[:focus-visible]:ring-brand-200">
                <input type="radio" name="register_as" value="head" class="mt-1 size-4 accent-brand-600"
                       <?= $values['register_as'] === 'head' ? 'checked' : '' ?>>
                <span>
                    <span class="block text-sm font-semibold text-slate-900">Department Head</span>
                    <span class="block text-xs text-slate-500">Needs the administrator's approval.</span>
                </span>
            </label>
        </div>
        <?php field_error($errors, 'register_as'); ?>
    </fieldset>

    <!-- Full name -->
    <div>
        <label for="full_name" class="mb-1 block text-sm font-medium text-slate-700">Complete name</label>
        <input id="full_name" name="full_name" type="text" required maxlength="150" autocomplete="name"
               value="<?= e($values['full_name']) ?>" class="<?= e(input_class($errors, 'full_name')) ?>">
        <p class="mt-1 text-xs text-slate-500">
            Use your real, complete name. When you sign a form you must type this name again,
            and it has to match.
        </p>
        <?php field_error($errors, 'full_name'); ?>
    </div>

    <!-- Email -->
    <div>
        <label for="email" class="mb-1 block text-sm font-medium text-slate-700">Email</label>
        <input id="email" name="email" type="email" required maxlength="190" autocomplete="username"
               value="<?= e($values['email']) ?>" class="<?= e(input_class($errors, 'email')) ?>">
        <?php if (otp_required('register')): ?>
            <p class="mt-1 text-xs text-slate-500">We will email you a 6-digit code to confirm this address, so use one you can open now.</p>
        <?php endif; ?>
        <?php field_error($errors, 'email'); ?>
    </div>

    <!-- Passwords -->
    <div class="grid gap-5 sm:grid-cols-2">
        <div>
            <label for="password" class="mb-1 block text-sm font-medium text-slate-700">Password</label>
            <input id="password" name="password" type="password" required minlength="8" maxlength="72"
                   autocomplete="new-password" class="<?= e(input_class($errors, 'password')) ?>">
            <p class="mt-1 text-xs text-slate-500">At least 8 characters.</p>
            <?php field_error($errors, 'password'); ?>
        </div>
        <div>
            <label for="password_confirm" class="mb-1 block text-sm font-medium text-slate-700">Repeat password</label>
            <input id="password_confirm" name="password_confirm" type="password" required minlength="8" maxlength="72"
                   autocomplete="new-password" class="<?= e(input_class($errors, 'password_confirm')) ?>">
            <?php field_error($errors, 'password_confirm'); ?>
        </div>
    </div>

    <!-- Employee ID and department -->
    <div class="grid gap-5 sm:grid-cols-2">
        <div>
            <label for="employee_id" class="mb-1 block text-sm font-medium text-slate-700">Employee ID</label>
            <input id="employee_id" name="employee_id" type="text" required maxlength="50"
                   value="<?= e($values['employee_id']) ?>" class="<?= e(input_class($errors, 'employee_id')) ?>">
            <?php field_error($errors, 'employee_id'); ?>
        </div>
        <div>
            <label for="department_id" class="mb-1 block text-sm font-medium text-slate-700">Department</label>
            <select id="department_id" name="department_id" required class="<?= e(input_class($errors, 'department_id')) ?>">
                <option value="">Choose...</option>
                <?php foreach ($departments as $dep): ?>
                    <option value="<?= e($dep['id']) ?>" <?= (string) $dep['id'] === $values['department_id'] ? 'selected' : '' ?>>
                        <?= e($dep['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?php field_error($errors, 'department_id'); ?>
        </div>
    </div>

    <!-- Position and contact number -->
    <div class="grid gap-5 sm:grid-cols-2">
        <div>
            <label for="position" class="mb-1 block text-sm font-medium text-slate-700">Position</label>
            <input id="position" name="position" type="text" required maxlength="100" autocomplete="organization-title"
                   value="<?= e($values['position']) ?>" class="<?= e(input_class($errors, 'position')) ?>">
            <?php field_error($errors, 'position'); ?>
        </div>
        <div>
            <label for="contact_no" class="mb-1 block text-sm font-medium text-slate-700">
                Contact number <span class="font-normal text-slate-400">(optional)</span>
            </label>
            <input id="contact_no" name="contact_no" type="tel" maxlength="30" autocomplete="tel"
                   value="<?= e($values['contact_no']) ?>" class="<?= e(input_class($errors, 'contact_no')) ?>">
            <?php field_error($errors, 'contact_no'); ?>
        </div>
    </div>

    <button type="submit" class="btn btn-primary w-full">Create account</button>
</form>

<p class="mt-5 border-t border-slate-100 pt-4 text-sm text-slate-600">
    Already have an account?
    <a href="<?= e(url('auth/login.php')) ?>" class="font-semibold text-brand-700 underline underline-offset-2 hover:text-brand-800">Sign in</a>
</p>

<?php require __DIR__ . '/../app/views/layout/auth-bottom.php'; ?>