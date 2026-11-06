<?php
/**
 * config/config.sample.php
 *
 * A safe template. Copy this file to config/config.php and fill in your values.
 * Do not put real passwords in this sample file.
 */

// 'local' = show PHP errors (use on XAMPP). 'live' = hide PHP errors (use on InfinityFree).
define('ENV', 'local');

// The web address of the project folder. No slash at the end.
//   XAMPP:        http://localhost/zcmc-evaluation
//   InfinityFree: https://your-site.infinityfreeapp.com
define('BASE_URL', 'http://localhost/zcmc-evaluation');

// Database settings.
// XAMPP default: host localhost, user root, empty password.
// InfinityFree: copy the values from your hosting control panel (MySQL Databases).
define('DB_HOST', 'localhost');
define('DB_NAME', 'zcmc-evaluation');
define('DB_USER', 'root');
define('DB_PASS', '');

// One-time passwords by email. Keep false until Step 15.
define('OTP_ENABLED', false);

// true  = the supplier can see the evaluation result when they sign the Conforme.
// false = the supplier never sees the evaluator's result.
define('SUPPLIER_SEES_RESULT_AT_CONFORME', true);
