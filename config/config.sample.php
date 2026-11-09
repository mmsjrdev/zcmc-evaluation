<?php
/**
 * config/config.sample.php
 *
 * A copy of config.php WITHOUT secrets. Copy it to config.php and fill in
 * the values. config.php is the ONLY file that differs between your laptop
 * (XAMPP) and the live server (InfinityFree).
 */

// 'local' = show PHP errors (use on XAMPP). 'live' = hide PHP errors (use on InfinityFree).
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

// One-time passwords by email. Keep false until Step 15.
define('OTP_ENABLED', false);

// true  = the supplier can see the evaluation result when they sign the Conforme.
// false = the supplier never sees the evaluator's result.
define('SUPPLIER_SEES_RESULT_AT_CONFORME', true);