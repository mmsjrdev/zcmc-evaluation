<?php
/**
 * common/download.php?id=N
 *
 * Serves the paper Conforme scan of a form (Step 10).
 *
 *   - login required, and the person must pass can_view_evaluation()
 *   - suppliers never get it: the scan is a print of the WHOLE form, which
 *     includes the evaluator's sections
 *   - the file name comes only from the database, and must look exactly like
 *     the random names this system creates, so no path can come from the URL
 *   - storage/ itself is closed to the web (see storage/.htaccess)
 */

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/domain/supplier-conforme.php';

$user = require_role(all_roles());

$id   = (int) ($_GET['id'] ?? 0);
$form = $id > 0 ? conforme_load_form($id) : null;

if (!$form || !can_view_evaluation($user, $form) || $user['role'] === 'supplier') {
    forbidden();
}

/** Plain "not found" answer. */
function download_not_found(): void
{
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'File not found.';
    exit;
}

// Only a paper Conforme of a completed form has a scan that counts.
if (!in_array((string) $form['status'], ['completed', 'received'], true)
    || (string) ($form['conforme_method'] ?? '') !== 'paper') {
    download_not_found();
}

$stored = (string) ($form['paper_scan_file'] ?? '');
if (!preg_match('/^[a-f0-9]{32}\.(pdf|jpg|png)$/', $stored, $m)) {
    download_not_found();
}

$dir = realpath(__DIR__ . '/../storage/uploads');
if ($dir === false) {
    download_not_found();
}
$path = $dir . DIRECTORY_SEPARATOR . $stored;
if (!is_file($path)) {
    download_not_found();
}

$types = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'png' => 'image/png'];

// A safe download name such as conforme-scan-EF-2611-0001.pdf
$ref      = trim((string) ($form['ref_no'] ?? ''));
$niceName = 'conforme-scan-' . preg_replace('/[^A-Za-z0-9-]/', '', $ref !== '' ? $ref : 'form-' . $id) . '.' . $m[1];

while (ob_get_level() > 0) {
    ob_end_clean();
}
header('Content-Type: ' . $types[$m[1]]);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: inline; filename="' . $niceName . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($path);
exit;