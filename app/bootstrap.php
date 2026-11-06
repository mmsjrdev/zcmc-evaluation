<?php
/**
 * app/bootstrap.php
 *
 * Every page starts with:
 *   require_once __DIR__ . '/../app/bootstrap.php';
 *
 * It loads the settings, starts a safe session, and defines the small helper
 * functions used on every page.
 */

declare(strict_types=1);

// ---------------------------------------------------------------------------
// 1. Load the settings
// ---------------------------------------------------------------------------
$configFile = __DIR__ . '/../config/config.php';
if (!is_file($configFile)) {
    http_response_code(500);
    exit('config/config.php is missing. Copy config/config.sample.php to config/config.php and fill it in.');
}
require_once $configFile;

// ---------------------------------------------------------------------------
// 2. Errors: show them on 'local', hide them on 'live'
// ---------------------------------------------------------------------------
error_reporting(E_ALL);
if (ENV === 'local') {
    ini_set('display_errors', '1');
} else {
    ini_set('display_errors', '0');   // visitors never see error details
    ini_set('log_errors', '1');       // errors go to the server log instead
}

// ---------------------------------------------------------------------------
// 3. Timezone
// ---------------------------------------------------------------------------
date_default_timezone_set('Asia/Manila');

// ---------------------------------------------------------------------------
// 4. Safe session cookies
// ---------------------------------------------------------------------------
if (session_status() === PHP_SESSION_NONE) {
    // Is the site opened through https?
    $isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';

    ini_set('session.use_strict_mode', '1');   // refuse session IDs the server did not create
    ini_set('session.use_only_cookies', '1');  // never put the session ID in the URL

    session_set_cookie_params([
        'lifetime' => 0,          // cookie ends when the browser closes
        'path'     => '/',
        'secure'   => $isHttps,   // only sent over https when the site uses https
        'httponly' => true,       // JavaScript cannot read the cookie
        'samesite' => 'Lax',      // blocks most cross-site request tricks
    ]);
    session_start();
}

// ---------------------------------------------------------------------------
// 5. Database (PDO)
// ---------------------------------------------------------------------------
/**
 * Returns the one shared database connection.
 * The connection opens the first time db() is called, so a page that never
 * touches the database does not need MySQL to be running.
 * If MySQL is not reachable, this throws a PDOException.
 */
function db(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,  // errors become exceptions
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,        // rows come back as arrays
            PDO::ATTR_EMULATE_PREPARES   => false,                   // real prepared statements
        ]);
    }

    return $pdo;
}

// ---------------------------------------------------------------------------
// 6. Helper functions
// ---------------------------------------------------------------------------

/** Escape text before printing it in HTML. Use it on EVERY value you print. */
function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Build a full link from a path. Example: url('auth/login.php') */
function url(string $path = ''): string
{
    return rtrim(BASE_URL, '/') . '/' . ltrim($path, '/');
}

/**
 * Build a link to a file in the assets/ folder.
 * Example: asset('css/app.css'). The ?v= part makes the browser reload the
 * file after you change it.
 */
function asset(string $path): string
{
    $path = ltrim($path, '/');
    $file = __DIR__ . '/../assets/' . $path;
    $version = is_file($file) ? '?v=' . filemtime($file) : '';
    return url('assets/' . $path) . $version;
}

/** Send the browser to another page and stop. Example: redirect('auth/login.php') */
function redirect(string $path): void
{
    // A full web address is used as is; a short path goes through url().
    $target = preg_match('#^https?://#i', $path) ? $path : url($path);
    header('Location: ' . $target);
    exit;
}

/**
 * Save a message to show on the NEXT page load.
 * $type is one of: success, error, info
 */
function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

/** Print the saved messages (once) and clear them. Call this in the page layout. */
function show_flash(): void
{
    if (empty($_SESSION['flash'])) {
        return;
    }

    // Tailwind colours for each message type.
    $styles = [
        'success' => 'border-green-200 bg-green-50 text-green-800',
        'error'   => 'border-red-200 bg-red-50 text-red-800',
        'info'    => 'border-blue-200 bg-blue-50 text-blue-800',
    ];

    foreach ($_SESSION['flash'] as $item) {
        $class = $styles[$item['type']] ?? $styles['info'];
        echo '<div role="alert" class="mb-4 rounded-xl border px-4 py-3 text-sm ' . $class . '">'
            . e($item['message'])
            . '</div>';
    }

    unset($_SESSION['flash']);
}

/** The secret token that proves a form was posted from our own site. */
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/** Put this inside every <form method="post">. */
function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

/** Call this at the top of every POST handler. Stops the page if the token is wrong. */
function csrf_check(): void
{
    $sent = $_POST['csrf_token'] ?? '';

    if (!is_string($sent) || !hash_equals(csrf_token(), $sent)) {
        http_response_code(419);
        exit('Your session expired or the form was not valid. Go back, reload the page and try again.');
    }
}

// ---------------------------------------------------------------------------
// 7. Load the other core files
// ---------------------------------------------------------------------------
// auth.php   = who is signed in (sign in, sign out, current_user()).
// permissions.php = what each role may do (require_role(), can_view_evaluation(), ...).
require_once __DIR__ . '/core/auth.php';
require_once __DIR__ . '/core/permissions.php';
require_once __DIR__ . '/services/otp.php';
// definitions.php = status labels and colours. notify.php = the in-app bell.
require_once __DIR__ . '/domain/definitions.php';
require_once __DIR__ . '/services/notify.php';
// Step 4: form rules. form-fields = who owns which field, workflow = status moves,
// validation = field checks, hashing = fingerprints, supplier-forms = supplier queries.
require_once __DIR__ . '/services/audit.php';
require_once __DIR__ . '/domain/form-fields.php';
require_once __DIR__ . '/domain/workflow.php';
require_once __DIR__ . '/domain/validation.php';
require_once __DIR__ . '/domain/hashing.php';
require_once __DIR__ . '/domain/supplier-forms.php';
