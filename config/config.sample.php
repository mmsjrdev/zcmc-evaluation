<?php
/**
 * config/config.sample.php
 *
 * A copy of config.php WITHOUT secrets. Copy it to config.php and fill in
 * the values. config.php is the ONLY file that differs between your laptop
 * (XAMPP) and the live server (InfinityFree, Hostinger or hospital hosting).
 */

// 'local' = show PHP errors (use on XAMPP). 'live' = hide PHP errors (use on the live server).
define('ENV', 'local');

// The web address of the project folder. No slash at the end. Examples:
//   XAMPP:        http://localhost/zcmc-evaluation
//   InfinityFree: https://your-site.infinityfreeapp.com
define('BASE_URL', 'http://localhost/zcmc-evaluation');

// Database settings.
define('DB_HOST', 'localhost');
define('DB_NAME', 'zcmc-evaluation');
define('DB_USER', 'root');
define('DB_PASS', '');

// One-time passwords by email (Step 15).
// false = the system works exactly as before (password only).
// true  = new accounts confirm their email with a 6-digit code, and every
//         signing needs a fresh code instead of the password.
// Turn it on only after the SMTP settings below pass admin/mail-test.php.
define('OTP_ENABLED', false);

// Email (SMTP) settings, used only for one-time codes.
// Gmail example for testing on XAMPP:
//   SMTP_HOST 'smtp.gmail.com', SMTP_PORT 587, SMTP_USER your Gmail address,
//   SMTP_PASS a Google "App password" (not your normal password, no spaces),
//   SMTP_FROM the same Gmail address.
// Port 465 uses SSL, any other port uses STARTTLS.
define('SMTP_HOST', '');
define('SMTP_PORT', 587);
define('SMTP_USER', '');
define('SMTP_PASS', '');
define('SMTP_FROM', '');

// true  = the supplier can see the evaluation result when they sign the Conforme.
// false = the supplier never sees the evaluator's result.
define('SUPPLIER_SEES_RESULT_AT_CONFORME', true);