<?php
/**
 * install.php  (ONE-TIME USE - delete this file after it works)
 *
 * Creates the first admin account. It refuses to run again once an admin
 * exists, but you should still delete the file from the server.
 */

require_once __DIR__ . '/app/bootstrap.php';

$errors    = [];     // messages shown above the form
$done      = false;  // true after the admin has been created
$locked    = false;  // true when an admin already exists
$fullName  = '';
$email     = '';

// ---------------------------------------------------------------------------
// 1. Is the database ready, and is there already an admin?
// ---------------------------------------------------------------------------
try {
    $stmt = db()->query("SELECT COUNT(*) FROM users WHERE role = 'admin'");
    $locked = ((int) $stmt->fetchColumn()) > 0;
} catch (PDOException $ex) {
    http_response_code(500);
    $message = 'The database is not ready. Check config/config.php and import database/schema.sql first.';
    if (ENV === 'local') {
        $message .= ' (' . $ex->getMessage() . ')';
    }
    exit(e($message));
}

// ---------------------------------------------------------------------------
// 2. Handle the form
// ---------------------------------------------------------------------------
if (!$locked && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $fullName = trim((string) ($_POST['full_name'] ?? ''));
    $email    = strtolower(trim((string) ($_POST['email'] ?? '')));
    $password = (string) ($_POST['password'] ?? '');
    $confirm  = (string) ($_POST['password_confirm'] ?? '');

    // Check what was typed.
    if ($fullName === '' || mb_strlen($fullName) > 150) {
        $errors[] = 'Enter your full name (up to 150 characters).';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
        $errors[] = 'Enter a valid email address.';
    }
    if (strlen($password) < 8) {
        $errors[] = 'The password must be at least 8 characters.';
    }
    if ($password !== $confirm) {
        $errors[] = 'The two passwords do not match.';
    }

    // Save the admin if everything is fine.
    if (!$errors) {
        try {
            $pdo = db();
            $pdo->beginTransaction();

            // Check again inside the transaction so two people cannot both install.
            $count = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin' FOR UPDATE")->fetchColumn();
            if ($count > 0) {
                $pdo->rollBack();
                $locked = true;
            } else {
                $insert = $pdo->prepare(
                    "INSERT INTO users (email, password_hash, full_name, position, role, status, requested_role)
                     VALUES (:email, :hash, :name, 'Administrator', 'admin', 'active', 'admin')"
                );
                $insert->execute([
                    ':email' => $email,
                    ':hash'  => password_hash($password, PASSWORD_DEFAULT),
                    ':name'  => $fullName,
                ]);
                $adminId = (int) $pdo->lastInsertId();

                // Keep a record of who was created and from where.
                $log = $pdo->prepare(
                    "INSERT INTO audit_log (user_id, action, details, ip_address)
                     VALUES (:uid, 'install', 'First admin created with install.php', :ip)"
                );
                $log->execute([
                    ':uid' => $adminId,
                    ':ip'  => substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
                ]);

                $pdo->commit();
                $done = true;
            }
        } catch (PDOException $ex) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            // 23000 = duplicate email (or another database rule was broken)
            $errors[] = ($ex->getCode() === '23000')
                ? 'That email is already used by another account.'
                : 'Could not save the admin. Please try again.';
            if (ENV === 'local') {
                $errors[] = $ex->getMessage();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Install - ZCMC Evaluation Form System</title>
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body class="min-h-screen">
<main class="mx-auto flex min-h-screen max-w-md flex-col justify-center px-4 py-10">

    <div class="mb-6 text-center">
        <p class="text-sm font-semibold uppercase tracking-wide text-brand-700">Zamboanga City Medical Center</p>
        <h1 class="mt-1 text-2xl font-bold text-slate-900">First-time setup</h1>
    </div>

    <?php if ($done): ?>

        <!-- Success -->
        <section class="card">
            <h2 class="text-lg font-semibold text-green-800">Admin account created</h2>
            <p class="mt-2 text-sm text-slate-600">
                You can now log in as <strong><?= e($email) ?></strong> once the login page is built (Step 2).
            </p>
            <div class="mt-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                <strong>Important:</strong> delete <code>install.php</code> from your project folder now
                (and from InfinityFree if you uploaded it).
            </div>
        </section>

    <?php elseif ($locked): ?>

        <!-- An admin already exists -->
        <section class="card">
            <h2 class="text-lg font-semibold text-slate-900">Setup is already done</h2>
            <p class="mt-2 text-sm text-slate-600">
                An admin account already exists, so this page is switched off.
                Please delete <code>install.php</code> from the project folder.
            </p>
        </section>

    <?php else: ?>

        <!-- The form -->
        <section class="card">
            <h2 class="text-lg font-semibold text-slate-900">Create the first admin</h2>
            <p class="mt-1 text-sm text-slate-500">This page only works once.</p>

            <?php foreach ($errors as $error): ?>
                <div role="alert" class="mt-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                    <?= e($error) ?>
                </div>
            <?php endforeach; ?>

            <form method="post" action="<?= e(url('install.php')) ?>" class="mt-5 space-y-4" autocomplete="off">
                <?= csrf_field() ?>

                <div>
                    <label for="full_name" class="mb-1 block text-sm font-medium text-slate-700">Full name</label>
                    <input id="full_name" name="full_name" type="text" required maxlength="150"
                           value="<?= e($fullName) ?>" class="input">
                </div>

                <div>
                    <label for="email" class="mb-1 block text-sm font-medium text-slate-700">Email</label>
                    <input id="email" name="email" type="email" required maxlength="190"
                           value="<?= e($email) ?>" class="input">
                </div>

                <div>
                    <label for="password" class="mb-1 block text-sm font-medium text-slate-700">Password</label>
                    <input id="password" name="password" type="password" required minlength="8" class="input">
                    <p class="mt-1 text-xs text-slate-500">At least 8 characters.</p>
                </div>

                <div>
                    <label for="password_confirm" class="mb-1 block text-sm font-medium text-slate-700">Repeat password</label>
                    <input id="password_confirm" name="password_confirm" type="password" required minlength="8" class="input">
                </div>

                <button type="submit" class="btn btn-primary w-full">Create admin</button>
            </form>
        </section>

    <?php endif; ?>

</main>
</body>
</html>
